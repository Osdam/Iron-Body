<?php

namespace App\Console\Commands;

use App\Services\Billing\Factus\FactusClient;
use App\Services\Billing\SandboxProbe;
use App\Services\Billing\TaxPolicy;
use Illuminate\Console\Command;

/**
 * Ensaya la representación tributaria contra el ambiente SANDBOX de Factus.
 *
 * Por qué existe: el contador confirmó que las membresías son EXENTAS de IVA y
 * Factus confirmó que un exento se declara como IVA al 0 %
 * (`[{code:'01', rate:'0.00'}]`). Antes de emitir un solo documento real hay
 * que comprobar contra el proveedor que ese contrato valida y que el
 * comprobante devuelto conserva el precio comercial: $80.000 → base 80.000,
 * IVA 0, total 80.000. Ese ensayo hay que poder repetirlo sin arriesgar un
 * documento real.
 *
 * El comando se niega a ejecutarse contra producción por tres vías
 * independientes: el ambiente declarado, el endpoint configurado y el rango de
 * numeración. Cualquiera de las tres aborta.
 *
 * No crea ninguna fila en `electronic_invoices`: la referencia lleva el prefijo
 * de sonda y no representa una venta.
 */
class BillingTestFactusSandboxCommand extends Command
{
    protected $signature = 'billing:test-factus-sandbox
        {--amount=80000 : Importe comercial de prueba}
        {--emit : Envía realmente el payload a sandbox. Sin esta opción solo se construye y verifica}';

    protected $description = 'Ensaya en SANDBOX el desglose sin IVA. Se niega a correr contra producción.';

    private const PRODUCTION_HOST = 'api.factus.com.co';

    public function handle(TaxPolicy $policy, FactusClient $client): int
    {
        if (($code = $this->assertSandbox()) !== self::SUCCESS) {
            return $code;
        }

        $amount = (int) round(((float) $this->option('amount')) * 100);
        $reference = SandboxProbe::reference();
        $payload = $this->buildPayload($amount, $reference);

        $this->info('PAYLOAD DE PRUEBA (sandbox)');
        $this->line('  referencia   : '.$reference);
        $this->line('  rango        : '.($payload['numbering_range_id'] ?? '—'));
        $this->line('  precio ítem  : '.$payload['items'][0]['price']);
        $this->line('');

        if (($code = $this->assertPayloadInvariants($payload, $amount, $policy)) !== self::SUCCESS) {
            return $code;
        }

        if (! $this->option('emit')) {
            $this->line('');
            $this->info('Solo verificación. Usa --emit para enviarlo a sandbox.');

            return self::SUCCESS;
        }

        return $this->emit($client, $payload, $reference, $amount);
    }

    // ── Negativa a tocar producción ───────────────────────────────────────

    private function assertSandbox(): int
    {
        $env = strtolower((string) config('billing.env', 'sandbox'));
        $baseUrl = strtolower((string) config('billing.base_url', ''));
        $range = (string) config('billing.numbering.range_id', '');

        $problems = [];

        if ($env === 'production') {
            $problems[] = 'el ambiente declarado es «production» (billing.env)';
        }

        if (str_contains($baseUrl, self::PRODUCTION_HOST)) {
            $problems[] = "el endpoint configurado es «{$baseUrl}»";
        }

        // El rango 2076 es el productivo de Iron Body. Si aparece aquí, la
        // configuración está mezclada aunque las otras dos señales digan sandbox.
        if ($range === '2076') {
            $problems[] = 'el rango de numeración configurado es el productivo (2076)';
        }

        if ($problems !== []) {
            $this->error('Este comando NO se ejecuta contra producción.');
            foreach ($problems as $p) {
                $this->line('  · '.$p);
            }
            $this->line('');
            $this->line('  Las pruebas tributarias no se hacen contra la DIAN: un documento');
            $this->line('  emitido allí consume consecutivo y no se puede deshacer.');

            return self::FAILURE;
        }

        $this->line('  ambiente     : sandbox ✓');
        $this->line('  endpoint     : '.$baseUrl.' ✓');
        $this->line('');

        return self::SUCCESS;
    }

