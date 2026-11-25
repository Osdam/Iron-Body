<?php

namespace App\Services\UltronStudio;

use InvalidArgumentException;

/**
 * UN CASO DEL RECORRIDO DEL MOTOR EN EL LABORATORIO (apartado 5 del cierre;
 * diseño `plans/informes-cierre/diseno-arq-lab.md` §3.1 y §3.2, B5).
 *
 *  1. El contexto de `decide` del escenario ({@see StudioLabEngineContext}).
 *  2. El tramo S2 entero ({@see StudioLabEngineFlow::walk()}): cada nodo de
 *     OpenAI con su prompt compilado, su modelo y sus parámetros
 *     ({@see StudioLabEngineModelClient}), y cada «Comprobar Borrador k» contra
 *     la comprobación del laboratorio ({@see StudioLabEngineCheck::respond()})
 *     con los segundos desde el principio del caso: pasados 25, ya no hay otra
 *     redacción. Como mucho 3 borradores, por la forma del grafo.
 *  3. Lo que el motor enviaría ({@see StudioLabEngineCheck::predict()}): del
 *     modelo o un respaldo, el tope y el registro.
 *  4. El bloque `engine` (≤ 16 KB) y los hallazgos del motor (fuente
 *     `engine`): `motor-respaldo` y `motor-comprobacion-<código>` (bloquean),
 *     `motor-reescrituras` y `motor-aviso-del-revisor` (informan; el segundo,
 *     PT-17 L4, con `final.criticAdvisory`).
 *
 * NO ESCRIBE NADA y no decide el resultado: devuelve las llamadas (para el
 * coste), el texto final (el del borrador que va, para que el juez y la
 * pantalla de hoy sigan valiendo), el bloque y los hallazgos; el runner
 * liquida. Una llamada que falla para el caso con su clase, como hoy.
 */
final class StudioLabEngineCase
{
    /** Ninguna llamada del recorrido espera más que esto (diseño §6). */
    public const CALL_TIMEOUT_SECONDS = 15;

    /**
     * NB4 de la revisión de seguridad: lo que puede durar un caso del motor
     * ENTERO, el juez incluido, desde que el runner lo empieza. El paso tiene
     * 150 s ({@see StudioLabStepJob::$timeout} y el `--timeout` del worker
     * `ironbody-lab`); los 10 que sobran son para reclamar, liquidar y encolar
     * el siguiente. El runner aparta el plazo del juez y le pasa a
     * {@see run()} el resto como límite del recorrido: con el juez en 60 s,
     * 80 s, más que el camino más largo que puede recorrer hoy un proveedor
     * lento (5 llamadas de 15 s: pasados 25 s, la comprobación ya no deja un
     * tercer borrador). Así el límite no recorta nada de lo que hoy cabe y,
     * si algo tarda más (la base, un cambio del flujo), el caso termina
     * `timeout` ANTES de que muera el job: se cobra lo gastado, no la reserva
     * entera.
     */
    public const CASE_BUDGET_SECONDS = 140;

    /** Con menos que esto por delante, el nodo no se llama: no se paga una llamada que no cabe. */
    public const MIN_CALL_SECONDS = 5;

    public const SOURCE = 'engine';

    /** Lo que se guarda de cada texto (la columna `reply` admite 2000). */
    private const TEXT_MAX = 2000;

    private const NOTES_MAX = 500;

    private const CRITIC_NOTES_MAX = 300;

    private const MAX_CORRECTIONS = 8;

    /** El nombre de cada nodo en el camino que se guarda. */
    private const PATH = [
        StudioLabEngineFlow::STRATEGIST => 'strategist',
        StudioLabEngineFlow::COMPOSER => 'composer',
        StudioLabEngineFlow::CHECK_1 => 'check1',
        StudioLabEngineFlow::CRITIC => 'critic',
        StudioLabEngineFlow::RETRY => 'composer_retry',
        StudioLabEngineFlow::CHECK_2 => 'check2',
        StudioLabEngineFlow::CRITIC_2 => 'critic_2',
        StudioLabEngineFlow::RETRY_2 => 'composer_retry_2',
        StudioLabEngineFlow::CRITIC_3 => 'critic_3',
    ];

    public function __construct(
        private readonly StudioLabEngineContext $context,
        private readonly StudioLabEngineCheck $check,
    ) {}

