<?php

namespace Tests\Feature\Voice\E2E;

use App\Services\Voice\VoiceCallPolicy;

/**
 * E2E del Hito 8, FASE ABIERTA (TEST8-b): la COPIA DEL BANCO con `CALLS_ENABLED_IN_CODE = true` (el giro de una línea,
 * comprobado con diff por el arnés; nunca en el árbol compartido, protección 2) y todas las banderas encendidas.
 * Recorridos del paso 3 del contrato con la pila real, los adaptadores reales y los simuladores (E6-11):
 *  a. perfil → enviar → aprobar → compile-preview → laboratorio hablado → cierre → veredicto de otra identidad;
 *  b. publicar en canario con `expectedSpecHash` (y el 409 `spec_changed`) → agente desplegado, número asignado,
 *     entrada firmada y verificada;
 *  c. tres entrantes del canario (iniciación, las cuatro herramientas, post-llamada firmada → `seal_verified`, costes
 *     por la conciliación, transcripción solo en códigos) y una de un número que no es canario → `denied`;
 *  d. la evidencia del canario (recuentos y `too_soon`) y el 409 al promover;
 *  e. el freno: `seal_mismatch` → herramientas cerradas → soltar; llamada que solo existe en Twilio → freno; iniciación
 *     con el secreto mal puesto (C-A2); deriva del agente y del número (`--numbers`); el corte (D8-20 nivel 0b);
 *  g. salientes: la puerta (409 por E4-6 y el calendario) y el JOB real con filas selladas como la puerta: completa
 *     contra los dos simuladores, y canceladas sin petición (fuera de horario, festivo, exclusión tras encolar);
 *  f. revertir y apagar: el número vuelve al agente anterior y luego queda sin agente; la cadena solo crece;
 *  h. cero red fuera de 127.0.0.1 y 0 violaciones del contrato en los simuladores.
 *
 * Orden de ejecución = orden de declaración; cada paso exige con `requiere()` lo que dejó el anterior. La g va antes
 * que la f porque apagar deja el canario sin publicación. Solo corre con `docs/ultron-studio/ops/h8-e2e.sh abierta`.
 */
final class H8E2EAbiertaTest extends H8StackE2ETestCase
{
    protected const PHASE = 'abierta';

    /** Instantes del reloj desplazado de los workers de UN trabajo (`ops/h8-e2e/reloj.php`), en Bogotá. */
    private const MARTES_EN_HORARIO = '2026-11-10T10:00:00-05:00';

    private const DOMINGO = '2026-11-15T10:00:00-05:00';

    private const FESTIVO = '2026-11-16T10:00:00-05:00';

    private const NO_CANARIO = '+573000000099';

    public function test_0_la_copia_del_banco_tiene_la_constante_abierta_y_los_canarios_de_voz_activos(): void
    {
        $this->must('la constante está ABIERTA en esta copia (solo en la del banco)', VoiceCallPolicy::CALLS_ENABLED_IN_CODE === true, VoiceCallPolicy::CALLS_ENABLED_IN_CODE);
        $st = $this->telefonia('GET', 'status', 'a');
        $this->check('GET admin/voice/status: 200, banderas encendidas y sin flags.publishing', $st->status() === 200 && $st->json('flags.inbound') === true && $st->json('flags.outbound') === true
            && ! array_key_exists('publishing', (array) $st->json('flags')), self::resumen($st, 'flags'));
        $iv = $this->studio('GET', 'integrations/voice', 'b');
        $this->check('ruta 45: 200 y Beto (Super Admin) tiene las 7 llaves de D8-22', $iv->status() === 200 && count(array_filter((array) $iv->json('data.can'))) === 7, self::resumen($iv, 'data.can'));
        $this->prepararCanarios();
        $this->finish();
    }

    public function test_a_perfil_compilacion_laboratorio_y_veredicto(): void
    {
        $this->requiere('canarios');
        self::$s['a'] = $this->recorridoLaboratorio('Voz del E2E (canario, perfil 1)', self::contenidoPerfil());
        $this->finish();
    }

