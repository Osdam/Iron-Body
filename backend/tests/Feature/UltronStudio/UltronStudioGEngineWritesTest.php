<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\MarketingAgentSetting;
use App\Models\MarketingKnowledgeItem;
use App\Models\Plan;
use App\Models\UltronAbort;
use App\Services\Marketing\MarketingAgentSwitch;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * EL STUDIO NO MANDA NI UNA ESCRITURA AL MOTOR (contrato G §A9, «aislamiento
 * ampliado»), vigilado por las SENTENCIAS, no por el estado final.
 *
 * `UltronStudioEngineReadOnlyTest` compara una huella de las tablas antes y
 * después de recorrer las rutas. Una escritura cuyo efecto neto se deshace no
 * cambia esa huella: el tester del Hito G metió en la lectura de la pausa una
 * escritura con métodos dinámicos que ALTERNA `marketing_agent_settings.paused`;
 * esa prueba recorre `engine/readings` dos veces (la pausa vuelve a su valor) y
 * `updated_at` tiene precisión de segundo (la prueba cabe en uno), así que la
 * huella salió idéntica y solo lo delataron las pruebas de valores de lectura.
 *
 * Aquí se escucha cada sentencia que el Studio manda a la base mientras se
 * recorren las 21 rutas (la lectura del motor, tres veces): un INSERT, UPDATE,
 * DELETE, TRUNCATE o DDL sobre cualquier tabla que no sea del Studio (o la
 * sesión del personal, que toca `AdminSessionService::touch` en cada petición)
 * es un fallo, deje el valor que deje. La configuración se compara entera.
 */
