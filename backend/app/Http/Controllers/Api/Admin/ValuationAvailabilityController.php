<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ValuationException;
use App\Http\Controllers\Concerns\ValuationResponses;
use App\Http\Controllers\Controller;
use App\Models\Trainer;
use App\Models\ValuationAvailabilityBlock;
use App\Rules\PlainText;
use App\Services\Valuations\ValuationAccess;
use App\Services\Valuations\ValuationAvailabilityEditor;
use App\Services\Valuations\ValuationTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Disponibilidad de los entrenadores para valoraciones: reglas semanales y
 * bloqueos (contrato §4, «Disponibilidad»).
 *
 * Leer: quien tiene `valuations.manage_availability`, o el propio entrenador
 * vinculado con `view`. Escribir: solo `manage_availability` (el mapa lo exige
 * en la puerta; aquí se repite con la persona delante).
 */
class ValuationAvailabilityController extends Controller
{
    use ValuationResponses;

    private const MAX_RULES = 100;

    private const MAX_BLOCK_RANGE_DAYS = 366;

    public function __construct(private readonly ValuationAvailabilityEditor $editor) {}

    /** GET /api/admin/valuations/trainers/{id}/availability */
    public function show(Request $request, int $trainerId): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($trainerId): JsonResponse {
            $this->exigirLectura($access, $trainerId);

            return response()->json(['ok' => true, 'data' => $this->disponibilidad($trainerId)]);
        });
    }

    /** PUT /api/admin/valuations/trainers/{id}/availability — sustituye el conjunto entero. */
    public function replace(Request $request, int $trainerId): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($request, $trainerId): JsonResponse {
            $access->require(ValuationAccess::MANAGE_AVAILABILITY);
            $this->exigirEntrenador($trainerId);

            $datos = $this->validar($request, [
                'rules' => ['present', 'array', 'max:'.self::MAX_RULES],
                'rules.*.weekday' => ['required', 'integer', 'min:1', 'max:7'],
                'rules.*.start_time' => ['required', 'date_format:H:i'],
                'rules.*.end_time' => ['required', 'date_format:H:i', 'after:rules.*.start_time'],
                'rules.*.valid_from' => ['nullable', 'date_format:Y-m-d'],
                'rules.*.valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:rules.*.valid_from'],
                'rules.*.active' => ['nullable', 'boolean'],
            ]);

            $this->editor->replaceRules($access, $trainerId, array_values($datos['rules']), $request);

            return response()->json(['ok' => true, 'data' => $this->disponibilidad($trainerId)]);
        });
    }

    /** GET /api/admin/valuations/trainers/{id}/blocks?from&to (por defecto, los próximos 60 días) */
    public function blocks(Request $request, int $trainerId): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($request, $trainerId): JsonResponse {
            $this->exigirLectura($access, $trainerId);
            $datos = $this->validar($request, [
                'from' => ['nullable', 'string', 'max:40'],
                'to' => ['nullable', 'string', 'max:40'],
            ]);

            $desde = ! empty($datos['from']) ? ValuationTime::parseInstant((string) $datos['from'], 'from') : Carbon::now()->utc();
            $hasta = ! empty($datos['to']) ? ValuationTime::parseInstant((string) $datos['to'], 'to') : $desde->copy()->addDays(60);
            if (! $hasta->gt($desde)) {
                return $this->invalido(['to' => ['El final del rango tiene que ser posterior al inicio.']]);
            }
            if ($desde->diffInSeconds($hasta) > self::MAX_BLOCK_RANGE_DAYS * 86400) {
                throw ValuationException::rangeTooWide(self::MAX_BLOCK_RANGE_DAYS);
            }

            return response()->json(['ok' => true, 'data' => $this->editor->blocks($trainerId, $desde, $hasta)]);
        });
    }

    /** POST /api/admin/valuations/trainers/{id}/blocks — 409 si pisa citas activas, salvo `force`. */
    public function storeBlock(Request $request, int $trainerId): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($request, $trainerId): JsonResponse {
            $access->require(ValuationAccess::MANAGE_AVAILABILITY);
            $this->exigirEntrenador($trainerId);

            $datos = $this->validar($request, [
                'starts_at' => ['required', 'string', 'max:40'],
                'ends_at' => ['required', 'string', 'max:40'],
                'kind' => ['required', Rule::in(ValuationAvailabilityBlock::KINDS)],
                'reason' => ['nullable', 'string', 'max:255', new PlainText],
                'force' => ['nullable', 'boolean'],
            ]);

            $r = $this->editor->createBlock($access, $trainerId, $datos, $request);

            return response()->json([
                'ok' => true,
                'data' => ValuationAvailabilityEditor::presentBlock($r['block']),
                // Con `force`: las citas que el bloqueo pisa. NO se cancelaron.
                'overlapping_appointments' => $r['overlapping'],
            ], 201);
        });
    }

    /** DELETE /api/admin/valuations/trainers/{id}/blocks/{blockId} */
    public function destroyBlock(Request $request, int $trainerId, int $blockId): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($request, $trainerId, $blockId): JsonResponse {
            $access->require(ValuationAccess::MANAGE_AVAILABILITY);
            $this->exigirEntrenador($trainerId);
            $this->editor->deleteBlock($trainerId, $blockId, $request);

            return response()->json(['ok' => true, 'data' => ['id' => $blockId]]);
        });
    }

    /** @return array{rules: list<array<string,mixed>>, blocks_next_60_days: list<array<string,mixed>>} */
    private function disponibilidad(int $trainerId): array
    {
        return [
            'rules' => $this->editor->rules($trainerId),
            'blocks_next_60_days' => $this->editor->upcomingBlocks($trainerId)
                ->map(fn (ValuationAvailabilityBlock $b) => ValuationAvailabilityEditor::presentBlock($b))
                ->values()
                ->all(),
        ];
    }

    private function exigirLectura(ValuationAccess $access, int $trainerId): void
    {
        if (! $access->canReadAvailability($trainerId)) {
            throw ValuationException::forbidden('Solo quien gestiona la disponibilidad, o el propio entrenador, puede verla.');
        }
        $this->exigirEntrenador($trainerId);
    }

    private function exigirEntrenador(int $trainerId): void
    {
        if (! Trainer::query()->whereKey($trainerId)->exists()) {
            throw ValuationException::notFound('El entrenador');
        }
    }
}
