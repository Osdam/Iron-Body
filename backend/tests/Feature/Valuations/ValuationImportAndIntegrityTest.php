<?php

namespace Tests\Feature\Valuations;

use App\Models\Notification;
use App\Models\ValuationAppointment;
use App\Models\ValuationAppointmentEvent;
use App\Models\ValuationReminder;
use App\Services\Valuations\ValuationImporter;
use App\Services\Valuations\ValuationReminderProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El importador CSV (simulación por defecto, `--apply` en una transacción) y la
 * integridad: el flujo entero de la agenda no cambia ni una fila de pagos,
 * ventas, cuentas/planes de socios, asignaciones entrenador-socio ni clases.
 */
class ValuationImportAndIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use ValuationFixtures;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-10-19 08:00');
        $this->dir = sys_get_temp_dir().'/val-import-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function csv(string $contenido): string
    {
        $ruta = $this->dir.'/citas-'.uniqid().'.csv';
        file_put_contents($ruta, $contenido);

        return $ruta;
    }

    private function escenario(): array
    {
        $andres = $this->trainer('Andrés Pérez', ['document' => '80.012.345']);
        $this->everyDay($andres, '06:00', '21:00');
        $sofia = $this->trainer('Sofía Sin Doc', ['document' => null]);
        $this->everyDay($sofia, '06:00', '21:00');
        $this->trainer('Gemelo Uno', ['document' => '7777']);
        $this->trainer('Gemelo Dos', ['document' => '7-777']);
        $laura = $this->member('Laura Gómez', '2027-01-31', ['document_number' => '1075123456']);
        $pedro = $this->member('Pedro Ruiz', '2027-01-31', ['document_number' => '1075999888']);
        $existente = ValuationAppointment::create([
            'member_id' => $pedro->id, 'trainer_id' => $andres->id, 'type' => 'initial', 'status' => 'scheduled',
            'starts_at' => Carbon::parse('2026-10-30 10:00', 'America/Bogota')->utc(),
            'ends_at' => Carbon::parse('2026-10-30 11:00', 'America/Bogota')->utc(),
            'duration_minutes' => 60, 'source' => 'crm',
        ]);

        $ruta = $this->csv(implode("\n", [
            'member_document,trainer_document,date,time,duration_minutes,type,notes',
            '1075123456,80012345,2026-10-10,09:00,60,initial,Ya ocurrió',          // 2 en el pasado (válida solo con --include-past)
            '1.075.123.456,80012345,2026-10-28,10:00,,seguimiento,',               // 3 válida (futura)
            '9999999999,80012345,2026-10-28,12:00,60,initial,',                    // 4 socio no encontrado
            '1075123456,123,2026-10-29,12:00,60,initial,',                         // 5 entrenador no encontrado
            '1075123456,7777,2026-10-29,12:00,60,initial,',                        // 6 entrenador ambiguo
            '1075999888,80012345,2026-10-30,10:00,60,initial,',                    // 7 duplicado (en la base, del CRM)
            '1075123456,80012345,2026-10-30,10:30,60,initial,',                    // 8 conflicto (entrenador ocupado)
            '1075123456,80012345,2026-10-28,10:00,,follow_up,',                    // 9 duplicado (en el fichero)
            '1075123456,80012345,2026-10-28,10:30,30,initial,',                    // 10 conflicto (en el fichero)
            '1075123456,80012345,28/10/2026,10:00,60,initial,',                    // 11 inválida
            '1075123456,80012345,2026-10-31,25:00,60,initial,',                    // 12 inválida
            '1075123456,80012345,2026-10-31,10:00,47,initial,',                    // 13 inválida
            '1075123456,  sofía  sin doc ,2026-10-29,09:00,60,other,',             // 14 válida (entrenador por nombre)
            '1075123456,80012345,2026-10-31,05:00,60,initial,',                    // 15 conflicto (fuera de la disponibilidad)
        ]));

        return [$andres, $laura, $existente, $ruta, $sofia];
    }

    /** El informe por fila, sin lo que solo existe tras escribir (`uuid`) ni los instantes. */
    private static function informe(array $r): array
    {
        return array_map(fn ($f) => [$f['line'], $f['category'], $f['reason']], $r['rows']);
    }

    /** Lo que el importador tiene que decir de cada fila del escenario (sin --include-past). */
    private const INFORME = [
        [2, 'in_past', 'en el pasado (usa --include-past para importarla sin avisos)'],
        [3, 'valid', null],
        [4, 'member_not_found', 'socio no encontrado'],
        [5, 'trainer_not_found', 'entrenador no encontrado'],
        [6, 'trainer_ambiguous', 'documento de entrenador ambiguo'],
        [7, 'duplicate', 'ya existe esa cita (agendada en el CRM, Programada)'],
        [8, 'conflict', 'el entrenador ya tiene otra cita a esa hora'],
        [9, 'duplicate', 'repite la fila 3 del fichero'],
        [10, 'conflict', 'choca con la fila 3 del fichero'],
        [11, 'invalid', 'fecha no válida (YYYY-MM-DD)'],
        [12, 'invalid', 'hora no válida (HH:MM)'],
        [13, 'invalid', 'duración no válida (15..240, múltiplo de 5)'],
        [14, 'valid', null],
        [15, 'conflict', 'fuera de la disponibilidad del entrenador'],
    ];

    public function test_la_simulacion_informa_y_no_escribe(): void
    {
        [, , , $ruta] = $this->escenario();

        $this->artisan('valuations:import-csv', ['path' => $ruta])
            ->expectsOutputToContain('SIMULACIÓN')
            ->expectsOutputToContain('Se importarían 2 citas.')
            ->assertSuccessful();

        $this->assertSame(1, ValuationAppointment::count(), 'solo la que ya estaba');
        $this->assertSame(0, ValuationAppointmentEvent::count());
        $this->assertSame(0, ValuationReminder::count());

        $r = app(ValuationImporter::class)->run($ruta, false);
        $this->assertFalse($r['applied']);
        $this->assertSame(['valid' => 2, 'member_not_found' => 1, 'trainer_not_found' => 1, 'trainer_ambiguous' => 1, 'in_past' => 1, 'conflict' => 3, 'duplicate' => 2, 'invalid' => 3], $r['counts']);
        $this->assertSame(self::INFORME, self::informe($r), 'una línea por fila del fichero, con su motivo');
        $this->assertSame('3456', $r['rows'][0]['member_document_tail']);
        $this->assertStringNotContainsString('1075123456', json_encode(array_map(fn ($f) => array_diff_key($f, ['starts_at' => 1, 'ends_at' => 1]), $r['rows'])), 'el informe no lleva documentos enteros');

        // Con --include-past la fila pasada pasa a válida y nada más cambia.
        $conPasadas = app(ValuationImporter::class)->run($ruta, false, true);
        $esperado = self::INFORME;
        $esperado[0] = [2, 'valid', null];
        $this->assertSame($esperado, self::informe($conPasadas));
        $this->artisan('valuations:import-csv', ['path' => $ruta, '--include-past' => true])
            ->expectsOutputToContain('Se importarían 3 citas.')
            ->assertSuccessful();
        $this->assertSame(1, ValuationAppointment::count(), 'la simulación tampoco escribe con --include-past');
    }

    public function test_apply_escribe_las_futuras_sin_confirmacion_y_rechaza_las_pasadas(): void
    {
        [$andres, $laura, , $ruta, $sofia] = $this->escenario();
        $simulado = self::informe(app(ValuationImporter::class)->run($ruta, false));

        $this->artisan('valuations:import-csv', ['path' => $ruta, '--apply' => true])
            ->expectsOutputToContain('APLICAR')
            ->expectsOutputToContain('Importadas: 2 citas.')
            ->assertSuccessful();

        $importadas = ValuationAppointment::where('source', 'import')->orderBy('starts_at')->get();
        $this->assertCount(2, $importadas, 'la pasada NO se escribe sin --include-past');
        [$futura, $porNombre] = $importadas->all();
        $this->assertSame([$laura->id, $andres->id], [$futura->member_id, $futura->trainer_id]);
        $this->assertSame('2026-10-28 15:00:00', $futura->getRawOriginal('starts_at'), '10:00 Bogotá');
        $this->assertSame('follow_up', $futura->type);
        $this->assertSame(60, $futura->duration_minutes, 'duración por defecto');
        $this->assertSame('scheduled', $futura->status);
        $this->assertSame('Importación CSV', $futura->created_by_name);
        $this->assertNull($futura->client_request_id);
        $this->assertSame($sofia->id, $porNombre->trainer_id, 'entrenador sin documento, por su nombre completo');
        $this->assertSame('other', $porNombre->type);
        $this->assertSame(2, ValuationAppointmentEvent::where('action', 'imported')->count());

        // Futura: los recordatorios de siempre y los internos, NINGUNA confirmación.
        foreach ([$futura, $porNombre] as $cita) {
            $this->assertSame(
                ['internal_unattended', 'internal_upcoming', 'reminder_long', 'reminder_short'],
                ValuationReminder::where('appointment_id', $cita->id)->orderBy('kind')->pluck('kind')->all(),
            );
        }
        $this->assertSame(0, ValuationReminder::where('kind', 'confirmation')->count(), 'ninguna confirmación: ya se le dijo por Google');
        $this->assertSame(0, Notification::where('type', 'valuation')->count(), 'una importación no avisa a la bandeja');

        // El informe de --apply es el mismo, fila a fila, que el de la simulación.
        $this->assertSame(self::INFORME, $simulado);

        // Volver a aplicar el mismo fichero: lo importado es «duplicado», nada nuevo.
        $r = app(ValuationImporter::class)->run($ruta, true);
        $this->assertSame(0, $r['created']);
        $this->assertSame('duplicate', $r['rows'][1]['category']);
        $this->assertSame('ya existe esa cita (importada, Programada)', $r['rows'][1]['reason']);
        $this->assertSame('duplicate', $r['rows'][12]['category'], 'la de Sofía también');
        $this->assertSame(2, ValuationAppointment::where('source', 'import')->count());
    }

    public function test_include_past_importa_las_pasadas_sin_ningun_aviso(): void
    {
        [, , , $ruta] = $this->escenario();

        $this->artisan('valuations:import-csv', ['path' => $ruta, '--apply' => true, '--include-past' => true])
            ->expectsOutputToContain('--include-past')
            ->expectsOutputToContain('Importadas: 3 citas.')
            ->assertSuccessful();

        $pasada = ValuationAppointment::where('source', 'import')->orderBy('starts_at')->firstOrFail();
        $this->assertSame('2026-10-10 14:00:00', $pasada->getRawOriginal('starts_at'), '09:00 Bogotá');
        $this->assertSame('scheduled', $pasada->status, 'nada se completa solo');
        $this->assertSame('Ya ocurrió', $pasada->notes);
        $this->assertSame(0, ValuationReminder::where('appointment_id', $pasada->id)->count(), 'cita pasada: ningún aviso');
        $this->assertSame(1, ValuationAppointmentEvent::where('appointment_id', $pasada->id)->where('action', 'imported')->count());
        $this->assertSame(0, ValuationReminder::where('kind', 'confirmation')->count());

        // Reaplicar con --include-past: la pasada ya importada es «duplicado», no «en el pasado».
        $r = app(ValuationImporter::class)->run($ruta, true, true);
        $this->assertSame([0, 'duplicate', 'ya existe esa cita (importada, Programada)'], [$r['created'], $r['rows'][0]['category'], $r['rows'][0]['reason']]);
        $this->assertSame(3, ValuationAppointment::where('source', 'import')->count());
    }

    public function test_apply_es_todo_o_nada(): void
    {
        [, , , $ruta] = $this->escenario();
        $antes = [ValuationAppointment::count(), ValuationAppointmentEvent::count(), ValuationReminder::count()];

        // La segunda cita revienta a mitad: no puede quedar escrita la primera.
        $creadas = 0;
        ValuationAppointment::created(function () use (&$creadas): void {
            if (++$creadas === 2) {
                throw new \LogicException('fallo a mitad de la importación');
            }
        });

        try {
            app(ValuationImporter::class)->run($ruta, true, true);
            $this->fail('el fallo tenía que llegar hasta aquí');
        } catch (\LogicException $e) {
            $this->assertSame('fallo a mitad de la importación', $e->getMessage());
        }

        $this->assertSame(2, $creadas, 'llegó a crear la primera antes del fallo');
        $this->assertSame($antes, [ValuationAppointment::count(), ValuationAppointmentEvent::count(), ValuationReminder::count()], 'ni citas, ni eventos, ni avisos');
    }

    /**
     * V-6. El informe pinta campos crudos del CSV (fecha y hora): sin
     * caracteres de control (ESC, BEL, NUL) y sin que la consola interprete
     * etiquetas de formato.
     */
    public function test_el_informe_de_consola_no_pinta_controles_ni_etiquetas_del_csv(): void
    {
        $this->everyDay($this->trainer('Andrés Pérez', ['document' => '80012345']));
        $this->member('Laura Gómez', '2027-01-31', ['document_number' => '1075123456']);
        $ruta = $this->csv(implode("\n", [
            'member_document,trainer_document,date,time,duration_minutes,type,notes',
            "1075123456,80012345,\"2026\e[2J\e]0;titulo\x07-10-28<error>X</error>\",\"09:00\x00\x7F\u{202E}\",60,initial,",
        ])."\n");

        Artisan::call('valuations:import-csv', ['path' => $ruta]);
        $salida = Artisan::output();

        $this->assertStringContainsString('Fila inválida', $salida);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B-\x1F\x7F]/', $salida, 'ningún carácter de control llega a la terminal');
        $this->assertStringNotContainsString("\u{202E}", $salida, 'ni los de dirección');
        $this->assertStringContainsString('<error>X</error>', $salida, 'la etiqueta se pinta como texto, no se interpreta');
    }

    /** V-2 en el importador: bytes inválidos o controles son una fila inválida, no un fallo. */
    public function test_texto_no_valido_en_el_csv_es_una_fila_invalida(): void
    {
        $this->everyDay($this->trainer('Andrés Pérez', ['document' => '80012345']));
        $this->member('Laura Gómez', '2027-01-31', ['document_number' => '1075123456']);
        $ruta = $this->csv(implode("\n", [
            'member_document,trainer_document,date,time,duration_minutes,type,notes',
            "10751\xFF23456,80012345,2026-10-28,10:00,60,initial,",        // 2 documento con UTF-8 inválido
            "1075123456,Andr\xE9s P\xE9rez,2026-10-28,11:00,60,initial,",  // 3 nombre en Latin-1
            "1075123456,80012345,2026-10-28,12:00,60,initial,\"con\x00nul\"", // 4 NUL en las notas
            "1075123456,80012345,2026-10-28,13:00,60,initial,\"dos\nlíneas\"", // 5 válida: salto de línea
        ])."\n");

        $r = app(ValuationImporter::class)->run($ruta, true);

        $this->assertSame([
            [2, 'invalid', 'texto que no es UTF-8 válido'],
            [3, 'invalid', 'texto que no es UTF-8 válido'],
            [4, 'invalid', 'notas con caracteres no permitidos'],
            [5, 'valid', null],
        ], self::informe($r));
        $this->assertSame(1, $r['created']);
        $this->assertSame("dos\nlíneas", ValuationAppointment::where('source', 'import')->sole()->notes);
    }

    public function test_cabecera_incorrecta_bom_y_punto_y_coma(): void
    {
        $this->artisan('valuations:import-csv', ['path' => $this->csv("socio,entrenador\n1,2\n")])
            ->expectsOutputToContain('Cabecera incorrecta')
            ->assertFailed();
        $this->artisan('valuations:import-csv', ['path' => $this->dir.'/no-existe.csv'])->assertFailed();

        $this->everyDay($this->trainer('Andrés Pérez', ['document' => '80012345']));
        $this->member('Laura Gómez', '2027-01-31', ['document_number' => '1075123456']);
        $ruta = $this->csv("\xEF\xBB\xBFmember_document;trainer_document;date;time;duration_minutes;type;notes\n1075123456;80012345;2026-10-28;10:00;60;initial;\"Con; punto y coma\"\n");
        $r = app(ValuationImporter::class)->run($ruta, true);
        $this->assertSame(1, $r['created']);
        $this->assertSame('Con; punto y coma', ValuationAppointment::firstOrFail()->notes);
    }

    // ── Integridad: el flujo no toca nada ajeno ────────────────────────────

    /** @return array<string, string> tabla → huella de TODAS sus filas */
    private function huellas(): array
    {
        $out = [];
        foreach (['payments', 'product_sales', 'users', 'member_trainer_assignments', 'classes', 'class_reservations', 'receivables', 'cash_shifts', 'receipts', 'inventory_movements', 'marketing_appointments'] as $tabla) {
            $out[$tabla] = md5(json_encode(DB::table($tabla)->orderBy('id')->get()->all()));
        }

        return $out;
    }

    public function test_el_flujo_completo_no_cambia_pagos_ventas_planes_asignaciones_ni_clases(): void
    {
        $andres = $this->trainer('Andrés Pérez');
        $sofia = $this->trainer('Sofía Ríos');
        $this->everyDay($andres);
        $this->everyDay($sofia);
        $laura = $this->member('Laura Gómez');
        $this->assign($laura, $sofia);
        $this->klass($andres, ['day_of_week' => 'Sábado', 'start_time' => '07:00', 'end_time' => '08:00']);
        DB::table('users')->where('id', $laura->user_id)->update(['plan' => 'Plan Oro', 'membership_start_date' => '2026-10-01']);
        $pedro = $this->member('Pedro');
        $antes = $this->huellas();

        $root = $this->h($this->superAdmin());
        $rec = $this->reception();
        $this->settings(['member_app_enabled' => true]);

        $u = $this->book($rec, $laura, $andres, '2026-10-24T09:00:00-05:00')->assertCreated()->json('data.uuid');
        $this->postJson("/api/admin/valuations/{$u}/confirm", ['expected_version' => 1], $root)->assertOk();
        $this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => 2, 'starts_at' => '2026-10-24T10:00:00-05:00', 'trainer_id' => $sofia->id], $rec)->assertOk();
        $this->patchJson("/api/admin/valuations/{$u}", ['expected_version' => 3, 'notes' => 'nota'], $rec)->assertOk();
        $v = $this->book($rec, $pedro, $andres, '2026-10-25T09:00:00-05:00')->assertCreated()->json('data.uuid');
        $this->postJson("/api/admin/valuations/{$v}/cancel", ['expected_version' => 1, 'reason' => 'Cambió de idea'], $rec)->assertOk();
        $this->putJson("/api/admin/valuations/trainers/{$andres->id}/availability", ['rules' => [['weekday' => 6, 'start_time' => '06:00', 'end_time' => '20:00']]], $root)->assertOk();
        $this->postJson("/api/admin/valuations/trainers/{$sofia->id}/blocks", ['starts_at' => '2026-10-26T08:00:00-05:00', 'ends_at' => '2026-10-26T09:00:00-05:00', 'kind' => 'break'], $root)->assertCreated();
        foreach (['2026-10-23 10:00', '2026-10-24 09:00', '2026-10-24 09:45', '2026-10-24 10:00'] as $hora) {
            $this->clock($hora);
            app(ValuationReminderProcessor::class)->run();
        }
        $root = $this->h($this->superAdmin());
        $this->postJson("/api/admin/valuations/{$u}/start", ['expected_version' => 3], $root)->assertOk();
        $this->postJson("/api/admin/valuations/{$u}/complete", ['expected_version' => 4], $root)->assertOk();

        $this->assertSame($antes, $this->huellas(), 'ninguna fila ajena a la agenda cambió');
        $this->assertSame(1, DB::table('member_trainer_assignments')->count(), 'agendar con otro entrenador no asigna');
        $this->assertGreaterThan(0, Notification::where('type', 'valuation')->where('audience', 'member')->count(), 'el flujo sí avisó');
    }
}
