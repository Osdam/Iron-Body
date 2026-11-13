<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Services\Marketing\SalesConversationReplyService;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\UltronReplyFitter as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A4 · EL AJUSTADOR DE LONGITUD (plan del arquitecto §3.1 y §3.2, decisiones
 * del lead D-3, D-4 y D-5). Clase pura: sin base de datos, sin config, sin
 * reloj. Los textos son los REALES: los de `SalesConversationReplyService`
 * leídos por su API pública, y la ficha del horario de producción tal cual.
 */
class UltronReplyFitterTest extends TestCase
{
    /** La línea del horario de producción (253 caracteres, plan §3.1). */
    private const FICHA = 'Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases.';

    /** Lo que da hoy `scheduleReply()` con esa ficha (300 caracteres). */
    private const HORARIO = 'Claro. '.self::FICHA.' ¿En qué franja te queda mejor entrenar?';

    // ── D-3 · Qué se mide ───────────────────────────────────────────────────

    public function test_se_mide_sin_las_url_y_todo_lo_demas_cuenta(): void
    {
        $this->assertSame(253, mb_strlen(self::FICHA));
        $this->assertSame(300, F::measure(self::HORARIO));
        $this->assertSame(mb_strlen('Mira: '), F::measure('Mira: https://iron.body/app?x=1'));
        $this->assertSame(mb_strlen('Android:  · iPhone:  · Web: '), F::measure('Android: https://play.google.com/x · iPhone: https://apps.apple.com/y · Web: https://iron.body/z'));
        $this->assertSame(3, F::measure('ñáé'), 'caracteres, no bytes');
        $this->assertTrue(F::fits(str_repeat('a', 200), 200), 'tolerancia 0: 200 cabe en 200');
        $this->assertFalse(F::fits(str_repeat('a', 201), 200));
    }

    public function test_el_tope_firmado_se_valida(): void
    {
        $this->assertSame(200, F::cap(200));
        $this->assertSame(160, F::cap(160));
        $this->assertSame(480, F::cap(480));
        foreach ([159, 481, '200', 200.0, null, true, [], 0, -1] as $malo) {
            $this->assertNull(F::cap($malo), var_export($malo, true));
        }
    }

    // ── Partir en frases ────────────────────────────────────────────────────

    public function test_parte_en_frases_enteras_y_no_dentro_de_a_m(): void
    {
        $frases = array_map(fn (array $f) => $f[0], F::sentences(self::HORARIO));
        $this->assertSame([
            'Claro.',
            'Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m.',
            'Sabados de 8:00 a. m. a 2:00 p. m.',
            'Domingos de 8:00 a. m. a 2:00 p. m.',
            'Festivos de 8:00 a. m. a 2:00 p. m.',
            'Este es el horario de apertura del gimnasio, no el horario de las clases.',
            '¿En qué franja te queda mejor entrenar?',
        ], $frases);
    }

    /** Ajuste 1 del lead: el punto de una abreviatura de dirección o de tratamiento no termina la frase. */
    public function test_una_abreviatura_no_es_frontera_de_frase(): void
    {
        $frases = fn (string $t) => array_map(fn (array $f) => $f[0], F::sentences($t));

        $this->assertSame(['Estamos en la Av. La Toma # 10-20, barrio Altico.', '¿Le gustaria agendar una visita?'], $frases('Estamos en la Av. La Toma # 10-20, barrio Altico. ¿Le gustaria agendar una visita?'));
        $this->assertSame(['Cl. 24 Sur #33-53, Neiva, Huila.', '¿Vas a ir por primera vez?'], $frases('Cl. 24 Sur #33-53, Neiva, Huila. ¿Vas a ir por primera vez?'));
        foreach (['Av', 'av', 'Avda', 'Cra', 'Kra', 'Cr', 'Cl', 'Cll', 'Dg', 'Diag', 'Tv', 'Transv', 'Nro', 'Num', 'Núm', 'núm', 'Sr', 'Sra', 'Srta', 'Dr', 'DRA', 'Ing', 'Lic', 'Ed', 'Edif', 'Apto', 'Urb', 'Km', 'Tel', 'Cel', 'Ext', 'Int', 'Mz', 'Of', 'Loc'] as $abr) {
            $this->assertCount(2, F::sentences("Queda en la {$abr}. Molina del barrio. ¿Te sirve?"), $abr);
        }
        // «etc.» sí termina la frase, y «a. m.» / «p. m.» seguidos de mayúscula también (el horario depende de eso).
        $this->assertCount(2, F::sentences('Hay pesas, bancos, etc. ¿Te sirve?'));
        // «No» no está en la lista: la negación al final de frase sí termina la frase.
        $this->assertSame(['Creo que no.', 'Mañana te cuento.'], $frases('Creo que no. Mañana te cuento.'));
        $this->assertCount(2, F::sentences('Abrimos a las 5:00 a. m. Cerramos tarde.'));
        $this->assertCount(7, F::sentences(self::HORARIO), 'la ficha del horario sigue dando las mismas siete');
        // Y el recorte del borrador ya no puede dejar «Estamos en la Av.» como texto final.
        $r = F::draft('Estamos en la Av. La Toma # 10-20, barrio Altico. ¿Le gustaria agendar una visita?', 60);
        $this->assertSame('Estamos en la Av. La Toma # 10-20, barrio Altico.', $r['text']);
    }

