<?php

namespace Tests\Feature\UltronStudio\Stream;

use App\Models\Admin;
use App\Models\Voice\VoiceBrakeEntry;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceEvents;
use App\Services\Telephony\VoicePhone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Telephony\Support\VoiceFixtures;

/**
 * T24 (contrato H9, «Paquete SSE9»), los temas de la voz: llamadas y
 * conciliación (eventos de llamada repartidos por kind), el freno, la cadena
 * de voz y el laboratorio hablado (cursor compuesto sobre una tabla que se
 * actualiza). Cada uno con el permiso del GET de lista de su módulo: el tema
 * que no se puede leer se omite y se anota; sin ninguno, 403.
 */
class UltronStudioStreamVoiceTopicsTest extends StreamTestCase
{
    use VoiceFixtures;

    private function superAdmin(): Admin
    {
        return $this->person(Admin::ROLE_SUPER_ADMIN, 'Súper de pruebas');
    }

    public function test_llamadas_y_conciliacion_se_reparten_los_eventos_y_no_llevan_telefonos_ni_huellas(): void
    {
        $quien = $this->superAdmin();
        $llamada = $this->voiceCall('outbound', 'dialing');
        $this->clock->onSleep = function (int $n) use ($llamada): void {
            if ($n === 1) {
                VoiceEvents::record('outbound_started', (int) $llamada->id, 'elevenlabs', 'stream-started-1', ['codes' => ['sid' => 'CA123']]);
                VoiceEvents::record('reconciled', (int) $llamada->id, 'laravel', 'stream-reconciled-1', ['codes' => ['carrier' => 'completed']]);
            }
        };

        $body = $this->open('topics=voice.calls,voice.reconcile', $this->as($quien))->assertOk()->streamedContent();

        $llamadas = $this->events($body, 'voice.calls');
        $conciliacion = $this->events($body, 'voice.reconcile');
        $this->assertCount(1, $llamadas);
        $this->assertCount(1, $conciliacion);
        $this->assertSame(['type' => 'voice_call.outbound_started', 'id' => $llamada->uuid, 'state' => 'dialing', 'at' => $llamadas[0]['data']['at'], 'ref' => 'outbound'], $llamadas[0]['data']);
        $this->assertSame('voice_reconcile.reconciled', $conciliacion[0]['data']['type']);
        $this->assertSame($llamada->uuid, $conciliacion[0]['data']['id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $llamadas[0]['data']['at']);
        // El `id` de cada evento es el vector entero: la posición de los dos temas.
        $this->assertMatchesRegularExpression('/^voice\.calls:\d+,voice\.reconcile:\d+$/', $llamadas[0]['id']);
        $this->assertMatchesRegularExpression('/^voice\.calls:\d+,voice\.reconcile:\d+$/', $conciliacion[0]['id']);
        // Ni el número, ni sus 4 dígitos como dato, ni la huella, ni los códigos del proveedor.
        foreach ([self::CANARY_PHONE, VoicePhone::hash(self::CANARY_PHONE), 'CA123', 'carrier'] as $privado) {
            $this->assertStringNotContainsString($privado, $body);
        }
        $this->assertSame(['type', 'id', 'state', 'at', 'ref'], array_keys($llamadas[0]['data']));
    }

    public function test_el_freno_anuncia_la_apertura_y_la_suelta_sin_evidencia_ni_nota(): void
    {
        $quien = $this->superAdmin();
        $apertura = null;
        $this->clock->onSleep = function (int $n) use (&$apertura, $quien): void {
            if ($n === 1) {
                $apertura = VoiceBrake::open('cost_cap', 'stream-brake-1', ['cap_micros' => 123456, 'spent_micros' => 234567]);
            }
            if ($n === 2) {
                VoiceBrake::release($quien, (string) $apertura->uuid, 'Se revisó el tope de gasto y se vuelve a abrir la voz', (string) Str::uuid());
            }
        };

        $body = $this->open('topics=voice.brake', $this->as($quien))->assertOk()->streamedContent();

        $eventos = $this->events($body, 'voice.brake');
        $this->assertCount(2, $eventos);
        $this->assertSame(['type' => 'voice_brake.open', 'id' => (string) $apertura->uuid, 'state' => 'cost_cap', 'at' => $eventos[0]['data']['at'], 'ref' => null], $eventos[0]['data']);
        $suelta = VoiceBrakeEntry::query()->where('kind', VoiceBrakeEntry::KIND_RELEASE)->firstOrFail();
        $this->assertSame(['type' => 'voice_brake.release', 'id' => (string) $suelta->uuid, 'state' => 'cost_cap', 'at' => $eventos[1]['data']['at'], 'ref' => (string) $apertura->uuid], $eventos[1]['data']);
        $this->assertSame('voice.brake:'.$suelta->id, $eventos[1]['id']);
        foreach (['123456', '234567', 'Se revisó', 'cap_micros'] as $privado) {
            $this->assertStringNotContainsString($privado, $body);
        }
    }

    public function test_la_cadena_de_voz_anuncia_cada_entrada_con_su_huella_y_sin_el_mensaje_firmado(): void
    {
        $quien = $this->superAdmin();
        $this->clock->onSleep = function (int $n): void {
            if ($n === 1) {
                $this->publishVoice();
            }
        };

        $body = $this->open('topics=voice.publications', $this->as($quien))->assertOk()->streamedContent();

        $fila = DB::table('ultron_studio_voice_publications')->orderByDesc('seq')->first();
        $eventos = $this->events($body, 'voice.publications');
        $this->assertCount(1, $eventos);
        $this->assertSame([
            'type' => 'voice_publication.publish', 'id' => (string) $fila->uuid, 'state' => 'canary', 'at' => $eventos[0]['data']['at'],
            'hash' => (string) $fila->effective_hash, 'ref' => '1',
        ], $eventos[0]['data']);
        $this->assertSame('voice.publications:'.$fila->id, $eventos[0]['id']);
        foreach (['published_at', (string) $fila->signature, 'provider_agent_id', 'Beto'] as $privado) {
            $this->assertStringNotContainsString($privado, $body);
        }
    }

    public function test_el_laboratorio_hablado_avanza_con_cada_toque_de_la_sesion_y_reanuda_sin_repetir(): void
    {
        $quien = $this->superAdmin();
        $t0 = Carbon::parse('2026-11-09 20:50:00', 'UTC');
        Carbon::setTestNow($t0);
        $sesion = $this->labSession($quien);
        $this->clock->onSleep = function (int $n) use ($sesion, $t0): void {
            if ($n === 1) {
                Carbon::setTestNow($t0->copy()->addSeconds(5));
                DB::table('ultron_studio_voice_lab_sessions')->where('id', $sesion)
                    ->update(['status' => 'ended', 'ended_at' => now(), 'end_reason' => 'completed', 'duration_seconds' => 120, 'status_changed_at' => now()]);
            }
            if ($n === 2) {
                Carbon::setTestNow($t0->copy()->addSeconds(10));
                DB::table('ultron_studio_voice_lab_sessions')->where('id', $sesion)
                    ->update(['verdict' => 'approved', 'verdict_by_admin_id' => 7, 'verdict_by_name' => 'Jueza Privada', 'verdict_by_role' => 'Administrador', 'verdict_at' => now()]);
            }
        };

        $body = $this->open('topics=voice.lab', $this->as($quien))->assertOk()->streamedContent();

        $uuid = (string) DB::table('ultron_studio_voice_lab_sessions')->where('id', $sesion)->value('uuid');
        $ready = $this->events($body, 'ready');
        $this->assertSame(['voice.lab' => $t0->getTimestamp().'-'.$sesion], $ready[0]['data']['topics']);
        $eventos = $this->events($body, 'voice.lab');
        $this->assertCount(2, $eventos);
        $this->assertSame(['type' => 'voice_lab.session', 'id' => $uuid, 'state' => 'ended', 'at' => '2026-11-09T20:50:05+00:00', 'ref' => 'completed'], $eventos[0]['data']);
        $this->assertSame('voice.lab:'.($t0->getTimestamp() + 5).'-'.$sesion, $eventos[0]['id']);
        $this->assertSame(['type' => 'voice_lab.session', 'id' => $uuid, 'state' => 'ended', 'at' => '2026-11-09T20:50:10+00:00', 'ref' => 'approved'], $eventos[1]['data']);
        $this->assertSame('voice.lab:'.($t0->getTimestamp() + 10).'-'.$sesion, $eventos[1]['id']);
        $this->assertStringNotContainsString('Jueza', $body);

        // Reanudar desde el último cursor: nada que repetir; desde el primero: solo lo posterior.
        $this->clock->onSleep = null;
        $this->assertSame([], $this->events($this->open('topics=voice.lab&after='.$eventos[1]['id'], $this->as($quien))->assertOk()->streamedContent(), 'voice.lab'));
        $otraVez = $this->events($this->open('topics=voice.lab&after='.$eventos[0]['id'], $this->as($quien))->assertOk()->streamedContent(), 'voice.lab');
        $this->assertSame([$eventos[1]['id']], array_column($otraVez, 'id'));
        // Un cursor con forma válida pero que no es `<unix>-<id>` arranca en la cabeza, sin reproducir la tabla; uno sin forma es 422.
        $this->assertSame([], $this->events($this->open('topics=voice.lab&after=voice.lab:roto', $this->as($quien))->assertOk()->streamedContent(), 'voice.lab'));
        $this->getJson(self::URL.'?topics=voice.lab&after=voice.lab:rot%C3%ADsimo', $this->as($quien))->assertUnprocessable();
        Carbon::setTestNow();
    }

    public function test_un_tema_sin_permiso_se_omite_y_se_anota_y_sin_ninguno_es_403(): void
    {
        Log::spy();
        $soloStudio = $this->adminWithPermissions(['ultron.view']);

        $body = $this->open('topics=ledger,voice.calls,voice.reconcile,voice.brake', $soloStudio)->assertOk()->streamedContent();
        $ready = $this->events($body, 'ready');
        // Lo que se puede leer sigue; lo que no, ni aparece ni se nombra en el canal.
        $this->assertSame(['ledger', 'voice.brake'], array_keys($ready[0]['data']['topics']));
        $this->assertStringNotContainsString('voice.calls', $body);
        Log::shouldHaveReceived('notice')->with('ultron_studio.stream_topic_denied', Mockery::on(fn (array $ctx): bool => $ctx['topic'] === 'voice.calls' && is_int($ctx['admin_id'])))->once();
        Log::shouldHaveReceived('notice')->with('ultron_studio.stream_topic_denied', Mockery::on(fn (array $ctx): bool => $ctx['topic'] === 'voice.reconcile'))->once();

        $this->getJson(self::URL.'?topics=voice.calls', $soloStudio)->assertStatus(403)->assertJson(['ok' => false, 'code' => 'stream_no_topics']);

        // Con la llave de la telefonía, el tema entra.
        $conTelefonia = $this->adminWithPermissions(['ultron.view', 'telephony.view']);
        $body = $this->open('topics=voice.calls', $conTelefonia)->assertOk()->streamedContent();
        $this->assertSame(['voice.calls'], array_keys($this->events($body, 'ready')[0]['data']['topics']));
    }

    /**
     * Una sesión abierta del laboratorio hablado, sembrada como hace
     * StudioVoiceFixtures::labVerdictFor: su agente de laboratorio cuelga de la
     * versión y el perfil reales que deja publishVoice(). Devuelve su id.
     */
    private function labSession(Admin $opener): int
    {
        $ahora = now();
        $publicado = DB::table('ultron_studio_voice_agents')->where('id', $this->publishVoice()['agentRowId'])->first();
        $agente = DB::table('ultron_studio_voice_agents')->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'purpose' => 'lab',
            'studio_version_id' => $publicado->studio_version_id, 'studio_content_hash' => str_repeat('a', 64), 'voice_profile_id' => $publicado->voice_profile_id, 'voice_profile_hash' => str_repeat('c', 64),
            'compiler_version' => 'ultron-voice-compiler/1', 'spec_hash' => str_repeat('d', 64), 'payload_hash' => str_repeat('e', 64), 'projection_hash' => str_repeat('f', 64),
            'provider' => 'fake', 'status' => 'pending', 'serves_canary' => false, 'serves_rest' => false,
            'created_by_admin_id' => $opener->id, 'created_by_name' => $opener->name, 'created_by_role' => $opener->role,
            'created_at' => $ahora, 'status_changed_at' => $ahora, 'updated_at' => $ahora,
        ]);
        DB::table('ultron_studio_voice_agents')->where('id', $agente)->update([
            'status' => 'deployed', 'provider_agent_id' => 'lab_fake_'.Str::lower(Str::random(8)), 'observed_hash' => str_repeat('f', 64), 'observed_at' => $ahora, 'status_changed_at' => $ahora,
        ]);

        return (int) DB::table('ultron_studio_voice_lab_sessions')->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'agent_id' => $agente, 'status' => 'open',
            'budget_day' => $ahora->toDateString(), 'reserved_micros' => 0, 'max_seconds' => 300,
            'created_by_admin_id' => $opener->id, 'created_by_name' => $opener->name, 'created_by_role' => $opener->role,
            'created_at' => $ahora, 'status_changed_at' => $ahora, 'updated_at' => $ahora,
        ]);
    }
}
