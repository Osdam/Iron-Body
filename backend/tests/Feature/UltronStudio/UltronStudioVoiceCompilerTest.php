<?php

namespace Tests\Feature\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\Admin;
use App\Models\UltronStudio\StudioVersion;
use App\Services\UltronStudio\StudioVersions;
use App\Services\UltronStudio\StudioVoiceCompiler as C;
use App\Services\UltronStudio\StudioVoiceTemplate as T;
use App\Services\Voice\Dto\VoiceAgentSpec;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El compilador de la voz con VERSIONES REALES (contrato H8 §D8-6): creadas,
 * enviadas y aprobadas por la API del Studio por dos personas, con una regla
 * S-NNN creada también por la API. Prueba lo que la prueba unitaria no ve:
 * que la forma de la versión que guarda el Studio es la que el compilador
 * entiende (su huella guardada cuadra con la que recalcula), que la salida la
 * lee el DTO neutro de B8 con la misma huella, que compilar no consulta la base
 * ni sale a la red, y que la negativa llega al CRM como un 422 con su motivo y
 * sin el texto que la provocó.
 *
 * El perfil se construye aquí como lo hará S8-b (sin modelo todavía): su
 * contenido cerrado y su huella con {@see C::profileHash()}.
 */
class UltronStudioVoiceCompilerTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::preventStrayRequests();
        $this->importBaseline();
    }

    /** La entrada del compilador desde el modelo, como la armará quien publica (S8-b). */
    private static function versionInput(StudioVersion $v): array
    {
        $v->loadMissing('review');

        return [
            'uuid' => $v->uuid,
            'status' => $v->status,
            'contentHash' => $v->content_hash,
            'content' => $v->content,
            'createdBy' => $v->created_by_admin_id,
            'approvedBy' => $v->review?->decision === 'approved' ? $v->review->reviewer_admin_id : null,
        ];
    }

    /** @return array<string,mixed> */
    private static function profileInput(int $createdBy, ?int $approvedBy, string $status = 'approved', array $over = []): array
    {
        $content = array_replace_recursive([
            'format' => C::PROFILE_FORMAT,
            'voiceId' => 'pNInz6obpgDQGcFmaJgB',
            'language' => 'es',
            'aiNotice' => T::AI_NOTICE,
            'greetings' => ['inbound' => '¿En qué te puedo ayudar hoy?', 'outbound' => 'Te llamo porque pediste información de Iron Body.'],
            'holes' => ['presentation' => 'Te ayudo con planes, horarios y la sede.', 'closing' => 'Gracias por tu tiempo.'],
            'maxDurationSeconds' => 300,
            'tools' => ['consultar_planes', 'consultar_horarios', 'no_volver_a_llamar'],
            'systemTools' => ['end_call'],
            'transfer' => ['destinations' => []],
            'privacy' => ['retentionDays' => 30, 'storeAudio' => false, 'recording' => false],
            'voicemail' => 'hang_up',
        ], $over);

        return [
            'uuid' => '6c1f2e3d-4b5a-4c6d-8e7f-0a1b2c3d4e5f',
            'status' => $status,
            'contentHash' => C::profileHash($content),
            'content' => $content,
            'createdBy' => $createdBy,
            'approvedBy' => $approvedBy,
        ];
    }

    public function test_compila_una_version_aprobada_por_la_api_con_sus_reglas_s_nnn(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');

        // Dos reglas del Studio por la API: una prohíbe frases siempre; la otra, solo con tono profesional.
        $this->newStudioRule($ana, ['layers' => ['persona'], 'conditions' => self::conditionsOf([
            ['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => ['  Súper  BARATO ', 'te lo juro']],
        ])])->assertCreated();
        $this->newStudioRule($ana, ['layers' => ['persona'], 'conditions' => self::conditionsOf(
            [['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => ['oiga']]],
            [['target' => 'config', 'layer' => 'persona', 'key' => 'tone', 'op' => 'eq', 'value' => 'profesional']],
        )])->assertCreated();

        $v = $this->approvedVersion($ana, $root, 'Voz cercana');
        $this->assertTrue(StudioVersions::integrityOk($v));
        $perfil = self::profileInput($ana->id, $root->id);

        // Compilar no consulta la base (es puro) ni sale a la red (preventStrayRequests).
        $consultas = 0;
        DB::listen(static function () use (&$consultas): void {
            $consultas++;
        });
        $r = C::compile(self::versionInput($v), $perfil, 300);
        $this->assertSame(0, $consultas, 'el compilador consultó la base');

        $s = $r['spec'];
        $this->assertSame($v->uuid, $s['source']['studioVersionUuid']);
        $this->assertSame($v->content_hash, $s['source']['studioContentHash']);
        $this->assertSame($v->catalog_version, $s['source']['catalogVersion']);
        $this->assertSame($perfil['contentHash'], $s['source']['voiceProfileHash']);
        // Las frases canónicas de la regla que aplica; la condicionada a otro tono, no.
        $this->assertSame(['súper barato', 'te lo juro'], $s['forbiddenPhrases']);
        $this->assertStringContainsString('«súper barato», «te lo juro»', $s['systemPrompt']);
        $this->assertStringNotContainsString('oiga', $s['systemPrompt']);
        $this->assertStringContainsString(T::TONES['cercano'], $s['systemPrompt']);

        // El lector estricto de B8 la acepta y calcula la misma huella.
        $dto = VoiceAgentSpec::fromCompiled($r);
        $this->assertSame($r['specHash'], $dto->specHash);
        $this->assertSame('no', $dto->dynamicVariableDefaults()['caller_authorized']);

        // Con el tono que la activa, la otra regla entra.
        $prof = $this->approvedVersion($ana, $root, 'Voz profesional', ['persona' => ['tone' => 'profesional']]);
        $this->assertSame(['oiga', 'súper barato', 'te lo juro'], C::compile(self::versionInput($prof), $perfil, 300)['spec']['forbiddenPhrases']);
    }

    public function test_lo_enviado_a_revision_solo_compila_para_el_laboratorio_y_lo_rechazado_nunca(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');

        $enviada = StudioVersion::query()->with('review')->where('uuid', $this->submittedVersion($ana))->sole();
        $this->assertContains($enviada->status, C::SUBMITTED_VERSION_STATES);
        $perfilEnviado = self::profileInput($ana->id, null, 'submitted');

        try {
            C::compile(self::versionInput($enviada), self::profileInput($ana->id, $root->id), 300);
            $this->fail('Publicar compiló una versión solo enviada a revisión.');
        } catch (UltronStudioException $e) {
            $this->assertSame(['reason' => 'version_not_approved', 'field' => 'version.status'], $e->payload);
        }
        $lab = C::compile(self::versionInput($enviada), $perfilEnviado, 300, C::PURPOSE_LAB);
        $this->assertSame($enviada->content_hash, $lab['spec']['source']['studioContentHash']);

        // Rechazada por otra persona: ni para el laboratorio.
        $uuid = $this->submittedVersion($ana, 'Para rechazar');
        $this->studio('POST', "versions/{$uuid}/reject", $root, ['reason' => 'No convence el tono.'])->assertOk();
        $rechazada = StudioVersion::query()->with('review')->where('uuid', $uuid)->sole();
        try {
            C::compile(self::versionInput($rechazada), $perfilEnviado, 300, C::PURPOSE_LAB);
            $this->fail('El laboratorio compiló una versión rechazada.');
        } catch (UltronStudioException $e) {
            $this->assertSame('version_not_approved', $e->payload['reason']);
        }
    }

    public function test_la_negativa_llega_al_crm_como_un_422_con_su_motivo_y_sin_el_texto(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->approvedVersion($ana, $root);
        $malo = self::profileInput($ana->id, $root->id, over: ['greetings' => ['outbound' => 'Planes desde 80 mil al mes']]);

        try {
            C::compile(self::versionInput($v), $malo, 300);
            $this->fail('Compiló un saludo con un precio.');
        } catch (UltronStudioException $e) {
            // Una negativa del dominio no va al log de errores: `report()` distinto
            // de false es «ya está tratada» y Laravel no la registra.
            $this->assertTrue($e->report());

            // Lo que pinta el manejador de excepciones de la aplicación.
            $respuesta = TestResponse::fromBaseResponse(app(ExceptionHandler::class)->render(Request::create('/api/admin/ultron-studio/voice/compile-preview', 'POST'), $e));
            $respuesta->assertStatus(422)->assertExactJson([
                'ok' => false,
                'code' => 'voice_compile_refused',
                'message' => C::REFUSALS['text_money'],
                'reason' => 'text_money',
                'field' => 'profile.content.greetings.outbound',
            ]);
            $this->assertStringNotContainsString('80 mil', (string) $respuesta->getContent());
        }
    }

    public function test_cuatro_ojos_por_identidad_con_personas_reales(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->approvedVersion($ana, $root);

        // El perfil aprobado por quien lo creó: no compila, aunque la versión sí sea buena.
        try {
            C::compile(self::versionInput($v), self::profileInput($root->id, $root->id), 300);
            $this->fail('Compiló un perfil aprobado por quien lo creó.');
        } catch (UltronStudioException $e) {
            $this->assertSame(['reason' => 'profile_four_eyes', 'field' => 'profile.approvedBy'], $e->payload);
        }

        // La versión aprobada por otra persona y el perfil también: compila, con cualquiera de las dos como autora del perfil.
        $this->assertSame(64, strlen(C::compile(self::versionInput($v), self::profileInput($ana->id, $root->id), 300)['specHash']));
        $this->assertSame(64, strlen(C::compile(self::versionInput($v), self::profileInput($root->id, $ana->id), 300)['specHash']));
    }
}
