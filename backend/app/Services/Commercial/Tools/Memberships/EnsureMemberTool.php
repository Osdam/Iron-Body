<?php

namespace App\Services\Commercial\Tools\Memberships;

use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Services\Commercial\Tools\BaseTool;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolResult;
use App\Services\Observability\ChannelLog;
use App\Services\Wompi\PaymentStateMachine as SM;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea la ficha de socio, y solo después de que haya pagado.
 *
 * Dos reglas irrenunciables, las dos aprendidas de cómo se rompe esto en la
 * práctica:
 *
 *  1. **No se crea un socio sin pago confirmado.** La confirmación se lee de la
 *     transacción, que solo escriben el webhook firmado y la reconciliación
 *     oficial. Ni la palabra del cliente ni una captura de pantalla.
 *
 *  2. **No se duplica una persona.** Se busca por documento y por teléfono
 *     antes de crear nada. Un socio duplicado parte el historial en dos: las
 *     asistencias quedan en una ficha y los pagos en otra, y a partir de ahí
 *     ninguna decisión comercial sobre esa persona es correcta.
 *
 * Cuando la identidad es AMBIGUA —un documento que apunta a alguien y un
 * teléfono que apunta a otro— no se elige ni se fusiona: se manda a revisión
 * humana. Fusionar mal dos personas es mucho más caro de reparar que esperar.
 */
class EnsureMemberTool extends BaseTool
{
    public function name(): string
    {
        return 'ensure_member';
    }

    public function description(): string
    {
        return 'Crea la ficha de socio de esta persona tras un pago confirmado, '
            .'o devuelve la que ya existe. Requiere el documento de identidad.';
    }

    public function schema(): array
    {
        return $this->strictSchema([
            'document_number' => $this->stringProp('Número de documento, tal como lo dio la persona.'),
            'full_name' => $this->stringProp('Nombre completo.'),
            'payment_reference' => $this->stringProp('Referencia del pago confirmado que respalda el alta.'),
        ], ['document_number', 'full_name', 'payment_reference']);
    }

    public function rules(): array
    {
        return [
            'document_number' => ['required', 'string', 'max:40', 'regex:/^[0-9A-Za-z\-]+$/'],
            'full_name' => ['required', 'string', 'max:150'],
            'payment_reference' => ['required', 'string', 'max:120'],
        ];
    }

    public function featureFlag(): ?string
    {
        return 'commercial.tools.memberships';
    }

    public function timeoutSeconds(): int
    {
        return 15;
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $lead = $context->lead;

        if ($lead === null) {
            return ToolResult::failed('no_lead_in_context', 'No hay prospecto al que dar de alta.');
        }

        // ── Regla 1: sin pago confirmado no hay socio ───────────────────────
        $payment = PaymentTransaction::query()
            ->where('reference', $arguments['payment_reference'])
            ->first(['id', 'status', 'member_id', 'plan_id', 'amount']);

        if ($payment === null) {
            return ToolResult::failed('payment_not_found', 'Esa referencia de pago no existe.');
        }

        if ((string) $payment->status !== SM::APPROVED) {
            return ToolResult::failed(
                'payment_not_confirmed',
                'Ese pago no está confirmado por la pasarela. No se puede crear la membresía todavía.',
                // Reintentable: el pago puede confirmarse en unos minutos.
                retryable: true,
            );
        }

        if ($lead->member_id !== null) {
            return ToolResult::skipped('Esta persona ya tiene ficha de socio.', [
                'member_id' => $lead->member_id,
            ]);
        }

        // ── Regla 2: no duplicar personas ───────────────────────────────────
        $document = trim((string) $arguments['document_number']);
        $phone = $lead->phone;

        $byDocument = Member::query()->where('document_number', $document)->first();
        $byPhone = $phone !== null
            ? Member::query()->where('phone', $phone)->first()
            : null;

        // Identidad ambigua: el documento dice una persona y el teléfono otra.
        // No se decide ni se fusiona.
        if ($byDocument !== null && $byPhone !== null && $byDocument->id !== $byPhone->id) {
            ChannelLog::warning('commercial.identity.ambiguous', [
                'lead_id' => $lead->id,
                'member_by_document' => $byDocument->id,
                'member_by_phone' => $byPhone->id,
            ]);

            return ToolResult::failed(
                'ambiguous_identity',
                'El documento y el teléfono apuntan a dos fichas distintas. '
                    .'Esto lo tiene que resolver una persona: no fusiones nada.',
            );
        }

        $existing = $byDocument ?? $byPhone;

        if ($existing !== null) {
            // Ya existía: se enlaza, no se crea otra.
            $lead->forceFill([
                'member_id' => $existing->id,
                'status' => \App\Models\MarketingLead::STATUS_CONVERTED,
                'converted_at' => now(),
            ])->save();

            return ToolResult::skipped('Ya existía una ficha para esta persona; se enlazó.', [
                'member_id' => $existing->id,
                'matched_by' => $byDocument !== null ? 'document' : 'phone',
            ]);
        }

        $member = DB::transaction(function () use ($arguments, $lead, $document, $payment) {
            $member = Member::create([
                'full_name' => trim((string) $arguments['full_name']),
                'document_number' => $document,
                'phone' => $lead->phone,
                'access_hash' => Str::random(64),
                'status' => Member::STATUS_ACTIVE,
            ]);

            $lead->forceFill([
                'member_id' => $member->id,
                'status' => \App\Models\MarketingLead::STATUS_CONVERTED,
                'converted_at' => now(),
            ])->save();

            // El pago se ata a la ficha para que la activación de membresía —que
            // hace el flujo de pagos ya existente— encuentre a quién activar.
            if ($payment->member_id === null) {
                $payment->forceFill(['member_id' => $member->id])->save();
            }

            return $member;
        });

        ChannelLog::info('commercial.member.created', [
            'member_id' => $member->id,
            'lead_id' => $lead->id,
        ]);

        return ToolResult::ok([
            'member_id' => $member->id,
            'full_name' => $member->full_name,
        ], 'Ficha de socio creada.');
    }
}
