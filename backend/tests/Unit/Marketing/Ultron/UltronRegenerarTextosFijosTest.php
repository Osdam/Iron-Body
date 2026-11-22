<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Models\Plan;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\UltronPlansOverview;
use App\Services\Marketing\Ultron\UltronRegenerationNotes as N;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * «REGENERAR ANTES DE SUSTITUIR» · CORRECCIONES TRAS LAS REVISIONES · C8
 * (encargo `encargo-be-r6.md`): EL DOCUMENTO DE LOS TEXTOS FIJOS NUEVOS, que
 * aprueba el propietario (gate 4-H).
 *
 * `docs/ultron-studio/plans/informes-motores/a4-textos-regenerar.md` sale de
 * aquí, con las clases reales, y la prueba lo FIJA: si un texto cambia en el
 * código y no en el documento (o al revés), falla. Se regenera con
 * `A4_REGENERAR=escribir` (el mismo mecanismo que `a4-textos-usted.md`).
 *
 * Lleva, en este orden y para leerse de corrido, con una frase que dice cuándo
 * sale cada texto:
 *  1. las dos instrucciones del parche del motor (`T_PLANS_COMPOSER` y
 *     `T_PLANS_CRITIC`, del compilador);
 *  2. todas las notas de reescritura, cada una con su plantilla y un ejemplo ya
 *     rellena (el trato, la longitud, las seis de la lista, la del beneficio y
 *     las seis de la política);
 *  3. el formato de la expansión de la lista, con tres ejemplos del catálogo de
 *     prueba («Nombre ($65.000 COP)», uno con duración y «1 día»).
 *
 * Cada nota del código está en el documento (ninguna se queda fuera) y ninguna
 * lleva llaves. Pura: sin base de datos (los `Plan` son instancias en memoria),
 * sin config, sin reloj.
 */
class UltronRegenerarTextosFijosTest extends TestCase
{
    private const DOC = 'docs/ultron-studio/plans/informes-motores/a4-textos-regenerar.md';

    /**
     * Cuándo sale cada nota, y los parámetros del ejemplo. En el orden de la
     * tabla de `UltronRegenerationNotes` (el de la respuesta de `ai/check`).
     *
     * @return array<string, array{string, string, array<string,mixed>}> `código` o `código/detalle` → [título, cuándo sale, parámetros del ejemplo]
     */
    private static function notas(): array
    {
        return [
            'register_tuteo' => ['El trato de usted', 'Con el tono «profesional», cuando el borrador (ya expandido) tutea. Entre paréntesis van las marcas de tuteo que encontró el detector, de su lista cerrada y como mucho cinco.', ['MARCAS' => ['te', 'quieres']]],
            'length_over_cap' => ['La longitud', 'Con un tope publicado distinto del de la línea base, cuando el borrador expandido pasa del tope (cada marcador cuenta como el texto que lo sustituye). L es su medida, N el tope publicado y M lo que se le pide al redactor (el 80 % del tope, en decenas: 160 con 200).', ['L' => 241, 'N' => 200, 'M' => UltronPromptCompiler::composerTargetChars(200)]],
            'plans_overview/missing' => ['La lista: falta la lista', 'En un turno con la lista ofrecida (la persona pidió los planes o los precios en general) y sin herramientas, cuando el borrador no nombra al menos dos planes de la lista con sus marcadores. También si no lleva ninguno aunque nombre un plan a mano (el borrador típico del 18-11, «El Plan Valera cuesta {{PLAN_PRICE}}…»).', []],
            'plans_overview/mixed' => ['La lista: marcadores mezclados', 'Con la lista, cuando el borrador mezcla sus marcadores con PLAN_NAME, PLAN_PRICE o PLAN_DURATION, o repite uno.', []],
            'plans_overview/name_written' => ['La lista: el nombre escrito a mano', 'Con la lista y al menos uno de sus marcadores, cuando el borrador escribe además a mano el nombre de un plan de la lista.', []],
            'plans_overview/unknown_marker' => ['La lista: un marcador que no existe', 'Con la lista, cuando el borrador usa un marcador que no es de ella (PLAN_4 con tres planes, PLAN_0, PLAN_01, plan_1), haya o no herramientas en el turno. K es cuántos planes tiene la lista.', ['K' => 3]],
            'plans_overview/no_list' => ['La lista: un marcador sin lista', 'En un turno sin lista (no se ofreció, o es un turno de cobro), cuando el borrador usa PLAN_1, PLAN_2 o PLAN_3.', []],
            'plans_overview/price_written' => ['La lista: una cifra escrita', 'Con la lista, cuando el borrador escribe una cifra o un precio (los pone el backend).', []],
            'benefit_misattributed' => ['El beneficio de otro plan', 'Cuando una frase del borrador nombra un solo plan y le atribuye un beneficio que en el catálogo es de otro. PLAN es su marcador sin llaves si el borrador lo nombra con él, su nombre del catálogo si lo escribe a mano, o «ese plan» si el nombre queda vacío al sanearlo; BENEFICIO es el texto del catálogo, sin llaves ni saltos de línea y a 60 caracteres.', ['PLAN' => 'PLAN_1', 'BENEFICIO' => 'Entrenador personal dedicado']],
            'policy_greeting_sells' => ['La política: el saludo que vende', 'Cuando la persona solo saludó (modo recepción) y el borrador nombra planes, precios o pagos, o pregunta ya su objetivo.', []],
            'policy_active_goal_lost' => ['La política: la visita a medias', 'Cuando hay una visita de cortesía a medio concretar, la persona está dando sus datos y el borrador no la retoma (nombra un plan o una cifra).', []],
            'policy_wrong_first_plan' => ['La política: el primer plan equivocado', 'Cuando el turno tiene un plan obligatorio (commercial_turn_policy.required_plan_id) y el borrador nombra otro primero, o cuando nombra un plan que la persona rechazó (fuera de commercial_turn_policy.allowed_plan_ids). Las dos claves le llegan al redactor en commercial_turn_policy.', []],
            'policy_answer_without_fact' => ['La política: la información sin el dato', 'Cuando la persona pidió información del gimnasio y el borrador no copia ningún dato de knowledge_base (la dirección, el horario o los servicios).', []],
            'policy_general_info_only_discovery' => ['La política: solo preguntas', 'Cuando la persona pidió información (también el horario o el precio) y el borrador solo pregunta.', []],
            'policy_memory_turn_sells' => ['La política: la memoria que vende', 'Cuando la persona preguntó por algo ya acordado (su visita) y el borrador vende o pregunta su objetivo.', []],
        ];
    }

