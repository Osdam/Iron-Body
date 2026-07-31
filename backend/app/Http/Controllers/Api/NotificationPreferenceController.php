<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberNotificationPreference;
use App\Services\Notifications\NotificationCatalog;
use App\Support\Notifications\NotificationCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Preferencias de notificación del socio autenticado.
 *
 * El socio SIEMPRE sale del bearer (`auth.member`): no se acepta `member_id` por
 * parámetro, así nadie puede cambiar los ajustes de otra persona.
 */
class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $memberId = $this->memberId($request);
        if ($memberId === null) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        return response()->json([
            'data' => MemberNotificationPreference::forMember($memberId)->toStateArray(),
            'meta' => $this->meta(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $memberId = $this->memberId($request);
        if ($memberId === null) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $data = $request->validate([
            'timezone' => ['sometimes', 'string', 'max:64'],
            'quiet_hours_enabled' => ['sometimes', 'boolean'],
            'quiet_hours_start' => ['sometimes', 'integer', 'min:0', 'max:23'],
            'quiet_hours_end' => ['sometimes', 'integer', 'min:0', 'max:23'],
            'max_per_day' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'max_wellness_per_week' => ['sometimes', 'integer', 'min:0', 'max:14'],
            'opted_out' => ['sometimes', 'boolean'],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['boolean'],
            'supplement_kinds' => ['sometimes', 'array'],
            'supplement_kinds.*' => ['boolean'],
        ]);

        if (isset($data['timezone']) && ! in_array($data['timezone'], timezone_identifiers_list(), true)) {
            return response()->json([
                'message' => 'Zona horaria no reconocida.',
                'code' => 'invalid_timezone',
            ], 422);
        }

        $prefs = MemberNotificationPreference::query()->firstOrNew(['member_id' => $memberId]);

        foreach (['timezone', 'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end', 'max_per_day', 'max_wellness_per_week'] as $field) {
            if (array_key_exists($field, $data)) {
                $prefs->{$field} = $data[$field];
            }
        }

        if (array_key_exists('opted_out', $data)) {
            $prefs->opted_out_at = $data['opted_out'] ? now() : null;
        }

        if (array_key_exists('categories', $data)) {
            $prefs->categories = $this->mergeToggles(
                $prefs->categories ?? [],
                $data['categories'],
                NotificationCategory::ALL,
                // Lo obligatorio no se puede apagar aunque llegue en el cuerpo.
                NotificationCategory::MANDATORY,
            );
        }

        if (array_key_exists('supplement_kinds', $data)) {
            $prefs->supplement_kinds = $this->mergeToggles(
                $prefs->supplement_kinds ?? [],
                $data['supplement_kinds'],
                NotificationCategory::SUPPLEMENT_KINDS,
                [],
            );
        }

        $prefs->member_id = $memberId;
        $prefs->save();

        return response()->json([
            'data' => $prefs->fresh()->toStateArray(),
            'meta' => $this->meta(),
        ]);
    }

    /**
     * Funde los cambios sobre lo que ya había, ignorando claves desconocidas.
     *
     * Se funde en vez de reemplazar para que la app pueda mandar un solo
     * interruptor sin borrar sin querer el resto de las preferencias.
     *
     * @param  list<string>  $allowed
     * @param  list<string>  $locked
     */
    private function mergeToggles(array $current, array $incoming, array $allowed, array $locked): array
    {
        foreach ($incoming as $key => $value) {
            if (! in_array($key, $allowed, true) || in_array($key, $locked, true)) {
                continue;
            }
            $current[$key] = (bool) $value;
        }

        return $current;
    }

    /** Etiquetas para que la app no tenga que traducir nada por su cuenta. */
    private function meta(): array
    {
        $categories = [];
        foreach (NotificationCategory::ALL as $category) {
            $categories[] = [
                'key' => $category,
                'label' => NotificationCategory::label($category),
                'mandatory' => NotificationCategory::isMandatory($category),
            ];
        }

        $supplements = [];
        foreach (NotificationCategory::SUPPLEMENT_KINDS as $kind) {
            $supplements[] = [
                'key' => $kind,
                'label' => NotificationCategory::supplementLabel($kind),
            ];
        }

        return [
            'categories' => $categories,
            'supplement_kinds' => $supplements,
            'supplement_notice' => NotificationCatalog::SUPPLEMENT_DISCLAIMER,
        ];
    }

    private function memberId(Request $request): ?int
    {
        $member = $request->attributes->get('auth_member');

        return $member?->id;
    }
}
