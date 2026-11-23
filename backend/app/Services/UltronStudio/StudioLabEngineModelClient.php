<?php

namespace App\Services\UltronStudio;

/**
 * Una llamada al modelo COMO LA HACE UN NODO DE OPENAI DEL FLUJO S2 (apartado
 * 5 del cierre; diseño `plans/informes-cierre/diseno-arq-lab.md` §3.1, B5).
 * Aparte de {@see StudioLabModelClient} a propósito: la simulación no cambia y
 * los dobles que ya la implementan siguen valiendo.
 *
 * Lo que envía el nodo `@n8n/n8n-nodes-langchain.openAi` 2.3 (Responses API),
 * verificado en su código fuente (`v2/actions/text/helpers/responses.ts` y
 * `descriptions.ts`, rama `master`, consultados el 22-11-2026): los DOS mensajes
 * de `responses.values` como mensajes de `user` (el `role` vale `user` por
 * defecto y el flujo no lo fija), cada uno con una parte `input_text`; SIN
 * `instructions` (el flujo no las pone y el nodo quita las vacías); el modelo,
 * `temperature`, `max_output_tokens` (`maxTokens`) y `store` del nodo; y
 * `text.format = {type: json_schema, name, schema}` con el esquema decodificado
 * y SIN `strict` (el nodo no lo mapea aunque el flujo lo lleve). No se pudo
 * verificar la versión de n8n que corre en producción: la divergencia posible
 * queda dicha en el informe.
 *
 * El resultado tiene la forma de {@see StudioLabModelClient::complete()} y
 * tampoco lanza: todo fallo vuelve clasificado.
 */
interface StudioLabEngineModelClient
{
    /**
     * @param  string  $node  el nombre del nodo ({@see StudioLabEngineFlow::NODES})
     * @param  string  $system  el primer mensaje: el prompt compilado de su rol
     * @param  string  $user  el segundo mensaje: el JSON del contexto, como lo escribe `JSON.stringify`
     * @param  int  $timeoutSeconds  el tiempo máximo de la llamada
     * @return array{status:string,output:array<string,mixed>|null,usage:array{inputTokens:int,outputTokens:int,reasoningTokens:int}|null,class:string|null,http:int|null,latencyMs:int,exception:string|null}
     */
    public function completeEngine(string $node, string $system, string $user, int $timeoutSeconds): array;
}
