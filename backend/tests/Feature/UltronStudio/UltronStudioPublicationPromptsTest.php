<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\UltronStudio\StudioPublication;
use App\Models\UltronStudio\StudioVersion;
use App\Services\Marketing\Ultron\Prompts\UltronPromptOffer;
use App\Services\Marketing\Ultron\UltronPublishedConfig;
use App\Services\UltronStudio\StudioEngineReadings;
use App\Services\UltronStudio\StudioPromptBundle;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use App\Services\UltronStudio\StudioPublications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * E11 y E12 por HTTP (Enmienda M-1 del contrato H5; plan de motores §B.4 y
 * §D): la versión publicada gobierna los prompts de ULTRON en la canaria.
 *
 *  - Con la llave de prompts del motor en `off`, publicar es lo de siempre:
 *    `/1`, `effect = declaration`, los gobernados en `noEffect`.
 *  - Con ella en `canary`: la vista previa enseña el paquete exacto
 *    (`effect = prompts`, la tabla de cambios, la huella), los gobernados no
 *    se reconocen, publicar escribe `/2` con lo que enseñó y el LECTOR REAL del
 *    motor y la OFERTA REAL de `decide` lo leen (escritor → lector); la huella
 *    de la vista previa ata el paquete (cambiar la llave entre la vista previa
 *    y publicar es `preview_stale`); un paquete que no compila es 409
 *    `prompts_compile_failed` y no escribe nada; volver a Legacy y revertir a
 *    una cadena sin partes son `/1`; revertir y promover solo mantienen `/2`
 *    si todas las partes ya lo eran.
 *  - `status.engine.prompts`: su forma, la oferta de ahora igual a la de
 *    `decide` en cada estado de la cadena, solo turnos de la canaria (≤ 20),
 *    sin ids ni textos, y 401/403 como el resto de `status`.
 */
class UltronStudioPublicationPromptsTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const G8_HASH = '702890f7d86a27fef027abd672a99903e64b6661c2d8f8a72a45ddea3d6d311c';

    private const LEGACY_HASH = '3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902';

    private Admin $ana;

    private Admin $root;

    private Admin $pub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->importBaseline();
        $this->openKeys();
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $this->pub = $this->personWith(['ultron.view', 'ultron.publish', 'ultron.rollback'], 'Pablo Publica');
    }

    private function promptsOn(): void
    {
        config(['marketing.ultron.studio_prompts' => 'canary']);
    }

    /** La versión del cambio de aceptación (G8): tono profesional y 200 caracteres. */
    private function g8(string $label = 'Profesional y corto'): StudioVersion
    {
        return $this->approvedVersion($this->ana, $this->root, $label, ['persona' => ['tone' => 'profesional', 'maxChars' => 200]]);
    }

    /** @return array{contract:string,canary:array<string,mixed>|null,rest:array<string,mixed>|null} */
    private function headEntry(): array
    {
        $c = $this->chainHead();

        return D::entry((string) $c->effective_json, (string) $c->uuid, (int) $c->seq) + ['column' => $c->engine_contract];
    }

    /** La oferta real de `decide` para la canaria, sin los textos. */
    private function decideOffer(): array
    {
        $r = app(UltronPromptOffer::class)->forConversation(self::CANARY_ID)['response'];

        return ['mode' => $r['mode'], 'version' => $r['version'], 'bundleHash' => $r['bundle_hash'], 'reason' => $r['reason']];
    }

    private function statusPrompts(?Admin $as = null): array
    {
        return $this->studio('GET', 'status', $as ?? $this->pub)->assertOk()->json('data.engine.prompts');
    }

    // ── Con la llave en `off`, lo de siempre ────────────────────────────────

    public function test_con_la_llave_apagada_publicar_es_lo_de_siempre(): void
    {
        $v = $this->g8();

        $vista = $this->previewOf($this->pub, $v->uuid);
        $this->assertSame('declaration', $vista['effect']);
        $this->assertNull($vista['prompts']);
        $this->assertSame(['persona.tone', 'persona.maxChars'], $vista['noEffect']);
        $this->assertSame([], array_values(array_filter($vista['params'], static fn (array $p): bool => $p['class'] === 'prompt')));
        $this->assertSame(['readsVersions', 'activeVersion', 'message', 'publication', 'prompts'], array_keys($this->studio('GET', 'status', $this->pub)->json('data.engine')));

        $this->publishAs($this->pub, $v->uuid)->assertCreated();

        $cabeza = $this->headEntry();
        $this->assertSame(D::CONTRACT, $cabeza['column']);
        $this->assertSame(D::CONTRACT, $cabeza['contract']);
        $this->assertFalse(D::isPartV2($cabeza['canary']));
        $this->assertSame('applied', $this->engineStamp(self::CANARY_ID)['state']);
        $this->assertSame(['mode' => 'legacy', 'version' => null, 'bundleHash' => null, 'reason' => 'prompts_off'], $this->statusPrompts()['current']);
        $this->assertSame($this->decideOffer(), $this->statusPrompts()['current']);
        $this->assertSame(StudioPublications::ENGINE_MESSAGES['canary'], $this->studio('GET', 'status', $this->pub)->json('data.engine.message'));
    }

    // ── Con la llave encendida: vista previa → /2 → lector y oferta reales ──

    public function test_con_la_llave_encendida_se_publica_el_paquete_que_enseno_la_vista_previa_y_el_motor_lo_ofrece(): void
    {
        $this->promptsOn();
        $v = $this->g8();

        $vista = $this->previewOf($this->pub, $v->uuid);
        $this->assertTrue($vista['publishable'], json_encode($vista['blocking']));
        $this->assertSame('prompts', $vista['effect']);
        $this->assertSame([], $vista['noEffect'], 'los gobernados no se reconocen');
        $this->assertSame(
            [['layer' => 'persona', 'key' => 'tone', 'class' => 'prompt', 'versionValue' => 'profesional', 'engineValue' => null, 'ok' => true],
                ['layer' => 'persona', 'key' => 'maxChars', 'class' => 'prompt', 'versionValue' => 200, 'engineValue' => null, 'ok' => true]],
            array_values(array_filter($vista['params'], static fn (array $p): bool => $p['class'] === 'prompt')),
        );
        $p = $vista['prompts'];
        $this->assertSame(['key', 'base', 'compiler', 'legacyBundleHash', 'bundleHash', 'shortHash', 'baseline', 'bytes', 'changes', 'carried', 'error', 'phrases'], array_keys($p));
        $this->assertSame(['canary', 'n8n-29de5119', 2, self::LEGACY_HASH, self::G8_HASH, '702890f7', false, 113672], [$p['key'], $p['base'], $p['compiler'], $p['legacyBundleHash'], $p['bundleHash'], $p['shortHash'], $p['baseline'], $p['bytes']]);
        $this->assertSame(['list' => [], 'rules' => [], 'max' => 12, 'problems' => []], $p['phrases'], 'sin reglas S-NNN no entra ninguna frase');
        $this->assertSame(['persona.tone', 'persona.maxChars'], array_column($p['changes'], 'param'));
        $this->assertStringStartsWith('REGISTRO PUBLICADO: profesional.', $p['changes'][0]['patches'][0]['text']);
        $this->assertNull($p['carried']);
        $this->assertNull($p['error']);

        $this->publishAs($this->pub, $v->uuid)->assertCreated();

        // Lo firmado: `/2`, la parte con los gobernados y la huella que se vio.
        $cabeza = $this->headEntry();
        $this->assertSame(D::CONTRACT_V2, $cabeza['column']);
        $this->assertSame(D::CONTRACT_V2, $cabeza['contract']);
        $this->assertSame(['persona.emojis' => true, 'persona.maxChars' => 200, 'persona.tone' => 'profesional', 'rules.forbiddenPhrases' => [], 'strategy.goal' => 'cita'], $cabeza['canary']['governed']);
        $this->assertSame(['base' => 'n8n-29de5119', 'bundleHash' => self::G8_HASH, 'compiler' => 2], $cabeza['canary']['prompts']);
        $this->assertNull($cabeza['rest']);
        $this->assertSame(['nuevo', $vista['previewHash']], ['nuevo', StudioPublication::query()->sole()->acknowledged['previewHash']]);

        // Escritor → lector: el sello del commit no cambia de forma, y la oferta
        // de `decide` es exactamente la que enseña el Studio.
        $sello = $this->engineStamp(self::CANARY_ID);
        $this->assertSame(['applied', $v->uuid], [$sello['state'], $sello['version']]);
        $parte = app(UltronPublishedConfig::class)->partFor(self::CANARY_ID);
        $this->assertSame([D::CONTRACT_V2, self::G8_HASH], [$parte['contract'], $parte['prompts']['bundleHash']]);
        $this->assertSame(['mode' => 'studio', 'version' => $v->uuid, 'bundleHash' => self::G8_HASH, 'reason' => null], $this->decideOffer());
        $this->assertSame($this->decideOffer(), $this->statusPrompts()['current']);
        $this->assertSame(StudioPublications::ENGINE_MESSAGES_PROMPTS['canary'], $this->studio('GET', 'status', $this->pub)->json('data.engine.message'));

        // Revertir el canario sin resto: la cadena no declara nada → `/1` (R10).
        $this->rollbackAs($this->pub, $v->uuid)->assertCreated();
        $this->assertSame([D::CONTRACT, null, null], [$this->headEntry()['column'], $this->headEntry()['canary'], $this->headEntry()['rest']]);
        $this->assertSame(['mode' => 'legacy', 'version' => null, 'bundleHash' => null, 'reason' => 'part_legacy'], $this->decideOffer());
        $this->assertSame($this->decideOffer(), $this->statusPrompts()['current']);
        $this->assertChainMatchesStates();
    }

    public function test_cambiar_la_llave_entre_la_vista_previa_y_publicar_es_preview_stale(): void
    {
        $v = $this->g8();
        $apagada = $this->previewOf($this->pub, $v->uuid);
        $this->promptsOn();
        $encendida = $this->previewOf($this->pub, $v->uuid);
        $this->assertNotSame($apagada['previewHash'], $encendida['previewHash']);

        // La huella vista con la llave apagada ya no publica (y la de ahora sí).
        $this->studio('POST', "versions/{$v->uuid}/publish", $this->pub, [
            'clientRequestId' => (string) Str::uuid(),
            'previewHash' => $apagada['previewHash'],
            'acknowledgedNoEffect' => [],
        ])->assertStatus(409)->assertJsonPath('code', 'preview_stale');
        $this->assertNull($this->chainHead());

        // Reconocer los gobernados con la llave encendida no vale: ya no son `no_effect`.
        $this->studio('POST', "versions/{$v->uuid}/publish", $this->pub, [
            'clientRequestId' => (string) Str::uuid(),
            'previewHash' => $encendida['previewHash'],
            'acknowledgedNoEffect' => ['persona.tone', 'persona.maxChars'],
        ])->assertStatus(409)->assertJsonPath('code', 'no_effect_not_acknowledged');

        $this->publishAs($this->pub, $v->uuid)->assertCreated();
        $this->assertSame(D::CONTRACT_V2, $this->chainHead()->engine_contract);
    }

    public function test_un_paquete_que_no_compila_no_se_firma(): void
    {
        // El normalizador admite 330 (entre 160 y 480) aunque el paso del catálogo
        // sea 20; el compilador no: con la llave encendida, no se publica.
        $v = $this->approvedVersion($this->ana, $this->root, 'Fuera de paso', ['persona' => ['maxChars' => 330]]);
        $this->promptsOn();

        $vista = $this->previewOf($this->pub, $v->uuid);
        $this->assertFalse($vista['publishable']);
        $this->assertSame(['prompts_compile_failed'], $vista['blocking']);
        $this->assertSame(['bundleHash' => null, 'changes' => [], 'error' => 'prompts_compile_failed'], array_intersect_key($vista['prompts'], array_flip(['error', 'bundleHash', 'changes'])));

        $this->publishAs($this->pub, $v->uuid)->assertStatus(409)->assertJsonPath('code', 'prompts_compile_failed');
        // Es una puerta, en su sitio (tras el laboratorio, antes de los pasos 8
        // y 9): con una huella vieja, sigue diciendo que no compila.
        $this->publishAs($this->pub, $v->uuid, null, ['previewHash' => str_repeat('f', 64)])->assertStatus(409)->assertJsonPath('code', 'prompts_compile_failed');
        $this->assertNull($this->chainHead());
        $this->assertSame('APPROVED', $v->fresh()->status);

        // Con la llave apagada se publica como siempre: es solo una declaración.
        config(['marketing.ultron.studio_prompts' => 'off']);
        $this->publishAs($this->pub, $v->uuid)->assertCreated();
        $this->assertSame(D::CONTRACT, $this->chainHead()->engine_contract);
    }

    public function test_la_parte_del_resto_que_no_compila_tampoco_se_firma(): void
    {
        // Una cabeza `/1` cuyo resto nombra una versión que no existe: se puede
        // declarar (con la llave apagada se arrastra tal cual), pero no compilar.
        $fantasma = D::part(strtolower((string) Str::uuid()), str_repeat('d', 64), '2026-10-19.1', 1, 1, ['governance.canaryOnly' => true, 'governance.enabled' => true, 'intent.driver' => 'entorno', 'rules.paymentLinksEnabled' => false]);
        $this->seedEntry(D::ACTION_LEGACY, null, null, $fantasma);
        $v = $this->g8();
        $this->promptsOn();

        $vista = $this->previewOf($this->pub, $v->uuid);
        $this->assertSame(['prompts_compile_failed'], $vista['blocking']);
        $this->assertSame(self::G8_HASH, $vista['prompts']['bundleHash'], 'la parte nueva sí compila');
        $this->assertNull($vista['prompts']['carried']);
        $this->publishAs($this->pub, $v->uuid)->assertStatus(409)->assertJsonPath('code', 'prompts_compile_failed');
        $this->assertSame(1, StudioPublication::query()->count());
    }

    public function test_la_parte_que_se_arrastra_se_ensena_y_revertir_la_conserva_en_v2(): void
    {
        // V0 PUBLISHED en el resto, promovida antes de la Enmienda M-1 (`/1`),
        // con tono entusiasta: se siembra la cabeza que dejó su promoción.
        $v0 = $this->seededVersion($this->ana, $this->root, $this->contentWith(['persona' => ['tone' => 'entusiasta']]), 'PUBLISHED');
        $this->seedEntry(D::ACTION_PROMOTE, $v0, $this->partOf($v0), $this->partOf($v0));
        $this->assertSame(D::CONTRACT, $this->chainHead()->engine_contract);

        $this->promptsOn();
        $entusiasta = StudioPromptBundle::compile(StudioPromptBundle::governedFrom($v0->content))['prompts']['bundleHash'];
        $v1 = $this->g8();
        $vista = $this->previewOf($this->pub, $v1->uuid);
        $this->assertTrue($vista['publishable'], json_encode($vista['blocking']));
        // Vuelta 2 de las reglas S-NNN: `phrases` siempre (aquí V0 no trae reglas que aporten frases: `[]`).
        $this->assertSame(['versionUuid' => $v0->uuid, 'label' => $v0->label, 'bundleHash' => $entusiasta, 'shortHash' => substr($entusiasta, 0, 8), 'baseline' => false, 'phrases' => []], $vista['prompts']['carried']);

        $this->publishAs($this->pub, $v1->uuid)->assertCreated();
        $cabeza = $this->headEntry();
        $this->assertSame(D::CONTRACT_V2, $cabeza['column']);
        $this->assertSame([$v1->uuid, self::G8_HASH], [$cabeza['canary']['version'], $cabeza['canary']['prompts']['bundleHash']]);
        $this->assertSame([$v0->uuid, $entusiasta], [$cabeza['rest']['version'], $cabeza['rest']['prompts']['bundleHash']]);
        $this->assertSame('entusiasta', $cabeza['rest']['governed']['persona.tone']);

        // Revertir V1: las dos partes ya eran `/2` → la entrada sigue en `/2` y
        // la canaria recibe lo que la vista previa enseñó como arrastrado.
        $this->rollbackAs($this->pub, $v1->uuid)->assertCreated();
        $cabeza = $this->headEntry();
        $this->assertSame(D::CONTRACT_V2, $cabeza['column']);
        $this->assertSame($cabeza['canary'], $cabeza['rest']);
        $this->assertSame(['mode' => 'studio', 'version' => $v0->uuid, 'bundleHash' => $entusiasta, 'reason' => null], $this->decideOffer());
        $this->assertSame($this->decideOffer(), $this->statusPrompts()['current']);

        // Con la llave apagada, volver a Legacy: `/1`, siempre.
        config(['marketing.ultron.studio_prompts' => 'off']);
        $this->legacyAs($this->pub)->assertCreated();
        $this->assertSame([D::CONTRACT, null, null], [$this->headEntry()['column'], $this->headEntry()['canary'], $this->headEntry()['rest']]);
    }

    public function test_revertir_con_la_llave_encendida_sobre_una_cabeza_v1_escribe_v1(): void
    {
        // Publicada con la llave apagada: nadie vio su paquete.
        $v = $this->g8();
        $this->publishAs($this->pub, $v->uuid)->assertCreated();
        // La línea base, explícita (sin configs, la API reutiliza el borrador de Ana).
        $otra = $this->approvedVersion($this->ana, $this->root, 'Base', ['persona' => ['tone' => 'cercano', 'maxChars' => 320]]);
        $this->promptsOn();

        $this->rollbackAs($this->pub, $v->uuid)->assertCreated();
        $this->assertSame(D::CONTRACT, $this->chainHead()->engine_contract);
        $this->assertSame('part_legacy', $this->decideOffer()['reason']);

        // Y la siguiente publicación (con la llave encendida) ya es `/2`.
        $this->publishAs($this->pub, $otra->uuid)->assertCreated();
        $cabeza = $this->headEntry();
        $this->assertSame(D::CONTRACT_V2, $cabeza['column']);
        $this->assertSame(self::LEGACY_HASH, $cabeza['canary']['prompts']['bundleHash'], 'la línea base publica el paquete Legacy (etapa E3)');
        $this->assertSame(['mode' => 'studio', 'version' => $otra->uuid, 'bundleHash' => self::LEGACY_HASH, 'reason' => null], $this->decideOffer());
    }

    public function test_la_llave_de_prompts_del_studio_se_lee_como_la_lee_decide(): void
    {
        foreach ([null, 'canary', ' CANARY ', 'Canary', 'all', 'off', '', 'canario', 1, true, ['canary']] as $valor) {
            config(['marketing.ultron.studio_prompts' => $valor]);
            $this->assertSame(UltronPromptOffer::key(), StudioEngineReadings::promptsKey(), json_encode($valor));
        }
    }

    // ── E12: status.engine.prompts ──────────────────────────────────────────

    public function test_la_oferta_de_status_es_la_de_decide_en_cada_estado(): void
    {
        $comparar = function (string $donde): void {
            $this->assertSame($this->decideOffer(), $this->statusPrompts()['current'], $donde);
        };

        $comparar('llave apagada, sin cadena');
        $this->promptsOn();
        $comparar('sin publicaciones');
        $this->assertSame('no_publication', $this->decideOffer()['reason']);

        $v = $this->g8();
        $this->publishAs($this->pub, $v->uuid)->assertCreated();
        $comparar('V2 en canario');
        $this->assertSame('studio', $this->decideOffer()['mode']);

        config(['marketing.ultron.studio_config' => 'off']);
        $comparar('sello apagado');
        $this->assertSame('studio_config_off', $this->decideOffer()['reason']);
        config(['marketing.ultron.studio_config' => 'canary', 'marketing.ultron.canary_conversation_id' => null]);
        $comparar('sin canario');
        $this->assertSame('out_of_scope', $this->decideOffer()['reason']);
        config(['marketing.ultron.canary_conversation_id' => self::CANARY_ID]);

        // Una cabeza firmada con otra clave (la cadena es de solo inserción: se
        // siembra encima): el mismo motivo por los dos lados.
        $this->seedEntry(D::ACTION_LEGACY, null, null, null, ['signature' => str_repeat('0', 64)]);
        $comparar('firma alterada');
        $this->assertSame('publication_signature_invalid', $this->decideOffer()['reason']);
    }

    public function test_los_ultimos_turnos_son_solo_de_la_canaria_y_no_llevan_ids_ni_textos(): void
    {
        $this->promptsOn();
        $otra = MarketingConversation::query()->forceCreate([
            'id' => 123456789, 'lead_id' => MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3001112233', 'name' => 'Otra'])->id,
            'channel' => 'whatsapp', 'status' => 'open',
        ]);
        $sello = static fn (string $estado, string $marca): array => ['studio_prompts' => [
            'v' => 1, 'state' => $estado,
            'offered' => ['mode' => 'studio', 'reason' => null, 'version' => 'b0000000-0000-4000-8000-00000000000b', 'contentHash' => str_repeat('c', 64), 'entry' => (string) Str::uuid(), 'seq' => 7, 'bundleHash' => self::G8_HASH],
            'observed' => ['mode' => 'studio', 'bundleHash' => self::G8_HASH, 'reason' => 'TEXTO LIBRE '.$marca, 'flowRev' => 'S1', 'execution' => 'EJEC-'.$marca],
        ]];

        $this->stampTurns(3, null, now()->subMinutes(30), ['state' => 'legacy', 'version' => null], ['text_marker' => 'no-sale']);
        $this->stampTurns(25, 'b0000000-0000-4000-8000-00000000000b', now()->subMinutes(10), [], $sello('applied', 'QK1'));
        $this->stampTurns(2, null, now(), null, $sello('mismatch', 'QK2'), conversationId: $otra->id);
        $this->stampTurns(2, null, now(), null, $sello('applied', 'QK3'), 'outbound_followup');

        $r = $this->studio('GET', 'status', $this->pub)->assertOk();
        $turnos = $r->json('data.engine.prompts.lastTurns');
        $this->assertCount(StudioPublications::PROMPT_TURNS, $turnos, 'como mucho 20, los más recientes');
        foreach ($turnos as $t) {
            $this->assertSame(['at', 'declared', 'offered', 'observed', 'state'], array_keys($t));
            $this->assertSame('applied', $t['state']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}Z$/', $t['at']);
            $this->assertSame(['state' => 'applied', 'version8' => 'b0000000'], $t['declared']);
            $this->assertSame(['mode' => 'studio', 'reason' => null, 'version8' => 'b0000000', 'bundle8' => '702890f7'], $t['offered']);
            $this->assertSame(['mode' => 'studio', 'bundle8' => '702890f7', 'flowRev' => 'S1'], $t['observed']);
        }

        // Nada de ids ni textos: ni la conversación, ni la ejecución, ni el
        // motivo libre, ni la otra conversación, ni el marcador.
        $cuerpo = $r->getContent();
        foreach (['987654321', '123456789', 'TEXTO LIBRE', 'EJEC-', 'no-sale', 'QK1', 'QK2', 'QK3', str_repeat('c', 64)] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $cuerpo, $prohibido);
        }

        // Sin canario configurado, ningún turno.
        config(['marketing.ultron.canary_conversation_id' => null]);
        $this->assertSame([], $this->statusPrompts()['lastTurns']);
    }

    public function test_status_con_la_traza_pide_sesion_y_ver_el_studio(): void
    {
        $this->json('GET', '/api/admin/ultron-studio/status')->assertStatus(401);
        $sinPermiso = $this->personWith(['marketing.view'], 'Sin Studio');
        $this->studio('GET', 'status', $sinPermiso)->assertStatus(403);
        $this->assertArrayHasKey('prompts', $this->studio('GET', 'status', $this->personWith(['ultron.view'], 'Solo ver'))->assertOk()->json('data.engine'));
    }
}
