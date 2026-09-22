<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingKnowledgeItem;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\Ultron\ComposerStyleGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «EL MEJOR GIMNASIO DE NEIVA» LO DICE EL NEGOCIO, O NO SE DICE.
 *
 * Esto nace de una medición incómoda: dieciocho de dieciocho afirmaciones de
 * superioridad pasaban TODOS los guardianes de salida. «Iron Body es el mejor
 * gimnasio de Neiva» salía por WhatsApp sin que nada la parara, y no es una
 * exageración de marketing: es una afirmación sobre un ranking que nadie ha
 * medido. Un redactor no puede deducirla —para saber quién es el mejor de una
 * ciudad hace falta un dato que no existe en este sistema—, así que la única
 * forma honesta de que salga es que el negocio la haya escrito y aprobado.
 *
 * Lo que se mide aquí:
 *
 *   1. que sin aprobación NO pase, ni una ni la última de un mensaje;
 *   2. que con aprobación pase, literal;
 *   3. que un borrador NO autorice nada (aprobar es de una persona, no de
 *      quien escribe en la base);
 *   4. y —lo que más importa— que las frases honestas sigan pasando: «el mejor
 *      PARA TI», «el más completo de nuestros planes», «el único plan con
 *      clases». Un guardián que mate esas frases deja al agente sin poder
 *      recomendar, que es su trabajo.
 */
class CopyAprobadoDeMarcaTest extends TestCase
{
    use RefreshDatabase;

    private ComposerStyleGuard $guard;

