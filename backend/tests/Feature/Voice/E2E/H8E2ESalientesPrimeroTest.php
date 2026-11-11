<?php

namespace Tests\Feature\Voice\E2E;

use App\Services\Voice\VoiceCallPolicy;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * E2E del Hito 8, FASE «SALIENTES PRIMERO» (TEST8-c; Enmienda 14, E14-5): la copia del banco con
 * `CALLS_ENABLED_IN_CODE = true` (el mismo giro de UNA línea que la abierta) y la configuración de la fase 4a SIN la 3:
 * `VOICE_INBOUND_ENABLED=false`, salientes encendidas con alcance `canary`. Demuestra contra la pila real (HTTP, SQL,
 * worker real, simuladores; nunca `Http::fake`) que el recorrido de las salientes no depende de nada de las entrantes,
 * y los bloqueos de seguridad que pidió el lead:
 *  0. la fase que deduce el servidor es la 4a y las entrantes están apagadas;
 *  a. perfil → laboratorio → veredicto de otra identidad (lo mismo que en la abierta);
 *  b. publicar en canario (cuatro ojos; el número se asigna: lo usa ElevenLabs como origen de la saliente);
 *  c. una entrante del canario oye «no» (`inbound_disabled`): la fase 3 está apagada de verdad;
 *  d. TRES salientes al canario por el JOB real (costura de la fila sellada, E4-6) contra los dos simuladores:
 *     marcar → contestar → herramienta → post-llamada firmada → `completed` + `seal_verified`, y su precio; la 3 se
 *     queda VIVA y sin post-llamada;
 *  e. la evidencia del canario (E13-3, B8-g: siete claves) cuenta solo las salientes con su post-llamada procesada:
 *     `calls` 2 y `missingPostCall` 1, y promover se niega por `missing_post_call` (antes y después de la COSTURA DE
 *     TIEMPO: la entrada canaria 25 h atrás); llega la post-llamada tardía de la 3 → `calls` 3, la evidencia pasa y
 *     Beto promueve (Ana no: cuatro ojos; con la huella vieja, `evidence_changed`);
 *  f. la parte 4b con una segunda API en `VOICE_OUTBOUND_SCOPE=contacts` (la parte `rest` ya existe): consentimiento
 *     sin confirmar → `no_voice_consent`; cuatro ojos al confirmarlo; RNE; exclusión; y el JOB cancela sin petición la
 *     saliente de un contacto cuyo consentimiento se revocó después de encolarla (TOCTOU);
 *  g. en una saliente viva: el freno cierra sus herramientas, `no_volver_a_llamar` excluye el número SELLADO (la puerta
 *     y el job lo respetan) y el tope de 20 herramientas por llamada y el cupo de 60 por minuto y llamada;
 *  g2. la identidad del número que llama (B8-g): una conciliación que ve otro número que `TWILIO_VOICE_FROM` → la
 *     puerta falla cerrado con `number_mismatch` y el freno; restaurada y suelto, desaparece (`number_unverified` sin
 *     ninguna conciliación, en el paso 0);
 *  h. las cuatro rutas públicas: credencial mala (401 sin escribir nada), el cubo de fallos por ruta e IP (429 y una
 *     credencial buena sigue pasando), los cupos por llamada (iniciación, post-llamada, aviso) y el techo global de
 *     la ruta del aviso de Twilio;
 *  z. cero red fuera de 127.0.0.1 y 0 violaciones del contrato en los simuladores.
 *
 * COSTURAS (además de las del arnés): la fila `requested` sellada (`ayudante.php saliente`, la puerta no deja pasar
 * ninguna hasta E4-6), el reloj del job (`reloj.php`, un martes a las 10:00), la antigüedad de la entrada canaria (el
 * superusuario del banco retrasa su `created_at`, que la firma no cubre; ver el informe §TEST8-c) y la segunda API en
 * `ports.api2` con el alcance `contacts`. Solo corre con `docs/ultron-studio/ops/h8-e2e.sh salientes`.
 */
final class H8E2ESalientesPrimeroTest extends H8StackE2ETestCase
{
    protected const PHASE = 'salientes';

    private const MARTES_EN_HORARIO = '2026-11-10T10:00:00-05:00';

    private const NO_CANARIO = '+573000000099';

    private const CONTACTO = '+573000000071';

    /** La segunda API de la 4b: el alcance `contacts` y la bandera para dar de alta contactos (auditoría B-T4). */
    private const ENTORNO_4B = ['VOICE_OUTBOUND_SCOPE' => 'contacts', 'VOICE_CONTACTS_ENABLED' => 'true'];

    public function test_0_la_fase_es_la_4a_sin_entrantes_y_los_canarios_de_voz_se_registran(): void
    {
        $this->must('la constante está ABIERTA en esta copia (el giro de una línea del banco)', VoiceCallPolicy::CALLS_ENABLED_IN_CODE === true, VoiceCallPolicy::CALLS_ENABLED_IN_CODE);
        $st = $this->telefonia('GET', 'status', 'a');
        $this->check('GET admin/voice/status: entrantes APAGADAS y salientes encendidas con alcance canary', $st->status() === 200 && $st->json('flags.inbound') === false
            && $st->json('flags.outbound') === true && $st->json('scopes.outbound') === 'canary', self::resumen($st, 'flags', 'scopes'));
        $iv = $this->studio('GET', 'integrations/voice', 'b');
        $this->check('ruta 45: la fase que deduce el servidor es la 4a (salientes a canarios) sin la 3', $iv->status() === 200 && $iv->json('data.phase.id') === '4a', self::resumen($iv, 'data.phase'));
        $this->prepararCanarios();

        $puerta = fn (): array => (array) $this->telefonia('POST', 'calls', 'a', ['clientRequestId' => self::uuid(), 'target' => ['kind' => 'canary', 'uuid' => self::$s['canarios'][0]]])->json('reasons');

        // B8-g (SEC8-c B-1): la IDENTIDAD del número que llama. Sin una conciliación del número de menos de 2 h que cuadre
        // con TWILIO_VOICE_FROM, la puerta falla cerrado (`number_unverified`); si la reciente vio otro, `number_mismatch`.
        $m0 = $puerta();
        $this->check('number_unverified: sin ninguna conciliación del número, la puerta falla cerrado', in_array('number_unverified', $m0, true), $m0);
        $cn = $this->conciliar(true);
        $this->check('voice:reconcile --numbers: el número importado del simulador cuadra con TWILIO_VOICE_FROM', ($cn['json']['number']['matchesFrom'] ?? null) === true
            && ($cn['json']['number']['result'] ?? null) === 'ok', $cn['json']['number'] ?? $cn);
        $m1 = $puerta();
        $this->check('… y la puerta deja de decir number_unverified', ! in_array('number_unverified', $m1, true) && ! in_array('number_mismatch', $m1, true), $m1);
        // `number_mismatch` se provoca en el paso g2: aquí su freno caería en la ventana de la evidencia que la costura de
        // tiempo del paso e retrasa 25 h (contaría como drift_events).

        // E4-6 (B8-g): la puerta solo deja pasar salientes con la última verificación de ElevenLabs `ok` (la marca que
        // deja `--verify-voice`; el arnés la corre al arrancar). Falla cerrado: una verificación fallida la BORRA.
        $this->check('E4-6: tras el --verify-voice del arranque, el proveedor está VERIFICADO (marca vigente) y la puerta no dice elevenlabs_unverified', $st->json('provider') === 'ELEVENLABS_VERIFIED'
            && $st->json('providerVerification.state') === 'valid' && ! in_array('elevenlabs_unverified', $puerta(), true), self::resumen($st, 'provider', 'providerVerification'));
        $mala = $this->artisanCopia(['ultron-studio:integrations-snapshot', '--verify-voice', '--json'], ['ELEVENLABS_API_KEY' => 'xi_'.bin2hex(random_bytes(24))]);
        $voz = $mala['json']['voice'] ?? null;
        $this->check('E4-6: una verificación con la clave MALA falla (401 del simulador) y borra la marca', $mala['code'] !== 0 && is_array($voz) && $voz['verified'] === false
            && ($voz['httpStatus'] ?? null) === 401 && ($voz['telephonyMark'] ?? null) === 'cleared', $voz ?? mb_substr($mala['err'], 0, 300));
        $st2 = $this->telefonia('GET', 'status', 'a');
        $m2 = $puerta();
        $this->check('… y la telefonía vuelve a ELEVENLABS_UNVERIFIED: la puerta dice elevenlabs_unverified (falla cerrado)', $st2->json('provider') === 'ELEVENLABS_UNVERIFIED'
            && $st2->json('providerVerification.state') === 'missing' && in_array('elevenlabs_unverified', $m2, true), ['provider' => $st2->json('provider'), 'reasons' => $m2]);
        $buena = $this->artisanCopia(['ultron-studio:integrations-snapshot', '--verify-voice', '--json']);
        $st3 = $this->telefonia('GET', 'status', 'a');
        $this->check('E4-6: la verificación buena vuelve a escribir la marca (VERIFIED) y la puerta deja de decirlo', $buena['code'] === 0 && ($buena['json']['voice']['telephonyMark'] ?? null) === 'written'
            && $st3->json('provider') === 'ELEVENLABS_VERIFIED' && ! in_array('elevenlabs_unverified', $puerta(), true), $buena['json']['voice'] ?? mb_substr($buena['err'], 0, 300));
        $this->check('ninguna de esas peticiones a la puerta escribió una fila (aún no hay publicación)', (int) $this->valor("SELECT count(*) FROM voice_calls WHERE direction = 'outbound'") === 0, null);
        $this->finish();
    }

