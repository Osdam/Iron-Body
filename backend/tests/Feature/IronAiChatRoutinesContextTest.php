<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberRoutineAssignment;
use App\Models\Routine;
use App\Models\Trainer;
use App\Services\IronAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El contexto del chat de Iron IA enseña al asistente las «Rutinas disponibles»
 * del socio. Antes entraban TODAS sus rutinas no asignadas: también los
 * borradores de su entrenador, las propuestas de recuperación de Iron IA
 * («Propuesta: 6 días por confirmar · …») y las rutinas retiradas. El socio no
 * las ve, así que el asistente podía ofrecerle una rutina que no existe para él.
 * Ahora sigue el criterio de «Más rutinas» (AppRoutineController::custom).
 */
class IronAiChatRoutinesContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_chat_solo_ve_las_rutinas_que_el_socio_ve(): void
    {
        $socio = Member::create(['full_name' => 'Socia Ficticia', 'document_number' => '9900123', 'status' => Member::STATUS_ACTIVE]);
        $entrenador = Trainer::create(['full_name' => 'Entrenador Ficticio', 'document' => 'T-990', 'status' => 'active']);
        $base = ['member_id' => $socio->id, 'is_template' => false];

        $publicada = Routine::create($base + ['name' => 'Rutina publicada por el entrenador', 'trainer_id' => $entrenador->id,
            'is_assigned' => true, 'status' => 'Activa']);
        MemberRoutineAssignment::create(['member_id' => $socio->id, 'routine_id' => $publicada->id, 'assigned_at' => now()]);
        Routine::create($base + ['name' => 'Rutina que se hizo ella', 'trainer_id' => null, 'is_assigned' => false, 'status' => 'Activa']);
        Routine::create($base + ['name' => 'Propuesta: 6 días por confirmar · Torso/Pierna', 'trainer_id' => $entrenador->id,
            'is_assigned' => false, 'status' => 'Borrador', 'generated_by_ai' => true]);
        Routine::create($base + ['name' => 'Borrador de Iron IA sin publicar', 'trainer_id' => $entrenador->id,
            'is_assigned' => false, 'status' => 'Borrador', 'generated_by_ai' => true]);
        Routine::create($base + ['name' => 'Rutina retirada', 'trainer_id' => $entrenador->id, 'is_assigned' => false, 'status' => 'Retirada']);

        $contexto = app(IronAiService::class)->buildUserContext($socio, null);

        $this->assertStringContainsString('Rutina publicada por el entrenador', $contexto);
        $this->assertStringContainsString('Rutina que se hizo ella', $contexto);
        $this->assertStringNotContainsString('Propuesta:', $contexto);
        $this->assertStringNotContainsString('Borrador de Iron IA sin publicar', $contexto);
        $this->assertStringNotContainsString('Rutina retirada', $contexto);
    }
}
