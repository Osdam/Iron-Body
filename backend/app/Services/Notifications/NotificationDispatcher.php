<?php

namespace App\Services\Notifications;

use App\Models\Member;
use App\Models\MemberDeviceToken;
use App\Models\MemberNotificationPreference;
use App\Models\NotificationDispatch;
use App\Services\Fcm\FcmHttpV1Client;
use App\Services\Moderation\SuspensionService;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\PushChannel;
use App\Support\Notifications\SendingWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Puerta única por la que sale toda notificación discrecional.
 *
 * Antes cada función creaba su notificación y empujaba push por su cuenta: no
 * había forma de saber cuántas recibía alguien al día, ni de que dejara de
 * recibirlas. Aquí todo pasa por la misma lista de comprobaciones y TODO queda
 * escrito, incluso lo que se decide no enviar — porque "no me llegó" y "el
 * sistema decidió callarse" son cosas distintas y hay que poder distinguirlas.
 *
 * No sustituye a las notificaciones operativas existentes (pagos, clases): esas
 * siguen su camino. Este motor gobierna lo nuevo y opcional.
 */
class NotificationDispatcher
{
    public function __construct(
        private readonly FcmHttpV1Client $client,
        private readonly SuspensionService $suspensions,
    ) {}

    /**
     * Intenta enviar. Devuelve SIEMPRE la fila del intento, enviada o no.
     *
     * @param  string|null  $idempotencyKey  Si ya existe, no se reenvía nada.
     */
    public function dispatch(
        int $memberId,
        string $category,
        string $title,
        string $body,
        ?string $actionRoute = null,
        ?string $templateKey = null,
        ?string $supplementKind = null,
        ?string $idempotencyKey = null,
        ?int $campaignId = null,
        ?CarbonImmutable $now = null,
        ?string $slot = null,
        ?string $selectionReason = null,
    ): NotificationDispatch {
        $now ??= CarbonImmutable::now();
        $title = trim($title);
        $body = trim($body);
        // La llave por defecto incluye un resumen del CONTENIDO, no solo la
        // categoría y el instante: dos avisos distintos emitidos en el mismo
        // microsegundo son cosas distintas y deben salir los dos. Repetir el
        // mismo aviso en ese instante sí es un duplicado, y se descarta.
        $idempotencyKey ??= sprintf(
            '%s:%d:%s:%s',
            $category,
            $memberId,
            $now->format('Y-m-d-H-i-s-u'),
            substr(hash('sha256', $title.'|'.$body.'|'.($supplementKind ?? '')), 0, 12),
        );

        // La llave se comprueba ANTES que nada: un reintento no debe volver a
        // evaluar límites ni, mucho menos, enviar dos veces.
        $existing = NotificationDispatch::query()->firstWhere('idempotency_key', $idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $record = fn (string $status, ?string $reason, int $targeted = 0, int $delivered = 0) => $this->record([
            'member_id' => $memberId,
            'category' => $category,
            'slot' => $slot,
            'selection_reason' => $selectionReason,
            'supplement_kind' => $supplementKind,
            'template_key' => $templateKey,
            'title' => $title,
            'body' => $body,
            'action_route' => $actionRoute,
            'idempotency_key' => $idempotencyKey,
            'status' => $status,
            'reason' => $reason,
            'tokens_targeted' => $targeted,
            'tokens_delivered' => $delivered,
            'campaign_id' => $campaignId,
            'sent_at' => $status === NotificationDispatch::STATUS_SENT ? $now : null,
        ]);

        if ($title === '' || $body === '') {
            return $record(NotificationDispatch::STATUS_SUPPRESSED, NotificationDispatch::REASON_INCOMPLETE);
        }

        $member = Member::find($memberId);
        if (! $this->isEligible($member, $category)) {
            return $record(NotificationDispatch::STATUS_SUPPRESSED, NotificationDispatch::REASON_NOT_ELIGIBLE);
        }

        $prefs = MemberNotificationPreference::forMember($memberId);

        if (! $this->wanted($prefs, $category, $supplementKind)) {
            return $record(NotificationDispatch::STATUS_SUPPRESSED, NotificationDispatch::REASON_OPTED_OUT);
        }

        // Techo horario del gimnasio, en hora de Bogotá. Va ANTES de las horas
        // de silencio del socio porque no es una preferencia suya: es un límite
        // del negocio que nadie puede levantar apagando un interruptor.
        if (! NotificationCategory::bypassesQuietHours($category) && ! SendingWindow::isOpen($now)) {
            return $record(NotificationDispatch::STATUS_SUPPRESSED, NotificationDispatch::REASON_OUTSIDE_WINDOW);
        }

        if (! NotificationCategory::bypassesQuietHours($category) && $prefs->inQuietHours($now)) {
            return $record(NotificationDispatch::STATUS_SUPPRESSED, NotificationDispatch::REASON_QUIET_HOURS);
        }

        if ($limit = $this->overLimit($prefs, $memberId, $category, $now)) {
            return $record(NotificationDispatch::STATUS_SUPPRESSED, $limit);
        }

        $tokens = MemberDeviceToken::query()
            ->where('member_id', $memberId)
            ->where('is_active', true)
            ->pluck('token');

        if ($tokens->isEmpty()) {
            return $record(NotificationDispatch::STATUS_SUPPRESSED, NotificationDispatch::REASON_NO_TOKEN);
        }

        if (! $this->client->isConfigured()) {
            return $record(NotificationDispatch::STATUS_SUPPRESSED, NotificationDispatch::REASON_FCM_DISABLED, $tokens->count());
        }

        ['delivered' => $delivered, 'unregistered' => $unregistered] =
            $this->push($tokens->all(), $category, $title, $body, $actionRoute, $templateKey);

        if ($delivered > 0) {
            return $record(NotificationDispatch::STATUS_SENT, null, $tokens->count(), $delivered);
        }

        // Un token caducado y un proveedor caído no son el mismo problema: el
        // primero se resuelve solo cuando el socio vuelva a abrir la app, y el
        // segundo hay que mirarlo. Meterlos en el mismo cajón deja las métricas
        // sin capacidad de distinguir «nadie tiene la app instalada» de «FCM
        // está rechazando todo».
        $todosCaducados = $unregistered === $tokens->count();

        return $record(
            NotificationDispatch::STATUS_FAILED,
            $todosCaducados
                ? NotificationDispatch::REASON_INVALID_TOKEN
                : NotificationDispatch::REASON_PROVIDER_FAILED,
            $tokens->count(),
            0,
        );
    }

    /**
     * ¿Puede este socio recibir esta categoría?
     *
     * Quien está eliminado no recibe nada. Quien tiene el acceso a la app
     * suspendido tampoco recibe nada opcional: invitarle a entrenar mientras no
     * puede entrar sería, como poco, de mal gusto. La seguridad de la cuenta sí
     * le llega, porque es justo lo que necesita saber.
     */
    private function isEligible(?Member $member, string $category): bool
    {
        if ($member === null || $member->status === Member::STATUS_DELETED) {
            return false;
        }

        if (NotificationCategory::isMandatory($category)) {
            return true;
        }

        if ($member->status === Member::STATUS_SUSPENDED) {
            return false;
        }

        try {
            return ! $this->suspensions->hasFullAppBlock($member->id);
        } catch (Throwable $e) {
            // Ante la duda, callar: molestar a un sancionado es peor que
            // perderse un consejo de hidratación.
            Log::warning('Notificaciones: no se pudo comprobar la sanción', [
                'member' => $member->id,
                'error' => class_basename($e),
            ]);

            return false;
        }
    }

    private function wanted(MemberNotificationPreference $prefs, string $category, ?string $kind): bool
    {
        if ($category === NotificationCategory::SUPPLEMENTS) {
            // Un mensaje de suplementos SIEMPRE dice de cuál habla; sin eso no
            // se puede respetar el interruptor del subtipo, así que no sale.
            return NotificationCategory::isSupplementKind($kind) && $prefs->allowsSupplement($kind);
        }

        return $prefs->allows($category);
    }

    /** Límite diario global y semanal de la categoría "bienestar". */
    private function overLimit(
        MemberNotificationPreference $prefs,
        int $memberId,
        string $category,
        CarbonImmutable $now,
    ): ?string {
        if (NotificationCategory::isMandatory($category)) {
            return null;
        }

        // El día se cuenta desde la medianoche del gimnasio, NO en una ventana
        // móvil de 24 horas. Con la ventana móvil, el envío de las 21:45 de
        // ayer seguiría ocupando cupo a las 07:00 de hoy y la promesa de cinco
        // diarias se incumpliría sola cada mañana.
        $desdeMedianoche = self::localMidnight($now);

        $sentToday = NotificationDispatch::query()
            ->where('member_id', $memberId)
            ->sent()
            ->where('created_at', '>=', $desdeMedianoche)
            ->count();

        if ($sentToday >= $prefs->dailyLimit()) {
            return NotificationDispatch::REASON_DAILY_LIMIT;
        }

        if (! in_array($category, self::WELLNESS, true)) {
            return null;
        }

        $wellnessToday = NotificationDispatch::query()
            ->where('member_id', $memberId)
            ->sent()
            ->whereIn('category', self::WELLNESS)
            ->where('created_at', '>=', $desdeMedianoche)
            ->count();

        if ($wellnessToday >= $prefs->wellnessDailyLimit()) {
            return NotificationDispatch::REASON_DAILY_LIMIT;
        }

        $wellnessThisWeek = NotificationDispatch::query()
            ->where('member_id', $memberId)
            ->sent()
            ->whereIn('category', self::WELLNESS)
            ->where('created_at', '>=', $now->subWeek())
            ->count();

        if ($wellnessThisWeek >= $prefs->weeklyWellnessLimit()) {
            return NotificationDispatch::REASON_WEEKLY_LIMIT;
        }

        // Intervalo mínimo de seguridad. Las franjas están separadas por horas,
        // así que en marcha normal esto no se activa nunca: existe para el caso
        // anormal —un reintento, dos ejecuciones solapadas, un cron duplicado—
        // en el que la llave de idempotencia no ayuda porque las franjas que se
        // pisan son distintas.
        $ultimo = NotificationDispatch::query()
            ->where('member_id', $memberId)
            ->sent()
            ->whereIn('category', self::WELLNESS)
            ->max('sent_at');

        if ($ultimo !== null) {
            $minutos = CarbonImmutable::parse($ultimo)->diffInMinutes($now, absolute: true);
            if ($minutos < self::minIntervalMinutes()) {
                return NotificationDispatch::REASON_MIN_INTERVAL;
            }
        }

        return null;
    }

    /** Minutos que deben pasar como mínimo entre dos avisos de bienestar. */
    private static function minIntervalMinutes(): int
    {
        return (int) config('notifications.wellness.min_interval_minutes', 150);
    }

    /** Medianoche del gimnasio expresada en el instante de referencia. */
    private static function localMidnight(CarbonImmutable $now): CarbonImmutable
    {
        try {
            return $now->setTimezone(SendingWindow::timezone())
                ->startOfDay()
                ->setTimezone($now->getTimezone());
        } catch (Throwable) {
            return $now->startOfDay();
        }
    }

    /**
     * Categorías sujetas a los cupos de bienestar.
     *
     * Nutrición entró aquí al convertirse en contenido de acompañamiento. Los
     * avisos de nutrición del coach IRON IA no se ven afectados: van por
     * `AppNotificationService` y no escriben en el libro mayor, así que no
     * consumen cupo ni desplazan a los del motor.
     */
    private const WELLNESS = [
        NotificationCategory::MOTIVATION,
        NotificationCategory::HYDRATION,
        NotificationCategory::RECOVERY,
        NotificationCategory::NUTRITION,
        NotificationCategory::SUPPLEMENTS,
    ];

    /**
     * @param  list<string>  $tokens
     * @return array{delivered:int,unregistered:int}
     */
    private function push(
        array $tokens,
        string $category,
        string $title,
        string $body,
        ?string $actionRoute,
        ?string $templateKey,
    ): array {
        $delivered = 0;
        $caducados = 0;

        foreach ($tokens as $token) {
            try {
                $unregistered = false;
                $ok = $this->client->send([
                    'token' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => array_map('strval', array_filter([
                        'category' => $category,
                        // La app rutea y estiliza por `type`; sin él, todo lo
                        // que sale de aquí se vería como un aviso genérico.
                        'type' => NotificationCategory::appType($category),
                        'template_key' => $templateKey,
                        'action_route' => $actionRoute,
                        'action_type' => $actionRoute !== null ? 'route' : null,
                        'source' => 'iron_body',
                    ], fn ($v) => $v !== null)),
                    'android' => PushChannel::androidBlock($category),
                    'apns' => PushChannel::apnsBlock($category),
                ], $unregistered);

                if ($ok) {
                    $delivered++;
                } elseif ($unregistered) {
                    $caducados++;
                    MemberDeviceToken::where('token', $token)
                        ->update(['is_active' => false, 'updated_at' => now()]);
                }
            } catch (Throwable $e) {
                Log::warning('Notificaciones: fallo enviando token', ['error' => class_basename($e)]);
            }
        }

        return ['delivered' => $delivered, 'unregistered' => $caducados];
    }

    /**
     * Escribe el intento. Si dos procesos corren a la vez, el índice único de
     * `idempotency_key` decide, y el que pierde devuelve la fila del que ganó.
     */
    private function record(array $attributes): NotificationDispatch
    {
        try {
            return NotificationDispatch::create($attributes);
        } catch (QueryException $e) {
            $existing = NotificationDispatch::query()
                ->firstWhere('idempotency_key', $attributes['idempotency_key']);

            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }
    }
}
