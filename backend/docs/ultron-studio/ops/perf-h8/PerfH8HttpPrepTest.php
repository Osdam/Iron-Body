<?php

namespace Tests\PerfH8;

require_once __DIR__.'/PerfH8Seeding.php';

use App\Models\Admin;
use App\Services\Admin\AdminSessionService;
use App\Services\Telephony\VoicePhone;
use App\Services\Telephony\VoicePublishedConfig;
use App\Services\Twilio\TwilioRequestSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\UltronStudio\IntegrationFixtures;
use Tests\Feature\UltronStudio\PublicationFixtures;
use Tests\Feature\UltronStudio\StudioFixtures;
use Tests\Feature\UltronStudio\StudioVoiceFixtures;
use Tests\TestCase;

/**
 * PERF8 (Hito 8): PREPARA el banco HTTP de extremo a extremo (`serve.sh seed`)
 * sobre `ultron_studio_h8_perf_http`, una copia de la base sembrada. No mide.
 *
 * Deja en PERF_HTTP_DIR (fuera del repo, 700):
 *  - `manifest.json`: 8 sesiones de Super Admin (el cupo del CRM es por
 *    persona), el agente sellado, los números de ficción, las CREDENCIALES DE
 *    PRUEBA del mundo de la medida (valores sintéticos de `PerfH8Seeding`, las
 *    mismas que el servidor recibe por el entorno) y las llamadas `active`
 *    preparadas para las herramientas;
 *  - `postcall-9k-*.json` y `postcall-1m-*.json`: transcripciones LIMPIAS (la
 *    guarda no echa el freno), una por llamada preparada; el cliente las firma
 *    al enviarlas (la firma de ElevenLabs lleva la hora);
 *  - `twilio-*.json`: avisos de la subcuenta ya firmados (la firma de Twilio no
 *    lleva hora), uno por `IdempotencyToken`.
 */
final class PerfH8HttpPrepTest extends TestCase
{
    use IntegrationFixtures, PerfH8Seeding, PublicationFixtures, StudioFixtures, StudioVoiceFixtures {
        PublicationFixtures::personWith insteadof IntegrationFixtures;
    }

    private const CALLS = 160;

    private const BODIES = 40;

