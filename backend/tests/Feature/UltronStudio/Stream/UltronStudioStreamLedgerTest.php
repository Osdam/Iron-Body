<?php

namespace Tests\Feature\UltronStudio\Stream;

use App\Models\UltronStudio\StudioAuditEvent;
use App\Services\UltronStudio\Stream\StreamEvent;
use App\Services\UltronStudio\StudioAudit;
use Illuminate\Support\Str;

/**
 * T24 (contrato H9, «Paquete SSE9»), el tema `ledger`: un evento del libro
 * llega al cliente en el sondeo siguiente; reanudar no repite ni pierde; un
 * evento nunca lleva datos personales (fila sembrada con teléfono, nombre,
 * documento y cuerpo); los borradores ajenos no viajan sin `audit.view`; el
 * latido y el cierre a los 55 s; la forma del `id` (vector de cursores).
 */
class UltronStudioStreamLedgerTest extends StreamTestCase
{
    /** @param  array<string, mixed>  $extra */
    private function row(string $event = 'version.approved', string $subjectType = 'version', ?string $toStatus = 'APPROVED', array $extra = []): StudioAuditEvent
    {
        return app(StudioAudit::class)->record(null, null, $event, $subjectType, $extra['uuid'] ?? (string) Str::uuid(), $extra['summary'] ?? 'Resumen de prueba', [
            'toStatus' => $toStatus,
            'contentHash' => $extra['hash'] ?? str_repeat('a', 64),
            'diff' => $extra['diff'] ?? null,
            'metadata' => $extra['metadata'] ?? null,
        ]);
    }

    public function test_un_evento_del_libro_llega_en_el_sondeo_siguiente_con_la_forma_del_contrato(): void
    {
        $ana = $this->person();
        $previo = $this->row();
        $nuevo = null;
        $this->clock->onSleep = function (int $n) use (&$nuevo): void {
            if ($n === 1) {
                $nuevo = $this->row('rule.archived', 'rule', 'archived');
            }
        };

        $response = $this->open('topics=ledger', $this->as($ana))->assertOk();
        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        $body = $response->streamedContent();

        // El preludio y el `ready` con el cursor de partida (la cabeza): solo lo nuevo viaja.
        $this->assertStringStartsWith("retry: 3000\n: connected\n\n", $body);
        $ready = $this->events($body, 'ready');
        $this->assertSame([['topics' => ['ledger' => (string) $previo->id], 'resync' => true]], array_column($ready, 'data'));
        $this->assertSame('ledger:'.$previo->id, $ready[0]['id']);

        $eventos = $this->events($body, 'ledger');
        $this->assertCount(1, $eventos);
        $this->assertSame('ledger:'.$nuevo->id, $eventos[0]['id']);
        $this->assertSame([
            'type' => 'rule.archived',
            'id' => $nuevo->subject_uuid,
            'state' => 'archived',
            'at' => $nuevo->created_at->utc()->toIso8601String(),
            'hash' => str_repeat('a', 64),
            'ref' => 'rule',
        ], $eventos[0]['data']);
        // Cuatro sondeos en 3 s a un sondeo por segundo: el evento salió en el segundo (tras el primer «sueño»).
        $this->assertSame(3, $this->clock->sleeps);
        $this->assertTrue($this->clock->prepared);
    }

    public function test_reanudar_con_after_o_last_event_id_ni_repite_ni_pierde(): void
    {
        $ana = $this->person();
        [$a, $b, $c] = [$this->row(), $this->row(), $this->row()];

        $ids = fn (string $body): array => array_map(fn (array $e): string => $e['id'], $this->events($body, 'ledger'));

        // `?after=` reproduce lo posterior al cursor, nada más.
        $this->assertSame(['ledger:'.$b->id, 'ledger:'.$c->id], $ids($this->open('topics=ledger&after=ledger:'.$a->id, $this->as($ana))->assertOk()->streamedContent()));
        // Al día: nada.
        $this->assertSame([], $ids($this->open('topics=ledger&after=ledger:'.$c->id, $this->as($ana))->assertOk()->streamedContent()));
        // La cabecera del navegador vale igual…
        $this->assertSame(['ledger:'.$c->id], $ids($this->open('topics=ledger', $this->as($ana) + ['Last-Event-ID' => 'ledger:'.$b->id])->assertOk()->streamedContent()));
        // …y `?after=` manda sobre ella.
        $this->assertSame(['ledger:'.$c->id], $ids($this->open('topics=ledger&after=ledger:'.$b->id, $this->as($ana) + ['Last-Event-ID' => 'ledger:0'])->assertOk()->streamedContent()));
        // Un cursor por delante de la cabeza se recorta a ella (no deja al cliente sordo).
        $body = $this->open('topics=ledger&after=ledger:999999', $this->as($ana))->assertOk()->streamedContent();
        $this->assertSame([['topics' => ['ledger' => (string) $c->id], 'resync' => true]], array_column($this->events($body, 'ready'), 'data'));
        // Una cabecera que no es un vector se ignora (se arranca en la cabeza); un `?after=` roto es 422.
        $this->assertSame([], $ids($this->open('topics=ledger', $this->as($ana) + ['Last-Event-ID' => 'basura sin forma'])->assertOk()->streamedContent()));
        $this->getJson(self::URL.'?topics=ledger&after=ledger', $this->as($ana))->assertUnprocessable();
        $this->getJson(self::URL.'?topics=ledger&after=ledger:1,ledger:2', $this->as($ana))->assertUnprocessable();
    }

