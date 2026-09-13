<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditTrail;
use App\Services\Exports\ExportCatalog;
use App\Services\Exports\ExportColumn;
use App\Services\Exports\ExportWriter;
use App\Support\Access\AdminActor;
use App\Support\Access\CrmPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Módulo de exportación: elegir QUÉ (conjunto, columnas y filtros) y CÓMO
 * (formato), y descargarlo.
 *
 *   GET /api/admin/exports            → lo que esta sesión puede exportar
 *   GET /api/admin/exports/members    → descarga de socios      (members.export)
 *   GET /api/admin/exports/payments   → descarga de cobros      (payments.export)
 *
 * Exportar es un permiso PROPIO, no «ver». Mirar la lista de socios en
 * pantalla y llevarse a todos en un fichero —documento, teléfono y correo
 * incluidos— son actos distintos, y el segundo es la forma más directa de sacar
 * datos personales del sistema. Por eso cada descarga queda también en la
 * auditoría, con qué columnas y filtros se pidieron.
 */
class ExportController extends Controller
{
    /**
     * Tope de filas por descarga. No es un límite técnico —se escribe por
     * lotes— sino una barandilla: más que esto casi siempre es un filtro que se
     * olvidó, y es mejor decirlo que generar un fichero que nadie va a abrir.
     */
    public const MAX_ROWS = 100_000;

    public function __construct(
        private readonly ExportCatalog $catalog,
        private readonly ExportWriter $writer,
    ) {}

    /** GET /api/admin/exports */
    public function index(Request $request): JsonResponse
    {
        $admin = AdminActor::from($request);

        return response()->json([
            'ok' => true,
            'datasets' => array_map(
                fn ($d) => $d->describe(),
                $this->catalog->allowedFor($admin),
            ),
            'formats' => $this->writer->availableFormats(),
            'max_rows' => self::MAX_ROWS,
        ]);
    }

    /** GET /api/admin/exports/{dataset} — el dataset lo fija la ruta. */
    public function download(Request $request, string $dataset)
    {
        $ds = $this->catalog->get($dataset);
        abort_if($ds === null, 404);

        // La ruta ya exige el permiso (AuthorizationMap). Se comprueba otra vez
        // aquí porque este controlador sirve varios datasets y un error al
        // registrar una ruta nueva no debe abrir la descarga.
        $admin = AdminActor::from($request);
        if (! CrmPermission::allows($admin, $ds->permission())) {
            return response()->json([
                'ok' => false,
                'code' => 'forbidden',
                'message' => 'No tienes permiso para exportar '.mb_strtolower($ds->label()).'.',
                'required_permission' => $ds->permission(),
            ], 403);
        }

        // Validator a mano y no $request->validate(): este endpoint devuelve un
        // fichero, así que el cliente no siempre pide JSON, y en ese caso Laravel
        // responde a un error con una REDIRECCIÓN 302. Una descarga que falla
        // tiene que decir por qué, siempre en JSON.
        $validator = Validator::make($request->all(), [
            'format' => ['required', Rule::in($this->writer->formatKeys())],
            'columns' => ['required', 'array', 'min:1'],
            // Lista blanca: solo columnas que el dataset declara. Nada del
            // cliente llega a un select sobre la tabla.
            'columns.*' => ['string', 'distinct', Rule::in($ds->columnKeys())],
            ...$ds->filterRules(),
        ], [
            'columns.required' => 'Elige al menos una columna.',
            'columns.min' => 'Elige al menos una columna.',
            'columns.*.in' => 'La columna «:input» no existe en este conjunto.',
            'format.in' => 'Ese formato no está disponible en este servidor.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'code' => 'invalid_export',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $filtros = array_intersect_key($data, $ds->filterRules());
        $columnas = $ds->select($data['columns']);
        $query = $ds->query($filtros);

        $filas = (clone $query)->count();
        if ($filas > self::MAX_ROWS) {
            return response()->json([
                'ok' => false,
                'code' => 'too_many_rows',
                'message' => sprintf(
                    'Son %s registros y el máximo por descarga es %s. Acota con los filtros.',
                    number_format($filas, 0, ',', '.'),
                    number_format(self::MAX_ROWS, 0, ',', '.'),
                ),
            ], 422);
        }

        // Se traza ANTES de generar: si la descarga se corta a medias, igual
        // queda constancia de que se pidió. La traza no lleva ni un dato de las
        // filas, solo qué se pidió; la auditoría no es sitio para documentos.
        app(AuditTrail::class)->record($request, [
            'action' => 'export',
            'module' => 'exports',
            'entity' => $ds->key(),
            'summary' => sprintf(
                'Exportó %s %s en %s',
                number_format($filas, 0, ',', '.'),
                mb_strtolower($ds->label()),
                strtoupper($data['format']),
            ),
            'metadata' => [
                'format' => $data['format'],
                'rows' => $filas,
                'columns' => array_map(fn (ExportColumn $c) => $c->key, $columnas),
                'personal_columns' => array_values(array_map(
                    fn (ExportColumn $c) => $c->key,
                    array_filter($columnas, fn (ExportColumn $c) => $c->personal),
                )),
                'filters' => array_filter($filtros, fn ($v) => $v !== null && $v !== '' && $v !== 'all'),
            ],
        ]);

        $nombre = sprintf('iron-body-%s-%s', $ds->fileSlug(), now('America/Bogota')->format('Y-m-d'));

        return $this->writer->download($query, $columnas, $data['format'], $nombre);
    }
}
