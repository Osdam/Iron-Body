<?php

namespace App\Services\Caja;

use App\Exceptions\ReceiptException;
use App\Models\Admin;
use App\Models\Receipt;
use App\Models\ReceiptSource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Único sitio donde nace un recibo.
 *
 * IDEMPOTENTE. Pedir dos veces el recibo de la misma operación devuelve el mismo
 * documento con el mismo número. Lo garantizan dos índices únicos —`origin_key`
 * y `receipt_sources(source_type, source_id)`—, no el navegador: un doble clic,
 * un reintento de red o dos pestañas acaban en la misma fila.
 *
 * NUMERACIÓN SIN HUECOS POR CAJA. El número sale de `receipt_sequences`, con la
 * fila de la caja BLOQUEADA (`lockForUpdate`) durante la emisión. Contador,
 * recibo y fuentes se escriben en la misma transacción: si cualquiera falla, el
 * contador vuelve atrás con lo demás y no queda un número sin recibo. Un
 * `max(id) + 1` —lo que hace `ProductSale::nextCode()`— repite número cuando dos
 * cajeros cobran en el mismo instante.
 *
 * El bloqueo además serializa a dos peticiones del MISMO recibo: la segunda
 * espera, vuelve a mirar bajo el bloqueo y encuentra el que acaba de crear la
 * primera, sin gastar número. Si aun así choca un índice único —otra operación
 * que comparte una fuente—, se recupera FUERA de la transacción, igual que
 * ReceivableService con `client_request_id`.
 */
final class ReceiptIssuer
{
    public function __construct(private readonly ReceiptComposer $composer) {}

    /**
     * @return array{0: Receipt, 1: bool} el recibo y si se acaba de crear
     *
     * @throws ReceiptException receipt_conflict si otra emisión se quedó con
     *                          parte de las fuentes a la vez
     */
    public function issue(ReceiptCandidate $c, ?Admin $actor): array
    {
        // Camino rápido del reintento, sin tomar ningún bloqueo.
        if ($ya = $this->existing($c)) {
            return [$ya, false];
        }

        // El snapshot se compone FUERA de la transacción: lee varias tablas y no
        // hay motivo para hacerlo con el contador de la caja bloqueado.
        $snapshot = $this->composer->compose($c);
        $total = (float) $snapshot['totals']['total'];
        $cliente = $snapshot['customer'];

        try {
            return DB::transaction(function () use ($c, $actor, $snapshot, $total, $cliente): array {
                $caja = $c->cashType->value;

                // Por si la fila no existiera (la migración las crea): sin fila
                // no hay nada que bloquear y dos emisiones repetirían número.
                DB::table('receipt_sequences')->insertOrIgnore(['cash_type' => $caja, 'last_number' => 0]);

                $secuencia = DB::table('receipt_sequences')
                    ->where('cash_type', $caja)
                    ->lockForUpdate()
                    ->first();

                // Otra vez, ya con el contador en la mano: quien esperaba el
                // bloqueo puede encontrarse aquí el recibo que acaba de nacer.
                if ($ya = $this->existing($c)) {
                    return [$ya, false];
                }

                $numero = (int) $secuencia->last_number + 1;
                DB::table('receipt_sequences')->where('cash_type', $caja)->update(['last_number' => $numero]);

                $ahora = now();
                $tardio = $c->operationAt !== null
                    && $c->operationAt->diffInMinutes($ahora, false) > (int) config('caja.receipts.late_after_minutes', 10);

                $recibo = Receipt::create([
                    'uuid' => (string) Str::uuid(),
                    'number' => $this->format($caja, $numero),
                    'cash_type' => $caja,
                    'cash_shift_id' => $c->cashShiftId,
                    'kind' => $c->kind,
                    'origin_key' => $c->originKey,
                    'operation_ref' => $c->operationRef,
                    'operation_at' => $c->operationAt,
                    'issued_at' => $ahora,
                    'issued_late' => $tardio,
                    'issued_by_admin_id' => $actor?->id,
                    'issued_by_name' => $actor?->name,
                    'total' => $total,
                    'customer_name' => $cliente,
                    'snapshot' => $snapshot,
                    'template_version' => (int) config('caja.receipts.template_version', 1),
                ]);

                foreach ($c->sources as $fuente) {
                    ReceiptSource::create([
                        'receipt_id' => $recibo->id,
                        'source_type' => $fuente['type'],
                        'source_id' => $fuente['id'],
                    ]);
                }

                return [$recibo, true];
            });
        } catch (QueryException $e) {
            // Choque contra un índice único: alguien emitió este recibo —o uno
            // que comparte fuente— entre la comprobación y el INSERT. Si es el
            // MISMO, se devuelve. Si es otro, no se inventa una mezcla: se dice.
            if ($ya = $this->existing($c)) {
                return [$ya, false];
            }

            if ($this->anySourceTaken($c)) {
                throw new ReceiptException(
                    'Parte de estas operaciones acaba de quedar en otro recibo. Vuelve a pedirlo.',
                    'receipt_conflict',
                );
            }

            throw $e;
        }
    }

    /** RG-000001 / RP-000001. */
    public function format(string $cashType, int $number): string
    {
        $prefijo = (string) config("caja.receipts.prefixes.{$cashType}", strtoupper($cashType).'-');
        $relleno = (int) config('caja.receipts.number_padding', 6);

        return $prefijo.str_pad((string) $number, $relleno, '0', STR_PAD_LEFT);
    }

    /**
     * El recibo que ya imprime EXACTAMENTE esta operación: por su llave o porque
     * todas sus fuentes son de un mismo recibo.
     */
    private function existing(ReceiptCandidate $c): ?Receipt
    {
        if ($porLlave = Receipt::where('origin_key', $c->originKey)->first()) {
            return $porLlave;
        }

        $recibos = $this->receiptIdsOf($c);

        if (count($recibos) === 1 && $this->coveredCount($c) === count($c->sources)) {
            return Receipt::find($recibos[0]);
        }

        return null;
    }

    private function anySourceTaken(ReceiptCandidate $c): bool
    {
        return $this->coveredCount($c) > 0;
    }

    /** @return list<int> */
    private function receiptIdsOf(ReceiptCandidate $c): array
    {
        return $this->sourcesQuery($c)->distinct()->pluck('receipt_id')->map(fn ($id) => (int) $id)->all();
    }

    private function coveredCount(ReceiptCandidate $c): int
    {
        return $this->sourcesQuery($c)->count();
    }

    private function sourcesQuery(ReceiptCandidate $c)
    {
        $porTipo = [];
        foreach ($c->sources as $f) {
            $porTipo[$f['type']][] = $f['id'];
        }

        return ReceiptSource::query()->where(function ($q) use ($porTipo): void {
            foreach ($porTipo as $tipo => $ids) {
                $q->orWhere(fn ($w) => $w->where('source_type', $tipo)->whereIn('source_id', $ids));
            }
        });
    }
}
