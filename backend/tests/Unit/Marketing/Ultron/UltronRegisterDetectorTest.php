<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Services\Marketing\Ultron\UltronRegisterDetector as D;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A4 · H4-9 · EL DETECTOR DEL TUTEO (plan del arquitecto `plan-arquitecto-a4-resto.md`
 * §2.2 (a), decisión T-2 del lead). Clase pura: sin base de datos, sin config,
 * sin reloj. Cada marca de la lista cerrada y cada exclusión llevan su caso;
 * los positivos de verdad son los tres textos de la prueba real del 18-11.
 */
class UltronRegisterDetectorTest extends TestCase
{
    /** El catálogo mínimo para quitar nombres de plan (como `activePlans()`: id y nombre). */
    private const PLANES = [
        ['id' => 3, 'name' => 'Plan Valera'],
        ['id' => 25, 'name' => 'PLAN DÍA'],
        ['id' => 90, 'name' => 'Tu Plan Fit'],
    ];

    // ── Los textos reales del 18-11 (dictamen §2 y §3) ──────────────────────

    public function test_los_tres_textos_de_la_prueba_real(): void
    {
        $this->assertSame([], D::tuteo('Buenas tardes, bienvenido a Iron Body Neiva, que gusto atenderle. Cuénteme, ¿qué le gustaría conocer hoy del gimnasio?'), 'turno 1: de usted');
        $this->assertSame(['tus'], D::tuteo('Claro, el Plan Valera tiene una mensualidad de $65.000 COP y ofrece acceso ilimitado al gimnasio, asesoría semi personalizada y seguimiento en tus ejercicios.', self::PLANES), 'turno 2: «tus ejercicios»');
        $this->assertSame(['te', 'quieres'], D::tuteo('Te entiendo, quieres saber el horario entre semana. El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m. ¿Quieres que te cuente también sobre las clases o los planes que tenemos?'), 'turno 3: entero de tú; cada marca una vez, en orden');
        $this->assertSame(['tus'], D::tuteo('Claro, el Plan Valera tiene una mensualidad de {{PLAN_PRICE}} y ofrece acceso ilimitado al gimnasio, asesoría semi personalizada y seguimiento en tus ejercicios. ¿Me cuenta si ya ha entrenado antes o es su primera vez?', self::PLANES), 'el borrador del turno 2: mezcla');
    }

    public function test_como_mucho_cinco_marcas(): void
    {
        $this->assertCount(5, D::tuteo('Tú tienes tu plan, tus ganas, te ayudo, cuéntame si quieres y puedes venir.'));
    }

    // ── Cada marca de la lista cerrada, con su caso ─────────────────────────

