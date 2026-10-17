<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ValuationException;
use App\Http\Controllers\Concerns\ValuationResponses;
use App\Http\Controllers\Controller;
use App\Models\ValuationSetting;
use App\Services\Audit\AuditTrail;
use App\Services\Valuations\ValuationAccess;
use App\Services\Valuations\ValuationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ajustes de la agenda de valoraciones (contrato §1 y §4, «Ajustes»).
 *
 * Leer: `manage_settings`, o `view` en solo lectura. Escribir: solo
 * `manage_settings` —que el Administrador NO recibe por defecto—, validado
 * ENTERO y auditado con el antes y el después (son números y casillas, nada
 * personal).
 */
class ValuationSettingsController extends Controller
{
    use ValuationResponses;

    public function __construct(
        private readonly ValuationSettings $settings,
        private readonly AuditTrail $audit,
    ) {}

    /** GET /api/admin/valuations/settings */
    public function show(Request $request): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access): JsonResponse {
            $puedeEditar = $access->can(ValuationAccess::MANAGE_SETTINGS);
            if (! $puedeEditar && ! $access->can(ValuationAccess::VIEW) && ! $access->can(ValuationAccess::VIEW_ALL)) {
                throw ValuationException::forbidden();
            }

            return response()->json([
                'ok' => true,
                'data' => $this->settings->present($this->settings->current()),
                'meta' => ['can_edit' => $puedeEditar],
            ]);
        });
    }

    /** PUT /api/admin/valuations/settings — todas las claves, validadas juntas. */
    public function update(Request $request): JsonResponse
    {
        return $this->conPersona($request, function (ValuationAccess $access) use ($request): JsonResponse {
            $access->require(ValuationAccess::MANAGE_SETTINGS);
            $valores = $this->settings->validate($request->all());

            $fila = DB::transaction(function () use ($access, $valores, $request): ValuationSetting {
                $fila = ValuationSetting::query()->whereKey($this->settings->current()->id)->lockForUpdate()->firstOrFail();
                $previo = $fila->getOriginal();
                $fila->fill($valores);
                $fila->updated_by_admin_id = $access->actor->id;
                $fila->save();

                $this->audit->record($request, [
                    'action' => 'update', 'module' => 'Valoraciones', 'entity' => 'ajustes_valoraciones',
                    'entity_id' => $fila->id, 'summary' => 'Cambió los ajustes de la agenda de valoraciones',
                    'changes' => $this->audit->changesOf($fila, $previo, ValuationSetting::FIELDS),
                ]);

                return $fila;
            });

            return response()->json([
                'ok' => true,
                'data' => $this->settings->present($fila->fresh()),
                'meta' => ['can_edit' => true],
            ]);
        });
    }
}
