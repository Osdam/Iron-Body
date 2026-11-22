<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingAutomationEvent;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Services\Marketing\Ultron\UltronReplyOrigin;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `php artisan ultron:origin-report` («regenerar antes de sustituir», ola 6;
 * plan `plan-arquitecto-a4-regenerar.md` §3 P-7, §6 «Ola 6» y §12; condición 5
 * del propietario).
 *
 * Lo que se fija:
 *  - REPRODUCE LA LÍNEA BASE: sobre 88 filas equivalentes a las históricas
 *    (sin `metadata.regeneration`, con el cruce de la consulta: 59 aprobadas
 *    al primer intento, 11 tras la reescritura, 12 rechazadas dos veces y 6
 *    sin revisor), el reparto es 71 del modelo, 14 respaldos del revisor o de
 *    la rendición y 3 de la política. En PostgreSQL se ejecuta además la
 *    consulta C de `origen-respuestas.sql`, tal cual, y da lo mismo.
 *  - Los agregados y el JSON: el porcentaje de la IA frente al 90 % y, aparte,
 *    el del primer intento; las reescrituras por autor y por código; los
 *    respaldos por causa; los recortes; la latencia p50, p95 y máxima del
 *    entrante al saliente; las llamadas al modelo por turno; los tokens y el
 *    costo con los precios dados; los reintentos del job; lo que queda fuera
 *    del reparto.
 *  - Sin datos personales ni texto de mensajes, ni ids de conversación.
 *  - SOLO LECTURA.
 *  - Las opciones (`--conversation`, `--since`, `--until`, los precios) y sus
 *    errores.
 */
class UltronOriginReportCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Lo que nunca puede salir: datos de la persona y textos de los mensajes y de los borradores. */
    private const PRIVADO = ['Ramiro', 'Zuluetag', '3209876543', 'QX-77812', 'cuéntame tu objetivo', 'Te espero', 'texto curado'];

    private int $n = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── La línea base ───────────────────────────────────────────────────────

    public function test_reproduce_el_reparto_de_la_linea_base(): void
    {
        [$a, $b] = $this->lineaBase();

        [$informe, , $escrituras] = $this->informe(['--json' => true, '--conversation' => [$a->id, $b->id]]);

        $this->assertSame([], $escrituras, 'solo lectura');
        $this->assertSame(88, $informe['total']);
        $this->assertSame(0, $informe['traced']);
        $this->assertSame(['count' => 71, 'pct' => 80.7], array_intersect_key($informe['ai'], ['count' => 1, 'pct' => 1]), '71 del modelo');
        $this->assertSame([90, false], [$informe['ai']['target_pct'], $informe['ai']['meets_target']]);
        $this->assertSame(['count' => 56, 'pct' => 63.6], $informe['ai']['first_attempt'], 'al primer intento: las 56 aprobadas a la primera');
        $this->assertSame(['count' => 11, 'pct' => 12.5], $informe['ai']['regenerated'], 'tras la reescritura del revisor');
        $this->assertSame(['count' => 4, 'pct' => 4.5], $informe['ai']['unknown_attempt'], 'sin revisor ni traza: no se sabe');
        $this->assertSame(1, $informe['ai']['trimmed']);
        $this->assertSame(['count' => 17, 'pct' => 19.3], array_intersect_key($informe['fallbacks'], ['count' => 1, 'pct' => 1]));
        $this->assertSame([UltronReplyOrigin::REASON_CRITIC => 12, UltronReplyOrigin::REASON_RECOVERY => 2, UltronReplyOrigin::REASON_POLICY => 3], $informe['fallbacks']['by_reason'], '14 del revisor o la rendición y 3 de la política');
        $this->assertSame(['policy_greeting_sells' => 2, 'policy_active_goal_lost' => 1], $informe['fallbacks']['policy_codes']);
        $this->assertSame(['critic_unavailable' => 2], $informe['fallbacks']['recovery_reasons']);
        $this->assertSame(['count' => 23, 'by' => ['critic' => 23], 'codes' => []], $informe['rewrites'], '11 rescatadas + 12 que no: 23 reescrituras del revisor');
        $this->assertSame(['no_reply/executed' => 3, 'repeated_reply_recovered/executed' => 1], $informe['outside'], 'fuera del reparto, como la consulta (por frecuencia)');

        // La misma clasificación, fila a fila, que la consulta C (sus reglas, copiadas aquí como oráculo).
        foreach (MarketingAiAction::query()->whereIn('conversation_id', [$a->id, $b->id])->where('action_type', 'reply')->where('status', 'executed')->get() as $fila) {
            $c = UltronReplyOrigin::classify($fila->metadata);
            $this->assertSame(self::oraculo($fila->metadata), match (true) {
                $c['origin'] === UltronReplyOrigin::FALLBACK && $c['fallback_reason'] !== UltronReplyOrigin::REASON_POLICY => '3',
                $c['origin'] === UltronReplyOrigin::FALLBACK => '2',
                $c['trimmed'] => '1b',
                default => '1',
            });
        }
    }

    /** En PostgreSQL, la consulta C de la línea base, TAL CUAL (solo cambian los ids), da lo mismo que el informe. */
    public function test_en_postgresql_la_consulta_de_la_linea_base_da_lo_mismo(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('La consulta de la línea base usa operadores de jsonb: solo PostgreSQL.');
        }
        [$a, $b] = $this->lineaBase();
        $sql = (string) file_get_contents(base_path('docs/ultron-studio/ops/comprobacion-produccion/origen-respuestas.sql'));
        $this->assertSame(1, preg_match("/select 'C · origen del texto'.*?order by 2, 3;/s", $sql, $m));
        // Solo cambian los ids y el operador `?` de jsonb, que por PDO se escribe `??` (si no, lo toma por un parámetro;
        // en `psql`, donde se ejecuta la consulta de verdad, va tal cual).
        $this->assertSame(2, substr_count($m[0], "::jsonb ? '"));
        $consulta = str_replace(['in (273, 882)', ';', "::jsonb ? '"], ['in ('.$a->id.', '.$b->id.')', '', "::jsonb ?? '"], $m[0]);

        $reparto = [];
        foreach (DB::select($consulta) as $fila) {
            $fila = array_values((array) $fila);
            $clave = strtok((string) $fila[2], ' ');
            $reparto[$clave] = ($reparto[$clave] ?? 0) + (int) $fila[3];
        }
        ksort($reparto);

        [$informe] = $this->informe(['--json' => true, '--conversation' => [$a->id, $b->id]]);
        $this->assertSame(['1' => 70, '1b' => 1, '2' => 3, '3' => 14], $reparto, 'la consulta');
        $this->assertSame([$reparto['1'] + $reparto['1b'], $reparto['1b'], $reparto['2'], $reparto['3']], [
            $informe['ai']['count'], $informe['ai']['trimmed'], $informe['fallbacks']['by_reason'][UltronReplyOrigin::REASON_POLICY],
            $informe['fallbacks']['count'] - $informe['fallbacks']['by_reason'][UltronReplyOrigin::REASON_POLICY],
        ], 'el informe');
    }

    // ── Los agregados ───────────────────────────────────────────────────────

    public function test_los_agregados_y_el_json(): void
    {
        $c = $this->conversacion('3150537001');
        $pasa = ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9];
        $servidor = ['by' => 'server', 'codes' => ['register_tuteo', 'policy_greeting_sells'], 'issues' => ['naturalness', 'customer_fit']];
        $revisor = ['by' => 'critic', 'codes' => [], 'issues' => ['relevance']];

        // Primer intento (S1): 3 llamadas, 7 s, el job a la primera.
        $this->turno($c, ['critic' => $pasa], latencia: 7, intentos: 1);
        // Con traza, al primer intento: 3 llamadas, 1 comprobación, tokens.
        $this->turno($c, ['critic' => $pasa, 'regeneration' => self::traza(1, 1, 1, [], ['input' => 20000, 'output' => 500])], latencia: 8, intentos: 1);
        // El servidor pidió una: 4 llamadas, 2 comprobaciones; recortada por el tope.
        $this->turno($c, ['critic' => ['attempt' => 2] + $pasa, 'regeneration' => self::traza(2, 1, 2, [$servidor], ['input' => 41000, 'output' => 900]), 'length' => ['action' => 'trimmed', 'from' => 230, 'dropped' => 1]], latencia: 9, intentos: 2);
        // Servidor y revisor: 3 borradores, 2 revisores, 6 llamadas.
        $this->turno($c, ['critic' => ['attempt' => 3] + $pasa, 'regeneration' => self::traza(3, 2, 2, [$servidor, $revisor])], latencia: 30, intentos: 1);
        // El revisor rechazó los dos intentos (S1): respaldo, 5 llamadas.
        $this->turno($c, ['critic' => ['verdict' => 'fail', 'attempt' => 2], 'fallback_mode' => 'SAFE_CURATED_REPLY', 'discarded_draft' => 'Hola Ramiro, cuéntame tu objetivo'], latencia: 10, intentos: 3);
        // La rendición: respaldo, llamadas sin dato; sin saliente (la latencia no se sabe).
        $this->turno($c, ['fallback_mode' => 'SAFE_CURATED_REPLY', 'recovery' => ['reason' => 'critic_unavailable', 'attempt' => 2]], latencia: null, intentos: null);
        // La hora de la visita: texto del servidor que la consulta cuenta como del modelo.
        $this->turno($c, ['visit_hour' => ['mode' => 'ask', 'date' => '2026-11-21'], 'discarded_draft' => 'Te espero mañana'], latencia: 6, intentos: 1);
        // Fuera del reparto.
        $this->turno($c, ['critic' => $pasa], tipo: 'repeated_reply_recovered');
        $this->turno($c, ['fallback_mode' => 'NO_REPLY_AND_HANDOFF', 'critic' => ['verdict' => 'fail', 'attempt' => 2]], estado: 'skipped');

        [$informe, , $escrituras] = $this->informe(['--json' => true, '--precio-entrada' => '2.5', '--precio-salida' => '10']);

        $this->assertSame([], $escrituras);
        $this->assertSame(['window', 'total', 'traced', 'ai', 'rewrites', 'fallbacks', 'latency_s', 'model_calls', 'server_checks', 'tokens', 'job', 'outside'], array_keys($informe), 'la forma');
        $this->assertSame(['since' => null, 'until' => null, 'conversations' => null], $informe['window']);
        $this->assertSame([7, 3], [$informe['total'], $informe['traced']]);
        $this->assertSame([
            'count' => 5, 'pct' => 71.4, 'written_count' => 4, 'written_pct' => 57.1, 'target_pct' => 90, 'meets_target' => false,
            'first_attempt' => ['count' => 2, 'pct' => 28.6],
            'regenerated' => ['count' => 2, 'pct' => 28.6],
            'unknown_attempt' => ['count' => 1, 'pct' => 14.3],
            'trimmed' => 1,
            'visit_hour' => 1, 'pct_without_visit_hour' => 57.1,
        ], $informe['ai']);
        $this->assertSame(['count' => 4, 'by' => ['server' => 2, 'critic' => 2], 'codes' => ['register_tuteo' => 2, 'policy_greeting_sells' => 2]], $informe['rewrites'], 'dos del servidor, una del revisor con traza y una del revisor de S1');
        $this->assertSame([
            'count' => 2, 'pct' => 28.6,
            'by_reason' => [UltronReplyOrigin::REASON_CRITIC => 1, UltronReplyOrigin::REASON_RECOVERY => 1],
            'policy_codes' => [], 'recovery_reasons' => ['critic_unavailable' => 1],
        ], $informe['fallbacks']);
        // Latencias 6, 7, 8, 9, 10, 30 (la rendición, sin saliente): rango más cercano.
        $this->assertSame(['samples' => 6, 'p50' => 8, 'p95' => 30, 'max' => 30], $informe['latency_s']);
        // 3, 3, 4, 6, 5 (la rendición y la hora de la visita, sin dato).
        $this->assertSame(['samples' => 5, 'mean' => 4.2, 'max' => 6], $informe['model_calls']);
        $this->assertSame(['samples' => 3, 'mean' => 1.7, 'max' => 2], $informe['server_checks']);
        $this->assertSame(['samples' => 2, 'input' => 61000, 'output' => 1400, 'cost' => 0.1665], $informe['tokens'], '0,061 × 2,5 + 0,0014 × 10');
        $this->assertSame(['events' => 6, 'retried' => 2, 'max_attempts' => 3], $informe['job'], 'por turno del reparto con su evento');
        $this->assertSame(['repeated_reply_recovered/executed' => 1, 'reply/skipped' => 1], $informe['outside']);
    }

    public function test_sin_precios_no_hay_costo_y_sin_turnos_no_hay_porcentajes(): void
    {
        [$vacio] = $this->informe(['--json' => true]);
        $this->assertSame(0, $vacio['total']);
        $this->assertNull($vacio['ai']['pct']);
        $this->assertSame(['samples' => 0, 'p50' => null, 'p95' => null, 'max' => null], $vacio['latency_s']);
        $this->assertSame(['samples' => 0, 'input' => null, 'output' => null, 'cost' => null], $vacio['tokens']);

        $c = $this->conversacion('3150537002');
        $this->turno($c, ['critic' => ['verdict' => 'pass', 'attempt' => 1], 'regeneration' => self::traza(1, 1, 1, [], ['input' => 1000, 'output' => 10])]);
        [$informe] = $this->informe(['--json' => true]);
        $this->assertSame(['samples' => 1, 'input' => 1000, 'output' => 10, 'cost' => null], $informe['tokens'], 'sin los dos precios, sin costo');
        $this->assertSame([100.0, true], [$informe['ai']['pct'], $informe['ai']['meets_target']]);
    }

    /**
     * EL OBJETIVO DEL 90 % SE MIDE CON LO QUE DE VERDAD ESCRIBIÓ EL MODELO (lead, 21-11).
     * La hora de la visita la escribe el servidor desde la fila del CRM: la consulta de
     * la línea base la contaba como del modelo y eso inflaba la cifra. Ocho del modelo
     * y una hora de la visita: con la regla de la línea base, 100 %; de verdad, 88,9 %.
     */
    public function test_la_hora_de_la_visita_no_cuenta_para_el_objetivo(): void
    {
        $c = $this->conversacion('3150537003');
        for ($i = 0; $i < 8; $i++) {
            $this->turno($c, ['critic' => ['verdict' => 'pass', 'attempt' => 1]]);
        }
        $this->turno($c, ['visit_hour' => ['mode' => 'ask', 'date' => '2026-11-21']]);

        [$informe, $salida] = $this->informe(['--json' => true]);

        $this->assertSame([9, 100.0], [$informe['ai']['count'], $informe['ai']['pct']], 'la regla de la línea base');
        $this->assertSame([8, 88.9], [$informe['ai']['written_count'], $informe['ai']['written_pct']], 'lo que escribió el modelo');
        $this->assertFalse($informe['ai']['meets_target'], 'el objetivo se juzga con lo que escribió el modelo');
        $this->assertSame(1, $informe['ai']['visit_hour']);
    }

    public function test_la_salida_de_texto(): void
    {
        [$a, $b] = $this->lineaBase();

        [, $salida] = $this->informe(['--conversation' => [$a->id, $b->id]]);

        foreach ([
            'ULTRON · ¿quién escribió cada respuesta? (solo lectura)',
            'Respuestas enviadas: 88 (con traza de regeneración: 0)',
            'Escrito por la IA: 67 de 88 (76,1 %) · objetivo 90 %: NO',
            'La hora de la visita (la escribe el servidor desde el CRM; no cuenta como IA): 4',
            'con la regla de la línea base, que la contaba como del modelo: 71 (80,7 %)',
            'al primer intento: 56 (63,6 %) · regeneradas: 11 (12,5 %) · intento sin dato: 4 (4,5 %)',
            'Respaldos: 17 de 88 (19,3 %)',
            'el revisor rechazó todos los intentos: 12',
            'la rendición: 2 (critic_unavailable: 2)',
            'la política del commit: 3 (policy_greeting_sells: 2, policy_active_goal_lost: 1)',
            'Recortadas (sin su pregunta final): 1',
            'Reescrituras: 23 (revisor: 23)',
            'Tokens: sin dato',
            'Fuera del reparto: no_reply/executed: 3, repeated_reply_recovered/executed: 1',
            'el 90 % se juzga con al menos 30 turnos con traza; aquí hay 0',
        ] as $linea) {
            $this->assertStringContainsString($linea, $salida);
        }
    }

    // ── Privacidad y solo lectura ───────────────────────────────────────────

    public function test_sin_datos_personales_ni_textos_ni_ids(): void
    {
        $c = $this->conversacion('3209876543', 'Ramiro Zuluetagüe');
        $this->turno($c, [
            'critic' => ['verdict' => 'fail', 'attempt' => 2, 'notes' => 'Ramiro pidió que le escriban al 3209876543'],
            'fallback_mode' => 'SAFE_CURATED_REPLY', 'discarded_draft' => 'Ramiro, cuéntame tu objetivo',
        ], latencia: 9, intentos: 1);
        $this->turno($c, [
            'critic' => ['verdict' => 'pass', 'attempt' => 1],
            'policy_violations' => ['policy_greeting_sells', 'QX-77812 Ramiro'], 'policy_discarded_draft' => 'Te espero, Ramiro',
            'policy' => ['active_goal' => ['data' => ['name' => 'Ramiro Zuluetagüe']]],
        ], latencia: 8, intentos: 1);
        $this->turno($c, ['fallback_mode' => 'SAFE_CURATED_REPLY', 'recovery' => ['reason' => 'Ramiro 3209876543', 'attempt' => 2]], latencia: 5, intentos: 1);
        $this->turno($c, ['critic' => ['verdict' => 'pass', 'attempt' => 2], 'regeneration' => self::traza(2, 1, 2, [['by' => 'server', 'codes' => ['register_tuteo', 'Ramiro'], 'issues' => ['naturalness']]])], latencia: 7, intentos: 1);

        foreach ([['--json' => true], [], ['--json' => true, '--conversation' => [$c->id]], ['--conversation' => [$c->id]]] as $opciones) {
            [, $salida] = $this->informe($opciones);
            foreach (self::PRIVADO as $dato) {
                $this->assertStringNotContainsString($dato, $salida, json_encode($opciones).': '.$dato);
            }
            $this->assertStringNotContainsString('conversation_id', $salida, 'ni ids de conversación');
        }
        [$informe] = $this->informe(['--json' => true]);
        $this->assertSame(['policy_greeting_sells' => 1], $informe['fallbacks']['policy_codes'], 'solo códigos');
        $this->assertSame([], $informe['fallbacks']['recovery_reasons'], 'una etiqueta que no es un código no sale');
        $this->assertSame(['register_tuteo' => 1], $informe['rewrites']['codes'], 'solo códigos del vocabulario');
    }

    // ── Las opciones ────────────────────────────────────────────────────────

    public function test_las_opciones_filtran_y_validan(): void
    {
        $a = $this->conversacion('3150537003');
        $b = $this->conversacion('3150537004');
        $pasa = ['critic' => ['verdict' => 'pass', 'attempt' => 1]];
        $this->turno($a, $pasa, cuando: '2026-11-10 10:00:00');
        $this->turno($a, $pasa, cuando: '2026-11-15 10:00:00');
        $this->turno($b, $pasa, cuando: '2026-11-15 11:00:00');
        // Ni una fila de Legacy (sin `source_type`) ni las del enrutador cuentan.
        $this->turno($b, $pasa, cuando: '2026-11-15 12:00:00', fuente: null);
        $this->turno($b, [], cuando: '2026-11-15 12:01:00', tipo: 'inbound_skipped', fuente: null);

        $this->assertSame(3, $this->informe(['--json' => true])[0]['total']);
        $this->assertSame(2, $this->informe(['--json' => true, '--conversation' => [$a->id]])[0]['total']);
        $this->assertSame(1, $this->informe(['--json' => true, '--conversation' => [$b->id]])[0]['total']);
        $this->assertSame(2, $this->informe(['--json' => true, '--since' => '2026-11-15T00:00:00Z'])[0]['total']);
        $this->assertSame(1, $this->informe(['--json' => true, '--until' => '2026-11-15T00:00:00Z'])[0]['total']);
        $this->assertSame(1, $this->informe(['--json' => true, '--since' => '2026-11-15', '--until' => '2026-11-15 10:30', '--conversation' => [$a->id, $b->id]])[0]['total']);
        $this->assertSame(['since' => '2026-11-15T00:00:00+00:00', 'until' => null, 'conversations' => 2], $this->informe(['--json' => true, '--since' => '2026-11-15', '--conversation' => [$a->id, $b->id]])[0]['window']);

        foreach ([
            ['--since' => 'ayer por la tarde'],
            ['--until' => 'no'],
            ['--since' => '2026-11-16', '--until' => '2026-11-15'],
            ['--conversation' => ['abc']],
            ['--conversation' => ['0']],
            ['--precio-entrada' => '-1'],
            ['--precio-salida' => 'gratis'],
        ] as $mala) {
            $this->assertSame(1, Artisan::call('ultron:origin-report', $mala), json_encode($mala));
        }
    }

    // ── Andamio ──────────────────────────────────────────────────────────────

    /**
     * Las 88 filas equivalentes a las de la línea base (`07-origen-de-las-respuestas.txt`), en dos conversaciones:
     * el cruce de la consulta F con la C (pass/1 59 = 56 del modelo + 3 de la política; pass/2 11; fail/2 12;
     * sin revisor 6 = 4 del modelo + 2 de la rendición), una recortada, y lo que la consulta deja fuera del reparto.
     *
     * @return array{MarketingConversation, MarketingConversation}
     */
    private function lineaBase(): array
    {
        $a = $this->conversacion('3150537273');
        $b = $this->conversacion('3150537882');
        $pasa = ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.86, 'issues' => [], 'hard_fail' => null, 'notes' => null];
        for ($i = 0; $i < 55; $i++) {
            $this->turno($i < 53 ? $a : $b, ['critic' => $pasa, 'length' => ['action' => 'fit', 'from' => 150, 'dropped' => 0]]);
        }
        $this->turno($b, ['critic' => $pasa, 'length' => ['action' => 'trimmed', 'from' => 214, 'dropped' => 1]]);
        for ($i = 0; $i < 11; $i++) {
            $this->turno($a, ['critic' => ['attempt' => 2] + $pasa]);
        }
        for ($i = 0; $i < 4; $i++) {
            // En producción las cuatro «sin revisor ni traza» son la hora de la visita (comprobado el 21-11, solo lectura).
            $this->turno($a, ['origin' => 'ultron', 'intent' => 'general_info', 'visit_hour' => ['mode' => 'ask', 'date' => '2026-10-20']]);
        }
        foreach (['policy_greeting_sells', 'policy_greeting_sells', 'policy_active_goal_lost'] as $v) {
            $this->turno($a, ['critic' => $pasa, 'policy_violations' => [$v], 'policy_discarded_draft' => 'texto curado']);
        }
        for ($i = 0; $i < 12; $i++) {
            $this->turno($a, ['critic' => ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.4], 'fallback_mode' => 'SAFE_CURATED_REPLY', 'discarded_draft' => 'texto curado']);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->turno($a, ['fallback_mode' => 'SAFE_CURATED_REPLY', 'recovery' => ['reason' => 'critic_unavailable', 'attempt' => 2]]);
        }
        $this->turno($a, ['critic' => $pasa, 'risk_flags' => ['repeated_reply']], tipo: 'repeated_reply_recovered');
        for ($i = 0; $i < 3; $i++) {
            $this->turno($a, ['blocked_reason' => 'superseded'], tipo: 'no_reply');
        }
        // Las del enrutador (sin `source_type`), que la consulta C tampoco cuenta.
        $this->turno($a, [], tipo: 'inbound_skipped', fuente: null);

        return [$a, $b];
    }

    /** Las reglas de la consulta C de `origen-respuestas.sql`, en su orden, como oráculo. */
    private static function oraculo(array $m): string
    {
        return match (true) {
            array_key_exists('fallback_mode', $m) => '3',
            is_array($m['policy_violations'] ?? null) && $m['policy_violations'] !== [] && array_key_exists('policy_discarded_draft', $m) => '2',
            ($m['length']['action'] ?? null) === 'trimmed' => '1b',
            default => '1',
        };
    }

    private static function traza(int $drafts, ?int $critics, ?int $checks, array $rewrites, ?array $tokens = null): array
    {
        return ['drafts' => $drafts, 'critics' => $critics, 'checks' => $checks, 'check_errors' => 0, 'rewrites' => $rewrites, 'tokens' => $tokens, 'consistent' => true];
    }

    /** @return array{array<string,mixed>|null, string, list<string>} el JSON (si lo es), la salida y las escrituras */
    private function informe(array $opciones): array
    {
        $escrituras = [];
        DB::listen(function (QueryExecuted $q) use (&$escrituras): void {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $q->sql) === 1) {
                $escrituras[] = $q->sql;
            }
        });
        $codigo = Artisan::call('ultron:origin-report', $opciones);
        $salida = Artisan::output();
        $this->assertSame(0, $codigo, $salida);

        return [($opciones['--json'] ?? false) ? json_decode($salida, true, 32, JSON_THROW_ON_ERROR) : null, $salida, $escrituras];
    }

    private function conversacion(string $telefono, string $nombre = 'Prospecto'): MarketingConversation
    {
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => $telefono, 'meta_user_id' => '57'.$telefono,
            'name' => $nombre, 'status' => MarketingLead::STATUS_NEW,
        ]);

        return MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    /**
     * Un turno: el entrante (con un texto que no puede salir), la fila del commit y, si hay latencia, el saliente
     * a esos segundos; si hay intentos, el evento del job.
     */
    private function turno(
        MarketingConversation $c,
        array $metadata,
        ?int $latencia = 9,
        ?int $intentos = null,
        string $tipo = 'reply',
        string $estado = 'executed',
        ?string $cuando = null,
        ?string $fuente = 'inbound_message',
    ): MarketingAiAction {
        $n = ++$this->n;
        $llega = Carbon::parse($cuando ?? '2026-11-18 22:00:00', 'UTC')->addMinutes($cuando === null ? $n : 0);
        try {
            Carbon::setTestNow($llega);
            $entrante = MarketingMessage::create([
                'conversation_id' => $c->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD,
                'body' => 'Hola, soy Ramiro Zuluetagüe del conjunto QX-77812, mi celular es 3209876543', 'meta_message_id' => 'wamid.org.'.$n, 'status' => 'received',
            ]);
            $saliente = null;
            if ($latencia !== null) {
                Carbon::setTestNow($llega->copy()->addSeconds($latencia));
                $saliente = MarketingMessage::create([
                    'conversation_id' => $c->id, 'direction' => MarketingMessage::DIRECTION_OUTBOUND, 'sender_type' => MarketingMessage::SENDER_AI,
                    'body' => 'Te espero, Ramiro: cuéntame tu objetivo', 'status' => 'sent',
                ]);
            }
            if ($intentos !== null) {
                MarketingAutomationEvent::create([
                    'event_type' => MarketingAutomationEvent::TYPE_MESSAGE_RECEIVED, 'lead_id' => $c->lead_id, 'conversation_id' => $c->id,
                    'message_id' => $entrante->id, 'payload_json' => ['texto' => 'Ramiro 3209876543'], 'status' => MarketingAutomationEvent::STATUS_SENT,
                    'idempotency_key' => 'ultron:org:'.$n, 'attempts' => $intentos,
                ]);
            }
            $meta = ['message_id' => $entrante->id, 'origin' => 'ultron'] + $metadata;
            if ($saliente !== null) {
                $meta['outbound'] = ['message_id' => $saliente->id, 'sent' => true];
            }

            return MarketingAiAction::create([
                'lead_id' => $c->lead_id, 'conversation_id' => $c->id, 'action_type' => $tipo, 'reason' => 'general_info',
                'confidence' => 0.9, 'status' => $estado, 'source_type' => $fuente, 'source_event_id' => $fuente === null ? null : $entrante->id,
                'idempotency_key' => $fuente === null ? null : 'wamid.org.'.$n, 'metadata' => $meta,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