class UltronStudioGEngineWritesTest extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    /** Las tablas en las que el Studio puede escribir: las suyas y la sesión del personal. */
    private const WRITABLE = ['ultron_studio_drafts', 'ultron_studio_rules', 'ultron_studio_versions', 'ultron_studio_version_reviews', 'ultron_studio_audit_events', 'admin_sessions'];

    private const WRITE_SQL = '/^\s*(?:with\b.*?\)\s*)?(insert\s+into|update|delete\s+from|truncate(?:\s+table)?|replace\s+into|alter\s+table|drop\s+table|create\s+(?:temporary\s+)?table)\s+(?:only\s+)?"?([A-Za-z0-9_\.]+)"?/is';

    /** @var list<string> */
    private array $recorridas = [];

    public function test_ninguna_ruta_del_studio_manda_una_escritura_fuera_de_sus_tablas(): void
    {
        // Las escrituras reales superan el cupo de 30/min, que aquí no es lo que se prueba.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedEngine();
        $this->importBaseline();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        // Las sesiones se abren antes de escuchar: abrirlas no es del Studio.
        $this->as($ana);
        $this->as($root);

        $escrituras = [];
        DB::listen(function (QueryExecuted $q) use (&$escrituras): void {
            if (preg_match(self::WRITE_SQL, $q->sql, $m) === 1) {
                $tabla = strtolower((string) preg_replace('/^.*\./', '', $m[2]));
                $escrituras[] = ['table' => $tabla, 'sql' => $q->sql];
            }
        });
        $configuracion = json_encode(config()->all());

        $this->walkEveryRoute($ana, $root);

        $ajenas = array_values(array_filter($escrituras, static fn (array $e): bool => ! in_array($e['table'], self::WRITABLE, true)));
        $this->assertSame([], $ajenas, 'El Studio mandó escrituras a tablas que no son suyas.');
        $this->assertSame($configuracion, json_encode(config()->all()), 'El Studio cambió la configuración.');

        // El oído funciona: se vieron las escrituras propias del Studio.
        $propias = array_unique(array_column($escrituras, 'table'));
        foreach (['ultron_studio_drafts', 'ultron_studio_rules', 'ultron_studio_versions', 'ultron_studio_version_reviews', 'ultron_studio_audit_events'] as $tabla) {
            $this->assertContains($tabla, $propias, "No se vio ninguna escritura en {$tabla}: el recorrido o el oído fallan.");
        }
        // Y se recorrieron las 21 rutas.
        $this->assertCount(21, array_unique($this->recorridas), implode(', ', array_unique($this->recorridas)));
    }

    /** @param  array<string, mixed>  $body */
    private function walk(string $method, string $path, Admin $as, string $route, array $body = []): TestResponse
    {
        $this->recorridas[] = "{$method} {$route}";

        return $this->studio($method, $path, $as, $body);
    }

    private function walkEveryRoute(Admin $ana, Admin $root): void
    {
        foreach (['status', 'catalog', 'draft', 'rules', 'versions', 'audit'] as $lectura) {
            $this->walk('GET', $lectura, $ana, $lectura)->assertOk();
        }
        // La lectura del motor, un número IMPAR de veces.
        foreach ([1, 2, 3] as $_) {
            $this->walk('GET', 'engine/readings', $ana, 'engine/readings')->assertOk();
        }

        $borrador = $this->walk('PUT', 'draft', $ana, 'draft', ['baseRevision' => 0, 'content' => $this->contentWith([
            'persona' => ['tone' => 'entusiasta', 'maxChars' => 400], 'strategy' => ['goal' => 'venta'],
        ])])->assertOk()->json('data');

        $regla = $this->walk('POST', 'rules', $ana, 'rules', [
            'description' => 'Mensajes cortos.', 'type' => 'style', 'priority' => 'normal', 'layers' => ['persona'],
            'conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 440)]), 'clientRequestId' => (string) Str::uuid(),
        ])->assertCreated()->json('data');
        $this->walk('PATCH', "rules/{$regla['uuid']}", $ana, 'rules/{uuid}', ['baseRevision' => 1, 'conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 460)])])->assertOk();
        $otra = $this->newStudioRule($ana)->assertCreated()->json('data');
        $this->walk('POST', "rules/{$otra['uuid']}/archive", $ana, 'rules/{uuid}/archive', ['baseRevision' => 1, 'reason' => 'Solo para el recorrido'])->assertOk();

        $version = $this->walk('POST', 'versions', $ana, 'versions', ['label' => 'Recorrido', 'note' => null, 'draftRevision' => $borrador['revision'], 'clientRequestId' => (string) Str::uuid()])
            ->assertCreated()->json('data');
        $uuid = $version['uuid'];
        $this->walk('GET', "versions/{$uuid}", $root, 'versions/{uuid}')->assertOk();
        $this->walk('POST', "versions/{$uuid}/validate", $root, 'versions/{uuid}/validate')->assertOk();
        $avisos = $this->walk('POST', "versions/{$uuid}/submit", $ana, 'versions/{uuid}/submit')->assertOk()->json('data.warnings');
        $this->walk('POST', "versions/{$uuid}/approve", $root, 'versions/{uuid}/approve', ['acknowledgedWarnings' => $avisos, 'note' => 'Recorrido'])->assertOk();
        $this->walk('POST', "versions/{$uuid}/publish", $root, 'versions/{uuid}/publish')->assertStatus(409);
        $this->walk('POST', "versions/{$uuid}/rollback", $root, 'versions/{uuid}/rollback')->assertStatus(409);

        $segunda = (string) $this->createVersion($ana, 'Para rechazar')->assertCreated()->json('data.uuid');
        $this->studio('POST', "versions/{$segunda}/submit", $ana)->assertOk();
        $this->walk('POST', "versions/{$segunda}/reject", $root, 'versions/{uuid}/reject', ['reason' => 'Solo para el recorrido'])->assertOk();

        $this->walk('GET', "compare?from={$uuid}&to=draft", $ana, 'compare')->assertOk();
        $actual = $this->studio('GET', 'draft', $ana)->assertOk()->json('data');
        $this->walk('POST', 'draft/discard', $ana, 'draft/discard', ['baseRevision' => $actual['revision']])->assertOk();
    }

    /** La pausa puesta, el freno echado, conocimiento y planes: el motor «vivo». */
    private function seedEngine(): void
    {
        MarketingAgentSetting::query()->whereKey(MarketingAgentSwitch::ROW_ID)->update(['paused' => true, 'paused_at' => now(), 'version' => 3]);
        UltronAbort::query()->create(['reason' => UltronAbort::REASON_MANUAL, 'engaged_by' => 'Consola de prueba', 'engaged_at' => now()]);
        MarketingKnowledgeItem::create(['category' => 'tone', 'key' => 'tone.cercano', 'title' => 'Tono', 'content' => 'Cercano y claro.', 'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_SERVER]);
        Plan::create(['name' => 'Mensualidad', 'price' => 98765, 'duration_days' => 30, 'active' => true, 'is_recommended' => true, 'sort_order' => 1]);
    }
}
