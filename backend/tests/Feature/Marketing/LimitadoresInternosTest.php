<?php

namespace Tests\Feature\Marketing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * UN VECINO RUIDOSO NO DEJA MUDO AL ASESOR.
 *
 * Nace de un incidente físico completo. El detector diario de cumplimiento
 * emitió 3.758 eventos en 28 segundos; n8n los relevó a `notify-member` a unas
 * veinte peticiones por segundo y vació el cubo de rate limit. Tres minutos
 * después llegaron tres mensajes de WhatsApp y sus llamadas a `/ai/decide` se
 * encontraron un 429. El agente no se equivocó: no llegó a enterarse de que
 * alguien había escrito.
 *
 * La causa era que `throttle:120,1` —el limitador anónimo de Laravel— construye
 * su clave con el DOMINIO y la IP, no con la ruta. Las dos puertas internas
 * compartían un único cubo, y todo lo que entra por n8n sale de la misma IP.
 *
 * Lo que se fija aquí es la separación, no los números: que una puerta se
 * quede sin presupuesto NO puede gastar el de la otra.
 */
class LimitadoresInternosTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('automation.internal_secret', self::SECRET);
        // Topes minúsculos: lo que se mide es el aislamiento, no la cifra.
        config()->set('automation.rate_limits.automation', 3);
        config()->set('automation.rate_limits.marketing_ai', 3);
        RateLimiter::clear('internal-automation');
        RateLimiter::clear('internal-marketing-ai');
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    /** Agota la puerta de automatización hasta que responde 429. */
    private function inundarAutomatizacion(): int
    {
        $rechazos = 0;

        foreach (range(1, 8) as $i) {
            $r = $this->postJson('/api/internal/automation/notify-member', [
                'member_id' => 1, 'type' => 'daily.compliance_missing',
                'title' => 'Aun puedes cumplir hoy', 'body' => 'Texto.',
            ], $this->h());

            if ($r->getStatusCode() === 429) {
                $rechazos++;
            }
        }

        return $rechazos;
    }

    public function test_an_automation_flood_does_not_silence_the_advisor(): void
    {
        $this->assertGreaterThan(0, $this->inundarAutomatizacion(), 'la inundación no llegó a agotar su propio cubo');

        // Y ahora el asesor. Da igual qué conteste —el contexto de un turno
        // inventado será 4xx— mientras NO sea el 429 del vecino.
        $r = $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => 999999, 'message_id' => 999999,
        ], $this->h());

        $this->assertNotSame(
            429,
            $r->getStatusCode(),
            'la avalancha de notificaciones dejó al asesor sin poder pedir su contexto',
        );

        // Y que llegó al endpoint de verdad: un 404 también «no es 429», y
        // dejaría esta prueba pasando sin medir nada.
        $this->assertSame(422, $r->getStatusCode(), 'la petición del asesor ni siquiera llegó al controlador');
    }

    /** Y al revés: el asesor tampoco puede dejar sin notificaciones a nadie. */
    public function test_the_advisor_does_not_starve_the_notifications(): void
    {
        foreach (range(1, 8) as $i) {
            $this->postJson('/api/internal/marketing/ai/decide', [
                'conversation_id' => 999999, 'message_id' => 999999,
            ], $this->h());
        }

        $r = $this->postJson('/api/internal/automation/notify-member', [
            'member_id' => 1, 'type' => 'daily.compliance_missing',
            'title' => 'Aun puedes cumplir hoy', 'body' => 'Texto.',
        ], $this->h());

        $this->assertNotSame(429, $r->getStatusCode(), 'el asesor se comió el cubo de las notificaciones');
    }

    /** Cada puerta sigue teniendo tope: esto no es abrir la mano. */
    public function test_each_door_still_has_a_ceiling(): void
    {
        $this->assertGreaterThan(0, $this->inundarAutomatizacion(), 'la puerta de automatización dejó de tener tope');
    }
}
