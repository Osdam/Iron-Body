<?php

namespace App\Services\Commercial\Tools;

/**
 * Valores por defecto conservadores para toda herramienta.
 *
 * Los defaults están elegidos para que olvidarse de declarar algo lleve al lado
 * SEGURO: una herramienta que no dice nada se considera que muta el mundo, y
 * por tanto exige autonomía encendida. Al revés —asumir que solo lee— haría
 * que un despiste dejara suelta una acción con efectos.
 */
abstract class BaseTool implements CommercialTool
{
    public function featureFlag(): ?string
    {
        return null;
    }

    public function mutates(): bool
    {
        return true;
    }

    public function requiresHumanApproval(): bool
    {
        return false;
    }

    /** Por defecto, todo lo que escribe necesita autonomía. Ver el contrato. */
    public function requiresAutonomy(): bool
    {
        return $this->mutates();
    }

    public function timeoutSeconds(): int
    {
        return 15;
    }

    /**
     * Construye el JSON Schema estricto.
     *
     * `additionalProperties: false` no es opcional: sin él, un campo inventado
     * —`amount`, `price`, `status`— viajaría hasta la implementación y la única
     * defensa sería que a alguien se le ocurriera ignorarlo.
     *
     * @param  array<string,array<string,mixed>>  $properties
     * @param  array<int,string>  $required
     */
    protected function strictSchema(array $properties, array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_values($required),
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    protected function stringProp(string $description, ?array $enum = null): array
    {
        $prop = ['type' => 'string', 'description' => $description];

        if ($enum !== null) {
            $prop['enum'] = $enum;
        }

        return $prop;
    }

    /** @return array<string,mixed> */
    protected function intProp(string $description): array
    {
        return ['type' => 'integer', 'description' => $description];
    }
}
