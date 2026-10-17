<?php

namespace Tests\Feature\Caja;

use App\Exceptions\ReceiptException;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\ReceiptPrint;
use App\Models\ReceivablePayment;
use App\Models\RolePermission;
use App\Support\Access\CrmPermission;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * IMPRIMIR, REIMPRIMIR Y OMITIR: el rastro de cada tirilla, y quién puede qué.
 *
 *  · El SERVIDOR decide si una copia es el original, un reintento o una
 *    reimpresión; el navegador solo dice desde dónde imprime.
 *  · Cada intento se cierra UNA vez y solo lo cierra quien lo abrió.
 *  · Omitir la tirilla exige `receipts.print_optional`, que por defecto solo
 *    tiene Super Admin. Sin él, el intento queda registrado como denegado y
 *    auditado ANTES del 403.
 *  · Un permiso de caja no abre la otra caja, el token de automatizaciones no es
 *    nadie y un entrenador no toca caja.
 *  · Nada de esto mueve una fila de dinero, inventario ni facturación.
 */
class ThermalReceiptPrintTest extends TestCase
{
    use BuildsCashReceipts;
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

    /** Un recibo del gimnasio recién emitido. */
    private function reciboDeGimnasio(): array
    {
        $pago = $this->cobrar($this->socio(), $this->plan('Premium '.uniqid()));

        return $this->emitir('payment', $pago->id)->assertStatus(201)->json('data.0');
    }

    private function iniciar(string $uuid, string $contexto = 'checkout', ?string $intento = null, ?array $h = null): TestResponse
    {
        return $this->postJson("/api/admin/receipts/{$uuid}/prints", [
            'client_attempt_id' => $intento ?? (string) Str::uuid(),
            'context' => $contexto,
            'print_mode' => 'silent',
        ], $h ?? $this->h);
    }

    private function cerrar(string $uuid, string $intento, string $estado, ?array $h = null, ?string $error = null): TestResponse
    {
        return $this->patchJson("/api/admin/receipts/{$uuid}/prints/{$intento}", array_filter([
            'status' => $estado,
            'error_message' => $error,
        ]), $h ?? $this->h);
    }

    private function omitir(string $uuid, array $h, ?string $intento = null): TestResponse
    {
        return $this->postJson("/api/admin/receipts/{$uuid}/skip", [
            'client_attempt_id' => $intento ?? (string) Str::uuid(),
            'reason' => 'La impresora se quedó sin papel',
        ], $h);
    }

    // ── Original, reintento, reimpresión ────────────────────────────────────

    public function test_el_servidor_decide_que_copia_es(): void
    {
        $uuid = $this->reciboDeGimnasio()['uuid'];

        $original = $this->iniciar($uuid)->assertStatus(201)->json('data');
        $this->assertSame('original', $original['kind']);
        $this->assertNull($original['copy_label']);
        $this->assertSame(['client_attempt_id', 'kind', 'copy_label'], array_keys($original));

        $this->cerrar($uuid, $original['client_attempt_id'], 'error', error: str_repeat('x', 600))
            ->assertOk()
            ->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.print_state', 'error');
        $this->assertSame(255, strlen(ReceiptPrint::where('uuid', $original['client_attempt_id'])->value('error_message')),
            'el mensaje del navegador se guarda recortado, no rompe el registro');

        $r1 = $this->iniciar($uuid)->assertStatus(201)->json('data');
        $this->assertSame(['retry', 'REINTENTO 1'], [$r1['kind'], $r1['copy_label']]);
        $this->cerrar($uuid, $r1['client_attempt_id'], 'sent')->assertOk()->assertJsonPath('data.print_state', 'printed');

        $r2 = $this->iniciar($uuid)->assertStatus(201)->json('data');
        $this->assertSame(['retry', 'REINTENTO 2'], [$r2['kind'], $r2['copy_label']]);

        foreach (['shift_report', 'pending'] as $contexto) {
            $re = $this->iniciar($uuid, $contexto)->assertStatus(201)->json('data');
            $this->assertSame(['reprint', 'REIMPRESIÓN'], [$re['kind'], $re['copy_label']], $contexto);
        }

        $this->assertSame(1, ReceiptPrint::where('kind', 'original')->count());

        // La lectura trae los intentos, del más reciente al más antiguo.
        $prints = $this->getJson("/api/admin/receipts/{$uuid}", $this->h)->assertOk()->json('data.prints');
        $this->assertCount(5, $prints);
        $this->assertSame('original', end($prints)['kind']);
        $this->assertSame(['client_attempt_id', 'kind', 'status', 'context', 'actor_name', 'started_at', 'finished_at'], array_keys($prints[0]));
        $this->assertSame('Ana Recepción', $prints[0]['actor_name']);
    }

