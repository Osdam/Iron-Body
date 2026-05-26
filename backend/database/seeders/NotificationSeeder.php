<?php

namespace Database\Seeders;

use App\Models\Member;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Database\Seeder;

/**
 * Datos de prueba de notificaciones — SOLO desarrollo.
 *
 * Garantiza un miembro demo con documento 123456789 y genera notificaciones
 * realistas (app + CRM). Idempotente: usa los mismos event_key del
 * NotificationService, así que re-ejecutarlo no duplica nada.
 *
 *   php artisan db:seed --class=NotificationSeeder
 */
class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(NotificationService::class);

        $member = Member::firstOrCreate(
            ['document_number' => '123456789'],
            [
                'full_name' => 'Juan Casas Demo',
                'email'     => 'demo.notificaciones@ironbody.local',
                'phone'     => '3000000000',
                'status'    => Member::STATUS_ACTIVE,
            ]
        );

        // ── App (audience=member) + espejo admin donde aplica ──
        $service->notifyPaymentApproved($member, [
            'reference' => 'IRON-DEMO-0001',
            'amount'    => 120000,
        ]);

        $service->notifyMembershipActivated($member, [
            'name'                => 'Plan Mensual',
            'id'                  => 1,
            'membership_end_date' => now()->addDays(30)->toDateString(),
        ]);

        $service->notifyMembershipExpiring($member, [
            'name'                => 'Plan Mensual',
            'membership_end_date' => now()->addDays(4)->toDateString(),
        ]);

        $service->notifyClassReserved($member, [
            'id'           => 101,
            'name'         => 'CrossFit Avanzado',
            'day_of_week'  => 'Lunes',
            'start_time'   => '18:00',
        ]);

        $service->notifyRoutineAssigned($member, [
            'id'   => 55,
            'name' => 'Full Body Fuerza',
        ]);

        $service->notifyIronAiRecommendation($member, [
            'id'      => 'demo-rec-1',
            'title'   => 'IRON IA recomienda',
            'message' => 'Hoy es buen día para entrenar piernas. Recuerda calentar 8 minutos.',
        ]);

        // Pago rechazado (otra referencia) para ver el tipo en rojo.
        $service->notifyPaymentRejected($member, [
            'reference'      => 'IRON-DEMO-0002',
            'amount'         => 79900,
            'failure_reason' => 'Fondos insuficientes',
        ]);

        // Promoción difundida a todos los miembros (sin destinatario concreto).
        $this->ensureBroadcast(
            'promo_demo_2x1',
            'promotion',
            '2x1 en clases esta semana',
            'Trae a un amigo y entrena gratis en cualquier clase grupal hasta el domingo.',
        );

        // ── CRM (audience=admin) ──
        $service->notifySystem(
            'Sistema conectado',
            'El backend de Iron Body está conectado y operativo.',
            Notification::AUDIENCE_ADMIN,
            ['event_key' => 'system_backend_connected', 'priority' => 'low', 'type' => 'system'],
        );

        // Variar fechas para que se vea agrupación y "time ago" realista.
        $this->backdate('class_reserved_101_' . $member->id, now()->subMinutes(2));
        $this->backdate('routine_assigned_55_' . $member->id, now()->subHours(5));
        $this->backdate('iron_ai_' . $member->id . '_demo-rec-1', now()->subDay());
        $this->backdate('payment_approved_IRON-DEMO-0001', now()->subDays(2));
        $this->backdate('payment_rejected_IRON-DEMO-0002', now()->subDays(3));

        $this->command?->info("NotificationSeeder OK · miembro demo id={$member->id} document=123456789");
    }

    private function ensureBroadcast(string $key, string $type, string $title, string $message): void
    {
        Notification::firstOrCreate(
            ['event_key' => $key],
            [
                'audience' => Notification::AUDIENCE_MEMBER,
                'type'     => $type,
                'title'    => $title,
                'message'  => $message,
                'priority' => 'low',
            ],
        );
    }

    private function backdate(string $eventKey, \DateTimeInterface $when): void
    {
        Notification::where('event_key', $eventKey)
            ->update(['created_at' => $when, 'updated_at' => $when]);
    }
}
