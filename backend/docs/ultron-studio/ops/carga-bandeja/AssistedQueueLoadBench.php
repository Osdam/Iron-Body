<?php

namespace Tests\Ops\CargaBandeja;

use App\Http\Controllers\Crm\UltronStudio\UltronStudioOutreachController;
use App\Http\Middleware\LimitRequestBodySize;
use App\Models\Admin;
use App\Models\RolePermission;
use App\Services\Outreach\Run\AssistedQueue;
use App\Services\Outreach\Run\OutreachAccess;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * BANCO DE CARGA de la bandeja asistida (m-1 de `informe-reviewer-ronda3.md`; cierre de la bandeja, punto 5). NO
 * es de la batería: vive fuera de `tests/` y se lanza por su ruta, contra el PostgreSQL 14 local y desechable:
 *
 *   UNIT_DB=ultron_studio_cierre_bandeja bash docs/ultron-studio/ops/pgunit.sh --no-logging \
 *     docs/ultron-studio/ops/carga-bandeja/AssistedQueueLoadBench.php
 *
 * Siembra, dentro de la transacción de la prueba (nada queda), `N` trabajos asistidos `started` en vivo (50, 500
 * y 5 000) de `K` = 5 estrategias vivas, cada uno de un socio RESOLUBLE distinto (la foto con la política es la
 * parte cara), con plazos escalonados; Ana tiene 20 reclamados. Mide, por HTTP (el controlador real, con la
 * autenticación y los permisos) y con una instancia nueva por petición, el número de consultas, el tiempo de
 * pared y el de la base (la mediana de 5) de:
 *  - `GET outreach/assisted/queue` en `unclaimed` (limit 50), `mine` (limit 50) y `all` (limit 20);
 *  - `POST outreach/assisted/{uuid}/claim` (un trabajo libre distinto cada vez);
 *  - y lo mismo de `unclaimed` con 20 000 prospectos «no contactar» ajenos en la base (`LeadFacts::optedOutPhones()`
 *    los lee todos en cada tanda) y con las 250 primeras filas retenidas por «no contactar» (el peor caso: la
 *    página lee las 4 tandas de {@see AssistedQueue::MAX_SCAN} y no llena nada).
 * Escribe la tabla en STDERR, en el formato del informe. Las aserciones solo comprueban que la carga es la
 * esperada (que lo que se mide es lo que se dice).
 */
