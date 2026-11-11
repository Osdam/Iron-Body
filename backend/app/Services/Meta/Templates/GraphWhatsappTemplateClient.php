<?php

namespace App\Services\Meta\Templates;

use App\Services\Meta\MetaAuthService;
use App\Services\Observability\ChannelLog;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use SensitiveParameter;
use Throwable;

/**
 * El cliente de las plantillas de WhatsApp contra Graph ({@see WhatsappTemplateClient}).
 * Sin estado: cada llamada lee la credencial en el momento y no guarda nada.
 *
 * Reglas que no se negocian:
 *
 *  1. SU PROPIA VERSIÓN DE GRAPH. {@see DEFAULT_GRAPH_VERSION} (`v25.0`, la
 *     última con fecha de caducidad publicada: 29-07-2028) o la que llegue por
 *     el constructor; nunca la del WhatsApp de mensajería (`meta.graph_version`),
 *     que caduca antes y es otra decisión.
 *  2. HOST FIJO EN PRODUCCIÓN. Con `app()->isProduction()`, siempre
 *     {@see PRODUCTION_BASE}; la base del constructor se ignora. Fuera de
 *     producción, SOLO la base del constructor (el simulador), y solo si es una
 *     URL limpia (sin usuario, consulta ni fragmento) que no sea de Meta
 *     ({@see META_DOMAINS}) y que sea `https://`, o `http://` a
 *     {@see SIMULATOR_LOCAL_HOSTS}: sin ella no hay a quién llamar y el
 *     resultado es `not_configured`, sin red. Se decide en cada llamada, no al
 *     construir.
 *  3. LA CREDENCIAL, POR INYECCIÓN, Y SOLO HACIA GRAPH. En producción, el token
 *     sale de {@see MetaAuthService} (la conexión hecha desde el CRM si está
 *     usable; si no, `meta.access_token`) EN EL MOMENTO DE LLAMAR, va solo en
 *     la cabecera `Authorization: Bearer` y no se guarda en ninguna propiedad.
 *     Fuera de producción el portador es SIEMPRE {@see SIMULATOR_BEARER}: el
 *     token real nunca viaja al simulador (PM-1). La credencial se sigue
 *     exigiendo igual en los dos casos (sin ella, `not_configured`), para que
 *     el simulador ensaye la misma puerta que producción. No mira
 *     `META_ENABLED`: leer y crear plantillas no escribe a nadie; quien decide
 *     si se puede crear es quien llama. La cuenta (WABA) llega en cada llamada.
 *  4. UNA PETICIÓN POR LLAMADA, SIN REINTENTOS NI REDIRECCIONES.
 *     {@see CONNECT_TIMEOUT_SECONDS} para conectar y {@see TIMEOUT_SECONDS} en
 *     total. Una redirección no se sigue (llevaría el token a otro sitio). Un
 *     POST NUNCA se repite: si no se sabe qué pasó, `unknown`.
 *  5. NUNCA LANZA POR LA RED NI POR META. Todo vuelve como resultado
 *     clasificado ({@see TemplateApiErrors}); de un fallo inesperado del cliente
 *     HTTP solo se registra la clase (su mensaje puede llevar la URL).
 *  6. NADA SALE DE AQUÍ CON EL TOKEN NI CON CUERPOS CRUDOS: ni en el resultado,
 *     ni en el registro (`ChannelLog`, eventos `meta.templates.*`, solo códigos
 *     y tiempos), ni en el mensaje de una excepción.
 *  7. SOLO ESTAS RUTAS: `{waba}/message_templates` (POST para crear, GET para
 *     listar o buscar por nombre), `{plantilla}` (GET) y `me/permissions`
 *     (GET). Los ids son dígitos antes de llegar a la ruta.
 */
final class GraphWhatsappTemplateClient implements WhatsappTemplateClient
{
    public const PRODUCTION_BASE = 'https://graph.facebook.com';

    public const DEFAULT_GRAPH_VERSION = 'v25.0';

    /** `vN.N` o `vNN.N`, como la valida la configuración que la da. */
    public const GRAPH_VERSION_PATTERN = '/^v[1-9][0-9]?\.[0-9]$/D';

    public const CONNECT_TIMEOUT_SECONDS = 5;

    public const TIMEOUT_SECONDS = 15;

    /** Una respuesta más grande no es de una página de plantillas. */
    public const MAX_RESPONSE_BYTES = 4194304;