    /**
     * @param  array{roles:array<string,string>,sp:array<string,mixed>}  $bundle  {@see StudioPromptBundle::rolesFor()}
     * @param  array<string,mixed>  $scenario  el `scenario_snapshot` del caso
     * @param  callable(): int  $clockMs  los milisegundos de un reloj monótono
     * @param  int|null  $deadlineMs  NB4: el límite del recorrido en ese reloj (null, sin límite). Cada llamada espera
     *                                como mucho lo que queda hasta él ({@see callTimeout()}); si ya no cabe ninguna, el
     *                                nodo no se llama y el caso termina `timeout`
     * @return array{status:string,class:?string,calls:list<array<string,mixed>>,reply:?string,engine:array<string,mixed>|null,findings:list<array<string,mixed>>,turn:array<string,mixed>|null}
     *
     * @throws InvalidArgumentException si el escenario no pasa el filtro de datos personales (no sale nada)
     */
    public function run(array $bundle, array $scenario, StudioLabEngineModelClient $client, callable $clockMs, ?int $deadlineMs = null): array
    {
        $ctx = $this->context->build($scenario, $bundle['sp']);
        $inicio = $clockMs();
        $llamadas = [];
        $respuestas = [];
        $auditC9 = (int) ($bundle['sp']['k'] ?? StudioLabEngineFlow::COMPILER) >= 9;

        $recorrido = StudioLabEngineFlow::walk($ctx['decide'], $bundle['roles'], $ctx['inbound'],
            function (string $nodo, string $sistema, string $usuario) use ($client, $clockMs, $deadlineMs, $auditC9, &$llamadas): array {
                $plazo = self::callTimeout($clockMs(), $deadlineMs);
                if ($plazo === null) {
                    // NB4: el caso agotó su plazo. Este nodo no se llama ni se cobra; el recorrido para con `timeout`.
                    return ['ok' => false, 'class' => StudioLabModelClient::CLASS_TIMEOUT];
                }
                $r = $client->completeEngine($nodo, $sistema, $usuario, $plazo);
                $llamadas[] = ['node' => $nodo, 'role' => self::PATH[$nodo], 'result' => $r, 'inputBytes' => strlen($sistema) + strlen($usuario), 'maxTokens' => StudioLabEngineFlow::NODES[$nodo]['maxTokens']];
                if (($r['class'] ?? null) !== null || ! is_array($r['output'] ?? null)) {
                    return ['ok' => false, 'class' => $r['class'] ?? StudioLabModelClient::CLASS_INVALID_OUTPUT];
                }
                if ($auditC9 && in_array($nodo, StudioLabEngineFlow::COMPOSERS, true)
                    && is_string($r['output']['reply_draft'] ?? null) && mb_strlen($r['output']['reply_draft']) > self::TEXT_MAX) {
                    return ['ok' => false, 'class' => StudioLabModelClient::CLASS_INVALID_OUTPUT];
                }
                $uso = is_array($r['usage'] ?? null) ? ['input_tokens' => $r['usage']['inputTokens'], 'output_tokens' => $r['usage']['outputTokens']] : null;

                return ['ok' => true, 'json' => ['output' => [['content' => [['text' => $r['output']]]]]] + ($uso === null ? [] : ['usage' => $uso])];
            },
            function (int $k, array $cuerpo) use ($ctx, $clockMs, $inicio, &$respuestas): array {
                $r = $this->check->respond($ctx['turn'], $cuerpo, intdiv(max(0, $clockMs() - $inicio), 1000));
                $respuestas[$k] = $r;

                return $r;
            });
        $ms = max(0, $clockMs() - $inicio);

        if ($recorrido['status'] === 'stopped') {
            return ['status' => 'error', 'class' => (string) ($recorrido['failure']['class'] ?? StudioLabModelClient::CLASS_INVALID_OUTPUT), 'calls' => $llamadas, 'reply' => null, 'engine' => null, 'findings' => [], 'turn' => $ctx['turn']];
        }
        if ($recorrido['status'] === 'failed') {
            // Un Code que lanza (estrategia ilegible, ningún borrador): en n8n la ejecución falla y nadie contesta.
            return ['status' => 'error', 'class' => StudioLabModelClient::CLASS_INVALID_OUTPUT, 'calls' => $llamadas, 'reply' => null, 'engine' => null, 'findings' => [], 'turn' => $ctx['turn']];
        }

        $estrategia = (array) ($recorrido['ran'][StudioLabEngineFlow::VALIDATE]['strategy'] ?? []);
        $commit = $recorrido['commit'];
        if ((int) ($bundle['sp']['k'] ?? StudioLabEngineFlow::COMPILER) >= 9 && mb_strlen((string) $commit['reply_draft']) > self::TEXT_MAX) {
            // c9 conserva la misma salida para métricas, auditoría y juez: nunca corta un texto para hacerlo parecer válido.
            return ['status' => 'error', 'class' => StudioLabModelClient::CLASS_INVALID_OUTPUT, 'calls' => $llamadas, 'reply' => null, 'engine' => null, 'findings' => [], 'turn' => $ctx['turn']];
        }
        $final = $this->check->predict($ctx['turn'], $commit, $estrategia);
        $engine = $this->block($recorrido, $respuestas, $ctx['turn'], $estrategia, $commit, $final, $ms);

        return [
            'status' => 'done',
            'class' => null,
            'calls' => $llamadas,
            'reply' => mb_substr((string) $commit['reply_draft'], 0, self::TEXT_MAX),
            'engine' => $engine,
            'findings' => self::findings($final, $commit['regeneration'] ?? null),
            'turn' => $ctx['turn'],
        ] + ((int) ($bundle['sp']['k'] ?? StudioLabEngineFlow::COMPILER) >= 9 ? ['judgment' => [
            'candidate_rendered' => $this->check->judgeText($ctx['turn'], (string) $commit['reply_draft'], $estrategia, $commit['recommended_plan_id'] ?? null),
            'prediction' => $final,
            'authorized_facts' => StudioLabPrompts::engineJudgeFacts((array) ($ctx['decide']['context'] ?? []), $ctx['turn']),
        ]] : []);
    }

