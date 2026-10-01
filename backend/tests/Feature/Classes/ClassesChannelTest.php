<?php

namespace Tests\Feature\Classes;

use App\Models\ClassEvent;
use App\Services\Classes\ClassesChannel;
use App\Services\Classes\ClassEventsFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Qué recibe, latido a latido, una conexión del canal de clases del CRM.
 *
 * El stream SSE no se puede abrir en PHPUnit (dura 25 s); su lógica vive en
 * ClassesChannel y se prueba aquí con una huella controlada.
 */
class ClassesChannelTest extends TestCase
{
    use RefreshDatabase;

    private string $huella = 'h0';

    private function hecho(string $type = 'booking.created'): int
    {
        return (int) ClassEvent::create([
            'type' => $type, 'class_id' => 7, 'session_date' => '2026-10-05',
            'source' => 'app', 'created_at' => now(),
        ])->id;
    }

    private function canal(bool $proto2): ClassesChannel
    {
        $apertura = ClassEventsFeed::open((int) (ClassEvent::max('id') ?? 0));

        return new ClassesChannel($apertura['cursor'], $proto2, fn (): string => $this->huella);
    }

    /** @param list<array{event:string, data:array, id:int|null}> $salida */
    private function eventos(array $salida): array
    {
        return array_map(fn ($s) => $s['event'].($s['id'] !== null ? '#'.$s['id'] : ''), $salida);
    }

    public function test_el_primer_latido_fija_la_huella_sin_dispararla(): void
    {
        $canal = $this->canal(false);
        $this->huella = 'h1';

        $this->assertSame([], $canal->tick());
        $this->assertSame([], $canal->tick(), 'Sin cambios desde la línea base.');
    }

    public function test_un_cambio_sin_hecho_llega_como_huella_a_los_dos_protocolos(): void
    {
        foreach ([false, true] as $proto2) {
            $this->huella = 'base';
            $canal = $this->canal($proto2);
            $canal->tick();

            $this->huella = 'borrado-directo';
            $salida = $canal->tick();

            $this->assertSame(['classes'], $this->eventos($salida), $proto2 ? 'proto=2' : 'CRM anterior');
            $this->assertSame(['sig' => 'borrado-directo'], $salida[0]['data']);
        }
    }

    public function test_un_cambio_con_hecho_no_repite_la_huella_con_proto_2(): void
    {
        $viejo = $this->canal(false);
        $nuevo = $this->canal(true);
        $viejo->tick();
        $nuevo->tick();

        $id = $this->hecho();
        $this->huella = 'reserva';

        $this->assertSame(["booking.created#{$id}", 'classes'], $this->eventos($viejo->tick()), 'El CRM anterior solo entiende la huella.');
        $this->assertSame(["booking.created#{$id}"], $this->eventos($nuevo->tick()));
    }

    public function test_tras_un_hecho_un_cambio_sin_hecho_en_otro_latido_si_llega(): void
    {
        $canal = $this->canal(true);
        $canal->tick();

        $id = $this->hecho();
        $this->huella = 'reserva';
        $this->assertSame(["booking.created#{$id}"], $this->eventos($canal->tick()));

        $this->huella = 'borrado-en-cascada';
        $this->assertSame(['classes'], $this->eventos($canal->tick()));
    }

    public function test_cada_hecho_sale_una_vez_aunque_se_relea_en_la_ventana(): void
    {
        $canal = $this->canal(true);
        $canal->tick();

        $a = $this->hecho();
        $b = $this->hecho('booking.cancelled');
        $this->assertSame(["booking.created#{$a}", "booking.cancelled#{$b}"], $this->eventos($canal->tick()));
        $this->assertSame([], $canal->tick(), 'La ventana de solape los relee, pero ya salieron.');

        $c = $this->hecho('class.updated');
        $this->assertSame(["class.updated#{$c}"], $this->eventos($canal->tick()));
    }

    public function test_un_hecho_que_confirma_tarde_con_id_menor_tambien_llega(): void
    {
        $canal = $this->canal(true);
        $canal->tick();

        // Dos transacciones: la del id menor confirma DESPUÉS que la del mayor.
        // Se simula quitando la fila menor hasta su «commit».
        $tarde = $this->hecho();
        $pronto = $this->hecho();
        $filaTarde = ClassEvent::find($tarde)->getAttributes();
        ClassEvent::whereKey($tarde)->delete();

        $this->assertSame(["booking.created#{$pronto}"], $this->eventos($canal->tick()));

        ClassEvent::query()->insert($filaTarde);
        $this->assertSame(["booking.created#{$tarde}"], $this->eventos($canal->tick()), 'Su id es menor que el cursor, pero cae en la ventana de solape.');
    }

    public function test_al_reconectar_llega_un_hecho_de_id_menor_que_confirmo_en_el_hueco(): void
    {
        $tarde = $this->hecho();
        $visto = $this->hecho();
        $fila = ClassEvent::find($tarde)->getAttributes();
        ClassEvent::whereKey($tarde)->delete(); // aún sin confirmar cuando el CRM vio el siguiente

        // El CRM vio hasta $visto y reconecta; en el hueco confirmó el de id menor.
        ClassEvent::query()->insert($fila);
        $apertura = ClassEventsFeed::open($visto);
        $this->assertFalse($apertura['resync']);
        $canal = new ClassesChannel(ClassEventsFeed::floorFor($apertura), true, fn (): string => $this->huella);

        $this->assertContains("booking.created#{$tarde}", $this->eventos($canal->tick()), 'El CRM descarta por id el ya visto.');
    }
}
