<?php

namespace Tests\Feature\Telephony;

use App\Models\Admin;
use App\Models\Voice\VoiceCall;
use App\Services\Telephony\OutboundGate;
use App\Services\Telephony\TelephonyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * TEST8-c H-4 (B8-h, cerrado por el lead): LA IDEMPOTENCIA DE LA SALIENTE SE
 * RESUELVE ANTES DE LA POLÍTICA. Repetir la misma petición (mismo
 * `client_request_id` de la misma persona para el mismo destino) devuelve 200
 * con la misma fila (DF-G5). Antes, `OutboundGate::decide()` evaluaba la
 * política también para la repetición y la propia llamada original contaba
 * para la frecuencia de 1 al día, así que el 200 no era alcanzable: el E2E
 * recibía 409 `[frequency_exceeded, duplicate_request]` y el CRM habría dicho
 * «no permitida» con la llamada en curso. La repetición no crea fila, no
 * despacha job y no consume frecuencia, cupo ni presupuesto. Sin red.
 */
class OutboundIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        // 10:00 de Bogotá, en UTC: dentro del horario legal de salientes.
        $this->travelTo(Carbon::parse('2026-11-09 15:00:00', 'UTC'));
        Http::preventStrayRequests();
        Queue::fake();
        $this->voiceWorld();
    }

    public function test_repetir_la_misma_peticion_devuelve_200_con_la_misma_fila_sin_pasar_por_la_politica(): void
    {
        $this->publishVoice();
        $canario = $this->activeVoiceCanary();
        $ana = $this->person(Admin::ROLE_SUPER_ADMIN, 'Ana Idempotente');
        // `client_request_id` es uuid en PostgreSQL (SQLite no lo exige): siempre un UUID real.
        $crid = (string) Str::uuid();
        $previa = $this->voiceCall('outbound', 'dialing', [
            'canary_number_uuid' => $canario, 'requested_by_admin_id' => $ana->id, 'client_request_id' => $crid,
        ]);
        $puerta = app(OutboundGate::class);

        // La misma petición dos veces (la primera con el identificador en mayúsculas: se normaliza).
        $r1 = $puerta->request($ana, OutboundGate::TARGET_CANARY, $canario, strtoupper($crid));
        $r2 = $puerta->request($ana, OutboundGate::TARGET_CANARY, $canario, $crid);

        foreach ([$r1, $r2] as $r) {
            $this->assertSame(200, $r['http']);
            $this->assertTrue($r['body']['ok']);
            $this->assertTrue($r['body']['duplicate']);
            $this->assertSame($previa->uuid, $r['body']['call']['uuid'], 'la misma fila, no una nueva');
            $this->assertArrayNotHasKey('reasons', $r['body'], 'no se evaluó la política: la original ya la pasó');
        }
        // Ni fila nueva, ni job, ni consumo: la frecuencia de 1 al día que la propia llamada ocupa no estorba.
        $this->assertSame(1, VoiceCall::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_el_mismo_identificador_para_otro_destino_sigue_siendo_un_conflicto(): void
    {
        $this->publishVoice();
        $canario = $this->activeVoiceCanary();
        $otro = $this->activeVoiceCanary('+573000000032');
        $ana = $this->person(Admin::ROLE_SUPER_ADMIN, 'Ana Reutiliza');
        $crid = (string) Str::uuid();
        $this->voiceCall('outbound', 'dialing', [
            'canary_number_uuid' => $canario, 'requested_by_admin_id' => $ana->id, 'client_request_id' => $crid,
        ]);

        try {
            app(OutboundGate::class)->request($ana, OutboundGate::TARGET_CANARY, $otro, $crid);
            $this->fail('el identificador reutilizado para otro destino tiene que ser un conflicto');
        } catch (TelephonyException $e) {
            $this->assertStringContainsString('otro destino', $e->getMessage());
        }
        $this->assertSame(1, VoiceCall::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_una_peticion_nueva_sigue_pasando_por_la_politica(): void
    {
        $this->publishVoice();
        $canario = $this->activeVoiceCanary();
        $ana = $this->person(Admin::ROLE_SUPER_ADMIN, 'Ana Nueva');

        // Sin fila previa: la política decide (con la constante cerrada, se deniega) y no se crea nada.
        $r = app(OutboundGate::class)->request($ana, OutboundGate::TARGET_CANARY, $canario, (string) Str::uuid());
        $this->assertSame(409, $r['http']);
        $this->assertSame('outbound_denied', $r['body']['code']);
        $this->assertContains('calls_disabled_in_code', $r['body']['reasons']);
        $this->assertNotContains('duplicate_request', $r['body']['reasons'], 'sin fila previa no hay duplicado');
        $this->assertSame(0, VoiceCall::query()->count());
        Queue::assertNothingPushed();
    }
}
