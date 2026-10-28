<?php

namespace App\Services\Caja;

use App\Exceptions\ReceiptException;
use App\Models\Receipt;
use App\Models\ReceiptAgentTicket;
use App\Models\ReceiptPrint;
use App\Models\ReceiptStation;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Normalizer;

/**
 * LA TIRILLA COMO LÍNEAS DE TEXTO, maquetada en el servidor para el servicio
 * local de impresión (ESC/POS). Contrato: CONTRATO_AGENTE_IMPRESION.md §2.
 *
 * Es un PORT de lo que ya imprime el CRM —`thermal-receipt.ts` (receiptLines,
 * wrap, pair, center, receiptMoney, qty) y `receipt-snapshot.ts`
 * (snapshotToThermal)— para que el papel diga LO MISMO venga por donde venga.
 * Mismo texto, línea a línea, a 32 columnas; las pruebas «golden» de
 * ReceiptTextLayoutTest son los casos del spec del CRM con sus mismos textos.
 *
 * Diferencias DELIBERADAS con el TS, y solo estas:
 *
 *  · Estilos: `normal | bold | title`. Lo que en el TS sería 'center' sale como
 *    'normal' ya centrado con espacios (el TS tampoco lo usa en la tirilla).
 *  · El título (business.name) se parte a `columns` y no a 22: en ESC/POS sale
 *    a doble ALTO, que no estrecha la línea (en el navegador salía a 1,4×).
 *  · `columns` admite 32 (Fuente A) o 42 (Fuente B).
 *  · Garantías que el TS no necesitaba porque dibujaba en HTML: cada línea es
 *    NFC, mide ≤ `columns` PUNTOS DE CÓDIGO (el TS cuenta unidades UTF-16; solo
 *    difiere con caracteres fuera del plano básico, p. ej. emojis) y no lleva
 *    caracteres de control (U+0000–U+001F, U+007F–U+009F), que el texto
 *    convertiría en comandos de la impresora. Un control se sustituye por un
 *    espacio, igual que el TS ya hacía con saltos de línea y tabuladores.
 *  · «…» sale como «...» (el servicio lo expande; medido ya expandido).
 *  · Ninguna marca combinante suelta. PRODUCCIÓN NO TIENE ext-intl: allí la
 *    normalización es {@see nfcSinIntl()} (compone el español y quita las
 *    marcas que sobren), y las pruebas lo fuerzan con `new ReceiptTextLayout(useIntl: false)`.
 *
 * Nada se calcula ni se inventa: importes, fechas, nombres y leyenda vienen
 * congelados en `snapshot`; lo vivo (anulación, factura) lo da
 * {@see ReceiptPresenter::live()} y el rótulo de la copia
 * {@see ReceiptPrints::copyLabel()}.
 */
final class ReceiptTextLayout
{
    public const COLUMNS = ReceiptStation::COLUMNS;

    public const DEFAULT_COLUMNS = 32;

    public const STYLE_NORMAL = 'normal';

    public const STYLE_BOLD = 'bold';

    public const STYLE_TITLE = 'title';

    /** §2: 1..400 líneas por documento. */
    public const MAX_LINES = 400;

    /**
     * Marca de los recibos de MUESTRA (§5.4): número, cliente y rótulo de copia
     * que ningún recibo real puede llevar. El rótulo sale arriba y abajo como
     * «*** PRUEBA · SIN VALOR ***»: el papel no puede pasar por uno de verdad.
     */
    public const SAMPLE_NUMBER = 'PRUEBA';

    public const SAMPLE_CUSTOMER = 'CLIENTE DE PRUEBA';

    public const SAMPLE_COPY_LABEL = 'PRUEBA · SIN VALOR';

    private const DEFAULT_LEGEND = 'Documento no válido como factura electrónica';

    private const DEFAULT_FOOTER = '¡Gracias por entrenar con nosotros!';

    /**
     * Estados con los que el papel ya no respalda dinero. Son EXACTAMENTE los de
     * `receipt-snapshot.ts` (ANULADA): el mismo recibo no puede decir «anulada»
     * por el navegador y no decirlo por el servicio.
     */
    private const VOIDED_STATUSES = ['cancelled', 'refunded', 'reversed', 'failed', 'voided', 'annulled'];

    /** De los anteriores, los que son una DEVOLUCIÓN (se imprimen «DEVUELTA»). */
    private const REFUNDED_STATUSES = ['refunded'];

    /**
     * Marcas combinantes: diacríticos (U+0300–U+036F) y sus extensiones
     * (U+1AB0–U+1AFF, U+1DC0–U+1DFF), las de símbolos (U+20D0–U+20FF) y las
     * de medio carácter (U+FE20–U+FE2F). Una que quede SUELTA cuenta una
     * columna y el servicio la imprimiría como «?».
     */
    private const COMBINING = '\x{0300}-\x{036F}\x{1AB0}-\x{1AFF}\x{1DC0}-\x{1DFF}\x{20D0}-\x{20FF}\x{FE20}-\x{FE2F}';

    /**
     * Las combinaciones que de verdad llegan en español, descompuestas → su
     * carácter compuesto. Es lo único que el respaldo sin ext-intl sabe componer.
     */
    private const SPANISH_COMPOSITIONS = [
        "a\u{0301}" => 'á', "e\u{0301}" => 'é', "i\u{0301}" => 'í', "o\u{0301}" => 'ó', "u\u{0301}" => 'ú',
        "A\u{0301}" => 'Á', "E\u{0301}" => 'É', "I\u{0301}" => 'Í', "O\u{0301}" => 'Ó', "U\u{0301}" => 'Ú',
        "n\u{0303}" => 'ñ', "N\u{0303}" => 'Ñ', "u\u{0308}" => 'ü', "U\u{0308}" => 'Ü',
    ];

