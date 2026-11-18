<?php

namespace Tests\Feature\Notifications;

use App\Models\Notification;
use App\Services\Fcm\FcmHttpV1Client;
use App\Services\Fcm\FcmService;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * El push de un aviso al socio con `action_type=route` lleva `action_route`:
 * es lo que la app lee al tocar la notificación del sistema para abrir la
 * pantalla (p. ej. `/evaluation` en los avisos de valoración). Solo rutas
 * internas de la app; nunca una URL externa.
 */
class FcmPushRouteTest extends TestCase
{
    private function mensaje(array $atributos): array
    {
        $servicio = new FcmService(Mockery::mock(FcmHttpV1Client::class));
        $n = new Notification(array_merge(['title' => 'Título', 'message' => 'Cuerpo', 'type' => 'valuation', 'priority' => 'medium'], $atributos));
        $n->uuid = 'b6f1d3a0-0000-4000-8000-000000000001';
        $m = new ReflectionMethod(FcmService::class, 'buildMessage');

        return $m->invoke($servicio, 'token-de-prueba', $n);
    }

    public function test_un_aviso_con_ruta_interna_lleva_action_route(): void
    {
        $data = $this->mensaje(['action_type' => 'route', 'action_url' => '/evaluation'])['data'];

        $this->assertSame('/evaluation', $data['action_route']);
        $this->assertSame('route', $data['action_type']);
        $this->assertSame('valuation', $data['type']);
    }

    public function test_sin_ruta_o_con_url_externa_no_lleva_action_route(): void
    {
        $this->assertArrayNotHasKey('action_route', $this->mensaje([])['data']);
        $this->assertArrayNotHasKey('action_route', $this->mensaje(['action_type' => 'url', 'action_url' => '/evaluation'])['data']);
        $this->assertArrayNotHasKey('action_route', $this->mensaje(['action_type' => 'route', 'action_url' => 'https://evil.example/x'])['data']);
        $this->assertArrayNotHasKey('action_route', $this->mensaje(['action_type' => 'route', 'action_url' => '//evil.example'])['data']);
    }
}
