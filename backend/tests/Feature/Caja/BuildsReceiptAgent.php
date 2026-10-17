<?php

namespace Tests\Feature\Caja;

use App\Models\Admin;
use App\Models\ReceiptStation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * El servicio local de impresión visto desde fuera, como lo usan el CRM y el
 * servicio de Windows: por HTTP y con el contrato tal cual
 * (CONTRATO_AGENTE_IMPRESION.md §4–§5).
 *
 * Las llamadas a /api/receipt-agent/* van SIN ninguna cabecera de
 * autenticación: el servicio no tiene sesión, solo el ticket.
 */
trait BuildsReceiptAgent
{
    /** Un equipo registrado. Se crea directo: es tabla propia, no dinero. */
    protected function estacion(array $attrs = []): ReceiptStation
    {
        return ReceiptStation::create(array_merge([
            'station_key' => (string) Str::uuid(),
            'name' => 'Recepción',
            'printer_name' => 'POS-58',
        ], $attrs));
    }

    /** POST /receipts/{uuid}/prints en modo agent. */
    protected function imprimirConAgente(string $uuid, ReceiptStation|string $estacion, string $contexto = 'checkout', ?string $intento = null, ?array $h = null): TestResponse
    {
        return $this->postJson("/api/admin/receipts/{$uuid}/prints", [
            'client_attempt_id' => $intento ?? (string) Str::uuid(),
            'context' => $contexto,
            'print_mode' => 'agent',
            'station_key' => $estacion instanceof ReceiptStation ? $estacion->station_key : $estacion,
        ], $h ?? $this->h);
    }

    /** Lo que manda el servicio en `agent`. */
    protected function agente(array $extra = []): array
    {
        return array_merge([
            'version' => '1.0.0',
            'protocol' => 1,
            'printer_name' => 'POS-58',
            'columns' => 32,
            'codepage' => 'cp850',
        ], $extra);
    }

    protected function canjear(?string $ticket, ReceiptStation|string $estacion, array $agente = []): TestResponse
    {
        return $this->postJson('/api/receipt-agent/redeem', [
            'ticket' => $ticket,
            'station_key' => $estacion instanceof ReceiptStation ? $estacion->station_key : $estacion,
            'agent' => $this->agente($agente),
        ]);
    }

    protected function reportar(?string $ticket, ReceiptStation|string $estacion, string $outcome, array $extra = []): TestResponse
    {
        return $this->postJson('/api/receipt-agent/report', array_merge([
            'ticket' => $ticket,
            'station_key' => $estacion instanceof ReceiptStation ? $estacion->station_key : $estacion,
            'outcome' => $outcome,
            'error_code' => null,
            'message' => null,
            'spool_job_id' => 12,
            'printer_name' => 'POS-58',
        ], $extra));
    }

    /** Ticket de prueba o configuración emitido por alguien que supervisa caja. */
    protected function ticketDeEstacion(ReceiptStation $estacion, string $proposito = 'test', ?string $variante = null, ?array $h = null): TestResponse
    {
        return $this->postJson("/api/admin/receipt-stations/{$estacion->station_key}/tickets", array_filter([
            'purpose' => $proposito,
            'variant' => $variante,
        ]), $h ?? $this->supervisor());
    }

    /** Administrador: supervisa las dos cajas por defecto. */
    protected function supervisor(): array
    {
        return $this->actingAsAdmin(Admin::create([
            'name' => 'Marta Supervisora',
            'email' => 'super-caja-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_ADMINISTRADOR,
            'status' => 'active',
        ]));
    }

    /**
     * Las tablas de dinero, con SUMAS además de conteos y huella: el flujo del
     * servicio local no puede mover un peso.
     *
     * @return array<string, mixed>
     */
    protected function dineroYStock(): array
    {
        return $this->conteosDeNegocio() + [
            'suma_payments' => (string) DB::table('payments')->sum('amount'),
            'suma_product_sales' => (string) DB::table('product_sales')->sum('total'),
            'suma_receivable_payments' => (string) DB::table('receivable_payments')->sum('amount'),
            'suma_inventory_movements' => (string) DB::table('inventory_movements')->sum('quantity'),
            'suma_stock' => (string) DB::table('products')->sum('stock'),
            'suma_cash_shifts' => (string) DB::table('cash_shifts')->sum('sales_total'),
            'electronic_invoices' => DB::table('electronic_invoices')->orderBy('id')->get()->toJson(),
            'receipts' => DB::table('receipts')->orderBy('id')->get(['id', 'number', 'total', 'snapshot'])->toJson(),
        ];
    }
}
