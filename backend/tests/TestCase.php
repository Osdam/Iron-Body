<?php

namespace Tests;

use App\Models\Admin;
use App\Models\Member;
use App\Models\Plan;
use App\Models\User;
use App\Services\Admin\AdminSessionService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * Enlaza al miembro con un User y un plan VIGENTE que incluye clases.
     *
     * Reservar exige que el plan del miembro tenga la feature `classes` (misma
     * fuente que usa la app para bloquear la pestaña). Un miembro suelto, sin
     * User ni plan, resuelve todas las features en false y recibiría un 403.
     */
    protected function givePlanWithClasses(Member $member, array $features = []): Member
    {
        $resolved = array_merge(Plan::defaultFeatures(), ['classes' => true], $features);
        // Un plan distinto por combinación de features: si todos compartieran
        // nombre, el primero creado ganaría y los overrides se ignorarían.
        $name = 'Plan Test '.substr(md5(json_encode($resolved)), 0, 8);

        $plan = Plan::firstOrCreate(
            ['name' => $name],
            [
                'price' => 100000,
                'duration_days' => 30,
                'active' => true,
                'features' => $resolved,
            ],
        );

        $user = User::create([
            'name' => $member->full_name,
            'email' => 'plan-'.$member->document_number.'@ironbody.test',
            'password' => bcrypt('secret-password'),
            'document' => $member->document_number,
            'status' => 'active',
            'plan' => $plan->name,
            'membership_end_date' => now()->addYear()->toDateString(),
        ]);

        $member->forceFill(['user_id' => $user->id])->save();

        return $member->refresh();
    }

    /**
     * Cabecera con una sesión admin REAL (login email+contraseña). Crea el admin
     * si no se pasa, emite una sesión vía AdminSessionService y devuelve el
     * header Bearer con el token en claro. Para probar el camino de sesión (no el
     * fallback del secreto compartido).
     */
    protected function actingAsAdmin(?Admin $admin = null, array $headers = []): array
    {
        $admin ??= Admin::create([
            'name' => 'Test Admin',
            'email' => 'test-admin-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);

        $issued = app(AdminSessionService::class)->issueSession($admin);

        return array_merge(['Authorization' => 'Bearer '.$issued['token']], $headers);
    }

    /**
     * Cabecera con el secreto administrativo (blindaje de /api/admin/* y pagos
     * legacy). Usa el token configurado o uno determinista de respaldo. Los
     * helpers admin* de abajo la inyectan para que los tests de funcionalidad
     * CRM crucen el middleware EnsureAdminAuth sin repetir el header a mano.
     */
    /**
     * Abre un turno de caja para el administrador dado (o uno nuevo).
     *
     * Cobrar exige turno abierto desde que Caja lleva arqueo: sin esto, toda
     * prueba que registre una venta recibiría 409. Devuelve el turno.
     */
    protected function openCashShift(
        ?Admin $admin = null,
        \App\Enums\CashShiftType $type = \App\Enums\CashShiftType::PRODUCTS,
    ): \App\Models\CashShift {
        $admin ??= Admin::create([
            'name' => 'Cajero de pruebas',
            'email' => 'cajero-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);

        return app(\App\Services\Caja\CashShiftService::class)->open($admin, $type);
    }

    /**
     * Credenciales de una PERSONA con sesión de Super Admin.
     *
     * Antes devolvía el token compartido de automatizaciones, y con él las
     * pruebas escribían por todo el CRM. Eso funcionaba porque 294 endpoints no
     * comprobaban permisos; ahora que los comprueban, ese token solo lee —que
     * es lo que siempre dijo su documentación—. Las pruebas que verifican los
     * LÍMITES del token compartido usan {@see sharedTokenHeaders()}.
     */
    protected function adminHeaders(array $headers = []): array
    {
        $this->testSuperAdmin ??= Admin::create([
            'name' => 'Super Admin de pruebas',
            'email' => 'suite-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);

        return array_merge($this->actingAsAdmin($this->testSuperAdmin), $headers);
    }

    /**
     * Sesión de un administrador con el rol indicado.
     *
     * Es la forma correcta de autenticar una prueba administrativa: con el rol
     * MÍNIMO que la acción necesita. Usar Super Admin para todo haría pasar la
     * suite sin comprobar nada de la política, que es justo lo que hay que
     * comprobar.
     */
    protected function adminAs(string $role, array $headers = []): array
    {
        $admin = Admin::create([
            'name' => $role.' de pruebas',
            'email' => 'rol-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $role,
            'status' => 'active',
        ]);

        return array_merge($this->actingAsAdmin($admin), $headers);
    }

    /**
     * Sesión con EXACTAMENTE los permisos indicados, ni uno más.
     *
     * Para pruebas que quieren fijar que una acción necesita una llave concreta:
     * se concede solo esa y se comprueba que basta. Si mañana el endpoint pide
     * algo más, la prueba lo dice.
     *
     * @param  list<string>  $permissions
     */
    protected function adminWithPermissions(array $permissions, array $headers = []): array
    {
        $rol = 'Prueba-'.substr(md5(implode(',', $permissions)), 0, 8);
        \App\Models\AdminRole::firstOrCreate(['name' => $rol], ['is_system' => false]);

        foreach ($permissions as $p) {
            \App\Models\RolePermission::updateOrCreate(
                ['role' => $rol, 'permission' => $p],
                ['granted' => true],
            );
        }
        app(\App\Support\Access\RolePermissionPolicy::class)->flush();

        return $this->adminAs($rol, $headers);
    }

    /** El token compartido de automatizaciones: sin persona detrás, solo lee. */
    protected function sharedTokenHeaders(array $headers = []): array
    {
        $token = config('admin.api_token') ?: 'test-admin-secret';
        config(['admin.api_token' => $token]);

        return array_merge(['Authorization' => 'Bearer '.$token], $headers);
    }

    private ?Admin $testSuperAdmin = null;

    protected function adminGetJson(string $uri, array $headers = []): TestResponse
    {
        return $this->getJson($uri, $this->adminHeaders($headers));
    }

    protected function adminPostJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->postJson($uri, $data, $this->adminHeaders($headers));
    }

    protected function adminPutJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->putJson($uri, $data, $this->adminHeaders($headers));
    }

    protected function adminPatchJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->patchJson($uri, $data, $this->adminHeaders($headers));
    }

    protected function adminDeleteJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->deleteJson($uri, $data, $this->adminHeaders($headers));
    }
}
