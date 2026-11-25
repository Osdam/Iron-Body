<?php

namespace App\Services\Marketing\Ultron\Prompts;

use App\Services\Marketing\Ultron\CriticContract;
use App\Services\Marketing\Ultron\UltronDraftCheck;
use App\Services\Marketing\Ultron\UltronDraftDefects;
use App\Services\Marketing\Ultron\UltronRegenerationNotes;
use App\Services\Marketing\Ultron\UltronRegenerationTrace;
use RuntimeException;

/**
 * EL FLUJO «S2» DE n8n, GENERADO (cierre V2, apartado 4, «regenerar antes de
 * sustituir»; plan `plan-arquitecto-a4-regenerar.md` §5 y §6 «Ola 5»).
 *
 * S2 = los 25 nodos de S1 (la salida de {@see UltronPromptsN8nFlow::build()},
 * la que se verifica hoy) + 14 nuevos, DESENROLLADO: ningún bucle. Entre el
 * redactor y el revisor, n8n le pregunta al servidor (`POST ai/check`, de solo
 * lectura, contrato `ultron-regen/1`) si aceptaría el borrador. Si el servidor
 * pide reescribir, el redactor de la reescritura recibe su nota donde hoy
 * recibe la del revisor (`critic_issues`, `critic_notes`): su `values[1]` no
 * se toca. Puede haber un tercer borrador («Composer Reintento 2»), que no se
 * comprueba pero sí lo juzga un revisor («Critic Comercial 3»). El tope de
 * tres redacciones está en la FORMA del grafo; no hay contador:
 *
 *   Composer → ¿Comprobar 1? ─no→ Critic Comercial
 *                └sí→ Comprobar Borrador 1 → Leer Comprobación 1 → ¿Reescribir 1? ─no→ Critic Comercial
 *                                                                    └sí→ Nota del Servidor 1 → Composer Reintento
 *   Critic Comercial → Critic aprueba? ─sí→ Preparar Commit · ─no→ Composer Reintento        (sin cambios)
 *   Composer Reintento → ¿Comprobar 2? ─no→ Critic Comercial 2
 *                └sí→ Comprobar Borrador 2 → Leer Comprobación 2 → ¿Reescribir 2? ─no→ Critic Comercial 2
 *                                                                    └sí→ Nota del Servidor 2 → Composer Reintento 2
 *   Critic Comercial 2 → Decidir Intento 3 → ¿Intento 3? ─no→ Preparar Commit
 *                                              └sí→ Composer Reintento 2 → Critic Comercial 3 → Preparar Commit
 *
 * SIN LA OFERTA (`decide` no devuelve `regeneration` con este contrato: un
 * turno Legacy o un servidor anterior), los dos «¿Comprobar k?» van por «no»,
 * «Decidir Intento 3» dice que no y el recorrido es el de S1: las mismas
 * llamadas al modelo, con los mismos datos, ninguna a `ai/check`, y Preparar
 * Commit con la misma salida, sin el bloque.
 *
 * Los 25 de S1 quedan IDÉNTICOS salvo:
 *  - «Elegir Prompts»: el selector de S1 con `FLOW_REV = "S2"`;
 *  - «Preparar Commit»: dos anclas ({@see self::commitCode()}): el tercer
 *    borrador, en su propio `try`, y el bloque `regeneration`, dentro de otro.
 *
 * Los IF solo miran un nodo que siempre corrió («Pedir Contexto al CRM») o un
 * booleano que acaba de calcular un Code; lo que mira nodos que pueden no
 * haber corrido va en Code con `try` (R9 del plan).
 *
 * PURA: no lee ficheros (salvo la base, por S1), no usa red ni reloj; las
 * mismas entradas dan los mismos bytes. La orden `ultron:prompts-n8n --rev=S2`
 * hace la E/S; el comprobador offline (`verificar-flujo.cjs --rev=S2`) lo
 * verifica. Nada de esto toca el n8n en marcha.
 */
final class UltronRegenN8nFlow
{
    /** La revisión del flujo que se observa en el sello (`prompts.flow_rev`). */
    public const FLOW_REV = 'S2';

    /** El contrato de la comprobación y del bloque del commit (§4). */
    public const CONTRACT = UltronDraftCheck::CONTRACT;

    /** Como mucho tres redacciones por turno (dos reescrituras). */
    public const MAX_DRAFTS = UltronDraftCheck::MAX_DRAFTS;

    /** Como mucho dos comprobaciones: el tercer borrador no se comprueba. */
    public const MAX_CHECKS = UltronRegenerationTrace::MAX_CHECKS;

    /** El tiempo máximo de cada comprobación, en ms; sin reintentos. */
    public const CHECK_TIMEOUT_MS = 3000;

    /** El nombre de la copia en n8n (la importa el lead, inactiva). */
    public const NAME = 'Iron Body - ULTRON - Commercial Advisor (Studio S2)';

    /** La copia S1 publicada hoy en n8n (plan H2): la reserva y la vuelta atrás de S2. */
    public const S1_COPY_ID = 'QSklqHlsDIxucRfk';

    /** S1: 24 nodos del respaldo + «Elegir Prompts». */
    public const S1_NODES = 25;

    /** La puerta de `decide` de S1 y la de la comprobación, en el mismo servidor. */
    public const DECIDE_URL = 'https://api.ironbodyneiva.cloud/api/internal/marketing/ai/decide';

    public const CHECK_URL = 'https://api.ironbodyneiva.cloud/api/internal/marketing/ai/check';

    /** Nodos de S1 que se leen. */
    public const EVENT = 'Normalizar Evento';

    public const STRATEGY = 'Validar Estrategia';

    public const CRITIC_GATE = 'Critic aprueba?';

    /** El IF de S1 del que se copia la forma de los IF nuevos (booleano, validación laxa). */
    public const IF_TEMPLATE = 'Rendirse?';

    // Los 14 nodos nuevos (§5.2).
    public const IF_CHECK_1 = '¿Comprobar 1?';

    public const CHECK_1 = 'Comprobar Borrador 1';

    public const READ_1 = 'Leer Comprobación 1';

    public const IF_REWRITE_1 = '¿Reescribir 1?';

    public const NOTE_1 = 'Nota del Servidor 1';

    public const IF_CHECK_2 = '¿Comprobar 2?';

    public const CHECK_2 = 'Comprobar Borrador 2';

    public const READ_2 = 'Leer Comprobación 2';

    public const IF_REWRITE_2 = '¿Reescribir 2?';