    /** @return array<string, array{string, string}> */
    public static function marcas(): array
    {
        return [
            // Pronombres.
            'tú' => ['tú', '¿Y tú qué opinas?'],
            'tu' => ['tu', 'Con gusto revisamos tu caso.'],
            'tus' => ['tus', 'Revisamos tus dudas.'],
            'te' => ['te', 'Claro, te cuento.'],
            'ti' => ['ti', 'Lo dejamos listo para ti.'],
            'contigo' => ['contigo', 'Hablamos contigo mañana.'],
            'tuyo' => ['tuyo', 'El plan es tuyo.'],
            'tuya' => ['tuya', 'La decisión es tuya.'],
            'tuyos' => ['tuyos', 'Los datos tuyos están bien.'],
            'tuyas' => ['tuyas', 'Las metas tuyas cuentan.'],
            // Imperativos con enclítico (con y sin tilde: la base de los prompts va sin tildes).
            'cuéntame' => ['cuéntame', 'Cuéntame qué buscas.'],
            'cuentame' => ['cuentame', 'Cuentame que buscas.'],
            'dime' => ['dime', 'Dime en qué ayudo.'],
            'dímelo' => ['dímelo', 'Si prefiere, dímelo.'],
            'dimelo' => ['dimelo', 'Si prefiere, dimelo.'],
            'escríbeme' => ['escríbeme', 'Escríbeme cuando pueda.'],
            'escribeme' => ['escribeme', 'Escribeme cuando pueda.'],
            'avísame' => ['avísame', 'Avísame si viene.'],
            'avisame' => ['avisame', 'Avisame si viene.'],
            'pregúntame' => ['pregúntame', 'Pregúntame por una clase.'],
            'preguntame' => ['preguntame', 'Preguntame por una clase.'],
            'cuéntanos' => ['cuéntanos', 'Cuéntanos qué necesita.'],
            'cuentanos' => ['cuentanos', 'Cuentanos que necesita.'],
            'escríbenos' => ['escríbenos', 'Escríbenos por aquí.'],
            'escribenos' => ['escribenos', 'Escribenos por aqui.'],
            'avísanos' => ['avísanos', 'Avísanos si viene.'],
            'avisanos' => ['avisanos', 'Avisanos si viene.'],
            'dinos' => ['dinos', 'Dinos qué día.'],
            // Verbos de tú.
            'quieres' => ['quieres', '¿Quieres saber más?'],
            'puedes' => ['puedes', 'Puedes venir hoy.'],
            'tienes' => ['tienes', '¿Ya tienes experiencia?'],
            'necesitas' => ['necesitas', 'Lo que necesitas está aquí.'],
            'buscas' => ['buscas', '¿Qué buscas lograr?'],
            'prefieres' => ['prefieres', '¿Prefieres la mañana?'],
            'vienes' => ['vienes', '¿Ya vienes entrenando?'],
            'estás' => ['estás', '¿Estás empezando?'],
            'sabes' => ['sabes', '¿Sabes dónde quedamos?'],
            'vas' => ['vas', '¿Vas a venir?'],
            'eres' => ['eres', 'Si eres principiante, mejor.'],
            'empiezas' => ['empiezas', 'Si empiezas hoy, mejor.'],
            'arrancas' => ['arrancas', '¿Arrancas desde cero?'],
            'entrenas' => ['entrenas', 'Donde entrenas con guía.'],
            'animas' => ['animas', 'Si te animas, aquí estamos.'],
            'alcanzaste' => ['alcanzaste', 'Si no alcanzaste a venir, no pasa nada.'],
            'viniste' => ['viniste', 'Cuando viniste estaba cerrado.'],
            'quisieras' => ['quisieras', 'Si quisieras, lo vemos.'],
            'podrías' => ['podrías', '¿Podrías venir el lunes?'],
            'podrias' => ['podrias', '¿Podrias venir el lunes?'],
            // Propuestas de `backend` (cada una sale hoy en un texto de Laravel o del modelo).
            'has' => ['has', '¿Ya has entrenado antes?'],
            'vengas' => ['vengas', 'Seas principiante o ya vengas entrenando.'],
            'seas' => ['seas', 'Sea cual sea el plan, seas quien seas.'],
            'puedas' => ['puedas', 'Algo que puedas sostener.'],
            'quieras' => ['quieras', 'Cuando quieras, aquí estamos.'],
            'sientas' => ['sientas', 'La idea es que sientas que lo aprovechas.'],
            'crees' => ['crees', '¿Cuántos días crees que podrá venir?'],
            'decides' => ['decides', 'Si decides empezar, avisamos.'],
            'ayudarte' => ['ayudarte', 'Puedo ayudarte con eso.'],
            'contarte' => ['contarte', 'Quiero contarte algo.'],
            'darte' => ['darte', 'Para no darte un dato equivocado.'],
            'orientarte' => ['orientarte', 'Para orientarte mejor.'],
            'responderte' => ['responderte', 'No quiero responderte cualquier cosa.'],
            'resolverte' => ['resolverte', 'Una duda que pueda resolverte.'],
            'recomendarte' => ['recomendarte', 'Para recomendarte el plan.'],
            // m-1 del revisor (G9): falsos negativos de tú sin «te/tu»; solo formas que no existen de usted ni en tercera persona.
            'anímate' => ['anímate', 'Anímate, el primer día es el más difícil.'],
            'animate' => ['animate', 'Animate, el primer dia es el mas dificil.'],
            'pásate' => ['pásate', 'Pásate por la sede cuando quieras.'],
            'pasate' => ['pasate', 'Pasate por la sede esta semana.'],
            'inscríbete' => ['inscríbete', 'Inscríbete en recepción con el documento.'],
            'inscribete' => ['inscribete', 'Inscribete en recepcion con el documento.'],
            'acércate' => ['acércate', 'Acércate a la recepción y lo vemos.'],
            'acercate' => ['acercate', 'Acercate a la recepcion y lo vemos.'],
            'estés' => ['estés', 'Cuando estés listo, empezamos.'],
            'tengas' => ['tengas', 'Ven cuando tengas un rato libre.'],
            'necesites' => ['necesites', 'Cuenta conmigo para lo que necesites.'],
            'llegas' => ['llegas', 'Cuando llegas, el entrenador te recibe.'],
            'entrenaste' => ['entrenaste', '¿Ya entrenaste alguna vez en un gimnasio?'],
        ];
    }

