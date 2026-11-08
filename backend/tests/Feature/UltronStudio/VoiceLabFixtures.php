<?php

namespace Tests\Feature\UltronStudio;

use App\Http\Controllers\Crm\UltronStudio\UltronStudioVoiceLabController;
use App\Http\Middleware\EnforceAdminAuthorization;
use App\Http\Middleware\LimitRequestBodySize;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\RolePermission;
use App\Models\UltronStudio\StudioVersion;
use App\Services\UltronStudio\StudioIntegrationConfig;
use App\Services\UltronStudio\StudioVoiceCompiler;
use App\Services\UltronStudio\StudioVoiceTemplate;
use App\Services\Voice\Dto\VoiceWebhookVerdict;
use App\Services\Voice\VoiceCheckResult;
use App\Support\Access\AuthorizationMap;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Datos, configuración y peticiones del LABORATORIO HABLADO (contrato H8,
 * D8-23) para las pruebas Feature. Se usa junto a {@see StudioFixtures} (las
 * peticiones van con la sesión REAL de cada persona) y a
 * {@see PublicationFixtures} (versiones aprobadas por la API).
 *
 * LAS RUTAS. Son de S8-b (`routes/ultron-studio.php`) y las clasifica B8-b
 * (`AuthorizationMap`, con la llave nueva `ultron.voice_lab_run`): LAB8 no toca
 * ninguno de los dos ficheros. Hasta que estén, {@see voiceLabRoutes()} monta
 * EXACTAMENTE las de {@see VOICE_LAB_ROUTES} (las que LAB8 entrega a S8-b) en
 * el mismo grupo `api` y con el tope de 64 KB; si la puerta todavía no las
 * clasifica, la quita en la prueba (una ruta sin clasificar se deniega a
 * todos) y queda la defensa en profundidad del controlador, que comprueba la
 * persona y la llave por su cuenta. Con las rutas de S8-b y la clasificación
 * de B8-b ya en el árbol, no monta nada ni quita nada: las pruebas recorren la
 * pila real. `UltronStudioVoiceLabSessionsTest` compara la tabla real con
 * {@see VOICE_LAB_ROUTES} y, si la puerta ya las clasifica, sus llaves.
 *
 * LA CONFIGURACIÓN se fija con `config()` en cada prueba: la suite no depende
 * del `.env`. La clave de ElevenLabs es una CENTINELA que no puede aparecer en
 * ninguna respuesta ni fila; lo mismo la firma de la URL firmada, que solo
 * puede ir en la respuesta que abre la sesión.
 */
trait VoiceLabFixtures
{
    protected const VOICE_SIM = 'http://127.0.0.1:8096';

    protected const VOICE_KEY = 'xi-centinela-LABORATORIO-HABLADO-0123456789';

    protected const URL_SIGNATURE = 'FIRMA-CENTINELA-LAB-8f7e6d5c4b3a2190';

    protected const WEBHOOK_BASE = 'https://crm.ironbody.test';

    /**
     * Las rutas del laboratorio hablado que añade S8-b, con su llave
     * (`AuthorizationMap`) y sus limitadores: [método, ruta bajo
     * /api/admin/ultron-studio, método del controlador, llave, limitadores].
     */
    protected const VOICE_LAB_ROUTES = [
        ['GET', 'voice-lab/status', 'status', 'ultron.view', ['throttle:ultron-studio-read']],
        ['GET', 'voice-lab/sessions', 'index', 'ultron.view', ['throttle:ultron-studio-read']],
        ['GET', 'voice-lab/sessions/{uuid}', 'show', 'ultron.view', ['throttle:ultron-studio-read']],
        ['POST', 'voice-lab/sessions', 'store', 'ultron.voice_lab_run', ['throttle:ultron-studio-write', 'throttle:ultron-studio-lab-run']],
        ['POST', 'voice-lab/sessions/{uuid}/end', 'end', 'ultron.voice_lab_run', ['throttle:ultron-studio-write']],
        ['POST', 'voice-lab/sessions/{uuid}/verdict', 'verdict', 'ultron.approve', ['throttle:ultron-studio-write']],
    ];

    /** @var array{tools:array<string,array<string,mixed>>,agents:array<string,array<string,mixed>>,n:int,drift:bool,urlStatus:int,createStatus:int} */
    protected array $sim = ['tools' => [], 'agents' => [], 'n' => 0, 'drift' => false, 'urlStatus' => 200, 'createStatus' => 200];

