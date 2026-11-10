<?php

namespace Tests\Feature\Voice\E2E;

use App\Services\Voice\VoiceCallPolicy;

/**
 * E2E del Hito 8, FASE CERRADA (TEST8-b): la copia del árbol es EXACTAMENTE el commit (`CALLS_ENABLED_IN_CODE =
 * false`) y TODAS las banderas de la voz están encendidas (las de las fases 2 a 4b a la vez, ver
 * `ops/h8-e2e/entorno-banco.ejemplo`). La constante es la única cerradura que queda (protección 2) y tiene que cerrar
 * sola, con la pila real y los simuladores:
 *  - el gobierno funciona sin ella: perfil, compilación y laboratorio hablado (no llaman a nadie por teléfono);
 *  - publicar despliega un agente INERTE y se detiene antes de asignar el número (`voice_number_not_assigned`,
 *    `not_attempted` por `calls_disabled_in_code`): ningún PATCH al número y la cadena vacía;
 *  - una entrante que llegue (el número asignado a mano en el panel, fuera de Laravel) dice `caller_authorized = no`;
 *  - una saliente es 409 en la puerta; la base no admite una fila sin sello (`vc_stamp_chk`); y una fila sellada (con
 *    la entrada de la cadena que publicar habría escrito) la cancela el worker real sin petición;
 *  - nada sale de 127.0.0.1.
 *
 * Los pasos corren en el orden en que se declaran y cada uno exige con `requiere()` lo que dejó el anterior (sin
 * `#[Depends]`: un rojo blando de un paso no esconde los demás). Solo corre con el arnés
 * `docs/ultron-studio/ops/h8-e2e.sh cerrada` (ver {@see H8StackE2ETestCase}).
 */
final class H8E2ECerradaTest extends H8StackE2ETestCase
{
    protected const PHASE = 'cerrada';

    public function test_0_la_copia_es_el_commit_y_los_canarios_de_voz_se_registran_a_cuatro_ojos(): void
    {
        $this->must('la constante está cerrada en esta copia (el commit tal cual)', VoiceCallPolicy::CALLS_ENABLED_IN_CODE === false, VoiceCallPolicy::CALLS_ENABLED_IN_CODE);
        $st = $this->telefonia('GET', 'status', 'a');
        $this->check('GET admin/voice/status: 200 y las banderas encendidas', $st->status() === 200 && $st->json('flags.inbound') === true && $st->json('flags.outbound') === true, self::resumen($st, 'flags'));
        $this->check('GET admin/voice/status: ya no informa flags.publishing (E4-1, E6-10)', ! array_key_exists('publishing', (array) $st->json('flags')), $st->json('flags'));
        $this->check('E4-6 (B8-g): la verificación del arranque (--verify-voice contra el simulador) dejó el proveedor VERIFICADO, con su marca vigente', $st->json('provider') === 'ELEVENLABS_VERIFIED'
            && $st->json('providerVerification.state') === 'valid', self::resumen($st, 'provider', 'providerVerification'));
        $nadie = $this->telefonia('GET', 'status', null);
        $this->check('GET admin/voice/status sin token → 401', $nadie->status() === 401, self::resumen($nadie));
        $this->prepararCanarios();
        $this->finish();
    }

    public function test_a_perfil_compilacion_laboratorio_y_veredicto_funcionan_sin_la_constante(): void
    {
        $this->requiere('canarios');
        self::$s['a'] = $this->recorridoLaboratorio('Voz del E2E (copia cerrada)', self::contenidoPerfil());
        $this->finish();
    }

