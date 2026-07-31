<?php

namespace Tests\Feature\Notifications;

use App\Support\Notifications\NotificationCategory as Cat;
use App\Support\Notifications\PushChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El contrato entre el router de n8n y las categorías del backend.
 *
 * El router emite tipos con punto (`nutrition.missing`, `streak.at_risk`). El
 * mapeo original solo miraba la cadena completa, así que los 19 caían en el
 * respaldo: salían por el canal de máxima prioridad y ningún interruptor de la
 * app los alcanzaba. Estos tests impiden que vuelva a pasar en silencio.
 */
class CoachEventMappingTest extends TestCase
{
    /**
     * Los 19 tipos que el workflow «Iron Body - Automation Router» puede
     * emitir hoy, con la categoría que les corresponde.
     */
    public static function coachEvents(): array
    {
        return [
            'nutrition.missing' => ['nutrition.missing', Cat::NUTRITION],
            'iron_ai.nutrition_invite' => ['iron_ai.nutrition_invite', Cat::WORKOUTS],
            'workout.missed' => ['workout.missed', Cat::WORKOUTS],
            'workout.not_started_today' => ['workout.not_started_today', Cat::WORKOUTS],
            'streak.completed' => ['streak.completed', Cat::WORKOUTS],
            'streak.at_risk' => ['streak.at_risk', Cat::WORKOUTS],
            'streak.not_started' => ['streak.not_started', Cat::WORKOUTS],
            'evaluation.outdated' => ['evaluation.outdated', Cat::WORKOUTS],
            'progress.stalled' => ['progress.stalled', Cat::WORKOUTS],
            'daily.compliance_missing' => ['daily.compliance_missing', Cat::WORKOUTS],
            'weekly.coach_plan' => ['weekly.coach_plan', Cat::WORKOUTS],
            'coach.nudge' => ['coach.nudge', Cat::WORKOUTS],
            'coach.reactivation' => ['coach.reactivation', Cat::WORKOUTS],
            'module.discovery' => ['module.discovery', Cat::WORKOUTS],
            'iron_ai.chat_invite' => ['iron_ai.chat_invite', Cat::WORKOUTS],
            'iron_ai.progress_invite' => ['iron_ai.progress_invite', Cat::WORKOUTS],
            'iron_ai.streak_invite' => ['iron_ai.streak_invite', Cat::WORKOUTS],
            'iron_ai.weekly_summary_ready' => ['iron_ai.weekly_summary_ready', Cat::WORKOUTS],
            'membership.expiring' => ['membership.expiring', Cat::MEMBERSHIP],
        ];
    }

    #[DataProvider('coachEvents')]
    public function test_cada_evento_del_coach_cae_en_su_categoria(string $type, string $expected): void
    {
        $this->assertSame(
            $expected,
            Cat::fromLegacyType($type),
            "El evento {$type} no se clasifica donde debe: el socio no podría apagarlo desde su categoría.",
        );
    }

    /**
     * La separación que pidió el gimnasio: el coach de IRON IA y las
     * notificaciones de bienestar son cosas distintas y se apagan por separado.
     */
    #[DataProvider('coachEvents')]
    public function test_ningun_evento_del_coach_invade_el_bienestar(string $type): void
    {
        $this->assertNotContains(
            Cat::fromLegacyType($type),
            [Cat::MOTIVATION, Cat::HYDRATION, Cat::RECOVERY, Cat::SUPPLEMENTS],
            "El evento {$type} cayó en una categoría de bienestar: apagar los consejos de "
            .'suplementos silenciaría también al coach, y al revés.',
        );
    }

    public function test_solo_lo_urgente_usa_el_canal_de_maxima_prioridad(): void
    {
        // El coach no es una urgencia. Antes TODOS sus eventos salían por
        // `iron_body_high` por culpa del respaldo, sonando como un pago.
        foreach (self::coachEvents() as [$type, $expected]) {
            if ($expected === Cat::MEMBERSHIP) {
                continue; // El vencimiento de la membresía sí lo es.
            }

            $this->assertNotSame(
                PushChannel::HIGH,
                PushChannel::forCategory(Cat::fromLegacyType($type)),
                "El evento {$type} sale por el canal de máxima prioridad sin serlo.",
            );
        }
    }

    public function test_un_tipo_desconocido_no_se_cuela_como_obligatorio(): void
    {
        foreach (['algo.inventado', 'sin_punto', '', null] as $type) {
            $categoria = Cat::fromLegacyType($type);

            $this->assertFalse(
                Cat::isMandatory($categoria),
                'Un tipo sin mapear no debe heredar la categoría que ignora las preferencias.',
            );
        }
    }

    public function test_las_familias_conocidas_se_resuelven_sin_el_sufijo(): void
    {
        $this->assertSame(Cat::NUTRITION, Cat::fromLegacyType('nutrition'));
        $this->assertSame(Cat::WORKOUTS, Cat::fromLegacyType('workout'));
        $this->assertSame(Cat::ACCOUNT_SECURITY, Cat::fromLegacyType('security.face_failed'));
        $this->assertSame(Cat::PAYMENTS, Cat::fromLegacyType('payment.declined'));
    }
}
