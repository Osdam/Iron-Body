<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reproduce el bug reportado: admin activa un toggle del bloque IA en el editor
 * del plan, guarda, reabre el editor y aparece desactivado. Valida save→DB→load
 * para CADA toggle (no solo el reportado: el mismo bug suele afectar varios).
 */
class AiCapabilitiesSaveLoadTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_response_shape_matches_form_keys(): void
    {
        // El editor del CRM patchea el FormGroup con la respuesta GET. Confirma
        // que la respuesta SOLO trae las claves que el form espera (ai_*) y
        // que ninguna venga con nombre alterno (image_analysis_enabled sin ai_).
        $plan = Plan::create([
            'name' => 'Premium',
            'price' => 200000,
            'duration_days' => 30,
            'benefits' => 'Todo',
            'active' => true,
        ]);

        $this->adminPutJson("/api/plans/{$plan->id}/ai-capabilities", [
            'ai_image_analysis_enabled' => true,
            'ai_voice_chat_enabled' => true,
            'ai_realtime_voice_enabled' => true,
            'ai_progress_analysis_enabled' => true,
        ])->assertOk();

        $get = $this->adminGetJson("/api/plans/{$plan->id}/ai-capabilities");
        $get->assertOk();

        $caps = $get->json('capabilities');

        // Cada toggle del form DEBE existir con su nombre exacto.
        $required = [
            'ai_enabled', 'ai_chat_enabled', 'ai_image_analysis_enabled',
            'ai_voice_chat_enabled', 'ai_realtime_voice_enabled',
            'ai_progress_analysis_enabled', 'ai_smart_recommendations_enabled',
            'ai_weekly_summary_enabled', 'ai_proactive_notifications_enabled',
            'ai_monthly_messages_limit', 'ai_daily_messages_limit',
            'ai_monthly_image_limit', 'ai_monthly_audio_limit',
            'ai_max_audio_seconds', 'ai_max_image_size_mb', 'ai_context_level',
            'ai_nutrition_power', 'ai_routine_power',
        ];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $caps, "GET respuesta debe traer $key");
        }

        // Y los valores que el admin acaba de activar.
        $this->assertTrue($caps['ai_image_analysis_enabled']);
        $this->assertTrue($caps['ai_voice_chat_enabled']);
        $this->assertTrue($caps['ai_realtime_voice_enabled']);
        $this->assertTrue($caps['ai_progress_analysis_enabled']);
    }

    public function test_partial_payload_only_updates_supplied_fields(): void
    {
        // Reproduce el caso real: la fila ya existe (admin abrió el editor antes
        // y se creó con valores por defecto). Ahora envía SOLO un cambio: activa
        // image_analysis. Las otras claves no van en el payload — y la regla
        // 'sometimes' debería dejarlas como están en DB.
        $plan = Plan::create([
            'name' => 'Plan Mensual',
            'price' => 100000,
            'duration_days' => 30,
            'benefits' => 'Acceso',
            'active' => true,
        ]);

        // Setup: fila inicial con todo apagado.
        \DB::table('membership_ai_capabilities')->insert([
            'membership_plan_id' => $plan->id,
            'plan_code' => 'plan_mensual',
            'is_active' => true,
            'ai_enabled' => false,
            'ai_chat_enabled' => false,
            'ai_image_analysis_enabled' => false,
            'ai_voice_chat_enabled' => false,
            'ai_realtime_voice_enabled' => false,
            'progress_analysis_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Acción: admin envía SOLO el toggle que cambió (escenario "PATCH-like").
        $put = $this->adminPutJson(
            "/api/plans/{$plan->id}/ai-capabilities",
            ['ai_image_analysis_enabled' => true],
        );

        $put->assertOk();
        $put->assertJsonPath('capabilities.ai_image_analysis_enabled', true);

        $row = \DB::table('membership_ai_capabilities')
            ->where('membership_plan_id', $plan->id)->first();
        $this->assertEquals(1, $row->ai_image_analysis_enabled, 'flag debe persistir');
    }

    public function test_save_load_cycle_keeps_image_analysis_on(): void
    {
        $plan = Plan::create([
            'name' => 'Plan VIP',
            'price' => 200000,
            'duration_days' => 30,
            'benefits' => 'Acceso completo',
            'active' => true,
        ]);

        $payload = $this->fullPayload(['ai_image_analysis_enabled' => true]);

        $put = $this->adminPutJson("/api/plans/{$plan->id}/ai-capabilities", $payload);
        $put->assertOk();
        $put->assertJsonPath('capabilities.ai_image_analysis_enabled', true);

        $get = $this->adminGetJson("/api/plans/{$plan->id}/ai-capabilities");
        $get->assertOk();
        $get->assertJsonPath('capabilities.ai_image_analysis_enabled', true);

        // El DB row de verdad debe tener 1, no 0.
        $row = \DB::table('membership_ai_capabilities')
            ->where('membership_plan_id', $plan->id)->first();
        $this->assertEquals(1, $row->ai_image_analysis_enabled);
    }

    /**
     * Recorre TODOS los toggles del bloque IA. El bug suele afectar a varios
     * que comparten la misma raíz: si uno se cae, los otros con la misma forma
     * de validación/mapeo también se caen.
     */
    public function test_save_load_cycle_for_every_ai_toggle(): void
    {
        $plan = Plan::create([
            'name' => 'Plan Full',
            'price' => 250000,
            'duration_days' => 30,
            'benefits' => 'Todo incluido',
            'active' => true,
        ]);

        $toggles = [
            'ai_enabled',
            'ai_chat_enabled',
            'ai_image_analysis_enabled',
            'ai_voice_chat_enabled',
            'ai_realtime_voice_enabled',
            'ai_progress_analysis_enabled',
            'ai_smart_recommendations_enabled',
            'ai_weekly_summary_enabled',
            'ai_proactive_notifications_enabled',
        ];

        foreach ($toggles as $key) {
            $payload = $this->fullPayload([$key => true]);
            $put = $this->adminPutJson("/api/plans/{$plan->id}/ai-capabilities", $payload);
            $put->assertOk();

            $get = $this->adminGetJson("/api/plans/{$plan->id}/ai-capabilities");
            $get->assertOk();
            $this->assertTrue(
                $get->json("capabilities.$key") === true,
                "Toggle $key se perdió en el ciclo save→load. Respuesta: " . $get->getContent(),
            );
        }
    }

    // ── Potencias de Iron IA (docs/iron-ia/POTENCIAS-IRON-IA.md §3) ─────────

    /**
     * Los cinco niveles de cada potencia, ida y vuelta: PUT → fila → GET. El
     * mismo bug que este archivo vigila (guardar, reabrir y ver otro valor)
     * se repetiría aquí con un mapeo equivocado.
     */
    public function test_cada_nivel_de_cada_potencia_sobrevive_el_ciclo_guardar_y_cargar(): void
    {
        $plan = Plan::create(['name' => 'Plan Potencias', 'price' => 150000, 'duration_days' => 30, 'benefits' => 'IA', 'active' => true]);

        foreach (['ai_nutrition_power' => 'nutrition_power', 'ai_routine_power' => 'routine_power'] as $clave => $columna) {
            foreach ([1, 2, 3, 4, 5] as $nivel) {
                $this->adminPutJson("/api/plans/{$plan->id}/ai-capabilities", $this->fullPayload([$clave => $nivel]))
                    ->assertOk()
                    ->assertJsonPath("capabilities.$clave", $nivel);

                $this->adminGetJson("/api/plans/{$plan->id}/ai-capabilities")
                    ->assertOk()
                    ->assertJsonPath("capabilities.$clave", $nivel);

                $fila = \DB::table('membership_ai_capabilities')->where('membership_plan_id', $plan->id)->first();
                $this->assertSame($nivel, (int) $fila->{$columna}, "$columna no persistió el nivel $nivel");
            }
        }

        // Cambiar una potencia no toca la otra.
        $this->adminPutJson("/api/plans/{$plan->id}/ai-capabilities", ['ai_nutrition_power' => 2])->assertOk()
            ->assertJsonPath('capabilities.ai_nutrition_power', 2)
            ->assertJsonPath('capabilities.ai_routine_power', 5);
    }

    public function test_sin_fila_las_potencias_valen_1(): void
    {
        $plan = Plan::create(['name' => 'Plan Nuevo', 'price' => 90000, 'duration_days' => 30, 'benefits' => 'Acceso', 'active' => true]);

        $this->adminGetJson("/api/plans/{$plan->id}/ai-capabilities")->assertOk()
            ->assertJsonPath('capabilities.ai_nutrition_power', 1)
            ->assertJsonPath('capabilities.ai_routine_power', 1);

        // Y una fila creada sin mandarlas también queda en 1 (default de la columna).
        $this->adminPutJson("/api/plans/{$plan->id}/ai-capabilities", ['ai_chat_enabled' => true])->assertOk()
            ->assertJsonPath('capabilities.ai_nutrition_power', 1)
            ->assertJsonPath('capabilities.ai_routine_power', 1);
    }

    public function test_un_nivel_fuera_de_1_a_5_o_que_no_es_entero_se_rechaza_sin_tocar_la_fila(): void
    {
        $plan = Plan::create(['name' => 'Plan Validación', 'price' => 90000, 'duration_days' => 30, 'benefits' => 'Acceso', 'active' => true]);
        $this->adminPutJson("/api/plans/{$plan->id}/ai-capabilities", ['ai_nutrition_power' => 3, 'ai_routine_power' => 3])->assertOk();

        foreach ([0, 6, -1, 2.5, 'cinco', null, true] as $malo) {
            $this->adminPutJson("/api/plans/{$plan->id}/ai-capabilities", ['ai_nutrition_power' => $malo])
                ->assertStatus(422)->assertJsonValidationErrors('ai_nutrition_power');
            $this->adminPutJson("/api/plans/{$plan->id}/ai-capabilities", ['ai_routine_power' => $malo])
                ->assertStatus(422)->assertJsonValidationErrors('ai_routine_power');
        }

        $fila = \DB::table('membership_ai_capabilities')->where('membership_plan_id', $plan->id)->first();
        $this->assertSame([3, 3], [(int) $fila->nutrition_power, (int) $fila->routine_power]);
    }

    /**
     * Con Iron IA apagada se APLICA el nivel 1, pero el CRM tiene que volver a
     * enseñar lo que el administrador eligió: si no, al guardar cualquier otro
     * cambio lo machacaría con un 1.
     */
    public function test_con_ia_apagada_el_get_devuelve_lo_guardado(): void
    {
        $plan = Plan::create(['name' => 'Plan Apagado', 'price' => 90000, 'duration_days' => 30, 'benefits' => 'Acceso', 'active' => true]);
        $this->adminPutJson("/api/plans/{$plan->id}/ai-capabilities", ['ai_enabled' => false, 'ai_nutrition_power' => 4, 'ai_routine_power' => 5])->assertOk();

        $this->adminGetJson("/api/plans/{$plan->id}/ai-capabilities")->assertOk()
            ->assertJsonPath('capabilities.ai_enabled', false)
            ->assertJsonPath('capabilities.ai_nutrition_power', 4)
            ->assertJsonPath('capabilities.ai_routine_power', 5);

        $aplicadas = app(\App\Services\IronAiMembershipAccessService::class)->getPlanPowers([
            'active' => true, 'plan_id' => $plan->id, 'plan_name' => $plan->name, 'plan' => $plan, 'end_date' => null,
        ]);
        $this->assertSame([1, 1, 'ai_disabled'], [$aplicadas['nutrition'], $aplicadas['routine'], $aplicadas['source']]);
    }

    /** Las potencias se editan con el MISMO permiso que el resto: `plans.manage`. */
    public function test_editar_potencias_exige_plans_manage(): void
    {
        $plan = Plan::create(['name' => 'Plan Permisos', 'price' => 90000, 'duration_days' => 30, 'benefits' => 'Acceso', 'active' => true]);

        $this->putJson("/api/plans/{$plan->id}/ai-capabilities", ['ai_nutrition_power' => 5], $this->adminWithPermissions(['plans.view']))
            ->assertForbidden();
        $this->putJson("/api/plans/{$plan->id}/ai-capabilities", ['ai_nutrition_power' => 5], $this->sharedTokenHeaders())
            ->assertForbidden();
        $this->assertSame(0, \DB::table('membership_ai_capabilities')->where('membership_plan_id', $plan->id)->count());

        $this->putJson("/api/plans/{$plan->id}/ai-capabilities", ['ai_nutrition_power' => 5], $this->adminWithPermissions(['plans.view', 'plans.manage']))
            ->assertOk()->assertJsonPath('capabilities.ai_nutrition_power', 5);

        // Leer sí lo puede quien solo ve planes.
        $this->getJson("/api/plans/{$plan->id}/ai-capabilities", $this->adminWithPermissions(['plans.view']))
            ->assertOk()->assertJsonPath('capabilities.ai_nutrition_power', 5);
    }

    /**
     * Payload con TODOS los toggles+límites a un baseline (false/0) y luego
     * overrides puntuales. Replica lo que envía buildAiPayload() del CRM.
     */
    private function fullPayload(array $overrides = []): array
    {
        return array_merge([
            'ai_enabled' => false,
            'ai_chat_enabled' => false,
            'ai_image_analysis_enabled' => false,
            'ai_voice_chat_enabled' => false,
            'ai_realtime_voice_enabled' => false,
            'ai_progress_analysis_enabled' => false,
            'ai_smart_recommendations_enabled' => false,
            'ai_weekly_summary_enabled' => false,
            'ai_proactive_notifications_enabled' => false,
            'ai_monthly_messages_limit' => null,
            'ai_daily_messages_limit' => null,
            'ai_monthly_image_limit' => 0,
            'ai_monthly_audio_limit' => 0,
            'ai_max_audio_seconds' => 60,
            'ai_context_level' => 'basic',
        ], $overrides);
    }
}
