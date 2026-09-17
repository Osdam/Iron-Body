<?php

namespace Tests\Feature\Marketing;

use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El parte de avería del workflow.
 *
 * Lo que se prueba aquí no es que el endpoint escriba una fila: es que el
 * canal siga teniendo alarma cuando ULTRON se rompe, y que esa alarma sea
 * legible. Dos cosas la vuelven inútil, y ambas tienen su prueba:
 *
 *  - que cada fallo abra su propia alarma (panel ilegible, se deja de mirar);
 *  - que el parte arrastre el texto del cliente (PII en la pantalla de averías).
 */
class UltronIncidentTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'internal-secret-de-pruebas';

    private const RUTA = '/api/internal/marketing/ai/ultron/incidents';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('automation.internal_secret', self::SECRET);
        config()->set('observability.incidents.grouping_window_minutes', 60);
        config()->set('observability.incidents.escalate_after_occurrences', 10);
    }

    /** @param array<string,mixed> $extra */
    private function reportar(array $extra = []): TestResponse
    {
        return $this->postJson(self::RUTA, array_merge([
            'workflow_execution_id' => '278951',
            'stage' => 'strategist',
            'error_code' => 'openai_rate_limited',
            'retryable' => true,
        ], $extra), ['Authorization' => 'Bearer '.self::SECRET]);
    }

    // ── La puerta ────────────────────────────────────────────────────────────

    public function test_sin_credencial_no_se_pueden_publicar_incidentes(): void
    {
        $this->postJson(self::RUTA, [
            'workflow_execution_id' => '1', 'stage' => 'commit',
            'error_code' => 'boom', 'retryable' => false,
        ])->assertStatus(401);

        $this->assertSame(0, Incident::count());
    }

    public function test_con_credencial_equivocada_tampoco(): void
    {
        $this->postJson(self::RUTA, [
            'workflow_execution_id' => '1', 'stage' => 'commit',
            'error_code' => 'boom', 'retryable' => false,
        ], ['Authorization' => 'Bearer otra-cosa'])->assertStatus(401);

        $this->assertSame(0, Incident::count());
    }

    // ── El contrato ──────────────────────────────────────────────────────────

    public function test_registra_el_incidente_y_devuelve_su_id(): void
    {
        $r = $this->reportar()->assertOk();

        $incidente = Incident::findOrFail($r->json('incident_id'));

        $this->assertSame('ultron', $incidente->source);
        $this->assertSame('ultron.strategist.openai_rate_limited', $incidente->kind);
        $this->assertSame(Incident::STATUS_OPEN, $incidente->status);
        $this->assertSame(1, $incidente->occurrences);
        $this->assertTrue($r->json('ok'));
    }

    public function test_lo_que_no_se_puede_reintentar_entra_como_grave(): void
    {
        $this->reportar(['retryable' => false])->assertOk();

        $this->assertSame(Incident::SEVERITY_HIGH, Incident::first()->severity);
    }

    public function test_lo_reintentable_entra_como_medio(): void
    {
        $this->reportar(['retryable' => true])->assertOk();

        $this->assertSame(Incident::SEVERITY_MEDIUM, Incident::first()->severity);
    }

    /**
     * La regla `boolean` no admite las cadenas "true"/"false" — que es justo lo
     * que emitiría un nodo Set mal configurado. Mejor un 422 ruidoso que un
     * fallo definitivo archivado como reintentable.
     */
    public function test_la_cadena_false_se_rechaza_en_vez_de_interpretarse(): void
    {
        $this->reportar(['retryable' => 'false'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('retryable');

        $this->assertSame(0, Incident::count());
    }

    /** "0" sí se acepta, y debe quedar como `false` en la evidencia, no como "0". */
    public function test_el_cero_textual_se_normaliza_a_booleano(): void
    {
        $this->reportar(['retryable' => '0'])->assertOk();

        $incidente = Incident::first();
        $this->assertSame(Incident::SEVERITY_HIGH, $incidente->severity);
        $this->assertFalse($incidente->evidence['retryable']);
    }

    public function test_una_etapa_inventada_se_rechaza(): void
    {
        $this->reportar(['stage' => 'etapa_que_no_existe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stage');

        $this->assertSame(0, Incident::count());
    }

    /**
     * Al revés que la anterior: `unknown` SÍ se acepta. Un nodo nuevo que el
     * Error Handler no sepa traducir no puede dejar al canal sin alarma.
     */
    public function test_una_etapa_desconocida_pero_declarada_si_entra(): void
    {
        $this->reportar(['stage' => 'unknown'])->assertOk();

        $this->assertSame('ultron.unknown.openai_rate_limited', Incident::first()->kind);
    }

    public function test_los_campos_obligatorios_lo_son(): void
    {
        $this->postJson(self::RUTA, [], ['Authorization' => 'Bearer '.self::SECRET])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['workflow_execution_id', 'stage', 'error_code', 'retryable']);
    }

    public function test_un_campo_fuera_del_contrato_tumba_el_parte(): void
    {
        $r = $this->reportar(['stack_trace' => 'Error: at line 42'])->assertStatus(422);

        $this->assertSame('unexpected_fields', $r->json('code'));
        $this->assertSame(['stack_trace'], $r->json('fields'));
        $this->assertSame(0, Incident::count());
    }

    // ── Que no se cuele PII ──────────────────────────────────────────────────

    /**
     * El `error_code` acaba en el título que se lee en pantalla. Si aceptara
     * prosa libre, un workflow mal escrito publicaría ahí lo que escribió el
     * cliente.
     */
    public function test_el_codigo_de_error_no_admite_prosa_libre(): void
    {
        $this->reportar(['error_code' => 'Hola, cuanto vale la mensualidad?'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('error_code');

        $this->assertSame(0, Incident::count());
    }

    public function test_el_id_de_ejecucion_tampoco(): void
    {
        $this->reportar(['workflow_execution_id' => '+57 314 345 5483'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('workflow_execution_id');
    }

    public function test_el_incidente_guarda_hilos_de_investigacion_no_contenido(): void
    {
        $this->reportar(['conversation_id' => 23, 'source_event_id' => 165])->assertOk();

        $incidente = Incident::first();

        $this->assertSame(
            ['n8n:278951', 'conversation:23', 'message:165'],
            $incidente->correlation_ids,
        );
        $this->assertSame(
            ['stage', 'error_code', 'retryable', 'occurred_at'],
            array_keys($incidente->evidence),
        );
        $this->assertSame(1, $incidente->affected_conversations);
        $this->assertSame(1, $incidente->affected_messages);
    }

    public function test_sin_conversacion_no_se_inventa_una_afectada(): void
    {
        $this->reportar(['conversation_id' => null, 'source_event_id' => null])->assertOk();

        $incidente = Incident::first();
        $this->assertSame(['n8n:278951'], $incidente->correlation_ids);
        $this->assertSame(0, $incidente->affected_conversations);
        $this->assertSame(0, $incidente->affected_messages);
    }

    // ── La agrupación, que es para lo que existe IRON GUARD ──────────────────

    public function test_el_mismo_fallo_repetido_es_un_incidente_con_varias_ocurrencias(): void
    {
        foreach (['278951', '278952', '278953'] as $ejecucion) {
            $this->reportar(['workflow_execution_id' => $ejecucion])->assertOk();
        }

        $this->assertSame(1, Incident::count());

        $incidente = Incident::first();
        $this->assertSame(3, $incidente->occurrences);
        // Los tres hilos se conservan: sin ellos no se puede investigar.
        $this->assertSame(['n8n:278951', 'n8n:278952', 'n8n:278953'], $incidente->correlation_ids);
    }

    public function test_fallos_distintos_no_se_mezclan(): void
    {
        $this->reportar(['stage' => 'strategist', 'error_code' => 'openai_rate_limited'])->assertOk();
        $this->reportar(['stage' => 'composer', 'error_code' => 'openai_rate_limited'])->assertOk();
        $this->reportar(['stage' => 'strategist', 'error_code' => 'openai_timeout'])->assertOk();

        $this->assertSame(3, Incident::count());
    }

    public function test_insistir_mucho_sube_la_gravedad(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->reportar(['workflow_execution_id' => (string) (278951 + $i)])->assertOk();
        }

        $incidente = Incident::first();
        $this->assertSame(10, $incidente->occurrences);
        // Entró como medio por reintentable; repetirse diez veces lo agrava.
        $this->assertSame(Incident::SEVERITY_HIGH, $incidente->severity);
    }
}
