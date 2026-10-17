<?php

namespace Tests\Feature\Caja;

use App\Models\AuditLog;
use App\Models\ReceiptAgentTicket;
use App\Models\ReceiptStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EQUIPOS DE CAJA con el servicio local de impresión (contrato §5.3): alta,
 * ajustes, auditoría y rastro. Quién puede qué está en ReceiptAgentSecurityTest.
 */
class ReceiptStationsTest extends TestCase
{
    use BuildsCashReceipts;
    use BuildsReceiptAgent;
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $sup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->relojDelMostrador();
        $this->empresa();
        $this->cajero = $this->recepcion();
        $this->h = $this->actingAsAdmin($this->cajero);
        $this->sup = $this->supervisor();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_registrar_un_equipo_queda_auditado_y_no_se_duplica(): void
    {
        $clave = (string) Str::uuid();

        $res = $this->postJson('/api/admin/receipt-stations', ['station_key' => strtoupper($clave), 'name' => '  Recepción  '], $this->sup)
            ->assertStatus(201)
            ->assertJsonPath('ok', true);

        $this->assertSame([
            'station_key' => $clave,
            'name' => 'Recepción',
            'active' => true,
            'printer_name' => null,
            'columns' => 32,
            'codepage' => 'cp850',
            'feed_lines' => 4,
            'agent_version' => null,
            'agent_protocol' => null,
            'last_printer_name' => null,
            'last_seen_at' => null,
            'created_at' => now()->toIso8601String(),
            'created_by_name' => 'Marta Supervisora',
        ], $res->json('data'));

        $traza = AuditLog::where('entity', 'estacion_impresion')->where('action', 'create')->firstOrFail();
        $this->assertSame($clave, $traza->entity_id);
        $this->assertSame('Marta Supervisora', $traza->actor_name);

        $this->postJson('/api/admin/receipt-stations', ['station_key' => $clave, 'name' => 'Otra'], $this->sup)
            ->assertStatus(409)
            ->assertJsonPath('code', 'station_exists')
            ->assertJsonPath('data.name', 'Recepción');
        $this->assertSame(1, ReceiptStation::count());
        $this->assertSame(1, AuditLog::where('entity', 'estacion_impresion')->count());
    }

    public function test_validacion_de_alta_y_ajustes(): void
    {
        $this->postJson('/api/admin/receipt-stations', ['station_key' => 'no-uuid', 'name' => 'Caja'], $this->sup)->assertStatus(422)->assertJsonValidationErrors('station_key');
        $this->postJson('/api/admin/receipt-stations', ['station_key' => (string) Str::uuid(), 'name' => 'ab'], $this->sup)->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/admin/receipt-stations', ['station_key' => (string) Str::uuid(), 'name' => str_repeat('x', 61)], $this->sup)->assertStatus(422);
        $this->postJson('/api/admin/receipt-stations', ['station_key' => (string) Str::uuid(), 'name' => "Caja\u{001B}[2J"], $this->sup)->assertStatus(422);

        $e = $this->estacion();
        $url = "/api/admin/receipt-stations/{$e->station_key}";
        foreach ([
            ['columns' => 48], ['columns' => '32a'], ['codepage' => 'utf8'], ['feed_lines' => 9], ['feed_lines' => -1],
            ['printer_name' => str_repeat('p', 121)], ['printer_name' => "POS\u{0007}58"], ['name' => ''], ['active' => 'quizá'],
        ] as $malo) {
            $this->patchJson($url, $malo, $this->sup)->assertStatus(422);
        }
        $this->assertSame($e->getAttributes()['updated_at'], $e->fresh()->getAttributes()['updated_at']);
        $this->assertSame(0, AuditLog::where('entity', 'estacion_impresion')->count());
    }