    #[DataProvider('marcas')]
    public function test_cada_marca_de_la_lista_cerrada(string $marca, string $texto): void
    {
        $this->assertContains($marca, D::tuteo($texto), $texto);
        $this->assertContains($marca, D::MARCAS, 'está en la lista cerrada');
    }

    public function test_la_lista_es_cerrada_y_sin_repetidos(): void
    {
        $this->assertSame(array_values(array_unique(D::MARCAS)), D::MARCAS);
        $this->assertCount(count(self::marcas()), D::MARCAS, 'cada marca tiene su caso');
    }

    // ── Lo que NUNCA marca ──────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function negativos(): array
    {
        return [
            'su y sus (tercera persona o usted)' => ['Su visita y sus dudas quedan anotadas.'],
            'usted' => ['Con gusto le atendemos a usted y a ustedes.'],
            'te dentro de otra palabra' => ['Este gimnasio es parte de la gente que hace deporte; el importe está aparte.'],
            'té con tilde' => ['Tenemos té y café en la recepción.'],
            'esta y estas sin tilde' => ['Esta semana y estas fechas están abiertas.'],
            'tu dentro de otra palabra' => ['El estatus de la actualización y la tutoría.'],
            'cita entre comillas latinas' => ['Usted escribió «quieres que te llame» y lo anotamos.'],
            'cita entre comillas rectas' => ['Usted escribió "te puedo llamar" y lo anotamos.'],
            'cita entre comillas inglesas' => ['Usted escribió “tú me dijiste” y lo anotamos.'],
            'marcador' => ['El precio es {{TU_PRECIO}} al mes.'],
            'url' => ['La app está en https://ironbody.example/tu/te?quieres=1 para descargarla.'],
            'url sin protocolo' => ['La app está en www.ironbody.example/tu-plan para descargarla.'],
            'de usted completo' => ['Hola, bienvenido a Iron Body, qué gusto atenderle. Cuénteme, ¿en qué le podemos ayudar hoy?'],
            'de usted con verbos' => ['Si quiere, pregúnteme por una clase; puede venir cuando le quede bien y le ayudo.'],
            'neutro de precios' => ['Claro. Planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP. ¿Sobre cuál amplío la información?'],
            // m-1: las que NO se añaden, porque coinciden con la tercera persona o con el usted.
            'ven (tercera persona)' => ['Ellos ven los resultados en un mes.'],
            'mira (tercera persona)' => ['El entrenador mira la técnica de cada socio.'],
            'aprovecha (tercera persona)' => ['El plan aprovecha la franja de la mañana.'],
            'recuerda (tercera persona)' => ['El equipo recuerda cada visita.'],
            'cuenta (tercera persona)' => ['La sede cuenta con duchas.'],
            'de usted con los verbos de m-1' => ['Anímese, pásese por la sede cuando esté listo; inscríbase en recepción cuando tenga un rato y acérquese si necesita algo. Cuando llega, ¿ya entrenó antes?'],
        ];
    }

