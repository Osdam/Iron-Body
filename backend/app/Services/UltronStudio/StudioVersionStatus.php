<?php

namespace App\Services\UltronStudio;

/**
 * Los diez estados de una versión del Studio (contrato F §A2 y H5 §A2), con su
 * etiqueta y, si no se pueden alcanzar, el motivo.
 *
 * Desde el Hito 5 se alcanzan nueve: los cinco de F (DRAFT, VALIDATED,
 * REVIEW_REQUIRED, APPROVED y REJECTED, terminal) y los cuatro de la
 * publicación ({@see StudioPublications}):
 *
 *   APPROVED ─ publicar ─▶ CANARY ─ promover ─▶ PUBLISHED ─ (otra se promueve,
 *   o Legacy) ─▶ RETIRED («Sustituida»); CANARY | PUBLISHED ─ revertir ─▶
 *   ROLLED_BACK (terminal); RETIRED ─ revertir la que la sustituyó ─▶ PUBLISHED.
 *
 * `VALIDATING` sigue sin poder guardarse: la validación es pura e inmediata.
 * Lo garantizan tres capas: no hay transición que lleve a él, el modelo lo
 * rechaza y, en PostgreSQL, el CHECK `usv_status_chk` (que desde la migración
 * 2026_10_25_100002 admite los otros nueve). Como mucho una versión en CANARY y
 * una en PUBLISHED a la vez: lo garantizan los índices únicos parciales
 * `usv_one_canary` y `usv_one_published`, en los dos motores.
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

    /** Las versiones que declara la cadena de publicaciones: las únicas que se revierten (contrato H5 §A5.3). */
    public const ACTIVE_PUBLICATION = [self::Canary, self::Published];

    public function reachable(): bool
    {
        return $this !== self::Validating;
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
            // Sustituida por una publicación posterior (o por volver a Legacy):
            // se reutiliza el valor de F en vez de añadir SUPERSEDED (decisión 5 del lead).
            self::Retired => 'Sustituida',
            self::RolledBack => 'Revertida',
        };
    }

    /** Por qué no se alcanza; null en los alcanzables. */
    public function reason(): ?string
    {
        return match ($this) {
            self::Validating => 'La validación es inmediata: ninguna versión queda en este estado.',
            default => null,
        };
    }

    public function isReviewable(): bool
    {
        return in_array($this, self::REVIEWABLE, true);
    }

    /** ¿La declara la cadena (la de canario o la publicada)? */
    public function isActivePublication(): bool
    {
        return in_array($this, self::ACTIVE_PUBLICATION, true);
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
