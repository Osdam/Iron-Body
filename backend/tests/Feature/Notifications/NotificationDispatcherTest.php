<?php

namespace Tests\Feature\Notifications;

use App\Models\MemberNotificationPreference;
use App\Models\MemberSuspension;
use App\Models\NotificationDispatch;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Moderation\ModerationScope;
use App\Support\Notifications\NotificationCategory;
use Carbon\CarbonImmutable;

/**
 * Las puertas del motor de envío. Cada test comprueba que una razón concreta
 * para NO enviar queda además registrada, porque "no me llegó" y "el sistema
 * decidió callarse" tienen que ser distinguibles después.
 */
class NotificationDispatcherTest extends NotificationTestCase
{
    private function dispatcher(): NotificationDispatcher
    {
        return app(NotificationDispatcher::class);
    }

    /** Mediodía en Colombia: fuera de las horas de silencio por defecto. */
    private function midday(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-07-30 17:00:00', 'UTC');
    }

    public function test_envia_cuando_todo_esta_en_orden(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        $dispatch = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::MOTIVATION,
            title: 'Mantén la constancia',
            body: 'Cada sesión suma.',
            now: $this->midday(),
        );

        $this->assertSame(NotificationDispatch::STATUS_SENT, $dispatch->status);
        $this->assertSame(1, $dispatch->tokens_delivered);
        $this->assertNotNull($dispatch->sent_at);
    }

    public function test_no_envia_si_el_socio_apago_la_categoria(): void
    {
        $member = $this->makeMember();
        $this->giveDevice($member);
        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'categories' => [NotificationCategory::MOTIVATION => false],
        ]);

        $dispatch = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::MOTIVATION,
            title: 'Título',
            body: 'Cuerpo',
            now: $this->midday(),
        );

        $this->assertSame(NotificationDispatch::STATUS_SUPPRESSED, $dispatch->status);
        $this->assertSame(NotificationDispatch::REASON_OPTED_OUT, $dispatch->reason);
    }

    public function test_el_interruptor_general_apaga_todo_lo_opcional(): void
    {
        $member = $this->makeMember();
        $this->giveDevice($member);
        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'opted_out_at' => now(),
        ]);

        $dispatch = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::PAYMENTS,
            title: 'Pago',
            body: 'Cuerpo',
            now: $this->midday(),
        );

        $this->assertSame(NotificationDispatch::REASON_OPTED_OUT, $dispatch->reason);
    }

    public function test_la_seguridad_de_la_cuenta_llega_aunque_este_todo_apagado(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);
        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'opted_out_at' => now(),
            'categories' => [NotificationCategory::ACCOUNT_SECURITY => false],
        ]);

        // Medianoche en Colombia: dentro de las horas de silencio.
        $dispatch = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::ACCOUNT_SECURITY,
            title: 'Nuevo inicio de sesión',
            body: 'Alguien entró en tu cuenta.',
            now: CarbonImmutable::parse('2026-07-30 05:00:00', 'UTC'),
        );

        $this->assertSame(NotificationDispatch::STATUS_SENT, $dispatch->status);
    }

    /**
     * Instante elegido a propósito: las 21:30 en Bogotá.
     *
     * Está DENTRO de la franja del gimnasio (07:00–22:00) y DENTRO de las horas
     * de silencio por defecto del socio (21:00–07:00). Así se prueba el
     * silencio del socio y no la ventana del negocio, que se comprueba aparte
     * en {@see SendingWindowTest}.
     */
    private function nocheTemprana(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-07-30 21:30:00', 'America/Bogota')->setTimezone('UTC');
    }

    public function test_no_envia_en_horas_de_silencio(): void
    {
        $member = $this->makeMember();
        $this->giveDevice($member);
        // El silencio se declara explícitamente. Por defecto empieza a las 22,
        // justo donde cierra la ventana del gimnasio, así que con los valores
        // de fábrica esta comprobación no probaría el silencio sino la ventana.
        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'quiet_hours_start' => 21,
            'quiet_hours_end' => 7,
        ]);

        $dispatch = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::MOTIVATION,
            title: 'Título',
            body: 'Cuerpo',
            now: $this->nocheTemprana(),
        );

        $this->assertSame(NotificationDispatch::REASON_QUIET_HOURS, $dispatch->reason);
    }

    public function test_respeta_la_zona_horaria_del_socio(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);
        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'timezone' => 'America/Los_Angeles',
            'quiet_hours_start' => 21,
            'quiet_hours_end' => 7,
        ]);

        // El MISMO instante que el test anterior. Para un socio en Bogotá son
        // las 21:30 y está callado; para uno en Los Ángeles son las 19:30 y
        // todavía no. Misma hora absoluta, decisión distinta: eso es lo que
        // demuestra que manda la zona horaria de cada persona.
        $dispatch = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::MOTIVATION,
            title: 'Título',
            body: 'Cuerpo',
            now: $this->nocheTemprana(),
        );

        $this->assertSame(NotificationDispatch::STATUS_SENT, $dispatch->status);
    }

    public function test_la_misma_llave_de_idempotencia_no_envia_dos_veces(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        $first = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::MOTIVATION,
            title: 'Título',
            body: 'Cuerpo',
            idempotencyKey: 'wellness:'.$member->id.':2026-07-30',
            now: $this->midday(),
        );

        $second = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::MOTIVATION,
            title: 'Otro título',
            body: 'Otro cuerpo',
            idempotencyKey: 'wellness:'.$member->id.':2026-07-30',
            now: $this->midday(),
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, NotificationDispatch::query()->count());
    }

    public function test_respeta_el_limite_diario(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);
        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'max_per_day' => 1,
        ]);

        $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::CLASSES,
            title: 'Primera',
            body: 'Cuerpo',
            now: $this->midday(),
        );

        $second = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::CLASSES,
            title: 'Segunda',
            body: 'Cuerpo',
            now: $this->midday()->addMinute(),
        );

        $this->assertSame(NotificationDispatch::REASON_DAILY_LIMIT, $second->reason);
    }

    public function test_respeta_el_limite_semanal_de_bienestar(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);
        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'max_wellness_per_week' => 1,
        ]);

        $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::MOTIVATION,
            title: 'Primera',
            body: 'Cuerpo',
            now: $this->midday(),
        );

        $second = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::HYDRATION,
            title: 'Segunda',
            body: 'Cuerpo',
            now: $this->midday()->addDay(),
        );

        $this->assertSame(NotificationDispatch::REASON_WEEKLY_LIMIT, $second->reason);
    }

    public function test_no_envia_sin_token_activo(): void
    {
        $member = $this->makeMember();
        $this->giveDevice($member, active: false);

        $dispatch = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::MOTIVATION,
            title: 'Título',
            body: 'Cuerpo',
            now: $this->midday(),
        );

        $this->assertSame(NotificationDispatch::REASON_NO_TOKEN, $dispatch->reason);
    }

    public function test_un_token_muerto_se_desactiva_no_se_borra(): void
    {
        $this->fakeFcmUnregistered();
        $member = $this->makeMember();
        $token = $this->giveDevice($member);

        $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::MOTIVATION,
            title: 'Título',
            body: 'Cuerpo',
            now: $this->midday(),
        );

        $token->refresh();
        $this->assertFalse($token->is_active, 'El token debe quedar desactivado.');
        $this->assertDatabaseHas('member_device_tokens', ['id' => $token->id]);
    }

    public function test_no_envia_a_quien_tiene_el_acceso_suspendido(): void
    {
        $member = $this->makeMember();
        $this->giveDevice($member);

        MemberSuspension::create([
            'member_id' => $member->id,
            'scope' => ModerationScope::FULL_APP_ACCESS,
            'reason' => 'prueba',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(3),
        ]);

        $dispatch = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::MOTIVATION,
            title: 'Título',
            body: 'Cuerpo',
            now: $this->midday(),
        );

        $this->assertSame(NotificationDispatch::REASON_NOT_ELIGIBLE, $dispatch->reason);
    }

    public function test_no_envia_contenido_incompleto(): void
    {
        $member = $this->makeMember();
        $this->giveDevice($member);

        $dispatch = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::MOTIVATION,
            title: 'Título',
            body: '   ',
            now: $this->midday(),
        );

        $this->assertSame(NotificationDispatch::REASON_INCOMPLETE, $dispatch->reason);
    }

    public function test_un_suplemento_sin_subtipo_no_sale(): void
    {
        $member = $this->makeMember();
        $this->giveDevice($member);
        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'categories' => [NotificationCategory::SUPPLEMENTS => true],
        ]);

        $dispatch = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::SUPPLEMENTS,
            title: 'Creatina',
            body: 'Cuerpo',
            supplementKind: null,
            now: $this->midday(),
        );

        $this->assertSame(NotificationDispatch::REASON_OPTED_OUT, $dispatch->reason);
    }

    public function test_los_suplementos_estan_apagados_por_defecto(): void
    {
        $member = $this->makeMember();
        $this->giveDevice($member);

        $dispatch = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::SUPPLEMENTS,
            title: 'Creatina',
            body: 'Cuerpo',
            supplementKind: NotificationCategory::SUPPLEMENT_CREATINE,
            now: $this->midday(),
        );

        $this->assertSame(NotificationDispatch::REASON_OPTED_OUT, $dispatch->reason);
    }

    public function test_apagar_un_subtipo_no_afecta_a_los_demas(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);
        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'categories' => [NotificationCategory::SUPPLEMENTS => true],
            'supplement_kinds' => [NotificationCategory::SUPPLEMENT_CREATINE => false],
        ]);

        $creatina = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::SUPPLEMENTS,
            title: 'Creatina',
            body: 'Cuerpo',
            supplementKind: NotificationCategory::SUPPLEMENT_CREATINE,
            now: $this->midday(),
        );
        $this->assertSame(NotificationDispatch::REASON_OPTED_OUT, $creatina->reason);

        $proteina = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::SUPPLEMENTS,
            title: 'Proteína',
            body: 'Cuerpo',
            supplementKind: NotificationCategory::SUPPLEMENT_PROTEIN,
            now: $this->midday(),
        );
        $this->assertSame(NotificationDispatch::STATUS_SENT, $proteina->status);
    }

    public function test_apagar_la_categoria_apaga_todos_los_subtipos(): void
    {
        $member = $this->makeMember();
        $this->giveDevice($member);
        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'categories' => [NotificationCategory::SUPPLEMENTS => false],
            'supplement_kinds' => [NotificationCategory::SUPPLEMENT_PROTEIN => true],
        ]);

        $dispatch = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::SUPPLEMENTS,
            title: 'Proteína',
            body: 'Cuerpo',
            supplementKind: NotificationCategory::SUPPLEMENT_PROTEIN,
            now: $this->midday(),
        );

        $this->assertSame(NotificationDispatch::REASON_OPTED_OUT, $dispatch->reason);
    }

    public function test_no_envia_si_fcm_esta_apagado(): void
    {
        config(['fcm.enabled' => false]);
        $member = $this->makeMember();
        $this->giveDevice($member);

        $dispatch = $this->dispatcher()->dispatch(
            memberId: $member->id,
            category: NotificationCategory::MOTIVATION,
            title: 'Título',
            body: 'Cuerpo',
            now: $this->midday(),
        );

        $this->assertSame(NotificationDispatch::REASON_FCM_DISABLED, $dispatch->reason);
    }
}
