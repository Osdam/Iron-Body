<?php

namespace App\Services\UltronStudio;

/**
 * Los diez estados de una versión del Studio (contrato §A2), con su etiqueta y,
 * si no se pueden alcanzar, el motivo.
 *
 * En esta fase solo se alcanzan DRAFT, VALIDATED, REVIEW_REQUIRED, APPROVED y
 * REJECTED (terminal). Los demás siguen aquí porque `GET status` los devuelve
 * con `reachable: false` para que el CRM pinte la tubería completa en gris sin
 * inventarse textos. Que no se puedan guardar lo garantizan tres capas: no hay
 * transición que lleve a ellos, el modelo los rechaza y, en PostgreSQL, el
 * CHECK `usv_status_chk`.
 *
 * `VALIDATING` no existe como estado guardado: la validación es pura e
 * inmediata.
 */
enum StudioVersionStatus: string
{
    case Draft = 'DRAFT';
    case Validating = 'VALIDATING';
    case Validated = 'VALIDATED';
    case ReviewRequired = 'REVIEW_REQUIRED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Canary = 'CANARY';
    case Published = 'PUBLISHED';
    case Retired = 'RETIRED';
    case RolledBack = 'ROLLED_BACK';

    /** Los estados desde los que otra persona puede aprobar o rechazar. */
    public const REVIEWABLE = [self::Validated, self::ReviewRequired];

    public function reachable(): bool
    {
        return match ($this) {
            self::Draft, self::Validated, self::ReviewRequired, self::Approved, self::Rejected => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Creada, sin enviar',
            self::Validating => 'Validando',
            self::Validated => 'Validada, espera revisión',
            self::ReviewRequired => 'Con avisos · espera revisión',
            self::Approved => 'Aprobada',
            self::Rejected => 'Rechazada',
            self::Canary => 'Canario',
            self::Published => 'Publicada',
            self::Retired => 'Retirada',
            self::RolledBack => 'Revertida',
        };
    }

    /** Por qué no se alcanza; null en los alcanzables. */
    public function reason(): ?string
    {
        return match ($this) {
            self::Validating => 'La validación es inmediata: ninguna versión queda en este estado.',
            self::Canary, self::Published, self::Retired, self::RolledBack => 'El motor aún no lee versiones.',
            default => null,
        };
    }

    public function isReviewable(): bool
    {
        return in_array($this, self::REVIEWABLE, true);
    }

    /**
     * La tubería completa, en orden, para `GET status`.
     *
     * @return list<array{status:string,label:string,reachable:bool,reason?:string}>
     */
    public static function lifecycle(): array
    {
        return array_map(static function (self $s): array {
            $paso = ['status' => $s->value, 'label' => $s->label(), 'reachable' => $s->reachable()];
            if ($s->reason() !== null) {
                $paso['reason'] = $s->reason();
            }

            return $paso;
        }, self::cases());
    }

    /** @return list<string> Los valores que pueden existir en la base. */
    public static function reachableValues(): array
    {
        return array_values(array_map(
            static fn (self $s): string => $s->value,
            array_filter(self::cases(), static fn (self $s): bool => $s->reachable()),
        ));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
