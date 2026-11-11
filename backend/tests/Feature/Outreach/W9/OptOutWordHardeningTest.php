<?php

namespace Tests\Feature\Outreach\W9;

use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use App\Services\Marketing\MarketingMessageDispatcher;
use App\Services\Meta\MetaConversationService;
use App\Services\Outreach\Facts\WhatsappSignals;
use App\Services\Outreach\Run\Channels\WhatsappTemplateChannel;
use App\Services\Outreach\Run\OutreachPublishedStrategies;
use App\Services\Outreach\Run\SubjectSuppressions;
use App\Services\Outreach\Run\WhatsappTemplateOutcomes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use IntlChar;
use Mockery;
use Normalizer;
use Tests\Feature\Outreach\W9\Support\W9World;
use Tests\TestCase;

/**
 * SEC · SC-2 de la revisión de seguridad del cierre: LA PALABRA DE BAJA dejaba
 * pasar bajas reales («No me vuelvan a escribir», «no me llamen», la tilde
 * combinada de NFD, el ancho completo, un espacio de anchura cero) y el
 * docblock decía lo contrario. Ahora:
 *
 *  - la forma NORMALIZADA es la de la guarda de la voz (el subconjunto latino
 *    de NFKC, sin marcas ni invisibles), sin `ext-intl` (producción no la
 *    tiene): aquí se compara con `Normalizer` donde la extensión sí está;
 *  - una baja SEGURA (la lista cerrada, el mensaje entero) sigue siendo
 *    `opted_out`;
 *  - una POSIBLE baja (contención conservadora) no se envía (`opt_out_word`,
 *    el código cerrado que ya existía) y una persona la revisa: el
 *    normalizador la deriva (`human_requested` con ese detalle) aunque la
 *    estrategia mande `replied` → `end_exit`, y la persona queda suprimida
 *    para la finalidad mientras tanto;
 *  - las frases normales de un cliente no se marcan.
 *
 * Cero red salvo el Graph falso de Meta; la constante de envío no se gira (el
 * doble de la puerta, solo aquí).
 */
class OptOutWordHardeningTest extends TestCase
{
    use RefreshDatabase;
    use W9World;

    private const NOW = '2026-11-17 15:00:00';

    /**
     * Las frases de SC-2 que NO contaban como baja (sonda de sec-cierre, §2 y
     * §8 #6): nueve naturales, la tilde COMBINADA (NFD, en sus dos ejemplos),
     * el ancho completo y el espacio de anchura cero. Las NFD se escriben a
     * mano: no dependen de `ext-intl`.
     */
    private const REPORT_PHRASES = [
        'Quiero que me den de baja', 'No me vuelvan a escribir', 'no me interesa, no me escriban más', 'Ya no quiero recibir mensajes',
        'no me llamen', 'dejen de escribirme', 'Sáquenme de su lista', 'eliminen mi número', 'Detener',
        "no ma\u{0301}s", "No me escriban ma\u{0301}s", 'ＢＡＪＡ', "ba\u{200B}ja",
    ];

    /** Las del informe que, ya normalizadas, son una baja SEGURA de la lista cerrada (el resto, posibles). */
    private const REPORT_SURE = ["no ma\u{0301}s", "No me escriban ma\u{0301}s", 'ＢＡＪＡ', "ba\u{200B}ja"];