    public function test_un_evento_nunca_lleva_datos_personales_ni_claves_fuera_de_la_lista_blanca(): void
    {
        $ana = $this->person();
        // Sembrada directamente (el libro es de solo inserción): teléfono, nombre,
        // documento, cuerpo e importe en resumen, diff y metadata; el actor y los
        // rastros de la persona del CRM en sus columnas.
        StudioAuditEvent::query()->forceCreate([
            'event' => 'version.submitted', 'subject_type' => 'version', 'subject_uuid' => (string) Str::uuid(),
            'actor_type' => 'admin', 'actor_admin_id' => $ana->id, 'actor_name' => 'Nombre Privado Del CRM', 'actor_role' => 'Administrador',
            'from_status' => 'DRAFT', 'to_status' => 'SUBMITTED', 'content_hash' => str_repeat('b', 64),
            'summary' => 'Llamó Marisol Quintero (3001234567) con cédula 1023456789: «quiero el plan anual»',
            'diff' => ['phone' => '3001234567', 'name' => 'Marisol Quintero', 'body' => 'quiero el plan anual', 'amount' => 180000],
            'metadata' => ['document' => '1023456789', 'token' => 'secreto-de-prueba'],
            'ip_address' => '203.0.113.9', 'user_agent' => 'Navegador privado', 'created_at' => now(),
        ]);

        $body = $this->open('topics=ledger&after=ledger:0', $this->as($ana))->assertOk()->streamedContent();

        foreach (['Marisol', 'Quintero', '3001234567', '1023456789', 'quiero el plan', '180000', 'secreto-de-prueba', 'Privado', '203.0.113.9', 'Navegador'] as $privado) {
            $this->assertStringNotContainsString($privado, $body, "el canal filtró «{$privado}»");
        }
        $eventos = $this->events($body, 'ledger');
        $this->assertCount(1, $eventos);
        $this->assertSame(['type', 'id', 'state', 'at', 'hash', 'ref'], array_keys($eventos[0]['data']));
        $this->assertSame(StreamEvent::KEYS, array_keys($eventos[0]['data']));
    }

    public function test_los_borradores_ajenos_no_viajan_sin_audit_view_y_los_propios_si(): void
    {
        $ana = $this->person();
        $this->saveDraft($ana)->assertOk();
        $uuidAna = StudioAuditEvent::query()->where('event', 'draft.saved')->value('subject_uuid');
        $this->assertNotNull($uuidAna);
        $version = $this->row();

        // Quien solo tiene `ultron.view` ve la versión, no el borrador de Ana.
        $soloVer = $this->adminWithPermissions(['ultron.view']);
        $tipos = fn (string $body): array => array_map(fn (array $e): string => $e['data']['type'], $this->events($body, 'ledger'));
        $this->assertSame(['version.approved'], $tipos($this->open('topics=ledger&after=ledger:0', $soloVer)->assertOk()->streamedContent()));
        // Ana ve el suyo.
        $this->assertSame(['draft.saved', 'version.approved'], $tipos($this->open('topics=ledger&after=ledger:0', $this->as($ana))->assertOk()->streamedContent()));
        // Con `audit.view`, todos.
        $auditor = $this->adminWithPermissions(['ultron.view', 'audit.view']);
        $this->assertSame(['draft.saved', 'version.approved'], $tipos($this->open('topics=ledger&after=ledger:0', $auditor)->assertOk()->streamedContent()));
        // Y el cursor avanza igual para todos: el que no ve el borrador no se queda atascado detrás de él.
        $body = $this->open('topics=ledger&after=ledger:0', $soloVer)->assertOk()->streamedContent();
        $this->assertSame('ledger:'.$version->id, $this->events($body, 'ledger')[0]['id']);
    }

    public function test_el_latido_cada_15_s_y_el_cierre_a_los_55_s_con_el_compas_del_contrato(): void
    {
        config(['ultron_studio_stream.max_seconds' => 55, 'ultron_studio_stream.poll_ms' => 1500, 'ultron_studio_stream.heartbeat_seconds' => 15]);
        $this->usePacer();
        $ana = $this->person();

        $body = $this->open('topics=ledger', $this->as($ana))->assertOk()->streamedContent();

        // 55 s a 1,5 s por sondeo: 37 esperas (la última, recortada al cierre) y 38 sondeos; latidos a los 15, 30 y 45 s.
        $this->assertSame(3, $this->pings($body));
        $this->assertSame(37, $this->clock->sleeps);
        $this->assertEqualsWithDelta(55.0, $this->clock->now - 1_700_000_000.0, 0.001);
        $this->assertSame(1500, $this->clock->sleptMs[0]);
        $this->assertSame(1000, end($this->clock->sleptMs));
        // Los latidos son comentarios, nunca eventos: el cliente no los ve como datos.
        $this->assertSame([], $this->events($body, 'ledger'));
        $this->assertCount(1, $this->blocks($body));
    }

