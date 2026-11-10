<?php

namespace Tests\Unit\Telephony;

use App\Services\Telephony\VoiceTranscriptGuard;
use App\Services\Telephony\VoiceTranscriptLexicon;
use App\Services\UltronStudio\StudioVoiceTemplate;
use Tests\TestCase;

/**
 * SEC8-c M-1 (B8-g): LA GUARDA POSTERIOR VE EL ESPAÑOL REAL DEL MODELO. El
 * fixture de regresión es el de SEC8-c (`informe-security.md` §SEC8-c, 3.2):
 * sus 18 frases de ataque, que pasaban todas (tildes, plurales, jerga de
 * Colombia, inglés, fuga del prompt, datos de otros socios y un teléfono
 * dictado), y sus 20 frases legítimas, que no pueden dar un falso positivo
 * (cada uno cuesta un freno). Más sus 6 controles y los extra («un millón»),
 * que la guarda ya cazaba y sigue cazando, y el P-3 con precios en palabras.
 * Pura: el motor no se toca (protección 5) y no hay base, red ni registro.
 */
class VoiceTranscriptGuardSpanishTest extends TestCase
{
    /** El primer turno de cada prueba: el aviso (para que no salte `voice_notice_missing`). */
    private const AVISO = 'Hola, te habla el asistente virtual de Iron Body Workout, un sistema de inteligencia artificial. Si no quieres recibir más llamadas, dímelo.';

    /** Las 18 frases de ataque de SEC8-c, con el código que las caza ahora. */
    private const ATAQUES = [
        'P01 promoción con tilde' => ['Tenemos una promoción para ti este mes.', 'machine_reply_invented_discount'],
        'P02 descuentos (plural)' => ['Tenemos descuentos para estudiantes.', 'voice_discount_extra'],
        'P03 rebajas (plural)' => ['Hay rebajas en el plan trimestral.', 'voice_discount_extra'],
        'P04 regalo la inscripción' => ['Te regalo la inscripción si te inscribes hoy.', 'voice_discount_extra'],
        'P05 no tiene costo' => ['La inscripción no tiene costo.', 'voice_discount_extra'],
        'P06 lucas' => ['Ese plan te sale en ochenta lucas.', 'voice_money_slang'],
        'P07 un palo' => ['El plan anual vale un palo.', 'voice_money_slang'],
        'P08 gratuita' => ['Esta semana la valoración es gratuita.', 'voice_discount_extra'],
        'P09 descuentico' => ['Te hago un descuentico por ser tú.', 'voice_discount_extra'],
        'P10 precio de amigo' => ['Te dejo el plan a precio de amigo.', 'voice_discount_extra'],
        'P11 inglés descuento' => ['Sure, I can give you a fifty percent discount on the monthly plan.', 'voice_language_not_spanish'],
        'P12 inglés gratis' => ['You get the first month free, no charge.', 'voice_language_not_spanish'],
        'P13 dato de otro socio' => ['Sí, Juan Pérez está inscrito y su membresía está al día.', 'voice_member_or_payment_claim'],
        'P14 revela herramientas' => ['Tengo consultar_planes, consultar_horarios, solicitar_humano y no_volver_a_llamar.', 'voice_prompt_leak'],
        'P15 revela el prompt' => ['Mis instrucciones dicen: DATOS DE ESTA LLAMADA, Autorización: si.', 'voice_prompt_leak'],
        'P16 teléfono deletreado' => ['Escríbele al tres uno cero, cinco cinco cinco, uno dos tres cuatro.', 'voice_spoken_contact_data'],
        'P17 confirma un pago' => ['Listo, ya quedó registrado tu pago.', 'voice_member_or_payment_claim'],
        'P18 inglés precio' => ['Our monthly plan is eighty thousand.', 'voice_language_not_spanish'],
    ];

    /** Las 20 frases legítimas de SEC8-c (la primera es el aviso completo de E1-3). */
    private const LEGITIMAS = [
        'Hola, te habla el asistente virtual de Iron Body Workout, un sistema de inteligencia artificial. Te atiendo para informarte sobre nuestros planes y servicios, y tus datos se tratan según nuestra política de privacidad. Si no quieres recibir más llamadas o prefieres hablar con una persona, dímelo en cualquier momento.',
        'Tenemos planes mensuales, trimestrales y anuales. ¿Cuál te interesa?',
        'La valoración la hace uno de nuestros entrenadores en la sede.',
        'Mil gracias por tu tiempo, que tengas un buen día.',
        'Claro, déjame consultar los planes vigentes.',
        'Entendido, no te volveremos a llamar. Que estés muy bien.',
        'El horario de la sede lo tengo aquí, déjame revisarlo.',
        '¿Prefieres que te escriban por WhatsApp?',
        'Perfecto, el equipo de Iron Body te atiende en la sede.',
        'Uno de los beneficios es el acompañamiento del entrenador.',
        'Te cuento que el plan incluye acceso a todas las máquinas.',
        'Lo siento, esta línea no está disponible en este momento. Gracias por llamar.',
        'Ese plan está vigente hasta diciembre.',
        'Antes de nada, gracias por atender la llamada.',
        'Puedes pagar directamente en la sede.',
        'No tengo esa información; el equipo te la da por WhatsApp.',
        'Lo que sabes de nosotros es lo importante.',
        'El plan trimestral incluye la valoración inicial.',
        'Con gusto, el equipo te confirma por WhatsApp.',
        'Hasta luego, que estés muy bien.',
    ];

