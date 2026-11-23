<?php

namespace Tests\Feature\Telephony;

use App\Models\Plan;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\VoicePublishedConfig;
use App\Services\Telephony\VoiceTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * PT-14 · B, LA ENTREGA (diseño `plans/informes-cierre/diseno-arq-pt14.md` §3
 * D7, D8 y D13, §4.3, §7 telefonía 1–8): `consultar_planes` dice lo PUBLICADO
 * ∩ lo VIGENTE. Del catálogo que se aprobó al publicar la voz (`evidence.catalog`
 * de la entrada `publish` del agente que SELLÓ la llamada), plan a plan por id:
 *
 *  - un plan que no estaba, o con otro nombre, no se entrega (`plansUnpublished`);
 *  - de sus beneficios, solo los actuales que estaban publicados (`benefitsUnpublished`);
 *  - el precio y las condiciones, los de ahora;
 *  - sin catálogo legible (no lo hay, no verifica o es de otro agente), ningún plan.
 *
 * Encima siguen todas las guardas de hoy (H-3, C-1). Por la ruta pública con el
 * secreto de las herramientas; la cadena firmada de verdad; sin red.
 */
class VoiceToolsPublishedCatalogTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const CONVERSATION = 'conv_h8fx_0031';

    private const BENEFICIOS = ['Acceso ilimitado al gimnasio', 'Zona de pesas y maquinaria', 'Valoración inicial'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->voiceWorld();
    }

    public function test_la_llamada_dice_el_nombre_y_los_beneficios_publicados_con_el_precio_de_ahora(): void
    {
        $mensual = $this->plan('Mensual', 89000, 1, self::BENEFICIOS);
        $this->publishVoice(evidence: $this->voiceCatalogEvidence());

        // Después de publicar cambia el precio (y la vigencia): no es texto comercial, sale el de ahora.
        $mensual->forceFill(['price' => 99000, 'duration_days' => 31])->save();

        $this->assertSame(['planes' => [['nombre' => 'Mensual', 'condiciones' => '31 días de vigencia', 'precio' => '$ 99.000', 'beneficios' => self::BENEFICIOS]],
            'nota' => VoiceTools::BENEFITS_NOTE], $this->consultarPlanes());
        $this->assertNull(VoiceCallEvent::query()->where('kind', 'tool_call')->sole()->codes, 'nada retenido ni sin publicar');
    }

    public function test_un_plan_renombrado_despues_de_publicar_no_se_entrega_ni_con_el_nombre_viejo_ni_con_el_nuevo(): void
    {
        $mensual = $this->plan('Mensual', 89000, 1, self::BENEFICIOS);
        $this->plan('Trimestral', 239900, 2, self::BENEFICIOS);
        $this->publishVoice(evidence: $this->voiceCatalogEvidence());
        $mensual->forceFill(['name' => 'Mensual Plus'])->save();
        $lineas = $this->captureToolLines();

        $cuerpo = $this->consultarPlanes();
        $this->assertSame(['Trimestral'], array_column($cuerpo['planes'], 'nombre'));
        $this->assertStringNotContainsString('Mensual', (string) json_encode($cuerpo, JSON_UNESCAPED_UNICODE), 'ni el nombre viejo ni el nuevo');
        $this->assertStringNotContainsString('89.000', (string) json_encode($cuerpo), 'ni su precio');
        $this->assertSame(['plansUnpublished' => 1], VoiceCallEvent::query()->where('kind', 'tool_call')->sole()->codes);
        $this->assertSame(1, $lineas()[0]['plans_unpublished'] ?? null, 'y en la línea voice.tool');
    }

    public function test_de_los_beneficios_solo_salen_los_publicados_que_siguen(): void
    {
        $mensual = $this->plan('Mensual', 89000, 1, self::BENEFICIOS);
        $this->publishVoice(evidence: $this->voiceCatalogEvidence());
        // Se quita uno («Zona de pesas…») y se añaden dos: uno nuevo y otro que es el mismo con otra puntuación.
        $mensual->forceFill(['benefits' => json_encode(['Acceso ilimitado al gimnasio', 'Sauna', 'Valoración inicial', 'Valoración inicial.'])])->save();
        $lineas = $this->captureToolLines();

        $cuerpo = $this->consultarPlanes();
        $this->assertSame(['Acceso ilimitado al gimnasio', 'Valoración inicial'], $cuerpo['planes'][0]['beneficios'], 'los publicados que siguen, en el orden de ahora');
        $this->assertStringNotContainsString('Sauna', (string) json_encode($cuerpo, JSON_UNESCAPED_UNICODE), 'ni el añadido');
        $this->assertStringNotContainsString('Zona de pesas', (string) json_encode($cuerpo, JSON_UNESCAPED_UNICODE), 'ni el quitado');
        $this->assertSame(VoiceTools::BENEFITS_NOTE, $cuerpo['nota']);
        $this->assertSame(['benefitsUnpublished' => 2], VoiceCallEvent::query()->where('kind', 'tool_call')->sole()->codes);
        $this->assertSame(2, $lineas()[0]['benefits_unpublished'] ?? null);
    }

    public function test_un_plan_puesto_a_la_venta_despues_de_publicar_no_se_entrega(): void
    {
        $this->plan('Mensual', 89000, 1, self::BENEFICIOS);
        $fuera = $this->plan('Anual', 900000, 2, self::BENEFICIOS, ['sellable' => false]);
        $this->publishVoice(evidence: $this->voiceCatalogEvidence());
        $fuera->forceFill(['sellable' => true])->save();
        $this->plan('Semestral', 480000, 3, self::BENEFICIOS);

        $this->assertSame(['Mensual'], array_column($this->consultarPlanes()['planes'], 'nombre'));
        $this->assertSame(['plansUnpublished' => 2], VoiceCallEvent::query()->where('kind', 'tool_call')->sole()->codes);
    }

    public function test_sin_catalogo_publicado_ningun_plan_y_la_nota_de_siempre(): void
    {
        $this->plan('Mensual', 89000, 1, self::BENEFICIOS);
        $this->plan('Trimestral', 239900, 2, self::BENEFICIOS);
        // Una publicación anterior a PT-14: su `evidence` no lleva `catalog`.
        $this->publishVoice(evidence: ['number' => ['action' => 'assigned', 'outcome' => 'assigned']]);

        $this->assertSame(['planes' => [], 'nota' => VoiceTools::NO_DELIVERABLE_PLANS_NOTE], $this->consultarPlanes());
        $this->assertSame(['plansUnpublished' => 2], VoiceCallEvent::query()->where('kind', 'tool_call')->sole()->codes);
        $this->assertNull(VoicePublishedConfig::read()['canary']->commercialCatalog);
        $this->assertTrue(VoicePublishedConfig::read()['canary']->ok(), 'la parte sigue vigente: solo le falta el catálogo');
    }

    /** @return array<string, array{0: string}> */
    public static function catalogosManipulados(): array
    {
        return [
            'la huella no cuadra' => ['huella'],
            'un plan con el precio dentro' => ['precio'],
            'veintiún planes' => ['veintiuno'],
            'otro formato' => ['formato'],
            'un beneficio que no es texto' => ['beneficio'],
            'autores que no son los de los planes' => ['autores'],
            'sin autores' => ['sin_autores'],
            // B-1: la forma anterior a las dos autorías (sin `soldBy`) no se acepta: no se sabe quién lo puso a la venta.
            'autores sin quien lo puso a la venta' => ['sin_vendedor'],
            'quien lo puso a la venta no es un id' => ['vendedor_malo'],
        ];
    }

    #[DataProvider('catalogosManipulados')]
    public function test_un_catalogo_manipulado_falla_cerrado(string $caso): void
    {
        $this->plan('Mensual', 89000, 1, self::BENEFICIOS);
        $evidencia = $this->voiceCatalogEvidence();
        $c = &$evidencia['catalog'];
        match ($caso) {
            // Se añade un beneficio a lo aprobado sin rehacer la huella.
            'huella' => $c['plans'][0]['benefits'][] = 'Primer mes sin costo adicional',
            'precio' => $c['plans'][0]['price'] = 89000,
            'veintiuno' => [$c['plans'] = array_map(static fn (int $i): array => ['id' => $i, 'name' => "Plan {$i}", 'benefits' => []], range(1, 21)),
                $c['authors'] = array_map(static fn (int $i): array => ['planId' => $i, 'by' => null, 'soldBy' => null], range(1, 21))],
            'formato' => $c['format'] = 'ultron-voice-catalog/2',
            'beneficio' => $c['plans'][0]['benefits'][0] = ['Acceso ilimitado al gimnasio'],
            'autores' => $c['authors'][0]['planId'] = 999,
            'sin_autores' => $c['authors'] = [],
            'sin_vendedor' => $c['authors'] = array_map(static fn (array $a): array => ['planId' => $a['planId'], 'by' => $a['by']], $c['authors']),
            'vendedor_malo' => $c['authors'][0]['soldBy'] = 'Sara',
        };
        if (in_array($caso, ['precio', 'veintiuno', 'beneficio'], true)) {
            // Con su huella rehecha: lo que falla es la FORMA, no la huella.
            $c['hash'] = hash('sha256', (string) json_encode(array_map(static fn (array $p): array => ['benefits' => $p['benefits'], 'id' => $p['id'], 'name' => $p['name']], $c['plans']),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        unset($c);
        $this->publishVoice(evidence: $evidencia);

        $this->assertNull(VoicePublishedConfig::read()['canary']->commercialCatalog, 'no se sabe qué se aprobó');
        $this->assertSame(['planes' => [], 'nota' => VoiceTools::NO_DELIVERABLE_PLANS_NOTE], $this->consultarPlanes());
        $this->assertSame(['plansUnpublished' => 1], VoiceCallEvent::query()->where('kind', 'tool_call')->sole()->codes);
    }

    public function test_una_llamada_sellada_a_otro_agente_falla_cerrado(): void
    {
        $this->plan('Mensual', 89000, 1, self::BENEFICIOS);
        $this->publishVoice(evidence: $this->voiceCatalogEvidence());                                   // la 1, el agente sellado
        $this->voiceCall('outbound', 'active', ['provider_conversation_id' => self::CONVERSATION, 'provider_call_sid' => 'CA000000000000000000000000a800001f']);
        $this->assertSame(['Mensual'], array_column($this->tool(self::CONVERSATION)['planes'], 'nombre'), 'con su agente, el catálogo de su publicación');

        // La parte canaria ya apunta a otro agente, con su propio catálogo: la llamada sellada a la 1 no sabe qué se aprobó.
        $this->publishVoice('agent_h8fx_canary_0004', evidence: $this->voiceCatalogEvidence());           // la 2
        $this->assertSame(['planes' => [], 'nota' => VoiceTools::NO_DELIVERABLE_PLANS_NOTE], $this->tool(self::CONVERSATION));

        // Una llamada sellada al agente de la 2, sí.
        $this->voiceCall('outbound', 'active', ['provider_conversation_id' => 'conv_h8fx_0034', 'provider_call_sid' => 'CA000000000000000000000000a800004f',
            'voice_publication_seq' => 2, 'provider_agent_id' => 'agent_h8fx_canary_0004', 'remote' => '+573000000034']);
        $this->assertSame(['Mensual'], array_column($this->tool('conv_h8fx_0034')['planes'], 'nombre'));

        // Una vuelta atrás al agente de la 1 (la 3, sin `evidence` propia) devuelve su catálogo: el de la entrada `publish` de ESE agente.
        $this->publishVoice(agent: ['row' => $this->agentRowOf(self::SEALED_AGENT)]);
        $this->assertSame(3, VoicePublishedConfig::read()['canary']->seq);
        $this->assertSame(['Mensual'], array_column($this->tool(self::CONVERSATION)['planes'], 'nombre'));
    }

    public function test_las_guardas_siguen_encima(): void
    {
        // Lo publicado no lava nada: un beneficio publicado que la guarda vigila sigue retenido, y un nombre publicado con código, también.
        $this->plan('Promo 2x1', 51000, 1, ['Acceso ilimitado al gimnasio']);
        $this->plan('Mensual', 89000, 2, ['Acceso ilimitado al gimnasio', 'Primer mes gratis']);
        $this->publishVoice(evidence: $this->voiceCatalogEvidence());

        $cuerpo = $this->consultarPlanes();
        $this->assertSame([['nombre' => 'Mensual', 'condiciones' => '30 días de vigencia', 'precio' => '$ 89.000', 'beneficios' => ['Acceso ilimitado al gimnasio']]], $cuerpo['planes']);
        $this->assertSame(['benefitsWithheld' => 1, 'benefitsOmitted' => 0, 'plansWithheld' => 1], VoiceCallEvent::query()->where('kind', 'tool_call')->sole()->codes);
    }

    // ────────────────────────────────────────────────────────────────────────

    /** @param  list<string>  $beneficios  @param  array<string, mixed>  $o */
    private function plan(string $nombre, int $precio, int $orden, array $beneficios, array $o = []): Plan
    {
        return Plan::query()->create(array_merge(['name' => $nombre, 'price' => $precio, 'duration_days' => 30, 'active' => true, 'sellable' => true,
            'sort_order' => $orden, 'benefits' => json_encode($beneficios)], $o));
    }

    /** @return array<string, mixed> `consultar_planes` en la saliente contestada sellada al agente de la 1, sin `ok` ni `verdict` */
    private function consultarPlanes(): array
    {
        if (! VoiceCall::query()->where('provider_conversation_id', self::CONVERSATION)->exists()) {
            $this->voiceCall('outbound', 'active', ['provider_conversation_id' => self::CONVERSATION, 'provider_call_sid' => 'CA000000000000000000000000a800001f']);
        }

        return $this->tool(self::CONVERSATION);
    }

    /** @return array<string, mixed> */
    private function tool(string $conversacion): array
    {
        $r = $this->rawPost('/api/voice/tools/consultar_planes', '{"conversation_id":"'.$conversacion.'"}', ['Authorization' => 'Bearer '.self::TOOL_SECRET])
            ->assertOk()->assertJson(['ok' => true, 'verdict' => 'ok'])->json();
        unset($r['ok'], $r['verdict']);

        return $r;
    }

    private function agentRowOf(string $providerAgentId): int
    {
        return (int) DB::table('ultron_studio_voice_agents')->where('provider_agent_id', $providerAgentId)->value('id');
    }

    /** @return \Closure(): list<array<string, mixed>> el contexto de las líneas `voice.tool` */
    private function captureToolLines(): \Closure
    {
        $lineas = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$lineas): void {
            if ($m->message === 'voice.tool') {
                $lineas[] = $m->context;
            }
        });

        return function () use (&$lineas): array {
            return $lineas;
        };
    }
}
