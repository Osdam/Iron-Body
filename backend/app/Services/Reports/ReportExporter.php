<?php

namespace App\Services\Reports;

use App\Services\Exports\ExportColumn;
use App\Services\Exports\ExportWriter;
use Carbon\CarbonImmutable;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Llevarse un informe: Excel, CSV o PDF.
 *
 * LO QUE SE EXPORTA ES LO QUE SE ESTÁ VIENDO. El fichero se arma con los mismos
 * servicios y los mismos filtros que pintaron la pantalla, no con una consulta
 * paralela: si no, el Excel diría una cosa y el CRM otra, y la discusión de la
 * reunión sería sobre cuál de los dos mentía.
 *
 * Y lleva SU CONTEXTO dentro —qué informe es, de qué periodo y con qué filtros,
 * y cuándo se generó—, porque un fichero de cifras sin fechas acaba reenviado
 * por correo tres semanas después sin que nadie sepa qué contenía.
 */
final class ReportExporter
{
    /** Los informes que se pueden descargar, con su nombre y su permiso. */
    public const DATASETS = [
        'transactions' => ['label' => 'Transacciones', 'file' => 'transacciones', 'permission' => 'reports.view'],
        'sales' => ['label' => 'Ventas de planes', 'file' => 'ventas', 'permission' => 'reports.view'],
        'expiring' => ['label' => 'Membresías por vencer', 'file' => 'por-vencer', 'permission' => 'reports.view'],
        'expired' => ['label' => 'Membresías vencidas', 'file' => 'vencidas', 'permission' => 'reports.view'],
        'staff' => ['label' => 'Rendimiento del equipo', 'file' => 'equipo', 'permission' => 'reports.view'],
        'activity' => ['label' => 'Actividad del sistema', 'file' => 'actividad', 'permission' => 'audit.view'],
    ];

    /** Cuántas filas como mucho lleva una exportación de informe. */
    private const MAX_ROWS = 5000;

    public function __construct(
        private readonly ExportWriter $writer,
        private readonly ReportPdf $pdf,
    ) {}

    /** @return list<array{key: string, label: string, permission: string}> */
    public static function datasets(): array
    {
        return array_map(
            fn (string $clave, array $meta) => [
                'key' => $clave,
                'label' => $meta['label'],
                'permission' => $meta['permission'],
            ],
            array_keys(self::DATASETS),
            self::DATASETS,
        );
    }

