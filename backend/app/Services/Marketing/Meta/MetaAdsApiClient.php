<?php

namespace App\Services\Marketing\Meta;

use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cliente de LECTURA de la Marketing API de Meta: el gasto y las métricas por
 * anuncio y día, el alcance de un rango, el estado de campañas, conjuntos y
 * anuncios, la ficha de la cuenta y qué es el token. Nada escribe en Meta.
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
    /**
     * Campos que se piden a `/insights` por anuncio y día. Solo métricas que se
     * pueden sumar entre días; CPM, CTR y CPC se calculan después a partir de
     * las sumas, porque la media de ratios diarios no es el ratio del periodo.
     */
    public const INSIGHTS_FIELDS = 'date_start,campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,spend,impressions,reach,clicks,inline_link_clicks,actions,account_currency';

    /**
     * TODOS los estados efectivos de un anuncio, para pedir sus insights. Sin
     * este filtro Meta deja fuera los anuncios eliminados y archivados, y su
     * gasto, que sí cuenta en el total de la cuenta del Administrador de
     * anuncios: el gasto del CRM no cuadraría. Y un estado que faltara aquí no
     * solo dejaría fuera su gasto: la pasada borraría las filas que ya tenía de
     * esos días (las que Meta «ya no devuelve»). Por eso va la lista completa, y
     * la conciliación (`marketing:meta-ads-reconcile`) lo comprueba con la cuenta.
     */
    public const AD_STATUSES = [
        'ACTIVE', 'PAUSED', 'DELETED', 'PENDING_REVIEW', 'DISAPPROVED', 'PREAPPROVED',
        'PENDING_BILLING_INFO', 'CAMPAIGN_PAUSED', 'ARCHIVED', 'ADSET_PAUSED',
        'IN_PROCESS', 'WITH_ISSUES',
    ];

    /** Lo mismo para el alcance por campaña: todos sus estados efectivos. */
    public const CAMPAIGN_STATUSES = ['ACTIVE', 'PAUSED', 'DELETED', 'ARCHIVED', 'IN_PROCESS', 'WITH_ISSUES'];

    /**
     * Los estados que se pueden pedir a `/act_{id}/campaigns`. Es la lista de
     * arriba SIN `DELETED`: ese borde lo rechaza («No se pueden solicitar objetos
     * eliminados en este extremo», código 100/1815001, comprobado con la cuenta
     * real). Y `?ids=` ya no existe en v26: el estado de una eliminada se
     * queda como el último conocido.
     */
    public const CAMPAIGN_EDGE_STATUSES = ['ACTIVE', 'PAUSED', 'ARCHIVED', 'IN_PROCESS', 'WITH_ISSUES'];

    /** Lo mismo para `/act_{id}/adsets`: todos sus estados efectivos menos DELETED. */
    public const ADSET_EDGE_STATUSES = ['ACTIVE', 'PAUSED', 'CAMPAIGN_PAUSED', 'ARCHIVED', 'IN_PROCESS', 'WITH_ISSUES'];

    /** Lo mismo para `/act_{id}/ads`: los de AD_STATUSES menos DELETED. */
    public const AD_EDGE_STATUSES = [
        'ACTIVE', 'PAUSED', 'PENDING_REVIEW', 'DISAPPROVED', 'PREAPPROVED', 'PENDING_BILLING_INFO',
        'CAMPAIGN_PAUSED', 'ARCHIVED', 'ADSET_PAUSED', 'IN_PROCESS', 'WITH_ISSUES',
    ];

    /** Campos de la cuenta publicitaria que se comprueban antes de sincronizar. */
    public const ACCOUNT_FIELDS = 'id,account_id,name,currency,timezone_name,timezone_offset_hours_utc,account_status';

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
            'filtering' => json_encode([['field' => 'ad.effective_status', 'operator' => 'IN', 'value' => self::AD_STATUSES]]),
            'limit' => self::PAGE_LIMIT,
        ]);
    }

    /**
     * Alcance (personas únicas), impresiones y frecuencia de TODO el rango
     * `[since, until]`, de la cuenta (`account`) o por campaña (`campaign`).
     *
     * Sin `time_increment`: Meta calcula el alcance del rango entero, que no es
     * la suma del de cada día.
     *
     * @return list<array<string,mixed>>
     *
     * @throws MetaAdsApiException
     */
    public function reach(string $level, string $since, string $until): array
    {
        $query = [
            'level' => $level === 'campaign' ? 'campaign' : 'account',
            'time_range' => json_encode(['since' => $since, 'until' => $until]),
            'fields' => $level === 'campaign'
                ? 'campaign_id,campaign_name,reach,impressions,frequency,spend,account_currency'
                : 'reach,impressions,frequency,spend,account_currency',
            'limit' => self::PAGE_LIMIT,
        ];

        if ($level === 'campaign') {
            $query['filtering'] = json_encode([['field' => 'campaign.effective_status', 'operator' => 'IN', 'value' => self::CAMPAIGN_STATUSES]]);
        }

        return $this->paginate('insights', $query);
    }

    /**
     * Totales de la CUENTA en `[since, until]`: gasto, impresiones y alcance tal
     * como los da el Administrador de anuncios. Para conciliar, no para el panel.
     *
     * @return array<string,mixed>|null null si Meta no devuelve fila (sin actividad)
     *
     * @throws MetaAdsApiException
     */
    public function accountTotals(string $since, string $until): ?array
    {
        $rows = $this->reach('account', $since, $until);

        return $rows[0] ?? null;
    }

    /**
     * A qué cuenta publicitaria pertenece un anuncio: `GET /{ad_id}` con
     * `account_id`. La lectura de un objeto suelto sí existe en v26 (`?ids=` no).
     * Un anuncio de una cuenta que el token no ve contesta 100/33 («does not
     * exist, cannot be loaded due to missing permissions»): lanza.
     *
     * @return array{account_id: ?string, campaign_id: ?string, effective_status: ?string}
     *
     * @throws MetaAdsApiException
     */
    public function adOwner(string $adId): array
    {
        if (preg_match('/^\d{1,40}$/', $adId) !== 1) {
            throw new MetaAdsApiException(MetaAdsApiException::OTHER, 'Id de anuncio no válido.');
        }

        $body = $this->call($adId, ['fields' => 'account_id,campaign_id,effective_status']);

        return [
            'account_id' => isset($body['account_id']) && is_scalar($body['account_id']) ? (string) $body['account_id'] : null,
            'campaign_id' => isset($body['campaign_id']) && is_scalar($body['campaign_id']) ? (string) $body['campaign_id'] : null,
            'effective_status' => is_string($body['effective_status'] ?? null) ? $body['effective_status'] : null,
        ];
    }

    /**
     * La cuenta publicitaria configurada: id, nombre, moneda, zona horaria y
     * estado. Lo primero que se comprueba con un token nuevo.
     *
     * @return array<string,mixed>
     *
     * @throws MetaAdsApiException
     */
    public function account(): array
    {
        $account = $this->accountId();
        if ($account === null) {
            throw new MetaAdsApiException(MetaAdsApiException::OTHER, 'Meta Ads no está configurado.');
        }

        return $this->call("act_{$account}", ['fields' => self::ACCOUNT_FIELDS]);
    }

    /**
     * Quién es el token configurado y qué permisos tiene concedidos, con
     * `/me` y `/me/permissions`. Nunca devuelve el token.
     *
     * No se usa `/debug_token` a propósito: exige el token a examinar en la URL
     * (`input_token`), y una URL acaba en logs y en trazas. Aquí el token solo
     * va en la cabecera, como en el resto del cliente. Un token inválido o
     * caducado no llega a contestar: Meta responde 190 y sale como fallo
     * `permission`.
     *
     * @return array{id: ?string, name: ?string, scopes: list<string>}
     *
     * @throws MetaAdsApiException
     */
    public function tokenInfo(): array
    {
        $me = $this->call('me', ['fields' => 'id,name']);
        $permissions = $this->call('me/permissions', []);

        $granted = [];
        foreach ((array) ($permissions['data'] ?? []) as $p) {
            if (is_array($p) && ($p['status'] ?? null) === 'granted' && is_string($p['permission'] ?? null)) {
                $granted[] = $p['permission'];
            }
        }

        return [
            'id' => is_scalar($me['id'] ?? null) ? (string) $me['id'] : null,
            'name' => is_string($me['name'] ?? null) ? mb_substr($me['name'], 0, 120) : null,
            'scopes' => array_values(array_unique($granted)),
        ];
    }

    /**
     * Campañas de la cuenta con su estado efectivo (ACTIVE, PAUSED…), también
     * las archivadas: sin el filtro, Meta solo devuelve las que no lo están, y
     * una campaña archivada se quedaría en ACTIVE para siempre mientras su gasto
     * sigue entrando. Las eliminadas no se pueden pedir aquí
     * ({@see CAMPAIGN_EDGE_STATUSES}): su estado se queda como el último conocido.
     *
     * @return list<array<string,mixed>>
     *
     * @throws MetaAdsApiException
     */
    public function campaigns(): array
    {
        return $this->paginate('campaigns', [
            'fields' => 'id,name,effective_status',
            'effective_status' => json_encode(self::CAMPAIGN_EDGE_STATUSES),
            'limit' => self::PAGE_LIMIT,
        ]);
    }

    /**
     * Conjuntos de la cuenta con su estado efectivo, también los archivados. Los
     * eliminados no se pueden pedir a este borde, y `?ids=` ya no existe en
     * v26: su estado se queda como el último conocido.
     *
     * @return list<array<string,mixed>>
     *
     * @throws MetaAdsApiException
     */
    public function adsets(): array
    {
        return $this->paginate('adsets', [
            'fields' => 'id,name,effective_status',
            'effective_status' => json_encode(self::ADSET_EDGE_STATUSES),
            'limit' => self::PAGE_LIMIT,
        ]);
    }

    /**
     * Anuncios de la cuenta con su estado efectivo, como {@see adsets()}.
     *
     * @return list<array<string,mixed>>
     *
     * @throws MetaAdsApiException
     */
    public function ads(): array
    {
        return $this->paginate('ads', [
            'fields' => 'id,name,effective_status',
            'effective_status' => json_encode(self::AD_EDGE_STATUSES),
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
        $account = $this->accountId();

        if ($account === null) {
            // Quien llama comprueba la configuración antes; esto es la red.
            throw new MetaAdsApiException(MetaAdsApiException::OTHER, 'Meta Ads no está configurado.');
        }

        $body = $this->call("act_{$account}/{$edge}", $query, $status);
        if (! is_array($body['data'] ?? null) || ! array_is_list($body['data'])) {
            throw new MetaAdsApiException(MetaAdsApiException::OTHER, 'Meta respondió sin la lista de datos esperada.', $status);
        }

        return $body;
    }

    /**
     * Un GET a Graph con el token en la cabecera; devuelve el JSON decodificado
     * y deja en `$status` el código HTTP, para el diagnóstico.
     *
     * @return array<string,mixed>
     *
     * @throws MetaAdsApiException
     */
    private function call(string $path, array $query, ?int &$status = null): array
    {
        $token = trim((string) config('meta.ads.access_token'));

        if ($token === '') {
            throw new MetaAdsApiException(MetaAdsApiException::OTHER, 'Meta Ads no está configurado.');
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(max(1, (int) config('meta.timeout', 20)))
                ->get($this->url($path), $query);
        } catch (ConnectionException $e) {
            // El texto de Guzzle trae la URL completa: se resume, no se copia.
            preg_match('/cURL error \d+/', $e->getMessage(), $curl);

            throw new MetaAdsApiException(
                MetaAdsApiException::TRANSIENT,
                'Sin respuesta de Meta'.(isset($curl[0]) ? " ({$curl[0]})" : '').'.',
            );
        }

        $status = $response->status();
        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new MetaAdsApiException(MetaAdsApiException::OTHER, 'Meta respondió algo que no es JSON.', $response->status());
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

        return $path === '' ? "{$base}/{$version}/" : "{$base}/{$version}/{$path}";
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
