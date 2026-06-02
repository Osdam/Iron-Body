<?php

namespace App\Services;

use Kreait\Firebase\Contract\Auth as FirebaseAuthContract;
use Kreait\Firebase\Factory;
use RuntimeException;

/**
 * Genera Firebase Custom Tokens para que la app móvil pueda autenticarse
 * en Firebase Auth usando la identidad real del `member` Iron Body.
 *
 * Flujo de uso:
 *   1. App Iron Body autentica al member con su propio sistema (access_hash
 *      o session_token de dispositivo) → bearer token.
 *   2. App llama POST /api/app/firebase/custom-token con ese bearer.
 *   3. Laravel valida el bearer (middleware `auth.member`) y este servicio
 *      genera un token firmado con el service-account.json.
 *   4. App hace `FirebaseAuth.signInWithCustomToken(token)`.
 *   5. A partir de ese momento, `request.auth.uid` en Firebase Storage
 *      Rules es igual al `member->id` real — habilita uploads seguros.
 *
 * **Garantías de seguridad:**
 * - El `uid` del custom token siempre proviene del `member` autenticado,
 *   NUNCA del request del cliente. Defense in depth: aunque alguien
 *   manipule el body, el id usado para firmar es el del bearer válido.
 * - El service account JSON nunca sale del backend. Solo se usa para
 *   firmar localmente.
 * - El token resultante es firmado por Google → el cliente no puede
 *   alterar el uid sin invalidarlo.
 */
class FirebaseCustomTokenService
{
    /** Path relativo a `storage/app/` del service account JSON. */
    private const SERVICE_ACCOUNT_RELATIVE_PATH = 'firebase/service-account.json';

    private ?FirebaseAuthContract $auth = null;

    /**
     * Genera un Firebase Custom Token con `uid = (string) $memberId`.
     *
     * @param int $memberId ID interno del miembro autenticado en Iron Body.
     * @return string JWT firmado, listo para `signInWithCustomToken` en cliente.
     *
     * @throws RuntimeException Si el service-account.json no existe o no
     *                          se puede leer.
     * @throws \Throwable        Si kreait/Google falla al firmar.
     */
    public function createCustomToken(int $memberId): string
    {
        $auth = $this->auth();
        $token = $auth->createCustomToken((string) $memberId);

        // En kreait 8.x el retorno implementa `Lcobucci\JWT\UnencryptedToken`
        // y expone `toString()`. Si en una futura versión cambia la interfaz,
        // (string)$token también funciona porque tiene __toString().
        return method_exists($token, 'toString')
            ? $token->toString()
            : (string) $token;
    }

    /**
     * Inicialización lazy del cliente Firebase Auth. La instancia se cachea
     * en memoria por la duración del request (singleton Laravel) — evita
     * releer el service account JSON en cada llamada.
     */
    private function auth(): FirebaseAuthContract
    {
        if ($this->auth !== null) {
            return $this->auth;
        }

        $path = storage_path('app/' . self::SERVICE_ACCOUNT_RELATIVE_PATH);

        if (!is_file($path)) {
            throw new RuntimeException(
                'Firebase service account no encontrado en storage/app/'
                . self::SERVICE_ACCOUNT_RELATIVE_PATH
            );
        }
        if (!is_readable($path)) {
            throw new RuntimeException(
                'Firebase service account no es legible: ' . $path
            );
        }

        $this->auth = (new Factory())
            ->withServiceAccount($path)
            ->createAuth();

        return $this->auth;
    }
}
