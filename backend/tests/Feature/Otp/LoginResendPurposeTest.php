<?php

namespace Tests\Feature\Otp;

use App\Models\Member;
use App\Models\MemberAuthChallenge;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `members/login/resend` es PÚBLICO y busca el reto solo por su uuid.
 *
 * Reenviaba cualquier reto vivo: el de borrar la cuenta, revocar equipos o
 * cambiar el número (que va al número NUEVO). Con el uuid de uno de esos
 * retos se volvía a mandar un SMS —con coste— fuera de su flujo. La APK solo
 * lo usa en la pantalla del login (otp_verification_screen.dart), con el reto
 * que devuelve `members/login`.
 */
class LoginResendPurposeTest extends TestCase
{
    use RefreshDatabase;

    private function socio(): Member
    {
        $user = User::create([
            'name' => 'Ana Prueba', 'email' => 'ana@example.com', 'password' => 'secret',
            'document' => '1010101011', 'phone' => '3001234568', 'status' => 'active',
        ]);

        return Member::create([
            'user_id' => $user->id, 'full_name' => 'Ana Prueba', 'email' => 'ana@example.com',
            'document_number' => '1010101011', 'phone' => '3001234568', 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    public static function propositosAjenosAlLogin(): array
    {
        return [
            'borrar la cuenta' => [MemberAuthChallenge::PURPOSE_ACCOUNT_DELETE],
            'revocar un equipo' => [MemberAuthChallenge::PURPOSE_DEVICE_REVOKE],
            'cambiar el número' => [MemberAuthChallenge::PURPOSE_PHONE_CHANGE],
        ];
    }

    #[DataProvider('propositosAjenosAlLogin')]
    public function test_el_reenvio_del_login_no_reenvia_retos_de_otros_flujos(string $proposito): void
    {
        $reto = app(OtpService::class)->startChallenge($this->socio(), [], $proposito)['challenge'];
        $this->travel(61)->seconds();

        $this->postJson('/api/members/login/resend', ['challenge_id' => $reto->uuid])
            ->assertStatus(410)
            ->assertJsonPath('ok', false);

        $reto->refresh();
        $this->assertSame(0, (int) $reto->resend_count, 'No se reenvió.');
    }

    public function test_el_reenvio_del_reto_de_login_sigue_funcionando(): void
    {
        $this->socio();
        $login = $this->postJson('/api/members/login', ['document_number' => '1010101011', 'device_id' => 'equipo-1'])
            ->assertOk();
        $this->travel(61)->seconds();

        $this->postJson('/api/members/login/resend', ['challenge_id' => $login->json('data.challenge_id')])
            ->assertOk()
            ->assertJsonPath('data.challenge_id', $login->json('data.challenge_id'));

        $this->assertSame(1, (int) MemberAuthChallenge::where('uuid', $login->json('data.challenge_id'))->value('resend_count'));
    }
}