    /** Formatos que este servidor puede producir de verdad. */
    public function formats(): array
    {
        $formatos = $this->writer->availableFormats();

        if ($this->pdf->available()) {
            $formatos[] = [
                'key' => 'pdf',
                'label' => 'PDF',
                'description' => 'Documento listo para imprimir o enviar, con el periodo y los filtros en la cabecera.',
            ];
        }

        return $formatos;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function download(
        string $dataset,
        string $format,
        ReportRange $range,
        array $filters,
        array $options = [],
    ): StreamedResponse|BinaryFileResponse {
        if (! isset(self::DATASETS[$dataset])) {
            throw new RuntimeException("Informe desconocido: {$dataset}");
        }

        [$columnas, $filas] = $this->build($dataset, $range, $filters, $options);
        $meta = $this->meta($dataset, $range, $filters);
        $nombre = 'iron-body_'.self::DATASETS[$dataset]['file'].'_'.$range->from->toDateString().'_'.$range->to->toDateString();

        if ($format === 'pdf') {
            return $this->pdf->render(self::DATASETS[$dataset]['label'], $meta, $columnas, $filas, $nombre.'.pdf');
        }

        return $this->writer->download($filas, $columnas, $format, $nombre, $meta);
    }

    /**
     * Las columnas y las filas de cada informe.
     *
     * @return array{0: list<ExportColumn>, 1: list<array<string, mixed>>}
     */
    private function build(string $dataset, ReportRange $range, array $filters, array $options): array
    {
        $texto = ExportColumn::TYPE_TEXT;
        $numero = ExportColumn::TYPE_NUMBER;
        $fecha = ExportColumn::TYPE_DATE;
        $col = fn (string $clave, string $etiqueta, string $grupo, string $campo, string $tipo = ExportColumn::TYPE_TEXT) => new ExportColumn(
            $clave, $etiqueta, $grupo, fn (array $f) => $f[$campo] ?? null, $tipo,
        );

        return match ($dataset) {
            'transactions' => [
                [
                    $col('date', 'Fecha', 'Operación', 'date', $fecha),
                    $col('source', 'Tipo', 'Operación', 'source_label'),
                    $col('concept', 'Concepto', 'Operación', 'concept'),
                    $col('amount', 'Monto', 'Operación', 'amount', $numero),
                    $col('method', 'Medio de pago', 'Operación', 'method_label'),
                    $col('channel', 'Canal', 'Operación', 'channel_label'),
                    $col('member', 'Socio', 'Socio', 'member_name'),
                    $col('document', 'Documento', 'Socio', 'member_document'),
                    $col('staff', 'Registró', 'Responsable', 'staff_name'),
                    $col('role', 'Rol', 'Responsable', 'staff_role'),
                    $col('shift', 'Turno de caja', 'Responsable', 'cash_shift_id', $numero),
                    $col('reference', 'Referencia', 'Operación', 'reference'),
                ],
                (new MoneyLedger($range, $filters))->rows(1, self::MAX_ROWS)['data'],
            ],

            'sales' => [
                [
                    $col('date', 'Fecha', 'Venta', 'date', $fecha),
                    $col('member', 'Socio', 'Socio', 'member_name'),
                    $col('document', 'Documento', 'Socio', 'member_document'),
                    $col('plan', 'Plan', 'Venta', 'plan'),
                    $col('kind', 'Tipo', 'Venta', 'kind_label'),
                    $col('sold', 'Valor vendido', 'Dinero', 'sold', $numero),
                    $col('collected', 'Cobrado', 'Dinero', 'collected', $numero),
                    $col('balance', 'Saldo', 'Dinero', 'balance', $numero),
                    $col('method', 'Forma de pago', 'Dinero', 'method_label'),
                    $col('channel', 'Canal', 'Venta', 'channel_label'),
                    $col('staff', 'Registró', 'Responsable', 'staff_name'),
                    $col('role', 'Rol', 'Responsable', 'staff_role'),
                    $col('period_start', 'Membresía desde', 'Membresía', 'period_start', $fecha),
                    $col('period_end', 'Membresía hasta', 'Membresía', 'period_end', $fecha),
                ],
                (new SalesInsights($range, $filters))->rows(1, self::MAX_ROWS)['data'],
            ],

            'expiring' => [
                $this->memberColumns($fecha, $numero),
                app(MembershipInsights::class)->expiring(
                    (int) ($options['days'] ?? 7), 1, self::MAX_ROWS, (string) ($filters['search'] ?? ''),
                )['data'],
            ],

            'expired' => [
                $this->memberColumns($fecha, $numero),
                app(MembershipInsights::class)->expired(
                    $options['bucket'] ?? null, 1, self::MAX_ROWS, (string) ($filters['search'] ?? ''),
                )['data'],
            ],

            'staff' => [
                [
                    $col('name', 'Persona', 'Equipo', 'name'),
                    $col('role', 'Rol', 'Equipo', 'role'),
                    $col('collected', 'Dinero cobrado', 'Dinero', 'collected', $numero),
                    $col('operations', 'Operaciones', 'Dinero', 'operations', $numero),
                    $col('sales', 'Planes vendidos', 'Ventas', 'sales', $numero),
                    $col('sold', 'Valor vendido', 'Ventas', 'sold', $numero),
                    $col('actions', 'Acciones registradas', 'Actividad', 'actions', $numero),
                ],
                (new StaffPerformance($range))->leaderboard(),
            ],

            'activity' => [
                [
                    $col('at', 'Fecha', 'Evento', 'at', $fecha),
                    new ExportColumn('actor', 'Usuario', 'Evento', fn (array $f) => $f['actor']['name'] ?? null, $texto),
                    new ExportColumn('role', 'Rol', 'Evento', fn (array $f) => $f['actor']['role'] ?? null, $texto),
                    $col('action', 'Acción', 'Evento', 'action_label'),
                    $col('module', 'Módulo', 'Evento', 'module'),
                    $col('entity', 'Entidad', 'Evento', 'entity'),
                    $col('target', 'Sobre', 'Evento', 'target'),
                    $col('summary', 'Resumen', 'Evento', 'summary'),
                ],
                (new ActivityFeed($range, $filters))->page(1, 100)['data'],
            ],

            default => throw new RuntimeException("Informe desconocido: {$dataset}"),
        };
    }

    /** @return list<ExportColumn> */
    private function memberColumns(string $fecha, string $numero): array
    {
        return [
            new ExportColumn('name', 'Socio', 'Socio', fn (array $f) => $f['name'] ?? null),
            new ExportColumn('document', 'Documento', 'Socio', fn (array $f) => $f['document'] ?? null, personal: true),
            new ExportColumn('phone', 'Teléfono', 'Socio', fn (array $f) => $f['phone'] ?? null, personal: true),
            new ExportColumn('plan', 'Plan', 'Membresía', fn (array $f) => $f['plan'] ?? null),
            new ExportColumn('end', 'Vencimiento', 'Membresía', fn (array $f) => $f['end'] ?? null, $fecha),
            new ExportColumn('days_left', 'Días restantes', 'Membresía', fn (array $f) => $f['days_left'] ?? null, $numero),
            new ExportColumn('days_overdue', 'Días vencida', 'Membresía', fn (array $f) => $f['days_overdue'] ?? null, $numero),
            new ExportColumn('last_payment_at', 'Último pago', 'Último pago', fn (array $f) => isset($f['last_payment_at']) ? substr((string) $f['last_payment_at'], 0, 10) : null, $fecha),
            new ExportColumn('last_payment_amount', 'Monto', 'Último pago', fn (array $f) => $f['last_payment_amount'] ?? null, $numero),
        ];
    }

    /**
     * La cabecera del fichero: qué informe, de qué periodo, con qué filtros y
     * cuándo se generó.
     *
     * @return list<string>
     */
    private function meta(string $dataset, ReportRange $range, array $filters): array
    {
        $lineas = [
            'Iron Body · '.self::DATASETS[$dataset]['label'],
            'Periodo: '.$range->from->toDateString().' a '.$range->to->toDateString().' ('.$range->toArray()['label'].')',
            'Generado: '.CarbonImmutable::now(ReportRange::TZ)->format('Y-m-d H:i').' (hora de Colombia)',
        ];

        $aplicados = [];
        foreach ($filters as $clave => $valor) {
            if ($valor === null || $valor === '' || $valor === 'all') {
                continue;
            }
            $aplicados[] = self::filterLabel((string) $clave).': '.$valor;
        }

        if ($aplicados !== []) {
            $lineas[] = 'Filtros · '.implode(' · ', $aplicados);
        }

        return $lineas;
    }

    private static function filterLabel(string $key): string
    {
        return match ($key) {
            'staff' => 'Responsable',
            'plan' => 'Plan',
            'method' => 'Medio de pago',
            'channel' => 'Canal',
            'source' => 'Tipo',
            'member_id' => 'Socio',
            'search' => 'Búsqueda',
            'kind' => 'Tipo de venta',
            'module' => 'Módulo',
            'action' => 'Acción',
            default => $key,
        };
    }
}
