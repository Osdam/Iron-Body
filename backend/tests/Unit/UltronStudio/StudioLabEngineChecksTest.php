<?php

namespace Tests\Unit\UltronStudio;

use App\Models\MarketingMessage;
use App\Services\UltronStudio\StudioLabEngineChecks as E;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * El adaptador de las guardas puras del motor (contrato H4 §A4, decisión 1).
 *
 * LO QUE SE PROTEGE. Que las firmas de las guardas sean las que el adaptador
 * espera (reflexión: si el motor cambia una, esto falla en CI); que con las
 * guardas REALES cada texto típico salga con su hallazgo y su nivel; que una
 * guarda que lanza, o que devuelve una forma desconocida, salga «no
 * disponible» sin tumbar a las demás; y que los niveles de estilo se decidan
 * por código (también los desconocidos, cerrados).
 */
class StudioLabEngineChecksTest extends TestCase
{
    private const SIN_HECHOS = ['paymentState' => 'none', 'handoffRequested' => false];

    /** @param  list<array<string,mixed>>  $hallazgos */
    private static function idsYNiveles(array $hallazgos): array
    {
        return array_map(static fn (array $h): string => "{$h['id']}:{$h['level']}", $hallazgos);
    }

    public function test_las_firmas_del_motor_son_las_que_espera_el_adaptador(): void
    {
        $this->assertSame([], E::signatureProblems(), 'Una guarda del motor cambió de firma: revisa StudioLabEngineChecks::SIGNATURES y el adaptador.');
        $this->assertSame('ai', MarketingMessage::SENDER_AI, 'el autor con el que se llama a inspect()');
        $this->assertSame(E::CALLS, array_keys(E::SIGNATURES));
        $this->assertSame(E::CALLS, array_keys(E::CALL_LABELS));
    }

    public function test_con_las_guardas_reales_cada_texto_tipico_sale_con_su_hallazgo(): void
    {
        $e = new E;
        $casos = [
            '¡Hola! ¿En qué te puedo ayudar?' => [],
            'La mensualidad vale $80.000' => ['motor-machine_reply_invented_price:blocking'],
            'Te hago un descuento del 20%' => ['motor-machine_reply_invented_discount:blocking'],
            'Entra a www.ejemplo.co y paga ahí' => ['motor-machine_reply_url:blocking'],
            'Pásame el número de tu tarjeta y el código de seguridad' => ['motor-machine_reply_card_data:blocking'],
            'Te paso con una asesora' => ['motor-machine_reply_unauthorized_handoff:blocking'],
            'Ya recibimos tu pago, gracias' => ['motor-pago:blocking'],
            'Mándame la captura del pago' => ['motor-pago:blocking'],
            'Tu plan vence el 15 de octubre' => ['motor-membresia:blocking'],
            'Te escribo mañana con la información' => ['motor-promesa:blocking'],
            'Listo, quedaste agendado para mañana a las 6' => ['motor-cortesia:blocking'],
            'Son los últimos cupos, decide ya' => ['motor-estilo-fake_urgency:blocking'],
            'Somos el mejor gimnasio de Neiva' => ['motor-estilo-unapproved_superiority_claim:warning'],
        ];
        foreach ($casos as $texto => $esperado) {
            $this->assertSame($esperado, self::idsYNiveles($e->check($texto, self::SIN_HECHOS)), $texto);
        }

        $h = $e->check('Ya recibimos tu pago, gracias', self::SIN_HECHOS)[0];
        $this->assertSame('engine', $h['source']);
        $this->assertStringStartsWith('El filtro real del motor marcaría este texto (', $h['title']);
        $this->assertStringNotContainsString('rechazaría', $h['title'].$h['detail']);
    }

    public function test_los_hechos_del_escenario_llegan_a_las_guardas(): void
    {
        $e = new E;
        // Con la petición corroborada, ofrecer el traspaso está autorizado.
        $this->assertSame([], $e->check('Te paso con una asesora', ['paymentState' => 'none', 'handoffRequested' => true]));
        // Con el pago aprobado, decir que llegó no contradice nada.
        $this->assertSame([], $e->check('Ya recibimos tu pago, gracias', ['paymentState' => 'approved', 'handoffRequested' => false]));
        // Sin hechos, los valores por defecto: sin pago y sin petición.
        $this->assertSame(['motor-pago:blocking'], self::idsYNiveles($e->check('Ya recibimos tu pago, gracias', [])));
    }

