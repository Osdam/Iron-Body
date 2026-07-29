<?php

namespace App\Services\Billing;

/**
 * Limpia payloads antes de persistirlos o registrarlos.
 *
 * Se aplica a `request_payload` y `response_payload`, que son estructuras
 * ARBITRARIAS venidas del proveedor: ahí no sirve la lista blanca que usa
 * {@see FiscalReconciliationService}, porque no se conoce de antemano el
 * conjunto de campos legítimos.
 *
 * Dos riesgos distintos, tratados por separado:
 *
 *  - **Credenciales**: el Bearer, el `client_secret` o la contraseña no pueden
 *    quedar escritos en una tabla ni en un log. Se sustituyen por una marca,
 *    no se borran, para que siga siendo evidente que el campo venía.
 *  - **Volumen**: la respuesta trae PDF y XML en base64. Guardarlos infla la
 *    fila sin aportar nada al diagnóstico, así que se recortan dejando
 *    constancia del tamaño.
 */
class PayloadSanitizer
{
    /**
     * Fragmentos de nombre de campo que nunca deben persistirse.
     * Se compara en minúsculas y por inclusión: cubre `access_token`,
     * `client_secret`, `Authorization`, `api_key`, etc.
     */
    private const SECRET_HINTS = [
        'token', 'secret', 'password', 'passwd', 'authorization',
        'api_key', 'apikey', 'client_id', 'credential', 'bearer',
    ];

    /** Campos voluminosos que se recortan en lugar de guardarse enteros. */
    private const BULKY_HINTS = ['base64', 'pdf', 'xml', 'qr_image', 'logo'];

    private const REDACTED = '[redactado]';

    private const MAX_STRING = 512;

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public function sanitize(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            $name = strtolower((string) $key);

            if ($this->matches($name, self::SECRET_HINTS)) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value);

                continue;
            }

            if (is_string($value) && $this->shouldTruncate($name, $value)) {
                $clean[$key] = sprintf('[recortado: %d caracteres]', strlen($value));

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private function shouldTruncate(string $name, string $value): bool
    {
        return $this->matches($name, self::BULKY_HINTS) || strlen($value) > self::MAX_STRING;
    }

    /** @param  array<int,string>  $hints */
    private function matches(string $name, array $hints): bool
    {
        foreach ($hints as $hint) {
            if (str_contains($name, $hint)) {
                return true;
            }
        }

        return false;
    }
}
