<?php

namespace Tests\Feature\UltronStudio\Stream;

use App\Http\Controllers\Api\Admin\IronGuardController;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioStreamController;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\RolePermission;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Outreach\Run\Stream\OutreachTopic;
use App\Services\UltronStudio\Stream\IncidentsTopic;
use App\Services\UltronStudio\Stream\PendingTopic;
use App\Services\UltronStudio\Stream\StreamCountable;
use App\Services\UltronStudio\Stream\StreamCursors;
use App\Services\UltronStudio\Stream\StreamEvent;
use App\Services\UltronStudio\Stream\StreamTopics;
use App\Services\UltronStudio\Stream\VoiceCallEventsTopic;
use App\Services\UltronStudio\Stream\VoiceCallsTopic;
use App\Services\UltronStudio\StudioAccess;
use App\Support\Access\AuthorizationMap;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use ReflectionClassConstant;
use ReflectionMethod;

/**
 * Guardas del canal (contrato H9, «Paquete SSE9»): el registro tiene
 * exactamente los once temas del contrato; el permiso que declara cada tema
 * es el que AuthorizationMap resuelve para el GET de lista de su módulo (las
 * dos fuentes no se separan); los dos temas de eventos de llamada se reparten
 * TODOS los `kind` sin solaparse; los roles de IRON GUARD son los del
 * controlador; la lista blanca del evento y el vector de cursores rechazan
 * lo que no tiene su forma.
 */
class UltronStudioStreamTopicsGuardTest extends StreamTestCase
{
    private function registry(): StreamTopics
    {
        return StreamTopics::for(new StudioAccess($this->person()));
    }

    public function test_el_registro_tiene_los_once_temas_del_contrato_en_su_orden(): void
    {
        $this->assertSame(StreamTopics::NAMES, $this->registry()->names());
        $this->assertSame([
            'ledger', 'voice.calls', 'voice.brake', 'voice.reconcile', 'voice.publications', 'voice.lab',
            'monitor.incidents', 'outreach.runs', 'outreach.jobs', 'outreach.outcomes', 'outreach.brakes',
        ], StreamTopics::NAMES);
        foreach ($this->registry()->all() as $name => $topic) {
            $this->assertSame($name, $topic->name());
            $this->assertMatchesRegularExpression(StreamCursors::TOPIC, $name);
        }
    }

