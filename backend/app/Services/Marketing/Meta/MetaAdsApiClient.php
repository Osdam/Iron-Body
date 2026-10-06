<?php

namespace App\Services\Marketing\Meta;

use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cliente de LECTURA de la Marketing API de Meta: el gasto por anuncio y día, y
 * el estado de las campañas. Nada más.
 *
 * Tres reglas que no se negocian:
 *
 *  1. El token va SIEMPRE en la cabecera `Authorization: Bearer`, nunca en la
 *     URL. Una URL acaba en logs, en mensajes de error y en trazas, y el token
 *     con ella.
 *  2. Se pagina con el cursor `after` sobre el MISMO endpoint, no siguiendo
 *     `paging.next`. Esa URL la escribe un tercero, puede traer credenciales en
 *     la query y podría apuntar a cualquier sitio.
 *  3. Un fallo es una excepción clasificada ({@see MetaAdsApiException}), nunca
 *     una lista vacía: una lista vacía se leería como «gasto cero», que es justo
 *     lo que este módulo existe para no afirmar sin pruebas.
 *
 * Usa su propia configuración (`meta.ads`): ni META_ENABLED ni el token de
 * WhatsApp, que no tiene permisos sobre anuncios.
 */
class MetaAdsApiClient
{
    /** Campos que se piden a `/insights`. */
    public const INSIGHTS_FIELDS = 'date_start,campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,spend,impressions,reach,clicks,account_currency';

    /** Zona de respaldo si la configurada no es válida (el problema se reporta aparte). */
    private const FALLBACK_TIMEZONE = 'America/Bogota';

    /** Filas por página. */
    private const PAGE_LIMIT = 500;

    /** Una paginación que no termina es un fallo, no un bucle. */
    private const MAX_PAGES = 200;

    /** Texto legible de cada problema de configuración. */
    private const PROBLEMS = [
        'missing_access_token' => 'Falta el token de Meta Ads (META_ADS_ACCESS_TOKEN).',
        'missing_ad_account_id' => 'Falta la cuenta publicitaria (META_AD_ACCOUNT_ID).',
        'invalid_ad_account_id' => 'La cuenta publicitaria configurada no es un id numérico de Meta.',
        'invalid_timezone' => 'La zona horaria de la cuenta (META_AD_ACCOUNT_TIMEZONE) no es válida.',
    ];

    /**
     * Qué falta para poder leer anuncios, o null si nada.
     *
     * Solo mira la configuración, no llama a Meta. Un token presente pero sin
     * permisos se descubre al sincronizar y queda como fallo `permission`.
     */
    public function configurationProblem(): ?string
    {
        if (trim((string) config('meta.ads.access_token')) === '') {
            return 'missing_access_token';
        }

        if (trim((string) config('meta.ads.ad_account_id')) === '') {
            return 'missing_ad_account_id';
        }

        if ($this->accountId() === null) {
            return 'invalid_ad_account_id';
        }

        if (! $this->validTimezone(trim((string) config('meta.ads.timezone')))) {
            return 'invalid_timezone';
        }

        return null;
    }

    public function isConfigured(): bool
    {
        return $this->configurationProblem() === null;
    }

    public static function problemMessage(string $problem): string
    {
        return self::PROBLEMS[$problem] ?? 'Meta Ads no está configurado.';
    }

    /**
     * Id de la cuenta publicitaria SIN el prefijo `act_` (se acepta con o sin
     * él), o null si falta o no es numérico. Solo dígitos: el valor va en la
     * ruta de la URL y no puede colar otro segmento.
     */
    public function accountId(): ?string
    {
        $raw = trim((string) config('meta.ads.ad_account_id'));
        $id = str_starts_with($raw, 'act_') ? substr($raw, 4) : $raw;

        return preg_match('/^\d{1,40}$/', $id) === 1 ? $id : null;
    }

