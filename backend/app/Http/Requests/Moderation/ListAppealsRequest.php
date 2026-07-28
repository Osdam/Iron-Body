<?php

namespace App\Http\Requests\Moderation;

use App\Models\ModerationAppeal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros de la bandeja de apelaciones (GET /api/admin/moderation/appeals).
 *
 * Comparte con {@see ListReportsRequest} el mismo defecto y la misma
 * corrección: `open_only` llegaba como la cadena `"true"` y la regla `boolean`
 * lo rechazaba con 422.
 */
class ListAppealsRequest extends FormRequest
{
    use NormalizesBooleanFilters;

    public function authorize(): bool
    {
        return true;
    }

    protected function booleanFilters(): array
    {
        return ['open_only'];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeBooleanFilters();
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in([
                ModerationAppeal::STATUS_SUBMITTED,
                ModerationAppeal::STATUS_UNDER_REVIEW,
                ModerationAppeal::STATUS_UPHELD,
                ModerationAppeal::STATUS_GRANTED,
                ModerationAppeal::STATUS_REJECTED,
            ])],
            'open_only' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:5|max:100',
        ];
    }

    public function messages(): array
    {
        return $this->booleanFilterMessages();
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return array_filter(
            $this->validated(),
            static fn ($value) => $value !== null && $value !== '',
        );
    }
}