    public const NOTE_2 = 'Nota del Servidor 2';

    public const DECIDE_THIRD = 'Decidir Intento 3';

    public const IF_THIRD = '¿Intento 3?';

    public const COMPOSER_3 = 'Composer Reintento 2';

    public const CRITIC_3 = 'Critic Comercial 3';

    /**
     * Los 14, en el orden de §5.2, con su id fijo (el generador es
     * determinista) y su sitio en el lienzo (cosmético: carriles por encima y
     * por debajo de los de S1, que no se mueven).
     */
    public const NEW_NODES = [
        self::IF_CHECK_1 => ['5e1ec7a0-0002-4c0d-9a5e-000000000101', [192, -560]],
        self::CHECK_1 => ['5e1ec7a0-0002-4c0d-9a5e-000000000102', [384, -720]],
        self::READ_1 => ['5e1ec7a0-0002-4c0d-9a5e-000000000103', [576, -720]],
        self::IF_REWRITE_1 => ['5e1ec7a0-0002-4c0d-9a5e-000000000104', [768, -720]],
        self::NOTE_1 => ['5e1ec7a0-0002-4c0d-9a5e-000000000105', [960, -720]],
        self::IF_CHECK_2 => ['5e1ec7a0-0002-4c0d-9a5e-000000000106', [496, 576]],
        self::CHECK_2 => ['5e1ec7a0-0002-4c0d-9a5e-000000000107', [688, 768]],
        self::READ_2 => ['5e1ec7a0-0002-4c0d-9a5e-000000000108', [880, 768]],
        self::IF_REWRITE_2 => ['5e1ec7a0-0002-4c0d-9a5e-000000000109', [1072, 768]],
        self::NOTE_2 => ['5e1ec7a0-0002-4c0d-9a5e-000000000110', [1264, 768]],
        self::DECIDE_THIRD => ['5e1ec7a0-0002-4c0d-9a5e-000000000111', [992, 544]],
        self::IF_THIRD => ['5e1ec7a0-0002-4c0d-9a5e-000000000112', [1184, 544]],
        self::COMPOSER_3 => ['5e1ec7a0-0002-4c0d-9a5e-000000000113', [1456, 928]],
        self::CRITIC_3 => ['5e1ec7a0-0002-4c0d-9a5e-000000000114', [1680, 928]],
    ];

    /** Los ficheros de los Code nuevos (los dos «Leer Comprobación» difieren en su constante: dos ficheros). */
    public const CODE_FILES = [
        self::READ_1 => 'leer-comprobacion-1.js',
        self::READ_2 => 'leer-comprobacion-2.js',
        self::NOTE_1 => 'nota-del-servidor.js',
        self::NOTE_2 => 'nota-del-servidor.js',
        self::DECIDE_THIRD => 'decidir-intento-3.js',
    ];

    /** El IF de la oferta (§5.2, literal): solo nombra un nodo que siempre corrió. */
    public const IF_CHECK_EXPRESSION = "={{ \$('Pedir Contexto al CRM').first().json.regeneration ? \$('Pedir Contexto al CRM').first().json.regeneration.contract === 'ultron-regen/1' : false }}";

    /** Los IF de la reescritura y del tercer borrador: booleanos que acaba de calcular un Code. */
    public const IF_REWRITE_EXPRESSION = '={{ $json.ultron_check ? $json.ultron_check.rewrite === true : false }}';

    public const IF_THIRD_EXPRESSION = '={{ $json.ultron_intento3 === true }}';

    // Las anclas de Preparar Commit en S1 (cada una aparece una vez).
    private const ANCHOR_DRAFT = "} catch (e) { intento = 1; }\n";

    private const ANCHOR_RETURN = "} catch (e) { promptsAplicados = null; }\n\nreturn [{ json: Object.assign({\n";

    private const ANCHOR_END = "\n}, promptsAplicados ? { prompts: promptsAplicados } : {}) }];";

    private const END_S2 = "\n}, promptsAplicados ? { prompts: promptsAplicados } : {}, regeneracion ? { regeneration: regeneracion } : {}) }];";

    /** Lo que `rejected_draft` lee en Composer Reintento y en su copia. */
    private const REJECTED_FROM_1 = 'rejected_draft: $("Composer").first().json.output[0].content[0].text.reply_draft';

    private const REJECTED_FROM_2 = 'rejected_draft: $("Composer Reintento").first().json.output[0].content[0].text.reply_draft';

    /**
     * El cuerpo de «Comprobar Borrador k» (§4.1): SOLO las claves del contrato.
     * Los ids, de «Normalizar Evento» (los mismos que firmó `decide`); el
     * token, de «Pedir Contexto al CRM»; el borrador, del redactor k; la
     * intención, el plan y las herramientas, de la estrategia (lo mismo que
     * manda Preparar Commit en `proposal`, y el plan con su misma regla de
     * `undefined` → `null`).
     *
     * @throws RuntimeException si k no es 1 ni 2 (el tercer borrador no se comprueba)
     */
    public static function checkBody(int $k): string
    {
        $redactor = [1 => UltronPromptBase::NODES['composer'], 2 => UltronPromptBase::NODES['composer_retry']][$k]
            ?? throw new RuntimeException("Solo hay dos comprobaciones; no la {$k}.");
        $e = '$("'.self::EVENT.'").first().json';
        $d = '$("'.UltronPromptsN8nFlow::DECIDE.'").first().json';
        $s = '$("'.self::STRATEGY.'").first().json.strategy';

        return '={{ JSON.stringify({ conversation_id: '.$e.'.conversation_id, message_id: '.$e.'.message_id, decide_token: '.$d.'.decide_token, attempt: '.$k.', '
            .'draft: { reply_draft: $("'.$redactor.'").first().json.output[0].content[0].text.reply_draft, intent: '.$s.'.intent, '
            .'recommended_plan_id: '.$s.'.recommended_plan_id === undefined ? null : '.$s.'.recommended_plan_id, tools_requested: '.$s.'.tools_requested } }) }}';
    }

    /** El código de «Leer Comprobación k». */
    public static function readCode(int $k): string
    {
        $redactor = [1 => UltronPromptBase::NODES['composer'], 2 => UltronPromptBase::NODES['composer_retry']][$k]
            ?? throw new RuntimeException("Solo hay dos comprobaciones; no la {$k}.");

        return strtr(self::READ_TEMPLATE, self::vocabulario() + [
            '%%K%%' => (string) $k,
            '%%REDACTOR%%' => self::js($redactor),
        ]);
    }