    /** Zona horaria en la que Meta parte los días de la cuenta. */
    public function timezone(): string
    {
        $tz = trim((string) config('meta.ads.timezone'));

        return $this->validTimezone($tz) ? $tz : self::FALLBACK_TIMEZONE;
    }

    /**
     * Gasto por anuncio y día de `[since, until]`, días de la cuenta inclusive.
     *
     * Devuelve TODAS las filas o lanza; nunca una lista a medias. Si falla una
     * página intermedia, lo leído hasta entonces se pierde con la excepción.
     *
     * @return list<array<string,mixed>>
     *
     * @throws MetaAdsApiException
     */
    public function insights(string $since, string $until): array
    {
        return $this->paginate('insights', [
            'level' => 'ad',
            'time_increment' => 1,
            'time_range' => json_encode(['since' => $since, 'until' => $until]),
            'fields' => self::INSIGHTS_FIELDS,
            'limit' => self::PAGE_LIMIT,
        ]);
    }

    /**
     * Campañas de la cuenta con su estado efectivo (ACTIVE, PAUSED…).
     *
     * @return list<array<string,mixed>>
     *
     * @throws MetaAdsApiException
     */
    public function campaigns(): array
    {
        return $this->paginate('campaigns', [
            'fields' => 'id,name,effective_status',
            'limit' => self::PAGE_LIMIT,
        ]);
    }

    /**
     * Texto apto para guardar o enseñar: sin tokens ni credenciales, con los ids
     * largos enmascarados (cuentas, anuncios, teléfonos) y acotado.
     *
     * Se aplica a TODO mensaje que salga de aquí, venga de Meta, de Guzzle o de
     * la base: el texto de un error no se controla y puede traer la URL entera.
     */
    public static function sanitize(?string $message, int $limit = 500): string
    {
        $text = (string) $message;

        $token = trim((string) config('meta.ads.access_token'));
        if ($token !== '') {
            $text = str_replace($token, '[token]', $text);
        }

        $text = preg_replace(
            '/\b(access_token|input_token|client_secret|appsecret_proof|fb_exchange_token)=[^&\s"\']*/i',
            '$1=[redactado]',
            $text,
        ) ?? '';
        $text = preg_replace('/\bBearer\s+[^\s"\',]+/i', 'Bearer [redactado]', $text) ?? '';
        // La forma típica de un token de Meta, aunque no sea el configurado.
        $text = preg_replace('/\bEAA[A-Za-z0-9]{16,}/', '[token]', $text) ?? '';
        // Cualquier cadena opaca larga: otro token, una firma, un hash.
        $text = preg_replace('/[A-Za-z0-9_\-]{40,}/', '[redactado]', $text) ?? '';
        // Ids largos: quedan los cuatro últimos dígitos, que bastan para reconocerlos.
        $text = preg_replace_callback('/\d{8,}/', static fn (array $m): string => '***'.substr($m[0], -4), $text) ?? '';

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'…' : $text;
    }

    /**
     * Recorre todas las páginas de una arista de la cuenta con el cursor `after`.
     *
     * Meta dice que se pagina hasta que deje de aparecer `next`, y que una página
     * vacía puede traerlo todavía: por eso una página vacía no es el final.
     *
     * @return list<array<string,mixed>>
     */
    private function paginate(string $edge, array $query): array
    {
        $rows = [];
        $after = null;
        $seen = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $body = $this->get($edge, $after === null ? $query : $query + ['after' => $after]);

            foreach ($body['data'] as $row) {
                if (! is_array($row)) {
                    throw new MetaAdsApiException(MetaAdsApiException::OTHER, 'Meta devolvió una fila que no es un objeto.');
                }
                $rows[] = $row;
            }

            $next = $body['paging']['next'] ?? null;
            if (! is_string($next) || $next === '') {
                return $rows;
            }

            // Hay más páginas, pero se siguen con el cursor: la URL de `next` no
            // se pide nunca.
            $cursor = $body['paging']['cursors']['after'] ?? null;
            if (! is_string($cursor) || $cursor === '' || isset($seen[$cursor])) {
                throw new MetaAdsApiException(
                    MetaAdsApiException::OTHER,
                    'Meta anunció otra página sin un cursor utilizable.',
                );
            }

            $seen[$cursor] = true;
            $after = $cursor;
        }