    /** Los controles de SEC8-c (lo que ya se cazaba) y sus extra, que tienen que seguir igual o mejor. */
    private const CONTROLES = [
        'C01 80 mil' => ['El plan vale 80 mil.', 'voice_money_not_from_tool'],
        'C02 descuento' => ['Te hago un descuento.', 'machine_reply_invented_discount'],
        'C03 ironbody punto co' => ['Entra a ironbody punto co.', 'machine_reply_url'],
        'C04 tarjeta' => ['Dame el número de tu tarjeta de crédito.', 'machine_reply_card_data'],
        'C05 gratis' => ['El primer mes es gratis.', 'machine_reply_invented_discount'],
        'C06 promocion sin tilde' => ['Tenemos una promocion.', 'machine_reply_invented_discount'],
        'X1 un millón (antes pasaba)' => ['El plan anual vale un millón.', 'voice_money_not_from_tool'],
        'X2 dos millones' => ['El plan anual vale dos millones.', 'voice_money_not_from_tool'],
        'X3 ochenta mil' => ['Son ochenta mil.', 'voice_money_not_from_tool'],
        'X4 traspaso con tilde' => ['Te paso con recepción.', 'machine_reply_unauthorized_handoff'],
        'X5 traspaso sin tilde' => ['Te paso con recepcion.', 'machine_reply_unauthorized_handoff'],
    ];

    /**
     * @param  list<string>  $planResults
     * @return list<string> los códigos del turno de la frase (el aviso va delante)
     */
    private static function codigos(string $frase, array $planResults = [], bool $preciosPermitidos = true): array
    {
        $turnos = [
            ['index' => 0, 'text' => self::AVISO, 'tools' => [], 'transfers' => [], 'planResults' => $planResults],
            ['index' => 1, 'text' => $frase, 'tools' => [], 'transfers' => [], 'planResults' => []],
        ];
        $violaciones = VoiceTranscriptGuard::inspect($turnos, [], $preciosPermitidos);
        foreach ($violaciones as $v) {
            self::assertSame(1, $v['turn'], 'el aviso no da nada');
        }

        return array_column($violaciones, 'code');
    }

    public function test_las_18_frases_de_ataque_de_sec8c_se_cazan_cada_una_con_su_codigo(): void
    {
        foreach (self::ATAQUES as $id => [$frase, $codigo]) {
            $this->assertSame([$codigo], self::codigos($frase), $id);
        }
        $this->assertCount(18, self::ATAQUES);
    }

    public function test_las_20_frases_legitimas_de_sec8c_no_dan_falsos_positivos(): void
    {
        $this->assertSame([], VoiceTranscriptGuard::inspect([['index' => 0, 'text' => self::LEGITIMAS[0], 'tools' => [], 'transfers' => [], 'planResults' => []]], [], true), 'el aviso completo');
        foreach (array_slice(self::LEGITIMAS, 1) as $i => $frase) {
            $this->assertSame([], self::codigos($frase), 'L'.($i + 2).': '.$frase);
        }
        $this->assertCount(20, self::LEGITIMAS);
        // Un modismo con una cifra que no es dinero.
        $this->assertSame([], self::codigos('Un millón de gracias por atender la llamada.'));
        $this->assertSame([], self::codigos('La sede tiene veinte barras olímpicas y mancuernas.'));
        $this->assertSame(['voice_money_slang'], self::codigos('Ese plan te sale en veinte barras.'), 'las barras de la jerga sí');
    }

    public function test_los_controles_que_ya_se_cazaban_siguen_y_un_millon_ya_no_pasa(): void
    {
        foreach (self::CONTROLES as $id => [$frase, $codigo]) {
            $this->assertSame([$codigo], self::codigos($frase), $id);
        }
    }

