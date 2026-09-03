<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El fallo real de producción, reproducido.
 *
 * Desde el 1 de septiembre el lector facial reintenta el mismo POST cada 15
 * segundos y el servidor responde 422 en todos: unas once mil peticiones sin
 * una sola asistencia registrada. Se supo QUÉ regla falla por el tamaño de la
 * respuesta —76 bytes, que solo produce `validation.integer`— pero no con qué
 * valor, y sin eso no hay arreglo posible del cliente.
 *
 * Estas pruebas fijan dos cosas: que el rechazo sigue siendo un rechazo —la
 * validación NO se relaja— y que ahora queda registrado con qué dato llegó.
 */
class AttendanceRejectionLogTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(): User
    {
        return User::factory()->create();
    }

    // ── El rechazo sigue siendo un rechazo ──────────────────────────────────

    /**
     * La forma exacta que se observó en producción: `user_id` no entero.
     */
    #[DataProvider('noEnteros')]
    public function test_un_user_id_que_no_es_entero_se_rechaza(mixed $valor): void
    {
        $this->postJson('/api/attendances', ['user_id' => $valor, 'source' => 'facial'], $this->adminHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');

        $this->assertDatabaseCount('attendances', 0);
    }

    public static function noEnteros(): array
    {
        return [
            'texto de un cliente Python' => ['None'],
            'texto de un cliente JS' => ['undefined'],
            'decimal' => [774.5],
            'lista' => [[774]],
            'cadena vacía con espacio' => [' '],
        ];
    }

    public function test_un_user_id_entero_valido_se_acepta(): void
    {
        $user = $this->usuario();

        $this->postJson('/api/attendances', ['user_id' => $user->id, 'source' => 'facial'], $this->adminHeaders())
            ->assertStatus(201);

        $this->assertDatabaseHas('attendances', ['user_id' => $user->id, 'source' => 'facial']);
    }

    public function test_un_entero_en_texto_sigue_siendo_valido(): void
    {
        // Los formularios y muchos clientes mandan todo como cadena. Rechazarlo
        // rompería productores que hoy funcionan.
        $user = $this->usuario();

        $this->postJson('/api/attendances', ['user_id' => (string) $user->id], $this->adminHeaders())
            ->assertStatus(201);
    }

    public function test_un_usuario_inexistente_se_rechaza_por_otro_motivo(): void
    {
        $this->postJson('/api/attendances', ['user_id' => 999999], $this->adminHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');

        $this->assertDatabaseCount('attendances', 0);
    }

    // ── Y ahora queda constancia de con qué llegó ───────────────────────────

    public function test_el_rechazo_registra_el_valor_recibido(): void
    {
        Log::shouldReceive('channel')->with('attendance')->andReturnSelf();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $mensaje, array $contexto) {
                // Lo que hacía falta para arreglar el cliente: el tipo y la
                // muestra del dato que llegó, más el error que provocó.
                return str_contains($mensaje, 'rechazado')
                    && str_contains($contexto['campos']['user_id'], 'string')
                    && str_contains($contexto['campos']['user_id'], 'None')
                    && array_key_exists('user_id', $contexto['errores'])
                    && array_key_exists('ip', $contexto);
            });

        $this->postJson('/api/attendances', ['user_id' => 'None'], $this->adminHeaders())
            ->assertStatus(422);
    }

    public function test_una_peticion_valida_no_escribe_en_ese_canal(): void
    {
        // El canal es para diagnosticar el fallo; si registrara también los
        // aciertos serían miles de líneas al día sin ningún valor.
        Log::shouldReceive('channel')->with('attendance')->never();

        $this->postJson('/api/attendances', ['user_id' => $this->usuario()->id], $this->adminHeaders())
            ->assertStatus(201);
    }

    public function test_no_se_registran_valores_sensibles(): void
    {
        $registrado = null;
        Log::shouldReceive('channel')->with('attendance')->andReturnSelf();
        Log::shouldReceive('warning')->once()->withArgs(function ($mensaje, $contexto) use (&$registrado) {
            $registrado = $contexto['campos'];

            return true;
        });

        $this->postJson('/api/attendances', [
            'user_id' => 'None',
            'token' => 'secreto-que-no-debe-aparecer',
            'face_image' => str_repeat('A', 4000),
        ], $this->adminHeaders())->assertStatus(422);

        $this->assertStringNotContainsString('secreto-que-no-debe-aparecer', json_encode($registrado));
        $this->assertStringContainsString('oculto', $registrado['token']);
        $this->assertStringContainsString('oculto', $registrado['face_image']);
    }

    public function test_un_fallo_al_registrar_no_altera_la_respuesta(): void
    {
        // Un observador que rompe lo observado es peor que no observar. Si el
        // canal de log falla, el cliente debe seguir recibiendo su 422.
        Log::shouldReceive('channel')->with('attendance')->andThrow(new \RuntimeException('disco lleno'));

        $this->postJson('/api/attendances', ['user_id' => 'None'], $this->adminHeaders())
            ->assertStatus(422);
    }
}
