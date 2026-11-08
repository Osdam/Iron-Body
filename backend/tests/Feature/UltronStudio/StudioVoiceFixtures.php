<?php

namespace Tests\Feature\UltronStudio;

use App\Http\Middleware\EnforceAdminAuthorization;
use App\Models\Admin;
use App\Models\UltronStudio\StudioVersion;
use App\Models\UltronStudio\StudioVoiceProfile;
use App\Services\UltronStudio\StudioAudit;
use App\Services\UltronStudio\StudioVersions;
use App\Services\UltronStudio\StudioVoiceCompiler;
use App\Services\UltronStudio\StudioVoiceEvidence;
use App\Services\UltronStudio\StudioVoiceProfiles;
use App\Services\UltronStudio\StudioVoicePublishing;
use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Datos y peticiones del GOBIERNO DE LA VOZ (S8-b) para las pruebas Feature.
 *
 * LA PUERTA. Las pruebas de comportamiento van SIN `EnforceAdminAuthorization`:
 * prueban la segunda cerradura (la persona y la llave dentro de los servicios,
 * `StudioAccess::require`), los cuatro ojos y las banderas. La primera (que
 * `AuthorizationMap` clasifique cada ruta con su permiso, de B8) la prueba
 * `UltronStudioVoiceRoutesTest` con la puerta puesta. La sesión de cada
 * persona es real (`actingAsAdmin`).
 *
 * CERO RED: `Http::preventStrayRequests()` siempre; el despliegue va por
 * {@see FakeVoiceDeployer} salvo en las pruebas que usan el adaptador real con
 * `Http::fake`.
 */
trait StudioVoiceFixtures
{
    protected FakeVoiceDeployer $fake;

    protected const VOICE_PUBLISHER = ['ultron.view', 'ultron.voice_publish'];

    protected const VOICE_MANAGER = ['ultron.view', 'ultron.voice_manage'];

    protected const VOICE_APPROVER = ['ultron.view', 'ultron.approve'];

    /** El número dedicado importado en el proveedor (sintético). */
    protected const NUMBER_ID = 'phnum_h8s_0001';

    /** Un destino de transferencia (fijo de ficción de Bogotá). */
    protected const DESTINATION_PHONE = '+576010000099';

    protected function voiceSetUp(): void
    {
        $this->withoutMiddleware([ThrottleRequests::class, EnforceAdminAuthorization::class]);
        Http::preventStrayRequests();
        $this->importBaseline();
        $this->deployConfig();
    }

    /** @param  array<string, mixed>  $body */
    protected function voice(string $method, string $path, Admin $as, array $body = []): TestResponse
    {
        return $this->studio($method, 'voice/'.ltrim($path, '/'), $as, $body);
    }

    /**
     * El contenido de un perfil como lo escribe quien lo crea (sin transferencia).
     *
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    protected static function profileContent(array $over = []): array
    {
        return array_replace([
            'voiceId' => 'pNInz6obpgDQGcFmaJgB',
            'greetings' => ['inbound' => '¿En qué te puedo ayudar hoy?', 'outbound' => 'Te llamo porque pediste información de Iron Body.'],
            'holes' => ['presentation' => 'Te ayudo con planes, horarios y la sede.', 'closing' => 'Gracias por tu tiempo.'],
            'maxDurationSeconds' => 300,
            'tools' => ['consultar_planes', 'consultar_horarios', 'no_volver_a_llamar'],
            'systemTools' => ['end_call'],
            'transfer' => ['destinations' => []],
            'privacy' => ['retentionDays' => 30],
            'voicemail' => 'hang_up',
        ], $over);
    }

    /** @return array<string, mixed> con un destino de transferencia (y `solicitar_humano`) */
    protected static function profileContentWithTransfer(): array
    {
        return self::profileContent([
            'tools' => ['consultar_planes', 'consultar_horarios', 'no_volver_a_llamar', 'solicitar_humano'],
            'systemTools' => ['end_call', 'transfer_to_number'],
            'transfer' => ['destinations' => [['alias' => 'Recepción', 'phone' => self::DESTINATION_PHONE]]],
        ]);
    }

    /** @param  array<string, mixed>|null  $content */
    protected function createProfile(Admin $as, ?array $content = null, ?string $clientRequestId = null, string $label = 'Voz de prueba', ?string $basedOn = null): TestResponse
    {
        return $this->voice('POST', 'profiles', $as, array_filter([
            'clientRequestId' => $clientRequestId ?? (string) Str::uuid(),
            'label' => $label,
            'basedOn' => $basedOn,
            'content' => $content ?? self::profileContent(),
        ], static fn (mixed $v): bool => $v !== null));
    }

    /** Un perfil creado por `$author`, enviado y aprobado por `$reviewer`, por la API. */
    protected function approvedProfile(Admin $author, Admin $reviewer, ?array $content = null, string $label = 'Voz aprobada'): StudioVoiceProfile
    {
        $uuid = (string) $this->createProfile($author, $content, null, $label)->assertCreated()->json('data.uuid');
        $this->voice('POST', "profiles/{$uuid}/submit", $author)->assertOk();
        $this->voice('POST', "profiles/{$uuid}/approve", $reviewer)->assertOk();

        return StudioVoiceProfile::query()->where('uuid', $uuid)->sole();
    }

