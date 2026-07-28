<?php

namespace App\Support\Moderation;

/**
 * Tipos de acción administrativa y su permiso requerido.
 *
 * `requiredPermission()` es la ÚNICA fuente de verdad de qué permiso exige
 * cada acción: el controlador la consulta, nunca replica la tabla. Las
 * acciones irreversibles o de alcance total exigen permiso ELEVADO
 * (`moderation.suspend_full_access` / `moderation.remove_content`), que por
 * defecto solo tiene Super Admin.
 */
final class ActionType
{
    public const WARN = 'warn';

    public const HIDE_CONTENT = 'hide_content';

    public const RESTORE_CONTENT = 'restore_content';

    public const REMOVE_CONTENT = 'remove_content';

    public const RESTRICT_POSTING = 'restrict_posting';

    public const SUSPEND_SOCIAL = 'suspend_social';

    public const SUSPEND_FULL = 'suspend_full';

    public const DISMISS = 'dismiss';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::WARN,
            self::HIDE_CONTENT,
            self::RESTORE_CONTENT,
            self::REMOVE_CONTENT,
            self::RESTRICT_POSTING,
            self::SUSPEND_SOCIAL,
            self::SUSPEND_FULL,
            self::DISMISS,
        ];
    }

    public static function requiredPermission(string $action): string
    {
        return match ($action) {
            self::HIDE_CONTENT, self::RESTORE_CONTENT => ModerationPermission::HIDE_CONTENT,
            self::REMOVE_CONTENT => ModerationPermission::REMOVE_CONTENT,
            self::WARN => ModerationPermission::WARN_MEMBER,
            self::RESTRICT_POSTING, self::SUSPEND_SOCIAL => ModerationPermission::SUSPEND_SOCIAL,
            self::SUSPEND_FULL => ModerationPermission::SUSPEND_FULL_ACCESS,
            default => ModerationPermission::REVIEW,
        };
    }

    /** Alcance de sanción que implica cada acción. */
    public static function scopeFor(string $action): string
    {
        return match ($action) {
            self::RESTRICT_POSTING => ModerationScope::STORY_POSTING,
            self::SUSPEND_SOCIAL => ModerationScope::SOCIAL_FEATURES,
            self::SUSPEND_FULL => ModerationScope::FULL_APP_ACCESS,
            default => ModerationScope::CONTENT_ONLY,
        };
    }

    /** ¿La acción crea una suspensión consultable en caliente? */
    public static function createsSuspension(string $action): bool
    {
        return in_array($action, [
            self::RESTRICT_POSTING,
            self::SUSPEND_SOCIAL,
            self::SUSPEND_FULL,
        ], true);
    }

    /**
     * ¿Requiere revisión humana obligatoria y confirmación explícita?
     * Ninguna regla automática puede ejecutar estas acciones.
     */
    public static function isIrreversible(string $action): bool
    {
        return $action === self::REMOVE_CONTENT;
    }

    /** Resolución que se asigna al caso al aplicar la acción. */
    public static function resolutionFor(string $action): string
    {
        return match ($action) {
            self::DISMISS => ReportStatus::RESOLUTION_NO_VIOLATION,
            self::HIDE_CONTENT => ReportStatus::RESOLUTION_CONTENT_HIDDEN,
            self::REMOVE_CONTENT => ReportStatus::RESOLUTION_CONTENT_REMOVED,
            self::WARN => ReportStatus::RESOLUTION_MEMBER_WARNED,
            self::RESTRICT_POSTING => ReportStatus::RESOLUTION_MEMBER_RESTRICTED,
            self::SUSPEND_SOCIAL, self::SUSPEND_FULL => ReportStatus::RESOLUTION_MEMBER_SUSPENDED,
            default => ReportStatus::RESOLUTION_NO_VIOLATION,
        };
    }

    public static function label(string $action): string
    {
        return [
            self::WARN => 'Advertencia',
            self::HIDE_CONTENT => 'Contenido oculto',
            self::RESTORE_CONTENT => 'Contenido restaurado',
            self::REMOVE_CONTENT => 'Contenido eliminado',
            self::RESTRICT_POSTING => 'Restricción de publicación',
            self::SUSPEND_SOCIAL => 'Suspensión de funciones sociales',
            self::SUSPEND_FULL => 'Suspensión de acceso a la app',
            self::DISMISS => 'Caso desestimado',
        ][$action] ?? $action;
    }
}
