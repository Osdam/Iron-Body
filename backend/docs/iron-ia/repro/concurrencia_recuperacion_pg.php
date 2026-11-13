<?php

/**
 * Concurrencia REAL de la recuperación de Iron IA en PostgreSQL (docs/iron-ia/RECUPERACION-RUTINAS.md).
 *
 * SQLite no tiene bloqueos de fila: esto lo comprueba con dos procesos contra un
 * PostgreSQL DESECHABLE. Un proceso «retenedor» toma la fila como lo haría otra
 * ejecución de la campaña o una primera publicación, y el código real tiene que
 * esperarle y no repetir el trabajo:
 *
 *   1. Reclamo: con la fila reclamada por otro, process() espera a que suelte y
 *      devuelve not_claimed (no genera, no paga).
 *   2. Publicación: con la fila bloqueada por una publicación en curso, publish()
 *      espera y no hace una segunda sustitución.
 *   3. Regenerar frente a publicar la anterior (RECUPERACION-REVISION-CONTRATO.md):
 *      con una regeneración confirmando, publicar la propuesta que sustituye espera
 *      y se rechaza con recovery_superseded; no se publica nada.
 *   4. Publicar antes de regenerar: con una publicación confirmando, la reserva del
 *      intento espera y se rechaza (recovery_not_pending) SIN llamar al modelo.
 *   5. Publicar durante la llamada al modelo: la regeneración espera al confirmar, deja
 *      la nueva retirada y marcada como sustituida, y no toca la fila.
 *   6. Dos regeneraciones por la última plaza (regenerations=2): con la otra
 *      confirmando, esta espera y se rechaza (recovery_regeneration_limit) sin llamar
 *      al modelo ni gastar un intento.
 *   7. Publicar frente a retirar (interbloqueo 40P01 que reprodujo la revisión del
 *      13-11): con una publicación que tiene la fila y va a tocar la rutina, retirar
 *      espera a la fila ANTES de tocar la rutina, sin interbloqueo.
 *
 * Uso (base desechable; el script se niega con cualquier otro nombre):
 *   psql -h 127.0.0.1 -p 55432 -U ironbody -d postgres -c "CREATE DATABASE iron_ia_conc_1"
 *   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=iron_ia_conc_1 DB_USERNAME=ironbody \
 *     DB_PASSWORD= DB_URL= PGGSSENCMODE=disable php docs/iron-ia/repro/concurrencia_recuperacion_pg.php
 */

use App\Models\Exercise;
use App\Models\IronIaRecoveryItem;
use App\Models\Member;
use App\Models\MemberRoutineAssignment;
use App\Models\MemberTrainerAssignment;
use App\Models\NutritionAiRun;
use App\Models\Routine;
use App\Models\RoutineExercise;
use App\Models\Trainer;
use App\Models\User;
use App\Services\Trainer\IntegralPlanGenerationService;
use App\Services\Trainer\Recovery\RoutineRecoveryCampaign;
use App\Services\Trainer\RoutinePublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$raiz = dirname(__DIR__, 3);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// La guarda mira la base a la que está CONECTADO de verdad, no la de la
// configuración: con DB_URL, Laravel conecta a esa aunque DB_DATABASE diga otra.
// Este script hace migrate:fresh: contra cualquier otra base sería borrarla.
if ((string) getenv('DB_URL') !== '' || app()->environment('production') || config('database.default') !== 'pgsql') {
    fwrite(STDERR, "Se niega: sin DB_URL, fuera de producción y solo con pgsql.\n");
    exit(2);
}
$base = (string) (DB::selectOne('select current_database() as db')->db ?? '');
if (! preg_match('/^iron_ia_conc_[a-z0-9_]+$/', $base)) {
    fwrite(STDERR, "Solo contra un PostgreSQL desechable llamado iron_ia_conc_*. Conectado a: {$base}\n");
    exit(2);
}

