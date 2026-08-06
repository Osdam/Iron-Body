<?php

namespace App\Services\Commercial\Tools\Commercial;

use App\Services\Commercial\Tools\BaseTool;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolResult;

/**
 * Guarda lo que el prospecto ha contado de sí mismo.
 *
 * Nombre, objetivo y correo, y nada más. El esquema es corto a propósito:
 * cuanto más se le permite escribir a un agente sobre la ficha de una persona,
 * más fácil es que una frase mal interpretada quede como un dato.
 *
 * No crea leads. El lead lo crea el canal cuando alguien escribe; esta
 * herramienta solo completa el que ya existe, y por eso el sujeto viene del
 * contexto y no de los argumentos: si el identificador fuera un argumento más,
 * bastaría con cambiar un número para escribir sobre la ficha de otro.
 */
class UpdateLeadTool extends BaseTool
{
    public function name(): string
    {
        return 'update_lead';
    }

    public function description(): string
    {
        return 'Guarda en la ficha del prospecto los datos que él mismo ha dado: '
            .'su nombre, su objetivo de entrenamiento o su correo. Solo lo que haya dicho.';
    }

    public function schema(): array
    {
        return $this->strictSchema([
            'name' => $this->stringProp('Nombre con el que la persona se presentó.'),
            'objective' => $this->stringProp('Objetivo que declaró: bajar de peso, ganar masa, salud general…'),
            'email' => $this->stringProp('Correo, solo si lo dio explícitamente.'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'objective' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:180'],
        ];
    }

    public function featureFlag(): ?string
    {
        return 'commercial.tools.lead';
    }

    public function timeoutSeconds(): int
    {
        return 5;
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $lead = $context->lead;

        if ($lead === null) {
            return ToolResult::failed('no_lead_in_context', 'No hay ficha de prospecto en esta conversación.');
        }

        if ($arguments === []) {
            return ToolResult::skipped('No se indicó ningún dato que guardar.');
        }

        // Solo se rellena lo que está vacío, salvo el objetivo, que sí puede
        // cambiar durante la conversación. Sobrescribir un nombre confirmado con
        // otro que el modelo creyó entender es una regresión difícil de detectar.
        $changes = [];

        if (isset($arguments['name']) && blank($lead->name)) {
            $changes['name'] = $arguments['name'];
        }

        // El correo no tiene columna propia en la ficha de prospecto; vive en
        // metadata. Hace falta guardarlo porque es el dato que pide la factura
        // electrónica más adelante.
        if (isset($arguments['email'])) {
            $metadata = (array) ($lead->metadata ?? []);

            if (blank($metadata['email'] ?? null)) {
                $metadata['email'] = $arguments['email'];
                $changes['metadata'] = $metadata;
            }
        }

        if (isset($arguments['objective'])) {
            $changes['objective'] = $arguments['objective'];
        }

        if ($changes === []) {
            return ToolResult::skipped('La ficha ya tenía esos datos.', ['lead_id' => $lead->id]);
        }

        $lead->forceFill($changes)->save();

        return ToolResult::ok([
            'lead_id' => $lead->id,
            // Se informa de 'email' y no de 'metadata', que es un detalle de
            // almacenamiento que al agente no le dice nada.
            'updated_fields' => array_map(
                fn (string $f) => $f === 'metadata' ? 'email' : $f,
                array_keys($changes),
            ),
        ]);
    }
}
