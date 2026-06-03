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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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

        Log::info('contract:onboarding:draft:start', ['member_id' => $member->id, 'type' => $type]);
        try {
            $contract = $this->contracts->createOrGetDraft($member, $type, $request);
        } catch (ContractTemplateException $e) {
            Log::warning('contract:onboarding:draft:error', ['type' => $type, 'message' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 503);
        }
        Log::info('contract:onboarding:draft:success', ['contract_uuid' => $contract->contract_uuid]);

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
        Log::info('contract:onboarding:sign:start', ['contract_uuid' => $model->contract_uuid]);

        [$png, $reason] = $this->normalizeSignaturePng($request);
        if ($png === null) {
            Log::warning('contract:onboarding:sign:error', ['reason' => $reason]);

            return response()->json(['message' => $reason], 422);
        }

        try {
            $signed = $this->contracts->sign($model, $request->all(), $png, $request);
        } catch (ContractTemplateException $e) {
            Log::warning('contract:onboarding:sign:error', ['type' => 'template', 'message' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 503);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // 422 con errores de validación (checkboxes, etc.)
        } catch (Throwable $e) {
            // Cualquier fallo (p. ej. generación de PDF): NO se marca signed; se
            // devuelve JSON de error claro (nunca respuesta corrupta) y se loguea.
            Log::error('contract:onboarding:sign:error', [
                'type' => $e::class,
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()).':'.$e->getLine(),
            ]);

            return response()->json([
                'message' => 'No pudimos generar el contrato firmado. Intenta de nuevo.',
            ], 500);
        }

        Log::info('contract:onboarding:sign:success', ['contract_uuid' => $signed->contract_uuid]);

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
    /**
     * Normaliza la firma a un PNG embebible por TCPDF. Devuelve [png, error].
     *
     * Clave: TCPDF SOLO puede incrustar PNG con canal alfa si hay GD/Imagick.
     * Por eso: un PNG sin alfa se acepta tal cual (TCPDF lo parsea en PHP puro);
     * si llega un PNG con alfa u otro formato, se intenta aplanar sobre blanco
     * con GD/Imagick; y si no hay ninguna de las dos, se rechaza con 422 claro
     * (nunca se deja que TCPDF aborte y corrompa la respuesta).
     */
    private function normalizeSignaturePng(Request $request): array
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
            return [null, 'La firma es obligatoria.'];
        }

        $isPng = str_starts_with($raw, "\x89PNG");
        $hasGd = function_exists('imagecreatefromstring');
        $hasImagick = class_exists('Imagick');

        // PNG sin canal alfa → embebible directamente (no requiere GD).
        if ($isPng && ! $this->pngHasAlpha($raw)) {
            return [$raw, null];
        }

        // GD disponible: aplanar sobre fondo blanco (quita alfa) y re-encodear.
        if ($hasGd) {
            $img = @imagecreatefromstring($raw);
            if ($img !== false) {
                $w = imagesx($img);
                $h = imagesy($img);
                $flat = imagecreatetruecolor($w, $h);
                $white = imagecolorallocate($flat, 255, 255, 255);
                imagefilledrectangle($flat, 0, 0, $w, $h, $white);
                imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
                ob_start();
                imagepng($flat);
                $png = (string) ob_get_clean();
                imagedestroy($img);
                imagedestroy($flat);

                return $png !== '' ? [$png, null] : [null, 'No pudimos procesar la firma.'];
            }
        }

        // Imagick disponible: aplanar alfa.
        if ($hasImagick) {
            try {
                $im = new \Imagick();
                $im->setBackgroundColor('white');
                $im->readImageBlob($raw);
                $im->setImageBackgroundColor('white');
                $im = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                $im->setImageFormat('png24');

                return [$im->getImageBlob(), null];
            } catch (Throwable) {
                // cae al rechazo controlado
            }
        }

        // PNG con alfa (u otro formato) y sin GD/Imagick: no embebible de forma
        // segura. Rechazo claro en vez de dejar que TCPDF aborte la respuesta.
        return [null, 'No pudimos procesar la firma. Vuelve a firmar e inténtalo de nuevo.'];
    }

    /** ¿El PNG tiene canal alfa (gray+alpha=4, RGBA=6, o paleta con tRNS)? */
    private function pngHasAlpha(string $raw): bool
    {
        if (strlen($raw) < 26) {
            return false;
        }
        $colorType = ord($raw[25]); // byte de color-type del chunk IHDR
        if ($colorType === 4 || $colorType === 6) {
            return true;
        }
        if ($colorType === 3 && str_contains($raw, 'tRNS')) {
            return true;
        }

        return false;
    }
}