    /** Espacios que JavaScript reconoce en `\s` y en `trim()`. */
    private const JS_SPACE = '\t\n\x{0B}\f\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /**
     * @param  bool|null  $useIntl  COSTURA para pruebas. null (el valor por
     *                              defecto, y lo que resuelve el contenedor) =
     *                              usar ext-intl si está instalada; false = forzar
     *                              el respaldo sin intl, que es lo que corre en
     *                              PRODUCCIÓN (PHP 8.3 sin ext-intl, comprobado el
     *                              17-10-2026). true solo surte efecto si intl existe.
     */
    public function __construct(private readonly ?bool $useIntl = null) {}

    // ── Documentos (§2) ─────────────────────────────────────────────────────

    /**
     * El documento de impresión de un intento: lo que el servicio manda a la
     * impresora, ya maquetado.
     *
     * @param  array{operation_status?: string|null, invoice?: array{status?: string, full_number?: string|null}|null}  $live
     * @return array{receipt_number: string, copy_label: string|null, kind: string, columns: int, feed_lines: int, cut: false, lines: list<array{text: string, style: string}>}
     */
    public function printJob(Receipt $recibo, ReceiptPrint $intento, ?string $copyLabel, array $live, int $columns, int $feedLines): array
    {
        $lineas = $this->forReceipt($recibo, $live, $copyLabel, $columns);

        return $this->document($recibo->number, $copyLabel, $intento->kind, $columns, $feedLines, $lineas);
    }

    /**
     * El documento de la página de prueba (§5.4).
     *
     * `minimal` y `full` son páginas de diagnóstico. Las `sample_*` son recibos
     * SINTÉTICOS maquetados por este mismo código desde un snapshot inventado en
     * memoria ({@see sampleReceipt()}): prueban el papel real sin crear una venta,
     * un cobro ni un recibo. Llevan número «PRUEBA» y el rótulo «PRUEBA · SIN
     * VALOR».
     *
     * @return array{receipt_number: string|null, copy_label: string|null, kind: string, columns: int, feed_lines: int, cut: false, lines: list<array{text: string, style: string}>}
     */
    public function testJob(string $variant, int $columns, string $codepage, ?string $stationName, int $feedLines, ?CarbonInterface $now = null, ?string $cashier = null): array
    {
        $now ??= Carbon::now();

        if (str_starts_with($variant, 'sample_')) {
            $lineas = $this->lines($this->thermal($this->sampleReceipt($variant, $now, $cashier), self::SAMPLE_COPY_LABEL), $columns);

            return $this->document(self::SAMPLE_NUMBER, self::SAMPLE_COPY_LABEL, 'test', $columns, $feedLines, $lineas);
        }

        $lineas = $variant === 'full'
            ? $this->fullTestPage($columns, $codepage, $stationName, $now)
            : $this->minimalTestPage($columns);

        return $this->document(null, null, 'test', $columns, $feedLines, $lineas);
    }

    /**
     * @param  list<array{text: string, style: string}>  $lineas
     * @return array<string, mixed>
     */
    private function document(?string $numero, ?string $copyLabel, string $kind, int $columns, int $feedLines, array $lineas): array
    {
        $this->assertPrintable($lineas, $columns);

        return [
            'receipt_number' => $numero,
            'copy_label' => $copyLabel,
            'kind' => $kind,
            'columns' => $columns,
            'feed_lines' => max(0, min(ReceiptStation::MAX_FEED_LINES, $feedLines)),
            // La DIG-58IIK no declara cortador: en v1 nunca se corta.
            'cut' => false,
            'lines' => $lineas,
        ];
    }

    /**
     * Lo que el contrato GARANTIZA al servicio, comprobado antes de entregarlo.
     * Si algo no lo cumple es un defecto de este código: se lanza, no se recorta
     * (el servicio rechazaría el trabajo con `invalid_job`, y con razón).
     *
     * @param  list<array{text: string, style: string}>  $lineas
     */
    public function assertPrintable(array $lineas, int $columns): void
    {
        if (! in_array($columns, self::COLUMNS, true)) {
            throw new ReceiptException('Columnas no admitidas: '.$columns.'.', 'layout_invalid');
        }

        if ($lineas === [] || count($lineas) > self::MAX_LINES) {
            throw new ReceiptException('El recibo no cabe en un trabajo de impresión ('.count($lineas).' líneas).', 'job_too_long');
        }

        foreach ($lineas as $i => $linea) {
            $texto = $linea['text'];
            $valida = in_array($linea['style'], [self::STYLE_NORMAL, self::STYLE_BOLD, self::STYLE_TITLE], true)
                && mb_check_encoding($texto, 'UTF-8')
                && mb_strlen($texto, 'UTF-8') <= $columns
                && preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', $texto) === 0
                // Ni marcas sueltas (cada una sería una columna y un «?»), ni
                // «…», que el servicio expande a tres columnas…
                && preg_match('/['.self::COMBINING.']/u', $texto) === 0
                && ! str_contains($texto, "\u{2026}")
                // …y ya normalizada con el MISMO método que la produjo: en
                // producción, el respaldo sin intl.
                && $this->nfc($texto) === $texto;

            if (! $valida) {
                throw new ReceiptException('Línea '.$i.' fuera de contrato.', 'layout_invalid');
            }
        }
    }

