<?php

namespace Tests\Feature\Valuations;

use App\Exceptions\ValuationException;
use App\Models\Admin;
use App\Models\Member;
use App\Models\Trainer;
use App\Models\ValuationAppointment;
use App\Models\ValuationAppointmentEvent;
use App\Models\ValuationAvailabilityBlock;
use App\Services\Valuations\ValuationTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * La ENTRADA de la agenda de valoraciones (revisión de seguridad V-2, V-3, V-4).
 *
 *  · V-2: todo texto libre es UTF-8 válido, sin NUL ni controles → si no, 422
 *    `validation_failed` (antes: 500 en PostgreSQL; en SQLite la fila se
 *    guardaba y rompía la lista para todos). También con un cuerpo
 *    form-urlencoded, que es por donde llegan los bytes crudos.
 *  · V-3: años absurdos → 422, no 500.
 *  · V-4: ISO 8601 ESTRICTO. `YYYY-MM-DD` para fechas y
 *    `YYYY-MM-DDTHH:MM[:SS][Z|±HH:MM]` para instantes (sin desfase = Bogotá):
 *    ni «+1 day», ni zonas por nombre, ni desbordes (02-31).
 *
 * Corre igual en SQLite y en PostgreSQL.
 */
class ValuationInputValidationTest extends TestCase
{
    use RefreshDatabase;
    use ValuationFixtures;

    private Trainer $andres;

    private Member $laura;

    private Admin $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-10-19 08:00');
        $this->andres = $this->trainer('Andrés Pérez');
        $this->everyDay($this->andres);
        $this->laura = $this->member('Laura Gómez');
        $this->root = $this->superAdmin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Texto que no se puede aceptar, por el motivo de la clave. El carácter va
     * EN MEDIO: al final, TrimStrings ya quita lo que Unicode llama espacio
     * (U+0085 incluido) antes de validar.
     */
    private const MALOS = [
        'utf8_invalido' => "Nota \xFF\xFE rota",
        'latin1' => "Valoraci\xF3n inicial",
        'nul' => "Antes\x00después",
        'escape_terminal' => "Hola \e[2J mundo",
        'campana' => "Hola \x07 mundo",
        'c1' => "Hola \u{0085} mundo",
        'bidi' => "Hola \u{202E}odraude",
    ];

    /** Cuerpo de formulario (bytes crudos, como los deja PHP al decodificar `%FF`). */
    private function form(string $metodo, string $uri, array $datos, ?Admin $quien = null): TestResponse
    {
        return $this->call($metodo, $uri, $datos, [], [], $this->transformHeadersToServerVars(
            $this->h($quien ?? $this->root) + ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded']
        ));
    }

    private function rechazado(TestResponse $r, string $campo): void
    {
        $r->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('code', 'validation_failed');
        $this->assertArrayHasKey($campo, (array) $r->json('errors'), $r->getContent());
    }

    private function cita(): ValuationAppointment
    {
        $u = $this->book($this->h($this->root), $this->laura, $this->andres, '2026-10-24T09:00:00-05:00')->assertCreated()->json('data.uuid');

        return $this->appointment($u);
    }

