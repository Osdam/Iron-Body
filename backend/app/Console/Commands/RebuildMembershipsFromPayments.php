<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Payments\MembershipRebuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula vigencias desde los pagos que siguen cobrados.
 *
 *   php artisan memberships:rebuild                      escanea a todos (solo lee)
 *   php artisan memberships:rebuild --user=7642          qué le quedaría a uno
 *   php artisan memberships:rebuild --user=7642 --apply  lo corrige
 *   php artisan memberships:rebuild --all --apply        corrige a todos los que salgan
 *
 * PARA QUÉ. Los cobros anulados ANTES del 14/09/2026 no devolvieron los días
 * que habían dado, y de aquella época no quedó periodo congelado que devolver
 * (ver MembershipRebuilder). A esos socios les sobra tiempo en la ficha y no
 * hay forma de restarlo pago por pago: hay que rehacer la cuenta desde los
 * pagos que hoy siguen siendo válidos.
 *
 * SIN `--apply` NO ESCRIBE NADA. Y `--apply` sin `--user` exige además `--all`,
 * porque mover de golpe la fecha de acceso de todo el gimnasio no puede ser un
 * descuido de tecleo.
 */
class RebuildMembershipsFromPayments extends Command
{
    protected $signature = 'memberships:rebuild
        {--user= : Un solo socio (users.id)}
        {--all : Autoriza tocar a todos los socios que salgan}
        {--apply : Guarda los cambios. Sin esto solo informa}';

    protected $description = 'Recalcula la vigencia de los socios a partir de sus pagos cobrados';

    public function handle(MembershipRebuilder $rebuilder): int
    {
        $aplicar = (bool) $this->option('apply');
        $unSocio = $this->option('user');

        if ($aplicar && ! $unSocio && ! $this->option('all')) {
            $this->error('Para escribir sobre TODOS los socios añade --all. Con --user=<id> se corrige uno solo.');

            return self::FAILURE;
        }

        $socios = User::query()
            ->when($unSocio, fn ($q) => $q->where('id', (int) $unSocio))
            // Sin plan y sin fecha no hay nada que recalcular ni que sobre.
            ->when(! $unSocio, fn ($q) => $q->whereNotNull('membership_end_date'))
            ->orderBy('id')
            ->get();

        if ($socios->isEmpty()) {
            $this->info('No hay socios que revisar.');

            return self::SUCCESS;
        }

        $this->line('Revisando '.$socios->count().' socio(s)…');
        $this->newLine();

        $filas = [];
        $corregidos = 0;
        $omitidos = 0;

        foreach ($socios as $user) {
            $previo = $rebuilder->preview($user);

            if ($previo['skipped'] !== null) {
                // Solo interesa el ruido cuando se preguntó por alguien concreto.
                if ($unSocio) {
                    $this->warn("  #{$user->id} {$user->name}: no se puede reconstruir — {$previo['skipped']}");
                }
                $omitidos++;

                continue;
            }

            $cambia = $user->membership_start_date !== $previo['start']
                || $user->membership_end_date !== $previo['end'];

            if (! $cambia) {
                if ($unSocio) {
                    $this->info("  #{$user->id} {$user->name}: su vigencia ya cuadra con sus pagos. Nada que hacer.");
                }

                continue;
            }

            $filas[] = [
                $user->id,
                $user->name,
                ($user->membership_start_date ?: '—').' → '.($user->membership_end_date ?: '—'),
                ($previo['start'] ?: '—').' → '.($previo['end'] ?: '—'),
                $previo['applied'].' pago(s)',
                $this->warning($user, $previo),
            ];

            if ($aplicar) {
                $hecho = DB::transaction(fn () => $rebuilder->rebuild($user));
                if ($hecho) {
                    $this->audit($user, $hecho);
                    $corregidos++;
                }
            }
        }

        if ($filas === []) {
            $this->info('Ninguna vigencia se aparta de sus pagos.'
                .($omitidos ? " ({$omitidos} sin reconstruir)" : ''));

            return self::SUCCESS;
        }

        $this->table(['#', 'socio', 'tiene', 'le corresponde', 'según', 'ojo'], $filas);
        $this->newLine();
        $this->line($aplicar
            ? "Corregidos: {$corregidos}. Sin reconstruir: {$omitidos}."
            : count($filas).' socio(s) con la vigencia estirada. Repite con --apply para corregir.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Avisa cuando corregir la fecha le cierra la puerta a alguien que HOY
     * entra al gimnasio. Pasa cuando el cobro que sobrevive está fechado a
     * futuro: la cuenta es correcta, pero el socio se queda fuera hasta ese
     * día y alguien tiene que decidirlo a sabiendas, no descubrirlo en la
     * recepción.
     *
     * @param  array{start: ?string, end: ?string, applied: int, skipped: ?string}  $previo
     */
    private function warning(User $user, array $previo): string
    {
        $hoy = MembershipRebuilder::today();
        $entraHoy = $user->membership_end_date !== null
            && CarbonImmutable::parse($user->membership_end_date, MembershipRebuilder::TZ)->gte($hoy);

        if (! $entraHoy) {
            return '';
        }

        if ($previo['end'] === null) {
            return 'QUEDA SIN ACCESO';
        }
        if (CarbonImmutable::parse($previo['end'], MembershipRebuilder::TZ)->lt($hoy)) {
            return 'QUEDA VENCIDO HOY';
        }
        if ($previo['start'] !== null
            && CarbonImmutable::parse($previo['start'], MembershipRebuilder::TZ)->gt($hoy)) {
            return 'NO ENTRA HASTA '.$previo['start'];
        }

        return '';
    }

    /** @param array<string, mixed> $hecho */
    private function audit(User $user, array $hecho): void
    {
        AuditLog::create(array_filter([
            'action' => 'update',
            'module' => 'members',
            'entity' => 'membership',
            'entity_id' => (string) $user->id,
            'target_name' => $user->name,
            'actor_name' => 'memberships:rebuild',
            'summary' => 'Vigencia recalculada desde los pagos cobrados: '
                .($hecho['previous_end'] ?: '—').' → '.($hecho['end'] ?: '—'),
            'metadata' => $hecho,
        ], static fn ($v) => $v !== null));
    }
}