    public function test_a_perfil_laboratorio_y_veredicto(): void
    {
        $this->requiere('canarios');
        self::$s['a'] = $this->recorridoLaboratorio('Voz del E2E (salientes primero)', self::contenidoPerfil());
        $this->finish();
    }

    public function test_b_publicar_en_canario_a_cuatro_ojos(): void
    {
        $a = $this->requiere('a');
        usleep(1_100_000); // O-1 (E9-6): un suceso anterior del mismo segundo contaría contra la publicación
        $cuerpo = ['clientRequestId' => self::uuid(), 'versionUuid' => $a['version'], 'profileUuid' => $a['profile'], 'expectedSpecHash' => $a['specHash']];
        $ana = $this->studio('POST', 'voice/publish', 'a', $cuerpo);
        $this->check('Ana no publica lo que creó (403 four_eyes_required)', $ana->status() === 403 && $ana->json('code') === 'four_eyes_required', self::resumen($ana));
        $r = $this->studio('POST', 'voice/publish', 'b', ['clientRequestId' => self::uuid()] + $cuerpo);
        $this->must('Beto publica en canario (201, número asignado)', $r->status() === 201 && $r->json('data.entries.0.seq') === 1 && $r->json('data.number.outcome') === 'assigned', self::resumen($r, 'data.number'));
        $ag = $this->fila("SELECT id, uuid, provider_agent_id FROM ultron_studio_voice_agents WHERE purpose = 'publication' ORDER BY id DESC LIMIT 1");
        $this->check('ElevenLabs: el número importado apunta al agente publicado (es el origen de las salientes)', ($this->el('state')['numbers'][0]['agent'] ?? null) === $ag['provider_agent_id'], null);
        self::$s['pub1'] = ['agentId' => (int) $ag['id'], 'provider' => (string) $ag['provider_agent_id']];
        $this->finish();
    }

    public function test_c_con_la_fase_3_apagada_una_entrante_del_canario_oye_no(): void
    {
        $this->requiere('pub1');
        $antes = (int) $this->valor('SELECT count(*) FROM voice_calls');
        $e = $this->entrante(self::ctx()['canaries'][0]);
        $this->check('la iniciación responde 200 con caller_authorized = no', ($e['sim']['laravel']['status'] ?? null) === 200 && ($e['sim']['caller_authorized'] ?? null) === 'no', $e['sim']);
        $this->check('SQL: la entrante queda denied con inbound_disabled el primero (E1-2: con su fila)', (int) $this->valor('SELECT count(*) FROM voice_calls') === $antes + 1
            && ($e['row']['status'] ?? null) === 'denied' && ($e['row']['policy_reason'] ?? null) === 'inbound_disabled' && ($e['row']['caller_authorized'] ?? null) === false, $e['row']);
        $t = $this->el('tool', ['conversation_id' => $e['sim']['conversation_id'] ?? '', 'tool' => 'consultar_horarios']);
        $this->check('sus herramientas dicen call_not_active', ($t['laravel']['body']['verdict'] ?? null) === 'call_not_active', $t['laravel'] ?? $t);
        $this->el('call', ['conversation_id' => $e['sim']['conversation_id'] ?? '', 'event' => 'completed', 'duration' => 6]);
        $this->finish();
    }

