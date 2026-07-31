<?php

namespace App\Services\Notifications;

use App\Models\Attendance;
use App\Models\Member;
use App\Models\MemberDeviceToken;
use App\Models\MemberNotificationPreference;
use App\Models\NotificationDispatch;
use App\Models\NotificationTemplate;
use App\Services\MembershipService;
use App\Support\Notifications\NotificationCategory as Cat;
use App\Support\Notifications\SendingWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Decide QUÉ recibe cada socio hoy, si es que recibe algo.
 *
 * Segmenta solo por lo que el propio socio hace con el gimnasio: si entrenó
 * hace poco, si lleva días sin venir, qué categorías tiene encendidas. No mira
 * peso, ni edad más allá del corte de mayoría, ni nada declarado como dato de
 * salud — el sistema no infiere condiciones médicas ni las almacena.
 *
 * El envío real lo hace {@see NotificationDispatcher}, que puede vetar esta
 * decisión por preferencias, horas de silencio o límites. Aquí solo se propone.
 */
class WellnessPlanner
{
    /** Debajo de esta edad no se envía NADA de suplementos. */
    public const SUPPLEMENT_MIN_AGE = 18;

    /** Días sin fichar a partir de los cuales el mensaje cambia de tono. */
    private const AWAY_DAYS = 5;

    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /**
     * Planifica el día para todos los socios con dispositivo activo.
     *
     * `sent` cuenta lo que ha salido AHORA. Lo que ya estaba resuelto de una
     * tanda anterior del mismo día va en `already_handled`, no en `sent`: la
     * segunda pasada del día no envía nada, y decir que envió tres convierte el
     * contador en un adorno. Con la separación, `sent` vuelve a ser el número de
     * teléfonos que sonaron.
     *
     * @return array{considered:int,sent:int,suppressed:int,already_handled:int}
     */
    public function planDaily(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $stats = ['considered' => 0, 'sent' => 0, 'suppressed' => 0, 'already_handled' => 0];

        $memberIds = MemberDeviceToken::query()
            ->where('is_active', true)
            ->distinct()
            ->pluck('member_id')
            ->filter()
            ->values();

        foreach ($memberIds as $memberId) {
            $member = Member::find($memberId);
            if ($member === null || $member->status !== Member::STATUS_ACTIVE) {
                continue;
            }

            $stats['considered']++;

            $plan = $this->planFor($member, $now);
            if ($plan === null) {
                $stats['suppressed']++;

                continue;
            }

            $dispatch = $this->dispatcher->dispatch(
                memberId: $member->id,
                category: $plan['category'],
                title: $plan['title'],
                body: $plan['body'],
                actionRoute: $plan['action_route'],
                templateKey: $plan['key'],
                supplementKind: $plan['supplement_kind'],
                // Una y solo una por socio y día, pase lo que pase.
                //
                // La fecha se toma en la zona del gimnasio, NO en UTC. El día
                // local va de las 05:00 UTC a las 05:00 UTC siguientes, así que
                // con una fecha en UTC una tanda de las 21:00 de Neiva caería
                // ya en el día siguiente y el mismo socio podría recibir dos
                // avisos en su mismo día.
                idempotencyKey: sprintf('wellness:%d:%s', $member->id, $this->localDate($now)),
                now: $now,
            );

            // `wasRecentlyCreated` distingue la fila escrita en esta pasada de
            // la que el despachador devolvió por llave repetida. Sin esto ambas
            // se cuentan igual y una tanda que no manda nada informa envíos.
            if (! $dispatch->wasRecentlyCreated) {
                $stats['already_handled']++;

                continue;
            }

            $dispatch->status === NotificationDispatch::STATUS_SENT
                ? $stats['sent']++
                : $stats['suppressed']++;
        }

        return $stats;
    }

    /**
     * Elige categoría y plantilla para un socio concreto.
     *
     * @return array{key:string,category:string,supplement_kind:?string,title:string,body:string,action_route:?string}|null
     */
    public function planFor(Member $member, CarbonImmutable $now): ?array
    {
        $prefs = MemberNotificationPreference::forMember($member->id);
        $puedeEntrenar = $this->hasActiveMembership($member);

        foreach ($this->categoryPreference($member, $now) as $category) {
            if ($category === Cat::SUPPLEMENTS) {
                $kind = $this->pickSupplementKind($member, $prefs, $now);
                if ($kind === null) {
                    continue;
                }
                $template = $this->pickTemplate($member->id, $category, $kind, $puedeEntrenar);
            } else {
                if (! $prefs->allows($category)) {
                    continue;
                }
                $template = $this->pickTemplate($member->id, $category, null, $puedeEntrenar);
            }

            if ($template === null) {
                continue;
            }

            return [
                'key' => $template->key,
                'category' => $template->category,
                'supplement_kind' => $template->supplement_kind,
                'title' => $template->title,
                'body' => $template->renderedBody(),
                'action_route' => $template->action_route,
            ];
        }

        return null;
    }

