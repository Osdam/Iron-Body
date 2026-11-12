<?php

namespace Tests\Feature\Outreach\Run;

use App\Http\Controllers\Crm\UltronStudio\UltronStudioOutreachController;
use App\Http\Middleware\LimitRequestBodySize;
use App\Models\Admin;
use App\Models\MarketingAiAction;
use App\Models\MarketingLead;
use App\Models\RolePermission;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Core\StrategyChainSignature;
use App\Services\Outreach\Run\AssistedQueue;
use App\Services\Outreach\Run\OutcomeRecorder;
use App\Services\Outreach\Run\OutreachAccess;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachException;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachReconciler;
use App\Services\Outreach\Run\OutreachStop;
use App\Services\Outreach\Run\OutreachStore;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * La tercera ronda de la BANDEJA ASISTIDA (`informe-security-bandeja-condiciones.md`, §7), sobre las clases
 * REALES de la ejecución. Al ofrecer, al reclamar y al enseñar lo reclamado, la bandeja aplica lo que la
 * escalera negaría HOY por la persona o por la estrategia:
 *
 *  - N-1: el «no me contacten» que llega DESPUÉS de la salida (`do_not_contact`, el del hermano, el
 *    *opt-out* comercial, el consentimiento denegado, la exclusión de voz y la supresión del libro) retiene
 *    con `opted_out`; sin poder comprobarlo, `policy_unknown` (falla cerrado);
 *  - N-2: la estrategia pausada, retirada, revertida, archivada o con la cadena ilegible retiene con
 *    `strategy_stopped`;
 *  - la prioridad única de los siete motivos;
 *  - m-1: la repetición de `claim()` con la inscripción cerrada da 409 `enrollment_closed`;
 *  - m-2: un freno echado entre la lectura y el `UPDATE` deja la fila reclamada sin contacto;
 *  - m-3: `scanCapped` cuando la página llega al tope de lectura sin llenarse;
 *  - m-8: la vista `all` no se llena con lo reclamado por otra persona y ya vencido.
 *
 * El martes 17-11-2026 a las 10:00 de Bogotá (15:00Z), en vivo, con la fuente de eventos encendida (L9-26),
 * como el resto de B9-run. El reloj, en UTC.
 */
class H9AssistedOptOutStrategyTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const NOW = '2026-11-17 15:00:00';

    private const BASE = '/api/admin/ultron-studio/';

    /** @var array<string, mixed> la estrategia viva de la prueba */
    private array $vivo = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true, 'commercial.enabled' => false]);
        $this->threePeople();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── El mundo ────────────────────────────────────────────────────────────

    /**
     * Una estrategia viva (parte canario) y un trabajo asistido que SALIÓ en vivo por cada teléfono, por el
     * planificador y el despachador reales. El reloj queda 15 min después.
     *
     * @param  list<string>  $phones
     * @return list<array{member: array<string, mixed>, job: object}>
     */
    private function startedFor(array $phones): array
    {
        $ahora = self::utcAt(self::NOW);
        $this->runFlags('live');
        $this->vivo = $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
        $socios = [];
        foreach ($phones as $telefono) {
            $socios[] = $this->qualifyingMember($ahora, 5, $telefono);
        }
        $planner = new OutreachPlanner;
        $planner->sweep($ahora, true);
        $this->assertSame(count($phones), $planner->planDue($ahora)['planned'] ?? 0);
        Carbon::setTestNow(Carbon::instance($ahora)->addMinutes(15));
        $this->assertSame(['started' => count($phones)], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);

        return array_map(static fn (array $s): array => [
            'member' => $s,
            'job' => DB::table('outreach_jobs')->where('subject_key', $s['key'])->first(),
        ], $socios);
    }

    /**
     * `$n` trabajos asistidos `started` en vivo de la estrategia de la prueba, por las filas del esquema (nacen
     * `planned` y avanzan por los disparadores reales). Sin `$persona`, de personas que no se resuelven.
     *
     * @param  array<string, mixed>  $persona  columnas de la inscripción (`subject_key`, `member_id`, `cycle_key`)
     * @param  array<string, mixed>|null  $live  otra estrategia viva (una persona tiene una inscripción por estrategia)
     * @return list<int> ids de los trabajos
     */
    private function seedStarted(int $n, string $expiresAt, array $persona = [], string $medium = 'whatsapp', bool $resolvable = false, ?array $live = null): array
    {
        $plantilla = $this->assistedTemplate();
        $huella = (string) DB::table('ultron_studio_message_templates')->where('id', $plantilla)->value('content_hash');
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $fila = $persona;
            if ($resolvable) {
                $socio = $this->socio();
                $fila = ['subject_key' => 'm:'.$socio['member'], 'member_id' => $socio['member']] + $fila;
            }
            $e = $this->insertEnrollment($live ?? $this->vivo, ['mode' => 'live'] + $fila);
            $ids[] = $this->insertJob($e, ['channel' => 'assisted', 'medium' => $medium, 'template_version_id' => $plantilla, 'template_hash' => $huella,
                'not_before' => '2026-11-17 15:00:00', 'expires_at' => $expiresAt, 'budget_day' => '2026-11-17']);
        }
        foreach ([['status' => 'claimed', 'claimed_at' => now()], ['status' => 'dispatching'], ['status' => 'started']] as $paso) {
            DB::table('outreach_jobs')->whereIn('id', $ids)->update($paso + ['status_changed_at' => now()]);
        }

        return $ids;
    }

    /** Reclamado a mano (sin pasar por la bandeja, que no lo dejaría): lo que pasó ANTES de que llegara la señal. */
    private function claimedBy(int $jobId, Admin $who): void
    {
        DB::table('outreach_jobs')->where('id', $jobId)->update([
            'assisted_claimed_by_admin_id' => (int) $who->id, 'assisted_claimed_by_name' => (string) $who->name,
            'assisted_claimed_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function uuidOf(int $jobId): string
    {
        return (string) DB::table('outreach_jobs')->where('id', $jobId)->value('uuid');
    }

    private function assertClaimWithheld(AssistedQueue $cola, string $jobUuid, string $why, string $code = 'not_in_queue', ?Admin $who = null): void
    {
        try {
            $cola->claim($who ?? $this->threePeople()['beto'], $jobUuid, (string) Str::uuid());
            $this->fail("Se reclamó un trabajo que la bandeja tenía que retener ({$why}).");
        } catch (OutreachException $e) {
            $this->assertSame([409, $code], [$e->status, $e->errorCode], $why);
        }
    }

    /** @param  list<array<string, mixed>>  $vista @return array<string, mixed>|null */
    private static function row(array $vista, string $uuid): ?array
    {
        foreach ($vista as $fila) {
            if ($fila['uuid'] === $uuid) {
                return $fila;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>|null  $fila @return array{0: mixed, 1: bool, 2: mixed} */
    private static function contact(?array $fila): array
    {
        if ($fila === null) {
            return ['falta la fila', false, 'falta la fila'];
        }

        return [$fila['person'], $fila['script'] !== null, array_key_exists('contactWithheld', $fila) ? $fila['contactWithheld'] : 'falta la clave'];
    }

    /** Lo que la bandeja NO puede escribir al retener. @return array<string, mixed> */
    private static function rowsSnapshot(): array
    {
        return [
            'jobs' => DB::table('outreach_jobs')->orderBy('id')->get(['id', 'status', 'cancel_reason', 'assisted_claimed_by_admin_id', 'updated_at'])->map(fn ($r) => (array) $r)->all(),
            'enrollments' => DB::table('outreach_enrollments')->orderBy('id')->get(['id', 'status', 'exit_reason', 'next_due_at', 'updated_at'])->map(fn ($r) => (array) $r)->all(),
            'decisions' => DB::table('outreach_decisions')->count(),
            'outcomes' => DB::table('outreach_outcomes')->count(),
        ];
    }

    // ── N-1 · El «no me contacten» que llega DESPUÉS de la salida ───────────

    /** @return array<string, array{0: string}> */
    public static function senales(): array
    {
        return [
            'do_not_contact (lo marca el agente o ULTRON)' => ['dnc'],
            'consentimiento denegado' => ['consent_denied'],
            'opt-out comercial por WhatsApp' => ['commercial_opt_out'],
            'do_not_contact de un prospecto con su mismo número' => ['sibling_dnc'],
            'posible palabra de baja en el libro (supresión, SC-2)' => ['opt_out_word'],
        ];
    }

    /** Una señal de «no contactar» que llega DESPUÉS de la salida, por el camino real de cada una. @param  array{member: array<string, mixed>, job: object}  $p */
    private function signal(string $senal, array $p): void
    {
        $lead = (int) $p['member']['lead'];
        match ($senal) {
            // `SalesAgentOrchestratorService::execMarkDoNotContact()` escribe las dos; aquí, cada una por separado.
            'dnc' => MarketingLead::query()->findOrFail($lead)->forceFill(['do_not_contact' => true])->save(),
            'consent_denied' => MarketingLead::query()->findOrFail($lead)->forceFill(['consent_status' => MarketingLead::CONSENT_DENIED])->save(),
            'commercial_opt_out' => MarketingAiAction::create([
                'lead_id' => $lead,
                'conversation_id' => (int) DB::table('marketing_conversations')->where('lead_id', $lead)->value('id'),
                'action_type' => 'commercial_opt_out', 'status' => 'executed',
            ]),
            'sibling_dnc' => $this->lead(['phone' => (string) DB::table('marketing_leads')->where('id', $lead)->value('phone'), 'do_not_contact' => true]),
            // `WhatsappTemplateOutcomes`: una POSIBLE baja deja `human_requested` con el detalle `opt_out_word` y deriva la inscripción.
            'opt_out_word' => (new OutcomeRecorder)->record(
                DB::table('outreach_enrollments')->where('id', $p['job']->enrollment_id)->first(),
                'human_requested', 'inbound_message', 'job:'.$p['job']->uuid.':possible_opt_out', now(), ['job' => $p['job'], 'occurredAt' => now(), 'detailCode' => 'opt_out_word'],
            ),
        };
    }

    #[DataProvider('senales')]
    public function test_n1_la_senal_de_no_contactar_que_llega_despues_de_salir_retiene_sin_reclamar_al_reclamante_y_en_la_repeticion(string $senal): void
    {
        $gente = $this->threePeople();
        [$mio, $libre] = $this->startedFor(['3001234561', '3001234562']);
        $uuidMio = (string) $mio['job']->uuid;
        $uuidLibre = (string) $libre['job']->uuid;
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], $uuidMio, (string) Str::uuid());
        $this->assertSame([['kind' => 'member', 'id' => $mio['member']['member']], true, null], self::contact($cola->queue($gente['ana'], 'mine')[0]), 'antes: el enlace y el guion');
        $this->assertNotNull(self::row($cola->queue($gente['beto'], 'unclaimed'), $uuidLibre), 'antes: el libre se ofrece');

        // DESPUÉS de salir, las dos personas piden que no las contacten.
        $this->signal($senal, $mio);
        $this->signal($senal, $libre);
        $antes = self::rowsSnapshot();
        Log::spy();

        // Sin reclamar: no se ofrece, no se reclama, y se cuenta sin decir de quién.
        $this->assertSame([], $cola->queue($gente['beto'], 'unclaimed'), "{$senal}: no se ofrece");
        $this->assertNull(self::row($cola->queue($gente['beto'], 'all'), $uuidLibre));
        $this->assertSame(1, $cola->page($gente['beto'], 'unclaimed')['withheld']);
        $this->assertClaimWithheld($cola, $uuidLibre, $senal);
        Log::shouldHaveReceived('warning')->once()->with('outreach.assisted.claim_withheld', ['job' => $uuidLibre, 'reason' => 'opted_out']);
        $this->assertNull(DB::table('outreach_jobs')->where('uuid', $uuidLibre)->value('assisted_claimed_by_admin_id'));

        // El reclamante: en `mine`, en `all` y en la repetición, sin enlace ni guion y con el motivo.
        foreach (['mine', 'all'] as $vista) {
            $this->assertSame([null, false, 'opted_out'], self::contact(self::row($cola->queue($gente['ana'], $vista), $uuidMio)), "{$senal}: {$vista}");
        }
        $r = $cola->claim($gente['ana'], $uuidMio, (string) Str::uuid());
        $this->assertTrue($r['replayed']);
        $this->assertSame([null, false, 'opted_out'], self::contact($r['job']), "{$senal}: la repetición");
        // A otra persona no se le cuenta: la fila de Ana va sin enlace, sin guion y sin motivo.
        $this->assertSame([null, false, null], self::contact(self::row($cola->queue($gente['beto'], 'all'), $uuidMio)));
        $this->assertStringNotContainsString('300123456', json_encode([$cola->queue($gente['ana']), $cola->queue($gente['beto']), $r]));
        $this->assertSame($antes, self::rowsSnapshot(), 'la bandeja no escribe nada al retener');

        // `recordOutcome()` no cambia.
        $this->assertSame(OutcomeRecorder::RESULT_RECORDED, $cola->recordOutcome($gente['ana'], $uuidMio, 'opted_out', (string) Str::uuid())['result']);
    }

    public function test_n1_la_exclusion_de_voz_retiene_el_asistido_por_voz_y_no_el_de_whatsapp_de_la_misma_persona(): void
    {
        $gente = $this->threePeople();
        $this->startedFor(['3001234567']);
        $socio = $this->socio();
        $persona = ['subject_key' => 'm:'.$socio['member'], 'member_id' => $socio['member']];
        $contacto = $this->voiceContact('member', $socio['member'], '+573005550101');
        [$voz] = $this->seedStarted(1, '2026-11-18 09:00:00', $persona, 'voice');
        // El de WhatsApp, de OTRA estrategia viva (una persona tiene una inscripción por estrategia).
        $otra = $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
        [$whatsapp] = $this->seedStarted(1, '2026-11-18 10:00:00', $persona, 'whatsapp', live: $otra);
        $cola = new AssistedQueue;
        $libres = static fn () => array_column($cola->queue($gente['beto'], 'unclaimed', 50), 'uuid');
        $this->assertContains($this->uuidOf($voz), $libres(), 'sin exclusión, el de voz se ofrece (la voz no pide aquí su consentimiento, solo que no esté denegado)');

        // «No volver a llamar»: la exclusión de voz de su número (N1 `voice_excluded`).
        $this->voiceExclusion($contacto['hash']);
        $this->assertNotContains($this->uuidOf($voz), $libres(), 'el de voz ya no se ofrece');
        $this->assertContains($this->uuidOf($whatsapp), $libres(), 'el de WhatsApp sí: la exclusión es del medio voz');
        Log::spy();
        $this->assertClaimWithheld($cola, $this->uuidOf($voz), 'exclusión de voz');
        Log::shouldHaveReceived('warning')->once()->with('outreach.assisted.claim_withheld', ['job' => $this->uuidOf($voz), 'reason' => 'opted_out']);
        $this->claimedBy($voz, $gente['ana']);
        $this->assertSame([null, false, 'opted_out'], self::contact($cola->queue($gente['ana'], 'mine')[0]));
    }

    public function test_n1_sin_poder_comprobarlo_retiene_con_policy_unknown_tambien_sin_la_queja(): void
    {
        $gente = $this->threePeople();
        $this->startedFor(['3001234567']);
        // Personas que no se resuelven (la escalera las niega con `unknown_subject`): una reclamada antes, otra libre.
        [$mio, $libre] = $this->seedStarted(2, '2026-11-18 09:00:00');
        $this->claimedBy($mio, $gente['ana']);
        // La queja apagada por configuración: lo que retiene es SOLO la política sin datos.
        config(['outreach.complaint_days' => 0]);
        $cola = new AssistedQueue;

        $this->assertSame([null, false, 'policy_unknown'], self::contact(self::row($cola->queue($gente['ana'], 'mine'), $this->uuidOf($mio))));
        $this->assertSame([null, false, 'policy_unknown'], self::contact($cola->claim($gente['ana'], $this->uuidOf($mio), (string) Str::uuid())['job']));
        $this->assertNull(self::row($cola->queue($gente['beto'], 'unclaimed'), $this->uuidOf($libre)), 'sin poder comprobarlo, no se ofrece');
        Log::spy();
        $this->assertClaimWithheld($cola, $this->uuidOf($libre), 'persona sin resolver');
        Log::shouldHaveReceived('warning')->once()->with('outreach.assisted.claim_withheld', ['job' => $this->uuidOf($libre), 'reason' => 'policy_unknown']);
        $this->assertSame(1, $cola->page($gente['beto'], 'unclaimed')['withheld']);
    }

    // ── N-2 · La estrategia que ya no está en marcha ────────────────────────

    public function test_n2_la_estrategia_pausada_retiene_lo_que_ya_salio_y_al_reanudarla_vuelve(): void
    {
        $gente = $this->threePeople();
        [$mio, $libre] = $this->startedFor(['3001234561', '3001234562']);
        $uuidMio = (string) $mio['job']->uuid;
        $uuidLibre = (string) $libre['job']->uuid;
        // Otra estrategia, EN MARCHA, con un trabajo libre de otra persona: no se toca.
        $otra = $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
        [$deOtra] = $this->seedStarted(1, '2026-11-18 12:00:00', resolvable: true, live: $otra);
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], $uuidMio, (string) Str::uuid());

        // El propietario pulsa «Pausar» en el Studio: una entrada `pause` firmada en la cadena; ningún freno.
        $this->pauseStrategy($this->vivo);
        $this->assertSame(0, DB::table('outreach_brakes')->count(), 'pausar no echa ningún freno');
        $this->assertSame(['active', 'active', 'active'], DB::table('outreach_enrollments')->orderBy('id')->pluck('status')->all(), 'las inscripciones siguen abiertas');
        $antes = self::rowsSnapshot();
        Log::spy();

        $this->assertSame([$this->uuidOf($deOtra)], array_column($cola->queue($gente['beto'], 'unclaimed'), 'uuid'), 'pausada: lo suyo sin reclamar no se ofrece; lo de la otra, sí');
        $this->assertSame(1, $cola->page($gente['beto'], 'unclaimed')['withheld'], 'y se cuenta solo lo de la pausada (en SQL, exacto)');
        $this->assertSame(1, $cola->page($gente['beto'], 'all')['withheld']);
        $this->assertClaimWithheld($cola, $uuidLibre, 'estrategia pausada');
        Log::shouldHaveReceived('warning')->once()->with('outreach.assisted.claim_withheld', ['job' => $uuidLibre, 'reason' => 'strategy_stopped']);
        $this->assertSame([null, false, 'strategy_stopped'], self::contact($cola->queue($gente['ana'], 'mine')[0]), 'el reclamado retiene el contacto');
        $this->assertSame([null, false, 'strategy_stopped'], self::contact($cola->claim($gente['ana'], $uuidMio, (string) Str::uuid())['job']), 'y la repetición');
        $this->assertSame([null, false, null], self::contact(self::row($cola->queue($gente['beto'], 'all'), $uuidMio)), 'a otra persona no se le cuenta');
        $this->assertSame($antes, self::rowsSnapshot(), 'la bandeja no escribe');

        // Reanudada: todo vuelve.
        $parte = ['contentHash' => (string) $this->vivo['v']->content_hash, 'version' => (string) $this->vivo['v']->uuid];
        $this->signedPublication($this->vivo['strategy'], null, 'resume', ['canary' => $parte, 'rest' => null, 'status' => 'active']);
        $this->assertNotNull(self::row($cola->queue($gente['beto'], 'unclaimed'), $uuidLibre));
        $this->assertNotNull(self::row($cola->queue($gente['beto'], 'unclaimed'), $this->uuidOf($deOtra)));
        $this->assertSame(0, $cola->page($gente['beto'], 'unclaimed')['withheld']);
        $this->assertSame([['kind' => 'member', 'id' => $mio['member']['member']], true, null], self::contact($cola->queue($gente['ana'], 'mine')[0]));
    }

    /** @return array<string, array{0: string}> */
    public static function paradas(): array
    {
        return [
            'revertida' => ['rollback'],
            'retirada (apagada)' => ['retire'],
            'revertida y archivada' => ['archive'],
            'con la cadena ilegible (firma rota)' => ['broken_signature'],
        ];
    }

    #[DataProvider('paradas')]
    public function test_n2_la_estrategia_revertida_retirada_archivada_o_ilegible_retiene(string $parada): void
    {
        $gente = $this->threePeople();
        [$mio, $libre] = $this->startedFor(['3001234561', '3001234562']);
        $uuidMio = (string) $mio['job']->uuid;
        $uuidLibre = (string) $libre['job']->uuid;
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], $uuidMio, (string) Str::uuid());

        match ($parada) {
            'rollback' => $this->rollbackStrategy($this->vivo),
            'retire' => $this->signedPublication($this->vivo['strategy'], null, 'retire', ['canary' => null, 'rest' => null, 'status' => 'off']),
            // La base solo deja archivar sin versiones en canario ni publicadas: se revierte primero.
            'archive' => [
                $this->rollbackStrategy($this->vivo),
                DB::table('ultron_studio_strategies')->where('id', $this->vivo['strategy'])->update(['archived_at' => now(), 'archived_by_admin_id' => (int) $gente['beto']->id, 'archived_by_name' => 'Beto']),
            ],
            'broken_signature' => $this->brokenSignaturePublication($this->vivo),
        };
        Log::spy();

        $this->assertSame([], $cola->queue($gente['beto'], 'unclaimed'), "{$parada}: no se ofrece");
        $this->assertSame(1, $cola->page($gente['beto'], 'unclaimed')['withheld']);
        $this->assertClaimWithheld($cola, $uuidLibre, $parada);
        Log::shouldHaveReceived('warning')->once()->with('outreach.assisted.claim_withheld', ['job' => $uuidLibre, 'reason' => 'strategy_stopped']);
        $this->assertSame([null, false, 'strategy_stopped'], self::contact($cola->queue($gente['ana'], 'mine')[0]), "{$parada}: el reclamado");
        $this->assertSame([null, false, 'strategy_stopped'], self::contact($cola->claim($gente['ana'], $uuidMio, (string) Str::uuid())['job']), "{$parada}: la repetición");
    }

    public function test_n2_tras_la_reversion_y_el_conciliador_la_fila_sale_y_la_repeticion_da_enrollment_closed(): void
    {
        $gente = $this->threePeople();
        [$mio] = $this->startedFor(['3001234561']);
        $uuid = (string) $mio['job']->uuid;
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], $uuid, (string) Str::uuid());
        $this->rollbackStrategy($this->vivo);
        (new OutreachReconciler)->run(now());
        $this->assertSame('canceled', DB::table('outreach_enrollments')->where('id', $mio['job']->enrollment_id)->value('status'), 'el conciliador cierra la inscripción huérfana');

        $this->assertSame([], $cola->queue($gente['ana'], 'mine'));
        $this->assertClaimWithheld($cola, $uuid, 'la repetición con la inscripción cancelada', 'enrollment_closed', $gente['ana']);
    }

    // ── Las lecturas nuevas también fallan cerrado ──────────────────────────

    /** @return array<string, array{0: string}> */
    public static function ilegibles(): array
    {
        return [
            'la cadena de estrategias (N-2)' => ['ultron_studio_strategy_publications'],
            'la foto de la persona con la política (N-1)' => ['marketing_ai_actions'],
        ];
    }

    #[DataProvider('ilegibles')]
    public function test_si_la_cadena_o_la_politica_no_se_pueden_leer_la_bandeja_falla_cerrada(string $tabla): void
    {
        $gente = $this->threePeople();
        [$mio, $libre] = $this->startedFor(['3001234561', '3001234562']);
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], (string) $mio['job']->uuid, (string) Str::uuid());
        // Renombrada (otras tablas la referencian y no se puede borrar): para la bandeja, ilegible.
        Schema::rename($tabla, $tabla.'_ilegible');
        // En PostgreSQL un error aborta la transacción de la prueba: cada intento va en su punto de guardado.
        $pg = DB::connection()->getDriverName() === 'pgsql';
        $intento = static fn (callable $f): mixed => $pg ? DB::transaction($f) : $f();

        foreach ([
            'la bandeja' => fn () => $cola->queue($gente['beto'], 'all'),
            'la vista del reclamante' => fn () => $cola->queue($gente['ana'], 'mine'),
            'reclamar' => fn () => $cola->claim($gente['beto'], (string) $libre['job']->uuid, (string) Str::uuid()),
            'la repetición' => fn () => $cola->claim($gente['ana'], (string) $mio['job']->uuid, (string) Str::uuid()),
        ] as $que => $f) {
            try {
                $intento($f);
                $this->fail("{$que}: con {$tabla} ilegible tenía que fallar (cerrado), no responder.");
            } catch (QueryException $e) {
                $this->assertStringContainsString($tabla, $e->getMessage(), "{$que}: falla por {$tabla}, no por otra cosa");
            }
        }
        $this->assertNull(DB::table('outreach_jobs')->where('id', $libre['job']->id)->value('assisted_claimed_by_admin_id'), 'nada se reclamó');
    }

    // ── La prioridad única ──────────────────────────────────────────────────

    public function test_un_solo_motivo_por_fila_en_el_orden_de_los_siete(): void
    {
        $gente = $this->threePeople();
        [$p] = $this->startedFor(['3001234567']);
        $uuid = (string) $p['job']->uuid;
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], $uuid, (string) Str::uuid());
        $motivo = fn (): mixed => self::contact(self::row($cola->queue($gente['ana'], 'mine', 50), $uuid))[2];

        Carbon::setTestNow(Carbon::parse((string) $p['job']->expires_at, 'UTC')->addMinute());
        $this->assertSame('expired', $motivo());
        config(['commercial.events_enabled' => false]);
        $this->assertSame('complaint_unknown', $motivo(), 'complaint_unknown > expired');
        config(['commercial.events_enabled' => true]);
        $this->commercialEvent('complaint_created', Carbon::now()->subMinute()->format('Y-m-d H:i:s'), $p['member']['member']);
        $this->assertSame('complaint_recent', $motivo(), 'complaint_recent > complaint_unknown');
        MarketingLead::query()->findOrFail($p['member']['lead'])->forceFill(['do_not_contact' => true])->save();
        $this->assertSame('opted_out', $motivo(), 'opted_out > complaint_recent');
        $this->pauseStrategy($this->vivo);
        $this->assertSame('strategy_stopped', $motivo(), 'strategy_stopped > opted_out');
        (new OutreachStop)->open('channel', 'assisted', 'legal_hold', 'Retención legal', $gente['beto'], (string) Str::uuid());
        $this->assertSame('brake', $motivo(), 'brake > strategy_stopped');

        // `policy_unknown` > `complaint_unknown` > `expired`: una persona sin resolver, reclamada y vencida, sin datos de eventos.
        [$sinResolver] = $this->seedStarted(1, '2026-11-17 15:10:00');
        $this->claimedBy($sinResolver, $gente['ana']);
        $motivoDe = fn (int $id): mixed => self::contact(self::row($cola->queue($gente['ana'], 'mine', 50), $this->uuidOf($id)))[2];
        $this->assertSame('brake', $motivoDe($sinResolver), 'con el freno del canal y la pausa, brake');
        $freno = (string) DB::table('outreach_brakes')->whereNull('released_at')->value('uuid');
        (new OutreachStop)->release($gente['caro'], $freno, 'Revisado: se suelta', (string) Str::uuid());
        $this->assertSame('strategy_stopped', $motivoDe($sinResolver), 'sin freno y con la pausa, strategy_stopped');
        $parte = ['contentHash' => (string) $this->vivo['v']->content_hash, 'version' => (string) $this->vivo['v']->uuid];
        $this->signedPublication($this->vivo['strategy'], null, 'resume', ['canary' => $parte, 'rest' => null, 'status' => 'active']);
        $this->assertSame('policy_unknown', self::contact(self::row($cola->queue($gente['ana'], 'mine', 50), $this->uuidOf($sinResolver)))[2], 'sin freno ni pausa: policy_unknown > complaint_unknown > expired');

        $this->assertSame(['brake', 'strategy_stopped', 'opted_out', 'complaint_recent', 'policy_unknown', 'complaint_unknown', 'expired'], AssistedQueue::WITHHELD_REASONS);
    }

    // ── m-1 · La repetición con la inscripción cerrada ──────────────────────

    public function test_m1_la_repeticion_de_claim_con_la_inscripcion_cerrada_da_409_enrollment_closed_sin_contacto(): void
    {
        $gente = $this->threePeople();
        [$p] = $this->startedFor(['3001234567']);
        $uuid = (string) $p['job']->uuid;
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], $uuid, (string) Str::uuid());
        $this->assertTrue($cola->claim($gente['ana'], $uuid, (string) Str::uuid())['replayed'], 'con la inscripción abierta, la repetición sigue');

        // La inscripción se cierra (un `opted_out` en otra estrategia, la 1581 o la reversión la cierran igual).
        $this->assertTrue((new OutreachStore)->closeEnrollment((int) $p['job']->enrollment_id, 'exited', 'opted_out', self::utcAt('2026-11-17 15:20:00')));
        foreach ([(string) Str::uuid(), (string) Str::uuid()] as $peticion) {
            try {
                $cola->claim($gente['ana'], $uuid, $peticion);
                $this->fail('La repetición con la inscripción cerrada entregó la fila.');
            } catch (OutreachException $e) {
                $this->assertSame([409, 'enrollment_closed'], [$e->status, $e->errorCode]);
            }
        }
        $this->assertSame([], $cola->queue($gente['ana'], 'mine'));
        $this->assertSame((int) $gente['ana']->id, (int) DB::table('outreach_jobs')->where('uuid', $uuid)->value('assisted_claimed_by_admin_id'), 'no se deshace nada');
    }

    // ── m-2 · El freno echado entre la lectura y el UPDATE ──────────────────

    public function test_m2_un_freno_echado_entre_leer_los_frenos_y_reclamar_deja_la_fila_reclamada_sin_contacto(): void
    {
        $gente = $this->threePeople();
        [$p] = $this->startedFor(['3001234567']);
        $uuid = (string) $p['job']->uuid;
        $cola = new AssistedQueue;
        $stop = new OutreachStop;
        // Justo DESPUÉS de que `claim()` lea los frenos (y antes de su UPDATE), alguien echa la PARADA GLOBAL.
        $echado = false;
        DB::listen(function ($q) use (&$echado, $stop, $gente): void {
            if (! $echado && str_contains(strtolower($q->sql), '"outreach_brakes"') && str_starts_with(strtolower(ltrim($q->sql)), 'select')) {
                $echado = true;
                $stop->stopAll('Parada global en mitad de la reclamación', $gente['caro']);
            }
        });

        $r = $cola->claim($gente['beto'], $uuid, (string) Str::uuid());
        $this->assertTrue($echado, 'el freno se echó a mitad');
        $this->assertFalse($r['replayed']);
        $this->assertSame([null, false, 'brake'], self::contact($r['job']), 'la respuesta ya no lleva el enlace ni el guion');
        $this->assertSame((int) $gente['beto']->id, (int) DB::table('outreach_jobs')->where('uuid', $uuid)->value('assisted_claimed_by_admin_id'), 'el trabajo queda reclamado: no se deshace nada');
        $this->assertSame([null, false, 'brake'], self::contact($cola->queue($gente['beto'], 'mine')[0]));
        $this->assertStringNotContainsString('3001234567', json_encode($r));
    }

    // ── m-3 · El tope de lectura ────────────────────────────────────────────

    public function test_m3_la_pagina_dice_scan_capped_cuando_llega_al_tope_sin_llenarse(): void
    {
        $gente = $this->threePeople();
        $this->startedFor(['3001234567']);
        $cola = new AssistedQueue;
        $this->assertSame(['items' => 1, 'withheld' => 0, 'scanCapped' => false], self::summary($cola->page($gente['ana'], 'all', 20)), 'una página normal');
        $this->assertSame(['items' => 1, 'withheld' => 0, 'scanCapped' => false], self::summary($cola->page($gente['ana'], 'all', 1)), 'llena: no se sabe si hay más, pero no es el tope');

        // MAX_SCAN + 1 retenidos (personas sin resolver) en cabeza del orden y uno ofrecible detrás.
        $this->seedStarted(AssistedQueue::MAX_SCAN + 1, '2026-11-17 16:00:00');
        $this->assertSame(['items' => 0, 'withheld' => AssistedQueue::MAX_SCAN, 'scanCapped' => true], self::summary($cola->page($gente['ana'], 'all', 20)));
        $this->assertSame(['items' => 0, 'withheld' => AssistedQueue::MAX_SCAN, 'scanCapped' => true], self::summary($cola->page($gente['beto'], 'unclaimed', 20)));
        $this->assertSame(['items' => 0, 'withheld' => 0, 'scanCapped' => false], self::summary($cola->page($gente['ana'], 'mine', 20)));
    }

    /** @param  array{items: list<array<string, mixed>>, withheld: int, scanCapped?: bool}  $pagina @return array<string, mixed> */
    private static function summary(array $pagina): array
    {
        return ['items' => count($pagina['items']), 'withheld' => $pagina['withheld'], 'scanCapped' => $pagina['scanCapped'] ?? 'falta la clave'];
    }

    // ── m-R1 y m-R2 del revisor (`informe-reviewer-ola-7.md`) ───────────────

    /** @return array<string, array{0: string}> */
    public static function retenidosEnSql(): array
    {
        return [
            'parada global' => ['global'],
            'freno del canal asistido' => ['channel'],
            'freno de la estrategia' => ['strategy'],
            'estrategia pausada (strategy_stopped)' => ['pause'],
        ];
    }

    /**
     * m-R1: el recuento exacto de lo que se retiene en SQL (`heldCount()`) cuenta SOLO lo sin reclamar y en
     * plazo: ni lo que Ana ya reclamó ni lo que venció sin que nadie lo reclamara. En la vista `all`, que es la
     * que tiene las dos cosas a la vez.
     */
    #[DataProvider('retenidosEnSql')]
    public function test_mr1_withheld_cuenta_solo_lo_sin_reclamar_y_en_plazo_con_un_reclamado_y_un_vencido_presentes(string $retencion): void
    {
        $gente = $this->threePeople();
        [$mio, $libre] = $this->startedFor(['3001234561', '3001234562']);
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], (string) $mio['job']->uuid, (string) Str::uuid());
        // Un tercero libre de la misma estrategia, que ya venció (las 15:10; el reloj va por las 15:15).
        [$vencido] = $this->seedStarted(1, '2026-11-17 15:10:00', resolvable: true);
        $this->assertSame(['all' => 0, 'unclaimed' => 0], ['all' => $cola->page($gente['beto'], 'all')['withheld'], 'unclaimed' => $cola->page($gente['beto'], 'unclaimed')['withheld']], 'sin retención: el vencido no cuenta');

        $stop = new OutreachStop;
        match ($retencion) {
            'global' => $stop->stopAll('Parada global de la prueba', $gente['caro']),
            'channel' => $stop->open('channel', 'assisted', 'legal_hold', 'Retención legal de la prueba', $gente['caro'], (string) Str::uuid()),
            'strategy' => $stop->open('strategy', $this->vivo['strategyUuid'], 'manual', 'Freno de la estrategia', $gente['caro'], (string) Str::uuid()),
            'pause' => $this->pauseStrategy($this->vivo),
        };

        $pagina = $cola->page($gente['beto'], 'all');
        $this->assertSame(1, $pagina['withheld'], "{$retencion}: solo el libre en plazo; ni el reclamado por Ana ni el vencido");
        $this->assertSame(1, $cola->page($gente['beto'], 'unclaimed')['withheld']);
        $this->assertSame([(string) $mio['job']->uuid], array_column($pagina['items'], 'uuid'), 'en «all» de Beto solo queda lo de Ana, sin contacto');
        $this->assertNull($pagina['items'][0]['person']);
        $this->assertNull(self::row($pagina['items'], (string) $libre['job']->uuid));
        $this->assertNull(self::row($pagina['items'], $this->uuidOf($vencido)));
    }

    /**
     * m-R2: la página se corta EXACTAMENTE en `limit` (`break 2`), también cuando se llena dentro de una tanda
     * llena: con más de una tanda de ofrecibles, las `limit` primeras por `(expires_at, id)`, en orden.
     */
    public function test_mr2_con_mas_de_una_tanda_de_ofrecibles_la_pagina_son_las_limit_primeras_en_orden(): void
    {
        $gente = $this->threePeople();
        $this->startedFor(['3001234567']);
        $ofrecibles = $this->seedStarted(120, '2026-11-18 09:00:00', resolvable: true);
        $cola = new AssistedQueue;
        $orden = DB::table('outreach_jobs')->whereIn('id', $ofrecibles)->orderBy('expires_at')->orderBy('id')->pluck('uuid')->all();

        foreach ([[20, 'all'], [20, 'unclaimed'], [1, 'all'], [AssistedQueue::MAX_PAGE, 'unclaimed']] as [$limite, $vista]) {
            $pagina = $cola->page($gente['beto'], $vista, $limite);
            $this->assertSame(array_slice($orden, 0, $limite), array_column($pagina['items'], 'uuid'), "{$vista}, limit {$limite}: las primeras, en orden, ni una más");
            $this->assertSame([0, false], [$pagina['withheld'], $pagina['scanCapped']], 'llena: ni retenidos ni tope');
        }
    }

    // ── m-8 · La vista `all` y lo reclamado por otra persona ya vencido ─────

    public function test_m8_all_no_se_llena_con_lo_reclamado_por_otra_persona_y_vencido(): void
    {
        $gente = $this->threePeople();
        $this->startedFor(['3001234567']);
        // 20 de Ana, reclamados en plazo, que vencen pronto; 4 libres detrás, con más plazo.
        $deAna = $this->seedStarted(20, '2026-11-17 16:00:00', resolvable: true);
        $libres = $this->seedStarted(4, '2026-11-18 12:00:00', resolvable: true);
        $cola = new AssistedQueue;
        foreach ($deAna as $id) {
            $cola->claim($gente['ana'], $this->uuidOf($id), (string) Str::uuid());
        }
        Carbon::setTestNow(Carbon::parse('2026-11-17 16:30:00', 'UTC'));

        $vista = array_column($cola->queue($gente['beto'], 'all', 20), 'uuid');
        foreach ($libres as $id) {
            $this->assertContains($this->uuidOf($id), $vista, 'lo ofrecible no queda escondido detrás de lo vencido de Ana');
        }
        foreach ($deAna as $id) {
            $this->assertNotContains($this->uuidOf($id), $vista, 'lo de Ana ya vencido no sale en «all» de Beto');
        }
        $mios = $cola->queue($gente['ana'], 'mine', 50);
        $this->assertCount(20, $mios, 'siguen en la vista de su reclamante');
        $this->assertSame(['expired'], array_values(array_unique(array_column($mios, 'contactWithheld'))));
        $this->assertCount(20, array_filter($cola->queue($gente['ana'], 'all', 50), static fn (array $f): bool => $f['contactWithheld'] === 'expired'), 'y en su «all»');
    }

    // ── Las lecturas: el orden y cuántas ────────────────────────────────────

    public function test_las_lecturas_de_la_pagina_y_de_claim_van_en_orden_y_por_lotes(): void
    {
        $gente = $this->threePeople();
        $trabajos = $this->startedFor(['3001234561', '3001234562', '3001234563']);
        $cola = new AssistedQueue;
        $sql = [];
        DB::listen(function ($q) use (&$sql): void {
            $sql[] = strtolower($q->sql);
        });
        $primera = static function (array $sql, string $trozo): int {
            foreach ($sql as $i => $q) {
                if (str_contains($q, $trozo)) {
                    return $i;
                }
            }

            return PHP_INT_MAX;
        };
        $cuenta = static fn (array $sql, string $trozo): int => count(array_filter($sql, static fn (string $q): bool => str_contains($q, $trozo)));

        // La página: los frenos primero; después la cadena; la foto, la supresión y la cadena, una vez cada una.
        $this->assertCount(3, $cola->queue($gente['ana'], 'all', 50));
        $this->assertSame(0, $primera($sql, '"outreach_brakes"'), 'los frenos, la primera consulta: '.json_encode($sql));
        $this->assertLessThan($primera($sql, '"enrollment_uuid"'), $primera($sql, '"ultron_studio_strategy_publications"'), 'la cadena antes de las filas');
        $this->assertSame([1, 1, 1], [$cuenta($sql, '"outreach_brakes"'), $cuenta($sql, '"commercial_events"'), $cuenta($sql, '"detail_code"')], 'frenos, foto y supresión: una vez por página, no por fila');
        $this->assertSame(1, $cuenta($sql, 'max(seq)'), 'la cadena, una vez por página');

        // Reclamar: frenos → cadena → foto → supresión → UPDATE → frenos otra vez (m-2).
        $sql = [];
        $cola->claim($gente['ana'], (string) $trabajos[0]['job']->uuid, (string) Str::uuid());
        $orden = [$primera($sql, '"outreach_brakes"'), $primera($sql, 'max(seq)'), $primera($sql, '"commercial_events"'), $primera($sql, '"detail_code"'), $primera($sql, 'update "outreach_jobs"')];
        $this->assertSame($orden, array_values(array_unique([...$orden])), json_encode($sql));
        $ordenado = $orden;
        sort($ordenado);
        $this->assertSame($ordenado, $orden, 'frenos, cadena, foto, supresión y escritura, en ese orden: '.json_encode($sql));
        $this->assertSame(2, $cuenta($sql, '"outreach_brakes"'), 'los frenos, antes de decidir y otra vez tras el UPDATE (m-2)');
        $despues = array_keys(array_filter($sql, static fn (string $q): bool => str_contains($q, '"outreach_brakes"')));
        $this->assertGreaterThan($primera($sql, 'update "outreach_jobs"'), $despues[1]);
    }

    // ── Por HTTP: la forma de `meta` y el 409 de la repetición ──────────────

    public function test_por_http_meta_lleva_scan_capped_y_la_repeticion_con_la_inscripcion_cerrada_da_409(): void
    {
        Route::middleware('api')->prefix('api')->group(function (): void {
            Route::prefix('admin/ultron-studio')->middleware(LimitRequestBodySize::class.':65536')->group(static fn () => UltronStudioOutreachController::routes());
        });
        foreach (OutreachAccess::PERMISSIONS as $llave) {
            RolePermission::query()->updateOrCreate(['role' => Admin::ROLE_SUPER_ADMIN, 'permission' => $llave], ['granted' => true]);
        }
        Cache::forget('crm.role_permissions.v1');
        app(RolePermissionPolicy::class)->flush();
        $gente = $this->threePeople();
        [$mio, $libre] = $this->startedFor(['3001234561', '3001234562']);
        $ana = $this->actingAsAdmin($gente['ana']);
        $uuid = (string) $mio['job']->uuid;

        $cola = $this->getJson(self::BASE.'outreach/assisted/queue', $ana)->assertOk();
        $this->assertSame(['withheld' => 0, 'scanCapped' => false], $cola->json('meta'));
        $this->postJson(self::BASE."outreach/assisted/{$uuid}/claim", ['clientRequestId' => (string) Str::uuid()], $ana)->assertCreated()->assertJsonPath('data.contactWithheld', null);

        // DNC del libre después de salir: 409 al reclamarlo; contado en `meta.withheld`.
        MarketingLead::query()->findOrFail($libre['member']['lead'])->forceFill(['do_not_contact' => true, 'consent_status' => MarketingLead::CONSENT_DENIED])->save();
        $this->postJson(self::BASE."outreach/assisted/{$libre['job']->uuid}/claim", ['clientRequestId' => (string) Str::uuid()], $ana)->assertStatus(409)->assertJsonPath('code', 'not_in_queue');
        $this->assertSame(['withheld' => 1, 'scanCapped' => false], $this->getJson(self::BASE.'outreach/assisted/queue?filter=unclaimed', $ana)->assertOk()->json('meta'));

        // m-1: la inscripción se cierra → la repetición, 409 sin la fila.
        (new OutreachStore)->closeEnrollment((int) $mio['job']->enrollment_id, 'exited', 'opted_out', RelativeTime::utc(now()));
        $r = $this->postJson(self::BASE."outreach/assisted/{$uuid}/claim", ['clientRequestId' => (string) Str::uuid()], $ana)->assertStatus(409)->assertJsonPath('code', 'enrollment_closed');
        $this->assertArrayNotHasKey('data', $r->json());
        $this->assertStringNotContainsString('300123456', $r->getContent());
    }

    // ── Apoyo ───────────────────────────────────────────────────────────────

    /**
     * Una entrada de la cadena con el ESTADO bien formado (la versión sigue `active` en su parte) pero una FIRMA
     * que no verifica (`signature_mismatch`), como `H9ChainOrphansTest::brokenSignaturePublication()`.
     *
     * @param  array<string, mixed>  $live
     */
    private function brokenSignaturePublication(array $live): int
    {
        $people = $this->people();
        $beto = (string) $this->db()->table('identities')->where('id', $people[2])->value('uuid');
        $s = DB::table('ultron_studio_strategies')->where('id', $live['strategy'])->first(['uuid', 'key', 'purpose']);
        $cabeza = DB::table('ultron_studio_strategy_publications')->orderByDesc('seq')->first(['id', 'seq', 'effective_hash']);
        $seq = (int) $cabeza->seq + 1;
        $parte = ['contentHash' => (string) $live['v']->content_hash, 'version' => (string) $live['v']->uuid];
        $mensaje = StrategyChainSignature::message([
            'action' => 'resume',
            'actedByPerson' => strtolower($beto),
            'approvedByPerson' => null,
            'contract' => StrategyChainSignature::CONTRACT,
            'issuedAt' => RelativeTime::format(now()),
            'prevHash' => (string) $cabeza->effective_hash,
            'seq' => $seq,
            'state' => $live['part'] === 'rest' ? ['canary' => null, 'rest' => $parte, 'status' => 'active'] : ['canary' => $parte, 'rest' => null, 'status' => 'active'],
            'strategy' => ['key' => (string) $s->key, 'purpose' => (string) $s->purpose, 'uuid' => (string) $s->uuid],
        ]);
        $this->insertPublication($seq, (int) $cabeza->id, [
            'strategy_id' => $live['strategy'], 'version_id' => null, 'action' => 'resume', 'effective_json' => $mensaje,
            'effective_hash' => StrategyChainSignature::entryHash($mensaje), 'signature' => str_repeat('c', 64),
            'simulation_gate' => null, 'evidence' => null, 'reason' => 'Firma rota (prueba)',
        ]);

        return $seq;
    }
}
