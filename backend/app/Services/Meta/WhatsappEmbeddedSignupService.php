<?php

namespace App\Services\Meta;

use App\Exceptions\WhatsappOnboardingException;
use App\Models\Admin;
use App\Models\WhatsappBusinessIntegration;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Embedded Signup de Meta: el onboarding oficial de WhatsApp Business, empezando
 * DENTRO del CRM.
 *
 * El recorrido completo, y quién hace cada tramo:
 *
 *   CRM  ─(1)─►  este servicio: entrega app_id, config_id y un `state`
 *   CRM  ─(2)─►  diálogo de Meta (SDK de JS): el dueño elige negocio y número
 *   Meta ─(3)─►  CRM: devuelve un `code` de un solo uso + los ids elegidos
 *   CRM  ─(4)─►  este servicio: canjea el code por un token y persiste
 *
 * El App Secret nunca sale del backend: el paso 4 es el único que lo usa y
 * ocurre servidor contra servidor. El navegador solo maneja `app_id` y
 * `config_id`, que Meta publica igualmente en el propio diálogo.
 *
 * Por qué coexistencia y no el registro tradicional: dar de alta el número por
 * la vía clásica lo SACA de la app WhatsApp Business y el personal pierde
 * WhatsApp Web. Ya pasó una vez en este negocio (2026-06-30) y hubo que
 * deshacerlo. Ver docs/marketing-meta-whatsapp.md §8.
 */
class WhatsappEmbeddedSignupService
{
    private const STATE_CACHE_PREFIX = 'wa:embedded-signup:state:';

    public function __construct(
        private readonly MetaAuthService $auth,
        private readonly WhatsappIntegrationRegistry $registry,
    ) {}

    // ── 1. Arranque: lo que el CRM necesita para abrir el diálogo ─────────────

    /**
     * Parámetros públicos del diálogo + un `state` de un solo uso.
     *
     * @return array<string,mixed>
     *
     * @throws WhatsappOnboardingException si falta configuración del servidor.
     */
    public function launchConfig(Admin $admin, string $purpose = WhatsappBusinessIntegration::PURPOSE_PRODUCTION): array
    {
        $this->assertPurposeUsable($purpose);

        if (! $this->canLaunch()) {
            throw WhatsappOnboardingException::notConfigured();
        }

        return [
            // La app de Embedded Signup, que NO tiene por qué ser la del canal.
            'app_id' => (string) config('meta.embedded_signup.app_id'),
            'config_id' => (string) config('meta.embedded_signup.config_id'),
            'graph_version' => (string) config('meta.graph_version'),
            'sdk_version' => (string) config('meta.embedded_signup.sdk_version'),
            'scopes' => (array) config('meta.embedded_signup.scopes', []),
            'purpose' => $purpose,
            /*
             * La ÚNICA diferencia entre los dos modos.
             *
             * Producción pide coexistencia (`whatsapp_business_app_onboarding`),
             * que es lo que conserva el número en la app WhatsApp Business. Ese
             * parámetro activa el emparejamiento con Cloud API, y Meta lo corta
             * con el error #4563039 mientras no haya Advanced Access — que es
             * justo lo que se está pidiendo en la revisión.
             *
             * El modo demostración ejecuta el MISMO Embedded Signup sin pedir
             * ese emparejamiento, para poder enseñar de verdad la selección y
             * autorización de activos del negocio. No es una simulación: cambia
             * lo que se le pide a Meta, no lo que se le enseña al revisor.
             */
            'feature_type' => $purpose === WhatsappBusinessIntegration::PURPOSE_REVIEW
                ? null
                : (string) config('meta.embedded_signup.feature_type'),
            'state' => $this->issueState($admin, $purpose),
            'state_ttl_minutes' => (int) config('meta.embedded_signup.state_ttl_minutes', 30),
        ];
    }

    /**
     * Propósitos válidos, y si ese propósito está habilitado ahora mismo.
     *
     * @throws WhatsappOnboardingException
     */
    public function assertPurposeUsable(string $purpose): void
    {
        if ($purpose === WhatsappBusinessIntegration::PURPOSE_REVIEW) {
            if (! $this->reviewEnabled()) {
                throw WhatsappOnboardingException::reviewModeDisabled();
            }

            return;
        }

        if ($purpose !== WhatsappBusinessIntegration::PURPOSE_PRODUCTION) {
            throw new WhatsappOnboardingException(
                'Propósito de conexión desconocido.',
                'unknown_purpose',
                422,
            );
        }
    }

