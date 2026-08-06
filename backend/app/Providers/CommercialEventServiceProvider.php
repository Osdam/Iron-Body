<?php

namespace App\Providers;

use App\Models\Attendance;
use App\Models\ElectronicInvoice;
use App\Models\MarketingAppointment;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Observers\Commercial\AttendanceCommercialObserver;
use App\Observers\Commercial\ElectronicInvoiceCommercialObserver;
use App\Observers\Commercial\MarketingAppointmentCommercialObserver;
use App\Observers\Commercial\MarketingConversationCommercialObserver;
use App\Observers\Commercial\MarketingLeadCommercialObserver;
use App\Observers\Commercial\MembershipCommercialObserver;
use App\Observers\Commercial\MemberCommercialObserver;
use App\Observers\Commercial\PaymentTransactionCommercialObserver;
use Illuminate\Support\ServiceProvider;

/**
 * Conecta el motor comercial con los hechos que ya ocurren en el sistema.
 *
 * Se hace observando TABLAS y no editando servicios. La diferencia es
 * importante en un sistema con varios años encima: un pago se aprueba por el
 * webhook de Wompi, por la reconciliación periódica, por el cobro recurrente y
 * por el push de Nequi. Enganchar en cada servicio obligaría a acordarse de los
 * cuatro —y del quinto que se escriba el año que viene—. El observer escucha
 * donde queda el resultado, así que ninguno se escapa.
 *
 * Además deja intacto el código de pagos y facturación, que el encargo marca
 * como zona que no se toca.
 *
 * Los observers están armados por `commercial.events_enabled`, un flag propio y
 * distinto de `commercial.enabled`. Es deliberado: permite REGISTRAR hechos
 * durante semanas —observar es inofensivo— y comprobar que el motor decide
 * bien, antes de dejarle decidir de verdad.
 */
class CommercialEventServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const OBSERVERS = [
        PaymentTransaction::class => PaymentTransactionCommercialObserver::class,
        User::class => MembershipCommercialObserver::class,
        Member::class => MemberCommercialObserver::class,
        MarketingLead::class => MarketingLeadCommercialObserver::class,
        MarketingAppointment::class => MarketingAppointmentCommercialObserver::class,
        MarketingConversation::class => MarketingConversationCommercialObserver::class,
        Attendance::class => AttendanceCommercialObserver::class,
        ElectronicInvoice::class => ElectronicInvoiceCommercialObserver::class,
    ];

    public function boot(): void
    {
        // Se registran siempre; cada observer comprueba el flag por su cuenta.
        // Registrarlos condicionalmente haría que encender el flag exigiera
        // reiniciar los workers, y esa clase de dependencia oculta es justo lo
        // que convierte un despliegue en una noche larga.
        foreach (self::OBSERVERS as $model => $observer) {
            $model::observe($observer);
        }
    }
}