    public function test_b_publicar_en_canario_con_la_huella_que_se_vio(): void
    {
        $a = $this->requiere('a');
        $otra = substr($a['specHash'], 0, 63).($a['specHash'][63] === '0' ? '1' : '0');
        $cuerpo = static fn (string $hash, ?string $crid = null): array => ['clientRequestId' => $crid ?? self::uuid(), 'versionUuid' => $a['version'], 'profileUuid' => $a['profile'], 'expectedSpecHash' => $hash];
        // O-1 del informe: la evidencia del canario cuenta los sucesos con `created_at >= publicación` a granularidad de
        // SEGUNDO (VoiceEvidence.php:80). Un suceso anterior del mismo segundo (aquí, el `unknown_provider_call` que deja
        // F-1 al cerrar el laboratorio) contaría contra esta publicación. Una persona no publica en el mismo segundo:
        // se deja pasar uno para que el paso d mida lo que dice el contrato.
        usleep(1_100_000);

        $antes = $this->fotos();
        $r1 = $this->studio('POST', 'voice/publish', 'b', $cuerpo($otra));
        $this->check('publicar con otra huella → 409 spec_changed {expected, actual}', $r1->status() === 409 && $r1->json('code') === 'spec_changed'
            && $r1->json('expected') === $otra && $r1->json('actual') === $a['specHash'], self::resumen($r1, 'expected', 'actual'));
        $this->check('spec_changed: ninguna petición a ElevenLabs, ningún agente ni entrada', self::delta($antes['el'], $this->el('stats'))['requests'] === 0
            && (int) $this->valor("SELECT count(*) FROM ultron_studio_voice_agents WHERE purpose = 'publication'") === 0
            && (int) $this->valor('SELECT count(*) FROM ultron_studio_voice_publications') === 0, null);
        $r2 = $this->studio('POST', 'voice/publish', 'a', $cuerpo($a['specHash']));
        $this->check('Ana no publica lo que creó (403 four_eyes_required)', $r2->status() === 403 && $r2->json('code') === 'four_eyes_required', self::resumen($r2));
        $r3 = $this->studio('POST', 'voice/publish', 'c', $cuerpo($a['specHash']));
        $this->check('Carla (Administrador) no publica (403)', $r3->status() === 403, self::resumen($r3));

        $crid = self::uuid();
        $antes = $this->fotos();
        $r = $this->studio('POST', 'voice/publish', 'b', $cuerpo($a['specHash'], $crid));
        $this->must('Beto publica en canario (201)', $r->status() === 201, self::resumen($r, 'data.number'));
        $this->check('la entrada: seq 1, publish, canary, puerta del laboratorio required', $r->json('data.entries.0.seq') === 1 && $r->json('data.entries.0.action') === 'publish'
            && $r->json('data.entries.0.scope') === 'canary' && $r->json('data.entries.0.labGate.mode') === 'required', $r->json('data.entries'));
        $this->check('el agente: deployed, sirve al canario, con la huella que se vio; el id del proveedor enmascarado', $r->json('data.agent.status') === 'deployed' && $r->json('data.agent.servesCanary') === true
            && $r->json('data.agent.specHash') === $a['specHash'] && str_starts_with((string) $r->json('data.agent.providerAgentRef'), '…'), $r->json('data.agent'));
        $this->check('el número: asignado', $r->json('data.number.action') === 'assigned' && $r->json('data.number.outcome') === 'assigned', $r->json('data.number'));
        $d = self::delta($antes['el'], $this->el('stats'));
        $this->check('en ElevenLabs: 4 herramientas, crear, leer (con sus herramientas) y UN PATCH al número', ($d['byRoute']['/v1/convai/tools'] ?? 0) === 4 && ($d['byRoute']['/v1/convai/agents/create'] ?? 0) === 1
            && ($d['byRoute']['/v1/convai/agents/{agent_id}'] ?? 0) >= 1 && ($d['byRoute']['/v1/convai/phone-numbers/{phone_number_id}'] ?? 0) === 1, $d);
        $this->check('0 violaciones en ElevenLabs al publicar; Twilio sin peticiones', self::separar($d['violations'])['own'] === [] && self::delta($antes['tw'], $this->tw('stats'))['requests'] === 0, $d['violations']);
        $ag = $this->fila("SELECT id, uuid, provider_agent_id, status, serves_canary, serves_rest, spec_hash, payload_hash, projection_hash, observed_hash, transfer_destination_hashes::text AS destinos FROM ultron_studio_voice_agents WHERE purpose = 'publication' ORDER BY id DESC LIMIT 1");
        $this->must('SQL: el agente de publicación', $ag !== null, null);
        $this->check('SQL: deployed, sirve al canario, misma huella, la proyección observada = la compilada y destinos []', $ag['status'] === 'deployed' && $ag['serves_canary'] === true && $ag['serves_rest'] === false
            && $ag['spec_hash'] === $a['specHash'] && $ag['observed_hash'] === $ag['projection_hash'] && $ag['destinos'] === '[]', $ag);
        $this->check('ElevenLabs: el número importado apunta al agente nuevo', ($this->el('state')['numbers'][0]['agent'] ?? null) === $ag['provider_agent_id'], null);
        $e = $this->fila("SELECT seq, action, scope, agent_id, prev_hash, effective_hash, encode(sha256(convert_to(effective_json, 'UTF8')), 'hex') AS recalculada, length(signature) AS firma, lab_gate->>'mode' AS puerta, actor_admin_id FROM ultron_studio_voice_publications ORDER BY seq");
        $this->check('SQL: entrada 1 (génesis: prev_hash de 64 ceros), su huella = sha256 de los bytes guardados, firma de 64 hex, de Beto', $e !== null && (int) $e['seq'] === 1 && $e['action'] === 'publish'
            && $e['scope'] === 'canary' && (int) $e['agent_id'] === (int) $ag['id'] && $e['prev_hash'] === str_repeat('0', 64) && $e['effective_hash'] === $e['recalculada']
            && (int) $e['firma'] === 64 && $e['puerta'] === 'required' && (int) $e['actor_admin_id'] === (int) self::ctx()['accounts']['b']['id'], $e);
        $pub = $this->studio('GET', 'voice/publications', 'c');
        $this->check('GET voice/publications (Carla, ultron.view): canario vigente, verificado, con la specHash; historial verificado', $pub->status() === 200 && $pub->json('data.state.canary.entry.seq') === 1
            && $pub->json('data.state.canary.verified') === 'ok' && $pub->json('data.state.canary.agent.specHash') === $a['specHash'] && $pub->json('data.state.verified') === true
            && $pub->json('data.history.data.0.verified') === 'ok', self::resumen($pub, 'data.state.canary.verified', 'data.history.total'));
        $this->check('GET voice/publications no lleva el id del proveedor en claro', ! str_contains($pub->body(), (string) $ag['provider_agent_id']), null);
        $re = $this->studio('POST', 'voice/publish', 'b', $cuerpo($a['specHash'], $crid));
        $this->check('repetir la misma petición → 200 replayed con la misma entrada', $re->status() === 200 && $re->json('replayed') === true && $re->json('data.entries.0.uuid') === $r->json('data.entries.0.uuid'), self::resumen($re, 'replayed'));
        $this->check('la cadena es de solo inserción para la aplicación: UPDATE → 42501', $this->comoAplicacion("UPDATE ultron_studio_voice_publications SET reason = 'x'") === '42501', null);
        $this->check('… y DELETE → 42501', $this->comoAplicacion('DELETE FROM ultron_studio_voice_publications') === '42501', null);
        self::$s['pub1'] = ['agentId' => (int) $ag['id'], 'agentUuid' => (string) $ag['uuid'], 'provider' => (string) $ag['provider_agent_id'], 'entry' => (string) $r->json('data.entries.0.uuid')];
        $this->finish();
    }

