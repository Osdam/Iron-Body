<?php

namespace App\Console\Commands\Marketing;

use App\Models\MarketingKnowledgeItem;
use Illuminate\Console\Command;

/**
 * Revisar lo que la máquina propuso para la base de conocimiento.
 *
 * RC-4 dejó que lo escrito con el secreto compartido naciera en BORRADOR, que
 * es lo correcto: ese secreto identifica una máquina, no a una persona con
 * autoridad para definir los hechos del gimnasio. Pero cerró la puerta sin
 * abrir la ventana —`approve()` no tenía ni un llamador en `app/`—, así que lo
 * único que quedaba para publicar era `tinker` en el servidor. Una frontera que
 * solo se puede cruzar improvisando no es una frontera: es un atasco, y los
 * atascos se resuelven saltándoselos.
 *
 * Esto es la vía. Corre en el servidor, así que la autoridad es tener acceso a
 * él, y exige decir QUIÉN aprueba: una aprobación anónima no es una revisión.
 * Enseña el contenido entero antes de publicar, porque aprobar sin leer es
 * exactamente el agujero que RC-4 vino a tapar.
 */
class MarketingKnowledgeReview extends Command
{
    protected $signature = 'marketing:knowledge-review
        {key? : la clave del ítem a revisar; sin ella, lista lo pendiente}
        {--by= : quién revisa (etiqueta corta para la auditoría); obligatorio al decidir}
        {--reject : rechazar en vez de aprobar}
        {--all : listar todo, no solo lo pendiente}';

    protected $description = 'Lista, aprueba o rechaza el conocimiento propuesto para ULTRON.';

    public function handle(): int
    {
        $key = $this->argument('key');

        if ($key === null) {
            return $this->listar();
        }

        $item = MarketingKnowledgeItem::where('key', $key)->first();
        if ($item === null) {
            $this->error('No existe ningún ítem con la clave '.$key.'.');

            return self::FAILURE;
        }

        $quien = MarketingKnowledgeItem::actorLabel((string) $this->option('by'));
        if ($quien === null) {
            $this->error('Falta --by: una aprobación sin nombre no es una revisión.');

            return self::FAILURE;
        }

        $this->mostrar($item);

        if ($this->option('reject')) {
            $item->reject($quien);
            $this->warn('RECHAZADO. No llega al asesor, y queda el rastro de que se descartó.');

            return self::SUCCESS;
        }

        if ($item->isPublishable()) {
            $this->info('Ya estaba aprobado; no se toca nada.');

            return self::SUCCESS;
        }

        $item->approve($quien);
        $this->info('APROBADO por '.$quien.'. A partir del próximo turno, el asesor lo puede afirmar.');

        return self::SUCCESS;
    }

    private function listar(): int
    {
        $items = MarketingKnowledgeItem::query()
            ->when(! $this->option('all'), fn ($q) => $q->pendingReview())
            ->orderBy('category')->orderBy('key')
            ->get();

        if ($items->isEmpty()) {
            $this->info($this->option('all') ? 'La base de conocimiento está vacía.' : 'No hay nada esperando revisión.');

            return self::SUCCESS;
        }

        foreach ($items as $item) {
            $this->line(sprintf(
                '%-28s %-16s %-10s %-12s %s',
                $item->key,
                $item->category,
                $item->review_status,
                (string) $item->origin,
                $item->reachesPrompt() ? 'EN EL PROMPT' : '—',
            ));
        }

        $this->newLine();
        $this->line('Para leer y decidir:  php artisan marketing:knowledge-review <key> --by="tu nombre" [--reject]');

        return self::SUCCESS;
    }

    private function mostrar(MarketingKnowledgeItem $item): void
    {
        $this->newLine();
        $this->line('clave      : '.$item->key);
        $this->line('categoría  : '.$item->category);
        $this->line('procedencia: '.$item->origin.($item->submitted_by !== null ? ' · lo propuso '.$item->submitted_by : ''));
        $this->line('estado     : '.$item->review_status);
        $this->line('título     : '.(string) $item->title);
        $this->newLine();
        // El contenido ENTERO: lo que se aprueba es lo que el asesor va a poder
        // afirmarle a un cliente, y recortarlo aquí sería aprobar a ciegas.
        $this->line($item->content);
        $this->newLine();
    }
}
