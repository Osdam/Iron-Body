<?php

namespace App\Services\Billing;

use App\Exceptions\ManualEmissionRejectedException;
use App\Models\FiscalProfile;
use App\Models\Member;
use App\Models\Payment;
use App\Models\ProductSale;
use App\Models\User;

/**
 * Resuelve los datos fiscales del adquiriente (a quién se factura).
 *
 * Política (decisión del cliente): si NO hay un FiscalProfile completo con tipo
 * de documento explícito, se factura a CONSUMIDOR FINAL (datos de
 * config('billing.consumer_final')) sin bloquear el cobro. No se "adivina" el
 * tipo de documento a partir de datos parciales: para factura nominativa debe
 * existir un FiscalProfile capturado (recepción/app). Así evitamos rechazos
 * DIAN por asumir un doc_type incorrecto.
 *
 * Devuelve SIEMPRE el mismo shape:
 *   doc_type, doc_number, dv, name, legal_name, email, phone, address,
 *   city_code, department_code, is_final_consumer
 */
class FiscalProfileResolver
{
    /**
     * Decisión EXPLÍCITA de adquiriente para la emisión manual.
     *
     * `null` conserva la política automática descrita arriba (perfil completo →
     * nominativa; si no → consumidor final), que es la que usan los hooks de
     * cobro. Un booleano viene de una persona que marcó (o desmarcó) la casilla
     * del modal y manda sobre la política: `true` factura a consumidor final
     * aunque exista perfil, y `false` exige un perfil fiscal utilizable y
     * fracasa con el detalle de lo que falta en vez de degradar en silencio.
     *
     * @return array<string,mixed>
     */
    public function resolveForPayment(Payment $payment, ?bool $finalConsumer = null): array
    {
        $member = $payment->member_id ? Member::find($payment->member_id) : null;
        $user = $payment->user_id ? User::find($payment->user_id) : null;

        $profile = $this->findProfile($user?->id, $member?->id, $member?->identity_id);

        if ($finalConsumer !== true && $this->esUtilizable($profile, $finalConsumer)) {
            return $this->fromProfile($profile, $payment->invoice_email);
        }

        return $this->consumerFinal(
            contactName: $member?->full_name ?? $user?->name,
            // El correo de entrega tiene que existir de verdad. `socio-XXX@
            // ironbody.local` es un relleno del sistema: si se colara aquí, el
            // comprobante se «enviaría» a un buzón inexistente y el pago
            // quedaría marcado como notificado.
            contactEmail: InvoiceEmail::primeroEntregable(
                $payment->invoice_email,
                $member?->email,
                $user?->email,
            ),
            contactPhone: $member?->phone ?? $user?->phone,
        );
    }

    /** @return array<string,mixed> */
    public function resolveForSale(ProductSale $sale, ?bool $finalConsumer = null): array
    {
        $member = $sale->member_id ? Member::find($sale->member_id) : null;
        $profile = $this->findProfile(null, $member?->id, $member?->identity_id);

        if ($finalConsumer !== true && $this->esUtilizable($profile, $finalConsumer)) {
            return $this->fromProfile($profile, $sale->invoice_email);
        }

        return $this->consumerFinal(
            contactName: $sale->customer_name ?? $member?->full_name,
            contactEmail: InvoiceEmail::primeroEntregable($sale->invoice_email, $member?->email),
            contactPhone: $member?->phone,
        );
    }

    /**
     * ¿Se puede facturar NOMINATIVAMENTE con este perfil?
     *
     * `isComplete()` sólo mira que los campos no estén vacíos, y eso deja pasar
     * basura tipográfica: en producción hay un perfil con el NIT guardado como
     * «9 0 1 4 9 9 7 4 2». Con la política automática eso llegaba al payload
     * congelado tal cual. Aquí se comprueba además que el documento tenga forma
     * de documento, y si la persona pidió factura nominativa explícitamente
     * (`$finalConsumer === false`) se falla diciendo QUÉ falta, en vez de
     * degradar a consumidor final a sus espaldas.
     *
     * @throws ManualEmissionRejectedException
     */
    private function esUtilizable(?FiscalProfile $profile, ?bool $finalConsumer): bool
    {
        $faltantes = self::camposFaltantes($profile);

        if ($faltantes === []) {
            return true;
        }

        if ($finalConsumer === false) {
            throw ManualEmissionRejectedException::noFacturable(
                'No se puede emitir una factura nominativa: '.implode('; ', $faltantes)
                .'. Corrige el perfil fiscal del cliente o marca «Usar consumidor final».'
            );
        }

        return false;
    }

