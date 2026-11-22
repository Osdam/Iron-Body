<?php

namespace App\Services\Outreach\Run\Classifier;

/**
 * AI9 · EL PUERTO hacia el modelo que clasifica una respuesta (contrato H9,
 * paquete AI9; §3 protección 4: el espacio `Outreach` no nombra proveedores
 * ni red). Aquí solo está la forma: la petición sale de un adaptador FUERA
 * del H9, que el contenedor enlaza con esta interfaz. Sin ese enlace no hay
 * transporte y el clasificador no clasifica (falla cerrado).
 *
 * El adaptador recibe SOLO lo que dice la firma (el modelo, las instrucciones
 * fijas, el texto YA ENMASCARADO de la respuesta, el esquema y los límites):
 * nunca un teléfono, un nombre ni un identificador de la persona. Lee su
 * clave en el momento de llamar y no la devuelve. Una llamada, sin
 * reintentos. No lanza: todo fallo es un código de {@see ERRORS}.
 */
interface ReplyClassifierTransport
{
    /** Los fallos posibles, conjunto cerrado. */
    public const ERRORS = ['not_configured', 'timeout', 'connection', 'auth', 'rate_limited', 'quota', 'bad_request', 'server', 'refusal', 'incomplete', 'invalid_output'];

    /** Los fallos en los que la petición NO llegó al proveedor (no se cobró). Ante la duda, se cobró. */
    public const NOT_SENT = ['not_configured', 'connection'];

    /** ¿Puede llamar? (su clave propia presente y distinta de la compartida). No hace red ni devuelve la clave. */
    public function configured(): bool;

    /**
     * Una clasificación, una petición. `output` es el objeto JSON que devolvió
     * el modelo, sin interpretar: quien llama lo valida entero.
     *
     * @param  array<string, mixed>  $schema
     * @return array{error: string|null, output: array<mixed>|null, usage: array{inputTokens: int, outputTokens: int}|null, latencyMs: int}
     */
    public function classify(string $model, string $instructions, string $text, array $schema, int $maxOutputTokens, int $timeoutSeconds): array;
}
