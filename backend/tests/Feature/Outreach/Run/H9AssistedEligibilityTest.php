<?php

namespace Tests\Feature\Outreach\Run;

use App\Models\Admin;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\StrategyContent;
use App\Services\Outreach\Facts\CatalogDomains;
use App\Services\Outreach\Facts\CoreProjection;
use App\Services\Outreach\Facts\OutreachConsentFacts;
use App\Services\Outreach\Run\AssistedQueue;
use App\Services\Outreach\Run\OutcomeRecorder;
use App\Services\Outreach\Run\OutreachAccess;
use App\Services\Outreach\Run\OutreachConsents;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachException;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachPublishedStrategies;
use App\Services\Outreach\Run\OutreachReplay;
use App\Services\Outreach\Run\OutreachStore;
use App\Services\Telephony\VoiceContacts;
use App\Services\UltronStudio\StudioCatalog;
use App\Support\Privacy\PhoneFingerprint;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * El cierre de R3-1, R3-2 y R3-3 de `informe-security-bandeja-ronda3.md` y de m-1 de `informe-reviewer-ronda3.md`,
 * sobre las clases REALES de la ejecución:
 *
 *  - R3-1: revocar el consentimiento de VOZ después de salir retiene el asistido por voz (N1
 *    `no_consent_for_purpose_channel`); el de WhatsApp de la misma persona, no;
 *  - R3-3 (directiva del propietario del 21-11-2026): el menor (N1 `subject_minor`) y la consulta al RNE que
 *    caduca (N1 `rne_check_missing`, solo voz) retienen; la edad que no se sabe, no (la escalera tampoco niega);
 *  - R3-2: un «no contactar» o una pausa de la estrategia que llegan ENTRE la comprobación y el `UPDATE` de
 *    `claim()` no entregan el contacto ni una vez (la carrera con dos conexiones de verdad, en
 *    `H9AssistedClaimRaceTest`, solo PostgreSQL);
 *  - m-1 de `informe-security-bandeja-r3.md`: la inscripción que se cierra en ese mismo hueco da 409
 *    `enrollment_closed`, como la repetición, sin entregar el contacto;
 *  - m-2 de `informe-security-bandeja-r3.md`: la referencia del Studio que deja de valer (N3
 *    `studio_reference_invalid`) para la estrategia en la bandeja (`strategy_stopped`), por cada causa del puente;
 *  - m-1: la página no hace consultas por fila.
 *
 * El martes 17-11-2026 a las 10:00 de Bogotá (15:00Z), en vivo, con la fuente de eventos encendida (L9-26),
 * como el resto de B9-run. El reloj, en UTC.
 */