    public function test_si_el_cliente_se_va_el_bucle_termina(): void
    {
        $this->clock->abortAfterSleeps = 1;
        $body = $this->open('topics=ledger', $this->as($this->person()))->assertOk()->streamedContent();
        $this->assertSame(1, $this->clock->sleeps);
        $this->assertCount(1, $this->events($body, 'ready'));
    }

    public function test_una_reconexion_que_honra_los_cursores_no_obliga_a_releer_y_las_demas_si(): void
    {
        $ana = $this->person();
        $fila = $this->row();
        $ready = fn (string $query): array => $this->events($this->open($query, $this->as($ana))->assertOk()->streamedContent(), 'ready')[0]['data'];

        // Primera conexión (sin cursores): releer.
        $this->assertTrue($ready('topics=ledger')['resync']);
        // Reconexión con el cursor exacto: no hace falta releer.
        $this->assertFalse($ready('topics=ledger&after=ledger:'.$fila->id)['resync']);
        // Un cursor por delante de la cabeza se recorta: releer.
        $this->assertTrue($ready('topics=ledger&after=ledger:999999')['resync']);
        // Dos temas y solo uno con cursor: releer (el otro arranca en la cabeza).
        $this->assertTrue($ready('topics=ledger,voice.brake&after=ledger:'.$fila->id)['resync']);
        $this->assertFalse($ready('topics=ledger,voice.brake&after=ledger:'.$fila->id.',voice.brake:0')['resync']);
    }

    public function test_un_commit_tardio_bajo_el_cursor_pide_releer_solo_ese_tema_y_una_sola_vez(): void
    {
        $ana = $this->person();
        // Los ids se reservan antes del commit: el 3 y el 5 ya son visibles; el 4 llegará tarde.
        foreach ([3, 5] as $id) {
            StudioAuditEvent::query()->forceCreate(['id' => $id, 'event' => 'version.approved', 'subject_type' => 'version', 'subject_uuid' => (string) Str::uuid(),
                'actor_type' => 'system', 'actor_name' => 'Sistema', 'to_status' => 'APPROVED', 'summary' => 'Sembrada', 'created_at' => now()]);
        }
        $this->clock->onSleep = function (int $n): void {
            if ($n === 1) {
                StudioAuditEvent::query()->forceCreate(['id' => 4, 'event' => 'version.approved', 'subject_type' => 'version', 'subject_uuid' => (string) Str::uuid(),
                    'actor_type' => 'system', 'actor_name' => 'Sistema', 'to_status' => 'APPROVED', 'summary' => 'Tardía', 'created_at' => now()]);
            }
            if ($n === 2) {
                // Con id explícito también aquí: en PostgreSQL la secuencia no avanza con los ids escritos a mano y daría el 1.
                StudioAuditEvent::query()->forceCreate(['id' => 6, 'event' => 'rule.archived', 'subject_type' => 'rule', 'subject_uuid' => (string) Str::uuid(),
                    'actor_type' => 'system', 'actor_name' => 'Sistema', 'to_status' => 'archived', 'summary' => 'Posterior', 'created_at' => now()]);
            }
        };

        $body = $this->open('topics=ledger,voice.brake&after=ledger:5,voice.brake:0', $this->as($ana))->assertOk()->streamedContent();

        $ready = $this->events($body, 'ready');
        // El de apertura (cursores honrados) y UNO de relectura, solo del libro, con su cursor sin mover.
        $this->assertSame([
            ['topics' => ['ledger' => '5', 'voice.brake' => '0'], 'resync' => false],
            ['topics' => ['ledger' => '5'], 'resync' => true],
        ], array_column($ready, 'data'));
        $this->assertSame('ledger:5,voice.brake:0', $ready[1]['id']);
        // La fila tardía no se emite como evento (está bajo el cursor), la posterior sí.
        $eventos = $this->events($body, 'ledger');
        $this->assertSame(['rule.archived'], array_column(array_column($eventos, 'data'), 'type'));
        $this->assertSame('ledger:6,voice.brake:0', $eventos[0]['id']);
    }

    public function test_temas_mal_pedidos_son_422(): void
    {
        $ana = $this->person();
        $this->getJson(self::URL, $this->as($ana))->assertUnprocessable();
        $this->getJson(self::URL.'?topics=', $this->as($ana))->assertUnprocessable();
        $this->getJson(self::URL.'?topics=ledger,inventado', $this->as($ana))->assertUnprocessable();
        $this->getJson(self::URL.'?topics=Ledger', $this->as($ana))->assertUnprocessable();
        $this->getJson(self::URL.'?topics='.str_repeat('a', 257), $this->as($ana))->assertUnprocessable();
        // Repetir un tema no lo duplica.
        $body = $this->open('topics=ledger,ledger', $this->as($ana))->assertOk()->streamedContent();
        $this->assertSame(['ledger'], array_keys($this->events($body, 'ready')[0]['data']['topics']));
    }
}