    private MarketingKnowledgeBaseService $kb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = app(ComposerStyleGuard::class);
        $this->kb = app(MarketingKnowledgeBaseService::class);
    }

    /**
     * Una frase aprobada de verdad, y no aprobada por accidente.
     *
     * `review_status` NO es asignable en masa —aprobarse a uno mismo por
     * asignación es justo el agujero que el modelo cierra—, así que pasarlo a
     * `create()` no hacía nada: la fila acababa aprobada por el hook de origen
     * de confianza, y el test decía una cosa mientras dependía de otra. Ahora
     * se aprueba por el camino real y se comprueba que quedó aprobada, para
     * que si ese hook cambiara el test lo dijera en vez de seguir en verde.
     */
    private function aprobar(string $contenido, string $key = 'brand_copy.claim'): MarketingKnowledgeItem
    {
        $item = MarketingKnowledgeItem::create([
            'category' => 'brand_copy',
            'key' => $key,
            'title' => 'Frase autorizada',
            'content' => $contenido,
            'is_active' => true,
            'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL,
        ])->approve('revisor');

        $this->assertSame(MarketingKnowledgeItem::REVIEW_APPROVED, $item->review_status);

        return $item;
    }

    /** @return array<int,array{id:int,name:string}> */
    private function planes(): array
    {
        return [['id' => 22, 'name' => 'TOTAL ACCESS - ELITE'], ['id' => 4, 'name' => 'Plan Mensual']];
    }

    private function duro(string $texto, array $aprobadas = []): array
    {
        return $this->guard->inspect($texto, $this->planes(), false, false, $aprobadas)['hard'];
    }

    public function test_sin_aprobacion_la_superioridad_no_sale(): void
    {
        $this->assertSame([], $this->kb->approvedBrandCopy(), 'La lista arranca vacía: sin aprobación no hay excepción.');

        foreach ([
            'Iron Body es el mejor gimnasio de Neiva.',
            'Somos el número uno del Huila.',
            'Es el gimnasio más completo de la ciudad.',
            'Ningún otro gimnasio de la zona te da esto.',
            'Somos mejores que la competencia.',
            'A diferencia de otros gimnasios, aquí sí te acompañan.',
            'De todos los gimnasios de Neiva, somos el mejor.',
            'En Neiva no hay nadie como nosotros.',
            'Ningún gimnasio de Neiva se nos compara.',
            'Somos los primeros en Neiva.',
            'Somos el mejor gym de acá.',
            'Somos el mejor gimnasio del sector.',
            'No hay otro lugar así en Neiva.',
            'A diferencia de otros centros, aquí entrenas con plan.',
            /*
             * Las veintidós de una revisión que midió con un corpus escrito
             * APARTE de este patrón, no alrededor de él. Es la diferencia que
             * importa: el corpus propio encuentra lo que uno ya contempló.
             */
            // el alcance delante, sin el preámbulo «de todos los»
            'En Neiva, somos el mejor gimnasio.',
            'En Neiva no hay un gimnasio mejor.',
            'En el Huila no vas a encontrar algo así.',
            // exclusividad sin la palabra «otro», que es la forma natural
            'Ningún gimnasio de Neiva te da este acompañamiento.',
            'Ninguno de los gimnasios de la ciudad tiene esto.',
            'No vas a encontrar otro gimnasio así en Neiva.',
            // ranking sin alcance: no existe un «número uno» honesto
            'Somos el número uno.',
            'Somos los líderes indiscutibles.',
            'Iron Body es insuperable.',
            'Somos el gimnasio #1.',
            // comparación parafraseada, con la estructura completa
            'Somos mejores que cualquiera.',
            'Somos mejores que el resto.',
            'Estamos por encima de la competencia.',
            'Otros gimnasios no te dan esto.',
            'El resto de gimnasios ni se acerca.',
            'Superamos a cualquier gimnasio de Neiva.',
            'Nos comparan con otros gimnasios y ganamos.',
            // topónimos que faltaban en la lista de alcance
            'Somos el mejor gym de aquí.',
            'Somos el mejor gimnasio del barrio.',
            'Somos el mejor gimnasio del departamento.',
            'Somos el mejor gimnasio de todo Neiva.',
            'Somos el mejor gimnasio de la comuna.',
            /*
             * La coma apositiva, que abrió el propio arreglo del falso
             * positivo de «en la zona»: prohibir la coma curaba una frase y
             * dejaba pasar la afirmación entera partida en dos. Y la forma que
             * el anclaje al fin de oración dejaría escapar por su cuenta
             * («…de Neiva PARA PRINCIPIANTES»), que es por lo que hacen falta
             * los dos patrones y no uno.
             */
            'Somos el mejor gimnasio, de Neiva y de todo el Huila.',
            'Iron Body es el mejor gimnasio, de toda la ciudad.',
            'Somos el más completo, de Neiva.',
            'Somos el gimnasio más moderno, de Neiva.',
            'Iron Body es el más grande, del Huila.',
            'Somos el mejor gimnasio de Neiva para principiantes.',
            'Somos el gimnasio más completo de la ciudad para empezar.',
            'Tenemos el mejor equipo del Huila para acompañarte.',
            // La negación tiene tres adverbios y el verbo va en futuro la mitad
            // de las veces: pedir «no» y el infinitivo dejaba salir esto entero.
            'Jamás encontrarás algo igual.',
            'Nunca vas a encontrar algo igual.',
            'Jamás vas a encontrar otro gimnasio así.',
        ] as $frase) {
            $this->assertContains(
                'unapproved_superiority_claim',
                $this->duro($frase, $this->kb->approvedBrandCopy()),
                "Pasó sin aprobación: {$frase}",
            );
        }
    }

    public function test_con_aprobacion_del_negocio_sale_literal(): void
    {
        $this->aprobar('Iron Body es el mejor gimnasio de Neiva.');

        $aprobadas = $this->kb->approvedBrandCopy();
        $this->assertSame(['Iron Body es el mejor gimnasio de Neiva.'], $aprobadas);
        $this->assertSame([], $this->duro('Iron Body es el mejor gimnasio de Neiva.', $aprobadas));
    }

    /**
     * EL AGUJERO QUE SE CERRÓ: abrir con la frase aprobada no autoriza el resto.
     *
     * La primera versión sólo miraba la primera coincidencia, así que un
     * mensaje que empezara con la frase autorizada podía colar una segunda
     * afirmación inventada detrás, gratis.
     */
    public function test_una_frase_aprobada_no_autoriza_a_las_demas(): void
    {
        $this->aprobar('Iron Body es el mejor gimnasio de Neiva.');
        $aprobadas = $this->kb->approvedBrandCopy();

        $this->assertContains(
            'unapproved_superiority_claim',
            $this->duro('Iron Body es el mejor gimnasio de Neiva. Y somos los número uno del Huila.', $aprobadas),
        );
    }

    /**
     * LA PARTE QUE CIERRA EL CÍRCULO: la máquina no se autoriza a sí misma.
     *
     * El camino por el que una frase podría entrar sola es la API interna —la
     * puerta de n8n y del modelo—, y ése es justo el origen que NO tiene
     * autoridad para publicar: nace en borrador y se queda ahí hasta que una
     * persona la apruebe por consola. Una persona escribiendo en el panel sí es
     * la aprobación, y por eso ese origen es de confianza.
     */
    public function test_una_frase_propuesta_por_la_maquina_no_se_autoriza_a_si_misma(): void
    {
        $propuesta = MarketingKnowledgeItem::create([
            'category' => 'brand_copy',
            'key' => 'brand_copy.propuesta',
            'title' => 'Propuesta sin aprobar',
            'content' => 'Iron Body es el mejor gimnasio de Neiva.',
            'is_active' => true,
            'origin' => MarketingKnowledgeItem::ORIGIN_INTERNAL_API,
        ]);

        $this->assertSame(MarketingKnowledgeItem::REVIEW_PENDING, $propuesta->review_status);

        $this->assertSame([], $this->kb->approvedBrandCopy(), 'Un borrador entró en la lista de autorizadas.');
        $this->assertContains(
            'unapproved_superiority_claim',
            $this->duro('Iron Body es el mejor gimnasio de Neiva.', $this->kb->approvedBrandCopy()),
        );
    }

    public function test_una_frase_desactivada_deja_de_autorizar(): void
    {
        $item = $this->aprobar('Iron Body es el mejor gimnasio de Neiva.');
        $this->assertNotSame([], $this->kb->approvedBrandCopy());

        $item->update(['is_active' => false]);

        $this->assertSame([], $this->kb->approvedBrandCopy());
        $this->assertContains(
            'unapproved_superiority_claim',
            $this->duro('Iron Body es el mejor gimnasio de Neiva.', $this->kb->approvedBrandCopy()),
        );
    }

    /**
     * LA OTRA MITAD, Y LA QUE MÁS FÁCIL SE ROMPE.
     *
     * Recomendar es comparar: si el guardián mata «el mejor para ti» o «el más
     * completo de nuestros planes», el agente se queda sin poder recomendar, y
     * eso es peor que la afirmación que se intentaba evitar. `SecuenciaComercialRealTest`
     * tiene un borrador vivo con «el TOTAL ACCESS - ELITE es el mejor para ti».
     */
    public function test_las_frases_honestas_siguen_pasando(): void
    {
        foreach ([
            'El TOTAL ACCESS - ELITE es el mejor para ti.',
            'Es el más completo de nuestros planes.',
            'Es el único plan que incluye clases.',
            'Es la mejor opción si vas a entrenar todos los días.',
            'Somos un gimnasio en Neiva con acompañamiento desde el primer día.',
            'Tenemos la mejor disposición para ayudarte.',
            'El mejor momento para empezar es hoy.',
            'De todas las opciones, la mejor para ti es el TOTAL ACCESS - ELITE.',
            'Somos los primeros en apoyarte cuando empiezas.',
            'Nadie te obliga a nada: entras cuando quieras.',
            'Ninguno de nuestros planes tiene permanencia.',
            'Ven a tu primera clase esta semana.',
            'A diferencia de otros planes, este incluye clases.',
            'Tenemos el mejor ambiente en la zona de pesas.',
            'La mejor máquina en el sector de espalda es la de remo.',
            'Es mejor que los demás días de la semana.',
            'Este plan es mejor que otros planes para tu objetivo.',
            'No hay otro sitio para parquear cerca.',
            'Nadie te va a decir lo que nosotros pensamos de eso.',
            // Y las honestas que rozan las familias nuevas.
            'Es la mejor hora, en la zona hay menos gente.',
            'Es mejor que los demás días de la semana.',
            'Es mejor que esperes a los demás para la clase grupal.',
            'Ninguno de nuestros planes tiene permanencia.',
            'Somos el gimnasio #1 para ti.',
            'Llevamos el número uno en la camiseta.',
            'En Neiva el mejor momento para entrenar es temprano.',
            'No te puedo comparar con otros gimnasios: solo sé lo nuestro.',
            'Es el mejor plan, en la zona de pesas vas a estar cómodo.',
            'Es la mejor opción, en el sector hay parqueadero.',
            'No vas a encontrar parqueadero igual de cerca.',
            'Nunca encontrarás la sala vacía a esa hora.',
            'No vas a encontrar el vestuario sin toallas.',
        ] as $frase) {
            $this->assertSame([], $this->duro($frase), "El guardián mató una frase honesta: {$frase}");
        }
    }

    public function test_la_categoria_existe_en_las_dos_listas_que_el_sistema_usa(): void
    {
        /*
         * Están desincronizadas de nacimiento y no se puede unificar sin cambiar
         * el prompt: una dice qué se puede GUARDAR, la otra qué se ENVÍA. Una
         * categoría declarada sólo en la primera existiría en la base y no la
         * vería ningún modelo; sólo en la segunda, no se podría crear.
         */
        $this->assertContains('brand_copy', MarketingKnowledgeItem::CATEGORIES);
        $this->assertContains('brand_copy', MarketingKnowledgeBaseService::PROMPT_CATEGORIES);
    }

    public function test_lo_aprobado_llega_al_prompt_por_su_categoria(): void
    {
        $this->aprobar('Iron Body es el mejor gimnasio de Neiva.');

        $agrupado = $this->kb->groupedForPrompt();

        $this->assertArrayHasKey('brand_copy', $agrupado);
        $this->assertStringContainsString('el mejor gimnasio de Neiva', implode(' ', $agrupado['brand_copy']));
    }
}