    /** Páginas de 100 que recorre {@see findByName()} como mucho. */
    public const FIND_BY_NAME_MAX_PAGES = 5;

    /**
     * Errores de cURL en los que la petición NO llegó a salir: no resolvió el
     * proxy (5), no resolvió el host (6) o no conectó (7). Solo con ellos un
     * POST fallido es `failed` (`not_sent`); con cualquier otro, `unknown`.
     */
    public const NOT_SENT_CURL_ERRORS = [5, 6, 7];

    /** Fuera de producción, la base del simulador no puede ser de Meta. */
    public const META_DOMAINS = ['facebook.com', 'facebook.net', 'fb.com', 'fbcdn.net', 'meta.com', 'whatsapp.com', 'whatsapp.net', 'instagram.com'];

    /** Fuera de producción, los únicos hosts del simulador admitidos sin TLS (`http://`). */
    public const SIMULATOR_LOCAL_HOSTS = ['127.0.0.1', 'localhost'];

    /** Fuera de producción, el portador FIJO que se manda al simulador: nunca el token real (PM-1). */
    public const SIMULATOR_BEARER = 'simulator';

    /** La forma de un token que puede ir en una cabecera: ASCII visible, sin espacios ni saltos. */
    private const TOKEN_PATTERN = '/^[\x21-\x7E]{1,4096}$/D';

    private const JSON_MAX_DEPTH = 64;

    private const LOG_PREFIX = 'meta.templates.';

    private readonly string $graphVersion;

    /** La base del simulador, limpia y sin barra final; null = nadie (fuera de producción). */
    private readonly ?string $nonProductionBase;

    /** $nonProductionBase se IGNORA si app()->isProduction(): ahí, siempre graph.facebook.com. */
    public function __construct(
        private readonly MetaAuthService $auth,
        string $graphVersion = self::DEFAULT_GRAPH_VERSION,
        ?string $nonProductionBase = null,
    ) {
        if (preg_match(self::GRAPH_VERSION_PATTERN, $graphVersion) !== 1) {
            throw new InvalidArgumentException('La versión de Graph de las plantillas tiene la forma vNN.N (por ejemplo, v25.0).');
        }

        $this->graphVersion = $graphVersion;
        $this->nonProductionBase = self::simulatorBase($nonProductionBase);
    }

    public function graphVersion(): string
    {
        return $this->graphVersion;
    }

    public function configurationProblem(): ?string
    {
        if ($this->base() === null) {
            return 'no_simulator';
        }

        $token = trim((string) $this->auth->accessToken());

        return match (true) {
            $token === '' => 'missing_token',
            preg_match(self::TOKEN_PATTERN, $token) !== 1 => 'malformed_token',
            default => null,
        };
    }

    public function create(string $wabaId, array $payload): TemplateApiResult
    {
        $waba = TemplateRequestGuard::wabaId($wabaId);
        $cuerpo = TemplateRequestGuard::payload($payload);

        $respuesta = $this->request(TemplateApiResult::OP_CREATE, "{$waba}/message_templates", [], $cuerpo);
        if ($respuesta instanceof TemplateApiResult) {
            return $this->logged($respuesta);
        }
        [$datos, $estado, $ms] = $respuesta;

        $id = $datos['id'] ?? null;
        if (is_int($id) && $id >= 0) {
            $id = (string) $id;
        }
        if (! is_string($id) || preg_match(TemplateRequestGuard::ID_PATTERN, $id) !== 1) {
            // Meta dijo que sí sin un id legible: puede que la plantilla exista.
            return $this->logged(TemplateApiResult::failure(TemplateApiResult::OP_CREATE, TemplateApiResult::UNKNOWN, TemplateApiErrors::INVALID_RESPONSE, 'unexpected_shape', $estado, durationMs: $ms));
        }

        return $this->logged(TemplateApiResult::created(
            $id,
            MetaTemplate::enum($datos['status'] ?? null, MetaTemplate::STATUS_MAX),
            MetaTemplate::enum($datos['category'] ?? null, MetaTemplate::CATEGORY_MAX),
            $estado,
            $ms,
        ));
    }