    #[DataProvider('negativos')]
    public function test_lo_que_nunca_marca(string $texto): void
    {
        $this->assertSame([], D::tuteo($texto, self::PLANES), $texto);
    }

    public function test_un_nombre_de_plan_con_tu_no_cuenta(): void
    {
        $this->assertSame([], D::tuteo('El Tu Plan Fit cuesta lo mismo que el PLAN DÍA.', self::PLANES));
        $this->assertSame([], D::tuteo('El tu plan fit y el plan dia, sin tildes ni mayúsculas.', self::PLANES));
        $this->assertSame(['tu'], D::tuteo('El Tu Plan Fit es tu mejor opción.', self::PLANES), 'el nombre se quita, el tuteo de alrededor no');
        $this->assertSame(['tu'], D::tuteo('El Tu Plan Fit es tu mejor opción.'), 'sin catálogo no se sabe que es un plan');
    }

    public function test_la_cita_no_tapa_lo_que_va_fuera(): void
    {
        $this->assertSame(['te'], D::tuteo('Usted escribió «quieres» y te contesto.'));
    }

    // ── G6 · S-6 y m-2: las comillas no ciegan al detector ──────────────────
    //
    // Umbral: lo entrecomillado solo se descarta como «cita» si FUERA de las
    // comillas quedan MÁS palabras que dentro (la mayor parte del mensaje). Si
    // no, se mira el mensaje entero, comillas incluidas.

    /** @return array<string, array{string, list<string>}> */
    public static function comillasQueNoCiegan(): array
    {
        return [
            'el mensaje entero entre comillas rectas (S-6)' => ['"Hola, te cuento que tienes acceso y puedes venir cuando quieras. ¿Quieres?"', ['te', 'tienes', 'puedes', 'quieras', 'quieres']],
            'casi entero entre comillas (S-6)' => ['El "Plan Mensual te da acceso libre y tienes clases; el Valera" también.', ['te', 'tienes']],
            'el mensaje entero entre comillas latinas' => ['«Claro, te ayudo con eso cuando quieras.»', ['te', 'quieras']],
            'el mensaje entero entre comillas inglesas' => ['“Con gusto te cuento los planes.”', ['te']],
            'la frase de la casa como lema (m-2)' => ['Nuestro lema: «te acompañamos en cada paso».', ['te']],
            'mitad y mitad no es «la mayor parte»' => ['Sobre "¿tienes clases de yoga?": sí, los martes.', ['tienes']],
        ];
    }

    #[DataProvider('comillasQueNoCiegan')]
    public function test_si_lo_entrecomillado_es_la_mayor_parte_se_mira_entero(string $texto, array $marcas): void
    {
        $this->assertSame($marcas, D::tuteo($texto), $texto);
    }

    public function test_una_cita_corta_dentro_de_un_mensaje_de_usted_sigue_sin_contar(): void
    {
        $this->assertSame([], D::tuteo('Usted me escribió «¿tienes clases de yoga?» y con gusto le confirmo que sí, los martes y jueves a las 7 p. m.'));
        $this->assertSame([], D::tuteo('Como nos dijo, "quiero algo para mí solo"; para eso le recomiendo empezar con el plan de un mes y una rutina guiada.'));
    }

    public function test_una_comilla_sin_cerrar_no_se_come_el_resto(): void
    {
        $this->assertSame(['te', 'quieres'], D::tuteo('Usted escribió «hola y te cuento lo que quieres saber.'));
        $this->assertSame(['te'], D::tuteo('Usted escribió "hola" y luego " te cuento todo.'), 'la tercera comilla, sin pareja, no tapa lo que sigue');
    }

    public function test_mayusculas_y_tildes(): void
    {
        $this->assertSame(['tú', 'decides'], D::tuteo('TÚ DECIDES.'), 'minúsculas conservando la tilde');
        $this->assertSame(['estás'], D::tuteo('¿ESTÁS LISTO?'));
        $this->assertSame([], D::tuteo('ESTAS FECHAS'));
    }
}