    public function test_repetir_el_mismo_intento_devuelve_lo_mismo(): void
    {
        $uuid = $this->reciboDeGimnasio()['uuid'];
        $intento = (string) Str::uuid();

        $primero = $this->iniciar($uuid, intento: $intento)->assertStatus(201)->json('data');
        $segundo = $this->iniciar($uuid, intento: $intento)->assertStatus(200)->json('data');

        $this->assertSame($primero, $segundo);
        $this->assertSame(1, ReceiptPrint::count());

        // El mismo identificador no sirve para otro recibo.
        $otro = $this->reciboDeGimnasio()['uuid'];
        $this->iniciar($otro, intento: $intento)->assertStatus(409)->assertJsonPath('code', 'attempt_conflict');
    }

    public function test_cada_intento_se_cierra_una_vez_y_solo_quien_lo_abrio(): void
    {
        $uuid = $this->reciboDeGimnasio()['uuid'];
        $intento = $this->iniciar($uuid)->assertStatus(201)->json('data.client_attempt_id');

        $otraPersona = $this->actingAsAdmin($this->recepcion('Otra Recepción'));
        $this->cerrar($uuid, $intento, 'sent', $otraPersona)->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->assertSame('started', ReceiptPrint::where('uuid', $intento)->value('status'));

        $this->cerrar($uuid, $intento, 'sent')->assertOk()->assertJsonPath('data.status', 'sent');
        $this->cerrar($uuid, $intento, 'error')->assertStatus(409)
            ->assertJsonPath('code', 'print_already_finished')
            ->assertJsonPath('status', 'sent');
        $this->assertSame('sent', ReceiptPrint::where('uuid', $intento)->value('status'));

        $this->cerrar($uuid, (string) Str::uuid(), 'sent')->assertStatus(404);
        $this->cerrar($uuid, 'no-es-un-uuid', 'sent')->assertStatus(404);
        $this->cerrar($uuid, $intento, 'skipped')->assertStatus(422);

        // Y el modelo no deja reescribir una fila cerrada por otra vía.
        $this->expectException(ReceiptException::class);
        ReceiptPrint::where('uuid', $intento)->firstOrFail()->update(['status' => 'error']);
    }

    public function test_solo_puede_haber_un_original_por_recibo_tambien_en_la_base(): void
    {
        $recibo = Receipt::where('uuid', $this->reciboDeGimnasio()['uuid'])->firstOrFail();
        $this->iniciar($recibo->uuid)->assertStatus(201);

        // El índice único parcial es la red bajo el bloqueo del recibo.
        $this->expectException(QueryException::class);
        DB::table('receipt_prints')->insert([
            'uuid' => (string) Str::uuid(), 'receipt_id' => $recibo->id, 'kind' => 'original',
            'status' => 'started', 'context' => 'checkout', 'print_mode' => 'unknown', 'started_at' => now(),
        ]);
    }

    public function test_un_identificador_que_no_es_uuid_es_404_y_no_un_500(): void
    {
        $this->getJson('/api/admin/receipts/123', $this->h)->assertStatus(404);
        $this->getJson('/api/admin/receipts/'.Str::uuid(), $this->h)->assertStatus(404);
        $this->iniciar('RG-000001')->assertStatus(404);
    }

    // ── Omitir la impresión ─────────────────────────────────────────────────

