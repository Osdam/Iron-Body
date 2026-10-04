<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DE DÓNDE SALIÓ ESTA FICHA: del mostrador o del sistema anterior.
 *
 * Las altas del informe se cuentan por `users.created_at`, y para un socio
 * importado esa fecha no es cuando se inscribió: es el día en que se corrió la
 * importación. Con tres mil fichas entrando el mismo día, el periodo que las
 * contiene enseña tres mil altas y el siguiente aparece como un desplome del
 * 99 %. El gimnasio estaba leyendo una caída que nunca ocurrió.
 *
 * No vale con tapar una fecha concreta: el export del sistema anterior se vuelve
 * a cargar cada tanto, así que esto pasaría otra vez en cada carga. Por eso se
 * marca la ficha, y la importación marcará las que traiga de ahora en adelante.
 *
 * LAS QUE YA ESTÁN se marcan aquí, en dos pasadas:
 *
 *   1. Las que se delatan solas: tienen un pago o un inicio de membresía
 *      ANTERIOR a su propia fecha de creación, o un pago con referencia MIGR-.
 *      Nadie paga antes de existir; eso solo pasa cuando la historia es vieja y
 *      la ficha es nueva.
 *   2. Las demás de la misma carga. Una importación es un trabajo por lotes:
 *      crea miles de filas en unos minutos. Así que se marcan también las fichas
 *      creadas dentro de la misma ventana que las ya detectadas, que es la forma
 *      de alcanzar a los clientes del export que nunca compraron nada y por eso
 *      no dejan ningún rastro que delate su edad.
 */
return new class extends Migration
{
    /** Hueco máximo entre dos filas para seguir considerándolas la misma carga. */
    private const MINUTOS_DE_LOTE = 10;

    /**
     * Cuánto tiene que preceder la historia a la ficha para delatarla. Un mes:
     * por debajo de eso es un pago registrado con fecha atrasada, no una
     * importación.
     */
    private const DIAS_DE_MARGEN = 30;

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'imported_at')) {
                $table->timestamp('imported_at')->nullable()->index();
            }
        });

        $this->marcarLasQueSeDelatan();
        $this->marcarElRestoDeCadaCarga();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'imported_at')) {
                $table->dropColumn('imported_at');
            }
        });
    }

    /**
     * Historia MUY anterior a la propia ficha, o un pago con referencia MIGR-.
     *
     * El margen de un mes no es un adorno: en el mostrador se registran pagos
     * con fecha atrasada —«pagó el lunes y lo subimos el miércoles»— y sin él
     * cualquier socio real con un cobro de hace tres días quedaría marcado como
     * importado. Lo que delata una ficha vieja no es un día de diferencia, son
     * meses o años.
     *
     * La decisión se toma en PHP y no en SQL porque restarle treinta días a una
     * marca de tiempo se escribe distinto en PostgreSQL y en SQLite, y esta
     * migración corre en los dos.
     */
    private function marcarLasQueSeDelatan(): void
    {
        $primerPago = DB::table('payments')
            ->selectRaw('user_id, MIN(COALESCE(paid_at, created_at)) as primero')
            ->groupBy('user_id')
            ->pluck('primero', 'user_id');

        $conReferenciaDeMigracion = DB::table('payments')
            ->where('reference', 'like', 'MIGR-%')
            ->distinct()
            ->pluck('user_id')
            ->flip();

        $sospechosos = [];

        DB::table('users')
            ->whereNull('imported_at')
            ->select('id', 'created_at', 'membership_start_date')
            ->orderBy('id')
            ->chunk(2000, function ($usuarios) use (&$sospechosos, $primerPago, $conReferenciaDeMigracion): void {
                foreach ($usuarios as $u) {
                    $creada = strtotime((string) $u->created_at);
                    $limite = $creada - self::DIAS_DE_MARGEN * 86400;

                    $pago = $primerPago[$u->id] ?? null;
                    $inicio = $u->membership_start_date;

                    $esVieja = isset($conReferenciaDeMigracion[$u->id])
                        || ($pago !== null && strtotime((string) $pago) < $limite)
                        || ($inicio !== null && strtotime((string) $inicio) < $limite);

                    if ($esVieja) {
                        $sospechosos[] = $u->id;
                    }
                }
            });

        foreach (array_chunk($sospechosos, 1000) as $trozo) {
            DB::table('users')
                ->whereIn('id', $trozo)
                ->update(['imported_at' => DB::raw('created_at')]);
        }
    }

    /**
     * El resto de las fichas creadas en la misma tanda.
     *
     * Los instantes se agrupan en PHP y no en SQL a propósito: agrupar por
     * minutos exige funciones de fecha distintas en PostgreSQL y en SQLite, y
     * esta migración tiene que correr igual en el servidor y en las pruebas.
     */
    private function marcarElRestoDeCadaCarga(): void
    {
        $instantes = DB::table('users')
            ->whereNotNull('imported_at')
            ->orderBy('created_at')
            ->pluck('created_at')
            ->map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : (string) $v)
            ->unique()
            ->values();

        if ($instantes->isEmpty()) {
            return;
        }

        $hueco = self::MINUTOS_DE_LOTE * 60;
        $lotes = [];
        $inicio = null;
        $ultimo = null;

        foreach ($instantes as $instante) {
            $t = strtotime($instante);

            if ($inicio === null) {
                $inicio = $ultimo = $t;

                continue;
            }

            if ($t - $ultimo > $hueco) {
                $lotes[] = [$inicio, $ultimo];
                $inicio = $t;
            }

            $ultimo = $t;
        }
        $lotes[] = [$inicio, $ultimo];

        foreach ($lotes as [$desde, $hasta]) {
            // La ventana se ensancha a los dos lados: las fichas que se delatan
            // solas son solo ALGUNAS de la carga, y las que no dejan rastro
            // —los clientes del export que nunca compraron— están repartidas
            // entre ellas y alrededor. Sin ensanchar, un lote detectado por una
            // sola ficha no alcanzaría a ninguna otra.
            DB::table('users')
                ->whereNull('imported_at')
                ->whereBetween('created_at', [
                    date('Y-m-d H:i:s', $desde - $hueco),
                    date('Y-m-d H:i:s', $hasta + $hueco),
                ])
                ->update(['imported_at' => DB::raw('created_at')]);
        }
    }
};
