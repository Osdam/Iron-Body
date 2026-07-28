<?php

namespace App\Http\Requests\Moderation;

use App\Support\Moderation\ReportReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del reporte que envía la app.
 *
 * Lo que este request NO acepta, por diseño: `reporter_id`, `reported_member_id`,
 * `author_id`, `status`, `severity`, `priority` ni ninguna URL de medio. Todo
 * eso lo resuelve el servidor desde el bearer y desde la Story real. Si el
 * cliente los envía, se ignoran (no están en `rules()` y el controlador nunca
 * lee `$request->all()`).
 */
class SubmitReportRequest extends FormRequest
{
    /** La autorización real es el middleware `auth.member`. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Catálogo CERRADO: cualquier otro valor es 422.
            'reason_code' => ['required', 'string', Rule::in(ReportReason::codes())],
            // Texto libre opcional del reportante. Acotado para rechazar
            // payloads gigantes antes de tocar la base de datos.
            'reason_detail' => [
                'nullable',
                'string',
                'max:'.(int) config('ugc.report_detail_max_length', 500),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'reason_code.required' => 'Selecciona un motivo para el reporte.',
            'reason_code.in' => 'El motivo seleccionado no es válido.',
            'reason_detail.max' => 'La descripción es demasiado larga.',
        ];
    }

    public function reasonCode(): string
    {
        return (string) $this->validated('reason_code');
    }

    public function reasonDetail(): ?string
    {
        $detail = $this->validated('reason_detail');

        return is_string($detail) && trim($detail) !== '' ? $detail : null;
    }
}
