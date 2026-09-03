<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Qué acepta y qué rechaza el registro de asistencias.
 *
 * Nacieron durante el bloqueo del lector facial de septiembre, cuando se creyó
 * —por el tamaño de la respuesta— que el 422 venía de un `user_id` no entero.
 * Resultó ser `source`, pero estas comprobaciones se quedan: fijan el contrato
 * de entrada del endpoint, que es justo lo que nadie había escrito y por eso el
 * desajuste con el cliente pudo vivir dos días y medio sin que nada lo dijera.
 *
 * El cierre automático de jornada tiene sus propias pruebas en
 * {@see AutoCloseAttendanceTest}.
 */
class AttendanceValidationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('userIdsInvalidos')]
    public function test_un_user_id_que_no_es_entero_se_rechaza(mixed $valor): void
    {
        $this->postJson('/api/attendances', ['user_id' => $valor, 'source' => 'facial'], $this->adminHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');

        $this->assertDatabaseCount('attendances', 0);
    }

    public static function userIdsInvalidos(): array
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
        $user = User::factory()->create();

        $this->postJson('/api/attendances', ['user_id' => $user->id, 'source' => 'facial'], $this->adminHeaders())
            ->assertStatus(201);

        $this->assertDatabaseHas('attendances', ['user_id' => $user->id, 'source' => 'facial']);
    }

    public function test_un_entero_en_texto_sigue_siendo_valido(): void
    {
        // Los formularios y muchos clientes mandan todo como cadena. Rechazarlo
        // rompería productores que hoy funcionan.
        $user = User::factory()->create();

        $this->postJson('/api/attendances', ['user_id' => (string) $user->id], $this->adminHeaders())
            ->assertStatus(201);
    }

    public function test_un_usuario_inexistente_se_rechaza(): void
    {
        $this->postJson('/api/attendances', ['user_id' => 999999], $this->adminHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_sin_user_id_no_se_registra_nada(): void
    {
        $this->postJson('/api/attendances', ['source' => 'facial'], $this->adminHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');

        $this->assertDatabaseCount('attendances', 0);
    }
}
