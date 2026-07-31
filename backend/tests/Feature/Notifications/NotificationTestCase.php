<?php

namespace Tests\Feature\Notifications;

use App\Models\Member;
use App\Models\MemberDeviceToken;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Base de la suite de notificaciones.
 *
 * `preventStrayRequests` hace fallar el test si algo intentara una llamada real
 * a FCM: aquí se prueba la LÓGICA de decisión, y la red se simula siempre.
 */
abstract class NotificationTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // Reloj congelado a mediodía en Bogotá.
        //
        // Sin esto la suite pasaría o fallaría según la hora a la que alguien
        // la ejecute: la ventana 07:00–22:00 es real y cierra de noche. Es el
        // mismo defecto que arrastran las pruebas de membresía de este
        // repositorio, y no tiene sentido repetirlo aquí. Quien quiera probar
        // una hora concreta pasa su propio `$now`.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-30 12:00:00', 'America/Bogota'));
        Carbon::setTestNow(CarbonImmutable::getTestNow());

        config([
            'fcm.enabled' => true,
            'fcm.project_id' => 'iron-body-test',
            'fcm.credentials' => $this->fakeServiceAccount(),
            'notifications.wellness.enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        if (self::$serviceAccountPath !== null && is_file(self::$serviceAccountPath)) {
            @unlink(self::$serviceAccountPath);
            self::$serviceAccountPath = null;
        }

        parent::tearDown();
    }

    private static ?string $serviceAccountPath = null;

    /**
     * Cuenta de servicio desechable con una clave RSA generada al vuelo.
     *
     * El cliente FCM firma un JWT de verdad antes de pedir el token, así que sin
     * una clave válida `isConfigured()` diría que no y todos los tests de envío
     * medirían el camino equivocado. La clave nace y muere con el test; no hay
     * ningún secreto en el repositorio.
     */
    private function fakeServiceAccount(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, $pem);

        $path = tempnam(sys_get_temp_dir(), 'ib-fcm-').'.json';
        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'project_id' => 'iron-body-test',
            'client_email' => 'test@iron-body-test.iam.gserviceaccount.com',
            'private_key' => $pem,
        ]));

        self::$serviceAccountPath = $path;

        return $path;
    }

    /** FCM responde que sí a todo (token OAuth + envío). */
    protected function fakeFcmSuccess(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/iron-body-test/messages/1']),
        ]);
    }

    /** FCM responde que el token ya no existe (UNREGISTERED). */
    protected function fakeFcmUnregistered(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'UNREGISTERED']], 404),
        ]);
    }

    protected function makeMember(string $name = 'Socio Prueba', array $overrides = []): Member
    {
        $suffix = uniqid();

        $user = User::create([
            'name' => $name,
            'email' => "u{$suffix}@example.test",
            'password' => 'secret',
            'document' => substr((string) crc32($suffix), 0, 10),
            'phone' => '3001234567',
            'status' => 'active',
            'plan' => 'PLAN TOTAL',
            'membership_end_date' => now()->addDays(30)->toDateString(),
        ]);

        return Member::create(array_merge([
            'user_id' => $user->id,
            'full_name' => $name,
            'email' => "u{$suffix}@example.test",
            'document_number' => substr((string) crc32($suffix), 0, 10),
            'phone' => '3001234567',
            'access_hash' => 'tok-'.$suffix,
            'status' => Member::STATUS_ACTIVE,
            // Mayor de edad por defecto: los tests de suplementos necesitan una
            // fecha de nacimiento, y quien pruebe el corte la pone explícita.
            'birth_date' => now()->subYears(30)->toDateString(),
        ], $overrides));
    }

    protected function giveDevice(Member $member, bool $active = true): MemberDeviceToken
    {
        return MemberDeviceToken::create([
            'member_id' => $member->id,
            'token' => 'fcm-'.uniqid(),
            'platform' => 'android',
            'is_active' => $active,
            'notification_permission' => 'authorized',
        ]);
    }

    protected function asMember(Member $member): array
    {
        return ['Authorization' => 'Bearer '.$member->access_hash];
    }
}
