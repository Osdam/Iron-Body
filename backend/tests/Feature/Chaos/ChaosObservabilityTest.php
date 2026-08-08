<?php

namespace Tests\Feature\Chaos;

use App\Models\CommercialAlert;
use App\Models\Incident;
use App\Models\MarketingMessage;
use App\Models\MetaWebhookEvent;
use App\Models\PaymentTransaction;
use App\Services\Commercial\CommercialAlertService;
use App\Services\IronGuard\ChannelHealthDetector;
use App\Services\IronGuard\IncidentRecorder;
use App\Services\Wompi\PaymentStateMachine;

/**
 * Incidentes, alertas y recuperación · lo transversal a los 45 escenarios.
 *
 * Un fallo bien detectado y mal presentado es un fallo no detectado. Si un
 * worker caído durante una hora abre cuatrocientas filas idénticas, el panel se
 * vuelve ilegible; y un panel ilegible se deja de mirar, que es la forma
 * habitual en que muere la observabilidad de un proyecto.
 *
 * Por eso aquí no se comprueba que «se registró algo». Se comprueba que lo
 * registrado sea USABLE: uno por clase de problema, con la cuenta real de
 * cuántas veces pasó, con evidencia suficiente para investigar, con el hilo que
 * lleva al caso concreto, y sin un solo secreto dentro.
 *
 * Y la mitad que casi siempre falta: que cuando el problema se arregla, se
 * pueda demostrar y la alarma se apague sola.
 */
class ChaosObservabilityTest extends ChaosTestCase
{
    // ── Deduplicación ───────────────────────────────────────────────────

    /**
     * Cien fallos idénticos son UN incidente que ocurrió cien veces.
     *
     * La afirmación tiene dos mitades y las dos importan. Una fila en vez de
     * cien es lo que hace legible el panel; que el contador diga cien es lo que
     * distingue «pasó una vez» de «lleva pasando toda la mañana», que es
     * exactamente la diferencia entre mirarlo mañana y levantarse ahora.
     */
    public function test_cien_fallos_identicos_son_un_incidente_con_cien_ocurrencias(): void
    {
        $recorder = app(IncidentRecorder::class);

        for ($i = 1; $i <= 100; $i++) {
            $recorder->record([
                'source' => 'outbox',
                'kind' => 'messages_dead',
                'fingerprint_keys' => ['131047'],
                'title' => 'Meta rechazó el envío con el código 131047',
                'severity' => Incident::SEVERITY_MEDIUM,
                'evidence' => ['count' => $i, 'last_message_id' => $i],
                'correlation_ids' => ['corr-'.$i],
            ]);
        }

        $incident = $this->assertSingleIncident('messages_dead', 100);

        $this->assertSame('outbox', $incident->source);
        $this->assertNotNull($incident->fingerprint);
        $this->assertNotNull($incident->first_seen_at);
        $this->assertNotNull($incident->last_seen_at);
        $this->assertTrue($incident->last_seen_at->greaterThanOrEqualTo($incident->first_seen_at));

        // La evidencia se refresca: importa cómo está AHORA, no cómo empezó.
        $this->assertSame(100, (int) data_get($incident->evidence, 'count'));

        // Se guardan unos pocos hilos, suficientes para investigar sin inflar
        // la fila con cien identificadores que nadie va a leer.
        $this->assertNotEmpty($incident->correlation_ids);
        $this->assertLessThanOrEqual(10, count((array) $incident->correlation_ids));

        $this->assertNoSecretsLeaked($incident);
    }

    /**
     * Dos clases distintas de fallo son dos incidentes.
     *
     * Es la contraparte necesaria: agrupar de más sería tan inútil como no
     * agrupar. Dos códigos de error distintos son dos problemas distintos, con
     * causas y arreglos distintos.
     */
    public function test_dos_clases_de_fallo_no_se_agrupan_en_uno(): void
    {
        $recorder = app(IncidentRecorder::class);

        foreach (['131047', '470'] as $codigo) {
            for ($i = 0; $i < 5; $i++) {
                $recorder->record([
                    'source' => 'outbox',
                    'kind' => 'messages_dead',
                    'fingerprint_keys' => [$codigo],
                    'title' => 'Meta rechazó el envío con el código '.$codigo,
                    'severity' => Incident::SEVERITY_MEDIUM,
                ]);
            }
        }

        $this->assertSame(2, Incident::where('kind', 'messages_dead')->count(),
            'Dos códigos de error distintos se agruparon como si fueran el mismo problema.');

        Incident::where('kind', 'messages_dead')->get()->each(
            fn (Incident $i) => $this->assertSame(5, (int) $i->occurrences),
        );
    }