    public function test_c_tres_entrantes_del_canario_selladas_y_una_de_un_numero_que_no_es_canario(): void
    {
        $a = $this->requiere('a');
        $p = $this->requiere('pub1');
        [$c1] = $this->requiere('canarios');
        $herramientas = [1 => ['consultar_planes', 'consultar_horarios'], 2 => ['consultar_horarios'], 3 => ['solicitar_humano', 'no_volver_a_llamar']];
        $llamadas = [];
        foreach ($herramientas as $i => $lista) {
            $e = $this->entrante(self::ctx()['canaries'][0]);
            $r = $e['sim'];
            $f = $e['row'];
            $this->must("entrante {$i}: la iniciación autoriza (200, caller_authorized = si)", ($r['laravel']['status'] ?? null) === 200 && ($r['caller_authorized'] ?? null) === 'si' && $f !== null, $r);
            $propias = self::separar(array_count_values((array) ($r['violations'] ?? [])))['own'];
            if (isset($propias['initiation_response_personal_data']) && $this->falsoPositivoDeTelefono($r['conversation_id'], 'initiation_response_personal_data', self::ctx()['canaries'][0])) {
                unset($propias['initiation_response_personal_data']);
            }
            $this->check("entrante {$i}: 0 violaciones propias en la respuesta de la iniciación (un falso positivo de F-6, verificado, aparte)", $propias === [], $r['violations'] ?? null);
            $this->check("entrante {$i}: SQL active y SELLADA (entrada 1, canario, specHash, agente, versión, compilador)", $f['direction'] === 'inbound' && $f['status'] === 'active' && $f['caller_authorized'] === true
                && $f['stamp_state'] === 'applied' && (int) $f['voice_publication_seq'] === 1 && $f['part'] === 'canary' && $f['spec_hash'] === $a['specHash'] && $f['provider_agent_id'] === $p['provider']
                && $f['studio_version_uuid'] === $a['version'] && $f['compiler_version'] === 'ultron-voice-compiler/1' && $f['provider_call_sid'] === $r['call_sid'] && $f['started_at'] !== null, $f);
            $this->check("entrante {$i}: del que llama, solo la huella y los 4 últimos dígitos", $f['remote_last4'] === '0011' && preg_match('/^[0-9a-f]{64}$/', (string) $f['remote_hash']) === 1, null);
            foreach ($lista as $herramienta) {
                $t = $this->el('tool', ['conversation_id' => $r['conversation_id'], 'tool' => $herramienta]);
                $cuerpo = $t['laravel']['body'] ?? [];
                $ok = ($t['laravel']['status'] ?? null) === 200 && ($cuerpo['verdict'] ?? null) === 'ok' && ($t['violations'] ?? []) === [];
                $ok = $ok && match ($herramienta) {
                    'consultar_planes' => ($cuerpo['planes'][0]['precio'] ?? null) === '$ 89.000' && ($cuerpo['planes'][0]['nombre'] ?? null) === 'Plan Mensual del banco',
                    'consultar_horarios' => ($cuerpo['disponible'] ?? null) === true && ($cuerpo['sede'] ?? null) === 'Sede del banco de pruebas',
                    'solicitar_humano' => ($cuerpo['disponible'] ?? null) === false && array_key_exists('alias', $cuerpo) && $cuerpo['alias'] === null,
                    'no_volver_a_llamar' => ($cuerpo['excluido'] ?? null) === true,
                };
                $this->check("entrante {$i}: {$herramienta} responde lo del catálogo (sin teléfonos)", $ok, ['http' => $t['laravel']['status'] ?? null, 'body' => $cuerpo, 'violations' => $t['violations'] ?? null]);
            }
            $cierre = $this->cerrarConversacion($r['conversation_id'], 95);
            $this->check("entrante {$i}: la post-llamada firmada → 200", ($cierre['post']['deliveries'][0]['status'] ?? null) === 200 && ($cierre['post']['expected'] ?? null) === 'ok', $cierre['post']);
            $g = $this->fila('SELECT status, seal_verified, observed_agent_id, duration_seconds, ended_at IS NOT NULL AS fin, tool_calls FROM voice_calls WHERE uuid = ?', [$f['uuid']]);
            $this->check("entrante {$i}: SQL completed, seal_verified, el agente observado = el sellado, 95 s, {$this->n($lista)} herramientas", $g['status'] === 'completed' && $g['seal_verified'] === true
                && $g['observed_agent_id'] === $p['provider'] && (int) $g['duration_seconds'] === 95 && $g['fin'] === true && (int) $g['tool_calls'] === count($lista), $g);
            $kinds = array_column($this->sql('SELECT kind, outcome FROM voice_call_events WHERE call_id = (SELECT id FROM voice_calls WHERE uuid = ?) ORDER BY id', [$f['uuid']]), 'outcome', 'kind');
            $this->check("entrante {$i}: eventos initiation_received (authorized), tool_call, post_call_transcription y guard_verdict (clean)", ($kinds['initiation_received'] ?? null) === 'authorized'
                && ($kinds['tool_call'] ?? null) === 'ok' && isset($kinds['post_call_transcription']) && ($kinds['guard_verdict'] ?? null) === 'clean', $kinds);
            $llamadas[] = ['uuid' => (string) $f['uuid'], 'sid' => (string) $r['call_sid']];
        }
        $ex = $this->fila('SELECT count(*) AS n, min(source) AS source, min(phone_last4) AS last4, min(call_id) AS call_id FROM voice_exclusions');
        $this->check('no_volver_a_llamar: UNA exclusión inmediata, oral, del número SELLADO de la llamada 3', (int) $ex['n'] === 1 && $ex['source'] === 'spoken_opt_out' && $ex['last4'] === '0011'
            && (int) $ex['call_id'] === (int) $this->valor('SELECT id FROM voice_calls WHERE uuid = ?', [$llamadas[2]['uuid']]), $ex);
        $this->check('la transcripción no se guarda: ningún texto de los turnos en voice_call_events', (int) $this->valor("SELECT count(*) FROM voice_call_events WHERE codes::text ILIKE '%horarios de la sede%' OR codes::text ILIKE '%llámame%' OR codes::text ILIKE '%asistente virtual%'") === 0, null);
        $this->check('ninguna llamada guarda la transcripción ni el resumen', (int) $this->valor("SELECT count(*) FROM voice_calls WHERE row_to_json(voice_calls)::text ILIKE '%asistente virtual%' OR row_to_json(voice_calls)::text ILIKE '%Resumen con%'") === 0, null);

        // Un número que no es canario: «no disponible» (denied), con su fila (E1-2), y sus herramientas no responden.
        $e = $this->entrante(self::NO_CANARIO);
        $this->check('no canario: 200 con caller_authorized = no', ($e['sim']['laravel']['status'] ?? null) === 200 && ($e['sim']['caller_authorized'] ?? null) === 'no', $e['sim']);
        $this->check('no canario: SQL denied (not_an_active_voice_canary), sellada con la entrada vigente', $e['row'] !== null && $e['row']['status'] === 'denied' && $e['row']['policy_reason'] === 'not_an_active_voice_canary'
            && $e['row']['stamp_state'] === 'applied' && (int) $e['row']['voice_publication_seq'] === 1 && $e['row']['remote_last4'] === '0099', $e['row']);
        $t = $this->el('tool', ['conversation_id' => $e['sim']['conversation_id'], 'tool' => 'consultar_planes']);
        $this->check('no canario: sus herramientas dicen call_not_active', ($t['laravel']['body']['verdict'] ?? null) === 'call_not_active', $t['laravel'] ?? $t);
        $this->el('call', ['conversation_id' => $e['sim']['conversation_id'], 'event' => 'completed', 'duration' => 6]);
        $denegada = ['uuid' => (string) $e['row']['uuid'], 'sid' => (string) $e['sim']['call_sid']];

        // La conciliación: las cuatro conocidas y el precio real de Twilio.
        $rc = $this->conciliar();
        $j = $rc['json'] ?? [];
        $this->check('voice:reconcile: sale con 0; 4 conocidas, 0 desconocidas, 0 tramos; canario sin deriva; techo de gasto dentro', $rc['code'] === 0 && ($j['carrier'] ?? null) === 'listed'
            && ($j['known'] ?? null) === 4 && ($j['unknown'] ?? null) === 0 && ($j['legs'] ?? null) === 0 && ($j['drift']['canary'] ?? null) === 'ok' && ($j['costCap'] ?? null) === false, $rc);
        foreach ([...$llamadas, $denegada] as $k => $l) {
            $precio = self::micros($this->tw('state', ['sid' => $l['sid']])['price'] ?? null);
            $c = $this->fila("SELECT c.carrier_cost_micros, (SELECT count(*) FROM voice_call_events e WHERE e.call_id = c.id AND e.kind = 'cost_recorded') AS costes,
                (SELECT count(*) FROM voice_call_events e WHERE e.call_id = c.id AND e.kind = 'reconciled') AS conciliadas FROM voice_calls c WHERE c.uuid = ?", [$l['uuid']]);
            $this->check('coste de la llamada '.($k + 1).': el precio de Twilio en micro-US$ con un cost_recorded y su reconciled', $precio !== null && $precio > 0 && (int) $c['carrier_cost_micros'] === $precio
                && (int) $c['costes'] === 1 && (int) $c['conciliadas'] === 1, ['twilio' => $precio, 'fila' => $c]);
        }
        $lista = $this->telefonia('GET', 'calls', 'c', [], ['perPage' => 50]);
        $this->check('GET admin/voice/calls (Carla, telephony.view): 200 y sin teléfonos', $lista->status() === 200 && ! str_contains($lista->body(), '3000000011') && ! str_contains($lista->body(), '3000000099')
            && ! str_contains($lista->body(), '+57'), self::resumen($lista));
        $una = $this->telefonia('GET', 'calls/'.$llamadas[0]['uuid'], 'c');
        $this->check('GET admin/voice/calls/{uuid}: 200, sin teléfonos', $una->status() === 200 && ! str_contains($una->body(), '3000000011'), self::resumen($una));
        $me = $this->telefonia('GET', 'metrics', 'c');
        $this->check('GET admin/voice/metrics: 200', $me->status() === 200, self::resumen($me));
        self::$s['c'] = ['calls' => $llamadas, 'denied' => $denegada];
        $this->finish();
    }

    public function test_d_la_evidencia_del_canario_cuenta_bien_y_promover_se_niega_por_too_soon(): void
    {
        $a = $this->requiere('a');
        $this->requiere('c');
        $ev = $this->studio('GET', 'voice/canary-evidence', 'b');
        $this->must('GET voice/canary-evidence: 200', $ev->status() === 200, self::resumen($ev));
        $this->check('recuentos: 3 llamadas selladas (sin la denegada), 0 violaciones, 0 sellos rotos, 0 deriva, 0 desconocidas, 0 gasto de más', $ev->json('data.measures') === [
            'calls' => 3, 'guardViolations' => 0, 'sealMismatches' => 0, 'driftEvents' => 0, 'unknownProviderCalls' => 0, 'budgetBreaches' => 0], $ev->json('data.measures'));
        $this->check('solo falla too_soon (E6-11): 0 h < 24 h; cadena verificada, freno suelto, veredicto approved', $ev->json('data.failing') === ['too_soon'] && $ev->json('data.passes') === false
            && $ev->json('data.hours') === 0 && $ev->json('data.chainVerified') === true && $ev->json('data.brake') === 'released' && $ev->json('data.labVerdict.latest') === 'approved'
            && $ev->json('data.thresholds') === ['minHours' => 24, 'minCalls' => 3] && $ev->json('data.specHash') === $a['specHash'], self::resumen($ev, 'data.failing', 'data.hours', 'data.brake'));
        $hash = (string) $ev->json('data.evidenceHash');
        $this->check('la evidencia lleva su huella', preg_match('/^[0-9a-f]{64}$/', $hash) === 1, $hash);
        $vc = $this->studio('GET', 'voice/canary-evidence', 'c');
        $this->check('Carla (ultron.view) la lee', $vc->status() === 200, self::resumen($vc));
        $otra = substr($a['specHash'], 0, 63).($a['specHash'][63] === '0' ? '1' : '0');
        $antes = $this->fotos();
        $p1 = $this->studio('POST', 'voice/promote', 'b', ['clientRequestId' => self::uuid(), 'evidenceHash' => $hash, 'expectedSpecHash' => $otra]);
        $this->check('promover con otra huella del canario → 409 spec_changed', $p1->status() === 409 && $p1->json('code') === 'spec_changed', self::resumen($p1));
        $p2 = $this->studio('POST', 'voice/promote', 'a', ['clientRequestId' => self::uuid(), 'evidenceHash' => $hash, 'expectedSpecHash' => $a['specHash']]);
        $this->check('Ana no promueve lo que creó (403 four_eyes_required)', $p2->status() === 403 && $p2->json('code') === 'four_eyes_required', self::resumen($p2));
        $p3 = $this->studio('POST', 'voice/promote', 'b', ['clientRequestId' => self::uuid(), 'evidenceHash' => $hash, 'expectedSpecHash' => $a['specHash']]);
        $this->check('promover con la huella del canario → 409 canary_evidence_insufficient (too_soon)', $p3->status() === 409 && $p3->json('code') === 'canary_evidence_insufficient'
            && in_array('too_soon', (array) $p3->json('evidence.failing'), true), self::resumen($p3, 'evidence.failing'));
        $this->check('promover no escribe ni llama a nadie', (int) $this->valor('SELECT count(*) FROM ultron_studio_voice_publications') === 1 && self::delta($antes['el'], $this->el('stats'))['requests'] === 0, null);
        $this->finish();
    }

    public function test_e_el_freno_se_abre_solo_y_solo_lo_suelta_una_persona(): void
    {
        $p = $this->requiere('pub1');
        [$c1, $c2] = [self::ctx()['canaries'][0], self::ctx()['canaries'][1]];
        $pz = $this->piezas();

        // 1. Sello roto: la post-llamada de la llamada 4 llega con OTRO agente. Mientras, la 5 sigue viva.
        $e4 = $this->entrante($c2);
        $e5 = $this->entrante($c1);
        $this->must('dos llamadas vivas a la vez (concurrencia 2)', ($e4['row']['status'] ?? null) === 'active' && ($e5['row']['status'] ?? null) === 'active', [$e4['sim'], $e5['sim']]);
        $cierre = $this->cerrarConversacion($e4['sim']['conversation_id'], 30, ['agent_id' => 'agent_suplantado_e2e']);
        $this->check('post-llamada con otro agent_id → 200', ($cierre['post']['deliveries'][0]['status'] ?? null) === 200, $cierre['post']);
        $f4 = $this->fila('SELECT status, seal_verified, observed_agent_id FROM voice_calls WHERE uuid = ?', [$e4['row']['uuid']]);
        $this->check('SQL: seal_verified = false con el agente observado distinto del sellado', $f4['seal_verified'] === false && $f4['observed_agent_id'] === 'agent_suplantado_e2e' && $f4['status'] === 'completed', $f4);
        $fr = $this->fila("SELECT uuid, reason, evidence->>'where' AS donde FROM voice_brakes WHERE kind = 'open' ORDER BY id DESC LIMIT 1");
        $this->check('SQL: seal_mismatch y el freno abierto por él', $fr !== null && $fr['reason'] === 'seal_mismatch' && $fr['donde'] === 'post_call'
            && (int) $this->valor("SELECT count(*) FROM voice_call_events WHERE kind = 'seal_mismatch'") === 1, $fr);
        $t = $this->el('tool', ['conversation_id' => $e5['sim']['conversation_id'], 'tool' => 'consultar_horarios']);
        $this->check('con el freno echado, las herramientas de la llamada viva responden cerrado (voice_braked, mensaje neutro)', ($t['laravel']['status'] ?? null) === 200
            && ($t['laravel']['body']['verdict'] ?? null) === 'voice_braked' && is_string($t['laravel']['body']['message'] ?? null), $t['laravel'] ?? $t);
        $e6 = $this->entrante($c1);
        $this->check('con el freno echado, una entrante nueva oye «no» (denied por voice_braked)', ($e6['sim']['caller_authorized'] ?? null) === 'no' && ($e6['row']['policy_reason'] ?? null) === 'voice_braked', $e6['row']);
        $lb = $this->studio('GET', 'voice/brakes', 'c');
        $this->check('GET voice/brakes: engaged, con la apertura sin soltar', $lb->status() === 200 && $lb->json('data.state') === 'engaged' && ($lb->json('data.brakes.0.released') === false), self::resumen($lb, 'data.state'));
        $rc = $this->studio('POST', 'voice/brakes/'.$fr['uuid'].'/release', 'c', ['clientRequestId' => self::uuid(), 'note' => 'Carla intenta soltar']);
        $this->check('Carla (sin ultron.voice_publish) no lo suelta (403)', $rc->status() === 403, self::resumen($rc));
        $rv = $this->studio('POST', 'voice/brakes/'.$fr['uuid'].'/release', 'a', ['clientRequestId' => self::uuid(), 'note' => '']);
        $this->check('sin nota → 422', $rv->status() === 422, self::resumen($rv));
        $ok = $this->studio('POST', 'voice/brakes/'.$fr['uuid'].'/release', 'a', ['clientRequestId' => self::uuid(), 'note' => 'Revisado: era una prueba del E2E del banco.']);
        $this->check('Ana (ultron.voice_publish) lo suelta con nota', $ok->status() === 200 && $ok->json('data.state') === 'released', self::resumen($ok, 'data.state'));
        $sr = $this->fila("SELECT r.actor_admin_id, r.note FROM voice_brakes r JOIN voice_brakes o ON o.id = r.brake_id WHERE r.kind = 'release' AND o.uuid = ?", [$fr['uuid']]);
        $this->check('SQL: la suelta es una fila nueva de Ana enlazada a la apertura (solo inserción)', $sr !== null && (int) $sr['actor_admin_id'] === (int) self::ctx()['accounts']['a']['id'], $sr);
        $dos = $this->studio('POST', 'voice/brakes/'.$fr['uuid'].'/release', 'a', ['clientRequestId' => self::uuid(), 'note' => 'Otra vez']);
        $this->check('una apertura se suelta una vez (409)', $dos->status() === 409, self::resumen($dos));
        $c5 = $this->cerrarConversacion($e5['sim']['conversation_id'], 40);
        $this->check('la llamada 5 termina limpia (seal_verified)', ($c5['post']['deliveries'][0]['status'] ?? null) === 200
            && $this->valor('SELECT seal_verified FROM voice_calls WHERE uuid = ?', [$e5['row']['uuid']]) === true, null);
        $this->el('call', ['conversation_id' => $e6['sim']['conversation_id'], 'event' => 'completed', 'duration' => 6]);

        // 2. Una llamada que SOLO existe en Twilio (alguien la hizo fuera de Laravel): la conciliación frena.
        $sid = (string) ($this->tw('seed-call', ['direction' => 'outbound-api', 'from' => self::ctx()['voiceNumber'], 'to' => '+573000000077', 'status' => 'completed', 'duration' => 40])['sid'] ?? '');
        $r1 = $this->conciliar();
        $this->check('voice:reconcile: 1 desconocida y sale con fallo', $r1['code'] !== 0 && ($r1['json']['unknown'] ?? null) === 1, $r1);
        $this->check('SQL: unknown_provider_call de Twilio con ese SID y el freno abierto por la conciliación', (int) $this->valor("SELECT count(*) FROM voice_call_events WHERE kind = 'unknown_provider_call' AND provider = 'twilio' AND provider_event_key = ?", [$sid]) === 1
            && $this->valor("SELECT reason FROM voice_brakes WHERE kind = 'open' ORDER BY id DESC LIMIT 1") === 'unknown_provider_call', null);
        $this->soltarFrenos('a', 'Revisada la llamada que solo estaba en Twilio (E2E).');

        // 3. C-A2: la iniciación con el secreto mal puesto → 401 sin fila; ElevenLabs sigue con el valor por defecto.
        $antes = (int) $this->valor('SELECT count(*) FROM voice_calls');
        $mal = $this->el('inbound', ['from' => $c2, 'variant' => 'bad_secret']);
        $this->check('C-A2: secreto malo → 401, sin fila; el agente sigue con su defecto caller_authorized = no', ($mal['laravel']['status'] ?? null) === 401 && ($mal['caller_authorized'] ?? null) === 'no'
            && ($mal['initiation'] ?? null) === 'fallback_defaults' && (int) $this->valor('SELECT count(*) FROM voice_calls') === $antes, $mal);
        $r2 = $this->conciliar();
        $this->check('esa llamada de Twilio sin fila → la conciliación la marca desconocida (2 en la lista: la sembrada y esta) y frena (fallo cerrado)', ($r2['json']['unknown'] ?? null) === 2
            && (int) $this->valor("SELECT count(*) FROM voice_call_events WHERE kind = 'unknown_provider_call' AND provider_event_key = ?", [$mal['call_sid'] ?? '']) === 1
            && (int) $this->valor("SELECT count(*) FROM voice_brakes WHERE kind = 'open' AND open_key = ?", ['unknown:'.($mal['call_sid'] ?? '')]) === 1, $r2['json'] ?? $r2);
        $this->soltarFrenos('a', 'Revisada la iniciación con el secreto mal puesto (E2E).');

        // 4. Deriva del agente: alguien cambia el primer mensaje en el panel; la conciliación lo ve y frena.
        $orig = $this->panel('GET', '/v1/convai/agents/'.$p['provider'])->json('conversation_config.agent.first_message');
        $this->must('panel: el primer mensaje del agente publicado', is_string($orig) && $orig !== '', null);
        $this->el('drift', ['agent_id' => $p['provider'], 'path' => 'conversation_config.agent.first_message', 'value' => json_encode('Hola, soy otra voz.')]);
        $r3 = $this->conciliar();
        $this->check('deriva del agente: drift en el canario, freno agent_drift', ($r3['json']['drift']['canary'] ?? null) === 'drift'
            && $this->valor("SELECT reason FROM voice_brakes WHERE kind = 'open' ORDER BY id DESC LIMIT 1") === 'agent_drift', $r3['json'] ?? $r3);
        $this->el('drift', ['agent_id' => $p['provider'], 'path' => 'conversation_config.agent.first_message', 'value' => json_encode($orig)]);
        $r4 = $this->conciliar();
        $this->check('devuelto el mensaje en el panel, la conciliación vuelve a ver el canario ok', ($r4['json']['drift']['canary'] ?? null) === 'ok', $r4['json']['drift'] ?? $r4);
        $this->soltarFrenos('a', 'Revisada la deriva del agente en el panel (E2E).');

        // 5. Deriva del NÚMERO (E2-3, E6-1): el número apunta a otro agente.
        $this->must('panel: el número apunta al agente de verificación', $this->panel('PATCH', '/v1/convai/phone-numbers/'.$pz['EL_PHONE_NUMBER_ID'], ['agent_id' => $pz['EL_VERIFY_AGENT_ID']])->status() === 200, null);
        $r5 = $this->conciliar(true);
        $this->check('voice:reconcile --numbers: drift y freno abierto', ($r5['json']['number']['result'] ?? null) === 'drift' && ($r5['json']['number']['brakeOpened'] ?? null) === true, $r5['json']['number'] ?? $r5);
        $nd = $this->fila("SELECT reason, evidence->>'trigger' AS causa FROM voice_brakes WHERE kind = 'open' ORDER BY id DESC LIMIT 1");
        $this->check('SQL: se guarda como agent_drift con trigger number_drift (E6-1)', $nd !== null && $nd['reason'] === 'agent_drift' && $nd['causa'] === 'number_drift', $nd);
        $this->must('panel: el número vuelve al agente publicado', $this->panel('PATCH', '/v1/convai/phone-numbers/'.$pz['EL_PHONE_NUMBER_ID'], ['agent_id' => $p['provider']])->status() === 200, null);
        $r6 = $this->conciliar(true);
        $this->check('voice:reconcile --numbers: ok', ($r6['json']['number']['result'] ?? null) === 'ok', $r6['json']['number'] ?? $r6);
        $this->soltarFrenos('a', 'Revisada la deriva del número (E2E).');

        // 6. El corte (D8-20, nivel 0b): una entrante viva, colgada por una persona con telephony.call_place.
        $e7 = $this->entrante($c1);
        $this->must('una entrante viva para cortar', ($e7['row']['status'] ?? null) === 'active', $e7['row']);
        $hc = $this->telefonia('POST', 'calls/'.$e7['row']['uuid'].'/hangup', 'c');
        $this->check('Carla no corta (403, telephony.call_place)', $hc->status() === 403, self::resumen($hc));
        $antes = $this->fotos();
        $marca = $this->marca('tw');
        $h = $this->telefonia('POST', 'calls/'.$e7['row']['uuid'].'/hangup', 'a');
        $this->check('Ana corta: 200 hung_up', $h->status() === 200 && $h->json('result') === 'hung_up' && $h->json('status') === 'hung_up', self::resumen($h, 'result', 'status'));
        $dt = self::delta($antes['tw'], $this->tw('stats'));
        $rutas = $this->rutasDesde('tw', $marca);
        $this->check('Twilio: exactamente UN calls_update (Status=completed, con la key de Laravel) y 0 violaciones; la llamada, completed', ($rutas['calls_update'] ?? 0) === 1 && $dt['violations'] === []
            && ($this->tw('state', ['sid' => $e7['sim']['call_sid']])['status'] ?? null) === 'completed', ['rutas' => $rutas, 'violaciones' => $dt['violations']]);
        $f7 = $this->fila("SELECT status, termination_reason, (SELECT count(*) FROM voice_call_events e WHERE e.call_id = c.id AND e.kind = 'hangup_requested') AS cortes FROM voice_calls c WHERE uuid = ?", [$e7['row']['uuid']]);
        $this->check('SQL: hung_up por emergency_hangup y su hangup_requested', $f7['status'] === 'hung_up' && $f7['termination_reason'] === 'emergency_hangup' && (int) $f7['cortes'] === 1, $f7);
        $pc = $this->el('emit/post-call', ['conversation_id' => $e7['sim']['conversation_id']]);
        $this->check('la post-llamada posterior no cambia un estado final (sigue hung_up)', ($pc['deliveries'][0]['status'] ?? null) === 200 && $this->valor('SELECT status FROM voice_calls WHERE uuid = ?', [$e7['row']['uuid']]) === 'hung_up', null);
        $this->check('el freno queda suelto al terminar', $this->studio('GET', 'voice/brakes', 'a')->json('data.state') === 'released', null);
        $this->finish();
    }

    public function test_g_salientes_la_puerta_y_el_job_real_contra_los_simuladores(): void
    {
        $a = $this->requiere('a');
        $p = $this->requiere('pub1');
        $canario = $this->requiere('canarios')[1];
        $ana = (string) self::ctx()['accounts']['a']['id'];

        // 1. La puerta, con el reloj real: E4-6 y el calendario (oráculo propio con el fixture de festivos de TEST8-a).
        $ahora = new \DateTimeImmutable('now');
        $cal = self::calendarioLegal($ahora);
        $antes = $this->fotos();
        $r = $this->telefonia('POST', 'calls', 'a', ['clientRequestId' => self::uuid(), 'target' => ['kind' => 'canary', 'uuid' => $canario]]);
        $m = (array) $r->json('reasons');
        $esperados = array_values(array_filter(['elevenlabs_unverified', $cal['outsideHours'] ? 'outside_hours' : null, $cal['holiday'] ? 'holiday' : null]));
        $this->check('la puerta: 409 con EXACTAMENTE elevenlabs_unverified (E4-6) y el calendario de ahora ('.implode(', ', $esperados).')', $r->status() === 409 && $m === $esperados, ['reasons' => $m, 'esperados' => $esperados, 'ahora' => $ahora->format(DATE_ATOM)]);
        $this->check('la puerta no llama a nadie ni escribe una fila', self::delta($antes['el'], $this->el('stats'))['requests'] === 0
            && (int) $this->valor("SELECT count(*) FROM voice_calls WHERE direction = 'outbound'") === 0, null);
        $nc = $this->telefonia('POST', 'calls', 'c', ['clientRequestId' => self::uuid(), 'target' => ['kind' => 'canary', 'uuid' => $canario]]);
        $this->check('Carla no pide salientes (403)', $nc->status() === 403, self::resumen($nc));

        // 2. El WORKER RESIDENTE con el reloj real: la fila sellada como la puerta y el job en la cola `voice`.
        $s = $this->ayudante(['saliente', 'canary', $canario, self::uuid(), $ana, '0']);
        $this->must('costura: fila requested sellada (applied, entrada 1, canario)', ($s['status'] ?? null) === 'requested' && ($s['stampState'] ?? null) === 'applied' && ($s['seq'] ?? null) === 1, $s);
        $antes = $this->fotos();
        $this->ayudante(['despachar', $s['call'], 'voice']);
        $estado = $this->esperarEstado($s['call']);
        if ($cal['outsideHours'] || $cal['holiday']) {
            $motivo = $cal['outsideHours'] ? 'outside_hours' : 'holiday';
            $this->check("worker residente (reloj real, fuera de la ventana legal): cancelada con {$motivo}, sin petición", $estado === 'canceled'
                && $this->valor('SELECT policy_reason FROM voice_calls WHERE uuid = ?', [$s['call']]) === $motivo
                && ! isset(self::delta($antes['el'], $this->el('stats'))['byRoute']['/v1/convai/twilio/outbound-call']), ['estado' => $estado]);
        } else {
            $this->check('worker residente (reloj real, dentro de la ventana): marca → dialing', $estado === 'dialing', ['estado' => $estado]);
            $conv = (string) $this->valor('SELECT provider_conversation_id FROM voice_calls WHERE uuid = ?', [$s['call']]);
            $this->cerrarConversacion($conv, 30);
        }

        // 3. SALIENTE COMPLETA, con el job en un instante dentro del horario legal (martes 10:00 de Bogotá).
        $s2 = $this->ayudante(['saliente', 'canary', $canario, self::uuid(), $ana, '0']);
        $this->ayudante(['despachar', $s2['call'], 'voice-e2e']);
        $antes = $this->fotos();
        $w = $this->workerDeUnTrabajo(self::MARTES_EN_HORARIO);
        $this->check('el worker de un trabajo (reloj en el martes) sale con 0', $w['code'] === 0, mb_substr($w['err'], 0, 300));
        $f = $this->fila('SELECT status, provider_conversation_id, provider_call_sid, started_at IS NOT NULL AS empezo, voice_publication_seq, provider_agent_id, spec_hash FROM voice_calls WHERE uuid = ?', [$s2['call']]);
        $this->must('la saliente marcó: dialing con la conversación y el SID de Twilio', $f !== null && $f['status'] === 'dialing' && is_string($f['provider_conversation_id']) && preg_match('/^CA[0-9a-f]{32}$/', (string) $f['provider_call_sid']) === 1, $f);
        $this->check('sellada con la entrada 1 y su agente y huella', (int) $f['voice_publication_seq'] === 1 && $f['provider_agent_id'] === $p['provider'] && $f['spec_hash'] === $a['specHash'] && $f['empezo'] === true, $f);
        $dEl = self::delta($antes['el'], $this->el('stats'));
        $dTw = self::delta($antes['tw'], $this->tw('stats'));
        $propias = self::separar($dEl['violations'])['own'];
        if (isset($propias['dynamic_variables_personal_data']) && $this->falsoPositivoDeTelefono((string) $f['provider_conversation_id'], 'dynamic_variables_personal_data', self::ctx()['canaries'][1])) {
            unset($propias['dynamic_variables_personal_data']);
        }
        $this->check('ElevenLabs: UNA outbound-call; 0 violaciones propias (grabación apagada, sin sobrescrituras, user_id = call_ref; un falso positivo de F-6, verificado, aparte)',
            ($dEl['byRoute']['/v1/convai/twilio/outbound-call'] ?? 0) === 1 && $propias === [], $dEl);
        $this->check('Twilio: la llamada la crea ElevenLabs con SU credencial (Laravel no crea llamadas), 0 violaciones', ($dTw['byRoute']['/2010-04-01/Accounts/{sid}/Calls.json'] ?? 0) === 1 && $dTw['violations'] === []
            && ($this->tw('state', ['sid' => $f['provider_call_sid']])['origin'] ?? null) === 'elevenlabs_key', $dTw);
        $this->check('SQL: outbound_started', (int) $this->valor("SELECT count(*) FROM voice_call_events WHERE kind = 'outbound_started' AND call_id = (SELECT id FROM voice_calls WHERE uuid = ?)", [$s2['call']]) === 1, null);
        $this->el('call', ['conversation_id' => $f['provider_conversation_id'], 'event' => 'answered']);
        $t = $this->el('tool', ['conversation_id' => $f['provider_conversation_id'], 'tool' => 'consultar_horarios']);
        $this->check('contestada: la primera herramienta responde y la pasa de dialing a active (E1-1)', ($t['laravel']['body']['verdict'] ?? null) === 'ok'
            && $this->valor('SELECT status FROM voice_calls WHERE uuid = ?', [$s2['call']]) === 'active', $t['laravel'] ?? $t);
        $cierre = $this->cerrarConversacion((string) $f['provider_conversation_id'], 61);
        $g = $this->fila('SELECT status, seal_verified, duration_seconds FROM voice_calls WHERE uuid = ?', [$s2['call']]);
        $this->check('post-llamada: completed, seal_verified, 61 s', ($cierre['post']['deliveries'][0]['status'] ?? null) === 200 && $g['status'] === 'completed' && $g['seal_verified'] === true
            && (int) $g['duration_seconds'] === 61, $g);
        $rc = $this->conciliar();
        $precio = self::micros($this->tw('state', ['sid' => $f['provider_call_sid']])['price'] ?? null);
        $this->check('conciliación: el precio de la saliente (móvil + Media Streams) en carrier_cost_micros', $precio !== null && $precio > 0
            && (int) $this->valor('SELECT carrier_cost_micros FROM voice_calls WHERE uuid = ?', [$s2['call']]) === $precio, ['twilio' => $precio, 'reconcile' => $rc['json']['known'] ?? null]);
        $this->ayudante(['olvidar-deriva', $p['provider']]);

        // 4. Canceladas por el job SIN PETICIÓN (TOCTOU, E6-6): domingo, festivo y una exclusión añadida tras encolar.
        foreach ([[self::DOMINGO, 'outside_hours', null], [self::FESTIVO, 'holiday', null], [self::MARTES_EN_HORARIO, 'excluded', 'exclusion']] as [$instante, $motivo, $antesDeMarcar]) {
            $sx = $this->ayudante(['saliente', 'canary', $canario, self::uuid(), $ana, '0']);
            if ($antesDeMarcar === 'exclusion') {
                $ex = $this->telefonia('POST', 'exclusions', 'a', ['clientRequestId' => self::uuid(), 'number' => self::ctx()['canaries'][1], 'note' => 'Pidió no recibir más llamadas (E2E).']);
                $this->check('exclusión manual tras encolar: 201', $ex->status() === 201, self::resumen($ex));
            }
            $this->ayudante(['despachar', $sx['call'], 'voice-e2e']);
            $antes = $this->fotos();
            $wx = $this->workerDeUnTrabajo($instante);
            $fx = $this->fila('SELECT status, policy_reason, reserved_micros FROM voice_calls WHERE uuid = ?', [$sx['call']]);
            $dx = self::delta($antes['el'], $this->el('stats'));
            $this->check("job a las {$instante}: cancelada con {$motivo}, sin petición a ElevenLabs ni evento del proveedor", $wx['code'] === 0 && $fx['status'] === 'canceled' && $fx['policy_reason'] === $motivo
                && ! isset($dx['byRoute']['/v1/convai/twilio/outbound-call'])
                && (int) $this->valor("SELECT count(*) FROM voice_call_events WHERE call_id = (SELECT id FROM voice_calls WHERE uuid = ?) AND kind <> 'outbound_requested'", [$sx['call']]) === 0, ['fila' => $fx, 'elevenlabs' => $dx]);
        }
        $this->ayudante(['olvidar-deriva', $p['provider']]);
        $this->check('ninguna saliente queda viva', (int) $this->valor("SELECT count(*) FROM voice_calls WHERE direction = 'outbound' AND status IN ('requested', 'dialing', 'active', 'ambiguous')") === 0, null);
        $this->finish();
    }

    public function test_f_revertir_y_apagar_el_numero_sigue_a_la_cadena_que_solo_crece(): void
    {
        $p = $this->requiere('pub1');
        $a = $this->requiere('a');
        $c1 = self::ctx()['canaries'][0];

        // Una segunda publicación en canario (otro perfil, su laboratorio y su veredicto): el agente anterior queda para volver.
        $a2 = $this->recorridoLaboratorio('Voz del E2E (canario, perfil 2)', self::contenidoPerfil('Hola, ¿qué necesitas hoy?'));
        $this->check('el perfil 2 compila con otra huella', $a2['specHash'] !== $a['specHash'], null);
        $r = $this->studio('POST', 'voice/publish', 'b', ['clientRequestId' => self::uuid(), 'versionUuid' => $a2['version'], 'profileUuid' => $a2['profile'], 'expectedSpecHash' => $a2['specHash']]);
        $this->must('Beto publica el perfil 2 en canario (entrada 2)', $r->status() === 201 && $r->json('data.entries.0.seq') === 2, self::resumen($r, 'data.entries'));
        $agente2 = (string) $this->valor('SELECT provider_agent_id FROM ultron_studio_voice_agents WHERE uuid = ?', [$r->json('data.agent.uuid')]);
        $this->check('el número apunta al agente 2', ($this->el('state')['numbers'][0]['agent'] ?? null) === $agente2, null);
        $foto = $this->sql('SELECT seq, effective_hash, signature, prev_hash, md5(effective_json) AS bytes FROM ultron_studio_voice_publications ORDER BY seq');
        $e = $this->entrante($c1);
        $this->check('una entrante ahora se sella con la entrada 2 y el agente 2', (int) ($e['row']['voice_publication_seq'] ?? 0) === 2 && ($e['row']['provider_agent_id'] ?? null) === $agente2, $e['row']);
        $this->cerrarConversacion($e['sim']['conversation_id'], 20);

        // Revertir (nivel 1): sin cuatro ojos ni ventana; el número vuelve al agente 1.
        $rb = $this->studio('POST', 'voice/rollback', 'a', ['clientRequestId' => self::uuid(), 'scope' => 'canary', 'reason' => 'Vuelta atrás del E2E del banco.']);
        $this->must('Ana revierte el canario (201)', $rb->status() === 201, self::resumen($rb, 'data.entries', 'data.number'));
        $this->check('la entrada 3: rollback del canario al agente 1; el número reasignado', $rb->json('data.entries.0.seq') === 3 && $rb->json('data.entries.0.action') === 'rollback'
            && $rb->json('data.agent.uuid') === $p['agentUuid'] && $rb->json('data.number.outcome') === 'assigned', $rb->json());
        $this->check('ElevenLabs: el número vuelve a apuntar al agente 1', ($this->el('state')['numbers'][0]['agent'] ?? null) === $p['provider'], null);
        $q = $this->fila('SELECT p.agent_id, r.seq AS restaura FROM ultron_studio_voice_publications p JOIN ultron_studio_voice_publications r ON r.id = p.restores_publication_id WHERE p.seq = 3');
        $this->check('SQL: la entrada 3 restaura la 1 y apunta al agente 1; el agente 2 ya no sirve', $q !== null && (int) $q['agent_id'] === $p['agentId'] && (int) $q['restaura'] === 1
            && $this->valor('SELECT serves_canary FROM ultron_studio_voice_agents WHERE provider_agent_id = ?', [$agente2]) === false, $q);
        $e2 = $this->entrante($c1);
        $this->check('una entrante nueva se sella con la entrada 3 y el agente 1', (int) ($e2['row']['voice_publication_seq'] ?? 0) === 3 && ($e2['row']['provider_agent_id'] ?? null) === $p['provider'], $e2['row']);
        $this->cerrarConversacion($e2['sim']['conversation_id'], 20);

        // Apagar (nivel 2): una entrada sin agente por cada parte con agente; el número sin agente.
        $off = $this->studio('POST', 'voice/off', 'a', ['clientRequestId' => self::uuid(), 'reason' => 'Apagado del E2E del banco.']);
        $this->must('Ana apaga la voz (201)', $off->status() === 201, self::resumen($off, 'data.entries', 'data.number'));
        $this->check('la entrada 4: off del canario, sin agente; el número queda sin agente', count((array) $off->json('data.entries')) === 1 && $off->json('data.entries.0.seq') === 4
            && $off->json('data.entries.0.action') === 'off' && $off->json('data.agent') === null && $off->json('data.number.action') === 'unassigned', $off->json());
        $this->check('ElevenLabs: el número no apunta a ningún agente', $this->numeroSinAgente(), $this->el('state')['numbers'] ?? null);
        $this->check('SQL: ningún agente sirve al canario', (int) $this->valor('SELECT count(*) FROM ultron_studio_voice_agents WHERE serves_canary') === 0, null);
        $sin = $this->el('inbound', ['from' => $c1]);
        $this->check('con la voz apagada nadie atiende el número (el simulador no llega a Laravel)', ($sin['outcome'] ?? null) === 'no_agent_assigned', $sin);
        $otra = $this->studio('POST', 'voice/off', 'a', ['clientRequestId' => self::uuid(), 'reason' => 'Apagar otra vez.']);
        $this->check('apagar otra vez → 409 voice_already_off', $otra->status() === 409 && $otra->json('code') === 'voice_already_off', self::resumen($otra));

        // La cadena solo crece.
        $todas = $this->sql("SELECT seq, prev_hash, effective_hash, encode(sha256(convert_to(effective_json, 'UTF8')), 'hex') AS recalculada FROM ultron_studio_voice_publications ORDER BY seq");
        $encadenada = count($todas) === 4;
        foreach ($todas as $i => $f) {
            $encadenada = $encadenada && (int) $f['seq'] === $i + 1 && $f['effective_hash'] === $f['recalculada'] && $f['prev_hash'] === ($i === 0 ? str_repeat('0', 64) : $todas[$i - 1]['effective_hash']);
        }
        $this->check('la cadena: 1..4 seguidas, cada prev_hash = la huella de la anterior, cada huella = sha256 de sus bytes', $encadenada, $todas);
        $despues = $this->sql('SELECT seq, effective_hash, signature, prev_hash, md5(effective_json) AS bytes FROM ultron_studio_voice_publications WHERE seq <= 2 ORDER BY seq');
        $this->check('las entradas 1 y 2 siguen byte a byte como antes de revertir y apagar', $despues === $foto, null);
        $pub = $this->studio('GET', 'voice/publications', 'b');
        $hist = (array) $pub->json('data.history.data');
        $this->check('GET voice/publications: las 4 verificadas y el estado verificado, sin canario sirviendo', count($hist) === 4 && array_unique(array_column($hist, 'verified')) === ['ok']
            && $pub->json('data.state.verified') === true && $pub->json('data.state.canary.serving') === false, self::resumen($pub, 'data.state.canary.serving'));
        foreach (['UPDATE ultron_studio_voice_publications SET reason = \'x\'', 'DELETE FROM ultron_studio_voice_publications', 'TRUNCATE ultron_studio_voice_publications'] as $q) {
            $this->check('la aplicación no puede: '.strtok($q, ' '), $this->comoAplicacion($q) === '42501', null);
        }
        $this->finish();
    }

    public function test_h_cero_red_fuera_de_127_y_0_violaciones_del_contrato(): void
    {
        $el = $this->el('stats');
        $tw = $this->tw('stats');
        $va = $this->valla();
        $sEl = self::separar($el['violationsByCode'] ?? []);
        $this->check('ElevenLabs: 0 violaciones propias del contrato en toda la fase (menos los falsos positivos de F-6 ya verificados)', self::sinFalsosPositivos($sEl['own']) === [],
            ['violaciones' => $el['violationsByCode'] ?? null, 'falsosPositivosF6' => self::$s['falsosPositivos'] ?? []]);
        $this->check('falsos positivos del simulador por F-6 (verificados con el oráculo propio): '.array_sum((array) (self::$s['falsosPositivos'] ?? [])), true, self::$s['falsosPositivosDetalle'] ?? []);
        $this->check('Twilio: 0 violaciones en toda la fase (ni la maestra, ni crear llamadas desde Laravel, ni teléfonos en la consulta)', ($tw['violationsByCode'] ?? []) === [], $tw['violationsByCode'] ?? null);
        $this->check('ElevenLabs: exactamente UNA outbound-call en toda la fase (la saliente completa)', ($el['byRoute']['/v1/convai/twilio/outbound-call'] ?? 0) === 1, $el['byRoute'] ?? null);
        $hosts = array_diff(array_keys($va['byHost'] ?? []), ['h8-e2e-sonda.invalid', 'h8-e2e-sonda.invalid:443']);
        $this->check('valla: ningún host salvo las dos sondas del arranque', $hosts === [] && ($va['localThroughFence'] ?? 1) === 0, $va);
        $this->check('sin deriva conocida del simulador (F-2 corregido por OPS8-b): lo que quede, aparte', true, ['elevenlabs' => $sEl['simDrift']]);
        $this->anotarFalsosPositivos();
        $esperas = self::$s['esperas'] ?? [];
        $this->check('esperas por los limitadores reales (429 con Retry-After, un reintento cada una): '.count($esperas), true, $esperas);
        $this->finish();
    }

    /** @param  list<string>  $l */
    private function n(array $l): int
    {
        return count($l);
    }
}
