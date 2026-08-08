<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberContract;
use App\Models\User;
use App\Support\MemberPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceso demo para testers YA registrados: el comando desbloquea el plan sin
 * límites sobre cuentas existentes, nunca crea cuentas, y el acceso caduca solo
 * al vencer la membresía.
 */
class GrantTesterDemoAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Miembro tal como lo deja el registro desde la app: sin pagar, incompleto. */
    private function makeIncompleteMember(string $document, string $phone = '3154830099'): Member
    {
        $user = User::create([
            'name' => 'Tester',
            'email' => "u{$document}@example.com",
            'password' => 'secret',
            'document' => $document,
            'phone' => $phone,
            'status' => 'pending',
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => 'Tester',
            'email' => "u{$document}@example.com",
            'document_number' => $document,
            'phone' => $phone,
            'status' => Member::STATUS_INCOMPLETE,
        ]);
    }

    public function test_grants_full_access_to_an_existing_tester(): void
    {
        $member = $this->makeIncompleteMember('1018229933');

        // Antes: todo bloqueado salvo workouts (sin plan ni membresía).
        $before = MemberPayload::featuresFor($member->user);
        $this->assertFalse((bool) $before['iron_ia']);
        $this->assertFalse((bool) $before['nutrition']);

        $this->artisan('app:grant-tester-demo-access', [
            '--documents' => '1018229933',
            '--days' => 90,
            '--force' => true,
        ])->assertSuccessful();

        $member->refresh()->load('user');

        // Módulos desbloqueados.
        $features = MemberPayload::featuresFor($member->user);
        foreach (['iron_ia', 'ranking', 'classes', 'progress', 'nutrition', 'custom_routines', 'workouts'] as $key) {
            $this->assertTrue((bool) ($features[$key] ?? false), "módulo bloqueado: {$key}");
        }

        // Deja de figurar como registro incompleto.
        $this->assertSame(Member::STATUS_ACTIVE, $member->status);
        $this->assertSame('active', $member->user->status);
        $this->assertSame('Demo App Review', $member->user->plan);

        // Onboarding superado: no lo mandan a términos ni a firmar.
        $this->assertTrue((bool) $member->legalConsent->terms_and_conditions);
        $this->assertNotNull($member->signature);
        $this->assertTrue(
            $member->contracts()->where('status', MemberContract::STATUS_SIGNED)->exists(),
            'el contrato firmado no quedó registrado'
        );
    }

    public function test_never_creates_accounts_for_unknown_documents(): void
    {
        $this->makeIncompleteMember('1018229933');

        // Una cédula buena y una que no existe: aborta entero, sin efectos.
        $this->artisan('app:grant-tester-demo-access', [
            '--documents' => '1018229933,1234567890',
            '--force' => true,
        ])->assertFailed();

        $this->assertDatabaseMissing('members', ['document_number' => '1234567890']);
        $this->assertDatabaseMissing('users', ['document' => '1234567890']);
        // El miembro válido tampoco se tocó: la operación es todo o nada.
        $this->assertSame(Member::STATUS_INCOMPLETE, Member::where('document_number', '1018229933')->first()->status);
    }

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        $member = $this->makeIncompleteMember('1018229933');

        $this->artisan('app:grant-tester-demo-access', ['--documents' => '1018229933'])
            ->assertSuccessful();

        $member->refresh()->load('user');
        $this->assertSame(Member::STATUS_INCOMPLETE, $member->status);
        $this->assertNull($member->user->plan);
    }

    public function test_access_expires_on_its_own_when_the_membership_lapses(): void
    {
        $member = $this->makeIncompleteMember('1018229933');

        $this->artisan('app:grant-tester-demo-access', [
            '--documents' => '1018229933',
            '--days' => 90,
            '--force' => true,
        ])->assertSuccessful();

        $member->refresh()->load('user');
        $this->assertTrue((bool) MemberPayload::featuresFor($member->user)['iron_ia']);

        // Pasada la vigencia, sin tocar nada, todo vuelve a bloquearse.
        $this->travel(91)->days();
        $member->refresh()->load('user');
        $features = MemberPayload::featuresFor($member->user);
        $this->assertFalse((bool) $features['iron_ia']);
        $this->assertFalse((bool) $features['nutrition']);
    }

    public function test_revoke_takes_the_demo_access_back(): void
    {
        $member = $this->makeIncompleteMember('1018229933');

        $this->artisan('app:grant-tester-demo-access', [
            '--documents' => '1018229933', '--force' => true,
        ])->assertSuccessful();

        $this->artisan('app:grant-tester-demo-access', [
            '--documents' => '1018229933', '--revoke' => true, '--force' => true,
        ])->assertSuccessful();

        $member->refresh()->load('user');
        $this->assertNull($member->user->plan);
        $this->assertFalse((bool) MemberPayload::featuresFor($member->user)['iron_ia']);
    }

    public function test_running_it_twice_changes_nothing_extra(): void
    {
        $this->makeIncompleteMember('1018229933');

        foreach (range(1, 2) as $_) {
            $this->artisan('app:grant-tester-demo-access', [
                '--documents' => '1018229933', '--force' => true,
            ])->assertSuccessful();
        }

        // Ni contratos ni consentimientos duplicados.
        $member = Member::where('document_number', '1018229933')->first();
        $this->assertSame(1, $member->contracts()->count());
        $this->assertSame(1, $member->legalConsent()->count());
    }
}
