<?php

namespace Tests\Feature\Caja;

use App\Http\Controllers\Api\Admin\EarningsController;
use App\Models\Admin;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quién puede ABRIR el canal financiero en tiempo real.
 *
 * EL FALLO QUE ESTO CIERRA. El canal vivía bajo el dominio `earnings`, así que
 * exigía `earnings.view`. RECEPCIÓN no lo tiene —es el perfil del mostrador, no
 * el que mira la facturación—, de modo que el navegador recibía 403 al abrir el
 * stream justo en la pantalla donde más falta hace ver la caja moverse.
 *
 * Y ahí está lo peor: `EventSource` no cuenta eso como un error del que la
 * pantalla se entere. Dispara `onerror`, reintenta con espera creciente y lo
 * vuelve a intentar para siempre. En producción se contaron 902 rechazos
 * seguidos de una misma recepcionista mientras miraba una caja que nunca se
 * actualizaba. Todo lo demás funcionaba: el dinero se guardaba bien, el
 * servidor emitía bien, y la pantalla mentía en silencio.
 *
 * Ninguna prueba lo cubría porque todas abrían el canal como Super Admin.
 */
class FinancialStreamAccessTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/admin/earnings/stream';

    private function admin(string $rol): Admin
    {
        return Admin::create([
            'name' => $rol.' de pruebas',
            'email' => 'stream-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $rol,
            'status' => 'active',
        ]);
    }

    /**
     * ¿Deja pasar la puerta? El stream se corta solo tras unos segundos, así
     * que lo que se comprueba es que NO responda 403, no lo que emite después.
     */
    private function puedeAbrir(Admin $admin): bool
    {
        $r = $this->get(self::RUTA, $this->actingAsAdmin($admin));

        return $r->getStatusCode() !== 403;
    }

    public function test_recepcion_puede_escuchar_el_canal(): void
    {
        // Es quien está en el mostrador cobrando: si alguien no puede ver la
        // caja moverse en vivo, es precisamente quien no debe.
        $this->assertTrue($this->puedeAbrir($this->admin(Admin::ROLE_RECEPCION)));
    }

    public function test_administrativo_n_o_escucha_el_canal_y_esta_bien(): void
    {
        // Su alcance es la moderación de comunidad: no ve Caja, ni Pagos, ni
        // cuentas por cobrar. Sin pantalla financiera abierta no hay nada que
        // refrescar, y el canal sigue diciendo «aquí se mueve dinero».
        $this->assertFalse($this->puedeAbrir($this->admin(Admin::ROLE_ADMINISTRATIVO)));
    }

    public function test_administrador_y_super_admin_siguen_pudiendo(): void
    {
        $this->assertTrue($this->puedeAbrir($this->admin(Admin::ROLE_ADMINISTRADOR)));
        $this->assertTrue($this->puedeAbrir($this->admin(Admin::ROLE_SUPER_ADMIN)));
    }

    public function test_basta_con_ver_un_a_pantalla_de_dinero(): void
    {
        // Cada permiso, por separado, abre el canal: son las pantallas que lo
        // escuchan. Quien ve cuentas por cobrar no tiene por qué ver ganancias.
        foreach ([
            'receivables.view',
            'payments.view',
            'cash.gym.view',
            'cash.products.view',
            'earnings.view',
        ] as $permiso) {
            $cabeceras = $this->adminWithPermissions([$permiso]);
            $r = $this->get(self::RUTA, $cabeceras);

            $this->assertNotSame(403, $r->getStatusCode(),
                "Con «{$permiso}» se ve una pantalla financiera: tiene que poder escuchar el canal.");
        }
    }

    public function test_quien_no_ve_ninguna_pantalla_de_dinero_no_entra(): void
    {
        // El canal deja de ser secreto, pero no es público: sigue diciendo
        // «aquí se mueve dinero», y eso no se le cuenta a quien no lo mira.
        $cabeceras = $this->adminWithPermissions(['members.view']);

        $this->get(self::RUTA, $cabeceras)->assertForbidden();
    }

    public function test_sin_sesion_no_se_entra(): void
    {
        $this->get(self::RUTA)->assertStatus(401);
    }

    public function test_la_firma_no_lleva_cifras_de_dinero(): void
    {
        // Se abrió la puerta a más perfiles, así que el contenido tiene que
        // dejar de ser legible: una huella opaca cumple la misma función.
        $firma = EarningsController::financialSignature();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $firma,
            'La firma tiene que ser una huella, no el estado en claro.');
        $this->assertStringNotContainsString('.00', $firma);
        $this->assertStringNotContainsString('|', $firma);
    }

    public function test_la_huella_sigue_distinguiendo_estados(): void
    {
        // Hashear no puede costar sensibilidad: si dos estados distintos dieran
        // la misma huella, la pantalla no se enteraría de nada.
        $antes = EarningsController::financialSignature();

        $this->admin(Admin::ROLE_RECEPCION); // no es dinero: no debe mover nada
        $this->assertSame($antes, EarningsController::financialSignature());

        Payment::create([
            'user_id' => User::create([
                'name' => 'Socio', 'email' => 'socio-'.uniqid().'@ironbody.test',
                'password' => 'secret-password',
            ])->id,
            'amount' => 50000, 'method' => 'cash', 'status' => 'paid', 'paid_at' => now(),
        ]);

        $this->assertNotSame($antes, EarningsController::financialSignature());
    }
}
