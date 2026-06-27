<?php

namespace App\Console\Commands;

use Database\Seeders\MarketingKnowledgeSeeder;
use Illuminate\Console\Command;

/**
 * Siembra/reejecuta la base de conocimiento comercial. IDEMPOTENTE: el seeder
 * hace upsert por `key`, así que correrlo varias veces no duplica; actualiza el
 * contenido base si la key ya existe. No toca pagos ni facturación.
 */
class MarketingKnowledgeSeed extends Command
{
    protected $signature = 'marketing:knowledge-seed';
    protected $description = 'Siembra (idempotente) la base de conocimiento comercial de Iron Body.';

    public function handle(MarketingKnowledgeSeeder $seeder): int
    {
        $seeder->setContainer($this->laravel)->setCommand($this);
        $seeder->run();

        $this->info('Base de conocimiento comercial sembrada/actualizada (idempotente).');
        return self::SUCCESS;
    }
}
