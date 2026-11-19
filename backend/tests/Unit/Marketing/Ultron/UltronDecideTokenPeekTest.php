<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\Ultron\UltronDecideToken;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * «REGENERAR ANTES DE SUSTITUIR» · OLA 1 · `UltronDecideToken::peek()` (plan
 * del arquitecto `plan-arquitecto-a4-regenerar.md` §3 P-1, cambio 1).
 *
 * `peek()` es la mitad de `verify()` que no mira el mundo: la firma, la
 * caducidad y que la conversación y el mensaje sean los del token. Devuelve la
 * carga firmada (`x`) y el instante de emisión (`i`, para el presupuesto de
 * tiempo de la comprobación). NO recalcula la fase, las transiciones ni la
 * versión del conocimiento: si el mundo cambió, lo decide el commit
 * (`stale_decision`), como hoy. `verify()` no cambia.
 *
 * Sin base de datos: solo la APP_KEY y el reloj (en UTC, la trampa conocida
 * de la suite).
 */
class UltronDecideTokenPeekTest extends TestCase
{
    private UltronDecideToken $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new UltronDecideToken;
        Carbon::setTestNow(Carbon::parse('2026-11-19 15:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function emitir(array $extra = ['sp' => ['m' => 'studio', 'n' => 200], 'po' => [3, 4, 8]]): string
    {
        return $this->tokens->issue(882, 123456, P::NEW_LEAD, [P::NEW_LEAD, P::DISCOVERY], 'kv1', $extra)['token'];
    }

    public function test_devuelve_la_carga_firmada_y_el_instante_de_emision(): void
    {
        $emitido = Carbon::now('UTC')->getTimestamp();
        $token = $this->emitir();
        Carbon::setTestNow(Carbon::now('UTC')->addSeconds(9));

        $r = $this->tokens->peek($token, 882, 123456);
        $this->assertSame(['valid' => true, 'reason' => null, 'extra' => ['sp' => ['m' => 'studio', 'n' => 200], 'po' => [3, 4, 8]], 'issued_at' => $emitido], $r);
    }

    public function test_sin_carga_la_carga_es_vacia(): void
    {
        $r = $this->tokens->peek($this->emitir([]), 882, 123456);
        $this->assertTrue($r['valid']);
        $this->assertSame([], $r['extra']);
    }

    public function test_no_mira_la_fase_ni_las_transiciones_ni_el_conocimiento(): void
    {
        $token = $this->emitir();
        // verify() con otra fase, otras transiciones u otro conocimiento: inválido (no cambia).
        $this->assertSame('decide_token_phase_changed', $this->tokens->verify($token, 882, 123456, P::DISCOVERY, [P::NEW_LEAD, P::DISCOVERY], 'kv1')['reason']);
        $this->assertSame('decide_token_transitions_changed', $this->tokens->verify($token, 882, 123456, P::NEW_LEAD, [P::NEW_LEAD], 'kv1')['reason']);
        $this->assertSame('decide_token_knowledge_changed', $this->tokens->verify($token, 882, 123456, P::NEW_LEAD, [P::NEW_LEAD, P::DISCOVERY], 'kv2')['reason']);
        $this->assertTrue($this->tokens->verify($token, 882, 123456, P::NEW_LEAD, [P::DISCOVERY, P::NEW_LEAD], 'kv1')['valid']);
        // peek() ni siquiera las recibe.
        $this->assertTrue($this->tokens->peek($token, 882, 123456)['valid']);
    }

    public function test_falla_cerrado_con_su_motivo_y_sin_carga(): void
    {
        $token = $this->emitir();
        [$cuerpo, $firma] = explode('.', $token);
        $otroCuerpo = rtrim(strtr(base64_encode((string) json_encode(['c' => 882, 'm' => 123456, 'e' => PHP_INT_MAX, 'i' => 1, 'x' => ['sp' => ['m' => 'studio']]])), '+/', '-_'), '=');

        $casos = [
            'otra conversación' => [$token, 883, 123456, 'decide_token_conversation_mismatch'],
            'otro mensaje' => [$token, 882, 123457, 'decide_token_message_mismatch'],
            'sin token' => [null, 882, 123456, 'decide_token_missing'],
            'vacío' => ['  ', 882, 123456, 'decide_token_missing'],
            'sin firma' => [$cuerpo, 882, 123456, 'decide_token_malformed'],
            'tres trozos' => [$token.'.x', 882, 123456, 'decide_token_malformed'],
            'firma alterada' => [$cuerpo.'.'.strrev($firma), 882, 123456, 'decide_token_bad_signature'],
            'carga cambiada con la firma de otra' => [$otroCuerpo.'.'.$firma, 882, 123456, 'decide_token_bad_signature'],
        ];
        foreach ($casos as $nombre => [$t, $c, $m, $motivo]) {
            $this->assertSame(['valid' => false, 'reason' => $motivo, 'extra' => [], 'issued_at' => null], $this->tokens->peek($t, $c, $m), $nombre);
        }
    }

    public function test_caduca_como_verify(): void
    {
        $token = $this->emitir();
        Carbon::setTestNow(Carbon::now('UTC')->addSeconds(UltronDecideToken::TTL_SECONDS));
        $this->assertTrue($this->tokens->peek($token, 882, 123456)['valid'], 'a los 120 s justos, aún vale');

        Carbon::setTestNow(Carbon::now('UTC')->addSecond());
        $this->assertSame('decide_token_expired', $this->tokens->peek($token, 882, 123456)['reason']);
        $this->assertSame('decide_token_expired', $this->tokens->verify($token, 882, 123456, P::NEW_LEAD, [P::NEW_LEAD, P::DISCOVERY], 'kv1')['reason']);
    }

    public function test_un_token_firmado_sin_instante_de_emision_es_malformado(): void
    {
        // Firmado con la APP_KEY (como lo haría el emisor) pero sin `i`: no lo emite nadie hoy; si llega, no se adivina.
        $cuerpo = rtrim(strtr(base64_encode((string) json_encode(['c' => 882, 'm' => 123456, 'e' => Carbon::now('UTC')->getTimestamp() + 60, 'i' => 'ayer', 'x' => null])), '+/', '-_'), '=');
        $firmado = $cuerpo.'.'.hash_hmac('sha256', $cuerpo, (string) config('app.key'));
        $this->assertSame('decide_token_malformed', $this->tokens->peek($firmado, 882, 123456)['reason']);
    }
}