    // ── Del recibo a la estructura (snapshotToThermal) ──────────────────────

    /**
     * Las líneas de un recibo emitido.
     *
     * @param  array<string, mixed>  $live
     * @return list<array{text: string, style: string}>
     */
    public function forReceipt(Receipt $recibo, array $live, ?string $copyLabel, int $columns = self::DEFAULT_COLUMNS): array
    {
        return $this->lines($this->thermal([
            'number' => $recibo->number,
            'cash_type' => $recibo->cash_type,
            'operation_ref' => $recibo->operation_ref,
            'snapshot' => $recibo->snapshot,
            'live' => $live,
        ], $copyLabel), $columns);
    }

    /**
     * PORT de `snapshotToThermal()`: traducción de nombres, no un cálculo. Si un
     * campo falta o llega mal formado se omite o se imprime vacío; nunca se
     * rellena con un valor inventado.
     *
     * @param  array<string, mixed>  $recibo  {number, cash_type, operation_ref, snapshot, live}
     * @return array<string, mixed> la estructura `ThermalReceipt` del TS (claves en camelCase, como allí)
     */
    public function thermal(array $recibo, ?string $copyLabel = null): array
    {
        $s = $this->obj($recibo['snapshot'] ?? null);
        $b = $this->obj($s['business'] ?? null);
        $t = $this->obj($s['totals'] ?? null);
        $live = $this->obj($recibo['live'] ?? null);
        $factura = $this->obj($live['invoice'] ?? null);
        $total = $this->num($t['total'] ?? null);

        $membresia = $this->obj($s['membership'] ?? null);
        $deuda = $this->obj($s['debt'] ?? null);

        return [
            'business' => [
                'name' => $this->text($b['name'] ?? null) ?? 'Iron Body',
                'legalName' => $this->text($b['legal_name'] ?? null),
                'nit' => $this->text($b['nit'] ?? null),
                'address' => $this->text($b['address'] ?? null),
                'phone' => $this->text($b['phone'] ?? null),
            ],
            'title' => $this->text($s['title'] ?? null),
            'number' => $this->text($recibo['number'] ?? null) ?? '—',
            'operationRef' => $this->text($s['operation_ref'] ?? null) ?? $this->text($recibo['operation_ref'] ?? null),
            'dateTime' => $this->text($s['local_datetime'] ?? null) ?? '—',
            'cashbox' => $this->text($s['cash_label'] ?? null)
                ?? (($recibo['cash_type'] ?? null) === 'gym' ? 'Caja del gimnasio' : 'Caja de productos'),
            'cashier' => $this->text($s['cashier'] ?? null),
            'customer' => $this->text($s['customer'] ?? null),
            'items' => array_values(array_map(
                fn (array $l) => [
                    'description' => $this->text($l['description'] ?? null),
                    'quantity' => $this->num($l['quantity'] ?? null) ?: 1,
                    'unitPrice' => $this->num($l['unit_price'] ?? null),
                    'discount' => $this->numOrNull($l['discount'] ?? null),
                    'total' => $this->num($l['total'] ?? null),
                ],
                array_filter(
                    $this->list($s['lines'] ?? null),
                    fn ($l) => is_array($l) && $this->text($l['description'] ?? null) !== null,
                ),
            )),
            'membership' => $membresia !== [] && $this->text($membresia['plan'] ?? null) !== null ? [
                'plan' => $this->text($membresia['plan']),
                'periodFrom' => $this->text($membresia['period_from'] ?? null),
                'periodTo' => $this->text($membresia['period_to'] ?? null),
                'upgradedFrom' => $this->text($membresia['upgraded_from'] ?? null),
            ] : null,
            'debt' => $deuda !== [] && $this->text($deuda['concept'] ?? null) !== null ? [
                'concept' => $this->text($deuda['concept']),
                'balanceBefore' => $this->num($deuda['balance_before'] ?? null),
                'balanceAfter' => $this->num($deuda['balance_after'] ?? null),
                'dueAt' => $this->text($deuda['due_at'] ?? null),
            ] : null,
            'subtotal' => ($t['subtotal'] ?? null) !== null ? $this->num($t['subtotal']) : $total,
            'discount' => $this->numOrNull($t['discount'] ?? null),
            'taxes' => array_values(array_map(
                fn (array $x) => ['label' => $this->text($x['label'] ?? null), 'amount' => $this->num($x['amount'] ?? null)],
                array_filter(
                    $this->list($t['taxes'] ?? null),
                    fn ($x) => is_array($x) && $this->text($x['label'] ?? null) !== null,
                ),
            )),
            'total' => $total,
            'paidNow' => $this->numOrNull($t['paid_now'] ?? null),
            'balanceAfter' => $this->numOrNull($t['balance_after'] ?? null),
            'payments' => array_values(array_map(
                fn ($p) => [
                    'method' => $this->text(is_array($p) ? ($p['method_label'] ?? null) : null) ?? 'Pago',
                    'amount' => $this->num(is_array($p) ? ($p['amount'] ?? null) : null),
                    'reference' => $this->text(is_array($p) ? ($p['reference'] ?? null) : null),
                ],
                array_filter($this->list($s['payments'] ?? null), fn ($p) => $this->truthy($p)),
            )),
            // La factura se mira en VIVO: pudo emitirse después de la operación.
            // Solo con número se nombra; si no, va la leyenda.
            'invoiceRef' => $this->text($factura['full_number'] ?? null),
            'legend' => $this->text($s['legend'] ?? null),
            'copyLabel' => $this->text($copyLabel),
            'voided' => in_array(mb_strtolower($this->jsString($live['operation_status'] ?? null)), self::VOIDED_STATUSES, true),
            // Devolver no es anular: el dinero entró y salió por una devolución.
            // El papel tiene que decir cuál de las dos pasó.
            'refunded' => in_array(mb_strtolower($this->jsString($live['operation_status'] ?? null)), self::REFUNDED_STATUSES, true),
        ];
    }

