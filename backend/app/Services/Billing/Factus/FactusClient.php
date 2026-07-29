<?php

namespace App\Services\Billing\Factus;

use App\Services\Billing\TaxPolicy;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Único punto de salida HTTP hacia Factus V2. Toda llamada a Factus pasa por
 * aquí (jamás desde front/app). Inyecta el Bearer del FactusTokenManager,
 * aplica timeouts de config y, ante un 401, refresca el token y reintenta UNA
 * vez. Reintentos automáticos los gobierna el job (con guardas de idempotencia).
 *
 * Rutas confirmadas contra la colección oficial (docs/factus/factus-v2.postman):
 *   POST   /v2/bills/validate
 *   GET    /v2/bills/{number}
 *   GET    /v2/bills/{number}/download-pdf | download-xml
 *   GET    /v2/bills/{number}/radian/events
 *   POST   /v2/credit-notes/validate
 *   GET    /v2/numbering-ranges
 * La consulta y descargas son por NÚMERO (full_number), no por id interno.
 */
class FactusClient
{
    private const PATH_CREATE_INVOICE = '/v2/bills/validate';
    private const PATH_BILL           = '/v2/bills/';            // + {number}[/download-pdf|/download-xml|/radian/events]
    private const PATH_CREATE_CREDIT  = '/v2/credit-notes/validate';
    private const PATH_CREDIT         = '/v2/credit-notes/';     // + {number}[/download-pdf|/download-xml]
    private const PATH_NUMBERING       = '/v2/numbering-ranges';

    public function __construct(
        private FactusTokenManager $tokens,
        private array $cfg,
    ) {
    }

    public static function make(): self
    {
        return new self(FactusTokenManager::fromConfig(), (array) config('billing'));
    }

    /** Emite (valida) una factura electrónica. POST — no se reintenta a ciegas. */
    /**
     * Emite una factura de venta.
     *
     * Antes de salir a la red el payload pasa por {@see normalizeTaxes()}: este
     * es el embudo ÚNICO por el que viaja cualquier comprobante, así que aquí se
     * garantiza que ningún documento declare una tarifa de IVA que Iron Body
     * —responsabilidad 49, no responsable— no cobra.
     *
     * Motivo: el constructor del DTO ya calculaba importe de IVA = 0, pero
     * seguía leyendo la tasa CRUDA del catálogo para decidir `taxes[]`, con lo
     * que habría emitido un documento declarando 19 % con importe cero.
     */
    public function createInvoice(array $payload): array
    {
        return $this->send('post', self::PATH_CREATE_INVOICE, $this->normalizeTaxes($payload));
    }

    /**
     * Ajusta `items[].taxes` a la política fiscal del emisor.
     *
     * Con emisor NO responsable, cada línea se declara sin tributo
     * (`is_excluded`) en lugar de con una tarifa positiva. NO se tocan importes:
     * si un total llevara IVA, {@see assertNoVatAmounts()} aborta en vez de
     * maquillarlo — corregir cifras en silencio produciría un documento legal
     * cuyo origen nadie podría explicar.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function normalizeTaxes(array $payload): array
    {
        $policy = app(TaxPolicy::class);

        if ($policy->collectsVat() || empty($payload['items'])) {
            return $payload;
        }

        foreach (array_keys($payload['items']) as $i) {
            $payload['items'][$i]['taxes'] = [['is_excluded' => true]];
        }

        $this->assertNoVatAmounts($payload, $policy);

        return $payload;
    }

    /**
     * Última barrera antes del HTTP: ningún total del payload puede llevar IVA.
     *
     * @param  array<string,mixed>  $payload
     */
    private function assertNoVatAmounts(array $payload, TaxPolicy $policy): void
    {
        foreach (['tax_amount', 'total_tax', 'taxes_amount'] as $key) {
            if (isset($payload[$key])) {
                $policy->assertNoVat($payload[$key], 'payload de factura');
            }
        }
    }

    /** Emite una nota crédito. POST. */
    public function createCreditNote(array $payload): array
    {
        return $this->send('post', self::PATH_CREATE_CREDIT, $payload);
    }