    public function test_d_tres_salientes_al_canario_por_el_job_real_sin_entrantes(): void
    {
        $a = $this->requiere('a');
        $p = $this->requiere('pub1');
        [$c1, $c2] = $this->requiere('canarios');
        $ana = (string) self::ctx()['accounts']['a']['id'];
        $llamadas = [];
        foreach ([$c1, $c2, $c1] as $i => $canario) {
            $n = $i + 1;
            $s = $this->ayudante(['saliente', 'canary', $canario, self::uuid(), $ana, '0']);
            $this->must("saliente {$n}: fila requested sellada como la puerta (entrada 1, parte canaria)", ($s['status'] ?? null) === 'requested' && ($s['stampState'] ?? null) === 'applied' && ($s['seq'] ?? null) === 1 && ($s['part'] ?? null) === 'canary', $s);
            $this->ayudante(['despachar', $s['call'], 'voice-e2e']);
            $antes = $this->fotos();
            $w = $this->workerDeUnTrabajo(self::MARTES_EN_HORARIO);
            $f = $this->fila('SELECT status, provider_conversation_id, provider_call_sid, spec_hash, provider_agent_id FROM voice_calls WHERE uuid = ?', [$s['call']]);
            $this->must("saliente {$n}: el job real marcó (dialing, conversación y SID de Twilio)", $w['code'] === 0 && $f !== null && $f['status'] === 'dialing'
                && is_string($f['provider_conversation_id']) && preg_match('/^CA[0-9a-f]{32}$/', (string) $f['provider_call_sid']) === 1, ['fila' => $f, 'worker' => mb_substr($w['err'], 0, 200)]);
            $dEl = self::delta($antes['el'], $this->el('stats'));
            $dTw = self::delta($antes['tw'], $this->tw('stats'));
            $propias = self::separar($dEl['violations'])['own'];
            if (isset($propias['dynamic_variables_personal_data']) && $this->falsoPositivoDeTelefono((string) $f['provider_conversation_id'], 'dynamic_variables_personal_data', self::ctx()['canaries'][$i % 2])) {
                unset($propias['dynamic_variables_personal_data']);
            }
            $this->check("saliente {$n}: UNA outbound-call a ElevenLabs y la llamada la crea ElevenLabs en Twilio con SU credencial; 0 violaciones propias", ($dEl['byRoute']['/v1/convai/twilio/outbound-call'] ?? 0) === 1
                && ($dTw['byRoute']['/2010-04-01/Accounts/{sid}/Calls.json'] ?? 0) === 1 && $propias === [] && $dTw['violations'] === []
                && ($this->tw('state', ['sid' => $f['provider_call_sid']])['origin'] ?? null) === 'elevenlabs_key', ['el' => $dEl['byRoute'], 'tw' => $dTw['byRoute'], 'violaciones' => $propias]);
            $this->check("saliente {$n}: sellada con el agente y la huella publicados", $f['spec_hash'] === $a['specHash'] && $f['provider_agent_id'] === $p['provider'], null);
            $this->el('call', ['conversation_id' => $f['provider_conversation_id'], 'event' => 'answered']);
            $t = $this->el('tool', ['conversation_id' => $f['provider_conversation_id'], 'tool' => 'consultar_planes']);
            $this->check("saliente {$n}: contestada, la primera herramienta responde y la pasa a active", ($t['laravel']['body']['verdict'] ?? null) === 'ok'
                && $this->valor('SELECT status FROM voice_calls WHERE uuid = ?', [$s['call']]) === 'active', $t['laravel'] ?? $t);
            if ($n < 3) {
                $cierre = $this->cerrarConversacion((string) $f['provider_conversation_id'], 40 + $n);
                $g = $this->fila('SELECT status, seal_verified, duration_seconds FROM voice_calls WHERE uuid = ?', [$s['call']]);
                $this->check("saliente {$n}: post-llamada firmada → completed, seal_verified, ".(40 + $n).' s', ($cierre['post']['deliveries'][0]['status'] ?? null) === 200
                    && $g['status'] === 'completed' && $g['seal_verified'] === true && (int) $g['duration_seconds'] === 40 + $n, $g);
            }
            // La 3 se queda VIVA, sellada y SIN post-llamada (E13-3): su post-llamada llega tarde, en el paso e.
            $this->check("saliente {$n}: ninguna iniciación de entrante en su recorrido (las salientes no pasan por el webhook de iniciación)",
                (int) $this->valor("SELECT count(*) FROM voice_call_events WHERE kind = 'initiation_received' AND call_id = (SELECT id FROM voice_calls WHERE uuid = ?)", [$s['call']]) === 0, null);
            $llamadas[] = ['uuid' => (string) $s['call'], 'sid' => (string) $f['provider_call_sid'], 'conv' => (string) $f['provider_conversation_id']];
            self::$s['salientesMarcadas'] = (self::$s['salientesMarcadas'] ?? 0) + 1;
        }
        $this->ayudante(['olvidar-deriva', $p['provider']]);
        $rc = $this->conciliar();
        $this->check('voice:reconcile: sale con 0; las conocidas sin desconocidas ni deriva', $rc['code'] === 0 && ($rc['json']['unknown'] ?? null) === 0 && ($rc['json']['drift']['canary'] ?? null) === 'ok', $rc['json'] ?? $rc);
        $this->check('la saliente 3 sigue viva (active) y sin post-llamada tras la conciliación', $this->valor('SELECT status FROM voice_calls WHERE uuid = ?', [$llamadas[2]['uuid']]) === 'active'
            && (int) $this->valor("SELECT count(*) FROM voice_call_events WHERE kind = 'guard_verdict' AND call_id = (SELECT id FROM voice_calls WHERE uuid = ?)", [$llamadas[2]['uuid']]) === 0, null);
        foreach (array_slice($llamadas, 0, 2) as $k => $l) {
            $precio = self::micros($this->tw('state', ['sid' => $l['sid']])['price'] ?? null);
            $this->check('coste de la saliente '.($k + 1).': el precio de Twilio en carrier_cost_micros', $precio !== null && $precio > 0
                && (int) $this->valor('SELECT carrier_cost_micros FROM voice_calls WHERE uuid = ?', [$l['uuid']]) === $precio, ['twilio' => $precio]);
        }
        $this->check('SQL: ninguna entrante autorizada en toda la fase (la evidencia sale solo de salientes)', (int) $this->valor("SELECT count(*) FROM voice_calls WHERE direction = 'inbound' AND caller_authorized") === 0, null);
        self::$s['d'] = $llamadas;
        $this->finish();
    }

