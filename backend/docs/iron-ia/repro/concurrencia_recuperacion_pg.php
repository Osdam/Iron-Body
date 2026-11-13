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

echo json_encode($resultados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
exit($resultados['reclamo']['ok'] && $resultados['publicacion']['ok'] ? 0 : 1);
