<?php

namespace Tests\Feature\Cycle;

use App\Jobs\Commercial\EvaluateCommercialSubject;
use App\Models\Admin;
use App\Models\Attendance;
use App\Models\CommercialEvent;
use App\Models\CommercialOpportunity;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Commercial\CommercialSubject;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\Commercial\ContactPolicy;
use App\Services\Commercial\NextBestActionEngine;
use App\Services\Wompi\PaymentStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Base de la fase F.9 · el ciclo comercial de larga duración.
 *
 * Las fases anteriores probaron momentos: ¿se guarda este mensaje?, ¿se cobra
 * una sola vez?, ¿qué queda escrito si Wompi no contesta? Todas caben en un
 * instante. Lo que se prueba aquí no cabe: que el agente no acose a alguien
 * durante seis meses no se puede ver en una llamada, solo en una secuencia.
 *
 * Eso obliga a poder mover el reloj. Y mover el reloj en una prueba es
 * peligroso, porque es fácil escribir una secuencia que pasa por casualidad de
 * las fechas elegidas. De ahí las dos reglas de este andamio:
 *
 *  · **Cada salto de tiempo se declara y se registra.** `$this->day(21)` deja
 *    constancia en la bitácora del caso. Cuando una prueba de estas falla, lo
 *    primero que hace falta saber es en qué día del ciclo iba, y ese dato no
 *    puede estar implícito en la suma de `addDays()` anteriores.
 *
 *  · **El reloj avanza, nunca retrocede.** Un `setTestNow` hacia atrás produce
 *    estados imposibles —pagos posteriores a su propia renovación— que después
 *    se interpretan como bugs del sistema y no del montaje.
 *
 * Todo corre sobre base en memoria, disco falso y red falseada. Ni un mensaje,
 * ni un cobro, ni una factura salen de aquí.
 */
abstract class CommercialCycleTestCase extends TestCase
{
    use RefreshDatabase;

    /** Instante del «día 0» del ciclo. Fijo para que las fechas sean legibles. */
    protected Carbon $origin;

    /** Día actual del ciclo, para la bitácora. */
    protected int $currentDay = 0;

    /** Bitácora de la simulación: qué pasó y en qué día. */
    protected array $ledger = [];

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Motor comercial encendido; el canal y la autonomía, apagados como en
        // producción. Lo que se prueba es qué DECIDE el motor, no qué envía.
        config()->set('commercial.enabled', true);
        config()->set('commercial.events_enabled', true);
        config()->set('commercial.autonomy_enabled', false);
        config()->set('meta.enabled', false);
        config()->set('marketing.agent_enabled', false);
        config()->set('marketing.ai.driver', 'fake');

        Storage::fake('whatsapp');
        Http::preventStrayRequests();
        Http::fake([]);

        /*
         * Un martes a las 10:00 de Neiva.
         *
         * La hora importa: la política de contacto tiene horas de silencio
         * (21:00–08:00 hora de Neiva) y arrancar el ciclo a las 03:00 haría que
         * media prueba fallara por el horario en vez de por lo que mide. El día
         * de la semana importa por la misma razón para las reglas semanales.
         */
        $this->origin = Carbon::parse('2026-03-03 10:00:00', 'America/Bogota')
            ->setTimezone(config('app.timezone'));

        Carbon::setTestNow($this->origin);