    public function test_e_la_evidencia_cuenta_las_salientes_y_se_promueve_sin_entrantes(): void
    {
        $a = $this->requiere('a');
        $d = $this->requiere('d');
        $medidas = static fn (int $calls, int $faltan): array => ['calls' => $calls, 'guardViolations' => 0, 'sealMismatches' => 0, 'driftEvents' => 0,
            'unknownProviderCalls' => 0, 'budgetBreaches' => 0, 'missingPostCall' => $faltan];

        // 1. Con la saliente 3 sellada y SIN post-llamada (E13-3, B8-g): no es evidencia y bloquea promover.
        $ev = $this->studio('GET', 'voice/canary-evidence', 'b');
        $this->must('GET voice/canary-evidence: 200', $ev->status() === 200, self::resumen($ev));
        $this->check('recuentos (E13-3): calls 2 (solo las salientes con su post-llamada procesada) y missingPostCall 1 (la viva), 0 incidentes; la entrante denegada no cuenta',
            $ev->json('data.measures') === $medidas(2, 1), $ev->json('data.measures'));
        $this->check('fallan too_soon, too_few_calls y missing_post_call; cadena verificada, freno suelto, veredicto approved', $ev->json('data.failing') === ['too_soon', 'too_few_calls', 'missing_post_call']
            && $ev->json('data.chainVerified') === true && $ev->json('data.brake') === 'released' && $ev->json('data.labVerdict.latest') === 'approved', self::resumen($ev, 'data.failing', 'data.hours'));
        $p1 = $this->studio('POST', 'voice/promote', 'b', ['clientRequestId' => self::uuid(), 'evidenceHash' => (string) $ev->json('data.evidenceHash'), 'expectedSpecHash' => $a['specHash']]);
        $this->check('promover ahora → 409 canary_evidence_insufficient (con missing_post_call), sin escribir', $p1->status() === 409 && $p1->json('code') === 'canary_evidence_insufficient'
            && in_array('missing_post_call', (array) $p1->json('evidence.failing'), true) && (int) $this->valor('SELECT count(*) FROM ultron_studio_voice_publications') === 1, self::resumen($p1, 'evidence.failing'));

        // COSTURA DE TIEMPO: la entrada canaria «se publicó» hace 25 h. Solo su `created_at` (la base del `since` de la
        // evidencia, que la firma no cubre); el superusuario del banco apaga los disparadores de solo inserción en ESTA
        // transacción. La cadena tiene que seguir verificando.
        $retrasada = $this->comoSuperusuario("UPDATE ultron_studio_voice_publications SET created_at = created_at - interval '25 hours' WHERE seq = 1");
        $this->must('costura de tiempo: la entrada 1, 25 h atrás', $retrasada === 1, $retrasada);
        $ev1 = $this->studio('GET', 'voice/canary-evidence', 'b');
        $this->check('pasadas las 24 h, siguen fallando too_few_calls y missing_post_call (cadena verificada)', $ev1->json('data.failing') === ['too_few_calls', 'missing_post_call']
            && $ev1->json('data.hours') >= 24 && $ev1->json('data.chainVerified') === true, self::resumen($ev1, 'data.failing', 'data.hours'));
        $p2 = $this->studio('POST', 'voice/promote', 'b', ['clientRequestId' => self::uuid(), 'evidenceHash' => (string) $ev1->json('data.evidenceHash'), 'expectedSpecHash' => $a['specHash']]);
        $this->check('promover con las 24 h cumplidas → 409 por missing_post_call (y too_few_calls), sin escribir', $p2->status() === 409 && $p2->json('code') === 'canary_evidence_insufficient'
            && (array) $p2->json('evidence.failing') === ['too_few_calls', 'missing_post_call'] && (int) $this->valor('SELECT count(*) FROM ultron_studio_voice_publications') === 1, self::resumen($p2, 'evidence.failing'));

        // 2. Llega la post-llamada tardía de la saliente 3: cuenta y ya no falta ninguna.
        $tarde = $this->cerrarConversacion($d[2]['conv'], 43);
        $g3 = $this->fila('SELECT status, seal_verified, duration_seconds FROM voice_calls WHERE uuid = ?', [$d[2]['uuid']]);
        $this->check('saliente 3: la post-llamada tardía → completed, seal_verified, 43 s', ($tarde['post']['deliveries'][0]['status'] ?? null) === 200
            && $g3['status'] === 'completed' && $g3['seal_verified'] === true && (int) $g3['duration_seconds'] === 43, $g3);
        $rc = $this->conciliar();
        $precio = self::micros($this->tw('state', ['sid' => $d[2]['sid']])['price'] ?? null);
        $this->check('coste de la saliente 3: el precio de Twilio en carrier_cost_micros', $rc['code'] === 0 && $precio !== null && $precio > 0
            && (int) $this->valor('SELECT carrier_cost_micros FROM voice_calls WHERE uuid = ?', [$d[2]['uuid']]) === $precio, ['twilio' => $precio]);
        $ev2 = $this->studio('GET', 'voice/canary-evidence', 'b');
        $this->check('recuentos: las 3 salientes con su post-llamada, ninguna falta', $ev2->json('data.measures') === $medidas(3, 0), $ev2->json('data.measures'));
        $this->check('la evidencia (25 h, 3 salientes) PASA: failing [], cadena verificada', $ev2->json('data.passes') === true && $ev2->json('data.failing') === []
            && $ev2->json('data.hours') >= 24 && $ev2->json('data.chainVerified') === true, self::resumen($ev2, 'data.failing', 'data.hours'));
        $hash = (string) $ev2->json('data.evidenceHash');
        $this->check('la huella de la evidencia cambió con ella', $hash !== (string) $ev->json('data.evidenceHash') && preg_match('/^[0-9a-f]{64}$/', $hash) === 1, null);
        $pa = $this->studio('POST', 'voice/promote', 'a', ['clientRequestId' => self::uuid(), 'evidenceHash' => $hash, 'expectedSpecHash' => $a['specHash']]);
        $this->check('Ana no promueve lo que creó (403 four_eyes_required)', $pa->status() === 403 && $pa->json('code') === 'four_eyes_required', self::resumen($pa));
        $pv = $this->studio('POST', 'voice/promote', 'b', ['clientRequestId' => self::uuid(), 'evidenceHash' => (string) $ev->json('data.evidenceHash'), 'expectedSpecHash' => $a['specHash']]);
        $this->check('Beto con la huella de una evidencia vieja → 409 evidence_changed', $pv->status() === 409 && $pv->json('code') === 'evidence_changed', self::resumen($pv));
        $marcaEl = $this->marca('el');
        $marcaTw = $this->marca('tw');
        $pr = $this->studio('POST', 'voice/promote', 'b', ['clientRequestId' => self::uuid(), 'evidenceHash' => $hash, 'expectedSpecHash' => $a['specHash'], 'note' => 'Promoción del E2E: evidencia de salientes al canario.']);
        $this->must('Beto promueve (201): entrada 2, promote, parte rest', $pr->status() === 201 && $pr->json('data.entries.0.seq') === 2 && $pr->json('data.entries.0.action') === 'promote'
            && $pr->json('data.entries.0.scope') === 'rest', self::resumen($pr, 'data.entries'));
        $ag = $this->fila("SELECT serves_canary, serves_rest FROM ultron_studio_voice_agents WHERE purpose = 'publication' ORDER BY id DESC LIMIT 1");
        $this->check('SQL: el agente sirve al canario y al resto', $ag !== null && $ag['serves_canary'] === true && $ag['serves_rest'] === true, $ag);
        $pub = $this->studio('GET', 'voice/publications', 'c');
        $this->check('GET voice/publications: las dos entradas verificadas y la parte rest sirviendo', $pub->json('data.state.verified') === true && $pub->json('data.state.rest.verified') === 'ok'
            && array_unique(array_column((array) $pub->json('data.history.data'), 'verified')) === ['ok'], self::resumen($pub, 'data.state.rest.verified'));
        $rutasEl = $this->rutasDesde('el', $marcaEl);
        $this->check('promover solo LEE el agente en ElevenLabs (el agente y sus herramientas, para verificarlo) y no escribe en ningún proveedor',
            array_diff(array_keys($rutasEl), ['agents_get', 'tools_get']) === [] && ($rutasEl['agents_get'] ?? 0) >= 1 && $this->rutasDesde('tw', $marcaTw) === [], ['elevenlabs' => $rutasEl]);
        $this->finish();
    }

