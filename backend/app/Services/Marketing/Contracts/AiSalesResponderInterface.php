<?php

namespace App\Services\Marketing\Contracts;

/**
 * Contrato del "cerebro" que clasifica un mensaje comercial. Permite cambiar la
 * implementación (reglas deterministas hoy; OpenAI/Claude mañana) SIN tocar el
 * orquestador. La implementación NUNCA debe ejecutar acciones reales ni activar
 * membresías: solo clasifica intención y extrae campos.
 */
interface AiSalesResponderInterface
{
    /**
     * Clasifica el cuerpo de un mensaje del lead.
     *
     * @param  string  $body     texto del lead.
     * @param  array   $context  contexto opcional (lead, historial, planes…).
     * @return array{intent:string, confidence:float, extracted_fields:array, missing_fields:array}
     */
    public function classify(string $body, array $context = []): array;

    /** Identificador del responder (fake | openai …) para auditoría. */
    public function name(): string;
}
