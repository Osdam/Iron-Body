<?php

namespace Tests\Feature\UltronStudio\Stream;

use App\Models\Admin;
use App\Models\Incident;
use App\Services\IronGuard\IncidentRecorder;
use Illuminate\Support\Carbon;

/**
 * T24 (contrato H9, «Paquete SSE9»), el tema `monitor.incidents`: cada toque
 * a un incidente de IRON GUARD de las fuentes del Studio (abrir, repetir,
 * reconocer), sin títulos ni evidencia; otras fuentes no viajan; la llave
 * `security.view` no basta sin uno de los roles que IRON GUARD admite (como
 * su GET).
 */
class UltronStudioStreamIncidentsTest extends StreamTestCase
{
    /** @param  array<string, mixed>  $extra */
    private function incident(string $source, string $kind, string $title, array $extra = []): Incident
    {
        return app(IncidentRecorder::class)->record(['source' => $source, 'kind' => $kind, 'title' => $title, 'severity' => Incident::SEVERITY_HIGH] + $extra);
    }

    public function test_abrir_repetir_y_reconocer_un_incidente_del_studio_llegan_sin_titulo_ni_evidencia_y_otras_fuentes_no(): void
    {
        $quien = $this->person(Admin::ROLE_SUPER_ADMIN, 'Súper de pruebas');
        $t0 = Carbon::parse('2026-11-09 21:00:00', 'UTC');
        Carbon::setTestNow($t0);
        $incidente = null;
        $this->clock->onSleep = function (int $n) use (&$incidente, $t0): void {
            if ($n === 1) {
                Carbon::setTestNow($t0->copy()->addSeconds(5));
                $incidente = $this->incident('ultron_studio', 'publication_drift', 'Deriva: la cadena dice 12 y el motor 11 (avisó Marisol, 3001234567)', ['evidence' => ['chain_seq' => 12, 'engine_seq' => 11]]);
                $this->incident('meta_webhook', 'signature_invalid', 'Firma inválida en el webhook de Meta');
            }
            if ($n === 2) {
                // La misma clase otra vez: una ocurrencia más en la MISMA fila (sin evento en incident_events).
                Carbon::setTestNow($t0->copy()->addSeconds(10));
                $this->incident('ultron_studio', 'publication_drift', 'Deriva: la cadena dice 12 y el motor 11 (avisó Marisol, 3001234567)', ['evidence' => ['chain_seq' => 12, 'engine_seq' => 11]]);
            }
            if ($n === 3) {
                Carbon::setTestNow($t0->copy()->addSeconds(15));
                $incidente->refresh()->forceFill(['status' => Incident::STATUS_ACKNOWLEDGED, 'acknowledged_at' => now()])->save();
            }
        };

        $body = $this->open('topics=monitor.incidents', $this->as($quien))->assertOk()->streamedContent();
        Carbon::setTestNow();

        $eventos = $this->events($body, 'monitor.incidents');
        $this->assertSame(['incident.opened', 'incident.updated', 'incident.updated'], array_column(array_column($eventos, 'data'), 'type'));
        $this->assertSame(['type' => 'incident.opened', 'id' => (string) $incidente->id, 'state' => 'open', 'at' => '2026-11-09T21:00:05+00:00', 'ref' => 'publication_drift'], $eventos[0]['data']);
        $this->assertSame(['type' => 'incident.updated', 'id' => (string) $incidente->id, 'state' => 'open', 'at' => '2026-11-09T21:00:10+00:00', 'ref' => 'publication_drift'], $eventos[1]['data']);
        $this->assertSame(['type' => 'incident.updated', 'id' => (string) $incidente->id, 'state' => 'acknowledged', 'at' => '2026-11-09T21:00:15+00:00', 'ref' => 'publication_drift'], $eventos[2]['data']);
        $this->assertSame('monitor.incidents:'.($t0->getTimestamp() + 15).'-'.$incidente->id, $eventos[2]['id']);
        $this->assertSame(2, (int) $incidente->refresh()->occurrences);
        foreach (['Deriva', 'Marisol', '3001234567', 'chain_seq', 'meta_webhook', 'signature_invalid', 'Firma'] as $privado) {
            $this->assertStringNotContainsString($privado, $body);
        }
    }

    public function test_la_llave_security_view_no_basta_sin_un_rol_de_iron_guard(): void
    {
        $conLlave = $this->adminWithPermissions(['ultron.view', 'security.view']);
        $this->getJson(self::URL.'?topics=monitor.incidents', $conLlave)->assertStatus(403)->assertJson(['code' => 'stream_no_topics']);

        $body = $this->open('topics=ledger,monitor.incidents', $conLlave)->assertOk()->streamedContent();
        $this->assertSame(['ledger'], array_keys($this->events($body, 'ready')[0]['data']['topics']));

        $superAdmin = $this->person(Admin::ROLE_SUPER_ADMIN, 'Súper de pruebas');
        $body = $this->open('topics=monitor.incidents', $this->as($superAdmin))->assertOk()->streamedContent();
        $this->assertSame(['monitor.incidents'], array_keys($this->events($body, 'ready')[0]['data']['topics']));
    }
}
