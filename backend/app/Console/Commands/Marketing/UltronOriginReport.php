<?php

namespace App\Console\Commands\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingAutomationEvent;
use App\Models\MarketingMessage;
use App\Services\Marketing\Ultron\UltronRegenerationNotes;
use App\Services\Marketing\Ultron\UltronReplyOrigin;
use App\Services\Marketing\Ultron\UltronSource;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * ¿QUIÉN ESCRIBIÓ CADA RESPUESTA DE ULTRON? (cierre V2, apartado 4, «regenerar
 * antes de sustituir», ola 6; plan del arquitecto
 * `plan-arquitecto-a4-regenerar.md` §3 P-7, §6 «Ola 6» y §12; condición 5 del
 * propietario: distinguir respuestas del modelo, regeneradas y respaldos, con
 * el 90 % de generación por IA como objetivo de calidad, y medir también
 * latencia y costo).
 *
 * EL REPARTO es el de la consulta de la línea base
 * (`ops/comprobacion-produccion/origen-respuestas.sql`, C): las filas `reply`
 * con estado `executed` del motor (`source_type = inbound_message`), cada una
 * clasificada con {@see UltronReplyOrigin::classify()}, que sigue sus mismas
 * reglas. Lo demás que deja el commit (bloqueos, rendiciones mudas, la
 * respuesta repetida recuperada…) va en una línea aparte. Las filas sin
 * `source_type` (Legacy, el enrutador) no son turnos de ULTRON y no cuentan.
 *
 * LO QUE DICE: el total; el porcentaje escrito por la IA frente al 90 % y,
 * aparte, el del primer intento; las reescrituras por autor (servidor o
 * revisor) y por código; los respaldos por causa; los recortes; la hora de la
 * visita (texto del servidor que la línea base cuenta como del modelo); la
 * latencia p50, p95 y máxima del entrante al saliente (`created_at` de los dos
 * mensajes, percentil por rango más cercano); las llamadas al modelo por turno
 * (1 + redacciones + revisores) y las comprobaciones del servidor; los tokens y
 * el costo si n8n los entrega y se dan los dos precios por millón; y los
 * reintentos del job (`marketing_automation_events.attempts` > 1).
 *
 * SOLO LECTURA, Y SIN DATOS DE NADIE: lee `marketing_ai_actions` (la
 * metadata, de la que solo salen códigos y números), los `created_at` de los
 * mensajes (nunca su texto) y los intentos de los eventos. No imprime ids de
 * conversación ni de mensaje, ni textos de la persona ni de los borradores.
 * `ultron:canary-report` sigue siendo el de los contadores mecánicos de
 * exactitud; el criterio de aceptación usa los dos (§12).
 */
class UltronOriginReport extends Command
{
    protected $signature = 'ultron:origin-report
        {--conversation=* : id de una conversación (se puede repetir); sin ninguna, todas las de ULTRON}
        {--since= : desde esta fecha u hora (ISO; sin zona, UTC)}
        {--until= : hasta esta fecha u hora, sin incluirla (ISO; sin zona, UTC)}
        {--json : salida en JSON para archivar}
        {--precio-entrada= : precio por millón de tokens de entrada (para estimar el costo)}
        {--precio-salida= : precio por millón de tokens de salida}';

    protected $description = 'Origen de las respuestas de ULTRON (solo lectura): modelo, regeneradas y respaldos frente al 90 %, latencia, llamadas, tokens y reintentos.';

    /** El objetivo de calidad del propietario (condición 5). */
    public const TARGET_PCT = 90;

    /** Con menos turnos con traza que estos, el 90 % no se juzga (plan §12). */
    public const MIN_TRACED_TURNS = 30;

    /** El universo de la consulta de la línea base. */
    private const TIPO = 'reply';

    private const ESTADO = 'executed';

    /** Un tipo o un estado de acción en la línea aparte: con forma de código, o no sale. */
    private const CODIGO = '/\A[a-z][a-z0-9_]{0,63}\z/';