class H9AssistedEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const NOW = '2026-11-17 15:00:00';

    /** La vigencia de la consulta al RNE de estas pruebas (`voice.contacts.rne_validity_days`; 0 = ninguna vigente). */
    private const RNE_DAYS = 7;

    /** @var array<string, mixed> la estrategia viva de la prueba */
    private array $vivo = [];

    /** La plantilla asistida de los trabajos sembrados (una, como el paso de una estrategia). */
    private ?int $plantilla = null;

    /** @var list<string> las consultas vistas desde que se registró el oyente ({@see queriesOf()}) */
    private array $consultas = [];

    private bool $escuchando = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true, 'commercial.enabled' => false, 'voice.contacts.rne_validity_days' => self::RNE_DAYS]);
        $this->threePeople();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── El mundo ────────────────────────────────────────────────────────────

    /**
     * Una estrategia viva (parte canario) y un trabajo asistido por WhatsApp que SALIÓ en vivo por cada teléfono,
     * por el planificador y el despachador reales. El reloj queda 15 min después.
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
     * Un trabajo asistido `started` en vivo de la persona, por las filas del esquema (nace `planned` y avanza por
     * los disparadores reales), como `H9AssistedOptOutStrategyTest::seedStarted()`.
     *
     * @param  array<string, mixed>  $persona  columnas de la inscripción (`subject_key`, `member_id`, `lead_id`)
     * @param  array<string, mixed>|null  $live  otra estrategia viva (una persona tiene una inscripción por estrategia)
     */
    private function seedStarted(array $persona, string $medium, string $expiresAt = '2026-11-18 09:00:00', ?array $live = null): int
    {
        $plantilla = $this->plantilla ??= $this->assistedTemplate();
        $huella = (string) DB::table('ultron_studio_message_templates')->where('id', $plantilla)->value('content_hash');
        $e = $this->insertEnrollment($live ?? $this->vivo, ['mode' => 'live'] + $persona);
        $id = $this->insertJob($e, ['channel' => 'assisted', 'medium' => $medium, 'template_version_id' => $plantilla, 'template_hash' => $huella,
            'not_before' => '2026-11-17 15:00:00', 'expires_at' => $expiresAt, 'budget_day' => '2026-11-17']);
        foreach ([['status' => 'claimed', 'claimed_at' => now()], ['status' => 'dispatching'], ['status' => 'started']] as $paso) {
            DB::table('outreach_jobs')->where('id', $id)->update($paso + ['status_changed_at' => now()]);
        }

        return $id;
    }

    /**
     * Un socio que se puede llamar HOY: su contacto de voz activo con el consentimiento comercial concedido y
     * confirmado por OTRA persona (E3-4), una consulta al RNE vigente que dice `not_listed` y su número de la ficha
     * como canario activo de voz y de WhatsApp (F-2: la parte canario y la línea base «solo canario» lo exigen).
     *
     * @return array{member: int, persona: array<string, mixed>, contact: array{id: int, hash: string, uuid: string}, phone: string}
     */
    private function callableMember(string $e164, string $rneCheckedAt = '2026-11-15 12:00:00'): array
    {
        $socio = $this->canaryMember('voice', 'whatsapp');
        $contacto = $this->voiceContact('member', $socio['member'], $e164);
        $this->voiceConsent($contacto['id'], 'granted', 2, '2026-11-01 12:00:00', 1);
        $this->rneCheck($contacto['hash'], $rneCheckedAt);

        return [
            'member' => $socio['member'],
            'persona' => ['subject_key' => 'm:'.$socio['member'], 'member_id' => $socio['member']],
            'contact' => $contacto + ['uuid' => (string) DB::table('voice_contacts')->where('id', $contacto['id'])->value('uuid')],
            'phone' => $socio['phone'],
        ];
    }

    /** Un contacto de voz retirado, con la forma que exige DB8 (`vct_cipher_chk`, `vct_retire_chk`): sin el número cifrado, con quién, cuándo y por qué. */
    private function retireVoiceContact(int $contactId): void
    {
        DB::table('voice_contacts')->where('id', $contactId)->update([
            'status' => 'retired', 'phone_encrypted' => null, 'retired_at' => now(), 'retired_by_admin_id' => 1, 'retire_reason' => 'Número dado de baja',
            'status_changed_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Reclamado a mano (sin pasar por la bandeja): lo que pasó ANTES de que llegara la señal. */
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

    private function assertClaimWithheld(AssistedQueue $cola, string $jobUuid, string $why, ?Admin $who = null): void
    {
        try {
            $cola->claim($who ?? $this->threePeople()['beto'], $jobUuid, (string) Str::uuid());
            $this->fail("Se reclamó un trabajo que la bandeja tenía que retener ({$why}).");
        } catch (OutreachException $e) {
            $this->assertSame([409, 'not_in_queue'], [$e->status, $e->errorCode], $why);
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

    /**
     * Lo común a toda retención por la persona: lo libre no se ofrece (ni en `unclaimed` ni en `all`), se cuenta en
     * `withheld`, `claim()` da 409 `not_in_queue` con el motivo al registro; el reclamante, en `mine`, en `all` y en
     * la repetición, recibe `person: null`, `script: null` y el motivo; la otra persona no ve el motivo; nada se
     * escribe y no sale ningún teléfono: ni el del contacto de voz ni el de la ficha del socio (m-4 de
     * `informe-security-bandeja-r3.md`), cada uno en `$phones` (un fragmento común a los dos trabajos vale).
     */
    private function assertWithheldEverywhere(AssistedQueue $cola, string $mio, string $libre, string $motivo, string ...$phones): void
    {
        $this->assertNotSame([], $phones, 'qué teléfonos no pueden salir');
        $gente = $this->threePeople();
        $antes = self::rowsSnapshot();
        Log::spy();

        $this->assertNull(self::row($cola->queue($gente['beto'], 'unclaimed', 50), $libre), "{$motivo}: no se ofrece");
        $this->assertNull(self::row($cola->queue($gente['beto'], 'all', 50), $libre), "{$motivo}: tampoco en «all»");
        $this->assertSame(1, $cola->page($gente['beto'], 'unclaimed', 50)['withheld'], "{$motivo}: se cuenta, sin decir de quién");
        $this->assertClaimWithheld($cola, $libre, $motivo);
        Log::shouldHaveReceived('warning')->once()->with('outreach.assisted.claim_withheld', ['job' => $libre, 'reason' => $motivo]);
        $this->assertNull(DB::table('outreach_jobs')->where('uuid', $libre)->value('assisted_claimed_by_admin_id'), 'no se reclamó');

        foreach (['mine', 'all'] as $vista) {
            $this->assertSame([null, false, $motivo], self::contact(self::row($cola->queue($gente['ana'], $vista, 50), $mio)), "{$motivo}: el reclamante en {$vista}");
        }
        $r = $cola->claim($gente['ana'], $mio, (string) Str::uuid());
        $this->assertTrue($r['replayed']);
        $this->assertSame([null, false, $motivo], self::contact($r['job']), "{$motivo}: la repetición");
        $this->assertSame([null, false, null], self::contact(self::row($cola->queue($gente['beto'], 'all', 50), $mio)), 'a otra persona no se le cuenta');
        $salida = (string) json_encode([$cola->queue($gente['ana'], 'all', 50), $cola->queue($gente['beto'], 'all', 50), $r]);
        foreach ($phones as $telefono) {
            $this->assertStringNotContainsString($telefono, $salida, "{$motivo}: no sale ningún teléfono ({$telefono})");
        }
        $this->assertSame($antes, self::rowsSnapshot(), 'la bandeja no escribe nada al retener');
    }

    // ── R3-1 · La revocación del consentimiento de voz ──────────────────────

    public function test_r3_1_revocar_el_consentimiento_de_voz_despues_de_salir_retiene_el_asistido_por_voz_y_no_el_de_whatsapp(): void
    {
        $gente = $this->threePeople();
        $this->startedFor(['3001234567']);
        $uno = $this->callableMember('+573005550101');
        $dos = $this->callableMember('+573005550102');
        $mio = $this->uuidOf($this->seedStarted($uno['persona'], 'voice'));
        $libre = $this->uuidOf($this->seedStarted($dos['persona'], 'voice'));
        // El de WhatsApp de la MISMA persona, de OTRA estrategia viva (una persona tiene una inscripción por estrategia).
        $otra = $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
        $whatsapp = $this->uuidOf($this->seedStarted($dos['persona'], 'whatsapp', '2026-11-18 10:00:00', $otra));
        $cola = new AssistedQueue;

        // ANTES: con el consentimiento concedido y el RNE vigente, se ofrece y el reclamante recibe el enlace y el guion.
        $this->assertNotNull(self::row($cola->queue($gente['beto'], 'unclaimed', 50), $libre), 'antes: el libre se ofrece');
        $r = $cola->claim($gente['ana'], $mio, (string) Str::uuid());
        $this->assertSame([['kind' => 'member', 'id' => $uno['member']], true, null], self::contact($r['job']), 'antes: el enlace y el guion');

        // DESPUÉS de salir, las dos personas revocan por el camino del personal (`POST voice/contacts/{uuid}/revoke`).
        foreach ([$uno, $dos] as $p) {
            (new VoiceContacts)->revoke($gente['caro'], $p['contact']['uuid'], ['clientRequestId' => (string) Str::uuid(), 'source' => 'written_form', 'evidence' => 'Pidió por escrito que no la llamen']);
        }
        $this->assertSame(2, DB::table('voice_consents')->where('kind', 'revoked')->count());

        // La revocación CONSTA (`voice.consent_revoked`): lo pidió, `opted_out`.
        $this->assertWithheldEverywhere($cola, $mio, $libre, 'opted_out', '555010', $uno['phone'], $dos['phone']);
        $this->assertNotNull(self::row($cola->queue($gente['beto'], 'unclaimed', 50), $whatsapp), 'el de WhatsApp de la misma persona sigue: R3-1 es del medio voz');
        $this->assertSame(OutcomeRecorder::RESULT_RECORDED, $cola->recordOutcome($gente['ana'], $mio, 'opted_out', (string) Str::uuid())['result'], 'el resultado se sigue registrando');
    }

    /**
     * R3-1 y la reproducibilidad (D9-12): `voice.consent_revoked` es una clave de la FOTO de política, no de lo que se
     * guarda. Ni la foto de condiciones (`facts_json`) ni la traza (la entrada de la escalera) la llevan, así que una
     * decisión guardada antes o después se repite igual: `outreach:replay` da `same` en todas.
     */
    public function test_r3_1_la_clave_nueva_no_entra_en_lo_que_se_guarda_y_las_decisiones_se_repiten_igual(): void
    {
        $this->startedFor(['3001234561', '3001234562']);
        $decisiones = DB::table('outreach_decisions')->orderBy('id')->get(['uuid', 'trace_json', 'facts_json']);
        $this->assertGreaterThanOrEqual(4, $decisiones->count(), 'inscripción y plan de cada una, como mínimo');
        foreach ($decisiones as $d) {
            $this->assertStringNotContainsString('consent_revoked', (string) $d->trace_json.(string) $d->facts_json);
            $this->assertSame(OutreachReplay::SAME, (new OutreachReplay)->replay((string) $d->uuid)['result'], "replay de {$d->uuid}");
        }
    }

    /**
     * R3-1, el otro caso: el consentimiento deja de estar concedido SIN que conste una revocación (el contacto de voz
     * se retira). N1 lo niega igual; la bandeja retiene, pero no dice que lo pidió: `policy_unknown`.
     */
    public function test_r3_1_el_contacto_de_voz_retirado_despues_de_salir_retiene_con_policy_unknown(): void
    {
        $gente = $this->threePeople();
        $this->startedFor(['3001234567']);
        $uno = $this->callableMember('+573005550101');
        $dos = $this->callableMember('+573005550102');
        $mio = $this->uuidOf($this->seedStarted($uno['persona'], 'voice'));
        $libre = $this->uuidOf($this->seedStarted($dos['persona'], 'voice'));
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], $mio, (string) Str::uuid());

        $this->retireVoiceContact($uno['contact']['id']);
        $this->retireVoiceContact($dos['contact']['id']);

        $this->assertWithheldEverywhere($cola, $mio, $libre, 'policy_unknown', '555010', $uno['phone'], $dos['phone']);
    }

    // ── R3-3 · El menor y la consulta al RNE que caduca ─────────────────────

    public function test_r3_3_el_menor_retiene_por_whatsapp_lo_libre_y_lo_reclamado(): void
    {
        $gente = $this->threePeople();
        [$mio, $libre] = $this->startedFor(['3001234561', '3001234562']);
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], (string) $mio['job']->uuid, (string) Str::uuid());
        $this->assertSame([['kind' => 'member', 'id' => $mio['member']['member']], true, null], self::contact($cola->queue($gente['ana'], 'mine')[0]), 'antes: el enlace y el guion');

        // DESPUÉS de salir, el personal corrige la fecha de nacimiento de las dos fichas: son menores.
        foreach ([$mio, $libre] as $p) {
            Member::query()->findOrFail($p['member']['member'])->forceFill(['birth_date' => '2012-03-03'])->save();
        }

        $this->assertWithheldEverywhere($cola, (string) $mio['job']->uuid, (string) $libre['job']->uuid, 'subject_minor', '300123456');
    }

    public function test_r3_3_la_edad_que_no_se_sabe_no_retiene_como_en_la_escalera(): void
    {
        $gente = $this->threePeople();
        $this->startedFor(['3001234567']);
        // Un prospecto sin ficha de socio: la edad no se infiere (D9-15) y la escalera no niega `subject_minor`.
        $lead = $this->lead(['phone' => '3009990001', 'consent_status' => 'granted']);
        $this->conversation($lead);
        $this->canary('+573009990001', 'whatsapp');
        $libre = $this->uuidOf($this->seedStarted(['subject_key' => 'l:'.$lead, 'member_id' => null, 'lead_id' => $lead], 'whatsapp'));
        $cola = new AssistedQueue;

        $this->assertNotNull(self::row($cola->queue($gente['beto'], 'unclaimed', 50), $libre), 'la edad desconocida no retiene');
        $r = $cola->claim($gente['beto'], $libre, (string) Str::uuid());
        $this->assertSame([['kind' => 'lead', 'id' => $lead], true, null], self::contact($r['job']));
    }

    public function test_r3_3_la_consulta_al_rne_que_caduca_despues_de_salir_retiene_el_asistido_por_voz_y_al_renovarla_vuelve(): void
    {
        $gente = $this->threePeople();
        $this->startedFor(['3001234567']);
        // La consulta, vigente hasta las 16:00 de hoy (7 días); el trabajo vence mañana.
        $uno = $this->callableMember('+573005550101', '2026-11-10 16:00:00');
        $dos = $this->callableMember('+573005550102', '2026-11-10 16:00:00');
        $mio = $this->uuidOf($this->seedStarted($uno['persona'], 'voice'));
        $libre = $this->uuidOf($this->seedStarted($dos['persona'], 'voice'));
        $cola = new AssistedQueue;
        $this->assertNotNull(self::row($cola->queue($gente['beto'], 'unclaimed', 50), $libre), 'antes: vigente, se ofrece');
        $cola->claim($gente['ana'], $mio, (string) Str::uuid());

        // Las 16:30: la consulta ya no está vigente y el trabajo sigue en plazo.
        Carbon::setTestNow(Carbon::parse('2026-11-17 16:30:00', 'UTC'));
        $this->assertWithheldEverywhere($cola, $mio, $libre, 'rne_check_missing', '555010', $uno['phone'], $dos['phone']);

        // Una consulta nueva (`POST voice/rne-checks` deja esta fila): vuelve todo. Retenía el RNE y nada más.
        foreach ([$uno, $dos] as $p) {
            $this->rneCheck($p['contact']['hash'], '2026-11-17 16:20:00');
        }
        $this->assertNotNull(self::row($cola->queue($gente['beto'], 'unclaimed', 50), $libre));
        $this->assertSame([['kind' => 'member', 'id' => $uno['member']], true, null], self::contact(self::row($cola->queue($gente['ana'], 'mine', 50), $mio)));
    }

    public function test_r3_3_el_rne_caducado_no_retiene_el_asistido_por_whatsapp_de_la_misma_persona(): void
    {
        $gente = $this->threePeople();
        $this->startedFor(['3001234567']);
        $dos = $this->callableMember('+573005550102', '2026-11-01 12:00:00');
        $voz = $this->uuidOf($this->seedStarted($dos['persona'], 'voice'));
        $otra = $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
        $whatsapp = $this->uuidOf($this->seedStarted($dos['persona'], 'whatsapp', '2026-11-18 10:00:00', $otra));
        $cola = new AssistedQueue;

        $libres = array_column($cola->queue($gente['beto'], 'unclaimed', 50), 'uuid');
        $this->assertNotContains($voz, $libres, 'por voz, con el RNE caducado, no');
        $this->assertContains($whatsapp, $libres, 'por WhatsApp, sí: N1 solo mira el RNE en la voz');
    }

    /**
     * La prioridad de los dos motivos de R3-3 ({@see AssistedQueue::WITHHELD_REASONS}): por detrás de lo que pidió la
     * persona y de la queja, por delante de lo desconocido (lo SABIDO gana) y del plazo.
     */
    public function test_r3_3_la_prioridad_de_subject_minor_y_rne_check_missing(): void
    {
        $gente = $this->threePeople();
        $this->startedFor(['3001234567']);
        $uno = $this->callableMember('+573005550101', '2026-11-10 16:00:00');
        $voz = $this->seedStarted($uno['persona'], 'voice', '2026-11-17 17:00:00');
        $this->claimedBy($voz, $gente['ana']);
        $cola = new AssistedQueue;
        $motivo = fn (): mixed => self::contact(self::row($cola->queue($gente['ana'], 'mine', 50), $this->uuidOf($voz)))[2];
        $this->assertNull($motivo(), 'al principio, limpio');

        // El RNE caduca (16:00) y el plazo vence (17:00): `rne_check_missing` > `expired`.
        Carbon::setTestNow(Carbon::parse('2026-11-17 17:30:00', 'UTC'));
        $this->assertSame('rne_check_missing', $motivo());
        // Sin datos de eventos: `rne_check_missing` > `complaint_unknown`.
        config(['commercial.events_enabled' => false]);
        $this->assertSame('rne_check_missing', $motivo());
        // El consentimiento deja de estar concedido SIN revocación que conste (se retira el contacto que lo tenía y queda
        // otro, activo, sin consentimiento ni consulta al RNE): `rne_check_missing` > `policy_unknown`.
        $this->retireVoiceContact($uno['contact']['id']);
        $otro = $this->voiceContact('member', $uno['member'], '+573005550199');
        $this->assertSame('rne_check_missing', $motivo());
        // Menor: `subject_minor` > `rne_check_missing`.
        Member::query()->findOrFail($uno['member'])->forceFill(['birth_date' => '2012-03-03'])->save();
        $this->assertSame('subject_minor', $motivo());
        // La queja: `complaint_recent` > `subject_minor`.
        config(['commercial.events_enabled' => true]);
        $this->commercialEvent('complaint_created', Carbon::now()->subMinute()->format('Y-m-d H:i:s'), $uno['member']);
        $this->assertSame('complaint_recent', $motivo());
        // Y lo que pidió la persona (revoca en el contacto que queda; R3-1): `opted_out` > `complaint_recent`.
        $uuidOtro = (string) DB::table('voice_contacts')->where('id', $otro['id'])->value('uuid');
        (new VoiceContacts)->revoke($gente['caro'], $uuidOtro, ['clientRequestId' => (string) Str::uuid(), 'source' => 'written_form', 'evidence' => 'Revoca por escrito']);
        $this->assertSame('opted_out', $motivo());

        $this->assertSame(['brake', 'strategy_stopped', 'template_not_approved', 'opted_out', 'complaint_recent', 'subject_minor', 'rne_check_missing', 'canary_scope_mismatch', 'policy_unknown', 'complaint_unknown', 'expired'], AssistedQueue::WITHHELD_REASONS);
    }

    // ── C-1 (revisión de API del consentimiento) · La retirada del libro de autorizaciones ──

    /** La retirada por el servicio REAL del libro (`POST consents/revoke`: sin segunda cuenta ni texto legal). */
    private function revokeInLedger(int $memberId, string $purpose = 'commercial'): void
    {
        $r = (new OutreachConsents)->revoke(new OutreachAccess($this->threePeople()['caro']), [
            'clientRequestId' => (string) Str::uuid(), 'personType' => 'member', 'personId' => $memberId, 'purpose' => $purpose,
            'source' => 'member_request', 'evidence' => 'Pidió en recepción que no le escriban más',
        ]);
        $this->assertSame(201, $r['http']);
    }

    /**
     * C-1: la persona retira su autorización de WhatsApp (el libro de C9) DESPUÉS de que su asistido salió. La escalera
     * la niega (`denied`) en la pasada siguiente; la bandeja, en el acto: lo libre no se ofrece ni se reclama y el
     * reclamante pierde el enlace y el guion (`opted_out`). Antes, el trabajo seguía en la bandeja hasta su plazo.
     */
    public function test_c1_una_retirada_del_libro_despues_de_salir_retiene_lo_libre_y_lo_reclamado(): void
    {
        $gente = $this->threePeople();
        [$a, $b] = $this->startedFor(['3001234567', '3001234568']);
        $mio = (string) $a['job']->uuid;
        $libre = (string) $b['job']->uuid;
        $cola = new AssistedQueue;
        $r = $cola->claim($gente['ana'], $mio, (string) Str::uuid());
        $this->assertSame([['kind' => 'member', 'id' => $a['member']['member']], true, null], self::contact($r['job']), 'antes: el enlace y el guion');
        $this->assertNotNull(self::row($cola->queue($gente['beto'], 'unclaimed', 50), $libre), 'antes: el libre se ofrece');

        foreach ([$a, $b] as $p) {
            $this->revokeInLedger($p['member']['member']);
        }
        $this->assertSame(2, DB::table('outreach_consents')->where('kind', 'revoked')->count());

        $this->assertWithheldEverywhere($cola, $mio, $libre, 'opted_out', '300123456');
        $this->assertSame(OutcomeRecorder::RESULT_RECORDED, $cola->recordOutcome($gente['ana'], $mio, 'opted_out', (string) Str::uuid())['result'], 'el resultado se sigue registrando');
    }

    /** C-1: cada finalidad por su fila. Retirar la de SERVICIO no retiene un asistido comercial. */
    public function test_c1_la_retirada_de_otra_finalidad_no_retiene(): void
    {
        $gente = $this->threePeople();
        [$p] = $this->startedFor(['3001234567']);
        $this->revokeInLedger($p['member']['member'], 'service');

        $r = (new AssistedQueue)->claim($gente['beto'], (string) $p['job']->uuid, (string) Str::uuid());
        $this->assertSame([['kind' => 'member', 'id' => $p['member']['member']], true, null], self::contact($r['job']));
    }

    /**
     * C-1 y R3-2: la retirada llega justo DESPUÉS de la última lectura de la comprobación y ANTES del `UPDATE`. La
     * relectura la ve: la reclamación queda escrita, pero el 201 no lleva ni el enlace ni el guion.
     */
    public function test_c1_la_retirada_que_llega_entre_el_reclamo_y_la_relectura_no_entrega_el_contacto(): void
    {
        $gente = $this->threePeople();
        [$p] = $this->startedFor(['3001234567']);
        $uuid = (string) $p['job']->uuid;
        $sql = [];
        $inyectada = null;
        DB::listen(function ($q) use (&$sql, &$inyectada, $p): void {
            $sql[] = strtolower($q->sql);
            if ($inyectada === null && str_contains(strtolower($q->sql), '"detail_code"')) {
                $inyectada = count($sql) - 1;
                $this->revokeInLedger($p['member']['member']);
            }
        });

        $r = (new AssistedQueue)->claim($gente['beto'], $uuid, (string) Str::uuid());
        $update = array_key_first(array_filter($sql, static fn (string $q): bool => str_starts_with(ltrim($q), 'update "outreach_jobs"')));
        $this->assertNotNull($inyectada, 'la retirada llegó a mitad');
        $this->assertLessThan($update, $inyectada, 'después de comprobar y ANTES del UPDATE');
        $this->assertSame([false, [null, false, 'opted_out']], [$r['replayed'], self::contact($r['job'])], 'el 201 ya no lleva el enlace ni el guion');
        $this->assertSame((int) $gente['beto']->id, (int) DB::table('outreach_jobs')->where('uuid', $uuid)->value('assisted_claimed_by_admin_id'), 'queda reclamado: no se deshace nada');
        $this->assertSame([null, false, 'opted_out'], self::contact((new AssistedQueue)->queue($gente['beto'], 'mine')[0]));
        $this->assertStringNotContainsString('3001234567', json_encode($r));
    }

    /**
     * C-1, lo que está en producción: con el libro VACÍO y sin la TABLA, la bandeja da lo mismo que antes (se ofrece lo
     * libre, el reclamante recibe el enlace y el guion, y lo que ya retenía sigue reteniendo con su motivo). Esta prueba
     * pasa igual con el `AssistedQueue` de antes de C-1: lo comprobé al cerrarlo.
     */
    public function test_c1_con_el_libro_vacio_o_sin_la_tabla_la_bandeja_es_la_de_antes(): void
    {
        $gente = $this->threePeople();
        [$a, $b, $c] = $this->startedFor(['3001234567', '3001234568', '3001234569']);
        MarketingLead::query()->findOrFail($c['member']['lead'])->forceFill(['do_not_contact' => true])->save();
        $this->claimedBy((int) $a['job']->id, $gente['ana']);
        $vistas = function () use ($gente, $a): array {
            $cola = new AssistedQueue;
            $mine = $cola->queue($gente['ana'], 'mine', 50);

            return [
                'unclaimed' => array_column($cola->queue($gente['beto'], 'unclaimed', 50), 'uuid'),
                'withheld' => $cola->page($gente['beto'], 'unclaimed', 50)['withheld'],
                'mine' => self::contact(self::row($mine, (string) $a['job']->uuid)),
                'all' => array_map(static fn (array $f): array => [$f['uuid'], $f['person'], $f['script'] !== null], $cola->queue($gente['ana'], 'all', 50)),
            ];
        };
        $esperado = [
            'unclaimed' => [(string) $b['job']->uuid],
            'withheld' => 1,
            'mine' => [['kind' => 'member', 'id' => $a['member']['member']], true, null],
        ];

        $this->assertSame(0, DB::table('outreach_consents')->count(), 'el libro, vacío');
        $conLibro = $vistas();
        $this->assertSame($esperado, array_intersect_key($conLibro, $esperado), 'con el libro vacío');
        Schema::drop('outreach_consents');
        OutreachConsentFacts::forgetLedger();
        try {
            $this->assertSame($conLibro, $vistas(), 'sin la tabla: lo mismo');
        } finally {
            OutreachConsentFacts::forgetLedger();
        }
    }

    // ── I-1 (revisor de la bandeja) · Perder el consentimiento comercial de WhatsApp sin un «denegado» ──

    /**
     * I-1 de `informe-reviewer-bandeja.md` (su sonda P-2), con la directiva del propietario («un contacto que pierde
     * elegibilidad debe dejar de poder ofrecerse, reclamarse o ejecutarse por el canal restringido»). El asistido
     * comercial por WhatsApp sale con el prospecto `granted` y Ana lo reclama. Después, la persona escribe desde
     * OTRO número: nace un prospecto suyo con `consent_status = null` y conversación de WhatsApp. `LeadFacts` toma ese,
     * el más reciente: `consent.whatsapp.commercial = false` y `lead.consent_denied = false` (null no es
     * «denegado»). La escalera negaría (`no_consent_for_purpose_channel`); la bandeja retiene con `policy_unknown`,
     * como por voz. Antes seguía entregando la ficha y el guion.
     */
    public function test_i1_por_whatsapp_comercial_perder_el_consentimiento_sin_un_denegado_retiene_con_policy_unknown(): void
    {
        $gente = $this->threePeople();
        [$a, $b] = $this->startedFor(['3001234567', '3001234568']);
        $mio = (string) $a['job']->uuid;
        $libre = (string) $b['job']->uuid;
        $cola = new AssistedQueue;
        $r = $cola->claim($gente['ana'], $mio, (string) Str::uuid());
        $this->assertSame([['kind' => 'member', 'id' => $a['member']['member']], true, null], self::contact($r['job']), 'antes: el enlace y el guion');

        foreach ([$a, $b] as $i => $p) {
            $nuevo = '300999000'.$i;
            $lead = $this->lead(['member_id' => $p['member']['member'], 'phone' => $nuevo, 'consent_status' => null]);
            $this->conversation($lead);
            // El número nuevo también es un número de prueba activo: el caso no es F-2 (`canary_scope_mismatch`).
            $this->canary('+57'.$nuevo, 'whatsapp');
        }
        // Lo que lee N1: sin la autorización comercial de WhatsApp y sin un «denegado» que conste.
        $foto = $this->snap([$a['member']['key']], '2026-11-17 15:15:00', CoreProjection::selectionFor([]))->subject($a['member']['key']);
        $this->assertSame(['missing', false], [CoreProjection::policyInput($foto, 'commercial', null)['consent']['whatsapp'], $foto->policy('lead.consent_denied')]);

        $this->assertWithheldEverywhere($cola, $mio, $libre, 'policy_unknown', '300123456', '300999000');
        $this->assertSame(OutcomeRecorder::RESULT_RECORDED, $cola->recordOutcome($gente['ana'], $mio, 'opted_out', (string) Str::uuid())['result'], 'el resultado se sigue registrando');
    }

    // ── R3-2 · Lo que llega entre la comprobación y el UPDATE ───────────────

    /** @return array<string, array{0: string, 1: string}> */
    public static function entreLaComprobacionYElUpdate(): array
    {
        return [
            'un «no contactar» (do_not_contact)' => ['dnc', 'opted_out'],
            'la pausa de la estrategia en la cadena' => ['pause', 'strategy_stopped'],
            'el menor (R3-3)' => ['minor', 'subject_minor'],
        ];
    }

    /**
     * R3-2: justo DESPUÉS de la última lectura de la comprobación (la del libro de supresiones) y ANTES del `UPDATE`,
     * llega la señal. La reclamación queda escrita (no se deshace nada) y el 201 ya no lleva ni el enlace ni el
     * guion. En la misma conexión: la carrera con dos conexiones de verdad está en `H9AssistedClaimRaceTest` (PG).
     */
    #[DataProvider('entreLaComprobacionYElUpdate')]
    public function test_r3_2_lo_que_llega_entre_la_comprobacion_y_el_update_no_entrega_el_contacto_ni_una_vez(string $senal, string $motivo): void
    {
        $gente = $this->threePeople();
        [$p] = $this->startedFor(['3001234567']);
        $uuid = (string) $p['job']->uuid;
        $cola = new AssistedQueue;
        $sql = [];
        $inyectada = null;
        DB::listen(function ($q) use (&$sql, &$inyectada, $senal, $p): void {
            $sql[] = strtolower($q->sql);
            if ($inyectada === null && str_contains(strtolower($q->sql), '"detail_code"')) {
                $inyectada = count($sql) - 1;
                match ($senal) {
                    'dnc' => MarketingLead::query()->findOrFail($p['member']['lead'])->forceFill(['do_not_contact' => true])->save(),
                    'pause' => $this->pauseStrategy($this->vivo),
                    'minor' => Member::query()->findOrFail($p['member']['member'])->forceFill(['birth_date' => '2012-03-03'])->save(),
                };
            }
        });

        $r = $cola->claim($gente['beto'], $uuid, (string) Str::uuid());
        $update = array_key_first(array_filter($sql, static fn (string $q): bool => str_starts_with(ltrim($q), 'update "outreach_jobs"')));
        $this->assertNotNull($inyectada, 'la señal llegó a mitad');
        $this->assertLessThan($update, $inyectada, 'después de comprobar y ANTES del UPDATE: '.json_encode($sql));
        $this->assertFalse($r['replayed']);
        $this->assertSame([null, false, $motivo], self::contact($r['job']), "{$senal}: el 201 ya no lleva el enlace ni el guion");
        $this->assertSame((int) $gente['beto']->id, (int) DB::table('outreach_jobs')->where('uuid', $uuid)->value('assisted_claimed_by_admin_id'), 'el trabajo queda reclamado: no se deshace nada');
        $this->assertSame([null, false, $motivo], self::contact($cola->queue($gente['beto'], 'mine')[0]));
        $this->assertStringNotContainsString('3001234567', json_encode($r));
    }

    public function test_r3_2_sin_nada_entre_medias_el_201_lleva_el_contacto(): void
    {
        $gente = $this->threePeople();
        [$p] = $this->startedFor(['3001234567']);
        $r = (new AssistedQueue)->claim($gente['beto'], (string) $p['job']->uuid, (string) Str::uuid());

        $this->assertSame([['kind' => 'member', 'id' => $p['member']['member']], true, null], self::contact($r['job']), 'la relectura no retiene de más');
    }

    // ── m-1 (security R3) · La inscripción que se cierra entre la comprobación y el UPDATE ──

    /** @return array<string, array{0: string, 1: string, 2: string|null}> */
    public static function cierresEntreLaComprobacionYElUpdate(): array
    {
        return [
            'el fin de la ventana (el despachador: expired)' => ['expired', 'enrollment_window_over', 'no_response'],
            'el éxito (completed)' => ['completed', 'success_reached', null],
            'un opt-out en otra estrategia (el registro de resultados: exited)' => ['exited', 'opted_out', null],
        ];
    }

    /**
     * m-1 de `informe-security-bandeja-r3.md`: la inscripción se cierra justo DESPUÉS de la última lectura de la
     * comprobación (la del libro de supresiones) y ANTES del `UPDATE`, por `OutreachStore::closeEnrollment()` (el camino
     * del despachador y del registro de resultados). La reclamación queda escrita (no se deshace nada), pero no entrega
     * el contacto ni una vez: 409 `enrollment_closed`, lo MISMO que la repetición. En la misma conexión: la de dos
     * conexiones de verdad está en `H9AssistedClaimRaceTest` (PG).
     */
    #[DataProvider('cierresEntreLaComprobacionYElUpdate')]
    public function test_m1_r3_la_inscripcion_que_se_cierra_entre_la_comprobacion_y_el_update_da_409_enrollment_closed_sin_contacto(string $estado, string $motivo, ?string $ultimo): void
    {
        $gente = $this->threePeople();
        [$p] = $this->startedFor(['3001234567']);
        $uuid = (string) $p['job']->uuid;
        $cola = new AssistedQueue;
        $sql = [];
        $inyectada = null;
        DB::listen(function ($q) use (&$sql, &$inyectada, $estado, $motivo, $ultimo, $p): void {
            $sql[] = strtolower($q->sql);
            if ($inyectada === null && str_contains(strtolower($q->sql), '"detail_code"')) {
                $inyectada = count($sql) - 1;
                $this->assertTrue((new OutreachStore)->closeEnrollment((int) $p['job']->enrollment_id, $estado, $motivo, AssistedQueue::now(), $ultimo), 'la inscripción estaba abierta');
            }
        });

        foreach (['la reclamación' => (string) Str::uuid(), 'la repetición' => (string) Str::uuid()] as $que => $peticion) {
            try {
                $r = $cola->claim($gente['beto'], $uuid, $peticion);
                $this->fail("{$que} entregó la fila de una inscripción cerrada: ".json_encode(self::contact($r['job'])));
            } catch (OutreachException $e) {
                $this->assertSame([409, 'enrollment_closed'], [$e->status, $e->errorCode], $que);
                $this->assertStringNotContainsString('3001234567', json_encode([$e->getMessage(), $e->payload]));
            }
        }
        $update = array_key_first(array_filter($sql, static fn (string $q): bool => str_starts_with(ltrim($q), 'update "outreach_jobs"')));
        $this->assertNotNull($inyectada, 'el cierre llegó a mitad');
        $this->assertNotNull($update, 'la reclamación llegó a escribir');
        $this->assertLessThan($update, $inyectada, 'después de comprobar y ANTES del UPDATE: '.json_encode($sql));
        $this->assertSame((int) $gente['beto']->id, (int) DB::table('outreach_jobs')->where('uuid', $uuid)->value('assisted_claimed_by_admin_id'), 'el trabajo queda reclamado: no se deshace nada');
        $this->assertSame($estado, DB::table('outreach_enrollments')->where('id', $p['job']->enrollment_id)->value('status'));
        $this->assertSame([], $cola->queue($gente['beto'], 'mine'), 'tampoco en «mine»');
    }

    // ── F-1 · El modo o el canal asistido, apagados por configuración ───────

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function apagados(): array
    {
        return [
            'el modo, a sombra (N2: lo vivo ya no sale)' => [['outreach.mode' => 'shadow']],
            'el modo, apagado (N2 mode_off)' => [['outreach.mode' => 'off']],
            'el canal asistido, apagado (N4 channel_disabled)' => [['outreach.assisted.enabled' => false]],
        ];
    }

    /**
     * F-1: lo que ya salió y la configuración ya no dejaría salir se retiene como un freno, en cuatro pasos: no se
     * ofrece, no se deja reclamar, quien ya lo reclamó pierde el enlace y el guion y, al volver a encender, vuelve todo.
     *
     * @param  array<string, mixed>  $apagar
     */
    #[DataProvider('apagados')]
    public function test_f1_el_modo_o_el_canal_apagados_despues_de_salir_retienen_como_un_freno_y_al_encender_vuelve(array $apagar): void
    {
        $gente = $this->threePeople();
        [$mio, $libre] = $this->startedFor(['3001234561', '3001234562']);
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], (string) $mio['job']->uuid, (string) Str::uuid());
        $encendido = ['outreach.mode' => config('outreach.mode'), 'outreach.assisted.enabled' => config('outreach.assisted.enabled')];

        config($apagar);
        // Pasos 1 a 3: no se ofrece (y se cuenta), no se reclama (409, `brake` al registro) y el reclamante, sin contacto.
        $this->assertWithheldEverywhere($cola, (string) $mio['job']->uuid, (string) $libre['job']->uuid, 'brake', '300123456');

        // Paso 4: al volver a encender, vuelve todo.
        config($encendido);
        $this->assertNotNull(self::row($cola->queue($gente['beto'], 'unclaimed', 50), (string) $libre['job']->uuid), 'encendido: el libre se ofrece');
        $this->assertSame(0, $cola->page($gente['beto'], 'unclaimed', 50)['withheld']);
        $this->assertSame([['kind' => 'member', 'id' => $mio['member']['member']], true, null], self::contact(self::row($cola->queue($gente['ana'], 'mine', 50), (string) $mio['job']->uuid)), 'y el reclamante recupera el contacto');
        $r = $cola->claim($gente['beto'], (string) $libre['job']->uuid, (string) Str::uuid());
        $this->assertSame([['kind' => 'member', 'id' => $libre['member']['member']], true, null], self::contact($r['job']), 'y se deja reclamar con el contacto');
    }

    // ── F-2 · El canario desactivado o revocado después de salir ────────────

    /**
     * El número canario `$e164` del canal pasa a `$to` con las MISMAS columnas que escribe el Studio:
     * `StudioCanaryNumbers::deactivate()` (vuelve a `authorized`), `revoke()` (`revoked`, sin el cifrado, con quién,
     * cuándo y por qué: `uscn_revoke_chk`) y `activate()` (`active`).
     */
    private function moveCanary(string $e164, string $channel, string $to): void
    {
        $fila = DB::table('ultron_studio_canary_numbers')->where('phone_hash', PhoneFingerprint::of($e164))->where('channel', $channel);
        $this->assertSame(1, match ($to) {
            'authorized', 'active' => $fila->update(['status' => $to, 'status_changed_at' => now(), 'updated_at' => now()]),
            'revoked' => $fila->update(['status' => 'revoked', 'phone_encrypted' => null, 'revoked_by_admin_id' => 1, 'revoked_by_name' => 'Ana', 'revoked_by_role' => 'Super Admin',
                'revoked_at' => now(), 'revoke_reason' => 'Ya no se usa este móvil', 'status_changed_at' => now(), 'updated_at' => now()]),
        });
    }

    /** @return array<string, array{0: string}> */
    public static function canariosQueSalen(): array
    {
        return ['desactivado (vuelve a «autorizado»)' => ['authorized'], 'revocado' => ['revoked']];
    }

    /**
     * F-2: en la parte canario (N5 `canary_scope_mismatch`) solo alcanza a los números canarios ACTIVOS del medio. Si el
     * de la persona se desactiva o se revoca después de salir, lo libre no se ofrece ni se reclama y quien ya lo reclamó
     * pierde el enlace y el guion. El motivo es uno propio: no lo pidió (`opted_out`), no es un freno (`brake`) y se
     * sabe (`policy_unknown` no).
     */
    #[DataProvider('canariosQueSalen')]
    public function test_f2_el_canario_que_deja_de_estar_activo_despues_de_salir_retiene_la_parte_canario(string $to): void
    {
        $gente = $this->threePeople();
        [$mio, $libre] = $this->startedFor(['3001234561', '3001234562']);
        $this->assertSame('canary', $this->vivo['part']);
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], (string) $mio['job']->uuid, (string) Str::uuid());

        foreach (['+573001234561', '+573001234562'] as $numero) {
            $this->moveCanary($numero, 'whatsapp', $to);
        }
        $this->assertWithheldEverywhere($cola, (string) $mio['job']->uuid, (string) $libre['job']->uuid, 'canary_scope_mismatch', '300123456');

        if ($to === 'authorized') {
            // Activado otra vez: vuelve. (Revocar es definitivo: hay que volver a registrar el número.)
            foreach (['+573001234561', '+573001234562'] as $numero) {
                $this->moveCanary($numero, 'whatsapp', 'active');
            }
            $this->assertNotNull(self::row($cola->queue($gente['beto'], 'unclaimed', 50), (string) $libre['job']->uuid));
            $this->assertSame([['kind' => 'member', 'id' => $mio['member']['member']], true, null], self::contact(self::row($cola->queue($gente['ana'], 'mine', 50), (string) $mio['job']->uuid)));
        }
    }

    /**
     * F-2 también fuera de la parte canario: la línea base del Studio declara «solo canario» (N3 `canary_only_declared`),
     * así que en la parte publicada (`rest`) tampoco sale a quien no sea canario. Y es por MEDIO: el canario de voz
     * revocado retiene el asistido por voz, no el de WhatsApp de la misma persona.
     */
    public function test_f2_con_la_version_solo_canario_tambien_retiene_la_parte_publicada_y_por_medio(): void
    {
        $gente = $this->threePeople();
        $this->startedFor(['3001234567']);
        $publicada = $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]), 'PUBLISHED');
        $this->assertSame('rest', $publicada['part']);
        $uno = $this->callableMember('+573005550101');
        $voz = $this->uuidOf($this->seedStarted(['part' => 'rest'] + $uno['persona'], 'voice', '2026-11-18 09:00:00', $publicada));
        $whatsapp = $this->uuidOf($this->seedStarted($uno['persona'], 'whatsapp', '2026-11-18 10:00:00'));
        $cola = new AssistedQueue;
        $libres = static fn () => array_column($cola->queue($gente['beto'], 'unclaimed', 50), 'uuid');
        $this->assertContains($voz, $libres(), 'antes, canario de voz activo: se ofrece en la parte publicada');

        $this->moveCanary('+57'.$uno['phone'], 'voice', 'revoked');
        $this->assertNotContains($voz, $libres(), 'el de voz, ya no: la versión es «solo canario»');
        $this->assertContains($whatsapp, $libres(), 'el de WhatsApp de la misma persona, sí: su canario de WhatsApp sigue activo');
        Log::spy();
        $this->assertClaimWithheld($cola, $voz, 'canario de voz revocado');
        Log::shouldHaveReceived('warning')->once()->with('outreach.assisted.claim_withheld', ['job' => $voz, 'reason' => 'canary_scope_mismatch']);
    }

    // ── F-3 · La plantilla que deja de estar aprobada después de salir ──────

    /**
     * F-3: retirada la plantilla (APPROVED → RETIRED, la única salida que admiten el modelo y la base,
     * `TEMPLATE_TRANSITIONS`), su guion no se entrega: lo libre no se ofrece ni se reclama y quien ya lo reclamó lo
     * pierde, con un motivo propio. El guion no aparece en ninguna respuesta. Va detrás de la estrategia y delante de
     * lo de la persona.
     */
    public function test_f3_la_plantilla_retirada_despues_de_salir_no_entrega_su_guion_y_retiene(): void
    {
        $gente = $this->threePeople();
        [$mio, $libre] = $this->startedFor(['3001234561', '3001234562']);
        $cola = new AssistedQueue;
        $antes = $cola->claim($gente['ana'], (string) $mio['job']->uuid, (string) Str::uuid())['job']['script'];
        $this->assertIsString($antes);
        $plantilla = (int) $mio['job']->template_version_id;
        $puente = new OutreachPublishedStrategies;
        $this->assertSame($antes, $puente->assistedScript($plantilla, (string) $mio['job']->template_hash), 'aprobada y con la huella del sello: el guion');
        $this->assertNull($puente->assistedScript($plantilla, str_repeat('0', 64)), 'con otra huella, no');

        DB::table('ultron_studio_message_templates')->where('id', $plantilla)->update(['status' => 'RETIRED', 'status_changed_at' => now(), 'updated_at' => now()]);
        $this->assertNull($puente->assistedScript($plantilla, (string) $mio['job']->template_hash), 'retirada: sin guion');
        $this->assertNull($puente->assistedScript($plantilla), 'tampoco sin huella');

        $this->assertWithheldEverywhere($cola, (string) $mio['job']->uuid, (string) $libre['job']->uuid, 'template_not_approved', '300123456');
        $this->assertStringNotContainsString($antes, json_encode([$cola->page($gente['ana'], 'all', 50), $cola->page($gente['beto'], 'all', 50), $cola->show((string) $mio['job']->uuid)]));

        // La prioridad: delante de la persona (un «no contactar» no lo cambia) y detrás de la estrategia parada.
        MarketingLead::query()->findOrFail($mio['member']['lead'])->forceFill(['do_not_contact' => true])->save();
        $motivo = fn (): mixed => self::contact(self::row($cola->queue($gente['ana'], 'mine', 50), (string) $mio['job']->uuid))[2];
        $this->assertSame('template_not_approved', $motivo(), 'template_not_approved > opted_out');
        $this->pauseStrategy($this->vivo);
        $this->assertSame('strategy_stopped', $motivo(), 'strategy_stopped > template_not_approved');
    }

    // ── m-2 (security R3) · La referencia del Studio que deja de valer ──────

    /** Una versión del Studio aprobada, por las filas del esquema (como `V9World::v9PublishVoice()`). @return array{uuid: string, hash: string} */
    private function studioVersion(): array
    {
        $uuid = (string) Str::uuid();
        $hash = hash('sha256', 'version-del-studio-'.$uuid);
        DB::table('ultron_studio_versions')->insert([
            'uuid' => $uuid, 'client_request_id' => (string) Str::uuid(), 'label' => 'Versión del Studio de la bandeja', 'status' => 'APPROVED',
            'content' => '{}', 'content_hash' => $hash, 'catalog_version' => StudioCatalog::VERSION, 'catalog_hash' => StudioCatalog::hash(),
            'source_draft_revision' => 1, 'created_by_admin_id' => (int) $this->threePeople()['ana']->id, 'created_by_name' => 'Ana', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['uuid' => $uuid, 'hash' => $hash];
    }

    /**
     * Una estrategia viva en la parte canario con la cadena FIRMADA de verdad, como `RunWorld::liveStrategy()`, cuya
     * versión guarda la referencia del Studio `$ref` en su contenido y en su fila. Las columnas `studio_*` de la fila son
     * inmutables, así que se siembran así al crearla.
     *
     * @param  array<string, string>  $ref  `{kind: version, uuid, contentHash}` o `{kind: baseline, catalogVersion, catalogHash}`
     * @return array<string, mixed>
     */
    private function liveWithStudioReference(array $ref): array
    {
        $leido = StrategyContent::fromArray($this->validContent($this->assistedTemplate(), ['exclusions' => null, 'studio' => $ref]), (new CatalogDomains)->read());
        $this->assertNotNull($leido['canonical'], 'el contenido de la prueba es válido: '.json_encode($leido['errors']));
        $s = $this->insertStrategy(['purpose' => 'commercial']);
        $v = $this->insertVersion($s, [
            'content_json' => $leido['json'], 'content_hash' => $leido['contentHash'], 'catalog_version' => OutreachCatalog::VERSION,
            'studio_ref_kind' => $ref['kind'],
            'studio_version_uuid' => $ref['kind'] === 'version' ? $ref['uuid'] : null,
            'studio_content_hash' => $ref['kind'] === 'version' ? $ref['contentHash'] : $ref['catalogHash'],
        ]);
        $this->approveVersion($v);
        $fila = DB::table('ultron_studio_strategy_versions')->where('id', $v)->first();
        $seq = $this->signedPublication($s, $v, 'publish', ['canary' => ['contentHash' => (string) $fila->content_hash, 'version' => (string) $fila->uuid], 'rest' => null, 'status' => 'active']);
        $this->setVersionStatus($v, 'CANARY');

        return [
            'strategy' => $s, 'strategyUuid' => (string) DB::table('ultron_studio_strategies')->where('id', $s)->value('uuid'), 'version' => $v,
            'versionUuid' => (string) $fila->uuid, 'seq' => $seq, 'purpose' => 'commercial', 'part' => 'canary',
            'v' => DB::table('ultron_studio_strategy_versions')->where('id', $v)->first(), 'contentHash' => (string) $fila->content_hash,
        ];
    }

    /**
     * Dos socios canarios de WhatsApp con un trabajo asistido `started` en vivo cada uno en `$live`: el primero, reclamado
     * por Ana a mano (lo que pasó ANTES); el segundo, libre.
     *
     * @param  array<string, mixed>  $live
     * @return array{0: string, 1: string, 2: array<string, mixed>} [el reclamado, el libre, el socio del reclamado]
     */
    private function mineAndFreeIn(array $live, bool $claimByHand = true): array
    {
        [$uno, $dos] = [$this->canaryMember('whatsapp'), $this->canaryMember('whatsapp')];
        $mio = $this->seedStarted(['subject_key' => $uno['key'], 'member_id' => $uno['member']], 'whatsapp', '2026-11-18 09:00:00', $live);
        $libre = $this->seedStarted(['subject_key' => $dos['key'], 'member_id' => $dos['member']], 'whatsapp', '2026-11-18 09:00:00', $live);
        if ($claimByHand) {
            $this->claimedBy($mio, $this->threePeople()['ana']);
        }

        return [$this->uuidOf($mio), $this->uuidOf($libre), $uno];
    }

    /** La referencia del Studio de la estrategia, como la lee el puente (la entrada de N3). @return array{0: bool, 1: string|null} */
    private static function studioOf(array $live): array
    {
        $l = (new OutreachPublishedStrategies)->liveStrategies([(int) $live['strategy']])[0];

        return [$l->studio['referenceValid'], $l->studio['reason']];
    }

    /** @return array<string, array{0: string}> */
    public static function referenciasQueNoValen(): array
    {
        return [
            'la versión del Studio que ya no se encuentra' => ['version_not_found'],
            'la huella de la versión del Studio, distinta' => ['content_hash_mismatch'],
            'la línea base de otro catálogo (un despliegue que cambia StudioCatalog::hash())' => ['baseline_mismatch'],
        ];
    }

    /**
     * m-2 de `informe-security-bandeja-r3.md`: con la referencia del Studio de su versión rota, la escalera niega a todos
     * (N3 `studio_reference_invalid`). La bandeja tampoco entrega: la parte no está en marcha (`strategy_stopped`), así
     * que lo libre no se ofrece ni se reclama y quien ya lo reclamó pierde el enlace y el guion. Lo de OTRA estrategia
     * sigue. Cada causa del puente (`OutreachPublishedStrategies::studioReference()`), sembrada en las filas.
     */
    #[DataProvider('referenciasQueNoValen')]
    public function test_m2_la_referencia_del_studio_que_no_vale_para_la_estrategia_en_la_bandeja_como_n3(string $causa): void
    {
        $gente = $this->threePeople();
        [$otro] = $this->startedFor(['3001234567']);
        $studio = $this->studioVersion();
        $vivo = $this->liveWithStudioReference(match ($causa) {
            'version_not_found' => ['kind' => 'version', 'uuid' => (string) Str::uuid(), 'contentHash' => $studio['hash']],
            'content_hash_mismatch' => ['kind' => 'version', 'uuid' => $studio['uuid'], 'contentHash' => hash('sha256', 'otra-huella')],
            'baseline_mismatch' => ['kind' => 'baseline', 'catalogVersion' => StudioCatalog::VERSION, 'catalogHash' => hash('sha256', 'el-catalogo-de-antes')],
        });
        $this->assertSame([false, $causa], self::studioOf($vivo), 'la causa sembrada es la que lee el puente');
        [$mio, $libre] = $this->mineAndFreeIn($vivo);
        $cola = new AssistedQueue;

        $this->assertWithheldEverywhere($cola, $mio, $libre, 'strategy_stopped', '310000000');
        $this->assertNotNull(self::row($cola->queue($gente['beto'], 'unclaimed', 50), (string) $otro['job']->uuid), 'lo de otra estrategia, sí: N3 es de la versión');
    }

    /**
     * m-2, la cuarta causa: la referencia que no se puede LEER (`unreadable`: la lectura de la versión del Studio falla).
     * Empieza íntegra (el control de la siembra con `kind: version`): se ofrece y el reclamante recibe el enlace y el
     * guion. Cuando la tabla deja de leerse, retiene como las otras tres; cuando vuelve, vuelve todo.
     */
    public function test_m2_la_referencia_del_studio_ilegible_para_la_estrategia_y_al_volver_a_leerse_vuelve(): void
    {
        $gente = $this->threePeople();
        $this->startedFor(['3001234567']);
        $studio = $this->studioVersion();
        $vivo = $this->liveWithStudioReference(['kind' => 'version', 'uuid' => $studio['uuid'], 'contentHash' => $studio['hash']]);
        $this->assertSame([true, null], self::studioOf($vivo), 'íntegra');
        [$mio, $libre, $uno] = $this->mineAndFreeIn($vivo, false);
        $cola = new AssistedQueue;
        $this->assertNotNull(self::row($cola->queue($gente['beto'], 'unclaimed', 50), $libre), 'antes: se ofrece');
        $this->assertSame([['kind' => 'member', 'id' => $uno['member']], true, null], self::contact($cola->claim($gente['ana'], $mio, (string) Str::uuid())['job']), 'antes: el enlace y el guion');

        // La lectura de las versiones del Studio falla (un permiso retirado, la tabla bloqueada): la consulta no llega a ejecutarse.
        $ilegible = true;
        DB::beforeExecuting(function (string $sql, array $bindings, $conexion) use (&$ilegible): void {
            if ($ilegible && str_contains($sql, '"ultron_studio_versions"')) {
                throw new QueryException((string) $conexion->getName(), $sql, $bindings, new \PDOException('SQLSTATE[42501]: permission denied for table ultron_studio_versions'));
            }
        });
        $this->assertSame([false, 'unreadable'], self::studioOf($vivo));
        // Lo de assertWithheldEverywhere(), salvo el registro: aquí el puente anota además, con razón, cada lectura ilegible.
        $antes = self::rowsSnapshot();
        Log::spy();
        $this->assertNull(self::row($cola->queue($gente['beto'], 'unclaimed', 50), $libre), 'ilegible: no se ofrece');
        $this->assertNull(self::row($cola->queue($gente['beto'], 'all', 50), $libre), 'ilegible: tampoco en «all»');
        $this->assertSame(1, $cola->page($gente['beto'], 'unclaimed', 50)['withheld'], 'ilegible: se cuenta, sin decir de quién');
        $this->assertClaimWithheld($cola, $libre, 'ilegible');
        Log::shouldHaveReceived('warning')->with('outreach.assisted.claim_withheld', ['job' => $libre, 'reason' => 'strategy_stopped'])->once();
        Log::shouldHaveReceived('warning')->with('outreach.studio_reference_unreadable', ['version_id' => (int) $vivo['version'], 'error_class' => 'QueryException']);
        $this->assertNull(DB::table('outreach_jobs')->where('uuid', $libre)->value('assisted_claimed_by_admin_id'), 'no se reclamó');
        foreach (['mine', 'all'] as $vista) {
            $this->assertSame([null, false, 'strategy_stopped'], self::contact(self::row($cola->queue($gente['ana'], $vista, 50), $mio)), "ilegible: el reclamante en {$vista}");
        }
        $r = $cola->claim($gente['ana'], $mio, (string) Str::uuid());
        $this->assertTrue($r['replayed']);
        $this->assertSame([null, false, 'strategy_stopped'], self::contact($r['job']), 'ilegible: la repetición');
        $this->assertSame([null, false, null], self::contact(self::row($cola->queue($gente['beto'], 'all', 50), $mio)), 'a otra persona no se le cuenta');
        $this->assertStringNotContainsString('310000000', json_encode([$cola->queue($gente['ana'], 'all', 50), $cola->queue($gente['beto'], 'all', 50), $r]));
        $this->assertSame($antes, self::rowsSnapshot(), 'la bandeja no escribe nada al retener');

        // Vuelve a leerse: vuelve todo. Retenía la referencia y nada más.
        $ilegible = false;
        $this->assertNotNull(self::row($cola->queue($gente['beto'], 'unclaimed', 50), $libre));
        $this->assertSame([['kind' => 'member', 'id' => $uno['member']], true, null], self::contact(self::row($cola->queue($gente['ana'], 'mine', 50), $mio)));
    }

    // ── m-1 · Ninguna consulta por fila ─────────────────────────────────────

    /**
     * Las consultas que hace `$f` (un solo oyente para toda la prueba), por su SQL.
     *
     * @return list<string>
     */
    private function queriesOf(callable $f): array
    {
        if (! $this->escuchando) {
            $this->escuchando = true;
            DB::listen(function ($q): void {
                // `in (?, ?, …)` crece con el lote, no es una consulta más. Tampoco la lista `VALUES (?, ?), (?, ?), …`
                // de la tanda del libro de autorizaciones (C9, `OutreachConsentFacts`): una sentencia por tramo de claves.
                $sql = (string) preg_replace('/\(\?(, \?)*\)/', '(?…)', (string) $q->sql);
                $this->consultas[] = (string) preg_replace('/VALUES \(\?…\)(?:, \(\?…\))*/', 'VALUES (?…)…', $sql);
            });
        }
        $antes = count($this->consultas);
        $f();

        return array_slice($this->consultas, $antes);
    }

    /** @param  list<string>  $pocas @param  list<string>  $muchas */
    private function assertSameQueries(array $pocas, array $muchas, string $que): void
    {
        $veces = static function (array $sql): array {
            $c = array_count_values($sql);
            ksort($c);

            return $c;
        };
        $distintas = [];
        foreach (array_unique([...$pocas, ...$muchas]) as $q) {
            if (($veces($pocas)[$q] ?? 0) !== ($veces($muchas)[$q] ?? 0)) {
                $distintas[$q] = [$veces($pocas)[$q] ?? 0, $veces($muchas)[$q] ?? 0];
            }
        }
        $this->assertSame([], $distintas, "{$que} (consulta → [con pocas filas, con muchas])");
    }

    /**
     * m-1: el número de consultas de una página NO depende de cuántas filas trae (dentro de una tanda): ni el
     * objetivo (la versión), ni el motivo de la traza, ni el guion se leen por fila. Ni en lo sin reclamar ni en lo
     * reclamado, que es lo que enseña el guion. Lo que enseña, igual que antes por fila.
     */
    public function test_m1_la_pagina_no_hace_ninguna_consulta_por_fila(): void
    {
        $gente = $this->threePeople();
        [$p] = $this->startedFor(['3001234567']);
        $socio = function (): array {
            $m = $this->canaryMember('whatsapp')['member'];

            return ['subject_key' => 'm:'.$m, 'member_id' => $m];
        };
        for ($i = 0; $i < 2; $i++) {
            $this->seedStarted($socio(), 'whatsapp');
        }
        // Cada petición, con su instancia (como en el contenedor): la primera lectura de una instancia calienta su memoria.
        $cola = new AssistedQueue;
        $pocas = $this->queriesOf(fn () => $this->assertCount(3, (new AssistedQueue)->queue($gente['beto'], 'unclaimed', 50)));
        for ($i = 0; $i < 40; $i++) {
            $this->seedStarted($socio(), 'whatsapp');
        }
        $muchas = $this->queriesOf(fn () => $this->assertCount(43, (new AssistedQueue)->queue($gente['beto'], 'unclaimed', 50)));
        $this->assertSameQueries($pocas, $muchas, 'sin reclamar: 3 filas y 43 filas, las mismas consultas');

        // Lo reclamado por quien mira (con enlace y guion): 3 y 43 filas, las mismas consultas.
        $ids = DB::table('outreach_jobs')->orderBy('id')->pluck('id')->all();
        foreach (array_slice($ids, 0, 3) as $id) {
            $this->claimedBy((int) $id, $gente['ana']);
        }
        $pocas = $this->queriesOf(fn () => $this->assertCount(3, (new AssistedQueue)->queue($gente['ana'], 'mine', 50)));
        foreach (array_slice($ids, 3) as $id) {
            $this->claimedBy((int) $id, $gente['ana']);
        }
        $mias = [];
        $muchas = $this->queriesOf(function () use ($gente, &$mias): void {
            $mias = (new AssistedQueue)->queue($gente['ana'], 'mine', 50);
        });
        $this->assertCount(43, $mias);
        $this->assertSameQueries($pocas, $muchas, 'reclamado: 3 filas y 43 filas, las mismas consultas');

        // Lo que enseña cada fila no cambia: el guion de la plantilla, el objetivo de la versión y el motivo de su traza.
        $this->assertSame([43, 1], [count(array_filter($mias, static fn (array $f): bool => is_string($f['script']) && $f['script'] !== '')), count(array_unique(array_column($mias, 'script')))]);
        $this->assertSame(['renew'], array_values(array_unique(array_map(static fn (array $f): ?string => $f['strategy']['goal'], $mias))));
        $delDespachador = self::row($mias, (string) $p['job']->uuid);
        $this->assertNotSame([], $delDespachador['reason'], 'el trabajo del despachador tiene su traza de plan');
        $this->assertSame($delDespachador['reason'], $cola->show((string) $p['job']->uuid)['reason'], 'la misma traza en la página y en el detalle');
        $sembradas = array_filter($mias, static fn (array $f): bool => $f['uuid'] !== (string) $p['job']->uuid);
        $this->assertSame([[]], array_values(array_unique(array_map(static fn (array $f): array => $f['reason'], $sembradas), SORT_REGULAR)), 'sin decisión de plan, sin frases');
    }
}
