<?php

namespace Tests\Feature\Caja;

use App\Exceptions\ReceiptException;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ReceiptAgentTicket;
use App\Models\ReceiptPrint;
use App\Models\ReceiptStation;
use App\Models\RolePermission;
use App\Rules\PlainText;
use App\Support\Access\CrmPermission;
use App\Support\Access\RolePermissionPolicy;
use App\Support\Caja\UntrustedText;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * SERVICIO LOCAL DE IMPRESIÓN: quién puede qué, y qué vale un ticket.
 *
 *  · El servicio no tiene sesión: solo el ticket de un solo uso, atado a una
 *    estación registrada y activa, que caduca y que se guarda como SHA-256.
 *  · Ningún rechazo consume el ticket ni dice nada del recibo.
 *  · Imprimir es de quien opera la caja del recibo; registrar y configurar
 *    equipos, de quien supervisa alguna caja. Sin persona, nada.
 */
class ReceiptAgentSecurityTest extends TestCase
{
    use BuildsCashReceipts;
    use BuildsReceiptAgent;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->relojDelMostrador();
        $this->empresa();
        $this->cajero = $this->recepcion();
        $this->h = $this->actingAsAdmin($this->cajero);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reciboDeGimnasio(): array
    {
        $pago = $this->cobrar($this->socio(), $this->plan('Premium '.uniqid()));

        return $this->emitir('payment', $pago->id)->assertStatus(201)->json('data.0');
    }

    /** @return array{0: string, 1: string} [uuid del recibo, token] */
    private function ticketDeImpresion(ReceiptStation $estacion): array
    {
        $uuid = $this->reciboDeGimnasio()['uuid'];
        $token = $this->imprimirConAgente($uuid, $estacion)->assertStatus(201)->json('data.agent_ticket.token');

        return [$uuid, $token];
    }

    /** Un rechazo uniforme: solo {ok, code, message}, nada del recibo. */
    private function assertRechazo(TestResponse $res, int $estado, string $codigo): void
    {
        $res->assertStatus($estado);
        $this->assertSame(['ok', 'code', 'message'], array_keys($res->json()));
        $this->assertFalse($res->json('ok'));
        $this->assertSame($codigo, $res->json('code'));
        $this->assertStringNotContainsString('RG-', $res->getContent());
        $this->assertStringNotContainsString('Laura', $res->getContent());
    }

    // ── Sesión y permisos en las rutas de administración ────────────────────

    public function test_sin_sesion_no_se_pasa_de_la_puerta(): void
    {
        $clave = (string) Str::uuid();

        $this->getJson('/api/admin/receipt-stations')->assertStatus(401);
        $this->postJson('/api/admin/receipt-stations', ['station_key' => $clave, 'name' => 'Caja'])->assertStatus(401);
        $this->patchJson("/api/admin/receipt-stations/{$clave}", ['name' => 'Otra'])->assertStatus(401);
        $this->postJson("/api/admin/receipt-stations/{$clave}/tickets", ['purpose' => 'test'])->assertStatus(401);
        $this->getJson("/api/admin/receipt-stations/{$clave}/events")->assertStatus(401);
        $this->getJson('/api/admin/receipts/pending')->assertStatus(401);
        $this->assertSame(0, DB::table('receipt_stations')->count());
    }