    public function test_cambiar_ajustes_deja_el_antes_y_el_despues(): void
    {
        $e = $this->estacion(['printer_name' => 'POS-58']);

        $this->patchJson("/api/admin/receipt-stations/{$e->station_key}", [
            'name' => 'Caja principal', 'printer_name' => 'DIG-58IIK', 'columns' => 42, 'codepage' => 'ascii', 'feed_lines' => 0,
        ], $this->sup)
            ->assertOk()
            ->assertJsonPath('data.name', 'Caja principal')
            ->assertJsonPath('data.columns', 42)
            ->assertJsonPath('data.codepage', 'ascii')
            ->assertJsonPath('data.feed_lines', 0)
            ->assertJsonPath('data.printer_name', 'DIG-58IIK');

        $cambios = collect(AuditLog::where('action', 'update')->where('entity', 'estacion_impresion')->firstOrFail()->changes)->keyBy('field');
        $this->assertSame(['from' => 'POS-58', 'to' => 'DIG-58IIK'], array_intersect_key($cambios['printer_name'], array_flip(['from', 'to'])));
        $this->assertEqualsCanonicalizing(['name', 'printer_name', 'columns', 'codepage', 'feed_lines'], $cambios->keys()->all());

        // Lo mismo otra vez no es un cambio: sin traza nueva.
        $this->patchJson("/api/admin/receipt-stations/{$e->station_key}", ['columns' => 42], $this->sup)->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'update')->where('entity', 'estacion_impresion')->count());

        // Quitar la impresora.
        $this->patchJson("/api/admin/receipt-stations/{$e->station_key}", ['printer_name' => null], $this->sup)->assertOk()->assertJsonPath('data.printer_name', null);
    }

    public function test_un_equipo_desconocido_es_404(): void
    {
        foreach ([(string) Str::uuid(), 'no-es-un-uuid'] as $clave) {
            $this->patchJson("/api/admin/receipt-stations/{$clave}", ['name' => 'Caja'], $this->sup)->assertStatus(404)->assertJsonPath('code', 'station_not_registered');
            $this->postJson("/api/admin/receipt-stations/{$clave}/tickets", ['purpose' => 'test'], $this->sup)->assertStatus(404);
            $this->getJson("/api/admin/receipt-stations/{$clave}/events", $this->sup)->assertStatus(404);
        }
    }

    public function test_listado_con_el_estado_del_servicio(): void
    {
        $this->estacion(['name' => 'Recepción']);
        $this->estacion(['name' => 'Cafetería', 'active' => false]);
        config(['caja.receipts.agent.min_protocol' => 1]);

        $res = $this->getJson('/api/admin/receipt-stations', $this->h)->assertOk();

        $this->assertSame(['Cafetería', 'Recepción'], array_column($res->json('data'), 'name'));
        $this->assertSame(['enabled' => true, 'min_protocol' => 1, 'protocol' => 1], $res->json('agent'));
    }

    public function test_tickets_de_prueba_y_configuracion(): void
    {
        $e = $this->estacion();

        $prueba = $this->ticketDeEstacion($e, 'test', 'minimal', $this->sup)->assertStatus(201)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $prueba->json('data.token'));
        $this->assertSame(now()->addSeconds(300)->toIso8601String(), $prueba->json('data.expires_at'));
        $this->assertSame('minimal', ReceiptAgentTicket::firstOrFail()->variant);

        // Sin variante: la mínima (contrato §5.4).
        $this->ticketDeEstacion($e, 'test', null, $this->sup)->assertStatus(201);
        $this->assertSame('minimal', ReceiptAgentTicket::latest('id')->firstOrFail()->variant);

        $this->ticketDeEstacion($e, 'configure', null, $this->sup)->assertStatus(201)->assertJsonPath('data.purpose', 'configure');
        // Solo la configuración se audita (las pruebas no tocan nada).
        $this->assertSame(1, AuditLog::where('entity', 'estacion_impresion')->where('action', 'settings')->count());

        $this->ticketDeEstacion($e, 'print', null, $this->sup)->assertStatus(422);
        $this->ticketDeEstacion($e, 'test', 'grande', $this->sup)->assertStatus(422);

        $this->patchJson("/api/admin/receipt-stations/{$e->station_key}", ['active' => false], $this->sup)->assertOk();
        $this->ticketDeEstacion($e, 'test', null, $this->sup)->assertStatus(403)->assertJsonPath('code', 'station_inactive');
        $this->assertSame(3, ReceiptAgentTicket::count());

        // Las variantes de muestra se validan y se guardan tal cual (la más
        // larga, «sample_membership», tiene 17 caracteres: en PostgreSQL no
        // cabría en un varchar(16)).
        $this->patchJson("/api/admin/receipt-stations/{$e->station_key}", ['active' => true], $this->sup)->assertOk();
        foreach (['full', 'sample_1', 'sample_5', 'sample_20', 'sample_membership'] as $variante) {
            $this->ticketDeEstacion($e, 'test', $variante, $this->sup)->assertStatus(201);
            $this->assertSame($variante, ReceiptAgentTicket::latest('id')->firstOrFail()->variant);
        }
        $this->ticketDeEstacion($e, 'test', 'sample_2', $this->sup)->assertStatus(422)->assertJsonValidationErrors('variant');
    }

    public function test_eventos_del_equipo_con_el_recibo_y_el_resultado(): void
    {
        $e = $this->estacion();
        $pago = $this->cobrar($this->socio(), $this->plan());
        $recibo = $this->emitir('payment', $pago->id)->json('data.0');
        $token = $this->imprimirConAgente($recibo['uuid'], $e)->json('data.agent_ticket.token');
        $this->canjear($token, $e, ['version' => '1.0.1', 'printer_name' => 'POS-58'])->assertOk();
        $this->reportar($token, $e, 'error', ['error_code' => 'printer_offline', 'message' => 'Impresora apagada', 'printer_name' => 'POS-58'])->assertOk();
        $this->ticketDeEstacion($e, 'test', null, $this->sup)->assertStatus(201);

        $eventos = $this->getJson("/api/admin/receipt-stations/{$e->station_key}/events?limit=1", $this->sup)->assertOk()->json('data');
        $this->assertCount(1, $eventos);
        $this->assertSame('test', $eventos[0]['purpose']);

        $todos = $this->getJson("/api/admin/receipt-stations/{$e->station_key}/events", $this->sup)->assertOk()->json('data');
        $this->assertSame([
            'purpose' => 'print',
            'issued_at' => now()->toIso8601String(),
            'issued_by_name' => 'Ana Recepción',
            'redeemed_at' => now()->toIso8601String(),
            'reported_at' => now()->toIso8601String(),
            'outcome' => 'error',
            'error_code' => 'printer_offline',
            'error_message' => 'Impresora apagada',
            'receipt_number' => $recibo['number'],
            'agent_version' => '1.0.1',
            'printer_name' => 'POS-58',
        ], $todos[1]);

        $this->getJson("/api/admin/receipt-stations/{$e->station_key}/events?limit=101", $this->sup)->assertStatus(422);
        // Recepción imprime pero no lee el rastro del equipo (M-5).
        $this->getJson("/api/admin/receipt-stations/{$e->station_key}/events", $this->h)->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.gym.manage | cash.products.manage');
    }
}