    /** ¿El modo demostración para la revisión de Meta está habilitado? */
    public function reviewEnabled(): bool
    {
        return (bool) config('meta.embedded_signup.review.enabled', false);
    }

    /**
     * ¿Se puede ABRIR el diálogo de Meta?
     *
     * Solo hacen falta los dos identificadores públicos. El secreto no
     * interviene en el navegador, y separar esta pregunta permite comprobar que
     * el diálogo arranca con la app correcta antes de tener el secreto puesto.
     *
     * Meta exige además que el `config_id` PERTENEZCA a este `app_id`: cruzados,
     * el diálogo responde «Función no disponible» y no devuelve ningún código.
     */
    public function canLaunch(): bool
    {
        return (string) config('meta.embedded_signup.app_id') !== ''
            && (string) config('meta.embedded_signup.config_id') !== '';
    }

    /**
     * ¿Se puede CANJEAR el código por un token?
     *
     * Exige el App Secret de la MISMA app que abrió el diálogo. Con el de otra
     * app, Meta rechaza el canje —y lo hace al final del recorrido, cuando
     * alguien ya autorizó todo, que es el peor momento para descubrirlo.
     */
    public function canExchange(): bool
    {
        return (string) config('meta.embedded_signup.app_secret') !== '';
    }

    /** ¿El onboarding puede completarse de principio a fin? */
    public function isConfigured(): bool
    {
        return $this->canLaunch() && $this->canExchange();
    }

    /** Qué falta, por nombre de variable y sin valores, para poder decirlo en pantalla. */
    public function missingConfiguration(): array
    {
        $missing = [];

        if ((string) config('meta.embedded_signup.app_id') === '') {
            $missing[] = 'META_EMBEDDED_SIGNUP_APP_ID';
        }
        if ((string) config('meta.embedded_signup.config_id') === '') {
            $missing[] = 'META_EMBEDDED_SIGNUP_CONFIG_ID';
        }
        if (! $this->canExchange()) {
            /*
             * Se nombra la variable dedicada, no META_APP_SECRET: cuando el
             * Embedded Signup corre en otra app, poner ahí el secreto del canal
             * no arregla nada y de paso arriesga la firma del webhook.
             */
            $missing[] = 'META_EMBEDDED_SIGNUP_APP_SECRET';
        }

        return $missing;
    }

    /**
     * Emite un `state` ligado al admin que lo pidió.
     *
     * El SDK de Meta no devuelve el `state` por sí solo, así que lo devuelve el
     * propio CRM al enviar el código. Sirve para lo que sirve: que un código
     * capturado no pueda canjearlo otra sesión ni reutilizarse dos veces.
     */
    public function issueState(Admin $admin, string $purpose = WhatsappBusinessIntegration::PURPOSE_PRODUCTION): string
    {
        $state = Str::random(48);

        Cache::put(
            self::STATE_CACHE_PREFIX.hash('sha256', $state),
            [
                'admin_id' => $admin->id,
                // El propósito viaja EN el state. Sin esto, un onboarding
                // iniciado como demostración podría cerrarse como producción y
                // colar una WABA de prueba en el canal.
                'purpose' => $purpose,
                'issued_at' => now()->toIso8601String(),
            ],
            now()->addMinutes((int) config('meta.embedded_signup.state_ttl_minutes', 30)),
        );

        return $state;
    }

