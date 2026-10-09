<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\PaymentController;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Membership\MembershipAccess;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * SOCIOS CON FECHAS QUE NO PUEDEN SER: la vigencia que empieza en el futuro o
 * termina dentro de años.
 *
 * DE DÓNDE SALEN. El importador del sistema anterior copia `inicio` y `fin` de
 * la fila que TERMINA MÁS TARDE de cada persona, y entre esas filas hay cobros
 * que no son membresías —la «Comisión de personalizados» estaba cargada como
 * un plan de treinta días—. Resultado: hay socios cuya vigencia la fija una
 * comisión, con inicio en marzo de 2027. Y como la membresía todavía no ha
 * empezado, la puerta no les deja entrar aunque tengan su anualidad pagada.
 *
 * ESTE COMANDO NO ADIVINA. Enseña la fecha que tiene puesta, el plan que se la
 * puso y el ÚLTIMO PAGO REAL de esa persona con el periodo que cubrió, que es
 * con lo que un humano decide. Con `--fix` escribe la vigencia que sale de ese
 * pago real, y sin él no toca nada.
 *
 * Hace falta porque los export se reimportan cada tanto: esto no es una
 * limpieza de una vez, es una revisión que va a repetirse.
 */
class MembershipFutureDatesCommand extends Command
{
    protected $signature = 'members:future-dates
        {--months=6 : Marca también las vigencias que terminan más allá de estos meses}
        {--fix : Escribe la vigencia que sale del último pago real. Sin esto no toca nada}
        {--user= : Revisa un solo socio por su id}
        {--start= : Fecha de inicio EXACTA (YYYY-MM-DD). Solo con --user y --fix}
        {--end= : Fecha de fin EXACTA (YYYY-MM-DD). Solo con --user y --fix}
        {--plan= : Nombre del plan a dejar puesto. Solo con --user y --fix}
        {--limit=200 : Cuántos listar}';

    protected $description = 'Socios con la vigencia empezando en el futuro o terminando dentro de años, y de qué pago salió.';

    public function handle(): int
    {
        $hoy = MembershipPeriod::today();
        $tope = $hoy->addMonths(max(1, (int) $this->option('months')));

        // ESCRIBIR FECHAS A MANO ES SOBRE UNA PERSONA, Y CON LAS DOS PUESTAS.
        // Soltarlo sobre una lista pondría la misma vigencia a todo el mundo,
        // que es el peor accidente que podría tener este comando.
        if ($this->manual() !== null) {
            if (! $this->option('user') || ! $this->option('fix')) {
                $this->error('--start y --end solo valen junto a --user y --fix.');

                return self::FAILURE;
            }

            $m = $this->manual();
            foreach (['start' => $m['start'], 'end' => $m['end']] as $cual => $valor) {
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
                    $this->error('--'.$cual.' tiene que ser una fecha YYYY-MM-DD.');

                    return self::FAILURE;
                }
            }
            if ($m['end'] < $m['start']) {
                $this->error('La fecha de fin no puede ser anterior a la de inicio.');

                return self::FAILURE;
            }
        }

