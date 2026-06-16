<?php

namespace App\Services\Contracts;

use App\Models\ContractAuditLog;
use App\Models\Member;
use App\Models\MemberContract;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Orquesta el ciclo de vida del contrato: borrador → firma → PDF → auditoría.
 * Mantiene snapshots inmutables (los cambios de perfil posteriores NO alteran
 * un contrato firmado) y nunca permite re-firmar un contrato ya firmado.
 */
class MemberContractService
{
    public function __construct(
        private ContractTemplateService $templates,
        private ContractPdfService $pdf,
    ) {
    }

    private const MONTHS_ES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    /** Clave de plantilla requerida para el miembro según mayoría de edad. */
    public function recommendedType(Member $member): string
    {
        if ($member->is_minor) {
            return 'minor_release';
        }

        return (string) Config::get('contracts.default_registration_template', 'workout_registration');
    }

    /** Estado de contratos para la app (GET /status). */
    public function statusFor(Member $member): array
    {
        $recommended = $this->recommendedType($member);

        $contracts = $member->contracts()->latest()->get();
        $signed = $contracts->where('status', MemberContract::STATUS_SIGNED);
        $pending = $contracts->whereIn('status', [MemberContract::STATUS_DRAFT, MemberContract::STATUS_PENDING])->first();

        $hasSignedRequired = $signed->contains(
            fn (MemberContract $c) => $c->contract_type === $recommended
        );

        return [
            'requires_contract'         => ! $hasSignedRequired,
            'recommended_contract_type' => $recommended,
            'is_minor'                  => (bool) $member->is_minor,
            'pending_contract'          => $pending ? $this->present($pending) : null,
            'signed_contracts'          => $signed->map(fn ($c) => $this->present($c))->values(),
            'missing_required_fields'   => $this->missingMemberFields($member),
            'checkboxes'                => $this->templates->checkboxes($recommended),
            'privacy_policy_url'        => Config::get('contracts.privacy_policy_url') ?: url('/api/legal/privacy'),
            'terms_url'                 => Config::get('contracts.terms_url') ?: url('/api/legal/terms'),
            'support_contact'           => Config::get('contracts.support_contact'),
        ];
    }

    /** Campos del perfil del miembro requeridos por la plantilla recomendada. */
    public function missingMemberFields(Member $member): array
    {
        $missing = [];
        foreach (['full_name', 'document_number', 'birth_date'] as $field) {
            if (blank($member->{$field})) {
                $missing[] = $field;
            }
        }
        // Para mayores pedimos contacto; para menor, lo aporta el acudiente al firmar.
        if (! $member->is_minor) {
            foreach (['phone', 'email'] as $field) {
                if (blank($member->{$field})) {
                    $missing[] = $field;
                }
            }
        }

        return $missing;
    }

    /** Crea (o reutiliza) un borrador para el tipo dado. */
    public function createOrGetDraft(Member $member, string $type, Request $request): MemberContract
    {
        $this->templates->assertSourceExists($type); // falla claro si falta plantilla
        $template = $this->templates->modelFor($type);

        $existing = $member->contracts()
            ->where('contract_type', $type)
            ->whereIn('status', [MemberContract::STATUS_DRAFT, MemberContract::STATUS_PENDING])
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        $contract = new MemberContract([
            'member_id'            => $member->id,
            'contract_template_id' => $template->id,
            'contract_type'        => $type,
            'status'               => MemberContract::STATUS_PENDING,
            'member_snapshot'      => $this->memberSnapshot($member),
            'template_version'     => $template->version,
            'ip_address'           => $request->ip(),
            'user_agent'           => substr((string) $request->userAgent(), 0, 500),
            'app_platform'         => $request->input('app_platform'),
            'app_version'          => $request->input('app_version'),
            'device_id'            => $request->input('device_id'),
        ]);
        $contract->save();
        $contract->folio = $this->makeFolio($contract);
        $contract->save();

        $this->audit($contract, ContractAuditLog::ACTOR_MEMBER, (string) $member->id, ContractAuditLog::ACTION_CREATED, $request);

        return $contract;
    }

