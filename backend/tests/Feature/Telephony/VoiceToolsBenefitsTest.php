<?php

namespace Tests\Feature\Telephony;

use App\Models\Plan;
use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Telephony\VoiceTools;
use App\Services\Telephony\VoiceTranscriptGuard;
use App\Services\Voice\ElevenLabs\ElevenLabsEventVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use ReflectionClassConstant;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * LOS BENEFICIOS EN `consultar_planes` (Enmienda del 22-11-2026 de D8-10,
 * H-3 del informe `informe-be-voz.md`; directiva del propietario, §7,
 * «Beneficios»). Por la ruta pública, con el secreto de las herramientas, y
 * la guarda posterior por el webhook post-llamada FIRMADO: las clases reales,
 * sin red.
 *
 *  - LA FUENTE: el mismo campo y la misma lectura que el motor de WhatsApp
 *    (`MarketingKnowledgeBaseService::activePlans()`), más la limpieza del
 *    defecto H4-14 (comillas y corchetes sobrantes) que el motor no hace.
 *  - LA GUARDA EN LOS DOS SENTIDOS: lo que se entrega, leído tal cual en la
 *    llamada, no echa el freno; lo que se retiene, leído tal cual, sí lo
 *    habría echado.
 *  - LOS TOPES: por plan, por texto y por bytes (los 8 192 de `result_value`
 *    que la guarda lee para tapar los precios), repartidos por turnos.
 */
class VoiceToolsBenefitsTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const URI = '/api/webhooks/voice/elevenlabs/post-call';

    /** El primer turno con los tres elementos del aviso (`VoiceTranscriptGuard::NOTICE_ELEMENTS`). */
    private const NOTICE = 'Hola, te habla el asistente virtual de Iron Body, un sistema de inteligencia artificial. Si no quieres recibir más llamadas, dímelo.';

    /** La ficha REAL del Plan Valera (evidencia del canario, 18-11-2026, `04-mensajes-y-dictamen.txt`). */
    private const VALERA = ['Acceso ilimitado al gimnasio', 'Zona de pesas y maquinaria', 'Asesoría semi personalizada con entrenador de planta',
        'Seguimiento en ejecución de ejercicios', 'Valoración inicial', 'Acceso básico completo a la app'];

    /** Lo que la guarda vigila: cada uno, dicho tal cual, es una violación (y por eso no se entrega). */
    private const VIGILADOS = ['Primer mes gratis', '10% de descuento en suplementos', 'Visita www.ironbody.co', 'Incluye toalla ($ 5.000)',
        'Escríbenos a info@ironbody.co', 'Free WiFi and parking', 'Cortesía de bienvenida', 'Invitado sin costo una vez al mes', 'Llama al 3001234567'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->voiceWorld();
    }

    public function test_la_fuente_es_la_del_motor_de_whatsapp_y_la_voz_quita_las_comillas_sobrantes_de_h4_14(): void
    {
        $this->plan('Plan Valera', 65000, 1, json_encode(self::VALERA));
        // H4-14: la lista guardada como JSON pegado como texto (así está en producción el Plan Mensual).
        $this->plan('Plan Mensual', 80000, 2, json_encode(['["Acceso ilimitado al gimnasio"', '"Clases grupales"', '"Toalla"]']));
        // Texto suelto con saltos de línea y comas (la otra lectura de `benefitsArray()`).
        $this->plan('Plan Semana', 45000, 3, "Acceso ilimitado al gimnasio\nZona de cardio, Casillero");
        $this->plan('Plan Sin Ficha', 30000, 4, null);

        $delMotor = array_column(app(MarketingKnowledgeBaseService::class)->activePlans(), 'benefits', 'name');
        $deLaVoz = $this->benefitsByPlan($this->consultarPlanes());

        $this->assertSame($delMotor['Plan Valera'], $deLaVoz['Plan Valera'], 'un plan bien guardado: lo mismo que el motor');
        $this->assertSame($delMotor['Plan Semana'], $deLaVoz['Plan Semana'], 'la lectura de texto suelto: lo mismo que el motor');
        $this->assertSame(['["Acceso ilimitado al gimnasio"', '"Clases grupales"', '"Toalla"]'], $delMotor['Plan Mensual'], 'el motor NO limpia H4-14: su modelo lo recibe así');
        $this->assertSame(['Acceso ilimitado al gimnasio', 'Clases grupales', 'Toalla'], $deLaVoz['Plan Mensual'], 'la voz sí: sin corchetes ni comillas sobrantes');
        $this->assertSame([], $delMotor['Plan Sin Ficha']);
        $this->assertArrayNotHasKey('Plan Sin Ficha', $deLaVoz, 'sin beneficios, el plan no lleva la clave');
    }

    public function test_la_limpieza_de_un_texto_solo_quita_lo_sobrante_y_retiene_lo_invisible(): void
    {
        foreach ([
            '["Acceso ilimitado al gimnasio"' => 'Acceso ilimitado al gimnasio',
            '"Clases grupales"' => 'Clases grupales',
            '"Toalla"]' => 'Toalla',
            '"Sauna' => 'Sauna',
            'Casillero"' => 'Casillero',
            'Clase "Spinning" los sábados' => 'Clase "Spinning" los sábados',
            'Clase de "Spinning"' => 'Clase de "Spinning"',
            "Zona\tde pesas\r\ny  maquinaria" => 'Zona de pesas y maquinaria',
            '[]' => '',
        ] as $crudo => $esperado) {
            $this->assertSame($esperado, VoiceTools::benefitText($crudo), $crudo);
        }
        foreach (["Acceso\u{200B}total", "Sauna\u{202E}", "Toalla\x00", "Toalla\x1B[31m", "\u{FEFF}Sauna", "Zona\u{2028}VIP", "\xC3\x28 inválido"] as $crudo) {
            $this->assertNull(VoiceTools::benefitText($crudo), 'con caracteres de control o de formato, no se entrega: '.bin2hex($crudo));
        }
    }

    public function test_lo_que_vigila_la_guarda_no_se_entrega_se_cuenta_y_leido_tal_cual_habria_echado_el_freno(): void
    {
        $this->plan('Plan Valera', 65000, 1, json_encode([self::VALERA[0], ...self::VIGILADOS, "Acceso\u{200B} total", self::VALERA[1]]));
        $lineas = $this->captureVoiceLines();

        $cuerpo = $this->consultarPlanes();
        $this->assertSame([self::VALERA[0], self::VALERA[1]], $cuerpo['planes'][0]['beneficios'], 'solo los limpios, en su orden');
        foreach (self::VIGILADOS as $vigilado) {
            $this->assertStringNotContainsString($vigilado, json_encode($cuerpo, JSON_UNESCAPED_UNICODE), 'no viaja nada de lo retenido');
        }
        $this->assertSame(VoiceTools::BENEFITS_NOTE, $cuerpo['nota']);
        $retenidos = count(self::VIGILADOS) + 1;
        $evento = VoiceCallEvent::query()->where('kind', 'tool_call')->sole();
        $this->assertSame(['benefitsWithheld' => $retenidos, 'benefitsOmitted' => 0], $evento->codes, 'el recuento, en el evento de la herramienta');
        $this->assertSame([['benefits_withheld' => $retenidos, 'benefits_omitted' => 0]], array_map(
            static fn (array $c): array => array_intersect_key($c, ['benefits_withheld' => 1, 'benefits_omitted' => 1]), $lineas()), 'y en la línea voice.tool, solo números');

        // EL OTRO SENTIDO: cada uno de los retenidos, dicho tal cual por el agente, es una violación de la guarda posterior (y el freno).
        $turnos = [['role' => 'agent', 'message' => self::NOTICE]];
        foreach (self::VIGILADOS as $vigilado) {
            $turnos[] = ['role' => 'agent', 'message' => 'El plan incluye: '.$vigilado.'.'];
        }
        $this->assertGreaterThanOrEqual(count(self::VIGILADOS), $this->postCall('conv_h8fx_0031', $turnos)->assertOk()->json('violations'));
        $violaciones = VoiceCallEvent::query()->where('kind', 'guard_verdict')->sole()->codes['violations'];
        $this->assertSame(range(1, count(self::VIGILADOS)), array_values(array_unique(array_column($violaciones, 'turn'))), 'cada beneficio retenido, en su turno; el aviso, limpio');
        $this->assertSame('guard_violation', VoiceBrakeEntry::query()->sole()->reason);
    }

    public function test_leer_tal_cual_todo_lo_que_devolvio_no_echa_el_freno(): void
    {
        $this->plan('Plan Valera', 65000, 1, json_encode(self::VALERA));
        $this->plan('Plan Mensual', 80000, 2, json_encode(['["Acceso ilimitado al gimnasio"', '"Clases grupales"', '"Toalla"]']));
        $this->plan('Élite', 180000, 3, json_encode(['Clases grupales', 'Acceso ilimitado al gimnasio', 'Plan nutricional personalizado', 'Sauna', 'Entrenador personal 2 veces por semana', 'Congelamiento de 15 días']));
        $this->plan('PLAN DÍA', 10000, 4, json_encode(['Acceso por un día']), benefitOnly: true);
        $cuerpo = $this->consultarPlanes();
        $resultado = (string) json_encode(['ok' => true, 'verdict' => 'ok'] + $cuerpo);

        // El agente lee, plan a plan, TODO: el nombre, las condiciones, el precio y cada beneficio, tal cual.
        $turnos = [['role' => 'agent', 'message' => self::NOTICE], ['role' => 'user', 'message' => '¿Qué planes tienen y qué incluyen?'],
            ['role' => 'agent', 'message' => 'Déjame consultarlo.', 'tool_calls' => [['tool_name' => 'consultar_planes']],
                'tool_results' => [['tool_name' => 'consultar_planes', 'result_value' => $resultado, 'is_error' => false]]]];
        $leidos = 0;
        foreach ($cuerpo['planes'] as $p) {
            $turnos[] = ['role' => 'agent', 'message' => $p['nombre'].', '.$p['condiciones'].', por '.$p['precio'].'. Incluye: '.implode('; ', $p['beneficios'] ?? []).'.'];
            $leidos += count($p['beneficios'] ?? []);
        }
        $this->assertSame(16, $leidos, 'los 16 beneficios del catálogo de prueba se entregan');
        $this->postCall('conv_h8fx_0031', $turnos)->assertOk()->assertJson(['violations' => 0]);
        $this->assertSame(['violations' => []], VoiceCallEvent::query()->where('kind', 'guard_verdict')->sole()->codes);
        $this->assertSame(0, VoiceBrakeEntry::query()->count(), 'ningún freno falso');
    }

    public function test_los_topes_por_plan_por_texto_y_por_bytes_con_el_reparto_por_turnos(): void
    {
        // Diez beneficios limpios y uno de 91 caracteres: seis entregados, el largo no se recorta.
        $largo = 'Acceso a '.str_repeat('m', VoiceTools::MAX_BENEFIT_CHARS - 8);
        $this->assertSame(VoiceTools::MAX_BENEFIT_CHARS + 1, mb_strlen($largo));
        $diez = array_map(static fn (int $n): string => "Beneficio número {$n} del plan", range(1, 10));
        $this->plan('Plan Largo', 99000, 1, json_encode([$largo, ...$diez]));
        $cuerpo = $this->consultarPlanes();
        $this->assertSame(array_slice($diez, 0, VoiceTools::MAX_BENEFITS_PER_PLAN), $cuerpo['planes'][0]['beneficios']);
        $this->assertSame(['benefitsWithheld' => 0, 'benefitsOmitted' => 5], VoiceCallEvent::query()->where('kind', 'tool_call')->sole()->codes, 'el largo y los cuatro que sobran');
    }

    public function test_veinte_planes_en_el_peor_caso_caben_en_el_tope_y_todos_reciben_por_turnos(): void
    {
        $verificador = (new ReflectionClassConstant(ElevenLabsEventVerifier::class, 'MAX_TOOL_ARGUMENTS_BYTES'))->getValue();
        $this->assertLessThanOrEqual($verificador - 2048, VoiceTools::MAX_PLANS_BODY_BYTES, 'el tope deja 2 KB bajo el de result_value que lee la guarda');

        // 20 planes (el límite de la consulta) con seis beneficios de 90 caracteres cada uno y tildes (6 bytes cada una en json_encode).
        $texto = static fn (int $plan, int $n): string => mb_substr(str_repeat("Á{$plan}b{$n} acceso ", 20), 0, VoiceTools::MAX_BENEFIT_CHARS);
        for ($i = 1; $i <= 20; $i++) {
            $this->plan("Plan número {$i} con nombre largo de catálogo", 100000 + $i, $i, json_encode(array_map(static fn (int $n): string => $texto($i, $n), range(1, 6))));
        }
        $this->voiceCall('outbound', 'active', ['provider_conversation_id' => 'conv_h8fx_0031', 'provider_call_sid' => 'CA000000000000000000000000a800001f']);
        $r = $this->rawPost('/api/voice/tools/consultar_planes', '{"conversation_id":"conv_h8fx_0031"}', ['Authorization' => 'Bearer '.self::TOOL_SECRET])
            ->assertOk()->assertJson(['ok' => true, 'verdict' => 'ok']);
        $cuerpo = $r->json();
        $sinEnvoltorio = $cuerpo;
        unset($sinEnvoltorio['ok'], $sinEnvoltorio['verdict']);
        $this->assertLessThanOrEqual(VoiceTools::MAX_PLANS_BODY_BYTES, strlen((string) json_encode($sinEnvoltorio)), 'el cuerpo, con sus escapes, dentro del tope');
        $this->assertLessThanOrEqual(VoiceTools::MAX_PLANS_BODY_BYTES + 26, strlen((string) $r->getContent()), 'y la respuesta HTTP entera');
        $this->assertCount(20, $cuerpo['planes']);
        $conAlguno = count(array_filter($cuerpo['planes'], static fn (array $p): bool => ($p['beneficios'] ?? []) !== []));
        $entregados = array_sum(array_map(static fn (array $p): int => count($p['beneficios'] ?? []), $cuerpo['planes']));
        $this->assertSame(20, $conAlguno, 'por turnos, los 20 planes reciben su primer beneficio');
        // Por turnos: nadie recibe un segundo beneficio mientras otro plan que cabía se quedaba sin el primero.
        foreach ($cuerpo['planes'] as $p) {
            $this->assertLessThanOrEqual(intdiv($entregados, $conAlguno) + 1, count($p['beneficios'] ?? []), 'reparto por turnos');
            $this->assertSame(array_slice(array_map(static fn (int $n): string => $texto((int) filter_var($p['nombre'], FILTER_SANITIZE_NUMBER_INT), $n), range(1, 6)), 0, count($p['beneficios'] ?? [])),
                $p['beneficios'] ?? [], 'cada plan entrega el principio de su lista, en su orden');
        }
        $this->assertSame(['benefitsWithheld' => 0, 'benefitsOmitted' => 120 - $entregados], VoiceCallEvent::query()->where('kind', 'tool_call')->sole()->codes);
    }

    public function test_spoken_codes_es_la_misma_guarda_que_la_de_la_post_llamada(): void
    {
        foreach ([...self::VALERA, ...self::VIGILADOS, 'Te paso con un asesor', 'Asesoría con un asesor comercial', 'Bono de referido', 'Promoción especial',
            'No ofrecemos descuentos', '{{system__call_sid}}', 'Valoración gratis', 'Acceso 24 horas'] as $texto) {
            $deLaPostLlamada = array_column(array_filter(VoiceTranscriptGuard::inspect([
                ['index' => 0, 'text' => self::NOTICE, 'tools' => [], 'transfers' => [], 'planResults' => []],
                ['index' => 1, 'text' => $texto, 'tools' => [], 'transfers' => [], 'planResults' => []],
            ], [], false), static fn (array $v): bool => $v['turn'] === 1), 'code');
            $this->assertSame($deLaPostLlamada, VoiceTranscriptGuard::spokenCodes($texto), $texto);
        }
    }

    /** @return array<string, mixed> el cuerpo de `consultar_planes` en una saliente contestada, sin `ok` ni `verdict` */
    private function consultarPlanes(): array
    {
        if (! VoiceCall::query()->where('provider_conversation_id', 'conv_h8fx_0031')->exists()) {
            $this->voiceCall('outbound', 'active', ['provider_conversation_id' => 'conv_h8fx_0031', 'provider_call_sid' => 'CA000000000000000000000000a800001f']);
        }
        $r = $this->rawPost('/api/voice/tools/consultar_planes', '{"conversation_id":"conv_h8fx_0031"}', ['Authorization' => 'Bearer '.self::TOOL_SECRET])
            ->assertOk()->assertJson(['ok' => true, 'verdict' => 'ok'])->json();
        unset($r['ok'], $r['verdict']);

        return $r;
    }

    /** @param  array<string, mixed>  $cuerpo  @return array<string, list<string>> */
    private function benefitsByPlan(array $cuerpo): array
    {
        $out = [];
        foreach ($cuerpo['planes'] as $p) {
            if (array_key_exists('beneficios', $p)) {
                $out[$p['nombre']] = $p['beneficios'];
            }
        }

        return $out;
    }

    private function plan(string $nombre, int $precio, int $orden, ?string $beneficios, bool $benefitOnly = false): void
    {
        Plan::query()->create(['name' => $nombre, 'price' => $precio, 'duration_days' => $benefitOnly ? 1 : 30, 'active' => true, 'sellable' => true,
            'sort_order' => $orden, 'benefits' => $beneficios, 'benefit_only' => $benefitOnly]);
    }

    /**
     * Una post-llamada FIRMADA de la saliente, con su transcripción.
     *
     * @param  list<array<string, mixed>>  $turnos
     */
    private function postCall(string $conversacion, array $turnos): TestResponse
    {
        $cuerpo = (string) json_encode(['type' => 'post_call_transcription', 'event_timestamp' => now()->getTimestamp(), 'data' => [
            'agent_id' => self::SEALED_AGENT, 'conversation_id' => $conversacion, 'transcript' => $turnos, 'metadata' => ['call_duration_secs' => 60],
        ]], JSON_UNESCAPED_UNICODE);

        return $this->rawPost(self::URI, $cuerpo, ['ElevenLabs-Signature' => $this->elevenSignature($cuerpo)]);
    }

    /** @return \Closure(): list<array<string, mixed>> el contexto de cada línea `voice.tool` registrada desde ahora */
    private function captureVoiceLines(): \Closure
    {
        $lineas = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$lineas): void {
            if ($m->message === 'voice.tool') {
                $lineas[] = $m->context;
            }
        });

        return function () use (&$lineas): array {
            return $lineas;
        };
    }
}
