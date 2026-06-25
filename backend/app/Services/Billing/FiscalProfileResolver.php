<?php

namespace App\Services\Billing;

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
    /** @return array<string,mixed> */
    public function resolveForPayment(Payment $payment): array
    {
        $member = $payment->member_id ? Member::find($payment->member_id) : null;
        $user   = $payment->user_id ? User::find($payment->user_id) : null;

        $profile = $this->findProfile($user?->id, $member?->id, $member?->identity_id);

        if ($profile && $profile->isComplete()) {
            return $this->fromProfile($profile);
        }

        return $this->consumerFinal(
            contactName: $member?->full_name ?? $user?->name,
            contactEmail: $member?->email ?? $user?->email,
            contactPhone: $member?->phone ?? $user?->phone,
        );
    }

    /** @return array<string,mixed> */
    public function resolveForSale(ProductSale $sale): array
    {
        $member = $sale->member_id ? Member::find($sale->member_id) : null;
        $profile = $this->findProfile(null, $member?->id, $member?->identity_id);

        if ($profile && $profile->isComplete()) {
            return $this->fromProfile($profile);
        }

        return $this->consumerFinal(
            contactName: $sale->customer_name ?? $member?->full_name,
            contactEmail: $member?->email,
            contactPhone: $member?->phone,
        );
    }

    private function findProfile(?int $userId, ?int $memberId, ?int $identityId): ?FiscalProfile
    {
        return FiscalProfile::query()
            ->when($identityId, fn ($q) => $q->orWhere('identity_id', $identityId))
            ->when($memberId, fn ($q) => $q->orWhere('member_id', $memberId))
            ->when($userId, fn ($q) => $q->orWhere('user_id', $userId))
            ->first();
    }

    /** @return array<string,mixed> */
    private function fromProfile(FiscalProfile $p): array
    {
        return [
            'doc_type'         => $p->doc_type,
            'doc_number'       => $p->doc_number,
            'dv'               => $p->dv,
            'name'             => $p->legal_name ?: ($p->user?->name ?? $p->member?->full_name),
            'legal_name'       => $p->legal_name,
            'person_type'      => $p->person_type,
            'email'            => $p->email ?: ($p->user?->email ?? $p->member?->email),
            'phone'            => $p->phone,
            'address'          => $p->address,
            'city_code'        => $p->city_code,
            'department_code'  => $p->department_code,
            'is_final_consumer' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function consumerFinal(?string $contactName, ?string $contactEmail, ?string $contactPhone): array
    {
        $cf = (array) config('billing.consumer_final');

        return [
            'doc_type'         => $cf['document_type'] ?? null,
            'doc_number'       => $cf['document_number'] ?? null,
            'dv'               => null,
            'name'             => $cf['name'] ?? 'Consumidor final',
            'legal_name'       => $cf['name'] ?? 'Consumidor final',
            'person_type'      => null,
            // Contacto real para entrega del comprobante (no es la identidad fiscal).
            'email'            => $contactEmail,
            'phone'            => $contactPhone,
            'address'          => null,
            'city_code'        => null,
            'department_code'  => null,
            'is_final_consumer' => true,
        ];
    }
}