    /**
     * Valida y QUEMA el state. Un state solo vale una vez: si el navegador
     * reenvía la misma respuesta —cosa que pasa con un doble clic o un
     * reintento automático—, el segundo intento se rechaza en vez de repetir el
     * canje contra Meta con un código ya gastado.
     */
    public function consumeState(?string $state, Admin $admin, string $purpose = WhatsappBusinessIntegration::PURPOSE_PRODUCTION): void
    {
        if (! is_string($state) || $state === '') {
            throw WhatsappOnboardingException::invalidState();
        }

        $key = self::STATE_CACHE_PREFIX.hash('sha256', $state);
        $stored = Cache::pull($key);

        if (! is_array($stored) || (int) ($stored['admin_id'] ?? 0) !== $admin->id) {
            throw WhatsappOnboardingException::invalidState();
        }

        // Empezar como demostración y terminar como producción (o al revés) es
        // exactamente la vía por la que una WABA de prueba acabaría operando el
        // canal. El state los mantiene atados.
        if (($stored['purpose'] ?? WhatsappBusinessIntegration::PURPOSE_PRODUCTION) !== $purpose) {
            throw WhatsappOnboardingException::invalidState();
        }
    }

    // ── 2. Cierre: canje del código y persistencia ────────────────────────────

    /**
     * Completa la conexión con lo que devolvió el diálogo de Meta.
     *
     * @param  array{code:string,waba_id:string,phone_number_id:string,business_id?:string|null}  $payload
     *
     * @throws WhatsappOnboardingException
     */
    public function complete(
        array $payload,
        Admin $admin,
        string $purpose = WhatsappBusinessIntegration::PURPOSE_PRODUCTION,
    ): WhatsappBusinessIntegration {
        $this->assertPurposeUsable($purpose);

        // Aquí sí hace falta el secreto: se comprueba ANTES de gastar contra
        // Meta un código que solo sirve una vez.
        if (! $this->isConfigured()) {
            throw WhatsappOnboardingException::notConfigured();
        }

        $wabaId = (string) $payload['waba_id'];
        $phoneNumberId = (string) $payload['phone_number_id'];

        $existing = WhatsappBusinessIntegration::query()
            ->where('waba_id', $wabaId)
            ->where('phone_number_id', $phoneNumberId)
            ->first();

        /*
         * Conflicto de propósito. Se comprueba ANTES de canjear —y no después,
         * como pedía el orden original— porque el código sirve una sola vez:
         * rechazar después obligaría a repetir el diálogo de Meta entero por
         * algo que ya se sabía. No persiste nada, así que la garantía se
         * mantiene igual.
         */
        if ($existing !== null && (string) $existing->purpose !== $purpose) {
            throw WhatsappOnboardingException::purposeConflict((string) $existing->purpose, $purpose);
        }

        try {
            $token = $this->exchangeCode((string) $payload['code']);
        } catch (WhatsappOnboardingException $e) {
            // Un canje fallido NO puede tumbar una conexión que funcionaba: se
            // deja constancia del error y el estado anterior sigue rigiendo.
            $this->recordFailure($existing, $wabaId, $phoneNumberId, $payload, $admin, $e, $purpose);

            throw $e;
        }

        /*
         * Con el token recién obtenido se le pregunta a Meta QUÉ número es, y se
         * comprueba contra la lista protegida ANTES de escribir una sola fila.
         * Es el último punto en el que una demostración puede pararse sin haber
         * dejado rastro.
         */
        $this->assertNumberAllowedFor($purpose, $token['access_token'], $phoneNumberId);

        $integration = WhatsappBusinessIntegration::updateOrCreate(
            ['waba_id' => $wabaId, 'phone_number_id' => $phoneNumberId],
            [
                'purpose' => $purpose,
                'meta_app_id' => (string) config('meta.embedded_signup.app_id'),
                'business_id' => $payload['business_id'] ?? $existing?->business_id,
                'status' => WhatsappBusinessIntegration::STATUS_CONNECTED,
                'access_token' => $token['access_token'],
                'token_type' => $token['token_type'],
                'token_expires_at' => $token['expires_at'],
                'granted_scopes' => $token['scopes'],
                'connected_by' => $admin->id,
                'connected_at' => now(),
                'disconnected_at' => null,
                'disconnected_by' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ],
        );

        // Lo de abajo ENRIQUECE, no condiciona. El nombre del negocio y la
        // calidad del número son para que un humano confirme que eligió el
        // correcto; si Graph no los da —permiso aún sin aprobar, por ejemplo—,
        // la conexión sigue siendo válida y se puede operar con ella.
        $this->syncFromGraph($integration);
        $this->subscribeApp($integration);

        $this->registry->forget();

        ChannelLog::info('whatsapp.onboarding.connected', [
            'integration_id' => $integration->id,
            'purpose' => $purpose,
            'waba_id' => $wabaId,
            'phone_number_id' => $phoneNumberId,
            'admin_id' => $admin->id,
            'granted_scopes' => $integration->granted_scopes ?? [],
        ]);

        return $integration->refresh();
    }