    public function find(string $templateId, array $fields): TemplateApiResult
    {
        $id = TemplateRequestGuard::templateId($templateId);
        $campos = TemplateRequestGuard::fields($fields, ['id']);

        $respuesta = $this->request(TemplateApiResult::OP_FIND, $id, ['fields' => $campos]);
        if ($respuesta instanceof TemplateApiResult) {
            return $this->logged($respuesta);
        }
        [$datos, $estado, $ms] = $respuesta;

        $plantilla = MetaTemplate::fromGraph($datos);
        if ($plantilla === null || $plantilla->id !== $id) {
            return $this->logged(TemplateApiResult::failure(TemplateApiResult::OP_FIND, TemplateApiResult::FAILED, TemplateApiErrors::INVALID_RESPONSE, 'unexpected_shape', $estado, durationMs: $ms));
        }

        return $this->logged(TemplateApiResult::templates(TemplateApiResult::OP_FIND, [$plantilla], null, 0, $estado, $ms));
    }

    public function findByName(string $wabaId, string $name, ?string $language, array $fields): TemplateApiResult
    {
        $waba = TemplateRequestGuard::wabaId($wabaId);
        $nombre = TemplateRequestGuard::name($name);
        $idioma = TemplateRequestGuard::language($language);
        $campos = TemplateRequestGuard::fields($fields, ['id', 'name', 'language']);

        $encontradas = [];
        $saltadas = 0;
        $ms = 0;
        $estado = null;
        $cursor = null;
        $vistos = [];
        for ($pagina = 1; $pagina <= self::FIND_BY_NAME_MAX_PAGES; $pagina++) {
            $consulta = ['name' => $nombre, 'fields' => $campos, 'limit' => self::MAX_PAGE_SIZE];
            if ($cursor !== null) {
                $consulta['after'] = $cursor;
            }

            $respuesta = $this->request(TemplateApiResult::OP_FIND_BY_NAME, "{$waba}/message_templates", $consulta);
            if ($respuesta instanceof TemplateApiResult) {
                return $this->logged(self::afterPages($respuesta, $ms, $pagina));
            }
            [$datos, $estado, $t] = $respuesta;
            $ms += $t;

            $leida = self::page($datos);
            if (is_string($leida)) {
                return $this->logged(TemplateApiResult::failure(TemplateApiResult::OP_FIND_BY_NAME, TemplateApiResult::FAILED, TemplateApiErrors::INVALID_RESPONSE, $leida, $estado, durationMs: $ms, requests: $pagina));
            }
            [$plantillas, $s, $siguiente] = $leida;
            $saltadas += $s;
            foreach ($plantillas as $plantilla) {
                if ($plantilla->name === $nombre && ($idioma === null || $plantilla->language === $idioma)) {
                    $encontradas[] = $plantilla;
                }
            }

            if ($siguiente === null) {
                return $this->logged(TemplateApiResult::templates(TemplateApiResult::OP_FIND_BY_NAME, $encontradas, null, $saltadas, $estado, $ms, $pagina));
            }
            if (isset($vistos[$siguiente])) {
                // El mismo cursor otra vez: seguir sería un bucle.
                return $this->logged(TemplateApiResult::failure(TemplateApiResult::OP_FIND_BY_NAME, TemplateApiResult::FAILED, TemplateApiErrors::INVALID_RESPONSE, 'bad_cursor', $estado, durationMs: $ms, requests: $pagina));
            }
            $vistos[$siguiente] = true;
            $cursor = $siguiente;
        }

        return $this->logged(TemplateApiResult::failure(TemplateApiResult::OP_FIND_BY_NAME, TemplateApiResult::FAILED, TemplateApiErrors::INVALID_RESPONSE, 'too_many_pages', $estado, durationMs: $ms, requests: self::FIND_BY_NAME_MAX_PAGES));
    }

    public function list(string $wabaId, array $fields, ?string $after = null, int $limit = self::MAX_PAGE_SIZE): TemplateApiResult
    {
        $waba = TemplateRequestGuard::wabaId($wabaId);
        $consulta = ['fields' => TemplateRequestGuard::fields($fields, ['id']), 'limit' => TemplateRequestGuard::limit($limit)];
        $cursor = TemplateRequestGuard::cursor($after);
        if ($cursor !== null) {
            $consulta['after'] = $cursor;
        }

        $respuesta = $this->request(TemplateApiResult::OP_LIST, "{$waba}/message_templates", $consulta);
        if ($respuesta instanceof TemplateApiResult) {
            return $this->logged($respuesta);
        }
        [$datos, $estado, $ms] = $respuesta;

        $leida = self::page($datos);
        if (is_string($leida)) {
            return $this->logged(TemplateApiResult::failure(TemplateApiResult::OP_LIST, TemplateApiResult::FAILED, TemplateApiErrors::INVALID_RESPONSE, $leida, $estado, durationMs: $ms));
        }
        [$plantillas, $saltadas, $siguiente] = $leida;

        return $this->logged(TemplateApiResult::templates(TemplateApiResult::OP_LIST, $plantillas, $siguiente, $saltadas, $estado, $ms));
    }