    /**
     * Insistir sube la gravedad, pero no todo nace crítico.
     *
     * Si cada fallo abriera una alarma crítica, «crítico» dejaría de querer
     * decir nada y el panel volvería a ser ruido, esta vez en rojo.
     */
    public function test_no_todo_fallo_nace_critico_pero_lo_repetido_escala(): void
    {
        $recorder = app(IncidentRecorder::class);

        $primero = $recorder->record([
            'source' => 'media', 'kind' => 'downloads_failing',
            'fingerprint_keys' => ['timeout'],
            'title' => 'Un adjunto no se pudo descargar',
            'severity' => Incident::SEVERITY_MEDIUM,
        ]);

        $this->assertSame(Incident::SEVERITY_MEDIUM, $primero->severity,
            'Un fallo suelto abrió una alarma crítica.');

        for ($i = 0; $i < 15; $i++) {
            $recorder->record([
                'source' => 'media', 'kind' => 'downloads_failing',
                'fingerprint_keys' => ['timeout'],
                'title' => 'Un adjunto no se pudo descargar',
                'severity' => Incident::SEVERITY_MEDIUM,
            ]);
        }

        $this->assertSame(Incident::SEVERITY_HIGH, $primero->fresh()->severity,
            'Un fallo que se repite quince veces sigue tratándose como una anécdota.');
    }

    /** Un incidente nunca se rebaja solo. */
    public function test_un_incidente_no_se_rebaja_por_una_comprobacion_optimista(): void
    {
        $recorder = app(IncidentRecorder::class);

        $recorder->record([
            'source' => 'wompi', 'kind' => 'payments_stuck',
            'fingerprint_keys' => ['pending_timeout'],
            'title' => 'Pagos sin resolver', 'severity' => Incident::SEVERITY_CRITICAL,
        ]);

        $despues = $recorder->record([
            'source' => 'wompi', 'kind' => 'payments_stuck',
            'fingerprint_keys' => ['pending_timeout'],
            'title' => 'Pagos sin resolver', 'severity' => Incident::SEVERITY_LOW,
        ]);

        $this->assertSame(Incident::SEVERITY_CRITICAL, $despues->severity,
            'Una comprobación puntual rebajó algo que ya se había juzgado grave.');
    }

    /**
     * Los secretos no entran en la evidencia.
     *
     * La evidencia se mira, se copia en un ticket y a veces se pega en un chat.
     * Un token dentro no es una fuga teórica: es la ruta habitual por la que
     * una credencial acaba fuera.
     */
    public function test_la_evidencia_de_un_incidente_nunca_lleva_secretos(): void
    {
        $incident = app(IncidentRecorder::class)->record([
            'source' => 'meta_api', 'kind' => 'error_code_spike',
            'fingerprint_keys' => ['190'],
            'title' => 'Meta rechazó envíos con el código 190',
            'severity' => Incident::SEVERITY_HIGH,
            'evidence' => [
                'error_code' => 190,
                'hint' => 'El token de acceso caducó',
                'sample_references' => ['IRON-20260807-ABC123-99999'],
            ],
        ]);

        $this->assertNoSecretsLeaked($incident);
    }

    // ── Alertas comerciales ─────────────────────────────────────────────

