<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\Ultron\CommercialTurnPolicy as P;
use App\Services\Marketing\Ultron\ConversationMemory as CM;
use PHPUnit\Framework\TestCase;

/**
 * LA POLÍTICA DEL TURNO, MEDIDA CON FRASES DE GIMNASIO.
 *
 * Todos los casos de aquí vienen de una revisión que midió la primera versión
 * y la encontró peor que el fallo que arreglaba:
 *
 *   · descartaba respuestas CORRECTAS porque en español la pregunta va detrás
 *     de una coma: «Estamos en la Calle 10, ¿te queda cerca?» perdía la
 *     dirección y se juzgaba como puro interrogatorio;
 *   · encontraba el plan «Pro» dentro de «aprovechar» y de «problema», y con
 *     eso el texto nombraba un plan mientras el precio grabado era de otro;
 *   · y podía declarar un plan obligatorio y prohibido en el mismo turno.
 */
class PoliticaDelTurnoTest extends TestCase
{
    /** @return array<int,array<string,mixed>> */
    private function catalogo(): array
    {
        return [
            ['id' => 22, 'name' => 'TOTAL ACCESS - ELITE', 'is_recommended' => true, 'benefits' => ['Acceso completo', 'Acompañamiento']],
            ['id' => 2, 'name' => 'Plan Semana', 'is_recommended' => false, 'benefits' => ['Acceso 7 días']],
            ['id' => 4, 'name' => 'Plan Mensual', 'is_recommended' => false, 'benefits' => ['Acceso ilimitado']],
            ['id' => 8, 'name' => 'Pro', 'is_recommended' => false, 'benefits' => ['Tres meses']],
        ];
    }

    /** @return array<string,mixed> */
    private function politica(array $extra = []): array
    {
        return array_merge([
            'answer_first' => true, 'greet_required' => false,
            'required_plan_id' => null, 'forbidden_plan_ids' => [],
        ], $extra);
    }

    /** @return array<string,array{0:string}> */
    public static function respuestasCorrectas(): array
    {
        return [
            'direccion y pregunta' => ['Estamos en la Calle 10 #5-20 del barrio Centro, ¿te queda cerca?'],
            'horario y pregunta' => ['Abrimos de lunes a sábado de 5am a 10pm, ¿a qué hora te sirve?'],
            'servicios y pregunta' => ['Tenemos sala de pesas, zona funcional y clases grupales, ¿cuál te llama más?'],
            'con punto en medio' => ['Estamos en el centro de Neiva. ¿Te queda cerca?'],
            // Corta, pero es una respuesta: pedir más descartaba contestaciones
            // completas por no ser elocuentes.
            'respuesta corta' => ['Sí, tenemos parqueadero. ¿Vienes en carro?'],
        ];
    }

    /**
     * Una respuesta que informa Y pregunta no es un interrogatorio.
     *
     * @dataProvider respuestasCorrectas
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('respuestasCorrectas')]
    public function test_an_answer_that_informs_and_then_asks_is_not_an_interrogation(string $borrador): void
    {
        $this->assertSame([], P::violations($borrador, $this->politica(), $this->catalogo()), $borrador);
    }

    /** @return array<string,array{0:string}> */
    public static function interrogatorios(): array
    {
        return [
            'el real del incidente' => ['Buenas tardes, gracias por escribirnos. Para orientarte mejor, ¿cuál es tu objetivo principal al venir al gimnasio? Por ejemplo, ¿quieres bajar grasa, ganar músculo o mejorar tu condición física?'],
            'devuelve la pelota' => ['Claro, ¿qué te gustaría lograr?'],
            /*
             * Una SOLA pregunta con la enumeración dentro. Se escapaba: la
             * coma cortaba el recorte y la lista de opciones quedaba de
             * residuo haciéndose pasar por información.
             */
            'una pregunta con lista' => ['Buenas tardes, gracias por escribirnos. Para orientarte mejor, ¿cuál es tu objetivo: bajar grasa, ganar músculo o mejorar tu condición física?'],
            'saludo y lista' => ['¡Hola! ¿Qué te gustaría lograr, bajar de peso, tonificar o ganar masa muscular?'],
        ];
    }

    /** Y uno que sólo pregunta sigue siéndolo. */
    #[\PHPUnit\Framework\Attributes\DataProvider('interrogatorios')]
    public function test_a_draft_that_only_asks_is_still_caught(string $borrador): void
    {
        $this->assertContains(
            P::VIOLACION_SOLO_DESCUBRIMIENTO,
            P::violations($borrador, $this->politica(), $this->catalogo()),
            $borrador,
        );
    }

    /** @return array<string,array{0:string,1:?int}> */
    public static function mensajesConYSinPlan(): array
    {
        return [
            'aprovechar no es Pro' => ['hola quiero aprovechar, cuanto vale el mensual?', null],
            'problema no es Pro' => ['tengo un problema con la promocion', null],
            'nombrarlo si cuenta' => ['me interesa el Pro', 8],
        ];
    }

    /**
     * El nombre de un plan se busca como PALABRA, no como subcadena.
     *
     * «Pro» dentro de «aprovechar» hacía que el texto nombrara un plan y el
     * precio grabado fuera de otro. Dos planes en el mismo turno es lo que las
     * guardas del dinero llevan meses impidiendo.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mensajesConYSinPlan')]
    public function test_a_plan_name_is_matched_as_a_whole_word(string $texto, ?int $esperado): void
    {
        $p = P::decide('general_info', 'DISCOVERY', CM::fromArray(CM::blank()), $this->catalogo(), [], $texto);

        $this->assertSame($esperado, $p['required_plan_id'], $texto);
    }

    /**
     * Volver a preguntar por un plan rechazado lo reabre.
     *
     * Antes salía obligatorio y prohibido a la vez, y el respaldo lo volvía a
     * nombrar incumpliendo lo mismo que venía a arreglar.
     */
    public function test_asking_again_for_a_rejected_plan_reopens_it(): void
    {
        $memoria = CM::fromArray(array_merge(CM::blank(), ['plans_rejected' => [8]]));

        $p = P::decide('general_info', 'DISCOVERY', $memoria, $this->catalogo(), [], 'me interesa el Pro');

        $this->assertSame(8, $p['required_plan_id']);
        $this->assertNotContains(8, $p['forbidden_plan_ids'], 'el mismo plan salió obligatorio y prohibido');
    }

    /** Y el respaldo del plan se lee como lo diría una persona. */
    public function test_the_plan_reply_reads_like_a_person(): void
    {
        $insignia = P::planReply(['required_plan_id' => 22, 'wants_alternative' => false, 'allowed_plan_ids' => [22]], $this->catalogo());
        $otro = P::planReply(['required_plan_id' => 4, 'wants_alternative' => true, 'allowed_plan_ids' => [4]], $this->catalogo());

        $this->assertStringContainsString('TOTAL ACCESS - ELITE', (string) $insignia);
        $this->assertStringNotContainsString('es el TOTAL ACCESS', (string) $insignia, '«el Élite» suena a máquina');
        $this->assertStringContainsString('el Plan Mensual', (string) $otro);

        // Y ninguno inventa una cifra: el precio lo pone el motor de precios.
        foreach ([$insignia, $otro] as $texto) {
            $this->assertDoesNotMatchRegularExpression('/\$\s*\d/', (string) $texto);
        }
    }
}