    public function handle(): int
    {
        $opciones = $this->opciones();
        if (is_string($opciones)) {
            $this->error($opciones);

            return self::FAILURE;
        }
        [$conversaciones, $desde, $hasta, $precios] = $opciones;

        $informe = $this->informe($conversaciones, $desde, $hasta, $precios);

        if ($this->option('json')) {
            $this->line((string) json_encode($informe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

            return self::SUCCESS;
        }

        $this->imprimir($informe);

        return self::SUCCESS;
    }

    /**
     * Las opciones validadas, o el mensaje de error.
     *
     * @return array{list<int>, ?Carbon, ?Carbon, ?array{float,float}}|string
     */
    private function opciones(): array|string
    {
        $conversaciones = [];
        foreach ((array) $this->option('conversation') as $id) {
            // Desde la consola llega como texto; desde `Artisan::call()`, puede llegar entero.
            $id = is_int($id) ? (string) $id : $id;
            if (! is_string($id) || preg_match('/\A[1-9][0-9]{0,18}\z/', $id) !== 1) {
                return 'Cada --conversation es un id entero positivo.';
            }
            $conversaciones[] = (int) $id;
        }

        $fechas = [];
        foreach (['since', 'until'] as $opcion) {
            $crudo = (string) $this->option($opcion);
            if ($crudo === '') {
                $fechas[$opcion] = null;

                continue;
            }
            try {
                $fechas[$opcion] = Carbon::parse($crudo, 'UTC')->utc();
            } catch (Throwable) {
                return 'No entiendo esa fecha: --'.$opcion.'='.$crudo;
            }
        }
        if ($fechas['since'] !== null && $fechas['until'] !== null && $fechas['until']->lte($fechas['since'])) {
            return '--until tiene que ser posterior a --since.';
        }

        $precio = [];
        foreach (['precio-entrada', 'precio-salida'] as $opcion) {
            $crudo = $this->option($opcion);
            if ($crudo === null || $crudo === '') {
                $precio[$opcion] = null;

                continue;
            }
            if (! is_numeric($crudo) || (float) $crudo < 0) {
                return '--'.$opcion.' es un número no negativo (por millón de tokens).';
            }
            $precio[$opcion] = (float) $crudo;
        }
        $precios = $precio['precio-entrada'] !== null && $precio['precio-salida'] !== null ? [$precio['precio-entrada'], $precio['precio-salida']] : null;

        return [array_values(array_unique($conversaciones)), $fechas['since'], $fechas['until'], $precios];
    }

    /**
     * @param  list<int>  $conversaciones
     * @param  ?array{float,float}  $precios
     * @return array<string,mixed>
     */
    private function informe(array $conversaciones, ?Carbon $desde, ?Carbon $hasta, ?array $precios): array
    {
        $turnos = [];
        $fuera = [];
        MarketingAiAction::query()
            ->where('source_type', UltronSource::INBOUND_MESSAGE)
            ->when($conversaciones !== [], fn ($q) => $q->whereIn('conversation_id', $conversaciones))
            ->when($desde !== null, fn ($q) => $q->where('created_at', '>=', $desde))
            ->when($hasta !== null, fn ($q) => $q->where('created_at', '<', $hasta))
            ->select(['id', 'action_type', 'status', 'source_event_id', 'metadata'])
            ->lazyById(500)
            ->each(function (MarketingAiAction $a) use (&$turnos, &$fuera): void {
                $tipo = (string) $a->action_type;
                $estado = (string) $a->status;
                $meta = is_array($a->metadata) ? $a->metadata : [];
                if ($tipo !== self::TIPO || $estado !== self::ESTADO) {
                    $clave = (preg_match(self::CODIGO, $tipo) === 1 ? $tipo : 'otro').'/'.(preg_match(self::CODIGO, $estado) === 1 ? $estado : 'otro');
                    $fuera[$clave] = ($fuera[$clave] ?? 0) + 1;

                    return;
                }
                $saliente = $meta['outbound']['message_id'] ?? null;
                $turnos[] = [
                    'origen' => UltronReplyOrigin::classify($meta, $tipo),
                    'entrante' => is_int($a->source_event_id) || ctype_digit((string) $a->source_event_id) ? (int) $a->source_event_id : null,
                    'saliente' => is_int($saliente) ? $saliente : null,
                ];
            });

        $total = count($turnos);
        $origenes = array_column($turnos, 'origen');
        $delModelo = array_filter($origenes, fn (array $o) => $o['origin'] === UltronReplyOrigin::MODEL);
        $respaldos = array_filter($origenes, fn (array $o) => $o['origin'] === UltronReplyOrigin::FALLBACK);
        $cuenta = fn (array $lista, string $intento) => count(array_filter($lista, fn (array $o) => $o['attempt'] === $intento));
        $horaDeLaVisita = count(array_filter($delModelo, fn (array $o) => $o['server_text'] === UltronReplyOrigin::SERVER_VISIT_HOUR));
        $pctIa = self::pct(count($delModelo), $total);
        // Lo que de verdad escribió el modelo: sin la hora de la visita, que la escribe el servidor desde la fila del CRM.
        // El objetivo del 90 % se juzga con esto, no con la regla de la línea base, que la contaba como del modelo (lead, 21-11).
        $escritoPorLaIa = count($delModelo) - $horaDeLaVisita;
        $pctEscrito = self::pct($escritoPorLaIa, $total);

        return [
            'window' => [
                'since' => $desde?->toIso8601String(),
                'until' => $hasta?->toIso8601String(),
                'conversations' => $conversaciones === [] ? null : count($conversaciones),
            ],
            'total' => $total,
            'traced' => count(array_filter($origenes, fn (array $o) => $o['traced'])),
            'ai' => [
                'count' => count($delModelo),
                'pct' => $pctIa,
                'written_count' => $escritoPorLaIa,
                'written_pct' => $pctEscrito,
                'target_pct' => self::TARGET_PCT,
                'meets_target' => $pctEscrito !== null && $pctEscrito >= self::TARGET_PCT,
                'first_attempt' => self::parte($cuenta($delModelo, UltronReplyOrigin::ATTEMPT_FIRST), $total),
                'regenerated' => self::parte($cuenta($delModelo, UltronReplyOrigin::ATTEMPT_REGENERATED), $total),
                'unknown_attempt' => self::parte($cuenta($delModelo, UltronReplyOrigin::ATTEMPT_UNKNOWN), $total),
                'trimmed' => count(array_filter($delModelo, fn (array $o) => $o['trimmed'])),
                'visit_hour' => $horaDeLaVisita,
                'pct_without_visit_hour' => $pctEscrito,
            ],
            'rewrites' => self::reescrituras($origenes),
            'fallbacks' => [
                'count' => count($respaldos),
                'pct' => self::pct(count($respaldos), $total),
                'by_reason' => self::enOrden(self::frecuencias(array_column($respaldos, 'fallback_reason')), UltronReplyOrigin::REASONS),
                'policy_codes' => self::porFrecuencia(self::frecuencias(array_merge([], ...array_column($respaldos, 'policy_codes')))),
                'recovery_reasons' => self::porFrecuencia(self::frecuencias(array_filter(array_column($respaldos, 'recovery_reason'), 'is_string'))),
            ],
            'latency_s' => $this->latencias($turnos),
            'model_calls' => self::resumen(array_values(array_filter(array_map(UltronReplyOrigin::modelCalls(...), $origenes), 'is_int'))),
            'server_checks' => self::resumen(array_values(array_filter(array_column(array_filter($origenes, fn (array $o) => $o['traced']), 'checks'), 'is_int'))),
            'tokens' => self::tokens($origenes, $precios),
            'job' => $this->reintentos($turnos),
            'outside' => self::porFrecuencia($fuera),
        ];
    }

    /**
     * Segundos del entrante al saliente, con los `created_at` de los dos
     * mensajes (sin su texto): p50, p95 y máximo por rango más cercano.
     *
     * @param  list<array{entrante:?int,saliente:?int}>  $turnos
     * @return array{samples:int,p50:?int,p95:?int,max:?int}
     */
    private function latencias(array $turnos): array
    {
        $ids = array_values(array_unique(array_filter([...array_column($turnos, 'entrante'), ...array_column($turnos, 'saliente')], 'is_int')));
        $cuando = [];
        foreach (array_chunk($ids, 1000) as $lote) {
            foreach (MarketingMessage::query()->whereIn('id', $lote)->get(['id', 'created_at']) as $m) {
                if ($m->created_at !== null) {
                    $cuando[(int) $m->id] = Carbon::parse($m->created_at)->getTimestamp();
                }
            }
        }

        $segundos = [];
        foreach ($turnos as $t) {
            if (isset($cuando[$t['entrante'] ?? 0], $cuando[$t['saliente'] ?? 0])) {
                $segundos[] = max(0, $cuando[$t['saliente']] - $cuando[$t['entrante']]);
            }
        }
        sort($segundos);
        $n = count($segundos);
        $rango = fn (float $q): ?int => $n === 0 ? null : $segundos[max(0, (int) ceil($q * $n) - 1)];

        return ['samples' => $n, 'p50' => $rango(0.50), 'p95' => $rango(0.95), 'max' => $n === 0 ? null : $segundos[$n - 1]];
    }

    /**
     * Los turnos del reparto con su evento del job, cuántos se reintentaron y
     * el máximo de intentos.
     *
     * @param  list<array{entrante:?int}>  $turnos
     * @return array{events:int,retried:int,max_attempts:?int}
     */
    private function reintentos(array $turnos): array
    {
        $porMensaje = [];
        foreach (array_chunk(array_values(array_unique(array_filter(array_column($turnos, 'entrante'), 'is_int'))), 1000) as $lote) {
            foreach (MarketingAutomationEvent::query()->whereIn('message_id', $lote)->get(['message_id', 'attempts']) as $e) {
                $id = (int) $e->message_id;
                $porMensaje[$id] = max($porMensaje[$id] ?? 0, (int) $e->attempts);
            }
        }
        $intentos = [];
        foreach ($turnos as $t) {
            if ($t['entrante'] !== null && isset($porMensaje[$t['entrante']])) {
                $intentos[] = $porMensaje[$t['entrante']];
            }
        }

        return [
            'events' => count($intentos),
            'retried' => count(array_filter($intentos, fn (int $i) => $i > 1)),
            'max_attempts' => $intentos === [] ? null : max($intentos),
        ];
    }

    /**
     * Todas las reescrituras de los turnos del reparto, por autor y por código.
     *
     * @param  list<array<string,mixed>>  $origenes
     * @return array{count:int,by:array<string,int>,codes:array<string,int>}
     */
    private static function reescrituras(array $origenes): array
    {
        $todas = array_merge([], ...array_column($origenes, 'rewrites'));

        return [
            'count' => count($todas),
            'by' => self::enOrden(self::frecuencias(array_column($todas, 'by')), UltronReplyOrigin::BYS),
            'codes' => self::enOrden(self::frecuencias(array_merge([], ...array_column($todas, 'codes'))), UltronRegenerationNotes::CODES),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $origenes
     * @param  ?array{float,float}  $precios
     * @return array{samples:int,input:?int,output:?int,cost:?float}
     */
    private static function tokens(array $origenes, ?array $precios): array
    {
        $con = array_values(array_filter(array_column($origenes, 'tokens'), fn ($t) => is_array($t) && ($t['input'] !== null || $t['output'] !== null)));
        if ($con === []) {
            return ['samples' => 0, 'input' => null, 'output' => null, 'cost' => null];
        }
        $entrada = array_sum(array_map(fn (array $t) => (int) $t['input'], $con));
        $salida = array_sum(array_map(fn (array $t) => (int) $t['output'], $con));

        return [
            'samples' => count($con),
            'input' => $entrada,
            'output' => $salida,
            'cost' => $precios === null ? null : round($entrada / 1_000_000 * $precios[0] + $salida / 1_000_000 * $precios[1], 4),
        ];
    }

    /**
     * @param  list<int>  $valores
     * @return array{samples:int,mean:?float,max:?int}
     */
    private static function resumen(array $valores): array
    {
        return [
            'samples' => count($valores),
            'mean' => $valores === [] ? null : round(array_sum($valores) / count($valores), 1),
            'max' => $valores === [] ? null : max($valores),
        ];
    }

    /** @return array{count:int,pct:?float} */
    private static function parte(int $n, int $total): array
    {
        return ['count' => $n, 'pct' => self::pct($n, $total)];
    }

    private static function pct(int $n, int $total): ?float
    {
        return $total === 0 ? null : round(100 * $n / $total, 1);
    }

    /**
     * @param  list<mixed>  $valores
     * @return array<string,int>
     */
    private static function frecuencias(array $valores): array
    {
        $out = [];
        foreach ($valores as $v) {
            if (is_string($v)) {
                $out[$v] = ($out[$v] ?? 0) + 1;
            }
        }

        return $out;
    }

    /**
     * Las que aparecen, en el orden del vocabulario.
     *
     * @param  array<string,int>  $frecuencias
     * @param  list<string>  $orden
     * @return array<string,int>
     */
    private static function enOrden(array $frecuencias, array $orden): array
    {
        return array_intersect_key(array_replace(array_flip($orden), $frecuencias), $frecuencias);
    }

    /**
     * De más a menos; a igualdad, por nombre.
     *
     * @param  array<string,int>  $frecuencias
     * @return array<string,int>
     */
    private static function porFrecuencia(array $frecuencias): array
    {
        uksort($frecuencias, fn (string $a, string $b) => [$frecuencias[$b], $a] <=> [$frecuencias[$a], $b]);

        return $frecuencias;
    }

    // ── La salida de texto ──────────────────────────────────────────────────

    /** @param array<string,mixed> $i */
    private function imprimir(array $i): void
    {
        $ia = $i['ai'];
        $total = $i['total'];
        $ventana = $i['window'];

        $this->info('ULTRON · ¿quién escribió cada respuesta? (solo lectura)');
        $this->line(sprintf('  Ventana: desde %s · hasta %s · conversaciones: %s',
            $ventana['since'] ?? 'el principio', $ventana['until'] ?? 'ahora', $ventana['conversations'] ?? 'todas'));
        $this->line(sprintf('  Respuestas enviadas: %d (con traza de regeneración: %d)', $total, $i['traced']));
        $this->line(sprintf('  Escrito por la IA: %d de %d (%s) · objetivo %d %%: %s',
            $ia['written_count'], $total, self::porciento($ia['written_pct']), self::TARGET_PCT, $ia['meets_target'] ? 'sí' : 'NO'));
        $this->line(sprintf('    al primer intento: %s · regeneradas: %s · intento sin dato: %s',
            self::conPct($ia['first_attempt']), self::conPct($ia['regenerated']), self::conPct($ia['unknown_attempt'])));
        $this->line(sprintf('    Recortadas (sin su pregunta final): %d', $ia['trimmed']));
        $this->line(sprintf('    La hora de la visita (la escribe el servidor desde el CRM; no cuenta como IA): %d · con la regla de la línea base, que la contaba como del modelo: %d (%s)',
            $ia['visit_hour'], $ia['count'], self::porciento($ia['pct'])));

        $re = $i['rewrites'];
        $nombres = [UltronReplyOrigin::BY_SERVER => 'servidor', UltronReplyOrigin::BY_CRITIC => 'revisor', UltronReplyOrigin::BY_UNKNOWN => 'sin dato'];
        $this->line(sprintf('  Reescrituras: %d%s', $re['count'], $re['by'] === [] ? '' : ' ('.self::lista(array_combine(array_map(fn ($k) => $nombres[$k], array_keys($re['by'])), $re['by'])).')'));
        if ($re['codes'] !== []) {
            $this->line('    por código: '.self::lista($re['codes']));
        }

        $re = $i['fallbacks'];
        $this->line(sprintf('  Respaldos: %d de %d (%s)', $re['count'], $total, self::porciento($re['pct'])));
        foreach ($re['by_reason'] as $causa => $n) {
            $this->line('    '.match ($causa) {
                UltronReplyOrigin::REASON_CRITIC => 'el revisor rechazó todos los intentos: '.$n,
                UltronReplyOrigin::REASON_RECOVERY => 'la rendición: '.$n.($re['recovery_reasons'] === [] ? '' : ' ('.self::lista($re['recovery_reasons']).')'),
                UltronReplyOrigin::REASON_POLICY => 'la política del commit: '.$n.($re['policy_codes'] === [] ? '' : ' ('.self::lista($re['policy_codes']).')'),
                UltronReplyOrigin::REASON_LAST_RESORT => 'el último recurso: '.$n,
                default => 'sin causa conocida: '.$n,
            });
        }

        $l = $i['latency_s'];
        $this->line($l['samples'] === 0
            ? '  Latencia del entrante al saliente: sin dato'
            : sprintf('  Latencia del entrante al saliente: p50 %d s · p95 %d s · máx %d s (n = %d)', $l['p50'], $l['p95'], $l['max'], $l['samples']));
        $c = $i['model_calls'];
        $s = $i['server_checks'];
        $this->line(sprintf('  Llamadas al modelo por turno: %s · comprobaciones del servidor por turno: %s', self::media($c), self::media($s)));
        $t = $i['tokens'];
        $this->line($t['samples'] === 0
            ? '  Tokens: sin dato (n8n no los entrega todavía)'
            : sprintf('  Tokens: entrada %d · salida %d (n = %d) · costo estimado: %s', $t['input'], $t['output'], $t['samples'],
                $t['cost'] === null ? 'sin los dos precios (--precio-entrada y --precio-salida)' : number_format($t['cost'], 4, ',', '.').' (con los precios dados, por millón de tokens)'));
        $j = $i['job'];
        $this->line(sprintf('  Reintentos del job: %d de %d turnos con evento%s', $j['retried'], $j['events'], $j['max_attempts'] === null ? '' : ' (máx. intentos '.$j['max_attempts'].')'));
        $this->line('  Fuera del reparto: '.($i['outside'] === [] ? 'nada' : self::lista($i['outside'])));
        if ($i['traced'] < self::MIN_TRACED_TURNS) {
            $this->line(sprintf('  Aviso: el %d %% se juzga con al menos %d turnos con traza; aquí hay %d.', self::TARGET_PCT, self::MIN_TRACED_TURNS, $i['traced']));
        }
    }

    private static function porciento(?float $pct): string
    {
        return $pct === null ? 'sin dato' : number_format($pct, 1, ',', '.').' %';
    }

    /** @param array{count:int,pct:?float} $parte */
    private static function conPct(array $parte): string
    {
        return $parte['count'].' ('.self::porciento($parte['pct']).')';
    }

    /** @param array{samples:int,mean:?float,max:?int} $r */
    private static function media(array $r): string
    {
        return $r['samples'] === 0 ? 'sin dato' : 'media '.number_format((float) $r['mean'], 1, ',', '.').' · máx '.$r['max'].' (n = '.$r['samples'].')';
    }

    /** @param array<string,int> $frecuencias */
    private static function lista(array $frecuencias): string
    {
        return implode(', ', array_map(fn (string $k, int $n) => $k.': '.$n, array_keys($frecuencias), $frecuencias));
    }
}