    /** Ajuste 2 del lead: la ficha por piezas se LLENA en orden; la que no cabe se salta y se prueba la siguiente. */
    public function test_la_ficha_por_piezas_se_llena_en_orden(): void
    {
        $apertura = 'Con gusto te cuento.';
        $identidad = 'Iron Body Neiva es un centro de acondicionamiento físico en Neiva.';
        $ubicacion = 'Iron Body Neiva está ubicado en Cl. 24 Sur #33-53, Neiva, Huila.';
        $horario = 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases.';
        $pregunta = '¿Qué te cuento más a fondo: los planes, las clases o cómo empezar?';
        $this->assertSame(66, mb_strlen($identidad));
        $this->assertSame(224, mb_strlen($horario));

        $minimo = $apertura.' '.$identidad.' '.$pregunta;
        foreach ([200, 160] as $tope) {
            $this->assertSame($minimo, F::pieces([$apertura, $identidad, $ubicacion, $horario, $pregunta], $tope), (string) $tope);
        }
        // Con sitio para la ubicación pero no para el horario: se salta el horario, no se corta.
        $this->assertSame($apertura.' '.$identidad.' '.$ubicacion.' '.$pregunta, F::pieces([$apertura, $identidad, $ubicacion, $horario, $pregunta], 230));
        // Una pieza grande delante no impide que entren las de después (se salta y se prueba la siguiente).
        $this->assertSame($apertura.' '.$identidad.' '.$pregunta, F::pieces([$apertura, $horario, $identidad, $pregunta], 200));
        // Entero si cabe; null si ni la apertura con la pregunta caben.
        $todo = implode(' ', [$apertura, $identidad, $ubicacion, $horario, $pregunta]);
        $this->assertSame($todo, F::pieces([$apertura, $identidad, $ubicacion, $horario, $pregunta], 480));
        $this->assertNull(F::pieces([$apertura, $identidad, $pregunta], 80));
    }

    // ── D-5 · Regla general de los curados ─────────────────────────────────

    /** @return array<string, array{string, ?int, ?int}> intención → [texto de hoy] y longitudes esperadas a 200 y 160 (tabla §3.1) */
    public static function curados(): array
    {
        return [
            'payment_link_request' => [SalesIntents::PAYMENT_LINK_REQUEST, 185, 139],
            'high_intent_close' => [SalesIntents::HIGH_INTENT_CLOSE, 185, 139],
            'medical_risk_escalation' => [SalesIntents::MEDICAL_RISK_ESCALATION, 151, 151],
            'invoice_request' => [SalesIntents::INVOICE_REQUEST, 125, 125],
            'fraud_or_payment_claim' => [SalesIntents::FRAUD_OR_PAYMENT_CLAIM, 195, 132],
            'goal_recomposition' => [SalesIntents::GOAL_RECOMPOSITION, 183, 134],
            'insecurity_body' => [SalesIntents::INSECURITY_BODY, 162, 112],
            'general_info' => [SalesIntents::GENERAL_INFO, 87, 87],
        ];
    }

