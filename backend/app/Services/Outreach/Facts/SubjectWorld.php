<?php

namespace App\Services\Outreach\Facts;

/**
 * Las filas de una pasada de {@see SubjectResolver}, ya leídas, y las reglas
 * de unificación aplicadas en memoria (puro: sin base). Agrupa las fichas de
 * socio que comparten identidad o usuario (unión de conjuntos) y elige la
 * canónica (la de menor id).
 */
final class SubjectWorld
{
    /** @var array<int, int> socio → representante del grupo */
    private array $padre = [];

    /** @var array<int, list<int>> representante → socios del grupo, ordenados */
    private array $grupos = [];

    /**
     * @param  array<int, array{user_id: ?int, identity_id: ?int}>  $members  socio → sus enlaces
     * @param  array<int, ?int>  $requestedLeads  prospecto pedido → su socio (null si no lo es)
     * @param  array<int, int>  $memberLeads  prospecto → socio, de todos los socios del mundo
     */
    public function __construct(
        private readonly array $members,
        private readonly array $requestedLeads,
        private readonly array $memberLeads,
    ) {
        foreach (array_keys($members) as $id) {
            $this->padre[$id] = $id;
        }
        $porIdentidad = [];
        $porUsuario = [];
        foreach ($members as $id => $m) {
            if ($m['identity_id'] !== null) {
                if (isset($porIdentidad[$m['identity_id']])) {
                    $this->union($porIdentidad[$m['identity_id']], $id);
                } else {
                    $porIdentidad[$m['identity_id']] = $id;
                }
            }
            if ($m['user_id'] !== null) {
                if (isset($porUsuario[$m['user_id']])) {
                    $this->union($porUsuario[$m['user_id']], $id);
                } else {
                    $porUsuario[$m['user_id']] = $id;
                }
            }
        }
        foreach (array_keys($members) as $id) {
            $this->grupos[$this->find($id)][] = $id;
        }
        foreach ($this->grupos as &$g) {
            sort($g);
        }
        unset($g);
    }

    public function forMember(string $requestedKey, int $memberId): Subject
    {
        if (! isset($this->members[$memberId])) {
            return Subject::unresolved($requestedKey);
        }
        $grupo = $this->grupos[$this->find($memberId)];
        $canonico = $grupo[0];

        $usuarios = [];
        foreach ($grupo as $id) {
            if ($this->members[$id]['user_id'] !== null) {
                $usuarios[] = $this->members[$id]['user_id'];
            }
        }
        $usuarios = array_values(array_unique($usuarios));
        sort($usuarios);

        $enGrupo = array_fill_keys($grupo, true);
        $leads = [];
        foreach ($this->memberLeads as $leadId => $socio) {
            if (isset($enGrupo[$socio])) {
                $leads[] = $leadId;
            }
        }
        sort($leads);

        $usuarioPrincipal = $this->members[$canonico]['user_id'] ?? ($usuarios[0] ?? null);

        return new Subject(
            requestedKey: $requestedKey,
            key: SubjectKey::member($canonico),
            memberId: $canonico,
            memberIds: $grupo,
            userIds: $usuarios,
            leadIds: $leads,
            primaryUserId: $usuarioPrincipal,
            primaryLeadId: $leads === [] ? null : $leads[count($leads) - 1],
        );
    }

    public function forLead(string $requestedKey, int $leadId): Subject
    {
        if (! array_key_exists($leadId, $this->requestedLeads)) {
            return Subject::unresolved($requestedKey);
        }
        $socio = $this->requestedLeads[$leadId];
        if ($socio !== null && isset($this->members[$socio])) {
            return $this->forMember($requestedKey, $socio);
        }

        return new Subject(
            requestedKey: $requestedKey,
            key: SubjectKey::lead($leadId),
            leadIds: [$leadId],
            primaryLeadId: $leadId,
        );
    }

    /** El socio canónico de un usuario, o null si el usuario no tiene ficha de socio. */
    public function memberOfUser(int $userId): ?int
    {
        foreach ($this->members as $id => $m) {
            if ($m['user_id'] === $userId) {
                return $this->grupos[$this->find($id)][0];
            }
        }

        return null;
    }

    private function find(int $id): int
    {
        while ($this->padre[$id] !== $id) {
            $this->padre[$id] = $this->padre[$this->padre[$id]];
            $id = $this->padre[$id];
        }

        return $id;
    }

    private function union(int $a, int $b): void
    {
        $ra = $this->find($a);
        $rb = $this->find($b);
        if ($ra === $rb) {
            return;
        }
        // El de menor id queda de representante: no cambia el resultado, solo lo hace legible.
        if ($ra < $rb) {
            $this->padre[$rb] = $ra;
        } else {
            $this->padre[$ra] = $rb;
        }
    }
}
