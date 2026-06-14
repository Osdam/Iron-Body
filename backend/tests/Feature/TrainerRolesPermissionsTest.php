<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTrainerFeature;
use App\Http\Middleware\EnsureTrainerPermission;
use App\Models\Identity;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Support\TrainerFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Fase 2 — Roles y permisos. Verifica que los permisos derivan del catálogo
 * central por rol, que múltiples roles dan la unión, los negativos, las
 * feature flags (incl. piloto) y los middlewares de permiso/feature.
 */
class TrainerRolesPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function trainer(array $roles = [], string $status = 'active'): Trainer
    {
        $trainer = Trainer::create([
            'full_name' => 'Pro Trainer',
            'document' => '100200300',
            'status' => $status,
        ]);
        $trainer->syncRoles($roles);

        return $trainer->fresh('roleAssignments');
    }

    public function test_floor_role_grants_floor_permissions(): void
    {
        $trainer = $this->trainer([TrainerRole::FLOOR]);

        $this->assertTrue($trainer->hasPermission('members.view_assigned'));
        $this->assertTrue($trainer->hasPermission('assessments.submit'));
        $this->assertTrue($trainer->hasPermission('routines.assign'));
        // Lo de funcional NO está incluido:
        $this->assertFalse($trainer->hasPermission('classes.manage'));
        $this->assertFalse($trainer->hasPermission('attendance.create'));
    }

    public function test_functional_role_grants_functional_permissions(): void
    {
        $trainer = $this->trainer([TrainerRole::FUNCTIONAL]);

        $this->assertTrue($trainer->hasPermission('classes.manage'));
        $this->assertTrue($trainer->hasPermission('attendance.update'));
        $this->assertTrue($trainer->hasPermission('assessments.create'));
        // Lo exclusivo de planta NO está:
        $this->assertFalse($trainer->hasPermission('members.search'));
        $this->assertFalse($trainer->hasPermission('routines.assign'));
    }

    public function test_dual_role_grants_union_of_permissions(): void
    {
        $trainer = $this->trainer([TrainerRole::FLOOR, TrainerRole::FUNCTIONAL]);

        $this->assertTrue($trainer->hasPermission('members.search'));
        $this->assertTrue($trainer->hasPermission('classes.manage'));
        $this->assertTrue($trainer->hasPermission('attendance.create'));
        $this->assertTrue($trainer->hasPermission('trainer.portal.access'));
    }

    public function test_trainer_without_roles_has_no_permissions(): void
    {
        $trainer = $this->trainer([]);

        $this->assertSame([], $trainer->permissions());
        $this->assertFalse($trainer->hasPermission('trainer.portal.access'));
    }

    public function test_inactive_trainer_has_no_permissions(): void
    {
        $trainer = $this->trainer([TrainerRole::FLOOR, TrainerRole::FUNCTIONAL], status: 'inactive');

        $this->assertFalse($trainer->hasPermission('trainer.portal.access'));
        $this->assertFalse($trainer->hasPermission('classes.manage'));
    }

    public function test_sync_roles_is_idempotent_and_removes(): void
    {
        $trainer = $this->trainer([TrainerRole::FLOOR]);
        $trainer->syncRoles([TrainerRole::FLOOR, TrainerRole::FUNCTIONAL]);
        $this->assertEqualsCanonicalizing(
            [TrainerRole::FLOOR, TrainerRole::FUNCTIONAL],
            $trainer->fresh('roleAssignments')->roleNames(),
        );

        $trainer->syncRoles([TrainerRole::FUNCTIONAL]);
        $this->assertSame([TrainerRole::FUNCTIONAL], $trainer->fresh('roleAssignments')->roleNames());
        $this->assertSame(1, $trainer->roleAssignments()->count());
    }

    public function test_sync_roles_ignores_invalid_roles(): void
    {
        $trainer = $this->trainer(['admin', 'trainer_floor', 'superuser']);

        $this->assertSame([TrainerRole::FLOOR], $trainer->fresh('roleAssignments')->roleNames());
    }

    public function test_feature_disabled_by_default(): void
    {
        config(['trainer.flags.trainer_auth_enabled' => false, 'trainer.pilot_identities' => []]);

        $this->assertFalse(TrainerFeatures::enabled('trainer_auth_enabled'));
        $this->assertFalse(TrainerFeatures::enabled('unknown_flag'));
    }

    public function test_feature_enabled_globally(): void
    {
        config(['trainer.flags.trainer_portal_enabled' => true]);

        $this->assertTrue(TrainerFeatures::enabled('trainer_portal_enabled'));
    }

    public function test_feature_enabled_for_pilot_identity_only(): void
    {
        $identity = Identity::create(['document_normalized' => 'PILOT1']);
        $other = Identity::create(['document_normalized' => 'OTHER1']);

        config([
            'trainer.flags.trainer_portal_enabled' => false,
            'trainer.pilot_identities' => [(string) $identity->getKey()],
        ]);

        $this->assertTrue(TrainerFeatures::enabled('trainer_portal_enabled', $identity));
        $this->assertFalse(TrainerFeatures::enabled('trainer_portal_enabled', $other));
        $this->assertFalse(TrainerFeatures::enabled('trainer_portal_enabled', null));
    }

    public function test_permission_middleware_blocks_and_allows(): void
    {
        $middleware = new EnsureTrainerPermission;
        $trainer = $this->trainer([TrainerRole::FUNCTIONAL]);

        // Sin entrenador autenticado → 401.
        $noAuth = $middleware->handle(Request::create('/x'), fn () => response('ok'), 'classes.manage');
        $this->assertSame(401, $noAuth->getStatusCode());

        // Con permiso → pasa.
        $withPerm = Request::create('/x');
        $withPerm->attributes->set('auth_trainer', $trainer);
        $ok = $middleware->handle($withPerm, fn () => response('ok'), 'classes.manage');
        $this->assertSame(200, $ok->getStatusCode());

        // Sin el permiso concreto → 403.
        $denied = Request::create('/x');
        $denied->attributes->set('auth_trainer', $trainer);
        $forbidden = $middleware->handle($denied, fn () => response('ok'), 'members.search');
        $this->assertSame(403, $forbidden->getStatusCode());
    }

    public function test_feature_middleware_hides_when_off(): void
    {
        config(['trainer.flags.trainer_auth_enabled' => false, 'trainer.pilot_identities' => []]);
        $middleware = new EnsureTrainerFeature;

        $off = $middleware->handle(Request::create('/x'), fn () => response('ok'), 'trainer_auth_enabled');
        $this->assertSame(404, $off->getStatusCode());

        config(['trainer.flags.trainer_auth_enabled' => true]);
        $on = $middleware->handle(Request::create('/x'), fn () => response('ok'), 'trainer_auth_enabled');
        $this->assertSame(200, $on->getStatusCode());
    }
}