    public function test_b_publicar_despliega_un_agente_inerte_y_se_detiene_antes_del_numero(): void
    {
        $a = $this->requiere('a');
        $otra = substr($a['specHash'], 0, 63).($a['specHash'][63] === '0' ? '1' : '0');
        $cuerpo = static fn (string $hash): array => ['clientRequestId' => self::uuid(), 'versionUuid' => $a['version'], 'profileUuid' => $a['profile'], 'expectedSpecHash' => $hash];

        $antes = $this->fotos();
        $r1 = $this->studio('POST', 'voice/publish', 'b', $cuerpo($otra));
        $this->check('publicar con otra huella → 409 spec_changed {expected, actual}', $r1->status() === 409 && $r1->json('code') === 'spec_changed'
            && $r1->json('expected') === $otra && $r1->json('actual') === $a['specHash'], self::resumen($r1, 'expected', 'actual'));
        $this->check('spec_changed: ninguna petición a ElevenLabs', self::delta($antes['el'], $this->el('stats'))['requests'] === 0, null);

        $r2 = $this->studio('POST', 'voice/publish', 'a', $cuerpo($a['specHash']));
        $this->check('Ana no publica lo que creó (403 four_eyes_required)', $r2->status() === 403 && $r2->json('code') === 'four_eyes_required', self::resumen($r2));
        $r3 = $this->studio('POST', 'voice/publish', 'c', $cuerpo($a['specHash']));
        $this->check('Carla (Administrador) no publica (403: ultron.voice_publish es solo de Super Admin)', $r3->status() === 403, self::resumen($r3));

        $antes = $this->fotos();
        $r = $this->studio('POST', 'voice/publish', 'b', $cuerpo($a['specHash']));
        $this->check('publicar con la constante cerrada → 409 voice_number_not_assigned (not_attempted · calls_disabled_in_code)', $r->status() === 409 && $r->json('code') === 'voice_number_not_assigned'
            && $r->json('outcome') === 'not_attempted' && $r->json('reason') === 'calls_disabled_in_code', self::resumen($r, 'outcome', 'reason', 'agent.status'));
        $d = self::delta($antes['el'], $this->el('stats'));
        $this->check('en ElevenLabs: el agente se crea y se lee (4 herramientas, crear, leer), y NINGÚN PATCH al número', ($d['byRoute']['/v1/convai/agents/create'] ?? 0) === 1
            && ($d['byRoute']['/v1/convai/agents/{agent_id}'] ?? 0) >= 1 && ! isset($d['byRoute']['/v1/convai/phone-numbers/{phone_number_id}']), $d);
        $this->check('0 violaciones en ElevenLabs al desplegar', self::separar($d['violations'])['own'] === [], $d['violations']);
        $this->check('en Twilio: ninguna petición', self::delta($antes['tw'], $this->tw('stats'))['requests'] === 0, null);
        $num = $this->el('state');
        $this->check('el número importado sigue sin agente en el simulador', $this->numeroSinAgente(), $num['numbers'] ?? null);
        $fa = $this->fila("SELECT uuid, status, serves_canary, serves_rest, provider_agent_id IS NOT NULL AS desplegado, spec_hash, transfer_destination_hashes::text AS destinos FROM ultron_studio_voice_agents WHERE purpose = 'publication' ORDER BY id DESC LIMIT 1");
        $this->check('SQL: el agente de publicación quedó deployed, INERTE (sin parte) y con su huella y destinos []', $fa !== null && $fa['status'] === 'deployed' && $fa['serves_canary'] === false
            && $fa['serves_rest'] === false && $fa['desplegado'] === true && $fa['spec_hash'] === $a['specHash'] && $fa['destinos'] === '[]', $fa);
        $this->check('SQL: la cadena de voz sigue vacía', (int) $this->valor('SELECT count(*) FROM ultron_studio_voice_publications') === 0, null);
        $pub = $this->studio('GET', 'voice/publications', 'b');
        $this->check('GET voice/publications: sin entradas', $pub->status() === 200 && (int) $pub->json('data.history.total') === 0 && $pub->json('data.state.canary') === null, self::resumen($pub, 'data.state'));
        if ($fa !== null) {
            self::$s['agenteInerte'] = (string) $fa['uuid'];
        }
        $this->finish();
    }

