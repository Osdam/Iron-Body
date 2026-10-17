<?php

namespace Tests\Feature\Valuations;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\MyClass;
use App\Models\RolePermission;
use App\Models\Trainer;
use App\Models\User;
use App\Models\ValuationAppointment;
use App\Models\ValuationAvailabilityRule;
use App\Models\ValuationSetting;
use App\Support\Access\CrmPermission;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Datos sintéticos para la agenda de valoraciones.
 *
 * EL RELOJ VA EN UTC (memoria «relojes de prueba en UTC»): se fija con el
 * instante UTC de una hora de Bogotá. Con el reloj en zona Bogotá, las columnas
 * datetime se leerían en Bogotá y las pruebas esconderían los desfases que en
 * producción (UTC) sí ocurren.
 *
 * Calendario de referencia: lunes 19-10-2026, 08:00 de Bogotá (13:00 UTC).
 */
trait ValuationFixtures
{
    protected function clock(string $bogota): Carbon
    {
        $utc = Carbon::parse($bogota, 'America/Bogota')->utc();
        Carbon::setTestNow($utc);

        return $utc;
    }

    protected function trainer(string $nombre = 'Andrés Pérez', array $extra = []): Trainer
    {
        return Trainer::create(array_merge([
            'full_name' => $nombre,
            'email' => 'coach-'.Str::random(8).'@ironbody.test',
            'document' => (string) random_int(10_000_000, 99_999_999),
            'phone' => '3001234567',
            'status' => 'active',
            'location' => 'Sede principal',
        ], $extra));
    }

    /** Socio con cuenta y membresía (fin = `$finMembresia`, o sin membresía si null). */
    protected function member(string $nombre = 'Laura Gómez', ?string $finMembresia = '2027-01-31', array $extra = []): Member
    {
        $documento = $extra['document_number'] ?? (string) random_int(1_000_000_000, 1_099_999_999);
        $user = User::create([
            'name' => $nombre,
            'email' => 'socio-'.Str::random(8).'@ironbody.test',
            'password' => bcrypt('secret-password'),
            'document' => $documento,
            'status' => 'active',
            'membership_end_date' => $finMembresia,
        ]);

        return Member::create(array_merge([
            'full_name' => $nombre,
            'email' => 'm-'.Str::random(8).'@ironbody.test',
            'document_number' => $documento,
            'phone' => '3109876543',
            'status' => Member::STATUS_ACTIVE,
            'user_id' => $user->id,
        ], $extra));
    }

    protected function assign(Member $m, Trainer $t): MemberTrainerAssignment
    {
        return MemberTrainerAssignment::create([
            'member_id' => $m->id,
            'trainer_id' => $t->id,
            'status' => MemberTrainerAssignment::STATUS_ACTIVE,
            'assigned_at' => now(),
        ]);
    }

    protected function rule(Trainer $t, int $weekday, string $inicio, string $fin, array $extra = []): ValuationAvailabilityRule
    {
        return ValuationAvailabilityRule::create(array_merge([
            'trainer_id' => $t->id,
            'weekday' => $weekday,
            'start_time' => $inicio,
            'end_time' => $fin,
            'active' => true,
        ], $extra));
    }

    /** La misma franja los siete días. */
    protected function everyDay(Trainer $t, string $inicio = '06:00', string $fin = '21:00'): void
    {
        foreach (range(1, 7) as $d) {
            $this->rule($t, $d, $inicio, $fin);
        }
    }

    protected function settings(array $cambios): ValuationSetting
    {
        $s = ValuationSetting::query()->orderBy('id')->firstOrFail();
        $s->forceFill($cambios)->save();

        return $s;
    }

    protected function klass(Trainer $t, array $extra = []): MyClass
    {
        return MyClass::create(array_merge([
            'name' => 'Spinning', 'type' => 'Cardio', 'trainer_id' => $t->id, 'day_of_week' => 'Lunes',
            'start_time' => '18:00', 'end_time' => '19:00', 'status' => 'active',
            'max_capacity' => 20, 'allow_online_booking' => true, 'is_recurring' => true,
        ], $extra));
    }

    /** Admin con un rol del sistema y, si se da, vinculado a un entrenador. */
    protected function staff(string $rol, ?Trainer $entrenador = null, string $nombre = 'Persona'): Admin
    {
        return Admin::create([
            'name' => $nombre.' '.$rol,
            'email' => 'staff-'.Str::random(8).'@ironbody.test',
            'password' => 'secret-password',
            'role' => $rol,
            'status' => 'active',
            'trainer_id' => $entrenador?->id,
        ]);
    }

    /** Admin con EXACTAMENTE estos permisos (rol de prueba), opcionalmente vinculado. */
    protected function staffWith(array $permisos, ?Trainer $entrenador = null): Admin
    {
        $rol = 'Prueba-'.substr(md5(implode(',', $permisos)), 0, 8);
        AdminRole::firstOrCreate(['name' => $rol], ['is_system' => false]);
        foreach ($permisos as $p) {
            RolePermission::updateOrCreate(['role' => $rol, 'permission' => $p], ['granted' => true]);
        }
        app(RolePermissionPolicy::class)->flush();

        return $this->staff($rol, $entrenador);
    }

    protected function h(Admin $a): array
    {
        return $this->actingAsAdmin($a);
    }

    protected function reception(): array
    {
        return $this->h($this->staff(Admin::ROLE_RECEPCION));
    }

    protected function superAdmin(): Admin
    {
        return $this->staff(Admin::ROLE_SUPER_ADMIN, null, 'Root');
    }

    /** Cuerpo de POST /api/admin/valuations. `$cuando` en hora de Bogotá, sin desfase. */
    protected function payload(Member $m, Trainer $t, string $cuando, array $extra = []): array
    {
        return array_merge([
            'client_request_id' => (string) Str::uuid(),
            'member_id' => $m->id,
            'trainer_id' => $t->id,
            'type' => 'initial',
            'starts_at' => $cuando,
            'duration_minutes' => 60,
            'notes' => null,
        ], $extra);
    }

    protected function book(array $headers, Member $m, Trainer $t, string $cuando, array $extra = []): TestResponse
    {
        return $this->postJson('/api/admin/valuations', $this->payload($m, $t, $cuando, $extra), $headers);
    }

    protected function appointment(string $uuid): ValuationAppointment
    {
        return ValuationAppointment::query()->where('uuid', $uuid)->firstOrFail();
    }

    protected function grants(string $rol, string $permiso): bool
    {
        return in_array($permiso, CrmPermission::defaultsFor($rol), true);
    }
}