    // ── La tirilla (receiptLines) ───────────────────────────────────────────

    /**
     * PORT de `receiptLines()`: la tirilla completa, línea a línea.
     *
     * @param  array<string, mixed>  $r  estructura `ThermalReceipt` (ver thermal())
     * @return list<array{text: string, style: string}>
     */
    public function lines(array $r, int $cols = self::DEFAULT_COLUMNS): array
    {
        $out = [];
        $add = function (array $lineas, string $style = self::STYLE_NORMAL) use (&$out): void {
            foreach ($lineas as $texto) {
                $out[] = ['text' => $texto, 'style' => $style];
            }
        };
        $regla = function () use (&$out, $cols): void {
            $out[] = ['text' => str_repeat('-', $cols), 'style' => self::STYLE_NORMAL];
        };

        $negocio = $this->obj($r['business'] ?? null);
        $copia = $this->clean($r['copyLabel'] ?? null);
        $marcaCopia = function () use ($copia, $cols, $add): void {
            if ($copia !== '') {
                $add($this->center("*** {$copia} ***", $cols), self::STYLE_BOLD);
            }
        };

        $marcaCopia();
        // El título lo centra la impresora (ESC a 1, doble alto): aquí solo se
        // parte, a todo el ancho.
        $add($this->wrap($negocio['name'] ?? '', $cols), self::STYLE_TITLE);
        if ($this->truthy($negocio['legalName'] ?? null) && $this->clean($negocio['legalName']) !== $this->clean($negocio['name'] ?? null)) {
            $add($this->center($negocio['legalName'], $cols));
        }
        if ($this->truthy($negocio['nit'] ?? null)) {
            $add($this->center('NIT '.$negocio['nit'], $cols));
        }
        if ($this->truthy($negocio['address'] ?? null)) {
            $add($this->center($negocio['address'], $cols));
        }
        if ($this->truthy($negocio['phone'] ?? null)) {
            $add($this->center('Tel. '.$negocio['phone'], $cols));
        }
        $regla();

        if ($this->truthy($r['title'] ?? null)) {
            $add($this->center($r['title'], $cols), self::STYLE_BOLD);
        }
        $add($this->pair('Recibo', $this->jsString($r['number'] ?? null), $cols), self::STYLE_BOLD);
        if ($this->truthy($r['operationRef'] ?? null)) {
            $add($this->pair('Comprobante', $r['operationRef'], $cols));
        }
        $add($this->pair('Fecha', $this->clean($r['dateTime'] ?? null), $cols));
        $add($this->pair('Caja', $this->jsString($r['cashbox'] ?? null), $cols));
        if ($this->truthy($r['cashier'] ?? null)) {
            $add($this->pair('Cajero', $r['cashier'], $cols));
        }
        if ($this->truthy($r['customer'] ?? null)) {
            $add($this->pair('Cliente', $r['customer'], $cols));
        }
        if ($this->truthy($r['voided'] ?? null)) {
            $add($this->center($this->truthy($r['refunded'] ?? null) ? '*** OPERACIÓN DEVUELTA ***' : '*** OPERACIÓN ANULADA ***', $cols), self::STYLE_BOLD);
        }
        $regla();

        foreach ($this->list($r['items'] ?? null) as $it) {
            $add($this->wrap($it['description'] ?? '', $cols));
            $add($this->pair('  '.$this->qty($it['quantity'] ?? 1).' x '.$this->money($it['unitPrice'] ?? 0), $this->money($it['total'] ?? 0), $cols));
            if ($this->truthy($it['discount'] ?? null)) {
                $add($this->pair('  Descuento', '-'.$this->money($it['discount']), $cols));
            }
        }

        $m = $r['membership'] ?? null;
        if (is_array($m)) {
            $regla();
            $add($this->pair('Plan', $this->jsString($m['plan'] ?? null), $cols));
            if ($this->truthy($m['upgradedFrom'] ?? null)) {
                $add($this->pair('Mejora desde', $m['upgradedFrom'], $cols));
            }
            if ($this->truthy($m['periodFrom'] ?? null) || $this->truthy($m['periodTo'] ?? null)) {
                // Ya vienen «dd/mm/aaaa» del servidor: no se reconvierten.
                $desde = $this->clean($m['periodFrom'] ?? null);
                $hasta = $this->clean($m['periodTo'] ?? null);
                $add($this->pair('Vigencia', ($desde !== '' ? $desde : '—').' - '.($hasta !== '' ? $hasta : '—'), $cols));
            }
        }

        $d = $r['debt'] ?? null;
        if (is_array($d)) {
            $regla();
            $add($this->wrap($d['concept'] ?? '', $cols));
            $add($this->pair('Saldo anterior', $this->money($d['balanceBefore'] ?? 0), $cols));
            $add($this->pair('Saldo actual', $this->money($d['balanceAfter'] ?? 0), $cols), self::STYLE_BOLD);
            if ($this->truthy($d['dueAt'] ?? null) && (float) ($d['balanceAfter'] ?? 0) > 0) {
                $add($this->pair('Fecha límite', $this->clean($d['dueAt']), $cols));
            }
        }

        $regla();
        $add($this->pair('Subtotal', $this->money($r['subtotal'] ?? 0), $cols));
        if ($this->truthy($r['discount'] ?? null)) {
            $add($this->pair('Descuentos', '-'.$this->money($r['discount']), $cols));
        }
        $add($this->pair('TOTAL', $this->money($r['total'] ?? 0), $cols), self::STYLE_BOLD);
        // El IVA va DEBAJO del total: es informativo y ya está incluido.
        foreach ($this->list($r['taxes'] ?? null) as $impuesto) {
            $add($this->pair($this->jsString($impuesto['label'] ?? null), $this->money($impuesto['amount'] ?? 0), $cols));
        }
        $pagadoHoy = $r['paidNow'] ?? null;
        if ($pagadoHoy !== null && $this->jsRound((float) $pagadoHoy) !== $this->jsRound((float) ($r['total'] ?? 0))) {
            $add($this->pair('Pagado hoy', $this->money($pagadoHoy), $cols), self::STYLE_BOLD);
        }
        // Con bloque de deuda el saldo ya va arriba, con su antes y su después.
        $saldo = $r['balanceAfter'] ?? null;
        if (! is_array($d) && $saldo !== null && (float) $saldo > 0) {
            $add($this->pair('Saldo pendiente', $this->money($saldo), $cols), self::STYLE_BOLD);
        }

        foreach ($this->list($r['payments'] ?? null) as $p) {
            $add($this->pair($this->jsString($p['method'] ?? null), $this->money($p['amount'] ?? 0), $cols));
            if ($this->truthy($p['reference'] ?? null)) {
                $add($this->pair('  Ref.', $p['reference'], $cols));
            }
        }
        if (($r['received'] ?? null) !== null) {
            $add($this->pair('Recibido', $this->money($r['received']), $cols));
        }
        if (($r['change'] ?? null) !== null) {
            $add($this->pair('Cambio', $this->money($r['change']), $cols));
        }
        $regla();

        // La leyenda va SIEMPRE: si solo saliera el número de la factura, el
        // papel podría leerse como la factura misma.
        $add($this->center($this->truthy($r['legend'] ?? null) ? $r['legend'] : self::DEFAULT_LEGEND, $cols));
        if ($this->truthy($r['invoiceRef'] ?? null)) {
            $add($this->center('Ref. factura electrónica '.$r['invoiceRef'], $cols));
        }
        $add($this->center($r['footer'] ?? self::DEFAULT_FOOTER, $cols));
        $marcaCopia();

        return $out;
    }

