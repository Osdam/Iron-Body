<?php

namespace App\Services\Billing\Factus;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Único punto de salida HTTP hacia Factus V2. Toda llamada a Factus pasa por
 * aquí (jamás desde front/app). Inyecta el Bearer del FactusTokenManager,
 * aplica timeouts de config y, ante un 401, refresca el token y reintenta UNA
 * vez. Reintentos automáticos SOLO en GET (idempotente); la emisión (POST) no
 * se reintenta a ciegas: de eso se encarga el job con guardas de idempotencia.
 *
 * Las RUTAS de los endpoints (validate/show/credit-notes/...) deben confirmarse
 * contra la colección/doc de Factus V2 y viven centralizadas aquí.
 */
class FactusClient
{
    // Rutas a confirmar contra la colección oficial de Factus V2.
    private const PATH_CREATE_INVOICE = '/v1/bills/validate';
    private const PATH_SHOW_INVOICE   = '/v1/bills/show/';         // + {id}
    private const PATH_DOWNLOAD_PDF   = '/v1/bills/download-pdf/'; // + {number}
    private const PATH_DOWNLOAD_XML   = '/v1/bills/download-xml/'; // + {number}
    private const PATH_CREATE_CREDIT  = '/v1/credit-notes/validate';

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
    public function createInvoice(array $payload): array
    {
        return $this->send('post', self::PATH_CREATE_INVOICE, $payload);
    }

    /** Emite una nota crédito. POST. */
    public function createCreditNote(array $payload): array
    {
        return $this->send('post', self::PATH_CREATE_CREDIT, $payload);
    }

    /** Consulta el estado/detalle de una factura (para reconciliación). GET. */
    public function getInvoice(string $id): array
    {
        return $this->send('get', self::PATH_SHOW_INVOICE . $id);
    }

    /** Descarga el PDF (la respuesta puede traer base64 o una URL). GET. */
    public function downloadPdf(string $number): array
    {
        return $this->send('get', self::PATH_DOWNLOAD_PDF . $number);
    }

    /** Descarga el XML UBL. GET. */
    public function downloadXml(string $number): array
    {
        return $this->send('get', self::PATH_DOWNLOAD_XML . $number);
    }

    /** Lectura de catálogos (municipios, tributos, formas de pago, ...). GET. */
    public function catalog(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query);
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /**
     * Ejecuta la petición y normaliza la respuesta. Ante 401 refresca el token
     * y reintenta UNA vez (el token pudo expirar). Nunca lanza: devuelve un
     * resultado estructurado para que el job decida estado/reintento sin romper
     * el flujo de pago.
     */
    private function send(string $method, string $path, array $data = [], bool $reauthed = false): array
    {
        $response = $this->dispatch($method, $path, $data);

        if ($response->status() === 401 && ! $reauthed) {
            $this->tokens->forget();
            return $this->send($method, $path, $data, reauthed: true);
        }

        return [
            'ok'     => $response->successful(),
            'status' => $response->status(),
            'body'   => $this->safeJson($response),
            'error'  => $response->successful() ? null : ('HTTP ' . $response->status()),
        ];
    }

    private function dispatch(string $method, string $path, array $data): Response
    {
        $request = $this->base();

        return $method === 'get'
            ? $request->get($path, $data)
            : $request->post($path, $data);
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
