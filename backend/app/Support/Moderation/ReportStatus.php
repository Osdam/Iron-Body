<?php

namespace App\Support\Moderation;

/**
 * Máquina de estados de un reporte.
 *
 * Las transiciones NO son arbitrarias: `canTransition()` es la única puerta.
 * Cualquier intento fuera del grafo se responde 422 y queda en auditoría.
 *
 *   submitted ──► triaged ──► under_review ──► actioned ──► appealed ──► closed
 *        │            │             │              │
 *        │            │             ├──► dismissed ────────────────────► closed
 *        │            │             └──► awaiting_information ──► under_review
 *        └────────────┴──────────────────────────────────────────► dismissed
 *
 * `closed` es terminal. Un caso cerrado no se reabre: se abre uno nuevo (así la
 * línea de tiempo del caso original queda intacta para auditoría).
 */
final class ReportStatus
{
    public const SUBMITTED = 'submitted';

    public const TRIAGED = 'triaged';

    public const UNDER_REVIEW = 'under_review';

    public const AWAITING_INFORMATION = 'awaiting_information';

    public const ACTIONED = 'actioned';

    public const DISMISSED = 'dismissed';

    public const APPEALED = 'appealed';

    public const CLOSED = 'closed';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SUBMITTED,
            self::TRIAGED,
            self::UNDER_REVIEW,
            self::AWAITING_INFORMATION,
            self::ACTIONED,
            self::DISMISSED,
            self::APPEALED,
            self::CLOSED,
        ];
    }

    /**
     * Grafo de transiciones permitidas.
     *
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::SUBMITTED => [self::TRIAGED, self::UNDER_REVIEW, self::DISMISSED],
            self::TRIAGED => [self::UNDER_REVIEW, self::DISMISSED],
            self::UNDER_REVIEW => [
                self::AWAITING_INFORMATION,
                self::ACTIONED,
                self::DISMISSED,
            ],
            self::AWAITING_INFORMATION => [self::UNDER_REVIEW, self::DISMISSED],
            self::ACTIONED => [self::APPEALED, self::CLOSED],
            self::DISMISSED => [self::CLOSED],
            self::APPEALED => [self::ACTIONED, self::CLOSED],
            self::CLOSED => [],
        ];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::transitions()[$from] ?? [], true);
    }

    /** Estados que cuentan como "caso abierto" (para dedup y cuarentena). */
    public static function open(): array
    {
        return [
            self::SUBMITTED,
            self::TRIAGED,
            self::UNDER_REVIEW,
            self::AWAITING_INFORMATION,
            self::APPEALED,
        ];
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, self::open(), true);
    }

    public static function isTerminal(string $status): bool
    {
        return $status === self::CLOSED;
    }

    /** Etiqueta legible para el CRM. */
    public static function label(string $status): string
    {
        return [
            self::SUBMITTED => 'Nuevo',
            self::TRIAGED => 'Clasificado',
            self::UNDER_REVIEW => 'En revisión',
            self::AWAITING_INFORMATION => 'Esperando información',
            self::ACTIONED => 'Con acción aplicada',
            self::DISMISSED => 'Desestimado',
            self::APPEALED => 'Apelado',
            self::CLOSED => 'Cerrado',
        ][$status] ?? $status;
    }

    /** Códigos de resolución permitidos al cerrar/decidir un caso. */
    public const RESOLUTION_NO_VIOLATION = 'no_violation';

    public const RESOLUTION_CONTENT_HIDDEN = 'content_hidden';

    public const RESOLUTION_CONTENT_REMOVED = 'content_removed';

    public const RESOLUTION_MEMBER_WARNED = 'member_warned';

    public const RESOLUTION_MEMBER_RESTRICTED = 'member_restricted';

    public const RESOLUTION_MEMBER_SUSPENDED = 'member_suspended';

    public const RESOLUTION_DUPLICATE = 'duplicate';

    public const RESOLUTION_ABUSIVE_REPORT = 'abusive_report';

    /** @return list<string> */
    public static function resolutions(): array
    {
        return [
            self::RESOLUTION_NO_VIOLATION,
            self::RESOLUTION_CONTENT_HIDDEN,
            self::RESOLUTION_CONTENT_REMOVED,
            self::RESOLUTION_MEMBER_WARNED,
            self::RESOLUTION_MEMBER_RESTRICTED,
            self::RESOLUTION_MEMBER_SUSPENDED,
            self::RESOLUTION_DUPLICATE,
            self::RESOLUTION_ABUSIVE_REPORT,
        ];
    }
}
