<?php

namespace Tests\Feature\UltronStudio;

use App\Http\Controllers\Concerns\ValidatesListSizesFirst;
use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioDraft;
use App\Models\UltronStudio\StudioRule;
use App\Models\UltronStudio\StudioVersion;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioAudit;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioDraftNormalizer;
use App\Services\UltronStudio\StudioRules;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator as LaravelValidator;
use LogicException;
use Tests\TestCase;

/**
 * Endurecimientos tras la revisión de seguridad de la API (H1, H3, H4) y la
 * propuesta P5 del especialista de datos: una llave de escritura sin
 * `ultron.view` no abre nada; los intentos denegados no inundan un libro que
 * no se puede vaciar; las reglas del Studio tienen tope; y una regla no se
 * borra ni por SQL.
 *
 * Y las correcciones de las revisiones del Hito G: un cuerpo de más de 64 KB
 * es 413 sin llegar a validar, una lista por encima de su tope se rechaza sin
 * recorrer sus elementos (M-2, también en las vías de F), el borrador más
 * grande que admite el normalizador cabe de sobra, y el doble clic simultáneo
 * con 99 reglas vivas es un replay y no el tope (H-3).
 */
class UltronStudioHardeningTest extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    public function test_una_llave_de_escritura_sin_ultron_view_no_abre_nada(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $uuid = $this->submittedVersion($ana);

        // Solo `ultron.approve`: ni leer la versión ni aprobarla a ciegas. Y sin
        // oráculo de existencia: un uuid inexistente también responde 403, no 404.
        $soloAprobar = $this->adminWithPermissions(['ultron.approve']);
        $this->json('GET', "/api/admin/ultron-studio/versions/{$uuid}", [], $soloAprobar)->assertStatus(403);
        $this->json('POST', '/api/admin/ultron-studio/versions/'.Str::uuid().'/approve', ['acknowledgedWarnings' => [], 'note' => null], $soloAprobar)
            ->assertStatus(403)->assertJsonPath('required_permission', 'ultron.view');
        $this->json('POST', "/api/admin/ultron-studio/versions/{$uuid}/approve", ['acknowledgedWarnings' => [], 'note' => null], $soloAprobar)
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden')
            ->assertJsonPath('required_permission', 'ultron.view');
        $this->assertSame('REVIEW_REQUIRED', StudioVersion::query()->where('uuid', $uuid)->value('status'));

        // Solo `ultron.edit_draft`: tampoco escribe el borrador (el único que hay es el de Ana).
        $borradores = DB::table('ultron_studio_drafts')->count();
        $soloEditar = $this->adminWithPermissions(['ultron.edit_draft']);
        $this->json('PUT', '/api/admin/ultron-studio/draft', ['baseRevision' => 0, 'content' => $this->baseline()], $soloEditar)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'ultron.view');
        $this->assertSame($borradores, DB::table('ultron_studio_drafts')->count());

        // Con las dos llaves, sí.
        $completo = $this->adminWithPermissions(['ultron.view', 'ultron.edit_draft']);
        $this->json('PUT', '/api/admin/ultron-studio/draft', ['baseRevision' => 0, 'content' => $this->baseline()], $completo)->assertOk();
    }

    public function test_los_intentos_denegados_no_inundan_el_libro(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $uuid = $this->submittedVersion($ana);

        for ($i = 0; $i < 5; $i++) {
            $this->studio('POST', "versions/{$uuid}/approve", $ana, ['acknowledgedWarnings' => [], 'note' => null])
                ->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
            $this->studio('POST', "versions/{$uuid}/publish", $root)->assertStatus(409)->assertJsonPath('code', 'publishing_disabled');
        }
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'version.four_eyes_denied')->count());
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'version.publish_refused')->count());

        // Pasada la ventana se vuelve a anotar: el libro sigue contando intentos, pero no por segundo.
        $this->travel(StudioAudit::DENIED_WINDOW_MINUTES + 1)->minutes();
        $this->studio('POST', "versions/{$uuid}/publish", $root)->assertStatus(409);
        $this->studio('POST', "versions/{$uuid}/publish", $root)->assertStatus(409);
        $this->assertSame(2, StudioAuditEvent::query()->where('event', 'version.publish_refused')->count());
    }

    public function test_las_reglas_del_studio_tienen_tope_y_las_archivadas_no_cuentan(): void
    {
        // Cien creaciones seguidas superan el cupo de escritura (30/min), que aquí no es lo que se prueba.
        $this->withoutMiddleware(ThrottleRequests::class);
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $cuerpo = fn (int $i, string $clientRequestId) => [
            'description' => "Regla de prueba número {$i} para el tope",
            'type' => 'restriction',
            'priority' => 'normal',
            'layers' => ['composer'],
            'clientRequestId' => $clientRequestId,
        ];
        $nueva = fn (int $i, ?string $clientRequestId = null) => $this->studio('POST', 'rules', $ana, $cuerpo($i, $clientRequestId ?? (string) Str::uuid()));

        $primera = null;
        $idPrimera = (string) Str::uuid();
        for ($i = 1; $i <= StudioRules::MAX_LIVE_STUDIO_RULES; $i++) {
            $r = $nueva($i, $i === 1 ? $idPrimera : null)->assertCreated();
            $primera ??= $r->json('data');
        }
        $nueva(0)->assertStatus(409)->assertJsonPath('code', 'rule_limit_reached');

        // Con el tope alcanzado, repetir una creación ya hecha sigue siendo replay (200), no 409.
        $nueva(1, $idPrimera)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.code', $primera['code']);

        // Archivar una libera un hueco.
        $this->studio('POST', "rules/{$primera['uuid']}/archive", $ana, ['baseRevision' => $primera['revision'], 'reason' => 'Hace hueco para la prueba'])->assertOk();
        $nueva(0)->assertCreated();
        $this->assertSame(StudioRules::MAX_LIVE_STUDIO_RULES, DB::table('ultron_studio_rules')->where('source', 'studio')->where('status', '<>', 'archived')->count());
    }

    public function test_la_escritura_condicionada_no_deja_activar_ni_cambiar_de_identidad_a_una_regla_del_studio(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $regla = $this->studio('POST', 'rules', $ana, [
            'description' => 'Regla del Studio para probar la guarda de escritura',
            'type' => 'restriction',
            'priority' => 'normal',
            'layers' => ['composer'],
            'clientRequestId' => (string) Str::uuid(),
        ])->assertCreated()->json('data');
        $modelo = StudioRule::query()->where('uuid', $regla['uuid'])->sole();

        // La guarda vive en código (además del CHECK de PostgreSQL): el UPDATE
        // del constructor de consultas no pasa por el modelo.
        $metodo = new \ReflectionMethod(StudioRules::class, 'conditionalWrite');
        $servicio = app(StudioRules::class);
        foreach ([['status' => 'active'], ['enforcement' => 'code'], ['source' => 'legacy_baseline'], ['code' => 'R-99']] as $valores) {
            try {
                $metodo->invoke($servicio, $modelo, $modelo->revision, $valores);
                $this->fail('Tendría que lanzar: '.json_encode($valores));
            } catch (LogicException $e) {
                $this->assertStringContainsString('solo cambia de contenido o se archiva', $e->getMessage());
            }
        }
        $this->assertSame('draft', $modelo->fresh()->status);
        $this->assertSame('none', $modelo->fresh()->enforcement);
    }

    public function test_un_cuerpo_de_mas_de_64_kb_es_413_sin_llegar_a_validar(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $this->as($ana);
        $this->as($root);
        $validadores = 0;
        Validator::resolver(static function ($traductor, array $datos, array $reglas, array $mensajes, array $atributos) use (&$validadores): LaravelValidator {
            $validadores++;

            return new LaravelValidator($traductor, $datos, $reglas, $mensajes, $atributos);
        });

        // 5000 elementos bajo un comodín: unos 150 KB. Antes se expandían y se
        // validaban uno a uno antes de que `max:3` cortara (12 s de CPU en la
        // medida de api-security).
        $relleno = array_fill(0, 5000, ['k' => str_repeat('x', 22)]);
        $regla = ['description' => 'Regla enorme', 'type' => 'style', 'priority' => 'normal', 'layers' => ['persona'],
            'conditions' => ['when' => [], 'then' => $relleno], 'clientRequestId' => (string) Str::uuid()];
        $this->assertGreaterThan(150 * 1024, strlen((string) json_encode($regla)));
        $grandes = [
            ['POST', 'rules', $ana, $regla],
            ['PATCH', 'rules/'.Str::uuid(), $ana, ['baseRevision' => 1, 'layers' => array_fill(0, 5000, str_repeat('p', 26))]],
            ['PUT', 'draft', $ana, ['baseRevision' => 0, 'content' => ['relleno' => str_repeat('x', 65536)]]],
            ['POST', 'versions/'.Str::uuid().'/approve', $root, ['acknowledgedWarnings' => array_fill(0, 5000, str_repeat('w', 26)), 'note' => null]],
        ];
        foreach ($grandes as [$metodo, $ruta, $quien, $cuerpo]) {
            $this->studio($metodo, $ruta, $quien, $cuerpo)
                ->assertStatus(413)
                ->assertExactJson(['ok' => false, 'code' => 'payload_too_large', 'message' => 'La petición supera el tamaño máximo (64 KB).']);
        }

        $this->assertSame(0, $validadores, 'Un cuerpo de más de 64 KB llegó a la validación.');
        $this->assertSame(0, StudioRule::query()->count());
        $this->assertSame(0, StudioDraft::query()->count());
        $this->assertSame(0, StudioAuditEvent::query()->count());
    }

    public function test_una_lista_por_encima_de_su_tope_es_422_solo_por_el_tope_y_sin_recorrerla(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $this->studio('GET', 'status', $ana)->assertOk();
        $this->studio('GET', 'status', $root)->assertOk();
        $regla = ['description' => 'Regla con una lista de más', 'type' => 'style', 'priority' => 'normal', 'layers' => ['persona'], 'clientRequestId' => (string) Str::uuid()];
        // Sin medir: la primera petición de escritura carga controlador y validación en frío.
        $this->studio('POST', 'rules', $ana, ['layers' => array_fill(0, 11, 'persona')] + $regla)->assertStatus(422);

        // Cada lista con reglas por elemento, de G y de F (ya en producción), muy
        // por encima de su tope y por debajo de los 64 KB. Los elementos no
        // valen: antes salía un error por cada uno, además del tope.
        $casos = [
            'layers (POST)' => ['POST', 'rules', $ana, ['layers' => array_map(static fn (int $i): string => sprintf('capa-%03d-', $i).str_repeat('x', 90), range(1, 200))] + $regla, 'layers'],
            'refs.configs' => ['POST', 'rules', $ana, $regla + ['refs' => ['configs' => array_fill(0, 1500, 'x')]], 'refs.configs'],
            'refs.edges' => ['POST', 'rules', $ana, $regla + ['refs' => ['edges' => array_fill(0, 1500, 'E14')]], 'refs.edges'],
            'refs.knowledgeKeys' => ['POST', 'rules', $ana, $regla + ['refs' => ['knowledgeKeys' => array_fill(0, 1500, 'clave.prueba')]], 'refs.knowledgeKeys'],
            'refs.knowledgeCategories' => ['POST', 'rules', $ana, $regla + ['refs' => ['knowledgeCategories' => array_fill(0, 1500, 'tone')]], 'refs.knowledgeCategories'],
            'conditions.when' => ['POST', 'rules', $ana, $regla + ['conditions' => ['when' => array_fill(0, 1500, 'x'), 'then' => [self::limitOn('persona', 'maxChars', 'lte', 300)]]], 'conditions.when'],
            'conditions.then' => ['POST', 'rules', $ana, $regla + ['conditions' => ['when' => [], 'then' => array_fill(0, 1500, 'x')]], 'conditions.then'],
            'layers (PATCH)' => ['PATCH', 'rules/'.Str::uuid(), $ana, ['baseRevision' => 1, 'layers' => array_fill(0, 1500, 'persona')], 'layers'],
            'conditions.then (PATCH)' => ['PATCH', 'rules/'.Str::uuid(), $ana, ['baseRevision' => 1, 'conditions' => ['when' => [], 'then' => array_fill(0, 1500, 'x')]], 'conditions.then'],
            'acknowledgedWarnings' => ['POST', 'versions/'.Str::uuid().'/approve', $root, ['acknowledgedWarnings' => array_fill(0, 1500, 'opcional-E14'), 'note' => null], 'acknowledgedWarnings'],
        ];
        foreach ($casos as $caso => [$metodo, $ruta, $quien, $cuerpo, $clave]) {
            $this->assertLessThan(65536, strlen((string) json_encode($cuerpo)), $caso);
            $inicio = hrtime(true);
            $r = $this->studio($metodo, $ruta, $quien, $cuerpo);
            $ms = (hrtime(true) - $inicio) / 1e6;

            $r->assertStatus(422);
            $this->assertSame([$clave], array_keys($r->json('errors')), "{$caso}: el 422 trae errores por elemento, así que se recorrió la lista.");
            $this->assertLessThan(50, $ms, sprintf('%s tardó %.1f ms en rechazarse.', $caso, $ms));
        }

        // El cuerpo de 20 KB que pide la revisión.
        $this->assertGreaterThan(20 * 1000, strlen((string) json_encode($casos['layers (POST)'][3])));
        $this->assertSame(0, StudioRule::query()->count());
    }

    public function test_el_borrador_mas_grande_que_admite_el_normalizador_cabe_de_sobra_en_el_tope(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $catalogo = StudioCatalog::toArray();

        // Cada parámetro en su forma más larga, con el carácter más caro (4
        // bytes en UTF-8; 12 escapado): 20 etiquetas de 60 en cada lista, todas
        // las aristas, coordenadas y vista extremas.
        $caro = "\u{1F525}";
        $configs = [];
        foreach ($catalogo['parameters'] as $capa => $parametros) {
            foreach ($parametros as $p) {
                $configs[$capa][$p['key']] = match ($p['type']) {
                    'toggle' => false,
                    'range' => strlen((string) $p['min']) >= strlen((string) $p['max']) ? $p['min'] : $p['max'],
                    'select' => array_reduce(array_column($p['options'], 'value'), static fn (string $a, string $b): string => strlen($b) > strlen($a) ? $b : $a, ''),
                    'text' => str_repeat($caro, StudioDraftNormalizer::MAX_TEXT),
                    'textarea' => str_repeat($caro, StudioDraftNormalizer::MAX_TEXTAREA),
                    'tags' => array_fill(0, StudioDraftNormalizer::MAX_TAGS, str_repeat($caro, StudioDraftNormalizer::MAX_TAG_LENGTH)),
                };
            }
        }
        $posiciones = [];
        foreach (array_column($catalogo['layers'], 'id') as $capa) {
            $posiciones[$capa] = ['x' => -StudioDraftNormalizer::MAX_COORDINATE, 'y' => -StudioDraftNormalizer::MAX_COORDINATE];
        }
        $contenido = [
            'definition' => ['layers' => array_column($catalogo['layers'], 'id'), 'edgeIds' => array_column($catalogo['edges'], 'id')],
            'configs' => $configs,
            'layout' => ['positions' => $posiciones, 'viewport' => ['x' => -1.7976931348623157E+308, 'y' => -1.7976931348623157E+308, 'scale' => 0.30000000000000004]],
        ];
        $this->assertSame(json_encode($contenido['configs']), json_encode(StudioDraftNormalizer::normalize($contenido)['configs']), 'el normalizador lo admite tal cual');

        // Tal como lo envía el CRM (UTF-8 sin escapar) y con todo el Unicode escapado.
        $crm = (string) json_encode(['baseRevision' => 0, 'content' => $contenido], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $escapado = (string) json_encode(['baseRevision' => 1, 'content' => $contenido]);
        $this->assertGreaterThan(10 * 1024, strlen($crm));
        $this->assertGreaterThan(29 * 1024, strlen($escapado));
        $this->assertLessThan(65536 / 2, strlen($escapado), 'el borrador más grande ocupa menos de la mitad del tope');

        $this->json('PUT', '/api/admin/ultron-studio/draft', ['baseRevision' => 0, 'content' => $contenido], $this->as($ana), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->assertOk()->assertJsonPath('data.revision', 1);
        $this->json('PUT', '/api/admin/ultron-studio/draft', ['baseRevision' => 1, 'content' => $contenido], $this->as($ana))
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertSame(str_repeat($caro, StudioDraftNormalizer::MAX_TAG_LENGTH), StudioDraft::query()->sole()->content['configs']['knowledge']['sources'][19]);
    }

    public function test_el_doble_clic_simultaneo_con_99_vivas_es_replay_y_no_el_tope(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $this->seedLiveStudioRules(StudioRules::MAX_LIVE_STUDIO_RULES - 1);
        $id = (string) Str::uuid();
        $cuerpo = ['description' => 'La regla número cien', 'type' => 'restriction', 'priority' => 'normal', 'layers' => ['composer'], 'clientRequestId' => $id];

        // La carrera de H-3 en una sola conexión: la segunda petición busca su
        // repetición FUERA de la transacción y no la encuentra; justo entonces
        // la primera se cuela entera y crea la regla cien con el MISMO
        // `clientRequestId`. Al entrar en la transacción ya hay cien vivas.
        $colada = false;
        DB::listen(function (QueryExecuted $q) use (&$colada, $ana, $cuerpo, $id): void {
            if ($colada || ! str_contains($q->sql, 'from "ultron_studio_rules" where "client_request_id" = ?')) {
                return;
            }
            $colada = true;
            app(StudioRules::class)->create(new StudioAccess($ana), request(), array_diff_key($cuerpo, ['clientRequestId' => true]), $id);
        });

        $r = $this->studio('POST', 'rules', $ana, $cuerpo);

        $this->assertTrue($colada, 'La carrera no llegó a simularse.');
        $r->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.code', 'S-100');
        $this->assertSame(1, StudioRule::query()->where('client_request_id', $id)->count());
        $this->assertSame(StudioRules::MAX_LIVE_STUDIO_RULES, StudioRule::query()->where('source', 'studio')->where('status', '<>', 'archived')->count());
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'rule.created')->count());
    }

    public function test_una_lista_con_comodin_sin_tope_o_con_dos_niveles_es_un_error_de_codigo(): void
    {
        $controlador = new class
        {
            use ValidatesListSizesFirst;

            /** @param  array<string, mixed>  $rules */
            public function validar(Request $request, array $rules): array
            {
                return $this->validateListSizesFirst($request, $rules);
            }
        };
        $peticion = Request::create('/', 'POST', ['lista' => [['a' => ['x']]]]);

        foreach ([
            'sin tope' => ['lista' => ['array'], 'lista.*' => ['array']],
            'sin regla de la lista' => ['lista.*' => ['array']],
            'dos niveles' => ['lista' => ['array', 'max:3'], 'lista.*.a' => ['array', 'max:3'], 'lista.*.a.*' => ['string']],
        ] as $caso => $reglas) {
            try {
                $controlador->validar($peticion, $reglas);
                $this->fail("{$caso}: tendría que negarse a validar.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('lista', $e->getMessage(), $caso);
            }
        }

        // Con su tope, valida como siempre.
        $this->assertSame(['lista' => [['a' => ['x']]]], $controlador->validar($peticion, ['lista' => ['array', 'max:3'], 'lista.*' => ['array']]));
    }

    /** `$n` reglas del Studio vivas, escritas directamente en la tabla: cien POST superarían el cupo. */
    private function seedLiveStudioRules(int $n): void
    {
        $refs = ['configs' => [], 'edges' => [], 'knowledgeKeys' => [], 'knowledgeCategories' => []];
        $filas = [];
        for ($i = 1; $i <= $n; $i++) {
            $campos = ['description' => "Viva {$i}", 'type' => 'restriction', 'priority' => 'normal', 'layers' => ['composer'], 'refs' => $refs, 'origin' => null, 'conditions' => null];
            $filas[] = [
                'uuid' => (string) Str::uuid(), 'code' => sprintf('S-%03d', $i), 'source' => 'studio', 'enforcement' => 'none', 'status' => 'draft',
                'description' => $campos['description'], 'type' => 'restriction', 'priority' => 'normal', 'origin' => null, 'revision' => 1,
                'layers' => json_encode(['composer']), 'refs' => json_encode($refs), 'conditions' => null,
                'content_hash' => StudioContentHasher::ruleHash($campos), 'client_request_id' => (string) Str::uuid(),
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($filas, 100) as $lote) {
            DB::table('ultron_studio_rules')->insert($lote);
        }
    }

    public function test_una_regla_no_se_borra_ni_por_sql(): void
    {
        $this->importBaseline();
        $antes = DB::table('ultron_studio_rules')->count();

        try {
            // En una transacción anidada: en PostgreSQL el fallo abortaría la de la prueba.
            DB::transaction(fn () => DB::table('ultron_studio_rules')->where('code', 'R-01')->delete());
            $this->fail('El DELETE de una regla tendría que fallar por el disparador.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('ultron_studio:', $e->getMessage());
        }

        $this->assertSame($antes, DB::table('ultron_studio_rules')->count());
    }
}
