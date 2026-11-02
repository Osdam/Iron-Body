<?php

namespace Tests\Feature\Marketing;

use App\Console\Commands\Marketing\UltronCanaryReport;
use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;
use Throwable;

/**
 * F-1 del Hito 7 (PERF7): el acta del canario armaba los turnos en tiempo
 * cuadrático —por cada entrante volvía a filtrar TODOS los mensajes y a recorrer
 * TODAS las acciones— y la evidencia del Studio tardaba un segundo con 200
 * turnos. Ahora es una sola pasada. El acta es el instrumento con el que se
 * decide si ULTRON sale a producción: la reescritura no puede cambiar ni un byte.
 *
 * La REFERENCIA es el mismo comando con el `turnos()` de d629202 copiado tal
 * cual (el resto —consultas, contadores, JSON y texto— se hereda). Cada caso
 * ejecuta las dos actas sobre los mismos datos y exige el mismo código de
 * salida, la misma salida byte a byte (JSON y texto) y las mismas sentencias
 * SQL con sus valores (DB::listen). Los datos salen de un generador con semilla
 * fija: turnos con y sin respuesta, sin acción, acciones duplicadas y empatadas,
 * respaldos y rendiciones, hallazgos, mensajes fuera de `--since`, de otra
 * conversación y sin fecha, con 0, 40, 200 y 1 000 turnos.
 */
final class UltronCanaryReportSinglePassH7Test extends TestCase
{
    use RefreshDatabase;

    private const REFERENCIA = 'ultron:canary-report-referencia-h7';

    /** Lo que escribe la persona: con nulos, emojis, comillas, barra y una frase larga (para `corto()`). */
    private const ENTRANTES = [
        'hola',
        'cuánto vale el plan mensual?',
        'qué horarios tienen?',
        'necesito hablar con una persona',
        'ñandú 🦤 "comillas" y \\ barra',
        null,
        'Buenas tardes, quería saber si tienen clases de spinning en la mañana, cuánto cuesta el plan trimestral y si puedo congelar la membresía si viajo un mes entero por trabajo.',
    ];

    /** Salientes que el acta no cuenta. */
    private const LIMPIOS = [
        'Claro, te cuento con gusto. ¿Qué objetivo tienes? 💪',
        'El Plan Mensual está en $80.000 COP al mes.',
        'Android: https://play.google.com/store/apps/details?id=com.ironbodyneiva.workout · iPhone: https://apps.apple.com/co/app/iron-body-workout/id6792374138',
        'Abrimos desde 2016 y el Plan Mensual está en $80.000 COP.',
        'No tengo un horario general confirmado en mi información, y prefiero no darte uno equivocado.',
        null,
    ];

    /** Salientes que el acta cuenta (los contadores mecánicos, alguno doble). */
    private const HALLAZGOS = [
        'Te conecto con alguien del equipo para que te ayude.',
        'No quiero darte un horario incorrecto: eso lo confirma una persona del equipo.',
        'Te recomiendo el Convenio Interno, que es el que más se lleva.',
        'El Plan Mensual está en $80.000 COP, pero te lo dejo en 65.000.',
        'Descárgala en https://apps-gratis.example/ironbody o entra a ironbody-promos.com',
        'El que tenemos registrado es el 3150536026; escríbenos a ventas@ironbody.example',
        'Te lo dejo en ochenta mil pesos.',
    ];

    /** Clases de turno del generador, con su peso. */
    private const PESOS = [
        'normal' => 40, 'varios' => 10, 'sin_respuesta' => 8, 'sin_accion' => 7, 'router' => 8,
        'duplicadas' => 6, 'empate' => 5, 'respaldo' => 6, 'hallazgo' => 6, 'nota' => 4,
    ];

    private MarketingConversation $canario;

    private MarketingConversation $otra;

    private UltronCanaryReport $referencia;

    /** El reloj del generador: cada turno, cinco minutos más. */
    private CarbonImmutable $reloj;

    /** @var list<array{id:int,en:?CarbonImmutable}> los entrantes del canario, en orden */
    private array $entrantes = [];

    private bool $capturando = false;

    /** @var list<array{0:string,1:array<int,mixed>}> */
    private array $sentencias = [];

