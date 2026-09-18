<?php

namespace App\Http\Controllers\Api\Admin\Reports;

use App\Services\Audit\AuditTrail;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Descarga de informes: Excel, CSV o PDF, con los filtros que se estén viendo.
 *
 * Cada descarga queda AUDITADA. Un informe financiero o la actividad del
 * sistema salen del servidor en un fichero que después vive fuera de él; que
 * conste quién se lo llevó y con qué filtros es parte de tratarlo con respeto.
 *
 * La validación se hace a mano y no con `$request->validate()`: en una descarga
 * —una petición GET que no es JSON— Laravel respondería con una redirección 302
 * y el navegador se descargaría el HTML del login en vez de avisar del error.
 */
class ExportController extends ReportsController
{
    public function index(): JsonResponse
    {
        $exportador = app(ReportExporter::class);

        return response()->json([
            'datasets' => ReportExporter::datasets(),
            'formats' => $exportador->formats(),
        ]);
    }

    public function download(Request $request, string $dataset): Response
    {
        $exportador = app(ReportExporter::class);
        $formatos = array_column($exportador->formats(), 'key');

        $validator = Validator::make(
            array_merge($request->query(), ['dataset' => $dataset]),
            array_merge($this->baseRules(), [
                'dataset' => 'required|in:'.implode(',', array_keys(ReportExporter::DATASETS)),
                'format' => 'required|in:'.implode(',', $formatos),
                'days' => 'nullable|integer|min:0|max:120',
                'bucket' => 'nullable|in:1_3,4_7,8_15,16_30,30_plus',
                'module' => 'nullable|string|max:80',
                'action' => 'nullable|string|max:40',
            ]),
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => 'No se pudo preparar la descarga.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $rango = $this->range($request);
        $filtros = array_merge($this->filters($request), array_filter([
            'module' => $request->query('module'),
            'action' => $request->query('action'),
        ]));

        $respuesta = $exportador->download($dataset, (string) $request->query('format'), $rango, $filtros, [
            'days' => $request->query('days'),
            'bucket' => $request->query('bucket'),
        ]);

        app(AuditTrail::class)->record($request, [
            'action' => 'export',
            'module' => 'Informes',
            'entity' => 'informe',
            'entity_id' => $dataset,
            'target_name' => ReportExporter::DATASETS[$dataset]['label'],
            'summary' => 'Descargó el informe de '.mb_strtolower(ReportExporter::DATASETS[$dataset]['label']),
            'metadata' => [
                'format' => $request->query('format'),
                'range' => $rango->toArray(),
                'filters' => $filtros,
            ],
        ]);

        return $respuesta;
    }
}
