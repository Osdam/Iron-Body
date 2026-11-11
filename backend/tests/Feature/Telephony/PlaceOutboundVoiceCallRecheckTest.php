<?php

namespace Tests\Feature\Telephony;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Admin;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Models\Voice\VoiceContact;
use App\Services\Telephony\OutboundGate;
use App\Services\Telephony\OutboundPolicy;
use App\Services\Telephony\VoiceContacts;
use App\Services\Telephony\VoiceCostGuard;
use App\Services\Telephony\VoiceNumberGuard;
use App\Services\Voice\Dto\OutboundCallRequest;
use App\Services\Voice\Dto\OutboundCallResult;
use App\Services\Voice\VoiceCallStarter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * El job REPITE el destino y el calendario de la puerta justo antes de marcar
 * (contrato H8, D8-12, D8-15, D8-18; revisión de seguridad sobre `61513a1`,
 * TOCTOU): entre encolar y marcar pueden revocarse el consentimiento, añadirse
 * una exclusión, caducar la consulta al RNE o cerrarse la ventana. Con las
 * MISMAS reglas y los mismos motivos que la puerta
 * ({@see OutboundPolicy::targetReasons()}), y cancelando sin petición.
 *
 * En el árbol compartido la constante está cerrada y `run()` no pasa de ella:
 * aquí se recorren los pasos 1–5 que van detrás (`recheckAfterCode`), con todo
 * lo anterior en regla (banderas encendidas EN LA PRUEBA, freno suelto, la
 * entrada sellada vigente y la deriva comprobada en la caché). Sin red.
 */
class PlaceOutboundVoiceCallRecheckTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const REST_AGENT = 'agent_h8fx_rest_0001';

    private const CONTACT_PHONE = '+573000000071';

    private Admin $ana;

    private Admin $beto;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        // Un miércoles laborable de Bogotá, dentro de la ventana legal (07:00–19:00).
        $this->travelTo(Carbon::parse('2026-11-04 10:00:00', 'America/Bogota'));
        $this->voiceWorld(['voice.outbound.enabled' => true, 'voice.twilio.enabled' => true, 'voice.contacts.rne_validity_days' => 30]);
        $this->publishVoice();                                // canary, seq 1, el agente sellado
        $this->publishVoice(self::REST_AGENT, 'rest');       // rest, seq 2
        $this->driftChecked();
        $this->ana = $this->person(Admin::ROLE_SUPER_ADMIN, 'Ana Contactos');
        $this->beto = $this->person(Admin::ROLE_SUPER_ADMIN, 'Beto Confirma');
    }

    /**
     * La deriva ya comprobada (sin deriva) en la caché del job: el paso 4 no sale
     * a la red. Y (B8-g) el número que llama vigilado ahora: una conciliación
     * reciente que cuadra, que también caduca al viajar en el tiempo.
     */
    private function driftChecked(): void
    {
        foreach ([self::SEALED_AGENT, self::REST_AGENT] as $agente) {
            Cache::put(OutboundGate::DRIFT_CACHE_PREFIX.$agente, false, OutboundGate::DRIFT_CACHE_SECONDS);
        }
        VoiceNumberGuard::rememberObservation(self::PHONE_NUMBER_ID, true, false);
    }

    private function recheck(VoiceCall $llamada): ?string
    {
        return (new ReflectionMethod(PlaceOutboundVoiceCall::class, 'recheckAfterCode'))
            ->invoke(new PlaceOutboundVoiceCall((int) $llamada->id), VoiceCall::query()->findOrFail($llamada->id));
    }

    /** Un canario de voz activo y su saliente pedida (alcance `canary`, parte canaria, entrada 1). */
    private function canaryCall(): VoiceCall
    {
        return $this->voiceCall('outbound', 'requested', ['canary_number_uuid' => $this->activeVoiceCanary(), 'remote' => self::CANARY_PHONE]);
    }

    /**
     * Un contacto con consentimiento confirmado a cuatro ojos y una consulta al
     * RNE vigente, y su saliente pedida (alcance `contacts`, parte `rest`).
     *
     * @return array{0: VoiceCall, 1: string}
     */
    private function contactCall(?Carbon $rneCheckedAt = null): array
    {
        // La fase 4b: el alcance `contacts` y, para darlos de alta, su bandera (auditoría B-T4).
        config(['voice.outbound.scope' => 'contacts', 'voice.contacts.enabled' => true]);
        $contactos = app(VoiceContacts::class);
        $uuid = (string) $contactos->register($this->ana, ['clientRequestId' => (string) Str::uuid(), 'number' => self::CONTACT_PHONE, 'sourceType' => 'manual', 'sourceId' => null])['contact']['uuid'];
        $contactos->requestConsent($this->ana, $uuid, ['source' => 'written_form', 'evidence' => 'Formulario firmado en recepción']);
        $contactos->confirmConsent($this->beto, $uuid, (string) Str::uuid());
        $contactos->rneCheck($this->ana, ['clientRequestId' => (string) Str::uuid(), 'contactUuid' => $uuid,
            'checkedAt' => ($rneCheckedAt ?? now()->subDay())->toIso8601String(), 'result' => 'not_listed', 'evidenceRef' => 'Consulta CRC del día']);
        $llamada = $this->voiceCall('outbound', 'requested', ['target_source' => 'voice_contacts', 'canary_number_uuid' => null,
            'voice_contact_id' => (int) VoiceContact::query()->where('uuid', $uuid)->value('id'), 'remote' => self::CONTACT_PHONE,
            'part' => 'rest', 'voice_publication_seq' => 2, 'provider_agent_id' => self::REST_AGENT]);

        return [$llamada, $uuid];
    }

    public function test_con_todo_en_regla_no_hay_motivo_y_la_constante_sigue_yendo_primero(): void
    {
        $canaria = $this->canaryCall();
        $this->assertNull($this->recheck($canaria), 'canario activo, sin exclusión, dentro de la ventana');
        [$deContacto] = $this->contactCall();
        $this->assertNull($this->recheck($deContacto), 'contacto con consentimiento, RNE vigente, sin exclusión');

        // El job de verdad no pasa de la constante (DF-G9): cancela por ella, sin red.
        $iniciador = new class implements VoiceCallStarter
        {
            public function startOutbound(OutboundCallRequest $request): OutboundCallResult
            {
                throw new RuntimeException('no tenía que marcar');
            }
        };
        $this->assertSame('canceled', (new PlaceOutboundVoiceCall((int) $canaria->id))->run($iniciador));
        $this->assertSame('calls_disabled_in_code', VoiceCall::query()->findOrFail($canaria->id)->policy_reason);
        Http::assertNothingSent();
    }

    public function test_el_consentimiento_revocado_tras_encolar_cancela_con_no_voice_consent(): void
    {
        [$llamada, $uuid] = $this->contactCall();
        $this->assertNull($this->recheck($llamada));

        app(VoiceContacts::class)->revoke($this->ana, $uuid, ['clientRequestId' => (string) Str::uuid(), 'source' => 'written_form', 'evidence' => 'Pidió no recibir más llamadas']);
        $this->assertSame('no_voice_consent', $this->recheck($llamada));
    }

    public function test_una_exclusion_anadida_tras_encolar_cancela_con_excluded(): void
    {
        $canaria = $this->canaryCall();
        [$deContacto] = $this->contactCall();
        config(['voice.outbound.scope' => 'canary']);
        $this->assertNull($this->recheck($canaria));

        // Por número (la huella del destino, la misma que la sellada en la fila).
        app(VoiceContacts::class)->exclude($this->ana, ['clientRequestId' => (string) Str::uuid(), 'contactUuid' => null, 'number' => self::CANARY_PHONE, 'note' => null]);
        $this->assertSame('excluded', $this->recheck($canaria));

        config(['voice.outbound.scope' => 'contacts']);
        $this->assertNull($this->recheck($deContacto));
        app(VoiceContacts::class)->exclude($this->ana, ['clientRequestId' => (string) Str::uuid(), 'contactUuid' => null, 'number' => self::CONTACT_PHONE, 'note' => null]);
        $this->assertSame('excluded', $this->recheck($deContacto));

        // La huella SELLADA en la fila también cuenta, aunque el número del destino ya no sea ese
        // (sin esto el job cancelaría igual, pero por `error` al construir la petición).
        config(['voice.outbound.scope' => 'canary']);
        $otroCanario = $this->activeVoiceCanary('+573000000078');
        $sellada = $this->voiceCall('outbound', 'requested', ['canary_number_uuid' => $otroCanario, 'remote' => '+573000000079']);
        $this->assertNull($this->recheck($sellada));
        app(VoiceContacts::class)->exclude($this->ana, ['clientRequestId' => (string) Str::uuid(), 'contactUuid' => null, 'number' => '+573000000079', 'note' => null]);
        $this->assertSame('excluded', $this->recheck($sellada));
    }

    public function test_si_el_alcance_se_estrecha_tras_encolar_el_contacto_ya_no_pasa(): void
    {
        [$llamada] = $this->contactCall();
        $this->assertNull($this->recheck($llamada));
        // D8-20 nivel 0: el alcance vuelve a `canary` con la saliente en la cola; la puerta ya no la aceptaría.
        config(['voice.outbound.scope' => 'canary']);
        $this->assertSame('not_an_active_voice_canary', $this->recheck($llamada));
    }

    public function test_una_consulta_al_rne_que_caduca_tras_encolar_cancela_con_rne_check_missing(): void
    {
        // Vigente al encolar por una hora (vigencia de 30 días).
        [$llamada] = $this->contactCall(now()->subDays(30)->addHour());
        $this->assertNull($this->recheck($llamada));

        $this->travel(2)->hours();
        $this->driftChecked();
        $this->assertSame('rne_check_missing', $this->recheck($llamada));

        // Y con la vigencia a 0 (el defecto mientras P-14 no se decida) ninguna vale.
        $this->travel(-2)->hours();
        $this->driftChecked();
        config(['voice.contacts.rne_validity_days' => 0]);
        $this->assertSame('rne_check_missing', $this->recheck($llamada));
    }

    public function test_un_job_que_corre_fuera_de_la_ventana_o_en_festivo_cancela_con_su_motivo(): void
    {
        $llamada = $this->canaryCall();
        $this->assertNull($this->recheck($llamada));

        // Encolada a las 10:00, el worker la toma a las 19:30 (la ventana legal cierra a las 19:00).
        $this->travelTo(Carbon::parse('2026-11-04 19:30:00', 'America/Bogota'));
        $this->driftChecked();
        $this->assertSame('outside_hours', $this->recheck($llamada));

        // Dentro de la legal pero fuera de la PROPIA (más estrecha), configurada después de encolar.
        $this->travelTo(Carbon::parse('2026-11-04 13:00:00', 'America/Bogota'));
        $this->driftChecked();
        config(['voice.outbound.own_hours.weekdays' => '08:00-12:00', 'voice.outbound.own_hours.saturday' => '']);
        $this->assertSame('outside_hours', $this->recheck($llamada));

        // Un festivo laborable (8 de diciembre, martes) a las 10:00.
        config(['voice.outbound.own_hours.weekdays' => '', 'voice.outbound.own_hours.saturday' => '']);
        $this->travelTo(Carbon::parse('2026-12-08 10:00:00', 'America/Bogota'));
        $this->driftChecked();
        $this->assertSame('holiday', $this->recheck($llamada));
    }

    /**
     * B8-g (decisión del lead, en la lista de E6-6): el job vuelve a comprobar
     * el número que llama justo antes de marcar. La puerta la dejó pasar con
     * una conciliación fresca; si caduca (2 h) antes de que corra el job, o la
     * siguiente dice que el número no es `TWILIO_VOICE_FROM`, se cancela con el
     * mismo motivo de la puerta, sin marcar, sin red y sin evento.
     */
    public function test_si_la_conciliacion_del_numero_caduca_entre_la_puerta_y_el_marcado_se_cancela_sin_marcar(): void
    {
        $canaria = $this->canaryCall();
        $this->assertNull($this->recheck($canaria), 'con la conciliación fresca, pasa');

        // Caduca entre la puerta y el job: `number_unverified`, antes de mirar la deriva (sin red).
        $this->travel(121)->minutes();
        $this->assertSame('number_unverified', $this->recheck($canaria));

        // Una nueva que dice que el importado no es el declarado: `number_mismatch`.
        VoiceNumberGuard::rememberObservation(self::PHONE_NUMBER_ID, false, false);
        $this->assertSame('number_mismatch', $this->recheck($canaria));

        // Y se cancela con ese motivo: sin marcar, sin evento y liberando la reserva.
        $dia = VoiceCostGuard::budgetDay(now());
        $estado = (new ReflectionMethod(PlaceOutboundVoiceCall::class, 'cancel'))->invoke(new PlaceOutboundVoiceCall((int) $canaria->id), $canaria, 'number_mismatch');
        $fila = VoiceCall::query()->findOrFail($canaria->id);
        $this->assertSame(['canceled', 'canceled', 'number_mismatch'], [$estado, $fila->status, $fila->policy_reason]);
        $this->assertSame(0, VoiceCallEvent::query()->where('call_id', $canaria->id)->count());
        $this->assertSame(0, VoiceCostGuard::committedMicros($dia), 'cancelada: sin coste');

        // Con una fresca que cuadra, vuelve a pasar (con la deriva comprobada otra vez en su caché de 5 min).
        $otra = $this->voiceCall('outbound', 'requested', ['canary_number_uuid' => $this->activeVoiceCanary('+573000000032'), 'remote' => '+573000000032']);
        $this->driftChecked();
        $this->assertNull($this->recheck($otra));
        Http::assertNothingSent();
    }

    public function test_cancelar_por_un_motivo_del_destino_no_marca_no_deja_evento_y_libera_la_reserva(): void
    {
        [$llamada, $uuid] = $this->contactCall();
        $dia = VoiceCostGuard::budgetDay(now());
        $this->assertSame((int) $llamada->reserved_micros, VoiceCostGuard::committedMicros($dia), 'pedida: su reserva cuenta');

        app(VoiceContacts::class)->revoke($this->ana, $uuid, ['clientRequestId' => (string) Str::uuid(), 'source' => 'written_form', 'evidence' => 'Pidió no recibir más llamadas']);
        $motivo = $this->recheck($llamada);
        $estado = (new ReflectionMethod(PlaceOutboundVoiceCall::class, 'cancel'))->invoke(new PlaceOutboundVoiceCall((int) $llamada->id), $llamada, $motivo);

        $fila = VoiceCall::query()->findOrFail($llamada->id);
        $this->assertSame(['canceled', 'canceled', 'no_voice_consent'], [$estado, $fila->status, $fila->policy_reason]);
        $this->assertNotNull($fila->ended_at);
        $this->assertSame(0, VoiceCostGuard::committedMicros($dia), 'cancelada: sin coste');
        $this->assertSame(0, VoiceCallEvent::query()->where('call_id', $llamada->id)->count(), 'como las demás cancelaciones del job (fixture §job: events = [])');
        $this->assertContains($motivo, OutboundPolicy::TARGET_REASONS);
        Http::assertNothingSent();
    }
}
