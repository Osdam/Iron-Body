<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\HumanHandoffAuthority;
use App\Services\Marketing\Ultron\ConversationMemory;
use App\Services\Marketing\Ultron\NoveltyGuard;
use App\Services\Marketing\Ultron\ReferenceResolver as R;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 400 EXPRESIONES REALES CONTRA LAS CAPAS DETERMINISTAS.
 *
 * El cerebro de ULTRON es un modelo y vive en n8n: esto no lo evalúa ni puede.
 * Lo que sí evalúa es todo lo que Laravel decide POR SÍ MISMO leyendo el
 * mensaje de la persona —a qué plan se refiere, si pidió hablar con alguien, si
 * pidió que le repitan algo— y que hoy se decide con expresiones regulares.
 *
 * Esa es exactamente la capa que la directiva obliga a generalizar: una regex
 * que acierta en las doce frases de su prueba y falla en la trece no generaliza,
 * generaliza la prueba. 400 frases escritas como escribe la gente —sin tildes,
 * con «xfa», con «q», partidas en tres mensajes— son la diferencia entre creer
 * que generaliza y saber en qué no.
 *
 * Lo que se afirma aquí son INVARIANTES, no salidas exactas. Ninguna de estas
 * pruebas dice «esta frase significa esto»: dicen «pase lo que pase, esto no
 * puede ocurrir». Las salidas exactas son cosa de ReferenceResolverTest, con
 * sus casos elegidos; aquí se busca el fallo que no se eligió.
 *
 * El dataset es de EVALUACIÓN. No es un seeder, no toca producción y no
 * contiene datos de ninguna persona.
 *
 * @see tests/fixtures/expresiones_ultron.php
 */
class ExpresionesTortureTest extends TestCase
{
    private const PLANES = [
        ['id' => 20, 'name' => 'Plan Mensual', 'benefits' => []],
        ['id' => 21, 'name' => 'Plan Trimestral', 'benefits' => []],
        ['id' => 23, 'name' => 'Plan Semana', 'benefits' => []],
    ];

    private R $resolver;

    private HumanHandoffAuthority $handoff;

