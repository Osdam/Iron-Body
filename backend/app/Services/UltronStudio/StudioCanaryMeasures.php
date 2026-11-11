<?php

namespace App\Services\UltronStudio;

use App\Models\MarketingAiAction;
use App\Models\UltronAbort;
use App\Services\Marketing\Ultron\UltronSource;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use JsonException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * LOS RECUENTOS DEL CANARIO (contrato H5 §A6): la parte con base de datos de
 * {@see StudioCanaryEvidence}, que decide sobre lo que esta clase cuenta.
 *
 * SOLO LEE, Y SOLO CUENTA. Es una de las clases del Studio que nombran el motor
 * (`UltronStudioEngineReadOnlyTest` las enumera): lee `marketing_ai_actions` y
 * `ultron_aborts`, la llave del canario del motor y ejecuta, dentro del proceso,
 * el acta `ultron:canary-report --json` (una lectura). Nunca escribe, y de lo
 * que lee NO sale nada que no sea un número: ni ids de conversación (el del
 * canario solo filtra las consultas y se le pasa al acta), ni textos, ni
 * nombres. Del acta solo se leen el código de salida, el total y los siete
 * recuentos; su texto (que lleva los mensajes del canario) no se guarda, no se
 * registra y no se devuelve: se lee de un búfer propio que queda vacío.
 *
 *  - `turns`: filas de la conversación canaria con `source_type` del mensaje
 *    entrante (`UltronSource::INBOUND_MESSAGE`, el único que acepta el commit)
 *    desde `since`. Cada commit crea UNA fila (su `idempotency_key` es UNIQUE),
 *    así que filas y turnos coinciden.
 *  - `byState` y `stamped`: de las MISMAS filas y en la MISMA consulta que
 *    `turns` (si no, una carrera daría una evidencia incoherente, y la parte
 *    pura la rechaza). El sello se lee POR CLAVE (`metadata->studio->state`):
 *    en PostgreSQL `metadata` es `jsonb` y no conserva el orden. `unstamped`
 *    es lo que queda: filas sin sello o con uno que no se reconoce.
 *  - `recoveries`: de esas filas, las que llevan `metadata.recovery`.
 *  - `brakes`: paradas de ULTRON accionadas desde `since`, y si hay una abierta
 *    ahora (la misma consulta que el motor, sin su red: un fallo se propaga).
 *  - `acta`: `{exitCode, total, counts}`; sin un JSON con los siete recuentos
 *    y un total que sea su suma, `{exitCode, null, null}` (falla cerrado).
 *
 * Sin canario configurado no hay conversación que contar: cero turnos y un
 * acta sin JSON.
 *
 * Enmienda M-1: también los últimos turnos con su sello de prompts
 * ({@see promptTurns()}, para `status.engine.prompts`) y, para la alerta M1-A
 * del monitor del H7, los turnos de una ventana con un sello de alerta
 * ({@see promptAlerts()}). Con las mismas reglas: solo lee, y solo cuenta o
 * recorta.
 */
final class StudioCanaryMeasures
{
    public const ACTA_COMMAND = 'ultron:canary-report';

    /** `--turns` del acta: los turnos contados, como mínimo 40 y como mucho 200 (§A6). */
    public const ACTA_MIN_TURNS = 40;

    public const ACTA_MAX_TURNS = 200;

    /** Los siete contadores mecánicos del acta, con las claves que imprime el comando. */
    public const ACTA_COUNTERS = [
        'traspasos_no_autorizados',
        'horarios_aplazados_en_una_persona',
        'planes_no_vendibles',
        'precios_que_no_son_del_catalogo',
        'enlaces_no_oficiales',
        'datos_personales',
        'negaciones_de_un_cobro_disponible',
    ];

    /**
     * M1-A (H5 M1-13): los estados del sello de prompts
     * (`metadata.studio_prompts.state`) que abren el incidente del monitor: n8n
     * aplicó otra cosa que lo ofrecido (`mismatch`) o los prompts Legacy ya no
     * son los de la base (`legacy_drift`). Son `UltronPromptStamp::MISMATCH` y
     * `LEGACY_DRIFT` (una prueba los ata), en orden alfabético.
     */
    public const PROMPT_ALERT_STATES = ['legacy_drift', 'mismatch'];