    /** Consulta una factura por su NÚMERO (para reconciliación). GET. */
    public function getInvoice(string $number): array
    {
        return $this->send('get', self::PATH_BILL . rawurlencode($number));
    }

    /** Eventos DIAN/RADIAN de la factura (estado). GET. */
    public function getInvoiceEvents(string $number): array
    {
        return $this->send('get', self::PATH_BILL . rawurlencode($number) . '/radian/events');
    }

    /** Descarga el PDF de la factura por número (respuesta con base64). GET. */
    public function downloadPdf(string $number): array
    {
        return $this->send('get', self::PATH_BILL . rawurlencode($number) . '/download-pdf');
    }

    /** Descarga el XML UBL de la factura por número. GET. */
    public function downloadXml(string $number): array
    {
        return $this->send('get', self::PATH_BILL . rawurlencode($number) . '/download-xml');
    }

    /** Descarga el PDF de una nota crédito por número. GET. */
    public function downloadCreditNotePdf(string $number): array
    {
        return $this->send('get', self::PATH_CREDIT . rawurlencode($number) . '/download-pdf');
    }

    /** Lista de rangos de numeración configurados en la cuenta. GET. */
    public function getNumberingRanges(): array
    {
        return $this->send('get', self::PATH_NUMBERING);
    }

    /** Elimina (sandbox) una factura por su reference_code. DELETE. */
    public function destroyByReference(string $referenceCode): array
    {
        return $this->send('delete', self::PATH_BILL . 'destroy/reference/' . rawurlencode($referenceCode));
    }
    /** Lectura genérica de catálogos (si se necesitan en vivo). GET. */
    public function catalog(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /**
     * Ejecuta la petición y normaliza la respuesta. Ante 401 refresca el token y
     * reintenta UNA vez. Nunca lanza: devuelve un resultado estructurado para que
     * el job decida estado/reintento sin romper el flujo de pago.
     *
     * Clasificación de errores (campo error_class):
     *   auth (401) · conflict (409) · validation (4xx/422) · rate_limit (429) ·
     *   server (5xx) · network (0) · ok.
     *
     * @return array{ok:bool,status:int,body:array,error:?string,error_class:string,retry_after:?int}
     */
    private function send(string $method, string $path, array $data = [], bool $reauthed = false): array
    {
        $response = $this->dispatch($method, $path, $data);
        $status = $response->status();

        if ($status === 401 && ! $reauthed) {
            $this->tokens->forget();
            return $this->send($method, $path, $data, reauthed: true);
        }

        return [
            'ok'          => $response->successful(),
            'status'      => $status,
            'body'        => $this->safeJson($response),
            'error'       => $response->successful() ? null : ('HTTP ' . $status),
            'error_class' => $this->classify($status),
            'retry_after' => $this->retryAfter($response),
        ];
    }

    private function classify(int $status): string
    {
        return match (true) {
            $status >= 200 && $status < 300 => 'ok',
            $status === 401                 => 'auth',
            $status === 409                 => 'conflict',
            $status === 429                 => 'rate_limit',
            $status >= 400 && $status < 500 => 'validation',
            $status >= 500                  => 'server',
            default                         => 'network',
        };
    }

    private function retryAfter(Response $response): ?int
    {
        $h = $response->header('Retry-After');
        return is_numeric($h) ? (int) $h : null;
    }

    private function dispatch(string $method, string $path, array $data): Response
    {
        $request = $this->base();

        return match ($method) {
            'get'    => $request->get($path, $data),
            'delete' => $request->delete($path, $data),
            default  => $request->post($path, $data),
        };
    }

    private function base(): PendingRequest
    {
        $http = (array) ($this->cfg['http'] ?? []);

        return Http::baseUrl((string) ($this->cfg['base_url'] ?? ''))
            ->timeout((int) ($http['timeout'] ?? 30))
            ->connectTimeout((int) ($http['connect_timeout'] ?? 10))
            ->withToken($this->tokens->accessToken())
            ->acceptJson();
    }

    private function safeJson(Response $response): array
    {
        try {
            $json = $response->json();
            return is_array($json) ? $json : ['raw' => $response->body()];
        } catch (\Throwable) {
            return ['raw' => $response->body()];
        }
    }
}