    #[DataProvider('curados')]
    public function test_la_regla_general_sobre_los_textos_de_hoy(string $intent, int $a200, int $a160): void
    {
        $texto = (string) (new SalesConversationReplyService)->replyFor($intent);
        $this->assertGreaterThan(160, F::measure($texto));

        foreach ([200 => $a200, 160 => $a160] as $tope => $esperado) {
            $ajustado = F::curated($texto, $tope);
            $this->assertNotNull($ajustado, "{$intent} a {$tope}");
            $this->assertSame($esperado, F::measure($ajustado), "{$intent} a {$tope}: {$ajustado}");
            // Nunca media frase: cada frase que sale estaba entera en el original.
            foreach (F::sentences($ajustado) as [$frase]) {
                $this->assertStringContainsString($frase, $texto);
            }
            // La primera frase siempre sale.
            $this->assertStringStartsWith(F::sentences($texto)[0][0], $ajustado);
        }
    }

    public function test_la_pregunta_final_se_suelta_despues_de_las_del_medio(): void
    {
        $texto = 'Primera frase corta. Segunda frase que ocupa bastante espacio en el mensaje para obligar a soltar. ¿La pregunta final?';
        $this->assertSame('Primera frase corta. ¿La pregunta final?', F::curated($texto, 45));
        $this->assertSame('Primera frase corta.', F::curated($texto, 25));
        $this->assertNull(F::curated($texto, 10), 'la primera frase nunca se parte: si no cabe, no hay ajuste');
    }

    public function test_un_texto_que_cabe_sale_igual(): void
    {
        $t = 'Hola. ¿Qué tal?';
        $this->assertSame($t, F::curated($t, 160));
    }

    // ── D-5 · El horario: los datos mandan sobre la pregunta ───────────────

    public function test_horario_entre_semana_a_200_salen_los_cuatro_grupos_sin_pregunta(): void
    {
        $r = F::schedule(self::HORARIO, 200, '¿Cuál es el horario de  atención entre semana?');
        $this->assertSame('Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m.', $r);
        $this->assertSame(186, F::measure($r));
    }

    public function test_horario_entre_semana_a_160_salen_laborables_sabados_y_domingos(): void
    {
        $r = F::schedule(self::HORARIO, 160, '¿Cuál es el horario de  atención entre semana?');
        $this->assertSame('Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m.', $r);
        $this->assertSame(150, F::measure($r));
    }

    public function test_horario_el_dia_preguntado_es_obligatorio(): void
    {
        // Con un tope en el que solo caben dos grupos, el preguntado se queda aunque sea el último.
        $r = F::schedule(self::HORARIO, 120, '¿y los festivos?');
        $this->assertNotNull($r);
        $this->assertStringContainsString('Festivos de 8:00 a. m. a 2:00 p. m.', $r);
        $this->assertLessThanOrEqual(120, F::measure($r));

        $sab = F::schedule(self::HORARIO, 160, '¿y los sábados?');
        $this->assertStringContainsString('Sabados de 8:00 a. m. a 2:00 p. m.', $sab);
        $this->assertLessThanOrEqual(160, F::measure($sab));

        // Sin día nombrado, el primero.
        $sin = F::schedule(self::HORARIO, 90, '¿qué horario tienen?');
        $this->assertSame('Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m.', $sin);
    }

    public function test_horario_sin_dias_reconocibles_aplica_la_regla_general(): void
    {
        $texto = 'Claro. Abrimos todo el año, mañana y tarde, con atención en recepción y entrenadores en sala durante toda la jornada de apertura del gimnasio. También en temporada. ¿En qué franja te queda mejor entrenar?';
        $this->assertSame(F::curated($texto, 160), F::schedule($texto, 160, 'horario?'));
        // El texto honesto de hoy (sin ficha) se queda en su primera frase (93, tabla §3.1).
        $honesto = (string) (new SalesConversationReplyService)->replyFor(SalesIntents::SCHEDULE_QUESTION);
        $this->assertSame(93, F::measure((string) F::schedule($honesto, 200, '¿cuál es el horario?')));
    }

    // ── D-4 · El borrador del modelo: se recorta y, si no se puede, se descarta ──

    public function test_el_borrador_se_recorta_desde_el_final(): void
    {
        $b = 'Primera frase del borrador. Segunda frase con más contenido útil. Tercera frase que sobra un poco. ¿Te cuento algo más?';
        $r = F::draft($b, 70);
        $this->assertSame(['text' => 'Primera frase del borrador. Segunda frase con más contenido útil.', 'dropped' => 2], $r);
        $this->assertSame(['text' => $b, 'dropped' => 0], F::draft($b, 200), 'si cabe, sale igual');
    }

