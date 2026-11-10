<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioVoiceProfile;
use App\Services\Telephony\VoiceTranscriptGuard;
use App\Services\UltronStudio\StudioVoiceTemplate as T;
use App\Services\Voice\Dto\VoiceAgentSpec;
use App\Services\Voice\Dto\VoiceAgentTarget;
use App\Services\Voice\ElevenLabs\ElevenLabsAgentMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S8-d (09-11-2026): EL ESPAÑOL DE COLOMBIA DESDE EL PRIMER SALUDO, de punta a
 * punta. La primera llamada real del propietario salió con un agente hecho a
 * mano en la consola de ElevenLabs, que habló en inglés (el idioma por defecto
 * de un agente nuevo del proveedor). Aquí se prueba que lo que compila el
 * Studio desde una versión y un perfil aprobados por la API llega a la carga
 * de ElevenLabs en español, con la voz del perfil, el modelo multilingüe, las
 * sobrescrituras cerradas y sin forma de cambiar de idioma; que el trato
 * (tú o usted) lo elige el perfil; y que los dos avisos pasan la guarda
 * posterior de la telefonía (si no, cada llamada echaría el freno).
 */
class UltronStudioVoiceLanguageTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;
    use StudioVoiceFixtures;

    /**
     * Los modelos de voz de ElevenLabs que admiten `es` (documentación oficial
     * de modelos, consultada el 09-11-2026: Flash v2.5 y Turbo v2.5 admiten 32
     * idiomas, `es` incluido; Multilingual v2, 29). Los `…_v2` sin `_5` y el
     * monolingüe son solo inglés.
     */
    private const MULTILINGUAL_WITH_ES = ['eleven_flash_v2_5', 'eleven_turbo_v2_5', 'eleven_multilingual_v2'];

    private const ENGLISH_ONLY = ['eleven_flash_v2', 'eleven_turbo_v2', 'eleven_monolingual_v1'];

    private Admin $ana;

    private Admin $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->voiceSetUp();
        $this->ana = $this->personWith([...self::VOICE_MANAGER, 'ultron.approve', 'ultron.edit_draft'], 'Ana Autora');
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root Revisa');
    }

    /** La carga de ElevenLabs de una especificación compilada (como la arma quien publica). */
    private static function payloadOf(array $spec, string $purpose = VoiceAgentTarget::PURPOSE_PUBLICATION): array
    {
        $target = new VoiceAgentTarget($purpose, 'https://crm.ironbody.test/backend', 'secref_tools_h8s', 'secref_init_h8s', null, 5, 1);

        return ElevenLabsAgentMapper::agentBody(VoiceAgentSpec::fromArray($spec), $target);
    }

    public function test_el_agente_que_compila_el_studio_llega_a_elevenlabs_en_espanol_con_la_voz_del_perfil(): void
    {
        $v = $this->approvedVersion($this->ana, $this->root, 'Voz en español');
        $p = $this->approvedProfile($this->ana, $this->root, self::profileContent(['voiceId' => 'VozColombiana0001']));
        $spec = $this->voice('POST', 'compile-preview', $this->ana, ['versionUuid' => $v->uuid, 'profileUuid' => $p->uuid])->assertOk()->json('data.spec');

        $this->assertSame('es', $spec['language']);
        $this->assertSame(T::AI_NOTICE, $spec['firstMessage'], 'el primer mensaje es el aviso de E1-3, en español');
        $this->assertStringContainsString(T::languageSection('tu'), $spec['systemPrompt']);
        $this->assertStringContainsString(T::securitySection(), $spec['systemPrompt']);

        $cuerpo = self::payloadOf($spec);
        $cc = $cuerpo['conversation_config'];
        // El idioma y el primer mensaje, explícitos (el defecto del proveedor es inglés).
        $this->assertSame('es', $cc['agent']['language']);
        $this->assertSame(T::AI_NOTICE, $cc['agent']['first_message']);
        $this->assertSame($spec['systemPrompt'], $cc['agent']['prompt']['prompt']);
        // La voz APROBADA del perfil, con un modelo multilingüe que admite `es` (nunca uno solo de inglés).
        $this->assertSame('VozColombiana0001', $cc['tts']['voice_id']);
        $this->assertSame(ElevenLabsAgentMapper::TTS_MODEL, $cc['tts']['model_id']);
        $this->assertContains(ElevenLabsAgentMapper::TTS_MODEL, self::MULTILINGUAL_WITH_ES);
        $this->assertNotContains(ElevenLabsAgentMapper::TTS_MODEL, self::ENGLISH_ONLY);
        // Sin idiomas adicionales ni detección de idioma: el agente no puede cambiar a otro.
        $this->assertArrayNotHasKey('language_presets', $cc);
        $this->assertSame(['end_call'], array_keys($cc['agent']['prompt']['built_in_tools']));
        // Nadie lo cambia al empezar la conversación (D8-11): ni el idioma, ni el primer mensaje, ni el prompt, ni la voz.
        $cerradas = $cuerpo['platform_settings']['overrides']['conversation_config_override'];
        $this->assertSame(['first_message' => false, 'language' => false, 'prompt' => ['prompt' => false]], $cerradas['agent']);
        $this->assertSame(['voice_id' => false], $cerradas['tts']);
        // La proyección de deriva vigila el idioma y los idiomas añadidos: un cambio en el panel es deriva.
        $proyeccion = ElevenLabsAgentMapper::projection($cuerpo, []);
        $this->assertSame('es', $proyeccion['agent']['language']);
        $this->assertSame(0, $proyeccion['languagePresetCount']);
        $enIngles = $cuerpo;
        $enIngles['conversation_config']['agent']['language'] = 'en';
        $this->assertNotSame(ElevenLabsAgentMapper::projection($cuerpo, []), ElevenLabsAgentMapper::projection($enIngles, []));
        $conOtroIdioma = $cuerpo;
        $conOtroIdioma['conversation_config']['language_presets'] = ['en' => ['overrides' => []]];
        $this->assertNotSame(ElevenLabsAgentMapper::projection($cuerpo, []), ElevenLabsAgentMapper::projection($conOtroIdioma, []));
    }

    public function test_el_laboratorio_y_la_publicacion_comparten_la_misma_carga_de_idioma(): void
    {
        $v = $this->approvedVersion($this->ana, $this->root, 'Voz');
        $p = $this->approvedProfile($this->ana, $this->root);
        $publicacion = $this->voice('POST', 'compile-preview', $this->ana, ['versionUuid' => $v->uuid, 'profileUuid' => $p->uuid])->assertOk()->json('data');
        $laboratorio = $this->voice('POST', 'compile-preview', $this->ana, ['versionUuid' => $v->uuid, 'profileUuid' => $p->uuid, 'purpose' => 'lab'])->assertOk()->json('data');

        $this->assertSame($publicacion['specHash'], $laboratorio['specHash'], 'lo que se prueba en el laboratorio es lo que se publica (P-19)');
        $a = self::payloadOf($publicacion['spec'])['conversation_config'];
        $b = self::payloadOf($laboratorio['spec'], VoiceAgentTarget::PURPOSE_LAB)['conversation_config'];
        $this->assertSame([$a['agent']['language'], $a['agent']['first_message'], $a['tts']], [$b['agent']['language'], $b['agent']['first_message'], $b['tts']]);
    }

    public function test_el_trato_lo_elige_el_perfil_por_la_api_y_el_de_tu_no_cambia_la_forma_del_contenido(): void
    {
        // De usted: el campo y su aviso en el contenido.
        $usted = $this->createProfile($this->ana, self::profileContent(['addressForm' => 'usted']), null, 'Voz de usted')->assertCreated()->json('data');
        $this->assertSame('usted', $usted['content']['addressForm']);
        $this->assertSame(T::AI_NOTICE_USTED, $usted['content']['aiNotice']);

        // De tú (sin el campo o escribiéndolo): el contenido de siempre, sin la clave. El lector del
        // CRM es cerrado («un campo de más no se enseña»), así que un perfil de tú se sigue viendo.
        $sinCampo = $this->createProfile($this->ana, self::profileContent(), null, 'Voz de tú')->assertCreated()->json('data');
        $this->assertArrayNotHasKey('addressForm', $sinCampo['content']);
        $this->assertSame(T::AI_NOTICE, $sinCampo['content']['aiNotice']);
        $explicito = $this->createProfile($this->ana, self::profileContent(['addressForm' => 'tu']), null, 'Voz de tú')->assertCreated()->json('data');
        $this->assertArrayNotHasKey('addressForm', $explicito['content']);
        $this->assertSame(
            StudioVoiceProfile::query()->where('uuid', $sinCampo['uuid'])->value('content_hash'),
            StudioVoiceProfile::query()->where('uuid', $explicito['uuid'])->value('content_hash'),
            'escribir `tu` es lo mismo que no escribirlo',
        );

        // Un trato fuera del conjunto: el 422 del compilador con su campo, sin repetir el valor.
        $r = $this->createProfile($this->ana, self::profileContent(['addressForm' => 'vosotros']))->assertStatus(422)
            ->assertJsonPath('code', 'voice_compile_refused')->assertJsonPath('reason', 'profile_field_invalid')->assertJsonPath('field', 'profile.content.addressForm');
        $this->assertStringNotContainsString('vosotros', $r->getContent());
        $this->createProfile($this->ana, self::profileContent(['addressForm' => 7]))->assertStatus(422)->assertJsonValidationErrors(['content.addressForm']);
        $this->assertSame(3, StudioVoiceProfile::query()->count());

        // Aprobado por otra persona, compila con el aviso y la línea de usted.
        $this->voice('POST', "profiles/{$usted['uuid']}/submit", $this->ana)->assertOk();
        $this->voice('POST', "profiles/{$usted['uuid']}/approve", $this->root)->assertOk();
        $v = $this->approvedVersion($this->ana, $this->root, 'Voz');
        $spec = $this->voice('POST', 'compile-preview', $this->ana, ['versionUuid' => $v->uuid, 'profileUuid' => $usted['uuid']])->assertOk()->json('data.spec');
        $this->assertSame(T::AI_NOTICE_USTED, $spec['firstMessage']);
        $this->assertStringContainsString(T::ADDRESS_FORMS['usted'], $spec['systemPrompt']);
        $this->assertSame(T::AI_NOTICE_USTED, self::payloadOf($spec)['conversation_config']['agent']['first_message']);
    }

    public function test_los_dos_avisos_pasan_la_guarda_posterior_de_la_telefonia(): void
    {
        foreach ([T::AI_NOTICE, T::AI_NOTICE_USTED] as $aviso) {
            $this->assertTrue(VoiceTranscriptGuard::hasNotice($aviso), $aviso);
            // El primer turno del agente es el aviso: ni `voice_notice_missing` ni ningún otro código del motor.
            $this->assertSame([], VoiceTranscriptGuard::inspect([['index' => 0, 'text' => $aviso, 'tools' => [], 'transfers' => [], 'planResults' => []]], [], true), $aviso);
        }
        // La guarda sí ve un aviso que no dice cómo pedir que no llamen más (la prueba no es vacía).
        $sinRevocacion = str_replace('Si prefiere no recibir más llamadas o hablar con una persona, dígamelo en cualquier momento.', 'Gracias.', T::AI_NOTICE_USTED);
        $this->assertFalse(VoiceTranscriptGuard::hasNotice($sinRevocacion));
    }

    public function test_un_saludo_de_tu_en_un_perfil_de_usted_no_lo_arregla_el_servidor_pero_el_aviso_si(): void
    {
        // El servidor pone el aviso del trato; los textos del perfil son de quien lo escribe (cuatro ojos).
        $r = $this->createProfile($this->ana, self::profileContent(['addressForm' => 'usted', 'greetings' => ['inbound' => '¿En qué le puedo colaborar hoy?', 'outbound' => 'Le llamo de Iron Body porque pidió información.']]), (string) Str::uuid())->assertCreated();
        $this->assertSame(T::AI_NOTICE_USTED, $r->json('data.content.aiNotice'));
        $this->assertSame('¿En qué le puedo colaborar hoy?', $r->json('data.content.greetings.inbound'));
    }
}
