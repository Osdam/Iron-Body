<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateFiscalProfileRequest;
use App\Models\AuditLog;
use App\Models\FiscalProfile;
use App\Models\Member;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Perfiles fiscales de usuarios/miembros (datos DIAN para factura nominativa).
 * NO bloquea pagos: si falta el perfil, la facturación usa consumidor final.
 * Bajo /api/admin/* → blindado por ProtectAdminPaths.
 */
class FiscalProfileController extends Controller
{
    // GET /api/admin/users/{user}/fiscal-profile
    public function showForUser(User $user): JsonResponse
    {
        $profile = FiscalProfile::where('user_id', $user->id)->first();

        return response()->json(['data' => $this->serialize($profile)]);
    }

    // PUT /api/admin/users/{user}/fiscal-profile
    public function updateForUser(UpdateFiscalProfileRequest $request, User $user): JsonResponse
    {
        $profile = FiscalProfile::updateOrCreate(
            ['user_id' => $user->id],
            $request->validated(),
        );
        $this->audit($request, 'fiscal_profile', (string) $profile->id, ['user_id' => $user->id]);

        return response()->json(['data' => $this->serialize($profile)]);
    }

    // GET /api/admin/members/{member}/fiscal-profile
    public function showForMember(Member $member): JsonResponse
    {
        $profile = FiscalProfile::where('member_id', $member->id)->first();

        return response()->json(['data' => $this->serialize($profile)]);
    }

    // PUT /api/admin/members/{member}/fiscal-profile
    public function updateForMember(UpdateFiscalProfileRequest $request, Member $member): JsonResponse
    {
        $profile = FiscalProfile::updateOrCreate(
            ['member_id' => $member->id],
            array_merge($request->validated(), [
                // Liga a la identidad central si el miembro la tiene (1:1).
                'identity_id' => $member->identity_id,
            ]),
        );
        $this->audit($request, 'fiscal_profile', (string) $profile->id, ['member_id' => $member->id]);

        return response()->json(['data' => $this->serialize($profile)]);
    }

    // ── Internos ────────────────────────────────────────────────────────────

    private function serialize(?FiscalProfile $p): ?array
    {
        if ($p === null) {
            return null;
        }

        return [
            'id'                   => $p->id,
            'user_id'              => $p->user_id,
            'member_id'            => $p->member_id,
            'identity_id'          => $p->identity_id,
            'doc_type'             => $p->doc_type,
            'doc_number'           => $p->doc_number,
            'dv'                   => $p->dv,
            'person_type'          => $p->person_type,
            'legal_name'           => $p->legal_name,
            'tax_responsibilities' => $p->tax_responsibilities,
            'email'                => $p->email,
            'phone'                => $p->phone,
            'address'              => $p->address,
            'city_code'            => $p->city_code,
            'department_code'      => $p->department_code,
            'is_complete'          => $p->isComplete(),
            'updated_at'           => optional($p->updated_at)->toIso8601String(),
        ];
    }

    private function audit(Request $request, string $entity, string $entityId, array $metadata = []): void
    {
        $admin = $request->attributes->get('auth_admin');

        AuditLog::create([
            'action'     => 'update',
            'module'     => 'billing',
            'entity'     => $entity,
            'entity_id'  => $entityId,
            'actor_id'   => $admin?->id,
            'actor_name' => $admin?->name ?? 'CRM',
            'actor_role' => $admin?->role,
            'summary'    => 'Actualización de perfil fiscal',
            'metadata'   => $metadata ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
