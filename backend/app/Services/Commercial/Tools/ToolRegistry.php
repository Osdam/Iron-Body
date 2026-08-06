<?php

namespace App\Services\Commercial\Tools;

/**
 * El catálogo cerrado de lo que el agente puede hacer.
 *
 * Cerrado es la palabra importante. El nombre de la herramienta se busca aquí y
 * en ningún otro sitio: no hay resolución dinámica de clases, ni `new $class`,
 * ni nada que convierta una cadena venida de un modelo de lenguaje en código
 * ejecutable. Si el nombre no está en esta lista, no pasa nada.
 */
class ToolRegistry
{
    /** @var array<string, CommercialTool>|null */
    private ?array $tools = null;

    /** @param array<int, class-string<CommercialTool>> $toolClasses */
    public function __construct(private readonly array $toolClasses) {}

    public function find(string $name): ?CommercialTool
    {
        return $this->all()[$name] ?? null;
    }

    /** @return array<string, CommercialTool> */
    public function all(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $resolved = [];

        foreach ($this->toolClasses as $class) {
            /** @var CommercialTool $tool */
            $tool = app($class);
            $resolved[$tool->name()] = $tool;
        }

        return $this->tools = $resolved;
    }

    /**
     * Los esquemas que se le ofrecen al modelo, en el formato de function
     * calling de OpenAI.
     *
     * Solo entran las herramientas cuyo flag está encendido: enseñarle al
     * modelo una capacidad que luego se le va a denegar produce conversaciones
     * en las que promete algo que el sistema no va a hacer.
     *
     * @return array<int,array<string,mixed>>
     */
    public function openAiSchemas(): array
    {
        $schemas = [];

        foreach ($this->all() as $tool) {
            $flag = $tool->featureFlag();

            if ($flag !== null && ! (bool) config($flag, false)) {
                continue;
            }

            $schemas[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->schema(),
                ],
            ];
        }

        return $schemas;
    }

    /** @return array<int,string> */
    public function names(): array
    {
        return array_keys($this->all());
    }
}
