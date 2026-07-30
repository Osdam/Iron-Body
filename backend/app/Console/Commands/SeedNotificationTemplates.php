<?php

namespace App\Console\Commands;

use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationCatalog;
use Illuminate\Console\Command;

/**
 * Siembra el catálogo de plantillas. Idempotente y NO destructivo: si el CRM
 * editó un texto, se respeta. Solo repone las que falten y actualiza la
 * categoría/subtipo de las sembradas, que son estructura y no redacción.
 */
class SeedNotificationTemplates extends Command
{
    protected $signature = 'notifications:seed-templates {--force-text : Restaura también el texto original de las plantillas sembradas}';

    protected $description = 'Siembra o repone las plantillas de motivación, hábitos y suplementos.';

    public function handle(): int
    {
        $created = 0;
        $updated = 0;
        $kept = 0;

        foreach (NotificationCatalog::templates() as $row) {
            $template = NotificationTemplate::query()->firstWhere('key', $row['key']);

            if ($template === null) {
                NotificationTemplate::create($row + ['is_seeded' => true, 'is_active' => true, 'version' => 1]);
                $created++;

                continue;
            }

            $changes = [
                'category' => $row['category'],
                'supplement_kind' => $row['supplement_kind'],
                'is_seeded' => true,
            ];

            if ($this->option('force-text')) {
                $changes['title'] = $row['title'];
                $changes['body'] = $row['body'];
                $changes['disclaimer'] = $row['disclaimer'];
                $changes['version'] = ((int) $template->version) + 1;
            }

            $template->fill($changes);
            $template->isDirty() ? $updated++ : $kept++;
            $template->save();
        }

        $this->info("Plantillas: {$created} creadas, {$updated} actualizadas, {$kept} sin cambios.");

        return self::SUCCESS;
    }
}