    public function test_crear_rechaza_texto_no_valido_en_notas_y_motivo_del_pasado(): void
    {
        foreach (self::MALOS as $motivo => $malo) {
            // form-urlencoded: los bytes llegan tal cual.
            $this->rechazado($this->form('POST', '/api/admin/valuations', $this->payload($this->laura, $this->andres, '2026-10-24T09:00:00-05:00', ['notes' => $malo])), 'notes');
            $this->rechazado($this->form('POST', '/api/admin/valuations', $this->payload($this->laura, $this->andres, '2026-10-12T09:00:00-05:00', ['allow_past' => '1', 'past_reason' => 'Motivo '.$malo])), 'past_reason');
            // JSON: lo que se puede escribir en JSON (NUL, controles) también.
            if (mb_check_encoding($malo, 'UTF-8')) {
                $this->rechazado($this->postJson('/api/admin/valuations', $this->payload($this->laura, $this->andres, '2026-10-24T09:00:00-05:00', ['notes' => $malo]), $this->h($this->root)), 'notes');
            }
        }
        $this->assertSame(0, ValuationAppointment::count(), 'nada se guardó');

        // Saltos de línea y tabuladores en las notas: sí (un <textarea>).
        $this->book($this->h($this->root), $this->laura, $this->andres, '2026-10-24T09:00:00-05:00', ['notes' => "Primera línea\n\tSegunda línea\r\nTercera"])
            ->assertCreated()->assertJsonPath('data.notes', "Primera línea\n\tSegunda línea\r\nTercera");
        // Y la lista sigue respondiendo.
        $this->getJson('/api/admin/valuations?from=2026-10-19T00:00:00-05:00&to=2026-10-30T00:00:00-05:00', $this->h($this->root))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_editar_reprogramar_cancelar_y_transiciones_rechazan_texto_no_valido(): void
    {
        $cita = $this->cita();
        $u = $cita->uuid;

        foreach (self::MALOS as $malo) {
            $this->rechazado($this->form('PATCH', "/api/admin/valuations/{$u}", ['expected_version' => 1, 'notes' => $malo]), 'notes');
            $this->rechazado($this->form('POST', "/api/admin/valuations/{$u}/reschedule", ['expected_version' => 1, 'starts_at' => '2026-10-24T10:00:00-05:00', 'reason' => 'Cambio '.$malo]), 'reason');
            $this->rechazado($this->form('POST', "/api/admin/valuations/{$u}/cancel", ['expected_version' => 1, 'reason' => 'Motivo '.$malo]), 'reason');
            $this->rechazado($this->form('POST', "/api/admin/valuations/{$u}/confirm", ['expected_version' => 1, 'note' => $malo]), 'note');
        }

        $cita->refresh();
        $this->assertSame(['scheduled', 1, null], [$cita->status, (int) $cita->version, $cita->notes], 'la cita no cambió');
        $this->assertSame(1, ValuationAppointmentEvent::where('appointment_id', $cita->id)->count(), 'solo el evento de crearla');
        $this->getJson("/api/admin/valuations/{$u}", $this->h($this->root))->assertOk();

        // Motivo de cancelación en varias líneas: sí.
        $this->postJson("/api/admin/valuations/{$u}/cancel", ['expected_version' => 1, 'reason' => "No puede venir.\nLlamó a recepción."], $this->h($this->root))
            ->assertOk()->assertJsonPath('data.cancellation_reason', "No puede venir.\nLlamó a recepción.");
    }

    public function test_bloqueo_busqueda_y_filtros_rechazan_texto_no_valido(): void
    {
        $bloque = fn (array $x) => array_merge(['starts_at' => '2026-10-26T08:00:00-05:00', 'ends_at' => '2026-10-26T09:00:00-05:00', 'kind' => 'break'], $x);

        foreach (self::MALOS as $malo) {
            $this->rechazado($this->form('POST', "/api/admin/valuations/trainers/{$this->andres->id}/blocks", $bloque(['reason' => 'Motivo '.$malo])), 'reason');
            $this->rechazado($this->form('GET', '/api/admin/valuations/members/search', ['q' => 'La'.$malo]), 'q');
            $this->rechazado($this->form('GET', '/api/admin/valuations', ['member_id' => $this->laura->id, 'status' => 'scheduled,'.$malo]), 'status');
            $this->rechazado($this->form('GET', '/api/admin/valuations', ['member_id' => $this->laura->id, 'type' => $malo]), 'type');
            $this->rechazado($this->form('POST', "/api/admin/valuations/trainers/{$this->andres->id}/blocks", $bloque(['kind' => $malo])), 'kind');
        }
        $this->assertSame(0, ValuationAvailabilityBlock::count());

        // El motivo del bloqueo es de una línea (un <input>): un salto de línea, no.
        $this->rechazado($this->postJson("/api/admin/valuations/trainers/{$this->andres->id}/blocks", $bloque(['reason' => "Médico\nmañana"]), $this->h($this->root)), 'reason');
        $this->postJson("/api/admin/valuations/trainers/{$this->andres->id}/blocks", $bloque(['reason' => 'Cita médica · revisión']), $this->h($this->root))->assertCreated();
        $this->getJson('/api/admin/valuations/members/search?q=laura', $this->h($this->root))->assertOk()->assertJsonPath('data.0.id', $this->laura->id);
    }

    // ── Fechas ──────────────────────────────────────────────────────────────

    /** Instantes que NO son la forma estricta (o son absurdos). */
    private const INSTANTES_MALOS = [
        '2026-10-21T09:00 +1 day',
        '2026-10-21T09:00:00 +1 day',
        '2026-10-21T09:00:00 Asia/Tokyo',
        '2026-10-21T09:00:00America/Bogota',
        '2026-10-21T09:00:00 EST',
        'tomorrow 09:00',
        'next monday',
        '@1792500000',
        '2026-02-31T09:00',
        '2026-02-29T09:00',
        '2026-13-01T09:00',
        '2026-10-21T24:00',
        '2026-10-21T09:60',
        '2026-10-21T09:00:60',
        '2026-10-21T9:00',
        '2026-10-21 09:00',
        '2026-10-21',
        '2026-10-21T09:00:00.000Z',
        '2026-10-21T09:00+05',
        '2026-10-21T09:00+0500',
        '2026-10-21T09:00+25:00',
        '2026-10-21T09:00-05:99',
        '2026-10-21t09:00z',
        '1999-12-31T23:59:00Z',
        '2101-01-01T09:00:00-05:00',
        '9999-12-31T09:00:00-05:00',
        '0001-01-01T00:00:00Z',
    ];

    public function test_el_parser_es_iso_8601_estricto(): void
    {
        foreach ([...self::INSTANTES_MALOS, "2026-10-21T09:00:00-05:00\n", ' 2026-10-21T09:00:00-05:00', "2026-10-21T09:00\x00"] as $malo) {
            try {
                ValuationTime::parseInstant($malo);
                $this->fail('tenía que rechazar '.json_encode($malo));
            } catch (ValuationException $e) {
                $this->assertSame([422, 'validation_failed'], [$e->status, $e->errorCode], json_encode($malo));
            }
        }

        $ok = [
            '2026-10-24T09:00' => '2026-10-24T14:00:00+00:00',             // sin desfase = Bogotá
            '2026-10-24T09:00:00' => '2026-10-24T14:00:00+00:00',
            '2026-10-24T09:00:00-05:00' => '2026-10-24T14:00:00+00:00',
            '2026-10-24T14:00Z' => '2026-10-24T14:00:00+00:00',
            '2026-10-24T14:00:00+00:00' => '2026-10-24T14:00:00+00:00',
            '2026-10-24T23:30:00+09:00' => '2026-10-24T14:30:00+00:00',
            '2028-02-29T09:00' => '2028-02-29T14:00:00+00:00',             // bisiesto
            '2000-01-01T05:00:00Z' => '2000-01-01T05:00:00+00:00',         // los bordes del rango
            '2100-12-31T18:59:59-05:00' => '2100-12-31T23:59:59+00:00',
        ];
        foreach ($ok as $entrada => $utc) {
            $this->assertSame($utc, ValuationTime::parseInstant($entrada)->toIso8601String(), $entrada);
        }
        // Bogotá a las 19:00 del 31-12-2100 ya es 2101 en UTC: fuera.
        $this->expectException(ValuationException::class);
        ValuationTime::parseInstant('2100-12-31T19:00:00-05:00');
    }

    public function test_fechas_sueltas_estrictas(): void
    {
        foreach (['2026-02-31', '2026-02-29', '9999-12-31', '0001-01-01', '1999-12-31', '2101-01-01', '2026-1-05', "2026-10-24\n", '2026-10-24T00:00', ''] as $malo) {
            $this->assertNull(ValuationTime::plainDate($malo), json_encode($malo));
        }
        $this->assertSame('2026-10-24', ValuationTime::plainDate('2026-10-24'));
        $this->assertSame('2000-01-01', ValuationTime::plainDate('2000-01-01'));
        $this->assertSame('2100-12-31', ValuationTime::plainDate('2100-12-31'));
    }

    /** V-3: `/slots` con un año extremo daba 500. */
    public function test_huecos_con_fechas_absurdas_dan_422(): void
    {
        $h = $this->h($this->root);
        foreach (['9999-12-31', '2101-01-01', '0001-01-01', '1999-12-31', '2026-02-31', '2026-10-24T00:00', '24/10/2026', 'mañana'] as $fecha) {
            $this->rechazado($this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date=".rawurlencode($fecha), $h), 'date');
        }
        $this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date=2026-10-24", $h)->assertOk()->assertJsonPath('data.date', '2026-10-24');
        $this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date=2100-12-31", $h)->assertOk();
    }

    public function test_crear_reprogramar_y_bloquear_rechazan_instantes_no_estrictos(): void
    {
        $h = $this->h($this->root);
        $u = $this->cita()->uuid;

        foreach (self::INSTANTES_MALOS as $malo) {
            $this->rechazado($this->book($h, $this->laura, $this->andres, $malo), 'starts_at');
            $this->rechazado($this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => 1, 'starts_at' => $malo], $h), 'starts_at');
            $this->rechazado($this->postJson("/api/admin/valuations/trainers/{$this->andres->id}/blocks", ['starts_at' => $malo, 'ends_at' => '2026-10-26T09:00:00-05:00', 'kind' => 'break'], $h), 'starts_at');
        }
        $this->assertSame(1, ValuationAppointment::count());
        $this->assertSame([1, '2026-10-24 14:00:00'], [(int) $this->appointment($u)->version, $this->appointment($u)->getRawOriginal('starts_at')]);
        $this->assertSame(0, ValuationAvailabilityBlock::count());

        // La forma estricta, con y sin desfase, sí.
        $this->book($h, $this->member('Pedro'), $this->andres, '2026-10-24T11:00')->assertCreated()->assertJsonPath('data.starts_at', '2026-10-24T16:00:00+00:00');
        $this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => 1, 'starts_at' => '2026-10-24T15:00Z'], $h)->assertOk()->assertJsonPath('data.local_start', '10:00');
    }

    public function test_rangos_de_lista_y_bloqueos_rechazan_instantes_no_estrictos(): void
    {
        $h = $this->h($this->root);
        $bien = '2026-10-19T00:00:00-05:00';

        foreach (['9999-01-01T00:00:00-05:00', '1999-12-31T00:00:00-05:00', '2026-10-19', '2026-10-19 00:00', '2026-10-19T00:00 +1 day', '2026-10-19T00:00:00 Asia/Tokyo', '2026-02-31T00:00'] as $malo) {
            $q = fn (string $desde, string $hasta) => 'from='.rawurlencode($desde).'&to='.rawurlencode($hasta);
            $this->rechazado($this->getJson('/api/admin/valuations?'.$q($malo, '2026-10-26T00:00:00-05:00'), $h), 'from');
            $this->rechazado($this->getJson('/api/admin/valuations?'.$q($bien, $malo), $h), 'to');
            $this->rechazado($this->getJson("/api/admin/valuations/trainers/{$this->andres->id}/blocks?".$q($malo, '2026-10-26T00:00:00-05:00'), $h), 'from');
            $this->rechazado($this->getJson("/api/admin/valuations/trainers/{$this->andres->id}/blocks?".$q($bien, $malo), $h), 'to');
        }

        $this->getJson('/api/admin/valuations?from='.rawurlencode($bien).'&to='.rawurlencode('2026-10-26T00:00:00-05:00'), $h)->assertOk();
        $this->getJson('/api/admin/valuations?from=2026-10-19T05:00Z&to=2026-10-26T05:00Z', $h)->assertOk();
    }
}
