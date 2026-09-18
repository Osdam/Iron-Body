<?php

namespace App\Http\Controllers\Api\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\MoneyLedger;
use App\Services\Reports\ReportRange;
use App\Support\Caja\PaymentMethodKind;
use Illuminate\Http\Request;

/**
 * Lo que comparten todos los informes: el periodo y los filtros.
 *
 * Se resuelven aquí y no en cada endpoint para que «este mes» signifique lo
 * mismo en las siete secciones. Cuando cada pantalla resolvía su propio rango,
 * dos tarjetas de la misma página podían contar días distintos.
 */
abstract class ReportsController extends Controller
{
    /** Reglas del periodo y de los filtros que acepta cualquier informe. */
    protected function baseRules(): array
    {
        return [
            'preset' => 'nullable|in:'.implode(',', ReportRange::PRESETS),
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'staff' => 'nullable|string|max:120',
            'plan' => 'nullable|string|max:120',
            'method' => 'nullable|in:all,'.implode(',', PaymentMethodKind::values()),
            'channel' => 'nullable|in:all,gym,app',
            'source' => 'nullable|in:all,'.implode(',', MoneyLedger::SOURCES),
            'member_id' => 'nullable|integer|min:1',
            'kind' => 'nullable|in:all,new,renewal',
            'search' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }

    protected function range(Request $request): ReportRange
    {
        return ReportRange::fromRequest($request->only(['preset', 'from', 'to']));
    }

    /**
     * Filtros ya limpios. `all` y las cadenas vacías se descartan aquí para que
     * los servicios no tengan que distinguir entre «sin filtro» y «filtro que
     * no filtra».
     *
     * @return array<string, mixed>
     */
    protected function filters(Request $request): array
    {
        $filtros = [];
        foreach (['staff', 'plan', 'method', 'channel', 'source', 'search', 'kind'] as $clave) {
            $valor = trim((string) $request->query($clave, ''));
            if ($valor !== '' && $valor !== 'all') {
                $filtros[$clave] = $valor;
            }
        }

        if ($miembro = (int) $request->query('member_id', 0)) {
            $filtros['member_id'] = $miembro;
        }

        return $filtros;
    }

    protected function page(Request $request): int
    {
        return max(1, (int) $request->query('page', 1));
    }

    protected function perPage(Request $request, int $default = 25): int
    {
        return max(1, min(100, (int) $request->query('per_page', $default)));
    }

    /**
     * Variación entre dos cifras del mismo indicador.
     *
     * Sin base no hay porcentaje: pasar de 0 a 500.000 no es «un aumento del
     * infinito por ciento», es que antes no había nada. Se dice así.
     *
     * @return array{current: float, previous: float, delta: float, percent: float|null}
     */
    protected function variation(float $actual, float $anterior): array
    {
        return [
            'current' => round($actual, 2),
            'previous' => round($anterior, 2),
            'delta' => round($actual - $anterior, 2),
            'percent' => $anterior > 0 ? round(($actual - $anterior) / $anterior * 100, 1) : null,
        ];
    }
}
