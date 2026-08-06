<?php

namespace App\Services\Commercial\Tools\Invoicing;

use App\Services\Commercial\Tools\BaseTool;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolResult;

/**
 * Comprueba si los datos fiscales bastan para pedir una factura.
 *
 * No emite nada. Solo dice qué falta, y esa es toda su utilidad: la inmensa
 * mayoría de las facturas rechazadas por la DIAN lo son por datos incompletos
 * del cliente, y ese problema se resuelve preguntando por WhatsApp antes de
 * enviar nada, no reintentando después.
 *
 * Es de solo lectura y deliberadamente tonta: valida formato, no identidad. No
 * consulta la DIAN ni pretende saber si un NIT es real.
 */
class ValidateInvoiceDataTool extends BaseTool
{
    private const DOC_TYPES = ['CC', 'NIT', 'CE', 'PP'];

    public function name(): string
    {
        return 'validate_invoice_data';
    }

    public function description(): string
    {
        return 'Comprueba si los datos fiscales que dio la persona alcanzan para pedir la factura '
            .'y te dice exactamente cuáles faltan. No emite la factura.';
    }

    public function schema(): array
    {
        return $this->strictSchema([
            'document_type' => $this->stringProp('Tipo de documento.', self::DOC_TYPES),
            'document_number' => $this->stringProp('Número de documento o NIT.'),
            'name' => $this->stringProp('Nombre o razón social como debe aparecer en la factura.'),
            'email' => $this->stringProp('Correo al que enviar la factura.'),
            'address' => $this->stringProp('Dirección.'),
            'city_code' => $this->stringProp('Código DANE de la ciudad, si se conoce.'),
        ]);
    }

    public function rules(): array
    {
        return [
            'document_type' => ['sometimes', 'string', 'in:'.implode(',', self::DOC_TYPES)],
            'document_number' => ['sometimes', 'string', 'max:40'],
            'name' => ['sometimes', 'string', 'max:200'],
            'email' => ['sometimes', 'email', 'max:180'],
            'address' => ['sometimes', 'string', 'max:200'],
            'city_code' => ['sometimes', 'string', 'max:10'],
        ];
    }

    public function featureFlag(): ?string
    {
        return 'commercial.tools.invoicing';
    }

    public function mutates(): bool
    {
        return false;
    }

    public function timeoutSeconds(): int
    {
        return 5;
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $required = ['document_type', 'document_number', 'name', 'email'];

        $missing = array_values(array_filter(
            $required,
            fn (string $field) => blank($arguments[$field] ?? null),
        ));

        // El NIT lleva dígito de verificación y suele venir pegado («900123456-7»).
        // Se avisa, no se corrige: adivinar un dígito fiscal no es tarea nuestra.
        $warnings = [];

        if (($arguments['document_type'] ?? null) === 'NIT'
            && filled($arguments['document_number'] ?? null)
            && ! str_contains((string) $arguments['document_number'], '-')) {
            $warnings[] = 'Un NIT suele incluir dígito de verificación (formato 900123456-7). Confírmalo.';
        }

        return ToolResult::ok([
            'complete' => $missing === [],
            'missing_fields' => $missing,
            'warnings' => $warnings,
            // Traducción a lenguaje llano para que el agente pregunte por lo que
            // falta sin recitar nombres de campos.
            'ask_for' => array_map(fn (string $f) => match ($f) {
                'document_type' => 'si la factura va a nombre de una persona (cédula) o de una empresa (NIT)',
                'document_number' => 'el número de cédula o NIT',
                'name' => 'el nombre o razón social exacto para la factura',
                'email' => 'el correo al que enviarla',
                default => $f,
            }, $missing),
        ], $missing === []
            ? 'Los datos están completos.'
            : 'Faltan datos: pídelos antes de solicitar la factura.');
    }
}
