<?php

namespace App\Services\Commercial\Tools;

/**
 * Lo que devuelve una herramienta, siempre con la misma forma.
 *
 * Un resultado estructurado no es burocracia: es lo que permite que el modelo
 * de lenguaje reciba un hecho en lugar de una frase que tenga que interpretar.
 * «No se pudo generar el enlace porque falta configurar Wompi» y
 * `error_code: wompi_not_configured` dicen lo mismo, pero solo con el segundo
 * puede el ejecutor decidir si reintentar, escalar o callarse.
 *
 * La distinción entre `failed` y `skipped` importa más de lo que parece:
 * `skipped` significa que el objetivo YA estaba conseguido —el enlace existía,
 * el miembro ya estaba creado— y es un éxito disfrazado. Tratarlo como fallo
 * llevaría a reintentar algo que ya está hecho.
 */
final class ToolResult
{
    private function __construct(
        public readonly string $status,
        public readonly array $data = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $message = null,
        public readonly bool $retryable = false,
    ) {}

    public static function ok(array $data = [], ?string $message = null): self
    {
        return new self('succeeded', $data, null, $message);
    }

    /**
     * No había nada que hacer porque ya estaba hecho. Es un éxito: quien lo
     * reciba NO debe reintentar.
     */
    public static function skipped(string $message, array $data = []): self
    {
        return new self('skipped', $data, null, $message);
    }

    /**
     * @param  bool  $retryable  true solo si repetir la misma llamada más tarde
     *                           podría funcionar (red, caída del proveedor). Un
     *                           dato inválido NUNCA es reintentable: repetirlo
     *                           da el mismo error y gasta el presupuesto.
     */
    public static function failed(
        string $errorCode,
        string $message,
        bool $retryable = false,
        array $data = [],
    ): self {
        return new self('failed', $data, $errorCode, $message, $retryable);
    }

    /** Rechazada antes de ejecutar: sin permiso, sin flag o argumentos inválidos. */
    public static function rejected(string $errorCode, string $message, array $data = []): self
    {
        return new self('rejected', $data, $errorCode, $message, false);
    }

    public function successful(): bool
    {
        return in_array($this->status, ['succeeded', 'skipped'], true);
    }

    /** Forma que se le entrega al modelo: hechos, nunca objetos internos. */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'data' => $this->data ?: null,
            'error_code' => $this->errorCode,
            'message' => $this->message,
            'retryable' => $this->retryable ?: null,
        ], fn ($v) => $v !== null);
    }
}
