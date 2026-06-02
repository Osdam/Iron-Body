<?php

namespace App\Services\Contracts;

use App\Models\ContractTemplate;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/**
 * Resuelve y valida las plantillas oficiales. Las plantillas viven en disco
 * PRIVADO (no versionadas en git) y se registran en `contract_templates`. Si
 * falta el archivo fuente o no está registrada, lanza ContractTemplateException
 * (nunca se inventa un documento).
 */
class ContractTemplateService
{
    public function disk(): string
    {
        return (string) Config::get('contracts.disk', 'local');
    }

    /** Ruta (relativa al disco) del PDF fuente de una plantilla. */
    public function sourcePath(string $templateKey): string
    {
        $base = trim((string) Config::get('contracts.templates_path'), '/');
        $def = $this->definition($templateKey);

        return $base.'/'.$def['file'];
    }

    /** Definición declarada en config/contracts.php para una clave dada. */
    public function definition(string $templateKey): array
    {
        $def = Config::get("contracts.templates.{$templateKey}");

        if (! is_array($def)) {
            throw new ContractTemplateException(
                "Plantilla de contrato no definida: '{$templateKey}'. ".
                'Revise config/contracts.php.'
            );
        }

        return $def;
    }

    /** Todas las claves de plantilla declaradas. */
    public function allKeys(): array
    {
        return array_keys((array) Config::get('contracts.templates', []));
    }

    /**
     * Verifica que el archivo fuente exista en disco. Lanza una excepción clara
     * (con instrucciones de instalación) si falta.
     */
    public function assertSourceExists(string $templateKey): string
    {
        $path = $this->sourcePath($templateKey);

        if (! Storage::disk($this->disk())->exists($path)) {
            $abs = Storage::disk($this->disk())->path($path);

            throw new ContractTemplateException(
                "Falta la plantilla oficial '{$templateKey}'. ".
                "Debe instalarse en: {$abs}. ".
                'Las plantillas NO se versionan en git: cópielas durante el despliegue '.
                '(ver docs/contracts/LEGAL_REVIEW_REQUIRED.md).'
            );
        }

        return $path;
    }

    /** Ruta absoluta del archivo fuente (para FPDI). Verifica existencia. */
    public function sourceAbsolutePath(string $templateKey): string
    {
        $path = $this->assertSourceExists($templateKey);

        return Storage::disk($this->disk())->path($path);
    }

    /** SHA256 del archivo fuente actualmente en disco. */
    public function sourceChecksum(string $templateKey): string
    {
        $path = $this->assertSourceExists($templateKey);

        return hash('sha256', (string) Storage::disk($this->disk())->get($path));
    }

    /**
     * Registra/actualiza las filas de contract_templates a partir de config y de
     * los archivos en disco. Devuelve un resumen por plantilla. NO sobrescribe
     * archivos fuente; solo lee y registra metadatos + checksum.
     */
    public function syncFromConfig(): array
    {
        $summary = [];

        foreach ((array) Config::get('contracts.templates', []) as $key => $def) {
            $path = $this->sourcePath($key);
            $exists = Storage::disk($this->disk())->exists($path);
            $checksum = $exists
                ? hash('sha256', (string) Storage::disk($this->disk())->get($path))
                : null;

            $template = ContractTemplate::updateOrCreate(
                ['template_key' => $key],
                [
                    'name'             => $def['name'] ?? $key,
                    'version'          => $def['version'] ?? '1.0.0',
                    'applies_to'       => $def['applies_to'] ?? 'any',
                    'source_file_path' => $path,
                    'source_checksum'  => $checksum,
                    'active'           => $exists,
                ]
            );

            $summary[$key] = [
                'id'       => $template->id,
                'exists'   => $exists,
                'checksum' => $checksum,
                'path'     => Storage::disk($this->disk())->path($path),
            ];
        }

        return $summary;
    }

    /** Obtiene (o crea perezosamente) la fila de plantilla registrada. */
    public function modelFor(string $templateKey): ContractTemplate
    {
        $def = $this->definition($templateKey);
        $path = $this->assertSourceExists($templateKey);

        return ContractTemplate::updateOrCreate(
            ['template_key' => $templateKey],
            [
                'name'             => $def['name'] ?? $templateKey,
                'version'          => $def['version'] ?? '1.0.0',
                'applies_to'       => $def['applies_to'] ?? 'any',
                'source_file_path' => $path,
                'source_checksum'  => $this->sourceChecksum($templateKey),
                'active'           => true,
            ]
        );
    }

    /** Conjunto de checkboxes (texto exacto) asociado a la plantilla. */
    public function checkboxes(string $templateKey): array
    {
        $def = $this->definition($templateKey);
        $set = $def['checkbox_set'] ?? null;

        return (array) Config::get("contracts.checkbox_sets.{$set}", []);
    }
}