    /**
     * Barrera del número protegido.
     *
     * Solo se aplica al modo DEMOSTRACIÓN, y esa distinción es deliberada: el
     * número del gimnasio es precisamente el que la coexistencia productiva
     * debe acabar conectando —esa es toda la razón de existir de este módulo—,
     * así que bloquearlo también ahí rompería el objetivo final. Lo que no
     * puede pasar es que aparezca en una demostración, donde el onboarding
     * estándar sí lo registraría en Cloud API y se lo quitaría al personal.
     *
     * @throws WhatsappOnboardingException
     */
    private function assertNumberAllowedFor(string $purpose, string $accessToken, string $phoneNumberId): void
    {
        if ($purpose !== WhatsappBusinessIntegration::PURPOSE_REVIEW) {
            return;
        }

        $protegidos = array_filter((array) config('meta.protected_numbers', []));
        if ($protegidos === []) {
            return;
        }

        // Comprobación barata primero: el identificador del número productivo
        // no necesita preguntarle nada a la red.
        $productivo = (string) config('meta.whatsapp_phone_number_id');
        if ($productivo !== '' && hash_equals($productivo, $phoneNumberId)) {
            throw WhatsappOnboardingException::protectedNumber($productivo);
        }

        $telefono = $this->fetchPhoneNumber($accessToken, $phoneNumberId);
        $mostrado = (string) ($telefono['display_phone_number'] ?? '');
        $digitos = preg_replace('/\D+/', '', $mostrado) ?? '';

        if ($digitos !== '' && in_array($digitos, $protegidos, true)) {
            throw WhatsappOnboardingException::protectedNumber($mostrado);
        }
    }

    /**
     * Lee un número de WhatsApp desde Graph. Devuelve [] si no se puede.
     *
     * @return array<string,mixed>
     */
    private function fetchPhoneNumber(string $accessToken, string $phoneNumberId): array
    {
        try {
            $r = Http::timeout($this->auth->timeout())
                ->withToken($accessToken)
                ->get($this->auth->graphUrl($phoneNumberId), [
                    'fields' => 'id,display_phone_number,verified_name,quality_rating,platform_type',
                ]);

            return $r->successful() ? (array) $r->json() : [];
        } catch (Throwable $e) {
            ChannelLog::info('whatsapp.onboarding.phone_read_failed', [
                'error_class' => class_basename($e),
            ]);

            return [];
        }
    }

    /**
     * Canjea el código de un solo uso por un token de negocio.
     *
     * @return array{access_token:string,token_type:?string,expires_at:?Carbon,scopes:array<int,string>}
     *
     * @throws WhatsappOnboardingException
     */
    public function exchangeCode(string $code): array
    {
        try {
            $response = Http::timeout($this->auth->timeout())
                ->get($this->auth->graphUrl('oauth/access_token'), [
                    'client_id' => (string) config('meta.embedded_signup.app_id'),
                    'client_secret' => (string) config('meta.embedded_signup.app_secret'),
                    'code' => $code,
                ]);
        } catch (Throwable $e) {
            ChannelLog::error('whatsapp.onboarding.exchange_transport_error', [
                'error_class' => class_basename($e),
            ]);

            throw WhatsappOnboardingException::exchangeFailed(
                'no se pudo contactar con Meta ('.class_basename($e).').',
                ['transport' => class_basename($e)],
            );
        }

        if (! $response->successful()) {
            $error = (array) $response->json('error', []);
            // Se registra el error de Meta, nunca el código ni el secreto.
            ChannelLog::warning('whatsapp.onboarding.exchange_rejected', [
                'http_status' => $response->status(),
                'error_code' => $error['code'] ?? null,
                'error_type' => $error['type'] ?? null,
                'error_subcode' => $error['error_subcode'] ?? null,
            ]);

            throw WhatsappOnboardingException::exchangeFailed(
                (string) ($error['message'] ?? 'respuesta HTTP '.$response->status()),
                [
                    'http_status' => $response->status(),
                    'error_code' => $error['code'] ?? null,
                    'error_type' => $error['type'] ?? null,
                ],
            );
        }

        $accessToken = (string) $response->json('access_token');
        if ($accessToken === '') {
            throw WhatsappOnboardingException::exchangeFailed('la respuesta no incluyó ningún token.');
        }

        $expiresIn = $response->json('expires_in');

        return [
            'access_token' => $accessToken,
            'token_type' => $response->json('token_type'),
            // Meta omite `expires_in` en los tokens de larga duración. Ausente
            // NO es cero: significa "sin caducidad declarada".
            'expires_at' => is_numeric($expiresIn) && (int) $expiresIn > 0
                ? now()->addSeconds((int) $expiresIn)
                : null,
            'scopes' => $this->grantedScopes($accessToken),
        ];
    }