    /**
     * Un pago atascado abre UNA alerta, la evalúe quien la evalúe.
     *
     * La evaluación corre cada pocos minutos. Sin huella, un pago pendiente
     * durante un día abriría noventa y seis alertas del mismo problema y la
     * bandeja del asesor dejaría de servir para nada.
     */
    public function test_un_pago_atascado_no_genera_una_alerta_cada_cinco_minutos(): void
    {
        $member = $this->member();

        PaymentTransaction::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'reference' => 'IRON-CHAOS-STUCK-1',
            'idempotency_key' => 'chaos-stuck-1',
            'provider' => 'wompi', 'environment' => 'sandbox',
            'amount' => 90000, 'currency' => 'COP',
            'status' => PaymentStateMachine::PENDING,
            'member_id' => $member->id, 'user_id' => $member->user_id,
        ])->forceFill(['created_at' => now()->subHours(5)])->save();

        $servicio = app(CommercialAlertService::class);

        // Doce vueltas: una hora de evaluaciones cada cinco minutos.
        for ($i = 0; $i < 12; $i++) {
            $servicio->evaluate();
        }

        $alertas = CommercialAlert::where('type', CommercialAlert::TYPE_PAYMENT_PENDING)->get();

        $this->assertCount(1, $alertas, sprintf(
            'Doce evaluaciones abrieron %d alertas del mismo pago. La bandeja se '
            .'vuelve inservible y el asesor deja de mirarla.',
            $alertas->count(),
        ));

        $alerta = $alertas->first();
        $this->assertSame(CommercialAlert::SEVERITY_HIGH, $alerta->severity);
        $this->assertSame($member->id, $alerta->member_id);
        $this->assertSame('IRON-CHAOS-STUCK-1', data_get($alerta->evidence, 'reference'));
        $this->assertNotNull($alerta->suggested_action, 'La alerta no dice qué hacer con ella.');
    }

    /**
     * Y cuando el pago entra, la alerta se cierra sola.
     *
     * Es lo que hace que la bandeja sea creíble: si hubiera que cerrarlas a
     * mano, se llenaría de cosas ya resueltas y volvería a ser ruido.
     */
    public function test_la_alerta_se_cierra_sola_cuando_el_pago_se_resuelve(): void
    {
        $member = $this->member();

        $tx = PaymentTransaction::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'reference' => 'IRON-CHAOS-STUCK-2',
            'idempotency_key' => 'chaos-stuck-2',
            'provider' => 'wompi', 'environment' => 'sandbox',
            'amount' => 90000, 'currency' => 'COP',
            'status' => PaymentStateMachine::PENDING,
            'member_id' => $member->id, 'user_id' => $member->user_id,
        ]);
        $tx->forceFill(['created_at' => now()->subHours(5)])->save();

        $servicio = app(CommercialAlertService::class);
        $servicio->evaluate();

        $alerta = CommercialAlert::where('type', CommercialAlert::TYPE_PAYMENT_PENDING)->firstOrFail();
        $this->assertTrue($alerta->isOpen());

        // El pago entra: la recuperación es demostrable.
        $tx->forceFill(['status' => PaymentStateMachine::APPROVED, 'paid_at' => now()])->save();

        $servicio->evaluate();

        $this->assertFalse($alerta->fresh()->isOpen(),
            'El pago entró y la alerta sigue abierta: la bandeja se llena de cosas ya resueltas.');
    }

    /**
     * Una alerta que una persona cerró NO se reabre sola.
     *
     * Si la siguiente evaluación la resucitara, la decisión de quien la cerró
     * sería papel mojado y se aprendería a no usar el botón.
     */
    public function test_lo_que_cerro_una_persona_no_se_reabre_solo(): void
    {
        $member = $this->member();

        PaymentTransaction::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'reference' => 'IRON-CHAOS-STUCK-3',
            'idempotency_key' => 'chaos-stuck-3',
            'provider' => 'wompi', 'environment' => 'sandbox',
            'amount' => 90000, 'currency' => 'COP',
            'status' => PaymentStateMachine::PENDING,
            'member_id' => $member->id, 'user_id' => $member->user_id,
        ])->forceFill(['created_at' => now()->subHours(5)])->save();

        $servicio = app(CommercialAlertService::class);
        $servicio->evaluate();

        $alerta = CommercialAlert::where('type', CommercialAlert::TYPE_PAYMENT_PENDING)->firstOrFail();
        $servicio->resolve($alerta, $this->admin->id, 'ya_gestionado', 'Le llamé, va a pagar en caja.');

        $servicio->evaluate();
        $servicio->evaluate();

        $this->assertFalse($alerta->fresh()->isOpen(),
            'Una alerta cerrada por una persona volvió a abrirse sola.');
        $this->assertSame(1, CommercialAlert::where('type', CommercialAlert::TYPE_PAYMENT_PENDING)->count());
    }

    /** Rechazos repetidos del mismo cliente: una alerta, no tres. */
    public function test_rechazos_repetidos_abren_una_sola_alerta(): void
    {
        $member = $this->member();

        for ($i = 1; $i <= 4; $i++) {
            PaymentTransaction::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'reference' => 'IRON-CHAOS-DECL-'.$i,
                'idempotency_key' => 'chaos-decl-'.$i,
                'provider' => 'wompi', 'environment' => 'sandbox',
                'amount' => 90000, 'currency' => 'COP',
                'status' => PaymentStateMachine::DECLINED,
                'member_id' => $member->id, 'user_id' => $member->user_id,
            ]);
        }

        app(CommercialAlertService::class)->evaluate();
        app(CommercialAlertService::class)->evaluate();

        $this->assertSame(1, CommercialAlert::where('type', CommercialAlert::TYPE_REPEATED_DECLINE)->count(),
            'Cuatro rechazos del mismo cliente abrieron más de una alerta.');
    }

    // ── Recuperación ────────────────────────────────────────────────────

    /**
     * Un incidente cerrado se REABRE si el problema vuelve.
     *
     * Y se reabre en su sitio, no como uno nuevo: la historia de un problema
     * recurrente es lo que permite ver que no se arregló, solo se calló.
     */
    public function test_un_problema_que_vuelve_reabre_su_incidente(): void
    {
        $recorder = app(IncidentRecorder::class);

        $incident = $recorder->record([
            'source' => 'queue', 'kind' => 'events_stuck',
            'fingerprint_keys' => ['pending'],
            'title' => 'Eventos sin procesar', 'severity' => Incident::SEVERITY_HIGH,
        ]);

        $incident->forceFill([
            'status' => Incident::STATUS_RESOLVED,
            'resolved_at' => now(),
        ])->save();

        $devuelta = $recorder->record([
            'source' => 'queue', 'kind' => 'events_stuck',
            'fingerprint_keys' => ['pending'],
            'title' => 'Eventos sin procesar', 'severity' => Incident::SEVERITY_HIGH,
        ]);

        $this->assertSame($incident->id, $devuelta->id,
            'El problema volvió y abrió un incidente nuevo: se pierde la historia de que es recurrente.');
        $this->assertSame(Incident::STATUS_OPEN, $devuelta->status);
        $this->assertNull($devuelta->resolved_at);
        $this->assertSame(1, Incident::where('kind', 'events_stuck')->count());
    }

    /**
     * Recuperado el canal, el detector deja de abrir cosas nuevas.
     *
     * Sin esto, un incidente resuelto seguiría sumando ocurrencias para siempre
     * y nunca se sabría si el arreglo funcionó.
     */
    public function test_tras_la_recuperacion_el_detector_no_abre_nada_nuevo(): void
    {
        // Un canal enfermo: eventos muertos de sobra.
        for ($i = 0; $i < 5; $i++) {
            MetaWebhookEvent::create([
                'correlation_id' => 'chaos-rec-'.$i,
                'payload_hash' => hash('sha256', 'chaos-rec-'.$i),
                'object' => 'whatsapp_business_account',
                'payload' => ['entry' => []],
                'payload_bytes' => 10, 'messages_count' => 1, 'statuses_count' => 0,
                'status' => MetaWebhookEvent::STATUS_DEAD, 'attempts' => 3,
                'last_error_class' => 'RuntimeException', 'last_error' => 'caído',
            ]);
        }

        app(ChannelHealthDetector::class)->scan();
        $this->assertSame(1, Incident::where('kind', 'events_dead')->count());

        // El canal se recupera: los eventos se reprocesan.
        MetaWebhookEvent::query()->update([
            'status' => MetaWebhookEvent::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);

        $antes = Incident::count();
        app(ChannelHealthDetector::class)->scan();

        $this->assertSame($antes, Incident::count(),
            'Con el canal ya sano, el detector siguió abriendo incidentes.');
        $this->assertSame(1, (int) Incident::where('kind', 'events_dead')->first()->occurrences,
            'Un problema ya resuelto siguió sumando ocurrencias.');
    }

    /**
     * Y el cliente queda en un estado coherente tras la tormenta.
     *
     * El resumen de toda la fase: cuando todo lo que podía fallar falló y
     * después se arregló, lo que queda escrito tiene que poder explicarse a la
     * persona que estaba al otro lado.
     */
    public function test_tras_la_tormenta_el_cliente_queda_en_un_estado_explicable(): void
    {
        $member = $this->member();

        // Un pago que quedó indeterminado por un timeout y después se aprobó.
        $tx = PaymentTransaction::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'reference' => 'IRON-CHAOS-FINAL-1',
            'idempotency_key' => 'chaos-final-1',
            'provider' => 'wompi', 'environment' => 'sandbox',
            'amount' => 90000, 'currency' => 'COP',
            'status' => PaymentStateMachine::PENDING,
            'member_id' => $member->id, 'user_id' => $member->user_id,
            'metadata' => ['outcome_unknown' => ['at' => now()->toIso8601String(), 'http_status' => 0]],
        ]);

        \App\Services\Wompi\WompiTransactionService::make()
            ->transitionTo($tx, PaymentStateMachine::APPROVED);

        $tx->refresh();

        // Un solo pago, un solo estado, y ese estado es el que ocurrió.
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->status);
        $this->assertSame(1, PaymentTransaction::where('member_id', $member->id)->count());
        $this->assertSame(1, \App\Models\Payment::where('reference', $tx->reference)->count());

        // Nada salió por el canal: sigue apagado.
        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')
            ->whereNotNull('meta_message_id')->count());
    }
}
