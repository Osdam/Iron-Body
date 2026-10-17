<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\ValuationException;
use App\Models\Admin;
use App\Services\Valuations\ValuationAccess;
use App\Support\Access\AdminActor;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Forma común de las respuestas de la agenda de valoraciones (contrato §4):
 * éxito `{ok:true, data, …}`, error `{ok:false, code, message, errors?}`.
 *
 * La validación no usa el 422 por defecto de Laravel (`{message, errors}`, sin
 * `ok` ni `code`): el CRM distingue los errores por `code`.
 */
trait ValuationResponses
{
    /**
     * Ejecuta la acción con una PERSONA identificada y traduce los rechazos del
     * dominio. El token compartido de automatizaciones no es nadie: aquí no
     * entra aunque el mapa le dejara pasar.
     *
     * @param  Closure(ValuationAccess): JsonResponse  $accion
     */
    protected function conPersona(Request $request, Closure $accion): JsonResponse
    {
        $actor = AdminActor::from($request);
        if (! $actor instanceof Admin) {
            return $this->fallo(new ValuationException('La agenda de valoraciones exige una sesión de administrador.', 'forbidden', 403));
        }

        try {
            return $accion(new ValuationAccess($actor));
        } catch (ValuationException $e) {
            return $this->fallo($e);
        } catch (ValidationException $e) {
            return $this->invalido($e->errors());
        }
    }

    /**
     * @param  array<string, mixed>  $reglas
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function validar(Request $request, array $reglas): array
    {
        return Validator::make($request->all(), $reglas)->validate();
    }

    protected function fallo(ValuationException $e): JsonResponse
    {
        return response()->json(array_merge([
            'ok' => false,
            'code' => $e->errorCode,
            'message' => $e->getMessage(),
        ], $e->extra), $e->status);
    }

    /** @param  array<string, list<string>>  $errores */
    protected function invalido(array $errores): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'validation_failed',
            'message' => 'Hay datos que corregir.',
            'errors' => $errores,
        ], 422);
    }
}
