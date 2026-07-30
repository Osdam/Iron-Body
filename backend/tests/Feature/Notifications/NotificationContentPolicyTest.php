<?php

namespace Tests\Feature\Notifications;

use App\Services\Notifications\NotificationCatalog;
use App\Support\Notifications\NotificationCategory;
use Tests\TestCase;

/**
 * El contenido es la parte que puede hacer daño de verdad.
 *
 * Un fallo de código se ve en un log; una frase que promete resultados o que
 * suena a consejo médico puede acabar en una tienda de aplicaciones o en manos
 * de alguien que no debía recibirla. Estos tests vigilan el texto, no el flujo.
 */
class NotificationContentPolicyTest extends TestCase
{
    /**
     * Vocabulario prohibido: promesas médicas, garantías de resultado y
     * lenguaje comercial agresivo.
     */
    private const BANNED = [
        'cura', 'curar', 'previene', 'prevenir', 'trata ', 'tratamiento',
        'garantiz', 'asegura resultados', 'quema grasa', 'quemagrasa',
        'adelgaza', 'milagro', 'infalible', 'sin esfuerzo',
        'debes tomar', 'tienes que tomar', 'necesitas tomar',
    ];

    /** Marcas: el contenido educativo no recomienda productos concretos. */
    private const BRANDS = ['optimum', 'myprotein', 'gold standard', 'bsn', 'muscletech', 'hsn'];

    public function test_ningun_texto_promete_ni_receta(): void
    {
        foreach (NotificationCatalog::templates() as $t) {
            $haystack = mb_strtolower($t['title'].' '.$t['body']);

            foreach (self::BANNED as $word) {
                $this->assertStringNotContainsString(
                    $word,
                    $haystack,
                    "La plantilla {$t['key']} usa lenguaje prohibido: «{$word}».",
                );
            }
        }
    }

    public function test_ninguna_plantilla_nombra_una_marca(): void
    {
        foreach (NotificationCatalog::templates() as $t) {
            $haystack = mb_strtolower($t['title'].' '.$t['body']);

            foreach (self::BRANDS as $brand) {
                $this->assertStringNotContainsString(
                    $brand,
                    $haystack,
                    "La plantilla {$t['key']} menciona una marca.",
                );
            }
        }
    }

    public function test_todo_lo_de_suplementos_lleva_aviso_educativo(): void
    {
        $supplements = array_filter(
            NotificationCatalog::templates(),
            fn (array $t): bool => $t['category'] === NotificationCategory::SUPPLEMENTS,
        );

        $this->assertNotEmpty($supplements);

        foreach ($supplements as $t) {
            $this->assertNotEmpty($t['disclaimer'], "La plantilla {$t['key']} no lleva aviso educativo.");
            $this->assertStringContainsString('no consejo médico', (string) $t['disclaimer']);
            $this->assertNotNull(
                $t['supplement_kind'],
                "La plantilla {$t['key']} no declara de qué suplemento habla, así que "
                .'no se podría respetar el interruptor de su subtipo.',
            );
        }
    }

    public function test_lo_que_no_es_suplemento_no_lleva_aviso_de_suplemento(): void
    {
        foreach (NotificationCatalog::templates() as $t) {
            if ($t['category'] === NotificationCategory::SUPPLEMENTS) {
                continue;
            }

            $this->assertNull($t['disclaimer'], "La plantilla {$t['key']} lleva un aviso que no le corresponde.");
            $this->assertNull($t['supplement_kind']);
        }
    }

    public function test_estan_las_ocho_familias_pedidas(): void
    {
        $categories = array_column(NotificationCatalog::templates(), 'category');
        foreach ([
            NotificationCategory::MOTIVATION,
            NotificationCategory::RECOVERY,
            NotificationCategory::HYDRATION,
            NotificationCategory::SUPPLEMENTS,
        ] as $category) {
            $this->assertContains($category, $categories, "Falta contenido para {$category}.");
        }

        $kinds = array_filter(array_column(NotificationCatalog::templates(), 'supplement_kind'));
        foreach (NotificationCategory::SUPPLEMENT_KINDS as $kind) {
            $this->assertContains($kind, $kinds, "Falta contenido para el suplemento {$kind}.");
        }
    }

    public function test_hay_variedad_suficiente_para_no_repetirse(): void
    {
        $porCategoria = [];
        foreach (NotificationCatalog::templates() as $t) {
            $clave = $t['supplement_kind'] ?? $t['category'];
            $porCategoria[$clave] = ($porCategoria[$clave] ?? 0) + 1;
        }

        foreach ($porCategoria as $clave => $total) {
            $this->assertGreaterThanOrEqual(
                4,
                $total,
                "Solo hay {$total} plantillas para «{$clave}»: se repetiría demasiado pronto.",
            );
        }
    }

    public function test_las_claves_son_unicas(): void
    {
        $keys = array_column(NotificationCatalog::templates(), 'key');

        $this->assertSame(
            count($keys),
            count(array_unique($keys)),
            'Hay claves de plantilla repetidas: una sobrescribiría a la otra al sembrar.',
        );
    }

    public function test_los_textos_son_breves(): void
    {
        foreach (NotificationCatalog::templates() as $t) {
            $this->assertLessThanOrEqual(
                60,
                mb_strlen($t['title']),
                "El título de {$t['key']} se cortaría en la barra de notificaciones.",
            );
            $this->assertLessThanOrEqual(180, mb_strlen($t['body']), "El cuerpo de {$t['key']} es demasiado largo.");
        }
    }
}