    protected function setUp(): void
    {
        parent::setUp();

        Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true]);
        Plan::create(['name' => 'Convenio Interno', 'price' => 0, 'duration_days' => 30, 'active' => true, 'sellable' => false]);
        // Retirado y ambiguo (está dentro de «Plan Mensual»): el acta lo dice y no lo vigila.
        Plan::create(['name' => 'Mensual', 'price' => 1, 'duration_days' => 30, 'active' => true, 'sellable' => false]);

        $this->canario = $this->conversacion('3150536026');
        $this->otra = $this->conversacion('3209990000');
        $this->reloj = CarbonImmutable::parse('2026-10-01T08:00:00Z');

        $this->referencia = self::referencia();
        Artisan::registerCommand($this->referencia);

        DB::listen(function (QueryExecuted $q): void {
            if ($this->capturando) {
                $this->sentencias[] = [$q->sql, $q->connection->prepareBindings($q->bindings)];
            }
        });
    }

    // ── Los casos ───────────────────────────────────────────────────────────

    public function test_with_no_turns_both_records_say_the_same(): void
    {
        // La conversación vacía.
        $this->mismaActa([]);
        $this->mismaActa(['--json' => true]);

        // Salientes, una nota interna y acciones, pero ningún entrante: ningún turno.
        $this->mensaje($this->canario, MarketingMessage::DIRECTION_OUTBOUND, 'Hola, soy ULTRON 👋', $this->reloj);
        $this->mensaje($this->canario, 'internal', 'nota del sistema', $this->reloj->addSecond(), null, MarketingMessage::SENDER_SYSTEM);
        $this->accion(null, ['intent' => 'seguimiento'], 'reactivate', 'executed', 'scheduler', $this->reloj->addSeconds(2));
        [, $json] = $this->mismaActa(['--json' => true]);
        $this->assertSame([], json_decode($json, true)['turns']);

        // Turnos que quedan todos antes de la ventana.
        $this->sembrar(3, 11);
        [, $json] = $this->mismaActa(['--json' => true, '--since' => '2027-01-01T00:00:00Z']);
        $this->assertSame([], json_decode($json, true)['turns']);
        $this->mismaActa(['--since' => '2027-01-01T00:00:00Z']);

        // Y las dos salidas tempranas, que no llegan a armar turnos.
        $this->mismaActa(['conversation' => 999999999], conTurnos: false);
        $this->mismaActa(['--since' => 'el martes pasado por la tarde'], conTurnos: false);
    }

    public function test_forty_turns_with_every_odd_case_give_the_same_record(): void
    {
        $cuandos = $this->sembrar(40, 7, zoo: true);
        $this->assertCount(40, $cuandos);

        // `--turns` por debajo de 1 da un turno (el corte iba después de añadir); `7x` se lee 7.
        foreach ([[], ['--turns' => '0'], ['--turns' => '1'], ['--turns' => '-3'], ['--turns' => '7x'], ['--turns' => '39'], ['--turns' => '40'], ['--turns' => '200']] as $opciones) {
            $this->mismaActa($opciones + ['--json' => true]);
        }
        $this->mismaActa([]);
        $this->mismaActa(['--turns' => '41']);

        // Ventanas: desde un entrante (entra), desde un segundo después (su respuesta queda huérfana) y
        // desde muy atrás (deja fuera lo que no tiene fecha, que sin ventana sí cuenta).
        $k = 15;
        while ($cuandos[$k] === null) {
            $k++;
        }
        $this->mismaActa(['--json' => true, '--turns' => '200', '--since' => $cuandos[$k]->toIso8601String()]);
        $this->mismaActa(['--json' => true, '--turns' => '200', '--since' => $cuandos[$k]->addSecond()->toIso8601String()]);
        $this->mismaActa(['--turns' => '200', '--since' => '2026-01-01T00:00:00Z']);

        // Que los datos recorren de verdad lo que dicen recorrer: si no, la igualdad no probaría nada.
        [$codigo, $json] = $this->mismaActa(['--json' => true, '--turns' => '41']);
        $acta = json_decode($json, true);
        $turnos = $acta['turns'];
        $intenciones = array_column($turnos, 'intent');
        $this->assertCount(40, $turnos);
        $this->assertSame(1, $codigo);
        foreach ($acta['findings']['mecanicos'] as $contador => $casos) {
            $this->assertNotEmpty($casos, "el contador {$contador} no tiene casos: revisar() no se ejercita entero");
        }
        $this->assertNotEmpty($acta['findings']['nombres_ambiguos']);
        $this->assertNotEmpty(array_filter($turnos, fn (array $t): bool => $t['accion'] === null), 'turnos sin acción');
        $this->assertNotEmpty(array_filter($turnos, fn (array $t): bool => $t['salientes'] === []), 'turnos sin respuesta');
        $this->assertNotEmpty(array_filter($turnos, fn (array $t): bool => count($t['salientes']) >= 3), 'turnos con varios salientes');
        $this->assertNotEmpty(array_filter($turnos, fn (array $t): bool => $t['en'] === null), 'turnos sin fecha');
        $this->assertNotEmpty(array_filter($turnos, fn (array $t): bool => $t['modo_respaldo'] !== null && $t['rendicion'] !== null && $t['nego_un_cobro_disponible'] !== null), 'respaldos');
        $this->assertNotEmpty(array_filter($turnos, fn (array $t): bool => $t['time_to_typing_ms'] !== null), 'presencia medida');
        $this->assertNotEmpty(array_filter($turnos, fn (array $t): bool => $t['traspaso_autorizado']), 'traspaso autorizado');
        // Los empates: gana la PRIMERA acción que nombra el entrante, por cualquiera de las dos vías.
        $this->assertContains('router_primero', $intenciones);
        $this->assertNotContains('por_fuente_despues', $intenciones);
        $this->assertContains('primera_duplicada', $intenciones);
        $this->assertNotContains('segunda_duplicada', $intenciones);
        $this->assertSame(2, count(array_keys($intenciones, 'dos_turnos', true)), 'una acción que ata dos turnos');
        $this->assertNotContains('llega_tarde', $intenciones);
        $this->assertContains('texto_con_espacios', $intenciones);
        $this->assertContains('texto_con_letras', $intenciones);
        $this->assertContains('clave_escapada', $intenciones);
        $this->assertNotContains('anidada', $intenciones);
        $this->assertContains('router', $intenciones);
    }

    public function test_two_hundred_turns_give_the_same_record(): void
    {
        $cuandos = $this->sembrar(200, 2026);

        $this->mismaActa(['--json' => true, '--turns' => '200']);
        $this->mismaActa(['--turns' => '200']);
        $this->mismaActa(['--json' => true]);
        $this->mismaActa(['--json' => true, '--turns' => '199']);
        $this->mismaActa(['--json' => true, '--turns' => '200', '--since' => $cuandos[120]->toIso8601String()]);
    }

    /**
     * Con más turnos que el tope, el acta cuenta los PRIMEROS desde `--since`,
     * no los últimos; y la referencia, que es cuadrática, tarda decenas de
     * veces más que el comando sobre los mismos datos.
     */
    public function test_a_thousand_turns_count_the_first_two_hundred_and_no_longer_grow_quadratically(): void
    {
        $cuandos = $this->sembrar(1000, 1000);

        [, $json, $msReferencia, $ms] = $this->mismaActa(['--json' => true, '--turns' => '200']);
        $turnos = json_decode($json, true)['turns'];
        $this->assertCount(200, $turnos);
        $this->assertSame($cuandos[0]->toIso8601String(), $turnos[0]['en']);
        $this->assertSame($cuandos[199]->toIso8601String(), $turnos[199]['en']);

        $this->mismaActa(['--json' => true, '--turns' => '1000', '--since' => $cuandos[750]->toIso8601String()]);
        $this->mismaActa(['--since' => $cuandos[960]->toIso8601String()]);

        /*
         * El tiempo, como cociente entre dos ejecuciones seguidas en el mismo
         * proceso y sobre los mismos datos (la carga de la máquina afecta a las
         * dos): medido, 82 veces en SQLite y 71 en PostgreSQL (2,5 s frente a
         * 31–36 ms); se exige 5. Volver a filtrar los mensajes por cada turno
         * lo tumba.
         */
        $this->assertGreaterThan(5 * $ms, $msReferencia, sprintf('referencia %.1f ms, comando %.1f ms', $msReferencia, $ms));
    }

    // ── Las dos actas ───────────────────────────────────────────────────────

    /**
     * La referencia y el comando con las mismas opciones: mismo código, misma
     * salida byte a byte y mismas sentencias con sus valores.
     *
     * @param  array<string,mixed>  $opciones
     * @return array{0:int,1:string,2:float,3:float} código, salida, ms de la referencia y ms del comando
     */
    private function mismaActa(array $opciones, bool $conTurnos = true): array
    {
        $argumentos = $opciones + ['conversation' => $this->canario->id];
        $caso = json_encode($opciones, JSON_UNESCAPED_SLASHES);

        $llamadas = $this->referencia->llamadas;
        [$codigoReferencia, $salidaReferencia, $sqlReferencia, $msReferencia] = $this->ejecutar(self::REFERENCIA, $argumentos);
        // Si la referencia no hubiera ejecutado SU turnos(), se compararía el acta consigo misma.
        $this->assertSame($llamadas + ($conTurnos ? 1 : 0), $this->referencia->llamadas, "la referencia no usó el algoritmo de antes con {$caso}");

        [$codigo, $salida, $sql, $ms] = $this->ejecutar('ultron:canary-report', $argumentos);

        $this->assertSame($codigoReferencia, $codigo, "código de salida distinto con {$caso}");
        $this->assertSame($salidaReferencia, $salida, "salida distinta con {$caso}");
        $this->assertSame($sqlReferencia, $sql, "sentencias distintas con {$caso}");
        $this->assertNotSame('', $salida);

        return [$codigo, $salida, $msReferencia, $ms];
    }

    /**
     * @param  array<string,mixed>  $argumentos
     * @return array{0:int,1:string,2:list<array{0:string,1:array<int,mixed>}>,3:float}
     */
    private function ejecutar(string $comando, array $argumentos): array
    {
        $this->sentencias = [];
        $this->capturando = true;
        $inicio = hrtime(true);
        try {
            $codigo = Artisan::call($comando, $argumentos);
        } finally {
            $this->capturando = false;
        }

        return [$codigo, Artisan::output(), $this->sentencias, (hrtime(true) - $inicio) / 1e6];
    }

    /**
     * El acta con el `turnos()` de d629202 (líneas 276-378) y su `msEntre()`
     * (112-124), copiados tal cual salvo `protected` y el contador. Todo lo
     * demás es el comando de verdad, heredado.
     */
    private static function referencia(): UltronCanaryReport
    {
        return new class extends UltronCanaryReport
        {
            protected $signature = 'ultron:canary-report-referencia-h7 {conversation : id de la conversación del canario} {--turns=40 : cuántos turnos como máximo} {--since= : cuenta solo desde esta fecha/hora (ISO), para no arrastrar el histórico} {--json : salida en JSON para archivar}';

            public int $llamadas = 0;

            /** Milisegundos entre un instante y una marca ISO, o null si falta alguna. */
            private function msEntre(?Carbon $desde, mixed $hastaIso): ?int
            {
                if ($desde === null || ! is_string($hastaIso) || $hastaIso === '') {
                    return null;
                }

                try {
                    return (int) round($desde->diffInMilliseconds(Carbon::parse($hastaIso)));
                } catch (Throwable) {
                    return null;
                }
            }

            /**
             * Un turno es un entrante de la persona y todo lo que vino detrás.
             *
             * @return list<array<string,mixed>>
             */
            protected function turnos(Collection $mensajes, Collection $acciones, int $max): array
            {
                $this->llamadas++;

                $entrantes = $mensajes->where('direction', MarketingMessage::DIRECTION_INBOUND)->values();
                $turnos = [];

                foreach ($entrantes as $i => $entrante) {
                    $siguiente = $entrantes[$i + 1]->id ?? PHP_INT_MAX;

                    $salientes = $mensajes
                        ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)
                        ->filter(fn (MarketingMessage $m) => $m->id > $entrante->id && $m->id < $siguiente)
                        ->values();

                    /*
                     * El turno se ata por `source_event_id`, que es donde el commit
                     * escribe el id del mensaje que lo disparó; `metadata.message_id`
                     * es el respaldo para las filas del router de entrada. No existe
                     * columna `message_id` en la tabla, y darla por buena dejaba todos
                     * los turnos sin decisión, que es el fallo que cazó la prueba.
                     */
                    $accion = $acciones->first(fn (MarketingAiAction $a) => (int) $a->source_event_id === (int) $entrante->id
                        || (int) data_get($a->metadata, 'message_id') === (int) $entrante->id);
                    $meta = is_array($accion?->metadata) ? $accion->metadata : [];

                    $primera = $salientes->first();
                    $turnos[] = [
                        'turno' => $i + 1,
                        'entrante' => (string) $entrante->body,
                        'en' => optional($entrante->created_at)->toIso8601String(),
                        'accion' => $accion?->action_type,
                        'estado' => $accion?->status,
                        'outcome' => $meta['outcome'] ?? null,
                        'blocked_reason' => $meta['blocked_reason'] ?? null,
                        'intent' => $meta['intent'] ?? null,
                        'strategy_goal' => $meta['strategy_goal'] ?? null,
                        'next_best_action' => $meta['next_best_action'] ?? null,
                        'referente' => $meta['reference_resolution'] ?? null,
                        'temperatura' => $meta['lead_temperature'] ?? null,
                        'ciclo_vida' => $meta['customer_lifecycle'] ?? null,
                        'fase' => ($meta['previous_phase'] ?? '?').' → '.($meta['next_phase'] ?? '?'),
                        'plan' => $meta['recommended_plan_id'] ?? null,
                        'herramientas_pedidas' => $meta['tools_requested'] ?? [],
                        'herramientas_ejecutadas' => $meta['tools_executed'] ?? [],
                        'critic' => $meta['critic'] ?? null,
                        /*
                         * Si este turno se RINDIÓ, el texto que leyó la persona lo
                         * escribió Laravel, no el modelo. Sin esta línea el acta
                         * atribuiría al modelo una respuesta que no es suya, que es
                         * justo la clase de mentira que el acta existe para evitar.
                         */
                        'rendicion' => $meta['recovery'] ?? null,
                        /*
                         * Y si el CRM tuvo que retirarle al modelo una frase que negaba
                         * un cobro disponible, el acta lo dice. El sistema se corrige
                         * solo, pero la causa sigue en el prompt: sin esta línea, que
                         * pase en un turno o en todos se lee exactamente igual.
                         */
                        'nego_un_cobro_disponible' => $meta['checkout_denial_dropped'] ?? null,
                        'modo_respaldo' => $meta['fallback_mode'] ?? null,
                        'riesgos' => $meta['risk_flags'] ?? [],
                        'novedad' => $meta['novelty_max_similarity'] ?? null,
                        'revision_humana' => $meta['needs_staff_review'] ?? false,
                        // Si la persona PIDIÓ hablar con alguien y Laravel lo autorizó,
                        // ofrecerlo es lo correcto, no un hallazgo.
                        'traspaso_autorizado' => (bool) ($meta['handoff_authorized'] ?? false),
                        'salientes' => $salientes->map(fn (MarketingMessage $m) => [
                            'id' => (int) $m->id,
                            'tipo' => data_get($m->metadata, 'kind', 'text'),
                            'texto' => (string) $m->body,
                        ])->all(),
                        'latencia_s' => $primera !== null && $entrante->created_at !== null && $primera->created_at !== null
                            ? $entrante->created_at->diffInSeconds($primera->created_at)
                            : null,
                        /*
                         * Lo que la persona VIO mientras esperaba, y cuánto tardó en
                         * verlo. El visto y el «escribiendo…» no son respuestas, pero
                         * si tardan más que la respuesta no sirven de nada, así que se
                         * miden con la misma vara.
                         */
                        'presencia' => data_get($entrante->metadata, 'ultron_presence'),
                        // Si Meta dijo cuánto tardó, esa es la medida buena: las marcas
                        // de la tabla son de segundo entero y restarlas da «0 ms».
                        'time_to_typing_ms' => $this->msEntre(
                            $entrante->created_at,
                            data_get($entrante->metadata, 'ultron_presence.typing_started_at'),
                        ),
                        'time_to_response_ms' => $primera !== null && $entrante->created_at !== null && $primera->created_at !== null
                            ? (int) round($entrante->created_at->diffInMilliseconds($primera->created_at))
                            : null,
                    ];

                    if (count($turnos) >= $max) {
                        break;
                    }
                }

                return $turnos;
            }
        };
    }

    // ── El generador ────────────────────────────────────────────────────────

    /**
     * Turnos del canario hasta tener $total entrantes, con ruido de otra
     * conversación entre medias y, al final, acciones tardías. La misma
     * semilla da los mismos datos. Con $zoo, los primeros turnos son los casos
     * raros, uno a uno.
     *
     * @return list<?CarbonImmutable> el `created_at` de cada entrante del canario, en orden
     */
    private function sembrar(int $total, int $semilla, bool $zoo = false): array
    {
        $azar = new Randomizer(new Mt19937($semilla));
        if ($zoo) {
            $this->zoo($azar);
        }
        while (count($this->entrantes) < $total) {
            if ($azar->getInt(1, 10) <= 3) {
                $this->ruido($azar);
            }
            $this->turno($this->clase($azar), $azar);
        }
        $this->tardias($azar);

        if ($zoo) {
            /*
             * Un saliente con el id más alto posible, el último de todos: la
             * referencia cerraba el último turno en PHP_INT_MAX, exclusivo, y lo
             * dejaba fuera. Después ya no cabe ningún mensaje más en SQLite.
             */
            DB::table('marketing_messages')->insert([
                'id' => PHP_INT_MAX, 'conversation_id' => $this->canario->id, 'direction' => MarketingMessage::DIRECTION_OUTBOUND,
                'sender_type' => MarketingMessage::SENDER_AI, 'body' => 'El id más alto posible.', 'status' => 'sent',
                'created_at' => $this->reloj->addHour(), 'updated_at' => $this->reloj->addHour(),
            ]);
        }

        return array_column($this->entrantes, 'en');
    }

    /** Los casos raros, en este orden (después sigue el generador). */
    private function zoo(Randomizer $azar): void
    {
        // Un saliente ANTES del primer entrante (no es de ningún turno) y una acción que no nombra ningún mensaje.
        $this->saliente($this->reloj->subMinute(), 'Hola, soy ULTRON 👋');
        $this->accion(null, ['intent' => 'seguimiento'], 'reactivate', 'executed', 'scheduler', $this->reloj->subSeconds(30));

        foreach (array_keys(self::PESOS) as $clase) {
            $this->turno($clase, $azar);
        }

        // Un turno por cada hallazgo (con el respaldo de arriba, los siete contadores tienen qué contar).
        foreach (self::HALLAZGOS as $texto) {
            $c = $this->hora($azar);
            $in = $this->entrante($c, $azar);
            $this->accion($in, $this->decision($azar), cuando: $c->addSeconds(2));
            $this->saliente($c->addSeconds(12), $texto);
        }

        // `metadata.message_id` como texto con espacios delante y con letras detrás: `(int)` lo lee igual.
        foreach (['texto_con_espacios' => '  %d', 'texto_con_letras' => '%dabc'] as $intencion => $forma) {
            $c = $this->hora($azar);
            $in = $this->entrante($c, $azar);
            $this->accion(null, ['intent' => $intencion, 'message_id' => sprintf($forma, $in)], 'router_reply', 'executed', 'router', $c->addSeconds(2));
            $this->saliente($c->addSeconds(30), 'Claro, te cuento.');
        }

        // Dos entrantes seguidos y UNA acción que ata los dos (`source_event_id` el primero, `message_id` el segundo);
        // la acción propia del segundo llega después y pierde. Las respuestas, tras el segundo: el primero queda sin ellas.
        $c = $this->hora($azar);
        $a = $this->entrante($c, $azar);
        $b = $this->entrante($c->addSeconds(5), $azar);
        $this->accion($a, $this->decision($azar, ['intent' => 'dos_turnos', 'message_id' => $b]), cuando: $c->addSeconds(7));
        $this->accion($b, $this->decision($azar, ['intent' => 'llega_tarde']), cuando: $c->addSeconds(8));
        $this->saliente($c->addSeconds(20), 'Te respondo las dos cosas a la vez.');

        // El traspaso que la persona pidió y Laravel autorizó (no es hallazgo).
        $c = $this->hora($azar);
        $in = $this->entrante($c, $azar);
        $this->accion($in, $this->decision($azar, ['handoff_authorized' => true]), cuando: $c->addSeconds(2));
        $this->saliente($c->addSeconds(15), 'Claro, te conecto con alguien del equipo.');

        // Presencia: con marca válida (con milisegundos), con una marca ilegible y vacía con error del proveedor.
        foreach ([fn (CarbonImmutable $c) => $c->addMilliseconds(420)->format('Y-m-d\TH:i:s.vP'), fn () => 'mañana', fn () => ''] as $marca) {
            $c = $this->hora($azar);
            $in = $this->mensaje($this->canario, MarketingMessage::DIRECTION_INBOUND, 'hola', $c, ['ultron_presence' => [
                'read_attempted' => true, 'read_success' => true, 'typing_attempted' => true, 'typing_success' => $marca($c) !== '',
                'typing_started_at' => $marca($c), 'provider_duration_ms' => 380, 'provider_error' => $marca($c) === '' ? 'timeout' : null,
            ]]);
            $this->entrantes[] = ['id' => $in, 'en' => $c];
            $this->accion($in, $this->decision($azar), cuando: $c->addSeconds(2));
            $this->saliente($c->addSeconds(9), 'Claro, te cuento con gusto.');
        }

        // `metadata` de la acción que no es un objeto: un texto JSON y una lista.
        foreach (['"hola"', '[1, 2, 3]'] as $crudo) {
            $c = $this->hora($azar);
            $in = $this->entrante($c, $azar);
            $this->accion($in, $crudo, 'reply', 'executed', cuando: $c->addSeconds(2), crudo: true);
            $this->saliente($c->addSeconds(10), 'Claro, te cuento.');
        }

        // La clave escrita con un escape (`message` + barra + `u005fid`): SQLite la guarda tal cual y solo
        // decodificando se ve que es `message_id` (PostgreSQL la devuelve normalizada). La barra va con chr(92)
        // para que ninguna herramienta la interprete. Y un `message_id` que no es clave de arriba.
        $escapada = 'message'.chr(92).'u005fid';
        foreach ([
            fn (int $in): string => '{"intent": "clave_escapada", "'.$escapada.'": '.$in.'}',
            fn (int $in): string => '{"intent": "anidada", "sub": {"message_id": '.$in.'}}',
        ] as $crudo) {
            $c = $this->hora($azar);
            $in = $this->entrante($c, $azar);
            $this->accion(null, $crudo($in), 'router_reply', 'executed', 'router', $c->addSeconds(2), crudo: true);
            $this->saliente($c->addSeconds(10), 'Claro, te cuento.');
        }

        // `message_id` de tipos raros (`(int)` de true, 1.9 o ['x'] es 1; de [] y de 'abc', 0): nombran ids bajos.
        $c = $this->hora($azar);
        $in = $this->entrante($c, $azar);
        foreach ([true, 1.9, [], ['x'], 'abc', null] as $raro) {
            $this->accion(null, ['intent' => 'raro', 'message_id' => $raro], 'router_reply', 'executed', 'router', $c->addSecond());
        }
        $this->accion($in, $this->decision($azar), cuando: $c->addSeconds(2));
        $this->saliente($c->addSeconds(10), 'Claro, te cuento.');

        // Sin fecha: entrante, acción y saliente con `created_at` NULL (fuera con --since, dentro sin ventana).
        $in = $this->mensaje($this->canario, MarketingMessage::DIRECTION_INBOUND, 'sin fecha', null);
        $this->entrantes[] = ['id' => $in, 'en' => null];
        $this->accion($in, $this->decision($azar), cuando: null);
        $this->saliente(null, 'Respuesta sin fecha.');

        // Respuesta en el mismo segundo, y una con el reloj por detrás del entrante; un saliente sin texto y con `kind` nulo.
        $c = $this->hora($azar);
        $in = $this->entrante($c, $azar);
        $this->accion($in, $this->decision($azar), cuando: $c);
        $this->saliente($c, 'Al instante.');
        $c = $this->hora($azar);
        $in = $this->entrante($c, $azar);
        $this->accion($in, $this->decision($azar), cuando: $c->addSecond());
        $this->saliente($c->subSeconds(5), null, ['kind' => null]);
    }

    /** Un turno del canario de la clase $clase. */
    private function turno(string $clase, Randomizer $azar): void
    {
        $c = $this->hora($azar);
        $in = $this->entrante($c, $azar);
        $decide = $c->addSeconds($azar->getInt(1, 5));
        $responde = $c->addSeconds($azar->getInt(0, 120));

        switch ($clase) {
            case 'normal':
                $this->accion($in, $this->decision($azar), cuando: $decide);
                $this->saliente($responde, $this->de(self::LIMPIOS, $azar));
                break;
            case 'varios':
                $this->accion($in, $this->decision($azar), cuando: $decide);
                $this->saliente($responde, $this->de(self::LIMPIOS, $azar));
                $this->saliente($responde->addSecond(), 'Tu enlace de pago: https://checkout.wompi.co/l/abc123', ['kind' => 'payment_link']);
                $this->saliente($responde->addSeconds(2), 'Te dejo la foto de la sede.', ['kind' => 'image', 'url' => 'https://cdn.example/sede.jpg']);
                if ($azar->getInt(0, 1) === 1) {
                    $this->saliente($responde->addSeconds(3), '¿Algo más?', ['kind' => null]);
                }
                break;
            case 'sin_respuesta':
                $this->accion($in, ['blocked_reason' => 'repeated_reply', 'outcome' => 'no_reply', 'intent' => 'repetido'], 'no_reply', 'skipped', cuando: $decide);
                break;
            case 'sin_accion':
                if ($azar->getInt(0, 1) === 1) {
                    $this->mensaje($this->canario, MarketingMessage::DIRECTION_OUTBOUND, 'Hola, te escribe Laura del equipo.', $responde, null, MarketingMessage::SENDER_HUMAN);
                }
                break;
            case 'router':
                $this->accion(null, ['intent' => 'router', 'message_id' => $azar->getInt(0, 1) === 1 ? $in : (string) $in], 'router_reply', 'executed', 'router', $decide);
                $this->saliente($responde, $this->de(self::LIMPIOS, $azar));
                break;
            case 'duplicadas':
                $this->accion($in, $this->decision($azar, ['intent' => 'primera_duplicada', 'outcome' => 'error']), 'reply', 'failed', cuando: $decide);
                $this->accion($in, $this->decision($azar, ['intent' => 'segunda_duplicada']), cuando: $decide->addSecond());
                $this->saliente($responde, $this->de(self::LIMPIOS, $azar));
                break;
            case 'empate':
                $this->accion(null, ['intent' => 'router_primero', 'message_id' => $in], 'router_reply', 'executed', 'router', $decide);
                $this->accion($in, $this->decision($azar, ['intent' => 'por_fuente_despues']), cuando: $decide->addSecond());
                $this->saliente($responde, $this->de(self::LIMPIOS, $azar));
                break;
            case 'respaldo':
                $this->accion($in, $this->decision($azar, [
                    'studio' => ['v' => 1, 'env' => 'canary', 'state' => 'fallback', 'reason' => 'hash_mismatch', 'part' => 'canary'],
                    'fallback_mode' => 'safe_reply',
                    'recovery' => ['kind' => 'surrender', 'reason' => 'critic_failed', 'attempts' => 2],
                    'checkout_denial_dropped' => ['frases_retiradas' => $azar->getInt(1, 3)],
                ]), cuando: $decide);
                $this->saliente($responde, 'Ahora mismo no puedo darte ese dato, pero te ayudo con lo demás.');
                break;
            case 'hallazgo':
                $this->accion($in, $this->decision($azar), cuando: $decide);
                $this->saliente($responde, $this->de(self::HALLAZGOS, $azar));
                break;
            case 'nota':
                $this->mensaje($this->canario, 'internal', 'nota interna', $c->addSecond(), null, MarketingMessage::SENDER_SYSTEM);
                $this->accion($in, $this->decision($azar), cuando: $decide);
                $this->saliente($responde, $this->de(self::LIMPIOS, $azar));
                break;
        }
    }

    /** Mensajes y acciones de OTRA conversación (la consulta los deja fuera), alguna nombrando un entrante del canario. */
    private function ruido(Randomizer $azar): void
    {
        $c = $this->reloj->addSeconds(30);
        $in = $this->mensaje($this->otra, MarketingMessage::DIRECTION_INBOUND, 'hola, info de precios', $c);
        $this->accion($in, $this->decision($azar), cuando: $c->addSeconds(2), conversacion: $this->otra);
        $this->mensaje($this->otra, MarketingMessage::DIRECTION_OUTBOUND, 'El Plan Mensual está en $80.000 COP.', $c->addSeconds(20));
        if ($this->entrantes !== [] && $azar->getInt(1, 3) === 1) {
            $suyo = $this->entrantes[array_key_last($this->entrantes)]['id'];
            $this->accion($suyo, ['intent' => 'de_otra_conversacion', 'message_id' => $suyo], cuando: $c->addSeconds(3), conversacion: $this->otra);
        }
    }

    /** Acciones que llegan al final y nombran entrantes antiguos: solo ganan donde no había otra antes. */
    private function tardias(Randomizer $azar): void
    {
        $c = $this->reloj->addMinutes(10);
        foreach ($this->entrantes as $k => $e) {
            if ($azar->getInt(1, 12) !== 1) {
                continue;
            }
            $azar->getInt(0, 1) === 1
                ? $this->accion(null, ['intent' => 'tardia', 'message_id' => $e['id']], 'router_reply', 'executed', 'router', $c->addSeconds($k))
                : $this->accion($e['id'], $this->decision($azar, ['intent' => 'tardia']), cuando: $c->addSeconds($k));
        }
    }

    /** @param  array<string,mixed>  $extra */
    private function decision(Randomizer $azar, array $extra = []): array
    {
        $m = [
            'origin' => 'ultron',
            'intent' => $this->de(['general_info', 'price_question', 'schedule_question', 'greeting'], $azar),
            'strategy_goal' => 'collect_data',
            'next_best_action' => 'ask_discovery',
            'previous_phase' => 'discovery',
            'next_phase' => $this->de(['discovery', 'offer'], $azar),
            'lead_temperature' => 'warm',
            'customer_lifecycle' => 'prospect',
            'outcome' => 'replied',
            'tools_requested' => ['plans'],
            'tools_executed' => $azar->getInt(0, 1) === 1 ? ['plans'] : [],
            'risk_flags' => $azar->getInt(1, 5) === 1 ? ['price_question'] : [],
            'studio' => ['v' => 1, 'env' => 'canary', 'state' => 'applied', 'part' => 'canary', 'reason' => null],
        ];
        if ($azar->getInt(1, 3) === 1) {
            $m['critic'] = ['verdict' => 'pass', 'score' => 0.9, 'attempt' => 1];
        }
        if ($azar->getInt(1, 4) === 1) {
            $m['reference_resolution'] = 'accept_offer';
        }
        if ($azar->getInt(1, 4) === 1) {
            $m['novelty_max_similarity'] = 0.31;
        }
        if ($azar->getInt(1, 6) === 1) {
            $m['needs_staff_review'] = true;
        }
        if ($azar->getInt(1, 8) === 1) {
            $m['recommended_plan_id'] = 1;
        }

        return $extra + $m;
    }

    // ── Filas ───────────────────────────────────────────────────────────────

    /** La hora del siguiente turno: cinco minutos después del anterior, más unos segundos. */
    private function hora(Randomizer $azar): CarbonImmutable
    {
        $this->reloj = $this->reloj->addMinutes(5);

        return $this->reloj->addSeconds($azar->getInt(0, 59));
    }

    private function entrante(CarbonImmutable $cuando, Randomizer $azar): int
    {
        $presencia = match ($azar->getInt(1, 10)) {
            1 => ['ultron_presence' => ['read_attempted' => true, 'read_success' => true, 'typing_attempted' => true, 'typing_success' => true,
                'typing_started_at' => $cuando->addMilliseconds($azar->getInt(80, 900))->format('Y-m-d\TH:i:s.vP')]],
            2 => ['ultron_presence' => ['read_success' => false, 'typing_success' => false, 'provider_error' => 'timeout', 'provider_duration_ms' => 1830]],
            default => null,
        };
        $id = $this->mensaje($this->canario, MarketingMessage::DIRECTION_INBOUND, $this->de(self::ENTRANTES, $azar), $cuando, $presencia);
        $this->entrantes[] = ['id' => $id, 'en' => $cuando];

        return $id;
    }

    /** @param  array<string,mixed>|null  $metadata */
    private function saliente(?CarbonImmutable $cuando, ?string $texto, ?array $metadata = null): int
    {
        return $this->mensaje($this->canario, MarketingMessage::DIRECTION_OUTBOUND, $texto, $cuando, $metadata);
    }

    /** @param  array<string,mixed>|null  $metadata */
    private function mensaje(MarketingConversation $c, string $direccion, ?string $cuerpo, ?CarbonImmutable $cuando, ?array $metadata = null, ?string $remitente = null): int
    {
        return (int) DB::table('marketing_messages')->insertGetId([
            'conversation_id' => $c->id,
            'direction' => $direccion,
            'sender_type' => $remitente ?? ($direccion === MarketingMessage::DIRECTION_INBOUND ? MarketingMessage::SENDER_LEAD : MarketingMessage::SENDER_AI),
            'body' => $cuerpo,
            'status' => $direccion === MarketingMessage::DIRECTION_INBOUND ? 'received' : 'sent',
            'metadata' => $metadata === null ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => $cuando,
            'updated_at' => $cuando,
        ]);
    }

    /** @param  array<string,mixed>|string|null  $metadata  con $crudo, el JSON tal cual */
    private function accion(?int $fuente, array|string|null $metadata, string $tipo = 'reply', string $estado = 'executed', string $origen = 'inbound_message',
        ?CarbonImmutable $cuando = null, ?MarketingConversation $conversacion = null, bool $crudo = false): int
    {
        $c = $conversacion ?? $this->canario;

        return (int) DB::table('marketing_ai_actions')->insertGetId([
            'lead_id' => $c->lead_id,
            'conversation_id' => $c->id,
            'action_type' => $tipo,
            'status' => $estado,
            'source_type' => $origen,
            'source_event_id' => $fuente,
            'metadata' => $crudo || $metadata === null ? $metadata : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => $cuando,
            'updated_at' => $cuando,
        ]);
    }

    private function conversacion(string $telefono): MarketingConversation
    {
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => $telefono,
            'meta_user_id' => '57'.$telefono, 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);

        return MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    private function clase(Randomizer $azar): string
    {
        $tirada = $azar->getInt(1, array_sum(self::PESOS));
        foreach (self::PESOS as $clase => $peso) {
            $tirada -= $peso;
            if ($tirada <= 0) {
                return $clase;
            }
        }

        return 'normal';
    }

    /**
     * @template T
     *
     * @param  list<T>  $opciones
     * @return T
     */
    private function de(array $opciones, Randomizer $azar): mixed
    {
        return $opciones[$azar->getInt(0, count($opciones) - 1)];
    }
}