    public function test_una_guarda_que_lanza_sale_no_disponible_y_las_demas_siguen(): void
    {
        $e = new E([
            E::CALL_PAYMENT => static fn () => throw new RuntimeException('fallo con el texto del actor dentro: 3001234567'),
            E::CALL_STYLE => static fn () => throw new \TypeError('firma cambiada'),
        ]);
        $h = $e->check('La mensualidad vale $80.000', self::SIN_HECHOS);

        $this->assertSame(['motor-machine_reply_invented_price:blocking', 'motor-no-disponible:info'], self::idsYNiveles($h));
        $this->assertSame('No se pudo consultar: hechos de pago, estilo. No cuenta para el resultado.', $h[1]['detail']);
        $this->assertStringNotContainsString('3001234567', json_encode($h), 'el mensaje de la excepción no se guarda');
    }

    public function test_una_forma_desconocida_tambien_es_no_disponible(): void
    {
        $e = new E([
            E::CALL_INSPECT => static fn () => ['ok' => true],
            E::CALL_PROMISE => static fn () => 'promesa',
            E::CALL_COURTESY_CONFIRMATION => static fn () => ['frase'],
        ]);
        $h = $e->check('Hola', self::SIN_HECHOS);

        $this->assertSame(['motor-no-disponible:info'], self::idsYNiveles($h));
        $this->assertStringContainsString('contenido saliente, promesas, cortesía confirmada', $h[0]['detail']);
    }

    public function test_cortesia_una_sola_vez_aunque_casen_las_dos_y_aunque_una_lance(): void
    {
        $dos = new E([
            E::CALL_COURTESY_CONFIRMATION => static fn () => 'quedaste agendad',
            E::CALL_COURTESY_REGISTRATION => static fn () => 'queda registrada tu visita',
        ]);
        $h = $dos->check('x', self::SIN_HECHOS);
        $this->assertSame(['motor-cortesia:blocking'], self::idsYNiveles($h));
        $this->assertSame('quedaste agendad', $h[0]['quote'], 'cuenta la frase de la confirmación');

        $lanza = new E([
            E::CALL_COURTESY_CONFIRMATION => static fn () => throw new RuntimeException('x'),
            E::CALL_COURTESY_REGISTRATION => static fn () => 'ya quedaste registrado',
        ]);
        $this->assertSame(['motor-cortesia:blocking', 'motor-no-disponible:info'], self::idsYNiveles($lanza->check('x', self::SIN_HECHOS)));
    }

    public function test_los_niveles_de_estilo_se_deciden_por_codigo_y_los_desconocidos_cierran(): void
    {
        $e = new E([E::CALL_STYLE => static fn () => [
            'hard' => ['unapproved_superiority_claim', 'codigo_duro_nuevo', 'fake_urgency'],
            'soft' => ['bot_phrase', 'codigo_blando_nuevo', 'fake_urgency'],
            'plans_mentioned' => 0,
        ]]);

        $this->assertSame([
            'motor-estilo-unapproved_superiority_claim:warning',
            'motor-estilo-codigo_duro_nuevo:blocking',
            'motor-estilo-fake_urgency:blocking',
            'motor-estilo-bot_phrase:info',
            'motor-estilo-codigo_blando_nuevo:info',
        ], self::idsYNiveles($e->check('x', self::SIN_HECHOS)), 'los duros en su orden, luego los blandos, sin repetir');
    }

    public function test_un_codigo_de_inspect_que_no_existe_hoy_bloquea_con_su_codigo(): void
    {
        $e = new E([E::CALL_INSPECT => static fn () => ['safe' => false, 'code' => 'machine_reply_algo_nuevo', 'signal' => 'señal', 'risk_flag' => 'x']]);
        $h = $e->check('x', self::SIN_HECHOS);

        $this->assertSame(['motor-machine_reply_algo_nuevo:blocking'], self::idsYNiveles($h));
        $this->assertSame('El filtro real del motor marcaría este texto (código machine_reply_algo_nuevo)', $h[0]['title']);
        $this->assertSame('señal', $h[0]['quote']);
    }

    public function test_el_adaptador_no_toca_base_log_configuracion_reloj_ni_assert_safe(): void
    {
        $codigo = $this->codeWithoutComments(dirname(__DIR__, 3).'/app/Services/UltronStudio/StudioLabEngineChecks.php');
        foreach (['assertSafe', 'config(', 'now(', 'Log::', 'DB::', 'Cache::', 'Http::', 'Carbon', 'ChannelLog', '->save(', '::query('] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $codigo, "El adaptador usa {$prohibido}.");
        }
    }

    private function codeWithoutComments(string $ruta): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($ruta)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }
}
