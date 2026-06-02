<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContractAuditLog;
use App\Models\Member;
use App\Models\MemberContract;
use App\Services\Contracts\MemberContractService;
use App\Services\Contracts\ContractTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contratos para el CRM (admin). Sigue el patrón de los demás endpoints
 * `admin/*` del proyecto. Los PDFs siguen en disco privado: se sirven por
 * streaming autenticado por la capa del CRM, nunca por URL pública.
 */
class ContractAdminController extends Controller
{
    public function __construct(
        private MemberContractService $contracts,
        private ContractTemplateService $templates,
    ) {
    }

    /** GET /api/admin/members/{member}/contracts */
    public function forMember(Member $member): JsonResponse
    {
        $list = $member->contracts()->latest()->get()
            ->map(fn (MemberContract $c) => $this->contracts->present($c))->values();

        return response()->json([
            'data'   => $list,
            'member' => $this->memberSummary($member),
        ]);
    }

    /** Resumen del miembro para el panel legal del CRM (estado biometría/menor). */
    private function memberSummary(Member $member): array
    {
        return [
            'id'               => $member->id,
            'is_minor'         => (bool) $member->is_minor,
            'biometric_status' => $member->biometric_status ?? 'pending',
        ];
    }

    /**
     * GET /api/admin/users/{user}/contracts
     * El listado de miembros del CRM usa el id de la tabla `users`; aquí se
     * resuelve el Member vinculado por `user_id`. Si no hay miembro asociado,
     * devuelve una lista vacía (no es un error).
     */
    public function forUser(int $user): JsonResponse
    {
        $member = Member::where('user_id', $user)->first();
        if (! $member) {
            return response()->json(['data' => [], 'member' => null]);
        }

        $list = $member->contracts()->latest()->get()
            ->map(fn (MemberContract $c) => $this->contracts->present($c))->values();

        return response()->json([
            'data'   => $list,
            'member' => $this->memberSummary($member),
        ]);
    }

    /** GET /api/admin/contracts/{contract} */
    public function show(string $contract): JsonResponse
    {
        $model = $this->resolve($contract);
        $model->load('member:id,full_name,document_number,is_minor');

        $audit = $model->auditLogs()->latest()->limit(100)->get()->map(fn (ContractAuditLog $l) => [
            'action'     => $l->action,
            'actor_type' => $l->actor_type,
            'actor_id'   => $l->actor_id,
            'ip_address' => $l->ip_address,
            'metadata'   => $l->metadata,
            'created_at' => optional($l->created_at)->toIso8601String(),
        ]);

        return response()->json([
            'data' => array_merge($this->contracts->present($model), [
                'member' => [
                    'id'              => $model->member->id,
                    'full_name'       => $model->member->full_name,
                    'document_number' => $model->member->document_number,
                    'is_minor'        => (bool) $model->member->is_minor,
                ],
                'member_snapshot'     => $model->member_snapshot,
                'guardian_snapshot'   => $model->guardian_snapshot,
                'medical_snapshot'    => $model->medical_snapshot,
                'acceptance_snapshot' => $model->acceptance_snapshot,
                'void_reason'         => $model->void_reason,
                'voided_at'           => optional($model->voided_at)->toIso8601String(),
                'audit_logs'          => $audit,
            ]),
        ]);
    }

    /** GET /api/admin/contracts/{contract}/download */
    public function download(Request $request, string $contract): StreamedResponse|JsonResponse
    {
        $model = $this->resolve($contract);

        if (! $model->signed_pdf_path || ! Storage::disk($this->templates->disk())->exists($model->signed_pdf_path)) {
            return response()->json(['message' => 'El PDF firmado no está disponible.'], 404);
        }

        $this->contracts->recordDownload($model, ContractAuditLog::ACTOR_ADMIN, $request->input('admin_id'), $request);

        $filename = 'contrato_'.$model->contract_type.'_'.$model->folio.'.pdf';

        return Storage::disk($this->templates->disk())->download($model->signed_pdf_path, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /** POST /api/admin/contracts/{contract}/void */
    public function void(Request $request, string $contract): JsonResponse
    {
        $data = $request->validate([
            'reason'   => ['required', 'string', 'min:5', 'max:500'],
            'admin_id' => ['nullable', 'string', 'max:80'],
        ]);

        $model = $this->resolve($contract);
        $voided = $this->contracts->void($model, $data['reason'], $data['admin_id'] ?? null, $request);

        return response()->json(['data' => $this->contracts->present($voided)]);
    }

    private function resolve(string $key): MemberContract
    {
        $model = Str::isUuid($key)
            ? MemberContract::where('contract_uuid', $key)->first()
            : (ctype_digit($key) ? MemberContract::find((int) $key) : null);

        abort_if($model === null, 404);

        return $model;
    }
}