    private NoveltyGuard $novedad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new R;
        $this->handoff = new HumanHandoffAuthority;
        $this->novedad = new NoveltyGuard;
    }

    /** @return array<string,list<string>> */
    private static function dataset(): array
    {
        /** @var array<string,list<string>> $d */
        $d = require __DIR__.'/../../fixtures/expresiones_ultron.php';

        return $d;
    }

    /** Cada expresión suelta, con su categoría. @return iterable<string,array{string,string}> */
    public static function todas(): iterable
    {
        foreach (self::dataset() as $categoria => $frases) {
            foreach ($frases as $i => $frase) {
                yield $categoria.'#'.$i.' · '.$frase => [$frase, $categoria];
            }
        }
    }

    /** Las de una categoría. @return iterable<string,array{string}> */
    private static function soloDe(string ...$categorias): iterable
    {
        $d = self::dataset();
        foreach ($categorias as $c) {
            foreach ($d[$c] as $i => $frase) {
                yield $c.'#'.$i.' · '.$frase => [$frase];
            }
        }
    }

    public static function ambiguas(): iterable
    {
        return self::soloDe('AMBIGUOUS');
    }

    /**
     * Las siete formas que NO se escalan a propósito, y por qué cada una.
     *
     * No son fallos: son decisiones. Reconocerlas haría que la máquina ofreciera
     * un traspaso que nadie pidió, y ése es el error caro.
     */
    private const AMBIGUAS_A_PROPOSITO = [
        'hay alguien ahi?' => 'puede ser «¿estás ahí?», que se contesta contestando',
        'esto es un bot?' => 'es transparencia: se responde la verdad, no se deriva',
        'necesito atencion personalizada' => 'en un gimnasio puede ser un entrenador personal: es una venta',
        'hay alguien en el gimnasio' => 'pregunta por el aforo o por si está abierto',
        'necesito q me expliquen en persona' => 'puede ser venir al gimnasio',
        'me da el numero de la recepcion' => 'pide un teléfono, que es otra capacidad',
        'quien me atiende' => 'puede ser quién entrena, quién recibe o quién contesta',
    ];

    /**
     * Formas que NO están en el banco y que también se decidieron a mano, cada
     * una con su porqué. Se prueban igual: una decisión sin prueba se deshace
     * sola en el siguiente refactor.
     */
    private const DECIDIDAS_FUERA_DEL_BANCO = [
        'me atiende alguien' => 'pregunta lo mismo que «hay alguien ahí», que ya se excluyó',
        'me contesta alguien' => 'ídem: sin «persona» o «asesor» no es una petición',
        'hay asesor nutricional' => 'pregunta por un SERVICIO, no por hablar con alguien',
        'hay recepcionista los domingos' => 'pregunta por horario de un servicio',
    ];

    /** Las formas inequívocas de pedir una persona. */
    public static function pideHumano(): iterable
    {
        foreach (self::dataset()['HUMAN_REQUEST'] as $i => $frase) {
            if (array_key_exists($frase, self::AMBIGUAS_A_PROPOSITO)) {
                continue;
            }
            yield 'HUMAN_REQUEST#'.$i.' · '.$frase => [$frase];
        }
    }

    /** @return iterable<string,array{string,string}> */
    public static function ambiguasAProposito(): iterable
    {
        foreach (self::AMBIGUAS_A_PROPOSITO as $frase => $porque) {
            yield $frase => [$frase, $porque];
        }
    }

    public static function rechazaHumano(): iterable
    {
        return self::soloDe('HUMAN_REFUSAL');
    }

    public static function noPideHumano(): iterable
    {
        return self::soloDe(
            'AFFIRMATION', 'NEGATION', 'INTEREST', 'MORE_INFO', 'START_PROCESS',
            'PLAN_REFERENCE', 'PRICE', 'SCHEDULE', 'CLASS', 'APP',
            'PAYMENT_INTENT', 'OBJECTION', 'HUMAN_REFUSAL', 'AMBIGUOUS', 'TYPO', 'SLANG', 'FRAGMENT',
        );
    }

    // ── El dataset ────────────────────────────────────────────────────────────

    public function test_the_dataset_is_the_one_that_was_promised(): void
    {
        $d = self::dataset();

        $this->assertCount(20, $d, '20 categorías');
        $this->assertSame(400, array_sum(array_map('count', $d)), '400 expresiones');

        foreach ($d as $categoria => $frases) {
            $this->assertCount(20, $frases, "la categoría $categoria trae 20");
            foreach ($frases as $f) {
                $this->assertIsString($f);
                $this->assertNotSame('', trim($f));
            }
        }
    }

    // ── I1 · Nada explota, y todo desenlace es del vocabulario ────────────────

    #[DataProvider('todas')]
    public function test_no_expression_breaks_the_deterministic_layer(string $frase, string $categoria): void
    {
        $vocabulario = [
            R::ACCEPT_OFFER, R::DECLINE_OFFER, R::MORE_INFO, R::PRICE_OF_PENDING,
            R::HOW_TO_START, R::SEND_IT, R::CHOOSE_PLAN, R::REFUSE_HUMAN,
            R::AFFIRMATION_WITHOUT_OFFER, R::NONE,
        ];

        foreach ([ConversationMemory::empty(), $this->conOferta()] as $memoria) {
            $r = $this->resolver->resolve($frase, $memoria, self::PLANES);

            $this->assertContains($r['type'], $vocabulario, "tipo fuera del vocabulario en [$categoria]");
            $this->assertTrue($r['plan_id'] === null || in_array($r['plan_id'], [20, 21, 23], true),
                'resolvió un plan que no está en el catálogo vendible');
        }

        // Y las demás lecturas deterministas del entrante tampoco revientan.
        $this->assertIsBool($this->novedad->isExplicitRepeatRequest($frase));

        $prueba = $this->handoff->peticionDeHumanoEn($frase);
        $this->assertTrue($prueba === null || is_string($prueba));
    }

    /** El mismo texto, dos veces, la misma lectura: aquí no decide un modelo. */
    #[DataProvider('todas')]
    public function test_the_reading_of_an_expression_is_deterministic(string $frase, string $categoria): void
    {
        $memoria = $this->conOferta();

        $this->assertSame(
            $this->resolver->resolve($frase, $memoria, self::PLANES),
            $this->resolver->resolve($frase, $memoria, self::PLANES),
            "la lectura de [$categoria] cambia entre dos llamadas iguales",
        );
    }

    // ── I2 · Lo ambiguo no elige plan ─────────────────────────────────────────

    /**
     * «ese», «el otro», «el que me dijo»: la persona se refiere a algo que está
     * en el historial, y adivinarlo es inventarlo. Con una oferta pendiente se
     * puede aceptar ESA; lo que no se puede es elegir un plan distinto porque
     * una palabra del mensaje se parezca a su nombre.
     */
    #[DataProvider('ambiguas')]
    public function test_an_ambiguous_reference_never_picks_a_plan_out_of_thin_air(string $frase): void
    {
        $r = $this->resolver->resolve($frase, ConversationMemory::empty(), self::PLANES);

        $this->assertNull($r['plan_id'], 'sin memoria no hay referente: no se puede elegir plan');
    }

    #[DataProvider('ambiguas')]
    public function test_an_ambiguous_reference_never_overrides_the_pending_plan(string $frase): void
    {
        $r = $this->resolver->resolve($frase, $this->conOferta(), self::PLANES);

        $this->assertTrue($r['plan_id'] === null || $r['plan_id'] === 20,
            'con una oferta pendiente del plan 20, lo ambiguo no puede acabar en otro plan');
    }

    // ── I3 · Pedir una persona, y no pedirla ──────────────────────────────────

    #[DataProvider('pideHumano')]
    public function test_asking_for_a_person_is_corroborated_by_the_text(string $frase): void
    {
        $prueba = $this->handoff->peticionDeHumanoEn($frase);

        $this->assertNotNull($prueba, 'quien pide hablar con alguien tiene que poder conseguirlo');
        $this->assertStringContainsString(
            $prueba,
            $this->normalizar($frase),
            'la prueba tiene que ser un trozo literal del mensaje, no una etiqueta',
        );
    }

    /**
     * Y las siete que se dejan sin escalar, con su motivo escrito.
     *
     * Esto no es una prueba de que el sistema acierte: es el registro de una
     * decisión. Si mañana se decide que «hay alguien ahi?» sí pide una persona,
     * esta prueba falla y obliga a cambiarla a conciencia.
     */
    #[DataProvider('ambiguasAProposito')]
    public function test_an_ambiguous_form_is_deliberately_not_escalated(string $frase, string $porque): void
    {
        $this->assertNull(
            $this->handoff->peticionDeHumanoEn($frase),
            "se escala «{$frase}» y no debería: {$porque}",
        );
    }

    /**
     * Y la mitad que de verdad importa: NADIE consigue un traspaso sin pedirlo.
     * Aquí se coló una vez un «sí por favor».
     */
    #[DataProvider('noPideHumano')]
    public function test_nobody_gets_a_handoff_without_asking_for_it(string $frase): void
    {
        $this->assertNull(
            $this->handoff->peticionDeHumanoEn($frase),
            'esta frase no pide una persona y aun así abriría el traspaso',
        );
    }

    /** Rechazar la llamada nunca puede leerse como pedirla. */
    #[DataProvider('rechazaHumano')]
    public function test_refusing_a_human_is_never_read_as_requesting_one(string $frase): void
    {
        $this->assertNull($this->handoff->peticionDeHumanoEn($frase));

        $r = $this->resolver->resolve($frase, $this->conOferta(), self::PLANES);
        $this->assertNotSame(R::ACCEPT_OFFER, $r['type'], 'rechazar no es aceptar');
    }

    // ── I4 · Las ráfagas ──────────────────────────────────────────────────────

    /**
     * Una ráfaga son varios mensajes seguidos. Cada trozo se lee por separado
     * —es lo que hace el sistema— y ninguno puede romper nada ni inventar un
     * plan: el trozo «si» de «si | pero antes | explicame una cosa» no puede
     * acabar comprando.
     */
    public function test_no_fragment_of_a_burst_can_buy_anything(): void
    {
        foreach (self::dataset()['MESSAGE_BURST'] as $rafaga) {
            foreach (explode('|', $rafaga) as $trozo) {
                $trozo = trim($trozo);
                if ($trozo === '') {
                    continue;
                }

                $r = $this->resolver->resolve($trozo, ConversationMemory::empty(), self::PLANES);

                // Nombrarlo sí vale: «el mensual» suelto ES elegir el mensual.
                if ($this->nombraUnPlan($trozo)) {
                    continue;
                }

                $this->assertNotSame(R::CHOOSE_PLAN, $r['type'],
                    "«{$trozo}» no elige plan sin que hubiera ninguno sobre la mesa");
                $this->assertNull($r['plan_id'], "«{$trozo}» no puede resolver un plan sin memoria");
            }
        }
    }

    // ── Andamio ───────────────────────────────────────────────────────────────

    /** ¿El trozo dice el nombre de un plan del catálogo? */
    private function nombraUnPlan(string $texto): bool
    {
        $t = $this->normalizar($texto);

        foreach (['mensual', 'trimestral', 'semana'] as $nombre) {
            if (str_contains($t, $nombre)) {
                return true;
            }
        }

        return false;
    }

    private function normalizar(string $s): string
    {
        return strtr(mb_strtolower(trim($s)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    /** Una conversación con el Plan Mensual recomendado y una pregunta abierta. */
    private function conOferta(): ConversationMemory
    {
        return ConversationMemory::empty()
            ->recommend([20], '2026-09-20T10:00:00Z')
            ->agentAsked('¿Quieres que te explique cómo empezar?', 'explain_how_to_start', 20, '2026-09-20T10:00:00Z', 1);
    }

    /**
     * Preguntar por un SERVICIO no es pedir una persona.
     *
     * Los trajo la revisión, y son de los caros: el patrón de «hay asesor»
     * escalaba «¿hay asesor nutricional?» y «¿hay recepcionista los domingos?»,
     * que preguntan qué ofrece el gimnasio. Ahora exige una palabra de
     * disponibilidad, que es lo que distingue pedir a alguien de preguntar por
     * algo.
     */
    public static function preguntasPorServicio(): array
    {
        return [
            ['hay asesor nutricional?'],
            ['hay recepcionista los domingos?'],
            ['hay entrenador disponible para principiantes?'],
            ['tienen asesor de nutricion?'],
        ];
    }

    #[DataProvider('preguntasPorServicio')]
    public function test_asking_about_a_service_is_not_asking_for_a_person(string $frase): void
    {
        $this->assertNull(
            $this->handoff->peticionDeHumanoEn($frase),
            'esto pregunta por lo que ofrece el gimnasio, no por hablar con alguien',
        );
    }

    /** Y pedir a alguien disponible sí lo es. */
    public function test_asking_for_an_available_advisor_still_counts(): void
    {
        $this->assertNotNull($this->handoff->peticionDeHumanoEn('hay asesor disponible'));
        $this->assertNotNull($this->handoff->peticionDeHumanoEn('hay algun encargado disponible'));
    }

    /** @return iterable<string,array{string,string}> */
    public static function decididasFueraDelBanco(): iterable
    {
        foreach (self::DECIDIDAS_FUERA_DEL_BANCO as $frase => $porque) {
            yield $frase => [$frase, $porque];
        }
    }

    #[DataProvider('decididasFueraDelBanco')]
    public function test_a_form_decided_by_hand_stays_decided(string $frase, string $porque): void
    {
        $this->assertNull(
            $this->handoff->peticionDeHumanoEn($frase),
            "se escala «{$frase}» y no debería: {$porque}",
        );
    }

    /** Y las que sí piden a alguien, en femenino y con artículo, siguen contando. */
    public static function peticionesConArticuloYGenero(): array
    {
        return [
            ['hay una asesora disponible'],
            ['hay la recepcionista disponible'],
            ['hay un agente disponible'],
            ['me puede contestar un asesor'],
            ['me atiende una asesora'],
        ];
    }

    #[DataProvider('peticionesConArticuloYGenero')]
    public function test_asking_for_a_person_works_in_feminine_too(string $frase): void
    {
        $this->assertNotNull(
            $this->handoff->peticionDeHumanoEn($frase),
            'media plantilla del gimnasio es femenina: el patrón no puede ignorarla',
        );
    }
}
