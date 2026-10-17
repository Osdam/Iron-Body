<?php

namespace App\Services\Valuations;

use App\Models\ValuationSetting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Los ajustes de la agenda: una fila, leída siempre fresca (los avisos miran
 * `member_app_enabled` EN EL MOMENTO de enviar) y validada entera al guardar.
 */
final class ValuationSettings
{
    public function current(): ValuationSetting
    {
        $fila = ValuationSetting::query()->orderBy('id')->first();
        if ($fila !== null) {
            return $fila;
        }

        // La migración crea la fila; si alguien la borró, se repone con los
        // valores del contrato en vez de romper la agenda.
        return ValuationSetting::create(ValuationSetting::DEFAULTS);
    }

    /** @return array<string, list<string>> reglas de validación del PUT entero */
    public static function rules(): array
    {
        return [
            'default_duration_minutes' => ['required', 'integer', 'min:15', 'max:240', 'multiple_of:5'],
            'slot_interval_minutes' => ['required', 'integer', 'in:15,30,60'],
            'buffer_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'min_notice_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'max_horizon_days' => ['required', 'integer', 'min:1', 'max:365'],
            'block_on_class_conflict' => ['required', 'boolean'],
            'confirmation_enabled' => ['required', 'boolean'],
            'reminder_long_enabled' => ['required', 'boolean'],
            'reminder_long_hours' => ['required', 'integer', 'min:1', 'max:72'],
            'reminder_short_enabled' => ['required', 'boolean'],
            'reminder_short_minutes' => ['required', 'integer', 'min:15', 'max:240'],
            'internal_alert_enabled' => ['required', 'boolean'],
            'internal_alert_minutes' => ['required', 'integer', 'min:5', 'max:120'],
            'internal_unattended_enabled' => ['required', 'boolean'],
            'member_app_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * Valida el conjunto ENTERO (todas las claves, con sus rangos y la regla
     * cruzada corto < largo) y devuelve los valores normalizados.
     *
     * @param  array<string,mixed>  $entrada
     * @return array<string,mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $entrada): array
    {
        $v = Validator::make($entrada, self::rules());
        $v->after(function ($v) use ($entrada): void {
            $corto = $entrada['reminder_short_minutes'] ?? null;
            $largo = $entrada['reminder_long_hours'] ?? null;
            if (is_numeric($corto) && is_numeric($largo) && (int) $corto >= (int) $largo * 60) {
                $v->errors()->add('reminder_short_minutes', 'El recordatorio corto tiene que llegar después del largo: menos minutos que las horas del largo × 60.');
            }
        });
        $datos = $v->validate();

        $out = [];
        foreach (ValuationSetting::FIELDS as $campo) {
            $valor = $datos[$campo];
            $out[$campo] = is_bool(ValuationSetting::DEFAULTS[$campo])
                ? filter_var($valor, FILTER_VALIDATE_BOOLEAN)
                : (int) $valor;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function present(ValuationSetting $s): array
    {
        $out = [];
        foreach (ValuationSetting::FIELDS as $campo) {
            $out[$campo] = $s->getAttribute($campo);
        }
        $out['updated_at'] = $s->updated_at?->copy()->utc()->toIso8601String();

        return $out;
    }
}
