<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\Admin;
use App\Models\UltronStudio\StudioVersion;
use App\Support\Access\AdminActor;
use App\Support\Access\AuthorizationMap;
use App\Support\Access\CrmPermission;
use Illuminate\Http\Request;

/**
 * Quién está usando el Studio y qué puede hacer en él.
 *
 * TRES CERRADURAS, y esta es la segunda y la tercera:
 *
 *  1. La puerta: {@see AuthorizationMap} exige el permiso `ultron.*` de cada
 *     ruta, una a una; lo que no esté clasificado se deniega.
 *  2. Una PERSONA ({@see requirePerson()}). El token compartido de
 *     automatizaciones no recibe nada del dominio `ultron` (ver CrmPermission),
 *     y aunque algún día alguien se lo concediera por error, aquí no entra: un
 *     borrador, una versión o una aprobación los firma alguien.
 *  3. CUATRO OJOS por IDENTIDAD ({@see isCreator()}): se compara el id de
 *     quien actúa con el de quien creó la versión, nunca el permiso. Tener
 *     `ultron.approve` no deja aprobar lo propio.
 *
 * `marketing.view` y `marketing.agent.manage` no abren nada de esto.
 *
 * Los permisos se calculan UNA vez por petición: `CrmPermission::allows()`
 * vuelve a derivar el catálogo de las rutas en cada llamada, y el detalle de una
 * versión pregunta por media docena.
 */
final class StudioAccess
{
    public const VIEW = 'ultron.view';

    public const EDIT_DRAFT = 'ultron.edit_draft';

    public const MANAGE_RULES = 'ultron.manage_rules';

    public const APPROVE = 'ultron.approve';

    public const PUBLISH = 'ultron.publish';

    public const ROLLBACK = 'ultron.rollback';

    /** Ver los eventos de borrador de otras personas en la auditoría. */
    public const AUDIT_VIEW = 'audit.view';

    /** @var array<string, true>|null */
    private ?array $granted = null;

    public function __construct(public readonly Admin $actor) {}

    /**
     * La segunda cerradura: sin persona identificada no hay Studio.
     *
     * @throws UltronStudioException 403 `person_required`
     */
    public static function requirePerson(Request $request): self
    {
        $admin = AdminActor::from($request);
        if (! $admin instanceof Admin) {
            throw UltronStudioException::personRequired();
        }

        // Toda ruta del Studio presupone `ultron.view`: comprobarlo aquí, antes de
        // buscar ningún recurso, evita el oráculo de existencia (404 con un uuid
        // inexistente frente a 403 con uno existente) para un rol con una llave
        // de escritura y sin lectura (hallazgo H-2 del tester).
        $access = new self($admin);
        $access->require(self::VIEW);

        return $access;
    }

    public function can(string $permission): bool
    {
        if ($this->granted === null) {
            $this->granted = [];
            foreach (CrmPermission::forAdmin($this->actor) as $p) {
                $this->granted[CrmPermission::canonical($p)] = true;
            }
        }

        return isset($this->granted[CrmPermission::canonical($permission)]);
    }

    /**
     * Defensa en profundidad para las escrituras: la puerta ya lo comprobó, y
     * esto lo vuelve a exigir dentro del dominio por si una ruta se clasificara
     * mal algún día.
     *
     * @throws UltronStudioException 403 `forbidden`
     */
    public function require(string $permission): void
    {
        // Toda llave del Studio presupone VER lo que toca: si no, un rol con solo
        // `ultron.approve` aprobaría a ciegas y leería en la respuesta el detalle
        // que el GET le niega (revisión de seguridad de la API, H1).
        foreach (array_unique([self::VIEW, $permission]) as $llave) {
            if (! $this->can($llave)) {
                throw UltronStudioException::forbidden($llave);
            }
        }
    }

    /**
     * El bloque `can` de `GET status`.
     *
     * @return array{view:bool,editDraft:bool,manageRules:bool,approve:bool,publish:bool,rollback:bool}
     */
    public function capabilities(): array
    {
        return [
            'view' => $this->can(self::VIEW),
            'editDraft' => $this->can(self::EDIT_DRAFT),
            'manageRules' => $this->can(self::MANAGE_RULES),
            'approve' => $this->can(self::APPROVE),
            'publish' => $this->can(self::PUBLISH),
            'rollback' => $this->can(self::ROLLBACK),
        ];
    }

    /** ¿La creó quien está actuando? Es la base de los cuatro ojos. */
    public function isCreator(StudioVersion $version): bool
    {
        return (int) $version->created_by_admin_id === (int) $this->actor->id;
    }

    /** Quien tiene `audit.view` ve también los eventos de borrador ajenos. */
    public function seesEveryDraftEvent(): bool
    {
        return $this->can(self::AUDIT_VIEW);
    }

    public function id(): int
    {
        return (int) $this->actor->id;
    }

    /**
     * Instantánea de quien actúa, con los tamaños de las columnas: la historia
     * sigue diciendo quién fue aunque la cuenta se renombre o desaparezca.
     *
     * @return array{id:int,name:string,role:?string}
     */
    public function snapshot(): array
    {
        $role = $this->actor->role !== null ? mb_substr((string) $this->actor->role, 0, 60) : null;

        return [
            'id' => (int) $this->actor->id,
            'name' => mb_substr((string) $this->actor->name, 0, 120),
            'role' => $role,
        ];
    }
}