    // ── Construcción ──────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function buildPayload(int $amountCents, string $reference): array
    {
        $defaults = (array) config('billing.defaults', []);
        $price = number_format($amountCents / 100, 2, '.', '');

        return [
            'document' => (string) ($defaults['document'] ?? '01'),
            'operation_type' => (string) ($defaults['operation_type'] ?? '10'),
            'numbering_range_id' => (int) config('billing.numbering.range_id'),
            'reference_code' => $reference,
            'send_email' => false,
            'observation' => 'PRUEBA DE SANDBOX — no corresponde a ninguna venta.',
            'payment_details' => [[
                'payment_form' => (int) ($defaults['payment_form'] ?? 1),
                'payment_method_code' => (string) ($defaults['payment_method_code'] ?? '10'),
                'amount' => $price,
            ]],
            'customer' => [
                'identification' => (string) config('billing.consumer_final.document_number'),
                'legal_organization_code' => (string) ($defaults['legal_organization_code'] ?? '2'),
                'tribute_code' => (string) ($defaults['tribute_code'] ?? 'ZZ'),
                'municipality_code' => (string) ($defaults['municipality_code'] ?? ''),
                'names' => 'PRUEBA SANDBOX',
            ],
            'items' => [[
                'code_reference' => 'SANDBOX-PROBE',
                'name' => 'Servicio de prueba (sandbox)',
                'quantity' => 1,
                'price' => $price,
                'discount_rate' => 0,
                'unit_measure_code' => (string) ($defaults['unit_measure_code'] ?? '94'),
                'standard_code' => (string) ($defaults['standard_code'] ?? '999'),
                // Deliberadamente SIN bloque `taxes`: lo fija la política en
                // FactusClient::normalizeTaxes() (exento, IVA al 0 %). Así la
                // sonda prueba el MISMO camino que una factura real, en vez de
                // un payload escrito a mano que podría no coincidir.
            ]],
        ];
    }

    // ── Verificación del contrato exigido ─────────────────────────────────

