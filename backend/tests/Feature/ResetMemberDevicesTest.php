<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberDeviceBinding;
use App\Models\MemberDeviceSession;
use App\Models\User;
use App\Services\DeviceSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Desvinculación de dispositivos: tras correr el comando, el miembro puede
 * volver a entrar desde cero sin chocar con el bloqueo por concurrencia ni con
 * el vínculo equipo↔cuenta.
 */
class ResetMemberDevicesTest extends TestCase
{
    use RefreshDatabase;

    private function makeMember(string $document = '1004301550'): Member
    {
        $user = User::create([
            'name' => 'Tester',
            'email' => "u{$document}@example.com",
            'password' => 'secret',
            'document' => $document,
            'phone' => '3215542105',
            'status' => 'active',
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => 'Tester',
            'email' => "u{$document}@example.com",
            'document_number' => $document,
            'phone' => '3215542105',
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    public function test_clears_the_concurrency_block_from_another_device(): void
    {
        $member = $this->makeMember();
        $sessions = app(DeviceSessionService::class);

        // Sesión viva desde el equipo "viejo".
        $sessions->issueSession($member, ['device_id' => 'equipo-viejo', 'device_name' => 'Viejo']);

        // Entrar desde un equipo nuevo choca con el bloqueo por concurrencia.
        $this->assertNotNull(
            $sessions->concurrentActiveSession($member, 'equipo-nuevo'),
            'no se reprodujo el bloqueo por concurrencia'
        );

        $this->artisan('app:reset-member-devices', [
            '--documents' => '1004301550',
            '--force' => true,
        ])->assertSuccessful();

        // Ya no hay sesión que bloquee: el equipo nuevo entra limpio.
        $this->assertNull($sessions->concurrentActiveSession($member, 'equipo-nuevo'));
        $this->assertSame(0, MemberDeviceSession::where('member_id', $member->id)->count());
    }

    public function test_removes_the_device_binding(): void
    {
        $member = $this->makeMember();
        MemberDeviceBinding::create([
            'member_id' => $member->id,
            'device_id' => 'equipo-viejo',
            'device_name' => 'Viejo',
        ]);

        $this->artisan('app:reset-member-devices', [
            '--documents' => '1004301550', '--force' => true,
        ])->assertSuccessful();

        $this->assertNull(MemberDeviceBinding::forDevice('equipo-viejo'));
    }

    public function test_dry_run_is_the_default_and_deletes_nothing(): void
    {
        $member = $this->makeMember();
        app(DeviceSessionService::class)
            ->issueSession($member, ['device_id' => 'equipo-viejo']);

        $this->artisan('app:reset-member-devices', ['--documents' => '1004301550'])
            ->assertSuccessful();

        $this->assertSame(1, MemberDeviceSession::where('member_id', $member->id)->count());
    }

    public function test_leaves_plan_and_membership_untouched(): void
    {
        $member = $this->makeMember();
        $member->user->forceFill([
            'plan' => 'Demo App Review',
            'membership_end_date' => now()->addDays(90)->toDateString(),
        ])->save();

        $this->artisan('app:reset-member-devices', [
            '--documents' => '1004301550', '--force' => true,
        ])->assertSuccessful();

        $member->refresh()->load('user');
        $this->assertSame('Demo App Review', $member->user->plan);
        $this->assertSame(Member::STATUS_ACTIVE, $member->status);
    }

    public function test_fails_loudly_for_an_unknown_document(): void
    {
        $this->artisan('app:reset-member-devices', [
            '--documents' => '9876543210', '--force' => true,
        ])->assertFailed();
    }
}
