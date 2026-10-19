<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioRule;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Las condiciones de las reglas del Studio (contrato G §A3, §A6 y §A8; Enmienda
 * 1): se guardan en su forma canónica, lo que no vale o choca con un control
 * del motor es un 422 con todas sus claves, lo que contradice otra regla viva
 * es un 409 `rule_contradiction` con sus conflictos, las del código siguen sin
 * tocarse, las capas cubren los objetivos, la auditoría guarda las condiciones
 * y `GET rules` trae el análisis.
 *
 * La semántica fina (cada operador, cada algoritmo) la fija la paridad contra
 * `coherence-cases.json`; aquí se prueba que la API la aplica.
 */
class UltronStudioRuleConditionsTest extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    private Admin $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importBaseline();
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
    }

    /**
     * @param  mixed  $conditions  lo que viaja tal cual (también lo que no es un objeto)
     * @param  list<string>  $layers
     * @param  array<string, mixed>  $extra
     */
    private function ruleWith(mixed $conditions, array $layers = ['persona'], array $extra = []): TestResponse
    {
        return $this->newStudioRule($this->ana, $extra + ['conditions' => $conditions, 'layers' => $layers]);
    }

    private function rulesCount(): int
    {
        return StudioRule::query()->where('source', 'studio')->count();
    }

    public function test_crear_con_condiciones_devuelve_y_guarda_la_forma_canonica(): void
    {
        // Claves en otro orden, opciones repetidas y fuera del orden del
        // catálogo, frases con espacios, mayúsculas y repetidas.
        $llegan = [
            'then' => [
                ['op' => 'in', 'values' => ['profesional', 'cercano', 'profesional'], 'key' => 'tone', 'layer' => 'persona', 'target' => 'config', 'kind' => 'require'],
                ['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => ['  Precio  DE  locura ', 'precio de LOCURA', 'Ñandú']],
            ],
            'when' => [['target' => 'config', 'layer' => 'strategy', 'key' => 'goal', 'op' => 'eq', 'value' => 'venta']],
        ];
        $canonica = [
            'when' => [['target' => 'config', 'layer' => 'strategy', 'key' => 'goal', 'op' => 'eq', 'value' => 'venta']],
            'then' => [
                ['kind' => 'require', 'target' => 'config', 'layer' => 'persona', 'key' => 'tone', 'op' => 'in', 'values' => ['cercano', 'profesional']],
                ['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => ['precio de locura', 'ñandú']],
            ],
        ];

        $r = $this->ruleWith($llegan, ['persona', 'strategy'])->assertCreated();

        $this->assertSame($canonica, $r->json('data.conditions'));
        $this->assertSame(['verification' => 'studio', 'issues' => []], $r->json('data.analysis'));
        $fila = StudioRule::query()->where('code', 'S-001')->sole();
        $this->assertSame($canonica, $fila->conditions);
        // La huella de la regla incluye las condiciones (solo si las tiene).
        $this->assertSame(StudioContentHasher::ruleHash($fila->only([...StudioContentHasher::RULE_FIELDS, 'conditions'])), $fila->content_hash);
        $this->assertNotSame(StudioContentHasher::ruleHash($fila->only(StudioContentHasher::RULE_FIELDS)), $fila->content_hash);
    }

    public function test_las_condiciones_llegan_crudas_al_servidor(): void
    {
        // Los middleware recortarían « cercano» y lo dejarían pasar; el CRM lo
        // rechaza, y el servidor tiene que ver lo mismo que el CRM.
        $this->ruleWith(self::conditionsOf([['kind' => 'require', 'target' => 'config', 'layer' => 'persona', 'key' => 'tone', 'op' => 'eq', 'value' => ' cercano']]))
            ->assertStatus(422)->assertJsonValidationErrors(['conditions.then.0.value']);
        // Y sin condiciones la regla sigue siendo documental.
        $this->newStudioRule($this->ana)->assertCreated()
            ->assertJsonPath('data.conditions', null)
            ->assertJsonPath('data.analysis', ['verification' => 'none', 'issues' => []]);
        $this->assertNull(StudioRule::query()->where('code', 'S-001')->sole()->conditions);
        $this->assertSame(1, $this->rulesCount());
    }

    public function test_editar_condiciones_y_quitarlas(): void
    {
        $uuid = (string) $this->ruleWith(self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)]))->assertCreated()->json('data.uuid');

        $nuevas = self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 280)], [['target' => 'config', 'layer' => 'persona', 'key' => 'emojis', 'op' => 'eq', 'value' => false]]);
        $r = $this->studio('PATCH', "rules/{$uuid}", $this->ana, ['baseRevision' => 1, 'conditions' => $nuevas])->assertOk();
        $r->assertJsonPath('data.revision', 2)->assertJsonPath('data.conditions', $nuevas);

        $evento = StudioAuditEvent::query()->where('event', 'rule.updated')->sole();
        $this->assertSame(['conditions'], array_column($evento->diff['changes'], 'field'));
        $this->assertSame(self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)]), $evento->diff['changes'][0]['from']);
        $this->assertSame($nuevas, $evento->diff['changes'][0]['to']);

        // Las mismas condiciones: nada cambia, ni revisión ni traza.
        $this->studio('PATCH', "rules/{$uuid}", $this->ana, ['baseRevision' => 2, 'conditions' => $nuevas])->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'rule.updated')->count());

        // `null` las quita: la regla vuelve a ser documental y conserva la huella de F.
        $this->studio('PATCH', "rules/{$uuid}", $this->ana, ['baseRevision' => 2, 'conditions' => null])->assertOk()
            ->assertJsonPath('data.revision', 3)
            ->assertJsonPath('data.conditions', null)
            ->assertJsonPath('data.analysis.verification', 'none');
        $fila = StudioRule::query()->where('uuid', $uuid)->sole();
        $this->assertNull($fila->conditions);
        $this->assertNull(DB::table('ultron_studio_rules')->where('uuid', $uuid)->value('conditions'), 'NULL, no el texto «null»');
        $this->assertSame(StudioContentHasher::ruleHash($fila->only(StudioContentHasher::RULE_FIELDS)), $fila->content_hash);
    }

    public function test_la_forma_y_la_semantica_dan_422_con_sus_claves(): void
    {
        $tono = static fn (string $op, mixed $valor): array => ['kind' => 'require', 'target' => 'config', 'layer' => 'persona', 'key' => 'tone', 'op' => $op, 'value' => $valor];
        $casos = [
            // Forma (validada ya en el controlador o en la canonización, con la misma clave).
            'no es un objeto' => ['texto', ['conditions']],
            'clave que sobra' => [['when' => [], 'then' => [self::limitOn('persona', 'maxChars', 'lte', 300)], 'otra' => 1], ['conditions']],
            'falta when' => [['then' => [self::limitOn('persona', 'maxChars', 'lte', 300)]], ['conditions.when']],
            'then vacío' => [['when' => [], 'then' => []], ['conditions.then']],
            'then de más' => [self::conditionsOf(array_fill(0, 4, self::limitOn('persona', 'maxChars', 'lte', 300))), ['conditions.then']],
            'when de más' => [self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)], array_fill(0, 4, ['target' => 'config', 'layer' => 'persona', 'key' => 'emojis', 'op' => 'eq', 'value' => true])), ['conditions.when']],
            'elemento que no es un objeto' => [self::conditionsOf(['texto']), ['conditions.then.0']],
            'objetivo desconocido' => [self::conditionsOf([['kind' => 'require', 'target' => 'regex', 'op' => 'eq', 'value' => '.*']]), ['conditions.then.0.target']],
            'capa desconocida' => [self::conditionsOf([self::limitOn('inventada', 'maxChars', 'lte', 300)]), ['conditions.then.0.layer']],
            'parámetro desconocido' => [self::conditionsOf([self::limitOn('persona', 'noExiste', 'lte', 300)]), ['conditions.then.0.key']],
            'operador que el tipo no admite' => [self::conditionsOf([$tono('lte', 3)]), ['conditions.then.0.op']],
            'opción que no existe' => [self::conditionsOf([$tono('eq', 'gritón')]), ['conditions.then.0.value']],
            'limit fuera de lte/gte' => [self::conditionsOf([['kind' => 'limit', 'target' => 'config', 'layer' => 'persona', 'key' => 'maxChars', 'op' => 'eq', 'value' => 320]]), ['conditions.then.0.kind']],
            'fuera de rango' => [self::conditionsOf([self::limitOn('persona', 'maxChars', 'gte', 500)]), ['conditions.then.0.value']],
            'variable repetida' => [self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300), self::limitOn('persona', 'maxChars', 'gte', 200)]), ['conditions.then.1']],
            'demasiado grande' => [self::conditionsOf([['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => array_map(static fn (int $i): string => str_repeat('ñ', 55).$i, range(1, 10))]], array_fill(0, 3, ['target' => 'config', 'layer' => 'persona', 'key' => 'tone', 'op' => 'in', 'values' => [str_repeat('x', 1500)]])), ['conditions']],
            // Semántica.
            'nunca se aplicaría' => [self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)], [['target' => 'config', 'layer' => 'persona', 'key' => 'tone', 'op' => 'not_in', 'values' => ['cercano', 'profesional', 'entusiasta']]]), ['conditions.when.0']],
            'se contradice con su si' => [self::conditionsOf([self::limitOn('persona', 'maxChars', 'gte', 300)], [['target' => 'config', 'layer' => 'persona', 'key' => 'maxChars', 'op' => 'lte', 'value' => 200]]), ['conditions.then.0']],
        ];

        foreach ($casos as $nombre => [$condiciones, $claves]) {
            $r = $this->ruleWith($condiciones)->assertStatus(422);
            $this->assertSame($claves, array_keys($r->json('errors')), $nombre.': '.$r->getContent());
        }

        // Todas las claves a la vez: `refs` y `conditions` en el mismo 422.
        $r = $this->ruleWith(self::conditionsOf([$tono('eq', 'gritón')]), ['persona'], ['refs' => ['configs' => [['layer' => 'persona', 'key' => 'noExiste']]]])->assertStatus(422);
        $this->assertEqualsCanonicalizing(['refs.configs.0.key', 'conditions.then.0.value'], array_keys($r->json('errors')));

        // Ni una regla, ni una traza: un 422 no cambia nada.
        $this->assertSame(0, $this->rulesCount());
        $this->assertSame(0, StudioAuditEvent::query()->where('event', 'like', 'rule.%')->where('event', '<>', 'rule.legacy_imported')->count());
    }

    public function test_contra_cada_control_del_motor_es_422(): void
    {
        $arista = static fn (string $id, string $op): array => ['kind' => 'require', 'target' => 'edge', 'edge' => $id, 'op' => $op];
        $casos = [
            'hechos' => [self::requireEq('knowledge', 'strictFacts', false), ['knowledge'], 'Contradice un control del motor («Hechos sin verificar»).'],
            'descuentos' => [self::requireEq('strategy', 'discountsAllowed', true), ['strategy'], 'Contradice R-02 (la aplica el código): «Puede ofrecer descuentos» tiene que ser «no».'],
            'critic' => [self::requireEq('composer', 'critic', false), ['composer'], 'Contradice un control del motor («Sin veredicto del Critic»).'],
            'derivacion' => [self::requireEq('rules', 'handoffOnlyCorroborated', false), ['rules'], 'Contradice un control del motor («Derivación sin corroborar»).'],
            'hermes' => [self::requireEq('intent', 'driver', 'hermes'), ['intent'], 'Contradice un control del motor («Hermes está inactivo»).'],
            'canario' => [self::requireEq('governance', 'canaryOnly', false), ['governance'], 'Contradice un control del motor («Tráfico general»).'],
            'cobro-sin-camino' => [$arista('E14', 'absent'), ['rules'], 'Contradice un control del motor («Enlaces de pago sin su camino»).'],
            'arista obligatoria' => [$arista('E10', 'absent'), ['rules'], '«borrador, veredicto y token → commit» es obligatoria en el motor: no se puede exigir que falte.'],
            'frases de R-02' => [['kind' => 'require', 'target' => 'text', 'op' => 'contains', 'phrases' => ['Gratis']], ['persona'], 'Contradice R-02 (la aplica el código): la frase «gratis» no puede aparecer.'],
        ];

        foreach ($casos as $nombre => [$afirmacion, $capas, $mensaje]) {
            $r = $this->ruleWith(self::conditionsOf([$afirmacion]), $capas)->assertStatus(422);
            $this->assertSame(['conditions.then.0'], array_keys($r->json('errors')), $nombre);
            $this->assertContains($mensaje, $r->json('errors')['conditions.then.0'], $nombre.': '.$r->getContent());
        }

        // Proponer el cobro con su camino no choca: es un aviso al validar, no un bloqueo.
        $this->ruleWith(self::conditionsOf([$arista('E14', 'absent')], [['target' => 'config', 'layer' => 'rules', 'key' => 'paymentLinksEnabled', 'op' => 'eq', 'value' => false]]), ['rules'])->assertCreated();
        $this->ruleWith(self::conditionsOf([self::requireEq('rules', 'paymentLinksEnabled', true)]), ['rules'])->assertCreated();
        $this->assertSame(2, $this->rulesCount());
    }

    public function test_contradecir_otra_regla_viva_es_409_con_sus_conflictos(): void
    {
        $objetivo = static fn (string $valor): array => [['target' => 'config', 'layer' => 'strategy', 'key' => 'goal', 'op' => 'eq', 'value' => $valor]];
        $s001 = $this->ruleWith(self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)], $objetivo('cita')), ['persona', 'strategy'])->assertCreated()->json('data');
        $eventos = StudioAuditEvent::query()->count();

        // Sin «si» propio no hay variable común que lo evite: por pares, al pie de la letra (Enmienda 1).
        $r = $this->ruleWith(self::conditionsOf([self::limitOn('persona', 'maxChars', 'gte', 320)]))->assertStatus(409);
        $r->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'rule_contradiction')
            ->assertJsonPath('conflicts', [['code' => 'S-001', 'uuid' => $s001['uuid'], 'revision' => 1, 'target' => 'config:persona.maxChars', 'label' => 'Longitud máxima']]);
        $this->assertNotSame('', (string) $r->json('message'));

        // Un `when` incompatible sobre la MISMA variable la evita (objetivo venta frente a cita).
        $this->ruleWith(self::conditionsOf([self::limitOn('persona', 'maxChars', 'gte', 320)], $objetivo('venta')), ['persona', 'strategy'])->assertCreated();
        $this->ruleWith(self::conditionsOf([self::limitOn('persona', 'maxChars', 'gte', 340)], $objetivo('cita')), ['persona', 'strategy'])
            ->assertStatus(409)->assertJsonPath('conflicts.0.code', 'S-001');

        // Una archivada ya no cuenta.
        $this->studio('POST', "rules/{$s001['uuid']}/archive", $this->ana, ['baseRevision' => 1, 'reason' => 'La sustituye otra'])->assertOk();
        $this->ruleWith(self::conditionsOf([self::limitOn('persona', 'maxChars', 'gte', 400)]))->assertCreated();

        // Los 409 no se auditan ni dejan regla.
        $this->assertSame(3, $this->rulesCount());
        $this->assertSame($eventos + 3, StudioAuditEvent::query()->count(), 'solo las dos creaciones y el archivo');
    }

    public function test_editar_hacia_una_contradiccion_es_409_y_no_escribe(): void
    {
        $s001 = $this->ruleWith(self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)]))->assertCreated()->json('data');
        $s002 = $this->ruleWith(self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 400)]))->assertCreated()->json('data');

        $this->studio('PATCH', "rules/{$s002['uuid']}", $this->ana, ['baseRevision' => 1, 'conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'gte', 320)])])
            ->assertStatus(409)
            ->assertJsonPath('code', 'rule_contradiction')
            ->assertJsonPath('conflicts.0.uuid', $s001['uuid']);

        $fila = StudioRule::query()->where('uuid', $s002['uuid'])->sole();
        $this->assertSame(1, $fila->revision);
        $this->assertSame(self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 400)]), $fila->conditions);
        $this->assertSame(0, StudioAuditEvent::query()->where('event', 'rule.updated')->count());

        // La regla no choca consigo misma: editar S-001 dentro de su propio margen pasa.
        $this->studio('PATCH', "rules/{$s001['uuid']}", $this->ana, ['baseRevision' => 1, 'conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 260)])])->assertOk();
    }

    public function test_las_del_codigo_siguen_siendo_de_solo_lectura_y_ya_traen_sus_condiciones(): void
    {
        $r01 = StudioRule::query()->where('code', 'R-01')->sole();
        $this->assertSame(StudioCatalog::legacyConditions()['R-01'], $r01->conditions);
        $this->assertNull(StudioRule::query()->where('code', 'R-03')->sole()->conditions);

        $this->studio('PATCH', "rules/{$r01->uuid}", $this->ana, ['baseRevision' => 1, 'conditions' => null])
            ->assertStatus(409)->assertJsonPath('code', 'legacy_rule_read_only');
        $this->assertSame(StudioCatalog::legacyConditions()['R-01'], $r01->fresh()->conditions);

        // GET rules: las doce del código con sus condiciones y sin problemas.
        $reglas = collect($this->studio('GET', 'rules', $this->ana)->assertOk()->json('data'))->keyBy('code');
        foreach (StudioCatalog::legacyRules() as $r) {
            $this->assertSame($r['conditions'], $reglas[$r['code']]['conditions'], $r['code']);
            $this->assertSame([], $reglas[$r['code']]['analysis']['issues'], $r['code']);
            $this->assertSame($r['conditions'] === null ? 'none' : 'studio', $reglas[$r['code']]['analysis']['verification'], $r['code']);
        }
    }

    public function test_las_capas_tienen_que_incluir_las_de_los_objetivos(): void
    {
        // Configuración: su capa; el «si» también cuenta.
        $this->ruleWith(self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)]), ['strategy'])
            ->assertStatus(422)->assertJsonValidationErrors(['layers']);
        $this->ruleWith(self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)], [['target' => 'config', 'layer' => 'strategy', 'key' => 'goal', 'op' => 'eq', 'value' => 'venta']]), ['persona'])
            ->assertStatus(422)->assertJsonValidationErrors(['layers']);
        // Una arista: basta con una de sus dos capas (E14: reglas → integraciones).
        $this->ruleWith(self::conditionsOf([['kind' => 'require', 'target' => 'edge', 'edge' => 'E14', 'op' => 'present']]), ['integrations'])->assertCreated();
        $this->ruleWith(self::conditionsOf([['kind' => 'require', 'target' => 'edge', 'edge' => 'E16', 'op' => 'present']]), ['persona'])
            ->assertStatus(422)->assertJsonValidationErrors(['layers']);
        // El texto no exige capa.
        $this->ruleWith(self::conditionsOf([['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => ['oferta relámpago']]]), ['governance'])->assertCreated();

        // Cambiar solo las capas también se comprueba contra las condiciones guardadas.
        $uuid = (string) $this->ruleWith(self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)]), ['persona', 'strategy'])->assertCreated()->json('data.uuid');
        $this->studio('PATCH', "rules/{$uuid}", $this->ana, ['baseRevision' => 1, 'layers' => ['strategy']])
            ->assertStatus(422)->assertJsonValidationErrors(['layers']);
        $this->studio('PATCH', "rules/{$uuid}", $this->ana, ['baseRevision' => 1, 'layers' => ['persona']])->assertOk()->assertJsonPath('data.revision', 2);
    }

    public function test_la_auditoria_guarda_las_condiciones_y_la_descripcion_entera(): void
    {
        $descripcion = str_repeat('Mensajes cortos cuando se vende ', 15).'y nada más.';
        $this->assertSame(491, mb_strlen($descripcion));
        $condiciones = self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)], [['target' => 'config', 'layer' => 'strategy', 'key' => 'goal', 'op' => 'eq', 'value' => 'venta']]);

        $uuid = (string) $this->ruleWith($condiciones, ['persona', 'strategy'], ['description' => $descripcion])->assertCreated()->json('data.uuid');

        $evento = StudioAuditEvent::query()->where('event', 'rule.created')->sole();
        $this->assertSame($condiciones, $evento->diff['conditions']);
        $this->assertSame($descripcion, $evento->diff['description']);
        $this->assertNull($evento->ip_address);
        $this->assertNull($evento->user_agent);

        // Y el historial de la regla lo devuelve por su uuid.
        $historia = $this->studio('GET', "audit?subjectType=rule&subjectUuid={$uuid}", $this->ana)->assertOk()->json('data.data');
        $this->assertSame(['rule.created'], array_column($historia, 'event'));
        $this->assertSame($condiciones, $historia[0]['diff']['conditions']);
    }

    public function test_get_rules_trae_el_analisis_de_cada_regla(): void
    {
        $this->ruleWith(self::conditionsOf([self::limitOn('persona', 'maxChars', 'gte', 160)]))->assertCreated();                           // S-001: vacía
        $this->ruleWith(self::conditionsOf([['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => ['oferta']]]))->assertCreated(); // S-002: solo texto
        // Lo que la API ya no deja escribir solo aparece si cambió el catálogo o
        // alguien escribió en la tabla: dos reglas que se contradicen y una que
        // cita un parámetro que ya no existe.
        $this->seedRule('S-003', self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)]));
        $this->seedRule('S-004', self::conditionsOf([self::limitOn('persona', 'maxChars', 'gte', 320)]));
        $this->seedRule('S-005', self::conditionsOf([self::limitOn('persona', 'longitud', 'lte', 300)]));

        $reglas = collect($this->studio('GET', 'rules', $this->ana)->assertOk()->json('data'))->keyBy('code');

        $this->assertSame(['verification' => 'studio', 'issues' => [['id' => 'regla-vacia', 'level' => 'warning', 'with' => [], 'target' => 'config:persona.maxChars']]], $reglas['S-001']['analysis']);
        $this->assertSame(['verification' => 'lab', 'issues' => []], $reglas['S-002']['analysis']);
        $this->assertSame([['id' => 'regla-contradice-regla', 'level' => 'blocking', 'with' => ['S-004'], 'target' => 'config:persona.maxChars']], $reglas['S-003']['analysis']['issues']);
        $this->assertSame([['id' => 'regla-contradice-regla', 'level' => 'blocking', 'with' => ['S-003'], 'target' => 'config:persona.maxChars']], $reglas['S-004']['analysis']['issues']);
        $this->assertSame('regla-incompatible', $reglas['S-005']['analysis']['issues'][0]['id']);
        $this->assertSame('blocking', $reglas['S-005']['analysis']['issues'][0]['level']);

        // Una archivada se analiza contra las vivas, sin contar entre ellas.
        $s004 = StudioRule::query()->where('code', 'S-004')->sole();
        $this->studio('POST', "rules/{$s004->uuid}/archive", $this->ana, ['baseRevision' => 1, 'reason' => 'Contradice a S-003'])->assertOk()
            ->assertJsonPath('data.analysis.issues.0.with', ['S-003']);
        $this->assertSame([], collect($this->studio('GET', 'rules', $this->ana)->json('data'))->firstWhere('code', 'S-003')['analysis']['issues']);
    }

    public function test_crear_editar_e_importar_toman_el_mismo_cerrojo_antes_de_leer_las_reglas_vivas(): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('El cerrojo consultivo solo existe en PostgreSQL: SQLite escribe en serie por sí mismo.');
        }

        // Sobre el SQL generado: la carrera real (dos conexiones) la prueba el
        // tester con la pila de verdad; aquí, que el orden es el del contrato.
        $sentencias = [];
        DB::listen(function ($q) use (&$sentencias): void {
            $sentencias[] = [$q->sql, $q->bindings];
        });
        $clave = crc32('ultron_studio_rules:code');
        $orden = function (callable $peticion, string $lectura) use (&$sentencias, $clave): array {
            $sentencias = [];
            $peticion();
            $cerrojo = $lee = null;
            foreach ($sentencias as $i => [$sql, $bindings]) {
                if ($cerrojo === null && str_contains($sql, 'pg_advisory_xact_lock') && $bindings === [$clave]) {
                    $cerrojo = $i;
                }
                if ($lee === null && str_contains($sql, $lectura)) {
                    $lee = $i;
                }
            }

            return [$cerrojo, $lee];
        };
        $vivas = 'from "ultron_studio_rules" where "source" = ? and "status" <> ?';

        [$cerrojo, $lee] = $orden(fn () => $this->ruleWith(self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)]))->assertCreated(), $vivas);
        $this->assertNotNull($cerrojo, 'POST rules no toma el cerrojo');
        $this->assertLessThan($lee, $cerrojo, 'POST rules lee las reglas vivas antes del cerrojo');

        $uuid = (string) StudioRule::query()->where('code', 'S-001')->value('uuid');
        [$cerrojo, $lee] = $orden(fn () => $this->studio('PATCH', "rules/{$uuid}", $this->ana, ['baseRevision' => 1, 'conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 280)])])->assertOk(), $vivas);
        $this->assertNotNull($cerrojo, 'PATCH rules no toma el cerrojo');
        $this->assertLessThan($lee, $cerrojo, 'PATCH rules lee las reglas vivas antes del cerrojo');

        [$cerrojo, $lee] = $orden(fn () => $this->assertSame(0, Artisan::call('ultron-studio:import-baseline')), 'from "ultron_studio_rules" where "code" in');
        $this->assertNotNull($cerrojo, 'import-baseline no toma el cerrojo');
        $this->assertLessThan($lee, $cerrojo, 'import-baseline lee la tabla antes del cerrojo');

        // Archivar no lo necesita: quitar una regla no crea contradicciones.
        [$cerrojo] = $orden(fn () => $this->studio('POST', "rules/{$uuid}/archive", $this->ana, ['baseRevision' => 2, 'reason' => 'Sin cerrojo'])->assertOk(), $vivas);
        $this->assertNull($cerrojo);
    }

    /** Una regla del Studio escrita directamente en la tabla, como si el catálogo hubiera cambiado después. */
    private function seedRule(string $code, array $conditions): void
    {
        $campos = ['description' => "Sembrada {$code}", 'type' => 'style', 'priority' => 'normal', 'layers' => ['persona'],
            'refs' => ['configs' => [], 'edges' => [], 'knowledgeKeys' => [], 'knowledgeCategories' => []], 'origin' => null, 'conditions' => $conditions];
        StudioRule::query()->forceCreate($campos + [
            'code' => $code, 'source' => 'studio', 'enforcement' => 'none', 'status' => 'draft', 'revision' => 1,
            'content_hash' => StudioContentHasher::ruleHash($campos), 'client_request_id' => (string) Str::uuid(),
        ]);
    }
}
