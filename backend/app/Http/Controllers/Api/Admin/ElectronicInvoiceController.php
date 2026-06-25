<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateCreditNoteRequest;
use App\Http\Requests\Admin\ManualEmitInvoiceRequest;
use App\Jobs\SyncFactusInvoiceStatusJob;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ElectronicInvoice;
use App\Services\Billing\InvoicingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * API administrativa de facturación electrónica (CRM). Bajo /api/admin/* →
 * blindada globalmente por ProtectAdminPaths (sesión admin o token compartido).
 *
 * Toda llamada a Factus pasa por el backend (servicios/jobs). Nunca se exponen
 * secretos, tokens ni rutas internas de archivo; los logs ya van saneados.
 *
 * Permisos granulares (INVOICES_VIEW/EMIT/RETRY/CREDIT_NOTE/SETTINGS) quedan
 * pendientes hasta que exista RBAC en backend; hoy basta la auth admin.
 */
class ElectronicInvoiceController extends Controller
{
    public function __construct(private InvoicingService $invoicing)
    {
    }

    // GET /api/admin/electronic-invoices
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(5, (int) $request->input('per_page', 20)));

        $page = ElectronicInvoice::query()
            ->filter($request->only([
                'status', 'type', 'source_type', 'source_id',
                'number', 'cufe', 'document', 'customer', 'date_from', 'date_to',
            ]))
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (ElectronicInvoice $i) => $i->toAdminArray()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    // GET /api/admin/electronic-invoices/{invoice}
    public function show(ElectronicInvoice $invoice): JsonResponse
    {
        return response()->json(['data' => $invoice->toAdminDetailArray()]);
    }

    // GET /api/admin/electronic-invoices/stats
    public function stats(Request $request): JsonResponse
    {
        $from = $request->input('date_from');
        $to   = $request->input('date_to');

        $scoped = fn () => ElectronicInvoice::query()
            ->when($from, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($to, fn ($q, $v) => $q->whereDate('created_at', '<=', $v));

        $byStatus = $scoped()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $totalInvoiced = (float) $scoped()
            ->where('type', 'invoice')
            ->where('status', 'validated')
            ->sum('total');

        return response()->json([
            'data' => [
                'total_invoiced' => $totalInvoiced,
                'by_status'      => $byStatus,
                'total'          => (int) $scoped()->count(),
                'pending'        => (int) $scoped()->where('status', 'pending')->count(),
                'processing'     => (int) $scoped()->whereIn('status', ['processing', 'credit_note_processing'])->count(),
                'validated'      => (int) $scoped()->whereIn('status', ['validated', 'credit_note_validated'])->count(),
                'rejected'       => (int) $scoped()->whereIn('status', ['rejected', 'credit_note_rejected'])->count(),
                'error'          => (int) $scoped()->whereIn('status', ['error', 'credit_note_error'])->count(),
                'cancelled'      => (int) $scoped()->where('status', 'cancelled')->count(),
            ],
        ]);
    }

    // GET /api/admin/electronic-invoices/config
    public function config(): JsonResponse
    {
        // WHITELIST explícita: jamás se difunde config('billing') completa.
        // Nunca credentials, ni webhook.secret, ni tokens.
        $c = config('billing');

        return response()->json([
            'data' => [
                'enabled'        => (bool) ($c['enabled'] ?? false),
                'env'            => $c['env'] ?? 'sandbox',
                'base_url'       => $c['base_url'] ?? null,
                'queue'          => $c['queue'] ?? 'billing',
                'http'           => [
                    'timeout'       => $c['http']['timeout'] ?? null,
                    'retry_times'   => $c['http']['retry_times'] ?? null,
                    'retry_backoff' => $c['http']['retry_backoff'] ?? null,
                ],
                'company'        => $c['company'] ?? [],
                'numbering'      => $c['numbering'] ?? [],
                'defaults'       => $c['defaults'] ?? [],
                'consumer_final' => $c['consumer_final'] ?? [],
                'reconciliation' => $c['reconciliation'] ?? [],
                'storage'        => ['disk' => $c['storage']['disk'] ?? null],
                'webhook'        => ['enabled' => (bool) ($c['webhook']['enabled'] ?? false)],
            ],
        ]);
    }

    // POST /api/admin/electronic-invoices/manual-emit
    public function manualEmit(ManualEmitInvoiceRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $invoice = $this->invoicing->manualEmit(
                $data['source_type'],
                (int) $data['source_id'],
                (bool) ($data['force'] ?? true),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        if ($invoice === null) {
            return response()->json(['ok' => false, 'message' => 'No se pudo crear el comprobante.'], 422);
        }

        $this->audit($request, 'create', 'Emisión manual de factura', (string) $invoice->id, [
            'source_type' => $data['source_type'],
            'source_id'   => $data['source_id'],
            'enabled'     => (bool) config('billing.enabled'),
        ]);

        return response()->json([
            'ok'      => true,
            'enabled' => (bool) config('billing.enabled'),
            'data'    => $invoice->toAdminArray(),
        ], $invoice->wasRecentlyCreated ? 201 : 200);
    }

    // POST /api/admin/electronic-invoices/{invoice}/retry
    public function retry(Request $request, ElectronicInvoice $invoice): JsonResponse
    {
        if (! config('billing.enabled')) {
            return response()->json([
                'ok' => false,
                'message' => 'Facturación deshabilitada (FACTUS_ENABLED=false). No se reintenta.',
            ], 409);
        }
        if (! $invoice->status->canRetry()) {
            return response()->json([
                'ok' => false,
                'message' => 'El estado actual ('.$invoice->status->value.') no permite reintento.',
            ], 409);
        }

        $this->invoicing->retry($invoice);
        $this->audit($request, 'status', 'Reintento de factura', (string) $invoice->id);

        return response()->json(['ok' => true, 'data' => $invoice->fresh()->toAdminArray()]);
    }

    // POST /api/admin/electronic-invoices/{invoice}/sync
    public function sync(Request $request, ElectronicInvoice $invoice): JsonResponse
    {
        if (! config('billing.enabled')) {
            return response()->json([
                'ok' => false,
                'message' => 'Facturación deshabilitada (FACTUS_ENABLED=false). No se sincroniza.',
            ], 409);
        }
        if (empty($invoice->factus_id)) {
            return response()->json([
                'ok' => false,
                'message' => 'La factura no tiene identificador de Factus para sincronizar.',
            ], 422);
        }

        SyncFactusInvoiceStatusJob::dispatch($invoice->id)->onQueue(config('billing.queue', 'billing'));
        $this->audit($request, 'status', 'Sincronización de factura', (string) $invoice->id);

        return response()->json(['ok' => true, 'message' => 'Sincronización encolada.']);
    }

    // GET /api/admin/electronic-invoices/{invoice}/pdf
    public function pdf(ElectronicInvoice $invoice)
    {
        return $this->downloadFile($invoice, 'pdf');
    }

    // GET /api/admin/electronic-invoices/{invoice}/xml
    public function xml(ElectronicInvoice $invoice)
    {
        return $this->downloadFile($invoice, 'xml');
    }

    // POST /api/admin/electronic-invoices/{invoice}/credit-note
    public function creditNote(CreateCreditNoteRequest $request, ElectronicInvoice $invoice): JsonResponse
    {
        // Anulación/nota crédito: acción sensible restringida al dueño/admin.
        // Token compartido (n8n) o rol menor → 403.
        if (! $this->isOwnerOrAdmin($request)) {
            return response()->json([
                'ok' => false,
                'message' => 'Solo el dueño o un administrador puede emitir notas crédito.',
            ], 403);
        }

        try {
            $note = $this->invoicing->createCreditNote($invoice, $request->validated()['reason']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $this->audit($request, 'create', 'Nota crédito de factura', (string) $note->id, [
            'references_invoice_id' => $invoice->id,
        ]);

        return response()->json([
            'ok'      => true,
            'enabled' => (bool) config('billing.enabled'),
            'data'    => $note->toAdminArray(),
        ], $note->wasRecentlyCreated ? 201 : 200);
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /**
     * Descarga segura: si hay archivo en disco privado, se sirve por streaming;
     * si solo hay URL (Factus), se redirige. Nunca se expone el path interno.
     */
    private function downloadFile(ElectronicInvoice $invoice, string $kind)
    {
        $path = $kind === 'pdf' ? $invoice->pdf_path : $invoice->xml_path;
        $url  = $kind === 'pdf' ? $invoice->pdf_url : $invoice->xml_url;
        $disk = (string) config('billing.storage.disk', 'local');

        if ($path && Storage::disk($disk)->exists($path)) {
            $name = ($invoice->full_number ?: $invoice->uuid).'.'.$kind;
            return Storage::disk($disk)->download($path, $name);
        }

        if ($url) {
            return redirect()->away($url);
        }

        return response()->json(['ok' => false, 'message' => 'Archivo no disponible.'], 404);
    }

    /** ¿El actor es dueño o administrador (no token compartido ni rol menor)? */
    private function isOwnerOrAdmin(Request $request): bool
    {
        $admin = $request->attributes->get('auth_admin');
        if (! $admin instanceof Admin) {
            return false; // token compartido / sin sesión admin real
        }

        return in_array($admin->role, [Admin::ROLE_SUPER_ADMIN, Admin::ROLE_ADMINISTRADOR], true);
    }

    /** Traza de auditoría de acciones administrativas (append-only). */
    private function audit(Request $request, string $action, string $summary, string $entityId, array $metadata = []): void
    {
        $admin = $request->attributes->get('auth_admin');

        AuditLog::create([
            'action'      => $action,
            'module'      => 'billing',
            'entity'      => 'electronic_invoice',
            'entity_id'   => $entityId,
            'actor_id'    => $admin?->id,
            'actor_name'  => $admin?->name ?? 'CRM',
            'actor_role'  => $admin?->role,
            'summary'     => $summary,
            'metadata'    => $metadata ?: null,
            'ip_address'  => $request->ip(),
            'user_agent'  => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