    public function test_el_recorte_salta_la_frase_con_precio_y_la_que_lleva_url(): void
    {
        $b = 'Claro, te cuento. El Élite incluye acceso total, sesiones funcionales y seguimiento. Su valor es $180.000 COP al mes. ¿Te ayudo con algo más?';
        $r = F::draft($b, 70);
        $this->assertSame('Claro, te cuento. Su valor es $180.000 COP al mes.', $r['text']);
        $this->assertSame(2, $r['dropped']);

        $conEnlaces = "Te paso la app para que la descargues. Ahí ves los planes y pagas desde tu celular. ¿Te ayudo con algo más?\n\nAndroid: https://play.google.com/store/apps/details?id=x · iPhone: https://apps.apple.com/app/id1 · Web: https://iron.body/app";
        $r = F::draft($conEnlaces, 90);
        $this->assertSame("Te paso la app para que la descargues.\n\nAndroid: https://play.google.com/store/apps/details?id=x · iPhone: https://apps.apple.com/app/id1 · Web: https://iron.body/app", $r['text'], 'la línea de los enlaces se queda, con su salto');
        $this->assertSame(mb_strlen("Te paso la app para que la descargues.\n\nAndroid:  · iPhone:  · Web: "), F::measure($r['text']), 'el salto y las etiquetas cuentan; las URL no');
    }

    public function test_si_no_se_puede_recortar_se_descarta(): void
    {
        $this->assertNull(F::draft(str_repeat('Una sola frase muy larga ', 12).'.', 160), 'la primera frase no se suelta');
        $this->assertNull(F::draft('Corta. El precio es $80.000 COP y lo mantenemos así todo el año para todo el mundo que entrena con nosotros.', 60), 'la del precio tampoco');
    }

    // ── 13.ter y 13.quater con tope ─────────────────────────────────────────

    public function test_la_frase_del_precio_verificado_tiene_prioridad_y_la_de_la_visita_solo_si_cabe(): void
    {
        $cuerpo = 'Sí, el Plan Mensual incluye acceso completo al gimnasio. También tienes rutinas guiadas en la app. ¿Te ayudo con algo más?';
        $precio = 'El precio actual del Plan Mensual es $80.000 COP.';

        $this->assertSame($cuerpo.' '.$precio, F::append($cuerpo, $precio, 200, true), 'cabe entero');
        $r = F::append($cuerpo, $precio, 120, true);
        $this->assertSame('Sí, el Plan Mensual incluye acceso completo al gimnasio. '.$precio, $r);
        $this->assertSame($precio, F::append($cuerpo, $precio, 60, true), 'si ni así, sale ella sola');

        $visita = '¿Qué día te queda bien para la visita?';
        $this->assertNull(F::append($cuerpo, $visita, 140, false), 'la que retoma la visita solo si cabe');
        $this->assertSame($cuerpo.' '.$visita, F::append($cuerpo, $visita, 200, false));
    }

    // ── D-6 · El respaldo de precios, por segmentos ─────────────────────────

    public function test_respaldo_de_precios_suelta_en_el_orden_fijado(): void
    {
        $cab = 'Claro. Planes de un mes: ';
        $planes = ['Plan Valera, $65.000 COP', 'Plan Mensual, $80.000 COP', 'Élite, $180.000 COP'];
        $tambien = 'También hay planes por día, por semana y de varios meses.';
        $pregunta = '¿Sobre cuál amplío la información?';

        $entero = F::composePricing($cab, $planes, $tambien, $pregunta, F::pricingLayout($cab, $planes, $tambien, $pregunta, null));
        $this->assertSame('Claro. Planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?', $entero);
        $this->assertLessThanOrEqual(200, F::measure($entero));

        $esperados = [
            // tope => texto
            160 => 'Claro. Planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. ¿Sobre cuál amplío la información?',
            120 => 'Claro. Planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP. ¿Sobre cuál amplío la información?',
            80 => 'Claro. Planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP.',
            60 => 'Claro. Planes de un mes: Plan Valera, $65.000 COP.',
        ];
        foreach ($esperados as $tope => $texto) {
            $layout = F::pricingLayout($cab, $planes, $tambien, $pregunta, $tope);
            $this->assertNotNull($layout, (string) $tope);
            $this->assertSame($texto, F::composePricing($cab, $planes, $tambien, $pregunta, $layout), (string) $tope);
            $this->assertLessThanOrEqual($tope, F::measure($texto));
        }
        $this->assertNull(F::pricingLayout($cab, $planes, $tambien, $pregunta, 40), 'encabezado y primer plan son obligatorios');
    }
}
