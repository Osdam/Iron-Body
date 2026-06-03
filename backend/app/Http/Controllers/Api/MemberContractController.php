<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SignMemberContractRequest;
use App\Models\ContractAuditLog;
use App\Models\Member;
use App\Models\MemberContract;
use App\Services\Contracts\ContractTemplateException;
use App\Services\Contracts\ContractTemplateService;
use App\Services\Contracts\MemberContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Endpoints de contratos para el miembro autenticado (auth.member). El miembro
 * SOLO ve/firma/descarga sus propios contratos. Los PDFs viven en disco privado
 * y nunca se exponen por URL pública.
 */
class MemberContractController extends Controller
{
    public function __construct(
        private MemberContractService $contracts,
        private ContractTemplateService $templates,
    ) {
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('auth_member');
    }

    /**
     * GET /api/contracts/consent-template  (PÚBLICO, sin auth)
     * Devuelve SOLO la configuración estática del consentimiento (textos de
     * checkboxes + URLs) para que la creación de cuenta muestre el contrato
     * real ANTES de que exista el miembro. No expone ningún dato personal.
     */
    public function consentTemplate(Request $request): JsonResponse
    {
        $isMinor = filter_var($request->query('is_minor', false), FILTER_VALIDATE_BOOLEAN);
        $type = $isMinor
            ? 'minor_release'
            : (string) config('contracts.default_registration_template', 'workout_registration');

        return response()->json([
            'contract_type'      => $type,
            'template_name'      => config("contracts.templates.{$type}.name", $type),
            'is_minor'           => $isMinor,
            'checkboxes'         => $this->templates->checkboxes($type),
            'privacy_policy_url' => config('contracts.privacy_policy_url') ?: url('/api/legal/privacy'),
            'terms_url'          => config('contracts.terms_url') ?: url('/api/legal/terms'),
            'support_contact'    => config('contracts.support_contact'),
        ]);
    }

    /** GET /api/member/contracts/status */
    public function status(Request $request): JsonResponse
    {
        return response()->json($this->contracts->statusFor($this->member($request)));
    }

    /** GET /api/member/contracts */
    public function index(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $list = $member->contracts()->latest()->get()
            ->map(fn (MemberContract $c) => $this->contracts->present($c))->values();

        return response()->json(['data' => $list]);
    }

    /** POST /api/member/contracts/draft */
    public function draft(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $type = (string) $request->input(
            'contract_type',
            $this->contracts->recommendedType($member)
        );

        if (! in_array($type, $this->templates->allKeys(), true)) {
            return response()->json(['message' => "Tipo de contrato no válido: {$type}."], 422);
        }

        try {
            $contract = $this->contracts->createOrGetDraft($member, $type, $request);
        } catch (ContractTemplateException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(['data' => $this->contracts->present($contract)], 201);
    }

    /** GET /api/member/contracts/{contract}/preview */
    public function preview(Request $request, string $contract): JsonResponse
    {
        $model = $this->resolveOwned($request, $contract);
        $type = $model->contract_type;

        return response()->json([
            'data' => [
                'contract'         => $this->contracts->present($model),
                'template_name'    => $this->templates->definition($type)['name'] ?? $type,
                'checkboxes'       => $this->templates->checkboxes($type),
                'prefill'          => $model->member_snapshot,
                'privacy_policy_url' => config('contracts.privacy_policy_url'),
                'terms_url'        => config('contracts.terms_url'),
                'support_contact'  => config('contracts.support_contact'),
            ],
        ]);
    }

    /** POST /api/member/contracts/{contract}/sign */
    public function sign(SignMemberContractRequest $request, string $contract): JsonResponse
    {
        $model = $this->resolveOwned($request, $contract);

        $png = $this->normalizeSignaturePng($request);
        if ($png === null) {
            return response()->json(['message' => 'Firma inválida o vacía.'], 422);
        }

        try {
            $signed = $this->contracts->sign($model, $request->all(), $png, $request);
        } catch (ContractTemplateException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(['data' => $this->contracts->present($signed)]);
    }

    /** GET /api/member/contracts/{contract}/download */
    public function download(Request $request, string $contract): StreamedResponse|JsonResponse
    {
        $model = $this->resolveOwned($request, $contract);

        if (! $model->signed_pdf_path || ! Storage::disk($this->templates->disk())->exists($model->signed_pdf_path)) {
            return response()->json(['message' => 'El PDF firmado no está disponible.'], 404);
        }

        $this->contracts->recordDownload($model, ContractAuditLog::ACTOR_MEMBER, (string) $model->member_id, $request);

        $filename = 'contrato_'.$model->contract_type.'_'.$model->folio.'.pdf';

        return Storage::disk($this->templates->disk())->download($model->signed_pdf_path, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** Resuelve el contrato por uuid/id verificando que pertenezca al miembro. */
    private function resolveOwned(Request $request, string $key): MemberContract
    {
        $member = $this->member($request);

        $query = MemberContract::query()->where('member_id', $member->id);
        $model = Str::isUuid($key)
            ? $query->where('contract_uuid', $key)->first()
            : (ctype_digit($key) ? $query->where('id', (int) $key)->first() : null);

        abort_if($model === null, 404);

        return $model;
    }

    /**
     * Normaliza la firma entrante (archivo o base64) a bytes PNG. Devuelve null
     * si no se pudo decodificar una imagen válida.
     */
    private function normalizeSignaturePng(Request $request): ?string
    {
        $raw = null;

        if ($request->hasFile('signature')) {
            $raw = (string) file_get_contents($request->file('signature')->getRealPath());
        } elseif (filled($request->input('signature_image'))) {
            $data = (string) $request->input('signature_image');
            if (str_contains($data, ',')) {
                $data = substr($data, strpos($data, ',') + 1); // quitar prefijo data URI
            }
            $decoded = base64_decode($data, true);
            $raw = $decoded === false ? null : $decoded;
        }

        if ($raw === null || $raw === '') {
            return null;
        }

        // Si ya es PNG, devolver tal cual.
        if (str_starts_with($raw, "\x89PNG")) {
            return $raw;
        }

        // Convertir a PNG con GD (si está disponible) para garantizar el formato.
        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($raw);
            if ($img !== false) {
                ob_start();
                imagesavealpha($img, true);
                imagepng($img);
                $png = (string) ob_get_clean();
                imagedestroy($img);

                return $png !== '' ? $png : null;
            }
        }

        return null;
    }
}