    /** De los turnos de alerta, cuántos de los más recientes se leen para sacar sus huellas cortas (el recuento es exacto aparte). */
    public const PROMPT_ALERT_SAMPLE = 20;

    /** Como mucho, cuántas combinaciones distintas de huellas se devuelven. */
    public const PROMPT_ALERT_FINGERPRINTS = 5;

    /** Los motivos de `fallback` que reconoce el reparto (§A4.4). */
    private const FALLBACK_REASONS = ['contract_unknown', 'hash_mismatch', 'malformed', 'read_failed', 'signature_invalid', 'table_missing'];

    /**
     * Los recuentos de §A6 que no salen del reloj, de la deriva ni de la firma.
     *
     * @return array{turns:int,stamped:int,byState:array<string,int>,recoveries:int,brakes:array{count:int,engaged:bool},acta:array{exitCode:int,total:int|null,counts:array<string,int>|null}}
     */
    public function measure(string $versionUuid, CarbonInterface $since): array
    {
        $canario = $this->canaryConversation();
        $filas = $canario === null ? self::noTurns() : $this->turns($canario, $versionUuid, $since);

        return $filas + [
            'brakes' => [
                'count' => UltronAbort::query()->where('engaged_at', '>=', $since)->count(),
                'engaged' => UltronAbort::query()->open()->exists(),
            ],
            'acta' => $canario === null
                ? self::noActa(1)
                : $this->acta($canario, $since, min(self::ACTA_MAX_TURNS, max(self::ACTA_MIN_TURNS, $filas['turns']))),
        ];
    }

