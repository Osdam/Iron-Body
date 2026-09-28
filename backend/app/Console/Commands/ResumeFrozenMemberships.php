<?php

namespace App\Console\Commands;

use App\Services\Membership\MembershipFreeze;
use Illuminate\Console\Command;

/**
 * Reanuda las membresías cuyo congelamiento ya cumplió su plazo.
 *
 * Corre a diario. Es la contraparte imprescindible de congelar: sin esto, una
 * pausa de siete días duraría hasta que alguien se acordara de deshacerla a
 * mano, y el socio se quedaría fuera pagando.
 *
 * IDEMPOTENTE: al reanudar se borran las fechas del congelamiento, así que una
 * segunda corrida del mismo día no encuentra a nadie y no devuelve días dos
 * veces.
 */
class ResumeFrozenMemberships extends Command
{
    protected $signature = 'memberships:resume-frozen {--dry-run : Solo enumera a quién le toca}';

    protected $description = 'Reanuda las membresías congeladas cuyo plazo ya terminó y les devuelve sus días';

    public function handle(MembershipFreeze $freeze): int
    {
        if ($this->option('dry-run')) {
            $pendientes = \App\Models\User::query()
                ->whereNotNull('membership_frozen_until')
                ->whereDate('membership_frozen_until', '<=', MembershipFreeze::today()->toDateString())
                ->get(['id', 'name', 'membership_frozen_until', 'membership_frozen_days_left']);

            foreach ($pendientes as $user) {
                $this->line(sprintf(
                    '#%d %s · venció el %s · %d día(s) por devolver',
                    $user->id,
                    $user->name,
                    $user->membership_frozen_until,
                    (int) $user->membership_frozen_days_left,
                ));
            }

            $this->info($pendientes->count().' membresía(s) por reanudar.');

            return self::SUCCESS;
        }

        $reanudadas = $freeze->resumeDue();

        foreach ($reanudadas as $fila) {
            $this->line(sprintf(
                'Reanudada #%d %s · %d día(s) devueltos',
                $fila['user_id'],
                $fila['name'],
                $fila['days_returned'],
            ));
        }

        $this->info($reanudadas === [] ? 'No había ninguna por reanudar.' : count($reanudadas).' membresía(s) reanudadas.');

        return self::SUCCESS;
    }
}
