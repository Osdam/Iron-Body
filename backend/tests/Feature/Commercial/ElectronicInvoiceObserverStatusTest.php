<?php

namespace Tests\Feature\Commercial;

use App\Enums\InvoiceStatus;
use App\Models\CommercialEvent;
use App\Models\ElectronicInvoice;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Services\Commercial\CommercialVocabulary as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El observador comercial de las facturas ante un CAMBIO DE ESTADO, con el
 * registro de hechos encendido (como está producción desde el 11-11-2026).
 *
 * El modelo castea `status` a {@see InvoiceStatus} y el observador hacía
 * `(string) $invoice->status`: un enum no se convierte así, y `updated()`
 * lanzaba `Error` en cada cambio de estado, que salía por el `save()` de
 * `markProcessing()`, `markValidated()`, `markRejected()` y `markError()`. El
 * job de emisión moría antes de hablar con Factus. Lo encontró la revisión de
 * la tanda 2 (informe-reviewer-tanda-2.md, §0); `InvoiceEmissionTest` no lo
 * veía porque corre con el registro apagado.
 *
 * Aquí se usa el modelo real, con sus métodos reales y el observador
 * registrado por el proveedor; nada de dobles.
 */
class ElectronicInvoiceObserverStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('commercial.events_enabled', true);
        config()->set('commercial.enabled', false);
    }

    private function invoice(string $type = 'invoice', string $status = 'pending'): ElectronicInvoice
    {
        $socio = Member::create([
            'full_name' => 'Socio de la factura', 'document_number' => '1020304050', 'phone' => '3150000001',
            'access_hash' => 'tok-'.uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
        $pago = PaymentTransaction::create([
            'reference' => 'REF-FE-'.uniqid(), 'idempotency_key' => 'idem-fe-'.uniqid(), 'member_id' => $socio->id,
            'amount' => 80000, 'currency' => 'COP', 'status' => 'approved', 'provider' => 'wompi',
        ]);

        return ElectronicInvoice::create([
            'source_type' => PaymentTransaction::class, 'source_id' => $pago->id, 'type' => $type,
            'status' => $status, 'full_number' => 'IBFE-77', 'total' => '80000.00',
        ]);
    }

    /** @return list<string> las claves de los hechos de la factura, en orden */
    private function keys(): array
    {
        return CommercialEvent::query()->orderBy('id')->pluck('dedupe_key')->all();
    }

    public function test_al_crearla_se_registra_que_se_pidio(): void
    {
        $f = $this->invoice();

        $this->assertSame(["invoice_requested:invoice:{$f->id}:requested"], $this->keys());
    }

    public function test_validarla_registra_invoice_validated_con_su_clave_y_su_payload(): void
    {
        $f = $this->invoice();

        $f->markProcessing();   // antes lanzaba aquí: el job de emisión no llegaba a Factus
        $f->markValidated(['pdf_url' => 'https://ejemplo.invalid/f.pdf']);

        $this->assertSame(InvoiceStatus::VALIDATED, $f->fresh()->status);
        $this->assertSame(
            ["invoice_requested:invoice:{$f->id}:requested", "invoice_validated:invoice:{$f->id}:validated"],
            $this->keys(),
            'en proceso no es un hecho comercial; validada, sí',
        );
        $hecho = CommercialEvent::query()->where('event', V::EV_INVOICE_VALIDATED)->firstOrFail();
        $this->assertSame($f->source->member_id, $hecho->member_id);
        // Por claves ordenadas: en PostgreSQL la columna es `jsonb` y devuelve las claves en su propio orden.
        $esperado = ['full_number' => 'IBFE-77', 'total' => '80000.00', 'failure_reason' => null, 'has_pdf' => true, 'has_xml' => false];
        $payload = $hecho->payload;
        ksort($esperado);
        ksort($payload);
        $this->assertSame($esperado, $payload);
    }

    public function test_rechazarla_registra_invoice_rejected_con_el_motivo(): void
    {
        $f = $this->invoice();

        $f->markProcessing();
        $f->markRejected('NIT del adquirente inválido');

        $this->assertSame(InvoiceStatus::REJECTED, $f->fresh()->status);
        $this->assertSame(
            ["invoice_requested:invoice:{$f->id}:requested", "invoice_rejected:invoice:{$f->id}:rejected"],
            $this->keys(),
        );
        $this->assertSame(
            'NIT del adquirente inválido',
            CommercialEvent::query()->where('event', V::EV_INVOICE_REJECTED)->firstOrFail()->payload['failure_reason'],
        );
    }

    public function test_un_error_tecnico_no_es_un_hecho_comercial_y_tampoco_lanza(): void
    {
        $f = $this->invoice();

        $f->markProcessing();
        $f->markError('timeout de red');   // `failed()` del job llama aquí: antes volvía a lanzar

        $this->assertSame(InvoiceStatus::ERROR, $f->fresh()->status);
        $this->assertSame(1, (int) $f->fresh()->retry_count);
        $this->assertSame(["invoice_requested:invoice:{$f->id}:requested"], $this->keys());
    }

    public function test_la_nota_credito_validada_y_la_rechazada_registran_lo_mismo_que_la_factura(): void
    {
        $validada = $this->invoice('credit_note', 'credit_note_pending');
        $validada->markProcessing();
        $validada->markValidated();
        $this->assertSame(InvoiceStatus::CREDIT_NOTE_VALIDATED, $validada->fresh()->status);

        $this->assertContains("invoice_validated:invoice:{$validada->id}:credit_note_validated", $this->keys());

        $validada->markRejected('rechazada después');   // el mismo comprobante cambia otra vez: otra clave, otro hecho
        $this->assertContains("invoice_rejected:invoice:{$validada->id}:credit_note_rejected", $this->keys());
    }

    /** @return iterable<string, array{string}> */
    public static function estados(): iterable
    {
        foreach (InvoiceStatus::cases() as $caso) {
            yield $caso->value => [$caso->value];
        }
    }

    #[DataProvider('estados')]
    public function test_ningun_estado_del_enum_hace_lanzar_al_observador(string $estado): void
    {
        $f = $this->invoice();

        // Tanto con el enum (lo que escriben los métodos del modelo) como con la cadena (lo que escribe un `update()`).
        $f->forceFill(['status' => InvoiceStatus::from($estado)])->save();
        $f->forceFill(['status' => 'pending'])->save();
        $f->update(['status' => $estado]);

        $this->assertSame($estado, $f->fresh()->status->value);
    }

    public function test_con_el_registro_apagado_no_se_registra_nada(): void
    {
        config()->set('commercial.events_enabled', false);
        $f = $this->invoice();

        $f->markProcessing();
        $f->markValidated();

        $this->assertSame([], $this->keys());
    }
}
