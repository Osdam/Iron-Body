<?php

namespace Tests\Feature\Telephony;

use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoicePhone;
use App\Services\Telephony\VoicePublishedConfig;
use App\Services\Telephony\VoiceTelephonyStatus;
use App\Services\Telephony\VoiceTransferDirectory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * Los DESTINOS DE TRANSFERENCIA aprobados (contrato H8, D8-14; Enmienda 5,
 * E5-1, que sustituye a E4-7): los COMPILADOS en el agente
 * (`ultron_studio_voice_agents.transfer_destination_hashes`, alias y huella
 * `voice-phone`), que `VoicePublishedConfig` lee de la fila del agente que ya
 * une, sin tocar el perfil ni descifrar nada. La guarda posterior compara
 * huellas: un destino aprobado no es violación; uno ajeno, sí, y abre el
 * freno. Sin escribir o sin forma: ninguno vale. Sin red.
 */
class VoiceTransferDestinationsTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const RECEPCION = '+576010000123';

    private const VENTAS = '+573000000099';

    private const AJENO = '+576010000999';

    private const AVISO = 'Hola, te habla el asistente virtual de Iron Body Workout, un sistema de inteligencia artificial. Si no quieres recibir más llamadas, dímelo.';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->voiceWorld();
    }

    public function test_el_lector_da_por_parte_los_destinos_compilados_en_el_agente_solo_alias_y_huella(): void
    {
        $this->publishVoice(transfers: [['Ventas', self::VENTAS], ['Recepción', self::RECEPCION]]);
        $partes = VoicePublishedConfig::read();

        $this->assertTrue($partes['canary']->ok());
        $this->assertSame([['alias' => 'Recepción', 'hash' => VoicePhone::hash(self::RECEPCION)], ['alias' => 'Ventas', 'hash' => VoicePhone::hash(self::VENTAS)]],
            $partes['canary']->transferDestinations);
        $this->assertSame([VoicePhone::hash(self::RECEPCION), VoicePhone::hash(self::VENTAS)], $partes['canary']->transferDestinationHashes());
        $this->assertSame(['Recepción', 'Ventas'], $partes['canary']->transferAliases());
        $this->assertNull($partes['rest']->transferDestinationHashes(), 'una parte sin entrada no aprueba a nadie');
        $volcado = var_export($partes, true);
        foreach (['6010000123', '3000000099'] as $s) {
            $this->assertStringNotContainsString($s, $volcado, 'ningún número');
        }
        $this->assertSame(['canary' => 2, 'rest' => null], VoiceTelephonyStatus::snapshot()['transferDirectory']);
        Http::assertNothingSent();
    }

    public function test_sin_destinos_la_lista_es_vacia_y_sin_escribir_ninguno_vale(): void
    {
        $this->publishVoice(agent: ['transfer_destination_hashes' => '[]']);
        $this->assertSame([], VoicePublishedConfig::read()['canary']->transferDestinationHashes());

        // Columna sin escribir (S8 todavía no la escribe): la parte sigue vigente y ningún destino vale.
        $this->publishVoice('agent_h8fx_canary_0004');
        $parte = VoicePublishedConfig::read()['canary'];
        $this->assertSame([true, 2, null], [$parte->ok(), $parte->seq, $parte->transferDestinationHashes()]);
        $this->assertSame(['canary' => null, 'rest' => null], VoiceTelephonyStatus::snapshot()['transferDirectory']);
    }

    /** @return array<string, array{0: string}> */
    public static function columnasSinForma(): array
    {
        $huella = str_repeat('a', 64);

        return [
            'no es JSON' => ['{no-json'],
            'no es una lista' => [(string) json_encode(['a' => ['alias' => 'Recepción', 'hash' => $huella]])],
            'más de tres' => [(string) json_encode(array_fill(0, 4, ['alias' => 'Recepción', 'hash' => $huella]))],
            'una huella sin forma' => [(string) json_encode([['alias' => 'Recepción', 'hash' => 'xyz']])],
            'sin alias' => [(string) json_encode([['alias' => '  ', 'hash' => $huella]])],
            'un alias demasiado largo' => [(string) json_encode([['alias' => str_repeat('a', 61), 'hash' => $huella]])],
            'una clave de más (el número)' => [(string) json_encode([['alias' => 'Recepción', 'hash' => $huella, 'e164' => self::RECEPCION]])],
            'la clave con otro nombre' => [(string) json_encode([['alias' => 'Recepción', 'phoneHash' => $huella]])],
        ];
    }

    #[DataProvider('columnasSinForma')]
    public function test_una_columna_sin_forma_no_aprueba_a_nadie_y_el_lector_no_lanza(string $columna): void
    {
        if (json_decode($columna) === null && DB::getDriverName() === 'pgsql') {
            // En PG la columna `json` ni siquiera admite escribirlo: el caso solo existe en SQLite (TEXT).
            $this->expectException(QueryException::class);
        }
        $this->publishVoice(agent: ['transfer_destination_hashes' => $columna]);
        $parte = VoicePublishedConfig::read()['canary'];
        $this->assertSame([true, null], [$parte->ok(), $parte->transferDestinations]);
    }

    public function test_las_claves_pueden_venir_en_otro_orden(): void
    {
        // Un `jsonb` de PG ordena las claves a su manera.
        $this->publishVoice(agent: ['transfer_destination_hashes' => (string) json_encode([['hash' => VoicePhone::hash(self::RECEPCION), 'alias' => 'Recepción']])]);
        $this->assertSame([['alias' => 'Recepción', 'hash' => VoicePhone::hash(self::RECEPCION)]], VoicePublishedConfig::read()['canary']->transferDestinations);
    }

    public function test_la_guarda_compara_huellas_un_destino_aprobado_no_es_violacion_y_uno_ajeno_si(): void
    {
        $this->publishVoice(transfers: [['Recepción', self::RECEPCION]]);

        $aprobada = $this->voiceCall('inbound', 'active', ['provider_conversation_id' => 'conv_tr_0001']);
        $this->postCall('conv_tr_0001', self::RECEPCION)->assertOk()->assertJson(['violations' => 0]);
        $this->assertSame('clean', VoiceCallEvent::query()->where('call_id', $aprobada->id)->where('kind', 'guard_verdict')->sole()->outcome);
        $this->assertFalse(VoiceBrake::isOpen());

        $ajena = $this->voiceCall('inbound', 'active', ['provider_conversation_id' => 'conv_tr_0002', 'remote' => '+573000000032']);
        $this->postCall('conv_tr_0002', self::AJENO)->assertOk()->assertJson(['violations' => 1]);
        $veredicto = VoiceCallEvent::query()->where('call_id', $ajena->id)->where('kind', 'guard_verdict')->sole();
        $this->assertSame(['violation', [['turn' => 2, 'code' => 'voice_transfer_not_approved']]], [$veredicto->outcome, $veredicto->codes['violations']]);
        $this->assertTrue(VoiceBrake::isOpen(), 'una transferencia a un destino no aprobado abre el freno');
        Http::assertNothingSent();
    }

    public function test_sin_destinos_escritos_toda_transferencia_es_violacion(): void
    {
        $this->publishVoice();   // la columna sin escribir
        $this->voiceCall('inbound', 'active', ['provider_conversation_id' => 'conv_tr_0003']);
        $this->postCall('conv_tr_0003', self::RECEPCION)->assertOk()->assertJson(['violations' => 1]);
        $this->assertTrue(VoiceBrake::isOpen());
    }

    public function test_si_la_parte_apunta_ya_a_otro_agente_ningun_destino_vale_y_si_vuelve_a_el_si(): void
    {
        $primera = $this->publishVoice(transfers: [['Recepción', self::RECEPCION]]);
        $vieja = $this->voiceCall('inbound', 'active');
        $this->assertSame([VoicePhone::hash(self::RECEPCION)], VoiceTransferDirectory::approvedHashesFor($vieja));
        $this->assertSame(['Recepción'], VoiceTransferDirectory::aliasesFor($vieja));

        $this->publishVoice('agent_h8fx_canary_0004', transfers: [['Recepción', self::RECEPCION]]);   // la 2, con su agente
        $this->assertNull(VoiceTransferDirectory::approvedHashesFor($vieja), 'sellada con el agente de la 1: no se sabe qué destinos llevaba');
        $this->assertSame([], VoiceTransferDirectory::aliasesFor($vieja));
        $nueva = $this->voiceCall('inbound', 'active', ['voice_publication_seq' => 2, 'provider_agent_id' => 'agent_h8fx_canary_0004', 'remote' => '+573000000033']);
        $this->assertSame([VoicePhone::hash(self::RECEPCION)], VoiceTransferDirectory::approvedHashesFor($nueva));

        // Vuelta atrás al agente de la 1 (D8-20 nivel 1): la 3 apunta a él, con sus mismos destinos compilados.
        $this->publishVoice(agent: ['row' => $primera['agentRowId']]);
        $this->assertSame([VoicePhone::hash(self::RECEPCION)], VoiceTransferDirectory::approvedHashesFor($vieja), 'los destinos los fija el agente, no la entrada');
        $this->assertSame(['Recepción'], VoiceTransferDirectory::aliasesFor($vieja));
        $this->assertNull(VoiceTransferDirectory::approvedHashesFor($nueva), 'la de la 2 ya no tiene su agente en la parte');
        // Misma entrada, otro agente en el sello (imposible sin un error): ninguno.
        $otroAgente = $this->voiceCall('inbound', 'active', ['voice_publication_seq' => 3, 'provider_agent_id' => 'agent_h8fx_canary_0009', 'remote' => '+573000000035']);
        $this->assertNull(VoiceTransferDirectory::approvedHashesFor($otroAgente));
        // Una llamada sin sello (sin publicación al empezar) tampoco tiene destinos.
        $sinSello = $this->voiceCall('inbound', 'denied', ['stamp_state' => 'off', 'voice_publication_seq' => null, 'part' => null, 'studio_version_uuid' => null,
            'studio_content_hash' => null, 'voice_profile_hash' => null, 'spec_hash' => null, 'payload_hash' => null, 'compiler_version' => null,
            'provider_agent_id' => null, 'remote' => '+573000000034']);
        $this->assertNull(VoiceTransferDirectory::approvedHashesFor($sinSello));
    }

    private function postCall(string $conversacion, string $destino): TestResponse
    {
        $cuerpo = (string) json_encode(['type' => 'post_call_transcription', 'event_timestamp' => now()->getTimestamp(), 'data' => [
            'agent_id' => self::SEALED_AGENT, 'conversation_id' => $conversacion, 'status' => 'done', 'transcript' => [
                ['role' => 'agent', 'message' => self::AVISO, 'tool_calls' => [], 'tool_results' => []],
                ['role' => 'user', 'message' => 'Quiero hablar con una persona.', 'tool_calls' => [], 'tool_results' => []],
                ['role' => 'agent', 'message' => 'Te comunico.', 'tool_calls' => [['type' => 'system', 'tool_name' => 'transfer_to_number',
                    'params_as_json' => (string) json_encode(['transfer_number' => $destino])]], 'tool_results' => []],
            ],
            'metadata' => ['call_duration_secs' => 41],
        ]]);

        return $this->rawPost('/api/webhooks/voice/elevenlabs/post-call', $cuerpo, ['ElevenLabs-Signature' => $this->elevenSignature($cuerpo)]);
    }
}
