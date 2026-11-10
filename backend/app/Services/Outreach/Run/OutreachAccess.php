<?php

namespace App\Services\Outreach\Run;

use App\Models\Admin;
use App\Support\Access\AdminActor;
use App\Support\Access\CrmPermission;
use Illuminate\Http\Request;

/**
 * Las CERRADURAS de la API de la ejecución (contrato H9, D9-13; T6): la
 * puerta de `AuthorizationMap` (una ruta, una llave, denegar por defecto) →
 * UNA PERSONA (el token compartido no entra: `commercial_automation` va a
 * `SHARED_TOKEN_EXCLUDED_DOMAINS`) → la llave, comprobada otra vez dentro
 * (defensa en profundidad, por si una ruta se clasificara mal). Sin
 * `ultron.view` implícito: el panel comercial tiene sus propias llaves.
 */
final class OutreachAccess
{
    public const VIEW = 'commercial_automation.view';

    public const SIMULATE_REAL = 'commercial_automation.simulate_real';

    public const TIMELINE_VIEW = 'commercial_automation.timeline_view';

    public const ASSISTED_WORK = 'commercial_automation.assisted_work';

    public const STOP = 'commercial_automation.stop';

    public const RELEASE = 'commercial_automation.release';

    public const PERMISSIONS = [self::VIEW, self::SIMULATE_REAL, self::TIMELINE_VIEW, self::ASSISTED_WORK, self::STOP, self::RELEASE];

    /** El dominio que el token compartido nunca obtiene (D9-13). */
    public const DOMAIN = 'commercial_automation';

    public function __construct(public readonly Admin $actor) {}

    /**
     * @throws OutreachException 403 `person_required` sin persona; 403 `forbidden` sin la llave
     */
    public static function require(Request $request, string $permission): self
    {
        $admin = AdminActor::from($request);
        if (! $admin instanceof Admin) {
            throw OutreachException::personRequired();
        }
        $acceso = new self($admin);
        if (! $acceso->can($permission)) {
            throw OutreachException::forbidden($permission);
        }

        return $acceso;
    }

    public function can(string $permission): bool
    {
        return CrmPermission::allows($this->actor, $permission);
    }

    public function id(): int
    {
        return (int) $this->actor->id;
    }

    /** El bloque `can` de `GET outreach/status`. @return array<string, bool> */
    public function capabilities(): array
    {
        return [
            'view' => $this->can(self::VIEW),
            'simulateReal' => $this->can(self::SIMULATE_REAL),
            'timeline' => $this->can(self::TIMELINE_VIEW),
            'assisted' => $this->can(self::ASSISTED_WORK),
            'stop' => $this->can(self::STOP),
            'release' => $this->can(self::RELEASE),
        ];
    }
}