    /** El código de las dos «Nota del Servidor» (el mismo). */
    public static function noteCode(): string
    {
        return self::NOTE_TEMPLATE;
    }

    /** El código de «Decidir Intento 3». */
    public static function thirdCode(): string
    {
        return strtr(self::THIRD_TEMPLATE, ['%%LEER_2%%' => self::js(self::READ_2)]);
    }

    /**
     * Preparar Commit de S2 = el de S1 con dos anclas:
     *  (i) detrás del borrador 2, el borrador 3 en su propio `try` (si no
     *      corrió o no trae texto, se queda el que había; nunca vuelve a 1);
     *  (ii) delante del `return`, el bloque `regeneration` dentro de un `try`
     *      (si algo falla, sin bloque, nunca sin respuesta), y el objeto
     *      devuelto lo gana solo si existe.
     *
     * @throws RuntimeException si el código de partida no es el de S1
     */
    public static function commitCode(string $s1): string
    {
        foreach ([self::ANCHOR_DRAFT, self::ANCHOR_RETURN, self::ANCHOR_END] as $ancla) {
            if (substr_count($s1, $ancla) !== 1) {
                throw new RuntimeException('Preparar Commit no es el de S1: no se encuentran sus anclas.');
            }
        }
        if (! str_ends_with($s1, self::ANCHOR_END)) {
            throw new RuntimeException('Preparar Commit no es el de S1: no termina en su return.');
        }

        $bloque = strtr(self::COMMIT_BLOCK_TEMPLATE, self::vocabulario() + [
            '%%REDACTORES%%' => self::js([UltronPromptBase::NODES['composer'], UltronPromptBase::NODES['composer_retry'], self::COMPOSER_3]),
            '%%REVISORES%%' => self::js([UltronPromptBase::NODES['critic'], UltronPromptBase::NODES['critic_retry'], self::CRITIC_3]),
            '%%MODELOS%%' => self::js([...array_values(UltronPromptBase::NODES), self::COMPOSER_3, self::CRITIC_3]),
            '%%LECTURAS%%' => self::js([self::READ_1, self::READ_2]),
            '%%LEER_1%%' => self::js(self::READ_1),
            '%%LEER_2%%' => self::js(self::READ_2),
            '%%COMPOSER_RETRY%%' => self::js(UltronPromptBase::NODES['composer_retry']),
            '%%COMPOSER_3%%' => self::js(self::COMPOSER_3),
            '%%CRITIC%%' => self::js(UltronPromptBase::NODES['critic']),
            '%%CRITIC_RETRY%%' => self::js(UltronPromptBase::NODES['critic_retry']),
            '%%MAX_DRAFTS%%' => (string) self::MAX_DRAFTS,
            '%%MAX_CRITICS%%' => (string) CriticContract::MAX_ATTEMPTS,
            '%%MAX_CHECKS%%' => (string) self::MAX_CHECKS,
            '%%MAX_TOKENS%%' => (string) UltronRegenerationTrace::MAX_TOKENS,
        ]);
        $tercero = strtr(self::THIRD_DRAFT_TEMPLATE, ['%%COMPOSER_3%%' => self::js(self::COMPOSER_3)]);

        $c = str_replace(self::ANCHOR_DRAFT, self::ANCHOR_DRAFT.$tercero, $s1);
        $c = str_replace(self::ANCHOR_RETURN, "} catch (e) { promptsAplicados = null; }\n\n".$bloque."\nreturn [{ json: Object.assign({\n", $c);

        return substr($c, 0, -strlen(self::ANCHOR_END)).self::END_S2;
    }

    /**
     * Todo S2 a partir de S1.
     *
     * @param  array<string,mixed>  $s1  el flujo S1 generado (`UltronPromptsN8nFlow::build()['flow']`)
     * @return array{flow:array<string,mixed>,hashes:array<string,mixed>,code:array<string,string>,fixtures:array<string,array<string,mixed>>}
     *
     * @throws RuntimeException si `$s1` no es el S1 que se genera hoy
     * @throws UltronPromptException si la base no se puede cargar
     */
    public static function build(array $s1): array
    {
        $nodos = self::assertS1($s1);
        $selector = UltronPromptsN8nFlow::selectorCode(self::FLOW_REV);
        $commit = self::commitCode((string) $nodos[UltronPromptsN8nFlow::COMMIT]['parameters']['jsCode']);

        $flujo = $s1;
        foreach ($flujo['nodes'] as $i => $nodo) {
            if ($nodo['name'] === UltronPromptsN8nFlow::SELECTOR) {
                $flujo['nodes'][$i]['parameters']['jsCode'] = $selector;
            } elseif ($nodo['name'] === UltronPromptsN8nFlow::COMMIT) {
                $flujo['nodes'][$i]['parameters']['jsCode'] = $commit;
            }
        }

        $code = [
            self::READ_1 => self::readCode(1),
            self::READ_2 => self::readCode(2),
            self::NOTE_1 => self::noteCode(),
            self::NOTE_2 => self::noteCode(),
            self::DECIDE_THIRD => self::thirdCode(),
        ];
        $decide = $nodos[UltronPromptsN8nFlow::DECIDE];
        $plantillaIf = $nodos[self::IF_TEMPLATE];
        $composerRetry = $nodos[UltronPromptBase::NODES['composer_retry']];
        $criticRetry = $nodos[UltronPromptBase::NODES['critic_retry']];

        $nuevos = [
            self::ifNode(self::IF_CHECK_1, self::IF_CHECK_EXPRESSION, $plantillaIf),
            self::checkNode(self::CHECK_1, 1, $decide),
            self::codeNode(self::READ_1, $code[self::READ_1]),
            self::ifNode(self::IF_REWRITE_1, self::IF_REWRITE_EXPRESSION, $plantillaIf),
            self::codeNode(self::NOTE_1, $code[self::NOTE_1]),
            self::ifNode(self::IF_CHECK_2, self::IF_CHECK_EXPRESSION, $plantillaIf),
            self::checkNode(self::CHECK_2, 2, $decide),
            self::codeNode(self::READ_2, $code[self::READ_2]),
            self::ifNode(self::IF_REWRITE_2, self::IF_REWRITE_EXPRESSION, $plantillaIf),
            self::codeNode(self::NOTE_2, $code[self::NOTE_2]),
            self::codeNode(self::DECIDE_THIRD, $code[self::DECIDE_THIRD]),
            self::ifNode(self::IF_THIRD, self::IF_THIRD_EXPRESSION, $plantillaIf),
            self::copyNode(self::COMPOSER_3, $composerRetry, self::REJECTED_FROM_1, self::REJECTED_FROM_2),
            self::copyNode(self::CRITIC_3, $criticRetry),
        ];
        array_push($flujo['nodes'], ...$nuevos);

        $flujo['connections'] = self::connections($s1['connections']);
        $flujo['id'] = null;
        $flujo['name'] = self::NAME;
        $flujo['active'] = false;
        $flujo['versionId'] = null;
        $flujo['updatedAt'] = null;
        $flujo['_generado'] = [
            'from' => UltronPromptsN8nFlow::FLOW_REV, 'fromVersion' => UltronPromptsN8nFlow::SOURCE_VERSION, 'flowRev' => self::FLOW_REV,
            'base' => UltronPromptBase::ID, 'compiler' => UltronPromptsN8nFlow::COMPILER, 'contract' => self::CONTRACT,
            'nota' => 'Para el comprobador y para importarlo como copia NUEVA e INACTIVA (id, versionId y sin _generado: ops/motores/n8n-copia-s2/LEEME.md). Nunca encima de un flujo publicado ni como vuelta atrás.',
        ];

        return [
            'flow' => $flujo,
            'hashes' => self::hashes($flujo, $selector, $commit, $code),
            'code' => ['preparar-commit.js' => $commit] + array_combine(array_map(fn (string $n) => self::CODE_FILES[$n], array_keys($code)), array_values($code)),
            'fixtures' => self::fixtures(),
        ];
    }

