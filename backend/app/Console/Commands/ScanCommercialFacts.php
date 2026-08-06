<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Commercial\CommercialEventRecorder;
use App\Services\Commercial\CommercialSubjectResolver;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\Observability\ChannelLog;
use App\Services\Wompi\PaymentStateMachine as SM;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Los hechos que no ocurren: nadie los provoca, hay que ir a buscarlos.
 *
 * Los observers cubren todo lo que alguien hace —pagar, venir, vincular la app—.
 * Pero los hechos comercialmente más caros son los que consisten en que ALGO
 * DEJÓ DE PASAR: la membresía que va a vencer, el socio que no aparece hace
 * tres semanas, el enlace de pago que se generó y nadie abrió. No hay ninguna
 * fila que cambie cuando alguien deja de venir, así que nada los dispara.
 *
 * Este comando es la mirada periódica que los detecta. Corre cada hora y es
 * idempotente por construcción: cada hecho lleva una clave con su fecha, así
 * que veinticuatro pasadas al día producen un evento, no veinticuatro.
 */
class ScanCommercialFacts extends Command
{
    protected $signature = 'commercial:scan
                            {--limit=500 : Techo de sujetos por familia, para no barrer la base entera}
                            {--dry-run : Muestra lo que detectaría sin registrar nada}';

    protected $description = 'Detecta vencimientos, inactividad y enlaces de pago abandonados';