    // ── Páginas de prueba (§5.4) ────────────────────────────────────────────

    /**
     * PRUEBA 1 del piloto: lo mínimo para saber si la impresora entiende el
     * trabajo. Tres líneas, sin tildes.
     *
     * @return list<array{text: string, style: string}>
     */
    public function minimalTestPage(int $cols = self::DEFAULT_COLUMNS): array
    {
        return [
            ['text' => 'IRON BODY', 'style' => self::STYLE_TITLE],
            ['text' => $this->center('PRUEBA POS', $cols)[0], 'style' => self::STYLE_NORMAL],
            ['text' => $this->center('OK', $cols)[0], 'style' => self::STYLE_BOLD],
        ];
    }

    /**
     * La página completa: caracteres que suelen romperse (tildes, eñes, signos
     * de apertura), una regla de `columns` y alineaciones. Si la regla cabe en
     * UNA línea y los importes tocan el borde derecho, el ancho está bien.
     *
     * @return list<array{text: string, style: string}>
     */
    public function fullTestPage(int $cols, string $codepage, ?string $stationName, CarbonInterface $now): array
    {
        $normal = fn (array $lineas) => array_map(fn (string $t) => ['text' => $t, 'style' => self::STYLE_NORMAL], $lineas);
        $raya = ['text' => str_repeat('-', $cols), 'style' => self::STYLE_NORMAL];
        $fecha = $now->copy()->setTimezone((string) config('caja.timezone'))->format('d/m/Y H:i');

        return array_values(array_merge(
            [['text' => 'PRUEBA DE IMPRESORA', 'style' => self::STYLE_TITLE]],
            $normal($this->center('Iron Body · Servicio local', $cols)),
            [$raya],
            [['text' => mb_substr(str_repeat('1234567890', 5), 0, $cols), 'style' => self::STYLE_NORMAL]],
            [['text' => '|'.str_repeat('-', $cols - 2).'|', 'style' => self::STYLE_NORMAL]],
            $normal($this->pair('Izquierda', 'Derecha', $cols)),
            $normal($this->center('Centro', $cols)),
            $normal(['á é í ó ú ñ Ñ ¿? ¡!']),
            $normal(['Á É Í Ó Ú ü Ü']),
            $normal($this->pair('Importe', $this->money(1_234_567), $cols)),
            array_map(fn (string $t) => ['text' => $t, 'style' => self::STYLE_BOLD], $this->pair('TOTAL', $this->money(18_500), $cols)),
            [$raya],
            $normal($this->pair('Fecha', $fecha, $cols)),
            $normal($this->pair('Estación', $stationName ?? '—', $cols)),
            $normal($this->center($cols.' col · '.$codepage, $cols)),
            [$raya],
            $normal($this->center('La regla debe caber', $cols)),
            $normal($this->center('en una sola línea.', $cols)),
        ));
    }