    /**
     * NB4: lo que puede esperar la siguiente llamada, en segundos enteros:
     * {@see CALL_TIMEOUT_SECONDS}, o lo que queda hasta `$deadlineMs` si es
     * menos. Null si queda menos de {@see MIN_CALL_SECONDS}: la llamada no
     * cabe. Pura.
     */
    public static function callTimeout(int $nowMs, ?int $deadlineMs): ?int
    {
        if ($deadlineMs === null) {
            return self::CALL_TIMEOUT_SECONDS;
        }
        $quedan = intdiv(max(0, $deadlineMs - $nowMs), 1000);

        return $quedan < self::MIN_CALL_SECONDS ? null : min(self::CALL_TIMEOUT_SECONDS, $quedan);
    }

    /**
     * El bloque `engine` del caso (§3.2).
     *
     * @param  array<string,mixed>  $recorrido
     * @param  array<int,array<string,mixed>>  $respuestas
     * @param  array<string,mixed>  $turn
     * @param  array<string,mixed>  $estrategia
     * @param  array<string,mixed>  $commit
     * @param  array<string,mixed>  $final
     * @return array<string,mixed>
     */
    private function block(array $recorrido, array $respuestas, array $turn, array $estrategia, array $commit, array $final, int $ms): array
    {
        $ran = $recorrido['ran'];
        $reescrituras = is_array($commit['regeneration']['rewrites'] ?? null) ? $commit['regeneration']['rewrites'] : [];
        $borradores = [];
        foreach (StudioLabEngineFlow::COMPOSERS as $i => $nodo) {
            if (! isset($ran[$nodo])) {
                continue;
            }
            $texto = (string) (StudioLabEngineFlow::modelText($ran[$nodo])['reply_draft'] ?? '');
            $medida = $this->check->predict($turn, ['reply_draft' => $texto, 'recommended_plan_id' => $commit['recommended_plan_id'] ?? null, 'critic' => []], $estrategia);
            $k = $i + 1;
            $c = $respuestas[$k] ?? null;
            $cuerpo = is_array($c['body'] ?? null) ? $c['body'] : null;
            $revisor = isset($ran[StudioLabEngineFlow::CRITICS[$i]]) ? StudioLabEngineFlow::modelText($ran[StudioLabEngineFlow::CRITICS[$i]]) : null;
            $borradores[] = [
                'n' => $k,
                'role' => self::PATH[$nodo],
                'text' => mb_substr($texto, 0, self::TEXT_MAX),
                'chars' => $medida['measured'],
                'cap' => $medida['cap'],
                'tuteo' => $medida['tuteo'],
                'check' => $c === null ? null : [
                    'http' => (int) $c['statusCode'],
                    'pass' => $cuerpo['pass'] ?? null,
                    'codes' => array_map(static fn (array $d): string => $d['detail'] === null ? $d['code'] : $d['code'].'/'.$d['detail'], (array) ($cuerpo['defects'] ?? [])),
                    'issues' => (array) ($cuerpo['issues'] ?? []),
                    'notes' => mb_substr((string) ($cuerpo['notes'] ?? ''), 0, self::NOTES_MAX),
                    'mayRewrite' => $cuerpo['may_rewrite'] ?? false,
                    'reason' => $cuerpo['budget']['reason'] ?? null,
                ],
                'critic' => $revisor === null ? null : [
                    'verdict' => $revisor['verdict'] ?? null,
                    'issues' => array_values(array_filter((array) ($revisor['issues'] ?? []), 'is_string')),
                    'hardFail' => is_string($revisor['hard_fail'] ?? null) ? $revisor['hard_fail'] : null,
                    'notes' => mb_substr((string) ($revisor['notes'] ?? ''), 0, self::CRITIC_NOTES_MAX),
                ],
                'rewriteBy' => $k === 1 ? null : ($reescrituras[$k - 2]['by'] ?? null),
            ];
        }

        return [
            'flowRev' => StudioLabEngineFlow::FLOW_REV,
            'path' => array_values(array_map(static fn (string $n): string => self::PATH[$n], array_filter($recorrido['order'], static fn (string $n): bool => isset(self::PATH[$n])))),
            'strategy' => [
                'intent' => $estrategia['intent'] ?? null,
                'nextState' => $estrategia['next_state'] ?? null,
                'toolsRequested' => array_values((array) ($estrategia['tools_requested'] ?? [])),
                'handoffAllowed' => ($ran[StudioLabEngineFlow::VALIDATE]['human_handoff_allowed'] ?? false) === true,
                'corrections' => array_slice(array_map(static fn ($c): string => mb_substr((string) $c, 0, 160), (array) ($ran[StudioLabEngineFlow::VALIDATE]['corrections'] ?? [])), 0, self::MAX_CORRECTIONS),
            ],
            'drafts' => $borradores,
            'final' => [
                'draft' => (int) $commit['attempt'],
                'origin' => $final['origin'],
                'cause' => $final['cause'],
                'codes' => $final['codes'],
                'chars' => $final['chars'],
                'cap' => $final['cap'],
                'withinCap' => $final['withinCap'],
                'register' => $final['register'],
                'trimmed' => $final['trimmed'],
            ] + (($final['criticAdvisory'] ?? false) === true ? ['criticAdvisory' => true] : [])
                // PT-17, puerta 2, L1: con la lista del servidor, la forma de la lista (aditivo; el guion de aceptación no lo necesita).
                + (is_array($final['list'] ?? null) ? ['list' => $final['list']] : []),
            'elapsedMs' => $ms,
        ];
    }

