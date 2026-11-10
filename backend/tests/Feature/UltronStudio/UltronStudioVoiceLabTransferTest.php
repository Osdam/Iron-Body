<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioVoiceLabSession;
use App\Services\Telephony\VoiceTools;
use App\Services\UltronStudio\StudioVoiceCompiler;
use App\Services\UltronStudio\StudioVoiceProfiles;
use App\Services\UltronStudio\StudioVoicePublishing;
use App\Services\UltronStudio\StudioVoiceTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EL LABORATORIO HABLADO CON DESTINOS DE TRANSFERENCIA (contrato H8, E10-10;
 * S8-d). Antes, un perfil con destinos daba 409
 * `voice_lab_transfers_not_supported` y, con la puerta `required` (P-19), no
 * se podía publicar. Ahora el agente de laboratorio lleva la MISMA compilación
 * que se publicará (`transfer_to_number` en conferencia con los destinos
 * aprobados, descifrados en memoria y comprobados contra la especificación,
 * R-07), y el número no queda en ningún sitio. El laboratorio NO transfiere:
 * la sesión es una conversación web (ElevenLabs solo ofrece
 * `transfer_to_number` en llamadas telefónicas) y `solicitar_humano` no
 * encuentra llamada en `voice_calls`, así que responde su negativa neutra y el
 * agente, por su prompt, no pasa la llamada.
 */
class UltronStudioVoiceLabTransferTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;
    use VoiceLabFixtures;

    /** El destino (un fijo de ficción de Bogotá). */
    private const DESTINO = '+576010000099';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->voiceLabRoutes();
        $this->importBaseline();
    }

    /** El contenido de un perfil con un destino, como lo escribe quien lo crea en el CRM. */
    private static function conDestino(string $telefono = self::DESTINO): array
    {
        return [
            'voiceId' => 'pNInz6obpgDQGcFmaJgB',
            'greetings' => ['inbound' => '¿En qué te puedo ayudar hoy?', 'outbound' => 'Te llamo porque pediste información de Iron Body.'],
            'holes' => ['presentation' => 'Te ayudo con planes, horarios y la sede.', 'closing' => 'Gracias por tu tiempo.'],
            'maxDurationSeconds' => 180,
            'tools' => ['consultar_planes', 'consultar_horarios', 'no_volver_a_llamar', 'solicitar_humano'],
            'systemTools' => ['end_call', 'transfer_to_number'],
            'transfer' => ['destinations' => [['alias' => 'Recepción', 'phone' => $telefono]]],
            'privacy' => ['retentionDays' => 30],
            'voicemail' => 'hang_up',
        ];
    }

    /** Un perfil con destino creado por la API de S8 (el cifrado real) y enviado a revisión. */
    private function profileWithDestination(Admin $author): string
    {
        $uuid = (string) $this->studio('POST', 'voice/profiles', $author, [
            'clientRequestId' => (string) Str::uuid(), 'label' => 'Voz con recepción', 'content' => self::conDestino(),
        ])->assertCreated()->json('data.uuid');
        $this->studio('POST', "voice/profiles/{$uuid}/submit", $author)->assertOk();

        return $uuid;
    }

    /** Todo lo que el laboratorio dejó escrito o respondió, para buscar el número. */
    private function everything(string ...$respuestas): string
    {
        return implode("\n", [
            json_encode(DB::table('ultron_studio_voice_agents')->get()->all()),
            json_encode(DB::table('ultron_studio_voice_lab_sessions')->get()->all()),
            json_encode(DB::table('ultron_studio_audit_events')->get()->all()),
            ...$respuestas,
        ]);
    }

    private function assertNoTraceOf(string $telefono, string $todo): void
    {
        $digitos = ltrim($telefono, '+');
        foreach ([$telefono, $digitos, substr($digitos, 2), substr($digitos, -7)] as $rastro) {
            $this->assertStringNotContainsString($rastro, $todo, "rastro del número: {$rastro}");
        }
    }

    public function test_con_elevenlabs_el_agente_de_laboratorio_lleva_la_transferencia_aprobada_y_el_numero_no_queda_en_ningun_sitio(): void
    {
        $this->voiceLabElevenLabs();
        $this->voiceVerified();
        $this->fakeElevenLabs();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $tina = $this->tester();
        $version = $this->labVersion($ana);
        $perfil = $this->profileWithDestination($ana);

        $r = $this->openSession($tina, $version->uuid, $perfil, null, $this->acceptedMax($tina))->assertCreated();
        $d = $r->json('data');
        $this->assertSame(['open', 'elevenlabs', 'deployed'], [$d['status'], $d['mode'], $d['agent']['status']]);

        // La carga que recibió ElevenLabs: end_call y transfer_to_number, en conferencia, al destino aprobado.
        $cuerpo = $this->sim['agents'][$d['agent']['providerAgentId']];
        $sistema = $cuerpo['conversation_config']['agent']['prompt']['built_in_tools'];
        $this->assertSame(['end_call', 'transfer_to_number'], array_keys($sistema));
        $this->assertSame([[
            'transfer_destination' => ['type' => 'phone', 'phone_number' => self::DESTINO],
            'condition' => sprintf(StudioVoiceTemplate::TRANSFER_CONDITION, 'Recepción'),
            'transfer_type' => 'conference',
        ]], $sistema['transfer_to_number']['params']['transfers']);
        // Y el prompt que dice cuándo transferir: solo con el «sí» de solicitar_humano.
        $this->assertStringContainsString('nunca la pasas sin esa respuesta', $cuerpo['conversation_config']['agent']['prompt']['prompt']);
        $this->assertSame('es', $cuerpo['conversation_config']['agent']['language']);

        // La compilación del perfil CON sus destinos (el propósito no entra en la
        // especificación: es la huella que exigirá la publicación, P-19).
        $agente = DB::table('ultron_studio_voice_agents')->sole();
        $compilada = StudioVoiceCompiler::compile(
            StudioVoicePublishing::versionInput($version), StudioVoiceProfiles::compilerInput(StudioVoiceProfiles::findByUuid($perfil)), 300, StudioVoiceCompiler::PURPOSE_LAB,
        );
        $this->assertSame($compilada['specHash'], $agente->spec_hash);
        $this->assertSame([['alias' => 'Recepción', 'ref' => StudioVoiceProfiles::phoneHash(self::DESTINO)]],
            array_map(static fn (array $x): array => ['alias' => $x['alias'], 'ref' => $x['ref']], $compilada['spec']['transfer']['destinations']));
        // Un agente de laboratorio nunca entra en la cadena (E7-3): sin destinos aprobados en su fila.
        $this->assertNull($agente->transfer_destination_hashes);

        // El número solo viajó a ElevenLabs en la carga: ni en la base, ni en la respuesta, ni en el libro.
        $this->assertNoTraceOf(self::DESTINO, $this->everything($r->getContent()));
    }

    public function test_con_el_simulador_tambien_se_abre_y_sin_red(): void
    {
        $this->voiceLabFake();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $tina = $this->tester();
        $version = $this->labVersion($ana);
        $perfil = $this->profileWithDestination($ana);

        $r = $this->openSession($tina, $version->uuid, $perfil, null, 0)->assertCreated();
        $this->assertSame(['open', 'fake', 'deployed'], [$r->json('data.status'), $r->json('data.mode'), $r->json('data.agent.status')]);
        Http::assertNothingSent();
        $this->assertNoTraceOf(self::DESTINO, $this->everything($r->getContent()));
    }

    public function test_un_destino_que_no_es_el_aprobado_no_abre_nada_ni_reserva(): void
    {
        $this->voiceLabFake();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $tina = $this->tester();
        $version = $this->labVersion($ana);

        // Un perfil escrito a mano en la base cuya lista cifrada no es la de su
        // contenido: OTRO número con la huella del aprobado (R-07).
        $contenido = $this->profileContent([
            'tools' => ['consultar_planes', 'consultar_horarios', 'no_volver_a_llamar', 'solicitar_humano'],
            'systemTools' => ['end_call', 'transfer_to_number'],
            'transfer' => ['destinations' => [['alias' => 'Recepción', 'phoneHash' => StudioVoiceProfiles::phoneHash(self::DESTINO)]]],
        ]);
        $perfil = (string) Str::uuid();
        $ahora = now();
        $id = DB::table('ultron_studio_voice_profiles')->insertGetId([
            'uuid' => $perfil, 'client_request_id' => (string) Str::uuid(), 'revision' => 1, 'based_on_profile_id' => null, 'label' => 'Manipulado', 'language' => 'es',
            'max_duration_seconds' => 180, 'retention_days' => 30, 'record_calls' => false, 'record_voice' => false, 'transfer_count' => 1,
            // El cifrado del cast `encrypted:array` del modelo de S8.
            'transfer_destinations_encrypted' => Crypt::encryptString((string) json_encode([['alias' => 'Recepción', 'phoneHash' => StudioVoiceProfiles::phoneHash(self::DESTINO), 'e164' => '+576010000098']])),
            'content' => json_encode($contenido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'content_hash' => StudioVoiceCompiler::profileHash($contenido),
            'status' => 'draft', 'created_by_admin_id' => $ana->id, 'created_by_name' => $ana->name, 'created_by_role' => $ana->role,
            'created_at' => $ahora, 'status_changed_at' => $ahora, 'updated_at' => $ahora,
        ]);
        DB::table('ultron_studio_voice_profiles')->where('id', $id)->update([
            'status' => 'submitted', 'submitted_by_admin_id' => $ana->id, 'submitted_by_name' => $ana->name, 'submitted_by_role' => $ana->role,
            'submitted_at' => $ahora, 'status_changed_at' => $ahora, 'updated_at' => $ahora,
        ]);

        $r = $this->openSession($tina, $version->uuid, $perfil, null, 0)->assertStatus(422)
            ->assertJsonPath('code', 'voice_compile_refused')->assertJsonPath('reason', 'transfer_destination_not_in_profile')->assertJsonPath('field', 'transfer.destinations');
        $this->assertNoTraceOf('+576010000098', $r->getContent());
        $this->assertSame(0, StudioVoiceLabSession::query()->count());
        $this->assertSame(0, DB::table('ultron_studio_voice_agents')->count());
    }

    public function test_destinos_que_este_servidor_no_puede_descifrar_dan_409_sin_reservar_nada(): void
    {
        $this->voiceLabFake();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $tina = $this->tester();
        $version = $this->labVersion($ana);
        $perfil = $this->profileWithDestination($ana);

        // La `APP_KEY` rotada: el cifrado del perfil ya no se lee.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        $r = $this->openSession($tina, $version->uuid, $perfil, null, 0)->assertStatus(409)->assertJsonPath('code', 'voice_destinations_unreadable');
        $this->assertStringNotContainsString('eyJ', $r->getContent(), 'ni rastro del texto cifrado');
        $this->assertSame(0, StudioVoiceLabSession::query()->count());
        $this->assertSame(0, DB::table('ultron_studio_voice_agents')->count());
    }

    /**
     * Cómo se porta el agente de laboratorio si el interlocutor pide un humano:
     * su `solicitar_humano` llega a Laravel con el `conversation_id` de la
     * sesión web, que no es una llamada de `voice_calls`, y la herramienta
     * responde su negativa neutra. Sin ese «sí», el prompt no deja transferir.
     */
    public function test_en_el_laboratorio_solicitar_humano_no_confirma_a_nadie(): void
    {
        $r = app(VoiceTools::class)->handle('solicitar_humano', (string) json_encode(['conversation_id' => 'conv_lab_sesion_web_1']));
        $this->assertSame(200, $r['http']);
        $this->assertSame(['ok' => false, 'verdict' => 'call_not_found', 'message' => VoiceTools::NEUTRAL], $r['body']);
        $this->assertSame(0, DB::table('voice_call_events')->count(), 'sin llamada, sin evento');
    }
}