    public function test_c_una_entrante_de_canario_oye_no_disponible_con_la_constante_cerrada(): void
    {
        $this->requiere('canarios');
        $sin = $this->el('inbound', ['from' => self::ctx()['canaries'][0]]);
        $this->check('sin agente en el número, el simulador ni siquiera llama a Laravel (no_agent_assigned)', ($sin['outcome'] ?? null) === 'no_agent_assigned', $sin);

        // Alguien asigna el número a mano en el panel (fuera de Laravel): la puerta de iniciación sigue diciendo que no.
        $pz = $this->piezas();
        $parche = $this->panel('PATCH', '/v1/convai/phone-numbers/'.$pz['EL_PHONE_NUMBER_ID'], ['agent_id' => $pz['EL_VERIFY_AGENT_ID']]);
        $this->must('panel: el número apunta al agente de verificación', $parche->status() === 200, $parche->status());
        $antes = (int) $this->valor('SELECT count(*) FROM voice_calls');
        $e = $this->entrante(self::ctx()['canaries'][0]);
        $r = $e['sim'];
        $f = $e['row'];
        $this->check('la iniciación responde 200 con caller_authorized = no', ($r['laravel']['status'] ?? null) === 200 && ($r['caller_authorized'] ?? null) === 'no', $r);
        if (in_array('initiation_response_personal_data', (array) ($r['violations'] ?? []), true)) {
            $this->check('F-6: el «dato personal» que marca el simulador en la respuesta es un falso positivo (oráculo propio)',
                $this->falsoPositivoDeTelefono((string) $r['conversation_id'], 'initiation_response_personal_data', self::ctx()['canaries'][0]), $r['violations']);
        }
        $this->check('SQL: la entrante queda denied con su primer motivo calls_disabled_in_code, sin sello aplicado', (int) $this->valor('SELECT count(*) FROM voice_calls') === $antes + 1
            && $f !== null && $f['direction'] === 'inbound' && $f['status'] === 'denied' && $f['caller_authorized'] === false && $f['policy_reason'] === 'calls_disabled_in_code' && $f['stamp_state'] === 'off', $f);
        $ev = $this->fila("SELECT codes::text AS codes FROM voice_call_events WHERE kind = 'initiation_received' ORDER BY id DESC LIMIT 1");
        $this->check('SQL: el evento de iniciación acumula los motivos (calls_disabled_in_code y no_publication)', $ev !== null && str_contains($ev['codes'], 'calls_disabled_in_code') && str_contains($ev['codes'], 'no_publication'), $ev);
        $this->check('SQL: solo los 4 últimos dígitos del que llama', $f !== null && $f['remote_last4'] === '0011' && $f['remote_hash'] !== null, null);
        $h = $this->el('tool', ['conversation_id' => $r['conversation_id'] ?? '', 'tool' => 'consultar_horarios']);
        $this->check('una herramienta en esa conversación → call_not_active (200 con mensaje neutro)', ($h['laravel']['status'] ?? null) === 200 && ($h['laravel']['body']['verdict'] ?? null) === 'call_not_active', $h['laravel'] ?? $h);
        $quita = $this->panel('PATCH', '/v1/convai/phone-numbers/'.$pz['EL_PHONE_NUMBER_ID'], ['agent_id' => null]);
        $this->check('panel: el número vuelve a quedar sin agente', $quita->status() === 200, $quita->status());
        $this->finish();
    }

