<?php

namespace Tests\PerfH8;

use App\Services\Marketing\OutboundContentGuard;
use App\Services\Telephony\VoiceTranscriptGuard;
use App\Services\Voice\ElevenLabs\ElevenLabsEventVerifier;
use App\Services\Voice\ElevenLabs\ElevenLabsWebhookEnvelope;
use App\Services\Voice\ElevenLabs\ElevenLabsWebhookSignature;
use App\Models\MarketingMessage;
use Tests\TestCase;

/**
 * PERF8 (Hito 8): DÓNDE SE VA EL TIEMPO de la post-llamada (CPU pura: sin base,
 * sin red). Tramos del camino de `ElevenLabsPostCallController` con el mismo
 * cuerpo que mide el arnés (`cuerpoTranscripcion`, el `pc_completed` del
 * fixture alargado): firma (HMAC del cuerpo crudo), el `json_decode` de
 * `ElevenLabsWebhookEnvelope::type()`, el sobre (`envelope()`, que vuelve a
 * decodificar y separa los turnos del agente) y la guarda posterior
 * (`VoiceTranscriptGuard::inspect`, una `OutboundContentGuard::inspect()` por
 * turno del agente). 3 de calentamiento y 30 cronometradas por tramo y tamaño.
 * Escribe resultados/tramos-post-llamada.json.
 */
final class PerfH8TramosTest extends TestCase
{
    private const SECRET = 'whsec_perf_h8_tramos_000000000000000000001';

    public function test_tramos_post_llamada(): void
    {
        $plantilla = $this->plantilla();
        $out = [];
        foreach ([['9 KB', 40, 0], ['40 KB', 200, 0], ['~100 KB', 200, 900], ['~1 MB', 1000, 1900]] as [$etiqueta, $turnos, $largo]) {
            $cuerpo = $this->cuerpo($plantilla, $turnos, $largo);
            $t = time();
            $cabecera = 't='.$t.',v0='.hash_hmac('sha256', $t.'.'.$cuerpo, self::SECRET);
            $veredicto = ElevenLabsEventVerifier::envelope($cuerpo);
            $guarda = $veredicto->guardTurns();
            $textoAgente = array_sum(array_map(static fn (array $g): int => strlen($g['text']), $guarda));
            $this->assertSame([], VoiceTranscriptGuard::inspect($guarda, []), 'el cuerpo de la medida tiene que ser limpio');
            $motor = new OutboundContentGuard;
            $tramos = [
                'firma (HMAC-SHA256 del cuerpo)' => fn () => ElevenLabsWebhookSignature::verify($cuerpo, $cabecera, self::SECRET, $t),
                'json_decode #1 (Envelope::type)' => fn () => ElevenLabsWebhookEnvelope::type($cuerpo),
                'sobre (envelope: json_decode #2 + turnos del agente)' => fn () => ElevenLabsEventVerifier::envelope($cuerpo),
                'guarda (VoiceTranscriptGuard::inspect)' => fn () => VoiceTranscriptGuard::inspect($guarda, []),
                '  · de ella, OutboundContentGuard::inspect por turno' => function () use ($guarda, $motor): void {
                    foreach ($guarda as $g) {
                        $motor->inspect($g['text'], MarketingMessage::SENDER_AI, false);
                    }
                },
            ];
            $fila = ['size' => $etiqueta, 'bodyBytes' => strlen($cuerpo), 'agentTurns' => count($guarda), 'agentTextBytes' => $textoAgente, 'stages' => []];
            foreach ($tramos as $nombre => $f) {
                for ($i = 0; $i < 3; $i++) {
                    $f();
                }
                $ms = [];
                for ($i = 0; $i < 30; $i++) {
                    $a = hrtime(true);
                    $f();
                    $ms[] = (hrtime(true) - $a) / 1e6;
                }
                sort($ms);
                $fila['stages'][$nombre] = ['p50' => round($ms[14], 3), 'p95' => round($ms[28], 3), 'max' => round($ms[29], 3)];
            }
            $out[] = $fila;
        }
        $dir = base_path('docs/ultron-studio/ops/perf-h8/resultados');
        file_put_contents("{$dir}/tramos-post-llamada.json", json_encode(['at' => gmdate('c'), 'php' => PHP_VERSION, 'load' => sys_getloadavg(), 'results' => $out],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    /** @return array<string, mixed> */
    private function plantilla(): array
    {
        foreach (json_decode((string) file_get_contents(base_path('tests/fixtures/voice/elevenlabs-webhook-bodies.json')), true)['postCall'] as $caso) {
            if (($caso['bodyKey'] ?? null) === 'pc_completed') {
                return json_decode($caso['rawBody'], true);
            }
        }
        $this->fail('falta pc_completed');
    }

    /** El mismo generador que `PerfH8QueriesTest::cuerpoTranscripcion` (turnos limpios; el primero, el aviso). */
    private function cuerpo(array $plantilla, int $turnos, int $largo): string
    {
        $base = $plantilla['data']['transcript'];
        $agente = ['La Sede Centro abre de lunes a viernes de 5:00 a 22:00 y los sábados de 7:00 a 14:00.', 'Con gusto te cuento cómo funcionan nuestros planes y qué incluye cada uno.',
            'Puedes entrenar en la zona de máquinas y en las clases grupales del horario de la sede.', 'Si quieres, te explico qué necesitas traer el primer día para empezar.'];
        $persona = ['¿Y los fines de semana también abren?', 'Me interesa entrenar en la mañana.', 'Vale, gracias por la información.', '¿Qué clases grupales tienen?'];
        $t = [$base[0]];
        for ($k = 1; $k < $turnos; $k++) {
            if ($k % 2 === 1) {
                $t[] = ['role' => 'user', 'message' => $persona[$k % 4], 'time_in_call_secs' => 5 * $k, 'tool_calls' => [], 'tool_results' => [], 'feedback' => null, 'conversation_turn_metrics' => null];

                continue;
            }
            $texto = $agente[$k % 4];
            while ($largo > 0 && strlen($texto) < $largo) {
                $texto .= ' '.$agente[(strlen($texto) + $k) % 4];
            }
            $t[] = ['role' => 'agent', 'message' => $texto, 'time_in_call_secs' => 5 * $k, 'tool_calls' => [], 'tool_results' => [], 'feedback' => null, 'conversation_turn_metrics' => null];
        }
        $c = $plantilla;
        $c['data']['agent_id'] = 'agent_perf_0025';
        $c['data']['conversation_id'] = 'conv_perf_tramos';
        $c['data']['transcript'] = $t;
        $json = json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        while (strlen($json) > 1048576 && count($c['data']['transcript']) > 2) {
            array_pop($c['data']['transcript']);
            $json = json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $json;
    }
}
