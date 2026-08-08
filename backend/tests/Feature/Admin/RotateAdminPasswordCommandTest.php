<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AdminSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `admin:password` es lo que se usa para rotar la credencial administrativa de
 * producción, así que lo que se prueba aquí no es que "funcione": es que no
 * pueda dejar la cuenta en un estado que PAREZCA rotado sin estarlo.
 */
class RotateAdminPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'ContrasenaVieja#2026';

    private const NEW = 'Rotada-Nueva#2026x';

    private function admin(string $email = 'admin@ironbody.com'): Admin
    {
        return Admin::create([
            'name' => 'Admin',
            'email' => $email,
            'password' => self::OLD,
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
    }

    public function test_rotates_hash_and_old_password_stops_validating(): void
    {
        $admin = $this->admin();
        $before = $admin->password;

        $this->artisan('admin:password', ['--email' => $admin->email])
            ->expectsQuestion('Contraseña NUEVA (no se muestra)', self::NEW)
            ->expectsQuestion('Repítela para confirmar', self::NEW)
            ->assertExitCode(0);

        $admin->refresh();
        $this->assertNotSame($before, $admin->password, 'el hash almacenado no cambió');
        $this->assertTrue(Hash::check(self::NEW, $admin->password), 'la contraseña nueva no valida');
        $this->assertFalse(Hash::check(self::OLD, $admin->password), 'la contraseña anterior sigue validando');
    }

    public function test_never_prints_the_password(): void
    {
        $admin = $this->admin();

        $this->artisan('admin:password', ['--email' => $admin->email])
            ->expectsQuestion('Contraseña NUEVA (no se muestra)', self::NEW)
            ->expectsQuestion('Repítela para confirmar', self::NEW)
            ->doesntExpectOutputToContain(self::NEW)
            ->assertExitCode(0);
    }

    public function test_revokes_open_sessions_so_a_stolen_bearer_dies_with_the_password(): void
    {
        $admin = $this->admin();
        $live = AdminSession::create([
            'admin_id' => $admin->id,
            'token_hash' => AdminSession::hashToken(Str::random(40)),
            'expires_at' => now()->addDay(),
        ]);

        $this->artisan('admin:password', ['--email' => $admin->email])
            ->expectsQuestion('Contraseña NUEVA (no se muestra)', self::NEW)
            ->expectsQuestion('Repítela para confirmar', self::NEW)
            ->assertExitCode(0);

        $live->refresh();
        $this->assertNotNull($live->revoked_at);
        $this->assertSame('password_rotated', $live->revoked_reason);
        $this->assertFalse($live->isActive());
    }

    public function test_keep_sessions_flag_leaves_them_alive(): void
    {
        $admin = $this->admin();
        $live = AdminSession::create([
            'admin_id' => $admin->id,
            'token_hash' => AdminSession::hashToken(Str::random(40)),
            'expires_at' => now()->addDay(),
        ]);

        $this->artisan('admin:password', ['--email' => $admin->email, '--keep-sessions' => true])
            ->expectsQuestion('Contraseña NUEVA (no se muestra)', self::NEW)
            ->expectsQuestion('Repítela para confirmar', self::NEW)
            ->assertExitCode(0);

        $live->refresh();
        $this->assertNull($live->revoked_at);
    }

    public function test_mismatched_confirmation_changes_nothing(): void
    {
        $admin = $this->admin();
        $before = $admin->password;

        $this->artisan('admin:password', ['--email' => $admin->email])
            ->expectsQuestion('Contraseña NUEVA (no se muestra)', self::NEW)
            ->expectsQuestion('Repítela para confirmar', self::NEW.'-distinta')
            ->assertExitCode(1);

        $admin->refresh();
        $this->assertSame($before, $admin->password);
        $this->assertTrue(Hash::check(self::OLD, $admin->password));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function weakPasswords(): array
    {
        return [
            'demasiado corta' => ['Ab1#short'],
            'sin variedad' => ['contrasenalargasinvariedad'],
            'contiene el usuario del correo' => ['Admin-Iron#2026-Body'],
        ];
    }

    #[DataProvider('weakPasswords')]
    public function test_rejects_weak_passwords_without_touching_the_account(string $weak): void
    {
        $admin = $this->admin();
        $before = $admin->password;

        $this->artisan('admin:password', ['--email' => $admin->email])
            ->expectsQuestion('Contraseña NUEVA (no se muestra)', $weak)
            ->expectsQuestion('Repítela para confirmar', $weak)
            ->assertExitCode(1);

        $admin->refresh();
        $this->assertSame($before, $admin->password);
    }

    public function test_rejects_reusing_the_current_password(): void
    {
        $admin = $this->admin();

        $this->artisan('admin:password', ['--email' => $admin->email])
            ->expectsQuestion('Contraseña NUEVA (no se muestra)', self::OLD)
            ->expectsQuestion('Repítela para confirmar', self::OLD)
            ->assertExitCode(1);
    }

    public function test_unknown_email_fails_without_creating_anything(): void
    {
        $this->artisan('admin:password', ['--email' => 'nadie@ironbody.com'])
            ->assertExitCode(1);

        $this->assertSame(0, Admin::count());
    }
}