    /**
     * Las piezas del despliegue y la bandera de publicar, encendidas, sin
     * ventana. Solo referencias e ids sintéticos: ningún secreto.
     *
     * @param  array<string, mixed>  $over
     */
    protected function deployConfig(array $over = []): void
    {
        config(array_replace([
            'ultron_studio.voice.publishing_enabled' => true,
            'ultron_studio.voice.lab_gate' => 'required',
            'ultron_studio.publishing.window' => '',
            'voice.webhook_base_url' => 'https://crm.ironbody.test/backend',
            'voice.elevenlabs.tool_secret_ref' => 'secref_tools_h8s',
            'voice.elevenlabs.initiation_secret_ref' => 'secref_init_h8s',
            'voice.elevenlabs.post_call_webhook_ref' => null,
            'voice.elevenlabs.agent_phone_number_id' => self::NUMBER_ID,
            'voice.twilio.daily_call_cap' => 3,
            'voice.inbound.daily_cap' => 2,
            'voice.limits.max_concurrent_calls' => 1,
            'voice.limits.per_call_max_seconds' => 300,
        ], $over));
    }

    /**
     * El desplegador falso en los servicios del contenedor (y, si se da, la
     * medida del canario de la telefonía).
     *
     * @param  Closure(int,string,\DateTimeInterface):mixed|null  $measure
     */
    protected function bindFake(?Closure $measure = null): FakeVoiceDeployer
    {
        $this->fake = new FakeVoiceDeployer;
        $evidencia = new StudioVoiceEvidence(app(StudioAudit::class), $measure);
        $this->app->instance(StudioVoiceEvidence::class, $evidencia);
        $this->app->instance(StudioVoicePublishing::class, new StudioVoicePublishing(
            app(StudioAudit::class), app(StudioVersions::class), $evidencia, $this->fake,
        ));

        return $this->fake;
    }

    /** La huella de la compilación de publicación de una versión y un perfil. */
    protected static function specHashOf(StudioVersion $v, StudioVoiceProfile $p): string
    {
        return StudioVoiceCompiler::compile(StudioVoicePublishing::versionInput($v), StudioVoiceProfiles::compilerInput($p), StudioVoicePublishing::maxCallSeconds())['specHash'];
    }

    /**
     * Un veredicto del laboratorio hablado sobre esa huella: un agente de
     * laboratorio (real, por defecto) y una sesión terminada que abre `$opener` y juzga
     * `$judge`, sembrados como los dejaría LAB8 (solo inserción y avance, con
     * las guardas de DB8).
     */
    protected function labVerdictFor(StudioVersion $v, StudioVoiceProfile $p, Admin $opener, Admin $judge, string $decision = 'approved', string $provider = 'elevenlabs'): void
    {
        $ahora = now();
        $agente = DB::table('ultron_studio_voice_agents')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'purpose' => 'lab',
            'studio_version_id' => $v->id,
            'studio_content_hash' => $v->content_hash,
            'voice_profile_id' => $p->id,
            'voice_profile_hash' => $p->content_hash,
            'compiler_version' => StudioVoiceCompiler::VERSION,
            'spec_hash' => self::specHashOf($v, $p),
            'payload_hash' => str_repeat('a', 64),
            'projection_hash' => str_repeat('b', 64),
            'provider' => $provider,
            'status' => 'pending',
            'serves_canary' => false,
            'serves_rest' => false,
            'created_by_admin_id' => $opener->id,
            'created_by_name' => $opener->name,
            'created_by_role' => $opener->role,
            'created_at' => $ahora,
            'status_changed_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        DB::table('ultron_studio_voice_agents')->where('id', $agente)->update([
            'status' => 'deployed', 'provider_agent_id' => 'lab_fake_'.Str::lower(Str::random(8)), 'observed_hash' => str_repeat('b', 64), 'observed_at' => $ahora, 'status_changed_at' => $ahora,
        ]);
        $sesion = DB::table('ultron_studio_voice_lab_sessions')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => (string) Str::uuid(),
            'agent_id' => $agente,
            'status' => 'open',
            'budget_day' => $ahora->toDateString(),
            'reserved_micros' => 0,
            'max_seconds' => 300,
            'created_by_admin_id' => $opener->id,
            'created_by_name' => $opener->name,
            'created_by_role' => $opener->role,
            'created_at' => $ahora,
            'status_changed_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        DB::table('ultron_studio_voice_lab_sessions')->where('id', $sesion)->update([
            'status' => 'ended', 'ended_at' => $ahora, 'end_reason' => 'completed', 'duration_seconds' => 120, 'status_changed_at' => $ahora,
        ]);
        DB::table('ultron_studio_voice_lab_sessions')->where('id', $sesion)->update([
            'verdict' => $decision,
            'verdict_note' => $decision === 'approved' ? null : 'No dice el aviso completo',
            'verdict_by_admin_id' => $judge->id,
            'verdict_by_name' => $judge->name,
            'verdict_by_role' => $judge->role,
            'verdict_at' => $ahora,
        ]);
    }

    protected function publishVoice(Admin $as, StudioVersion $v, StudioVoiceProfile $p, ?string $clientRequestId = null, ?string $note = null): TestResponse
    {
        return $this->voice('POST', 'publish', $as, array_filter([
            'clientRequestId' => $clientRequestId ?? (string) Str::uuid(),
            'versionUuid' => $v->uuid,
            'profileUuid' => $p->uuid,
            'note' => $note,
        ], static fn (mixed $x): bool => $x !== null));
    }
}