    /**
     * Un recibo de MUESTRA con la forma exacta de un recibo emitido (snapshot +
     * live), para pasarlo por {@see thermal()} y {@see lines()} como uno real.
     *
     * Vive solo en memoria: no se guarda en `receipts` ni toca ventas, cobros ni
     * inventario. El encabezado es el REAL (ReceiptComposer::business()) y la
     * fecha, la de ahora en la hora del gimnasio; todo lo demás es inventado y
     * se nota: número «PRUEBA», cliente «CLIENTE DE PRUEBA».
     *
     *  · sample_1          venta de 1 producto, en efectivo.
     *  · sample_5          venta de 5 productos, uno con descuento, pagada con
     *                      dos medios (efectivo + Nequi).
     *  · sample_20         venta de 20 productos con nombres largos y tildes.
     *  · sample_membership pago de membresía con plan, vigencia dd/mm/aaaa y saldo
     *                      pendiente (pagó la mitad).
     *
     * @return array{number: string, cash_type: string, operation_ref: null, snapshot: array<string, mixed>, live: array<string, mixed>}
     */
    public function sampleReceipt(string $variant, CarbonInterface $now, ?string $cashier = null): array
    {
        if (! in_array($variant, ReceiptAgentTicket::VARIANTS, true) || ! str_starts_with($variant, 'sample_')) {
            throw new ReceiptException('Variante de muestra desconocida: '.$variant.'.', 'layout_invalid');
        }

        $composer = app(ReceiptComposer::class);
        $membresia = $variant === 'sample_membership';

        [$lineas, $descuento, $impuestos, $pagos, $plan, $pagadoHoy] = match ($variant) {
            'sample_1' => $this->sampleSale([['Agua 600 ml', 1, 3000, 0]], [['Efectivo', null]]),
            'sample_5' => $this->sampleSale([
                ['Agua 600 ml', 2, 3000, 0],
                ['Barra de proteína', 1, 6500, 500],
                ['Bebida energética', 1, 7000, 0],
                ['Café americano', 2, 2500, 0],
                ['Toalla de entrenamiento', 1, 25000, 0],
            ], [['Efectivo', null, 20000], ['Nequi', 'NQ-PRUEBA']]),
            'sample_20' => $this->sampleSale(array_map(
                fn (array $p, int $i) => [$p[0], ($i % 3) + 1, $p[1], 0],
                [
                    ['Proteína Whey Isolate sabor chocolate con avellanas 2 lb', 189900],
                    ['Creatina monohidratada micronizada sin sabor 300 g', 89900],
                    ['Bebida isotónica de maracuyá y limón 500 ml', 4500],
                    ['Barra energética de maní, almendras y miel orgánica', 6500],
                    ['Guantes de entrenamiento acolchados talla mediana', 45000],
                    ['Café americano con leche de almendras sin azúcar', 5500],
                    ['Agua mineral sin gas edición montaña 600 ml', 3000],
                    ['Pre-entreno sabor limonada de coco con cafeína', 99900],
                    ['Shaker mezclador con compartimiento para cápsulas', 25000],
                    ['Toalla de microfibra de secado rápido grande', 32000],
                    ['Galletas de avena y arándanos altas en proteína', 7500],
                    ['Jugo natural de naranja y zanahoria recién exprimido', 6000],
                    ['Banda elástica de resistencia nivel intermedio', 28000],
                    ['Electrolitos en polvo sabor mandarina sin calorías', 3500],
                    ['Mantequilla de maní crocante sin sal añadida 340 g', 21000],
                    ['Colágeno hidrolizado con vitamina C y biotina', 74900],
                    ['Muñequeras de algodón para levantamiento olímpico', 18000],
                    ['Té verde helado con jengibre y limón 400 ml', 5000],
                    ['Snack de garbanzos tostados con ají y pimentón', 4000],
                    ['Candado de combinación para casillero metálico', 15000],
                ],
                array_keys(array_fill(0, 20, null)),
            ), [['Tarjeta débito', 'AUT-PRUEBA']], conIva: true),
            default => [
                [['description' => 'Plan Mensual Premium', 'quantity' => 1, 'unit_price' => 120000.0, 'discount' => 0.0, 'total' => 120000.0]],
                0.0,
                [],
                [['method_label' => 'Transferencia', 'amount' => 60000.0, 'reference' => 'TRX-PRUEBA']],
                'Mensual Premium',
                60000.0,
            ],
        };

        $total = array_sum(array_column($lineas, 'total'));
        // La vigencia es un día de calendario del gimnasio, como en los reales.
        $hoy = $now->copy()->setTimezone((string) config('caja.timezone'));

        return [
            'number' => self::SAMPLE_NUMBER,
            'cash_type' => $membresia ? 'gym' : 'products',
            'operation_ref' => null,
            'snapshot' => [
                'business' => $composer->business(),
                'title' => ReceiptComposer::titleFor($membresia ? Receipt::KIND_MEMBERSHIP_PAYMENT : Receipt::KIND_PRODUCT_SALE),
                'cash_label' => $membresia ? 'Caja de pagos' : 'Caja de productos',
                'operation_ref' => null,
                'cashier' => $cashier,
                'customer' => self::SAMPLE_CUSTOMER,
                'local_datetime' => $composer->localDateTime($now),
                'lines' => $lineas,
                'totals' => [
                    'subtotal' => $total + $descuento,
                    'discount' => $descuento,
                    'taxes' => $impuestos,
                    'total' => $total,
                    'paid_now' => $pagadoHoy ?? $total,
                    'balance_after' => $pagadoHoy !== null ? $total - $pagadoHoy : null,
                ],
                'payments' => $pagos,
                'membership' => $plan === null ? null : [
                    'plan' => $plan,
                    'period_from' => $hoy->format('d/m/Y'),
                    // Plan de 30 días, como el recibo real de un plan así (16/10 → 15/11).
                    'period_to' => $hoy->copy()->addDays(30)->format('d/m/Y'),
                    'upgraded_from' => null,
                ],
                'debt' => null,
                'legend' => (string) config('caja.receipts.legend'),
            ],
            'live' => ['operation_status' => 'paid', 'invoice' => null],
        ];
    }

