<?php

namespace App\Services\Wompi;

/**
 * Firma e integridad de Wompi. SOLO backend (usa secretos que jamás salen de
 * aquí). Dos responsabilidades, ambas con la documentación oficial vigente:
 *
 *  1) Firma de INTEGRIDAD para crear transacciones:
 *       SHA256( reference + amount_in_cents + currency [+ expiration_time] + integrity_secret )
 *     Determinística → cubierta por tests con vectores fijos.
 *
 *  2) Verificación del CHECKSUM de eventos (webhook):
 *       SHA256( <valores de signature.properties EN ORDEN> + timestamp + events_secret )
 *     Las `properties` son rutas con punto relativas a `data`
 *     (p. ej. "transaction.id" → data.transaction.id). Se concatenan los
 *     valores en el ORDEN recibido, luego el timestamp, luego el secreto.
 *
 * Comparaciones con hash_equals (constant-time). Nunca se loguean secretos.
 */
class WompiSignatureService
{
    public function __construct(private array $cfg)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('wompi'));
    }

    /**
     * Firma de integridad para POST /transactions.
     *
     * @param  string       $reference      referencia única del comercio.
     * @param  int          $amountInCents  monto en centavos (entero).
     * @param  string       $currency       ISO (COP).
     * @param  string|null  $expirationTime ISO8601 si la transacción expira.
     * @param  string|null  $integritySecret override (tests); por defecto config.
     */
    public function integritySignature(
        string $reference,
        int $amountInCents,
        string $currency,
        ?string $expirationTime = null,
        ?string $integritySecret = null
    ): string {
        $secret = $integritySecret ?? (string) ($this->cfg['integrity_secret'] ?? '');
        $concat = $reference.$amountInCents.strtoupper($currency)
            .($expirationTime ?? '')
            .$secret;

        return hash('sha256', $concat);
    }

    /**
     * Verifica el checksum de un evento de webhook de Wompi.
     *
     * @param  array        $payload       cuerpo del evento (decodificado).
     * @param  string|null  $eventsSecret  override (tests); por defecto config.
     */
    public function verifyWebhookChecksum(array $payload, ?string $eventsSecret = null): bool
    {
        $secret = $eventsSecret ?? (string) ($this->cfg['events_secret'] ?? '');
        if ($secret === '') {
            return false;
        }

        $given = (string) data_get($payload, 'signature.checksum', '');
        if ($given === '') {
            return false;
        }

        $expected = $this->computeWebhookChecksum($payload, $secret);
        if ($expected === null) {
            return false;
        }

        // Wompi devuelve el checksum en HEX mayúsculas; normalizamos a minúsculas
        // ambos lados antes de la comparación constant-time.
        return hash_equals(strtolower($expected), strtolower($given));
    }

    /**
     * Calcula el checksum esperado a partir de signature.properties + timestamp.
     * Devuelve null si faltan properties o timestamp (evento malformado).
     */
    public function computeWebhookChecksum(array $payload, string $secret): ?string
    {
        $properties = data_get($payload, 'signature.properties');
        if (! is_array($properties) || $properties === []) {
            return null;
        }
        if (! array_key_exists('timestamp', $payload)) {
            return null;
        }

        $concat = '';
        foreach ($properties as $path) {
            // Las properties son relativas a `data`.
            $value = data_get($payload, 'data.'.$path);
            if (is_array($value)) {
                return null; // una property nunca debe resolver a un array
            }
            $concat .= $this->stringifyValue($value);
        }
        $concat .= (string) $payload['timestamp'];
        $concat .= $secret;

        return hash('sha256', $concat);
    }

    /** Normaliza un valor escalar al string que Wompi usa para el checksum. */
    private function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }
}
