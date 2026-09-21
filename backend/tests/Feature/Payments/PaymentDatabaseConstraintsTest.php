<?php

namespace Tests\Feature\Payments;

use App\Models\PaymentWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LO QUE RECHAZA LA BASE, NO LA APLICACIÓN.
 *
 * Todas las reglas de «un solo cobro» que hay probadas hasta aquí se cumplen
 * en PHP: un SELECT antes de escribir, un `firstOrCreate`, un catch que
 * recupera. Eso vale mientras el código sea el único que escribe y no haya dos
 * procesos a la vez. La última línea —la que sigue en pie cuando falla todo lo
 * anterior, cuando alguien corre un comando a mano o cuando dos workers se
 * cruzan— es el índice único, y de esa no había ni un test.
 *
 * Aquí se escribe en crudo, sin modelos y sin servicios, y se exige que la
 * BASE diga que no.
 *
 * CON UNA SALVEDAD QUE CONVIENE NO OLVIDAR: esto corre sobre SQLite y lo que
 * valida es el esquema que SQLite construye desde las migraciones, no el de
 * PostgreSQL. Para un índice único declarado en la migración las dos bases
 * coinciden y la prueba vale; para lo que una acepta y la otra rechaza —tipos,
 * agregados, CHECK— no dice nada. Ya se pagó esa diferencia una vez, con
 * agregados que pasaban la suite y devolvían 500 en producción.
 *
 * Y tampoco ve la DERIVA del esquema vivo: un índice creado a mano, un
 * `CREATE INDEX CONCURRENTLY` que falló a medias o una migración marcada como
 * corrida sin haberlo estado seguirían dando verde aquí. Lo que esto sostiene
 * es que las restricciones DECLARADAS EN LAS MIGRACIONES siguen en pie: si
 * alguien quita un `unique()`, se cae.
 */
class PaymentDatabaseConstraintsTest extends TestCase
{
    use RefreshDatabase;

    /** Una fila mínima de cobro, escrita sin pasar por ningún servicio. */
    private function fila(array $overrides = []): array
    {
        return array_merge([
            'reference' => 'IRON-TEST-'.Str::upper(Str::random(6)),
            'idempotency_key' => 'mkt-lead-1-plan-1-'.Str::uuid(),
            'amount' => 80000,
            'currency' => 'COP',
            'status' => 'pending',
            'provider' => 'wompi',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    private function insertar(array $fila): void
    {
        DB::table('payment_transactions')->insert($fila);
    }

    public function test_the_database_refuses_two_payments_with_the_same_reference(): void
    {
        $ref = 'IRON-20260921-DUPLIC-00001';
        $this->insertar($this->fila(['reference' => $ref]));

        $this->expectException(QueryException::class);
        $this->insertar($this->fila(['reference' => $ref]));
    }

    public function test_the_database_refuses_two_payments_with_the_same_idempotency_key(): void
    {
        $clave = 'mkt-lead-7-plan-3-fija';
        $this->insertar($this->fila(['idempotency_key' => $clave]));

        $this->expectException(QueryException::class);
        $this->insertar($this->fila(['idempotency_key' => $clave]));
    }

    /**
     * Y dos filas nuestras no pueden reclamar la misma transacción de Wompi.
     *
     * Es la unicidad que sostiene el rastro contable: el id de la pasarela
     * identifica UN cobro real, así que no puede estar en dos filas.
     */
    public function test_the_database_refuses_two_payments_claiming_the_same_gateway_transaction(): void
    {
        $this->insertar($this->fila(['wompi_transaction_id' => 'wompi-tx-unica']));

        $this->expectException(QueryException::class);
        $this->insertar($this->fila(['wompi_transaction_id' => 'wompi-tx-unica']));
    }

    /**
     * Los NULL conviven: los cobros viejos no tienen id de pasarela.
     *
     * Sin esto, la unicidad anterior sería una regresión disfrazada de
     * garantía: un `unique` que no tolerara NULL impediría crear cualquier
     * cobro antes de que Wompi conteste.
     */
    public function test_payments_without_a_gateway_transaction_still_coexist(): void
    {
        $this->insertar($this->fila());
        $this->insertar($this->fila());

        $this->assertSame(2, DB::table('payment_transactions')->count());
    }

    /** Y el mismo evento del webhook no se registra dos veces. */
    public function test_the_database_refuses_the_same_webhook_event_twice(): void
    {
        $hash = hash('sha256', 'cuerpo-crudo-del-evento');
        $fila = [
            'uuid' => (string) Str::uuid(),
            'provider' => 'wompi',
            'event_type' => 'transaction.updated',
            'payload_hash' => $hash,
            'payload' => json_encode(['event' => 'transaction.updated']),
            'processing_status' => PaymentWebhookEvent::STATUS_RECEIVED,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('payment_webhook_events')->insert($fila);

        $this->expectException(QueryException::class);
        DB::table('payment_webhook_events')->insert(array_merge($fila, ['uuid' => (string) Str::uuid()]));
    }
}
