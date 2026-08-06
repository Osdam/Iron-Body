<?php

namespace App\Services\Commercial\Tools\Memberships;

use App\Models\Member;
use App\Services\Commercial\Tools\BaseTool;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolResult;
use App\Services\MembershipService;

/**
 * Vigencia de la membresía, tal como la ve el torniquete.
 *
 * Usa el mismo {@see MembershipService} que decide si alguien entra al
 * gimnasio. Preguntarle a otra fuente sería abrir la puerta a que el agente le
 * diga a un socio que está al día mientras el torniquete le dice que no, y esa
 * contradicción la paga el cliente en la recepción.
 */
class GetMembershipStatusTool extends BaseTool
{
    public function __construct(private readonly MembershipService $memberships) {}

    public function name(): string
    {
        return 'get_membership_status';
    }

    public function description(): string
    {
        return 'Consulta si la membresía de esta persona está vigente y hasta cuándo.';
    }

    public function schema(): array
    {
        return $this->strictSchema([]);
    }

    public function rules(): array
    {
        return [];
    }

    public function featureFlag(): ?string
    {
        return 'commercial.tools.memberships';
    }

    public function mutates(): bool
    {
        return false;
    }

    public function timeoutSeconds(): int
    {
        return 5;
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $memberId = $context->memberId();

        if ($memberId === null) {
            return ToolResult::ok([
                'is_member' => false,
                'active' => false,
            ], 'Esta persona todavía no es socia.');
        }

        $member = Member::query()->with('user')->find($memberId);
        $user = $member?->user;

        if ($user === null) {
            return ToolResult::ok([
                'is_member' => true,
                'active' => false,
                'has_app_account' => false,
            ], 'Tiene ficha de socio pero no hay cuenta con membresía asociada.');
        }

        $endsAt = $this->memberships->endsAt($user);
        $active = $this->memberships->isActive($user);

        return ToolResult::ok([
            'is_member' => true,
            'active' => $active,
            'status' => $this->memberships->status($user),
            'plan' => $user->plan,
            'ends_at' => $endsAt?->toDateString(),
            'days_remaining' => $this->memberships->daysRemaining($user),
            'has_app_account' => true,
        ]);
    }
}
