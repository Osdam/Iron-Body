<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Commercial\CommercialEventRecorder;
use App\Services\Commercial\CommercialSubjectResolver;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\MembershipService;
use App\Services\Observability\ChannelLog;
use App\Services\Wompi\PaymentStateMachine as SM;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
 *
 * EL DÍA ES EL DEL GIMNASIO ({@see Member::BUSINESS_TZ}), no el del servidor.
 * La aplicación corre en UTC y este comando, cada hora en punto UTC: con
 * `Carbon::today()` el «hoy» cambiaba a las 19:00 de Bogotá, y la primera
 * pasada de cada día UTC abría la ventana de renovación con un día de más,
 * daba por vencida una membresía que aún valía hasta la medianoche local y
 * movía la semana de la inactividad el domingo a las 19:00 (informe del
 * tester I-6, D-1 y A-1). Las claves de deduplicación conservan su forma.
 *
 * CADA FAMILIA VE A TODOS SUS CANDIDATOS. Antes cada una pedía `limit(500)`
 * sin orden y sin mirar lo ya registrado: con 591 socios vigentes, la
 * inactividad miraba siempre los mismos 500 (los que devolviera el plan) y el
 * resto no tenía nunca su hecho. Ahora cada familia recorre a sus candidatos
 * por id, en trozos ({@see self::walk()}), y `--limit` es un techo de hechos
 * NUEVOS por familia y pasada, no de sujetos mirados. Ver el docblock de
 * `walk()` para por qué nadie se queda atrás para siempre.
 */
class ScanCommercialFacts extends Command
{
    protected $signature = 'commercial:scan
                            {--limit=500 : Techo de hechos NUEVOS por familia y pasada (contra una avalancha); lo que no quepa se registra en la siguiente}
                            {--dry-run : Muestra lo que detectaría sin registrar nada}';

    protected $description = 'Detecta vencimientos, inactividad y enlaces de pago abandonados';

    /** Sujetos por trozo del recorrido: acota la memoria, sea cual sea el techo. */
    private const CHUNK = 500;

    /** @var array<string, true> familias que llegaron al techo con candidatos sin mirar */
    private array $truncated = [];

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
        // La instancia de la orden se reutiliza entre llamadas del mismo proceso
        // (`Artisan::call`, las pruebas): cada pasada empieza sin recortes.
        $this->truncated = [];

        $counts = [
            'renewal_window' => $this->scanRenewalWindow($limit, $dry),
            'expired' => $this->scanExpired($limit, $dry),
            'inactivity' => $this->scanInactivity($limit, $dry),
            'abandoned_links' => $this->scanAbandonedPaymentLinks($limit, $dry),
        ];

        foreach ($counts as $family => $n) {
            $this->line(sprintf('  %-18s %d', $family, $n));
        }