        $this->admin = Admin::create([
            'name' => 'Ciclo', 'email' => 'ciclo-'.uniqid().'@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── El reloj ────────────────────────────────────────────────────────

    /**
     * Sitúa el reloj en el día N del ciclo, a la hora indicada de Neiva.
     *
     * Avanzar solo hacia delante no es una comodidad: un salto atrás deja
     * hechos con fecha posterior a hechos que los causaron, y el motor lee esas
     * fechas para decidir. Un bug así se atribuye al sistema y no al montaje, y
     * se tarda mucho en verlo.
     */
    protected function day(int $n, int $hour = 10, string $note = ''): Carbon
    {
        if ($n < $this->currentDay) {
            $this->fail(sprintf(
                'El reloj del ciclo no retrocede (día %d → %d). Un salto atrás '
                .'produce estados imposibles que después parecen bugs del motor.',
                $this->currentDay, $n,
            ));
        }

        /*
         * La hora se fija en NEIVA y después se traduce.
         *
         * La aplicación corre en UTC. Poner la hora sobre el instante ya
         * convertido significaba fijar las 11:00 UTC, que son las 06:00 de
         * Neiva —dentro de las horas de silencio—. Una prueba que pedía «día 2 a
         * las 11 de la mañana» se estrellaba contra el horario nocturno y
         * parecía un bug de la política de contacto.
         */
        $at = $this->origin->copy()
            ->setTimezone('America/Bogota')
            ->addDays($n)
            ->setTime($hour, 0)
            ->setTimezone(config('app.timezone'));

        Carbon::setTestNow($at);
        $this->currentDay = $n;

        $this->note(sprintf(
            'DÍA %d · %s (Neiva)', $n,
            $at->copy()->setTimezone('America/Bogota')->format('Y-m-d H:i'),
        ), $note);

        return $at;
    }

    /** Anota un hito en la bitácora del ciclo. */
    protected function note(string $what, string $detail = ''): void
    {
        $this->ledger[] = trim($what.($detail !== '' ? ' — '.$detail : ''));
    }

    /** La bitácora, para poder leer el ciclo cuando algo falla. */
    protected function ledgerText(): string
    {
        return implode("\n", $this->ledger);
    }

    // ── Construcción del cliente ────────────────────────────────────────

    protected function plan(string $name, float $price, int $days, bool $active = true): Plan
    {
        return Plan::create([
            'name' => $name, 'price' => $price, 'duration_days' => $days,
            'active' => $active, 'sort_order' => $days, 'benefits' => '',
        ]);
    }

    /** Un prospecto recién llegado: sin miembro, sin pagos, sin historia. */
    protected function newLead(string $phone, string $name = 'Prospecto'): MarketingLead
    {
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound',
            'meta_user_id' => '57'.$phone, 'phone' => $phone, 'name' => $name,
            'status' => MarketingLead::STATUS_NEW,
        ]);

        MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
            'last_inbound_at' => now(),
        ]);

        $this->note('lead creado', $name.' ('.$phone.')');

        return $lead;
    }

    protected function conversationOf(MarketingLead $lead): MarketingConversation
    {
        return MarketingConversation::where('lead_id', $lead->id)->firstOrFail();
    }

    /**
     * Convierte el lead en miembro. Es lo que hace el alta real tras un pago.
     */
    protected function makeMember(MarketingLead $lead): Member
    {
        $user = User::create([
            'name' => $lead->name ?: 'Socio',
            'email' => 'ciclo'.$lead->id.'-'.uniqid().'@ironbody.test',
            'password' => 'x',
        ]);

        $member = Member::create([
            'user_id' => $user->id,
            'full_name' => $lead->name ?: 'Socio',
            'document_number' => (string) (40000000 + $lead->id),
            'phone' => $lead->phone,
            'access_hash' => 'tok-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);

        $lead->forceFill(['member_id' => $member->id])->save();

        return $member;
    }

    // ── Dinero ──────────────────────────────────────────────────────────

    /**
     * Un cobro APROBADO por el camino real: transacción Wompi + activación.
     *
     * No se inventa la fila de `payments`: se hace pasar por
     * `WompiTransactionService::transitionTo`, que es quien activa la membresía
     * una sola vez. Si se escribiera a mano, la prueba no diría nada sobre la
     * clasificación del ingreso, que es justo lo que F.9 tiene que comprobar.
     */
    protected function approvePayment(Member $member, Plan $plan, ?float $amount = null): PaymentTransaction
    {
        $tx = PaymentTransaction::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'reference' => 'CICLO-'.strtoupper(\Illuminate\Support\Str::random(8)),
            'idempotency_key' => 'ciclo-'.uniqid('', true),
            'provider' => 'wompi', 'environment' => 'sandbox',
            'amount' => $amount ?? (float) $plan->price,
            'currency' => 'COP',
            'status' => PaymentStateMachine::PENDING,
            'member_id' => $member->id, 'user_id' => $member->user_id,
            'plan_id' => $plan->id,
        ]);

        \App\Services\Wompi\WompiTransactionService::make()
            ->transitionTo($tx, PaymentStateMachine::APPROVED);

        /*
         * La membresía la extiende el ACTIVADOR, no esta prueba.
         *
         * `transitionTo(APPROVED)` llama a `PaymentMembershipActivator`, que ya
         * mueve la vigencia con las reglas de producción. Llamar además a
         * `grantMembership` —que es lo que hacía este montaje— extendía dos
         * veces el mismo pago: un plan de 30 días daba 60, la clienta nunca
         * entraba en ventana de renovación, y el motor parecía estar eligiendo
         * mal el objetivo cuando el que estaba mal era el calendario.
         */
        $member->refresh();

        $this->note('pago aprobado', sprintf(
            '%s · $%s · vigencia hasta %s',
            $plan->name, number_format((float) ($amount ?? $plan->price)),
            $member->user->membership_end_date ?: '(sin vigencia)',
        ));

        return $tx->fresh();
    }

    /** Un link de pago generado que nadie ha pagado todavía. */
    protected function pendingPayment(Member $member, Plan $plan): PaymentTransaction
    {
        $tx = PaymentTransaction::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'reference' => 'CICLO-PEND-'.strtoupper(\Illuminate\Support\Str::random(6)),
            'idempotency_key' => 'ciclo-pend-'.uniqid('', true),
            'provider' => 'wompi', 'environment' => 'sandbox',
            'amount' => (float) $plan->price, 'currency' => 'COP',
            'status' => PaymentStateMachine::PENDING,
            'member_id' => $member->id, 'user_id' => $member->user_id,
            'plan_id' => $plan->id,
        ]);

        $this->note('link de pago generado, sin pagar', $plan->name);

        return $tx;
    }

    /**
     * Vigencia de membresía, con la MISMA regla que el activador real.
     *
     * Las dos mitades importan y las dos se descubrieron con una simulación que
     * mentía:
     *
     *  · La fecha de INICIO solo se mueve si la membresía había caducado. En una
     *    renovación continua se conserva. Reescribirla en cada pago —que era lo
     *    que hacía este andamio— dejaba a quien renueva cada mes como «miembro
     *    nuevo» para siempre: `daysAsMember` volvía a cero, la regla de
     *    acompañamiento no dejaba de dispararse nunca, y la de mejora, que
     *    exige antigüedad, no llegaba a ejecutarse jamás. Seis meses de
     *    simulación producían veintidós oportunidades de adherencia por
     *    persona, y parecían un fallo del motor.
     *
     *  · La fecha de FIN se extiende desde el final vigente, no desde hoy. Si se
     *    sumara desde hoy, renovar antes de tiempo le regalaría días al cliente
     *    —o se los quitaría, según el signo—.
     *
     * Ver `PaymentMembershipActivator::extendMembership()`.
     */
    protected function grantMembership(Member $member, Plan $plan, ?Carbon $from = null): void
    {
        $paidAt = $from ?? now();
        $user = $member->user;

        $currentEnd = $user->membership_end_date
            ? Carbon::parse($user->membership_end_date)->startOfDay()
            : null;

        $lapsed = $currentEnd === null
            || $currentEnd->lessThan($paidAt->copy()->startOfDay())
            || empty($user->membership_start_date);

        if ($lapsed) {
            $user->membership_start_date = $paidAt->toDateString();
        }

        $base = $lapsed ? $paidAt->copy() : $currentEnd->copy();
        $user->membership_end_date = $base->addDays($plan->duration_days)->toDateString();
        $user->plan = $plan->name;
        $user->save();

        $this->note('membresía vigente', sprintf(
            '%s hasta %s%s', $plan->name, $user->membership_end_date,
            $lapsed ? ' (alta/reactivación)' : ' (renovación: se conserva el inicio)',
        ));
    }

    // ── Uso del gimnasio ────────────────────────────────────────────────

    /** Una asistencia registrada hoy. */
    protected function attend(Member $member): void
    {
        Attendance::create([
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            // `action` = 'entry' y `captured_at`: son las dos columnas que mira
            // `CommercialSubject` para contar adherencia. Con otros nombres la
            // asistencia se registraría y el motor no la vería.
            'action' => 'entry',
            'source' => 'manual',
            'captured_at' => now(),
        ]);
    }

    /** N asistencias por semana durante las semanas indicadas, hasta hoy. */
    protected function attendRegularly(Member $member, int $perWeek, int $weeks): void
    {
        for ($w = $weeks; $w >= 1; $w--) {
            for ($i = 0; $i < $perWeek; $i++) {
                Attendance::create([
                    'member_id' => $member->id,
                    'user_id' => $member->user_id,
                    'action' => 'entry',
                    'source' => 'manual',
                    'captured_at' => now()->copy()->subWeeks($w)->addDays($i * 2),
                ]);
            }
        }

        $this->note('asistencia', sprintf('%d/semana durante %d semanas', $perWeek, $weeks));
    }

    // ── El motor ────────────────────────────────────────────────────────

    /** El sujeto comercial tal como lo ve el motor AHORA. */
    protected function subject(MarketingLead $lead, ?Member $member = null): CommercialSubject
    {
        return CommercialSubject::build($lead->fresh(), $member?->fresh());
    }

    /**
     * Registra un hecho y deja que el ciclo real lo procese.
     *
     * Se pasa por el job, no por el motor directamente: lo que interesa saber es
     * si la cadena entera —reconciliar lo cumplido, recalcular, elegir el
     * siguiente objetivo— hace lo correcto.
     */
    protected function recordAndEvaluate(string $event, MarketingLead $lead, ?Member $member = null, array $payload = []): ?CommercialOpportunity
    {
        app(\App\Services\Commercial\CommercialEventRecorder::class)->record(
            $event,
            ['marketing_lead_id' => $lead->id, 'member_id' => $member?->id],
            $payload,
        );

        $last = CommercialEvent::query()->orderByDesc('id')->first();

        if ($last !== null) {
            app()->call([new EvaluateCommercialSubject($last->id), 'handle']);
        }

        return $this->openOpportunity($lead);
    }

    /**
     * Evalúa sin registrar un hecho nuevo: la reevaluación periódica.
     *
     * Devuelve lo que decidió ESTA evaluación, no «alguna oportunidad viva».
     * La distinción costó una prueba mal escrita: los observadores del dominio
     * abren oportunidades por su cuenta cuando se registra una asistencia o un
     * pago, así que devolver la primera fila viva mezclaba lo que el motor acaba
     * de decidir con lo que ya había. Una prueba de opt-out pasaba a fallar
     * enseñando una oportunidad anterior al opt-out, y la culpa parecía del
     * motor.
     */
    protected function reevaluate(MarketingLead $lead, ?Member $member = null): ?CommercialOpportunity
    {
        return app(NextBestActionEngine::class)->evaluate($this->subject($lead, $member));
    }

    /** La oportunidad viva de este cliente, si hay alguna. */
    protected function openOpportunity(MarketingLead $lead): ?CommercialOpportunity
    {
        return CommercialOpportunity::query()
            ->where('marketing_lead_id', $lead->id)
            ->whereIn('status', [V::STATUS_OPEN, V::STATUS_IN_PROGRESS])
            ->orderByDesc('id')
            ->first();
    }

    /** Todas las oportunidades del cliente, en orden. */
    protected function opportunities(MarketingLead $lead)
    {
        return CommercialOpportunity::query()
            ->where('marketing_lead_id', $lead->id)
            ->orderBy('id')
            ->get();
    }

    /** ¿Se puede contactar AHORA por esta oportunidad, y si no, por qué? */
    protected function contactCheck(CommercialOpportunity $opportunity, MarketingLead $lead, ?Member $member = null): array
    {
        return app(ContactPolicy::class)->check($opportunity, $this->subject($lead, $member));
    }

    // ── Aserciones del ciclo ────────────────────────────────────────────

    /** El objetivo vivo del cliente es el que se espera. */
    protected function assertGoal(?CommercialOpportunity $opportunity, string $expected, string $why = ''): void
    {
        $this->assertNotNull($opportunity, sprintf(
            "No hay ninguna oportunidad viva y se esperaba «%s».\n\nCiclo:\n%s",
            $expected, $this->ledgerText(),
        ));

        $this->assertSame($expected, $opportunity->goal, sprintf(
            "Objetivo esperado «%s», encontrado «%s».%s\n\nMotivo del motor: %s\n\nCiclo:\n%s",
            $expected, $opportunity->goal, $why !== '' ? ' '.$why : '',
            $opportunity->reason ?: '(sin motivo)', $this->ledgerText(),
        ));

        $this->note('objetivo', $opportunity->goal.' · '.($opportunity->reason ?: ''));
    }

    /** El cliente NO tiene ninguna oportunidad viva de este objetivo. */
    protected function assertNoGoal(MarketingLead $lead, string $goal, string $why = ''): void
    {
        $found = CommercialOpportunity::query()
            ->where('marketing_lead_id', $lead->id)
            ->where('goal', $goal)
            ->whereIn('status', [V::STATUS_OPEN, V::STATUS_IN_PROGRESS])
            ->first();

        $this->assertNull($found, sprintf(
            "Existe una oportunidad viva de «%s» y no debería.%s\n\nMotivo: %s\n\nCiclo:\n%s",
            $goal, $why !== '' ? ' '.$why : '', $found?->reason ?: '', $this->ledgerText(),
        ));
    }

    /** Exactamente una membresía activa, y exactamente N ventas. */
    protected function assertSales(Member $member, int $expected): void
    {
        $sales = Payment::where('member_id', $member->id)->count();

        $this->assertSame($expected, $sales, sprintf(
            "Se esperaban %d venta(s) y hay %d.\n\nCiclo:\n%s",
            $expected, $sales, $this->ledgerText(),
        ));
    }

    /** No se puede contactar ahora, y el motivo es el esperado. */
    protected function assertContactDenied(array $check, string $reason): void
    {
        $this->assertFalse($check['allowed'], sprintf(
            "Se permitió contactar y no debía (se esperaba «%s»).\n\nCiclo:\n%s",
            $reason, $this->ledgerText(),
        ));

        $this->assertSame($reason, $check['reason'], sprintf(
            "Se denegó por «%s» y se esperaba «%s».\n\nCiclo:\n%s",
            $check['reason'], $reason, $this->ledgerText(),
        ));

        $this->note('no contactar', $check['reason']);
    }
}
