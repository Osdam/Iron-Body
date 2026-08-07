<?php

namespace App\Console\Commands;

use App\Models\MarketingConversation;
use App\Services\Marketing\InboxContextService;
use App\Services\Marketing\MarketingInboxService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Medidor del Inbox: cuánto tarda cada cosa y cuántas consultas cuesta.
 *
 * Mide DOS cosas por escenario, y las dos hacen falta. El tiempo dice cómo se
 * siente hoy; el número de consultas dice cómo va a envejecer. Un endpoint que
 * tarda 40 ms haciendo 21 consultas está bien hoy y va a doler el día que la
 * base tenga latencia de red o carga real, y eso no se ve en el reloj.
 *
 * Se reportan p50, p95 y p99, no la media. La media esconde exactamente lo que
 * importa: si una de cada veinte veces tarda dos segundos, quien atiende lo
 * nota todo el día y la media sigue diciendo que va bien.
 *
 * La primera iteración se descarta: incluye el autoload, la conexión y los
 * cachés fríos, y medirla es medir el arranque, no la consulta.
 */
class BenchmarkInbox extends Command
{
    protected $signature = 'marketing:inbox-bench
        {--iterations=40 : Repeticiones por escenario}
        {--json= : Escribe el resultado como JSON en esta ruta}
        {--only= : Ejecuta solo los escenarios cuyo nombre contenga esto}';

    protected $description = 'Mide latencia (p50/p95/p99) y consultas de cada operación del Inbox.';