    public function test_f_contactos_4b_consentimiento_sin_confirmar_cuatro_ojos_rne_exclusion_y_revocacion_tras_encolar(): void
    {
        $this->requiere('a');
        $base = $this->iniciarApi2(self::ENTORNO_4B);
        try {
            $st = $this->telefonia2($base, 'GET', 'status', 'a');
            $this->must('segunda API: el alcance de salientes es contacts (parte 4b)', $st->json('scopes.outbound') === 'contacts', self::resumen($st, 'scopes'));
            $cal = self::calendarioLegal(new \DateTimeImmutable('now'));
            $calendario = array_values(array_filter([$cal['outsideHours'] ? 'outside_hours' : null, $cal['holiday'] ? 'holiday' : null]));

            // Alta (Ana) y consentimiento CARGADO por Ana, sin confirmar.
            $k = $this->telefonia2($base, 'POST', 'contacts', 'a', ['clientRequestId' => self::uuid(), 'number' => self::CONTACTO, 'source' => ['type' => 'manual']]);
            $this->must('Ana da de alta un contacto (201)', in_array($k->status(), [200, 201], true) && is_string($k->json('contact.uuid')), self::resumen($k));
            $contacto = (string) $k->json('contact.uuid');
            $this->check('la respuesta del alta no lleva el número: solo los 4 últimos', ! str_contains($k->body(), substr(self::CONTACTO, 3)), null);
            $c1 = $this->telefonia2($base, 'POST', "contacts/{$contacto}/consents", 'a', ['source' => 'written_form', 'evidence' => 'Formulario de voz firmado en recepción (E2E).']);
            $this->must('Ana carga el consentimiento (202, pendiente)', $c1->status() === 202 && $c1->json('pending') === true, self::resumen($c1));
            $g1 = $this->telefonia2($base, 'POST', 'calls', 'a', ['clientRequestId' => self::uuid(), 'target' => ['kind' => 'contact', 'uuid' => $contacto]]);
            $esperados = ['no_voice_consent', 'rne_check_missing', ...$calendario];
            $this->check('la puerta (alcance contacts): consentimiento SIN CONFIRMAR → 409 con no_voice_consent (y el RNE que falta)', $g1->status() === 409
                && (array) $g1->json('reasons') === $esperados, ['reasons' => $g1->json('reasons'), 'esperados' => $esperados]);
            $this->check('… sin fila ni petición', (int) $this->valor("SELECT count(*) FROM voice_calls WHERE direction = 'outbound' AND voice_contact_id IS NOT NULL") === 0, null);
            $propia = $this->telefonia2($base, 'POST', "contacts/{$contacto}/consents/confirm", 'a', ['clientRequestId' => self::uuid()]);
            $this->check('Ana no confirma lo que ella cargó (403 four_eyes_required)', $propia->status() === 403 && $propia->json('code') === 'four_eyes_required', self::resumen($propia));
            $conf = $this->telefonia2($base, 'POST', "contacts/{$contacto}/consents/confirm", 'b', ['clientRequestId' => self::uuid()]);
            $this->check('Beto lo confirma', in_array($conf->status(), [200, 201], true), self::resumen($conf));
            $cs = $this->fila("SELECT c.recorded_by_admin_id AS cargo, c.confirmed_by_admin_id AS confirmo FROM voice_consents c JOIN voice_contacts k ON k.id = c.contact_id WHERE k.uuid = ? AND c.kind = 'granted' ORDER BY c.id DESC LIMIT 1", [$contacto]);
            $this->check('SQL: el consentimiento vigente, cargado por Ana y confirmado por Beto', $cs !== null && (int) $cs['cargo'] === (int) self::ctx()['accounts']['a']['id']
                && (int) $cs['confirmo'] === (int) self::ctx()['accounts']['b']['id'], $cs);
            $g2 = $this->telefonia2($base, 'POST', 'calls', 'a', ['clientRequestId' => self::uuid(), 'target' => ['kind' => 'contact', 'uuid' => $contacto]]);
            $this->check('con el consentimiento confirmado y sin RNE → solo rne_check_missing (y el calendario de ahora)', (array) $g2->json('reasons') === ['rne_check_missing', ...$calendario], $g2->json('reasons'));
            $rne = $this->telefonia2($base, 'POST', 'rne-checks', 'a', ['clientRequestId' => self::uuid(), 'contactUuid' => $contacto, 'checkedAt' => gmdate('c'), 'result' => 'not_listed', 'evidenceRef' => 'rne-e2e-0001']);
            $this->check('la consulta al RNE (no inscrito) se registra', in_array($rne->status(), [200, 201], true), self::resumen($rne));
            // Con consentimiento y RNE ya no queda ningún motivo del destino. Para comprobarlo SIN crear una llamada (la
            // puerta encola en `voice`, y el worker residente del banco tiene el alcance canario), con el freno echado:
            // la puerta tiene que decir EXACTAMENTE voice_braked (y el calendario de ahora).
            $this->tw('seed-call', ['direction' => 'outbound-api', 'from' => self::ctx()['voiceNumber'], 'to' => '+573000000077', 'status' => 'completed', 'duration' => 15]);
            $this->conciliar();
            $this->must('el freno echado (una llamada solo en Twilio)', $this->studio('GET', 'voice/brakes', 'a')->json('data.state') === 'engaged', null);
            $g3 = $this->telefonia2($base, 'POST', 'calls', 'a', ['clientRequestId' => self::uuid(), 'target' => ['kind' => 'contact', 'uuid' => $contacto]]);
            $this->check('con consentimiento y RNE, ningún motivo del destino: solo voice_braked (y el calendario de ahora)', $g3->status() === 409 && (array) $g3->json('reasons') === ['voice_braked', ...$calendario], $g3->json('reasons'));
            $this->soltarFrenos('a', 'Revisada la llamada que solo estaba en Twilio (E2E, contactos 4b).');

            // TOCTOU: la fila sellada con la parte `rest` (como la puerta), el consentimiento revocado DESPUÉS de encolar.
            $s = $this->ayudante(['saliente', 'contact', $contacto, self::uuid(), (string) self::ctx()['accounts']['a']['id'], '0'], self::ENTORNO_4B);
            $this->must('costura 4b: saliente requested al contacto, sellada con la parte rest (entrada 2)', ($s['status'] ?? null) === 'requested' && ($s['part'] ?? null) === 'rest' && ($s['seq'] ?? null) === 2, $s);
            $rv = $this->telefonia2($base, 'POST', "contacts/{$contacto}/revoke", 'b', ['clientRequestId' => self::uuid(), 'source' => 'written_form', 'evidence' => 'Retiró el consentimiento por escrito (E2E).']);
            $this->check('el consentimiento se revoca después de encolar', in_array($rv->status(), [200, 201], true), self::resumen($rv));
            $this->ayudante(['despachar', $s['call'], 'voice-e2e']);
            $antes = $this->fotos();
            $w = $this->workerDeUnTrabajo(self::MARTES_EN_HORARIO, self::ENTORNO_4B);
            $fx = $this->fila('SELECT status, policy_reason FROM voice_calls WHERE uuid = ?', [$s['call']]);
            $dx = self::delta($antes['el'], $this->el('stats'));
            $this->check('el JOB (alcance contacts, martes 10:00) la cancela con no_voice_consent, sin petición a ElevenLabs', $w['code'] === 0 && $fx !== null && $fx['status'] === 'canceled'
                && $fx['policy_reason'] === 'no_voice_consent' && ! isset($dx['byRoute']['/v1/convai/twilio/outbound-call']), ['fila' => $fx, 'elevenlabs' => $dx['byRoute']]);

            // Exclusión del número del contacto: la puerta lo dice.
            $ex = $this->telefonia2($base, 'POST', 'exclusions', 'b', ['clientRequestId' => self::uuid(), 'contactUuid' => $contacto, 'note' => 'Pidió no recibir llamadas (E2E).']);
            $this->check('exclusión del contacto registrada', in_array($ex->status(), [200, 201], true), self::resumen($ex));
            $g4 = $this->telefonia2($base, 'POST', 'calls', 'a', ['clientRequestId' => self::uuid(), 'target' => ['kind' => 'contact', 'uuid' => $contacto]]);
            $this->check('la puerta: excluded (y el consentimiento revocado)', in_array('excluded', (array) $g4->json('reasons'), true) && in_array('no_voice_consent', (array) $g4->json('reasons'), true), $g4->json('reasons'));
            $this->check('Carla (sin telephony.contacts_manage) no da de alta contactos (403)', $this->telefonia2($base, 'POST', 'contacts', 'c', ['clientRequestId' => self::uuid(), 'number' => '+573000000072', 'source' => ['type' => 'manual']])->status() === 403, null);
            $this->check('Carla tampoco lista contactos (403: la lista es de telephony.contacts_manage)', $this->telefonia2($base, 'GET', 'contacts', 'c')->status() === 403, null);
            $lista = $this->telefonia2($base, 'GET', 'contacts', 'a');
            $this->check('GET admin/voice/contacts (Ana): el contacto con sus 4 últimos y sin el número completo', $lista->status() === 200 && ! str_contains($lista->body(), substr(self::CONTACTO, 3))
                && str_contains($lista->body(), '0071'), self::resumen($lista));
        } finally {
            $this->pararApi2();
        }
        $this->finish();
    }

