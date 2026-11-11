<?php

namespace Tests\Feature\UltronStudio\Stream;

use App\Models\Admin;
use App\Services\UltronStudio\Stream\StreamRateLimit;
use Illuminate\Support\Facades\RateLimiter;

/**
 * T24 (contrato H9, «Paquete SSE9»), el limitador del canal: el cubo con
 * nombre `studio-stream`, por PERSONA —la de la sesión del vale, leída sin
 * canjearlo, o la de la cabecera; nunca el vale en la clave—, y la respuesta
 * 429 con la forma de los cupos de la voz. Cuenta también con la bandera
 * apagada (el limitador va antes).
 *
 * Hasta P9-25 el cubo era el vale. El vale es ahora de un solo uso y el CRM
 * estrena uno en cada reconexión: un cubo por vale no frenaría un bucle.
 */
class UltronStudioStreamRateLimitTest extends StreamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ultron_studio_stream.rate.per_minute' => 3]);
    }

    private function vale(Admin $quien): string
    {
        return $this->getJson('/api/admin/stream-ticket', $this->as($quien))->assertOk()->json('data.ticket');
    }

    public function test_el_limitador_tiene_nombre_y_su_cubo_es_la_persona_del_vale_o_de_la_cabecera(): void
    {
        $ana = $this->person();
        $ticket = $this->vale($ana);

        $this->open('topics=ledger&ticket='.urlencode($ticket), [])->assertOk();
        $this->assertNotNull(RateLimiter::limiter(StreamRateLimit::NAME), 'el limitador studio-stream queda registrado');

        // Leer la persona no gasta el vale, y el vale no entra en la clave.
        $nuevo = $this->vale($ana);
        $clave = StreamRateLimit::key(request()->duplicate(['ticket' => $nuevo]));
        $this->assertSame('studio-stream:admin:'.$ana->id, $clave);
        $this->assertStringNotContainsString(substr($nuevo, 0, 12), $clave);
        $this->open('topics=ledger&ticket='.urlencode($nuevo), [])->assertOk();

        // Un vale ilegible no es una credencial: el cubo de su red.
        $this->assertStringStartsWith('studio-stream:anon:', StreamRateLimit::key(request()->duplicate(['ticket' => 'no-es-un-vale'])));
    }

    public function test_la_cuarta_conexion_de_una_persona_en_un_minuto_es_429_aunque_cada_una_estrene_vale(): void
    {
        $ana = $this->person();

        // Como el CRM: cada conexión con su vale nuevo. Estrenar vale no estrena cupo.
        for ($i = 0; $i < 3; $i++) {
            $this->open('topics=ledger&ticket='.urlencode($this->vale($ana)), [])->assertOk();
        }
        $respuesta = $this->getJson(self::URL.'?topics=ledger&ticket='.urlencode($this->vale($ana)))
            ->assertStatus(429)
            ->assertJson(['ok' => false, 'code' => 'too_many_requests']);
        $this->assertGreaterThan(0, (int) $respuesta->headers->get('Retry-After'));
        $this->assertGreaterThan(0, (int) $respuesta->json('retry_after'));
        $this->assertStringNotContainsString('text/event-stream', (string) $respuesta->headers->get('Content-Type'));

        // Otra persona no comparte el cubo.
        $this->open('topics=ledger&ticket='.urlencode($this->vale($this->person())), [])->assertOk();
    }

    public function test_repetir_un_vale_gastado_es_403_y_el_bucle_acaba_en_429(): void
    {
        $ana = $this->person();
        $ticket = $this->vale($ana);

        // El vale es de un solo uso (P9-25): la primera conexión lo gasta y las
        // siguientes son 403. El limitador va antes que la puerta; un vale gastado
        // ya no es una credencial, así que el bucle de reconexión con él se come el
        // cupo ANÓNIMO de su red (`rate.anonymous_per_minute`), no el de la dueña, y
        // acaba en 429 (lead, cierre de P9-25: coherente con la prueba siguiente).
        config(['ultron_studio_stream.rate.anonymous_per_minute' => 3]);
        $this->open('topics=ledger&ticket='.urlencode($ticket), [])->assertOk();
        for ($i = 0; $i < 3; $i++) {
            $this->getJson(self::URL.'?topics=ledger&ticket='.urlencode($ticket))
                ->assertStatus(403)
                ->assertJsonPath('code', 'admin_ticket_invalid');
        }
        $this->getJson(self::URL.'?topics=ledger&ticket='.urlencode($ticket))->assertStatus(429);
        // La dueña conserva su cupo: una conexión nueva con un vale nuevo sigue abriendo.
        $this->open('topics=ledger&ticket='.urlencode($this->vale($ana)), [])->assertOk();
    }

    public function test_quien_repite_un_vale_gastado_del_log_no_agota_el_cupo_de_su_dueno(): void
    {
        $ana = $this->person();
        $gastado = $this->vale($ana);
        $this->open('topics=ledger&ticket='.urlencode($gastado), [])->assertOk(); // 1 de 3 en el cubo de Ana

        // Alguien repite el vale leído del access.log: 403, y cuenta en el cubo de SU red, no en el de Ana.
        for ($i = 0; $i < 5; $i++) {
            $this->getJson(self::URL.'?topics=ledger&ticket='.urlencode($gastado))->assertStatus(403);
        }
        $this->assertStringStartsWith('studio-stream:anon:', StreamRateLimit::key(request()->duplicate(['ticket' => $gastado])));

        // A Ana le quedan sus otras dos conexiones del minuto.
        $this->open('topics=ledger&ticket='.urlencode($this->vale($ana)), [])->assertOk();
        $this->open('topics=ledger&ticket='.urlencode($this->vale($ana)), [])->assertOk();
        $this->getJson(self::URL.'?topics=ledger&ticket='.urlencode($this->vale($ana)))->assertStatus(429);
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