    /**
     * Permisos que Meta concedió DE VERDAD.
     *
     * Pedir tres y recibir dos es lo normal mientras la App Review está en
     * curso, y la diferencia explica por qué una llamada concreta falla. Es
     * mejor esfuerzo: si no se puede consultar, se guarda vacío y ya.
     *
     * @return array<int,string>
     */
    private function grantedScopes(string $userToken): array
    {
        try {
            $response = Http::timeout($this->auth->timeout())
                ->get($this->auth->graphUrl('debug_token'), [
                    'input_token' => $userToken,
                    'access_token' => config('meta.embedded_signup.app_id').'|'.config('meta.embedded_signup.app_secret'),
                ]);

            if (! $response->successful()) {
                return [];
            }

            $scopes = $response->json('data.scopes');

            return is_array($scopes) ? array_values(array_map('strval', $scopes)) : [];
        } catch (Throwable) {
            return [];
        }
    }

    // ── 3. Enriquecimiento y mantenimiento ────────────────────────────────────

    /**
     * Refresca desde Graph los datos que un humano necesita para reconocer la
     * cuenta: nombre del negocio, nombre verificado, teléfono visible, calidad.
     *
     * Best-effort a propósito. Devuelve true si consiguió algo.
     */
    public function syncFromGraph(WhatsappBusinessIntegration $integration): bool
    {
        $token = (string) $integration->access_token;
        if ($token === '') {
            return false;
        }

        $changed = [];

        try {
            $waba = Http::timeout($this->auth->timeout())
                ->withToken($token)
                ->get($this->auth->graphUrl((string) $integration->waba_id), [
                    'fields' => 'id,name,account_review_status,owner_business_info',
                ]);

            if ($waba->successful()) {
                $business = (array) $waba->json('owner_business_info', []);
                $changed['business_name'] = $waba->json('name');
                if (! empty($business['id'])) {
                    $changed['business_id'] = (string) $business['id'];
                }
                if (! empty($business['name'])) {
                    $changed['business_name'] = (string) $business['name'];
                }
            }
        } catch (Throwable $e) {
            ChannelLog::info('whatsapp.onboarding.waba_sync_failed', [
                'integration_id' => $integration->id,
                'error_class' => class_basename($e),
            ]);
        }

        try {
            $phone = Http::timeout($this->auth->timeout())
                ->withToken($token)
                ->get($this->auth->graphUrl((string) $integration->phone_number_id), [
                    'fields' => 'id,display_phone_number,verified_name,quality_rating,platform_type',
                ]);

            if ($phone->successful()) {
                $changed['display_phone_number'] = $phone->json('display_phone_number');
                $changed['verified_name'] = $phone->json('verified_name');
                $changed['quality_rating'] = $phone->json('quality_rating');
                $changed['platform_type'] = $phone->json('platform_type');
            }
        } catch (Throwable $e) {
            ChannelLog::info('whatsapp.onboarding.phone_sync_failed', [
                'integration_id' => $integration->id,
                'error_class' => class_basename($e),
            ]);
        }

        $changed = array_filter($changed, static fn ($v) => $v !== null && $v !== '');
        if ($changed === []) {
            return false;
        }

        $integration->forceFill($changed + ['last_synced_at' => now()])->save();

        return true;
    }