    public function test_g_en_una_saliente_viva_el_freno_cierra_las_herramientas_la_exclusion_oral_y_el_tope_de_herramientas(): void
    {
        $p = $this->requiere('pub1');
        [$c1, $c2] = $this->requiere('canarios');
        $ana = (string) self::ctx()['accounts']['a']['id'];
        $viva = $this->salienteViva($c1, 'A');

        // 1. El freno (una llamada que solo está en Twilio → la conciliación lo echa) cierra las herramientas de ESTA saliente.
        $this->tw('seed-call', ['direction' => 'outbound-api', 'from' => self::ctx()['voiceNumber'], 'to' => '+573000000077', 'status' => 'completed', 'duration' => 20]);
        $r = $this->conciliar();
        $this->check('una llamada solo en Twilio → la conciliación echa el freno', ($r['json']['unknown'] ?? 0) >= 1 && $this->studio('GET', 'voice/brakes', 'a')->json('data.state') === 'engaged', $r['json'] ?? $r);
        $t = $this->el('tool', ['conversation_id' => $viva['conv'], 'tool' => 'consultar_horarios']);
        $this->check('con el freno echado, la herramienta de la saliente viva responde voice_braked (mensaje neutro, 200)', ($t['laravel']['status'] ?? null) === 200
            && ($t['laravel']['body']['verdict'] ?? null) === 'voice_braked' && is_string($t['laravel']['body']['message'] ?? null), $t['laravel'] ?? $t);
        $this->soltarFrenos('a', 'Revisada la llamada que solo estaba en Twilio (E2E, salientes).');

        // 2. `no_volver_a_llamar` en la saliente: excluye el número SELLADO de la llamada.
        $nv = $this->el('tool', ['conversation_id' => $viva['conv'], 'tool' => 'no_volver_a_llamar']);
        $this->check('no_volver_a_llamar en la saliente → excluido', ($nv['laravel']['body']['verdict'] ?? null) === 'ok' && ($nv['laravel']['body']['excluido'] ?? null) === true, $nv['laravel'] ?? $nv);
        $exc = $this->fila("SELECT count(*) AS n, min(source) AS source, min(phone_last4) AS last4, min(call_id) AS call_id FROM voice_exclusions WHERE source = 'spoken_opt_out'");
        $this->check('SQL: UNA exclusión oral del número sellado de esa saliente (…0011)', (int) $exc['n'] === 1 && $exc['last4'] === '0011'
            && (int) $exc['call_id'] === (int) $this->valor('SELECT id FROM voice_calls WHERE uuid = ?', [$viva['uuid']]), $exc);
        $this->cerrarConversacion($viva['conv'], 25);
        $gate = $this->telefonia('POST', 'calls', 'a', ['clientRequestId' => self::uuid(), 'target' => ['kind' => 'canary', 'uuid' => $c1]]);
        $this->check('la puerta: el canario 1 queda excluded', $gate->status() === 409 && in_array('excluded', (array) $gate->json('reasons'), true), $gate->json('reasons'));
        $s = $this->ayudante(['saliente', 'canary', $c1, self::uuid(), $ana, '0']);
        $this->ayudante(['despachar', $s['call'], 'voice-e2e']);
        $antes = $this->fotos();
        $w = $this->workerDeUnTrabajo(self::MARTES_EN_HORARIO);
        $fx = $this->fila('SELECT status, policy_reason FROM voice_calls WHERE uuid = ?', [$s['call']]);
        $this->check('el job tampoco marca al número excluido (canceled excluded, sin petición)', $w['code'] === 0 && $fx['status'] === 'canceled' && $fx['policy_reason'] === 'excluded'
            && ! isset(self::delta($antes['el'], $this->el('stats'))['byRoute']['/v1/convai/twilio/outbound-call']), $fx);

        // 3. El tope de 20 herramientas por llamada (la base) y el cupo de 60 por minuto y llamada (el limitador).
        $b = $this->salienteViva($c2, 'B');  // la primera herramienta ya contó (1 de 20 y 1 de 60)
        $veredictos = [];
        $primer429 = null;
        for ($i = 2; $i <= 62 && $primer429 === null; $i++) {
            $x = $this->el('tool', ['conversation_id' => $b['conv'], 'tool' => 'consultar_horarios']);
            $estado = $x['laravel']['status'] ?? null;
            if ($estado === 429) {
                $primer429 = $i;
            } else {
                $veredictos[] = (string) ($x['laravel']['body']['verdict'] ?? $estado);
            }
        }
        $cuenta = array_count_values($veredictos);
        $this->check('las herramientas 1–20 responden ok; de la 21 en adelante, tool_quota_exceeded (la base no deja pasar de 20)', ($cuenta['ok'] ?? 0) === 19
            && ($cuenta['tool_quota_exceeded'] ?? 0) === count($veredictos) - 19 && (int) $this->valor('SELECT tool_calls FROM voice_calls WHERE uuid = ?', [$b['uuid']]) === 20, $cuenta);
        $this->check('la petición 61 de la misma llamada en el mismo minuto → 429 (cupo por llamada de la ruta de herramientas)', $primer429 === 61, ['primer429' => $primer429]);
        $this->cerrarConversacion($b['conv'], 30);
        $this->ayudante(['olvidar-deriva', $p['provider']]);
        $this->finish();
    }