    public function test_preparar_banco_http(): void
    {
        $this->assertSame('ultron_studio_h8_perf_http', DB::connection()->getDatabaseName(), 'solo en la base del banco HTTP (serve.sh seed)');
        $dir = (string) getenv('PERF_HTTP_DIR');
        $this->assertNotSame('', $dir, 'falta PERF_HTTP_DIR');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $this->mundoPerf();

        $sesiones = [];
        for ($i = 0; $i < 8; $i++) {
            $a = $i === 0 ? $this->persona(self::P_ANA) : $this->person(Admin::ROLE_SUPER_ADMIN, 'Perf HTTP '.$i);
            $sesiones[] = (string) app(AdminSessionService::class)->issueSession($a)['token'];
        }

        $parte = VoicePublishedConfig::read()['canary'];
        $this->assertTrue($parte->ok());
        $canario = (string) DB::table('ultron_studio_canary_numbers')->where('channel', 'voice')->where('status', 'active')->orderBy('id')->value('uuid');
        $llamadas = [];
        for ($i = 0; $i < self::CALLS; $i++) {
            $conv = 'conv_perf_http_'.$i.'_'.Str::lower(Str::random(5));
            $sid = 'CA'.md5('perf-http-'.$i.'-'.Str::random(8));
            $ref = (string) Str::uuid();
            $tel = self::CANARY_PHONES[$i % 3];
            DB::table('voice_calls')->insert([
                'uuid' => (string) Str::uuid(), 'call_ref' => $ref, 'direction' => 'inbound', 'status' => 'active', 'target_source' => null,
                'canary_number_uuid' => $canario, 'voice_contact_id' => null, 'remote_hash' => VoicePhone::hash($tel), 'remote_last4' => substr($tel, -4),
                'caller_authorized' => true, 'policy_reason' => null, 'attempt' => 1, ...$parte->stampColumns(),
                'provider_conversation_id' => $conv, 'provider_call_sid' => $sid, 'budget_day' => now()->setTimezone('America/Bogota')->toDateString(),
                'reserved_micros' => 610500, 'tool_calls' => 0, 'started_at' => now(), 'status_changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $llamadas[] = ['conv' => $conv, 'sid' => $sid, 'ref' => $ref];
        }

        // Las primeras 2 × BODIES llamadas reciben su transcripción (una cada una); el resto, herramientas.
        $plantilla = $this->plantilla();
        for ($i = 0; $i < self::BODIES; $i++) {
            file_put_contents("{$dir}/postcall-9k-{$i}.json", $this->transcripcion($plantilla, $llamadas[$i], (string) $parte->providerAgentId, 40, 0));
            file_put_contents("{$dir}/postcall-1m-{$i}.json", $this->transcripcion($plantilla, $llamadas[self::BODIES + $i], (string) $parte->providerAgentId, 1000, 1900));
        }
        $fx = json_decode((string) file_get_contents(base_path('tests/fixtures/voice/twilio-webhook-bodies.json')), true)['usageTrigger'][0]['params'];
        $uri = '/api/webhooks/voice/twilio/usage-trigger';
        for ($i = 0; $i < self::BODIES; $i++) {
            $pares = array_map(static fn (array $p): array => $p[0] === 'IdempotencyToken' ? [$p[0], 'AC8f-FIRES-HTTP-'.$i.'-'.Str::random(8)] : $p, $fx);
            $cuerpo = implode('&', array_map(static fn (array $p): string => rawurlencode($p[0]).'='.rawurlencode($p[1]), $pares));
            file_put_contents("{$dir}/twilio-{$i}.json", json_encode(['body' => $cuerpo, 'signature' => TwilioRequestSignature::compute(rtrim(self::WEBHOOK_BASE, '/').$uri, $pares, self::TWILIO_SIGNING)]));
        }

        file_put_contents("{$dir}/manifest.json", json_encode([
            'note' => 'Banco HTTP de PERF8. Credenciales SINTÉTICAS del mundo de la medida (PerfH8Seeding), base desechable ultron_studio_h8_perf_http.',
            'sessions' => $sesiones,
            'sealedAgent' => $parte->providerAgentId,
            'voiceFrom' => self::VOICE_FROM,
            'canaryPhone' => self::CANARY_PHONES[0],
            'initiationHeaderValue' => self::INIT_SECRET,
            'toolBearer' => self::TOOL_BEARER,
            'postCallSigning' => self::WEBHOOK_SIG,
            'toolCalls' => array_slice($llamadas, 2 * self::BODIES),
            'bodies' => self::BODIES,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        chmod("{$dir}/manifest.json", 0600);
    }

    /** @return array<string, mixed> el `pc_completed` del fixture de TEST8 */
    private function plantilla(): array
    {
        foreach (json_decode((string) file_get_contents(base_path('tests/fixtures/voice/elevenlabs-webhook-bodies.json')), true)['postCall'] as $caso) {
            if (($caso['bodyKey'] ?? null) === 'pc_completed') {
                return json_decode($caso['rawBody'], true);
            }
        }
        $this->fail('falta pc_completed');
    }

    /** La misma transcripción LIMPIA que el arnés en proceso (`PerfH8QueriesTest::cuerpoTranscripcion`). */
    private function transcripcion(array $plantilla, array $c, string $agente, int $turnos, int $largo): string
    {
        $base = $plantilla['data']['transcript'];
        $agenteFrases = ['La Sede Centro abre de lunes a viernes de 5:00 a 22:00 y los sábados de 7:00 a 14:00.', 'Con gusto te cuento cómo funcionan nuestros planes y qué incluye cada uno.',
            'Puedes entrenar en la zona de máquinas y en las clases grupales del horario de la sede.', 'Si quieres, te explico qué necesitas traer el primer día para empezar.'];
        $personaFrases = ['¿Y los fines de semana también abren?', 'Me interesa entrenar en la mañana.', 'Vale, gracias por la información.', '¿Qué clases grupales tienen?'];
        $t = [$base[0]];
        for ($k = 1; $k < $turnos; $k++) {
            if ($k % 2 === 1) {
                $t[] = ['role' => 'user', 'message' => $personaFrases[$k % 4], 'time_in_call_secs' => 5 * $k, 'tool_calls' => [], 'tool_results' => [], 'feedback' => null, 'conversation_turn_metrics' => null];

                continue;
            }
            $texto = $agenteFrases[$k % 4];
            while ($largo > 0 && strlen($texto) < $largo) {
                $texto .= ' '.$agenteFrases[(strlen($texto) + $k) % 4];
            }
            $t[] = ['role' => 'agent', 'message' => $texto, 'time_in_call_secs' => 5 * $k, 'tool_calls' => [], 'tool_results' => [], 'feedback' => null, 'conversation_turn_metrics' => null];
        }
        $cuerpo = $plantilla;
        $cuerpo['event_timestamp'] = now()->getTimestamp();
        $cuerpo['data']['agent_id'] = $agente;
        $cuerpo['data']['conversation_id'] = $c['conv'];
        $cuerpo['data']['user_id'] = $c['ref'];
        $cuerpo['data']['conversation_initiation_client_data']['dynamic_variables']['call_ref'] = $c['ref'];
        $cuerpo['data']['transcript'] = $t;
        $cuerpo['data']['metadata']['phone_call'] = ['call_sid' => $c['sid'], 'type' => 'twilio'];
        $cuerpo['data']['metadata']['call_duration_secs'] = 5 * $turnos;
        $json = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        while (strlen($json) > 1048576 && count($cuerpo['data']['transcript']) > 2) {
            array_pop($cuerpo['data']['transcript']);
            $json = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $json;
    }
}