    /** Las rutas del laboratorio hablado: las de S8-b si ya están; si no, las que LAB8 le entrega. */
    protected function voiceLabRoutes(): void
    {
        if ($this->voiceLabRoute('POST', 'voice-lab/sessions') === null) {
            Route::middleware('api')->prefix('api')->group(function (): void {
                Route::prefix('admin/ultron-studio')->middleware(LimitRequestBodySize::class.':65536')->group(function (): void {
                    foreach (self::VOICE_LAB_ROUTES as [$metodo, $ruta, $accion, , $limitadores]) {
                        $r = Route::match([$metodo], $ruta, [UltronStudioVoiceLabController::class, $accion])->middleware($limitadores);
                        if (str_contains($ruta, '{uuid}')) {
                            $r->whereUuid('uuid');
                        }
                    }
                });
            });
        }

        // Una ruta administrativa sin clasificar se deniega a todos: hasta que
        // B8-b clasifique estas, la puerta se quita y el controlador exige por
        // su cuenta la persona y la llave.
        foreach (self::VOICE_LAB_ROUTES as [$metodo, $ruta]) {
            if (AuthorizationMap::resolve($this->voiceLabRoute($metodo, $ruta)) === null) {
                $this->withoutMiddleware(EnforceAdminAuthorization::class);

                break;
            }
        }
    }

