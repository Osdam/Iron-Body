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
use App\Support\Notifications\NotificationSlot as Slot;
use App\Support\Notifications\SendingWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decide QUÉ recibe cada socio en cada franja del día, si es que recibe algo.
 *
 * Segmenta solo por lo que el propio socio hace con el gimnasio: si entrenó
 * hace poco, si lleva días sin venir, qué categorías tiene encendidas y qué le
 * ha llegado ya. No mira peso, ni edad más allá del corte de mayoría, ni nada
 * declarado como dato de salud — el sistema no infiere condiciones médicas ni
 * las almacena.
 *
 * Tres reglas gobiernan la variedad, y las tres miran al historial:
 *
 *  1. La FRANJA manda sobre el tema. A las siete de la mañana no se habla de
 *     dormir y a las nueve y media de la noche no se habla de preentreno.
 *  2. Ninguna plantilla se repite en catorce días. Es una regla dura: si no
 *     queda contenido nuevo, se calla en vez de repetir.
 *  3. Dos franjas seguidas no comparten categoría, para que el día no sea
 *     cinco veces lo mismo con otras palabras.
 *
 * El envío real lo hace {@see NotificationDispatcher}, que puede vetar esta
 * decisión por preferencias, horas de silencio, cupos o intervalo mínimo. Aquí
 * solo se propone.
 */
class WellnessPlanner
{
    /** Debajo de esta edad no se envía NADA de suplementos. */
    public const SUPPLEMENT_MIN_AGE = 18;

    /** Días sin fichar a partir de los cuales el mensaje cambia de tono. */
    private const AWAY_DAYS = 5;

    /** Días que una plantilla queda vetada para el mismo socio. */
    public const TEMPLATE_COOLDOWN_DAYS = 14;

    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /**
     * Planifica UNA franja para todos los socios con dispositivo activo.
     *
     * La franja se deduce de la hora del gimnasio. Si el instante cae fuera de
     * horario no hay nada que planificar y se devuelve el parte vacío: es la
     * misma respuesta que da la ventana, y no un error.
     *
     * @return array<string,mixed>
     */
    public function planDaily(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $slot = Slot::at($now);
        $stats = self::emptyStats($slot);

        if ($slot === null) {
            $stats['skipped_outside_window'] = 1;

            return $stats;
        }

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

            $plan = $this->planFor($member, $now, $slot);

            if ($plan === null) {
                // Sin contenido no hay intento que registrar: el libro mayor
                // guarda envíos, y aquí no llegó a existir ninguno. Queda en el
                // parte y en el log, que es donde se puede consultar.
                //
                // Se distingue POR QUÉ no hay nada. «Este socio apagó todas las
                // categorías de la franja» y «se le acabó el contenido nuevo»
                // producen el mismo silencio y piden arreglos opuestos: uno no
                // hay que arreglarlo y el otro exige escribir más plantillas.
                $motivo = $this->reasonForEmptyPlan($member, $slot);
                $stats[$motivo]++;
                $stats['suppressed']++;
                Log::info('notifications.wellness.sin_contenido', [
                    'member' => $member->id,
                    'slot' => $slot,
                    'motivo' => $motivo,
                ]);

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
                // Una y solo una por socio, día y FRANJA.
                //
                // La fecha se toma en la zona del gimnasio, NO en UTC: el día
                // local va de las 05:00 UTC a las 05:00 UTC siguientes, así que
                // con una fecha en UTC la franja de las 21:45 de Neiva caería
                // ya en el día siguiente.
                //
                // La categoría queda deliberadamente FUERA de la llave. Con ella
                // dentro, un mismo socio podría recibir dos avisos en la misma
                // franja con solo cambiar de categoría, que es justo el límite
                // que hay que sostener.
                idempotencyKey: $this->idempotencyKey($member->id, $now, $slot),
                now: $now,
                slot: $slot,
                selectionReason: $plan['selection_reason'],
            );

            $this->tally($stats, $dispatch, $slot);
        }

