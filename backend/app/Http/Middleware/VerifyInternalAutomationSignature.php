<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege los endpoints internos de automatización (los dispara n8n, no el
 * público). Autentica con:
 *  - Authorization: Bearer <AUTOMATION_INTERNAL_SECRET>   (obligatorio)
 *  - X-IronBody-Signature: HMAC-SHA256(raw body, secret)  (OPCIONAL, defensa extra)
 *
 * El Bearer es el secreto compartido que SOLO n8n tiene en su entorno; eso ya
 * autentica el origen. La firma HMAC es una capa adicional: si el cliente la
 * envía, se valida estrictamente; si no (p. ej. el sandbox de n8n bloquea el
 * módulo crypto), el Bearer correcto basta. Nunca filtra detalles.
 *
 * n8n NUNCA accede a PostgreSQL: solo dispara estos endpoints autenticados.
 */
class VerifyInternalAutomationSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('automation.internal_secret');
        if ($secret === '') {
            return response()->json(['success' => false, 'message' => 'Automatización interna no configurada.'], 503);
        }

        // 1) Bearer (obligatorio): prueba de autenticidad del origen interno.
        $bearer = $request->bearerToken();
        if ($bearer === null || !hash_equals($secret, $bearer)) {
            return response()->json(['success' => false, 'message' => 'No autorizado.'], 401);
        }

        // 2) Firma HMAC (opcional): si viene, debe ser válida; si no, se omite.
        $signature = (string) $request->header('X-IronBody-Signature', '');
        if ($signature !== '') {
            $expected = hash_hmac('sha256', $request->getContent(), $secret);
            if (!hash_equals($expected, $signature)) {
                return response()->json(['success' => false, 'message' => 'Firma inválida.'], 403);
            }
        }

        return $next($request);
    }
}
