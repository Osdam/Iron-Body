<?php

namespace Tests\Feature\Outreach\Run;

use App\Models\Admin;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Services\Outreach\Run\AssistedQueue;
use App\Services\Outreach\Run\OutcomeRecorder;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachException;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachReplay;
use App\Services\Telephony\VoiceContacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
     * confirmado por OTRA persona (E3-4) y una consulta al RNE vigente que dice `not_listed`.
     *
     * @return array{member: int, persona: array<string, mixed>, contact: array{id: int, hash: string, uuid: string}}
     */
    private function callableMember(string $e164, string $rneCheckedAt = '2026-11-15 12:00:00'): array
    {
        $socio = $this->socio();
        $contacto = $this->voiceContact('member', $socio['member'], $e164);
        $this->voiceConsent($contacto['id'], 'granted', 2, '2026-11-01 12:00:00', 1);
        $this->rneCheck($contacto['hash'], $rneCheckedAt);

        return [
            'member' => $socio['member'],
            'persona' => ['subject_key' => 'm:'.$socio['member'], 'member_id' => $socio['member']],
            'contact' => $contacto + ['uuid' => (string) DB::table('voice_contacts')->where('id', $contacto['id'])->value('uuid')],
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
     * escribe y no sale ningún teléfono.
     */
    private function assertWithheldEverywhere(AssistedQueue $cola, string $mio, string $libre, string $motivo, string $phoneFragment): void
    {
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
        $this->assertStringNotContainsString($phoneFragment, json_encode([$cola->queue($gente['ana'], 'all', 50), $cola->queue($gente['beto'], 'all', 50), $r]));
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
        $this->assertWithheldEverywhere($cola, $mio, $libre, 'opted_out', '555010');
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

        $this->assertWithheldEverywhere($cola, $mio, $libre, 'policy_unknown', '555010');
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
        $this->assertWithheldEverywhere($cola, $mio, $libre, 'rne_check_missing', '555010');

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

        $this->assertSame(['brake', 'strategy_stopped', 'opted_out', 'complaint_recent', 'subject_minor', 'rne_check_missing', 'policy_unknown', 'complaint_unknown', 'expired'], AssistedQueue::WITHHELD_REASONS);
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
                // `in (?, ?, …)` crece con el lote, no es una consulta más.
                $this->consultas[] = (string) preg_replace('/\(\?(, \?)*\)/', '(?…)', (string) $q->sql);
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
            $m = $this->socio()['member'];

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