        return $stats;
    }

    /**
     * Elige categoría y plantilla para un socio en una franja concreta.
     *
     * @return array{key:string,category:string,supplement_kind:?string,title:string,body:string,action_route:?string,selection_reason:string}|null
     */
    public function planFor(Member $member, CarbonImmutable $now, ?string $slot = null): ?array
    {
        $slot ??= Slot::at($now);
        if ($slot === null) {
            return null;
        }

        $prefs = MemberNotificationPreference::forMember($member->id);
        $puedeEntrenar = $this->hasActiveMembership($member);
        $recientes = $this->recentTemplateKeys($member->id, $now);
        [$categorias, $motivo] = $this->categoryPreference($member, $now, $slot);

        foreach ($categorias as $category) {
            if ($category === Cat::SUPPLEMENTS) {
                $kind = $this->pickSupplementKind($member, $prefs, $now);
                if ($kind === null) {
                    continue;
                }
                $template = $this->pickTemplate($category, $kind, $puedeEntrenar, $slot, $recientes);
            } else {
                if (! $prefs->allows($category)) {
                    continue;
                }
                $template = $this->pickTemplate($category, null, $puedeEntrenar, $slot, $recientes);
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
                'selection_reason' => $motivo,
            ];
        }

        return null;
    }

    /**
     * Orden en que se intentan las categorías en esta franja.
     *
     * Parte de lo que la franja permite —a las 21:45 solo descanso y ánimo— y lo
     * reordena según lo que el socio está haciendo: quien lleva días sin
     * aparecer recibe motivación antes que un consejo sobre proteína; quien
     * entrenó ayer, recuperación. Después se aparta la categoría de la franja
     * anterior, para que el día no repita tema dos veces seguidas.
     *
     * @return array{0:list<string>,1:string} la lista y el motivo de la selección
     */
    private function categoryPreference(Member $member, CarbonImmutable $now, string $slot): array
    {
        $categorias = Slot::categoriesFor($slot);
        $motivo = 'franja_'.$slot;

        $lastSeen = $this->lastAttendance($member->id);
        $daysAway = $lastSeen === null ? null : (int) $lastSeen->diffInDays($now, absolute: true);

        if ($daysAway !== null && $daysAway >= self::AWAY_DAYS) {
            $categorias = self::priorizar($categorias, Cat::MOTIVATION);
            $motivo = 'sin_venir_'.$daysAway.'d';
        } elseif ($daysAway !== null && $daysAway <= 1) {
            $categorias = self::priorizar($categorias, Cat::RECOVERY);
            $motivo = 'entreno_reciente';
        } elseif ($lastSeen === null) {
            $motivo = 'sin_asistencias';
        }

        // Nada de repetir categoría en dos franjas seguidas. Se aparta al final
        // en vez de eliminarla: si no queda ninguna otra con contenido, más vale
        // repetir tema que callar.
        $anterior = $this->categoryOfPreviousSlot($member->id, $now, $slot);
        if ($anterior !== null && count($categorias) > 1) {
            $categorias = array_values(array_filter($categorias, fn (string $c): bool => $c !== $anterior));
            $categorias[] = $anterior;
        }

        return [$categorias, $motivo];
    }

    /**
     * Por qué esta franja se quedó sin nada que decirle a este socio.
     *
     * Si no acepta ninguna de las categorías de la franja, el silencio es suyo
     * y está bien. Si acepta alguna, el silencio es del catálogo: no queda
     * contenido que no haya visto en catorce días, y eso sí hay que mirarlo.
     */
    private function reasonForEmptyPlan(Member $member, string $slot): string
    {
        $prefs = MemberNotificationPreference::forMember($member->id);

        foreach (Slot::categoriesFor($slot) as $category) {
            if ($prefs->allows($category)) {
                return 'skipped_recent_template';
            }
        }

        return 'skipped_preferences';
    }

    /** Mueve una categoría al principio de la lista, si está en ella. */
    private static function priorizar(array $categorias, string $preferida): array
    {
        if (! in_array($preferida, $categorias, true)) {
            return $categorias;
        }

        return array_values(array_merge(
            [$preferida],
            array_filter($categorias, fn (string $c): bool => $c !== $preferida),
        ));
    }

    /** Categoría que recibió este socio en la franja inmediatamente anterior. */
    private function categoryOfPreviousSlot(int $memberId, CarbonImmutable $now, string $slot): ?string
    {
        $anterior = Slot::previous($slot);
        if ($anterior === null) {
            return null;
        }

        return NotificationDispatch::query()
            ->where('member_id', $memberId)
            ->where('slot', $anterior)
            ->where('idempotency_key', $this->idempotencyKey($memberId, $now, $anterior))
            ->sent()
            ->value('category');
    }

    /** Plantillas que este socio ya vio dentro del periodo de veto. */
    private function recentTemplateKeys(int $memberId, CarbonImmutable $now): array
    {
        return NotificationDispatch::query()
            ->where('member_id', $memberId)
            ->sent()
            ->where('created_at', '>=', $now->subDays(self::TEMPLATE_COOLDOWN_DAYS))
            ->whereNotNull('template_key')
            ->pluck('template_key')
            ->unique()
            ->all();
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

        // Familias ya tratadas en las últimas dos semanas: se dejan para
        // después, para no insistir con la misma dos semanas seguidas.
        $recent = NotificationDispatch::query()
            ->where('member_id', $member->id)
            ->sent()
            ->where('category', Cat::SUPPLEMENTS)
            ->where('created_at', '>=', $now->subDays(self::TEMPLATE_COOLDOWN_DAYS))
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

    /**
     * Plantilla válida para esta franja que el socio no haya visto en 14 días.
     *
     * El veto es DURO: si no queda ninguna, devuelve null y el planificador
     * probará otra categoría o se callará. Repetir contenido a los pocos días es
     * la forma más rápida de que alguien apague las notificaciones enteras.
     *
     * @param  list<string>  $recientes
     */
    private function pickTemplate(
        string $category,
        ?string $kind,
        bool $puedeEntrenar,
        string $slot,
        array $recientes,
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
        $templates = $query->orderBy('id')->get()
            ->filter(fn (NotificationTemplate $t): bool => $t->servesSlot($slot))
            ->reject(fn (NotificationTemplate $t): bool => in_array($t->key, $recientes, true))
            ->values();

        return $templates->first();
    }

    /** Llave de idempotencia: socio + día del gimnasio + franja. */
    private function idempotencyKey(int $memberId, CarbonImmutable $now, string $slot): string
    {
        return sprintf('wellness:%d:%s:%s', $memberId, $this->localDate($now), $slot);
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

    /**
     * Parte vacío con todos los contadores presentes.
     *
     * Se declaran todos aunque valgan cero: un contador que solo aparece cuando
     * es distinto de cero obliga a quien lee el JSON a saber de antemano qué
     * podría faltar.
     *
     * @return array<string,mixed>
     */
    private static function emptyStats(?string $slot): array
    {
        return [
            'slot' => $slot,
            'considered' => 0,
            'sent' => 0,
            'already_handled' => 0,
            'suppressed' => 0,
            'skipped_preferences' => 0,
            'skipped_quiet_hours' => 0,
            'skipped_daily_limit' => 0,
            'skipped_min_interval' => 0,
            'skipped_recent_template' => 0,
            'skipped_no_token' => 0,
            'skipped_not_eligible' => 0,
            'skipped_outside_window' => 0,
            'invalid_token' => 0,
            'provider_failed' => 0,
            'retry_scheduled' => 0,
        ];
    }

    /**
     * Suma un despacho al parte.
     *
     * `sent` cuenta lo que el proveedor aceptó AHORA. Lo que ya estaba resuelto
     * de una pasada anterior de la misma franja va en `already_handled`: la
     * segunda ejecución de una franja no envía nada, y decir que envió convierte
     * el contador en un adorno.
     */
    private function tally(array &$stats, NotificationDispatch $dispatch, string $slot): void
    {
        if (! $dispatch->wasRecentlyCreated) {
            $stats['already_handled']++;

            return;
        }

        if ($dispatch->status === NotificationDispatch::STATUS_SENT) {
            $stats['sent']++;

            return;
        }

        $stats['suppressed']++;

        $contador = match ($dispatch->reason) {
            NotificationDispatch::REASON_OPTED_OUT => 'skipped_preferences',
            NotificationDispatch::REASON_QUIET_HOURS => 'skipped_quiet_hours',
            NotificationDispatch::REASON_DAILY_LIMIT,
            NotificationDispatch::REASON_WEEKLY_LIMIT => 'skipped_daily_limit',
            NotificationDispatch::REASON_MIN_INTERVAL => 'skipped_min_interval',
            NotificationDispatch::REASON_NO_TOKEN => 'skipped_no_token',
            NotificationDispatch::REASON_NOT_ELIGIBLE => 'skipped_not_eligible',
            NotificationDispatch::REASON_OUTSIDE_WINDOW => 'skipped_outside_window',
            NotificationDispatch::REASON_INVALID_TOKEN => 'invalid_token',
            NotificationDispatch::REASON_PROVIDER_FAILED => 'provider_failed',
            default => null,
        };

        if ($contador !== null) {
            $stats[$contador]++;
        }

        // Un fallo del proveedor tiene otra oportunidad en la franja siguiente,
        // porque la llave de idempotencia cambia con ella. Si ya no quedan
        // franjas hoy, no la tiene: decir lo contrario sería inventarse un
        // reintento que nadie va a ejecutar.
        if ($dispatch->reason === NotificationDispatch::REASON_PROVIDER_FAILED
            && Slot::index($slot) < count(Slot::ALL) - 1) {
            $stats['retry_scheduled']++;
        }
    }
}