    /**
     * Orden en que se intentan las categorías hoy.
     *
     * Quien lleva días sin aparecer recibe motivación, no un consejo sobre
     * proteína. Quien entrenó ayer recibe recuperación. El resto rota por el día
     * del año, para que la misma persona no vea siempre lo mismo.
     *
     * @return list<string>
     */
    private function categoryPreference(Member $member, CarbonImmutable $now): array
    {
        $lastSeen = $this->lastAttendance($member->id);
        $daysAway = $lastSeen === null ? null : $lastSeen->diffInDays($now);

        if ($daysAway !== null && $daysAway >= self::AWAY_DAYS) {
            return [Cat::MOTIVATION, Cat::HYDRATION, Cat::RECOVERY];
        }

        if ($daysAway !== null && $daysAway <= 1) {
            return [Cat::RECOVERY, Cat::HYDRATION, Cat::MOTIVATION, Cat::SUPPLEMENTS];
        }

        $rotation = [
            [Cat::MOTIVATION, Cat::HYDRATION, Cat::SUPPLEMENTS, Cat::RECOVERY],
            [Cat::HYDRATION, Cat::RECOVERY, Cat::MOTIVATION, Cat::SUPPLEMENTS],
            [Cat::SUPPLEMENTS, Cat::MOTIVATION, Cat::RECOVERY, Cat::HYDRATION],
        ];

        return $rotation[((int) $now->format('z') + $member->id) % count($rotation)];
    }

    /**
     * Subtipo de suplemento a tratar, o null si este socio no es elegible.
     *
     * La edad es el único filtro de persona que se aplica, y se aplica en
     * negativo: sin fecha de nacimiento NO se envía. Prefiero callar ante la
     * duda que mandarle información de suplementos a un menor.
     */
    private function pickSupplementKind(
        Member $member,
        MemberNotificationPreference $prefs,
        CarbonImmutable $now,
    ): ?string {
        if (! $prefs->allows(Cat::SUPPLEMENTS)) {
            return null;
        }

        $age = Member::ageFromBirthDate($member->birth_date);
        if ($age === null || $age < self::SUPPLEMENT_MIN_AGE) {
            return null;
        }

        // Familias ya tratadas en las últimas cuatro semanas: se dejan para
        // después, para no insistir con la misma dos semanas seguidas.
        $recent = NotificationDispatch::query()
            ->where('member_id', $member->id)
            ->sent()
            ->where('category', Cat::SUPPLEMENTS)
            ->where('created_at', '>=', $now->subWeeks(4))
            ->pluck('supplement_kind')
            ->filter()
            ->all();

        $candidates = array_values(array_filter(
            Cat::SUPPLEMENT_KINDS,
            fn (string $kind): bool => $prefs->allowsSupplement($kind) && ! in_array($kind, $recent, true),
        ));

        if ($candidates === []) {
            return null;
        }

        return $candidates[((int) $now->format('z') + $member->id) % count($candidates)];
    }

    /**
     * ¿Puede este socio entrenar hoy?
     *
     * Se consulta el estado real de la membresía, no el del registro: alguien
     * puede estar `active` como persona y tener el plan vencido desde ayer.
     */
    private function hasActiveMembership(Member $member): bool
    {
        try {
            $user = $member->user;
            if ($user === null) {
                return false;
            }

            return (bool) (app(MembershipService::class)->snapshot($user)['is_active'] ?? false);
        } catch (Throwable) {
            // Ante la duda, tratarlo como vencido: el contenido de reactivación
            // no molesta a quien sí puede entrenar, y al revés sí molesta.
            return false;
        }
    }

    /** Plantilla activa que este socio no haya visto recientemente. */
    private function pickTemplate(
        int $memberId,
        string $category,
        ?string $kind,
        bool $puedeEntrenar = true,
    ): ?NotificationTemplate {
        $query = NotificationTemplate::query()->active()->where('category', $category);
        $kind === null
            ? $query->whereNull('supplement_kind')
            : $query->where('supplement_kind', $kind);

        // Sin membresía al día, solo el contenido que no da por hecho que hoy
        // puede entrar al gimnasio.
        if (! $puedeEntrenar) {
            $query->forLapsedMembership();
        }

        /** @var Collection<int,NotificationTemplate> $templates */
        $templates = $query->orderBy('id')->get();
        if ($templates->isEmpty()) {
            return null;
        }

        $seen = NotificationDispatch::query()
            ->where('member_id', $memberId)
            ->where('category', $category)
            ->sent()
            ->orderByDesc('id')
            ->limit($templates->count())
            ->pluck('template_key')
            ->filter()
            ->all();

        // Si ya las vio todas, se reinicia el ciclo con la más antigua.
        return $templates->firstWhere(fn (NotificationTemplate $t): bool => ! in_array($t->key, $seen, true))
            ?? $templates->first();
    }

    /** Fecha del calendario del gimnasio, que es la que vive el socio. */
    private function localDate(CarbonImmutable $now): string
    {
        try {
            return $now->setTimezone(SendingWindow::timezone())->format('Y-m-d');
        } catch (Throwable) {
            return $now->format('Y-m-d');
        }
    }

    private function lastAttendance(int $memberId): ?CarbonImmutable
    {
        $last = Attendance::query()
            ->where('member_id', $memberId)
            ->where('action', 'entry')
            ->max('captured_at');

        return $last === null ? null : CarbonImmutable::parse($last);
    }
}
