<?php

namespace Tests\Feature\Workforce;

use App\Models\ClassEvent;
use App\Services\Classes\ClassesChannel;
use App\Services\Classes\ClassEventsFeed;
use App\Support\Access\AuthorizationMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * El canal SSE del CRM entrega cada hecho solo a quien tiene su permiso.
 * Escenarios 22-25 del encargo en su parte de servidor; la reconexión y la
 * deduplicación del cliente las prueba el CRM.
 */
class WorkforceStreamAudienceTest extends TestCase
{
    use RefreshDatabase;

    private function hecho(string $type, ?string $audience, ?string $subject = null): ClassEvent
    {
        return ClassEvent::create([
            'type' => $type, 'class_id' => $audience === null ? 7 : null, 'session_date' => '2026-10-12',
            'source' => 'kiosk', 'reason' => 'clock_in', 'subject' => $subject, 'audience' => $audience, 'created_at' => now(),
        ]);
    }

    /** @param list<string> $permisos */
    private function canal(array $permisos): ClassesChannel
    {
        $apertura = ClassEventsFeed::open(null);

        return new ClassesChannel($apertura['cursor'], true, fn (): string => 'huella-fija', fn (string $p): bool => in_array($p, $permisos, true));
    }

    public function test_quien_solo_supervisa_personal_no_recibe_hechos_de_clases_ni_la_huella(): void
    {
        $canal = $this->canal(['workforce.view']);
        $canal->tick();
        $this->hecho('booking.created', null);
        $this->hecho('workday.updated', 'workforce.view', 'uuid-1');

        $tipos = array_column($canal->tick(), 'event');
        $this->assertSame(['workday.updated'], $tipos);
    }

    public function test_quien_solo_ve_clases_no_recibe_hechos_de_personal(): void
    {
        $canal = $this->canal(['classes.view']);
        $canal->tick();
        $this->hecho('workday.updated', 'workforce.view');
        $this->hecho('biometric.updated', 'biometrics.view');
        $this->hecho('class.updated', null);

        $this->assertSame(['class.updated'], array_column($canal->tick(), 'event'));
    }

    public function test_los_hechos_filtrados_no_atascan_la_ventana_de_lectura(): void
    {
        $canal = $this->canal(['classes.view']);
        $canal->tick();
        // Más hechos ajenos que lo que cabe en una lectura (LIMIT + OVERLAP)…
        for ($i = 0; $i < ClassEventsFeed::LIMIT + ClassEventsFeed::OVERLAP + 20; $i++) {
            $this->hecho('workday.updated', 'workforce.view');
        }
        $visible = $this->hecho('class.updated', null);

        // …y aun así el de clases llega en pocos latidos.
        $recibidos = [];
        for ($t = 0; $t < 5 && ! in_array($visible->id, $recibidos, true); $t++) {
            $recibidos = array_merge($recibidos, array_column($canal->tick(), 'id'));
        }
        $this->assertContains((int) $visible->id, $recibidos);
    }

    public function test_la_carga_es_un_uuid_y_un_motivo_sin_datos_de_personas(): void
    {
        $canal = $this->canal(['workforce.view']);
        $canal->tick();
        $this->hecho('workday.updated', 'workforce.view', 'b1c2');

        $salida = $canal->tick()[0];
        $this->assertSame(['id', 'type', 'class_id', 'session_date', 'source', 'reason', 'subject', 'at'], array_keys($salida['data']));
        $this->assertSame('b1c2', $salida['data']['subject']);
    }

    public function test_el_stream_lo_abre_quien_supervisa_personal_aunque_no_vea_clases(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/api/admin/classes/stream', 'GET'));
        $this->assertContains('workforce.view', (array) AuthorizationMap::resolve($route));
    }
}
