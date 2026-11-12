<?php

namespace Tests\Feature\Commercial;

use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * La línea de tiempo de Supervisión (`GET /api/admin/marketing/supervision/activity`)
 * habla en español.
 *
 * El CRM pinta `action`, `reason` y `result` tal como llegan
 * (`supervision-page.ts`). Antes, 16 de los 27 hechos comerciales y casi todas
 * las decisiones salían con su clave interna: el 12-11-2026, en producción, 316
 * de 340 hechos (`renewal_window_opened`, `inactivity_detected`…) y 3 242 de
 * 3 262 decisiones de 30 días (`inbound_skipped`, `unsupported_message`). El
 * motivo y el resultado salían siempre en crudo.
 *
 * Aquí se fija, contra la ruta real, que TODO lo que el código puede escribir
 * tiene su etiqueta: un hecho, una acción, un motivo o un estado nuevos sin
 * texto ponen esta prueba en rojo.
 */
class SupervisionActivityLabelsTest extends TestCase
{
    use RefreshDatabase;

    /** Lo que el código escribe en `marketing_ai_actions.action_type` además de las acciones del modelo. */
    private const OTHER_ACTION_TYPES = ['inbound_skipped', 'unsupported_message', 'human_takeover', 'reactivate', 'release_to_ai', 'payment_link_generated'];

    /** Los motivos operativos que escribe el código (enrutador, revisión del personal, cobro). */
    private const OPERATIONAL_REASONS = [
        'auto_analyze_disabled', 'do_not_contact', 'skipped_manual_takeover', 'unsupported_message_type',
        'needs_human_review', 'critic_failed', 'staff_review', 'sensitive_topic', 'medical_case',
        'payment_or_fraud_claim', 'human_requested', 'unsafe_content', 'already_paid',
    ];

    /** Los estados de una acción y de una herramienta (`commercial_tool_invocations`: pending → running → succeeded | failed | rejected | skipped). */
    private const STATUSES = ['executed', 'proposed', 'skipped', 'failed', 'pending', 'running', 'succeeded', 'rejected'];

    private array $headers = [];

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.enabled', false);
        config()->set('commercial.events_enabled', false);   // las filas se insertan a mano: ningún observador añade las suyas
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-12 15:00:00', 'UTC'));