    public function test_la_ruta_del_canal_es_del_studio_y_si_esta_clasificada_lo_esta_con_ultron_view(): void
    {
        $rutas = [];
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->getActionName(), '\\UltronStudio\\UltronStudioStreamController')) {
                $rutas[] = $route;
            }
        }
        $this->assertCount(UltronStudioStreamController::ROUTE_COUNT, $rutas);
        $this->assertSame('GET api/admin/ultron-studio/stream', AuthorizationMap::routeKey($rutas[0]));
        $this->assertContains('throttle:studio-stream', $rutas[0]->gatherMiddleware());
        // La entrada del mapa (L9-43, integrada en L9-59): la puerta del canal es la base del Studio.
        $this->assertSame('ultron.view', AuthorizationMap::resolve($rutas[0]));
    }

    public function test_el_permiso_de_cada_tema_es_el_del_get_de_lista_de_su_modulo_en_el_mapa(): void
    {
        $rutas = [];
        foreach (Route::getRoutes() as $route) {
            $rutas[AuthorizationMap::routeKey($route)] = $route;
        }

        $comprobados = 0;
        foreach ($this->registry()->all() as $name => $topic) {
            $lista = $topic->listRoute();
            $this->assertNotNull($lista, "{$name}: todo tema declara la ruta de lista cuyo permiso exige (ninguno queda pendiente, L9-59)");
            $this->assertArrayHasKey($lista, $rutas, "{$name}: la ruta de lista {$lista} no existe");
            $this->assertSame(AuthorizationMap::resolve($rutas[$lista]), $topic->permission(), "{$name}: el permiso declarado no es el del mapa para {$lista}");
            $comprobados++;
        }
        $this->assertSame(11, $comprobados);
    }

    public function test_los_dos_temas_de_eventos_de_llamada_se_reparten_todos_los_kinds_sin_solaparse(): void
    {
        $kinds = new ReflectionMethod(VoiceCallsTopic::class, 'kinds');
        $llamadas = $kinds->invoke(new VoiceCallsTopic);

        $this->assertSame([], array_intersect($llamadas, VoiceCallEventsTopic::RECONCILE_KINDS));
        $todos = [...$llamadas, ...VoiceCallEventsTopic::RECONCILE_KINDS];
        sort($todos);
        $esperados = VoiceCallEvent::KINDS;
        sort($esperados);
        $this->assertSame($esperados, $todos);
    }

    public function test_los_roles_de_iron_guard_son_los_del_controlador(): void
    {
        $delControlador = (new ReflectionClassConstant(IronGuardController::class, 'ALLOWED_ROLES'))->getValue();
        $this->assertSame($delControlador, IncidentsTopic::ALLOWED_ROLES);
    }

    public function test_el_evento_solo_admite_la_lista_blanca_y_escalares_cortos(): void
    {
        $ok = new StreamEvent('12', ['type' => 'x.y', 'id' => 'abc', 'state' => null, 'at' => '2026-11-09T20:50:00+00:00', 'ref' => 'r', 'hash' => str_repeat('a', 64)]);
        $this->assertSame(['type', 'id', 'state', 'at', 'hash', 'ref'], array_keys($ok->data));

        foreach ([
            ['type' => 'x', 'id' => 'a', 'at' => 'b', 'phone' => '3001234567'],
            ['type' => 'x', 'id' => 'a', 'at' => 'b', 'name' => 'Nombre'],
            ['type' => 'x', 'id' => 'a', 'at' => 'b', 'ref' => ['lista']],
            ['type' => 'x', 'id' => 'a', 'at' => 'b', 'ref' => str_repeat('z', StreamEvent::MAX_VALUE_LENGTH + 1)],
            ['type' => 'x', 'id' => 'a', 'at' => 'b', 'ref' => "con\nsalto"],
            ['type' => 'x', 'id' => 'a', 'at' => 'b', 'ref' => 1.5],
            ['id' => 'a', 'at' => 'b'],
            ['type' => 'x', 'at' => 'b'],
            ['type' => 'x', 'id' => 'a'],
        ] as $data) {
            try {
                new StreamEvent('1', $data);
                $this->fail('se aceptó '.json_encode($data));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new StreamEvent('con:dos puntos', ['type' => 'x', 'id' => 'a', 'at' => 'b']);
    }

    public function test_el_vector_de_cursores_va_y_vuelve_y_rechaza_lo_que_no_tiene_su_forma(): void
    {
        $vector = ['ledger' => '12', 'voice.lab' => '1700000000-3'];
        $this->assertSame('ledger:12,voice.lab:1700000000-3', StreamCursors::format($vector));
        $this->assertSame($vector, StreamCursors::parse('ledger:12,voice.lab:1700000000-3'));
        $this->assertSame(12, StreamCursors::id('12'));
        $this->assertNull(StreamCursors::id('1700000000-3'));
        $this->assertNull(StreamCursors::id(str_repeat('9', 19)));

        foreach (['', 'ledger', 'ledger:', ':12', 'Ledger:1', 'ledger:1,ledger:2', 'ledger:a:b', 'ledger:'.str_repeat('1', 65), str_repeat('a:1,', 300)] as $malo) {
            try {
                StreamCursors::parse($malo);
                $this->fail("se aceptó «{$malo}»");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_los_temas_por_id_saben_contar_con_el_mismo_filtro_que_su_cola(): void
    {
        $contables = [];
        foreach ($this->registry()->all() as $name => $topic) {
            if ($topic instanceof StreamCountable) {
                $contables[] = $name;
                $this->assertSame(0, $topic->countThrough('0'), "{$name}: nada hasta 0");
                $this->assertSame(0, $topic->countThrough($topic->cursor()), "{$name}: tabla vacía");
            }
        }
        $this->assertSame(['ledger', 'voice.calls', 'voice.brake', 'voice.reconcile', 'voice.publications'], $contables);

        // El libro cuenta lo que esa persona puede ver: sin `audit.view`, un borrador ajeno no cuenta (mismo filtro que `since()`).
        $ana = $this->person();
        $this->saveDraft($ana)->assertOk();
        AdminRole::firstOrCreate(['name' => 'Solo ver Studio'], ['is_system' => false]);
        RolePermission::updateOrCreate(['role' => 'Solo ver Studio', 'permission' => 'ultron.view'], ['granted' => true]);
        app(RolePermissionPolicy::class)->flush();
        $bob = $this->person('Solo ver Studio', 'Bob');
        $this->assertFalse((new StudioAccess($bob))->seesEveryDraftEvent());

        $this->assertSame(1, StreamTopics::for(new StudioAccess($ana))->get('ledger')->countThrough('999'), 'Ana cuenta su borrador');
        $this->assertSame(0, StreamTopics::for(new StudioAccess($bob))->get('ledger')->countThrough('999'), 'Bob no cuenta el borrador de Ana');
        $this->assertSame(1, StreamTopics::for(new StudioAccess($this->person(Admin::ROLE_SUPER_ADMIN, 'Root')))->get('ledger')->countThrough('999'), 'quien audita lo cuenta');
    }

    public function test_los_temas_outreach_son_los_adaptadores_reales_y_ninguno_queda_pendiente(): void
    {
        $registro = $this->registry();
        foreach (['outreach.runs', 'outreach.jobs', 'outreach.outcomes', 'outreach.brakes'] as $name) {
            $tema = $registro->get($name);
            $this->assertInstanceOf(OutreachTopic::class, $tema, "{$name} es el adaptador real de B9-run (L9-59)");
            $this->assertSame(OutreachTopic::PERMISSION, $tema->permission(), "{$name}: el permiso es el del GET de lista de la ejecución");
            $this->assertStringStartsWith('GET api/admin/ultron-studio/outreach/', (string) $tema->listRoute());
        }
        foreach ($registro->all() as $name => $tema) {
            $this->assertNotInstanceOf(PendingTopic::class, $tema, "{$name} sigue siendo un tema pendiente: el canal no publica huecos");
        }
    }
}