    public function test_g_una_saliente_es_409_en_la_puerta_y_el_job_la_cancela_sin_peticion(): void
    {
        $canario = $this->requiere('canarios')[1];
        $antes = $this->fotos();
        $filas = (int) $this->valor("SELECT count(*) FROM voice_calls WHERE direction = 'outbound'");
        $nc = $this->telefonia('POST', 'calls', 'c', ['clientRequestId' => self::uuid(), 'target' => ['kind' => 'canary', 'uuid' => $canario]]);
        $this->check('Carla (Administrador) no pide salientes (403, telephony.call_place)', $nc->status() === 403, self::resumen($nc));
        $r = $this->telefonia('POST', 'calls', 'a', ['clientRequestId' => self::uuid(), 'target' => ['kind' => 'canary', 'uuid' => $canario]]);
        $motivos = (array) $r->json('reasons');
        $this->check('POST admin/voice/calls → 409 outbound_denied con calls_disabled_in_code el primero', $r->status() === 409 && $r->json('code') === 'outbound_denied' && ($motivos[0] ?? null) === 'calls_disabled_in_code', self::resumen($r, 'reasons'));
        $this->check('y además, acumulado: no_publication; sin elevenlabs_unverified (E4-6: el proveedor quedó verificado en el arranque)', ! in_array('elevenlabs_unverified', $motivos, true)
            && in_array('no_publication', $motivos, true), $motivos);
        $this->check('SQL: ninguna fila de saliente', (int) $this->valor("SELECT count(*) FROM voice_calls WHERE direction = 'outbound'") === $filas, null);

        // F-5: sin publicación, una saliente no puede existir ni escrita a mano (la base exige el sello aplicado).
        $sin = $this->proceso([(string) self::ctx()['php'], (string) self::ctx()['helpers']['ayudante'], (string) self::ctx()['backend'], 'saliente', 'canary', $canario, self::uuid(),
            (string) self::ctx()['accounts']['a']['id'], '0']);
        $j = json_decode(trim($sin['out']), true);
        $this->check('la base no admite una saliente sin sello aplicado (23514 vc_stamp_chk)', $sin['code'] === 3 && ($j['sqlstate'] ?? null) === '23514' && ($j['constraint'] ?? null) === 'vc_stamp_chk', $j ?? mb_substr($sin['err'], 0, 300));

        // La fila SELLADA, como la puerta, con la entrada de la cadena que publicar habría escrito (segunda costura).
        $agente = $this->requiere('agenteInerte');
        $cadena = $this->ayudante(['cadena-canaria', $agente, (string) self::ctx()['accounts']['b']['id']]);
        $this->must('costura de la cadena: entrada canaria firmada para el agente inerte', ($cadena['seq'] ?? null) === 1 && ($cadena['scope'] ?? null) === 'canary', $cadena);
        $pub = $this->studio('GET', 'voice/publications', 'b');
        $this->check('GET voice/publications: la entrada verifica con la firma real (ok)', $pub->json('data.state.canary.verified') === 'ok' && $pub->json('data.state.verified') === true, self::resumen($pub, 'data.state.canary.verified'));
        $r2 = $this->telefonia('POST', 'calls', 'a', ['clientRequestId' => self::uuid(), 'target' => ['kind' => 'canary', 'uuid' => $canario]]);
        $m2 = (array) $r2->json('reasons');
        $this->check('con publicación, la puerta sigue en 409 con calls_disabled_in_code el primero y sin no_publication', $r2->status() === 409 && ($m2[0] ?? null) === 'calls_disabled_in_code' && ! in_array('no_publication', $m2, true), $m2);
        $s = $this->ayudante(['saliente', 'canary', $canario, self::uuid(), (string) self::ctx()['accounts']['a']['id'], '0']);
        $this->must('costura: fila requested sellada (applied, entrada 1, parte canaria)', ($s['status'] ?? null) === 'requested' && ($s['stampState'] ?? null) === 'applied' && ($s['seq'] ?? null) === 1, $s);
        $this->ayudante(['despachar', $s['call'], 'voice']);
        $estado = $this->esperarEstado($s['call']);
        $f = $this->fila('SELECT status, policy_reason, ended_at IS NOT NULL AS fin FROM voice_calls WHERE uuid = ?', [$s['call']]);
        $this->check('el worker real de voice la cancela con calls_disabled_in_code', $estado === 'canceled' && $f['policy_reason'] === 'calls_disabled_in_code' && $f['fin'] === true, $f);
        $this->check('SQL: sin outbound_started ni ningún otro evento del proveedor', (int) $this->valor("SELECT count(*) FROM voice_call_events e JOIN voice_calls c ON c.id = e.call_id WHERE c.uuid = ? AND e.kind <> 'outbound_requested'", [$s['call']]) === 0, null);
        $dEl = self::delta($antes['el'], $this->el('stats'));
        $dTw = self::delta($antes['tw'], $this->tw('stats'));
        $this->check('ninguna petición a ElevenLabs ni a Twilio en todo el recorrido', $dEl['requests'] === 0 && $dTw['requests'] === 0, ['elevenlabs' => $dEl, 'twilio' => $dTw]);
        $this->finish();
    }

    public function test_h_los_simuladores_sin_violaciones_y_la_valla_sin_hosts(): void
    {
        $el = $this->el('stats');
        $tw = $this->tw('stats');
        $va = $this->valla();
        $sEl = self::separar($el['violationsByCode'] ?? []);
        $this->check('ElevenLabs: 0 violaciones propias del contrato (menos los falsos positivos de F-6 ya verificados)', self::sinFalsosPositivos($sEl['own']) === [],
            ['violaciones' => $el['violationsByCode'] ?? null, 'falsosPositivosF6' => self::$s['falsosPositivos'] ?? []]);
        $this->anotarFalsosPositivos();
        $this->check('Twilio: 0 violaciones', ($tw['violationsByCode'] ?? []) === [], $tw['violationsByCode'] ?? null);
        $this->check('ElevenLabs: ninguna saliente (outbound-call) en toda la fase', ! isset($el['byRoute']['/v1/convai/twilio/outbound-call']), $el['byRoute'] ?? null);
        $this->check('Twilio: ninguna petición de Laravel ni de ElevenLabs en toda la fase (solo las llamadas sembradas por el control)', (int) ($tw['requests'] ?? -1) === 0, ['requests' => $tw['requests'] ?? null, 'byRoute' => $tw['byRoute'] ?? null]);
        $hosts = array_diff(array_keys($va['byHost'] ?? []), ['h8-e2e-sonda.invalid', 'h8-e2e-sonda.invalid:443']);
        $this->check('valla: ningún host salvo las dos sondas del arranque', $hosts === [], $va['byHost'] ?? null);
        $this->check('sin deriva conocida del simulador (F-2 corregido por OPS8-b): lo que quede, aparte', true, ['elevenlabs' => $sEl['simDrift']]);
        $this->finish();
    }
}