    protected function voiceLabRoute(string $method, string $path): ?RouteObject
    {
        foreach (Route::getRoutes()->getRoutes() as $r) {
            if ($r->uri() === 'api/admin/ultron-studio/'.$path && in_array($method, $r->methods(), true)) {
                return $r;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $body */
    protected function voiceLab(string $method, string $path, Admin $as, array $body = []): TestResponse
    {
        return $this->studio($method, 'voice-lab/'.ltrim($path, '/'), $as, $body);
    }

    /**
     * Una persona con un rol a medida que tiene EXACTAMENTE estas llaves.
     *
     * @param  list<string>  $permissions
     */
    protected function labPerson(array $permissions, string $name): Admin
    {
        $rol = 'H8L-'.substr(md5(implode(',', $permissions)), 0, 10);
        AdminRole::firstOrCreate(['name' => $rol], ['is_system' => false]);
        foreach ($permissions as $p) {
            RolePermission::updateOrCreate(['role' => $rol, 'permission' => $p], ['granted' => true]);
        }
        app(RolePermissionPolicy::class)->flush();

        return $this->person($rol, $name);
    }

    /** Quien prueba: ver el Studio y abrir sesiones (gasta). */
    protected function tester(string $name = 'Tina Prueba'): Admin
    {
        return $this->labPerson(['ultron.view', 'ultron.voice_lab_run'], $name);
    }

    /** Quien emite el veredicto: ver y aprobar. */
    protected function reviewer(string $name = 'Rita Revisa'): Admin
    {
        return $this->labPerson(['ultron.view', 'ultron.approve'], $name);
    }

    // ── Configuración ───────────────────────────────────────────────────────

    protected function voiceLabOff(): void
    {
        config(['voice.lab.enabled' => false]);
    }

    /**
     * Encendido en el SIMULADOR: sin ElevenLabs, sin red y sin coste.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function voiceLabFake(array $extra = []): void
    {
        config($extra + [
            'voice.lab.enabled' => true,
            'voice.lab.driver' => 'fake',
            'voice.lab.daily_budget_usd' => '0',
            'voice.lab.session_budget_usd' => '0',
            'voice.prices.elevenlabs' => null,
            'voice.lab.max_session_seconds' => 300,
            'voice.lab.max_sessions_per_day' => 5,
        ]);
    }

    /**
     * Encendido con ElevenLabs, como quedaría en la fase 2: la configuración
     * de producción (E1-12) apuntando al simulador local, las referencias de
     * los secretos, la base de los webhooks, precio (0,10 US$/min) y
     * presupuesto (2 US$ al día, 0,50 por sesión). La credencial NO queda
     * verificada: eso es {@see voiceVerified()}.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function voiceLabElevenLabs(array $extra = []): void
    {
        $this->elevenLabsLikeProduction();
        config($extra + [
            'voice.webhook_base_url' => self::WEBHOOK_BASE,
            'voice.limits.per_call_max_seconds' => 300,
            'voice.lab.enabled' => true,
            'voice.lab.driver' => 'elevenlabs',
            'voice.lab.daily_budget_usd' => '2',
            'voice.lab.session_budget_usd' => '0.50',
            'voice.prices.elevenlabs' => 100000,
            'voice.lab.max_session_seconds' => 300,
            'voice.lab.max_sessions_per_day' => 5,
        ]);
    }

    /** ElevenLabs configurado y encendido como en producción desde el 03-11 (E1-12), contra el simulador. */
    protected function elevenLabsLikeProduction(): void
    {
        config(['voice.elevenlabs' => [
            'enabled' => true,
            'api_key' => self::VOICE_KEY,
            'agent_id' => 'agent_verify_0001',
            'webhook_secret' => 'whsec-centinela-laboratorio',
            'region' => 'default',
            'agent_phone_number_id' => null,
            'tool_secret' => 'tool-centinela-laboratorio',
            'initiation_secret' => 'init-centinela-laboratorio',
            'tool_secret_ref' => 'secref_tools_0001',
            'initiation_secret_ref' => 'secref_init_0001',
            'post_call_webhook_ref' => 'whref_post_0001',
            'base_url' => self::VOICE_SIM,
            'timeout_seconds' => 5,
        ]]);
    }

    /** La evidencia de una verificación `ok` de hoy: con la bandera y las piezas, `ELEVENLABS_VERIFIED`. */
    protected function voiceVerified(): void
    {
        $this->assertNotNull(app(StudioIntegrationConfig::class)->recordVoiceVerification(
            new VoiceCheckResult(VoiceCheckResult::OUTCOME_CREDENTIALS_OK, 200, true, 120),
        ));
    }

    // ── Versiones y perfiles ────────────────────────────────────────────────

    /** Una versión enviada a revisión por la API (el laboratorio la admite: D8-23). */
    protected function labVersion(Admin $author): StudioVersion
    {
        $uuid = $this->submittedVersion($author, 'Voz en pruebas');

        return StudioVersion::query()->with('review')->where('uuid', $uuid)->sole();
    }

    /**
     * El contenido cerrado de un perfil de voz (D8-6), como el del compilador
     * de S8-a.
     *
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    protected function profileContent(array $over = []): array
    {
        return array_replace_recursive([
            'format' => StudioVoiceCompiler::PROFILE_FORMAT,
            'voiceId' => 'pNInz6obpgDQGcFmaJgB',
            'language' => 'es',
            'aiNotice' => StudioVoiceTemplate::AI_NOTICE,
            'greetings' => ['inbound' => '¿En qué te puedo ayudar hoy?', 'outbound' => 'Te llamo porque pediste información de Iron Body.'],
            'holes' => ['presentation' => 'Te ayudo con planes, horarios y la sede.', 'closing' => 'Gracias por tu tiempo.'],
            'maxDurationSeconds' => 180,
            'tools' => ['consultar_planes', 'consultar_horarios', 'no_volver_a_llamar'],
            'systemTools' => ['end_call'],
            'transfer' => ['destinations' => []],
            'privacy' => ['retentionDays' => 30, 'storeAudio' => false, 'recording' => false],
            'voicemail' => 'hang_up',
        ], $over);
    }

    /**
     * Un perfil de voz SEMBRADO en su tabla (el servicio es de S8-b), por su
     * camino: nace `draft` y avanza a `submitted` y, con otra persona, a
     * `approved` o `rejected`. Devuelve su uuid.
     *
     * @param  array<string, mixed>  $over
     */
    protected function seedProfile(Admin $creator, ?Admin $reviewer = null, string $status = 'approved', array $over = [], int $transfers = 0): string
    {
        $contenido = $this->profileContent($over);
        $duracion = is_int($contenido['maxDurationSeconds'] ?? null) ? max(60, min(600, $contenido['maxDurationSeconds'])) : 300;
        $uuid = (string) Str::uuid();
        $ahora = now();
        $tabla = DB::table('ultron_studio_voice_profiles');
        $id = $tabla->insertGetId([
            'uuid' => $uuid,
            'client_request_id' => (string) Str::uuid(),
            'revision' => 1,
            'based_on_profile_id' => null,
            'label' => 'Perfil de prueba',
            'language' => 'es',
            'max_duration_seconds' => $duracion,
            'retention_days' => 30,
            'record_calls' => false,
            'record_voice' => false,
            'transfer_count' => $transfers,
            'transfer_destinations_encrypted' => $transfers > 0 ? encrypt('destinos sintéticos') : null,
            'content' => json_encode($contenido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'content_hash' => StudioVoiceCompiler::profileHash($contenido),
            'status' => 'draft',
            'created_by_admin_id' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_role' => $creator->role,
            'created_at' => $ahora,
            'status_changed_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        if ($status !== 'draft') {
            DB::table('ultron_studio_voice_profiles')->where('id', $id)->update([
                'status' => 'submitted', 'submitted_by_admin_id' => $creator->id, 'submitted_by_name' => $creator->name,
                'submitted_by_role' => $creator->role, 'submitted_at' => $ahora, 'status_changed_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }
        if (in_array($status, ['approved', 'rejected'], true)) {
            $this->assertNotNull($reviewer, 'un perfil revisado lo revisa otra persona');
            DB::table('ultron_studio_voice_profiles')->where('id', $id)->update([
                'status' => $status, 'reviewed_by_admin_id' => $reviewer->id, 'reviewed_by_name' => $reviewer->name, 'reviewed_by_role' => $reviewer->role,
                'reviewed_at' => $ahora, 'review_note' => $status === 'rejected' ? 'No cumple el tono.' : null, 'status_changed_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }

        return $uuid;
    }

    // ── Sesiones ────────────────────────────────────────────────────────────

    /** La reserva máxima que enseña el estado (la que acepta el CRM al abrir). */
    protected function acceptedMax(Admin $as): int
    {
        return (int) $this->voiceLab('GET', 'status', $as)->assertOk()->json('data.estimate.reservedMicros');
    }

    protected function openSession(Admin $as, string $versionUuid, string $profileUuid, ?string $clientRequestId = null, ?int $accepted = null): TestResponse
    {
        return $this->voiceLab('POST', 'sessions', $as, [
            'versionUuid' => $versionUuid,
            'profileUuid' => $profileUuid,
            'acceptedMaxCostMicros' => $accepted ?? 1000000,
            'clientRequestId' => $clientRequestId ?? (string) Str::uuid(),
        ]);
    }

    /** @return array{objectives:list<string>,restrictionsBroken:list<string>,interruptions:int,errors:list<string>} */
    protected static function observations(array $over = []): array
    {
        return $over + ['objectives' => ['aviso_ia', 'saludo', 'informo_horarios'], 'restrictionsBroken' => [], 'interruptions' => 1, 'errors' => []];
    }

    /**
     * El veredicto NEUTRO de un webhook post-llamada ya verificado, como lo
     * entregaría la telefonía (B8-b).
     *
     * @param  list<string>  $tools
     */
    protected static function postCall(?string $agentId, string $conversationId, ?int $duration, array $tools = ['consultar_horarios', 'end_call'], string $kind = VoiceWebhookVerdict::KIND_POST_CALL_TRANSCRIPTION): VoiceWebhookVerdict
    {
        return new VoiceWebhookVerdict(
            VoiceWebhookVerdict::OUTCOME_ACCEPTED, VoiceWebhookVerdict::REASON_OK, $kind, 1793804340,
            $agentId, $conversationId, null, null, null, null, $duration, null, null, false,
            $kind === VoiceWebhookVerdict::KIND_POST_CALL_TRANSCRIPTION ? $tools : [],
        );
    }

    // ── El simulador de ElevenLabs (Http::fake) ─────────────────────────────

    /**
     * ElevenLabs simulado sobre `Http::fake`, con la red cerrada: crea las
     * herramientas y el agente que le mandan, los devuelve tal cual al leerlos
     * (o con la ráfaga encendida si `drift`) y emite una URL firmada del mismo
     * host. Cualquier otra petición es un 404.
     */
    protected function fakeElevenLabs(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            $ruta = (string) parse_url($r->url(), PHP_URL_PATH);
            if ($r->method() === 'POST' && $r->url() === self::VOICE_SIM.'/v1/convai/tools') {
                $id = 'tool_lab_'.(++$this->sim['n']);
                $this->sim['tools'][$id] = $r->data()['tool_config'];

                return Http::response(['id' => $id], 200);
            }
            if ($r->method() === 'POST' && $r->url() === self::VOICE_SIM.'/v1/convai/agents/create') {
                if ($this->sim['createStatus'] !== 200) {
                    return Http::response(['detail' => 'no'], $this->sim['createStatus']);
                }
                $id = 'agent_lab_'.(++$this->sim['n']);
                $this->sim['agents'][$id] = $r->data();

                return Http::response(['agent_id' => $id], 200);
            }
            if ($r->method() === 'GET' && str_starts_with($ruta, '/v1/convai/agents/')) {
                $id = basename($ruta);
                $agente = ($this->sim['agents'][$id] ?? null);
                if ($agente === null) {
                    return Http::response(['detail' => 'no'], 404);
                }
                if ($this->sim['drift']) {
                    $agente['platform_settings']['call_limits']['bursting_enabled'] = true;
                }

                return Http::response($agente + ['agent_id' => $id, 'version_id' => 'agtvrsn_lab_1', 'branch_id' => 'agtbrch_lab_1'], 200);
            }
            if ($r->method() === 'GET' && str_starts_with($ruta, '/v1/convai/tools/')) {
                $id = basename($ruta);

                return isset($this->sim['tools'][$id])
                    ? Http::response(['id' => $id, 'tool_config' => $this->sim['tools'][$id]], 200)
                    : Http::response(['detail' => 'no'], 404);
            }
            if ($r->method() === 'GET' && $ruta === '/v1/convai/conversation/get-signed-url') {
                return $this->sim['urlStatus'] === 200
                    ? Http::response(['signed_url' => 'wss://127.0.0.1:8096/v1/convai/conversation?agent_id='.$r->data()['agent_id'].'&conversation_signature='.self::URL_SIGNATURE], 200)
                    : Http::response(['detail' => 'no'], $this->sim['urlStatus']);
            }

            return Http::response(['detail' => 'ruta no simulada'], 404);
        });
    }
}