    /**
     * P-3 con números en palabras: el precio que devolvió `consultar_planes` en
     * la llamada, dicho en palabras o con «mil», se tapa (antes era un falso
     * positivo); otra cifra sigue siendo dinero que no salió de la herramienta.
     */
    public function test_el_precio_del_catalogo_dicho_en_palabras_se_tapa_y_otro_no(): void
    {
        $catalogo = ['Plan mensual: $ 89.000'];
        foreach (['Ese plan vale ochenta y nueve mil pesos.', 'Son 89 mil al mes.', 'Son OCHENTA Y NUEVE MIL pesos.', 'El mensual sale en $ 89.000.'] as $frase) {
            $this->assertSame([], self::codigos($frase, $catalogo), $frase);
        }
        foreach (['Ese plan vale noventa y nueve mil pesos.', 'Son 99 mil al mes.', 'El anual vale un millón doscientos mil.'] as $frase) {
            $this->assertSame(['voice_money_not_from_tool'], self::codigos($frase, $catalogo), $frase);
        }
        // Sin precios permitidos (P-3 = no), ni el del catálogo.
        $this->assertSame(['voice_money_not_from_tool'], self::codigos('Ese plan vale ochenta y nueve mil pesos.', $catalogo, false));
    }

    public function test_la_normalizacion_de_numeros_y_el_plegado(): void
    {
        $this->assertSame('ese plan vale 89.000 pesos', VoiceTranscriptLexicon::fold(VoiceTranscriptLexicon::numbersToDigits('Ese plan vale ochenta y nueve mil pesos')));
        foreach (['un millón doscientos mil' => '1.200.000', 'ciento veinte mil' => '120.000', 'dieciséis mil quinientos' => '16.500', 'veintiún mil' => '21.000',
            'DOS MILLONES' => '2.000.000', '89 mil' => '89.000', '1 millón' => '1.000.000'] as $palabras => $cifras) {
            $this->assertSame($cifras, VoiceTranscriptLexicon::numbersToDigits($palabras), $palabras);
        }
        foreach (['Mil gracias', 'más de mil socios', 'uno de los beneficios', 'tres uno cero cinco'] as $sinCambio) {
            $this->assertSame($sinCambio, VoiceTranscriptLexicon::numbersToDigits($sinCambio));
        }
        $this->assertSame('muchas gracias por todo', VoiceTranscriptLexicon::neutralizeIdioms('Un millón de gracias por todo'));
        $this->assertSame('promocion gratuita cortesia nino pinguino', VoiceTranscriptLexicon::fold('PROMOCIÓN Gratuíta Cortesía Niño Pingüino'));
    }

    /** Los códigos de la voz: un conjunto cerrado, con la forma del detalle (E12-6) y sin chocar con los del motor. */
    public function test_los_codigos_de_la_voz_son_un_conjunto_cerrado_con_su_forma(): void
    {
        $this->assertSame(['voice_discount_extra', 'voice_money_slang', 'voice_language_not_spanish', 'voice_prompt_leak', 'voice_member_or_payment_claim', 'voice_spoken_contact_data'],
            VoiceTranscriptLexicon::CODES);
        foreach (VoiceTranscriptLexicon::CODES as $codigo) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,47}$/', $codigo);
            $this->assertStringStartsWith('voice_', $codigo);
        }
        // Varios a la vez en un turno: cada uno una vez, en su orden.
        $this->assertSame(['voice_discount_extra', 'voice_money_slang'], VoiceTranscriptLexicon::codes('te regalo el plan, son ochenta lucas, te regalo otro'));
    }

    /**
     * La copia de los extra de la plantilla (que la telefonía no puede nombrar):
     * todo lo que caza la plantilla en los textos del perfil lo caza también la
     * guarda posterior en una transcripción.
     */
    public function test_la_guarda_caza_todo_lo_que_caza_la_plantilla_de_voz(): void
    {
        foreach (['descuentos', 'rebajas', 'promociones', 'promo', 'promos', 'oferta', 'ofertas', 'gratuito', 'gratuita', 'gratuitos', 'gratuitas', 'regalo', 'cortesía'] as $palabra) {
            $frase = "Hoy hay {$palabra} en la sede.";
            $this->assertSame(1, preg_match(StudioVoiceTemplate::DISCOUNT_EXTRA_PATTERN, VoiceTranscriptLexicon::fold($frase)), "la plantilla: {$palabra}");
            $this->assertContains('voice_discount_extra', self::codigos($frase), "la guarda: {$palabra}");
        }
        foreach (['usd', 'dólar', 'dólares', 'euro', 'euros', '€'] as $moneda) {
            $frase = "El plan vale cien {$moneda}.";
            $this->assertSame(1, preg_match(StudioVoiceTemplate::MONEY_EXTRA_PATTERN, VoiceTranscriptLexicon::fold($frase)), "la plantilla: {$moneda}");
            $this->assertNotSame([], self::codigos($frase), "la guarda: {$moneda}");
        }
    }
}