    public function __construct(
        private readonly CommercialEventRecorder $recorder,
        private readonly CommercialSubjectResolver $resolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! (bool) config('commercial.events_enabled', false)) {
            $this->warn('commercial.events_enabled está en false: no se registra nada.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $dry = (bool) $this->option('dry-run');

        $counts = [
            'renewal_window' => $this->scanRenewalWindow($limit, $dry),
            'expired' => $this->scanExpired($limit, $dry),
            'inactivity' => $this->scanInactivity($limit, $dry),
            'abandoned_links' => $this->scanAbandonedPaymentLinks($limit, $dry),
        ];

        foreach ($counts as $family => $n) {
            $this->line(sprintf('  %-18s %d', $family, $n));
        }

        ChannelLog::info('commercial.scan.completed', $counts + ['dry_run' => $dry]);

        return self::SUCCESS;
    }

    /**
     * Membresías que entran en la ventana de renovación.
     *
     * Se avisa ANTES de que venza, no después: renovar a alguien que sigue
     * viniendo es una conversación natural; recuperarlo cuando ya perdió el
     * acceso es una negociación.
     */
    private function scanRenewalWindow(int $limit, bool $dry): int
    {
        $days = (int) config('commercial.thresholds.renewal_window_days', 10);
        $today = Carbon::today();
        $horizon = $today->copy()->addDays($days);

        $users = User::query()
            ->whereNotNull('membership_end_date')
            ->whereBetween('membership_end_date', [$today->toDateString(), $horizon->toDateString()])
            ->limit($limit)
            ->get(['id', 'plan', 'membership_end_date']);

        $n = 0;
        foreach ($users as $user) {
            $endsAt = Carbon::parse($user->membership_end_date);

            $n += $this->emit(
                $dry,
                V::EV_RENEWAL_WINDOW_OPENED,
                $this->resolver->fromUser($user->id),
                [
                    'plan' => $user->plan,
                    'ends_at' => $endsAt->toDateString(),
                    'days_to_expiry' => $today->diffInDays($endsAt, false),
                ],
                // Una sola vez por vencimiento, no una por día de la ventana.
                "renewal:{$user->id}:{$endsAt->toDateString()}",
            );
        }

        return $n;
    }

    /** Membresías que vencieron ayer: el primer día sin acceso. */
    private function scanExpired(int $limit, bool $dry): int
    {
        $yesterday = Carbon::yesterday();

        $users = User::query()
            ->whereNotNull('membership_end_date')
            // Solo el día siguiente al vencimiento. Barrer todo el histórico
            // convertiría la primera ejecución en una avalancha de eventos
            // sobre gente que se fue hace años.
            ->whereDate('membership_end_date', $yesterday->toDateString())
            ->limit($limit)
            ->get(['id', 'plan', 'membership_end_date']);

        $n = 0;
        foreach ($users as $user) {
            $n += $this->emit(
                $dry,
                V::EV_MEMBERSHIP_EXPIRED,
                $this->resolver->fromUser($user->id),
                ['plan' => $user->plan, 'expired_at' => $yesterday->toDateString()],
                "expired:{$user->id}:{$yesterday->toDateString()}",
            );
        }

        return $n;
    }

    /**
     * Socios con membresía vigente que llevan semanas sin venir.
     *
     * Es el hecho más valioso de todos y el más invisible: desde caja parecen
     * clientes sanos —pagaron— hasta el día en que no renuevan. La condición
     * exige membresía ACTIVA a propósito: a quien ya venció se le habla de
     * renovar, no de adherencia.
     */
    private function scanInactivity(int $limit, bool $dry): int
    {
        $days = (int) config('commercial.thresholds.at_risk_days', 14);
        $cutoff = Carbon::today()->subDays($days);

        $members = Member::query()
            ->whereNotNull('user_id')
            ->whereHas('user', fn ($q) => $q->whereNotNull('membership_end_date')
                ->whereDate('membership_end_date', '>=', Carbon::today()->toDateString()))
            ->limit($limit)
            ->get(['id', 'user_id']);

        $n = 0;
        foreach ($members as $member) {
            $lastEntry = Attendance::query()
                ->where('member_id', $member->id)
                ->where('action', 'entry')
                ->max('captured_at');

            // Sin ninguna visita nunca no es inactividad: es un socio que aún no
            // ha empezado, y eso se acompaña de otra manera (onboarding).
            if ($lastEntry === null) {
                continue;
            }

            $last = Carbon::parse($lastEntry);
            if ($last->greaterThan($cutoff)) {
                continue;
            }

            $n += $this->emit(
                $dry,
                V::EV_INACTIVITY_DETECTED,
                $this->resolver->fromMember($member->id),
                [
                    'last_attendance_at' => $last->toIso8601String(),
                    'days_inactive' => $last->diffInDays(Carbon::today()),
                ],
                // Semanal: si sigue sin venir, el motor vuelve a mirarlo, pero
                // no todos los días.
                "inactive:{$member->id}:".Carbon::today()->format('o-\WW'),
            );
        }

        return $n;
    }

    /**
     * Enlaces de pago generados y no usados pasado el plazo de cortesía.
     *
     * El plazo existe para no perseguir a alguien que está pagando en ese
     * momento: recordar un enlace tres minutos después de mandarlo es la forma
     * más rápida de parecer un cobrador.
     */
    private function scanAbandonedPaymentLinks(int $limit, bool $dry): int
    {
        $hours = (int) config('commercial.thresholds.payment_link_grace_hours', 6);
        $cutoff = now()->subHours($hours);

        $transactions = PaymentTransaction::query()
            ->whereIn('status', [SM::CREATED, SM::PENDING, SM::REQUIRES_ACTION, SM::TOKENIZING])
            ->whereNotNull('checkout_url')
            ->where('created_at', '<=', $cutoff)
            // Un enlace de hace tres semanas ya no se recupera; se ofrece uno
            // nuevo, que es otro objetivo.
            ->where('created_at', '>=', now()->subDays(7))
            ->limit($limit)
            ->get(['id', 'member_id', 'plan_id', 'amount', 'reference', 'created_at']);

        $n = 0;
        foreach ($transactions as $tx) {
            $n += $this->emit(
                $dry,
                V::EV_PAYMENT_PENDING,
                $this->resolver->fromMember($tx->member_id),
                [
                    'reference' => $tx->reference,
                    'plan_id' => $tx->plan_id,
                    'amount' => $tx->amount,
                    'abandoned_for_hours' => (int) $tx->created_at->diffInHours(now()),
                    'abandoned' => true,
                ],
                "tx:{$tx->id}:abandoned:".now()->format('Y-m-d'),
            );
        }

        return $n;
    }

    /** @param array{lead_id:?int,member_id:?int} $subject */
    private function emit(bool $dry, string $event, array $subject, array $payload, string $key): int
    {
        if ($subject['lead_id'] === null && $subject['member_id'] === null) {
            return 0; // sin ficha comercial no hay a quién escribir
        }

        if ($dry) {
            $this->line("  [dry] {$event} {$key}");

            return 1;
        }

        return $this->recorder->record($event, $subject, $payload, $key) !== null ? 1 : 0;
    }
}