    /**
     * Firma el contrato: valida checkboxes, arma snapshots, guarda la firma PNG,
     * genera el PDF fiel y lo persiste con su checksum. Idempotente frente a
     * doble envío: si ya está firmado, lanza error (no se re-firma).
     *
     * @param  array  $payload  datos del formulario + flags + metadatos
     * @param  string $signaturePng  bytes PNG de la firma
     */
    public function sign(MemberContract $contract, array $payload, string $signaturePng, Request $request): MemberContract
    {
        if ($contract->isLocked()) {
            throw ValidationException::withMessages([
                'contract' => 'Este contrato ya está firmado o anulado y no puede modificarse.',
            ]);
        }

        $member = $contract->member;
        $type = $contract->contract_type;
        $checkboxes = $this->templates->checkboxes($type);

        // Validar checkboxes obligatorios.
        $accept = (array) ($payload['acceptance'] ?? []);
        foreach ($checkboxes as $cb) {
            if (($cb['required'] ?? false) && empty($accept[$cb['key']])) {
                throw ValidationException::withMessages([
                    "acceptance.{$cb['key']}" => 'Debe aceptar: '.$cb['text'],
                ]);
            }
        }

        $now = Carbon::now();
        $signedAt = $now->copy();

        // Snapshots inmutables.
        $memberSnapshot = array_merge($this->memberSnapshot($member), array_filter([
            'full_name'       => $payload['full_name'] ?? null,
            'document_number' => $payload['document_number'] ?? null,
            'birth_date'      => $payload['birth_date'] ?? null,
            'rh'              => $payload['rh'] ?? null,
            'address'         => $payload['address'] ?? null,
            'phone'           => $payload['phone'] ?? null,
            'email'           => $payload['email'] ?? null,
        ], fn ($v) => $v !== null));

        $medicalSnapshot = [
            'medical_notes' => $payload['medical_notes'] ?? null,
            'injuries'      => $payload['injuries'] ?? null,
        ];

        $guardianSnapshot = null;
        if ($type === 'minor_release') {
            $guardianSnapshot = array_filter([
                'guardian_full_name'       => $payload['guardian_full_name'] ?? null,
                'guardian_document_number' => $payload['guardian_document_number'] ?? null,
                'guardian_document_city'   => $payload['guardian_document_city'] ?? null,
                'guardian_phone'           => $payload['guardian_phone'] ?? null,
                'guardian_email'           => $payload['guardian_email'] ?? null,
                'guardian_address'         => $payload['guardian_address'] ?? null,
                'guardian_city'            => $payload['guardian_city'] ?? null,
                'guardian_relationship'    => $payload['guardian_relationship'] ?? null,
                'minor_full_name'          => $payload['minor_full_name'] ?? $member->full_name,
                'minor_document_number'    => $payload['minor_document_number'] ?? $member->document_number,
            ], fn ($v) => $v !== null);
        }

        $acceptanceSnapshot = $this->buildAcceptanceSnapshot($checkboxes, $accept, $contract, $request, $payload, $signedAt);

        // Guardar la firma como ARCHIVO PNG privado (nunca base64 en DB).
        $disk = $this->templates->disk();
        $dir = "members/{$member->member_uuid}/contracts/{$contract->contract_uuid}";
        $sigPath = "{$dir}/signature.png";
        Storage::disk($disk)->put($sigPath, $signaturePng);
        $sigAbs = Storage::disk($disk)->path($sigPath);

        // Construir datos de estampado y generar el PDF fiel.
        [$fields, $multiline] = $this->buildStampData($type, $member, $payload, $signedAt);

        $audit = [
            'template_name'    => $this->templates->definition($type)['name'] ?? $type,
            'contract_type'    => $type,
            'template_version' => $contract->template_version,
            'folio'            => $contract->folio,
            'contract_uuid'    => $contract->contract_uuid,
            'member_name'      => $memberSnapshot['full_name'] ?? $member->full_name,
            'member_document'  => $memberSnapshot['document_number'] ?? $member->document_number,
            'signed_at'        => $signedAt->format('Y-m-d H:i:s T'),
            'ip_address'       => $request->ip(),
            'device_id'        => $payload['device_id'] ?? $contract->device_id,
            'app_platform'     => $payload['app_platform'] ?? $contract->app_platform,
            'app_version'      => $payload['app_version'] ?? $contract->app_version,
            'support_contact'  => Config::get('contracts.support_contact'),
            'checkboxes'       => array_map(fn ($cb) => [
                'text'  => $cb['text'],
                'value' => (bool) ($accept[$cb['key']] ?? false),
            ], $checkboxes),
        ];

        $pdfContent = $this->pdf->generate($type, $fields, $multiline, $sigAbs, $audit);

        $pdfPath = "{$dir}/contract_signed.pdf";
        Storage::disk($disk)->put($pdfPath, $pdfContent);
        $checksum = hash('sha256', $pdfContent);

        // Persistir en una transacción.
        DB::transaction(function () use ($contract, $memberSnapshot, $guardianSnapshot, $medicalSnapshot, $acceptanceSnapshot, $sigPath, $pdfPath, $checksum, $signedAt, $request, $payload) {
            $contract->fill([
                'status'              => MemberContract::STATUS_SIGNED,
                'member_snapshot'     => $memberSnapshot,
                'guardian_snapshot'   => $guardianSnapshot,
                'medical_snapshot'    => $medicalSnapshot,
                'acceptance_snapshot' => $acceptanceSnapshot,
                'signature_path'      => $sigPath,
                'signed_pdf_path'     => $pdfPath,
                'signed_pdf_checksum' => $checksum,
                'signed_at'           => $signedAt,
                'ip_address'          => $request->ip(),
                'user_agent'          => substr((string) $request->userAgent(), 0, 500),
                'device_id'           => $payload['device_id'] ?? $contract->device_id,
                'app_platform'        => $payload['app_platform'] ?? $contract->app_platform,
                'app_version'         => $payload['app_version'] ?? $contract->app_version,
            ])->save();

            $this->audit($contract, ContractAuditLog::ACTOR_MEMBER, (string) $contract->member_id, ContractAuditLog::ACTION_ACCEPTED, $request, ['checkboxes' => $acceptanceSnapshot]);
            $this->audit($contract, ContractAuditLog::ACTOR_MEMBER, (string) $contract->member_id, ContractAuditLog::ACTION_SIGNED, $request);
            $this->audit($contract, ContractAuditLog::ACTOR_SYSTEM, null, ContractAuditLog::ACTION_PDF_GENERATED, $request, ['checksum' => $checksum]);

            // Integración: reflejar flags en la tabla legacy member_legal_consents.
            $this->syncLegacyConsent($contract, $acceptanceSnapshot);
        });

        return $contract->fresh();
    }

