<?php

namespace Tests\Feature\UltronStudio\Stream;

use App\Services\UltronStudio\Stream\StreamRateLimit;
use Illuminate\Support\Facades\RateLimiter;

/**
 * T24 (contrato H9, «Paquete SSE9»), el limitador por tique: el cubo con
 * nombre `studio-stream`, por huella del vale (nunca el vale), por persona
 * con la cabecera, y la respuesta 429 con la forma de los cupos de la voz.
 * Cuenta también con la bandera apagada (el limitador va antes).
 */
class UltronStudioStreamRateLimitTest extends StreamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ultron_studio_stream.rate.per_minute' => 3]);
    }

    public function test_el_limitador_tiene_nombre_y_su_cubo_es_la_huella_del_vale_o_la_persona(): void
    {
        $ana = $this->person();
        $ticket = $this->getJson('/api/admin/stream-ticket', $this->as($ana))->assertOk()->json('data.ticket');

        $this->open('topics=ledger&ticket='.urlencode($ticket), [])->assertOk();
        $this->assertNotNull(RateLimiter::limiter(StreamRateLimit::NAME), 'el limitador studio-stream queda registrado');

        $clave = StreamRateLimit::key(request()->duplicate(['ticket' => $ticket]));
        $this->assertStringStartsWith('studio-stream:ticket:', $clave);
        $this->assertStringNotContainsString($ticket, $clave);
        $this->assertStringNotContainsString(substr($ticket, 0, 12), $clave);
    }

    public function test_la_cuarta_conexion_con_el_mismo_vale_en_un_minuto_es_429_y_otro_vale_tiene_su_cubo(): void
    {
        $ana = $this->person();
        $ticket = $this->getJson('/api/admin/stream-ticket', $this->as($ana))->assertOk()->json('data.ticket');

        for ($i = 0; $i < 3; $i++) {
            $this->open('topics=ledger&ticket='.urlencode($ticket), [])->assertOk();
        }
        $respuesta = $this->getJson(self::URL.'?topics=ledger&ticket='.urlencode($ticket))
            ->assertStatus(429)
            ->assertJson(['ok' => false, 'code' => 'too_many_requests']);
        $this->assertGreaterThan(0, (int) $respuesta->headers->get('Retry-After'));
        $this->assertGreaterThan(0, (int) $respuesta->json('retry_after'));
        $this->assertStringNotContainsString('text/event-stream', (string) $respuesta->headers->get('Content-Type'));

        // Otro vale de la misma persona: otro cubo (el límite es por tique).
        $otro = $this->getJson('/api/admin/stream-ticket', $this->as($ana))->assertOk()->json('data.ticket');
        $this->open('topics=ledger&ticket='.urlencode($otro), [])->assertOk();
    }

    public function test_con_la_cabecera_el_cubo_es_la_persona_y_cuenta_tambien_con_la_bandera_apagada(): void
    {
        $ana = $this->person();
        $this->open('topics=ledger', $this->as($ana))->assertOk();
        $this->open('topics=ledger', $this->as($ana))->assertOk();
        config(['ultron_studio_stream.enabled' => false]);
        $this->getJson(self::URL.'?topics=ledger', $this->as($ana))->assertStatus(409);
        $this->getJson(self::URL.'?topics=ledger', $this->as($ana))->assertStatus(429);

        // Otra persona no comparte el cubo.
        $this->getJson(self::URL.'?topics=ledger', $this->as($this->person()))->assertStatus(409);
    }
}