    /**
     * Enmienda M-1 (plan de motores §D): los últimos `$last` turnos de la
     * conversación canaria (`source_type` del mensaje entrante), del más antiguo
     * al más reciente, para `status.engine.prompts.lastTurns`. Por turno, el
     * minuto en UTC, lo declarado (`metadata.studio`: estado y versión
     * recortada), lo ofrecido y lo observado (`metadata.studio_prompts`) con
     * versiones y huellas recortadas a 8 caracteres, y el estado del sello. La
     * misma forma que `ultron:prompt-trace`.
     *
     * SIN DATOS DE NADIE: ni el id de la conversación (solo filtra), ni ids de
     * mensajes o de filas, ni textos, ni el motivo libre de nada: de cada clave
     * se copia solo un texto corto de ASCII visible (los vocabularios cerrados
     * del sello) o null. Sin canario configurado, ninguno.
     *
     * @return list<array{at:?string,declared:?array{state:?string,version8:?string},offered:?array{mode:?string,reason:?string,version8:?string,bundle8:?string},observed:?array{mode:?string,bundle8:?string,flowRev:?string},state:?string}>
     */
    public function promptTurns(int $last): array
    {
        $canario = $this->canaryConversation();
        if ($canario === null || $last < 1) {
            return [];
        }

        return MarketingAiAction::query()
            ->where('conversation_id', $canario)
            ->where('source_type', UltronSource::INBOUND_MESSAGE)
            ->orderByDesc('id')
            ->limit($last)
            ->get(['id', 'metadata', 'created_at'])
            ->reverse()
            ->map(static function (MarketingAiAction $fila): array {
                $meta = is_array($fila->metadata) ? $fila->metadata : [];
                $declarada = is_array($meta['studio'] ?? null) ? $meta['studio'] : null;
                $sello = is_array($meta['studio_prompts'] ?? null) ? $meta['studio_prompts'] : null;
                $ofrecida = is_array($sello['offered'] ?? null) ? $sello['offered'] : null;
                $observada = is_array($sello['observed'] ?? null) ? $sello['observed'] : null;

                return [
                    'at' => $fila->created_at?->copy()->utc()->format('Y-m-d\TH:i\Z'),
                    'declared' => $declarada === null ? null : [
                        'state' => self::word($declarada['state'] ?? null),
                        'version8' => self::short($declarada['version'] ?? null),
                    ],
                    'offered' => $ofrecida === null ? null : [
                        'mode' => self::word($ofrecida['mode'] ?? null),
                        'reason' => self::word($ofrecida['reason'] ?? null),
                        'version8' => self::short($ofrecida['version'] ?? null),
                        'bundle8' => self::short($ofrecida['bundleHash'] ?? null),
                    ],
                    'observed' => $observada === null ? null : [
                        'mode' => self::word($observada['mode'] ?? null),
                        'bundle8' => self::short($observada['bundleHash'] ?? null),
                        'flowRev' => self::word($observada['flowRev'] ?? null),
                    ],
                    'state' => $sello === null ? null : self::word($sello['state'] ?? null),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * M1-A (H5 M1-13), para el monitor del H7: los turnos de la conversación
     * canaria (`source_type` del mensaje entrante, como {@see promptTurns()})
     * con `created_at` en [`$from`, `$to`) y, de ellos, los que llevan un sello
     * de prompts de alerta ({@see PROMPT_ALERT_STATES}).
     *
     *  - `turns`: todos los turnos de la canaria en la ventana (se miró algo);
     *  - `byState`: cuántos de cada estado de alerta, siempre con las dos claves;
     *  - `fingerprints`: de los {@see PROMPT_ALERT_SAMPLE} turnos de alerta más
     *    recientes, las {@see PROMPT_ALERT_FINGERPRINTS} primeras combinaciones
     *    distintas (de la más reciente hacia atrás; se devuelven ordenadas) de
     *    estado, versión ofrecida y huellas ofrecida y observada, recortadas a
     *    8 caracteres.
     *
     * SIN DATOS DE NADIE, como `promptTurns()`: ni el id de la conversación
     * (solo filtra), ni ids de filas o de ejecuciones, ni textos, ni el motivo
     * libre de nada. Dos lecturas (el recuento, y la muestra solo si hay algo
     * que contar). Sin canario configurado, null. Un fallo de lectura se
     * propaga (el monitor lo convierte en `check_failed`).
     *
     * @return array{turns:int,byState:array<string,int>,fingerprints:list<array{state:string,version8:?string,offered8:?string,observed8:?string}>}|null
     */
    public function promptAlerts(CarbonInterface $from, CarbonInterface $to): ?array
    {
        $canario = $this->canaryConversation();
        if ($canario === null) {
            return null;
        }

        $ventana = static fn () => MarketingAiAction::query()
            ->where('conversation_id', $canario)
            ->where('source_type', UltronSource::INBOUND_MESSAGE)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to);

        // El recuento: UNA consulta con sumas condicionadas, con la ruta JSON
        // de la gramática de la conexión (como `turns()`).
        $consulta = $ventana()->toBase();
        $estado = $consulta->getGrammar()->wrap('metadata->studio_prompts->state');
        $columnas = ['count(*) as turns'];
        $valores = [];
        foreach (self::PROMPT_ALERT_STATES as $i => $alerta) {
            $columnas[] = "sum(case when {$estado} = ? then 1 else 0 end) as alert_{$i}";
            $valores[] = $alerta;
        }
        $fila = (array) $consulta->selectRaw(implode(', ', $columnas), $valores)->first();

        $porEstado = [];
        foreach (self::PROMPT_ALERT_STATES as $i => $alerta) {
            $porEstado[$alerta] = (int) ($fila["alert_{$i}"] ?? 0);
        }

        $huellas = [];
        if (array_sum($porEstado) > 0) {
            $muestra = $ventana()
                ->whereIn('metadata->studio_prompts->state', self::PROMPT_ALERT_STATES)
                ->orderByDesc('id')
                ->limit(self::PROMPT_ALERT_SAMPLE)
                ->get(['id', 'metadata']);
            foreach ($muestra as $turno) {
                $meta = is_array($turno->metadata) ? $turno->metadata : [];
                $sello = is_array($meta['studio_prompts'] ?? null) ? $meta['studio_prompts'] : [];
                $ofrecida = is_array($sello['offered'] ?? null) ? $sello['offered'] : [];
                $observada = is_array($sello['observed'] ?? null) ? $sello['observed'] : [];
                $huella = [
                    'state' => (string) self::word($sello['state'] ?? null),
                    'version8' => self::short($ofrecida['version'] ?? null),
                    'offered8' => self::short($ofrecida['bundleHash'] ?? null),
                    'observed8' => self::short($observada['bundleHash'] ?? null),
                ];
                // Del más reciente al más antiguo: las primeras combinaciones distintas.
                $huellas[implode('|', array_map('strval', $huella))] ??= $huella;
                if (count($huellas) >= self::PROMPT_ALERT_FINGERPRINTS) {
                    break;
                }
            }
            ksort($huellas, SORT_STRING);
        }

        return [
            'turns' => (int) ($fila['turns'] ?? 0),
            'byState' => $porEstado,
            'fingerprints' => array_values($huellas),
        ];
    }

    /** Los 8 primeros caracteres de un uuid o una huella, solo si son hexadecimales (o guiones). */
    private static function short(mixed $valor): ?string
    {
        return is_string($valor) && preg_match('/\A[0-9a-f-]{8}/', $valor) === 1 ? substr($valor, 0, 8) : null;
    }

    /** Una palabra de un vocabulario cerrado del sello (`applied`, `S1`, `prompts_off`…): ASCII visible, como mucho 64. */
    private static function word(mixed $valor): ?string
    {
        return is_string($valor) && preg_match('/\A[A-Za-z0-9_.:-]{1,64}\z/', $valor) === 1 ? $valor : null;
    }

    /** El id de la conversación canaria del motor, o null sin canario. No sale de aquí. */
    private function canaryConversation(): ?int
    {
        $id = config('marketing.ultron.canary_conversation_id');

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
    }

    /**
     * Turnos, reparto por sello, sellados con esta versión y recuperaciones:
     * UNA consulta (sumas condicionadas sobre las mismas filas).
     *
     * @return array{turns:int,stamped:int,byState:array<string,int>,recoveries:int}
     */
    private function turns(int $conversacion, string $versionUuid, CarbonInterface $since): array
    {
        $consulta = MarketingAiAction::query()->toBase()
            ->where('conversation_id', $conversacion)
            ->where('source_type', UltronSource::INBOUND_MESSAGE)
            ->where('created_at', '>=', $since);

        // Las rutas JSON con la gramática de la conexión: `->>` en PostgreSQL
        // (jsonb, por clave), `json_extract` en SQLite.
        $g = $consulta->getGrammar();
        $estado = $g->wrap('metadata->studio->state');
        $motivo = $g->wrap('metadata->studio->reason');
        $version = $g->wrap('metadata->studio->version');
        $recuperacion = $g->wrap('metadata->recovery');

        $columnas = ['count(*) as turns'];
        $valores = [];
        $columnas[] = "sum(case when {$estado} = ? then 1 else 0 end) as applied";
        $valores[] = 'applied';
        $columnas[] = "sum(case when {$estado} = ? and {$version} = ? then 1 else 0 end) as stamped";
        array_push($valores, 'applied', strtolower($versionUuid));
        foreach (self::FALLBACK_REASONS as $i => $razon) {
            $columnas[] = "sum(case when {$estado} = ? and {$motivo} = ? then 1 else 0 end) as fallback_{$i}";
            array_push($valores, 'fallback', $razon);
        }
        foreach (['legacy', 'out_of_scope'] as $sello) {
            $columnas[] = "sum(case when {$estado} = ? then 1 else 0 end) as {$sello}";
            $valores[] = $sello;
        }
        $columnas[] = "sum(case when {$recuperacion} is not null then 1 else 0 end) as recoveries";

        $fila = (array) $consulta->selectRaw(implode(', ', $columnas), $valores)->first();
        $n = static fn (string $columna): int => (int) ($fila[$columna] ?? 0);

        $porEstado = ['applied' => $n('applied')];
        foreach (self::FALLBACK_REASONS as $i => $razon) {
            $porEstado["fallback/{$razon}"] = $n("fallback_{$i}");
        }
        $porEstado['legacy'] = $n('legacy');
        $porEstado['out_of_scope'] = $n('out_of_scope');
        // Lo que no se reconoce como sello (sin clave `studio`, o con un estado
        // o un motivo desconocidos) es «sin sello»: así el reparto suma los
        // turnos y falla cerrado (`stamped` no llega a `turns`).
        $porEstado['unstamped'] = max(0, $n('turns') - array_sum($porEstado));
        ksort($porEstado, SORT_STRING);

        return [
            'turns' => $n('turns'),
            'stamped' => $n('stamped'),
            'byState' => $porEstado,
            'recoveries' => $n('recoveries'),
        ];
    }

    /**
     * El acta del canario, dentro del proceso y con un búfer propio. Solo se
     * leen el código de salida, el total y los siete recuentos.
     *
     * @return array{exitCode:int,total:int|null,counts:array<string,int>|null}
     */
    private function acta(int $conversacion, CarbonInterface $since, int $turnos): array
    {
        $salida = new BufferedOutput;

        try {
            $codigo = Artisan::call(self::ACTA_COMMAND, [
                'conversation' => $conversacion,
                '--since' => StudioPublicationDeclaration::utc($since),
                '--turns' => $turnos,
                '--json' => true,
            ], $salida);
        } catch (Throwable $e) {
            // Tragado a propósito y sin el mensaje (puede llevar SQL o texto del
            // canario): un acta que no se pudo levantar es un acta sin JSON, y
            // la evidencia falla cerrada en `acta`. Al log, solo la clase.
            Log::warning('ultron_studio.canary_acta_unavailable', ['error_class' => class_basename($e)]);

            return self::noActa(1);
        }

        // `fetch()` vacía el búfer: el texto vive solo en esta variable.
        $texto = $salida->fetch();
        $recuentos = self::counts($texto);
        unset($texto);

        return $recuentos === null ? self::noActa($codigo) : ['exitCode' => $codigo] + $recuentos;
    }

    /**
     * Los siete recuentos y su total, o null si el texto no es el JSON del acta
     * con exactamente esos contadores y un total que es su suma.
     *
     * @return array{total:int,counts:array<string,int>}|null
     */
    private static function counts(string $texto): ?array
    {
        try {
            $json = json_decode($texto, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $mecanicos = is_array($json) ? ($json['findings']['mecanicos'] ?? null) : null;
        $total = is_array($json) ? ($json['findings']['mecanicos_total'] ?? null) : null;
        if (! is_array($mecanicos) || ! is_int($total)) {
            return null;
        }
        $claves = array_keys($mecanicos);
        sort($claves, SORT_STRING);
        $esperadas = self::ACTA_COUNTERS;
        sort($esperadas, SORT_STRING);
        if ($claves !== $esperadas) {
            return null;
        }

        $counts = [];
        foreach (self::ACTA_COUNTERS as $clave) {
            if (! is_array($mecanicos[$clave]) || ! array_is_list($mecanicos[$clave])) {
                return null;
            }
            $counts[$clave] = count($mecanicos[$clave]);
        }

        return array_sum($counts) === $total ? ['total' => $total, 'counts' => $counts] : null;
    }

    /** @return array{turns:int,stamped:int,byState:array<string,int>,recoveries:int} */
    private static function noTurns(): array
    {
        return [
            'turns' => 0,
            'stamped' => 0,
            'byState' => array_fill_keys(StudioCanaryEvidence::BY_STATE, 0),
            'recoveries' => 0,
        ];
    }

    /** @return array{exitCode:int,total:null,counts:null} */
    private static function noActa(int $exitCode): array
    {
        return ['exitCode' => $exitCode, 'total' => null, 'counts' => null];
    }
}
