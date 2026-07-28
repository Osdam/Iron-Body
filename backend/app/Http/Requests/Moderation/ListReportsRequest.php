<?php

namespace App\Http\Requests\Moderation;

use App\Support\Moderation\ReportReason;
use App\Support\Moderation\ReportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros de la cola de moderación (GET /api/admin/moderation/reports).
 *
 * Extraído del controlador para poder normalizar los booleanos ANTES de
 * validar ({@see prepareForValidation()}). Las reglas son las mismas que ya
 * había: este request no amplía ni recorta lo que el endpoint acepta, solo
 * corrige cómo interpreta los booleanos de la query string.
 *
 * La autorización sigue donde estaba: el permiso `moderation.view` lo
 * comprueba el controlador. Este request no decide permisos.
 */
class ListReportsRequest extends FormRequest
{
    use NormalizesBooleanFilters;

    /** El permiso real lo aplica el controlador con ModerationPermission. */
    public function authorize(): bool
    {
        return true;
    }

    protected function booleanFilters(): array
    {
        return ['with_evidence', 'with_appeal', 'open_only'];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanFilters();
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(ReportStatus::all())],
            'reason_code' => ['nullable', 'string', Rule::in(ReportReason::codes())],
            'severity' => ['nullable', 'string', Rule::in([
                ReportReason::SEVERITY_LOW,
                ReportReason::SEVERITY_MEDIUM,
                ReportReason::SEVERITY_HIGH,
                ReportReason::SEVERITY_CRITICAL,
            ])],
            'assigned_admin_id' => 'nullable|integer',
            'reported_member_id' => 'nullable|integer',
            'content_type' => 'nullable|string|max:32',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'with_evidence' => 'nullable|boolean',
            'with_appeal' => 'nullable|boolean',
            'open_only' => 'nullable|boolean',
            // Lista BLANCA de ordenamientos: no se acepta una columna
            // arbitraria (evita filtrar por columnas sensibles).
            'sort' => ['nullable', 'string', Rule::in([
                'submitted_at', 'priority', 'severity', 'status',
            ])],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:5|max:100',
        ];
    }

    public function messages(): array
    {
        return $this->booleanFilterMessages();
    }

    /**
     * Filtros efectivos, sin nulos.
     *
     * Devolver los nulos haría que el controlador tratara «filtro ausente» y
     * «filtro vacío» de forma distinta según el `empty()` de cada rama.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter(
            $this->validated(),
            static fn ($value) => $value !== null && $value !== '',
        );
    }
}
