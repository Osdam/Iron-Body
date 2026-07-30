<?php

namespace Tests\Feature\Notifications;

use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\PushChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El contrato que se rompió y nadie notó.
 *
 * El backend pedía `iron_body_high` y la app no creaba ese canal, así que
 * Android mandaba todo a su canal de respaldo, mudo. Ningún test lo vio porque
 * los dos lados eran correctos por separado: el error vivía justo entre ambos.
 *
 * Estos tests fijan esa frontera.
 */
class PushChannelContractTest extends TestCase
{
    /** Ruta del Kotlin que crea los canales, si el repo de la app está al lado. */
    private const APP_KOTLIN = __DIR__
        .'/../../../../../../APP/Iron_Body_App/android/app/src/main/kotlin/com/ironbodyneiva/app/IronBodyApplication.kt';

    public function test_toda_categoria_apunta_a_un_canal_declarado(): void
    {
        foreach (NotificationCategory::ALL as $category) {
            $this->assertContains(
                PushChannel::forCategory($category),
                PushChannel::ALL,
                "La categoría {$category} apunta a un canal que no existe en PushChannel::ALL.",
            );
        }
    }

    public function test_lo_urgente_va_por_un_canal_de_prioridad_alta(): void
    {
        foreach ([
            NotificationCategory::ACCOUNT_SECURITY,
            NotificationCategory::PAYMENTS,
            NotificationCategory::MEMBERSHIP,
            NotificationCategory::CLASSES,
        ] as $category) {
            $this->assertSame(
                'high',
                PushChannel::priorityForCategory($category),
                "La categoría {$category} debe entregarse con prioridad alta.",
            );
        }
    }

    public function test_lo_prescindible_no_gasta_prioridad_alta(): void
    {
        foreach ([
            NotificationCategory::MOTIVATION,
            NotificationCategory::HYDRATION,
            NotificationCategory::RECOVERY,
            NotificationCategory::SUPPLEMENTS,
            NotificationCategory::PROMOTIONS,
        ] as $category) {
            $this->assertSame(
                'normal',
                PushChannel::priorityForCategory($category),
                "La categoría {$category} no debe entregarse con prioridad alta.",
            );
        }
    }

    public function test_el_bloque_android_siempre_lleva_canal(): void
    {
        foreach (NotificationCategory::ALL as $category) {
            $block = PushChannel::androidBlock($category);

            $this->assertArrayHasKey('priority', $block);
            $this->assertNotEmpty(
                $block['notification']['channel_id'] ?? null,
                "Sin channel_id, Android descarta el aviso de {$category}.",
            );
        }
    }

    /**
     * Comprobación cruzada con la app: cada canal que pide el backend tiene que
     * existir en el Kotlin que los crea.
     *
     * Se salta si el repo de la app no está al lado (CI del backend solo). No es
     * una excusa para no tenerlo: en el equipo de trabajo los tres repos son
     * hermanos, que es donde este desajuste se produce y donde hay que cazarlo.
     */
    #[DataProvider('channels')]
    public function test_el_canal_existe_en_la_app_android(string $channel): void
    {
        if (! is_file(self::APP_KOTLIN)) {
            $this->markTestSkipped('El repositorio de la app no está disponible junto al backend.');
        }

        $kotlin = (string) file_get_contents(self::APP_KOTLIN);

        $this->assertStringContainsString(
            '"'.$channel.'"',
            $kotlin,
            "El backend pide el canal {$channel} pero IronBodyApplication.kt no lo crea: "
            .'Android descartaría o silenciaría esas notificaciones.',
        );
    }

    /** @return array<string,array{0:string}> */
    public static function channels(): array
    {
        $cases = [];
        foreach (PushChannel::ALL as $channel) {
            $cases[$channel] = [$channel];
        }

        return $cases;
    }
}
