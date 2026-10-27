<?php

namespace App\Support\Workforce;

/**
 * Resultado de una verificación facial que pasó: la distancia a la plantilla
 * propia y si hizo falta el PIN (contrato §10.1). El método que se registra
 * en jornadas, clases y sesiones sale de aquí.
 */
final class FaceMatch
{
    public const METHOD_FACE = 'face';

    public const METHOD_FACE_PIN = 'face_pin';

    public function __construct(
        public readonly float $distance,
        public readonly bool $withPin,
    ) {}

    public function method(): string
    {
        return $this->withPin ? self::METHOD_FACE_PIN : self::METHOD_FACE;
    }
}
