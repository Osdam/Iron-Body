<?php

namespace Tests\Feature\Telephony;

use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoicePostCall;
use App\Services\Telephony\VoiceTranscriptGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * E10-4 (SEC8-b M-2): una post-llamada con MUCHAS violaciones de la guarda
 * posterior. Sin tope, el veredicto (`guard_verdict.codes`) pasaba de los
 * 4 096 bytes del CHECK `vce_codes_chk` de PostgreSQL desde unos 76 turnos con
 * violación: el INSERT caía con 23514, `ingest()` deshacía TODO (ni evento, ni
 * sello, ni FRENO), la ruta respondía 500 y ElevenLabs, que reintenta el mismo
 * cuerpo, acababa desactivando el webhook (D8-16). SQLite no tiene ese CHECK:
 * allí la misma prueba cae por el tope (se guardaban las 200).
 *
 * Ahora se guardan como mucho {@see VoicePostCall::MAX_GUARD_CODES} entradas
 * (las primeras, en orden de turno) y `truncated` con las que no caben; el
 * veredicto (`violation`) y el freno no dependen del tamaño de la evidencia.
 */
class VoicePostCallGuardCapTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const URI = '/api/webhooks/voice/elevenlabs/post-call';

    private const CONVERSATION = 'conv_h8fx_m2_0001';

    private const AVISO = 'Hola, te habla el asistente virtual de Iron Body Workout, un sistema de inteligencia artificial. Si no quieres recibir más llamadas, dímelo.';

    private const DESCUENTO = 'No tengo ningún descuento para ofrecerte.';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->voiceWorld();
        $this->travelTo(Carbon::createFromTimestamp(1793804400));   // world.now del fixture
    }

    /** Una post-llamada firmada con el aviso en el primer turno y `$n` respuestas del agente que nombran un descuento. */
    private function body(int $n): string
    {
        $turnos = [['role' => 'agent', 'message' => self::AVISO]];
        for ($i = 0; $i < $n; $i++) {
            $turnos[] = ['role' => 'user', 'message' => '¿Me haces un descuento?'];
            $turnos[] = ['role' => 'agent', 'message' => self::DESCUENTO];
        }

        return (string) json_encode(['type' => 'post_call_transcription', 'event_timestamp' => 1793804340, 'data' => [
            'agent_id' => self::SEALED_AGENT, 'conversation_id' => self::CONVERSATION, 'transcript' => $turnos,
            'metadata' => ['call_duration_secs' => 290],
        ]], JSON_UNESCAPED_UNICODE);
    }

    /** @return list<array{turn:int, code:string}> lo que da la guarda sobre esos turnos (la prueba no fija los códigos del motor) */
    private static function expectedViolations(int $n): array
    {
        $turnos = [['index' => 0, 'text' => self::AVISO, 'tools' => [], 'transfers' => [], 'planResults' => []]];
        for ($i = 0; $i < $n; $i++) {
            $turnos[] = ['index' => 2 + 2 * $i, 'text' => self::DESCUENTO, 'tools' => [], 'transfers' => [], 'planResults' => []];
        }

        return VoiceTranscriptGuard::inspect($turnos, []);
    }

    public function test_doscientas_violaciones_dan_200_guardan_el_veredicto_acotado_y_echan_el_freno(): void
    {
        $esperadas = self::expectedViolations(200);
        $this->assertCount(200, $esperadas, 'el aviso del primer turno es limpio y cada respuesta con «descuento» es una violación');
        $llamada = $this->voiceCall('outbound', 'active', ['provider_conversation_id' => self::CONVERSATION, 'provider_call_sid' => 'CA000000000000000000000000a8020001']);
        $cuerpo = $this->body(200);

        $this->rawPost(self::URI, $cuerpo, ['ElevenLabs-Signature' => $this->elevenSignature($cuerpo)])
            ->assertOk()->assertJson(['ok' => true, 'status' => 'completed', 'sealVerified' => true, 'violations' => 200]);

        $veredicto = VoiceCallEvent::query()->where('kind', 'guard_verdict')->sole();
        $this->assertSame('violation', $veredicto->outcome, 'el veredicto no se pierde');
        $this->assertSame(array_slice($esperadas, 0, VoicePostCall::MAX_GUARD_CODES), $veredicto->codes['violations'], 'las primeras, en orden de turno');
        $this->assertSame(200 - VoicePostCall::MAX_GUARD_CODES, $veredicto->codes['truncated'], 'y cuántas no caben');
        $crudo = (string) DB::table('voice_call_events')->where('id', $veredicto->id)->value('codes');
        $this->assertLessThanOrEqual(4096, strlen($crudo), 'cabe en vce_codes_chk (4 096 bytes)');

        $freno = VoiceBrakeEntry::query()->sole();
        $this->assertSame(['open', 'guard_violation', $veredicto->id, (int) $llamada->id], [$freno->kind, $freno->reason, $freno->event_id, $freno->call_id]);
        $this->assertSame(['codes' => array_values(array_unique(array_column($esperadas, 'code')))], $freno->evidence);
        $this->assertTrue(VoiceBrake::isOpen());

        $fila = VoiceCall::query()->findOrFail($llamada->id);
        $this->assertSame(['completed', 290, true], [$fila->status, $fila->duration_seconds, $fila->seal_verified]);
        $this->assertSame(['post_call_transcription', 'guard_verdict'], VoiceCallEvent::query()->orderBy('id')->pluck('kind')->all());

        // ElevenLabs reintenta el mismo cuerpo: duplicado, 200 y nada nuevo.
        $this->rawPost(self::URI, $cuerpo, ['ElevenLabs-Signature' => $this->elevenSignature($cuerpo)])->assertOk()->assertJson(['ok' => true, 'duplicate' => true]);
        $this->assertSame([2, 1], [VoiceCallEvent::query()->count(), VoiceBrakeEntry::query()->count()]);
        Http::assertNothingSent();
    }

    public function test_por_debajo_del_tope_se_guardan_todas_y_sin_contador(): void
    {
        $esperadas = self::expectedViolations(VoicePostCall::MAX_GUARD_CODES);
        $this->voiceCall('outbound', 'active', ['provider_conversation_id' => self::CONVERSATION, 'provider_call_sid' => 'CA000000000000000000000000a8020002']);
        $cuerpo = $this->body(VoicePostCall::MAX_GUARD_CODES);

        $this->rawPost(self::URI, $cuerpo, ['ElevenLabs-Signature' => $this->elevenSignature($cuerpo)])->assertOk()->assertJson(['violations' => VoicePostCall::MAX_GUARD_CODES]);
        $this->assertSame(['violations' => $esperadas], VoiceCallEvent::query()->where('kind', 'guard_verdict')->sole()->codes, 'la misma forma de antes');
        $this->assertTrue(VoiceBrake::isOpen());
    }

    public function test_el_tope_tambien_es_de_bytes_aunque_el_motor_traiga_codigos_largos(): void
    {
        // 50 entradas con un código de 64 caracteres y un turno de 7 cifras pasarían de 4 096 bytes: el tope de bytes corta antes.
        $largas = array_map(static fn (int $i): array => ['turn' => 1000000 + $i, 'code' => str_repeat('x', 63).chr(97 + $i % 26)], range(0, 59));
        $codigos = (new ReflectionMethod(VoicePostCall::class, 'verdictCodes'))->invoke(null, $largas);

        $this->assertLessThanOrEqual(VoicePostCall::MAX_GUARD_CODES_BYTES, strlen((string) json_encode($codigos)));
        $guardadas = count($codigos['violations']);
        $this->assertGreaterThan(0, $guardadas);
        $this->assertLessThan(VoicePostCall::MAX_GUARD_CODES, $guardadas, 'con estos códigos caben menos de 50');
        $this->assertSame(array_slice($largas, 0, $guardadas), $codigos['violations'], 'siempre las primeras');
        $this->assertSame(60 - $guardadas, $codigos['truncated']);
        $this->assertSame(['violations' => []], (new ReflectionMethod(VoicePostCall::class, 'verdictCodes'))->invoke(null, []), 'limpio: la forma de siempre');
    }
}
