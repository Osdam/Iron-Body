<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\TaxPolicy;

/**
 * Declara explícitamente que la prueba ejercita el MOTOR de cálculo con un
 * emisor responsable de IVA.
 *
 * Por qué existe: Iron Body es responsabilidad 49 (no responsable de IVA) y
 * {@see TaxPolicy} fuerza la tarifa efectiva a 0 en todo
 * el sistema. Estas pruebas, en cambio, verifican que el motor SEPA calcular
 * IVA —extraerlo, sumarlo, repartir descuentos, no derivar centavos— porque esa
 * capacidad sigue en el código y es reversible por configuración si el emisor
 * cambiara de régimen.
 *
 * Sin esta declaración, las pruebas del motor y la política del emisor se
 * confunden: al fijar la política en 0 % parecería que el motor «se rompió»,
 * cuando lo que ocurre es que nunca se le pide calcular IVA.
 *
 * La política REAL y vigente de Iron Body se verifica aparte, en
 * {@see NoVatIssuerPolicyTest}: precio $80.000 → subtotal $80.000, IVA $0,
 * total $80.000.
 */
trait AssumesVatResponsibleIssuer
{
    /**
     * Activa el régimen responsable de IVA solo para el caso de prueba.
     * Se invoca desde el `setUp()` de cada clase que use el trait.
     */
    protected function assumeVatResponsibleIssuer(): void
    {
        config([
            'tax_policy.issuer_is_vat_responsible' => true,
            'tax_policy.vat_collection_enabled' => true,
        ]);
    }
}