    public function permissions(): TemplateApiResult
    {
        $respuesta = $this->request(TemplateApiResult::OP_PERMISSIONS, 'me/permissions', []);
        if ($respuesta instanceof TemplateApiResult) {
            return $this->logged($respuesta);
        }
        [$datos, $estado, $ms] = $respuesta;

        $filas = $datos['data'] ?? null;
        if (! is_array($filas) || ! array_is_list($filas)) {
            return $this->logged(TemplateApiResult::failure(TemplateApiResult::OP_PERMISSIONS, TemplateApiResult::FAILED, TemplateApiErrors::INVALID_RESPONSE, 'unexpected_shape', $estado, durationMs: $ms));
        }

        $permisos = [];
        $saltadas = 0;
        foreach ($filas as $fila) {
            $permiso = is_array($fila) ? ($fila['permission'] ?? null) : null;
            $situacion = is_array($fila) ? ($fila['status'] ?? null) : null;
            if (is_string($permiso) && preg_match('/^[a-z0-9_]{1,64}$/D', $permiso) === 1 && in_array($situacion, TemplateApiResult::PERMISSION_STATES, true)) {
                $permisos[$permiso] = $situacion;
            } else {
                $saltadas++;
            }
        }
        ksort($permisos, SORT_STRING);

        return $this->logged(TemplateApiResult::permissionsRead($permisos, $saltadas, $estado, $ms));
    }

    /** A dónde se llama ahora mismo: Graph en producción, el simulador fuera, o null (nadie). */
    private function base(): ?string
    {
        return app()->isProduction() ? self::PRODUCTION_BASE : $this->nonProductionBase;
    }

    /**
     * UNA petición. Devuelve un fallo ya clasificado o, si Meta contestó 2xx
     * con un objeto JSON, `[cuerpo, estado HTTP, ms]`. `$json` !== null es un
     * POST con ese cuerpo exacto; si no, un GET con `$query`.
     *
     * @param  array<string,int|string>  $query
     * @return TemplateApiResult|array{0: array<mixed>, 1: int, 2: int}
     */
    private function request(string $operation, string $path, array $query, ?string $json = null): TemplateApiResult|array
    {
        $esCreacion = $json !== null;

        $problema = $this->configurationProblem();
        if ($problema !== null) {
            return TemplateApiResult::notConfigured($operation, $problema);
        }
        // El token real solo viaja a Graph (producción). Al simulador, el
        // portador fijo: así no hay nada que tachar de lo que conteste (null).
        $aGraph = app()->isProduction();
        $token = $aGraph ? trim((string) $this->auth->accessToken()) : self::SIMULATOR_BEARER;
        $url = $this->base().'/'.$this->graphVersion.'/'.$path;
        $inicio = hrtime(true);

        try {
            $pendiente = Http::withToken($token)->acceptJson()->withoutRedirecting()->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)->timeout(self::TIMEOUT_SECONDS);
            $respuesta = $esCreacion ? $pendiente->withBody($json, 'application/json')->post($url) : $pendiente->get($url, $query);
        } catch (StrayRequestException $e) {
            // Solo existe en las pruebas (`preventStrayRequests`): que se vea.
            throw $e;
        } catch (ConnectionException $e) {
            $noSalio = in_array(self::curlError($e), self::NOT_SENT_CURL_ERRORS, true);

            return TemplateApiResult::failure(
                $operation,
                $esCreacion && ! $noSalio ? TemplateApiResult::UNKNOWN : TemplateApiResult::FAILED,
                TemplateApiErrors::TRANSPORT,
                $noSalio ? 'not_sent' : 'maybe_sent',
                durationMs: self::ms($inicio),
            );
        } catch (Throwable $e) {
            // Otro fallo del cliente HTTP. Se convierte en resultado a propósito
            // (quien llama nunca recibe un fallo de la red como excepción) y se
            // registra solo su clase: el mensaje puede llevar la URL. Un POST
            // así queda «desconocido»: no se sabe si llegó a salir.
            ChannelLog::warning(self::LOG_PREFIX.'client_exception', ['operation' => $operation, 'exception_class' => $e::class]);

            return TemplateApiResult::failure($operation, $esCreacion ? TemplateApiResult::UNKNOWN : TemplateApiResult::FAILED, TemplateApiErrors::TRANSPORT, 'maybe_sent', durationMs: self::ms($inicio));
        }

