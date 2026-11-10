<?php

namespace Tests\Feature\Outreach\Facts;

use App\Services\Outreach\Facts\SubjectRef;
use App\Services\Outreach\Facts\SubjectTimeline;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Feature\Outreach\Facts\Support\FactsWorld;
use Tests\Feature\Outreach\Schema\OutreachSchemaFixtures;
use Tests\TestCase;

/**
 * B9-facts · EL HISTORIAL UNIFICADO POR PERSONA (contrato H9, D9-9.6, L9-3):
 * un modelo de lectura sobre decisiones, trabajos y resultados del H9, en
 * orden temporal, paginado por un cursor estable, sin textos ni números.
 *
 * Las filas son REALES, del esquema de DB9 (migraciones
 * `2026_11_12_1000{01,02}`), construidas con los constructores de
 * `OutreachSchemaFixtures`: pasan por todos los CHECK y disparadores de la
 * base (sello de una versión viva, plantilla aprobada, reclamación del
 * asistido, matriz resultado → transición…). Antes de DB9 esta prueba creaba
 * un esquema provisional; ya no hace falta.
 */
class SubjectTimelineTest extends TestCase
{
    use FactsWorld;
    use OutreachSchemaFixtures;
    use RefreshDatabase;

    public function test_con_las_tablas_de_db9_el_historial_esta_disponible_y_una_persona_sin_historial_sale_vacia(): void
    {
        foreach (SubjectTimeline::TABLES as $t) {
            $this->assertTrue(Schema::hasTable($t), "DB9 tiene que haber creado {$t}.");
        }
        $this->assertSame(['entries' => [], 'next' => null, 'available' => true], (new SubjectTimeline)->forSubject('m:1'));
    }

