<?php

namespace Tests\Feature\Notifications;

use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationSlot as Slot;
use Carbon\CarbonImmutable;

/**
 * Las horas exactas del día, una por una.
 *
 * Este fichero existe porque las fronteras horarias son donde se rompen estos
 * sistemas: el minuto anterior a abrir, el minuto de cerrar, y el hueco entre
 * dos franjas. Cada una está escrita con su hora literal para que un cambio de
 * horario futuro tenga que pasar por aquí y se vea qué se está cambiando.
 */
class NotificationSlotTest extends NotificationTestCase
{
    private function bogota(string $hora): CarbonImmutable
    {
        return CarbonImmutable::parse("2026-07-30 {$hora}", 'America/Bogota')->setTimezone('UTC');
    }

    public static function horasDelDia(): array
    {
        return [
            'las 06:59 no tienen franja' => ['06:59', null],
            'las 07:00 abren la mañana' => ['07:00', Slot::MORNING],
            'las 07:01 siguen en la mañana' => ['07:01', Slot::MORNING],
            'las 10:59 siguen en la mañana' => ['10:59', Slot::MORNING],
            'las 11:00 abren la media mañana' => ['11:00', Slot::MIDMORNING],
            'las 14:59 siguen en la media mañana' => ['14:59', Slot::MIDMORNING],
            'las 15:00 abren la tarde' => ['15:00', Slot::AFTERNOON],
            'las 18:59 siguen en la tarde' => ['18:59', Slot::AFTERNOON],
            'las 19:00 abren la noche' => ['19:00', Slot::EVENING],
            'las 21:29 siguen en la noche' => ['21:29', Slot::EVENING],
            'las 21:30 abren el cierre' => ['21:30', Slot::NIGHT],
            'las 21:45 son el disparo del cierre' => ['21:45', Slot::NIGHT],
            'las 21:59 aun son del cierre' => ['21:59', Slot::NIGHT],
            'las 22:00 ya no tienen franja' => ['22:00', null],
            'las 23:30 tampoco' => ['23:30', null],
            'las 03:00 tampoco' => ['03:00', null],
        ];
    }

    /**
     * @dataProvider horasDelDia
     */
    public function test_cada_hora_cae_en_su_franja(string $hora, ?string $esperada): void
    {
        $this->assertSame(
            $esperada,
            Slot::at($this->bogota($hora)),
            "Las {$hora} de Neiva no cayeron donde debían.",
        );
    }

    public function test_la_hora_se_lee_en_neiva_y_no_en_el_servidor(): void
    {
        // Las 22:00 UTC son las 17:00 de Neiva: dentro de la franja de tarde.
        // Si el sistema leyera la hora del servidor, las daría por cerradas.
        $instante = CarbonImmutable::parse('2026-07-30 22:00:00', 'UTC');

        $this->assertSame(Slot::AFTERNOON, Slot::at($instante));
    }

    public function test_las_cinco_franjas_disparan_a_las_horas_pactadas(): void
    {
        $horas = array_column(Slot::schedule(), 'at');

        $this->assertSame(['07:00', '11:00', '15:00', '19:00', '21:45'], $horas);
    }

    public function test_el_cierre_dispara_antes_de_las_22_para_aguantar_un_retraso(): void
    {
        $cierre = collect(Slot::schedule())->firstWhere('slot', Slot::NIGHT);

        $minutosDeMargen = 22 * 60 - ($cierre['hour'] * 60 + $cierre['minute']);

        $this->assertGreaterThanOrEqual(
            15,
            $minutosDeMargen,
            'Sin margen, un retraso de red convierte el último aviso del día en un envío bloqueado.',
        );
    }

    public function test_cada_franja_tiene_categorias_y_ninguna_repite_la_lista_entera(): void
    {
        $listas = [];
        foreach (Slot::ALL as $slot) {
            $categorias = Slot::categoriesFor($slot);
            $this->assertNotEmpty($categorias, "La franja {$slot} se quedó sin categorías.");
            $listas[] = implode(',', $categorias);
        }

        $this->assertCount(
            count($listas),
            array_unique($listas),
            'Dos franjas con la misma lista de categorías no aportan variedad.',
        );
    }

    public function test_el_cierre_del_dia_no_habla_de_entrenar_ni_de_estimulantes(): void
    {
        $categorias = Slot::categoriesFor(Slot::NIGHT);

        $this->assertNotContains(
            NotificationCategory::SUPPLEMENTS,
            $categorias,
            'A las 21:45 no toca hablar de preentreno.',
        );
    }

    public function test_la_franja_anterior_encadena_el_dia(): void
    {
        $this->assertNull(Slot::previous(Slot::MORNING), 'La mañana no tiene nada antes.');
        $this->assertSame(Slot::MORNING, Slot::previous(Slot::MIDMORNING));
        $this->assertSame(Slot::MIDMORNING, Slot::previous(Slot::AFTERNOON));
        $this->assertSame(Slot::AFTERNOON, Slot::previous(Slot::EVENING));
        $this->assertSame(Slot::EVENING, Slot::previous(Slot::NIGHT));
    }
}
