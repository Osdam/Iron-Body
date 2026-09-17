<?php

namespace App\Services\Marketing\Ultron;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * El billete que `/ai/decide` entrega y `/ai/commit` comprueba.
 *
 * NO es autenticación: eso ya lo hace el middleware `automation.internal` con
 * el bearer compartido. Esto resuelve otro problema, el del tiempo.
 *
 * Entre que ULTRON pide una decisión y la manda de vuelta pasan segundos —dos
 * llamadas a un modelo, a veces tres—. En ese hueco un asesor puede tomar la
 * conversación, el cliente puede pedir que no le escriban, o puede escribir
 * otro mensaje que cambia lo que había que decir. El token lleva grabado el
 * estado con el que se decidió; si al volver ya no coincide, la propuesta es
 * vieja y se recalcula en lugar de ejecutarse a ciegas.
 *
 * Firmado con la APP_KEY para que su contenido no se pueda reescribir por el
 * camino. Nunca se registra en logs: lleva el estado de la conversación.
 */
final class UltronDecideToken
{
    /** Vida corta a propósito: describe un instante, no una sesión. */
    public const TTL_SECONDS = 120;

    /**
     * @param  string[]  $allowedTransitions
     */
    public function issue(
        int $conversationId,
        int $messageId,
        string $commercialPhase,
        array $allowedTransitions,
        string $knowledgeVersion,
        array $extra = [],
    ): array {
        $issuedAt = now();
        $expiresAt = $issuedAt->copy()->addSeconds(self::TTL_SECONDS);

        $payload = [
            'c' => $conversationId,
            'm' => $messageId,
            'p' => $commercialPhase,
            // Huella de las transiciones, no la lista: el token no tiene por qué
            // crecer con el grafo, y lo único que importa es si cambió.
            't' => $this->fingerprint($allowedTransitions),
            'k' => $knowledgeVersion,
            'i' => $issuedAt->getTimestamp(),
            'e' => $expiresAt->getTimestamp(),
            /*
             * Carga firmada, no verificada: lo que decide le dijo al estratega
             * (las pistas) viaja aquí para que commit lo lea tal cual salió y no
             * lo recalcule con datos que ahora gobierna el modelo. Va bajo la
             * misma firma que el resto, así que no se puede alterar; y no entra
             * en las comprobaciones de «cambió el mundo», que son lo de arriba.
             */
            'x' => $extra === [] ? null : $extra,
        ];

        $body = $this->encode($payload);

        return [
            'token' => $body.'.'.$this->sign($body),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * ¿Sigue siendo válido este token para esta conversación y este estado?
     *
     * @param  string[]  $allowedTransitions  las de AHORA, recalculadas
     * @return array{valid:bool, reason:?string}
     */
    public function verify(
        ?string $token,
        int $conversationId,
        int $messageId,
        string $commercialPhase,
        array $allowedTransitions,
        string $knowledgeVersion,
    ): array {
        if ($token === null || trim($token) === '') {
            return $this->fail('decide_token_missing');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return $this->fail('decide_token_malformed');
        }

        [$body, $signature] = $parts;

        if (! hash_equals($this->sign($body), $signature)) {
            return $this->fail('decide_token_bad_signature');
        }

        $payload = $this->decode($body);
        if ($payload === null) {
            return $this->fail('decide_token_malformed');
        }

        if ((int) ($payload['e'] ?? 0) < now()->getTimestamp()) {
            return $this->fail('decide_token_expired');
        }

        if ((int) ($payload['c'] ?? 0) !== $conversationId) {
            return $this->fail('decide_token_conversation_mismatch');
        }

        if ((int) ($payload['m'] ?? 0) !== $messageId) {
            return $this->fail('decide_token_message_mismatch');
        }

        // A partir de aquí, lo que cambió fue el MUNDO, no el token. Son los
        // casos que justifican que esto exista.
        if ((string) ($payload['p'] ?? '') !== $commercialPhase) {
            return $this->fail('decide_token_phase_changed');
        }

        if ((string) ($payload['t'] ?? '') !== $this->fingerprint($allowedTransitions)) {
            return $this->fail('decide_token_transitions_changed');
        }

        if ((string) ($payload['k'] ?? '') !== $knowledgeVersion) {
            return $this->fail('decide_token_knowledge_changed');
        }

        return ['valid' => true, 'reason' => null, 'extra' => is_array($payload['x'] ?? null) ? $payload['x'] : []];
    }

    /** @param string[] $transitions */
    private function fingerprint(array $transitions): string
    {
        $sorted = array_values(array_unique($transitions));
        sort($sorted);

        return substr(hash('sha256', implode('|', $sorted)), 0, 16);
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, (string) config('app.key'));
    }

    private function encode(array $payload): string
    {
        return rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}'), '+/', '-_'), '=');
    }

    private function decode(string $body): ?array
    {
        try {
            $json = base64_decode(strtr($body, '-_', '+/'), true);
            $decoded = $json === false ? null : json_decode($json, true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{valid:bool, reason:string} */
    private function fail(string $reason): array
    {
        // El motivo sí, el token NO: lleva dentro el estado de la conversación.
        Log::info('ultron.decide_token.rejected', ['reason' => $reason]);

        return ['valid' => false, 'reason' => $reason];
    }
}
