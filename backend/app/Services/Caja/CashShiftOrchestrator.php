<?php

namespace App\Services\Caja;

use App\Enums\CashShiftType;
use App\Exceptions\CashShiftException;
use App\Models\Admin;
use App\Support\Access\CrmPermission;

/**
 * Apertura y cierre de LAS DOS cajas en una sola pulsación.
 *
 * Ergonomía, no contabilidad: sigue habiendo dos turnos, dos filas y dos
 * arqueos. No existe una "caja general" y este orquestador no crea ninguna.
 *
 * Deliberadamente NO envuelve ambas operaciones en una transacción común. Si
 * la caja de productos cierra bien y la del gimnasio falla, revertir el cierre
 * de productos sería destruir un arqueo válido por un problema ajeno. Cada
 * operación es atómica en sí misma —lo garantiza CashShiftService— y el
 * resultado dice exactamente qué pasó con cada una.
 *
 * Los permisos se comprueban POR CAJA: operar la segunda exige el permiso de la
 * segunda. Marcar la casilla no concede nada.
 */
class CashShiftOrchestrator
{
    public function __construct(private readonly CashShiftService $shifts) {}

    /**
     * @param  CashShiftType[]  $types  en el orden en que deben ejecutarse
     * @return array<string, array{result: string, message: string, shift: array<string,mixed>|null}>
     */
    public function open(Admin $admin, array $types): array
    {
        return $this->run($types, fn (CashShiftType $t) => $this->shifts->open($admin, $t), $admin, 'opened');
    }

    /**
     * @param  CashShiftType[]  $types
     * @return array<string, array{result: string, message: string, shift: array<string,mixed>|null}>
     */
    public function close(Admin $admin, array $types, ?string $note, ?string $forcedReason): array
    {
        return $this->run(
            $types,
            fn (CashShiftType $t) => $this->shifts->close(
                $admin,
                $t,
                $note,
                CrmPermission::allows($admin, $t->managePermission()),
                $forcedReason,
            ),
            $admin,
            'closed',
        );
    }

    /**
     * Ejecuta la operación sobre cada caja de forma independiente y acumula el
     * desenlace de cada una. Una excepción en la segunda no afecta a la primera.
     *
     * @param  CashShiftType[]  $types
     * @return array<string, array{result: string, message: string, shift: array<string,mixed>|null}>
     */
    private function run(array $types, callable $accion, Admin $admin, string $exito): array
    {
        $salida = [];

        foreach ($types as $type) {
            // El permiso se exige aquí, por caja, y no en la ruta: la ruta solo
            // conoce la caja principal. Sin esto, quien puede operar productos
            // cerraría el gimnasio marcando una casilla.
            if (! CrmPermission::allows($admin, $type->operatePermission())) {
                $salida[$type->value] = [
                    'result' => 'forbidden',
                    'message' => "No tienes permiso para operar la caja {$type->label()}.",
                    'shift' => null,
                ];

                continue;
            }

            try {
                $shift = $accion($type);
                $salida[$type->value] = [
                    'result' => $exito,
                    'message' => "Caja {$type->label()}: operación completada.",
                    'shift' => $shift->toCrmArray(withTotals: true),
                ];
            } catch (CashShiftException $e) {
                // "Ya estaba abierta" / "no hay turno abierto" no son fallos de
                // la operación doble: son estados esperados cuando una de las
                // dos cajas ya estaba como se pretendía dejarla.
                $yaEstaba = in_array($e->code_, ['shift_already_open', 'no_open_shift'], true);
                $salida[$type->value] = [
                    'result' => $yaEstaba ? 'already_'.($exito === 'opened' ? 'open' : 'closed') : 'error',
                    'message' => $e->getMessage(),
                    'shift' => null,
                ];
            }
        }

        return $salida;
    }
}
