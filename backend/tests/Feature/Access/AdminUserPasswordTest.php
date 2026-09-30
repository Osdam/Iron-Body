<?php

namespace Tests\Feature\Access;

use App\Http\Controllers\Api\Admin\AdminUserRealtimeController;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * La contraseña de una cuenta del CRM, fijada por quien administra: temporal
 * (la genera el sistema) o personalizada (la escribe quien administra, con
 * confirmación).
 *
 * Lo que se prueba es lo que la persona no ve: que se guarde hasheada, que la
 * personalizada no vuelva en la respuesta, que nadie sin permiso —ni con
 * `users.manage` sobre un Super Admin— la cambie, y que el evento que confirma
 * el cambio exista SI Y SOLO SI el cambio se guardó.
 */
class AdminUserPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const ANTERIOR = 'la-de-antes-123';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (Admin::ROLES as $rol) {
            AdminRole::firstOrCreate(['name' => $rol], ['is_system' => true]);
        }
    }

    private function cuenta(string $rol = Admin::ROLE_RECEPCION, string $nombre = 'Ana'): Admin
    {
        return Admin::create([
            'name' => $nombre,
            'email' => strtolower($nombre).'-'.uniqid().'@ironbody.test',
            'password' => self::ANTERIOR,
            'role' => $rol,
            'status' => 'active',
        ]);
    }

    private function superAdmin(): array
    {
        return $this->actingAsAdmin($this->cuenta(Admin::ROLE_SUPER_ADMIN, 'Root'));
    }

    private function restablecer(Admin $destino, array $cuerpo, array $cabeceras)
    {
        return $this->postJson("/api/admin/users/{$destino->id}/reset-password", $cuerpo, $cabeceras);
    }

    private function entra(Admin $cuenta, string $clave): bool
    {
        return $this->postJson('/api/admin/auth/login', ['email' => $cuenta->email, 'password' => $clave])->status() === 200;
    }

    /** @return list<AuditLog> */
    private function eventosDe(Admin $cuenta): array
    {
        return AuditLog::query()
            ->where('module', 'Usuarios')->where('entity', 'usuario')->where('entity_id', (string) $cuenta->id)
            ->get()
            ->filter(fn (AuditLog $f) => AdminUserRealtimeController::eventoDe($f) !== null)
            ->values()
            ->all();
    }

    // ── Personalizada ───────────────────────────────────────────────────────

    public function test_un_administrador_autorizado_define_una_personalizada_y_sirve_para_entrar(): void
    {
        $ana = $this->cuenta();
        $operacion = '5f0e7c1a-2b3d-4e5f-8a9b-0c1d2e3f4a5b';

        $res = $this->restablecer($ana, [
            'password' => 'Nueva clave de Ana 2026',
            'password_confirmation' => 'Nueva clave de Ana 2026',
            'operation_id' => $operacion,
        ], $this->superAdmin())->assertOk();

        $res->assertJsonPath('generated', false)
            ->assertJsonPath('password', null)
            ->assertJsonPath('operation_id', $operacion)
            ->assertJsonPath('data.id', $ana->id);
        $this->assertIsInt($res->json('event_id'));

        $this->assertTrue($this->entra($ana, 'Nueva clave de Ana 2026'), 'la personalizada no sirve para entrar');
        $this->assertFalse($this->entra($ana, self::ANTERIOR), 'la anterior sigue sirviendo');
    }

    public function test_la_personalizada_queda_hasheada_con_bcrypt(): void
    {
        $ana = $this->cuenta();
        $this->restablecer($ana, ['password' => 'Otra-Clave-2026', 'password_confirmation' => 'Otra-Clave-2026'], $this->superAdmin())->assertOk();

        $guardada = (string) Admin::whereKey($ana->id)->value('password');
        $this->assertNotSame('Otra-Clave-2026', $guardada);
        $this->assertStringStartsWith('$2y$', $guardada);
        $this->assertTrue(Hash::check('Otra-Clave-2026', $guardada));
    }

    public function test_una_confirmacion_distinta_no_persiste_nada(): void
    {
        $ana = $this->cuenta();
        $antes = Admin::whereKey($ana->id)->value('password');

        $this->restablecer($ana, ['password' => 'Clave-Buena-2026', 'password_confirmation' => 'Clave-Mala-2026'], $this->superAdmin())
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'La confirmación no coincide con la contraseña.');

        $this->assertSame($antes, Admin::whereKey($ana->id)->value('password'));
        $this->assertSame([], $this->eventosDe($ana), 'hay evento de un cambio que no ocurrió');
        $this->assertTrue($this->entra($ana, self::ANTERIOR));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function clavesQueRompenLaPolitica(): array
    {
        return [
            'corta' => ['Ab12345', 'al menos 8'],
            'vacía' => ['', 'Escribe la nueva contraseña.'],
            // `required` ya da por vacía una cadena de solo espacios.
            'solo espacios' => ['          ', 'Escribe la nueva contraseña.'],
            // Antes pasaba el mínimo de 8 y se guardaba «abc».
            'espacios al borde' => ['   abc   ', 'espacios'],
            // 37 «ñ» son 37 caracteres (max:72 los dejaba pasar) y 74 bytes: bcrypt ignoraría el final.
            'más de 72 bytes' => [str_repeat('ñ', 37), '72 bytes'],
            // El cast `hashed` lo guardaría tal cual y la cuenta quedaría con una clave que nadie conoce.
            'un hash' => [password_hash('a', PASSWORD_BCRYPT, ['cost' => 4]), 'cifrado'],
            // password_hash lanza con un byte nulo: era un 500, con el principio de la clave en la traza.
            'un byte nulo en medio' => ["Secreto\0DeAna2026", 'caracteres de control'],
            'un tabulador en medio' => ["Secreto\tDeAna2026", 'caracteres de control'],
        ];
    }

    #[DataProvider('clavesQueRompenLaPolitica')]
    public function test_una_personalizada_que_rompe_la_politica_se_rechaza(string $clave, string $motivo): void
    {
        $ana = $this->cuenta();
        $antes = Admin::whereKey($ana->id)->value('password');

        $res = $this->restablecer($ana, ['password' => $clave, 'password_confirmation' => $clave], $this->superAdmin())
            ->assertStatus(422);
        $this->assertStringContainsString($motivo, (string) $res->json('errors.password.0'));

        $this->assertSame($antes, Admin::whereKey($ana->id)->value('password'));
        $this->assertSame([], $this->eventosDe($ana));
        if ($clave !== '') {
            $this->assertStringNotContainsString($clave, $res->getContent(), 'la respuesta de error devuelve la contraseña');
        }
    }

    /** @return array<string, array{0: string}> */
    public static function cuerposIlegibles(): array
    {
        return [
            // Lo que emite JSON.stringify al pegar medio emoji: json_decode lo rechaza.
            'surrogate suelto' => ['{"password":"Clave-Buena-2026\ud83d","password_confirmation":"Clave-Buena-2026\ud83d","operation_id":"5f0e7c1a-2b3d-4e5f-8a9b-0c1d2e3f4a5b"}'],
            'bytes que no son UTF-8' => ["{\"password\":\"Clave-\xff-2026\",\"password_confirmation\":\"Clave-\xff-2026\"}"],
            'JSON cortado' => ['{"password":"Clave-Buena-2026","password_confirm'],
            'JSON que no es un objeto' => ['"Clave-Buena-2026"'],
            // La lista VACÍA sigue siendo «sin campos» (así codifica PHP un cuerpo vacío).
            'una lista con la clave' => ['["Clave-Buena-2026","Clave-Buena-2026"]'],
        ];
    }

    /**
     * Antes, Laravel convertía estos cuerpos en «{}», y «{}» es pedir una
     * temporal: la cuenta acababa con una aleatoria que nadie vio y el CRM
     * anunciaba que había quedado la personalizada.
     */
    #[DataProvider('cuerposIlegibles')]
    public function test_un_cuerpo_ilegible_no_se_toma_por_una_peticion_de_temporal(string $crudo): void
    {
        $ana = $this->cuenta();
        $antes = Admin::whereKey($ana->id)->value('password');
        $servidor = $this->transformHeadersToServerVars($this->superAdmin() + [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);

        $res = $this->call('POST', "/api/admin/users/{$ana->id}/reset-password", [], [], [], $servidor, $crudo)
            ->assertStatus(400)
            ->assertJsonPath('code', 'unreadable_body');

        $this->assertNull($res->json('password'));
        $this->assertSame($antes, Admin::whereKey($ana->id)->value('password'));
        $this->assertSame([], $this->eventosDe($ana), 'hay evento de un cambio que no ocurrió');
        $this->assertTrue($this->entra($ana, self::ANTERIOR));
    }

    // ── Temporal ────────────────────────────────────────────────────────────

    public function test_la_temporal_se_sigue_generando_y_sirve_para_entrar(): void
    {
        $ana = $this->cuenta();

        $res = $this->restablecer($ana, [], $this->superAdmin())->assertOk()
            ->assertJsonPath('generated', true);
        $temporal = (string) $res->json('password');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{16}$/', $temporal);
        $this->assertIsString($res->json('operation_id'));
        $this->assertTrue($this->entra($ana, $temporal));
        $this->assertFalse($this->entra($ana, self::ANTERIOR));

        $eventos = $this->eventosDe($ana);
        $this->assertCount(1, $eventos);
        $this->assertSame('generated', AdminUserRealtimeController::eventoDe($eventos[0])['mode']);
    }

    // ── Permisos ────────────────────────────────────────────────────────────

    public function test_sin_permiso_de_administrar_cuentas_se_rechaza(): void
    {
        $ana = $this->cuenta();
        $antes = Admin::whereKey($ana->id)->value('password');
        $cuerpo = ['password' => 'Clave-Nueva-2026', 'password_confirmation' => 'Clave-Nueva-2026'];

        $recepcion = $this->actingAsAdmin($this->cuenta(Admin::ROLE_RECEPCION, 'Recepcion'));
        $soloVer = $this->adminWithPermissions(['users.view']);

        foreach ([$recepcion, $soloVer, $this->sharedTokenHeaders()] as $cabeceras) {
            $res = $this->restablecer($ana, $cuerpo, $cabeceras)->assertStatus(403);
            $this->assertStringNotContainsString('Clave-Nueva-2026', $res->getContent());
            $this->restablecer($ana, [], $cabeceras)->assertStatus(403);
        }

        $this->assertSame($antes, Admin::whereKey($ana->id)->value('password'));
        $this->assertSame([], $this->eventosDe($ana));
    }

    public function test_quien_administra_cuentas_sin_ser_super_admin_no_toca_la_de_un_super_admin(): void
    {
        $jefa = $this->cuenta(Admin::ROLE_SUPER_ADMIN, 'Jefa');
        $antes = Admin::whereKey($jefa->id)->value('password');
        $gestor = $this->adminWithPermissions(['users.view', 'users.manage']);

        foreach ([['password' => 'Tomada-2026', 'password_confirmation' => 'Tomada-2026'], []] as $cuerpo) {
            $res = $this->restablecer($jefa, $cuerpo, $gestor)
                ->assertStatus(403)
                ->assertJsonPath('code', 'requires_super_admin');
            $this->assertNull($res->json('password'));
        }

        $this->assertSame($antes, Admin::whereKey($jefa->id)->value('password'));
        $this->assertSame([], $this->eventosDe($jefa));

        // A una cuenta que no es Super Admin sí puede.
        $this->restablecer($this->cuenta(), [], $gestor)->assertOk();
        // Y un Super Admin sí puede con otro Super Admin.
        $this->restablecer($jefa, ['password' => 'Nueva-Jefa-2026', 'password_confirmation' => 'Nueva-Jefa-2026'], $this->superAdmin())->assertOk();
        $this->assertTrue($this->entra($jefa, 'Nueva-Jefa-2026'));
    }

    public function test_una_cuenta_que_no_existe_da_404(): void
    {
        $this->postJson('/api/admin/users/999999/reset-password', [], $this->superAdmin())->assertStatus(404);
        $this->postJson('/api/admin/users/999999/reset-password', ['password' => 'Clave-2026-ok', 'password_confirmation' => 'Clave-2026-ok'], $this->superAdmin())
            ->assertStatus(404);
    }

    public function test_un_operation_id_que_no_es_uuid_se_rechaza(): void
    {
        $ana = $this->cuenta();
        $this->restablecer($ana, ['operation_id' => 'no-es-un-uuid'], $this->superAdmin())->assertStatus(422);
        $this->assertTrue($this->entra($ana, self::ANTERIOR));
    }

    // ── El evento ───────────────────────────────────────────────────────────

    public function test_el_evento_confirma_la_operacion_y_no_lleva_secretos(): void
    {
        $ana = $this->cuenta();
        $operacion = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
        $res = $this->restablecer($ana, [
            'password' => 'Secreto de Ana 2026',
            'password_confirmation' => 'Secreto de Ana 2026',
            'operation_id' => $operacion,
        ], $this->superAdmin())->assertOk();

        $eventos = $this->eventosDe($ana);
        $this->assertCount(1, $eventos);
        $this->assertSame($res->json('event_id'), $eventos[0]->id);

        $evento = AdminUserRealtimeController::eventoDe($eventos[0]);
        $this->assertSame(['type', 'user_id', 'operation_id', 'mode', 'status', 'actor_admin_id', 'timestamp'], array_keys($evento));
        $this->assertSame(AdminUserRealtimeController::PASSWORD_UPDATED, $evento['type']);
        $this->assertSame($ana->id, $evento['user_id']);
        $this->assertSame($operacion, $evento['operation_id']);
        $this->assertSame('custom', $evento['mode']);
        $this->assertSame('updated', $evento['status']);

        $hash = (string) Admin::whereKey($ana->id)->value('password');
        foreach ([json_encode($evento), json_encode($eventos[0]->toArray())] as $serializado) {
            $this->assertStringNotContainsString('Secreto de Ana 2026', $serializado);
            $this->assertStringNotContainsString($hash, $serializado);
            $this->assertStringNotContainsString(substr($hash, 7), $serializado);
        }
    }

    public function test_si_la_contrasena_no_se_guarda_no_hay_evento(): void
    {
        $ana = $this->cuenta();
        $antes = Admin::whereKey($ana->id)->value('password');
        // Las cabeceras antes: abrir sesión también actualiza la cuenta del actor.
        $root = $this->superAdmin();
        Admin::updating(function (): never {
            throw new RuntimeException('fallo simulado de la base de datos');
        });

        $this->restablecer($ana, ['password' => 'No-Llega-2026', 'password_confirmation' => 'No-Llega-2026'], $root)
            ->assertStatus(500);

        $this->assertSame($antes, Admin::whereKey($ana->id)->value('password'));
        $this->assertSame([], $this->eventosDe($ana), 'el canal anunciaría un cambio que no ocurrió');
    }

    public function test_si_la_traza_no_se_escribe_la_contrasena_no_cambia(): void
    {
        $ana = $this->cuenta();
        $antes = Admin::whereKey($ana->id)->value('password');
        $root = $this->superAdmin();
        AuditLog::creating(function (): never {
            throw new RuntimeException('fallo simulado de la auditoría');
        });

        $this->restablecer($ana, [], $root)->assertStatus(500);

        $this->assertSame($antes, Admin::whereKey($ana->id)->value('password'), 'la contraseña cambió sin traza ni evento');
        $this->assertTrue($this->entra($ana, self::ANTERIOR));
    }

    public function test_si_el_motor_falla_al_guardar_ni_el_hash_ni_la_clave_llegan_al_log(): void
    {
        $registros = $this->escucharLogs();
        $ana = $this->cuenta();
        $antes = Admin::whereKey($ana->id)->value('password');
        $root = $this->superAdmin();
        // Falla el propio motor, no un listener: lo que sale es una
        // QueryException, cuyo mensaje lleva los valores enlazados del UPDATE.
        DB::statement("CREATE TRIGGER clave_bloqueada BEFORE UPDATE OF password ON admins BEGIN SELECT RAISE(ABORT, 'motor caido'); END");

        $res = $this->restablecer($ana, ['password' => 'Clave-De-Ana-2026', 'password_confirmation' => 'Clave-De-Ana-2026'], $root)
            ->assertStatus(500)
            ->assertJsonPath('code', 'password_not_saved');

        $this->assertStringNotContainsString('$2y$', $res->getContent());
        $this->assertNotEmpty($registros->getArrayCopy(), 'el fallo no quedó registrado');
        foreach ($registros as $linea) {
            $this->assertStringNotContainsString('$2y$', $linea, 'el hash nuevo llegó al log');
            $this->assertStringNotContainsString('Clave-De-Ana-2026', $linea);
        }
        $this->assertStringContainsString('No se pudo guardar la contraseña de la cuenta '.$ana->id, implode("\n", $registros->getArrayCopy()));
        $this->assertSame($antes, Admin::whereKey($ana->id)->value('password'));
        $this->assertSame([], $this->eventosDe($ana));
    }

    /**
     * Con los argumentos en las trazas (zend.exception_ignore_args=Off, como en
     * local y en un PHP sin php.ini), un fallo que NO es de SQL —un listener,
     * un COMMIT caído— registraba la traza con la contraseña como argumento:
     * entera si mide hasta 15 caracteres, que es lo que PHP no recorta.
     */
    public function test_si_la_transaccion_falla_por_otra_causa_la_traza_no_lleva_la_contrasena(): void
    {
        $antes = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            $registros = $this->escucharLogs();
            $ana = $this->cuenta();
            $root = $this->superAdmin();
            Admin::updating(function (): never {
                throw new RuntimeException('fallo simulado fuera de SQL');
            });

            $this->restablecer($ana, ['password' => 'Corta-De-Ana1', 'password_confirmation' => 'Corta-De-Ana1'], $root)
                ->assertStatus(500);

            $this->assertNotEmpty($registros->getArrayCopy(), 'el fallo no quedó registrado');
            foreach ($registros as $linea) {
                $this->assertStringNotContainsString('Corta-De-Ana', $linea, 'la contraseña llegó al log en una traza');
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $antes);
        }
    }

    /**
     * Cada registro del log tal como acabaría en el fichero: las excepciones
     * del contexto, con su traza y sus previas. json_encode las dejaría en «{}».
     *
     * @return \ArrayObject<int, string>
     */
    private function escucharLogs(): \ArrayObject
    {
        $registros = new \ArrayObject;
        Event::listen(MessageLogged::class, function (MessageLogged $e) use ($registros): void {
            $contexto = array_map(
                fn (mixed $v): string => $v instanceof \Throwable ? (string) $v : (string) json_encode($v),
                $e->context,
            );
            $registros[] = $e->message.' '.implode(' ', $contexto);
        });

        return $registros;
    }

    public function test_la_contrasena_no_llega_a_los_logs(): void
    {
        $registros = $this->escucharLogs();

        $ana = $this->cuenta();
        $root = $this->superAdmin();
        $this->restablecer($ana, ['password' => 'Nunca-En-Logs-2026', 'password_confirmation' => 'Nunca-En-Logs-2026'], $root)->assertOk();
        $this->restablecer($ana, ['password' => 'Nunca-En-Logs-2026', 'password_confirmation' => 'Otra-Cosa-2026'], $root)->assertStatus(422);
        $this->restablecer($ana, ['password' => 'Nunca-En-Logs-2026', 'password_confirmation' => 'Nunca-En-Logs-2026'], $this->adminWithPermissions(['users.view']))->assertStatus(403);

        foreach ($registros as $linea) {
            $this->assertStringNotContainsString('Nunca-En-Logs-2026', $linea);
        }
    }

    // ── El canal ────────────────────────────────────────────────────────────

    public function test_el_canal_de_usuarios_exige_poder_administrar_cuentas(): void
    {
        $this->getJson('/api/admin/users/stream', $this->adminWithPermissions(['users.view']))->assertStatus(403);
        $this->getJson('/api/admin/users/stream', $this->actingAsAdmin($this->cuenta(Admin::ROLE_RECEPCION, 'Rec')))->assertStatus(403);
        $this->getJson('/api/admin/users/stream', $this->sharedTokenHeaders())->assertStatus(403);

        $res = $this->get('/api/admin/users/stream', $this->superAdmin())->assertOk();
        $this->assertStringStartsWith('text/event-stream', (string) $res->headers->get('Content-Type'));
    }

    public function test_solo_las_filas_de_contrasena_son_eventos_del_canal(): void
    {
        $otra = new AuditLog(['module' => 'Usuarios', 'entity' => 'usuario', 'entity_id' => '7', 'metadata' => ['event' => 'otra.cosa']]);
        $sinMeta = new AuditLog(['module' => 'Usuarios', 'entity' => 'usuario', 'entity_id' => '7']);
        $this->assertNull(AdminUserRealtimeController::eventoDe($otra));
        $this->assertNull(AdminUserRealtimeController::eventoDe($sinMeta));
    }
}