    public function test_compone_decisiones_trabajos_y_resultados_en_orden_y_pagina_sin_perder_ni_repetir(): void
    {
        // Una estrategia con su versión en canario (cadena incluida) y una inscripción EN VIVO de m:7, a la espera.
        $vivo = $this->liveVersion();
        $estrategia = (string) DB::table('ultron_studio_strategies')->where('id', $vivo['strategy'])->value('uuid');
        $insc = $this->insertEnrollment($vivo, ['subject_key' => 'm:7', 'subject_ref' => SubjectRef::of('m:7'), 'member_id' => 7, 'mode' => 'live',
            'status' => 'waiting', 'next_due_at' => self::utc('2026-11-12 15:00:00'), 'enrolled_at' => self::utc('2026-11-09 10:00:00'), 'window_ends_at' => self::utc('2026-12-09 10:00:00')]);
        // Dos decisiones de m:7 (la inscripción y un aplazamiento N1 con reintento) y una negativa N2 de OTRA persona, sin inscripción.
        $this->insertDecision($insc, ['kind' => 'enroll', 'evaluated_at' => self::utc('2026-11-09 10:00:00')]);
        $this->insertDecision($insc, ['kind' => 'defer', 'level' => 'N1', 'reasons' => '["daily_contact_limit"]', 'retry_at' => self::utc('2026-11-10 13:00:00'),
            'evaluated_at' => self::utc('2026-11-09 11:00:00')]);
        $this->insertDecision($insc, ['enrollment_id' => null, 'subject_key' => 'm:8', 'subject_ref' => SubjectRef::of('m:8'), 'kind' => 'deny', 'level' => 'N2',
            'reasons' => '["do_not_contact"]', 'evaluated_at' => self::utc('2026-11-09 12:00:00')]);
        // Un trabajo asistido por WhatsApp del paso 0, planificado a la misma hora que el aplazamiento, que una persona reclamó e inició...
        $job = $this->insertAssistedJob($insc, ['not_before' => self::utc('2026-11-10 13:00:00'), 'expires_at' => self::utc('2026-11-10 18:00:00'), 'budget_day' => '2026-11-10',
            'created_at' => self::utc('2026-11-09 11:00:00')]);
        $this->walkJob($job, 'started', ['assisted_claimed_by_admin_id' => 1, 'assisted_claimed_by_name' => 'Ana', 'assisted_claimed_at' => self::utc('2026-11-10 13:30:00')]);
        // ...y el resultado que registró: sin respuesta, a la espera hasta el 12 (D9-10: `no_answer` admite `wait`, y solo `wait`, `next_step` y `retry_step` llevan hora).
        $this->insertOutcome($insc, ['job' => $job, 'outcome' => 'no_answer', 'source' => 'staff', 'source_key' => 'personal:'.Str::uuid(),
            'recorded_by_admin_id' => 1, 'recorded_by_name' => 'Ana', 'next_action' => 'wait', 'next_action_at' => self::utc('2026-11-12 15:00:00'),
            'occurred_at' => self::utc('2026-11-10 14:00:00')]);

        $sql = [];
        DB::listen(function (QueryExecuted $q) use (&$sql): void {
            $sql[] = $q->sql;
        });
        $todo = (new SubjectTimeline)->forSubject('m:7', 50);
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        $this->assertTrue($todo['available']);
        $this->assertSame(['outcome', 'decision', 'job', 'decision'], array_column($todo['entries'], 'kind'));
        // L9-3: el resultado trae su canal, su estrategia y su próxima acción (la transición de D9-10 y su hora); el medio y el paso son de su trabajo.
        $this->assertSame([
            'at' => '2026-11-10T14:00:00Z', 'channel' => 'assisted', 'direction' => 'outbound', 'kind' => 'outcome', 'level' => null, 'medium' => 'whatsapp',
            'mode' => 'live', 'nextAction' => 'wait', 'nextAt' => '2026-11-12T15:00:00Z', 'reason' => null, 'result' => 'no_answer', 'step' => 0, 'strategy' => $estrategia,
        ], $todo['entries'][0]);
        $this->assertSame(['daily_contact_limit', 'N1', 'retry', '2026-11-10T13:00:00Z'],
            [$todo['entries'][1]['reason'], $todo['entries'][1]['level'], $todo['entries'][1]['nextAction'], $todo['entries'][1]['nextAt']]);
        $this->assertSame(['assisted', 'whatsapp', 'started', 0, null, $estrategia],
            [$todo['entries'][2]['channel'], $todo['entries'][2]['medium'], $todo['entries'][2]['result'], $todo['entries'][2]['step'], $todo['entries'][2]['nextAction'], $todo['entries'][2]['strategy']]);
        $this->assertSame(['enroll', null, '2026-11-09T10:00:00Z'], [$todo['entries'][3]['result'], $todo['entries'][3]['reason'], $todo['entries'][3]['at']]);
        $this->assertNull($todo['next']);
        // De otra persona no sale nada: la negativa de m:8 no está.
        $this->assertNotContains('deny', array_column($todo['entries'], 'result'));
        // Una consulta por fuente, cada una filtrada por SU `subject_key` (los índices `odec/ojob/oout_subject_idx` de L9-3):
        // la de resultados ya no pasa por la inscripción para saber de quién es.
        $resultados = array_values(array_filter($sql, static fn (string $s): bool => str_contains($s, 'from "outreach_outcomes"')));
        $this->assertCount(1, $resultados);
        $this->assertStringContainsString('"o"."subject_key" = ?', $resultados[0]);
        $this->assertStringNotContainsString('"e"."subject_key"', $resultados[0]);

        // Páginas de 1: las mismas entradas, en el mismo orden, sin repetir.
        $vistas = [];
        $cursor = null;
        do {
            $p = (new SubjectTimeline)->forSubject('m:7', 1, $cursor);
            $vistas = [...$vistas, ...$p['entries']];
            $cursor = $p['next'];
        } while ($cursor !== null);
        $this->assertSame($todo['entries'], $vistas);

        $this->expectException(InvalidArgumentException::class);
        (new SubjectTimeline)->forSubject('m:7', 10, 'no-es-un-cursor');
    }
}
