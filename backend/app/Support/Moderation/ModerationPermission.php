<?php

namespace App\Support\Moderation;

use App\Models\Admin;

/**
 * Permisos de moderación y su mapeo a los roles del CRM.
 *
 * El CRM NO tiene tabla de permisos: los deriva del `role` del admin
 * (`Admin::ROLES`), y el front hace lo mismo en `AccessControlService`. Para no
 * inventar un sistema paralelo, este mapa es el espejo servidor de esa política
 * y es la ÚNICA autoridad: el front puede ocultar un botón, pero quien decide
 * es el backend.
 *
 * Principio: NO se conceden todos los permisos a cualquier administrador.
 *  - Recepción / Administrativo: solo ver y clasificar.
 *  - Administrador: revisa, oculta contenido, advierte y suspende funciones
 *    sociales. NO puede eliminar contenido definitivamente ni bloquear la app
 *    entera ni tocar la configuración de moderación.
 *  - Super Admin: acciones permanentes y evidencia sensible.
 *
 * El token compartido de automatizaciones (`config('admin.api_token')`) NO
 * resuelve a un Admin: {@see resolveRole()} lo trata como `null` y por tanto
 * solo obtiene lectura. Ninguna sanción puede aplicarse con ese token.
 */
final class ModerationPermission
{
    public const VIEW = 'moderation.view';

    public const REVIEW = 'moderation.review';

    public const ASSIGN = 'moderation.assign';

    public const HIDE_CONTENT = 'moderation.hide_content';

    public const REMOVE_CONTENT = 'moderation.remove_content';

    public const WARN_MEMBER = 'moderation.warn_member';

    public const SUSPEND_SOCIAL = 'moderation.suspend_social';

    public const SUSPEND_FULL_ACCESS = 'moderation.suspend_full_access';

    public const RESOLVE_APPEALS = 'moderation.resolve_appeals';

    public const VIEW_SENSITIVE_EVIDENCE = 'moderation.view_sensitive_evidence';

    public const MANAGE_SETTINGS = 'moderation.manage_settings';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::VIEW,
            self::REVIEW,
            self::ASSIGN,
            self::HIDE_CONTENT,
            self::REMOVE_CONTENT,
            self::WARN_MEMBER,
            self::SUSPEND_SOCIAL,
            self::SUSPEND_FULL_ACCESS,
            self::RESOLVE_APPEALS,
            self::VIEW_SENSITIVE_EVIDENCE,
            self::MANAGE_SETTINGS,
        ];
    }

    /**
     * Permisos ELEVADOS: irreversibles o de máximo alcance. Reservados a
     * Super Admin por defecto.
     *
     * @return list<string>
     */
    public static function elevated(): array
    {
        return [
            self::REMOVE_CONTENT,
            self::SUSPEND_FULL_ACCESS,
            self::VIEW_SENSITIVE_EVIDENCE,
            self::MANAGE_SETTINGS,
        ];
    }

    /**
     * Mapa rol → permisos.
     *
     * @return array<string, list<string>>
     */
    public static function byRole(): array
    {
        $reception = [self::VIEW];

        $administrative = array_merge($reception, [
            self::REVIEW,
            self::ASSIGN,
        ]);

        $administrator = array_merge($administrative, [
            self::HIDE_CONTENT,
            self::WARN_MEMBER,
            self::SUSPEND_SOCIAL,
            self::RESOLVE_APPEALS,
        ]);

        return [
            Admin::ROLE_SUPER_ADMIN => self::all(),
            Admin::ROLE_ADMINISTRADOR => $administrator,
            Admin::ROLE_ADMINISTRATIVO => $administrative,
            Admin::ROLE_RECEPCION => $reception,
        ];
    }

    /**
     * Permisos efectivos de un admin. `null` (token de automatización) obtiene
     * SOLO lectura: nunca puede sancionar.
     *
     * @return list<string>
     */
    public static function forAdmin(?Admin $admin): array
    {
        if (! $admin instanceof Admin) {
            return [self::VIEW];
        }

        return self::byRole()[$admin->role] ?? [self::VIEW];
    }

    public static function allows(?Admin $admin, string $permission): bool
    {
        return in_array($permission, self::forAdmin($admin), true);
    }

    public static function isElevated(string $permission): bool
    {
        return in_array($permission, self::elevated(), true);
    }
}