    public function test_g2_la_identidad_del_numero_que_llama_falla_cerrado_con_number_mismatch(): void
    {
        [$c1] = $this->requiere('canarios');
        // El canario 1 ya está excluido (paso g): la puerta no deja pasar nada y ninguna de estas peticiones crea una fila.
        $puerta = fn (): array => (array) $this->telefonia('POST', 'calls', 'a', ['clientRequestId' => self::uuid(), 'target' => ['kind' => 'canary', 'uuid' => $c1]])->json('reasons');
        $filas = (int) $this->valor("SELECT count(*) FROM voice_calls WHERE direction = 'outbound'");
        $otro = $this->observarNumero(null, ['TWILIO_VOICE_FROM' => '+576010000091']);
        $this->check('number_mismatch: una conciliación que ve en ElevenLabs OTRO número que TWILIO_VOICE_FROM (la variable, cambiada solo en ese proceso) lo dice y echa el freno',
            ($otro['result'] ?? null) === 'number_mismatch' && ($otro['matchesFrom'] ?? null) === false && ($otro['brakeOpened'] ?? null) === true, $otro);
        $m2 = $puerta();
        $this->check('… y la puerta falla cerrado con number_mismatch (y voice_braked)', in_array('number_mismatch', $m2, true) && in_array('voice_braked', $m2, true), $m2);
        $bien = $this->observarNumero();
        $this->check('la observación con el TWILIO_VOICE_FROM de verdad vuelve a cuadrar', ($bien['policy'] ?? null) === 'verified', $bien);
        $this->soltarFrenos('a', 'Revisado: el número importado es el declarado (number_mismatch provocado por el E2E).');
        $m3 = $puerta();
        $this->check('… con el freno suelto, la puerta ya no dice number_mismatch, number_unverified ni voice_braked (sigue excluded)', array_intersect(['number_mismatch', 'number_unverified', 'voice_braked'], $m3) === []
            && in_array('excluded', $m3, true), $m3);
        $this->check('ninguna de esas peticiones a la puerta escribió una fila', (int) $this->valor("SELECT count(*) FROM voice_calls WHERE direction = 'outbound'") === $filas, null);
        $this->finish();
    }

    public function test_h_las_cuatro_rutas_publicas_credencial_mala_cubo_de_fallos_y_cupos(): void
    {
        $this->requiere('d');
        $conv = (string) self::$s['d'][0]['conv'];
        $sello = fn (): array => [
            'calls' => (int) $this->valor('SELECT count(*) FROM voice_calls'), 'events' => (int) $this->valor('SELECT count(*) FROM voice_call_events'),
            'brakes' => (int) $this->valor('SELECT count(*) FROM voice_brakes'), 'exclusions' => (int) $this->valor('SELECT count(*) FROM voice_exclusions'),
        ];

        // 1. Credencial mala en las cuatro (los emisores de los simuladores, como llegarían de fuera).
        $antes = $sello();
        $malas = [];
        foreach (['bad_secret', 'no_secret'] as $v) {
            $malas['iniciación '.$v] = $this->el('inbound', ['from' => self::NO_CANARIO, 'variant' => $v])['laravel']['status'] ?? null;
        }
        foreach (['bad_bearer', 'no_auth', 'basic_scheme'] as $v) {
            $malas['herramienta '.$v] = $this->el('tool', ['conversation_id' => $conv, 'tool' => 'consultar_horarios', 'variant' => $v])['laravel']['status'] ?? null;
        }
        foreach (['mismatch_secret', 'mismatch_body', 'expired', 'from_future', 'missing', 'no_v0'] as $v) {
            $malas['post-llamada '.$v] = $this->el('emit/post-call', ['conversation_id' => $conv, 'variant' => $v])['deliveries'][0]['status'] ?? null;
        }
        foreach (['wrong_token', 'master_token', 'tampered_param', 'extra_param', 'missing_header', 'scheme_flip', 'signed_over_real_url'] as $v) {
            $malas['aviso de Twilio '.$v] = $this->tw('emit/usage-trigger', ['variant' => $v])['deliveries'][0]['status'] ?? null;
        }
        $no401 = array_filter($malas, static fn (mixed $s): bool => $s !== 401);
        $this->check('credencial mala en las 4 rutas públicas: 401 en las '.count($malas).' variantes', $no401 === [], $no401 === [] ? array_keys($malas) : $no401);
        $this->check('… y ninguna escribe: ni llamadas, ni eventos, ni frenos, ni exclusiones', $sello() === $antes, ['antes' => $antes, 'después' => $sello()]);

        // 2. El cubo de FALLOS por ruta e IP (30/min): pasado, 429 en vez de 401; no frena una credencial buena ni otra ruta.
        $bolsa = [];
        $primer429 = null;
        for ($i = 1; $i <= 32 && $primer429 === null; $i++) {
            $r = Http::withHeaders(['Authorization' => 'Bearer '.bin2hex(random_bytes(12))])->acceptJson()->timeout(10)
                ->post(self::ctx()['api'].'/api/voice/tools/consultar_horarios', ['conversation_id' => $conv]);
            $bolsa[] = $r->status();
            if ($r->status() === 429) {
                $primer429 = ['n' => $i, 'retryAfter' => (int) $r->header('Retry-After')];
            }
        }
        $previos = 3; // los tres de la herramienta del punto 1, en el mismo minuto
        $this->check('herramientas con credencial mala: 401 hasta agotar el cubo de 30 fallos por minuto, y entonces 429 con Retry-After', $primer429 !== null && $primer429['n'] === 30 - $previos + 1
            && $primer429['retryAfter'] >= 1 && $primer429['retryAfter'] <= 60 && array_unique(array_slice($bolsa, 0, -1)) === [401], ['primer429' => $primer429, 'estados' => array_count_values($bolsa)]);
        $buena = $this->el('tool', ['conversation_id' => $conv, 'tool' => 'consultar_horarios']);
        $this->check('con el cubo de fallos agotado, la credencial BUENA sigue pasando (200: la llamada ya terminó, call_not_active)', ($buena['laravel']['status'] ?? null) === 200
            && ($buena['laravel']['body']['verdict'] ?? null) === 'call_not_active', $buena['laravel'] ?? $buena);
        $otra = $this->el('inbound', ['from' => self::NO_CANARIO, 'variant' => 'bad_secret']);
        $this->check('… y el cubo es POR RUTA: la iniciación con secreto malo sigue en 401', ($otra['laravel']['status'] ?? null) === 401, $otra['laravel'] ?? $otra);

        // 3. Los cupos POR LLAMADA, ya autenticados.
        //    Iniciación: 10 por minuto y `call_sid` (actuando como ElevenLabs, con su secreto; las entrantes siguen apagadas).
        $pz = $this->piezas();
        $sid = 'CA'.bin2hex(random_bytes(16));
        $cuerpo = ['call_sid' => $sid, 'conversation_id' => 'conv_'.bin2hex(random_bytes(10)), 'caller_id' => self::NO_CANARIO, 'agent_id' => 'agent_e2e_cupo', 'called_number' => self::ctx()['voiceNumber']];
        $estados = [];
        for ($i = 1; $i <= 11; $i++) {
            $estados[] = Http::withHeaders([$pz['EL_INITIATION_HEADER'] => $pz['EL_INITIATION_SECRET']])->acceptJson()->timeout(10)
                ->post(self::ctx()['api'].'/api/webhooks/voice/elevenlabs/initiation', $cuerpo)->status();
        }
        $this->check('iniciación: 10 por minuto con el mismo call_sid (200, la misma respuesta y UNA fila), la 11 → 429', array_slice($estados, 0, 10) === array_fill(0, 10, 200) && $estados[10] === 429
            && (int) $this->valor('SELECT count(*) FROM voice_calls WHERE provider_call_sid = ?', [$sid]) === 1, $estados);
        //    Post-llamada: 10 por minuto y EVENTO (tipo + conversación). Un evento que no ha llegado nunca (el
        //    `post_call_audio` de esa conversación: el envío de audio está apagado y se descarta con 200, D8-16), para
        //    que ninguna entrega anterior del mismo minuto cuente en su cubo.
        $pc = $this->el('emit/post-call', ['conversation_id' => $conv, 'type' => 'post_call_audio', 'repeat' => 11]);
        $entregas = array_column((array) ($pc['deliveries'] ?? []), 'status');
        $this->check('post-llamada: el mismo evento 11 veces en un minuto → 10 × 200 y la 11 → 429', count($entregas) === 11 && array_slice($entregas, 0, 10) === array_fill(0, 10, 200) && $entregas[10] === 429, $entregas);
        $this->check('… el audio se descarta: UN post_call_audio_discarded y la llamada no cambia', (int) $this->valor("SELECT count(*) FROM voice_call_events WHERE kind = 'post_call_audio_discarded'") === 1
            && $this->valor('SELECT status FROM voice_calls WHERE uuid = ?', [self::$s['d'][0]['uuid']]) === 'completed', null);
        $this->check('… y el webhook sigue activo en ElevenLabs (un 429 suelto no lo desactiva)', ($pc['webhook']['desactivado'] ?? null) === false, $pc['webhook'] ?? null);
        //    Aviso de Twilio: 10 por minuto e IdempotencyToken (el mismo cuerpo firmado 11 veces).
        $av = $this->tw('emit/usage-trigger', ['variant' => 'valid', 'repeat' => 11]);
        $ea = array_column((array) ($av['deliveries'] ?? []), 'status');
        $this->check('aviso de Twilio: el mismo IdempotencyToken 11 veces → 10 × 200 y la 11 → 429', count($ea) === 11 && array_slice($ea, 0, 10) === array_fill(0, 10, 200) && $ea[10] === 429, $ea);
        $this->check('… persistido UNA vez (los duplicados no escriben) y su freno por la alerta de la subcuenta', (int) $this->valor("SELECT count(*) FROM voice_call_events WHERE kind = 'cost_recorded' AND outcome = 'usage_alert'") === 1
            && (int) $this->valor("SELECT count(*) FROM voice_brakes WHERE kind = 'open' AND reason = 'subaccount_alert'") === 1, null);

        // 4. El techo GLOBAL de la ruta del aviso (60 autenticadas por minuto entre todos los avisos): avisos distintos.
        $aceptados = 0;
        $techo = null;
        for ($i = 1; $i <= 61 && $techo === null; $i++) {
            $x = $this->tw('emit/usage-trigger', ['variant' => 'valid'])['deliveries'][0]['status'] ?? null;
            if ($x === 429) {
                $techo = $i;
            } elseif ($x === 200) {
                $aceptados++;
            }
        }
        $this->check('aviso de Twilio: con avisos DISTINTOS, el techo global de la ruta (60/min, con los 10 del punto anterior) → 429 en el 51', $techo === 51 && $aceptados === 50, ['techo' => $techo, 'aceptados' => $aceptados]);
        $this->finish();
    }

