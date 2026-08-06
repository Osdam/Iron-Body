<?php

namespace App\Services\Commercial\Tools\App;

use App\Models\Member;
use App\Services\Commercial\Tools\BaseTool;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolResult;
use App\Services\MembershipService;

/**
 * Si esta persona tiene cuenta en la aplicación, y si la membresía se le
 * refleja allí.
 *
 * Las dos cosas, y por separado, porque son problemas distintos con respuestas
 * distintas. «No tengo cuenta» se resuelve guiando el registro; «tengo cuenta
 * pero no me aparece la membresía» es casi siempre una ficha sin enlazar, y
 * decirle a esa persona que se registre otra vez le crea un segundo usuario y
 * empeora el problema que venía a reportar.
 *
 * No vincula nada. Enlazar una cuenta a una ficha es una operación de identidad
 * y, hecha mal, le da a alguien acceso a los datos de otro; eso lo hace una
 * persona desde el CRM.
 */
class GetAppAccountStatusTool extends BaseTool
{
    public function __construct(private readonly MembershipService $memberships) {}

    public function name(): string
    {
        return 'get_app_account_status';
    }

    public function description(): string
    {
        return 'Comprueba si la persona tiene cuenta en la app y si su membresía aparece allí. '
            .'Úsala antes de explicarle cómo entrar: puede que el problema sea otro.';
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
        return 'commercial.tools.app';
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
                'has_account' => false,
                'membership_visible_in_app' => false,
            ], 'Todavía no es socia, así que aún no le toca tener cuenta.');
        }

        $member = Member::query()->with('user')->find($memberId);
        $user = $member?->user;

        if ($user === null) {
            return ToolResult::ok([
                'is_member' => true,
                'has_account' => false,
                'membership_visible_in_app' => false,
                'next_step' => 'guide_registration',
            ], 'Tiene ficha de socio pero no hay cuenta de app enlazada. Guíala en el registro.');
        }

        $active = $this->memberships->isActive($user);

        return ToolResult::ok([
            'is_member' => true,
            'has_account' => true,
            'membership_visible_in_app' => $active,
            // El caso que hay que distinguir: cuenta sí, membresía no visible.
            'next_step' => $active ? null : 'escalate_membership_not_reflected',
        ], $active
            ? 'Tiene cuenta y la membresía se le refleja.'
            : 'Tiene cuenta pero la membresía no aparece vigente. No le pidas registrarse otra vez: escala el caso.');
    }
}
