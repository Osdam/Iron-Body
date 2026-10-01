<?php

namespace Tests\Feature\Classes;

use App\Models\CatalogEvent;
use App\Models\ClassEvent;
use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MemberRealtimeEvent;
use App\Models\MyClass;
use App\Models\Trainer;
use App\Models\TrainerRealtimeEvent;
use App\Services\Classes\ClassBookingService;
use App\Services\Classes\ClassEventBus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * El bus de hechos de clases ({@see ClassEventBus}): a quién avisa cada hecho,
 * cuándo, y qué pasa cuando algo falla.
 *
 * Tres destinos y cada uno con lo suyo: el registro del CRM (`class_events`,
 * dentro de la transacción), el canal global de los socios (`catalog_events`,
 * tras el commit) y el canal del entrenador de la clase (tras el commit). Lo
 * que se mira aquí son esas filas, no qué métodos se llamaron.
 */
class ClassEventBusTest extends TestCase
{
    use RefreshDatabase;

    /** Lunes 05/10/2026 10:00 en Bogotá, como instante UTC (igual que producción). */
    private const AHORA_BOGOTA = '2026-10-05 10:00';

    /** Ocurrencia de una clase de los miércoles en esa semana. */
    private const MIERCOLES = '2026-10-07';

    private int $doc = 710100100;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::AHORA_BOGOTA, 'America/Bogota')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Utilería ────────────────────────────────────────────────────────────

    private function bus(): ClassEventBus
    {
        return app(ClassEventBus::class);
    }

    private function reservas(): ClassBookingService
    {
        return app(ClassBookingService::class);
    }

    private function entrenador(): Trainer
    {
        $doc = (string) $this->doc++;

        return Trainer::create(['full_name' => 'Coach '.$doc, 'document' => $doc, 'status' => 'active']);
    }

    private function clase(?Trainer $coach = null, array $extra = []): MyClass
    {
        return MyClass::create(array_merge([
            'name' => 'Spinning', 'type' => 'Spinning', 'trainer_id' => $coach?->id,
            'day_of_week' => 'Miércoles', 'start_time' => '19:00', 'end_time' => '20:00',
            'status' => 'active', 'max_capacity' => 20, 'allow_online_booking' => true,
        ], $extra));
    }

    /** El núcleo de reservas no mira el plan: basta con un socio activo. */
    private function socio(): Member
    {
        $doc = (string) $this->doc++;

        return Member::create([
            'full_name' => 'Socio '.$doc, 'document_number' => $doc, 'phone' => '+57300'.substr($doc, -7),
            'access_hash' => 'tok-'.$doc, 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    // ── El registro del CRM ─────────────────────────────────────────────────

    public function test_reservar_y_cancelar_dejan_cada_hecho_con_su_clase_fecha_origen_y_reserva(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();

        $this->assertSame(
            ClassBookingService::RESERVED,
            $this->reservas()->reserveOccurrence($socio, $clase, self::MIERCOLES, null, ClassEventBus::SOURCE_CRM),
        );
        $reserva = ClassReservation::sole();
        $this->reservas()->cancelOccurrence($socio, $clase, self::MIERCOLES, self::MIERCOLES, null, ClassEventBus::SOURCE_APP);

        $hechos = ClassEvent::orderBy('id')->get()->map(fn (ClassEvent $e) => [
            'type' => $e->type,
            'class_id' => $e->class_id,
            'session_date' => $e->session_date?->toDateString(),
            'source' => $e->source,
            'reservation_id' => $e->reservation_id,
            'reason' => $e->reason,
        ])->all();

        $this->assertSame([
            ['type' => 'booking.created', 'class_id' => $clase->id, 'session_date' => self::MIERCOLES,
                'source' => 'crm', 'reservation_id' => $reserva->id, 'reason' => null],
            ['type' => 'booking.cancelled', 'class_id' => $clase->id, 'session_date' => self::MIERCOLES,
                'source' => 'app', 'reservation_id' => $reserva->id, 'reason' => null],
        ], $hechos);
    }

    public function test_si_la_transaccion_exterior_se_revierte_no_queda_el_hecho_ni_sale_ningun_aviso(): void
    {
        $clase = $this->clase($this->entrenador());
        $socio = $this->socio();

        try {
            DB::transaction(function () use ($socio, $clase): void {
                $this->reservas()->reserveOccurrence($socio, $clase, self::MIERCOLES);
                throw new \RuntimeException('se revierte');
            });
            $this->fail('La transacción exterior tenía que revertirse.');
        } catch (\RuntimeException $e) {
            $this->assertSame('se revierte', $e->getMessage());
        }

        $this->assertSame(0, ClassReservation::count());
        $this->assertSame(0, ClassEvent::count(), 'El hecho vive en la transacción de la reserva: se revierte con ella.');
        $this->assertSame(0, CatalogEvent::count(), 'No se anuncia a los socios una reserva que no existe.');
        $this->assertSame(0, TrainerRealtimeEvent::count(), 'Ni al entrenador.');
        $this->assertSame(0, MemberRealtimeEvent::count());
    }

    // ── Avisos tras el commit ───────────────────────────────────────────────

    public function test_una_reserva_se_anuncia_tras_el_commit_a_todos_los_socios_y_al_entrenador(): void
    {
        $coach = $this->entrenador();
        $clase = $this->clase($coach);

        // Se mide DENTRO y se afirma FUERA: una aserción fallida con la
        // transacción abierta la dejaría colgando para las pruebas siguientes.
        DB::beginTransaction();
        try {
            $this->bus()->booking(ClassEventBus::BOOKING_CREATED, $clase, self::MIERCOLES, ClassEventBus::SOURCE_APP, 41);
            $hechosDentro = ClassEvent::count();
            $avisosDentro = CatalogEvent::count() + TrainerRealtimeEvent::count();
        } finally {
            DB::commit();
        }
        $this->assertSame(1, $hechosDentro, 'El hecho se escribe dentro de la transacción.');
        $this->assertSame(0, $avisosDentro, 'Antes del commit no se anuncia nada.');

        $global = CatalogEvent::sole();
        $this->assertSame('reservation.created', $global->type, 'La app publicada enruta `reservation.*` a Clases.');
        $this->assertSame($clase->id, $global->class_id);
        $this->assertSame(self::MIERCOLES, $global->session_date?->toDateString());
        $this->assertSame(['classes'], $global->changed);

        $delEntrenador = TrainerRealtimeEvent::sole();
        $this->assertSame($coach->id, (int) $delEntrenador->trainer_id);
        $this->assertSame('class.updated', $delEntrenador->type);
        $this->assertSame(['classes', 'booking.created'], $delEntrenador->changed);

        $this->assertSame(0, MemberRealtimeEvent::count(), 'Ni una fila por socio: el aviso va en el canal global.');
    }

    public function test_una_cancelacion_se_anuncia_como_reservation_cancelled(): void
    {
        $coach = $this->entrenador();
        $clase = $this->clase($coach);

        $this->bus()->booking(ClassEventBus::BOOKING_CANCELLED, $clase, self::MIERCOLES, ClassEventBus::SOURCE_APP, 41);

        $global = CatalogEvent::sole();
        $this->assertSame('reservation.cancelled', $global->type);
        $this->assertSame($clase->id, $global->class_id);
        $this->assertSame(self::MIERCOLES, $global->session_date?->toDateString());
        $this->assertSame(['classes', 'booking.cancelled'], TrainerRealtimeEvent::sole()->changed);
    }

    public function test_un_cambio_de_asistencia_solo_se_anuncia_al_socio_marcado_y_al_entrenador(): void
    {
        $coach = $this->entrenador();
        $clase = $this->clase($coach);
        $marcado = $this->socio();
        $otro = $this->socio();

        $this->bus()->booking(
            ClassEventBus::BOOKING_UPDATED, $clase, self::MIERCOLES, ClassEventBus::SOURCE_TRAINER, null, $marcado->id,
        );

        $suyo = MemberRealtimeEvent::sole();
        $this->assertSame($marcado->id, (int) $suyo->member_id);
        $this->assertSame('class.updated', $suyo->type);
        $this->assertSame(['classes'], $suyo->changed);
        $this->assertFalse(MemberRealtimeEvent::where('member_id', $otro->id)->exists(), 'La asistencia de uno no es asunto de los demás.');

        $delEntrenador = TrainerRealtimeEvent::sole();
        $this->assertSame($coach->id, (int) $delEntrenador->trainer_id);
        $this->assertSame('attendance.updated', $delEntrenador->type);
        $this->assertSame(['classes', 'booking.updated'], $delEntrenador->changed);

        $this->assertSame(0, CatalogEvent::count(), 'El cupo no cambió: el canal global no tiene nada que avisar.');
        $this->assertSame('booking.updated', ClassEvent::sole()->type, 'El CRM sí recibe el hecho.');
    }

    public function test_si_la_clase_cambia_de_entrenador_se_avisa_al_nuevo_y_al_anterior(): void
    {
        $anterior = $this->entrenador();
        $nuevo = $this->entrenador();
        $clase = $this->clase($nuevo);

        $this->bus()->classChanged($clase, 'class.updated', ClassEventBus::SOURCE_CRM, null, [$anterior->id]);

        $avisos = TrainerRealtimeEvent::orderBy('id')->get();
        $this->assertEqualsCanonicalizing(
            [$anterior->id, $nuevo->id],
            $avisos->map(fn (TrainerRealtimeEvent $a) => (int) $a->trainer_id)->all(),
            'El anterior tiene que dejar de ver la clase como suya; el nuevo, empezar a verla.',
        );
        foreach ($avisos as $aviso) {
            $this->assertSame('class.updated', $aviso->type);
            $this->assertSame(['classes', 'class.updated'], $aviso->changed);
        }

        $this->assertSame('class.updated', CatalogEvent::sole()->type);
        $hecho = ClassEvent::sole();
        $this->assertSame('class.updated', $hecho->type);
        $this->assertSame('class.updated', $hecho->reason);
        $this->assertSame('crm', $hecho->source);
        $this->assertNull($hecho->reservation_id);
    }

    public function test_una_clase_sin_entrenador_avisa_a_los_socios_y_a_nadie_mas(): void
    {
        $clase = $this->clase();

        $this->bus()->booking(ClassEventBus::BOOKING_CREATED, $clase, self::MIERCOLES, ClassEventBus::SOURCE_APP, 41);
        $this->bus()->classChanged($clase, 'class.updated', ClassEventBus::SOURCE_CRM);

        $this->assertSame(2, CatalogEvent::count());
        $this->assertSame(2, ClassEvent::count());
        $this->assertSame(0, TrainerRealtimeEvent::count());
    }

    // ── Lotes ───────────────────────────────────────────────────────────────

    public function test_un_lote_de_tres_reservas_de_dos_entrenadores_avisa_una_vez_a_los_socios_y_una_a_cada_entrenador(): void
    {
        $ana = $this->entrenador();
        $beto = $this->entrenador();
        $spinning = $this->clase($ana);
        $yoga = $this->clase($ana, ['name' => 'Yoga', 'day_of_week' => 'Jueves']);
        $boxeo = $this->clase($beto, ['name' => 'Boxeo', 'day_of_week' => 'Viernes']);
        $socio = $this->socio();

        $this->bus()->batch(function () use ($socio, $spinning, $yoga, $boxeo): void {
            $this->reservas()->reserveOccurrence($socio, $spinning, '2026-10-07');
            $this->reservas()->reserveOccurrence($socio, $yoga, '2026-10-08');
            $this->reservas()->reserveOccurrence($socio, $boxeo, '2026-10-09');
        });

        $this->assertSame(3, ClassEvent::where('type', 'booking.created')->count(), 'Cada reserva queda en el registro del CRM.');

        $global = CatalogEvent::sole();
        $this->assertSame('reservation.created', $global->type);
        $this->assertNull($global->class_id, 'Un aviso que cubre tres clases no puede decir que es de una sola.');
        $this->assertNull($global->session_date);

        $this->assertSame(['classes', 'booking.created'], TrainerRealtimeEvent::where('trainer_id', $ana->id)->sole()->changed);
        $this->assertSame(['classes', 'booking.created'], TrainerRealtimeEvent::where('trainer_id', $beto->id)->sole()->changed);
    }

    public function test_en_un_lote_el_aviso_del_entrenador_reune_todos_los_tipos_de_hecho(): void
    {
        $coach = $this->entrenador();
        $clase = $this->clase($coach);
        $entra = $this->socio();
        $sale = $this->socio();
        ClassReservation::create(['class_id' => $clase->id, 'member_id' => $sale->id, 'session_date' => self::MIERCOLES]);

        $this->bus()->batch(function () use ($clase, $entra, $sale): void {
            $this->reservas()->reserveOccurrence($entra, $clase, self::MIERCOLES);
            $this->reservas()->cancelOccurrence($sale, $clase, self::MIERCOLES, self::MIERCOLES);
            $this->bus()->classChanged($clase, 'class.updated', ClassEventBus::SOURCE_CRM);
        });

        $aviso = TrainerRealtimeEvent::sole();
        $this->assertSame(
            ['classes', 'booking.created', 'booking.cancelled', 'class.updated'],
            $aviso->changed,
            'Un solo aviso, pero con todo lo que cambió y sin repetir.',
        );
        $this->assertSame(1, CatalogEvent::count());
        $this->assertSame(3, ClassEvent::count());
    }

    public function test_un_lote_anidado_solo_avisa_al_cerrar_el_mas_externo(): void
    {
        $coach = $this->entrenador();
        $clase = $this->clase($coach);
        $uno = $this->socio();
        $dos = $this->socio();

        $this->bus()->batch(function () use ($clase, $uno, $dos): void {
            $this->bus()->batch(fn () => $this->reservas()->reserveOccurrence($uno, $clase, self::MIERCOLES));

            $this->assertSame(0, CatalogEvent::count(), 'Cerrar el lote interior no vacía nada: el exterior sigue abierto.');
            $this->assertSame(0, TrainerRealtimeEvent::count());

            $this->reservas()->reserveOccurrence($dos, $clase, self::MIERCOLES);
        });

        $this->assertSame(2, ClassEvent::count());
        $this->assertSame(1, CatalogEvent::count());
        $this->assertSame(1, TrainerRealtimeEvent::count());
    }

    public function test_si_un_lote_falla_a_mitad_lo_confirmado_se_anuncia_y_el_bus_vuelve_a_avisar_al_momento(): void
    {
        // El bus es un singleton: si un lote interrumpido lo dejara «dentro de
        // un lote», un proceso largo (cola, worker) dejaría de avisar para siempre.
        $clase = $this->clase($this->entrenador());
        $uno = $this->socio();
        $dos = $this->socio();

        try {
            $this->bus()->batch(function () use ($clase, $uno): void {
                $this->reservas()->reserveOccurrence($uno, $clase, self::MIERCOLES);
                throw new \RuntimeException('falla a mitad');
            });
            $this->fail('El lote tenía que propagar la excepción.');
        } catch (\RuntimeException $e) {
            $this->assertSame('falla a mitad', $e->getMessage());
        }

        $this->assertSame(1, CatalogEvent::count(), 'La reserva que sí se confirmó se anuncia.');

        $this->reservas()->reserveOccurrence($dos, $clase, self::MIERCOLES);
        $this->assertSame(2, CatalogEvent::count(), 'Fuera del lote, el siguiente hecho sale en el acto.');
    }

    // ── Si el registro falla ────────────────────────────────────────────────

    public function test_si_el_registro_no_se_puede_escribir_la_reserva_sigue_y_queda_constancia_en_el_log(): void
    {
        $clase = $this->clase();
        $socio = $this->socio();
        Schema::drop('class_events');
        Log::spy();

        $resultado = $this->reservas()->reserveOccurrence($socio, $clase, self::MIERCOLES);

        $this->assertSame(ClassBookingService::RESERVED, $resultado);
        $this->assertTrue(
            ClassReservation::where('member_id', $socio->id)->whereDate('session_date', self::MIERCOLES)->exists(),
            'La reserva no puede depender del registro del CRM.',
        );
        Log::shouldHaveReceived('warning')
            ->with('class_event.record_failed', Mockery::on(
                fn (array $ctx): bool => $ctx['type'] === 'booking.created' && $ctx['class_id'] === $clase->id,
            ))
            ->once();
        $this->assertTrue(
            CatalogEvent::where('type', 'reservation.created')->where('class_id', $clase->id)->exists(),
            'Los socios se enteran igual: el aviso no depende del registro.',
        );
    }
}