    /**
     * Las piezas de una venta de muestra: líneas que cuadran (total = cantidad ×
     * unitario − descuento) y medios que suman el total (el último se lleva el
     * resto).
     *
     * @param  list<array{0: string, 1: int, 2: int, 3: int}>  $productos  [nombre, cantidad, unitario, descuento]
     * @param  list<array{0: string, 1: string|null, 2?: int}>  $medios  [medio, referencia, importe fijo]
     * @return array{0: list<array<string, mixed>>, 1: float, 2: list<array<string, mixed>>, 3: list<array<string, mixed>>, 4: null, 5: null}
     */
    private function sampleSale(array $productos, array $medios, bool $conIva = false): array
    {
        $lineas = array_map(fn (array $p) => [
            'description' => $p[0],
            'quantity' => $p[1],
            'unit_price' => (float) $p[2],
            'discount' => (float) $p[3],
            'total' => (float) ($p[1] * $p[2] - $p[3]),
        ], $productos);

        $total = array_sum(array_column($lineas, 'total'));
        $resto = $total;
        $pagos = [];
        foreach ($medios as $i => $m) {
            $importe = $i === count($medios) - 1 ? $resto : (float) ($m[2] ?? 0);
            $resto -= $importe;
            $pagos[] = ['method_label' => $m[0], 'amount' => $importe, 'reference' => $m[1]];
        }

        // Informativo, ya incluido en el precio (igual que en los reales).
        $impuestos = $conIva ? [['label' => 'IVA 19% (incluido)', 'amount' => round($total * 19 / 119)]] : [];

        return [$lineas, (float) array_sum(array_column($lineas, 'discount')), $impuestos, $pagos, null, null];
    }

    // ── Primitivas (thermal-receipt.ts) ─────────────────────────────────────

    /** «$ 12.000», pesos sin decimales y punto de miles (Intl es-CO + Math.round). */
    public function money(mixed $amount): string
    {
        $valor = (float) $amount;
        $redondo = $this->jsRound($valor);
        // Math.round(-0,4) es -0 y Intl lo escribe «-$ 0»: se conserva para que
        // el texto sea idéntico al del CRM.
        $negativo = $redondo < 0 || ($redondo == 0 && $valor < 0);

        return ($negativo ? '-$ ' : '$ ').number_format(abs($redondo), 0, ',', '.');
    }

    /** Cantidad: entera tal cual («2»); con decimales, al estilo es-CO («1,5»). */
    public function qty(mixed $n): string
    {
        $valor = (float) $n;

        if (is_finite($valor) && floor($valor) === $valor) {
            return (string) (int) $valor;
        }

        return rtrim(rtrim(number_format($valor, 3, ',', '.'), '0'), ',');
    }

    /**
     * Parte un texto en líneas de `cols` sin cortar palabras (salvo las más
     * largas que una línea).
     *
     * @return list<string>
     */
    public function wrap(mixed $text, int $cols = self::DEFAULT_COLUMNS): array
    {
        $palabras = array_values(array_filter(explode(' ', $this->clean($text)), fn (string $w) => $w !== ''));
        $lineas = [];
        $linea = '';

        foreach ($palabras as $w) {
            while (mb_strlen($w) > $cols) {
                if ($linea !== '') {
                    $lineas[] = $linea;
                    $linea = '';
                }
                $lineas[] = mb_substr($w, 0, $cols);
                $w = mb_substr($w, $cols);
            }
            if ($w === '') {
                continue;
            }
            if ($linea === '') {
                $linea = $w;
            } elseif (mb_strlen($linea) + 1 + mb_strlen($w) <= $cols) {
                $linea .= ' '.$w;
            } else {
                $lineas[] = $linea;
                $linea = $w;
            }
        }

        if ($linea !== '') {
            $lineas[] = $linea;
        }

        return $lineas;
    }

    /**
     * Izquierda y derecha en la misma línea; si no caben, la izquierda se parte
     * y el valor va solo, pegado a la derecha.
     *
     * @return list<string>
     */
    public function pair(mixed $left, mixed $right, int $cols = self::DEFAULT_COLUMNS): array
    {
        $l = $this->clean($left);
        $r = $this->clean($right);
        $ll = mb_strlen($l);
        $rl = mb_strlen($r);

        if ($ll + 1 + $rl <= $cols) {
            return [$l.str_repeat(' ', $cols - $ll - $rl).$r];
        }

        $lineas = $this->wrap($l, $cols);
        $ultima = $lineas === [] ? null : $lineas[count($lineas) - 1];

        if ($ultima !== null && mb_strlen($ultima) + 1 + $rl <= $cols) {
            $lineas[count($lineas) - 1] = $ultima.str_repeat(' ', $cols - mb_strlen($ultima) - $rl).$r;

            return $lineas;
        }

        // El valor tampoco cabe junto al texto (un nombre largo): va debajo,
        // partido y pegado a la derecha, línea a línea. (Con la izquierda vacía
        // el TS fallaría; aquí simplemente no hay líneas a la izquierda.)
        foreach ($this->wrap($r, $cols) as $v) {
            $lineas[] = str_repeat(' ', $cols - mb_strlen($v)).$v;
        }

        return $lineas;
    }

