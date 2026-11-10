<?php

namespace App\Services\UltronStudio\Stream;

/**
 * Un tema REGISTRADO pero todavía VACÍO: responde con la cabeza `0` y sin
 * eventos. Es la forma de los `outreach.*` hasta que B9-run los implemente
 * sobre esta misma interfaz (contrato H9, «Paquete SSE9»): el cliente puede
 * suscribirse ya y el canal no cambia de forma cuando lleguen.
 */
final class PendingTopic implements StreamTopic
{
    public function __construct(private readonly string $name, private readonly string $permission) {}

    public function name(): string
    {
        return $this->name;
    }

    public function permission(): string
    {
        return $this->permission;
    }

    public function listRoute(): ?string
    {
        return null;
    }

    public function cursor(): string
    {
        return '0';
    }

    public function since(string $cursor, int $limit): array
    {
        return [];
    }
}
