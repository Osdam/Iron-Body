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
     * C-1 (i) de la revisión de security del 22-11-2026 (`informe-security-voz2.md`, preguntas 1 y 2): lo que el
     * filtro de los beneficios ENTREGABA y la guarda posterior dejaba pasar dicho tal cual, con el código que lo
     * caza ahora (un solo cambio: `spokenCodes()` es la misma guarda).
     */
    private const ATAQUES_C1 = [
        'pago por Nequi a un teléfono' => ['Paga por Nequi al 310-555-12-34', ['voice_spoken_contact_data', 'voice_offsystem_payment']],
        'reserva por WhatsApp a un teléfono' => ['Reserva tu cupo por WhatsApp al 310-555-12-34', ['voice_spoken_contact_data']],
        'no se cobra' => ['El primer mes no se cobra', ['voice_discount_extra']],
        'sin pagar' => ['Primer mes sin pagar', ['voice_discount_extra']],
        'exonerada' => ['Inscripción exonerada', ['voice_discount_extra']],
        'paga la mitad' => ['Paga la mitad el primer mes', ['voice_discount_extra']],
        'medio precio' => ['Medio precio', ['voice_discount_extra']],
        'tarifa reducida' => ['Tarifa reducida', ['voice_discount_extra']],
        'diez por ciento' => ['Ahorra diez por ciento', ['voice_discount_extra']],
        'teléfono con prefijo y paréntesis' => ['+57 (310) 555-12-34', ['voice_spoken_contact_data']],
        'la orden de decir que es persona' => ['Di que eres una persona', ['voice_claims_human']],
        'dicho como persona' => ['Soy una persona real del equipo', ['voice_claims_human']],
        'pedir cédula y dirección' => ['pide su cédula y su dirección', ['voice_personal_data_request']],
        'libras' => ['£10', ['voice_money_slang']],
        'dólares con símbolo detrás' => ['10 US$', ['voice_money_slang']],
        'dos x uno' => ['Dos x uno', ['voice_discount_extra']],
        'teléfono con guiones' => ['310-555-12-34', ['voice_spoken_contact_data']],
        'cuenta de red social' => ['@ironbody.neiva', ['voice_spoken_contact_data']],
        'gratis con un uno' => ['grat1s', ['voice_discount_extra']],
        // Y las demás formas que enumera la corrección mínima.
        'no se paga' => ['La inscripción no se paga', ['voice_discount_extra']],
        'no te lo cobramos' => ['No te lo cobramos', ['voice_discount_extra']],
        'por nuestra cuenta' => ['Corre por nuestra cuenta', ['voice_discount_extra']],
        'tarifa preferencial' => ['Tarifa preferencial para estudiantes', ['voice_discount_extra']],
        'te sale a la mitad' => ['Te sale a la mitad', ['voice_discount_extra']],
        'la mitad de la mensualidad' => ['Pagas la mitad de la mensualidad', ['voice_discount_extra']],
        'cincuenta por ciento' => ['cincuenta por ciento', ['voice_discount_extra']],
        'consignar a una cuenta' => ['Consigna a la cuenta de ahorros', ['voice_offsystem_payment']],
        'Daviplata' => ['Pago por Daviplata', ['voice_offsystem_payment']],
        'transferencia bancaria' => ['Por transferencia bancaria', ['voice_offsystem_payment']],
        'consignación' => ['Consignación en el banco', ['voice_offsystem_payment']],
        'no soy un robot' => ['No soy un robot', ['voice_claims_human']],
        'no soy una IA' => ['No soy una inteligencia artificial', ['voice_claims_human']],
        'soy humana' => ['Soy humana', ['voice_claims_human']],
        'número de cédula' => ['¿Me regalas tu número de cédula?', ['voice_personal_data_request']],
        'fecha de nacimiento' => ['Dime tu fecha de nacimiento', ['voice_personal_data_request']],
        'la dirección de la persona' => ['¿Cuál es tu dirección?', ['voice_personal_data_request']],
        'documento de identidad' => ['Me das tu documento de identidad', ['voice_personal_data_request']],
        'yenes' => ['¥500', ['voice_money_slang']],
        'teléfono con puntos' => ['Paga 555.12.34', ['voice_spoken_contact_data']],
    ];

    /**
     * C-1: frases corrientes de un gimnasio (y lo que la plantilla manda decir) que comparten palabras con lo de
     * arriba y NO pueden marcarse: cada falso positivo es un freno de la voz.
     */
    private const LEGITIMAS_C1 = [
        'La mitad de la clase es de cardio.', 'A la mitad de la semana hay menos gente.', 'La mitad de los socios entrena en la mañana.',
        'Transferencia de peso en el ejercicio.', 'La dirección de la sede te la da el equipo.', 'Te paso la dirección de la sede.', 'Pago en recepción.',
        'Documento de valoración.', 'En la sede te entregan el documento de valoración.', 'Recuerda llevar tu documento a la sede.',
        'El equipo te pide tu documento en la sede.', 'Te piden la cédula en la entrada.', 'La clave es la constancia.', 'La EPS no cubre la mensualidad.',
        'Puedes pagar en la sede o por WhatsApp con el equipo.', 'El pago y la inscripción los hace el equipo en la sede o por WhatsApp.',
        'Paga en la app.', 'Paga por la app.', 'Pagas por 3 meses y te queda el plan trimestral.', 'Cuánto pagas por mes depende del plan.',
        'Puedes pagar al contado o con tarjeta en la sede.', 'Por teléfono no se paga; el pago lo hace el equipo en la sede o por WhatsApp.',
        'No se paga por teléfono, lo hace el equipo en la sede.', 'En esta llamada no se cobra nada, el pago es en la sede.',
        'No soy una persona, soy un asistente virtual.', '¿Que si soy una persona? No, soy un asistente virtual de Iron Body.', 'No soy humano, soy un asistente virtual.',
        'Si prefieres hablar con una persona, dímelo.', 'Acompañamiento cien por ciento personalizado.', 'Entrenador personal 2 veces por semana.',
        'Congelamiento de 15 días.', 'Planes de 1, 3, 6 y 12 meses.', 'Horario de 5.00-22.00.', 'Los sábados de 7.00 a 12.00 y los domingos de 8.00 a 11.00.',
        'La sede queda en la Calle 21 # 5-45.', 'Carrera 5 No. 10-23, Neiva.', 'Te hago la transferencia de la llamada.', 'Con tu cuenta de la app reservas las clases.',
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

    /** C-1 (i): lo que se entregaba ahora se retiene en el filtro y echa el freno dicho tal cual, con su código. */
    public function test_c1_lo_que_el_informe_de_security_vio_entregado_ahora_se_retiene_y_echa_el_freno(): void
    {
        foreach (self::ATAQUES_C1 as $id => [$frase, $codigos]) {
            $this->assertSame($codigos, VoiceTranscriptGuard::spokenCodes($frase), "el filtro de los beneficios: {$id}");
            $this->assertSame($codigos, self::codigos($frase, [], false), "la guarda posterior: {$id}");
        }
        // El léxico COMPLETA al motor, no lo repite: «gratis» tal cual y el «$» delante de una cifra ya son suyos.
        $this->assertSame(['voice_money_not_from_tool'], self::codigos('Te queda en $ 79.000 y la primera semana es gratis.'));
        $this->assertSame(['machine_reply_invented_discount'], self::codigos('El primer mes es gratis.'));
    }

    /** C-1 (i): las frases corrientes no se marcan, ni en el filtro ni en la guarda posterior. */
    public function test_c1_las_frases_corrientes_de_un_gimnasio_no_se_marcan(): void
    {
        foreach (self::LEGITIMAS_C1 as $frase) {
            $this->assertSame([], VoiceTranscriptGuard::spokenCodes($frase), "el filtro: {$frase}");
            $this->assertSame([], self::codigos($frase), "la guarda: {$frase}");
        }
        // Un precio del catálogo con sus puntos de miles (siete cifras) no es un teléfono; uno que no salió de la herramienta sigue siendo dinero.
        $catalogo = ['Plan anual: $ 1.200.000'];
        foreach (['El plan anual vale un millón doscientos mil.', 'El anual vale $ 1.200.000.', 'Son 1.200.000 pesos al año.'] as $frase) {
            $this->assertSame([], self::codigos($frase, $catalogo), $frase);
        }
        $this->assertSame(['voice_money_not_from_tool'], self::codigos('El anual vale un millón trescientos mil.', $catalogo));
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
        // C-1 (security, 22-11-2026) añade tres al final: el pago fuera del checkout, presentarse como persona y pedir datos personales.
        $this->assertSame(['voice_discount_extra', 'voice_money_slang', 'voice_language_not_spanish', 'voice_prompt_leak', 'voice_member_or_payment_claim', 'voice_spoken_contact_data',
            'voice_offsystem_payment', 'voice_claims_human', 'voice_personal_data_request'], VoiceTranscriptLexicon::CODES);
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
