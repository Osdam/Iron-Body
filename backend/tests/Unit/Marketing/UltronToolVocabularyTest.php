<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\Ultron\UltronDecideService;
use PHPUnit\Framework\TestCase;

/**
 * Tres listas hablan de las mismas herramientas y tienen que encajar:
 *
 * - `UltronDecideService::V1_ALLOWED_TOOLS`: el MENÚ que decide ofrece siempre.
 * - `UltronDecideService::TOOL_VOCABULARY`: lo que el controlador acepta por
 *   FORMA (fail-closed: un nombre fuera es 422 antes de tocar nada).
 * - `SalesAgentDecisionSchema::ALLOWED_TOOLS`: el vocabulario del VALIDADOR
 *   legado, que commit reutiliza tal cual.
 *
 * Un nombre en el menú que el vocabulario no acepte deja a ULTRON sin poder
 * pedirlo; un nombre en el vocabulario que el validador no conozca hace que
 * commit lo marque como `forbidden_tool` después de haberlo aceptado. Las dos
 * derivas se han producido ya durante el cierre (PUNTOS 7 y 10): este test las
 * hace imposibles de introducir sin que la suite lo diga.
 */
class UltronToolVocabularyTest extends TestCase
{
    public function test_everything_in_the_menu_is_in_the_vocabulary(): void
    {
        $this->assertSame([], array_values(array_diff(UltronDecideService::V1_ALLOWED_TOOLS, UltronDecideService::TOOL_VOCABULARY)));
    }

    public function test_everything_in_the_vocabulary_is_known_to_the_validator(): void
    {
        $this->assertSame([], array_values(array_diff(UltronDecideService::TOOL_VOCABULARY, SalesAgentDecisionSchema::ALLOWED_TOOLS)));
    }

    public function test_the_tools_only_ultron_runs_are_in_its_vocabulary(): void
    {
        $this->assertSame([], array_values(array_diff(SalesAgentDecisionSchema::ULTRON_ONLY_TOOLS, UltronDecideService::TOOL_VOCABULARY)));
    }

    public function test_no_list_repeats_a_name(): void
    {
        foreach ([UltronDecideService::V1_ALLOWED_TOOLS, UltronDecideService::TOOL_VOCABULARY, SalesAgentDecisionSchema::ALLOWED_TOOLS] as $lista) {
            $this->assertSame(count($lista), count(array_unique($lista)));
        }
    }
}