    /**
     * Qué le falta al perfil para servir como adquiriente nominativo.
     * Devuelve frases listas para mostrarle al operador; vacío = utilizable.
     *
     * @return list<string>
     */
    public static function camposFaltantes(?FiscalProfile $profile): array
    {
        if ($profile === null) {
            return ['el cliente no tiene perfil fiscal registrado'];
        }

        $faltantes = [];

        if (blank($profile->doc_type)) {
            $faltantes[] = 'falta el tipo de documento';
        }

        $documento = (string) $profile->doc_number;
        if (blank($documento)) {
            $faltantes[] = 'falta el número de documento';
        } elseif (preg_match('/\s/', $documento) === 1) {
            $faltantes[] = 'el número de documento contiene espacios («'.$documento.'»)';
        } elseif (! self::esPasaporte($profile->doc_type) && preg_match('/^[0-9-]+$/', $documento) !== 1) {
            $faltantes[] = 'el número de documento contiene caracteres no numéricos («'.$documento.'»)';
        }

        if (blank($profile->legal_name ?: ($profile->user?->name ?? $profile->member?->full_name))) {
            $faltantes[] = 'falta la razón social o el nombre del cliente';
        }

        return $faltantes;
    }

    /** Sólo el pasaporte admite letras en el número de documento. */
    private static function esPasaporte(?string $docType): bool
    {
        $tipo = strtoupper(trim((string) $docType));

        return $tipo === 'PAS' || $tipo === 'PASAPORTE' || $tipo === '41';
    }

    private function findProfile(?int $userId, ?int $memberId, ?int $identityId): ?FiscalProfile
    {
        return FiscalProfile::query()
            ->when($identityId, fn ($q) => $q->orWhere('identity_id', $identityId))
            ->when($memberId, fn ($q) => $q->orWhere('member_id', $memberId))
            ->when($userId, fn ($q) => $q->orWhere('user_id', $userId))
            ->first();
    }

    /**
     * Factura NOMINATIVA: identidad fiscal real del cliente.
     *
     * `$solicitado` es el correo que el cliente indicó al pedir la factura y
     * tiene prioridad sobre el del perfil: es el que dio para ESTA compra.
     *
     * @return array<string,mixed>
     */
    private function fromProfile(FiscalProfile $p, ?string $solicitado = null): array
    {
        return [
            'doc_type' => $p->doc_type,
            'doc_number' => $p->doc_number,
            'dv' => $p->dv,
            'name' => $p->legal_name ?: ($p->user?->name ?? $p->member?->full_name),
            'legal_name' => $p->legal_name,
            'person_type' => $p->person_type,
            'email' => InvoiceEmail::primeroEntregable(
                $solicitado,
                $p->email,
                $p->user?->email,
                $p->member?->email,
            ),
            'phone' => $p->phone,
            'address' => $p->address,
            'city_code' => $p->city_code,
            'department_code' => $p->department_code,
            'is_final_consumer' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function consumerFinal(?string $contactName, ?string $contactEmail, ?string $contactPhone): array
    {
        $cf = (array) config('billing.consumer_final');

        return [
            'doc_type' => $cf['document_type'] ?? null,
            'doc_number' => $cf['document_number'] ?? null,
            'dv' => null,
            'name' => $cf['name'] ?? 'Consumidor final',
            'legal_name' => $cf['name'] ?? 'Consumidor final',
            'person_type' => null,
            // Contacto real para entrega del comprobante (no es la identidad fiscal).
            'email' => $contactEmail,
            'phone' => $contactPhone,
            'address' => null,
            'city_code' => null,
            'department_code' => null,
            'is_final_consumer' => true,
        ];
    }
}
