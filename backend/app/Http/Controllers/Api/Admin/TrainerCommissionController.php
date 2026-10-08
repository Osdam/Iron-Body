<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Trainer;
use App\Models\TrainerCommissionAgreement;
use App\Services\Audit\AuditTrail;
use App\Exceptions\CashShiftException;
use App\Exceptions\ReceivableException;
use App\Services\Commissions\TrainerCommissions;
use App\Support\Access\AdminActor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * EL PISO DE LOS ENTRENADORES: pactarlo, cobrarlo y ver a quién le falta.
 *
 * Antes esto se registraba como un PLAN llamado «Comisión de personalizados»,
 * y un plan define la membresía de quien lo tiene: había socios cuya vigencia
 * la fijaba una comisión. Este módulo es su sitio propio.
 *
 * TRES PERMISOS, PORQUE SON TRES DECISIONES DE DISTINTO PESO
 *   `commissions.view`   → ver el panel del mes y el histórico.
 *   `commissions.charge` → registrar que pagó, o abrirle la deuda. Mostrador.
 *   `commissions.manage` → pactar el valor y con quién. Eso es precio, y el
 *                          precio no lo cambia quien cobra.
 */
class TrainerCommissionController extends Controller
{
    /** GET /api/admin/commissions/board?period=YYYY-MM */
    public function board(Request $request, TrainerCommissions $servicio): JsonResponse
    {
        return response()->json($servicio->board($this->period($request)));
    }