    /** Lo que escribe un cliente de un gimnasio y NO es una baja (ni posible). */
    private const NORMAL_PHRASES = [
        'Hola, ¿a qué hora abren mañana?', '¿Cuánto cuesta la mensualidad?', 'Quiero renovar mi plan', 'Ya pagué, les envío el comprobante',
        'Me interesa, ¿me pueden llamar mañana?', 'No me interesa el plan anual', 'No me llegó el link de pago', 'Quiero bajar de peso',
        '¿Tienen clases de spinning?', 'Gracias, nos vemos el lunes', 'No puedo ir hoy, voy mañana', '¿Me pueden sacar una cita para valoración?',
        'Me gustaría congelar mi plan', '¿Me mandan el horario por favor?', 'No me mandaron nada al correo', 'Sí, por favor envíenme la información',
        'La bajada de precio me interesa', 'ok 👍', '¿Tienen parqueadero?', 'No más tarde de las 5 puedo ir', 'No me deja pagar con la tarjeta',
        '¿Qué pasa si no pago a tiempo?', 'Me llegó al spam el correo', '¿Y cuánto vale el plan trimestral?',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true]);
        $this->threePeople();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Un entrante de la persona por el camino de siempre (lo que guarda el webhook). */
    private function inbound(int $leadId, string $body, int $minutesLater = 1): MarketingMessage
    {
        Carbon::setTestNow(now()->addMinutes($minutesLater));
        $conversacion = MarketingConversation::query()->where('lead_id', $leadId)->where('channel', 'whatsapp')->firstOrFail();

        return app(MetaConversationService::class)->recordInbound($conversacion, 'wamid.IN.'.uniqid('', true), $body, ['message_type' => 'text']);
    }

    public function test_sc2_las_frases_del_informe_no_se_envian_y_no_llaman_a_la_puerta(): void
    {
        $p = $this->plannedWhatsappJob(self::utcAt(self::NOW));
        $e = DB::table('outreach_enrollments')->first();
        $live = (new OutreachPublishedStrategies)->liveVersion($p['live']['version'], 'canary');
        $puerta = Mockery::mock(MarketingMessageDispatcher::class);
        $puerta->shouldNotReceive('dispatchWhatsappTemplate');
        $canal = new WhatsappTemplateChannel(self::gateOpen(), gate: $puerta);

        foreach (self::REPORT_PHRASES as $frase) {
            $this->assertTrue(WhatsappSignals::isPossibleOptOutText($frase), 'posible baja: '.json_encode($frase, JSON_UNESCAPED_UNICODE));
            $this->assertSame(in_array($frase, self::REPORT_SURE, true), WhatsappSignals::isOptOutText($frase), 'baja segura solo la de la lista cerrada: '.json_encode($frase, JSON_UNESCAPED_UNICODE));
            $entrante = $this->inbound($p['member']['lead'], $frase);

            $intento = $canal->attempt($p['job'], $e, $live, now()->toDateTimeImmutable());

            $this->assertSame(['failed_to_start', 'opt_out_word'], [$intento->status, $intento->detailCode], json_encode($frase, JSON_UNESCAPED_UNICODE));
            $this->assertLessThanOrEqual(48, strlen((string) $intento->detailCode), 'un código cerrado que cabe en detail_code');
            $entrante->delete();
        }
        $this->assertSame(0, MarketingMessage::query()->where('direction', 'outbound')->count(), 'ningún saliente');
        Http::assertNothingSent();

        // Control: sin ninguna de esas frases, el MISMO canal con la puerta real sí envía (lo que paraba era la frase).
        $this->inbound($p['member']['lead'], 'Hola, ¿a qué hora abren mañana?');
        $ok = (new WhatsappTemplateChannel(self::gateOpen()))->attempt($p['job'], $e, $live, now()->toDateTimeImmutable());
        $this->assertSame('started', $ok->status);
        Http::assertSentCount(1);
    }

    public function test_sc2_que_es_la_baja_va_a_revision_humana_y_nunca_a_un_envio_silencioso(): void
    {
        // La estrategia manda `replied` → `end_exit`: una respuesta cualquiera cierra SIN que nadie la mire.
        $this->whatsappLive();
        $this->metaOn();
        $plantilla = $this->whatsappTemplate();
        $this->metaCheck($plantilla);
        $t = DB::table('ultron_studio_message_templates')->where('id', $plantilla)->first(['uuid', 'content_hash']);
        $this->whatsappStrategy($plantilla, ['sequence' => [[
            'id' => 'aviso', 'delay' => ['n' => 0, 'unit' => 'hours', 'from' => 'enrollment'], 'channels' => ['whatsapp_template'],
            'templates' => ['whatsapp_template' => ['uuid' => (string) $t->uuid, 'hash' => (string) $t->content_hash]], 'on' => ['replied' => 'end_exit'],
        ]]]);
        $pregunta = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $normal = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234568');
        $planner = $this->openPlanner();
        $planner->sweep(now(), true);
        $this->assertSame(2, $planner->planDue(now())['planned']);
        Carbon::setTestNow(now()->addMinutes(15));
        $this->assertSame(['started' => 2], $this->openDispatcher()->dispatch(now(), null, true)['results']);
        $this->inbound($pregunta['lead'], '¿qué es la baja?', 5);
        $this->inbound($normal['lead'], '¿Y cuánto vale el plan trimestral?', 0);

        $r = (new WhatsappTemplateOutcomes)->run(now());

        $this->assertSame(['human_requested' => 1, 'replied' => 1], $r['recorded']);
        $revision = DB::table('outreach_outcomes')->where('subject_key', $pregunta['key'])->sole();
        $this->assertSame(['human_requested', 'inbound_message', 'opt_out_word', 'handoff_human'], [$revision->outcome, $revision->source, $revision->detail_code, $revision->next_action],
            'la posible baja: una persona la revisa, diga lo que diga la estrategia de `replied`');
        $this->assertSame('handed_off', DB::table('outreach_enrollments')->where('subject_key', $pregunta['key'])->value('status'));
        $contraste = DB::table('outreach_outcomes')->where('subject_key', $normal['key'])->sole();
        $this->assertSame(['replied', 'end_exit'], [$contraste->outcome, $contraste->next_action], 'una respuesta normal sigue la estrategia (sin revisión)');
        $this->assertSame('exited', DB::table('outreach_enrollments')->where('subject_key', $normal['key'])->value('status'));
        $this->assertStringNotContainsStringIgnoringCase('baja', json_encode(DB::table('outreach_outcomes')->get()), 'el texto no viaja');

        // Mientras nadie la resuelva, ningún medio de esa finalidad le escribe ni la llama; otra finalidad, intacta.
        $supresion = new SubjectSuppressions;
        $this->assertTrue($supresion->isSuppressed($pregunta['key'], 'commercial', 'whatsapp'));
        $this->assertTrue($supresion->isSuppressed($pregunta['key'], 'commercial', 'voice'));
        $this->assertFalse($supresion->isSuppressed($pregunta['key'], 'service', 'whatsapp'));
        $this->assertFalse($supresion->isSuppressed($normal['key'], 'commercial', 'whatsapp'), 'una respuesta normal no suprime');

        // Y el canal tampoco le escribe aunque el libro no existiera: la posible baja está en su WhatsApp.
        $this->assertTrue((new WhatsappSignals)->everPossiblyOptedOut([$pregunta['lead']]));
        $this->assertFalse((new WhatsappSignals)->everPossiblyOptedOut([$normal['lead']]));
        Http::assertSentCount(2);
    }

    public function test_sc2_una_baja_segura_con_tilde_combinada_o_ancho_completo_sigue_siendo_opted_out(): void
    {
        $this->whatsappLive();
        $this->metaOn();
        $plantilla = $this->whatsappTemplate();
        $this->metaCheck($plantilla);
        $this->whatsappStrategy($plantilla);
        $nfd = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $ancho = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234568');
        $planner = $this->openPlanner();
        $planner->sweep(now(), true);
        $this->assertSame(2, $planner->planDue(now())['planned']);
        Carbon::setTestNow(now()->addMinutes(15));
        $this->assertSame(['started' => 2], $this->openDispatcher()->dispatch(now(), null, true)['results']);
        $this->inbound($nfd['lead'], "No me escriban ma\u{0301}s", 5);
        $this->inbound($ancho['lead'], 'ＢＡＪＡ', 0);

        $r = (new WhatsappTemplateOutcomes)->run(now());

        $this->assertSame(['opted_out' => 2], $r['recorded'], 'antes de SC-2 las dos eran `replied`');
        foreach ([$nfd['key'], $ancho['key']] as $clave) {
            $f = DB::table('outreach_outcomes')->where('subject_key', $clave)->sole();
            $this->assertSame(['opted_out', 'inbound_message', null], [$f->outcome, $f->source, $f->detail_code]);
            $this->assertSame('opted_out', DB::table('outreach_enrollments')->where('subject_key', $clave)->value('status'));
        }
    }

    public function test_sc2_las_frases_normales_de_cliente_no_se_marcan(): void
    {
        foreach (self::NORMAL_PHRASES as $frase) {
            $this->assertFalse(WhatsappSignals::isOptOutText($frase), "«{$frase}» no es una baja");
            $this->assertFalse(WhatsappSignals::isPossibleOptOutText($frase), "«{$frase}» no es una posible baja");
        }
        // Y sin texto no hay nada que revisar.
        foreach ([null, '', '   ', "\u{200B}", 'gracias', 'por favor', '👍'] as $vacio) {
            $this->assertFalse(WhatsappSignals::isPossibleOptOutText($vacio), var_export($vacio, true));
        }
    }

    public function test_sc2_lo_que_no_se_puede_leer_es_una_posible_baja_y_nunca_una_segura(): void
    {
        $this->assertTrue(WhatsappSignals::isPossibleOptOutText("\xB1\x31"), 'UTF-8 inválido: la duda cuenta como posible baja');
        $this->assertFalse(WhatsappSignals::isOptOutText("\xB1\x31"), 'pero no se afirma una baja SEGURA');
        $this->assertSame('', WhatsappSignals::normalize("\xB1\x31"));
    }

    public function test_sc2_las_formas_que_la_baja_segura_ya_reconoce(): void
    {
        foreach (['BAJA', ' Baja. ', 'Por favor, BAJA', "\u{FEFF}stop", "S\u{00AD}TOP", "no\u{200B}me\u{200B}escriban", 'B A J A', 'b.a.j.a',
            "\u{1D401}\u{1D400}\u{1D409}\u{1D400}", "\u{24B7}\u{24D0}\u{24D9}\u{24D0}", "UNSUBSCRIBE\u{3000}", "dar de baj\u{0061}\u{0301}"] as $si) {
            $this->assertTrue(WhatsappSignals::isOptOutText($si), 'baja segura: '.json_encode($si));
        }
        $this->assertSame('no me escriban mas', WhatsappSignals::normalize("  NO me ESCRIBAN ma\u{0301}s, gracias 🙏"));
    }

    /**
     * La forma normalizada sin `ext-intl` es la de NFKD (más quitar marcas y
     * pasar a minúsculas) en todo lo que mapea: el ancho completo, los
     * alfanuméricos matemáticos, las letras en círculo, las ligaduras y las
     * letras latinas con diacrítico. Donde NFKD no llega al ASCII (æ, ø, ł…)
     * la tabla propia va más allá o deja la letra; nunca la borra.
     */
    public function test_sc2_la_forma_normalizada_es_la_de_nfkd_donde_hay_intl(): void
    {
        if (! class_exists(Normalizer::class) || ! class_exists(IntlChar::class)) {
            $this->markTestSkipped('Sin ext-intl (como producción): la comparación con NFKD corre donde está.');
        }
        $rangos = [[0xFF01, 0xFF5E], [0x1D400, 0x1D6A3], [0x1D7CE, 0x1D7FF], [0x24B6, 0x24E9], [0xFB00, 0xFB06], [0x00C0, 0x00FF], [0x0100, 0x017F]];
        $comparados = 0;
        foreach ($rangos as [$desde, $hasta]) {
            for ($c = $desde; $c <= $hasta; $c++) {
                if (! IntlChar::isdefined($c) || in_array($c, [0x00D7, 0x00F7], true)) {
                    continue;
                }
                $caracter = (string) mb_chr($c, 'UTF-8');
                $nfkd = Normalizer::normalize($caracter, Normalizer::FORM_KD);
                $referencia = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string) preg_replace('/\p{M}/u', '', (string) $nfkd), 'UTF-8')));
                $propia = WhatsappSignals::normalize($caracter);
                if (preg_match('/^[a-z0-9 ]*$/', $referencia) === 1) {
                    $this->assertSame($referencia, $propia, sprintf('U+%04X', $c));
                    $comparados++;
                } else {
                    // NFKD no llega al ASCII (æ, ø, ł, ð…): la tabla propia la lleva a su base si la conoce; si no, la deja como letra. Nunca la borra.
                    $this->assertMatchesRegularExpression('/^\p{L}+$/u', $propia, sprintf('U+%04X', $c));
                }
            }
        }
        $this->assertGreaterThan(900, $comparados, 'la comparación ve los rangos');
    }
}
