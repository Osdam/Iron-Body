<?php

namespace Tests\Feature\Telephony;

use App\Models\Admin;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceConsent;
use App\Models\Voice\VoiceContact;
use App\Models\Voice\VoiceExclusion;
use App\Models\Voice\VoiceRneCheck;
use App\Services\Telephony\VoiceContacts;
use App\Services\Telephony\VoiceNumberGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * El CRM de la telefonía (`/api/admin/voice/*`; contrato H8, D8-12, D8-18,
 * D8-22, «API»): cada ruta con su llave (401 sin sesión, 403 con el token
 * compartido o sin la llave, y el camino feliz con la llave justa), la puerta
 * de salientes (en el árbol compartido, 409 `calls_disabled_in_code`), el
 * corte, el registro sin datos personales y los contactos con su
 * consentimiento a CUATRO OJOS (E3-4). Sin red.
 */
class VoiceCrmRoutesTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const VIEW = 'telephony.view';

    private const PLACE = 'telephony.call_place';

    private const CONTACTS = 'telephony.contacts_manage';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->voiceWorld();
        Queue::fake();
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}> método, ruta, llave, cuerpo */
    private function routes(): array
    {
        $uuid = (string) Str::uuid();

        return [
            ['GET', 'status', self::VIEW, []],
            ['GET', 'calls', self::VIEW, []],
            ['GET', "calls/{$uuid}", self::VIEW, []],
            ['GET', 'metrics', self::VIEW, []],
            ['POST', 'calls', self::PLACE, ['clientRequestId' => (string) Str::uuid(), 'target' => ['kind' => 'canary', 'uuid' => $uuid]]],
            ['POST', "calls/{$uuid}/hangup", self::PLACE, []],
            ['GET', 'contacts', self::CONTACTS, []],
            ['POST', 'contacts', self::CONTACTS, ['clientRequestId' => (string) Str::uuid(), 'number' => '+57 300 000 0099', 'source' => ['type' => 'manual', 'id' => null]]],
            ['POST', "contacts/{$uuid}/consents", self::CONTACTS, ['source' => 'written_form', 'evidence' => 'Formulario firmado en recepción']],
            ['POST', "contacts/{$uuid}/consents/confirm", self::CONTACTS, ['clientRequestId' => (string) Str::uuid()]],
            ['POST', "contacts/{$uuid}/revoke", self::CONTACTS, ['clientRequestId' => (string) Str::uuid(), 'source' => 'written_form', 'evidence' => 'Lo pidió por escrito']],
            ['POST', 'exclusions', self::CONTACTS, ['clientRequestId' => (string) Str::uuid(), 'number' => '+57 300 000 0098']],
            ['POST', 'rne-checks', self::CONTACTS, ['clientRequestId' => (string) Str::uuid(), 'contactUuid' => $uuid, 'checkedAt' => '2026-11-01T10:00:00Z', 'result' => 'not_listed', 'evidenceRef' => 'Consulta CRC 2026-11-01']],
        ];
    }

    public function test_cada_ruta_exige_sesion_persona_y_su_llave(): void
    {
        // Cinco peticiones por ruta desde la misma red: aquí se prueba la llave, no el limitador.
        $this->withoutMiddleware(ThrottleRequests::class);
        $sinLlave = $this->adminAs(Admin::ROLE_RECEPCION);
        foreach ($this->routes() as [$metodo, $ruta, $llave, $cuerpo]) {
            $uri = '/api/admin/voice/'.$ruta;
            $this->json($metodo, $uri, $cuerpo)->assertStatus(401);
            $this->json($metodo, $uri, $cuerpo, $this->sharedTokenHeaders())->assertStatus(403);
            $this->json($metodo, $uri, $cuerpo, $sinLlave)->assertStatus(403);
            $con = $this->json($metodo, $uri, $cuerpo, $this->adminWithPermissions([$llave]));
            $this->assertNotContains($con->status(), [401, 403, 405, 500], "{$metodo} {$ruta} con {$llave}: ".$con->getContent());
            // Con OTRA llave de la telefonía, no.
            $otra = $llave === self::VIEW ? self::CONTACTS : self::VIEW;
            $this->json($metodo, $uri, $cuerpo, $this->adminWithPermissions([$otra]))->assertStatus(403);
        }
        Http::assertNothingSent();
    }

    public function test_el_administrador_por_defecto_ve_el_registro_pero_no_llama_ni_administra_contactos(): void
    {
        $admin = $this->adminAs(Admin::ROLE_ADMINISTRADOR);
        $this->getJson('/api/admin/voice/status', $admin)->assertOk();
        $this->postJson('/api/admin/voice/calls', ['clientRequestId' => (string) Str::uuid(), 'target' => ['kind' => 'canary', 'uuid' => (string) Str::uuid()]], $admin)->assertStatus(403);
        $this->getJson('/api/admin/voice/contacts', $admin)->assertStatus(403);
        $this->getJson('/api/admin/voice/status', $this->adminHeaders())->assertOk()->assertJsonPath('callsEnabledInCode', false);
    }

    public function test_la_puerta_de_salientes_con_todo_abierto_salvo_la_constante_responde_409_sin_crear_nada(): void
    {
        // Lunes 9 de noviembre de 2026 a las 10:00 de Bogotá: dentro del horario legal y sin festivo.
        $this->travelTo(Carbon::parse('2026-11-09T15:00:00Z'));
        $canario = $this->activeVoiceCanary();
        $this->publishVoice();
        config([
            'voice.outbound.enabled' => true, 'voice.twilio.enabled' => true, 'voice.twilio.daily_call_cap' => 3, 'voice.limits.daily_budget_usd' => '5',
            'voice.elevenlabs.enabled' => true, 'voice.elevenlabs.api_key' => 'clave-de-prueba', 'voice.elevenlabs.agent_id' => 'agent_verificacion', 'voice.elevenlabs.region' => 'default',
        ]);
        // B8-g: «todo abierto» incluye el número que llama vigilado (una conciliación reciente que cuadra).
        VoiceNumberGuard::rememberObservation(self::PHONE_NUMBER_ID, true, false);
        $cuerpo = ['clientRequestId' => (string) Str::uuid(), 'target' => ['kind' => 'canary', 'uuid' => $canario]];
        $r = $this->postJson('/api/admin/voice/calls', $cuerpo, $this->adminWithPermissions([self::PLACE]))->assertStatus(409);
        // La constante cierra las llamadas; VERIFIED no se puede derivar sin la evidencia del Studio (falla cerrado).
        $this->assertSame(['calls_disabled_in_code', 'elevenlabs_unverified'], $r->json('reasons'));
        $this->assertSame(0, VoiceCall::query()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_la_puerta_valida_el_cuerpo_el_destino_y_el_id_de_la_peticion(): void
    {
        $persona = $this->adminWithPermissions([self::PLACE]);
        $this->postJson('/api/admin/voice/calls', ['target' => ['kind' => 'canary', 'uuid' => (string) Str::uuid()]], $persona)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->postJson('/api/admin/voice/calls', ['clientRequestId' => (string) Str::uuid(), 'target' => ['kind' => 'marketing_calls', 'uuid' => (string) Str::uuid()]], $persona)->assertStatus(422);
        $this->postJson('/api/admin/voice/calls', ['clientRequestId' => (string) Str::uuid(), 'target' => ['kind' => 'canary', 'uuid' => (string) Str::uuid(), 'number' => '+573000000031']], $persona)->assertStatus(422);
        $this->postJson('/api/admin/voice/calls', ['clientRequestId' => (string) Str::uuid(), 'target' => ['kind' => 'canary', 'uuid' => (string) Str::uuid()]], $persona)->assertStatus(404)->assertJsonPath('code', 'target_not_found');
    }

    public function test_la_misma_peticion_de_la_misma_persona_devuelve_la_misma_fila_y_para_otro_destino_client_request_reused(): void
    {
        $canario = $this->activeVoiceCanary();
        $yo = $this->person(Admin::ROLE_SUPER_ADMIN, 'Pide llamadas');
        $crid = (string) Str::uuid();
        $previa = $this->voiceCall('outbound', 'requested', ['canary_number_uuid' => $canario, 'requested_by_admin_id' => $yo->id, 'client_request_id' => $crid]);
        // B8-h (TEST8-c H-4): la repetición es 200 con la MISMA fila (DF-G5); la idempotencia se resuelve antes de la política,
        // así que ni la constante cerrada ni la frecuencia de 1 al día que ocupa la propia llamada la convierten en 409.
        $r = $this->postJson('/api/admin/voice/calls', ['clientRequestId' => $crid, 'target' => ['kind' => 'canary', 'uuid' => $canario]], $this->actingAsAdmin($yo))->assertOk();
        $this->assertTrue($r->json('duplicate'));
        $this->assertSame($previa->uuid, $r->json('call.uuid'));
        $this->assertNull($r->json('reasons'), 'no se evaluó la política');
        $this->assertSame(1, VoiceCall::query()->count());
        $otro = $this->activeVoiceCanary('+573000000032');
        $this->postJson('/api/admin/voice/calls', ['clientRequestId' => $crid, 'target' => ['kind' => 'canary', 'uuid' => $otro]], $this->actingAsAdmin($yo))->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
    }

    public function test_el_corte_cancela_una_pedida_y_sin_credencial_no_cuelga_una_en_curso(): void
    {
        $persona = $this->adminWithPermissions([self::PLACE]);
        $pedida = $this->voiceCall('outbound', 'requested');
        $this->postJson("/api/admin/voice/calls/{$pedida->uuid}/hangup", [], $persona)->assertOk()->assertJson(['ok' => true, 'result' => 'canceled', 'status' => 'canceled']);
        $this->assertSame('hangup', VoiceCall::query()->findOrFail($pedida->id)->policy_reason);

        $viva = $this->voiceCall('outbound', 'dialing', ['remote' => '+573000000033']);
        $this->postJson("/api/admin/voice/calls/{$viva->uuid}/hangup", [], $persona)->assertOk()->assertJson(['ok' => false, 'result' => 'not_configured', 'status' => 'dialing']);
        $terminada = $this->voiceCall('outbound', 'completed', ['remote' => '+573000000034']);
        $this->postJson("/api/admin/voice/calls/{$terminada->uuid}/hangup", [], $persona)->assertOk()->assertJson(['result' => 'not_live']);
        $this->postJson('/api/admin/voice/calls/'.Str::uuid().'/hangup', [], $persona)->assertStatus(404);
        Http::assertNothingSent();
    }

    public function test_el_registro_y_el_detalle_no_llevan_datos_personales(): void
    {
        $llamada = $this->voiceCall('inbound', 'denied');
        $vista = $this->adminWithPermissions([self::VIEW]);
        $lista = $this->getJson('/api/admin/voice/calls?direction=inbound', $vista)->assertOk();
        $this->assertSame(1, $lista->json('meta.total'));
        $this->assertSame('0031', $lista->json('data.0.remoteLast4'));
        $detalle = $this->getJson("/api/admin/voice/calls/{$llamada->uuid}", $vista)->assertOk();
        foreach ([$lista, $detalle, $this->getJson('/api/admin/voice/metrics', $vista)->assertOk(), $this->getJson('/api/admin/voice/status', $vista)->assertOk()] as $r) {
            foreach (['+573000000031', '573000000031', (string) $llamada->remote_hash, 'remote_hash', 'phone_encrypted'] as $s) {
                $this->assertStringNotContainsString($s, (string) $r->getContent());
            }
        }
        $this->getJson('/api/admin/voice/calls?status=raro', $vista)->assertStatus(422);
        $this->getJson('/api/admin/voice/calls/'.Str::uuid(), $vista)->assertStatus(404)->assertJsonPath('code', 'call_not_found');
    }

    public function test_contactos_con_consentimiento_a_cuatro_ojos_revocacion_exclusion_y_rne(): void
    {
        $ana = $this->person(Admin::ROLE_SUPER_ADMIN, 'Ana Contactos');
        $beto = $this->person(Admin::ROLE_SUPER_ADMIN, 'Beto Confirma');
        $comoAna = $this->actingAsAdmin($ana);
        $comoBeto = $this->actingAsAdmin($beto);

        $crid = (string) Str::uuid();
        $alta = ['clientRequestId' => $crid, 'number' => '+57 300 000 0041', 'source' => ['type' => 'manual', 'id' => null]];
        $creado = $this->postJson('/api/admin/voice/contacts', $alta, $comoAna)->assertCreated();
        $uuid = (string) $creado->json('contact.uuid');
        $this->assertSame(['0041', false, null], [$creado->json('contact.last4'), $creado->json('contact.voiceConsent'), $creado->json('contact.pendingConsent')]);
        $this->assertStringNotContainsString('573000000041', (string) $creado->getContent());
        $this->postJson('/api/admin/voice/contacts', $alta, $comoAna)->assertOk();   // la misma petición
        $this->postJson('/api/admin/voice/contacts', ['clientRequestId' => (string) Str::uuid()] + $alta, $comoAna)->assertStatus(409)->assertJsonPath('code', 'contact_exists');
        $this->postJson('/api/admin/voice/contacts', ['clientRequestId' => (string) Str::uuid(), 'number' => '12', 'source' => ['type' => 'manual', 'id' => null]], $comoAna)->assertStatus(422);
        $this->postJson('/api/admin/voice/contacts', ['clientRequestId' => (string) Str::uuid(), 'number' => '+57 300 000 0042', 'source' => ['type' => 'lead', 'id' => 999999]], $comoAna)->assertStatus(422);
        $contacto = VoiceContact::query()->where('uuid', $uuid)->sole();
        $this->assertSame('+573000000041', $contacto->phone_encrypted, 'cifrado en la base y descifrado solo en memoria');
        $this->assertNotSame('+573000000041', DB::table('voice_contacts')->where('uuid', $uuid)->value('phone_encrypted'));

        // Cuatro ojos: Ana lo carga, Ana no lo confirma, Beto sí.
        $this->postJson("/api/admin/voice/contacts/{$uuid}/consents", ['source' => 'written_form', 'evidence' => 'Formulario firmado en recepción el 01-11'], $comoAna)->assertStatus(202)->assertJsonPath('pending', true);
        $this->postJson("/api/admin/voice/contacts/{$uuid}/consents", ['source' => 'written_form', 'evidence' => 'Otra vez'], $comoAna)->assertStatus(409)->assertJsonPath('code', 'consent_request_pending');
        $this->postJson("/api/admin/voice/contacts/{$uuid}/consents/confirm", ['clientRequestId' => (string) Str::uuid()], $comoAna)->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        $this->assertFalse(VoiceContacts::hasConsent((int) $contacto->id));
        $this->postJson("/api/admin/voice/contacts/{$uuid}/consents/confirm", ['clientRequestId' => (string) Str::uuid()], $comoBeto)->assertCreated()->assertJsonPath('consent.kind', 'granted');
        $consentimiento = VoiceConsent::query()->sole();
        $this->assertSame([$ana->id, $beto->id, 'written_form'], [$consentimiento->recorded_by_admin_id, $consentimiento->confirmed_by_admin_id, $consentimiento->source]);
        $this->assertTrue(VoiceContacts::hasConsent((int) $contacto->id));
        $this->getJson('/api/admin/voice/contacts', $comoAna)->assertOk()->assertJsonPath('data.0.voiceConsent', true);

        // Revocar no espera a nadie.
        $this->postJson("/api/admin/voice/contacts/{$uuid}/revoke", ['clientRequestId' => (string) Str::uuid(), 'source' => 'written_form', 'evidence' => 'Pidió no recibir más llamadas'], $comoAna)->assertCreated();
        $this->assertFalse(VoiceContacts::hasConsent((int) $contacto->id));

        // La evidencia no admite teléfonos.
        $this->postJson("/api/admin/voice/contacts/{$uuid}/consents", ['source' => 'written_form', 'evidence' => 'Llamó desde el 300 000 0041'], $comoAna)->assertStatus(422);

        // Exclusión por contacto y por número; RNE con `listed` excluye.
        $this->postJson('/api/admin/voice/exclusions', ['clientRequestId' => (string) Str::uuid(), 'contactUuid' => $uuid, 'note' => 'Pedido del propietario'], $comoAna)->assertCreated();
        $this->postJson('/api/admin/voice/exclusions', ['clientRequestId' => (string) Str::uuid(), 'number' => '+57 300 000 0043'], $comoAna)->assertCreated()->assertJsonPath('exclusion.last4', '0043');
        $this->postJson('/api/admin/voice/exclusions', ['clientRequestId' => (string) Str::uuid(), 'contactUuid' => $uuid, 'number' => '+57 300 000 0043'], $comoAna)->assertStatus(422);
        $this->postJson('/api/admin/voice/rne-checks', ['clientRequestId' => (string) Str::uuid(), 'contactUuid' => $uuid, 'checkedAt' => now()->subDay()->toIso8601String(), 'result' => 'listed', 'evidenceRef' => 'CRC consulta 1102'], $comoAna)->assertCreated();
        $this->assertSame(['admin', 'admin', 'rne'], VoiceExclusion::query()->orderBy('id')->pluck('source')->all());
        $this->assertSame(1, VoiceRneCheck::query()->count());
        $this->postJson('/api/admin/voice/rne-checks', ['clientRequestId' => (string) Str::uuid(), 'contactUuid' => $uuid, 'checkedAt' => now()->addDay()->toIso8601String(), 'result' => 'not_listed', 'evidenceRef' => 'CRC futura'], $comoAna)->assertStatus(422);
    }
}