    /** @param  array<string,mixed>  $payload */
    private function assertPayloadInvariants(array $payload, int $amountCents, TaxPolicy $policy): int
    {
        $price = number_format($amountCents / 100, 2, '.', '');
        $json = json_encode($payload) ?: '';
        // El valor que produciría extraer el 19 %: no debe aparecer.
        $extracted = number_format(round($amountCents / 1.19) / 100, 2, '.', '');

        // Bloque de tributos que la política inyectará en cada ítem al salir por
        // FactusClient::normalizeTaxes(). Se verifica aquí, antes de enviar.
        $taxes = $policy->itemTaxes();
        $tax = $taxes[0] ?? [];

        $checks = [
            'precio = '.$price => $payload['items'][0]['price'] === $price,
            'subtotal = '.$price => ($payload['subtotal'] ?? $price) === $price,
            'IVA = 0' => $policy->effectiveBasisPoints(null) === 0,
            'total = '.$price => ($payload['total'] ?? $price) === $price,
            'tratamiento EXENTO' => $policy->itemTaxTreatment() === TaxPolicy::TREATMENT_EXEMPT,
            'tributo declarado code=01' => ($tax['code'] ?? null) === '01',
            'tarifa declarada 0.00' => ($tax['rate'] ?? null) === '0.00',
            'sin tarifa 19' => ! str_contains($json, '19.00'),
            'sin extracción de IVA (≠ '.$extracted.')' => ! str_contains($json, $extracted),
            'sin is_excluded' => ! str_contains($json, 'is_excluded')
                && ! array_key_exists('is_excluded', $tax),
            'referencia marcada como prueba' => str_starts_with(
                (string) $payload['reference_code'], SandboxProbe::REFERENCE_PREFIX,
            ),
        ];

        $failed = 0;

        $this->info('CONTRATO EXIGIDO');
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('  [%s] %s', $ok ? 'OK' : 'FALLA', $label));
            $failed += $ok ? 0 : 1;
        }

        if ($failed > 0) {
            $this->error("{$failed} comprobaciones fallaron. No se envía nada.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Aplana la respuesta de error a pares «campo: mensaje».
     *
     * La API anida los errores de validación a profundidad variable
     * (`data.items.0.taxes`), así que se recorre en vez de asumir una forma.
     *
     * @param  array<mixed>  $node
     * @return array<string,string>
     */
    private function flattenErrors(array $node, string $prefix = ''): array
    {
        $out = [];

        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $out += $this->flattenErrors($value, $path);

                continue;
            }

            if (is_scalar($value) && (string) $value !== '') {
                $out[$path] = (string) $value;
            }
        }

        return $out;
    }

    // ── Emisión en sandbox ────────────────────────────────────────────────

    /** @param  array<string,mixed>  $payload */
    private function emit(FactusClient $client, array $payload, string $reference, int $amountCents): int
    {
        $this->line('');
        $this->info('ENVIANDO A SANDBOX…');

        $result = SandboxProbe::run(fn () => $client->createInvoice($payload));

        if (! $result['ok']) {
            $this->line('');
            $this->error('Sandbox rechazó el documento — HTTP '.$result['status']);

            foreach ($this->flattenErrors((array) ($result['body'] ?? [])) as $field => $message) {
                $this->line("  {$field}: {$message}");
            }

            $this->line('');
            $this->line('  Este rechazo es el hallazgo, no un fallo del comando: significa que');
            $this->line('  el contrato tributario ensayado NO es el que el proveedor acepta.');
            $this->line('  No se emitió en producción y no se tocó la DIAN.');

            return self::FAILURE;
        }

        $data = $result['body']['data'] ?? [];
        $number = $data['number'] ?? ($data['bill']['number'] ?? null);
        $totals = $data['totals'] ?? [];

        $this->line('');
        $this->info('ACEPTADO EN SANDBOX');
        $this->line('  número      : '.($number ?? '—'));
        $this->line('  base        : '.($totals['taxable_amount'] ?? '—'));
        $this->line('  IVA         : '.($totals['tax_amount'] ?? '—'));
        $this->line('  total       : '.($totals['total'] ?? '—'));

        // Representación del tributo TAL COMO LA REGISTRÓ el proveedor. Es la
        // comprobación que importa: no basta con que el total cuadre, el
        // documento debe declarar IVA 0 % y NO estar marcado como excluido.
        $tax = $data['taxes'][0] ?? ($data['items'][0]['taxes'][0] ?? []);
        $rate = $tax['rates'][0]['rate'] ?? ($tax['rate'] ?? null);
        $tributeCode = $tax['tribute']['code'] ?? ($tax['code'] ?? null);
        $isExcluded = array_key_exists('is_excluded', $tax) ? (bool) $tax['is_excluded'] : null;

        $this->line('  tributo     : '.($tributeCode ?? '—'));
        $this->line('  tarifa      : '.($rate ?? '—').' %');
        $this->line('  is_excluded : '.($isExcluded === null ? '— (ausente)' : var_export($isExcluded, true)));
        $this->line('  CUFE        : '.($data['cufe'] ?? '—'));

        $expected = number_format($amountCents / 100, 2, '.', '');
        $taxCents = (int) round(((float) ($totals['tax_amount'] ?? 0)) * 100);

        $amountsOk = $taxCents === 0
            && (string) ($totals['taxable_amount'] ?? '') === $expected
            && (string) ($totals['total'] ?? '') === $expected;

        // El proveedor puede omitir `is_excluded`; lo inaceptable es true.
        $treatmentOk = $isExcluded !== true
            && ($rate === null || (float) $rate === 0.0);

        $ok = $amountsOk && $treatmentOk;

        $this->line('');
        $this->line(sprintf('  [%s] el documento devuelto conserva el precio comercial con IVA 0',
            $amountsOk ? 'OK' : 'FALLA'));
        $this->line(sprintf('  [%s] tratamiento EXENTO (tarifa 0 %%) y NO excluido',
            $treatmentOk ? 'OK' : 'FALLA'));

        // Consulta del documento, su PDF y su XML, para dejar constancia de lo
        // que realmente quedó registrado (no de lo que creemos haber enviado).
        if ($number !== null) {
            $check = $client->getInvoice((string) $number);
            $this->line('  GET documento  -> HTTP '.$check['status']);

            $pdf = $client->downloadPdf((string) $number);
            $this->line('  GET PDF        -> HTTP '.$pdf['status']);

            $xml = $client->downloadXml((string) $number);
            $this->line('  GET XML        -> HTTP '.$xml['status']);
        }

        $this->line('');
        $this->line('  Documento de SANDBOX: no tiene validez fiscal y no afecta a la');
        $this->line('  numeración productiva. Referencia: '.$reference);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