        $socios = User::query()
            ->when($this->option('user'), fn ($q) => $q->whereKey((int) $this->option('user')))
            ->when(! $this->option('user'), fn ($q) => $q->where(function ($w) use ($hoy, $tope): void {
                $w->whereDate('membership_start_date', '>', $hoy->toDateString())
                    ->orWhereDate('membership_end_date', '>', $tope->toDateString());
            }))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['id', 'name', 'document', 'plan', 'membership_start_date', 'membership_end_date']);

        if ($socios->isEmpty()) {
            $this->info('Ninguno. No hay vigencias empezando en el futuro ni terminando más allá de '.$tope->toDateString().'.');

            return self::SUCCESS;
        }

        $planes = Plan::query()->get(['id', 'name', 'duration_days'])
            ->keyBy(fn (Plan $p) => MembershipAccess::planKey((string) $p->name));

        $filas = [];
        $arreglados = 0;

        foreach ($socios as $socio) {
            $real = $this->pagoQueMasCubre($socio, $planes);
            $sugerida = $this->manual() ?? $this->vigenciaDe($real, $planes);

            $filas[] = [
                $socio->id,
                mb_strimwidth((string) $socio->name, 0, 26, '…'),
                mb_strimwidth((string) $socio->plan, 0, 24, '…'),
                substr((string) $socio->membership_start_date, 0, 10) ?: '—',
                substr((string) $socio->membership_end_date, 0, 10) ?: '—',
                $this->motivo($socio, $hoy, $tope),
                $real ? mb_strimwidth((string) ($real->plan?->name ?? 'sin plan'), 0, 22, '…') : '—',
                $sugerida ? $sugerida['start'].' → '.$sugerida['end'] : '—',
            ];

            if ($this->option('fix') && $sugerida) {
                $socio->forceFill([
                    'membership_start_date' => $sugerida['start'],
                    'membership_end_date' => $sugerida['end'],
                    'plan' => $this->option('plan') ?: ($real?->plan?->name ?? $socio->plan),
                ])->save();
                $arreglados++;
            }
        }

        $this->table(
            ['id', 'socio', 'plan puesto', 'inicio', 'vence', 'por qué sale', 'último pago real', 'vigencia que le toca'],
            $filas,
        );

        $this->newLine();
        $this->line('Revisados: '.$socios->count());

        if (! $this->option('fix')) {
            $this->comment('Nada se ha tocado. Para escribir la columna «vigencia que le toca»: añade --fix');
            $this->comment('Los que salen con «—» no tienen ningún pago real: hay que mirarlos a mano.');

            return self::SUCCESS;
        }

        $this->info('Corregidos: '.$arreglados);

        return self::SUCCESS;
    }

    private function motivo(User $socio, CarbonImmutable $hoy, CarbonImmutable $tope): string
    {
        $inicio = substr((string) $socio->membership_start_date, 0, 10);
        $fin = substr((string) $socio->membership_end_date, 0, 10);

        $partes = [];
        if ($inicio && $inicio > $hoy->toDateString()) {
            $partes[] = 'empieza en el futuro';
        }
        if ($fin && $fin > $tope->toDateString()) {
            $partes[] = 'vence muy lejos';
        }

        return $partes === [] ? 'revisión manual' : implode(' + ', $partes);
    }

    /**
     * El pago real que llega MÁS LEJOS. No el último que se registró.
     *
     * ESTO ESTABA MAL Y SE VIO EN PRODUCCIÓN. La primera versión cogía el
     * último pago por fecha, y con eso una socia con la ANUALIDAD de enero
     * —vigente hasta enero del año siguiente— y una mensualidad suelta de mayo
     * salía con la vigencia de la mensualidad: la habría dejado vencida en
     * junio. Un pago posterior no sustituye a uno que cubre más tiempo; lo que
     * define hasta cuándo puede entrar es el que llega más lejos.
     *
     * Es la misma regla del importador, menos su error: las comisiones no
     * cuentan, porque no compran acceso a nada.
     *
     * @param  Collection<string, Plan>  $planes
     */
    private function pagoQueMasCubre(User $socio, Collection $planes): ?Payment
    {
        $marcadores = implode(',', array_fill(0, count(PaymentController::PAID_STATUSES), '?'));

        $reales = Payment::query()
            ->where('user_id', $socio->id)
            ->whereNotNull('plan_id')
            ->whereRaw('LOWER(status) IN ('.$marcadores.')', PaymentController::PAID_STATUSES)
            ->with('plan:id,name,duration_days')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Payment $p) => ! $this->esComision((string) ($p->plan?->name ?? '')));

        $mejor = null;
        $masLejos = null;

        foreach ($reales as $pago) {
            $vigencia = $this->vigenciaDe($pago, $planes);
            if (! $vigencia) {
                continue;
            }
            if ($masLejos === null || $vigencia['end'] > $masLejos) {
                $masLejos = $vigencia['end'];
                $mejor = $pago;
            }
        }

        return $mejor;
    }

    /**
     * Las fechas puestas a mano, cuando quien manda el comando SABE cuáles son.
     *
     * Hay socios cuya membresía de verdad no está en `payments`: se importaron
     * con las fechas escritas directamente en la ficha y sin un cobro detrás.
     * Para esos no hay regla que valga, y adivinar es peor que preguntar; así
     * que se pueden escribir, pero solo sobre UNA persona a la vez.
     *
     * @return array{start: string, end: string}|null
     */
    private function manual(): ?array
    {
        $inicio = (string) $this->option('start');
        $fin = (string) $this->option('end');

        if ($inicio === '' && $fin === '') {
            return null;
        }

        return ['start' => $inicio, 'end' => $fin];
    }

    /**
     * ¿Ese «plan» es en realidad una comisión?
     *
     * Son los cobros que el sistema anterior metió en el catálogo de planes
     * porque no tenía otro sitio donde ponerlos. Ya tienen el suyo: el módulo
     * de Comisiones.
     */
    private function esComision(string $nombre): bool
    {
        $clave = MembershipAccess::planKey($nombre);

        return $clave !== '' && (str_contains($clave, 'comision') || str_contains($clave, 'piso'));
    }

    /**
     * La vigencia que sale de un pago real: su periodo congelado si lo tiene,
     * y si no, el inicio más la duración de su plan.
     *
     * @param  Collection<string, Plan>  $planes
     * @return array{start: string, end: string}|null
     */
    private function vigenciaDe(?Payment $pago, Collection $planes): ?array
    {
        if (! $pago) {
            return null;
        }

        if ($pago->period_start && $pago->period_end) {
            return [
                'start' => $pago->period_start->toDateString(),
                'end' => $pago->period_end->toDateString(),
            ];
        }

        $inicio = $pago->starts_on ?? $pago->paid_at;
        if (! $inicio) {
            return null;
        }

        $dias = (int) ($pago->plan?->duration_days
            ?? $planes->get(MembershipAccess::planKey((string) $pago->plan?->name))?->duration_days
            ?? 30);

        $desde = CarbonImmutable::parse($inicio)->setTimezone(MembershipPeriod::TZ)->startOfDay();

        return [
            'start' => $desde->toDateString(),
            'end' => $desde->addDays($dias)->toDateString(),
        ];
    }
}