// ── Rol «retenedor»: toma la fila, avisa y la suelta pasados N segundos ──────
if (($argv[1] ?? '') === 'reclamo') {
    [$id, $seg] = [(int) $argv[2], (int) $argv[3]];
    DB::beginTransaction();
    DB::table('iron_ia_recovery_items')->where('id', $id)
        ->update(['status' => IronIaRecoveryItem::STATUS_GENERATING, 'claimed_at' => now(), 'attempts' => DB::raw('attempts + 1')]);
    echo "RETENGO\n";
    sleep($seg);
    DB::commit();
    exit(0);
}
if (($argv[1] ?? '') === 'publicacion') {
    [$id, $seg] = [(int) $argv[2], (int) $argv[3]];
    DB::beginTransaction();
    $item = DB::table('iron_ia_recovery_items')->where('id', $id)->lockForUpdate()->first();
    $marca = '2026-11-14 10:00:00';
    DB::table('iron_ia_recovery_items')->where('id', $id)
        ->update(['status' => IronIaRecoveryItem::STATUS_PUBLISHED, 'published_at' => $marca, 'original_retired_at' => $marca]);
    DB::table('routines')->where('id', $item->original_routine_id)->update(['status' => 'Retirada', 'is_assigned' => false]);
    DB::table('member_routine_assignments')->where('routine_id', $item->original_routine_id)->delete();
    echo "RETENGO\n";
    sleep($seg);
    DB::commit();
    exit(0);
}

if (($argv[1] ?? '') === 'publicar_lento') {
    // Como publish(): primero la fila de la campaña, luego (tras la pausa) la rutina.
    [$id, $seg, $rutina] = [(int) $argv[2], (int) $argv[3], (int) $argv[4]];
    DB::beginTransaction();
    DB::table('iron_ia_recovery_items')->where('id', $id)->lockForUpdate()->first();
    echo "RETENGO\n";
    sleep($seg);
    DB::table('routines')->where('id', $rutina)->update(['name' => DB::raw("name || ' (revisada)'")]);
    DB::commit();
    exit(0);
}
if (($argv[1] ?? '') === 'ultima') {
    [$id, $seg] = [(int) $argv[2], (int) $argv[3]];
    DB::beginTransaction();
    DB::table('iron_ia_recovery_items')->where('id', $id)->lockForUpdate()->first();
    DB::table('iron_ia_recovery_items')->where('id', $id)
        ->update(['regenerations' => 3, 'regeneration_attempts' => DB::raw('regeneration_attempts + 1')]);
    echo "RETENGO\n";
    sleep($seg);
    DB::commit();
    exit(0);
}
if (($argv[1] ?? '') === 'regeneracion') {
    [$id, $seg, $nueva, $vieja] = [(int) $argv[2], (int) $argv[3], (int) $argv[4], (int) $argv[5]];
    DB::beginTransaction();
    DB::table('iron_ia_recovery_items')->where('id', $id)->lockForUpdate()->first();
    DB::table('iron_ia_recovery_items')->where('id', $id)->update([
        'status' => IronIaRecoveryItem::STATUS_PROPOSED, 'proposal_routine_id' => $nueva,
        'superseded_routine_ids' => json_encode([$vieja]), 'regenerations' => 1,
    ]);
    DB::table('routines')->where('id', $vieja)->update(['status' => 'Retirada']);
    echo "RETENGO\n";
    sleep($seg);
    DB::commit();
    exit(0);
}

/** El modelo no se llama aquí: la regeneración recibe una rutina y una corrida ya hechas. */
class GeneradorDePrueba extends IntegralPlanGenerationService
{
    public static ?Routine $rutina = null;

    public static ?NutritionAiRun $run = null;

    public static int $llamadas = 0;

    /** @var (callable():void)|null lo que pasa «mientras» se llama al modelo */
    public static $durante = null;

    public function generateRecoveryProposal(Trainer $trainer, Member $member, string $campaign, int $days, array $confirmed = []): array
    {
        self::$llamadas++;
        if (self::$durante !== null) {
            (self::$durante)();
        }

        return ['routine' => self::$rutina, 'run' => self::$run];
    }
}

// ── Director ─────────────────────────────────────────────────────────────────
Artisan::call('migrate:fresh', ['--force' => true]);