    public function test_recepcion_no_puede_omitir_y_su_intento_queda_registrado(): void
    {
        $uuid = $this->reciboDeGimnasio()['uuid'];
        $intento = (string) Str::uuid();

        $this->omitir($uuid, $this->h, $intento)
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'forbidden')
            ->assertJsonPath('required_permission', 'receipts.print_optional');

        $fila = ReceiptPrint::where('uuid', $intento)->firstOrFail();
        $this->assertSame('skip', $fila->kind);
        $this->assertSame('denied', $fila->status);
        $this->assertSame($this->cajero->id, (int) $fila->actor_admin_id);
        $this->assertSame('Recepción', $fila->actor_role);
        $this->assertSame('La impresora se quedó sin papel', $fila->reason);

        $traza = AuditLog::where('action', 'denied')->where('entity', 'recibo')->firstOrFail();
        $this->assertSame((string) $this->cajero->id, $traza->actor_id);
        $this->assertSame('receipts.print_optional', $traza->metadata['required_permission']);

        // Denegar no cuenta como omitir: el recibo sigue pendiente de imprimir.
        $this->getJson("/api/admin/receipts/{$uuid}", $this->h)->assertOk()->assertJsonPath('data.print_state', 'pending');

        // Repetir el mismo intento no escribe otra fila.
        $this->omitir($uuid, $this->h, $intento)->assertStatus(403);
        $this->assertSame(1, ReceiptPrint::count());
    }

    public function test_recepcion_con_el_permiso_concedido_desde_roles_si_puede(): void
    {
        RolePermission::create(['role' => Admin::ROLE_RECEPCION, 'permission' => 'receipts.print_optional', 'granted' => true]);
        app(RolePermissionPolicy::class)->flush();
        $uuid = $this->reciboDeGimnasio()['uuid'];

        $this->omitir($uuid, $this->h)
            ->assertOk()
            ->assertJsonPath('data.kind', 'skip')
            ->assertJsonPath('data.status', 'skipped')
            ->assertJsonPath('data.print_state', 'skipped');

        $this->assertSame($this->cajero->id, (int) ReceiptPrint::firstOrFail()->actor_admin_id);
        $this->assertSame(1, AuditLog::where('entity', 'recibo')->where('action', 'update')->count());
    }

    public function test_por_defecto_solo_super_admin_puede_omitir(): void
    {
        $uuid = $this->reciboDeGimnasio()['uuid'];

        $this->omitir($uuid, $this->adminAs(Admin::ROLE_ADMINISTRADOR))->assertStatus(403)
            ->assertJsonPath('required_permission', 'receipts.print_optional');
        $this->omitir($uuid, $this->adminAs(Admin::ROLE_SUPER_ADMIN))->assertOk()->assertJsonPath('data.status', 'skipped');

        // Ningún rol del sistema salvo Super Admin lo trae de serie, y no es una
        // lectura: el token compartido nunca lo obtiene.
        foreach ([Admin::ROLE_ADMINISTRADOR, Admin::ROLE_ADMINISTRATIVO, Admin::ROLE_RECEPCION, CrmPermission::ROLE_ENTRENADOR, 'Rol creado en el CRM'] as $rol) {
            $this->assertNotContains('receipts.print_optional', CrmPermission::defaultsFor($rol), $rol);
        }
        $this->assertContains('receipts.print_optional', CrmPermission::defaultsFor(Admin::ROLE_SUPER_ADMIN));
        $this->assertNotContains('receipts.print_optional', CrmPermission::readOnly());
    }

    public function test_no_se_omite_lo_que_ya_se_imprimio(): void
    {
        $uuid = $this->reciboDeGimnasio()['uuid'];
        $intento = $this->iniciar($uuid)->json('data.client_attempt_id');
        $this->cerrar($uuid, $intento, 'sent')->assertOk();

        $this->omitir($uuid, $this->adminAs(Admin::ROLE_SUPER_ADMIN))->assertStatus(409)->assertJsonPath('code', 'already_printed');
        // Ni siquiera queda un «denegado»: no había nada que omitir.
        $this->omitir($uuid, $this->h)->assertStatus(409);
        $this->assertSame(0, ReceiptPrint::where('kind', 'skip')->count());
    }

    // ── Acceso ──────────────────────────────────────────────────────────────

    public function test_un_permiso_de_productos_no_abre_el_gimnasio(): void
    {
        $gimnasio = $this->reciboDeGimnasio();
        $pago = Payment::firstOrFail();
        $venta = $this->vender([['product_id' => $this->producto()->id, 'quantity' => 1]]);

        // Opera la caja de productos (emitir es escribir: exige operar o supervisar).
        $soloVeProductos = $this->adminWithPermissions(['cash.products.operate']);

        $this->emitir('product_sale', $venta->id, $soloVeProductos)->assertStatus(201);
        $this->emitir('payment', $pago->id, $soloVeProductos)->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.gym.operate');
        $this->getJson("/api/admin/receipts/{$gimnasio['uuid']}", $soloVeProductos)->assertStatus(403);
        $this->iniciar($gimnasio['uuid'], h: $soloVeProductos)->assertStatus(403);
        $this->getJson('/api/admin/caja/shifts/'.$pago->cash_shift_id.'/receipts', $soloVeProductos)->assertStatus(403);

        // Y el 403 llega ANTES que el motivo del rechazo: quien no ve el gimnasio
        // no se entera de que un cobro de allí fue por pasarela, ni de que un
        // abono de allí se revirtió.
        $pasarela = Payment::create([
            'user_id' => $pago->user_id, 'plan_id' => $pago->plan_id, 'amount' => 1000, 'method' => 'wompi',
            'status' => 'paid', 'paid_at' => now(), 'origin' => 'gateway',
        ]);
        $this->emitir('payment', $pasarela->id, $soloVeProductos)->assertStatus(403)
            ->assertJsonMissingPath('reason');
        [, $abono] = $this->venderAPlazos($this->socio('Plazos'), $this->plan('Anual', 200000), 50000);
        $this->emitir('receivable_payment', [$abono->id], $soloVeProductos)->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.gym.operate');
    }

    /**
     * Revisión de seguridad (S3): con solo «ver» se consulta, no se escribe.
     * Emitir, imprimir y omitir exigen operar o supervisar ESA caja.
     */
    public function test_con_solo_ver_la_caja_se_consulta_pero_no_se_emite_ni_imprime(): void
    {
        $recibo = $this->reciboDeGimnasio();
        $pago = Payment::firstOrFail();
        $soloVe = $this->adminWithPermissions(['cash.gym.view']);
        $antes = ReceiptPrint::count();

        $this->getJson("/api/admin/receipts/{$recibo['uuid']}", $soloVe)->assertOk();
        $this->getJson('/api/admin/caja/shifts/'.$pago->cash_shift_id.'/receipts', $soloVe)->assertOk();

        $otro = $this->cobrar($this->socio('Otra socia'), $this->plan('Mensual '.uniqid()));
        $this->emitir('payment', $otro->id, $soloVe)->assertStatus(403)->assertJsonPath('required_permission', 'cash.gym.operate');
        $this->iniciar($recibo['uuid'], h: $soloVe)->assertStatus(403)->assertJsonPath('required_permission', 'cash.gym.operate');
        $this->omitir($recibo['uuid'], $soloVe)->assertStatus(403);
        $this->assertSame($antes, ReceiptPrint::count());
    }

    /**
     * Revisión de seguridad (S2): el id de un intento se ve en GET /receipts.
     * Reenviar el de OTRA persona no devuelve su resultado como propio.
     */
    public function test_reenviar_el_intento_de_otra_persona_es_un_conflicto(): void
    {
        $recibo = $this->reciboDeGimnasio();
        $super = $this->adminAs(Admin::ROLE_SUPER_ADMIN);
        $intento = (string) Str::uuid();

        $this->omitir($recibo['uuid'], $super, $intento)->assertOk()->assertJsonPath('data.status', 'skipped');

        $filas = ReceiptPrint::count();
        $this->omitir($recibo['uuid'], $this->h, $intento)->assertStatus(409)->assertJsonPath('code', 'attempt_conflict');
        $this->iniciar($recibo['uuid'], intento: $intento)->assertStatus(409)->assertJsonPath('code', 'attempt_conflict');
        $this->assertSame($filas, ReceiptPrint::count());
        // El mismo actor sí repite: idempotente.
        $this->omitir($recibo['uuid'], $super, $intento)->assertOk();
    }

    /** Revisión de seguridad (S3): el original es la tirilla del momento del cobro. */
    public function test_un_checkout_tardio_sale_como_reimpresion_y_nunca_como_original(): void
    {
        $recibo = $this->reciboDeGimnasio();
        Carbon::setTestNow(Carbon::now()->addMinutes(11));

        $this->iniciar($recibo['uuid'])->assertCreated()
            ->assertJsonPath('data.kind', 'reprint')
            ->assertJsonPath('data.copy_label', 'REIMPRESIÓN');
        $this->assertSame(0, ReceiptPrint::where('kind', 'original')->count());
    }

    public function test_una_operacion_anulada_nunca_sale_como_original(): void
    {
        $recibo = $this->reciboDeGimnasio();
        Payment::query()->update(['status' => 'cancelled']);

        $this->iniciar($recibo['uuid'])->assertCreated()
            ->assertJsonPath('data.kind', 'reprint')
            ->assertJsonPath('data.copy_label', 'REIMPRESIÓN');
    }

    /** Revisión de seguridad (S1): la lista se corta ANTES de validar elemento a elemento. */
    public function test_mas_de_50_abonos_se_rechazan_sin_validar_la_lista(): void
    {
        $inicio = microtime(true);
        $this->emitir('receivable_payment', range(1, 20000))->assertStatus(422)->assertJsonValidationErrors('source_ids');
        $this->assertLessThan(1.0, microtime(true) - $inicio);
        $this->emitir('receivable_payment', range(1, 51))->assertStatus(422)->assertJsonValidationErrors('source_ids');
    }

    public function test_el_token_de_automatizaciones_no_es_nadie(): void
    {
        $recibo = $this->reciboDeGimnasio();
        $pago = Payment::firstOrFail();
        $token = $this->sharedTokenHeaders();

        $this->emitir('payment', $pago->id, $token)->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->getJson("/api/admin/receipts/{$recibo['uuid']}", $token)->assertStatus(403);
        $this->iniciar($recibo['uuid'], h: $token)->assertStatus(403);
        $this->omitir($recibo['uuid'], $token)->assertStatus(403);
        $this->getJson('/api/admin/caja/shifts/'.$pago->cash_shift_id.'/receipts', $token)->assertStatus(403);

        $this->assertSame(0, ReceiptPrint::count(), 'sin persona no queda ni el intento');
    }

    public function test_un_entrenador_no_toca_recibos(): void
    {
        $recibo = $this->reciboDeGimnasio();
        $pago = Payment::firstOrFail();
        $entrenador = $this->adminAs(CrmPermission::ROLE_ENTRENADOR);

        $this->emitir('payment', $pago->id, $entrenador)->assertStatus(403);
        $this->getJson("/api/admin/receipts/{$recibo['uuid']}", $entrenador)->assertStatus(403);
        $this->omitir($recibo['uuid'], $entrenador)->assertStatus(403);
        $this->assertSame(0, ReceiptPrint::count());
    }

    public function test_sin_sesion_no_se_pasa_de_la_puerta(): void
    {
        $this->postJson('/api/admin/receipts', ['source_type' => 'payment', 'source_id' => 1])->assertStatus(401);
        $this->getJson('/api/admin/receipts/'.Str::uuid())->assertStatus(401);
    }

    // ── La vista del turno ──────────────────────────────────────────────────

    public function test_la_vista_del_turno_distingue_cada_estado(): void
    {
        $plan = $this->plan();
        $super = $this->adminHeaders();

        $impreso = $this->cobrar($this->socio('Impreso'), $plan);
        $omitido = $this->cobrar($this->socio('Omitido'), $plan);
        $fallido = $this->cobrar($this->socio('Fallido'), $plan);
        $sinRecibo = $this->cobrar($this->socio('Sin recibo'), $plan);
        $anulado = $this->cobrar($this->socio('Anulado'), $plan);
        $this->putJson("/api/payments/{$anulado->id}", ['status' => 'cancelled'], $super)->assertOk();
        [$devengo, $primerAbono] = $this->venderAPlazos($this->socio('Plazos'), $this->plan('Anual', 200000), 50000);

        $r = fn (Payment $p) => $this->emitir('payment', $p->id)->assertStatus(201)->json('data.0.uuid');
        $uImpreso = $r($impreso);
        $this->cerrar($uImpreso, $this->iniciar($uImpreso)->json('data.client_attempt_id'), 'sent')->assertOk();
        $this->omitir($r($omitido), $super)->assertOk();
        $uFallido = $r($fallido);
        $this->cerrar($uFallido, $this->iniciar($uFallido)->json('data.client_attempt_id'), 'error')->assertOk();

        $turno = $impreso->cash_shift_id;
        $antes = $this->conteosDeNegocio();
        $res = $this->getJson("/api/admin/caja/shifts/{$turno}/receipts", $this->h)->assertOk()->assertJsonPath('ok', true);
        $this->assertSame($antes, $this->conteosDeNegocio());

        $filas = collect($res->json('data'))->keyBy(fn ($f) => (string) $f['row_id']);

        $this->assertSame('printed', $filas[(string) $impreso->id]['print_state']);
        $this->assertSame('skipped', $filas[(string) $omitido->id]['print_state']);
        $this->assertSame('error', $filas[(string) $fallido->id]['print_state']);
        $this->assertSame('pending', $filas[(string) $sinRecibo->id]['print_state']);
        $this->assertNull($filas[(string) $sinRecibo->id]['receipt']);
        $this->assertTrue($filas[(string) $sinRecibo->id]['eligible']);

        $this->assertFalse($filas[(string) $anulado->id]['eligible']);
        $this->assertSame('cancelled', $filas[(string) $anulado->id]['reason']);
        $this->assertNull($filas[(string) $anulado->id]['print_state']);

        // El devengo del plan a plazos nace SIN turno: no es dinero y no sale en
        // el arqueo, así que tampoco aquí. Su primer abono sí, con la misma clave
        // de fila que usa el informe.
        $this->assertNull($devengo->cash_shift_id);
        $this->assertArrayNotHasKey((string) $devengo->id, $filas->all());
        $this->assertSame('receivable_payment', $filas['abono-'.$primerAbono->id]['source_type']);
        $this->assertTrue($filas['abono-'.$primerAbono->id]['eligible']);

        $impresa = $filas[(string) $impreso->id];
        $this->assertSame(['uuid' => $uImpreso, 'number' => Receipt::where('uuid', $uImpreso)->value('number')], $impresa['receipt']);
        $this->assertSame(['row_id', 'in_report', 'display', 'source_type', 'source_id', 'eligible', 'reason', 'receipt', 'print_state'], array_keys($impresa));
        // En el gimnasio todo sale en «Operaciones del turno», abonos incluidos.
        $this->assertTrue($impresa['in_report']);
        $this->assertTrue($filas['abono-'.$primerAbono->id]['in_report']);
        $this->assertEquals(50000, $filas['abono-'.$primerAbono->id]['display']['total']);

        $this->assertSame([
            'pending' => 2,        // el cobro sin recibo y el primer abono
            'printed' => 1,
            'skipped' => 1,
            'error' => 1,
            'not_eligible' => 1,   // el anulado
        ], $res->json('summary'));

        // Y las filas son las mismas del informe del turno: mismo id, misma fuente.
        $informe = $this->getJson("/api/admin/caja/shifts/{$turno}", $this->h)->assertOk()->json('transactions');
        $this->assertEqualsCanonicalizing(
            array_map(fn ($t) => [(string) $t['id'], $t['source_type'], $t['source_id']], $informe),
            array_map(fn ($f) => [(string) $f['row_id'], $f['source_type'], $f['source_id']], $res->json('data')),
        );
    }

    public function test_la_vista_de_productos_incluye_los_abonos_que_el_informe_no_lista(): void
    {
        $socio = $this->socio('Fiado');
        $agua = $this->producto();
        $venta = $this->vender([['product_id' => $agua->id, 'quantity' => 1]]);
        $credito = $this->fiar($socio, $agua);
        $this->postJson("/api/admin/receivables/people/member/{$socio->id}/settle", ['method' => 'cash'], $this->h)
            ->assertStatus(201);
        $abono = ReceivablePayment::firstOrFail();

        $turno = $venta->cash_shift_id;
        $this->assertSame($turno, $abono->cash_shift_id, 'el abono de un fiado entra en la caja de productos');

        $filas = collect($this->getJson("/api/admin/caja/shifts/{$turno}/receipts", $this->h)->assertOk()->json('data'))
            ->keyBy(fn ($f) => (string) $f['row_id']);

        $this->assertSame('product_sale', $filas[(string) $venta->id]['source_type']);
        $this->assertTrue($filas[(string) $credito->id]['eligible'], 'la entrega a crédito lleva comprobante');
        $this->assertSame('receivable_payment', $filas['abono-'.$abono->id]['source_type']);
        // No sale en el informe de productos: llega marcado y con lo necesario
        // para que el CRM lo liste aparte y se pueda reimprimir.
        $this->assertFalse($filas['abono-'.$abono->id]['in_report']);
        $this->assertTrue($filas[(string) $venta->id]['in_report']);
        $this->assertSame('Fiado', $filas['abono-'.$abono->id]['display']['customer']);
        $this->assertEquals((float) $abono->amount, $filas['abono-'.$abono->id]['display']['total']);
        $this->assertNotNull($filas['abono-'.$abono->id]['display']['at']);

        $informe = $this->getJson("/api/admin/caja/shifts/{$turno}", $this->h)->assertOk()->json('transactions');
        $this->assertNotContains('abono-'.$abono->id, array_column($informe, 'id'),
            'el informe de productos sigue sin listar abonos: esto es aditivo');
        $this->assertSame('product_sale', $informe[0]['source_type']);
        $this->assertSame($venta->id, $informe[0]['source_id']);
    }

    // ── Nada de esto toca el dinero ─────────────────────────────────────────

    public function test_ninguna_llamada_de_recibos_mueve_dinero_inventario_ni_facturas(): void
    {
        $super = $this->adminHeaders();
        $pago = $this->cobrar($this->socio(), $this->plan());
        $venta = $this->vender([['product_id' => $this->producto()->id, 'quantity' => 3]]);
        $credito = $this->fiar($this->socio('Fiado'), $this->producto('Café', 2500));

        $antes = $this->conteosDeNegocio();

        $uPago = $this->emitir('payment', $pago->id)->assertStatus(201)->json('data.0.uuid');
        $uVenta = $this->emitir('product_sale', $venta->id)->assertStatus(201)->json('data.0.uuid');
        $this->emitir('product_sale', $credito->id)->assertStatus(201);
        $this->emitir('payment', $pago->id)->assertStatus(200);
        $this->getJson("/api/admin/receipts/{$uPago}", $this->h)->assertOk();
        $i = $this->iniciar($uPago)->json('data.client_attempt_id');
        $this->cerrar($uPago, $i, 'sent')->assertOk();
        $this->iniciar($uPago, 'shift_report')->assertStatus(201);
        $this->omitir($uVenta, $this->h)->assertStatus(403);
        $this->omitir($uVenta, $super)->assertOk();
        $this->getJson("/api/admin/caja/shifts/{$pago->cash_shift_id}/receipts", $this->h)->assertOk();
        $this->getJson("/api/admin/caja/shifts/{$venta->cash_shift_id}/receipts", $this->h)->assertOk();

        $this->assertSame($antes, $this->conteosDeNegocio());
    }
}