    /** Anulación auditada (solo admin). El PDF firmado NO se borra ni reescribe. */
    public function void(MemberContract $contract, string $reason, ?string $actorId, Request $request): MemberContract
    {
        if ($contract->isVoid()) {
            return $contract;
        }

        $contract->fill([
            'status'      => MemberContract::STATUS_VOID,
            'voided_at'   => Carbon::now(),
            'void_reason' => $reason,
        ])->save();

        $this->audit($contract, ContractAuditLog::ACTOR_ADMIN, $actorId, ContractAuditLog::ACTION_VOIDED, $request, ['reason' => $reason]);

        return $contract->fresh();
    }

    /** Registra una descarga en la bitácora de auditoría. */
    public function recordDownload(MemberContract $contract, string $actorType, ?string $actorId, Request $request): void
    {
        $this->audit($contract, $actorType, $actorId, ContractAuditLog::ACTION_DOWNLOADED, $request);
    }

    /** Representación pública segura del contrato (sin rutas internas). */
    public function present(MemberContract $contract): array
    {
        return [
            'id'               => $contract->id,
            'uuid'             => $contract->contract_uuid,
            'folio'            => $contract->folio,
            'contract_type'    => $contract->contract_type,
            'status'           => $contract->status,
            'template_version' => $contract->template_version,
            'signed_at'        => optional($contract->signed_at)->toIso8601String(),
            'checksum'         => $contract->signed_pdf_checksum,
            'has_pdf'          => ! empty($contract->signed_pdf_path),
            'image_authorized' => $this->imageAuthorized($contract),
            'created_at'       => optional($contract->created_at)->toIso8601String(),
        ];
    }

    /**
     * Estado de la autorización de uso de imagen (checkbox opcional) leída del
     * acceptance_snapshot. Devuelve null si el contrato aún no se ha firmado.
     */
    public function imageAuthorized(MemberContract $contract): ?bool
    {
        foreach ((array) $contract->acceptance_snapshot as $item) {
            if (($item['key'] ?? null) === 'image_use') {
                return (bool) ($item['value'] ?? false);
            }
        }

        return null;
    }

    // ── Helpers internos ──────────────────────────────────────────────────────

    private function memberSnapshot(Member $member): array
    {
        return [
            'member_uuid'     => $member->member_uuid,
            'full_name'       => $member->full_name,
            'document_number' => $member->document_number,
            'birth_date'      => optional($member->birth_date)->format('Y-m-d'),
            'email'           => $member->email,
            'phone'           => $member->phone,
            'is_minor'        => (bool) $member->is_minor,
        ];
    }

    private function buildAcceptanceSnapshot(array $checkboxes, array $accept, MemberContract $contract, Request $request, array $payload, Carbon $signedAt): array
    {
        $items = [];
        foreach ($checkboxes as $cb) {
            $items[] = [
                'key'              => $cb['key'],
                'text'             => $cb['text'],
                'required'         => (bool) ($cb['required'] ?? false),
                'value'            => (bool) ($accept[$cb['key']] ?? false),
                'accepted_at'      => $signedAt->toIso8601String(),
                'template_version' => $contract->template_version,
                'app_version'      => $payload['app_version'] ?? $contract->app_version,
                'locale'           => $payload['locale'] ?? $request->getPreferredLanguage(),
                'ip_address'       => $request->ip(),
                'user_agent'       => substr((string) $request->userAgent(), 0, 500),
            ];
        }

        return $items;
    }