class AssistedQueueLoadBench extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const NOW = '2026-11-17 15:15:00';

    private const STRATEGIES = 5;

    private const MINE = 20;

    private const FOREIGN_DNC = 20000;

    private const HEAD_HELD = 250;

    private const REPEAT = 5;

    private const BASE = '/api/admin/ultron-studio/';

    private bool $escuchando = false;

    private int $consultas = 0;

    private float $msBase = 0.0;

    /** @var list<array{0: float, 1: string}> las sentencias de la última repetición (ms, SQL) */
    private array $traza = [];

    /** @return array<string, array{0: int}> */
    public static function tamanos(): array
    {
        return ['50 trabajos' => [50], '500 trabajos' => [500], '5 000 trabajos' => [5000]];
    }

    #[DataProvider('tamanos')]
    public function test_la_pagina_y_el_reclamo_con_carga(int $n): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('El banco mide PostgreSQL 14.');
        }
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true, 'commercial.enabled' => false]);
        $this->runFlags('live');
        $gente = $this->threePeople();
        $this->routesAndPermissions();
        $ana = $this->actingAsAdmin($gente['ana']);
        $beto = $this->actingAsAdmin($gente['beto']);

        $t0 = microtime(true);
        ['jobs' => $trabajos, 'members' => $socios] = $this->seedLoad($n);
        foreach (array_slice($trabajos, 0, self::MINE) as $id) {
            DB::table('outreach_jobs')->where('id', $id)->update(['assisted_claimed_by_admin_id' => (int) $gente['ana']->id, 'assisted_claimed_by_name' => 'Ana', 'assisted_claimed_at' => now(), 'updated_at' => now()]);
        }
        $sembrado = microtime(true) - $t0;
        $this->assertSame($n, DB::table('outreach_jobs')->where('channel', 'assisted')->where('status', 'started')->count());

        $filas = [];
        $filas[] = ['unclaimed (limit 50)', ...$this->measure(fn () => $this->getJson(self::BASE.'outreach/assisted/queue?filter=unclaimed&limit=50', $beto)->assertOk()->json('meta'))];
        $filas[] = ['mine (limit 50)', ...$this->measure(fn () => $this->getJson(self::BASE.'outreach/assisted/queue?filter=mine&limit=50', $ana)->assertOk()->json('meta'))];
        $filas[] = ['all (limit 20)', ...$this->measure(fn () => $this->getJson(self::BASE.'outreach/assisted/queue?filter=all&limit=20', $beto)->assertOk()->json('meta'))];
        // Los últimos del orden: la cabeza queda libre para el peor caso.
        $libres = array_slice($trabajos, -self::REPEAT);
        $uuids = DB::table('outreach_jobs')->whereIn('id', $libres)->orderBy('id')->pluck('uuid')->all();
        $i = 0;
        $filas[] = ['claim (201)', ...$this->measure(function () use (&$i, $uuids, $beto): array {
            return $this->postJson(self::BASE.'outreach/assisted/'.$uuids[$i++].'/claim', ['clientRequestId' => (string) Str::uuid()], $beto)->assertCreated()->json('meta');
        })];

        // 20 000 prospectos «no contactar» que no son de nadie de la bandeja (LeadFacts::optedOutPhones() los lee todos por tanda).
        $this->foreignDnc(self::FOREIGN_DNC);
        $filas[] = ['unclaimed (limit 50), +20 000 DNC ajenos', ...$this->measure(fn () => $this->getJson(self::BASE.'outreach/assisted/queue?filter=unclaimed&limit=50', $beto)->assertOk()->json('meta'))];

        // El peor caso: las primeras filas del orden, retenidas por «no contactar» (en PHP): la página lee 4 tandas.
        $cabeza = array_slice($socios, self::MINE, min(self::HEAD_HELD, $n - self::MINE));
        foreach (array_chunk($cabeza, 500) as $trozo) {
            DB::table('marketing_leads')->insert(array_map(static fn (int $m): array => [
                'channel' => 'whatsapp', 'phone' => '31'.str_pad((string) $m, 8, '0', STR_PAD_LEFT), 'status' => 'new', 'temperature' => 'warm',
                'consent_status' => 'granted', 'do_not_contact' => true, 'member_id' => $m, 'created_at' => now(), 'updated_at' => now(),
            ], $trozo));
        }
        $peor = $this->measure(fn () => $this->getJson(self::BASE.'outreach/assisted/queue?filter=unclaimed&limit=50', $beto)->assertOk()->json('meta'));
        $filas[] = ['unclaimed (limit 50), cabeza retenida, +20 000 DNC', ...$peor];
        if ($n >= self::MINE + self::HEAD_HELD) {
            $this->assertTrue($peor[3]['scanCapped'], 'el peor caso llega al tope de lectura');
        }

        fwrite(STDERR, "\n\n### N = {$n} trabajos asistidos, ".self::STRATEGIES.' estrategias (siembra '.round($sembrado, 1)." s)\n\n"
            .'| Petición | Consultas | Pared (ms, mediana de '.self::REPEAT.') | Base (ms, mediana) | meta |'."\n|---|---|---|---|---|\n");
        foreach ($filas as [$que, $consultas, $pared, $base, $meta]) {
            fwrite(STDERR, "| {$que} | {$consultas} | {$pared} | {$base} | ".json_encode($meta)." |\n");
        }
        fwrite(STDERR, "\nLas tres sentencias más lentas de la última repetición:\n");
        foreach ($filas as [$que, , , , , $lentas]) {
            fwrite(STDERR, "- {$que}: ".implode(' | ', $lentas)."\n");
        }
    }

    /**
     * La mediana de {@see REPEAT} peticiones: consultas (la última), tiempo de pared y tiempo de la base, en ms.
     *
     * @return array{0: int, 1: float, 2: float, 3: mixed}
     */
    private function measure(callable $peticion): array
    {
        if (! $this->escuchando) {
            $this->escuchando = true;
            DB::listen(function ($q): void {
                $this->consultas++;
                $this->msBase += (float) $q->time;
                $this->traza[] = [(float) $q->time, (string) preg_replace('/\(\?(, \?)*\)/', '(?…)', (string) $q->sql)];
            });
        }
        $pared = [];
        $base = [];
        $consultas = 0;
        $meta = null;
        for ($r = 0; $r < self::REPEAT; $r++) {
            [$n0, $ms0] = [$this->consultas, $this->msBase];
            $this->traza = [];
            $t = hrtime(true);
            $meta = $peticion();
            $pared[] = (hrtime(true) - $t) / 1e6;
            $base[] = $this->msBase - $ms0;
            $consultas = $this->consultas - $n0;
        }
        sort($pared);
        sort($base);
        $lentas = $this->traza;
        usort($lentas, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return [$consultas, round($pared[intdiv(self::REPEAT, 2)], 1), round($base[intdiv(self::REPEAT, 2)], 1), $meta,
            array_map(static fn (array $s): string => round($s[0], 1).' ms · '.mb_substr($s[1], 0, 110), array_slice($lentas, 0, 3))];
    }

    private function routesAndPermissions(): void
    {
        Route::middleware('api')->prefix('api')->group(function (): void {
            Route::prefix('admin/ultron-studio')->middleware(LimitRequestBodySize::class.':65536')->group(static fn () => UltronStudioOutreachController::routes());
        });
        foreach (OutreachAccess::PERMISSIONS as $llave) {
            RolePermission::query()->updateOrCreate(['role' => Admin::ROLE_SUPER_ADMIN, 'permission' => $llave], ['granted' => true]);
        }
        Cache::forget('crm.role_permissions.v1');
        app(RolePermissionPolicy::class)->flush();
    }

    /**
     * `$n` trabajos asistidos `started` en vivo, repartidos entre {@see STRATEGIES} estrategias vivas, cada uno de un
     * socio resoluble distinto (un usuario y una ficha), con plazos escalonados. Por lotes y por las filas del
     * esquema: los trabajos nacen `planned` y avanzan por los disparadores reales.
     *
     * @return array{jobs: list<int>, members: list<int>} los ids, en el orden de la bandeja (plazo)
     */
    private function seedLoad(int $n): array
    {
        $estrategias = [];
        for ($k = 0; $k < self::STRATEGIES; $k++) {
            $estrategias[] = $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
        }
        $plantilla = $this->assistedTemplate();
        $huella = (string) DB::table('ultron_studio_message_templates')->where('id', $plantilla)->value('content_hash');
        $socios = [];
        $marca = Str::lower(Str::random(6));
        foreach (array_chunk(range(1, $n), 500) as $trozo) {
            DB::table('users')->insert(array_map(static fn (int $i): array => [
                'name' => "Carga {$i}", 'email' => "carga{$i}-{$marca}@run.test", 'password' => 'x', 'plan' => 'Mensual',
                'membership_end_date' => '2026-12-31', 'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00',
            ], $trozo));
        }
        $usuarios = DB::table('users')->where('email', 'like', "%-{$marca}@run.test")->orderBy('id')->pluck('id')->all();
        foreach (array_chunk($usuarios, 500) as $trozo) {
            DB::table('members')->insert(array_map(static fn (int $u): array => [
                'member_uuid' => (string) Str::uuid(), 'access_hash' => hash('sha256', 'carga'.$u), 'full_name' => "Carga {$u}",
                'document_number' => 'CAR'.$u.'X', 'user_id' => $u, 'birth_date' => '1990-05-05', 'is_minor' => false,
                'status' => 'active', 'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00',
            ], $trozo));
        }
        $socios = DB::table('members')->whereIn('user_id', $usuarios)->orderBy('id')->pluck('id')->all();

        $trabajos = [];
        foreach (array_chunk($socios, 500, true) as $trozo) {
            $inscripciones = [];
            foreach ($trozo as $i => $m) {
                $inscripciones[] = $this->enrollmentRow($estrategias[$i % self::STRATEGIES], ['mode' => 'live', 'subject_key' => 'm:'.$m, 'member_id' => $m]);
            }
            DB::table('outreach_enrollments')->insert($inscripciones);
            $porPersona = DB::table('outreach_enrollments')->whereIn('subject_key', array_map(static fn (int $m): string => 'm:'.$m, $trozo))->get()->keyBy('subject_key');
            $filas = [];
            foreach ($trozo as $i => $m) {
                $e = $porPersona['m:'.$m];
                $filas[] = [
                    'uuid' => (string) Str::uuid(), 'enrollment_id' => (int) $e->id, 'subject_key' => $e->subject_key, 'subject_ref' => $e->subject_ref,
                    'strategy_id' => $e->strategy_id, 'strategy_version_id' => $e->strategy_version_id, 'content_hash' => $e->content_hash,
                    'publication_seq' => $e->publication_seq, 'part' => $e->part, 'studio_ref_kind' => $e->studio_ref_kind,
                    'studio_version_uuid' => $e->studio_version_uuid, 'studio_content_hash' => $e->studio_content_hash,
                    'catalog_version' => $e->catalog_version, 'evaluator_version' => $e->evaluator_version, 'mode' => 'live',
                    'step_index' => 0, 'attempt' => 1, 'channel' => 'assisted', 'medium' => 'whatsapp', 'template_version_id' => $plantilla,
                    'template_hash' => $huella, 'idempotency_key' => hash('sha256', "carga-{$marca}-{$m}"), 'status' => 'planned',
                    'not_before' => '2026-11-17 15:00:00', 'expires_at' => Carbon::parse('2026-11-18 09:00:00', 'UTC')->addSeconds($i)->format('Y-m-d H:i:s'),
                    'budget_day' => '2026-11-17', 'reserved_units' => 0, 'reserved_micros' => 0, 'status_changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            DB::table('outreach_jobs')->insert($filas);
            $ids = DB::table('outreach_jobs')->whereIn('enrollment_id', $porPersona->pluck('id')->all())->orderBy('expires_at')->orderBy('id')->pluck('id')->all();
            foreach ([['status' => 'claimed', 'claimed_at' => now()], ['status' => 'dispatching'], ['status' => 'started']] as $paso) {
                DB::table('outreach_jobs')->whereIn('id', $ids)->update($paso + ['status_changed_at' => now()]);
            }
            $trabajos = [...$trabajos, ...$ids];
        }

        return ['jobs' => $trabajos, 'members' => $socios];
    }

    /** `$n` prospectos «no contactar» sin ficha de socio y con números que no son de nadie de la bandeja. */
    private function foreignDnc(int $n): void
    {
        foreach (array_chunk(range(1, $n), 1000) as $trozo) {
            DB::table('marketing_leads')->insert(array_map(static fn (int $i): array => [
                'channel' => 'whatsapp', 'phone' => '32'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'status' => 'new', 'temperature' => 'warm',
                'consent_status' => 'granted', 'do_not_contact' => true, 'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00',
            ], $trozo));
        }
    }
}
