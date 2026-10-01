<?php

namespace Tests\Feature\Classes;

use App\Models\ClassEvent;
use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MyClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La baja de una cuenta y sus reservas de clase.
 *
 * La baja anonimizaba al socio sin tocar sus reservas: seguían ocupando plazas
 * (las heredadas sin fecha, en todas las semanas) y salían como «Cuenta
 * eliminada» en la lista del entrenador y del CRM.
 */
class AccountDeletionReleasesClassesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00', 'America/Bogota')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_la_baja_libera_las_reservas_que_ocupan_cupo_y_conserva_el_historial(): void
    {
        $user = User::create(['name' => 'Beto Baja', 'email' => 'beto@example.com', 'password' => 'secret', 'document' => '2020202020', 'status' => 'active']);
        $socio = Member::create([
            'user_id' => $user->id, 'full_name' => 'Beto Baja', 'document_number' => '2020202020',
            'access_hash' => 'tok-baja-'.uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
        $clase = MyClass::create([
            'name' => 'Funcional', 'type' => 'Funcional', 'day_of_week' => 'Lunes', 'start_time' => '07:00',
            'end_time' => '08:00', 'status' => 'active', 'max_capacity' => 20, 'allow_online_booking' => true, 'is_recurring' => true,
        ]);
        foreach (['2026-09-28', '2026-10-05', '2026-10-12', null] as $fecha) {
            ClassReservation::create(['class_id' => $clase->id, 'member_id' => $socio->id, 'session_date' => $fecha]);
        }

        $pedido = $this->postJson('/api/member/account/delete-request', [], ['Authorization' => 'Bearer '.$socio->access_hash])->assertOk();
        $cuerpo = $pedido->json('requires_otp') ? ['challenge_id' => $pedido->json('challenge_id'), 'code' => $pedido->json('dev_code')] : [];
        $this->postJson('/api/member/account/delete-confirm', $cuerpo, ['Authorization' => 'Bearer '.$socio->access_hash])->assertOk();

        $quedan = ClassReservation::where('member_id', $socio->id)->get()->map(fn ($r) => $r->session_date?->toDateString())->all();
        $this->assertSame(['2026-09-28'], $quedan, 'Solo el historial: la del lunes pasado.');
        $this->assertSame(3, ClassEvent::where('class_id', $clase->id)->where('type', 'booking.cancelled')->where('source', 'system')->count(), 'Cada plaza liberada se anuncia.');
        $this->assertSame(Member::STATUS_DELETED, $socio->fresh()->status);
    }
}
