<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\Ultron\ComposerStyleGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ComposerStyleGuardTest extends TestCase
{
    private const PLANS = [['id' => 2, 'name' => 'Plan Semana'], ['id' => 4, 'name' => 'Plan Mensual'], ['id' => 5, 'name' => 'Trimestre'], ['id' => 6, 'name' => 'Semestre'], ['id' => 7, 'name' => 'Anualidad'], ['id' => 8, 'name' => 'Élite']];

    private ComposerStyleGuard $g;

    protected function setUp(): void
    {
        parent::setUp();
        $this->g = new ComposerStyleGuard;
    }

    public static function presion(): array
    {
        return [
            ['Quedan los últimos cupos del mes, ¡aprovecha ya!', 'fake_urgency'],
            ['Este precio es solo por hoy.', 'fake_urgency'],
            ['El precio sube la próxima semana, decide ahora.', 'fake_urgency'],
            ['Sin excusas: todo está en la mente.', 'guilt_or_body_shaming'],
            ['A tu edad ya deberías estar entrenando.', 'guilt_or_body_shaming'],
            ['Si de verdad quisieras cambiar, ya habrías empezado.', 'guilt_or_body_shaming'],
            ['El 90% de nuestros clientes bajan 5 kilos el primer mes.', 'invented_testimonial'],
            ['Miles de personas ya lo lograron con nosotros.', 'invented_testimonial'],
            // Los que la revisión independiente encontró pasando.
            ['¿No te da vergüenza seguir así?', 'guilt_or_body_shaming'],
            ['Nuestros clientes dicen que bajan 10 kilos en un mes.', 'invented_testimonial'],
            ['María, una clienta nuestra, bajó 12 kilos en dos meses.', 'invented_testimonial'],
            ['9 de cada 10 clientes renuevan porque ven resultados.', 'invented_testimonial'],
            ['La mayoría de nuestros socios bajan 8 kilos.', 'invented_testimonial'],
            ['Es ahora o nunca.', 'fake_urgency'],
            ['No te quedes sin tu cupo.', 'fake_urgency'],
            ['Quedan pocos cupos, decide ya.', 'fake_urgency'],
            ['Estás gordo y lo sabes.', 'guilt_or_body_shaming'],
        ];
    }

    #[DataProvider('presion')]
    public function test_pressure_never_goes_out(string $texto, string $code): void
    {
        $r = $this->g->inspect($texto, self::PLANS);

        $this->assertContains($code, $r['hard'], "«{$texto}»");
    }

    public static function consultivo(): array
    {
        return [
            ['Para empezar vienes con tu documento, te hacen la valoración inicial y el equipo confirma el medio de pago. ¿Qué día te queda bien pasar?'],
            ['Te entiendo: empezar da un poco de nervios. El primer día alguien del equipo técnico te acompaña en la valoración.'],
            ['El Plan Mensual te da acceso ilimitado y asesoría semi personalizada. Si quieres comparar con el Trimestre, te cuento.'],
            ['La verdad es que hoy el gimnasio está lleno a las 6 pm; a las 9 am está más tranquilo.'],
            ['Cuando decidas, me dices y te explico los pasos. Sin prisa.'],
            // Hechos que la revisión independiente vio bloqueados: vencimiento, cupos reales, promo real, horario, pago, salud, reencuadres.
            ['Tu plan termina mañana; si renuevas antes sigues sin pausa.'],
            ['Hoy es el último día de tu membresía.'],
            ['En los últimos días ha venido más gente por la mañana.'],
            ['Tu membresía se acaba hoy, pero puedes renovar cualquier día.'],
            ['Te recomiendo venir a las 5:30, antes de que se llene.'],
            ['Quedan solo 2 cupos en spinning de las 6 am; la de las 7 tiene más espacio.'],
            ['Te quedan pocos días de plan: vence el viernes.'],
            ['Sí, aprovecha ahora que está tranquilo; a las 6 pm se llena.'],
            ['Hoy es festivo, atendemos solo hasta el mediodía.'],
            ['La promo de septiembre va solo hasta el 30; después el valor vuelve al normal.'],
            ['Ya deberías haber recibido el link de pago; si no, te lo reenvío.'],
            ['A tu edad el trabajo de fuerza es muy recomendable, con supervisión.'],
            ['No es cuestión de voluntad, es de tener un plan.'],
            ['No todo está en la mente: el cuerpo necesita descanso.'],
            ['Aquí nadie te va a juzgar por tu cuerpo; empezamos donde estés.'],
            ['Me gusta esa actitud: nada de excusas, pero tampoco de castigos.'],
        ];
    }

    #[DataProvider('consultivo')]
    public function test_helpful_language_is_not_pressure(string $texto): void
    {
        $r = $this->g->inspect($texto, self::PLANS);

        $this->assertSame([], $r['hard'], "«{$texto}»");
    }

    public function test_dumping_the_catalogue_is_flagged_unless_asked(): void
    {
        $folleto = 'Tenemos Plan Semana, Plan Mensual, Trimestre, Semestre, Anualidad y Élite. ¿Cuál te interesa?';

        $this->assertContains('plan_dump', $this->g->inspect($folleto, self::PLANS)['soft']);
        $this->assertSame(6, $this->g->inspect($folleto, self::PLANS)['plans_mentioned']);
        $this->assertNotContains('plan_dump', $this->g->inspect($folleto, self::PLANS, askedForAll: true)['soft']);
        $this->assertNotContains('plan_dump', $this->g->inspect('El Plan Mensual o el Trimestre; depende de cuánto tiempo quieras comprometerte.', self::PLANS)['soft']);
        $this->assertTrue($this->g->askedForAllPlans('qué planes tienen?'));
        $this->assertFalse($this->g->askedForAllPlans('cuánto vale el mensual?'));
    }

    /** Lo ambiguo se anota para el Critic, no se corta. */
    public function test_ambiguous_urgency_and_body_references_are_flags_not_blocks(): void
    {
        $r = $this->g->inspect('Tu plan termina mañana; a tu edad conviene no parar.', self::PLANS);
        $this->assertSame([], $r['hard']);
        $this->assertContains('urgency_wording', $r['soft']);
        $this->assertContains('age_or_body_reference', $r['soft']);
    }

    /** Emoji por grafema; viñetas reales de WhatsApp; planes sólo cuando se nombran como planes. */
    public function test_graphemes_bullets_and_plan_names_are_counted_the_way_people_read_them(): void
    {
        $this->assertNotContains('emoji_excess', $this->g->inspect('Vamos 💪🏽🔥', self::PLANS)['soft'], 'dos emoji, uno con tono de piel');
        $this->assertContains('emoji_excess', $this->g->inspect('Mira ⭐⭐⭐⭐⭐', self::PLANS)['soft']);
        $this->assertContains('emoji_excess', $this->g->inspect('Somos de Neiva 🇨🇴🇨🇴🇨🇴🇨🇴🇨🇴', self::PLANS)['soft']);
        $this->assertContains('bullet_abuse', $this->g->inspect("Incluye:\n— acceso\n— clases\n— valoración\n— asesoría\n— casilleros", self::PLANS)['soft']);
        $this->assertContains('bullet_abuse', $this->g->inspect("Incluye:\n✅ acceso\n✅ clases\n✅ valoración\n✅ asesoría\n✅ casilleros", self::PLANS)['soft']);
        $this->assertSame(0, $this->g->inspect('esta semana no puedo, mejor el otro semestre', self::PLANS)['plans_mentioned']);
        $this->assertSame(2, $this->g->inspect('El Plan Mensual o el Trimestre; depende de ti.', self::PLANS)['plans_mentioned']);
        $this->assertTrue($this->g->askedForAllPlans('quiero info de los planes'));
        $this->assertFalse($this->g->askedForDetail('el plan completo'), '«completo» solo no pide detalle');
    }

    public function test_bot_phrases_bullets_emoji_and_brochure_length_are_flagged(): void
    {
        $this->assertContains('bot_phrase', $this->g->inspect('Listo. ¿Hay algo más en lo que pueda ayudarte?', self::PLANS)['soft']);
        $this->assertContains('bot_phrase', $this->g->inspect('No dudes en consultarme.', self::PLANS)['soft']);
        $this->assertContains('bullet_abuse', $this->g->inspect("Incluye:\n- a\n- b\n- c\n- d\n- e", self::PLANS)['soft']);
        $this->assertContains('emoji_excess', $this->g->inspect('Vamos 💪🔥🙌 con toda', self::PLANS)['soft']);
        $largo = str_repeat('El plan incluye acceso al gimnasio y clases. ', 25);
        $this->assertContains('brochure_length', $this->g->inspect($largo, self::PLANS)['soft']);
        $this->assertNotContains('brochure_length', $this->g->inspect($largo, self::PLANS, askedForDetail: true)['soft']);
        $this->assertTrue($this->g->askedForDetail('explícame bien todo lo que incluye'));
    }

    /**
     * El PCRE de producción (10.39, 2021) no conoce \p{Extended_Pictographic} ni \p{Emoji}:
     * la regex no compila, preg_match_all avisa y Laravel convierte el aviso en ErrorException
     * en cada commit. La suite local (PCRE 10.47) no puede verlo, así que se fija aquí.
     */
    public function test_the_guard_only_uses_unicode_properties_the_production_pcre_compiles(): void
    {
        // Solo los literales de cadena (ahí viven las regex); los comentarios pueden nombrar la propiedad.
        $literales = array_map(
            static fn (array $tok): string => $tok[1],
            array_filter(token_get_all((string) file_get_contents((new \ReflectionClass(ComposerStyleGuard::class))->getFileName())), static fn ($tok): bool => is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING),
        );
        preg_match_all('/\\\\[pP]\{([A-Za-z_&]+)\}/', implode("\n", $literales), $m);
        $generales = ['L', 'Lu', 'Ll', 'M', 'N', 'Nd', 'P', 'S', 'So', 'Sm', 'Sc', 'Sk', 'Z'];

        $this->assertSame([], array_values(array_diff(array_unique($m[1]), $generales)), 'solo categorías generales Unicode: las propiedades de emoji no existen en PCRE 10.39');
    }

    public function test_emoji_graphemes_are_counted_by_code_point_ranges(): void
    {
        $this->assertNotContains('emoji_excess', $this->g->inspect('Vamos 👨‍👩‍👧 ❤️‍🔥', self::PLANS)['soft'], 'dos secuencias ZWJ cuentan dos');
        $this->assertContains('emoji_excess', $this->g->inspect('Vamos 👨‍👩‍👧 ❤️‍🔥 🏳️‍🌈', self::PLANS)['soft'], 'tres secuencias ZWJ cuentan tres');
        $this->assertNotContains('emoji_excess', $this->g->inspect('Marca ©, registro ® y ™ no son emoji', self::PLANS)['soft'], '©®™ son prosa, no emoji');
        $this->assertContains('emoji_excess', $this->g->inspect('Listo ✅ ➡ ⭐', self::PLANS)['soft'], 'símbolos del BMP con presentación emoji cuentan');
    }

    /**
     * LA EXCUSA DE PRECIO ES ESPECULACIÓN Y SE DETECTA POR ORACIÓN.
     *
     * La persona vio dos precios para el mismo plan y preguntó por qué. El
     * modelo contestó «la diferencia puede ser por confusión con otro plan o
     * información previa»: no lo sabía. Se detecta la oración para poder
     * retirarla y dejar el precio verdadero, que suele ir detrás.
     *
     * @return array<string,array{0:string,1:bool}>
     */
    public static function excusasDePrecio(): array
    {
        return [
            'la real' => ['La diferencia en el precio puede ser por confusión con otro plan o información previa.', true],
            'quizás fue un error' => ['Quizás fue por un error de información anterior.', true],
            'tal vez otro plan' => ['Tal vez se debe a otro plan que te mencioné.', true],
            'probablemente confusión' => ['Probablemente se trate de una confusión con la promoción.', true],
            'el de antes era de otro plan' => ['El precio anterior era de otro plan.', true],
            'disculpa sin causa' => ['Disculpa la confusión. El Plan Semana cuesta $45.000 COP.', false],
            'precio a secas' => ['El Plan Semana cuesta $45.000 COP y te da acceso por 7 días.', false],
            'explicar un beneficio' => ['La diferencia entre los dos planes es el acceso a clases.', false],
            'hay diferencia de precio entre planes' => ['Hay diferencia de precio entre el Semana y el Mensual porque duran distinto.', false],
            'puede parecer grande' => ['La diferencia de precio entre el Plan Semana y el Plan Mensual puede parecer grande, pero el Mensual rinde mucho mas.', false],
            'puede ser por otro plan' => ['La diferencia de precio puede ser por otro plan que viste.', true],
        ];
    }

    #[DataProvider('excusasDePrecio')]
    public function test_a_price_excuse_is_detected_and_an_honest_sentence_is_not(string $texto, bool $esExcusa): void
    {
        $g = new ComposerStyleGuard;

        $this->assertSame($esExcusa, $g->priceExcuseIn($texto) !== null, "«{$texto}»");
    }

    /**
     * Lo que el gimnasio ofrece de verdad, como llega al guard: nombres y tipos
     * de las clases activas y la ficha de la base de conocimiento.
     *
     * @return array<string, array{0:string, 1:bool}>
     */
    public static function serviciosInventados(): array
    {
        return [
            // El mensaje real de la prueba física: las especialidades de la ficha de los entrenadores no son clases.
            'pilates y yoga como servicios' => ['Claro, contamos con entrenadores especializados en musculacion, funcional, pilates, yoga y mas.', true],
            'clases de yoga y pilates' => ['Tenemos clases de yoga y pilates para todos los niveles.', true],
            'spinning en la manana' => ['Puedes hacer spinning en la mañana con nuestros entrenadores.', true],
            'zona humeda que no existe' => ['Después de entrenar puedes pasar por el sauna.', true],
            // Negar lo que no hay es honesto.
            'no tenemos yoga' => ['No tenemos clases de yoga, pero sí IRON POWERFLOW.', false],
            'yoga no manejamos' => ['Yoga no manejamos por ahora; nuestras clases son funcionales.', false],
            'no la tengo confirmada' => ['La clase de pilates no la tengo confirmada en mi información.', false],
            // Negaciones normales y el eco de la pregunta: honestas, se quedan.
            'yoga no, pero' => ['Yoga no, pero tenemos IRON POWERFLOW.', false],
            'no hace parte de la oferta' => ['El yoga no hace parte de nuestra oferta.', false],
            'no hay' => ['No hay pilates, pero sí clases funcionales.', false],
            'eco de la pregunta' => ['Sobre el yoga que preguntas, te cuento que nuestras clases son IRON POWERFLOW e IRON STRENGTH.', false],
            'no esta disponible' => ['Lamentablemente yoga no está disponible.', false],
            // Palabras genéricas del entrenamiento nunca se tocan.
            'cardio y funcional' => ['Aquí entrenas cardio y funcional con acompañamiento y una zona de pesas completa.', false],
            'clase real' => ['Tenemos IRON POWERFLOW los lunes a las 06:00, es funcional y dura una hora.', false],
            // Palabras que la revisión midió como falsas alarmas y ya no están en el léxico.
            'tenis son zapatillas' => ['Trae tus tenis y ropa cómoda para entrenar. Te esperamos en IRON POWERFLOW.', false],
            'escalada de cargas' => ['Hacemos una escalada progresiva de cargas en tu rutina.', false],
            'trx es un aparato' => ['Tenemos TRX en la zona funcional.', false],
            'ambiente de baile' => ['El ambiente es de baile y energía en IRON PARTY.', false],
            'primer step' => ['Da el primer step hoy mismo.', false],
        ];
    }

    /** Una ficha que NIEGA un servicio no lo habilita: lo permitido se lee con la misma vara. */
    public function test_a_knowledge_item_that_denies_a_service_does_not_allow_it(): void
    {
        $g = new ComposerStyleGuard;
        $ficha = ['No contamos con piscina ni sauna: somos un gimnasio de fuerza.', 'IRON POWERFLOW'];

        $this->assertNotNull($g->inventedServiceIn('Contamos con piscina climatizada y sauna.', $ficha));
        $this->assertNull($g->inventedServiceIn('Contamos con piscina climatizada.', ['La sede tiene piscina climatizada.']));
    }

    #[DataProvider('serviciosInventados')]
    public function test_a_class_the_gym_does_not_have_is_detected_and_an_honest_sentence_is_not(string $texto, bool $inventado): void
    {
        $g = new ComposerStyleGuard;
        $ofrecido = ['IRON POWERFLOW', 'IRON STRENGTH', 'Funcional', 'Iron Body Neiva es un centro de acondicionamiento físico en Neiva.'];

        $this->assertSame($inventado, $g->inventedServiceIn($texto, $ofrecido) !== null, "«{$texto}»");
    }

    /** La lista es vocabulario de detección: crear la clase «Yoga» la habilita sin tocar código. */
    public function test_a_discipline_becomes_real_the_moment_the_crm_has_it(): void
    {
        $g = new ComposerStyleGuard;

        $this->assertNotNull($g->inventedServiceIn('Tenemos clases de yoga los martes.', ['IRON POWERFLOW']));
        $this->assertNull($g->inventedServiceIn('Tenemos clases de yoga los martes.', ['IRON POWERFLOW', 'IRON YOGA']));
        $this->assertNull($g->inventedServiceIn('Tenemos piscina climatizada.', ['La sede cuenta con piscina y zona de pesas.']));
        // Y devuelve la oración culpable, no el texto entero, para retirar solo esa.
        $this->assertSame(
            'Además hay clases de zumba los viernes.',
            $g->inventedServiceIn('Tenemos IRON POWERFLOW los lunes. Además hay clases de zumba los viernes. ¿Te animas?', ['IRON POWERFLOW']),
        );
    }
}
