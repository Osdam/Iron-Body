<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El cierre automático de jornada, que llevaba dos días y medio bloqueado.
 *
 * El lector facial del gimnasio cierra al final del día las jornadas de quienes
 * entraron y nunca marcaron salida. Las enviaba con `source = 'auto-close'`, un
 * valor que no existía ni en la validación ni en el CHECK de la tabla, así que
 * el servidor respondía 422 y el cliente reintentaba cada quince segundos. Once
 * mil reintentos del MISMO registro, y su cola bloqueada por la cabecera: desde
 * el 01/sep 02:41 no se registró ninguna asistencia, tampoco las normales.
 *
 * El payload de estas pruebas es literalmente el capturado en producción.
 */
class AutoCloseAttendanceTest extends TestCase
{
    use RefreshDatabase;

    /** El cuerpo exacto que el lector reintentaba, tal como quedó en el log. */
    private function cierreDelDia(User $user): array
    {
        return [
            'user_id' => $user->id,
            'action' => 'exit',
            'source' => 'auto-close',
            'confidence' => null,
            'note' => 'Cierre automático del día',
        ];
    }

    /** Deja al socio dentro: una entrada sin salida, como fredy pajoy. */
    private function dentro(): User
    {
        $user = User::factory()->create();
        Attendance::query()->create([
            'user_id' => $user->id,
            'action' => 'entry',
            'source' => 'facial',
            'captured_at' => now()->subHours(6),
        ]);

        return $user;
    }

    // ── El fallo observado ──────────────────────────────────────────────────

    public function test_el_cierre_automatico_se_registra(): void
    {
        $user = $this->dentro();

        $this->postJson('/api/attendances', $this->cierreDelDia($user), $this->adminHeaders())
            ->assertStatus(201);

        $this->assertDatabaseHas('attendances', [
            'user_id' => $user->id,
            'action' => 'exit',
            'source' => 'auto-close',
        ]);
    }

    public function test_la_jornada_queda_cerrada(): void
    {
        $user = $this->dentro();

        $this->postJson('/api/attendances', $this->cierreDelDia($user), $this->adminHeaders())
            ->assertStatus(201);

        $ultima = Attendance::query()->where('user_id', $user->id)->orderByDesc('captured_at')->first();
        $this->assertSame('exit', $ultima->action);
    }

    public function test_queda_distinguible_de_una_salida_presenciada(): void
    {
        // El motivo de no reutilizar `manual`: quien audite el historial tiene
        // que poder separar lo que alguien vio de lo que dedujo una máquina.
        $user = $this->dentro();

        $this->postJson('/api/attendances', $this->cierreDelDia($user), $this->adminHeaders())
            ->assertStatus(201);

        $this->assertSame('auto-close', Attendance::query()->where('action', 'exit')->first()->source);
    }

    // ── Y sin inventar salidas ──────────────────────────────────────────────

    public function test_reintentar_el_mismo_cierre_no_duplica(): void
    {
        // El cliente reintenta hasta recibir un OK. Antes de este arreglo eso
        // eran once mil 422; después no puede convertirse en once mil salidas.
        $user = $this->dentro();
        $cuerpo = $this->cierreDelDia($user);

        $this->postJson('/api/attendances', $cuerpo, $this->adminHeaders())->assertStatus(201);
        $this->postJson('/api/attendances', $cuerpo, $this->adminHeaders())->assertOk();
        $this->postJson('/api/attendances', $cuerpo, $this->adminHeaders())->assertOk();

        $this->assertSame(1, Attendance::query()->where('action', 'exit')->count());
    }

    public function test_el_reintento_responde_ok_para_desatascar_la_cola(): void
    {
        // Si se respondiera con error, el cliente seguiría reintentando y la
        // cola quedaría bloqueada igual que estaba. El desbloqueo es el fin.
        $user = $this->dentro();

        $this->postJson('/api/attendances', $this->cierreDelDia($user), $this->adminHeaders())->assertStatus(201);

        $this->postJson('/api/attendances', $this->cierreDelDia($user), $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deduplicated', true);
    }

    public function test_no_cierra_la_jornada_de_quien_no_esta_dentro(): void
    {
        $user = User::factory()->create(); // sin ninguna asistencia

        $this->postJson('/api/attendances', $this->cierreDelDia($user), $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('deduplicated', true);

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_un_cierre_no_puede_registrarse_como_entrada(): void
    {
        // Un cliente que mandara `action: entry` con `source: auto-close` haría
        // entrar a alguien que se estaba yendo. Cerrar la jornada es salir.
        $user = $this->dentro();

        $this->postJson('/api/attendances', [
            'user_id' => $user->id,
            'action' => 'entry',
            'source' => 'auto-close',
        ], $this->adminHeaders())->assertStatus(201);

        $this->assertSame('exit', Attendance::query()->where('source', 'auto-close')->first()->action);
    }

    // ── Sin relajar nada más ────────────────────────────────────────────────

    public function test_los_origenes_siguen_siendo_una_lista_cerrada(): void
    {
        $user = $this->dentro();

        foreach (['auto_close', 'autoclose', 'app_open', 'huella', 'exit'] as $invalido) {
            $this->postJson('/api/attendances', [
                'user_id' => $user->id,
                'source' => $invalido,
            ], $this->adminHeaders())->assertStatus(422);
        }

        $this->assertDatabaseCount('attendances', 1); // solo la entrada inicial
    }

    public function test_un_source_vacio_cuenta_como_ausente(): void
    {
        // Laravel convierte la cadena vacía en null antes de validar, así que
        // `source: ""` no es un valor inválido sino uno no indicado, y cae al
        // valor por defecto. Se fija aquí para que quede dicho: no es un hueco
        // en la lista cerrada.
        $user = User::factory()->create();

        $this->postJson('/api/attendances', ['user_id' => $user->id, 'source' => ''], $this->adminHeaders())
            ->assertStatus(201);

        $this->assertDatabaseHas('attendances', ['user_id' => $user->id, 'source' => 'manual']);
    }

    public function test_facial_y_manual_siguen_funcionando(): void
    {
        foreach (['facial', 'manual'] as $origen) {
            $user = User::factory()->create();

            $this->postJson('/api/attendances', ['user_id' => $user->id, 'source' => $origen], $this->adminHeaders())
                ->assertStatus(201);

            $this->assertDatabaseHas('attendances', ['user_id' => $user->id, 'source' => $origen]);
        }
    }

    public function test_el_antiduplicado_facial_sigue_intacto(): void
    {
        $user = User::factory()->create();
        $cuerpo = ['user_id' => $user->id, 'action' => 'entry', 'source' => 'facial'];

        $this->postJson('/api/attendances', $cuerpo, $this->adminHeaders())->assertStatus(201);
        $this->postJson('/api/attendances', $cuerpo, $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('deduplicated', true);

        $this->assertSame(1, Attendance::query()->count());
    }
}
