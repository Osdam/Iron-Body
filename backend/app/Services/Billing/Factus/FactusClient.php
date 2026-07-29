<?php

namespace App\Services\Billing\Factus;

use App\Services\Billing\InvoiceEmissionGuard;
use App\Services\Billing\SandboxProbe;
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
        // Barrera de producción: comprueba QUÉ se está facturando (origen del
        // pago, estado, duplicados, ambiente) antes de mirar CÓMO se declara el
        // tributo. Va aquí y no en el servicio que origina la emisión porque
        // hay al menos cuatro caminos que pueden dispararla.
        app(InvoiceEmissionGuard::class)->assertMayEmit($payload);

        return $this->send('post', self::PATH_CREATE_INVOICE, $this->normalizeTaxes($payload));
    }

    /**
     * Ajusta `items[].taxes` a la política fiscal DEL EMISOR.
     *
     * Distinción jurídica que este método hace explícita: Iron Body no cobra
     * IVA porque el EMISOR figura con responsabilidad 49 (no responsable), NO
     * porque sus planes o servicios sean bienes exentos, excluidos o no
     * gravados. Son cosas distintas ante la DIAN.
     *
     * Por eso NO se marca `is_excluded = true`: eso afirmaría que el producto
     * está excluido por su naturaleza, lo que sería una clasificación falsa.
     * El ítem se declara explícitamente NO excluido y sin tarifa; la condición
     * del emisor vive en sus datos fiscales, no en la naturaleza del producto.
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
            // Se OMITE el bloque de tributos por completo.
            //
            // Factus rechaza `is_excluded: false` sin acompañarlo de `code` y
            // `rate` (HTTP 422: «El campo código tributo es obligatorio»), y
            // las dos únicas alternativas que acepta afirmarían algo falso:
            //   - `[{code:'01', rate:'0.00'}]` declara IVA con tarifa 0 %, que
            //     es como se representa un bien EXENTO;
            //   - `[{is_excluded:true}]` declara el bien EXCLUIDO.
            // Ninguna describe la realidad: el bien es gravable y la causa del
            // IVA cero es la responsabilidad 49 del EMISOR. Omitir el bloque no
            // afirma naturaleza tributaria alguna sobre el producto.
            unset($payload['items'][$i]['taxes']);
        }

        $this->assertPayloadIsTaxFree($payload, $policy);

        return $payload;
    }

    /**
     * Barrera PRE-HTTP. Rechaza, antes de cualquier POST:
     *
     *   - un importe de IVA mayor que cero;
     *   - una tarifa mayor que cero en cualquier línea;
     *   - un `is_excluded = true` (clasificación falsa del producto);
     *   - un subtotal que no coincida con el precio comercial de las líneas.
     *
     * Se aborta en vez de corregir: maquillar cifras produciría un documento
     * legal cuyo origen nadie podría explicar.
     *
     * @param  array<string,mixed>  $payload
     */
    private function assertPayloadIsTaxFree(array $payload, TaxPolicy $policy): void
    {
        $responsibility = $policy->issuerVatResponsibility();

        foreach (($payload['items'] ?? []) as $n => $item) {
            foreach (($item['taxes'] ?? []) as $tax) {
                if ((float) ($tax['rate'] ?? 0) > 0) {
                    throw new \RuntimeException(sprintf(
                        'Bloqueo tributario: tarifa %s%% en el ítem %d. El emisor es '
                        .'responsabilidad %s (no responsable de IVA).',
                        (string) $tax['rate'], $n + 1, $responsibility,
                    ));
                }

                if (($tax['is_excluded'] ?? false) === true) {
                    throw new \RuntimeException(sprintf(
                        'Bloqueo tributario: el ítem %d se declara EXCLUIDO. El IVA cero '
                        .'proviene de la responsabilidad %s del emisor, no de la '
                        .'naturaleza del producto; marcarlo excluido sería una '
                        .'clasificación falsa ante la DIAN.',
                        $n + 1, $responsibility,
                    ));
                }
            }
        }

        foreach (['tax_amount', 'total_tax', 'taxes_amount'] as $key) {
            if (isset($payload[$key])) {
                $policy->assertNoVat($payload[$key], 'payload de factura');
            }
        }

        $this->assertSubtotalMatchesLines($payload, $responsibility);
    }

    /**
     * El subtotal declarado debe ser el precio comercial de las líneas menos
     * descuentos. Si difiere, algo volvió a repartir base/IVA por detrás.
     *
     * @param  array<string,mixed>  $payload
     */
    private function assertSubtotalMatchesLines(array $payload, string $responsibility): void
    {
        if (! isset($payload['subtotal'])) {
            return; // Factus lo calcula; nada que contrastar.
        }

        $linesCents = 0;
        foreach (($payload['items'] ?? []) as $item) {
            $price = (float) ($item['price'] ?? 0);
            $qty = (float) ($item['quantity'] ?? 1);
            $discountRate = (float) ($item['discount_rate'] ?? 0);
            $gross = $price * $qty;
            $linesCents += (int) round($gross * (1 - $discountRate / 100) * 100);
        }

        $declaredCents = (int) round(((float) $payload['subtotal']) * 100);

        // 1 peso de tolerancia absorbe el redondeo comercial.
        if (abs($declaredCents - $linesCents) > 100) {
            throw new \RuntimeException(sprintf(
                'Bloqueo tributario: el subtotal declarado (%s) no coincide con el '
                .'precio comercial de las líneas (%s). Emisor responsabilidad %s.',
                number_format($declaredCents / 100, 2, '.', ''),
                number_format($linesCents / 100, 2, '.', ''),
                $responsibility,
            ));
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
        $this->assertEmissionAllowed($method, $path);

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

    /**
     * BLOQUEO DE EMISIÓN. Aborta cualquier petición que cree un documento fiscal
     * mientras la decisión tributaria no esté confirmada por escrito.
     *
     * Vive aquí —en el despachador genérico, no en {@see createInvoice()}— por
     * dos razones:
     *
     *  1. Es lo último que se ejecuta antes del HTTP saliente. Ocultar botones en
     *     el CRM no bloquea nada: `WompiTransactionService` y
     *     `PaymentMembershipActivator` fuerzan la emisión SIN consultar
     *     `auto_emit`, y un job en cola puede reintentar por su cuenta.
     *  2. Cubre cualquier POST, incluidos los que se añadan en el futuro, sin
     *     depender de que alguien se acuerde de repetir la guarda.
     *
     * Los GET quedan permitidos a propósito: la reconciliación fiscal necesita
     * leer del proveedor, y leer no crea documentos ni consume consecutivos.
     *
     * Contexto: Iron Body es responsabilidad 49 (no responsable de IVA) y las
     * dos únicas representaciones que la API acepta —tarifa 0 % o
     * `is_excluded`— clasificarían el servicio como exento o excluido, lo que
     * sería falso. Hasta que Halltec confirme el contrato correcto, no se emite.
     *
     * @throws \RuntimeException
     */
    private function assertEmissionAllowed(string $method, string $path): void
    {
        if (strtolower($method) !== 'post') {
            return;
        }

        if ((bool) ($this->cfg['tax_decision_confirmed'] ?? false)) {
            return;
        }

        // Única concesión: una prueba explícita contra sandbox. Sirve
        // precisamente para ensayar la representación del tributo sin tocar la
        // DIAN. Exige AMBAS condiciones, y el ambiente se lee de la
        // configuración, no de la sonda.
        if (SandboxProbe::isActive()
            && strtolower((string) ($this->cfg['env'] ?? 'sandbox')) !== 'production') {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Emisión bloqueada: se intentó POST %s con la decisión tributaria sin '
            .'confirmar (FACTUS_TAX_DECISION_CONFIRMED=false). El emisor es '
            .'responsabilidad %s (no responsable de IVA) y no existe todavía una '
            .'representación del tributo aceptada por el proveedor que no '
            .'clasifique el servicio como exento o excluido. No se emitió ningún '
            .'documento y no se consumió consecutivo.',
            $path,
            app(TaxPolicy::class)->issuerVatResponsibility(),
        ));
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