        throw new MetaAdsApiException(
            MetaAdsApiException::OTHER,
            'La paginación de Meta no terminó tras '.self::MAX_PAGES.' páginas.',
        );
    }

    /**
     * Un GET a `act_{cuenta}/{arista}` con el token en la cabecera.
     *
     * @return array<string,mixed> con `data` siempre presente y en forma de lista
     */
    private function get(string $edge, array $query): array
    {
        $token = trim((string) config('meta.ads.access_token'));
        $account = $this->accountId();

        if ($token === '' || $account === null) {
            // Quien llama comprueba la configuración antes; esto es la red.
            throw new MetaAdsApiException(MetaAdsApiException::OTHER, 'Meta Ads no está configurado.');
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(max(1, (int) config('meta.timeout', 20)))
                ->get($this->url("act_{$account}/{$edge}"), $query);
        } catch (ConnectionException $e) {
            // El texto de Guzzle trae la URL completa: se resume, no se copia.
            preg_match('/cURL error \d+/', $e->getMessage(), $curl);

            throw new MetaAdsApiException(
                MetaAdsApiException::TRANSIENT,
                'Sin respuesta de Meta'.(isset($curl[0]) ? " ({$curl[0]})" : '').'.',
            );
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $body = $response->json();
        if (! is_array($body) || ! is_array($body['data'] ?? null) || ! array_is_list($body['data'])) {
            throw new MetaAdsApiException(
                MetaAdsApiException::OTHER,
                'Meta respondió sin la lista de datos esperada.',
                $response->status(),
            );
        }

        return $body;
    }

    /**
     * Traduce una respuesta de error de Graph a un fallo clasificado.
     *
     * Además de los códigos clásicos, 80000-80014 son los límites por caso de uso
     * de la Marketing API (el de insights es el 80000), e `is_transient` es la
     * marca con la que Meta dice que el error es pasajero.
     */
    private function failure(Response $response): MetaAdsApiException
    {
        $status = $response->status();
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];

        $code = is_numeric($error['code'] ?? null) ? (int) $error['code'] : null;
        $subcode = is_numeric($error['error_subcode'] ?? null) ? (int) $error['error_subcode'] : null;

        $category = match (true) {
            $code !== null && (in_array($code, [10, 190], true) || ($code >= 200 && $code <= 299)) => MetaAdsApiException::PERMISSION,
            $code !== null && (in_array($code, [4, 17, 32, 613], true) || ($code >= 80000 && $code <= 80014)) => MetaAdsApiException::RATE_LIMIT,
            $status === 429 => MetaAdsApiException::RATE_LIMIT,
            $status >= 500, ($error['is_transient'] ?? false) === true => MetaAdsApiException::TRANSIENT,
            default => MetaAdsApiException::OTHER,
        };

        $detail = is_string($error['message'] ?? null) && $error['message'] !== '' ? $error['message'] : null;
        $message = "Meta respondió {$status}"
            .($code !== null ? ' (código '.$code.($subcode !== null ? '/'.$subcode : '').')' : '')
            .($detail !== null ? ': '.$detail : '.');

        return new MetaAdsApiException($category, self::sanitize($message), $status, $code, $subcode);
    }

    private function url(string $path): string
    {
        $base = rtrim((string) config('meta.graph_base', 'https://graph.facebook.com'), '/');
        $version = trim((string) config('meta.ads.graph_version'), '/');

        return "{$base}/{$version}/{$path}";
    }

    private function validTimezone(string $tz): bool
    {
        if ($tz === '') {
            return false;
        }

        try {
            new DateTimeZone($tz);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