        if ($this->truncated !== []) {
            $families = array_keys($this->truncated);
            // En seco no se registra nada, así que cada candidato cuenta para el techo, también el que ya tiene su
            // hecho: decir «hechos nuevos» y «lo ya registrado no cuenta» sería falso, y una pasada de prueba no es
            // un recorte real que avisar en el registro de producción (la lanza `h9-outreach-scope.sh`).
            $this->warn($dry
                ? sprintf(
                    'Techo de %d candidatos alcanzado en: %s. En seco cuenta cada candidato, también los que ya tienen su hecho: una pasada real registra solo los nuevos y puede llegar más lejos.',
                    $limit,
                    implode(', ', $families),
                )
                : sprintf(
                    'Techo de %d hechos nuevos alcanzado en: %s. Quedan candidatos sin mirar; la siguiente pasada sigue con ellos (lo ya registrado no cuenta para el techo).',
                    $limit,
                    implode(', ', $families),
                ));
            if (! $dry) {
                ChannelLog::warning('commercial.scan.truncated', ['families' => $families, 'limit' => $limit, 'dry_run' => false]);
            }
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
        $today = self::gymToday();
        $horizon = $today->copy()->addDays($days);

        $users = User::query()
            ->select(['id', 'plan', 'membership_end_date'])
            ->whereNotNull('membership_end_date')
            ->whereBetween('membership_end_date', [$today->toDateString(), $horizon->toDateString()]);

        return $this->walk('renewal_window', $users, $limit, function (User $user) use ($dry, $today): int {
            // A medianoche del gimnasio, como `$today`: los días que faltan
            // son días enteros de Bogotá.
            $endsAt = Carbon::parse($user->membership_end_date, Member::BUSINESS_TZ)->startOfDay();

            return $this->emit(
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
        });
    }

    /**
     * Membresías que vencieron ayer: el primer día sin acceso.
     *
     * «Ayer» del gimnasio: la membresía vale hasta el final de su último día
     * de Bogotá ({@see MembershipService::endsAt()}), así que el hecho no
     * puede llegar antes de esa medianoche local.
     */
    private function scanExpired(int $limit, bool $dry): int
    {
        $yesterday = self::gymToday()->subDay();

        $users = User::query()
            ->select(['id', 'plan', 'membership_end_date'])
            ->whereNotNull('membership_end_date')
            // Solo el día siguiente al vencimiento. Barrer todo el histórico
            // convertiría la primera ejecución en una avalancha de eventos
            // sobre gente que se fue hace años.
            ->whereDate('membership_end_date', $yesterday->toDateString());

        return $this->walk('expired', $users, $limit, fn (User $user): int => $this->emit(
            $dry,
            V::EV_MEMBERSHIP_EXPIRED,
            $this->resolver->fromUser($user->id),
            ['plan' => $user->plan, 'expired_at' => $yesterday->toDateString()],
            "expired:{$user->id}:{$yesterday->toDateString()}",
        ));
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
        $today = self::gymToday();
        $cutoff = $today->copy()->subDays($days);

        $members = Member::query()
            ->select(['id', 'user_id'])
            ->whereNotNull('user_id')
            ->whereHas('user', fn ($q) => $q->whereNotNull('membership_end_date')
                ->whereDate('membership_end_date', '>=', $today->toDateString()));

        return $this->walk('inactivity', $members, $limit, function (array $candidate) use ($dry, $today): int {
            [$member, $last] = $candidate;

            return $this->emit(
                $dry,
                V::EV_INACTIVITY_DETECTED,
                $this->resolver->fromMember($member->id),
                [
                    'last_attendance_at' => $last->toIso8601String(),
                    'days_inactive' => $last->diffInDays($today),
                ],
                // Semanal: si sigue sin venir, el motor vuelve a mirarlo, pero
                // no todos los días.
                "inactive:{$member->id}:".$today->format('o-\WW'),
            );
        }, function (Collection $members) use ($cutoff): iterable {
            // La última entrada de TODO el trozo en una consulta, no una por
            // socio (antes, un `max(captured_at)` por socio vigente).
            $lastEntries = Attendance::query()->toBase()
                ->whereIn('member_id', $members->pluck('id')->all())
                ->where('action', 'entry')
                ->groupBy('member_id')
                ->selectRaw('member_id, max(captured_at) as last_entry')
                ->pluck('last_entry', 'member_id');

            foreach ($members as $member) {
                $lastEntry = $lastEntries[$member->id] ?? null;

                // Sin ninguna visita nunca no es inactividad: es un socio que aún no
                // ha empezado, y eso se acompaña de otra manera (onboarding).
                if ($lastEntry === null) {
                    continue;
                }

                $last = Carbon::parse($lastEntry);
                if ($last->greaterThan($cutoff)) {
                    continue;
                }

                yield [$member, $last];
            }
        });
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
            ->select(['id', 'member_id', 'plan_id', 'amount', 'reference', 'created_at'])
            ->whereIn('status', [SM::CREATED, SM::PENDING, SM::REQUIRES_ACTION, SM::TOKENIZING])
            ->whereNotNull('checkout_url')
            ->where('created_at', '<=', $cutoff)
            // Un enlace de hace tres semanas ya no se recupera; se ofrece uno
            // nuevo, que es otro objetivo.
            ->where('created_at', '>=', now()->subDays(7));

        return $this->walk('abandoned_links', $transactions, $limit, fn (PaymentTransaction $tx): int => $this->emit(
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
            "tx:{$tx->id}:abandoned:".self::gymToday()->format('Y-m-d'),
        ));
    }

    /**
     * Recorre TODOS los candidatos de una familia, por id y en trozos de
     * {@see self::CHUNK}, y para cuando ha registrado `$limit` hechos NUEVOS.
     *
     * - Memoria acotada: nunca hay más de un trozo cargado.
     * - Orden estable (id ascendente), así que dos pasadas ven a los mismos
     *   candidatos en el mismo orden.
     * - El techo cuenta lo que `emit()` registra de verdad (1), no lo mirado.
     *   Lo ya registrado devuelve 0: la clave de deduplicación lleva el hecho
     *   y su fecha, y el registrador lo para en su lectura previa. Así que la
     *   pasada siguiente atraviesa lo que ya emitió la anterior sin gastar techo
     *   y llega a los que no cupieron. Con un conjunto de candidatos estable de
     *   C sujetos, todos tienen su hecho en ⌈C / limit⌉ pasadas; nadie se queda
     *   atrás para siempre mientras entren menos de `limit` candidatos nuevos
     *   por pasada (y si entran más, la orden lo avisa en cada pasada).
     * - Si para con candidatos sin mirar, la familia queda en `$truncated` y la
     *   orden lo dice.
     *
     * `$filter`, si viene, recibe cada trozo y devuelve (en el mismo orden) los
     * candidatos que de verdad cuentan; el techo solo se mira antes de emitir.
     *
     * @param  \Closure(mixed): int  $emit
     * @param  (\Closure(Collection): iterable<mixed>)|null  $filter
     */
    private function walk(string $family, Builder $query, int $limit, \Closure $emit, ?\Closure $filter = null): int
    {
        $n = 0;

        $query->chunkById(self::CHUNK, function (Collection $rows) use (&$n, $family, $limit, $emit, $filter): bool {
            foreach ($filter !== null ? $filter($rows) : $rows as $candidate) {
                if ($n >= $limit) {
                    $this->truncated[$family] = true;

                    return false;
                }

                $n += $emit($candidate);
            }

            return true;
        });

        return $n;
    }

    /** Medianoche de hoy en el gimnasio (lo que antes era `Carbon::today()` en UTC). */
    private static function gymToday(): Carbon
    {
        return Carbon::today(Member::BUSINESS_TZ);
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