    public function handle(MarketingInboxService $inbox, InboxContextService $context): int
    {
        $iterations = max(3, (int) $this->option('iterations'));
        $only = (string) $this->option('only');

        $longest = $this->longestConversation();

        if ($longest === null) {
            $this->error('No hay conversaciones para medir. Ejecuta marketing:bench-seed primero.');

            return self::FAILURE;
        }

        $messageCount = DB::table('marketing_messages')
            ->where('conversation_id', $longest->id)->count();

        $this->line(sprintf(
            'Base: %d conversaciones · %d mensajes · la más larga tiene %d.',
            DB::table('marketing_conversations')->count(),
            DB::table('marketing_messages')->count(),
            $messageCount,
        ));
        $this->newLine();

        $scenarios = [
            'lista: primera pagina' => fn () => $inbox->list($this->request([]), null)
                ->through(fn ($c) => $inbox->presentListItem($c)),

            'lista: filtro sin leer' => fn () => $inbox->list($this->request(['unread' => 'true']), null)
                ->through(fn ($c) => $inbox->presentListItem($c)),

            'lista: filtro IA pausada' => fn () => $inbox->list($this->request(['ai' => 'paused']), null)
                ->through(fn ($c) => $inbox->presentListItem($c)),

            'lista: filtro por etiqueta' => fn () => $inbox->list($this->request(['tag' => 'meta-ads']), null)
                ->through(fn ($c) => $inbox->presentListItem($c)),

            'lista: busqueda por texto' => fn () => $inbox->list($this->request(['q' => 'trimestral']), null)
                ->through(fn ($c) => $inbox->presentListItem($c)),

            'lista: busqueda por telefono' => fn () => $inbox->list($this->request(['q' => '900001']), null)
                ->through(fn ($c) => $inbox->presentListItem($c)),

            'detalle: abrir conversacion larga' => fn () => $inbox->detail($this->fresh($longest->id)),

            'historial: pagina anterior' => function () use ($inbox, $longest) {
                $conversation = $this->fresh($longest->id);
                $first = $inbox->messagePage($conversation, before: null);

                return $inbox->messagePage($conversation, before: $first['next_cursor']);
            },

            'panel derecho: contexto' => fn () => $context->build($this->fresh($longest->id), false),

            'metricas' => fn () => $inbox->metrics(),
        ];

        $results = [];

        foreach ($scenarios as $name => $scenario) {
            if ($only !== '' && ! str_contains($name, $only)) {
                continue;
            }

            $results[$name] = $this->measure($name, $scenario, $iterations);
        }

        $this->render($results);

        if ($path = $this->option('json')) {
            file_put_contents((string) $path, json_encode([
                'measured_at' => now()->toIso8601String(),
                'iterations' => $iterations,
                'volume' => [
                    'conversations' => DB::table('marketing_conversations')->count(),
                    'messages' => DB::table('marketing_messages')->count(),
                    'longest_conversation_messages' => $messageCount,
                ],
                'scenarios' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");

            $this->info("Resultado escrito en {$path}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  callable():mixed  $scenario
     * @return array<string,mixed>
     */
    private function measure(string $name, callable $scenario, int $iterations): array
    {
        $samples = [];
        $queries = 0;
        $bytes = 0;
        $error = null;

        // Calentamiento: la primera vez paga el autoload y la conexión. Medirla
        // es medir el arranque de PHP, no la consulta.
        try {
            $scenario();
        } catch (Throwable $e) {
            return ['error' => class_basename($e).': '.$e->getMessage()];
        }

        for ($i = 0; $i < $iterations; $i++) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $startedAt = hrtime(true);

            try {
                $result = $scenario();
            } catch (Throwable $e) {
                $error = class_basename($e).': '.$e->getMessage();
                DB::disableQueryLog();

                break;
            }

            $samples[] = (hrtime(true) - $startedAt) / 1e6;

            $log = DB::getQueryLog();
            DB::disableQueryLog();

            $queries = count($log);

            // El tamaño de la respuesta importa tanto como el tiempo: lo que
            // no viaja no hay que esperarlo ni que analizarlo en el navegador.
            if ($i === 0) {
                $bytes = strlen((string) json_encode($this->serializable($result)));
            }
        }

        if ($error !== null) {
            return ['error' => $error];
        }

        sort($samples);

        $this->line(sprintf('  %-34s %6.1f ms p95 · %2d consultas', $name, $this->percentile($samples, 95), $queries));

        return [
            'p50_ms' => round($this->percentile($samples, 50), 2),
            'p95_ms' => round($this->percentile($samples, 95), 2),
            'p99_ms' => round($this->percentile($samples, 99), 2),
            'min_ms' => round($samples[0], 2),
            'max_ms' => round($samples[count($samples) - 1], 2),
            'queries' => $queries,
            'payload_bytes' => $bytes,
        ];
    }

    private function serializable(mixed $result): mixed
    {
        if (is_object($result) && method_exists($result, 'toArray')) {
            return $result->toArray();
        }

        return $result;
    }

    /** @param array<int,float> $sorted */
    private function percentile(array $sorted, int $p): float
    {
        if ($sorted === []) {
            return 0.0;
        }

        // Índice más cercano por rango: con 40 muestras, interpolar da una
        // falsa precisión que no tenemos.
        $index = (int) ceil(($p / 100) * count($sorted)) - 1;

        return $sorted[max(0, min($index, count($sorted) - 1))];
    }

    /** @param array<string,mixed> $results */
    private function render(array $results): void
    {
        $this->newLine();

        $rows = [];

        foreach ($results as $name => $r) {
            if (isset($r['error'])) {
                $rows[] = [$name, 'ERROR', '', '', '', $r['error']];

                continue;
            }

            $rows[] = [
                $name,
                sprintf('%.1f', $r['p50_ms']),
                sprintf('%.1f', $r['p95_ms']),
                sprintf('%.1f', $r['p99_ms']),
                (string) $r['queries'],
                $this->humanBytes($r['payload_bytes']),
            ];
        }

        $this->table(['escenario', 'p50 ms', 'p95 ms', 'p99 ms', 'consultas', 'payload'], $rows);
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes < 1024 * 1024
            ? sprintf('%d KB', (int) round($bytes / 1024))
            : sprintf('%.1f MB', $bytes / 1048576);
    }

    /** @param array<string,string> $query */
    private function request(array $query): Request
    {
        return Request::create('/api/admin/marketing/inbox/conversations', 'GET', $query);
    }

    private function fresh(int $id): MarketingConversation
    {
        return MarketingConversation::findOrFail($id);
    }

    private function longestConversation(): ?object
    {
        return DB::table('marketing_messages')
            ->select('conversation_id as id', DB::raw('count(*) as total'))
            ->groupBy('conversation_id')
            ->orderByDesc('total')
            ->first();
    }
}