    public function test_recepcion_imprime_pero_no_registra_ni_configura_equipos(): void
    {
        $estacion = $this->estacion();

        // Ve los equipos (tiene permisos de caja)…
        $this->getJson('/api/admin/receipt-stations', $this->h)->assertOk()->assertJsonPath('data.0.station_key', $estacion->station_key);

        // …pero no los gobierna.
        $prohibido = fn (TestResponse $r) => $r->assertStatus(403)
            ->assertJsonPath('code', 'forbidden')
            ->assertJsonPath('required_permission', 'cash.gym.manage | cash.products.manage');
        $prohibido($this->postJson('/api/admin/receipt-stations', ['station_key' => (string) Str::uuid(), 'name' => 'Caja 2'], $this->h));
        $prohibido($this->patchJson("/api/admin/receipt-stations/{$estacion->station_key}", ['active' => false], $this->h));
        $prohibido($this->ticketDeEstacion($estacion, 'test', null, $this->h));
        $prohibido($this->ticketDeEstacion($estacion, 'configure', null, $this->h));
        // Ni lee el rastro del equipo, que mezcla recibos de las dos cajas (M-5).
        $prohibido($this->getJson("/api/admin/receipt-stations/{$estacion->station_key}/events", $this->h));
        $this->assertSame(0, ReceiptAgentTicket::count());
        $this->assertTrue($estacion->fresh()->active);

        // Imprimir sí: es su caja.
        $uuid = $this->reciboDeGimnasio()['uuid'];
        $data = $this->imprimirConAgente($uuid, $estacion)->assertStatus(201)->json('data');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $data['agent_ticket']['token']);
        $this->assertSame(['client_attempt_id', 'kind', 'copy_label', 'agent_ticket'], array_keys($data));
    }

    public function test_conceder_o_quitar_la_supervision_a_un_rol_cambia_el_resultado_en_caliente(): void
    {
        $clave = (string) Str::uuid();
        $this->postJson('/api/admin/receipt-stations', ['station_key' => $clave, 'name' => 'Caja 2'], $this->h)->assertStatus(403);

        $permiso = RolePermission::create(['role' => Admin::ROLE_RECEPCION, 'permission' => 'cash.products.manage', 'granted' => true]);
        app(RolePermissionPolicy::class)->flush();
        $this->postJson('/api/admin/receipt-stations', ['station_key' => $clave, 'name' => 'Caja 2'], $this->h)->assertStatus(201);

        $permiso->delete();
        app(RolePermissionPolicy::class)->flush();
        $this->patchJson("/api/admin/receipt-stations/{$clave}", ['name' => 'Caja dos'], $this->h)->assertStatus(403);
        $this->assertSame('Caja 2', DB::table('receipt_stations')->where('station_key', $clave)->value('name'));
    }

    public function test_el_token_compartido_no_es_nadie(): void
    {
        $estacion = $this->estacion();
        $uuid = $this->reciboDeGimnasio()['uuid'];
        $token = $this->sharedTokenHeaders();

        foreach ([
            $this->getJson('/api/admin/receipt-stations', $token),
            $this->getJson("/api/admin/receipt-stations/{$estacion->station_key}/events", $token),
            $this->getJson('/api/admin/receipts/pending', $token),
            $this->postJson('/api/admin/receipt-stations', ['station_key' => (string) Str::uuid(), 'name' => 'Caja'], $token),
            $this->ticketDeEstacion($estacion, 'test', null, $token),
            $this->imprimirConAgente($uuid, $estacion, h: $token),
        ] as $res) {
            $res->assertStatus(403);
        }

        $this->assertSame(0, ReceiptAgentTicket::count());
        $this->assertSame(0, ReceiptPrint::count());
        $this->assertSame(1, DB::table('receipt_stations')->count());
    }

    public function test_un_entrenador_no_ve_ni_toca_equipos(): void
    {
        $estacion = $this->estacion();
        $entrenador = $this->adminAs(CrmPermission::ROLE_ENTRENADOR);

        $this->getJson('/api/admin/receipt-stations', $entrenador)->assertStatus(403);
        $this->ticketDeEstacion($estacion, 'test', null, $entrenador)->assertStatus(403);
        $this->assertSame(0, ReceiptAgentTicket::count());
    }

    public function test_un_ticket_de_otra_caja_se_niega_al_emitirlo(): void
    {
        $estacion = $this->estacion();
        $uuid = $this->reciboDeGimnasio()['uuid'];
        $soloProductos = $this->adminWithPermissions(['cash.products.operate']);
        $soloVeGimnasio = $this->adminWithPermissions(['cash.gym.view']);

        $this->imprimirConAgente($uuid, $estacion, h: $soloProductos)->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.gym.operate');
        $this->imprimirConAgente($uuid, $estacion, h: $soloVeGimnasio)->assertStatus(403);

        $this->assertSame(0, ReceiptPrint::count());
        $this->assertSame(0, ReceiptAgentTicket::count());
    }

    public function test_sin_estacion_registrada_o_activa_no_se_abre_el_intento(): void
    {
        $uuid = $this->reciboDeGimnasio()['uuid'];

        $this->imprimirConAgente($uuid, (string) Str::uuid())->assertStatus(404)->assertJsonPath('code', 'station_not_registered');
        $this->postJson("/api/admin/receipts/{$uuid}/prints", [
            'client_attempt_id' => (string) Str::uuid(), 'context' => 'checkout', 'print_mode' => 'agent',
        ], $this->h)->assertStatus(422)->assertJsonValidationErrors('station_key');
        $this->imprimirConAgente($uuid, 'no-es-un-uuid')->assertStatus(422);

        $inactiva = $this->estacion(['active' => false]);
        $this->imprimirConAgente($uuid, $inactiva)->assertStatus(403)->assertJsonPath('code', 'station_inactive');

        $this->assertSame(0, ReceiptPrint::count(), 'un intento que ningún servicio puede tomar no se abre');
    }

    // ── El ticket ───────────────────────────────────────────────────────────

    public function test_un_ticket_falso_o_mal_formado_no_vale_y_no_dice_nada(): void
    {
        $estacion = $this->estacion();
        $this->ticketDeImpresion($estacion);

        $falso = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->assertRechazo($this->canjear($falso, $estacion), 404, 'ticket_invalid');
        $this->assertRechazo($this->canjear('corto', $estacion), 404, 'ticket_invalid');
        $this->assertRechazo($this->canjear(str_repeat('!', 43), $estacion), 404, 'ticket_invalid');
        $this->assertRechazo($this->reportar($falso, $estacion, 'accepted'), 404, 'ticket_invalid');
        // Sin ticket: la forma de la petición, no un ticket.
        $this->assertRechazo($this->canjear(null, $estacion), 422, 'invalid_request');

        $this->assertNull(ReceiptAgentTicket::firstOrFail()->redeemed_at);
    }

    public function test_un_ticket_caducado_no_vale(): void
    {
        $estacion = $this->estacion();
        [, $token] = $this->ticketDeImpresion($estacion);

        Carbon::setTestNow(now()->addSeconds(121));
        $this->assertRechazo($this->canjear($token, $estacion), 410, 'ticket_expired');
        $this->assertNull(ReceiptAgentTicket::firstOrFail()->redeemed_at);
        $this->assertNull(ReceiptPrint::firstOrFail()->dispatched_at);
    }

    public function test_un_ticket_se_canjea_una_sola_vez(): void
    {
        $estacion = $this->estacion();
        [, $token] = $this->ticketDeImpresion($estacion);

        $this->canjear($token, $estacion)->assertOk()->assertJsonPath('ok', true);
        $this->assertRechazo($this->canjear($token, $estacion), 409, 'ticket_used');
        $this->assertSame(1, ReceiptAgentTicket::whereNotNull('redeemed_at')->count());
    }

    public function test_una_repeticion_del_intento_revoca_el_ticket_anterior(): void
    {
        $estacion = $this->estacion();
        $uuid = $this->reciboDeGimnasio()['uuid'];
        $intento = (string) Str::uuid();

        $primero = $this->imprimirConAgente($uuid, $estacion, intento: $intento)->assertStatus(201)->json('data.agent_ticket.token');
        $segundo = $this->imprimirConAgente($uuid, $estacion, intento: $intento)->assertStatus(200)->json('data.agent_ticket.token');

        $this->assertNotSame($primero, $segundo);
        $this->assertRechazo($this->canjear($primero, $estacion), 404, 'ticket_invalid');
        $this->canjear($segundo, $estacion)->assertOk();
        $this->assertSame(1, ReceiptPrint::count());
        $this->assertSame(1, ReceiptAgentTicket::whereNotNull('revoked_at')->count());

        // Ya despachado: repetir no da otro ticket.
        $this->imprimirConAgente($uuid, $estacion, intento: $intento)->assertStatus(200)->assertJsonPath('data.agent_ticket', null);
        $this->assertSame(2, ReceiptAgentTicket::count());
    }

    public function test_un_ticket_de_otra_estacion_no_se_consume(): void
    {
        $estacion = $this->estacion();
        $otra = $this->estacion(['name' => 'Cafetería']);
        [, $token] = $this->ticketDeImpresion($estacion);

        $this->assertRechazo($this->canjear($token, $otra), 403, 'station_mismatch');
        $this->assertRechazo($this->canjear($token, (string) Str::uuid()), 403, 'station_mismatch');
        $this->assertNull(ReceiptAgentTicket::firstOrFail()->redeemed_at);
        $this->assertNull($otra->fresh()->last_seen_at);

        $this->canjear($token, $estacion)->assertOk();
    }

    public function test_una_estacion_desactivada_no_canjea_y_al_reactivarla_si(): void
    {
        $estacion = $this->estacion();
        [, $token] = $this->ticketDeImpresion($estacion);

        $this->patchJson("/api/admin/receipt-stations/{$estacion->station_key}", ['active' => false], $this->supervisor())->assertOk();
        $this->assertRechazo($this->canjear($token, $estacion), 403, 'station_inactive');
        $this->assertNull(ReceiptAgentTicket::firstOrFail()->redeemed_at);

        $this->patchJson("/api/admin/receipt-stations/{$estacion->station_key}", ['active' => true], $this->supervisor())->assertOk();
        $this->canjear($token, $estacion)->assertOk();
    }

    public function test_un_servicio_con_protocolo_viejo_no_canjea(): void
    {
        config(['caja.receipts.agent.min_protocol' => 2]);
        $estacion = $this->estacion();
        [, $token] = $this->ticketDeImpresion($estacion);

        $this->assertRechazo($this->canjear($token, $estacion), 426, 'protocol_unsupported');
        $this->assertNull(ReceiptAgentTicket::firstOrFail()->redeemed_at);
        $this->canjear($token, $estacion, ['protocol' => 2])->assertOk();
    }

    public function test_columnas_que_no_son_32_ni_42_son_422_y_no_consumen(): void
    {
        $estacion = $this->estacion();
        [, $token] = $this->ticketDeImpresion($estacion);

        $this->assertRechazo($this->canjear($token, $estacion, ['columns' => 48]), 422, 'invalid_request');
        $this->assertRechazo($this->canjear($token, $estacion, ['codepage' => 'utf8']), 422, 'invalid_request');
        $this->assertNull(ReceiptAgentTicket::firstOrFail()->redeemed_at);

        $this->canjear($token, $estacion, ['columns' => 42])->assertOk()->assertJsonPath('job.columns', 42);
    }

    public function test_el_interruptor_general_corta_emitir_y_canjear_sin_tocar_nada(): void
    {
        $estacion = $this->estacion();
        [$uuid, $token] = $this->ticketDeImpresion($estacion);

        config(['caja.receipts.agent.enabled' => false]);

        $this->imprimirConAgente($this->reciboDeGimnasio()['uuid'], $estacion)->assertStatus(503)->assertJsonPath('code', 'agent_disabled');
        $this->ticketDeEstacion($estacion)->assertStatus(503)->assertJsonPath('code', 'agent_disabled');
        $this->assertRechazo($this->canjear($token, $estacion), 503, 'agent_disabled');
        $this->getJson('/api/admin/receipt-stations', $this->h)->assertOk()->assertJsonPath('agent.enabled', false);
        $this->assertSame(1, ReceiptPrint::count(), 'con el interruptor apagado no se abre ningún intento agent');
        $this->assertNull(ReceiptAgentTicket::firstOrFail()->redeemed_at);

        // Los demás modos siguen como siempre.
        $this->postJson("/api/admin/receipts/{$uuid}/prints", [
            'client_attempt_id' => (string) Str::uuid(), 'context' => 'shift_report', 'print_mode' => 'silent',
        ], $this->h)->assertStatus(201)->assertJsonMissingPath('data.agent_ticket');

        config(['caja.receipts.agent.enabled' => true]);
        $this->canjear($token, $estacion)->assertOk();
    }

    // ── El resultado ────────────────────────────────────────────────────────

    public function test_reportar_exige_canje_previo_una_sola_vez_y_dentro_de_la_ventana(): void
    {
        $estacion = $this->estacion();
        [, $sinCanjear] = $this->ticketDeImpresion($estacion);
        $this->assertRechazo($this->reportar($sinCanjear, $estacion, 'accepted'), 409, 'not_redeemed');
        $this->assertSame('started', ReceiptPrint::firstOrFail()->status);

        [, $token] = $this->ticketDeImpresion($estacion);
        $this->canjear($token, $estacion)->assertOk();
        $this->reportar($token, $estacion, 'accepted')->assertOk()->assertExactJson(['ok' => true, 'recorded' => true]);
        $this->assertRechazo($this->reportar($token, $estacion, 'error'), 409, 'already_reported');

        [, $tarde] = $this->ticketDeImpresion($estacion);
        $this->canjear($tarde, $estacion)->assertOk();
        Carbon::setTestNow(now()->addHours(24)->addSecond());
        $this->assertRechazo($this->reportar($tarde, $estacion, 'accepted'), 410, 'report_window_closed');
        $this->assertSame('started', ReceiptAgentTicket::whereNull('reported_at')->latest('id')->firstOrFail()->print->status);
    }

    public function test_reportar_desde_otra_estacion_no_cierra_nada(): void
    {
        $estacion = $this->estacion();
        $otra = $this->estacion(['name' => 'Cafetería']);
        [, $token] = $this->ticketDeImpresion($estacion);
        $this->canjear($token, $estacion)->assertOk();

        $this->assertRechazo($this->reportar($token, $otra, 'accepted'), 403, 'station_mismatch');
        $this->assertSame('started', ReceiptPrint::firstOrFail()->status);
        $this->assertNull(ReceiptAgentTicket::firstOrFail()->reported_at);
    }

    public function test_el_resultado_tiene_que_corresponder_al_proposito(): void
    {
        $estacion = $this->estacion();
        [, $token] = $this->ticketDeImpresion($estacion);
        $this->canjear($token, $estacion)->assertOk();

        $this->assertRechazo($this->reportar($token, $estacion, 'applied'), 422, 'invalid_outcome');
        $this->assertRechazo($this->reportar($token, $estacion, 'impreso'), 422, 'invalid_request');
        $this->assertSame('started', ReceiptPrint::firstOrFail()->status);
    }

    // ── El token nunca en claro ─────────────────────────────────────────────

    public function test_el_token_solo_se_guarda_como_sha256_y_no_sale_en_eventos_ni_auditoria(): void
    {
        $estacion = $this->estacion();
        [, $impresion] = $this->ticketDeImpresion($estacion);
        $prueba = $this->ticketDeEstacion($estacion, 'test', 'minimal')->assertStatus(201)->json('data.token');
        $config = $this->ticketDeEstacion($estacion, 'configure')->assertStatus(201)->json('data.token');
        $this->canjear($impresion, $estacion)->assertOk();
        $this->reportar($impresion, $estacion, 'accepted')->assertOk();

        foreach ([$impresion, $prueba, $config] as $token) {
            $this->assertSame(1, ReceiptAgentTicket::where('token_hash', hash('sha256', $token))->count());
            foreach (['receipt_agent_tickets', 'receipt_prints', 'receipt_stations', 'audit_logs'] as $tabla) {
                $volcado = DB::table($tabla)->get()->toJson();
                $this->assertStringNotContainsString($token, $volcado, "el token en claro aparece en {$tabla}");
            }
        }

        $eventos = $this->getJson("/api/admin/receipt-stations/{$estacion->station_key}/events", $this->supervisor())->assertOk();
        $this->assertCount(3, $eventos->json('data'));
        $this->assertSame(
            ['purpose', 'issued_at', 'issued_by_name', 'redeemed_at', 'reported_at', 'outcome', 'error_code', 'error_message', 'receipt_number', 'agent_version', 'printer_name'],
            array_keys($eventos->json('data.0')),
        );
        foreach ([$impresion, $prueba, $config] as $token) {
            $this->assertStringNotContainsString($token, $eventos->getContent());
            $this->assertStringNotContainsString(hash('sha256', $token), $eventos->getContent());
        }
        $this->assertArrayNotHasKey('token_hash', ReceiptAgentTicket::firstOrFail()->toArray());
    }

    // ── Las rutas del servicio no son administrativas ───────────────────────

    public function test_las_rutas_del_servicio_no_piden_sesion_ni_la_aprovechan(): void
    {
        $estacion = $this->estacion();
        [, $token] = $this->ticketDeImpresion($estacion);

        // Una sesión de Super Admin no convierte un ticket falso en bueno.
        $falso = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->postJson('/api/receipt-agent/redeem', [
            'ticket' => $falso, 'station_key' => $estacion->station_key, 'agent' => $this->agente(),
        ], $this->adminHeaders())->assertStatus(404)->assertJsonPath('code', 'ticket_invalid');

        // Y sin ninguna cabecera, el ticket bueno basta.
        $this->canjear($token, $estacion)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_el_servicio_tiene_cupo_propio_y_responde_con_la_forma_del_contrato(): void
    {
        $estacion = $this->estacion();
        $falso = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        for ($i = 0; $i < 60; $i++) {
            $this->canjear($falso, $estacion)->assertStatus(404);
        }

        $this->assertRechazo($this->canjear($falso, $estacion), 429, 'rate_limited');
        // Cupo PROPIO: las rutas `throttle:N,1` del CRM no se ven afectadas.
        $this->getJson('/api/admin/receipt-stations', $this->h)->assertOk();
    }

    // ── Revisión de seguridad del 17-10 (M-1 … M-5) ─────────────────────────

    /**
     * M-1: un cuerpo form-urlencoded dejaba pasar UTF-8 inválido, y con él
     * `not_regex:/…/u` fallaba ABIERTO (preg_match → false). El servicio solo
     * habla JSON: cualquier otra cosa es 415 antes de validar nada.
     */
    public function test_el_servicio_solo_acepta_json(): void
    {
        $estacion = $this->estacion();
        [, $token] = $this->ticketDeImpresion($estacion);
        $cuerpo = ['ticket' => $token, 'station_key' => $estacion->station_key, 'agent' => $this->agente(['printer_name' => "\xFF\x1B[31mX"])];

        foreach (['/api/receipt-agent/redeem', '/api/receipt-agent/report'] as $ruta) {
            // Form (lo que reprodujo la revisión) y JSON declarado como texto.
            $this->assertRechazo($this->post($ruta, $cuerpo + ['outcome' => 'accepted']), 415, 'unsupported_media_type');
            $this->assertRechazo($this->call('POST', $ruta, [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'], json_encode(['ticket' => $token])), 415, 'unsupported_media_type');
        }

        $this->assertNull(ReceiptAgentTicket::firstOrFail()->redeemed_at);
        $this->assertNull(ReceiptPrint::firstOrFail()->dispatched_at);
        $this->assertNull($estacion->fresh()->last_seen_at);
    }

    public function test_una_cadena_que_no_es_utf8_valido_es_422_antes_de_tocar_la_base(): void
    {
        $estacion = $this->estacion();
        [, $token] = $this->ticketDeImpresion($estacion);

        // JSON no admite bytes inválidos, pero la query string sí, y entra en la entrada.
        $this->assertRechazo($this->postJson('/api/receipt-agent/redeem?x=%FF%1B', [
            'ticket' => $token, 'station_key' => $estacion->station_key, 'agent' => $this->agente(),
        ]), 422, 'invalid_request');
        $this->assertRechazo($this->postJson('/api/receipt-agent/report?%FF=1', [
            'ticket' => $token, 'station_key' => $estacion->station_key, 'outcome' => 'accepted',
        ]), 422, 'invalid_request');
        $this->assertNull(ReceiptAgentTicket::firstOrFail()->redeemed_at);

        // Estaciones: mismo corte, con el cuerpo form-urlencoded de la revisión.
        $supervisor = $this->supervisor();
        $antes = DB::table('receipt_stations')->get()->toJson();
        $this->withHeaders($supervisor + ['Accept' => 'application/json'])
            ->patch("/api/admin/receipt-stations/{$estacion->station_key}", ['name' => "\xFF\x1B[31mCaja"])
            ->assertStatus(422)->assertJsonValidationErrors('input');
        $this->withHeaders($supervisor + ['Accept' => 'application/json'])
            ->patch("/api/admin/receipt-stations/{$estacion->station_key}", ['printer_name' => "POS\xC3\x00\r\n"])
            ->assertStatus(422);
        $this->withHeaders($supervisor + ['Accept' => 'application/json'])
            ->post('/api/admin/receipt-stations', ['station_key' => (string) Str::uuid(), 'name' => "Caja \xFF"])
            ->assertStatus(422);
        $this->withHeaders($supervisor + ['Accept' => 'application/json'])
            ->post("/api/admin/receipt-stations/{$estacion->station_key}/tickets", ['purpose' => 'test', "\xFF" => 'x'])
            ->assertStatus(422);
        $this->assertSame($antes, DB::table('receipt_stations')->get()->toJson());
        $this->assertSame(1, DB::table('receipt_stations')->count());
        $this->assertSame(1, ReceiptAgentTicket::count());
    }

    /** La regla nueva falla CERRADA donde `not_regex` fallaba abierta. */
    public function test_la_regla_de_texto_plano_falla_cerrada(): void
    {
        $sucio = "\xFF\x1B[31m";
        $this->assertFalse(Validator::make(['x' => $sucio], ['x' => 'not_regex:/[\x{0000}-\x{001F}]/u'])->fails(),
            'así se saltaba el filtro anterior: por eso se cambió');
        foreach ([$sucio, "Caja\x1B[2J", "Caja\u{202E}odnoC", "Ca\u{200B}ja", "\u{FEFF}Caja", "Caja\u{2066}x", "Caja\u{0085}"] as $malo) {
            $this->assertTrue(Validator::make(['x' => $malo], ['x' => [new PlainText]])->fails(), json_encode(mb_convert_encoding($malo, 'UTF-8', 'UTF-8')));
        }
        $this->assertFalse(Validator::make(['x' => 'Recepción · Caja 1 (POS-58)'], ['x' => [new PlainText]])->fails());
        $this->assertFalse(Validator::make(['x' => null], ['x' => ['nullable', new PlainText]])->fails());
    }

    /** M-2: el texto libre del servicio se guarda sin controles ni invisibles, y recortado. */
    public function test_el_mensaje_del_servicio_se_guarda_limpio(): void
    {
        $estacion = $this->estacion();
        [$uuid, $token] = $this->ticketDeImpresion($estacion);
        $this->canjear($token, $estacion)->assertOk();

        $mensaje = "Sin\u{202E}papel\x1B[2J\r\nreinicie\u{200B} la\u{FEFF}\u{2066} impresora\u{0085}".str_repeat(' x', 400);
        $this->reportar($token, $estacion, 'error', ['error_code' => 'paper_out', 'message' => $mensaje])->assertOk();

        $ticket = ReceiptAgentTicket::firstOrFail();
        $fila = ReceiptPrint::firstOrFail();
        foreach ([$ticket->error_message, $fila->error_message] as $guardado) {
            $this->assertStringStartsWith('Sinpapel [2J reinicie la impresora x x', $guardado);
            $this->assertSame(0, preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', $guardado));
            $this->assertLessThanOrEqual(255, mb_strlen($guardado));
        }
        $this->assertSame('paper_out', $ticket->error_code);

        // Y lo que enseña el CRM es lo guardado.
        $pendiente = collect($this->getJson('/api/admin/receipts/pending', $this->h)->json('data'))->firstWhere('uuid', $uuid);
        $this->assertSame($fila->error_message, $pendiente['last_attempt']['error_message']);

        // Nombres de impresora o versión con invisibles: rechazados en la puerta.
        [, $otro] = $this->ticketDeImpresion($estacion);
        $this->assertRechazo($this->canjear($otro, $estacion, ['printer_name' => "POS\u{202E}85"]), 422, 'invalid_request');
        $this->assertRechazo($this->canjear($otro, $estacion, ['version' => "1.0\u{200B}.0"]), 422, 'invalid_request');
        $this->canjear($otro, $estacion)->assertOk();
        $this->assertRechazo($this->reportar($otro, $estacion, 'accepted', ['printer_name' => "POS\x0758"]), 422, 'invalid_request');
        $this->assertRechazo($this->reportar($otro, $estacion, 'error', ['error_code' => "paper_out\u{202E}"]), 422, 'invalid_request');
    }

    public function test_la_limpieza_del_texto_libre(): void
    {
        $this->assertNull(UntrustedText::sanitize(null, 10));
        $this->assertNull(UntrustedText::sanitize("\u{200B}\x00 \u{FEFF}", 10));
        $this->assertSame('a b c', UntrustedText::sanitize("a\tb\r\n\x1Bc", 10));
        $this->assertSame('abc', UntrustedText::sanitize("a\u{202E}b\u{2069}c", 10));
        $this->assertSame('áéí', UntrustedText::sanitize('áéíóú', 3));
        $this->assertSame('ab', UntrustedText::sanitize('ab   cd', 3), 'recorta sin dejar espacio al final');
        // Bytes que no son UTF-8 (solo por defensa: la puerta ya los rechaza) → «?».
        $this->assertSame('Jos? P?rez', UntrustedText::sanitize("Jos\xE9 P\xE9rez", 20));
    }

    /** M-3: la respuesta que lleva el ticket no la guarda ningún intermedio. */
    public function test_la_respuesta_con_agent_ticket_va_con_no_store(): void
    {
        $estacion = $this->estacion();
        $uuid = $this->reciboDeGimnasio()['uuid'];
        $intento = (string) Str::uuid();

        $this->imprimirConAgente($uuid, $estacion, intento: $intento)->assertStatus(201)->assertHeader('Cache-Control', 'no-store, private');
        $this->imprimirConAgente($uuid, $estacion, intento: $intento)->assertStatus(200)->assertHeader('Cache-Control', 'no-store, private');
        $this->ticketDeEstacion($estacion)->assertStatus(201)->assertHeader('Cache-Control', 'no-store, private');

        // Los demás modos, como siempre.
        $silenciosa = $this->postJson("/api/admin/receipts/{$uuid}/prints", [
            'client_attempt_id' => (string) Str::uuid(), 'context' => 'shift_report', 'print_mode' => 'silent',
        ], $this->h)->assertStatus(201);
        $this->assertStringNotContainsString('no-store', (string) $silenciosa->headers->get('Cache-Control'));
    }

    /** M-4 (servidor): la impresora de una estación es local, no una ruta de red ni una URL. */
    public function test_la_impresora_de_una_estacion_no_puede_ser_remota(): void
    {
        $estacion = $this->estacion();
        $url = "/api/admin/receipt-stations/{$estacion->station_key}";
        $supervisor = $this->supervisor();

        foreach (['\\\\host-atacante\\cola', '\\\\192.168.1.50\\POS-58', '//host/cola', 'http://host-atacante/printers/x/.printer', 'https://h/p', 'ipp://h/q'] as $remota) {
            $this->patchJson($url, ['printer_name' => $remota], $supervisor)
                ->assertStatus(422)
                ->assertJsonValidationErrors('printer_name')
                ->assertJsonPath('errors.printer_name.0', 'Elige una impresora conectada a este equipo, no una impresora de red.');
        }
        $this->assertSame('POS-58', $estacion->fresh()->printer_name);

        foreach (['POS-58', 'EPSON TM-T20II Receipt', 'DIG-58IIK (copia 1)', 'Generic / Text Only'] as $local) {
            $this->patchJson($url, ['printer_name' => $local], $supervisor)->assertOk()->assertJsonPath('data.printer_name', $local);
        }
    }

    // ── Revisión de BD del 17-10 ────────────────────────────────────────────

    /**
     * El canje bloquea intento → ticket → estación, en ese orden y con FOR
     * UPDATE (nunca FOR SHARE: dos canjes con FOR SHARE que luego actualizan la
     * misma estación se interbloquean). SQLite no emite cláusulas de bloqueo,
     * así que allí se comprueba el ORDEN; en PostgreSQL, además, el FOR UPDATE.
     */
    public function test_el_canje_bloquea_intento_ticket_y_estacion_en_ese_orden(): void
    {
        $estacion = $this->estacion();
        [, $token] = $this->ticketDeImpresion($estacion);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->canjear($token, $estacion)->assertOk();
        $consultas = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower($q))->values();
        DB::disableQueryLog();

        $intento = $consultas->search(fn ($q) => str_starts_with($q, 'select') && str_contains($q, 'from "receipt_prints"'));
        $ticket = $consultas->search(fn ($q) => str_starts_with($q, 'select') && str_contains($q, 'from "receipt_agent_tickets" where "receipt_agent_tickets"."id"'));
        $laEstacion = $consultas->search(fn ($q) => str_starts_with($q, 'select') && str_contains($q, 'from "receipt_stations" where "receipt_stations"."id"'));

        $this->assertIsInt($intento);
        $this->assertIsInt($ticket);
        $this->assertIsInt($laEstacion);
        $this->assertTrue($intento < $ticket && $ticket < $laEstacion, 'orden intento → ticket → estación: '.$consultas->implode(' | '));
        $this->assertFalse($consultas->contains(fn ($q) => str_contains($q, 'for share')));

        if (DB::getDriverName() === 'pgsql') {
            foreach ([$intento, $ticket, $laEstacion] as $i) {
                $this->assertStringEndsWith('for update', $consultas[$i]);
            }
        }
    }

    /**
     * Un fallo INESPERADO de la base en mitad del canje (aquí, al marcarlo
     * canjeado, ya despachado el intento): todo se deshace, el servicio recibe
     * la forma uniforme `internal_error` y el log no lleva ni el token ni el SQL.
     */
    public function test_un_fallo_inesperado_de_la_base_en_el_canje_es_internal_error_sin_detalles(): void
    {
        $estacion = $this->estacion();
        [, $token] = $this->ticketDeImpresion($estacion);
        $this->fallarEn('/^update "receipt_agent_tickets"/', $token);
        Log::spy();

        $res = $this->canjear($token, $estacion);

        $this->assertRechazo($res, 500, 'internal_error');
        $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
        $this->assertStringNotContainsString('receipt_', $res->getContent());
        $this->assertNull(ReceiptAgentTicket::firstOrFail()->redeemed_at);
        $this->assertNull(ReceiptPrint::firstOrFail()->dispatched_at, 'el despacho también se deshizo');
        $this->assertNull($estacion->fresh()->last_seen_at);
        $this->assertLogSinDetalles($token, 'redeem', '22021');
    }

    public function test_un_fallo_inesperado_en_el_reporte_tambien(): void
    {
        $estacion = $this->estacion();
        [, $token] = $this->ticketDeImpresion($estacion);
        $this->canjear($token, $estacion)->assertOk();
        $this->fallarEn('/^update "receipt_prints"/', $token);
        Log::spy();

        $this->assertRechazo($this->reportar($token, $estacion, 'accepted'), 500, 'internal_error');
        $this->assertSame('started', ReceiptPrint::firstOrFail()->status);
        $this->assertNull(ReceiptAgentTicket::firstOrFail()->reported_at);
        $this->assertLogSinDetalles($token, 'report', '22021');
    }

    public function test_un_error_que_no_es_de_la_base_tambien_sale_uniforme(): void
    {
        $estacion = $this->estacion();
        [, $token] = $this->ticketDeImpresion($estacion);
        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_starts_with(strtolower($query), 'select') && str_contains($query, 'from "receipt_stations"')) {
                throw new \RuntimeException('defecto inesperado');
            }
        });
        Log::spy();

        $this->assertRechazo($this->canjear($token, $estacion), 500, 'internal_error');
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $m, array $c) => $m === 'receipt_agent:internal_error'
            && $c['exception'] === \RuntimeException::class && $c['message'] === 'defecto inesperado'
            && ! str_contains(json_encode($c), $token));
        $this->assertNull(ReceiptAgentTicket::firstOrFail()->redeemed_at);
    }

    /** Provoca una QueryException real (22021, la de los bytes inválidos en PG) en la consulta indicada. */
    private function fallarEn(string $patron, string $token): void
    {
        DB::connection()->beforeExecuting(function (string $query, array $bindings, $conexion) use ($patron, $token): void {
            if (preg_match($patron, strtolower($query)) === 1) {
                $pdo = new \PDOException('SQLSTATE[22021]: Character not in repertoire: 7 ERROR:  invalid byte sequence for encoding "UTF8": 0xff');
                $pdo->errorInfo = ['22021', 7, 'ERROR:  invalid byte sequence for encoding "UTF8": 0xff'];

                // Con el token entre los valores, para demostrar que nada de la consulta llega al log.
                throw new QueryException($conexion->getName(), $query, array_merge($bindings, [$token]), $pdo);
            }
        });
    }

    private function assertLogSinDetalles(string $token, string $operacion, string $sqlstate): void
    {
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $mensaje, array $contexto) use ($token, $operacion, $sqlstate): bool {
            $volcado = json_encode($contexto);

            return $mensaje === 'receipt_agent:internal_error'
                && $contexto['operation'] === $operacion
                && $contexto['exception'] === QueryException::class
                && $contexto['sqlstate'] === $sqlstate
                && Str::isUuid($contexto['ticket_id'])
                && ! array_key_exists('message', $contexto)
                && ! str_contains($volcado, $token)
                && ! str_contains($volcado, hash('sha256', $token))
                && ! str_contains(strtolower($volcado), 'update');
        });
    }

    // ── El modelo ───────────────────────────────────────────────────────────

    public function test_el_despacho_es_la_unica_otra_escritura_y_solo_una_vez(): void
    {
        $estacion = $this->estacion();
        $this->ticketDeImpresion($estacion);
        $intento = ReceiptPrint::firstOrFail();

        // Despachar y cerrar a la vez no es ninguna de las dos transiciones.
        try {
            $intento->update(['dispatched_at' => now(), 'status' => 'sent']);
            $this->fail('despachar y cerrar en una escritura no está permitido');
        } catch (ReceiptException $e) {
            $this->assertSame('print_immutable', $e->code_);
        }

        $intento->refresh()->update(['dispatched_at' => now()]);
        $this->assertNotNull($intento->fresh()->dispatched_at);

        $this->expectException(ReceiptException::class);
        $intento->refresh()->update(['dispatched_at' => now()->addMinute()]);
    }

    public function test_no_se_puede_despachar_un_intento_cerrado(): void
    {
        $estacion = $this->estacion();
        [$uuid] = $this->ticketDeImpresion($estacion);
        $intento = ReceiptPrint::firstOrFail();
        $this->patchJson("/api/admin/receipts/{$uuid}/prints/{$intento->uuid}", ['status' => 'error'], $this->h)->assertOk();

        $this->expectException(ReceiptException::class);
        $intento->refresh()->update(['dispatched_at' => now()]);
    }

    public function test_la_auditoria_de_estaciones_no_lleva_el_token(): void
    {
        $estacion = $this->estacion();
        $token = $this->ticketDeEstacion($estacion, 'configure')->assertStatus(201)->json('data.token');

        $traza = AuditLog::where('entity', 'estacion_impresion')->where('action', 'settings')->firstOrFail();
        $this->assertSame($estacion->station_key, $traza->metadata['station_key']);
        $this->assertSame(ReceiptAgentTicket::firstOrFail()->uuid, $traza->metadata['ticket_id']);
        $this->assertStringNotContainsString($token, json_encode($traza->toArray()));
        $this->assertStringNotContainsString(hash('sha256', $token), json_encode($traza->toArray()));
    }
}
