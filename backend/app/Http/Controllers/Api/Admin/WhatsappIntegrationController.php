<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\WhatsappOnboardingException;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\WhatsappBusinessIntegration;
use App\Services\Meta\MetaAuthService;
use App\Services\Meta\WhatsappEmbeddedSignupService;
use App\Services\Meta\WhatsappIntegrationAuthorizationService;
use App\Services\Observability\ChannelLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración → Integraciones → WhatsApp Business.
 *
 * La entrada del onboarding oficial de Meta (Embedded Signup) desde dentro del
 * CRM. Tres endpoints y dos de mantenimiento:
 *
 *   GET    status      ¿hay cuenta conectada?, ¿cuál?, ¿qué puedo hacer yo?
 *   POST   start       parámetros del diálogo de Meta + `state` de un solo uso
 *   POST   callback    lo que devolvió el diálogo → canje del código y guardado
 *   POST   disconnect  soltar la cuenta (el histórico se conserva)
 *   POST   refresh     re-leer de Graph los datos del negocio conectado
 *
 * Autenticación: heredada del blindaje global de /api/admin/* (ProtectAdminPaths
 * → EnsureAdminAuth), que deja el Admin resuelto en `auth_admin`. Encima, cada
 * acción pasa por WhatsappIntegrationAuthorizationService, que exige sesión REAL
 * y rol pleno para conectar o desconectar.
 *
 * Nada de este controlador devuelve el App Secret ni el token de acceso. El
 * frontend recibe `app_id` y `config_id` —públicos por diseño, Meta los enseña
 * en el propio diálogo— y para el token solo el booleano de si existe.
 */
class WhatsappIntegrationController extends Controller
{
    public function __construct(
        private readonly WhatsappEmbeddedSignupService $signup,
        private readonly WhatsappIntegrationAuthorizationService $authz,
        private readonly MetaAuthService $auth,
    ) {}

    // ── Estado ────────────────────────────────────────────────────────────────

    /** GET /api/admin/integrations/whatsapp */
    public function status(Request $request): JsonResponse
    {
        if ($r = $this->guard($request, WhatsappIntegrationAuthorizationService::CAP_VIEW)) {
            return $r;
        }

        $integration = WhatsappBusinessIntegration::current();

        return response()->json([
            'ok' => true,
            'data' => [
                // 'connected' | 'not_connected'. El estado que pinta la pantalla.
                'status' => $integration ? 'connected' : 'not_connected',
                'integration' => $integration?->toPublicArray(),

                /*
                 * El estado del CANAL es una pregunta distinta de si hay cuenta
                 * conectada, y mezclarlas confunde a quien mira. Se puede estar
                 * conectado con el envío apagado —es el estado deseado mientras
                 * se verifica— y eso hay que poder decirlo sin que parezca avería.
                 */
                'channel' => [
                    'meta_enabled' => (bool) config('meta.enabled'),
                    'credential_source' => $this->auth->credentialSource(),
                    'can_send' => (bool) config('meta.enabled')
                        && (string) $this->auth->accessToken() !== ''
                        && (string) $this->auth->phoneNumberId() !== '',
                    'webhook_url' => rtrim((string) config('app.url'), '/').'/api/webhooks/meta',
                ],

                /*
                 * Si el servidor no puede ejecutar el onboarding, se dice AQUÍ y
                 * por nombre de variable. La alternativa —dejar que el botón
                 * abra una ventana de Meta que falla— convierte un problema de
                 * configuración de dos minutos en una sesión de depuración.
                 */
                'onboarding' => [
                    /*
                     * `available` responde a "¿se puede pulsar el botón?", que
                     * solo necesita los dos identificadores públicos. El App
                     * Secret se comprueba al canjear el código, y por eso puede
                     * faltar aquí y aparecer en `missing_configuration`: la
                     * pantalla enseña el botón Y el aviso a la vez, en vez de
                     * esconder uno de los dos.
                     */
                    'available' => $this->signup->canLaunch(),
                    'can_exchange' => $this->signup->canExchange(),
                    'missing_configuration' => $this->signup->missingConfiguration(),
                ],

                'capabilities' => $this->authz->frontendCapabilities($this->admin($request)),
            ],
        ]);
    }

    // ── 1. Iniciar conexión ───────────────────────────────────────────────────

