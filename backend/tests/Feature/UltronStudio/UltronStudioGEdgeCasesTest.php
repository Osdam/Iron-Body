<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioRule;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioRuleCoherence;
use App\Services\UltronStudio\StudioRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Casos límite de las condiciones del Hito G por la API real (contrato G §A3 y
 * Enmienda 1), escritos por el tester:
 *
 *  - el tamaño EXACTO: 4096 bytes pasan y 4097 dan un 422 solo en `conditions`;
 *  - los 55 casos `canonicalize` de `coherence-cases.json` pasados por HTTP dan
 *    lo mismo que el núcleo (el borde lee las condiciones en crudo: los
 *    middleware no recortan ni convierten nada, también con emojis y `ñ`);
 *  - dos `when` sobre variables DISTINTAS no evitan la contradicción
 *    (algoritmo por pares al pie de la letra);
 *  - una archivada no cuenta tampoco al editar, y quitar las condiciones
 *    (`null`) a una regla deshace la contradicción en el análisis de la otra;
 *  - una regla del código nunca recibe contradicciones;
 *  - con el tope de 100 lleno, el tope gana a la contradicción (orden de
 *    comprobación del backend: tope → contradicción → inserción).
 */
class UltronStudioGEdgeCasesTest extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

    private Admin $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importBaseline();
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
    }

    public function test_condiciones_de_4096_bytes_exactos_pasan_y_de_4097_dan_422_solo_en_conditions(): void
    {
        [$justas, $una] = self::textConditionsAroundTheLimit();
        $this->assertSame(StudioRuleCoherence::MAX_BYTES, strlen(json_encode($justas, self::JSON_FLAGS)), 'la prueba arma 4096 bytes');
        $this->assertSame(StudioRuleCoherence::MAX_BYTES + 1, strlen(json_encode($una, self::JSON_FLAGS)), 'y 4097 con un byte más');

        $this->newStudioRule($this->ana, ['conditions' => $justas])->assertCreated()
            ->assertJsonPath('data.conditions', $justas)
            ->assertJsonPath('data.analysis.verification', 'lab');

        $r = $this->newStudioRule($this->ana, ['conditions' => $una])->assertStatus(422);
        $this->assertSame(['conditions'], array_keys($r->json('errors')), $r->getContent());
        $this->assertSame(1, StudioRule::query()->where('source', 'studio')->count());
    }

    public function test_los_casos_canonicalize_del_fixture_dan_por_http_lo_mismo_que_el_nucleo(): void
    {
        // 55 creaciones y archivos seguidos: el cupo de 30/min no es lo que se prueba.
        $this->withoutMiddleware(ThrottleRequests::class);
        $fixture = json_decode((string) file_get_contents(base_path('tests/fixtures/ultron-studio/coherence-cases.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(55, $fixture['canonicalize']);

        foreach ($fixture['canonicalize'] as $caso) {
            $r = $this->newStudioRule($this->ana, ['conditions' => $caso['input']['conditions'], 'layers' => $caso['input']['layers']]);
            $esperado = $caso['expected'];

            if ($esperado['errorKeys'] === []) {
                $r->assertCreated();
                $this->assertSame($esperado['conditions'], $r->json('data.conditions'), $caso['name']);
                $this->assertSame($esperado['conditions'], StudioRule::query()->where('uuid', $r->json('data.uuid'))->sole()->conditions, $caso['name'].' (en la base)');
                // Cada caso por su cuenta: archivada, no choca con los siguientes.
                $this->studio('POST', 'rules/'.$r->json('data.uuid').'/archive', $this->ana, ['baseRevision' => 1, 'reason' => 'Caso del fixture'])->assertOk();

                continue;
            }

            $this->assertSame(422, $r->status(), $caso['name'].': '.$r->getContent());
            $claves = array_keys($r->json('errors'));
            sort($claves, SORT_STRING);
            $this->assertSame($esperado['errorKeys'], $claves, $caso['name'].': '.$r->getContent());
        }
    }

    public function test_dos_when_sobre_variables_distintas_no_evitan_la_contradiccion(): void
    {
        $s001 = $this->newStudioRule($this->ana, [
            'layers' => ['persona', 'strategy'],
            'conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)], [['target' => 'config', 'layer' => 'strategy', 'key' => 'goal', 'op' => 'eq', 'value' => 'cita']]),
        ])->assertCreated()->json('data');

        // El «si» de la nueva habla del tono; el de S-001, del objetivo: no hay
        // variable común que lo evite, así que se contradicen (Enmienda 1, punto 9).
        $this->newStudioRule($this->ana, [
            'conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'gte', 320)], [['target' => 'config', 'layer' => 'persona', 'key' => 'tone', 'op' => 'eq', 'value' => 'cercano']]),
        ])->assertStatus(409)
            ->assertJsonPath('code', 'rule_contradiction')
            ->assertJsonPath('conflicts', [['code' => 'S-001', 'uuid' => $s001['uuid'], 'revision' => 1, 'target' => 'config:persona.maxChars', 'label' => 'Longitud máxima']]);

        // Con la MISMA variable en los dos «si» e incompatibles, ya no.
        $this->newStudioRule($this->ana, [
            'layers' => ['persona', 'strategy'],
            'conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'gte', 320)], [
                ['target' => 'config', 'layer' => 'persona', 'key' => 'tone', 'op' => 'eq', 'value' => 'cercano'],
                ['target' => 'config', 'layer' => 'strategy', 'key' => 'goal', 'op' => 'eq', 'value' => 'venta'],
            ]),
        ])->assertCreated();
    }

    public function test_al_editar_una_archivada_no_cuenta_y_quitar_condiciones_deshace_la_contradiccion(): void
    {
        $s001 = $this->newStudioRule($this->ana, ['conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)])])->assertCreated()->json('data');
        $s002 = $this->newStudioRule($this->ana, ['conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 400)])])->assertCreated()->json('data');

        // Mientras S-001 viva, S-002 no puede pasar a «≥ 320».
        $hacia = ['baseRevision' => 1, 'conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'gte', 320)])];
        $this->studio('PATCH', "rules/{$s002['uuid']}", $this->ana, $hacia)->assertStatus(409)->assertJsonPath('conflicts.0.code', 'S-001');
        // Archivada S-001, sí.
        $this->studio('POST', "rules/{$s001['uuid']}/archive", $this->ana, ['baseRevision' => 1, 'reason' => 'La sustituye S-002'])->assertOk();
        $this->studio('PATCH', "rules/{$s002['uuid']}", $this->ana, $hacia)->assertOk()->assertJsonPath('data.revision', 2);

        // Dos vivas que se contradicen (solo por la tabla: la API ya no lo deja)…
        $this->seedLiveRule('S-003', self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)]));
        $antes = collect($this->studio('GET', 'rules', $this->ana)->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(['S-003'], $antes['S-002']['analysis']['issues'][0]['with']);
        // …y quitarle las condiciones a una deja limpia a la otra.
        $this->studio('PATCH', "rules/{$s002['uuid']}", $this->ana, ['baseRevision' => 2, 'conditions' => null])->assertOk()
            ->assertJsonPath('data.analysis', ['verification' => 'none', 'issues' => []]);
        $despues = collect($this->studio('GET', 'rules', $this->ana)->assertOk()->json('data'))->keyBy('code');
        $this->assertSame([], $despues['S-003']['analysis']['issues']);
    }

    public function test_una_regla_del_codigo_nunca_recibe_contradicciones(): void
    {
        // La API no deja proponer «Puede ofrecer descuentos = sí»: es un 422 por el control, nunca un 409 contra R-02.
        $r = $this->newStudioRule($this->ana, ['layers' => ['strategy'], 'conditions' => self::conditionsOf([self::requireEq('strategy', 'discountsAllowed', true)])])->assertStatus(422);
        $this->assertNull($r->json('conflicts'));
        $this->assertSame(['conditions.then.0'], array_keys($r->json('errors')));

        // Escrita a mano en la tabla: la S-NNN lleva el issue; R-02, ninguno.
        $this->seedLiveRule('S-001', self::conditionsOf([self::requireEq('strategy', 'discountsAllowed', true)]), ['strategy']);
        $reglas = collect($this->studio('GET', 'rules', $this->ana)->assertOk()->json('data'))->keyBy('code');
        $this->assertSame([['id' => 'regla-contradice-control', 'level' => 'blocking', 'with' => ['control:descuentos', 'R-02'], 'target' => 'config:strategy.discountsAllowed']], $reglas['S-001']['analysis']['issues']);
        foreach ($reglas->where('source', 'legacy_baseline') as $codigo => $regla) {
            $this->assertSame([], $regla['analysis']['issues'], $codigo);
        }

        // Y una candidata que repite lo de R-02 (descuentos = no) choca con la S-NNN, no con R-02.
        $this->newStudioRule($this->ana, ['layers' => ['strategy'], 'conditions' => self::conditionsOf([self::requireEq('strategy', 'discountsAllowed', false)])])
            ->assertStatus(409)
            ->assertJsonPath('conflicts', [['code' => 'S-001', 'uuid' => StudioRule::query()->where('code', 'S-001')->value('uuid'), 'revision' => 1, 'target' => 'config:strategy.discountsAllowed', 'label' => 'Puede ofrecer descuentos']]);
    }

    public function test_con_el_tope_lleno_gana_el_tope_a_la_contradiccion(): void
    {
        for ($i = 1; $i <= StudioRules::MAX_LIVE_STUDIO_RULES; $i++) {
            $this->seedLiveRule(sprintf('S-%03d', $i), self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)]));
        }
        $eventos = StudioAuditEvent::query()->count();

        // Contradice a las cien y además no cabe: 409 del tope.
        $this->newStudioRule($this->ana, ['conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'gte', 320)])])
            ->assertStatus(409)->assertJsonPath('code', 'rule_limit_reached');
        $this->assertSame(StudioRules::MAX_LIVE_STUDIO_RULES, StudioRule::query()->where('source', 'studio')->count());
        $this->assertSame($eventos, StudioAuditEvent::query()->count());
    }

    /**
     * Unas condiciones de solo texto que ocupan EXACTAMENTE 4096 bytes en el
     * JSON compacto sin escapar (como mide el contrato) y su gemela de 4097.
     * Frases distintas, en minúsculas y sin espacios dobles: la forma canónica
     * es la misma que la de entrada.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private static function textConditionsAroundTheLimit(): array
    {
        $armar = static function (array $frases): array {
            $then = [];
            foreach (array_chunk($frases, StudioRuleCoherence::MAX_PHRASES) as $grupo) {
                $then[] = ['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => $grupo];
            }

            return ['when' => [], 'then' => $then];
        };
        $medir = static fn (array $c): int => strlen(json_encode($c, self::JSON_FLAGS));

        // 48 puntos de código y 138 bytes cada una («€» ocupa 3).
        $todas = [];
        for ($i = 0; $i < StudioRuleCoherence::MAX_PHRASES * StudioRuleCoherence::MAX_THEN - 1; $i++) {
            $todas[] = sprintf('%02d ', $i).str_repeat('€', 45);
        }

        // Las n primeras y una frase de relleno («q», k «€» y m «x») que dejen 4096
        // justos y admitan una «x» más (la de relleno no llega a 60).
        for ($n = count($todas); $n >= 1; $n--) {
            $frases = array_slice($todas, 0, $n);
            for ($k = 0; $k <= 45; $k++) {
                for ($m = 1; 1 + $k + $m < StudioRuleCoherence::MAX_PHRASE_LENGTH; $m++) {
                    $relleno = 'q'.str_repeat('€', $k).str_repeat('x', $m);
                    $bytes = $medir($armar([...$frases, $relleno]));
                    if ($bytes === StudioRuleCoherence::MAX_BYTES) {
                        return [$armar([...$frases, $relleno]), $armar([...$frases, $relleno.'x'])];
                    }
                    if ($bytes > StudioRuleCoherence::MAX_BYTES) {
                        break;
                    }
                }
            }
        }

        throw new RuntimeException('No se pudo armar un caso de 4096 bytes exactos.');
    }

    /**
     * Una regla del Studio viva escrita directamente en la tabla, como la
     * dejaría un catálogo que cambió o una escritura a mano.
     *
     * @param  array<string, mixed>  $conditions
     * @param  list<string>  $layers
     */
    private function seedLiveRule(string $code, array $conditions, array $layers = ['persona']): StudioRule
    {
        $campos = ['description' => "Sembrada {$code}", 'type' => 'style', 'priority' => 'normal', 'layers' => $layers,
            'refs' => ['configs' => [], 'edges' => [], 'knowledgeKeys' => [], 'knowledgeCategories' => []], 'origin' => null, 'conditions' => $conditions];

        return StudioRule::query()->forceCreate($campos + [
            'code' => $code, 'source' => 'studio', 'enforcement' => 'none', 'status' => 'draft', 'revision' => 1,
            'content_hash' => StudioContentHasher::ruleHash($campos), 'client_request_id' => (string) Str::uuid(),
        ]);
    }
}
