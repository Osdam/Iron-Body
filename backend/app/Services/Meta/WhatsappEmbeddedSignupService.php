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
    public function launchConfig(Admin $admin): array
    {
        if (! $this->isConfigured()) {
            throw WhatsappOnboardingException::notConfigured();
        }

        return [
            'app_id' => (string) config('meta.app_id'),
            'config_id' => (string) config('meta.embedded_signup.config_id'),
            'graph_version' => (string) config('meta.graph_version'),
            'sdk_version' => (string) config('meta.embedded_signup.sdk_version'),
            'scopes' => (array) config('meta.embedded_signup.scopes', []),
            'feature_type' => (string) config('meta.embedded_signup.feature_type'),
            'state' => $this->issueState($admin),
            'state_ttl_minutes' => (int) config('meta.embedded_signup.state_ttl_minutes', 30),
        ];
    }

    /**
     * ¿El servidor tiene lo mínimo para ejecutar el onboarding?
     *
     * Los tres a la vez. Sin `config_id` el diálogo ni siquiera abre, y es la
     * pieza que hoy falta en esta app: se crea a mano en el panel de Meta y no
     * hay forma de generarla desde código.
     */
    public function isConfigured(): bool
    {
        return (string) config('meta.app_id') !== ''
            && (string) config('meta.app_secret') !== ''
            && (string) config('meta.embedded_signup.config_id') !== '';
    }

    /** Qué falta, por nombre de variable y sin valores, para poder decirlo en pantalla. */
    public function missingConfiguration(): array
    {
        $missing = [];
        if ((string) config('meta.app_id') === '') {
            $missing[] = 'META_APP_ID';
        }
        if ((string) config('meta.app_secret') === '') {
            $missing[] = 'META_APP_SECRET';
        }
        if ((string) config('meta.embedded_signup.config_id') === '') {
            $missing[] = 'META_EMBEDDED_SIGNUP_CONFIG_ID';
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
    public function issueState(Admin $admin): string
    {
        $state = Str::random(48);

        Cache::put(
            self::STATE_CACHE_PREFIX.hash('sha256', $state),
            ['admin_id' => $admin->id, 'issued_at' => now()->toIso8601String()],
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
    public function consumeState(?string $state, Admin $admin): void
    {
        if (! is_string($state) || $state === '') {
            throw WhatsappOnboardingException::invalidState();
        }

        $key = self::STATE_CACHE_PREFIX.hash('sha256', $state);
        $stored = Cache::pull($key);

        if (! is_array($stored) || (int) ($stored['admin_id'] ?? 0) !== $admin->id) {
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
    public function complete(array $payload, Admin $admin): WhatsappBusinessIntegration
    {
        if (! $this->isConfigured()) {
            throw WhatsappOnboardingException::notConfigured();
        }

        $wabaId = (string) $payload['waba_id'];
        $phoneNumberId = (string) $payload['phone_number_id'];

        $existing = WhatsappBusinessIntegration::query()
            ->where('waba_id', $wabaId)
            ->where('phone_number_id', $phoneNumberId)
            ->first();

        try {
            $token = $this->exchangeCode((string) $payload['code']);
        } catch (WhatsappOnboardingException $e) {
            // Un canje fallido NO puede tumbar una conexión que funcionaba: se
            // deja constancia del error y el estado anterior sigue rigiendo.
            $this->recordFailure($existing, $wabaId, $phoneNumberId, $payload, $admin, $e);

            throw $e;
        }

        $integration = WhatsappBusinessIntegration::updateOrCreate(
            ['waba_id' => $wabaId, 'phone_number_id' => $phoneNumberId],
            [
                'meta_app_id' => (string) config('meta.app_id'),
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
            'waba_id' => $wabaId,
            'phone_number_id' => $phoneNumberId,
            'admin_id' => $admin->id,
            'granted_scopes' => $integration->granted_scopes ?? [],
        ]);

        return $integration->refresh();
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
                    'client_id' => (string) config('meta.app_id'),
                    'client_secret' => (string) config('meta.app_secret'),
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
                    'access_token' => config('meta.app_id').'|'.config('meta.app_secret'),
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
    public function disconnect(Admin $admin): WhatsappBusinessIntegration
    {
        $integration = WhatsappBusinessIntegration::current();

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
    ): void {
        if ($existing) {
            $existing->forceFill([
                'last_error_code' => $e->errorCode,
                'last_error_message' => $e->getMessage(),
            ])->save();

            return;
        }

        WhatsappBusinessIntegration::create([
            'meta_app_id' => (string) config('meta.app_id'),
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