    /** Mapea snapshot/payload → claves de campo de la plantilla para estampar. */
    private function buildStampData(string $type, Member $member, array $payload, Carbon $signedAt): array
    {
        $birth = $payload['birth_date'] ?? optional($member->birth_date)->format('Y-m-d');
        $birthFmt = $birth ? Carbon::parse($birth)->format('d/m/Y') : '';

        if ($type === 'minor_release') {
            $minorName = $payload['minor_full_name'] ?? $member->full_name;
            $minorDoc = $payload['minor_document_number'] ?? $member->document_number;
            $city = $payload['sign_city'] ?? $payload['guardian_city'] ?? 'Neiva';

            $fields = [
                'guardian_full_name'        => (string) ($payload['guardian_full_name'] ?? ''),
                'guardian_document_number'  => (string) ($payload['guardian_document_number'] ?? ''),
                'guardian_document_city'    => (string) ($payload['guardian_document_city'] ?? ''),
                'minor_full_name_intro'     => (string) $minorName,
                'minor_full_name'           => (string) $minorName,
                'minor_document_number'     => (string) $minorDoc,
                'sign_city'                 => (string) $city,
                'sign_day'                  => $signedAt->format('d'),
                'sign_month'                => self::MONTHS_ES[(int) $signedAt->format('n')],
                'sign_year'                 => $signedAt->format('y'),
                'minor_full_name_footer'    => (string) $minorName,
                'guardian_full_name_footer' => (string) ($payload['guardian_full_name'] ?? ''),
                'guardian_phone'            => (string) ($payload['guardian_phone'] ?? ''),
                'guardian_address'          => (string) ($payload['guardian_address'] ?? ''),
                'guardian_city'             => (string) ($payload['guardian_city'] ?? $city),
            ];

            return [$fields, []];
        }

        // Plantillas de inscripción (adult): basic_registration / workout_registration.
        $fields = [
            'fecha'           => $signedAt->format('d/m/Y'),
            'full_name'       => (string) ($payload['full_name'] ?? $member->full_name),
            'document_number' => (string) ($payload['document_number'] ?? $member->document_number),
            'birth_date'      => $birthFmt,
            'rh'              => (string) ($payload['rh'] ?? ''),
            'address'         => (string) ($payload['address'] ?? ''),
            'phone'           => (string) ($payload['phone'] ?? $member->phone),
            'email'           => (string) ($payload['email'] ?? $member->email),
        ];

        $multiline = [
            'medical_notes' => (string) ($payload['medical_notes'] ?? ''),
            'injuries'      => (string) ($payload['injuries'] ?? $member->injuries ?? ''),
        ];

        return [$fields, $multiline];
    }

    private function makeFolio(MemberContract $contract): string
    {
        return 'IB-'.now()->format('Y').'-'.str_pad((string) $contract->id, 6, '0', STR_PAD_LEFT);
    }

    private function audit(MemberContract $contract, string $actorType, ?string $actorId, string $action, Request $request, array $metadata = []): void
    {
        ContractAuditLog::create([
            'member_contract_id' => $contract->id,
            'actor_type'         => $actorType,
            'actor_id'           => $actorId,
            'action'             => $action,
            'metadata'           => $metadata ?: null,
            'ip_address'         => $request->ip(),
            'user_agent'         => substr((string) $request->userAgent(), 0, 500),
            'created_at'         => Carbon::now(),
        ]);
    }

    /**
     * Refleja los flags aceptados en la tabla legacy member_legal_consents
     * (integración con el onboarding existente). No crea un sistema paralelo.
     */
    private function syncLegacyConsent(MemberContract $contract, array $acceptanceSnapshot): void
    {
        $checkboxes = collect($this->templates->checkboxes($contract->contract_type))->keyBy('key');
        $values = collect($acceptanceSnapshot)->pluck('value', 'key');

        $columns = [];
        foreach ($checkboxes as $key => $cb) {
            $col = $cb['consent_column'] ?? null;
            if ($col) {
                $columns[$col] = (bool) ($values[$key] ?? false);
            }
        }

        if (empty($columns)) {
            return;
        }

        $member = $contract->member;
        $consent = $member->legalConsent()->firstOrNew([]);
        $consent->fill($columns);
        $consent->contract_version = $contract->template_version;
        $consent->accepted_at = $contract->signed_at;
        $member->legalConsent()->save($consent);
    }
}
