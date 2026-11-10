<?php

namespace App\Services\Outreach\Facts;

/**
 * Una persona RESUELTA (contrato H9, D9-2): de la clave que se pidió a todas
 * las filas que son ella. Solo vive en memoria dentro de una pasada de
 * {@see BatchSnapshotBuilder}: lleva ids internos y NUNCA se serializa (la foto
 * guarda la clave y el seudónimo, no estos ids).
 *
 *  - `key` es la clave CANÓNICA (`m:<id>` del socio canónico si lo hay, si no
 *    `l:<id>`); null si la clave pedida no corresponde a nadie (`unknown_subject`).
 *  - `memberIds` son todas las fichas de socio de la persona (la canónica y las
 *    que comparten identidad o usuario con ella); `userIds`, sus usuarios;
 *    `leadIds`, todos sus prospectos.
 *  - `primaryUserId` es el usuario cuya membresía manda; `primaryLeadId`, el
 *    prospecto más reciente (como `CommercialSubjectResolver::leadIdForMember`).
 */
final class Subject
{
    /**
     * @param  list<int>  $memberIds
     * @param  list<int>  $userIds
     * @param  list<int>  $leadIds
     */
    public function __construct(
        public readonly string $requestedKey,
        public readonly ?string $key,
        public readonly ?int $memberId = null,
        public readonly array $memberIds = [],
        public readonly array $userIds = [],
        public readonly array $leadIds = [],
        public readonly ?int $primaryUserId = null,
        public readonly ?int $primaryLeadId = null,
    ) {}

    public static function unresolved(string $requestedKey): self
    {
        return new self($requestedKey, null);
    }

    public function resolved(): bool
    {
        return $this->key !== null;
    }

    public function isMember(): bool
    {
        return $this->memberId !== null;
    }

    public function isLead(): bool
    {
        return $this->leadIds !== [];
    }
}
