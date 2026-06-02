<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Detecta membresías por vencer → emite membership.expiring. */
class DetectMembershipExpiring extends Command
{
    protected $signature = 'ironbody:detect-membership-expiring {--expiring-days=3}';
    protected $description = 'Detecta membresías por vencer y emite membership.expiring.';

    public function handle(): int
    {
        return $this->call('ironbody:emit-automation-events', [
            '--only' => 'membership.expiring',
            '--expiring-days' => $this->option('expiring-days'),
        ]);
    }
}