    /**
     * Los fixtures del comprobador y del ensayo en n8n con datos fijados:
     * respuestas sintéticas (sin datos de nadie) de `decide` con y sin Studio,
     * y salidas del nodo HTTP de la comprobación (`fullResponse`) para cada
     * caso: pasa, pide reescritura, legacy, 404, 422, error de red y mal
     * formada. Las notas son las del servidor, compuestas con
     * {@see UltronRegenerationNotes} (las mismas piezas que `ai/check`).
     *
     * @return array<string,array<string,mixed>> nombre del fichero (sin `.json`) → contenido
     *
     * @throws UltronPromptException
     */
    public static function fixtures(): array
    {
        $s1 = UltronPromptsN8nFlow::fixtures();
        $turno = [
            'source' => ['source_type' => 'inbound_message', 'source_event_id' => 22, 'idempotency_key' => 'wamid.sintetico'],
            'context' => [
                'default_plan_id' => 3,
                'active_plans' => [['id' => 3]],
                'commercial_turn_policy' => ['intent' => 'general_info', 'active_goal' => null, 'resume_goal_after_answer' => false, 'price_verification' => null, 'reception_mode' => false, 'memory_mode' => false, 'greet_required' => false],
                'strategy_hints' => ['suggested_question' => null],
            ],
        ];

        $tuteo = ['code' => UltronRegenerationNotes::REGISTER_TUTEO, 'detail' => null, 'issue' => UltronRegenerationNotes::issue(UltronRegenerationNotes::REGISTER_TUTEO),
            'note' => UltronRegenerationNotes::note(UltronRegenerationNotes::REGISTER_TUTEO, null, ['MARCAS' => ['te']])];
        $largo = ['code' => UltronRegenerationNotes::LENGTH_OVER_CAP, 'detail' => null, 'issue' => UltronRegenerationNotes::issue(UltronRegenerationNotes::LENGTH_OVER_CAP),
            'note' => UltronRegenerationNotes::note(UltronRegenerationNotes::LENGTH_OVER_CAP, null, ['L' => 241, 'N' => 200, 'M' => UltronPromptCompiler::composerTargetChars(200)])];
        $json = ['content-type' => 'application/json'];
        $ok = fn (array $cuerpo): array => ['body' => $cuerpo, 'headers' => $json, 'statusCode' => 200, 'statusMessage' => 'OK'];

        return [
            'decide-studio' => $s1['studio'] + $turno + ['regeneration' => ['contract' => self::CONTRACT, 'max_drafts' => self::MAX_DRAFTS]],
            'decide-legacy' => $s1['legacy'] + $turno,
            'check-pasa' => $ok(self::respuesta(UltronDraftCheck::MODE_STUDIO, 1, [], true, ['max_drafts' => self::MAX_DRAFTS, 'elapsed_s' => 7, 'reason' => null])),
            'check-reescribe' => $ok(self::respuesta(UltronDraftCheck::MODE_STUDIO, 1, [$tuteo, $largo], true, ['max_drafts' => self::MAX_DRAFTS, 'elapsed_s' => 9, 'reason' => null])),
            'check-legacy' => $ok(self::respuesta(UltronDraftCheck::MODE_LEGACY, 1, [], false, ['max_drafts' => UltronDraftCheck::LEGACY_MAX_DRAFTS, 'elapsed_s' => null, 'reason' => UltronDraftCheck::REASON_LEGACY])),
            'check-404' => ['body' => ['message' => 'Not Found'], 'headers' => $json, 'statusCode' => 404, 'statusMessage' => 'Not Found'],
            'check-422' => ['body' => ['ok' => false, 'code' => 'invalid_token'], 'headers' => $json, 'statusCode' => 422, 'statusMessage' => 'Unprocessable Content'],
            // Lo que deja el nodo HTTP con `onError: continueRegularOutput` ante el tiempo agotado o la conexión caída [S: la forma exacta la fija el ensayo].
            'check-error-de-red' => ['error' => ['message' => 'timeout of '.self::CHECK_TIMEOUT_MS.'ms exceeded', 'name' => 'AxiosError', 'code' => 'ECONNABORTED']],
            // Un 200 que no es del contrato: le faltan claves y `rewrite` es texto.
            'check-mal-formada' => $ok(['ok' => true, 'contract' => self::CONTRACT, 'mode' => UltronDraftCheck::MODE_STUDIO, 'attempt' => 1, 'rewrite' => 'true', 'notes' => $tuteo['note']]),
        ];
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * La respuesta 200 de `ai/check`, con su forma y en su orden (la de
     * `UltronDraftCheck::respuesta()`, que es privada): cada defecto sin su
     * nota, las notas unidas con `merge()` y los `issues` del vocabulario.
     *
     * @param  list<array{code:string,detail:?string,issue:string,note:string}>  $defectos
     * @param  array{max_drafts:int,elapsed_s:?int,reason:?string}  $budget
     * @return array<string,mixed>
     */
    private static function respuesta(string $mode, int $attempt, array $defectos, bool $mayRewrite, array $budget): array
    {
        return [
            'ok' => true,
            'contract' => self::CONTRACT,
            'mode' => $mode,
            'attempt' => $attempt,
            'pass' => $defectos === [],
            'defects' => array_map(fn (array $d): array => ['code' => $d['code'], 'detail' => $d['detail'], 'issue' => $d['issue']], $defectos),
            'issues' => CriticContract::issues(array_column($defectos, 'issue')),
            'notes' => UltronRegenerationNotes::merge(array_column($defectos, 'note')),
            'may_rewrite' => $mayRewrite,
            'rewrite' => $defectos !== [] && $mayRewrite,
            'budget' => $budget,
        ];
    }

    /**
     * S1 tiene que ser el que se genera hoy: si no, S2 no se puede generar
     * desde él.
     *
     * @param  array<string,mixed>  $s1
     * @return array<string,array<string,mixed>> los nodos por nombre
     */
    private static function assertS1(array $s1): array
    {
        if (! isset($s1['nodes'], $s1['connections']) || ! is_array($s1['nodes']) || ! is_array($s1['connections'])) {
            throw new RuntimeException('S1 no tiene nodes y connections en el primer nivel.');
        }
        if (($s1['_generado']['flowRev'] ?? null) !== UltronPromptsN8nFlow::FLOW_REV) {
            throw new RuntimeException('El flujo de partida no es el S1 generado.');
        }
        $nodos = [];
        foreach ($s1['nodes'] as $nodo) {
            $nodos[(string) ($nodo['name'] ?? '')] = $nodo;
        }
        if (count($s1['nodes']) !== self::S1_NODES || count($nodos) !== self::S1_NODES) {
            throw new RuntimeException('S1 no tiene sus '.self::S1_NODES.' nodos.');
        }
        foreach (array_keys(self::NEW_NODES) as $nuevo) {
            if (isset($nodos[$nuevo])) {
                throw new RuntimeException("S1 ya tiene «{$nuevo}».");
            }
        }
        if (($nodos[UltronPromptsN8nFlow::SELECTOR]['parameters']['jsCode'] ?? null) !== UltronPromptsN8nFlow::selectorCode()) {
            throw new RuntimeException('El selector de S1 no es el que se genera hoy.');
        }
        foreach (UltronPromptBase::NODES as $rol => $nombre) {
            if (($nodos[$nombre]['parameters']['responses']['values'][0]['content'] ?? null) !== UltronPromptsN8nFlow::systemExpression($rol)) {
                throw new RuntimeException("{$nombre} no lee su rol del selector.");
            }
        }
        if (substr_count((string) ($nodos[UltronPromptBase::NODES['composer_retry']]['parameters']['responses']['values'][1]['content'] ?? ''), self::REJECTED_FROM_1) !== 1) {
            throw new RuntimeException('Composer Reintento no lee el borrador rechazado de Composer.');
        }
        $decide = $nodos[UltronPromptsN8nFlow::DECIDE] ?? [];
        if (($decide['parameters']['url'] ?? null) !== self::DECIDE_URL || ! isset($decide['credentials']['httpHeaderAuth']['id'], $decide['credentials']['httpHeaderAuth']['name'])) {
            throw new RuntimeException('«'.UltronPromptsN8nFlow::DECIDE.'» no es la puerta de siempre con su credencial.');
        }
        $plantilla = $nodos[self::IF_TEMPLATE]['parameters']['conditions']['conditions'] ?? null;
        if (! is_array($plantilla) || count($plantilla) !== 1 || ($plantilla[0]['operator'] ?? null) !== ['type' => 'boolean', 'operation' => 'true', 'singleValue' => true]) {
            throw new RuntimeException('«'.self::IF_TEMPLATE.'» no es el IF booleano de S1.');
        }
        $c = $s1['connections'];
        $a = fn (string $n): array => [['node' => $n, 'type' => 'main', 'index' => 0]];
        $esperadas = [
            UltronPromptBase::NODES['composer'] => [$a(UltronPromptBase::NODES['critic'])],
            UltronPromptBase::NODES['composer_retry'] => [$a(UltronPromptBase::NODES['critic_retry'])],
            UltronPromptBase::NODES['critic_retry'] => [$a(UltronPromptsN8nFlow::COMMIT)],
            self::CRITIC_GATE => [$a(UltronPromptsN8nFlow::COMMIT), $a(UltronPromptBase::NODES['composer_retry'])],
        ];
        foreach ($esperadas as $origen => $salidas) {
            if (($c[$origen]['main'] ?? null) !== $salidas) {
                throw new RuntimeException("Las conexiones de «{$origen}» no son las de S1.");
            }
        }

        return $nodos;
    }

    /**
     * Las conexiones de S1 con los cambios de §5.1, y nada más: las tres
     * salidas de S1 que cambian se reescriben en su sitio y las de los nodos
     * nuevos van detrás, en el orden de §5.2.
     *
     * @param  array<string,mixed>  $s1
     * @return array<string,mixed>
     */
    private static function connections(array $s1): array
    {
        $a = fn (string ...$n): array => array_map(fn (string $x): array => ['node' => $x, 'type' => 'main', 'index' => 0], $n);
        $composer = UltronPromptBase::NODES['composer'];
        $critic = UltronPromptBase::NODES['critic'];
        $retry = UltronPromptBase::NODES['composer_retry'];
        $critic2 = UltronPromptBase::NODES['critic_retry'];
        $commit = UltronPromptsN8nFlow::COMMIT;

        $c = $s1;
        $c[$composer] = ['main' => [$a(self::IF_CHECK_1)]];
        $c[$retry] = ['main' => [$a(self::IF_CHECK_2)]];
        $c[$critic2] = ['main' => [$a(self::DECIDE_THIRD)]];

        return $c + [
            self::IF_CHECK_1 => ['main' => [$a(self::CHECK_1), $a($critic)]],
            self::CHECK_1 => ['main' => [$a(self::READ_1)]],
            self::READ_1 => ['main' => [$a(self::IF_REWRITE_1)]],
            self::IF_REWRITE_1 => ['main' => [$a(self::NOTE_1), $a($critic)]],
            self::NOTE_1 => ['main' => [$a($retry)]],
            self::IF_CHECK_2 => ['main' => [$a(self::CHECK_2), $a($critic2)]],
            self::CHECK_2 => ['main' => [$a(self::READ_2)]],
            self::READ_2 => ['main' => [$a(self::IF_REWRITE_2)]],
            self::IF_REWRITE_2 => ['main' => [$a(self::NOTE_2), $a($critic2)]],
            self::NOTE_2 => ['main' => [$a(self::COMPOSER_3)]],
            self::DECIDE_THIRD => ['main' => [$a(self::IF_THIRD)]],
            self::IF_THIRD => ['main' => [$a(self::COMPOSER_3), $a($commit)]],
            self::COMPOSER_3 => ['main' => [$a(self::CRITIC_3)]],
            self::CRITIC_3 => ['main' => [$a($commit)]],
        ];
    }

    /**
     * Un IF nuevo con la forma exacta del de S1 (booleano «es verdadero»,
     * validación laxa), cambiando solo la expresión.
     *
     * @param  array<string,mixed>  $plantilla
     * @return array<string,mixed>
     */
    private static function ifNode(string $name, string $expression, array $plantilla): array
    {
        $parametros = $plantilla['parameters'];
        $parametros['conditions']['conditions'][0]['leftValue'] = $expression;

        return ['parameters' => $parametros] + self::cabecera($name, 'n8n-nodes-base.if', 2.3);
    }

    /**
     * «Comprobar Borrador k»: la misma puerta y la MISMA credencial que
     * `decide` (por referencia: id y nombre de n8n, nunca el secreto), 3 s,
     * sin reintentos (ni `retryOnFail` ni `maxTries`), con la respuesta
     * entera y sin convertir los 4xx/5xx en error (`fullResponse` +
     * `neverError`), y sin cortar el flujo si la red falla
     * (`continueRegularOutput`): el Code de detrás lo lee como «no reescribir».
     *
     * @param  array<string,mixed>  $decide
     * @return array<string,mixed>
     */
    private static function checkNode(string $name, int $k, array $decide): array
    {
        $parametros = $decide['parameters'];
        $parametros['url'] = self::CHECK_URL;
        $parametros['jsonBody'] = self::checkBody($k);
        $parametros['options'] = ['response' => ['response' => ['fullResponse' => true, 'neverError' => true]], 'timeout' => self::CHECK_TIMEOUT_MS];

        return ['parameters' => $parametros] + self::cabecera($name, 'n8n-nodes-base.httpRequest', 4.4)
            + ['credentials' => $decide['credentials'], 'onError' => 'continueRegularOutput'];
    }

    /** @return array<string,mixed> */
    private static function codeNode(string $name, string $code): array
    {
        return ['parameters' => ['jsCode' => $code]] + self::cabecera($name, 'n8n-nodes-base.code', 2);
    }

    /**
     * Una copia EXACTA de un nodo de OpenAI de S1 (modelo, opciones,
     * reintentos, credencial y rol de sistema): solo cambian el nombre, el id,
     * la posición y, si se pide, una cadena del `values[1]` (una vez).
     *
     * @param  array<string,mixed>  $original
     * @return array<string,mixed>
     */
    private static function copyNode(string $name, array $original, ?string $antes = null, ?string $despues = null): array
    {
        $copia = $original;
        if ($antes !== null) {
            $usuario = (string) $copia['parameters']['responses']['values'][1]['content'];
            if (substr_count($usuario, $antes) !== 1) {
                throw new RuntimeException("El values[1] de «{$original['name']}» no lleva una vez lo que cambia en «{$name}».");
            }
            $copia['parameters']['responses']['values'][1]['content'] = str_replace($antes, (string) $despues, $usuario);
        }
        [$id, $posicion] = self::NEW_NODES[$name];
        $copia['id'] = $id;
        $copia['name'] = $name;
        $copia['position'] = $posicion;

        return $copia;
    }

    /** @return array{id:string,name:string,type:string,typeVersion:int|float,position:array{int,int}} */
    private static function cabecera(string $name, string $type, int|float $version): array
    {
        [$id, $posicion] = self::NEW_NODES[$name];

        return ['id' => $id, 'name' => $name, 'type' => $type, 'typeVersion' => $version, 'position' => $posicion];
    }

    /**
     * @param  array<string,mixed>  $flujo
     * @param  array<string,string>  $code
     * @return array<string,mixed>
     */
    private static function hashes(array $flujo, string $selector, string $commit, array $code): array
    {
        $nodos = array_column($flujo['nodes'], null, 'name');
        $enlaces = 0;
        foreach ($flujo['connections'] as $salidas) {
            foreach ($salidas['main'] as $destinos) {
                $enlaces += count($destinos);
            }
        }
        $modelos = [...array_values(UltronPromptBase::NODES), self::COMPOSER_3, self::CRITIC_3];
        $usuario = [];
        $sistema = [];
        foreach ($modelos as $m) {
            $usuario[$m] = hash('sha256', (string) $nodos[$m]['parameters']['responses']['values'][1]['content']);
            $sistema[$m] = (string) $nodos[$m]['parameters']['responses']['values'][0]['content'];
        }
        $codigo = [];
        foreach ($code as $nombre => $js) {
            $codigo[$nombre] = ['file' => self::CODE_FILES[$nombre], 'sha256' => hash('sha256', $js), 'bytes' => strlen($js)];
        }

        return [
            'flowRev' => self::FLOW_REV,
            'name' => self::NAME,
            'from' => ['flowRev' => UltronPromptsN8nFlow::FLOW_REV, 'workflowId' => UltronPromptsN8nFlow::WORKFLOW_ID, 'sourceVersionId' => UltronPromptsN8nFlow::SOURCE_VERSION],
            'contract' => self::CONTRACT,
            'maxDrafts' => self::MAX_DRAFTS,
            'maxChecks' => self::MAX_CHECKS,
            'checkTimeoutMs' => self::CHECK_TIMEOUT_MS,
            'checkRetries' => 0,
            'nodes' => [
                'total' => count($flujo['nodes']),
                's1' => self::S1_NODES,
                'new' => count(self::NEW_NODES),
                'withCredentials' => count(array_filter($flujo['nodes'], fn (array $n): bool => isset($n['credentials']))),
                'newNames' => array_keys(self::NEW_NODES),
            ],
            'connections' => ['sources' => count($flujo['connections']), 'links' => $enlaces],
            'selector' => ['name' => UltronPromptsN8nFlow::SELECTOR, 'id' => UltronPromptsN8nFlow::SELECTOR_ID, 'flowRev' => self::FLOW_REV, 'jsCodeSha256' => hash('sha256', $selector), 'jsCodeBytes' => strlen($selector)],
            'preparaCommitJsCodeSha256' => hash('sha256', $commit),
            'code' => $codigo,
            'check' => ['url' => self::CHECK_URL, 'jsonBody' => [self::CHECK_1 => self::checkBody(1), self::CHECK_2 => self::checkBody(2)]],
            'systemContent' => $sistema,
            'userContentSha256' => $usuario,
            'vocabulary' => ['codes' => UltronRegenerationNotes::CODES, 'dimensions' => count(CriticContract::DIMENSIONS)],
        ];
    }

    /**
     * El vocabulario cerrado del servidor, embebido en los Code: lo que no es
     * de él no viaja al commit (que lo rechazaría con un 422 y mandaría el
     * turno a la rendición por una traza).
     *
     * @return array<string,string>
     */
    private static function vocabulario(): array
    {
        return [
            '%%CONTRACT%%' => self::js(self::CONTRACT),
            '%%CODES%%' => self::js(UltronRegenerationNotes::CODES),
            '%%DIMENSIONS%%' => self::js(CriticContract::DIMENSIONS),
            '%%MAX_CODES%%' => (string) UltronDraftDefects::MAX_DEFECTS,
            '%%MAX_ISSUES%%' => (string) CriticContract::MAX_ISSUES,
            '%%MAX_NOTES%%' => (string) CriticContract::MAX_NOTES,
        ];
    }

    /** Un literal de JavaScript (JSON es un subconjunto válido). */
    private static function js(mixed $valor): string
    {
        return json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    // ── El código de los nodos (JavaScript, Code v2, una vez para todos los ítems) ──

    private const READ_TEMPLATE = <<<'JS'
/*
 * LEER COMPROBACION %%K%% — ULTRON STUDIO S2 («regenerar antes de sustituir»,
 * plan §5.2). GENERADO por `php artisan ultron:prompts-n8n --rev=S2`. NO SE
 * EDITA A MANO.
 *
 * Lee lo que contesto ai/check sobre el borrador %%K%% y devuelve EL MISMO
 * objeto del redactor (el revisor sigue leyendo el borrador en $json.output)
 * mas ultron_check = {rewrite, may_rewrite, error, codes, issues, notes}.
 * Solo un 200 bien formado del contrato puede pedir una reescritura; cualquier
 * otra cosa (404, 422, 5xx, red, tiempo agotado, otra forma) es error y NO
 * reescribir: el camino de hoy. Lo que no es del vocabulario del servidor no
 * pasa. Leer la comprobacion nunca lanza.
 */
const K = %%K%%, REDACTOR = %%REDACTOR%%, CONTRACT = %%CONTRACT%%;
const CODES = %%CODES%%;
const DIMENSIONS = %%DIMENSIONS%%;
const MAX_CODES = %%MAX_CODES%%, MAX_ISSUES = %%MAX_ISSUES%%, MAX_NOTES = %%MAX_NOTES%%;

function noReescribir() {
  return { rewrite: false, may_rewrite: false, error: true, codes: [], issues: [], notes: "" };
}

function unicos(lista, vocabulario, tope) {
  const out = [];
  for (const x of Array.isArray(lista) ? lista : []) {
    if (typeof x === "string" && vocabulario.includes(x) && !out.includes(x)) out.push(x);
  }
  return out.slice(0, tope);
}

function leer(r) {
  if (!r || typeof r !== "object" || r.statusCode !== 200) return noReescribir();
  let b = r.body;
  if (typeof b === "string") { try { b = JSON.parse(b); } catch (e) { return noReescribir(); } }
  const forma = !!b && typeof b === "object" && !Array.isArray(b)
    && b.ok === true && b.contract === CONTRACT && (b.mode === "studio" || b.mode === "legacy") && b.attempt === K
    && typeof b.pass === "boolean" && Array.isArray(b.defects) && Array.isArray(b.issues)
    && typeof b.notes === "string" && typeof b.may_rewrite === "boolean" && typeof b.rewrite === "boolean";
  if (!forma) return noReescribir();
  const codes = unicos(b.defects.map(d => d && d.code), CODES, MAX_CODES);
  const issues = unicos(b.issues, DIMENSIONS, MAX_ISSUES);
  const notes = b.notes.trim().slice(0, MAX_NOTES);
  const puede = b.mode === "studio" && b.may_rewrite === true;
  if (b.rewrite !== true) return { rewrite: false, may_rewrite: puede, error: false, codes, issues, notes };
  // Reescribir sin presupuesto, sin defectos o sin nota no es del contrato: no se reescribe.
  if (!puede || b.defects.length === 0 || notes === "") return noReescribir();
  return { rewrite: true, may_rewrite: true, error: false, codes, issues, notes };
}

// El redactor siempre corrio: este nodo va detras de el.
const redactor = $(REDACTOR).first().json;
let check = noReescribir();
try { check = leer($input.first().json); } catch (e) { check = noReescribir(); }
return [{ json: Object.assign({}, redactor, { ultron_check: check }) }];
JS;

    private const NOTE_TEMPLATE = <<<'JS'
/*
 * NOTA DEL SERVIDOR — ULTRON STUDIO S2 («regenerar antes de sustituir», plan
 * §5.2). GENERADO por `php artisan ultron:prompts-n8n --rev=S2`. NO SE EDITA A
 * MANO.
 *
 * ai/check pidio reescribir: se emite con la FORMA DEL REVISOR, para que el
 * redactor de la reescritura lea critic_issues y critic_notes de
 * $json.output[0].content[0].text sin tocar su values[1]. by y codes son
 * traza: el redactor no los recibe.
 */
let c = {};
try {
  const j = ($input.first() && $input.first().json) || {};
  c = (j.ultron_check && typeof j.ultron_check === "object") ? j.ultron_check : {};
} catch (e) { c = {}; }
return [{ json: { output: [{ content: [{ text: {
  verdict: "fail",
  score: null,
  issues: Array.isArray(c.issues) ? c.issues : [],
  notes: typeof c.notes === "string" ? c.notes : "",
  hard_fail: null,
  by: "server",
  codes: Array.isArray(c.codes) ? c.codes : []
} }] }] } }];
JS;

    private const THIRD_TEMPLATE = <<<'JS'
/*
 * DECIDIR INTENTO 3 — ULTRON STUDIO S2 («regenerar antes de sustituir», plan
 * §5.2). GENERADO por `php artisan ultron:prompts-n8n --rev=S2`. NO SE EDITA A
 * MANO.
 *
 * Entra el veredicto de «Critic Comercial 2» y sale TAL CUAL (Preparar Commit
 * y el redactor del tercer borrador lo leen de $json.output), mas
 * ultron_intento3: un tercer borrador solo si ese revisor lo rechazo y la
 * comprobacion 2 dijo, sin error, que todavia queda presupuesto. Sin la oferta
 * (Legacy) la comprobacion 2 no corrio y es false: el camino de hoy. Nunca
 * lanza: si algo falla aqui, false.
 */
function sacar(j) {
  const o = j && j.output;
  if (o === undefined || o === null) return j;
  if (Array.isArray(o)) {
    const c = o[0] && o[0].content && o[0].content[0];
    let t = c ? c.text : undefined;
    if (typeof t === "string") { try { t = JSON.parse(t); } catch (e) {} }
    return t;
  }
  if (typeof o === "string") { try { return JSON.parse(o); } catch (e) { return o; } }
  return o;
}

let entrada = {};
try { entrada = ($input.first() && $input.first().json) || {}; } catch (e) { entrada = {}; }

let intento3 = false;
try {
  const critic = sacar(entrada);
  const falla = !!critic && typeof critic === "object"
    && (critic.verdict === "fail" || (typeof critic.hard_fail === "string" && critic.hard_fail !== ""));
  if (falla) {
    const l = $(%%LEER_2%%).first();
    const c = l && l.json ? l.json.ultron_check : null;
    intento3 = !!c && c.may_rewrite === true && c.error === false;
  }
} catch (e) { intento3 = false; }

return [{ json: Object.assign({}, entrada, { ultron_intento3: intento3 }) }];
JS;

    private const THIRD_DRAFT_TEMPLATE = <<<'JS'

/*
 * ULTRON STUDIO S2 («regenerar antes de sustituir»): el tercer borrador, en su
 * propio try. Si no corrio o no trae texto, se queda el que habia: nunca vuelve
 * al primero.
 */
try {
  const r3 = $(%%COMPOSER_3%%).first();
  const alt3 = r3 && r3.json ? sacar(r3.json) : null;
  if (alt3 && alt3.reply_draft) { intento = 3; draft = alt3.reply_draft; }
} catch (e) { /* no corrio: se queda el borrador de antes */ }

JS;

    private const COMMIT_BLOCK_TEMPLATE = <<<'JS'
/*
 * ULTRON STUDIO S2 («regenerar antes de sustituir», plan §4.3 y §5.2): lo que
 * este flujo dice haber hecho para el borrador. Solo si decide ofrecio el
 * contrato ultron-regen/1 (un backend sin la oferta no recibe un bloque que no
 * entenderia). Es traza: si algo falla aqui, el commit sale sin bloque, nunca
 * sin respuesta. Solo codigos y numeros del vocabulario cerrado del servidor:
 * ningun texto.
 */
let regeneracion = null;
try {
  const rg = ctx.regeneration;
  if (rg && rg.contract === %%CONTRACT%%) {
    const CODES = %%CODES%%;
    const DIMENSIONS = %%DIMENSIONS%%;
    const MAX_CODES = %%MAX_CODES%%, MAX_ISSUES = %%MAX_ISSUES%%, MAX_TOKENS = %%MAX_TOKENS%%;
    const corrio = n => { try { const x = $(n).first(); return x && x.json ? x.json : null; } catch (e) { return null; } };
    const unicos = (lista, vocabulario, tope) => {
      const out = [];
      for (const x of Array.isArray(lista) ? lista : []) { if (typeof x === "string" && vocabulario.includes(x) && !out.includes(x)) out.push(x); }
      return out.slice(0, tope);
    };
    const cuantos = nombres => nombres.filter(n => corrio(n) !== null).length;
    // La reescritura k la pidio el servidor si «Leer Comprobacion k» dijo rewrite; si no, el revisor anterior.
    const quien = (lectura, revisor) => {
      const l = corrio(lectura);
      const c = l ? l.ultron_check : null;
      if (c && c.rewrite === true) return { by: "server", codes: unicos(c.codes, CODES, MAX_CODES), issues: unicos(c.issues, DIMENSIONS, MAX_ISSUES) };
      const r = corrio(revisor);
      const v = r ? sacar(r) : null;
      return { by: "critic", codes: [], issues: unicos(v && v.issues, DIMENSIONS, MAX_ISSUES) };
    };
    const lecturas = %%LECTURAS%%.map(corrio).filter(j => j !== null).map(j => j.ultron_check);
    const reescrituras = [];
    if (corrio(%%COMPOSER_RETRY%%) !== null) reescrituras.push(quien(%%LEER_1%%, %%CRITIC%%));
    if (corrio(%%COMPOSER_3%%) !== null) reescrituras.push(quien(%%LEER_2%%, %%CRITIC_RETRY%%));
    // Los tokens, solo si TODOS los nodos de OpenAI que corrieron los entregan: una suma a medias diria menos de lo que costo.
    const modelos = %%MODELOS%%.map(corrio).filter(j => j !== null);
    const entero = v => Number.isInteger(v) && v >= 0;
    let tokens = null;
    if (modelos.length > 0 && modelos.every(j => j.usage && entero(j.usage.input_tokens) && entero(j.usage.output_tokens))) {
      const enviados = modelos.reduce((s, j) => s + j.usage.input_tokens, 0);
      const recibidos = modelos.reduce((s, j) => s + j.usage.output_tokens, 0);
      if (enviados <= MAX_TOKENS && recibidos <= MAX_TOKENS) tokens = { input: enviados, output: recibidos };
    }
    const bloque = {
      contract: %%CONTRACT%%,
      drafts: cuantos(%%REDACTORES%%),
      critics: cuantos(%%REVISORES%%),
      checks: lecturas.length,
      check_errors: lecturas.filter(c => !c || c.error !== false).length,
      rewrites: reescrituras,
      tokens: tokens
    };
    // Fuera de su rango no se manda: el commit lo rechazaria con un 422, y esto es solo traza.
    if (bloque.drafts >= 1 && bloque.drafts <= %%MAX_DRAFTS%% && bloque.critics <= %%MAX_CRITICS%% && bloque.checks <= %%MAX_CHECKS%%
      && bloque.rewrites.length === bloque.drafts - 1) {
      regeneracion = bloque;
    }
  }
} catch (e) { regeneracion = null; }

JS;
}
