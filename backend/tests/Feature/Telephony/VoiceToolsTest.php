<?php

namespace Tests\Feature\Telephony;

use App\Models\Plan;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Models\Voice\VoiceExclusion;
use App\Services\Telephony\VoiceBrake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * `POST /api/voice/tools/{tool}` de punta a punta (contrato H8, D8-10, D8-16,
 * E1-1; fixture `elevenlabs-webhook-bodies.json` §tools y §routeRules). Sin red.
 *
 * TRADUCCIONES DECLARADAS DEL FIXTURE (no doblan ningún caso):
 *  - «la saliente todavía requested (sin conversación)»: en DB8 una fila
 *    `requested` no admite ids del proveedor (`vc_provider_binding_chk`), así
 *    que se encuentra por su `call_ref` (la variable que la carga compilada
 *    manda en el cuerpo de cada herramienta, B8-a).
 *  - «solicitar_humano en horario del personal → disponible y el alias»: los
 *    destinos aprobados viven en el perfil del Studio y la telefonía aún no
 *    tiene un adaptador enumerado para leerlos (decisión pendiente del lead):
 *    falla cerrado, «no disponible» y sin alias.
 */
class VoiceToolsTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const REQUESTED_BY_CALL_REF = 'tools · consultar_horarios · la saliente todavía requested (sin conversación) → rechazada';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->voiceWorld(['voice.transfer.staff_hours.weekdays' => '00:00-24:00', 'voice.transfer.staff_hours.saturday' => '00:00-24:00']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function toolCases(): array
    {
        $casos = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/voice/elevenlabs-webhook-bodies.json'), true)['tools'];
        $out = [];
        foreach ($casos as $c) {
            $out[$c['name']] = [$c];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $c */
    #[DataProvider('toolCases')]
    public function test_cada_caso_del_fixture(array $c): void
    {
        if (isset($c['given']['profileTransferAliases'])) {
            // El horario del personal no abre en domingo ni en festivo: un miércoles laborable de Bogotá.
            $this->travelTo(Carbon::parse('2026-11-04 15:00:00', 'America/Bogota'));
        }
        $g = $c['given']['call'] ?? null;
        $llamada = null;
        $cuerpo = $c['rawBody'];
        if ($g !== null) {
            $sinIds = $g['status'] === 'requested';
            $llamada = $this->voiceCall($g['direction'], $g['status'], array_filter([
                'provider_conversation_id' => $sinIds ? null : $g['providerConversationId'],
                'provider_call_sid' => $sinIds ? null : $g['providerCallSid'],
                'tool_calls' => $g['toolRequests'],
            ], static fn ($v) => $v !== null));
            if ($c['name'] === self::REQUESTED_BY_CALL_REF) {
                $cuerpo = (string) json_encode(json_decode($cuerpo, true) + ['call_ref' => $llamada->call_ref]);
            }
            if ($g['braked']) {
                VoiceBrake::open('guard_violation', 'guard:prueba-herramienta', []);
            }
        }
        if (isset($c['given']['profileTransferAliases'])) {
            // E4-7: los destinos del perfil de la entrada que selló la llamada (la 1, con el agente sellado).
            $this->publishVoice(transfers: array_map(static fn (string $alias, int $i): array => [$alias, '+5760100001'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)],
                $c['given']['profileTransferAliases'], array_keys($c['given']['profileTransferAliases'])));
            if (($c['given']['staffHoursNow'] ?? true) === false) {
                config(['voice.transfer.staff_hours.weekdays' => '', 'voice.transfer.staff_hours.saturday' => '']);
            }
        }
        if (in_array($c['tool'], ['consultar_planes'], true)) {
            Plan::query()->create(['name' => 'Mensual', 'price' => 89000, 'duration_days' => 30, 'active' => true, 'sellable' => true]);
        }

        $r = $this->rawPost('/api/voice/tools/'.$c['tool'], $cuerpo, array_filter(['Authorization' => $c['headers']['Authorization'] ?? null], static fn ($v) => $v !== null));
        $e = $c['expected'];
        $r->assertStatus($e['http']);
        $this->assertSame($e['verdict'], $r->json('verdict'), 'veredicto');
        if (isset($e['events'])) {
            $this->assertSame($e['events'], VoiceCallEvent::query()->orderBy('id')->pluck('kind')->all(), 'eventos');
        }
        if (array_key_exists('transition', $e) && $llamada !== null) {
            $this->assertSame($e['transition']['to'] ?? $g['status'], VoiceCall::query()->findOrFail($llamada->id)->status, 'transición');
        }
        if (($e['neutralMessage'] ?? false) === true) {
            $this->assertIsString($r->json('message'));
        }
        if (array_key_exists('available', $e)) {
            $this->assertSame([$e['available'], $e['alias']], [$r->json('disponible'), $r->json('alias')]);
        }
        foreach ($e['sideEffects'] ?? [] as $efecto) {
            $this->assertSame('voice_exclusions', $efecto['table']);
            $this->assertTrue(VoiceExclusion::query()->where('source', $efecto['source'])->where('phone_hash', $efecto['remoteHash'])->exists(), 'la exclusión del número SELLADO de la llamada');
        }
        if (($e['sideEffects'] ?? null) === []) {
            $this->assertSame(0, VoiceExclusion::query()->count());
        }
        foreach ($e['mustNotCreateExclusionFor'] ?? [] as $huella) {
            $this->assertFalse(VoiceExclusion::query()->where('phone_hash', $huella)->exists());
        }
        foreach ($e['responseMustNotContain'] ?? [] as $s) {
            $this->assertStringNotContainsString($s, (string) $r->getContent());
        }
        if (isset($e['priceSource'])) {
            $this->assertSame([['nombre' => 'Mensual', 'condiciones' => '30 días de vigencia', 'precio' => '$ 89.000']], $r->json('planes'));
        }
        Http::assertNothingSent();
    }

    public function test_sin_secreto_configurado_no_vale_ningun_bearer_y_el_catalogo_no_se_aprende_sin_autenticar(): void
    {
        config(['voice.elevenlabs.tool_secret' => null]);
        foreach (['Bearer ', 'Bearer x', ''] as $cabecera) {
            $this->rawPost('/api/voice/tools/consultar_planes', '{"conversation_id":"conv_x"}', ['Authorization' => $cabecera])->assertStatus(401);
        }
        config(['voice.elevenlabs.tool_secret' => self::TOOL_SECRET]);
        $this->rawPost('/api/voice/tools/aplicar_descuento', '{"conversation_id":"conv_x"}', ['Authorization' => 'Bearer otro'])->assertStatus(401);
        $this->rawPost('/api/voice/tools/consultar_planes', '{"conversation_id":"conv_x"}', ['Authorization' => self::TOOL_SECRET])->assertStatus(401, 'sin el esquema Bearer');
    }

    public function test_el_tope_de_8_kb_y_un_nombre_de_herramienta_con_otra_forma(): void
    {
        $this->rawPost('/api/voice/tools/consultar_planes', str_repeat('a', 8193), ['Authorization' => 'Bearer '.self::TOOL_SECRET])->assertStatus(413);
        $this->rawPost('/api/voice/tools/Consultar-Planes', '{}', ['Authorization' => 'Bearer '.self::TOOL_SECRET])->assertStatus(404);
    }

    public function test_no_volver_a_llamar_es_idempotente_y_ata_el_numero_sellado(): void
    {
        $llamada = $this->voiceCall('outbound', 'active', ['provider_conversation_id' => 'conv_h8fx_0031', 'provider_call_sid' => 'CA000000000000000000000000a800001f']);
        foreach ([1, 2] as $_) {
            $this->rawPost('/api/voice/tools/no_volver_a_llamar', '{"conversation_id":"conv_h8fx_0031"}', ['Authorization' => 'Bearer '.self::TOOL_SECRET])->assertOk()->assertJson(['ok' => true, 'excluido' => true]);
        }
        $this->assertSame(1, VoiceExclusion::query()->count());
        $exclusion = VoiceExclusion::query()->sole();
        $this->assertSame([$llamada->remote_hash, '0031', 'spoken_opt_out', $llamada->id, 'system'], [$exclusion->phone_hash, $exclusion->phone_last4, $exclusion->source, $exclusion->call_id, $exclusion->actor_type]);
        $this->assertSame(2, VoiceCall::query()->findOrFail($llamada->id)->tool_calls, 'cada petición cuenta en el cupo');
    }

    public function test_un_call_ref_que_no_es_el_de_la_llamada_no_la_encuentra(): void
    {
        $this->voiceCall('outbound', 'active', ['provider_conversation_id' => 'conv_h8fx_0031', 'provider_call_sid' => 'CA000000000000000000000000a800001f']);
        $this->rawPost('/api/voice/tools/consultar_horarios', '{"conversation_id":"conv_h8fx_0031","call_ref":"00000000-0000-4000-8000-000000000999"}', ['Authorization' => 'Bearer '.self::TOOL_SECRET])
            ->assertOk()->assertJson(['ok' => false, 'verdict' => 'call_not_found']);
    }

    public function test_consultar_planes_solo_da_los_vendibles_con_el_precio_exacto_y_consultar_horarios_sin_hechos_no_inventa(): void
    {
        $this->voiceCall('outbound', 'active', ['provider_conversation_id' => 'conv_h8fx_0031', 'provider_call_sid' => 'CA000000000000000000000000a800001f']);
        Plan::query()->create(['name' => 'Trimestral', 'price' => 239900.0, 'duration_days' => 90, 'active' => true, 'sellable' => true, 'sort_order' => 2]);
        Plan::query()->create(['name' => 'Oculto', 'price' => 10000, 'duration_days' => 30, 'active' => true, 'sellable' => false]);
        Plan::query()->create(['name' => 'Gratis', 'price' => 0, 'duration_days' => 30, 'active' => true, 'sellable' => true]);
        $r = $this->rawPost('/api/voice/tools/consultar_planes', '{"conversation_id":"conv_h8fx_0031"}', ['Authorization' => 'Bearer '.self::TOOL_SECRET])->assertOk();
        $this->assertSame([['nombre' => 'Trimestral', 'condiciones' => '90 días de vigencia', 'precio' => '$ 239.900']], $r->json('planes'));

        $this->rawPost('/api/voice/tools/consultar_horarios', '{"conversation_id":"conv_h8fx_0031"}', ['Authorization' => 'Bearer '.self::TOOL_SECRET])
            ->assertOk()->assertJson(['disponible' => false]);
        config(['voice.facts.site' => 'Sede Centro', 'voice.facts.hours' => 'Lunes a viernes de 5:00 a 22:00']);
        $this->rawPost('/api/voice/tools/consultar_horarios', '{"conversation_id":"conv_h8fx_0031"}', ['Authorization' => 'Bearer '.self::TOOL_SECRET])
            ->assertOk()->assertJson(['disponible' => true, 'sede' => 'Sede Centro', 'horario' => 'Lunes a viernes de 5:00 a 22:00']);
    }
}