    /**
     * Los hallazgos del motor: con la fuente `engine` y la forma de siempre.
     *
     * @param  array<string,mixed>  $final
     * @param  array<string,mixed>|null  $regeneracion
     * @return list<array<string,mixed>>
     */
    private static function findings(array $final, ?array $regeneracion): array
    {
        $out = [];
        if ($final['origin'] === StudioLabEngineCheck::ORIGIN_FALLBACK) {
            $out[] = StudioLabChecks::finding('motor-respaldo', StudioLabChecks::LEVEL_BLOCKING, 'El motor enviaría un respaldo del servidor',
                'El texto del modelo no saldría: '.($final['cause'] === StudioLabEngineCheck::CAUSE_CRITIC ? 'el revisor lo rechazó en el último intento.' : 'el último borrador incumple «'.$final['cause'].'» y el commit lo descarta.'),
                null, self::SOURCE);
        }
        foreach ($final['codes'] as $codigo) {
            $out[] = StudioLabChecks::finding('motor-comprobacion-'.str_replace('_', '-', $codigo), StudioLabChecks::LEVEL_BLOCKING, 'El texto final incumple una comprobación del servidor',
                "Código «{$codigo}» en el borrador que iría al commit.", null, self::SOURCE);
        }
        if (($final['criticAdvisory'] ?? false) === true) {
            // PT-17 L4: informa, no bloquea; el commit lo anota como `critic_advisory`.
            $out[] = StudioLabChecks::finding('motor-aviso-del-revisor', StudioLabChecks::LEVEL_INFO, 'El revisor lo rechazó y sale igual',
                'Rechazo sin fallo grave sobre la lista limpia de un turno de lista: es un aviso, no un respaldo.', null, self::SOURCE);
        }
        $reescrituras = is_array($regeneracion['rewrites'] ?? null) ? $regeneracion['rewrites'] : [];
        if ($reescrituras !== []) {
            $quien = array_map(static fn (array $r): string => ($r['by'] ?? '') === 'server' ? 'el servidor ('.implode(', ', (array) ($r['codes'] ?? [])).')' : 'el revisor', $reescrituras);
            $out[] = StudioLabChecks::finding('motor-reescrituras', StudioLabChecks::LEVEL_INFO, count($reescrituras) === 1 ? 'Una reescritura antes del texto final' : count($reescrituras).' reescrituras antes del texto final',
                'Las pidió '.implode(' y ', $quien).'.', null, self::SOURCE);
        }

        return $out;
    }
}
