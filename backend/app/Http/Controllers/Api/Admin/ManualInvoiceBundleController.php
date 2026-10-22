<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ManualEmissionRejectedException;
use App\Http\Controllers\Controller;
use App\Models\FiscalProfile;
use App\Models\Member;
use App\Services\Audit\AuditTrail;
use App\Services\Billing\FiscalProfileResolver;
use App\Services\Billing\InvoiceEmail;
use App\Services\Billing\ManualInvoiceBundleService;
use App\Support\Access\AdminActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Facturación electrónica → Emitir manual (multiconcepto).
 *
 * Vista previa y emisión son rutas distintas a propósito: previsualizar no
 * escribe nada, y emitir exige la huella de la vista previa que la persona
 * confirmó. Las dos exigen una PERSONA: el token compartido de
 * automatizaciones no autoriza actos fiscales ante la DIAN.
 */
class ManualInvoiceBundleController extends Controller
{
    public function __construct(private ManualInvoiceBundleService $bundles) {}

    // GET /api/admin/electronic-invoices/bundles/customers?q=
    public function customers(Request $request): JsonResponse
    {
        if ($denied = $this->requirePerson($request)) {
            return $denied;
        }
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 3) {
            return response()->json(['ok' => true, 'data' => []]);
        }

        // Comodines escapados: «%%%» no puede convertirse en «todos los socios».
        // El escape es «!» y no la barra invertida: el analizador de marcadores
        // de PDO toma `'\'` como comilla escapada, el literal no se cierra y los
        // `?` siguientes desaparecen (SQLSTATE HY093 en PostgreSQL). Con un
        // ESCAPE explícito el tratamiento es el mismo en PostgreSQL y SQLite.
        $like = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
        $members = Member::query()
            ->where(fn ($w) => $w->whereRaw("lower(full_name) like ? escape '!'", ['%'.mb_strtolower($like).'%'])
                ->orWhereRaw("document_number like ? escape '!'", [$like.'%']))
            ->orderBy('full_name')->limit(15)
            ->get(['id', 'user_id', 'full_name', 'document_number', 'status']);

        return response()->json(['ok' => true, 'data' => $members->map(fn (Member $m) => [
            'id' => $m->id,
            'name' => $m->full_name,
            'document' => $m->document_number,
            'status' => $m->status,
        ])->all()]);
    }

    // GET /api/admin/electronic-invoices/bundles/members/{member}/candidates
    public function candidates(Request $request, Member $member): JsonResponse
    {
        if ($denied = $this->requirePerson($request)) {
            return $denied;
        }
        $profile = FiscalProfile::query()
            ->when($member->identity_id, fn ($q) => $q->orWhere('identity_id', $member->identity_id))
            ->orWhere('member_id', $member->id)
            ->when($member->user_id, fn ($q) => $q->orWhere('user_id', $member->user_id))
            ->first();
        $faltantes = FiscalProfileResolver::camposFaltantes($profile);

        return response()->json([
            'ok' => true,
            'customer' => [
                'id' => $member->id,
                'name' => $member->full_name,
                'document' => $member->document_number,
                'status' => $member->status,
                'fiscal_profile' => [
                    'exists' => $profile !== null,
                    'usable' => $faltantes === [],
                    'missing' => $faltantes,
                    'doc_type' => $profile?->doc_type,
                    'doc_number' => $profile?->doc_number,
                    'legal_name' => $profile?->legal_name,
                    'email' => $profile?->email,
                ],
                'delivery_email' => InvoiceEmail::primeroEntregable($profile?->email, $member->email),
            ],
            'data' => $this->bundles->candidates($member),
        ]);
    }

    // POST /api/admin/electronic-invoices/bundles/preview
    public function preview(Request $request): JsonResponse
    {
        if ($denied = $this->requirePerson($request)) {
            return $denied;
        }
        $data = $this->validateSelection($request);
        $member = Member::findOrFail($data['member_id']);

        try {
            $preview = $this->bundles->preview($member, $data['items'], $this->finalConsumer($data));
        } catch (ManualEmissionRejectedException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $e->status);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'data' => $preview]);
    }

    // POST /api/admin/electronic-invoices/bundles
    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->requirePerson($request)) {
            return $denied;
        }
        $data = $this->validateSelection($request, emitting: true);
        $member = Member::findOrFail($data['member_id']);
        $admin = AdminActor::from($request);

        try {
            $result = $this->bundles->emit(
                $member,
                $data['items'],
                $this->finalConsumer($data),
                $data['idempotency_key'],
                $data['preview_hash'],
                $admin?->id,
                $admin?->name,
            );
        } catch (ManualEmissionRejectedException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $e->status);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $invoice = $result['invoice'];

        if (! $result['replay']) {
            app(AuditTrail::class)->record($request, [
                'action' => 'create',
                'module' => 'Facturación electrónica',
                'entity' => 'factura multiconcepto',
                'entity_id' => (string) $invoice->id,
                'target_name' => $member->full_name,
                'summary' => sprintf('Autorizó la factura multiconcepto de %s por %s (%d operaciones)',
                    $member->full_name, number_format((float) $invoice->total, 0, ',', '.'), count($data['items'])),
                'metadata' => [
                    'bundle_uuid' => $result['bundle']->uuid,
                    'items' => array_values($data['items']),
                    'final_consumer' => $data['final_consumer'] ?? null,
                    'billing_enabled' => (bool) config('billing.enabled'),
                ],
            ]);
        }

        return response()->json([
            'ok' => true,
            'replay' => $result['replay'],
            'enabled' => (bool) config('billing.enabled'),
            'data' => $invoice->fresh()->toAdminArray(),
        ], $result['replay'] ? 200 : 201);
    }

    /** @return array<string,mixed> */
    private function validateSelection(Request $request, bool $emitting = false): array
    {
        return $request->validate(array_merge([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'items' => ['required', 'array', 'min:1', 'max:'.ManualInvoiceBundleService::MAX_ITEMS],
            'items.*' => ['required', 'string', 'max:40', 'regex:/^(payment|product_sale|commission_charge):[0-9]+$/'],
            'final_consumer' => ['nullable', 'boolean'],
        ], $emitting ? [
            'idempotency_key' => ['required', 'string', 'min:16', 'max:80', 'regex:/^[A-Za-z0-9-]+$/'],
            'preview_hash' => ['required', 'string', 'size:64'],
            'confirm' => ['required', 'accepted'],
        ] : []));
    }

    private function finalConsumer(array $data): ?bool
    {
        return array_key_exists('final_consumer', $data) && $data['final_consumer'] !== null
            ? (bool) $data['final_consumer']
            : null;
    }

    private function requirePerson(Request $request): ?JsonResponse
    {
        if (AdminActor::from($request) !== null) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'code' => 'person_required',
            'message' => 'Una factura solo la autoriza una persona con sesión en el CRM.',
        ], 403);
    }
}