    /** Del catálogo de prueba (el de producción del 18-11): uno de un mes, uno con duración y el de un día. */
    private const EJEMPLOS = [['Plan Valera', 65000, 30], ['Plan Semana', 45000, 7], ['PLAN DÍA', 10000, 1]];

    private static function constante(string $nombre): string
    {
        return (string) (new ReflectionClassConstant(UltronPromptCompiler::class, $nombre))->getValue();
    }

    private static function plan(string $nombre, float $precio, int $dias): Plan
    {
        return (new Plan)->forceFill(['name' => $nombre, 'price' => $precio, 'duration_days' => $dias]);
    }

    public function test_los_textos_fijos_de_regenerar_que_aprueba_el_propietario(): void
    {
        $notas = self::notas();

        // Ninguna nota del código se queda fuera del documento, ni sobra ninguna.
        $claves = array_map(fn (array $e): string => $e[1] === null ? $e[0] : $e[0].'/'.$e[1], N::entries());
        $this->assertSame($claves, array_keys($notas), 'cada nota de UltronRegenerationNotes, en su orden');

        $md = "# A4 · «Regenerar antes de sustituir» · Los textos fijos nuevos (para aprobar)\n\n";
        $md .= 'Generado por `UltronRegenerarTextosFijosTest::test_los_textos_fijos_de_regenerar_que_aprueba_el_propietario` (no a mano), con las clases reales: ';
        $md .= '`UltronPromptCompiler` (las dos instrucciones del parche del motor), `UltronRegenerationNotes` (las notas de reescritura) y `UltronPlansOverview::item()` (la expansión de la lista). ';
        $md .= "Si un texto cambia en el código y no aquí, la prueba falla: se regenera con `A4_REGENERAR=escribir` y se vuelve a aprobar.\n\n";
        $md .= 'Todo esto existe solo con la versión del Studio aplicada; sin ella (Legacy) no sale ninguno de estos textos. ';
        $md .= 'Las instrucciones y las notas son para el modelo (sin tildes, como el resto de los prompts) y la persona no las lee; lo único que lee la persona es la expansión de la lista (§3). ';
        $md .= "Las notas no llevan nada de lo que escribió la persona: solo marcas de una lista cerrada, números y textos del catálogo saneados.\n\n";

        // 1. El parche del motor.
        $md .= "## 1. Las dos instrucciones del parche del motor\n\n";
        $md .= 'Salen en el paquete de prompts de toda versión del Studio que gobierna algún parámetro (con la línea base no hay parches y no salen), al final de los parches. ';
        $md .= "Le enseñan al redactor a escribir la lista de planes con los marcadores, y al revisor a no tumbarla por «no lleva el marcador de precio». La lista solo viene cuando la persona pide los planes o los precios en general.\n\n";
        foreach ([
            ['Al redactor (`T_PLANS_COMPOSER`)', 'en `composer` y en `composer_retry`, en la línea siguiente a `'.self::constante('A_FORMA').'`', self::constante('T_PLANS_COMPOSER')],
            ['Al revisor (`T_PLANS_CRITIC`)', 'en `critic` y en `critic_retry`, en la línea siguiente a `'.self::constante('A_PROGRESSIVE').'`', self::constante('T_PLANS_CRITIC')],
        ] as [$titulo, $donde, $texto]) {
            $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $texto, 'ASCII imprimible, sin tildes ni llaves');
            $this->assertDoesNotMatchRegularExpression('/[{}]/', $texto);
            $md .= "### {$titulo}\n\n";
            $md .= "Va {$donde}.\n\n";
            $md .= "> {$texto}\n\n";
        }

