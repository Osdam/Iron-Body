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