$entrenador = Trainer::create(['full_name' => 'Coach Concurrencia', 'document' => '700', 'phone' => '+573007000000', 'status' => 'active']);
$usuario = User::create(['name' => 'Socio Concurrencia', 'email' => 'conc@ironbody.test', 'password' => 'secret-password', 'document' => '701',
    'status' => 'active', 'membership_start_date' => '2026-10-01', 'membership_end_date' => '2099-12-31']);
$socio = Member::create(['user_id' => $usuario->id, 'full_name' => 'Socio Concurrencia', 'document_number' => '701', 'phone' => '+573007000001',
    'status' => Member::STATUS_ACTIVE, 'training_level' => 'intermedio']);
MemberTrainerAssignment::create(['member_id' => $socio->id, 'trainer_id' => $entrenador->id, 'status' => MemberTrainerAssignment::STATUS_ACTIVE]);
$ej = Exercise::create(['name' => 'Press banca', 'local_name' => 'Press banca', 'muscle_group' => 'Pecho', 'body_part' => 'Pecho',
    'equipment' => 'barra', 'difficulty' => 'intermedio', 'target' => 'Pecho', 'video_path' => 'videos/c.mp4', 'provider' => 'local', 'external_id' => 'conc-1']);

// La rutina antigua (dentro de su corrida) y una propuesta en borrador.
$t = Carbon::parse('2026-11-05 15:00:00', 'UTC');
Carbon::setTestNow($t);
$run = NutritionAiRun::create(['uuid' => (string) Str::uuid(), 'member_id' => $socio->id, 'mode' => IntegralPlanGenerationService::RUN_MODE,
    'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'status' => 'ok', 'prompt_version' => 'v1', 'response_json' => ['routine' => []]]);
Carbon::setTestNow($t->copy()->addSeconds(3));
$original = Routine::create(['name' => 'Rutina IA 4 días', 'member_id' => $socio->id, 'trainer_id' => $entrenador->id, 'days_per_week' => 4,
    'is_assigned' => true, 'is_template' => false, 'status' => 'Activa', 'generated_by_ai' => true]);
RoutineExercise::create(['routine_id' => $original->id, 'exercise_id' => $ej->id, 'sets' => 3, 'reps' => '10', 'sort_order' => 0]);
MemberRoutineAssignment::create(['member_id' => $socio->id, 'routine_id' => $original->id, 'assigned_at' => now()]);
Carbon::setTestNow($t->copy()->addSeconds(6));
$run->touch();
Carbon::setTestNow(null);

$campana = 'concurrencia';
$corte = '2099-12-31';
$item = IronIaRecoveryItem::create(['campaign' => $campana, 'original_routine_id' => $original->id, 'member_id' => $socio->id,
    'trainer_id' => $entrenador->id, 'status' => IronIaRecoveryItem::STATUS_PENDING, 'proposed_days' => 6, 'original_days' => 4]);

$retener = function (string $rol, int $id, int $seg) use ($raiz) {
    $p = proc_open([PHP_BINARY, __FILE__, $rol, (string) $id, (string) $seg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos, $raiz);
    $linea = fgets($tubos[1]);
    if (trim((string) $linea) !== 'RETENGO') {
        fwrite(STDERR, "El retenedor no arrancó: {$linea}".stream_get_contents($tubos[2])."\n");
        exit(1);
    }

    return [$p, $tubos];
};
$soltar = function (array $h) {
    [$p, $tubos] = $h;
    stream_get_contents($tubos[1]);
    stream_get_contents($tubos[2]);

    return proc_close($p);
};

$resultados = [];

// 1. Reclamo concurrente.
$h = $retener('reclamo', $item->id, 3);
$t0 = microtime(true);
$res = app(RoutineRecoveryCampaign::class)->process($campana, $original->id, $corte);
$espera = round(microtime(true) - $t0, 1);
$soltar($h);
$item->refresh();
$resultados['reclamo'] = [
    'resultado' => $res['outcome'],
    'espero_segundos' => $espera,
    'estado_fila' => $item->status,
    'intentos' => $item->attempts,
    'propuestas' => Routine::where('status', 'Borrador')->count(),
    'ok' => $res['outcome'] === 'not_claimed' && $espera >= 2.0 && $item->attempts === 1 && Routine::where('status', 'Borrador')->count() === 0,
];

// 2. Publicación concurrente de la misma propuesta.
$propuesta = Routine::create(['name' => 'Propuesta: 6 días por confirmar · Rutina 6 días', 'member_id' => $socio->id, 'trainer_id' => $entrenador->id,
    'days_per_week' => 6, 'is_assigned' => false, 'is_template' => false, 'status' => 'Borrador', 'generated_by_ai' => true]);
RoutineExercise::create(['routine_id' => $propuesta->id, 'exercise_id' => $ej->id, 'sets' => 3, 'reps' => '10', 'sort_order' => 0]);
$item->forceFill(['status' => IronIaRecoveryItem::STATUS_PROPOSED, 'proposal_routine_id' => $propuesta->id,
    'proposal_base_name' => 'Rutina 6 días', 'claimed_at' => null])->save();

$h = $retener('publicacion', $item->id, 3);
$t0 = microtime(true);
app(RoutinePublisher::class)->publish($propuesta->fresh(), $entrenador);
$espera = round(microtime(true) - $t0, 1);
$soltar($h);
$item->refresh();
$resultados['publicacion'] = [
    'espero_segundos' => $espera,
    'estado_fila' => $item->status,
    'published_at' => $item->published_at?->format('Y-m-d H:i:s'),
    'publicado_por' => $item->published_by_trainer_id,
    'original' => $original->fresh()->status,
    'propuesta' => $propuesta->fresh()->status,
    'asignaciones_original' => MemberRoutineAssignment::where('routine_id', $original->id)->count(),
    'ok' => $espera >= 2.0
        && $item->published_at?->format('Y-m-d H:i:s') === '2026-11-14 10:00:00'
        && $item->published_by_trainer_id === null
        && $original->fresh()->status === 'Retirada'
        && $propuesta->fresh()->status === 'Activa',
];

// Una propuesta pendiente nueva (otra rutina original del mismo socio) para 3 y 4.
$nuevaPropuesta = function (string $campana) use ($socio, $entrenador, $ej) {
    $orig = Routine::create(['name' => 'Rutina IA '.$campana, 'member_id' => $socio->id, 'trainer_id' => $entrenador->id, 'days_per_week' => 4,
        'is_assigned' => true, 'is_template' => false, 'status' => 'Activa', 'generated_by_ai' => true]);
    MemberRoutineAssignment::create(['member_id' => $socio->id, 'routine_id' => $orig->id, 'assigned_at' => now()]);
    $prop = Routine::create(['name' => 'Propuesta: 4 días por confirmar · Rutina 4 días', 'member_id' => $socio->id, 'trainer_id' => $entrenador->id,
        'days_per_week' => 4, 'level' => 'intermedio', 'is_assigned' => false, 'is_template' => false, 'status' => 'Borrador', 'generated_by_ai' => true,
        'days' => [['name' => 'Día 1', 'exercises' => [['sets' => 3, 'reps' => '10']]]]]);
    RoutineExercise::create(['routine_id' => $prop->id, 'exercise_id' => $ej->id, 'sets' => 3, 'reps' => '10', 'sort_order' => 0]);
    $it = IronIaRecoveryItem::create(['campaign' => $campana, 'original_routine_id' => $orig->id, 'member_id' => $socio->id,
        'trainer_id' => $entrenador->id, 'status' => IronIaRecoveryItem::STATUS_PROPOSED, 'proposed_days' => 4, 'original_days' => 4,
        'proposal_routine_id' => $prop->id, 'proposal_base_name' => 'Rutina 4 días']);

    return [$orig, $prop, $it];
};
$confirmacion = ['days_per_week' => 4, 'session_minutes' => 60, 'training_level' => 'intermedio', 'objective' => 'Salud',
    'injuries' => 'Ninguna', 'availability_confirmed' => true, 'muscle_split_reviewed' => true];

// 3. Publicar la propuesta mientras otra la sustituye al regenerar.
[$orig3, $prop3, $item3] = $nuevaPropuesta('concurrencia-3');
$sustituta = Routine::create(['name' => 'Propuesta: 4 días por confirmar · Otra', 'member_id' => $socio->id, 'trainer_id' => $entrenador->id,
    'days_per_week' => 4, 'is_assigned' => false, 'is_template' => false, 'status' => 'Borrador', 'generated_by_ai' => true]);
$p = proc_open([PHP_BINARY, __FILE__, 'regeneracion', (string) $item3->id, '3', (string) $sustituta->id, (string) $prop3->id],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos, $raiz);
if (trim((string) fgets($tubos[1])) !== 'RETENGO') {
    fwrite(STDERR, 'El retenedor no arrancó: '.stream_get_contents($tubos[2])."\n");
    exit(1);
}
$t0 = microtime(true);
$codigo = null;
try {
    app(RoutinePublisher::class)->publish($prop3->fresh(), $entrenador, $confirmacion);
} catch (\App\Exceptions\IntegralPlanException $e) {
    $codigo = $e->code_;
}
$espera = round(microtime(true) - $t0, 1);
$soltar([$p, $tubos]);
$item3->refresh();
$resultados['publicar_la_sustituida'] = [
    'espero_segundos' => $espera,
    'codigo' => $codigo,
    'propuesta_vieja' => [$prop3->fresh()->status, (bool) $prop3->fresh()->is_assigned],
    'original' => $orig3->fresh()->status,
    'fila' => [$item3->status, $item3->proposal_routine_id, $item3->confirmation],
    'ok' => $espera >= 2.0 && $codigo === 'recovery_superseded'
        && $prop3->fresh()->status === 'Retirada' && ! $prop3->fresh()->is_assigned
        && $orig3->fresh()->status === 'Activa'
        && $item3->status === IronIaRecoveryItem::STATUS_PROPOSED && (int) $item3->proposal_routine_id === $sustituta->id
        && $item3->confirmation === null,
];

$falsa = function () use ($socio, $entrenador) {
    GeneradorDePrueba::$rutina = Routine::create(['name' => 'Rutina regenerada', 'member_id' => $socio->id, 'trainer_id' => $entrenador->id,
        'days_per_week' => 4, 'is_assigned' => false, 'is_template' => false, 'status' => 'Borrador', 'generated_by_ai' => true]);
    GeneradorDePrueba::$run = NutritionAiRun::create(['uuid' => (string) Str::uuid(), 'member_id' => $socio->id,
        'mode' => IntegralPlanGenerationService::RECOVERY_RUN_MODE, 'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'status' => 'ok',
        'prompt_version' => 'v1', 'response_json' => ['routine' => []], 'total_tokens' => 1000]);
    GeneradorDePrueba::$llamadas = 0;
    GeneradorDePrueba::$durante = null;
};
app()->bind(IntegralPlanGenerationService::class, GeneradorDePrueba::class);
app()->forgetInstance(RoutineRecoveryCampaign::class);
$regenerar = function (IronIaRecoveryItem $it) use ($entrenador, $confirmacion) {
    $t0 = microtime(true);
    $codigo = null;
    try {
        app(RoutineRecoveryCampaign::class)->regenerate($it->fresh(), $entrenador, $confirmacion);
    } catch (\App\Exceptions\IntegralPlanException $e) {
        $codigo = $e->code_;
    }

    return [round(microtime(true) - $t0, 1), $codigo];
};

// 4. Publicación confirmando ANTES de regenerar: se rechaza sin llamar al modelo.
[$orig4, $prop4, $item4] = $nuevaPropuesta('concurrencia-4');
$falsa();
$h = $retener('publicacion', $item4->id, 3);
[$espera, $codigo] = $regenerar($item4);
$soltar($h);
$item4->refresh();
$resultados['regenerar_tras_publicar'] = [
    'espero_segundos' => $espera,
    'codigo' => $codigo,
    'llamadas_al_modelo' => GeneradorDePrueba::$llamadas,
    'fila' => [$item4->status, $item4->regeneration_attempts, $item4->superseded_routine_ids],
    'ok' => $espera >= 2.0 && $codigo === 'recovery_not_pending' && GeneradorDePrueba::$llamadas === 0
        && $item4->status === IronIaRecoveryItem::STATUS_PUBLISHED && (int) $item4->regeneration_attempts === 0
        && $item4->superseded_routine_ids === null && GeneradorDePrueba::$rutina->fresh()->status === 'Borrador',
];

// 5. Publicación DURANTE la llamada al modelo: la nueva queda retirada y sustituida.
[$orig5, $prop5, $item5] = $nuevaPropuesta('concurrencia-5');
$falsa();
$h5 = null;
GeneradorDePrueba::$durante = function () use (&$h5, $retener, $item5) {
    $h5 = $retener('publicacion', $item5->id, 3);
};
[$espera, $codigo] = $regenerar($item5);
$soltar($h5);
$item5->refresh();
$regenerada = GeneradorDePrueba::$rutina->fresh();
$resultados['publicar_durante_la_regeneracion'] = [
    'espero_segundos' => $espera,
    'codigo' => $codigo,
    'regenerada' => [$regenerada->status, (bool) $regenerada->is_assigned],
    'fila' => [$item5->status, $item5->proposal_routine_id, $item5->regenerations, $item5->regeneration_attempts, $item5->superseded_routine_ids],
    'ok' => $espera >= 2.0 && $codigo === 'recovery_not_pending'
        && $regenerada->status === 'Retirada' && ! $regenerada->is_assigned
        && $item5->status === IronIaRecoveryItem::STATUS_PUBLISHED && (int) $item5->proposal_routine_id === $prop5->id
        && (int) $item5->regenerations === 0 && (int) $item5->regeneration_attempts === 1
        && $item5->superseded_routine_ids === [$regenerada->id]
        && IronIaRecoveryItem::supersededBy($regenerada->id) !== null,
];

// 6. Dos regeneraciones por la última plaza.
[$orig6, $prop6, $item6] = $nuevaPropuesta('concurrencia-6');
$item6->forceFill(['regenerations' => 2, 'regeneration_attempts' => 2])->save();
$falsa();
$h = $retener('ultima', $item6->id, 3);
[$espera, $codigo] = $regenerar($item6);
$soltar($h);
$item6->refresh();
$resultados['dos_por_la_ultima_plaza'] = [
    'espero_segundos' => $espera,
    'codigo' => $codigo,
    'llamadas_al_modelo' => GeneradorDePrueba::$llamadas,
    'fila' => [$item6->status, $item6->regenerations, $item6->regeneration_attempts],
    'ok' => $espera >= 2.0 && $codigo === 'recovery_regeneration_limit' && GeneradorDePrueba::$llamadas === 0
        && (int) $item6->regenerations === 3 && (int) $item6->regeneration_attempts === 3,
];

// 7. Publicar frente a retirar.
[$orig7, $prop7, $item7] = $nuevaPropuesta('concurrencia-7');
$p7 = proc_open([PHP_BINARY, __FILE__, 'publicar_lento', (string) $item7->id, '3', (string) $prop7->id],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos7, $raiz);
if (trim((string) fgets($tubos7[1])) !== 'RETENGO') {
    fwrite(STDERR, 'El retenedor no arrancó: '.stream_get_contents($tubos7[2])."\n");
    exit(1);
}
$t0 = microtime(true);
$error = null;
try {
    app(RoutinePublisher::class)->retire($prop7->fresh(), $entrenador);
} catch (\Throwable $e) {
    $error = $e::class.': '.mb_substr($e->getMessage(), 0, 120);
}
$espera = round(microtime(true) - $t0, 1);
$errRetenedor = trim(stream_get_contents($tubos7[2]));
stream_get_contents($tubos7[1]);
$rcRetenedor = proc_close($p7);
$item7->refresh();
$resultados['publicar_frente_a_retirar'] = [
    'espero_segundos' => $espera,
    'error' => $error,
    'retenedor' => [$rcRetenedor, mb_substr($errRetenedor, 0, 160)],
    'rutina' => [$prop7->fresh()->status, $prop7->fresh()->name],
    'fila' => $item7->status,
    'ok' => $espera >= 2.0 && $error === null && $rcRetenedor === 0
        && $prop7->fresh()->status === 'Retirada' && str_ends_with($prop7->fresh()->name, '(revisada)')
        && $item7->status === IronIaRecoveryItem::STATUS_DISCARDED,
];

echo json_encode($resultados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
exit(collect($resultados)->every(fn ($r) => $r['ok']) ? 0 : 1);