    /** POST /api/admin/integrations/whatsapp/start */
    public function start(Request $request): JsonResponse
    {
        if ($r = $this->guard($request, WhatsappIntegrationAuthorizationService::CAP_CONNECT)) {
            return $r;
        }

        $admin = $this->admin($request);

        try {
            $config = $this->signup->launchConfig($admin);
        } catch (WhatsappOnboardingException $e) {
            return $this->fail($e);
        }

        ChannelLog::info('whatsapp.onboarding.started', [
            'admin_id' => $admin->id,
        ]);

        return response()->json(['ok' => true, 'data' => $config]);
    }

    // ── 2. Recibir el callback de Meta ────────────────────────────────────────

    /**
     * POST /api/admin/integrations/whatsapp/callback
     *
     * Lo llama el CRM con lo que le entregó el diálogo de Meta. El `code` es de
     * un solo uso y se canjea aquí, servidor contra servidor: nunca viaja el
     * App Secret al navegador.
     */
    public function callback(Request $request): JsonResponse
    {
        if ($r = $this->guard($request, WhatsappIntegrationAuthorizationService::CAP_CONNECT)) {
            return $r;
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'min:10', 'max:1000'],
            'state' => ['required', 'string', 'max:200'],
            'waba_id' => ['required', 'string', 'max:64', 'regex:/^[0-9]+$/'],
            'phone_number_id' => ['required', 'string', 'max:64', 'regex:/^[0-9]+$/'],
            'business_id' => ['nullable', 'string', 'max:64', 'regex:/^[0-9]+$/'],
        ], [
            'waba_id.regex' => 'El identificador del WABA debe ser numérico.',
            'phone_number_id.regex' => 'El identificador del número debe ser numérico.',
        ]);

        $admin = $this->admin($request);

        try {
            $this->signup->consumeState($data['state'], $admin);
            $integration = $this->signup->complete($data, $admin);
        } catch (WhatsappOnboardingException $e) {
            return $this->fail($e);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'status' => 'connected',
                'integration' => $integration->toPublicArray(),
                /*
                 * Conectar NO enciende el envío. Se avisa en la respuesta para
                 * que la pantalla pueda decirlo, en vez de dar por hecho que ya
                 * se puede escribir a clientes y descubrirlo cuando no salga.
                 */
                'meta_enabled' => (bool) config('meta.enabled'),
            ],
        ]);
    }

    // ── Mantenimiento ─────────────────────────────────────────────────────────

    /** POST /api/admin/integrations/whatsapp/disconnect */
    public function disconnect(Request $request): JsonResponse
    {
        if ($r = $this->guard($request, WhatsappIntegrationAuthorizationService::CAP_DISCONNECT)) {
            return $r;
        }

        try {
            $integration = $this->signup->disconnect($this->admin($request));
        } catch (WhatsappOnboardingException $e) {
            return $this->fail($e);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'status' => 'not_connected',
                'integration' => $integration->toPublicArray(),
            ],
        ]);
    }

    /** POST /api/admin/integrations/whatsapp/refresh */
    public function refresh(Request $request): JsonResponse
    {
        if ($r = $this->guard($request, WhatsappIntegrationAuthorizationService::CAP_VIEW)) {
            return $r;
        }

        $integration = WhatsappBusinessIntegration::current();

        if (! $integration) {
            return $this->fail(WhatsappOnboardingException::noConnection());
        }

        $synced = $this->signup->syncFromGraph($integration);

        return response()->json([
            'ok' => true,
            'data' => [
                'synced' => $synced,
                'integration' => $integration->refresh()->toPublicArray(),
            ],
        ]);
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    /** El admin de la sesión real. Null si se entró con el secreto compartido. */
    private function admin(Request $request): ?Admin
    {
        $admin = $request->attributes->get('auth_admin');

        return $admin instanceof Admin ? $admin : null;
    }

    private function guard(Request $request, string $capability): ?JsonResponse
    {
        $deny = $this->authz->deny($this->admin($request), $capability);

        if ($deny === null) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'code' => $deny['code'],
            'message' => $deny['message'],
        ], $deny['status']);
    }

    private function fail(WhatsappOnboardingException $e): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => $e->errorCode,
            'message' => $e->getMessage(),
            'context' => $e->context,
        ], $e->status);
    }
}
