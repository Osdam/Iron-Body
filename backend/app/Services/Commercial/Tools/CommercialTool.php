<?php

namespace App\Services\Commercial\Tools;

/**
 * Contrato de una herramienta operativa del agente.
 *
 * Una herramienta es la ÚNICA vía por la que una decisión comercial se
 * convierte en un efecto real. Que sean pocas, con nombre estable y firma
 * estricta, es lo que hace que el sistema se pueda razonar: si algo cambió en
 * Wompi, en las membresías o en la agenda, fue porque alguna de estas se
 * ejecutó, y queda su fila.
 *
 * Lo que una herramienta NUNCA hace, por muy convincente que suene la petición:
 *
 *  · **Aceptar un monto, un precio o un estado propuesto desde fuera.** El
 *    dinero se calcula en Laravel leyendo el catálogo. Un modelo de lenguaje
 *    que sugiere «cóbrale 50.000» describe una intención, no un precio; el
 *    esquema simplemente no tiene ese campo.
 *  · **Confirmar un pago.** Un pago se confirma por webhook firmado o por
 *    consulta oficial a la pasarela. Ni una captura de pantalla, ni la
 *    afirmación del cliente, ni la conclusión del modelo.
 *  · **Crear una membresía antes de que el pago esté confirmado.**
 *  · **Hablar directamente con PostgreSQL, Wompi, Factus o Meta.** Cada
 *    herramienta encapsula un servicio de Laravel que ya existe y que ya sabe
 *    hacer eso bien.
 *  · **Ejecutar texto libre.** El nombre viene de un registro cerrado.
 */
interface CommercialTool
{
    /**
     * Nombre estable, en snake_case. Estable de verdad: se guarda en la
     * auditoría y puede aparecer en decisiones ya tomadas, así que renombrarlo
     * rompe el histórico.
     */
    public function name(): string;

    /** Una línea para el modelo: qué hace y cuándo usarla. */
    public function description(): string;

    /**
     * JSON Schema ESTRICTO de los argumentos.
     *
     * Estricto quiere decir `additionalProperties: false`. Sin eso, un campo
     * inventado —`amount`, `price`, `status`— viajaría hasta la
     * implementación, y la única defensa sería que a alguien se le ocurriera
     * ignorarlo.
     *
     * @return array<string,mixed>
     */
    public function schema(): array;

    /**
     * Reglas de validación de Laravel, equivalentes al esquema.
     *
     * Se declaran las dos cosas a propósito: el esquema es el contrato que ve
     * el modelo, y las reglas son la barrera que se ejecuta. Un esquema no
     * valida nada por sí solo.
     *
     * @return array<string,mixed>
     */
    public function rules(): array;

    /**
     * Interruptor propio. Permite encender la lectura de planes sin encender la
     * generación de enlaces de pago.
     */
    public function featureFlag(): ?string;

    /**
     * ¿Esta herramienta cambia algo en el mundo?
     *
     * Las de solo lectura se pueden dejar sueltas con mucho menos miedo; las
     * que escriben exigen idempotencia y auditoría completa.
     */
    public function mutates(): bool;

    /**
     * ¿Exige que una persona lo apruebe antes de ejecutarse?
     *
     * Reservado para lo que no tiene vuelta atrás cómoda: emitir un documento
     * fiscal, tocar una membresía existente.
     */
    public function requiresHumanApproval(): bool;

    /**
     * ¿Necesita que la autonomía del motor esté encendida?
     *
     * Por defecto coincide con `mutates()`, pero se declara aparte porque hay
     * una excepción importante: **ceder la conversación a una persona**. Esa
     * acción cambia el mundo y aun así no puede depender de un flag; si lo
     * hiciera, un cliente enfadado durante la fase de pruebas se quedaría
     * hablando con un robot justo cuando menos lo tolera. Callarse nunca
     * necesita permiso.
     */
    public function requiresAutonomy(): bool;

    /**
     * Segundos máximos. Una herramienta que se cuelga bloquea la conversación
     * de un cliente que está esperando.
     */
    public function timeoutSeconds(): int;

    /**
     * Ejecuta con argumentos YA validados contra las reglas.
     *
     * No debe volver a validar formato, pero sí comprobar las reglas de negocio
     * que dependen del estado (que el plan siga activo, que el pago esté
     * confirmado): entre la validación y este punto el mundo pudo cambiar.
     *
     * @param  array<string,mixed>  $arguments
     */
    public function execute(array $arguments, ToolContext $context): ToolResult;
}