        $this->headers = $this->actingAsAdmin(Admin::create([
            'name' => 'Supervisora', 'email' => 'sup-etiquetas@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]));
        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'meta_user_id' => '573150000077', 'phone' => '3150000077',
            'name' => 'Prospecto Etiquetas', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_los_27_hechos_del_vocabulario_salen_con_su_texto_y_no_con_la_clave(): void
    {
        $this->assertCount(27, V::EVENTS, 'si el vocabulario crece, esta prueba exige su etiqueta');
        foreach (V::EVENTS as $i => $evento) {
            $this->fact($evento, $i);
        }

        $filas = $this->activity()->where('kind', 'fact');

        $this->assertCount(27, $filas);
        $this->assertSame(V::EVENTS, $filas->sortBy('at')->pluck('action_code')->all(), 'los 27, cada uno una vez');
        foreach ($filas as $f) {
            $this->assertHumano($f['action'], "hecho {$f['action_code']}");
            $this->assertSame(['sistema', 'Registrado', 'ok'], [$f['actor'], $f['result'], $f['result_code']]);
        }
        $this->assertCount(27, $filas->pluck('action')->unique(), 'ningún texto repetido: cada hecho se distingue del otro');
        $this->assertSame(
            ['La membresía está por vencer', 'Dejó de venir: se detectó inactividad', 'La membresía venció', 'El cliente presentó una queja'],
            array_map(fn (string $e): string => $filas->firstWhere('action_code', $e)['action'], [V::EV_RENEWAL_WINDOW_OPENED, V::EV_INACTIVITY_DETECTED, V::EV_MEMBERSHIP_EXPIRED, V::EV_COMPLAINT_CREATED]),
            'los que más salen en producción, y la queja',
        );
    }

    public function test_toda_accion_que_el_codigo_escribe_sale_con_su_texto(): void
    {
        $tipos = array_merge(SalesAgentDecisionSchema::RECOMMENDED_ACTIONS, self::OTHER_ACTION_TYPES);
        $this->assertCount(15, array_unique($tipos));
        foreach ($tipos as $i => $tipo) {
            $this->action(['action_type' => $tipo], $i);
        }

        $filas = $this->activity()->where('kind', 'decision');

        $this->assertSame($tipos, $filas->sortBy('at')->pluck('action_code')->all());
        foreach ($filas as $f) {
            $this->assertHumano($f['action'], "acción {$f['action_code']}");
        }
        $this->assertSame(
            ['No analizó el mensaje entrante', 'Llegó un mensaje que el agente no puede leer', 'Decidió no responder', 'Propuso un enlace de pago'],
            array_map(fn (string $t): string => $filas->firstWhere('action_code', $t)['action'], ['inbound_skipped', 'unsupported_message', SalesIntents::ACTION_NO_REPLY, SalesIntents::ACTION_GENERATE_PAYMENT_LINK]),
        );
    }

    public function test_el_motivo_sale_en_espanol_si_es_un_codigo_y_tal_cual_si_lo_escribio_una_persona(): void
    {
        $intenciones = $this->intents();
        $this->assertCount(27, $intenciones, 'las intenciones del catálogo');
        $codigos = array_values(array_unique(array_merge(self::OPERATIONAL_REASONS, $intenciones)));
        foreach ($codigos as $i => $codigo) {
            $this->action(['reason' => $codigo, 'metadata' => ['orden' => $codigo]], $i);
        }
        $libre = 'El cliente llamó a recepción y prefiere que lo atienda Laura.';
        $this->action(['reason' => $libre, 'action_type' => 'human_takeover', 'metadata' => ['source' => 'manual', 'admin_id' => 7]], 200);
        $this->action(['reason' => null], 201);
        $this->action(['reason' => 'codigo_que_nadie_conoce'], 202);

        $filas = $this->activity()->where('kind', 'decision')->sortBy('at')->values();

        foreach ($filas->take(count($codigos)) as $i => $f) {
            $this->assertHumano($f['reason'], "motivo {$codigos[$i]}");
        }
        $this->assertSame(
            ['El análisis automático del agente está apagado.', 'Es un tipo de mensaje que el agente no puede leer.', 'Necesita que lo revise una persona.'],
            [$filas[0]['reason'], $filas[3]['reason'], $filas[4]['reason']],
            'los tres que más salen en producción',
        );
        [$deUnaPersona, $sinMotivo, $desconocido] = [$filas[count($codigos)], $filas[count($codigos) + 1], $filas[count($codigos) + 2]];
        $this->assertSame([$libre, 'empleado', 'La conversación pasó a una persona'], [$deUnaPersona['reason'], $deUnaPersona['actor'], $deUnaPersona['action']], 'lo que escribió una persona no se toca, y va a su nombre');
        $this->assertNull($sinMotivo['reason']);
        $this->assertSame('codigo_que_nadie_conoce', $desconocido['reason'], 'un código sin etiqueta sale tal cual: no se inventa un texto');
    }

    public function test_el_resultado_y_quien_lo_hizo_dicen_la_verdad(): void
    {
        foreach (self::STATUSES as $i => $estado) {
            $this->action(['status' => $estado], $i);
        }
        $this->action(['action_type' => 'inbound_skipped', 'status' => 'skipped', 'reason' => 'auto_analyze_disabled'], 50);
        $this->action(['action_type' => 'unsupported_message', 'status' => 'proposed', 'reason' => 'unsupported_message_type'], 51);
        $this->action(['action_type' => 'release_to_ai', 'status' => 'executed', 'metadata' => ['source' => 'manual', 'admin_id' => 7]], 52);
        $this->action(['action_type' => 'human_takeover', 'status' => 'executed', 'metadata' => null], 53);
        $this->action(['action_type' => 'reply', 'status' => 'estado_nuevo'], 54);
        $this->tool('succeeded', 60);
        $this->tool('rejected', 61);

        $todas = $this->activity();
        $filas = $todas->where('kind', 'decision')->sortBy('at')->values();

        foreach (self::STATUSES as $i => $estado) {
            $this->assertSame($estado, $filas[$i]['result_code']);
            $this->assertHumano($filas[$i]['result'], "estado {$estado}");
        }
        $this->assertSame(['Ejecutada', 'Propuesta, sin ejecutar', 'Omitida', 'Falló'], [$filas[0]['result'], $filas[1]['result'], $filas[2]['result'], $filas[3]['result']], 'los cuatro que hay en producción');
        $n = count(self::STATUSES);
        $this->assertSame(['sistema', 'sistema', 'empleado', 'ia', 'ia'], $filas->slice($n, 5)->pluck('actor')->all(), 'el enrutador, una persona desde el CRM y el agente');
        $this->assertSame('estado_nuevo', $filas[$n + 4]['result'], 'un estado sin etiqueta sale tal cual');

        $herramientas = $todas->where('kind', 'tool')->sortBy('at')->values();
        $this->assertSame([['Correcta', 'succeeded'], ['Rechazada', 'rejected']], $herramientas->map(fn (array $h): array => [$h['result'], $h['result_code']])->all());
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** @return Collection<int, array<string, mixed>> */
    private function activity(): Collection
    {
        $r = $this->getJson('/api/admin/marketing/supervision/activity?hours=24&limit=200', $this->headers)->assertOk();

        return collect($r->json('data'));
    }

    /** Un texto para una persona: ni vacío ni una clave interna (minúsculas con guiones bajos). */
    private function assertHumano(mixed $texto, string $que): void
    {
        $this->assertIsString($texto, $que);
        $this->assertNotSame('', trim($texto), $que);
        $this->assertDoesNotMatchRegularExpression('/^[a-z0-9]+(_[a-z0-9]+)*$/', $texto, "{$que} sale con su clave interna: «{$texto}»");
        $this->assertMatchesRegularExpression('/^[A-ZÁÉÍÓÚÑ¿¡]/u', $texto, "{$que} empieza como una frase: «{$texto}»");
    }

    /** @return list<string> las 27 intenciones de `SalesIntents` (las constantes anteriores a las temperaturas) */
    private function intents(): array
    {
        $constantes = (new ReflectionClass(SalesIntents::class))->getConstants();
        $hasta = array_search('TEMP_COLD', array_keys($constantes), true);

        return array_values(array_slice($constantes, 0, $hasta));
    }

    private function fact(string $evento, int $segundos): void
    {
        $en = Carbon::now()->subHour()->addSeconds($segundos)->toDateTimeString();
        DB::table('commercial_events')->insert([
            'marketing_lead_id' => $this->lead->id, 'event' => $evento, 'payload' => json_encode(['source' => 'prueba']),
            'occurred_at' => $en, 'dedupe_key' => "prueba:{$evento}:{$segundos}", 'created_at' => $en, 'updated_at' => $en,
        ]);
    }

    /** @param  array<string, mixed>  $con */
    private function action(array $con, int $segundos): void
    {
        $en = Carbon::now()->subHours(2)->addSeconds($segundos)->toDateTimeString();
        $meta = array_key_exists('metadata', $con) ? $con['metadata'] : ['message_id' => 1];
        DB::table('marketing_ai_actions')->insert([
            'lead_id' => $this->lead->id, 'conversation_id' => $this->conversation->id,
            'action_type' => $con['action_type'] ?? 'reply', 'reason' => array_key_exists('reason', $con) ? $con['reason'] : 'general_info',
            'status' => $con['status'] ?? 'executed', 'metadata' => $meta === null ? null : json_encode($meta),
            'created_at' => $en, 'updated_at' => $en,
        ]);
    }

    private function tool(string $estado, int $segundos): void
    {
        $en = Carbon::now()->subHours(3)->addSeconds($segundos)->toDateTimeString();
        DB::table('commercial_tool_invocations')->insert([
            'marketing_lead_id' => $this->lead->id, 'marketing_conversation_id' => $this->conversation->id,
            'uuid' => (string) Str::uuid(), 'tool' => 'payment_link.send', 'status' => $estado, 'idempotency_key' => "prueba:{$estado}:{$segundos}",
            'created_at' => $en, 'updated_at' => $en,
        ]);
    }
}
