<?php

namespace Tests\Feature\Hardening;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\AdminSession;
use App\Models\AuditLog;
use App\Services\Admin\AdminSessionService;
use App\Services\Admin\StreamTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * H-2 de la revisión de seguridad del Hito 7 de ULTRON STUDIO: restablecer la
 * contraseña de una cuenta desde el CRM (`POST /api/admin/users/{admin}/reset-password`)
 * cierra sus DEMÁS sesiones, las de «recuérdame» (30 días) incluidas, y con
 * ellas sus vales de SSE. Antes solo lo hacía `admin:password` (consola): con
 * una credencial robada, el token del atacante seguía valiendo.
 *
 * Un token de una sesión cerrada responde 403 `admin_token_invalid` (401 es
 * solo «sin token»): el CRM cierra la sesión ante ese código.
 */
class AdminPasswordResetRevokesSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (Admin::ROLES as $rol) {
            AdminRole::firstOrCreate(['name' => $rol], ['is_system' => true]);
        }
    }

    private function cuenta(string $rol, string $nombre): Admin
    {
        return Admin::create([
            'name' => $nombre, 'email' => Str::lower($nombre.'-'.Str::random(6)).'@ironbody.test',
            'password' => 'la-de-antes-123', 'role' => $rol, 'status' => 'active',
        ]);
    }

    /** @return array{0: string, 1: AdminSession} el token en claro y su sesión */
    private function sesion(Admin $cuenta, bool $recuerdame = false): array
    {
        $emitida = app(AdminSessionService::class)->issueSession($cuenta, [], $recuerdame);

        return [$emitida['token'], $emitida['session']];
    }

    private function yo(string $token): int
    {
        return $this->getJson('/api/admin/auth/me', ['Authorization' => 'Bearer '.$token])->getStatusCode();
    }

    private function restablecer(Admin $destino, string $token, array $cuerpo = []): void
    {
        $this->postJson("/api/admin/users/{$destino->id}/reset-password", $cuerpo, ['Authorization' => 'Bearer '.$token])->assertOk();
    }

    private function sesionesRevocadas(AuditLog $traza): mixed
    {
        return $traza->metadata['sessions_revoked'] ?? null;
    }

    public function test_restablecer_cierra_las_demas_sesiones_de_la_cuenta_recuerdame_y_vales_incluidos(): void
    {
        $ana = $this->cuenta(Admin::ROLE_RECEPCION, 'Ana');
        [$tokenNormal] = $this->sesion($ana);
        [$tokenRecuerdame, $sesionRecuerdame] = $this->sesion($ana, recuerdame: true);
        $vale = app(StreamTicketService::class)->issue($sesionRecuerdame)['ticket'];
        // Una sesión ya cerrada y una caducada: no se tocan.
        [, $cerrada] = $this->sesion($ana);
        app(AdminSessionService::class)->revoke($cerrada, 'logout');
        [, $caducada] = $this->sesion($ana);
        $caducada->forceFill(['expires_at' => now()->subMinute()])->save();
        [$tokenRoot] = $this->sesion($this->cuenta(Admin::ROLE_SUPER_ADMIN, 'Root'));
        $this->assertSame([200, 200], [$this->yo($tokenNormal), $this->yo($tokenRecuerdame)]);

        $this->restablecer($ana, $tokenRoot);

        foreach ([$tokenNormal, $tokenRecuerdame] as $token) {
            $this->getJson('/api/admin/auth/me', ['Authorization' => 'Bearer '.$token])
                ->assertStatus(403)->assertJsonPath('code', 'admin_token_invalid');
        }
        // Vigente, sin usar y de su finalidad: solo puede fallar por la sesión revocada.
        $this->assertNull(app(StreamTicketService::class)->redeem($vale, StreamTicketService::SCOPE_STREAM), 'el vale de SSE sobrevivió a su sesión');
        $this->assertSame(['password_reset', 'password_reset'], AdminSession::query()->where('admin_id', $ana->id)->where('revoked_reason', 'password_reset')->pluck('revoked_reason')->all());
        $this->assertSame('logout', $cerrada->fresh()->revoked_reason);
        $this->assertNull($caducada->fresh()->revoked_at);
        // Quien restablece sigue dentro, y la cuenta entra con la contraseña nueva.
        $this->assertSame(200, $this->yo($tokenRoot));
        $this->assertSame(2, $this->sesionesRevocadas(AuditLog::query()->where('entity_id', (string) $ana->id)->latest('id')->firstOrFail()));
    }

    public function test_con_la_contrasena_nueva_se_vuelve_a_entrar(): void
    {
        $ana = $this->cuenta(Admin::ROLE_RECEPCION, 'Ana');
        [$tokenRoot] = $this->sesion($this->cuenta(Admin::ROLE_SUPER_ADMIN, 'Root'));
        [$viejo] = $this->sesion($ana, recuerdame: true);

        $this->restablecer($ana, $tokenRoot, ['password' => 'Clave nueva de Ana 2026', 'password_confirmation' => 'Clave nueva de Ana 2026']);

        $this->assertSame(403, $this->yo($viejo));
        $nuevo = $this->postJson('/api/admin/auth/login', ['email' => $ana->email, 'password' => 'Clave nueva de Ana 2026'])->assertOk()->json('token');
        $this->assertSame(200, $this->yo($nuevo));
    }

    /** Quien restablece SU contraseña conserva la sesión con la que lo hace; sus otros dispositivos salen. */
    public function test_quien_restablece_su_propia_contrasena_conserva_la_sesion_con_la_que_lo_hace(): void
    {
        $root = $this->cuenta(Admin::ROLE_SUPER_ADMIN, 'Root');
        [$aqui] = $this->sesion($root);
        [$otroDispositivo] = $this->sesion($root, recuerdame: true);

        $this->restablecer($root, $aqui, ['password' => 'Clave nueva de Root 2026', 'password_confirmation' => 'Clave nueva de Root 2026']);

        $this->assertSame([200, 403], [$this->yo($aqui), $this->yo($otroDispositivo)]);
        $this->assertSame(1, $this->sesionesRevocadas(AuditLog::query()->where('entity_id', (string) $root->id)->latest('id')->firstOrFail()));
    }

    /** En la transacción de la contraseña: si el restablecimiento falla, no se cierra nada. */
    public function test_si_el_restablecimiento_no_se_guarda_no_se_cierra_ninguna_sesion(): void
    {
        $ana = $this->cuenta(Admin::ROLE_RECEPCION, 'Ana');
        [$tokenAna] = $this->sesion($ana, recuerdame: true);
        [$tokenRoot] = $this->sesion($this->cuenta(Admin::ROLE_SUPER_ADMIN, 'Root'));
        AuditLog::creating(function (): never {
            throw new RuntimeException('fallo simulado de la auditoría');
        });

        $this->postJson("/api/admin/users/{$ana->id}/reset-password", [], ['Authorization' => 'Bearer '.$tokenRoot])->assertStatus(500);

        $this->assertSame(200, $this->yo($tokenAna));
        $this->assertSame(0, AdminSession::query()->whereNotNull('revoked_at')->count());
    }
}