        // 2. Las notas.
        $md .= "## 2. Las notas de reescritura\n\n";
        $md .= 'Las devuelve la comprobación del servidor (`ai/check`) cuando el borrador tiene un defecto que el modelo puede corregir reescribiendo, y n8n se las pasa al redactor en `critic_notes`, como ya hacía con las del revisor. ';
        $md .= 'Solo se pide la reescritura si queda presupuesto: como mucho dos reescrituras (tres borradores) y 25 s desde `decide`. ';
        $md .= 'Si hay varios defectos, van primero los cuatro de la forma (la lista, el trato, la longitud, el beneficio) y después los de la política, unidas por un espacio y cortadas a '.N::MAX_NOTES." caracteres por notas enteras. Si los intentos se agotan, sale el respaldo de siempre.\n\n";
        $md .= "Entre llaves, en la plantilla, lo que rellena el servidor; debajo, un ejemplo ya relleno.\n\n";
        $plantillas = [];
        foreach (N::entries() as [$code, $detail, , $plantilla]) {
            $plantillas[$detail === null ? $code : $code.'/'.$detail] = $plantilla;
        }
        $i = 0;
        foreach ($notas as $clave => [$titulo, $cuando, $params]) {
            [$code, $detail] = array_pad(explode('/', $clave, 2), 2, null);
            $ejemplo = N::note($code, $detail, $params);
            $this->assertDoesNotMatchRegularExpression('/[{}]/', $ejemplo, $clave);
            $md .= '### 2.'.(++$i).' '.$titulo.' (`'.$clave.'`, `'.N::issue($code, $detail)."`)\n\n";
            $md .= '**Cuándo sale:** '.mb_strtolower(mb_substr($cuando, 0, 1)).mb_substr($cuando, 1)."\n\n";
            if ($plantillas[$clave] !== $ejemplo) {
                $md .= "- **Plantilla:** «{$plantillas[$clave]}»\n";
            }
            $md .= "- **Texto** ({$this->medida($ejemplo)}): «{$ejemplo}»\n\n";
        }

        // 3. La expansión de la lista.
        $md .= "## 3. La expansión de la lista (lo que lee la persona)\n\n";
        $md .= 'Cuando el borrador nombra los planes de la lista con sus marcadores (`{{PLAN_1}}` a `{{PLAN_3}}`), el commit sustituye cada uno por el nombre y el precio del plan leídos del catálogo en ese momento. ';
        $md .= 'Si el plan es de un mes (28 a 31 días), sin duración; si no, con la duración en las palabras de la lista fija («7 días», «tres meses», «un año»…) y «1 día» en singular. ';
        $md .= "El nombre sale sin llaves (si alguien escribe una en el CRM, no se convierte en un marcador), y si lo que sale tiene aún forma de marcador, no sale: va el último recurso de siempre.\n\n";
        foreach (self::EJEMPLOS as [$nombre, $precio, $dias]) {
            $md .= '- '.$dias.' '.($dias === 1 ? 'día' : 'días').': «'.UltronPlansOverview::item(self::plan($nombre, $precio, $dias))."»\n";
        }
        $borrador = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}. ¿Cuál le interesa más?';
        $textos = [];
        foreach (self::EJEMPLOS as $k => [$nombre, $precio, $dias]) {
            $textos[$k + 1] = UltronPlansOverview::item(self::plan($nombre, $precio, $dias));
        }
        $expandido = UltronPlansOverview::expand($borrador, $textos, [1, 2, 3])['text'];
        $md .= "\nUn borrador entero, «{$borrador}», sale así ({$this->medida($expandido)}): «{$expandido}»\n";

        $doc = dirname(__DIR__, 4).'/'.self::DOC;
        if (getenv('A4_REGENERAR') === 'escribir') {
            file_put_contents($doc, $md);
        }
        $this->assertFileExists($doc);
        $this->assertSame((string) file_get_contents($doc), $md, 'los textos cambiaron: regenerar con A4_REGENERAR=escribir y volver a aprobarlos');
    }

    private function medida(string $texto): string
    {
        return mb_strlen($texto).' caracteres';
    }
}