    /**
     * Suscribe la app al WABA para que empiecen a llegar los webhooks.
     *
     * Sin esto la conexión figura como correcta y no entra ni un mensaje, que es
     * el fallo más caro de diagnosticar de todo el canal porque no da error en
     * ninguna parte: simplemente no pasa nada.
     */
    public function subscribeApp(WhatsappBusinessIntegration $integration): bool
    {
        if (! (bool) config('meta.embedded_signup.subscribe_app', true)) {
            return false;
        }

        $token = (string) $integration->access_token;
        if ($token === '') {
            return false;
        }

        try {
            $response = Http::timeout($this->auth->timeout())
                ->withToken($token)
                ->post($this->auth->graphUrl($integration->waba_id.'/subscribed_apps'));

            $ok = $response->successful() && $response->json('success') !== false;

            ChannelLog::info('whatsapp.onboarding.subscribe_app', [
                'integration_id' => $integration->id,
                'ok' => $ok,
                'http_status' => $response->status(),
                'error_code' => $response->json('error.code'),
            ]);

            return $ok;
        } catch (Throwable $e) {
            ChannelLog::warning('whatsapp.onboarding.subscribe_app_failed', [
                'integration_id' => $integration->id,
                'error_class' => class_basename($e),
            ]);

            return false;
        }
    }

    /**
     * Desconecta la cuenta vigente. NO borra la fila: el histórico de qué número
     * estuvo conectado y cuándo es lo primero que se consulta cuando algo dejó
     * de llegar. El token sí se destruye — una credencial que ya no se usa no
     * debe seguir guardada.
     */
    public function disconnect(
        Admin $admin,
        string $purpose = WhatsappBusinessIntegration::PURPOSE_PRODUCTION,
    ): WhatsappBusinessIntegration {
        $this->assertPurposeUsable($purpose);

        /*
         * Cada modo resuelve SU conexión y solo la suya. `current()` ya no ve
         * las de demostración y `currentReview()` no ve las de producción, así
         * que el botón de la tarjeta de revisión no puede desconectar el canal
         * ni por error ni manipulando la petición.
         */
        $integration = $purpose === WhatsappBusinessIntegration::PURPOSE_REVIEW
            ? WhatsappBusinessIntegration::currentReview()
            : WhatsappBusinessIntegration::current();

        if (! $integration) {
            throw WhatsappOnboardingException::noConnection();
        }

        $integration->forceFill([
            'status' => WhatsappBusinessIntegration::STATUS_DISCONNECTED,
            'access_token' => null,
            'token_type' => null,
            'token_expires_at' => null,
            'disconnected_by' => $admin->id,
            'disconnected_at' => now(),
        ])->save();

        $this->registry->forget();

        ChannelLog::info('whatsapp.onboarding.disconnected', [
            'integration_id' => $integration->id,
            'purpose' => $purpose,
            'admin_id' => $admin->id,
        ]);

        return $integration->refresh();
    }

    /**
     * Deja constancia de un intento fallido sin pisar una conexión que funciona.
     */
    private function recordFailure(
        ?WhatsappBusinessIntegration $existing,
        string $wabaId,
        string $phoneNumberId,
        array $payload,
        Admin $admin,
        WhatsappOnboardingException $e,
        string $purpose = WhatsappBusinessIntegration::PURPOSE_PRODUCTION,
    ): void {
        if ($existing) {
            $existing->forceFill([
                'last_error_code' => $e->errorCode,
                'last_error_message' => $e->getMessage(),
            ])->save();

            return;
        }

        WhatsappBusinessIntegration::create([
            'purpose' => $purpose,
            'meta_app_id' => (string) config('meta.embedded_signup.app_id'),
            'business_id' => $payload['business_id'] ?? null,
            'waba_id' => $wabaId,
            'phone_number_id' => $phoneNumberId,
            'status' => WhatsappBusinessIntegration::STATUS_ERROR,
            'connected_by' => $admin->id,
            'last_error_code' => $e->errorCode,
            'last_error_message' => $e->getMessage(),
        ]);
    }
}