        return self::interpret($operation, $esCreacion, $respuesta, self::ms($inicio), $aGraph ? $token : null);
    }

    /**
     * Lo que contestó Meta: el cuerpo de un 2xx que es un objeto JSON, o el
     * fallo clasificado. En un POST, un 2xx ilegible o un error de servidor de
     * Meta quedan `unknown` (puede que la plantilla exista); lo demás, `failed`.
     * `$token` es el que viajó y se tacha de los textos de Meta; null con el
     * simulador, que no recibió ninguno (se tachan igual los que tengan forma
     * de token, {@see TemplateApiErrors::sanitize()}).
     *
     * @return TemplateApiResult|array{0: array<mixed>, 1: int, 2: int}
     */
    private static function interpret(string $operation, bool $esCreacion, Response $respuesta, int $ms, #[SensitiveParameter] ?string $token): TemplateApiResult|array
    {
        $estado = $respuesta->status();
        $exito = $estado >= 200 && $estado < 300;
        $crudo = $respuesta->body();

        if (strlen($crudo) > self::MAX_RESPONSE_BYTES) {
            return TemplateApiResult::failure($operation, $esCreacion && $exito ? TemplateApiResult::UNKNOWN : TemplateApiResult::FAILED, TemplateApiErrors::INVALID_RESPONSE, 'too_large', $estado, durationMs: $ms);
        }
        if ($estado >= 300 && $estado < 400) {
            // No se sigue: llevaría el token a otro sitio. Graph no redirige sus
            // llamadas, así que la petición no llegó a quien crea plantillas.
            return TemplateApiResult::failure($operation, TemplateApiResult::FAILED, TemplateApiErrors::INVALID_RESPONSE, 'redirect', $estado, durationMs: $ms);
        }

        $datos = self::decode($crudo);
        $conError = is_array($datos) && is_array($datos['error'] ?? null);

        if ($exito && ! $conError) {
            if ($datos === null || array_is_list($datos)) {
                return TemplateApiResult::failure($operation, $esCreacion ? TemplateApiResult::UNKNOWN : TemplateApiResult::FAILED, TemplateApiErrors::INVALID_RESPONSE, $datos === null ? 'not_json' : 'unexpected_shape', $estado, durationMs: $ms);
            }

            return [$datos, $estado, $ms];
        }

        $error = TemplateApiErrors::classify($estado, $datos, $token);
        $desconocido = $esCreacion && $error['class'] === TemplateApiErrors::SERVER;
        $detalle = $error['class'] === TemplateApiErrors::INVALID_RESPONSE ? ($datos === null ? 'not_json' : 'unexpected_shape') : null;

        return TemplateApiResult::failure(
            $operation,
            $desconocido ? TemplateApiResult::UNKNOWN : TemplateApiResult::FAILED,
            $error['class'],
            $detalle,
            $estado,
            $error['code'],
            $error['subcode'],
            $error['userTitle'],
            $error['userMessage'],
            $error['fbtraceId'],
            $ms,
        );
    }

    /**
     * Una página de `message_templates`: las plantillas que se entienden, cuántas
     * filas no, y el cursor de la siguiente (solo si Meta anuncia `next`). O el
     * detalle de por qué la página no se entiende.
     *
     * @param  array<mixed>  $datos
     * @return array{0: list<MetaTemplate>, 1: int, 2: ?string}|string
     */
    private static function page(array $datos): array|string
    {
        $filas = $datos['data'] ?? null;
        if (! is_array($filas) || ! array_is_list($filas)) {
            return 'unexpected_shape';
        }

        $plantillas = [];
        $saltadas = 0;
        foreach ($filas as $fila) {
            $plantilla = MetaTemplate::fromGraph($fila);
            if ($plantilla === null) {
                $saltadas++;

                continue;
            }
            $plantillas[] = $plantilla;
        }

        $paginacion = is_array($datos['paging'] ?? null) ? $datos['paging'] : [];
        $siguiente = $paginacion['next'] ?? null;
        if (! is_string($siguiente) || $siguiente === '') {
            return [$plantillas, $saltadas, null];
        }

        // Hay más, pero se sigue con el cursor sobre la MISMA ruta: la URL de
        // `next` la escribe un tercero y nunca se pide.
        $cursor = is_array($paginacion['cursors'] ?? null) ? ($paginacion['cursors']['after'] ?? null) : null;
        if (! is_string($cursor) || preg_match(TemplateRequestGuard::CURSOR_PATTERN, $cursor) !== 1) {
            return 'bad_cursor';
        }

        return [$plantillas, $saltadas, $cursor];
    }

    /** El fallo de una página de {@see findByName()}, con el tiempo y las peticiones de todas. */
    private static function afterPages(TemplateApiResult $fallo, int $msAntes, int $peticiones): TemplateApiResult
    {
        return TemplateApiResult::failure(
            $fallo->operation, $fallo->outcome, (string) $fallo->error, $fallo->detail, $fallo->httpStatus, $fallo->metaCode, $fallo->metaSubcode,
            $fallo->userTitle, $fallo->userMessage, $fallo->fbtraceId, $msAntes + $fallo->durationMs, $fallo->requests === 0 ? 0 : $peticiones,
        );
    }

    /** Una línea por operación, sin texto, sin cuerpo y sin token: códigos y tiempos. */
    private function logged(TemplateApiResult $resultado): TemplateApiResult
    {
        $contexto = array_filter([
            'operation' => $resultado->operation,
            'outcome' => $resultado->outcome,
            'http_status' => $resultado->httpStatus,
            'error_class' => $resultado->error,
            'error_detail' => $resultado->detail,
            'error_code' => $resultado->metaCode,
            'error_subcode' => $resultado->metaSubcode,
            'fbtrace_id' => $resultado->fbtraceId,
            'duration_ms' => $resultado->durationMs,
            'requests' => $resultado->requests,
            'graph_version' => $this->graphVersion,
        ], static fn (mixed $v): bool => $v !== null);

        if ($resultado->ok()) {
            ChannelLog::info(self::LOG_PREFIX.$resultado->operation, $contexto);
        } else {
            ChannelLog::warning(self::LOG_PREFIX.$resultado->operation, $contexto);
        }

        return $resultado;
    }

    /** El JSON de la respuesta si es un array (objeto o lista), o null. */
    private static function decode(string $crudo): ?array
    {
        if ($crudo === '') {
            return null;
        }

        try {
            $valor = json_decode($crudo, true, self::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            // Un cuerpo que no es JSON (HTML de un proxy, cortado…): se clasifica, no se propaga.
            return null;
        }

        return is_array($valor) ? $valor : null;
    }

    /** El número de error de cURL: del contexto de Guzzle o, si no está, del texto «cURL error N:». */
    private static function curlError(ConnectionException $e): ?int
    {
        $previa = $e->getPrevious();
        if ($previa instanceof ConnectException || $previa instanceof GuzzleRequestException) {
            $errno = $previa->getHandlerContext()['errno'] ?? null;
            if (is_int($errno)) {
                return $errno;
            }
        }

        return preg_match('/^cURL error (\d{1,3}):/', $e->getMessage(), $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * La base del simulador si es una URL limpia (sin usuario, clave, consulta
     * ni fragmento), no es de Meta y va por `https://` o, sin TLS, a
     * {@see SIMULATOR_LOCAL_HOSTS} (PM-1); sin la barra final. Si no, null.
     */
    private static function simulatorBase(?string $base): ?string
    {
        $base = trim((string) $base);
        if ($base === '' || filter_var($base, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $partes = parse_url($base);
        if (! is_array($partes)) {
            return null;
        }
        $host = (string) ($partes['host'] ?? '');
        $esquema = strtolower((string) ($partes['scheme'] ?? ''));
        $limpia = ($esquema === 'https' || ($esquema === 'http' && in_array(strtolower($host), self::SIMULATOR_LOCAL_HOSTS, true)))
            && $host !== ''
            && ! isset($partes['user'])
            && ! isset($partes['pass'])
            && ! isset($partes['query'])
            && ! isset($partes['fragment']);

        return $limpia && ! self::isMetaHost($host) ? rtrim($base, '/') : null;
    }

    /** ¿Es un host de Meta (o de uno de sus subdominios)? Sin distinguir mayúsculas ni el punto final. */
    public static function isMetaHost(string $host): bool
    {
        $host = strtolower(rtrim(trim($host), '.'));
        foreach (self::META_DOMAINS as $dominio) {
            if ($host === $dominio || str_ends_with($host, '.'.$dominio)) {
                return true;
            }
        }

        return false;
    }

    private static function ms(int|float $inicio): int
    {
        return (int) round((hrtime(true) - $inicio) / 1_000_000);
    }
}
