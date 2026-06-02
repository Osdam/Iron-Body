<?php

namespace App\Console\Commands;

use App\Models\Story;
use App\Services\FirebaseStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Purga stories expirados — borra archivo del disk y row de la tabla.
 *
 * Uso:
 *   php artisan stories:purge
 *   php artisan stories:purge --dry-run
 *
 * Para agendar diario, añadir en routes/console.php:
 *   Schedule::command('stories:purge')->hourly();
 */
class PurgeExpiredStories extends Command
{
    protected $signature = 'stories:purge {--dry-run : No borrar, solo reportar}';
    protected $description = 'Borra stories expirados (archivo + row).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $expired = Story::where('expires_at', '<=', now())->get();

        if ($expired->isEmpty()) {
            $this->info('No hay stories expirados.');
            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Encontrados {$expired->count()} stories expirados.");

        $deleted = 0;
        foreach ($expired as $story) {
            $this->line("  · #{$story->id} ({$story->type}) creado {$story->created_at} — expiró {$story->expires_at}");

            if ($dryRun) continue;

            try {
                if ($story->isFirebaseStored()) {
                    // Borrado físico vía service account (NO hay disk 'firebase').
                    app(FirebaseStorageService::class)->deleteObject($story->file_path);
                } else {
                    Storage::disk($story->disk)->delete($story->file_path);
                }
            } catch (\Throwable $e) {
                $this->warn("    ! no se pudo borrar el archivo: {$story->file_path}");
            }
            $story->delete();
            $deleted++;
        }

        if (!$dryRun) {
            $this->info("Borrados: $deleted");
        }
        return self::SUCCESS;
    }
}