    /** @return list<string> */
    public function center(mixed $text, int $cols = self::DEFAULT_COLUMNS): array
    {
        return array_map(
            fn (string $l) => str_repeat(' ', intdiv($cols - mb_strlen($l), 2)).$l,
            $this->wrap($text, $cols),
        );
    }

    /**
     * Texto limpio de una línea: UTF-8 válido, NFC (la «á» es UN carácter), sin
     * saltos, tabuladores ni controles, espacios repetidos colapsados.
     */
    public function clean(mixed $s): string
    {
        $texto = $this->jsString($s);
        if (! mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
        }
        $texto = $this->nfc($texto);
        $texto = (string) preg_replace('/[\r\n\t]+/u', ' ', $texto);
        // Lo que el TS no hacía porque dibujaba HTML: un control en el texto
        // sería un comando para la impresora.
        $texto = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', ' ', $texto);
        // «…» ocupa UNA columna aquí pero el servicio lo imprime como «...»
        // (contrato §2.2): sin esto, una línea justa se pasaría de `columns`.
        // Se expande ANTES de medir y partir. (El resto de tipográficos que el
        // servicio sustituye son de uno a uno y no cambian la medida.)
        $texto = str_replace("\u{2026}", '...', $texto);
        $texto = (string) preg_replace('/['.self::JS_SPACE.']{2,}/u', ' ', $texto);

        return $this->jsTrim($texto);
    }

    // ── Equivalencias con JavaScript ────────────────────────────────────────

    /**
     * NFC y SIN marcas sueltas.
     *
     * Con ext-intl, Normalizer (compone todo lo que Unicode sabe componer). Sin
     * ella —PRODUCCIÓN— {@see nfcSinIntl()}. En los dos casos se quitan después
     * las marcas combinantes que no se pudieron componer: el resultado mide en
     * puntos de código lo mismo que ocupa en el papel.
     */
    private function nfc(string $texto): string
    {
        if (($this->useIntl ?? true) && class_exists(Normalizer::class)) {
            $normal = Normalizer::normalize($texto, Normalizer::FORM_C);

            if ($normal !== false) {
                return self::withoutCombiningMarks($normal);
            }
        }

        return self::nfcSinIntl($texto);
    }

    /**
     * El RESPALDO sin ext-intl, que es lo que corre en producción.
     *
     * Compone las combinaciones del español (vocal + tilde o diéresis, n/N +
     * virgulilla) y ELIMINA cualquier marca combinante que quede: «c» + cedilla
     * descompuesta sale «c». Peor que «ç», pero una marca suelta contaría una
     * columna y el servicio la imprimiría como «?». Los caracteres que ya llegan
     * compuestos (lo normal desde un teclado o un navegador) no se tocan.
     * Idempotente.
     */
    public static function nfcSinIntl(string $texto): string
    {
        return self::withoutCombiningMarks(strtr($texto, self::SPANISH_COMPOSITIONS));
    }

    private static function withoutCombiningMarks(string $texto): string
    {
        return (string) preg_replace('/['.self::COMBINING.']/u', '', $texto);
    }

    /** Math.round: la mitad sube hacia +∞ (Math.round(-2,5) = -2). */
    private function jsRound(float $x): float
    {
        return floor($x + 0.5);
    }

    private function jsTrim(string $s): string
    {
        return (string) preg_replace('/^['.self::JS_SPACE.']+|['.self::JS_SPACE.']+$/u', '', $s);
    }

    /** `(s ?? '')` y `String(v)` del TS para lo que puede llegar aquí. */
    private function jsString(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'true' : 'false',
            is_scalar($v) => (string) $v,
            default => '',
        };
    }

    /** `text()` de receipt-snapshot.ts: texto o número, recortado; si queda vacío, null. */
    private function text(mixed $v): ?string
    {
        if (! is_string($v) && ! is_int($v) && ! is_float($v)) {
            return null;
        }

        $s = $this->jsTrim((string) $v);

        return $s === '' ? null : $s;
    }

    /** `num()`: un importe que puede llegar como texto («180000.00») o no llegar. */
    private function num(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return is_finite((float) $v) ? (float) $v : 0.0;
        }
        if (is_bool($v)) {
            return $v ? 1.0 : 0.0;
        }
        if (is_string($v)) {
            $s = $this->jsTrim($v);
            if ($s === '') {
                return 0.0;
            }

            return is_numeric($s) && is_finite((float) $s) ? (float) $s : 0.0;
        }

        return 0.0;
    }

    private function numOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_string($v)) {
            $s = $this->jsTrim($v);
            if ($s === '') {
                return 0.0;
            }

            return is_numeric($s) && is_finite((float) $s) ? (float) $s : null;
        }
        if (is_int($v) || is_float($v) || is_bool($v)) {
            return $this->num($v);
        }

        return null;
    }

    /** Verdad de JavaScript para lo que puede llegar aquí. */
    private function truthy(mixed $v): bool
    {
        return ! ($v === null || $v === false || $v === '' || $v === 0 || $v === 0.0 || (is_float($v) && is_nan($v)));
    }

    /** `Array.isArray(x) ? x : []`: solo listas. */
    private function list(mixed $v): array
    {
        return is_array($v) && array_is_list($v) ? $v : [];
    }

    /** Un objeto JSON (array asociativo), o vacío. */
    private function obj(mixed $v): array
    {
        return is_array($v) ? $v : [];
    }
}
