<?php

namespace Tests\Feature\Notifications;

use App\Services\Notifications\NotificationDispatcher;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\PushChannel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Lo que viaja DENTRO del mensaje.
 *
 * Dos cosas se comprueban aquí: que la app pueda estilizarlo y rutearlo, y que
 * no se escape nada que no deba salir del servidor.
 */
class PushPayloadTest extends NotificationTestCase
{
    /** Captura el cuerpo del último POST a FCM. */
    private function lastMessage(): array
    {
        $captured = [];
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'fcm.googleapis.com')) {
                $captured = $request->data()['message'] ?? [];
            }
        }

        return $captured;
    }

    private function send(string $category, ?string $route = null): array
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        app(NotificationDispatcher::class)->dispatch(
            memberId: $member->id,
            category: $category,
            title: 'Título',
            body: 'Cuerpo del aviso.',
            actionRoute: $route,
            supplementKind: $category === NotificationCategory::SUPPLEMENTS
                ? NotificationCategory::SUPPLEMENT_CREATINE
                : null,
            now: CarbonImmutable::parse('2026-07-30 17:00:00', 'UTC'),
        );

        return $this->lastMessage();
    }

    public function test_lleva_el_tipo_que_la_app_sabe_estilizar(): void
    {
        $msg = $this->send(NotificationCategory::ACCOUNT_SECURITY);

        $this->assertSame('security', $msg['data']['type'] ?? null);
        $this->assertSame(NotificationCategory::ACCOUNT_SECURITY, $msg['data']['category'] ?? null);
    }

    public function test_lleva_siempre_canal_y_prioridad(): void
    {
        $msg = $this->send(NotificationCategory::PAYMENTS);

        $this->assertSame('iron_body_high', $msg['android']['notification']['channel_id'] ?? null);
        $this->assertSame('high', $msg['android']['priority'] ?? null);
    }

    public function test_lo_prescindible_va_por_el_canal_silencioso(): void
    {
        $msg = $this->send(NotificationCategory::MOTIVATION);

        $this->assertSame('iron_body_wellness', $msg['android']['notification']['channel_id'] ?? null);
        $this->assertSame('normal', $msg['android']['priority'] ?? null);
    }

    public function test_la_ruta_de_navegacion_viaja_para_el_tap(): void
    {
        $msg = $this->send(NotificationCategory::CLASSES, '/classes/42');

        $this->assertSame('/classes/42', $msg['data']['action_route'] ?? null);
        $this->assertSame('route', $msg['data']['action_type'] ?? null);
    }

    public function test_sin_ruta_no_se_inventa_una_accion(): void
    {
        $msg = $this->send(NotificationCategory::MOTIVATION);

        $this->assertArrayNotHasKey('action_route', $msg['data'] ?? []);
        $this->assertArrayNotHasKey('action_type', $msg['data'] ?? []);
    }

    public function test_el_iphone_recibe_lo_urgente_con_peso(): void
    {
        $msg = $this->send(NotificationCategory::ACCOUNT_SECURITY);

        $this->assertSame('10', $msg['apns']['headers']['apns-priority'] ?? null);
        $this->assertSame('active', $msg['apns']['payload']['aps']['interruption-level'] ?? null);
        $this->assertSame('default', $msg['apns']['payload']['aps']['sound'] ?? null);
    }

    public function test_el_iphone_recibe_lo_prescindible_en_silencio(): void
    {
        $msg = $this->send(NotificationCategory::MOTIVATION);

        $this->assertSame('5', $msg['apns']['headers']['apns-priority'] ?? null);
        $this->assertSame('passive', $msg['apns']['payload']['aps']['interruption-level'] ?? null);
        // Omitido, no vacío: iOS trata la cadena vacía como nombre de sonido.
        $this->assertArrayNotHasKey('sound', $msg['apns']['payload']['aps'] ?? []);
    }

    public function test_toda_categoria_lleva_bloque_apns(): void
    {
        foreach (NotificationCategory::ALL as $category) {
            $block = PushChannel::apnsBlock($category);

            $this->assertNotEmpty(
                $block['headers']['apns-priority'] ?? null,
                "Sin apns-priority, el iPhone entrega {$category} a criterio del sistema.",
            );
            $this->assertContains(
                $block['payload']['aps']['interruption-level'] ?? null,
                ['active', 'passive'],
                "La categoría {$category} debe declarar cómo interrumpe en iOS.",
            );
        }
    }

    public function test_android_e_ios_coinciden_en_lo_que_es_urgente(): void
    {
        foreach (NotificationCategory::ALL as $category) {
            $android = PushChannel::androidBlock($category);
            $apns = PushChannel::apnsBlock($category);

            $urgenteAndroid = $android['priority'] === 'high';
            $urgenteIos = $apns['headers']['apns-priority'] === '10';

            $this->assertSame(
                $urgenteAndroid,
                $urgenteIos,
                "La categoría {$category} es urgente en una plataforma y no en la otra.",
            );
        }
    }

    public function test_el_mensaje_no_lleva_datos_personales(): void
    {
        $msg = $this->send(NotificationCategory::SUPPLEMENTS);
        $raw = json_encode($msg);

        foreach (['document', 'email', 'phone', 'access_hash', 'member_id', 'birth_date'] as $prohibido) {
            $this->assertStringNotContainsString(
                $prohibido,
                $raw,
                "El mensaje FCM no debe contener «{$prohibido}».",
            );
        }
    }
}