    /** GET /api/admin/commissions/agreements */
    public function index(Request $request): JsonResponse
    {
        $acuerdos = TrainerCommissionAgreement::query()
            ->when(
                ! $request->boolean('include_ended'),
                fn ($q) => $q->where('active', true)
            )
            ->with(['trainer:id,full_name', 'member:id,full_name,document_number'])
            ->orderByDesc('active')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TrainerCommissionAgreement $a) => $this->agreementArray($a));

        return response()->json(['data' => $acuerdos]);
    }

    /** POST /api/admin/commissions/agreements */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trainer_id' => ['required', 'integer', 'exists:trainers,id'],
            'member_id' => ['required', 'integer', 'exists:members,id'],
            // El valor es del trato. 50.000 es lo habitual, no una regla.
            'amount' => ['required', 'numeric', 'min:1', 'max:99999999'],
            'payer' => ['required', 'in:'.implode(',', TrainerCommissionAgreement::PAYERS)],
            // Si se pasa de la fecha, ¿le retira al cliente los beneficios de
            // la app? Nace apagado: ver la migración.
            'blocks_app' => ['nullable', 'boolean'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $existente = TrainerCommissionAgreement::query()
            ->where('trainer_id', $data['trainer_id'])
            ->where('member_id', $data['member_id'])
            ->first();

        if ($existente && $existente->active) {
            return response()->json([
                'ok' => false,
                'message' => 'Ya hay un acuerdo vigente entre ese entrenador y ese cliente. Edítalo en vez de crear otro.',
            ], 422);
        }

        $actor = AdminActor::from($request);

        // Reabrir el que existía en vez de chocar con el índice único: el trato
        // con esta persona es el mismo, se retomó.
        $acuerdo = $existente ?: new TrainerCommissionAgreement();
        $acuerdo->fill(array_merge($data, [
            'blocks_app' => (bool) ($data['blocks_app'] ?? false),
            'active' => true,
            'ended_at' => null,
            'ended_by_name' => null,
            'created_by' => $acuerdo->created_by ?? $actor?->id,
            'created_by_name' => $acuerdo->created_by_name ?? $actor?->name,
        ]));
        $acuerdo->save();

        $this->audit($request, $acuerdo, 'create', 'Pactó el piso de un cliente personalizado');

        return response()->json($this->agreementArray($acuerdo->fresh(['trainer', 'member'])), 201);
    }

    /** PUT /api/admin/commissions/agreements/{agreement} */
    public function update(Request $request, TrainerCommissionAgreement $agreement): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['sometimes', 'numeric', 'min:1', 'max:99999999'],
            'payer' => ['sometimes', 'in:'.implode(',', TrainerCommissionAgreement::PAYERS)],
            'blocks_app' => ['nullable', 'boolean'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        // Cambiar el trato NO reescribe lo ya cobrado: cada mes guardó su
        // importe y su pagador. Octubre siguió costando lo que costó.
        $agreement->fill($data)->save();

        $this->audit($request, $agreement, 'update', 'Cambió el piso de un cliente personalizado');

        return response()->json($this->agreementArray($agreement->fresh(['trainer', 'member'])));
    }

    /**
     * DELETE /api/admin/commissions/agreements/{agreement} — se acabó el trato.
     *
     * No se borra: se cierra. Los meses ya cobrados siguen siendo ciertos y las
     * deudas abiertas siguen debiéndose; lo que se acaba es seguir cobrándolo.
     */
    public function destroy(Request $request, TrainerCommissionAgreement $agreement): JsonResponse
    {
        $actor = AdminActor::from($request);

        $agreement->update([
            'active' => false,
            'ended_at' => now(),
            'ended_by_name' => $actor?->name,
        ]);

        $this->audit($request, $agreement, 'delete', 'Terminó el piso de un cliente personalizado');

        return response()->json($this->agreementArray($agreement->fresh(['trainer', 'member'])));
    }

    /**
     * POST /api/admin/commissions/agreements/{agreement}/charge
     *
     * Con `method` se registra que pagó ahora; sin él se le abre la deuda. Son
     * la misma operación porque en el mostrador son el mismo momento: «vengo a
     * pagar octubre» y «de octubre te quedas debiendo» se deciden ahí.
     */
    public function charge(Request $request, TrainerCommissionAgreement $agreement, TrainerCommissions $servicio): JsonResponse
    {
        $data = $request->validate([
            'period' => ['nullable', 'date_format:Y-m'],
            'method' => ['nullable', 'string', 'max:30'],
            'due_at' => ['nullable', 'date_format:Y-m-d'],
            // Un mes a medias o un descuento puntual. No toca el acuerdo.
            'amount' => ['nullable', 'numeric', 'min:1', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $cobro = $servicio->charge(
                $agreement,
                $this->period($request),
                $data['method'] ?? null,
                AdminActor::from($request),
                isset($data['due_at']) ? CarbonImmutable::parse($data['due_at']) : null,
                isset($data['amount']) ? (float) $data['amount'] : null,
                $data['notes'] ?? null,
            );
        } catch (CashShiftException $e) {
            // El caso real: no hay caja del gimnasio abierta. Se dice qué hacer,
            // no el nombre de la excepción.
            return response()->json([
                'ok' => false,
                'message' => 'No hay una caja del gimnasio abierta. Ábrela para registrar el pago, o deja la deuda abierta sin cobrar ahora.',
            ], 422);
        } catch (ReceivableException|RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        app(AuditTrail::class)->record($request, [
            'action' => 'create',
            'module' => 'Comisiones',
            'entity' => 'piso de entrenador',
            'entity_id' => (string) $agreement->id,
            'target_name' => $agreement->trainer?->full_name,
            'summary' => ($data['method'] ?? null)
                ? 'Registró el pago del piso de '.$cobro['period_label']
                : 'Abrió la deuda del piso de '.$cobro['period_label'],
            'metadata' => [
                'period' => $cobro['period'],
                'amount' => $cobro['amount'],
                'payer' => $cobro['payer'],
                'receivable_id' => $cobro['receivable_id'],
            ],
        ]);

        return response()->json(['ok' => true, 'charge' => $cobro], 201);
    }

    /** GET /api/admin/commissions/agreements/{agreement}/history */
    public function history(TrainerCommissionAgreement $agreement, TrainerCommissions $servicio): JsonResponse
    {
        return response()->json([
            'agreement' => $this->agreementArray($agreement->load(['trainer', 'member'])),
            'charges' => $servicio->history($agreement),
        ]);
    }

    /**
     * GET /api/admin/commissions/people — los entrenadores.
     *
     * Solo ellos. Los socios NO vienen aquí: son más de tres mil, y mandarlos
     * todos para que el navegador pinte un desplegable con tres mil opciones
     * es una descarga grande para un problema que no se resuelve —nadie
     * encuentra a nadie en una lista así—. Los socios se buscan.
     */
    public function people(): JsonResponse
    {
        return response()->json([
            'trainers' => Trainer::query()
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'document', 'status', 'main_specialty'])
                ->map(fn (Trainer $t) => [
                    'id' => $t->id,
                    'name' => $t->full_name,
                    'document' => $t->document,
                    'specialty' => $t->main_specialty,
                    'active' => $t->status === 'active',
                ]),
        ]);
    }

    /**
     * GET /api/admin/commissions/members?q= — buscar al cliente.
     *
     * Por nombre o por documento, que es como se busca a alguien que está
     * delante: o se sabe cómo se llama, o se tiene su cédula en la mano.
     *
     * Sin término no se devuelve nada. Es a propósito: una lista «de muestra»
     * invita a elegir de ella, y en tres mil socios la muestra casi nunca
     * contiene a quien se busca.
     */
    public function members(Request $request): JsonResponse
    {
        $termino = trim((string) $request->input('q', ''));

        if (mb_strlen($termino) < 2) {
            return response()->json(['data' => [], 'hint' => 'Escribe al menos dos letras o el documento.']);
        }

        $driver = Member::query()->getConnection()->getDriverName();
        // `ilike` en PostgreSQL —allí LIKE distingue mayúsculas—; en SQLite y
        // MySQL el collation por defecto ya es insensible.
        $operador = $driver === 'pgsql' ? 'ilike' : 'like';
        $patron = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $termino).'%';

        $socios = Member::query()
            ->where(function ($q) use ($operador, $patron): void {
                $q->where('full_name', $operador, $patron)
                    ->orWhere('document_number', $operador, $patron);
            })
            ->orderBy('full_name')
            ->limit(25)
            ->get(['id', 'full_name', 'document_number', 'status'])
            ->map(fn (Member $m) => [
                'id' => $m->id,
                'name' => $m->full_name,
                'document' => $m->document_number,
                'status' => $m->status,
            ]);

        return response()->json(['data' => $socios]);
    }

    // ────────────────────────────────────────────────────────────────────────

    /** El mes sobre el que se trabaja. Sin decir nada, el actual. */
    private function period(Request $request): CarbonImmutable
    {
        $pedido = (string) $request->input('period', '');

        if (preg_match('/^\d{4}-\d{2}$/', $pedido)) {
            return CarbonImmutable::parse($pedido.'-01')->startOfMonth();
        }

        return CarbonImmutable::now()->startOfMonth();
    }

    /** @return array<string, mixed> */
    private function agreementArray(TrainerCommissionAgreement $a): array
    {
        return [
            'id' => $a->id,
            'trainer' => ['id' => $a->trainer_id, 'name' => $a->trainer?->full_name],
            'member' => ['id' => $a->member_id, 'name' => $a->member?->full_name],
            'amount' => (float) $a->amount,
            'payer' => $a->payer,
            'payer_label' => $a->payerLabel(),
            'blocks_app' => (bool) $a->blocks_app,
            'active' => (bool) $a->active,
            'starts_on' => $a->starts_on?->toDateString(),
            'ends_on' => $a->ends_on?->toDateString(),
            'notes' => $a->notes,
            'by' => $a->created_by_name,
            'ended_at' => $a->ended_at?->toIso8601String(),
            'ended_by' => $a->ended_by_name,
        ];
    }

    private function audit(Request $request, TrainerCommissionAgreement $a, string $action, string $resumen): void
    {
        app(AuditTrail::class)->record($request, [
            'action' => $action,
            'module' => 'Comisiones',
            'entity' => 'piso de entrenador',
            'entity_id' => (string) $a->id,
            'target_name' => $a->trainer?->full_name,
            'summary' => $resumen,
            'metadata' => [
                'trainer_id' => $a->trainer_id,
                'member_id' => $a->member_id,
                'amount' => (float) $a->amount,
                'payer' => $a->payer,
            ],
        ]);
    }
}
