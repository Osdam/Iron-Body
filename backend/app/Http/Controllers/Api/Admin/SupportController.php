<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberAuthChallenge;
use App\Models\MemberDeviceBinding;
use App\Models\MemberSecurityEvent;
use App\Models\MemberSupportTicket;
use App\Services\DeviceConflictService;
use App\Services\DeviceSessionService;
use App\Services\NotificationService;
use App\Services\RealtimeEvents;
use App\Services\SecurityEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bandeja de Soporte del CRM: lista, detalle y gestión de estado de los reportes
 * que envían los miembros desde la app. Patrón del resto del CRM admin.
 *
 * Además de estado/nota, expone ACCIONES RÁPIDAS de resolución para los tickets
 * de acceso/seguridad ("perdí mi número", "me robaron el celular", ...): el staff
 * puede actualizar el teléfono del miembro, cerrar todas sus sesiones o liberar la
 * confianza del dispositivo, y vincular un miembro cuando el documento no coincidió.
 * Cada acción queda auditada (evento de seguridad + traza en el ticket).
 */
class SupportController extends Controller
{
    public function __construct(
        private DeviceSessionService $sessions,
        private SecurityEventService $security,
        private NotificationService $notifications,
        private DeviceConflictService $conflicts,
    ) {}

    /** GET /api/admin/support?status=&search=&page= */
    public function index(Request $request): JsonResponse
    {
        $query = MemberSupportTicket::query()->with('member:id,full_name')->orderByDesc('id');

        $status = (string) $request->query('status', '');
        if (in_array($status, ['new', 'in_progress', 'resolved'], true)) {
            $query->where('status', $status);
        }

        if ($request->filled('search')) {
            $like = '%'.trim((string) $request->query('search')).'%';
            $query->where(function ($q) use ($like): void {
                $q->where('message', 'like', $like)
                    ->orWhere('document', 'like', $like)
                    ->orWhere('type', 'like', $like)
                    ->orWhereHas('member', fn ($m) => $m->where('full_name', 'like', $like));
            });
        }

        $unread = MemberSupportTicket::where('status', MemberSupportTicket::STATUS_NEW)->count();
        $page = $query->paginate((int) min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'ok' => true,
            'data' => collect($page->items())->map->toPublicArray()->values(),
            'new_count' => $unread,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** GET /api/admin/support/{ticket} */
    public function show(MemberSupportTicket $ticket): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $ticket->load('member:id,full_name')->toPublicArray()]);
    }

    /** PATCH /api/admin/support/{ticket} — cambia estado / agrega nota interna. */
    public function update(Request $request, MemberSupportTicket $ticket): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:new,in_progress,resolved'],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $ticket->status = $data['status'];
            $ticket->resolved_at = $data['status'] === MemberSupportTicket::STATUS_RESOLVED ? now() : null;
        }
        if (array_key_exists('admin_note', $data)) {
            $ticket->admin_note = $data['admin_note'];
        }
        $ticket->save();

        return response()->json(['ok' => true, 'data' => $ticket->fresh('member')->toPublicArray()]);
    }

    /** GET /api/admin/support/unread-count */
    public function unreadCount(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'new_count' => MemberSupportTicket::where('status', MemberSupportTicket::STATUS_NEW)->count(),
        ]);
    }

    // ── Acciones rápidas de resolución ─────────────────────────────────────────

    /**
     * GET /api/admin/support/member-lookup?document= — busca un miembro por
     * documento para vincularlo a un ticket que llegó sin miembro (el documento
     * no coincidió al crear el reporte). Solo lectura, resumen mínimo.
     */
    public function memberLookup(Request $request): JsonResponse
    {
        $document = trim((string) $request->query('document', ''));
        if ($document === '') {
            return response()->json(['ok' => false, 'message' => 'Ingresa un documento para buscar.'], 422);
        }

        $member = $this->resolveMember($document);

        return response()->json([
            'ok' => true,
            'member' => $member ? $this->memberSummary($member) : null,
        ]);
    }

    /**
     * GET /api/admin/support/{ticket}/context — contexto del miembro vinculado
     * (teléfono enmascarado, dispositivos activos, confianza) para alimentar el
     * panel de acciones del CRM.
     */
    public function context(MemberSupportTicket $ticket): JsonResponse
    {
        $member = $ticket->member_id ? Member::find($ticket->member_id) : null;
        if (! $member) {
            return response()->json(['ok' => true, 'linked' => false]);
        }

        // "0 dispositivos / no confiable" NO significa que pueda entrar: el
        // vínculo que lo bloquea puede ser de OTRO titular, y esas dos métricas
        // solo miran las filas del propio miembro. Por eso se expone aparte.
        $conflict = $this->conflicts->findConflict($member);

        return response()->json([
            'ok' => true,
            'linked' => true,
            'member' => $this->memberSummary($member),
            'active_devices' => $this->sessions->activeSessions($member)->count(),
            'trusted_device' => MemberDeviceBinding::where('member_id', $member->id)->exists(),
            'device_conflict' => $conflict ? $this->conflicts->describe($conflict) : null,
        ]);
    }

    /**
     * POST /api/admin/support/{ticket}/link-member — vincula un miembro al ticket
     * para poder aplicar acciones. Body: { member_id }.
     */
    public function linkMember(Request $request, MemberSupportTicket $ticket): JsonResponse
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
        ]);

        $member = Member::findOrFail($data['member_id']);
        $ticket->member_id = $member->id;
        $ticket->user_id = $ticket->user_id ?: $member->user_id;
        if (! $ticket->document) {
            $ticket->document = $member->document_number;
        }
        $ticket->save();

        $this->trace($ticket, $request, 'Vinculó al miembro '.$member->full_name.'.', [
            'action' => 'link_member',
            'member_id' => $member->id,
        ]);

        return response()->json([
            'ok' => true,
            'data' => $ticket->fresh('member')->toPublicArray(),
        ]);
    }

    /**
     * POST /api/admin/support/{ticket}/phone — ACCIÓN ESTRELLA. El staff actualiza
     * el teléfono verificado del miembro (caso "perdí mi número" / "cambié de
     * teléfono" cuando el auto-servicio no fue posible). Body: { phone, resolve? }.
     */
    public function changePhone(Request $request, MemberSupportTicket $ticket): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'resolve' => ['nullable', 'boolean'],
        ]);

        $member = $this->requireMember($ticket);
        if ($member instanceof JsonResponse) {
            return $member;
        }

        $newPhone = $this->normalizeCo($data['phone']);
        if ($newPhone === null) {
            return response()->json(['ok' => false, 'message' => 'Ingresa un celular válido (10 dígitos, inicia en 3).'], 422);
        }
        if ($this->phoneInUseByOther($member, $newPhone)) {
            return response()->json(['ok' => false, 'message' => 'Ese número ya está registrado en otra cuenta.'], 422);
        }
        if ($this->sameDigits($newPhone, (string) $member->phone)) {
            return response()->json(['ok' => false, 'message' => 'Ese ya es el número de la cuenta.'], 422);
        }

        $member->forceFill(['phone' => $newPhone])->save();
        if ($member->user) {
            $member->user->forceFill(['phone' => $newPhone])->save();
        }

        $masked = MemberAuthChallenge::maskPhone($newPhone);
        $this->security->record($member, MemberSecurityEvent::TYPE_PHONE_CHANGED, $this->context_($request), [
            'source' => 'admin_support',
            'ticket_id' => $ticket->id,
            'admin_id' => $this->adminId($request),
            'masked_phone' => $masked,
        ]);
        $this->notifications->notifyPhoneChanged($member, $masked);
        RealtimeEvents::phone($member->id);

        $this->trace($ticket, $request, 'Actualizó el número del miembro a '.$masked.'.', [
            'action' => 'phone_change',
            'masked_phone' => $masked,
        ], resolve: (bool) ($data['resolve'] ?? false));

        return response()->json([
            'ok' => true,
            'message' => 'Número actualizado a '.$masked.'.',
            'data' => $ticket->fresh('member')->toPublicArray(),
        ]);
    }

    /**
     * POST /api/admin/support/{ticket}/revoke-devices — cierra TODAS las sesiones
     * del miembro (robo / actividad sospechosa). Auditada.
     */
    public function revokeDevices(Request $request, MemberSupportTicket $ticket): JsonResponse
    {
        $member = $this->requireMember($ticket);
        if ($member instanceof JsonResponse) {
            return $member;
        }

        $count = 0;
        foreach ($this->sessions->activeSessions($member) as $session) {
            $this->sessions->revoke($session, 'revoked_by_support');
            $count++;
        }

        $this->security->record($member, MemberSecurityEvent::TYPE_DEVICE_REVOKED, $this->context_($request), [
            'scope' => 'all_by_support',
            'ticket_id' => $ticket->id,
            'admin_id' => $this->adminId($request),
            'revoked_count' => $count,
        ]);

        $this->trace($ticket, $request, "Cerró {$count} sesión(es) del miembro.", [
            'action' => 'revoke_devices',
            'revoked_count' => $count,
        ]);

        return response()->json([
            'ok' => true,
            'revoked_count' => $count,
            'message' => "Se cerraron {$count} sesión(es).",
            'data' => $ticket->fresh('member')->toPublicArray(),
        ]);
    }

    /**
     * POST /api/admin/support/{ticket}/reset-trust — libera el/los vínculos de
     * dispositivo confiable del miembro para que pueda re-vincular un equipo nuevo.
     */
    public function resetTrust(Request $request, MemberSupportTicket $ticket): JsonResponse
    {
        $member = $this->requireMember($ticket);
        if ($member instanceof JsonResponse) {
            return $member;
        }

        $bindings = MemberDeviceBinding::where('member_id', $member->id)->get();
        $count = $bindings->count();
        foreach ($bindings as $binding) {
            $binding->delete();
        }

        $this->security->record($member, MemberSecurityEvent::TYPE_DEVICE_RELEASED, $this->context_($request), [
            'source' => 'admin_support',
            'ticket_id' => $ticket->id,
            'admin_id' => $this->adminId($request),
            'released_count' => $count,
        ]);

        $this->trace($ticket, $request, "Restableció la confianza de dispositivo ({$count} vínculo(s) liberado(s)).", [
            'action' => 'reset_trust',
            'released_count' => $count,
        ]);

        return response()->json([
            'ok' => true,
            'released_count' => $count,
            'message' => "Confianza restablecida ({$count} vínculo(s)).",
            'data' => $ticket->fresh('member')->toPublicArray(),
        ]);
    }

    /**
     * POST /api/admin/support/{ticket}/release-device — libera el equipo que
     * está bloqueando al miembro del ticket porque pertenece a OTRA cuenta.
     *
     * Es la acción que falta cuando "Restablecer confianza" devuelve 0: aquella
     * borra los vínculos DEL miembro; esta borra EL vínculo que lo bloquea, que
     * es de otro titular. Acotada a un único `device_id` —el de la denegación
     * registrada—, nunca a "todos los dispositivos".
     */
    public function releaseDevice(Request $request, MemberSupportTicket $ticket): JsonResponse
    {
        $member = $this->requireMember($ticket);
        if ($member instanceof JsonResponse) {
            return $member;
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:200'],
        ]);
        $reason = trim((string) ($data['reason'] ?? '')) ?: 'Liberación por soporte';

        $binding = $this->conflicts->findConflict($member);
        if (! $binding) {
            // Respuesta segura: no se distingue "no hay conflicto" de "ya se
            // liberó", y no se filtra nada sobre otros miembros.
            return response()->json([
                'ok' => false,
                'code' => 'no_device_conflict',
                'message' => 'Este miembro no tiene ningún equipo bloqueado por otra cuenta.',
            ], 409);
        }

        $ownerBefore = $binding->member;
        $released = $this->conflicts->release($binding, $reason);
        $maskedId = $this->conflicts->mask($released['device_id']);

        // Auditoría por partida doble: al titular que pierde el equipo y al
        // miembro que lo recupera. Ambos eventos guardan quién actuó y por qué.
        $audit = [
            'source' => 'admin_support',
            'ticket_id' => $ticket->id,
            'admin_id' => $this->adminId($request),
            'device_id' => $released['device_id'],
            'reason' => $reason,
            'revoked_sessions' => $released['revoked_sessions'],
        ];
        if ($ownerBefore) {
            $this->security->record($ownerBefore, MemberSecurityEvent::TYPE_DEVICE_RELEASED, $this->context_($request), $audit + [
                'released_for_member' => $member->id,
            ]);
        }
        $this->security->record($member, MemberSecurityEvent::TYPE_DEVICE_RELEASED, $this->context_($request), $audit + [
            'previous_member_id' => $released['previous_member_id'],
        ]);

        $this->trace($ticket, $request, "Liberó el equipo {$maskedId}, que estaba vinculado a otra cuenta ({$released['revoked_sessions']} sesión(es) cerrada(s)).", [
            'action' => 'release_device',
            'device_id_masked' => $maskedId,
            'previous_member_id' => $released['previous_member_id'],
            'revoked_sessions' => $released['revoked_sessions'],
            'reason' => $reason,
        ]);

        // SSE: el titular anterior debe enterarse de que perdió el equipo (su
        // sesión acaba de morir) y el miembro del ticket, de que ya puede
        // entrar. Sin esto, ambas apps se quedarían con un estado obsoleto.
        if ($released['previous_member_id']) {
            RealtimeEvents::security($released['previous_member_id']);
        }
        RealtimeEvents::security($member->id);

        return response()->json([
            'ok' => true,
            'released_device' => $maskedId,
            'revoked_sessions' => $released['revoked_sessions'],
            'message' => 'Equipo liberado. El miembro ya puede iniciar sesión desde él.',
            'data' => $ticket->fresh('member')->toPublicArray(),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** Miembro del ticket o respuesta 422 si no hay ninguno vinculado. */
    private function requireMember(MemberSupportTicket $ticket): Member|JsonResponse
    {
        $member = $ticket->member_id ? Member::find($ticket->member_id) : null;
        if (! $member) {
            return response()->json([
                'ok' => false,
                'message' => 'Vincula un miembro al ticket antes de aplicar esta acción.',
            ], 422);
        }

        return $member;
    }

    /** Resumen mínimo de un miembro para el panel de acciones. */
    private function memberSummary(Member $member): array
    {
        return [
            'id' => $member->id,
            'full_name' => $member->full_name,
            'document' => $member->document_number,
            'phone_masked' => MemberAuthChallenge::maskPhone($member->phone),
            'status' => $member->status,
        ];
    }

    /** Resuelve un miembro por documento (raw + solo dígitos), como en el reporte. */
    private function resolveMember(string $document): ?Member
    {
        $raw = trim($document);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return Member::query()
            ->where('document_number', $raw)
            ->when($digits !== '' && $digits !== $raw, fn ($q) => $q->orWhere('document_number', $digits))
            ->first();
    }

    /** Normaliza un celular colombiano a 10 dígitos (mismo criterio que la app). */
    private function normalizeCo(string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($d) === 12 && str_starts_with($d, '57')) {
            $d = substr($d, 2);
        }

        return (strlen($d) === 10 && str_starts_with($d, '3')) ? $d : null;
    }

    /** ¿El teléfono (por dígitos) pertenece a OTRO miembro? */
    private function phoneInUseByOther(Member $member, string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return false;
        }

        $query = Member::query()->where('id', '!=', $member->id);
        if (DB::connection()->getDriverName() === 'pgsql') {
            $query->where(function ($q) use ($phone, $digits): void {
                $q->where('phone', $phone)
                    ->orWhereRaw("regexp_replace(phone, '\\D', '', 'g') = ?", [$digits]);
            });
        } else {
            $query->where('phone', $phone);
        }

        return $query->exists();
    }

    /** ¿Dos teléfonos tienen los mismos dígitos? */
    private function sameDigits(?string $a, ?string $b): bool
    {
        $da = preg_replace('/\D+/', '', (string) $a) ?? '';
        $db = preg_replace('/\D+/', '', (string) $b) ?? '';

        return $da !== '' && $da === $db;
    }

    /** Contexto de red para auditoría (las acciones admin no traen device_id). */
    private function context_(Request $request): array
    {
        return [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];
    }

    /** Id del admin actuante (expuesto por EnsureAdminAuth), defensivo. */
    private function adminId(Request $request): ?int
    {
        $admin = $request->attributes->get('auth_admin');

        return is_object($admin) && isset($admin->id) ? (int) $admin->id : null;
    }

    /** Nombre legible del admin actuante para la traza. */
    private function adminName(Request $request): string
    {
        $admin = $request->attributes->get('auth_admin');
        if (is_object($admin)) {
            return (string) ($admin->name ?? $admin->email ?? 'Staff');
        }

        return 'Staff';
    }

    /**
     * Deja traza de la acción del staff en el ticket: línea legible en la nota
     * interna + entrada estructurada en metadata['support_actions']. Marca el
     * ticket en progreso (o resuelto si `resolve`).
     */
    private function trace(MemberSupportTicket $ticket, Request $request, string $summary, array $meta, bool $resolve = false): void
    {
        $stamp = now()->format('Y-m-d H:i');
        $line = "[{$stamp}] {$this->adminName($request)}: {$summary}";
        $ticket->admin_note = trim(($ticket->admin_note ? $ticket->admin_note."\n" : '').$line);

        $actions = (array) ($ticket->metadata['support_actions'] ?? []);
        $actions[] = array_merge($meta, [
            'admin_id' => $this->adminId($request),
            'at' => now()->toIso8601String(),
        ]);
        $ticket->metadata = array_merge((array) $ticket->metadata, ['support_actions' => $actions]);

        if ($resolve) {
            $ticket->status = MemberSupportTicket::STATUS_RESOLVED;
            $ticket->resolved_at = now();
        } elseif ($ticket->status === MemberSupportTicket::STATUS_NEW) {
            $ticket->status = MemberSupportTicket::STATUS_IN_PROGRESS;
        }

        $ticket->save();
    }
}
