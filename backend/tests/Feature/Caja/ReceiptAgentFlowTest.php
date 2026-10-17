<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\ReceiptAgentTicket;
use App\Models\ReceiptPrint;
use App\Models\ReceiptStation;
use App\Models\ReceivablePayment;
use App\Models\RolePermission;
use App\Services\Caja\ReceiptPresenter;
use App\Services\Caja\ReceiptTextLayout;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SERVICIO LOCAL DE IMPRESIÓN: el recorrido completo de cada tirilla.
 *
 *   CRM abre el intento (agent) → recibe el ticket → el servicio canjea (las
 *   líneas YA maquetadas; el intento queda «enviando») → imprime → reporta
 *   (aceptado por el sistema | error).
 *
 * Con operaciones REALES de las dos cajas (BuildsCashReceipts): el papel que
 * sale es el del snapshot, el rótulo lo decide el servidor, lo vivo (anulada,
 * factura) se mira al canjear, y NADA de esto mueve un peso, una unidad de
 * inventario, un turno ni una factura.
 */
class ReceiptAgentFlowTest extends TestCase
{
    use BuildsCashReceipts;
    use BuildsReceiptAgent;
    use RefreshDatabase;

    private ReceiptStation $caja;

    protected function setUp(): void
    {
        parent::setUp();

        $this->relojDelMostrador();
        $this->empresa();
        $this->cajero = $this->recepcion();
        $this->h = $this->actingAsAdmin($this->cajero);
        $this->caja = $this->estacion(['feed_lines' => 5]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Imprime un recibo de punta a punta por el servicio local.
     *
     * @return array{job: array<string, mixed>, token: string, intento: string}
     */
    private function imprimir(string $uuid, string $contexto = 'checkout', string $resultado = 'accepted', array $agente = []): array
    {
        $inicio = $this->imprimirConAgente($uuid, $this->caja, $contexto)->assertStatus(201)->json('data');
        $token = $inicio['agent_ticket']['token'];

        $canje = $this->canjear($token, $this->caja, $agente)->assertOk()->json();
        $this->assertSame('print', $canje['purpose']);
        $this->assertTrue(Str::isUuid($canje['ticket_id']));

        if ($resultado !== 'none') {
            $this->reportar($token, $this->caja, $resultado, $resultado === 'error'
                ? ['error_code' => 'paper_out', 'message' => 'La impresora no tiene papel']
                : [])->assertOk();
        }

        return ['job' => $canje['job'], 'token' => $token, 'intento' => $inicio['client_attempt_id'], 'inicio' => $inicio];
    }

    private function texto(array $job): string
    {
        return implode("\n", array_column($job['lines'], 'text'));
    }

    /** El job es EXACTAMENTE lo que maqueta el servidor desde el snapshot y lo vivo. */
    private function assertJobDelRecibo(array $job, string $uuid, ?string $copia, int $columnas = 32): void
    {
        $recibo = Receipt::where('uuid', $uuid)->firstOrFail();
        $esperado = app(ReceiptTextLayout::class)->forReceipt($recibo, app(ReceiptPresenter::class)->live($recibo), $copia, $columnas);

        $this->assertSame($recibo->number, $job['receipt_number']);
        $this->assertSame($copia, $job['copy_label']);
        $this->assertSame($columnas, $job['columns']);
        $this->assertSame(5, $job['feed_lines'], 'el avance es el de la estación');
        $this->assertFalse($job['cut']);
        $this->assertSame($esperado, $job['lines']);
        app(ReceiptTextLayout::class)->assertPrintable($job['lines'], $columnas);
        $this->assertSame(['receipt_number', 'copy_label', 'kind', 'columns', 'feed_lines', 'cut', 'lines'], array_keys($job));
    }

    // ── Negocio, caja por caja ──────────────────────────────────────────────

    public function test_venta_de_productos_de_punta_a_punta(): void
    {
        $venta = $this->vender([
            ['product_id' => $this->producto('Agua 600 ml', 3000)->id, 'quantity' => 3],
            ['product_id' => $this->producto('Barra de proteína', 6500)->id, 'quantity' => 2],
        ]);
        $uuid = $this->emitir('product_sale', $venta->id)->assertStatus(201)->json('data.0.uuid');

        $r = $this->imprimir($uuid, resultado: 'none');
        $this->assertSame('original', $r['inicio']['kind']);
        $this->assertNull($r['inicio']['copy_label']);
        $this->assertSame('original', $r['job']['kind']);
        $this->assertJobDelRecibo($r['job'], $uuid, null);
        $this->assertStringStartsWith('RP-', $r['job']['receipt_number']);
        $this->assertMatchesRegularExpression('/3 x \$ 3\.000\s+\$ 9\.000/', $this->texto($r['job']));
        $this->assertMatchesRegularExpression('/TOTAL\s+\$ 22\.000/', $this->texto($r['job']));

        // Tomado por el servicio, sin resultado aún: ENVIANDO.
        $intento = ReceiptPrint::where('uuid', $r['intento'])->firstOrFail();
        $this->assertSame('started', $intento->status);
        $this->assertNotNull($intento->dispatched_at);
        $this->assertSame($this->caja->id, (int) $intento->station_id);
        $this->assertSame('agent', $intento->print_mode);
        $this->getJson("/api/admin/receipts/{$uuid}", $this->h)->assertOk()->assertJsonPath('data.print_state', 'sending');

        $this->reportar($r['token'], $this->caja, 'accepted', ['spool_job_id' => 77])->assertOk()->assertExactJson(['ok' => true, 'recorded' => true]);

        $intento->refresh();
        $this->assertSame('sent', $intento->status);
        $this->assertNotNull($intento->finished_at);
        $this->assertNull($intento->error_message);
        $this->getJson("/api/admin/receipts/{$uuid}", $this->h)->assertOk()->assertJsonPath('data.print_state', 'printed');

        $ticket = ReceiptAgentTicket::firstOrFail();
        $this->assertSame('accepted', $ticket->outcome);
        $this->assertSame(77, $ticket->spool_job_id);
        $this->assertSame(32, $ticket->columns);
        $this->assertSame('1.0.0', $ticket->agent_version);
        $this->assertSame(hash('sha256', json_encode($r['job']['lines'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $ticket->content_sha256);
        $this->assertSame((string) $this->cajero->id, (string) $ticket->issued_by_admin_id);

        // La estación queda con lo que contó el servicio.
        $this->caja->refresh();
        $this->assertNotNull($this->caja->last_seen_at);
        $this->assertSame('1.0.0', $this->caja->agent_version);
        $this->assertSame(1, $this->caja->agent_protocol);
        $this->assertSame('POS-58', $this->caja->last_printer_name);
        $this->assertSame('127.0.0.1', $this->caja->last_ip);
    }

    public function test_pago_de_membresia_con_vigencia_y_a_42_columnas(): void
    {
        $pago = $this->cobrar($this->socio('Laura Gómez'), $this->plan('Premium', 120000));
        $uuid = $this->emitir('payment', $pago->id)->assertStatus(201)->json('data.0.uuid');

        $r = $this->imprimir($uuid, agente: ['columns' => 42]);

        $this->assertJobDelRecibo($r['job'], $uuid, null, 42);
        $texto = $this->texto($r['job']);
        $this->assertStringStartsWith('RG-', $r['job']['receipt_number']);
        $this->assertMatchesRegularExpression('/Plan\s+Premium/', $texto);
        $this->assertMatchesRegularExpression('/Vigencia\s+\d{2}\/\d{2}\/\d{4} - \d{2}\/\d{2}\/\d{4}/', $texto);
        $this->assertMatchesRegularExpression('/Cliente\s+Laura Gómez/u', $texto);
        $this->assertStringContainsString('IRON BODY NEIVA S.A.S.', $texto);
        // 19:30 en Neiva, no las 00:30 UTC.
        $this->assertMatchesRegularExpression('/Fecha\s+16\/10\/2026 19:30/', $texto);
    }

    public function test_renovacion_y_mejora_de_plan(): void
    {
        // Renovar: el mismo socio vuelve a pagar su plan.
        $socio = $this->socio('Socio que renueva');
        $plan = $this->plan('Mensual renovable', 80000);
        $this->cobrar($socio, $plan);
        $renovacion = $this->cobrar($socio, $plan);
        $uRenovacion = $this->emitir('payment', $renovacion->id)->assertStatus(201)->json('data.0.uuid');
        $this->assertJobDelRecibo($this->imprimir($uRenovacion)['job'], $uRenovacion, null);

        // Mejorar: de Mensual a Total Access.
        $this->abrir(CashShiftType::GYM);
        [$user, , $total] = $this->socioConMensual();
        $this->postJson("/api/users/{$user->id}/plan-upgrade", ['plan_id' => $total->id, 'method' => 'transfer'], $this->h)->assertStatus(201);
        $mejora = Payment::whereNotNull('upgraded_from_plan_id')->firstOrFail();
        $uMejora = $this->emitir('payment', $mejora->id)->assertStatus(201)->json('data.0.uuid');

        $job = $this->imprimir($uMejora)['job'];
        $this->assertJobDelRecibo($job, $uMejora, null);
        $this->assertMatchesRegularExpression('/Mejora desde\s+Mensual/', $this->texto($job));
    }

    public function test_abono_con_saldo_y_cobro_de_varias_deudas_en_las_dos_cajas(): void
    {
        // Plan a plazos: el primer abono deja saldo.
        [, $primerAbono] = $this->venderAPlazos($this->socio('Pedro Plazos'), $this->plan('Anual', 200000), 80000);
        $uAbono = $this->emitir('receivable_payment', $primerAbono->id)->assertStatus(201)->json('data.0.uuid');
        $job = $this->imprimir($uAbono)['job'];
        $this->assertJobDelRecibo($job, $uAbono, null);
        $this->assertMatchesRegularExpression('/Saldo anterior\s+\$ 200\.000/', $this->texto($job));
        $this->assertMatchesRegularExpression('/Saldo actual\s+\$ 120\.000/', $this->texto($job));

        // Cobrar todo: deudas de las dos cajas → un recibo por caja, cada uno su tirilla.
        $socio = $this->socio('Marta Debe');
        $agua = $this->producto('Agua 600 ml', 3000);
        $this->fiar($socio, $agua, 1);
        $this->fiar($socio, $agua, 2);
        $this->postJson('/api/admin/receivables', [
            'member_id' => $socio->id, 'type' => 'gym', 'concept' => 'Saldo mensualidad octubre', 'total_amount' => 40000,
        ], $this->h)->assertStatus(201);
        $cobro = $this->postJson("/api/admin/receivables/people/member/{$socio->id}/settle", [
            'method' => 'cash', 'client_request_id' => 'barrido-agente',
        ], $this->h)->assertStatus(201)->json();
        $recibos = collect($this->emitir('receivable_payment', array_column($cobro['payments'], 'id'))->assertStatus(201)->json('data'))->keyBy('cash_type');
        $this->assertCount(2, $recibos);

        foreach ($recibos as $caja => $recibo) {
            $job = $this->imprimir($recibo['uuid'])['job'];
            $this->assertJobDelRecibo($job, $recibo['uuid'], null);
            $this->assertStringStartsWith($caja === 'gym' ? 'RG-' : 'RP-', $job['receipt_number']);
        }
    }

    public function test_lo_que_no_se_puede_imprimir_no_llega_a_tener_ticket(): void
    {
        $socio = $this->socio();
        $pasarela = Payment::create([
            'user_id' => $socio->user_id, 'plan_id' => $this->plan()->id, 'amount' => 120000,
            'method' => 'wompi', 'status' => 'paid', 'paid_at' => now(), 'origin' => 'gateway',
        ]);
        $fallido = $this->cobrar($this->socio('Pendiente'), $this->plan('Otro'), ['status' => 'pending']);

        $this->emitir('payment', $pasarela->id)->assertStatus(422)->assertJsonPath('reason', 'gateway');
        $this->emitir('payment', $fallido->id)->assertStatus(422)->assertJsonPath('reason', 'pending');

        $this->assertSame(0, Receipt::count());
        $this->assertSame(0, ReceiptAgentTicket::count());
    }

    // ── Copias: el rótulo lo pone el servidor ───────────────────────────────

    public function test_reimpresion_desde_el_informe_del_turno(): void
    {
        $pago = $this->cobrar($this->socio(), $this->plan());
        $uuid = $this->emitir('payment', $pago->id)->assertStatus(201)->json('data.0.uuid');
        $this->imprimir($uuid);

        $r = $this->imprimir($uuid, 'shift_report');
        $this->assertSame('reprint', $r['inicio']['kind']);
        $this->assertSame('reprint', $r['job']['kind']);
        $this->assertJobDelRecibo($r['job'], $uuid, 'REIMPRESIÓN');
        $this->assertSame('*** REIMPRESIÓN ***', trim($r['job']['lines'][0]['text']));
        $this->assertSame('*** REIMPRESIÓN ***', trim(end($r['job']['lines'])['text']));
    }

    public function test_reintento_tras_un_error_de_la_impresora(): void
    {
        $pago = $this->cobrar($this->socio(), $this->plan());
        $uuid = $this->emitir('payment', $pago->id)->assertStatus(201)->json('data.0.uuid');

        $original = $this->imprimir($uuid, resultado: 'error');
        $this->assertNull($original['job']['copy_label']);
        $fila = ReceiptPrint::where('uuid', $original['intento'])->firstOrFail();
        $this->assertSame('error', $fila->status);
        $this->assertSame('La impresora no tiene papel', $fila->error_message);
        $this->assertSame('paper_out', ReceiptAgentTicket::firstOrFail()->error_code);
        $this->getJson("/api/admin/receipts/{$uuid}", $this->h)->assertJsonPath('data.print_state', 'error');

        $reintento = $this->imprimir($uuid);
        $this->assertSame('retry', $reintento['job']['kind']);
        $this->assertJobDelRecibo($reintento['job'], $uuid, 'REINTENTO 1');
        $this->getJson("/api/admin/receipts/{$uuid}", $this->h)->assertJsonPath('data.print_state', 'printed');

        // Solo con el código, el código queda como mensaje.
        $otro = $this->imprimir($uuid, 'checkout', 'none');
        $this->reportar($otro['token'], $this->caja, 'error', ['error_code' => 'printer_offline'])->assertOk();
        $this->assertSame('printer_offline', ReceiptPrint::where('uuid', $otro['intento'])->value('error_message'));
        $this->assertSame('REINTENTO 2', $otro['job']['copy_label']);
    }

    public function test_una_operacion_anulada_despues_sale_anulada_y_con_su_factura_al_canjear(): void
    {
        $pago = $this->cobrar($this->socio(), $this->plan());
        $uuid = $this->emitir('payment', $pago->id)->assertStatus(201)->json('data.0.uuid');
        $snapshot = Receipt::where('uuid', $uuid)->value('snapshot');

        $this->putJson("/api/payments/{$pago->id}", ['status' => 'cancelled'], $this->adminHeaders())->assertOk();
        $r = $this->imprimir($uuid);

        $this->assertSame('reprint', $r['job']['kind'], 'algo anulado nunca sale como original');
        $this->assertStringContainsString('*** OPERACIÓN ANULADA ***', $this->texto($r['job']));
        $this->assertSame($snapshot, Receipt::where('uuid', $uuid)->value('snapshot'), 'el snapshot no cambia');
    }

    // ── Quién cierra ────────────────────────────────────────────────────────

    public function test_si_el_crm_cerro_antes_el_resultado_del_servicio_solo_se_anota(): void
    {
        $pago = $this->cobrar($this->socio(), $this->plan());
        $uuid = $this->emitir('payment', $pago->id)->assertStatus(201)->json('data.0.uuid');
        $r = $this->imprimir($uuid, resultado: 'none');

        // El servicio no pudo reportar a tiempo (reported=false): el CRM cierra.
        $this->patchJson("/api/admin/receipts/{$uuid}/prints/{$r['intento']}", ['status' => 'error', 'error_message' => 'Sin respuesta del servicio'], $this->h)
            ->assertOk()->assertJsonPath('data.status', 'error');

        $this->reportar($r['token'], $this->caja, 'accepted')->assertOk()
            ->assertExactJson(['ok' => true, 'recorded' => true, 'already_finished' => true]);

        $fila = ReceiptPrint::where('uuid', $r['intento'])->firstOrFail();
        $this->assertSame('error', $fila->status, 'no se pisa lo que cerró el CRM');
        $this->assertSame('Sin respuesta del servicio', $fila->error_message);
        $this->assertSame('accepted', ReceiptAgentTicket::firstOrFail()->outcome);
        $this->assertNotNull(ReceiptAgentTicket::firstOrFail()->reported_at);
    }

    public function test_un_intento_cerrado_no_se_canjea(): void
    {
        $pago = $this->cobrar($this->socio(), $this->plan());
        $uuid = $this->emitir('payment', $pago->id)->assertStatus(201)->json('data.0.uuid');
        $inicio = $this->imprimirConAgente($uuid, $this->caja)->assertStatus(201)->json('data');

        $this->patchJson("/api/admin/receipts/{$uuid}/prints/{$inicio['client_attempt_id']}", ['status' => 'error'], $this->h)->assertOk();

        $this->canjear($inicio['agent_ticket']['token'], $this->caja)->assertStatus(409)->assertJsonPath('code', 'attempt_closed');
        $this->assertNull(ReceiptAgentTicket::firstOrFail()->redeemed_at);
        $this->assertNull(ReceiptPrint::firstOrFail()->dispatched_at);
    }

    // ── Omitir: igual que antes ─────────────────────────────────────────────

    public function test_impresion_obligatoria_u_opcional_igual_con_el_servicio_local(): void
    {
        $pago = $this->cobrar($this->socio(), $this->plan());
        $uuid = $this->emitir('payment', $pago->id)->assertStatus(201)->json('data.0.uuid');
        $omitir = fn () => $this->postJson("/api/admin/receipts/{$uuid}/skip", ['client_attempt_id' => (string) Str::uuid(), 'reason' => 'Sin papel'], $this->h);

        // Un envío en curso (sin resultado) no es papel: omitir sigue exigiendo el permiso.
        $this->imprimir($uuid, resultado: 'none');
        $omitir()->assertStatus(403)->assertJsonPath('required_permission', 'receipts.print_optional');

        $permiso = RolePermission::create(['role' => Admin::ROLE_RECEPCION, 'permission' => 'receipts.print_optional', 'granted' => true]);
        app(RolePermissionPolicy::class)->flush();
        $omitir()->assertOk()->assertJsonPath('data.status', 'skipped')->assertJsonPath('data.print_state', 'skipped');

        $permiso->delete();
        app(RolePermissionPolicy::class)->flush();
        $omitir()->assertStatus(403);

        // Aceptado por el sistema = papel: ya no hay nada que omitir.
        $otro = $this->emitir('payment', $this->cobrar($this->socio('Otra'), $this->plan('Otro plan'))->id)->json('data.0.uuid');
        $this->imprimir($otro);
        $this->postJson("/api/admin/receipts/{$otro}/skip", ['client_attempt_id' => (string) Str::uuid()], $this->adminHeaders())
            ->assertStatus(409)->assertJsonPath('code', 'already_printed');
    }

    // ── Lo que ve el CRM ────────────────────────────────────────────────────

    public function test_el_informe_del_turno_expone_el_ultimo_intento_y_el_envio_en_curso(): void
    {
        $plan = $this->plan();
        $aceptado = $this->cobrar($this->socio('Aceptado'), $plan);
        $enviando = $this->cobrar($this->socio('Enviando'), $plan);
        $fallido = $this->cobrar($this->socio('Fallido'), $plan);

        $this->imprimir($this->emitir('payment', $aceptado->id)->json('data.0.uuid'));
        $this->imprimir($this->emitir('payment', $enviando->id)->json('data.0.uuid'), resultado: 'none');
        $this->imprimir($this->emitir('payment', $fallido->id)->json('data.0.uuid'), resultado: 'error');

        $res = $this->getJson("/api/admin/caja/shifts/{$aceptado->cash_shift_id}/receipts", $this->h)->assertOk();
        $filas = collect($res->json('data'))->keyBy(fn ($f) => (string) $f['row_id']);

        $this->assertSame('printed', $filas[(string) $aceptado->id]['print_state']);
        $this->assertSame(['kind' => 'original', 'status' => 'sent', 'print_mode' => 'agent'],
            array_intersect_key($filas[(string) $aceptado->id]['last_attempt'], array_flip(['kind', 'status', 'print_mode'])));
        $this->assertNotNull($filas[(string) $aceptado->id]['last_attempt']['dispatched_at']);

        $this->assertSame('sending', $filas[(string) $enviando->id]['print_state']);
        $this->assertSame('started', $filas[(string) $enviando->id]['last_attempt']['status']);

        $this->assertSame('error', $filas[(string) $fallido->id]['print_state']);
        $this->assertSame('La impresora no tiene papel', $filas[(string) $fallido->id]['last_attempt']['error_message']);

        // El resumen conserva sus cinco claves: lo que se está enviando aún falta.
        $this->assertSame(['pending' => 1, 'printed' => 1, 'skipped' => 0, 'error' => 1, 'not_eligible' => 0], $res->json('summary'));
    }

    public function test_recibos_pendientes_por_caja_y_ventana(): void
    {
        $impreso = $this->emitir('payment', $this->cobrar($this->socio('Impreso'), $this->plan())->id)->json('data.0.uuid');
        $this->imprimir($impreso);
        $enviando = $this->emitir('payment', $this->cobrar($this->socio('Enviando'), $this->plan('P2'))->id)->json('data.0.uuid');
        $this->imprimir($enviando, resultado: 'none');
        $fallido = $this->emitir('payment', $this->cobrar($this->socio('Fallido'), $this->plan('P3'))->id)->json('data.0.uuid');
        $this->imprimir($fallido, resultado: 'error');
        $omitido = $this->emitir('payment', $this->cobrar($this->socio('Omitido'), $this->plan('P4'))->id)->json('data.0.uuid');
        $this->postJson("/api/admin/receipts/{$omitido}/skip", ['client_attempt_id' => (string) Str::uuid()], $this->adminHeaders())->assertOk();
        $sinIntentos = $this->emitir('payment', $this->cobrar($this->socio('Sin intentos'), $this->plan('P5'))->id)->json('data.0.uuid');
        $producto = $this->emitir('product_sale', $this->vender([['product_id' => $this->producto()->id, 'quantity' => 1]])->id)->json('data.0.uuid');

        $res = $this->getJson('/api/admin/receipts/pending', $this->h)->assertOk();
        $porUuid = collect($res->json('data'))->keyBy('uuid');

        $this->assertEqualsCanonicalizing([$enviando, $fallido, $sinIntentos, $producto], $porUuid->keys()->all());
        $this->assertSame('sending', $porUuid[$enviando]['print_state']);
        $this->assertSame('error', $porUuid[$fallido]['print_state']);
        $this->assertSame('La impresora no tiene papel', $porUuid[$fallido]['last_attempt']['error_message']);
        $this->assertSame('agent', $porUuid[$fallido]['last_attempt']['print_mode']);
        $this->assertSame('pending', $porUuid[$sinIntentos]['print_state']);
        $this->assertNull($porUuid[$sinIntentos]['last_attempt']);
        $this->assertSame('Sin intentos', $porUuid[$sinIntentos]['customer_name']);
        $this->assertSame(
            ['uuid', 'number', 'cash_type', 'issued_at', 'customer_name', 'total', 'print_state', 'last_attempt'],
            array_keys($res->json('data.0')),
        );
        $this->assertSame(['kind', 'status', 'print_mode', 'error_message', 'started_at', 'dispatched_at'], array_keys($porUuid[$enviando]['last_attempt']));
        // Los más recientes primero.
        $this->assertSame($producto, $res->json('data.0.uuid'));

        // Por caja: quien solo opera productos ve solo productos; quien solo ve, nada.
        $soloProductos = $this->getJson('/api/admin/receipts/pending', $this->adminWithPermissions(['cash.products.operate']))->assertOk();
        $this->assertSame([$producto], array_column($soloProductos->json('data'), 'uuid'));
        $this->getJson('/api/admin/receipts/pending', $this->adminWithPermissions(['cash.gym.view']))->assertOk()->assertExactJson(['ok' => true, 'data' => []]);

        // Por ventana.
        Carbon::setTestNow(now()->addHours(3));
        $this->assertSame([], $this->getJson('/api/admin/receipts/pending?hours=2', $this->h)->assertOk()->json('data'));
        $this->assertCount(4, $this->getJson('/api/admin/receipts/pending?hours=4', $this->h)->json('data'));
        $this->getJson('/api/admin/receipts/pending?hours=0', $this->h)->assertStatus(422);
        $this->getJson('/api/admin/receipts/pending?hours=169', $this->h)->assertStatus(422);
    }

    public function test_recibos_pendientes_como_mucho_50(): void
    {
        $pago = $this->cobrar($this->socio(), $this->plan());
        $base = Receipt::where('uuid', $this->emitir('payment', $pago->id)->json('data.0.uuid'))->firstOrFail();

        // Recibos sintéticos del gimnasio (solo para el límite de la lista).
        for ($i = 1; $i <= 55; $i++) {
            DB::table('receipts')->insert([
                'uuid' => (string) Str::uuid(), 'number' => 'RG-9'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'cash_type' => 'gym',
                'kind' => $base->kind, 'origin_key' => 'synthetic:'.$i, 'issued_at' => now()->subMinutes($i), 'total' => 1000,
                'snapshot' => json_encode($base->snapshot), 'template_version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->assertCount(50, $this->getJson('/api/admin/receipts/pending?hours=168', $this->h)->assertOk()->json('data'));
    }

    // ── Páginas de prueba y configuración ───────────────────────────────────

    public function test_pagina_de_prueba_y_configuracion_por_el_servicio(): void
    {
        $supervisor = $this->supervisor();
        $this->patchJson("/api/admin/receipt-stations/{$this->caja->station_key}", [
            'printer_name' => 'DIG-58IIK', 'columns' => 42, 'codepage' => 'cp437', 'feed_lines' => 3,
        ], $supervisor)->assertOk();

        $minima = $this->ticketDeEstacion($this->caja, 'test', 'minimal', $supervisor)->assertStatus(201);
        $this->assertSame(['token', 'expires_at', 'purpose'], array_keys($minima->json('data')));
        $this->assertSame('test', $minima->json('data.purpose'));
        $canje = $this->canjear($minima->json('data.token'), $this->caja)->assertOk();
        $this->assertSame('test', $canje->json('purpose'));
        $this->assertSame(['ok', 'purpose', 'ticket_id', 'job'], array_keys($canje->json()));
        $this->assertSame('test', $canje->json('job.kind'));
        $this->assertNull($canje->json('job.receipt_number'));
        $this->assertSame(3, $canje->json('job.feed_lines'));
        $this->assertSame(['IRON BODY', '           PRUEBA POS', '               OK'], array_column($canje->json('job.lines'), 'text'));
        $this->reportar($minima->json('data.token'), $this->caja, 'accepted')->assertOk();

        $completa = $this->ticketDeEstacion($this->caja, 'test', 'full', $supervisor)->assertStatus(201)->json('data.token');
        $job = $this->canjear($completa, $this->caja, ['columns' => 42, 'codepage' => 'cp437'])->assertOk()->json('job');
        $this->assertSame(42, $job['columns']);
        $this->assertStringContainsString('42 col · cp437', implode("\n", array_column($job['lines'], 'text')));
        $this->assertStringContainsString('Recepción', implode("\n", array_column($job['lines'], 'text')));

        $config = $this->ticketDeEstacion($this->caja, 'configure', null, $supervisor)->assertStatus(201)->json('data.token');
        $this->canjear($config, $this->caja)->assertOk()->assertExactJson([
            'ok' => true, 'purpose' => 'configure', 'ticket_id' => ReceiptAgentTicket::where('token_hash', hash('sha256', $config))->value('uuid'),
            'settings' => ['station_name' => 'Recepción', 'printer_name' => 'DIG-58IIK', 'columns' => 42, 'codepage' => 'cp437', 'feed_lines' => 3],
        ]);
        $this->reportar($config, $this->caja, 'accepted')->assertStatus(422)->assertJsonPath('code', 'invalid_outcome');
        $this->reportar($config, $this->caja, 'applied')->assertOk()->assertExactJson(['ok' => true, 'recorded' => true]);

        // Ningún ticket de prueba ni de configuración toca un intento de impresión.
        $this->assertSame(0, ReceiptPrint::count());
        $this->assertSame(['test' => 2, 'configure' => 1], ReceiptAgentTicket::query()->pluck('purpose')->countBy()->all());
        $this->assertSame(300, (int) ReceiptAgentTicket::firstOrFail()->issued_at->diffInSeconds(ReceiptAgentTicket::firstOrFail()->expires_at));
    }

    /**
     * Los recibos de MUESTRA (pruebas físicas 2–5) salen del mismo maquetador
     * pero de un snapshot en memoria: emitirlos y canjearlos no crea ni toca una
     * sola fila de recibos, intentos, cobros, ventas, abonos, inventario,
     * turnos ni facturas.
     */
    public function test_los_recibos_de_muestra_no_crean_nada_fuera_de_su_ticket(): void
    {
        // Algo de negocio real alrededor, para que «no cambia» signifique algo.
        $this->cobrar($this->socio(), $this->plan());
        $this->vender([['product_id' => $this->producto()->id, 'quantity' => 2]]);
        $supervisor = $this->supervisor();

        $antes = $this->dineroYStock() + [
            'receipts_count' => Receipt::count(),
            'receipt_prints' => ReceiptPrint::count(),
            'receipt_sources' => DB::table('receipt_sources')->count(),
            'receipt_sequences' => DB::table('receipt_sequences')->orderBy('cash_type')->get()->toJson(),
        ];

        foreach (['sample_1', 'sample_5', 'sample_20', 'sample_membership'] as $variante) {
            foreach ([32, 42] as $cols) {
                $token = $this->ticketDeEstacion($this->caja, 'test', $variante, $supervisor)->assertStatus(201)->json('data.token');
                $job = $this->canjear($token, $this->caja, ['columns' => $cols])->assertOk()->assertJsonPath('purpose', 'test')->json('job');

                $this->assertSame('PRUEBA', $job['receipt_number'], $variante);
                $this->assertSame('PRUEBA · SIN VALOR', $job['copy_label']);
                $this->assertSame('test', $job['kind']);
                $this->assertSame($cols, $job['columns']);
                $this->assertSame(5, $job['feed_lines']);
                $this->assertSame('*** PRUEBA · SIN VALOR ***', trim($job['lines'][0]['text']));
                $this->assertSame('*** PRUEBA · SIN VALOR ***', trim(end($job['lines'])['text']));
                $this->assertStringContainsString('CLIENTE DE PRUEBA', implode("\n", array_column($job['lines'], 'text')));
                app(ReceiptTextLayout::class)->assertPrintable($job['lines'], $cols);

                $this->reportar($token, $this->caja, 'accepted')->assertOk()->assertExactJson(['ok' => true, 'recorded' => true]);

                $ticket = ReceiptAgentTicket::where('token_hash', hash('sha256', $token))->firstOrFail();
                $this->assertSame($variante, $ticket->variant);
                $this->assertNull($ticket->receipt_print_id);
                $this->assertSame(hash('sha256', json_encode($job['lines'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $ticket->content_sha256);
            }
        }

        $this->assertSame($antes, $this->dineroYStock() + [
            'receipts_count' => Receipt::count(),
            'receipt_prints' => ReceiptPrint::count(),
            'receipt_sources' => DB::table('receipt_sources')->count(),
            'receipt_sequences' => DB::table('receipt_sequences')->orderBy('cash_type')->get()->toJson(),
        ]);
        $this->assertSame(8, ReceiptAgentTicket::count(), 'lo único que queda es el rastro de cada ticket');
    }

    /**
     * PRODUCCIÓN NO TIENE ext-intl: el canje maqueta con el respaldo. Se fuerza
     * con la costura del maquetador (sin ella, el contenedor da el de siempre) y
     * se imprime un cobro de un socio con el nombre DESCOMPUESTO, como lo deja
     * algún teclado o un copiar-pegar desde macOS.
     */
    public function test_el_canje_con_el_respaldo_sin_intl_compone_y_no_deja_marcas(): void
    {
        $this->app->instance(ReceiptTextLayout::class, new ReceiptTextLayout(useIntl: false));

        $pago = $this->cobrar($this->socio("Jose\u{0301} Pen\u{0303}a Franc\u{0327}ois Mu\u{0308}ller"), $this->plan("Ma\u{0301}s Premium"));
        $uuid = $this->emitir('payment', $pago->id)->assertStatus(201)->json('data.0.uuid');

        foreach ([32, 42] as $cols) {
            $job = $this->imprimir($uuid, $cols === 32 ? 'checkout' : 'shift_report', agente: ['columns' => $cols])['job'];
            $texto = implode("\n", array_column($job['lines'], 'text'));

            $this->assertSame(0, preg_match('/[\x{0300}-\x{036F}\x{1AB0}-\x{1AFF}\x{1DC0}-\x{1DFF}\x{20D0}-\x{20FF}\x{FE20}-\x{FE2F}]/u', $texto));
            $this->assertMatchesRegularExpression('/Cliente\s+José Peña/u', $texto);
            $this->assertStringContainsString('Francois Müller', $texto);
            $this->assertStringContainsString('Más Premium', $texto);
            foreach ($job['lines'] as $l) {
                $this->assertLessThanOrEqual($cols, mb_strlen($l['text']));
            }
        }
    }

    /**
     * El encabezado por defecto es «IRON BODY WORKOUT» (pedido del propietario),
     * pero un recibo emitido ANTES lleva su nombre congelado: se reimprime igual.
     */
    public function test_el_nombre_comercial_por_defecto_y_los_recibos_ya_emitidos(): void
    {
        $this->assertSame('IRON BODY WORKOUT', config('caja.receipts.business_name'));

        // Uno emitido cuando el nombre era «IRON BODY».
        config(['caja.receipts.business_name' => 'IRON BODY']);
        $viejo = $this->emitir('payment', $this->cobrar($this->socio('Antes'), $this->plan('Viejo'))->id)->json('data.0.uuid');
        config(['caja.receipts.business_name' => 'IRON BODY WORKOUT']);
        $nuevo = $this->emitir('payment', $this->cobrar($this->socio('Después'), $this->plan('Nuevo'))->id)->json('data.0.uuid');

        $this->assertSame('IRON BODY', Receipt::where('uuid', $viejo)->firstOrFail()->snapshot['business']['name']);
        $this->assertSame(['text' => 'IRON BODY', 'style' => 'title'], $this->imprimir($viejo, 'shift_report')['job']['lines'][1]);
        $this->assertSame(['text' => 'IRON BODY WORKOUT', 'style' => 'title'], $this->imprimir($nuevo)['job']['lines'][0]);
    }

    /**
     * Un configure que el servicio NO pudo aplicar (impresora inexistente,
     * ajustes inválidos, no pudo guardarlos) se reporta como `error` y queda en
     * el ticket. `applied` sigue siendo solo de configure; `accepted`, de print y test.
     */
    public function test_un_configure_que_no_se_pudo_aplicar_se_reporta_como_error(): void
    {
        $supervisor = $this->supervisor();
        $config = $this->ticketDeEstacion($this->caja, 'configure', null, $supervisor)->assertStatus(201)->json('data.token');
        $this->canjear($config, $this->caja)->assertOk()->assertJsonPath('purpose', 'configure');

        $this->reportar($config, $this->caja, 'error', [
            'error_code' => 'printer_not_found', 'message' => 'La impresora «POS-58» no existe en este equipo', 'printer_name' => null,
        ])->assertOk()->assertExactJson(['ok' => true, 'recorded' => true]);

        $ticket = ReceiptAgentTicket::where('token_hash', hash('sha256', $config))->firstOrFail();
        $this->assertSame('error', $ticket->outcome);
        $this->assertSame('printer_not_found', $ticket->error_code);
        $this->assertSame('La impresora «POS-58» no existe en este equipo', $ticket->error_message);
        $this->assertNotNull($ticket->reported_at);
        $this->reportar($config, $this->caja, 'applied')->assertStatus(409)->assertJsonPath('code', 'already_reported');

        // Lo ve quien supervisa, en los eventos del equipo.
        $evento = $this->getJson("/api/admin/receipt-stations/{$this->caja->station_key}/events", $supervisor)->json('data.0');
        $this->assertSame(['configure', 'error', 'printer_not_found'], [$evento['purpose'], $evento['outcome'], $evento['error_code']]);

        // Las otras combinaciones siguen como estaban.
        $otro = $this->ticketDeEstacion($this->caja, 'configure', null, $supervisor)->json('data.token');
        $this->canjear($otro, $this->caja)->assertOk();
        $this->reportar($otro, $this->caja, 'accepted')->assertStatus(422)->assertJsonPath('code', 'invalid_outcome');
        $prueba = $this->ticketDeEstacion($this->caja, 'test', null, $supervisor)->json('data.token');
        $this->canjear($prueba, $this->caja)->assertOk();
        $this->reportar($prueba, $this->caja, 'applied')->assertStatus(422)->assertJsonPath('code', 'invalid_outcome');
        $this->reportar($prueba, $this->caja, 'error', ['error_code' => 'paper_out'])->assertOk();
        $this->assertSame(0, ReceiptPrint::count());
    }

    // ── Integridad ──────────────────────────────────────────────────────────

    public function test_el_flujo_completo_no_mueve_dinero_inventario_turnos_ni_facturas(): void
    {
        $pago = $this->cobrar($this->socio(), $this->plan());
        $venta = $this->vender([['product_id' => $this->producto()->id, 'quantity' => 3]]);
        $credito = $this->fiar($this->socio('Fiado'), $this->producto('Café', 2500));
        [, $abono] = $this->venderAPlazos($this->socio('Plazos'), $this->plan('Anual', 200000), 50000);

        $antes = $this->dineroYStock();

        $uPago = $this->emitir('payment', $pago->id)->json('data.0.uuid');
        $uVenta = $this->emitir('product_sale', $venta->id)->json('data.0.uuid');
        $uCredito = $this->emitir('product_sale', $credito->id)->json('data.0.uuid');
        $uAbono = $this->emitir('receivable_payment', $abono->id)->json('data.0.uuid');
        $antes['receipts'] = DB::table('receipts')->orderBy('id')->get(['id', 'number', 'total', 'snapshot'])->toJson();

        $this->imprimir($uPago);
        $this->imprimir($uVenta, resultado: 'error');
        $this->imprimir($uVenta);
        $this->imprimir($uCredito, 'shift_report');
        $sinResultado = $this->imprimir($uAbono, resultado: 'none');
        $this->patchJson("/api/admin/receipts/{$uAbono}/prints/{$sinResultado['intento']}", ['status' => 'sent'], $this->h)->assertOk();
        $this->reportar($sinResultado['token'], $this->caja, 'error', ['error_code' => 'job_timeout'])->assertOk();
        $this->getJson('/api/admin/receipts/pending', $this->h)->assertOk();
        $this->getJson("/api/admin/caja/shifts/{$pago->cash_shift_id}/receipts", $this->h)->assertOk();
        $this->getJson("/api/admin/caja/shifts/{$venta->cash_shift_id}/receipts", $this->h)->assertOk();
        $prueba = $this->ticketDeEstacion($this->caja)->json('data.token');
        $this->canjear($prueba, $this->caja)->assertOk();
        $this->reportar($prueba, $this->caja, 'accepted')->assertOk();

        $this->assertSame($antes, $this->dineroYStock());
        $this->assertSame(1, ReceivablePayment::whereKey($abono->id)->count());
        // Cinco intentos de impresión (la página de prueba no abre ninguno).
        $this->assertSame(5, ReceiptPrint::count());
    }
}
