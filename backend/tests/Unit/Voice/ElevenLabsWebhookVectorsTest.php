<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Dto\VoiceWebhookVerdict;
use App\Services\Voice\ElevenLabs\ElevenLabsEventVerifier;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El verificador del webhook post-llamada contra el fixture CONFIRMADO de
 * TEST8 (`tests/fixtures/voice/elevenlabs-webhook-vectors.json`, 27 casos):
 * el resultado de la firma, si se procesa, el tipo del sobre y el estado HTTP
 * que le corresponde (DF-E1: toda firma que no es `ok` es 401, sin leer el
 * cuerpo; un sobre firmado, 200). Manda la firma del H6 sin cambios (DF-E16).
 * Sin red: `Http::preventStrayRequests()`.
 */
class ElevenLabsWebhookVectorsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/voice/elevenlabs-webhook-vectors.json'), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function casos(): iterable
    {
        foreach (self::fixture()['verify'] as $c) {
            yield $c['name'] => [$c];
        }
    }

    public function test_el_fixture_tiene_los_27_vectores(): void
    {
        $this->assertSame(['verify' => 27, 'total' => 27], self::fixture()['counts']);
    }

    /** @param  array<string, mixed>  $c */
    #[DataProvider('casos')]
    public function test_vector(array $c): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['voice.elevenlabs.webhook_secret' => $c['secret']]);

        $v = (new ElevenLabsEventVerifier)->verifyPostCall($c['rawBody'], $c['header'], $c['now']);
        $e = $c['expected'];

        $this->assertSame($e['signatureResult'], $v->reason);
        $this->assertSame($e['processed'], $v->accepted());
        $this->assertSame($e['http'], $v->accepted() ? 200 : 401, 'DF-E1');
        if ($e['processed']) {
            $this->assertSame($e['envelopeType'] ?? VoiceWebhookVerdict::KIND_UNKNOWN, $v->kind);
        } else {
            $this->assertNull($v->kind, 'una firma mala no lee el cuerpo');
        }
        Http::assertNothingSent();
    }
}