    public function test_z_cero_red_fuera_de_127_y_0_violaciones_del_contrato(): void
    {
        $el = $this->el('stats');
        $tw = $this->tw('stats');
        $va = $this->valla();
        $sEl = self::separar($el['violationsByCode'] ?? []);
        $this->check('ElevenLabs: 0 violaciones propias del contrato en toda la fase (menos los falsos positivos de F-6 ya verificados)', self::sinFalsosPositivos($sEl['own']) === [],
            ['violaciones' => $el['violationsByCode'] ?? null, 'falsosPositivosF6' => self::$s['falsosPositivos'] ?? []]);
        $this->check('Twilio: 0 violaciones en toda la fase', ($tw['violationsByCode'] ?? []) === [], $tw['violationsByCode'] ?? null);
        $marcadas = (int) (self::$s['salientesMarcadas'] ?? 0);
        $this->check('ElevenLabs: tantas outbound-call como salientes marcó el job (3 de la evidencia y 2 vivas)', $marcadas === 5 && ($el['byRoute']['/v1/convai/twilio/outbound-call'] ?? 0) === $marcadas,
            ['marcadas' => $marcadas, 'byRoute' => $el['byRoute'] ?? null]);
        $hosts = array_diff(array_keys($va['byHost'] ?? []), ['h8-e2e-sonda.invalid', 'h8-e2e-sonda.invalid:443']);
        $this->check('valla: ningún host salvo las dos sondas del arranque', $hosts === [] && ($va['localThroughFence'] ?? 1) === 0, $va);
        $this->anotarFalsosPositivos();
        $esperas = self::$s['esperas'] ?? [];
        $this->check('esperas por los limitadores reales (429 con Retry-After, un reintento cada una): '.count($esperas), true, $esperas);
        $this->finish();
    }

    // ── Ayudantes de esta fase ──────────────────────────────────────────────

    /**
     * Una saliente al canario `$canario`, VIVA: fila sellada, job real (martes 10:00), contestada y con su primera
     * herramienta (dialing → active). @return array{uuid:string, conv:string}
     */
    private function salienteViva(string $canario, string $etiqueta): array
    {
        $s = $this->ayudante(['saliente', 'canary', $canario, self::uuid(), (string) self::ctx()['accounts']['a']['id'], '0']);
        $this->ayudante(['despachar', $s['call'], 'voice-e2e']);
        $w = $this->workerDeUnTrabajo(self::MARTES_EN_HORARIO);
        $f = $this->fila('SELECT status, provider_conversation_id FROM voice_calls WHERE uuid = ?', [$s['call']]);
        $this->must("saliente {$etiqueta}: marcada (dialing)", $w['code'] === 0 && ($f['status'] ?? null) === 'dialing', $f);
        self::$s['salientesMarcadas'] = (self::$s['salientesMarcadas'] ?? 0) + 1;
        $conv = (string) $f['provider_conversation_id'];
        $this->el('call', ['conversation_id' => $conv, 'event' => 'answered']);
        $t = $this->el('tool', ['conversation_id' => $conv, 'tool' => 'consultar_horarios']);
        $this->must("saliente {$etiqueta}: viva (active tras la primera herramienta)", ($t['laravel']['body']['verdict'] ?? null) === 'ok' && $this->valor('SELECT status FROM voice_calls WHERE uuid = ?', [$s['call']]) === 'active', $t['laravel'] ?? $t);

        return ['uuid' => (string) $s['call'], 'conv' => $conv];
    }

    /** La telefonía en la SEGUNDA API (alcance contacts). @param  array<string, mixed>  $cuerpo */
    private function telefonia2(string $base, string $metodo, string $ruta, ?string $quien, array $cuerpo = []): Response
    {
        return $this->api($metodo, 'admin/voice/'.ltrim($ruta, '/'), $quien, $cuerpo, [], $base);
    }
}
