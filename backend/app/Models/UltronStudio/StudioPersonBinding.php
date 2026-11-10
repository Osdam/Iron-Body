<?php

namespace App\Models\UltronStudio;

use App\Models\Identity;
use App\Services\UltronStudio\StudioPersonBindings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Un VÍNCULO cuenta → persona (contrato H9, D9-11.2, P9-4, §6.1): la cuenta
 * del CRM (`admin_id`) es de la persona `identity_id` (FK a `identities`,
 * L9-6). Base de los CUATRO OJOS POR PERSONA: dos cuentas de la misma persona
 * comparten la identidad y no se aprueban entre sí.
 *
 *  - Lo crea `ultron.person_bindings_manage` para OTRA cuenta
 *    (`created_by_admin_id` ≠ `admin_id`).
 *  - Lo verifica una segunda persona ya vinculada, distinta de la vinculada y
 *    de quien lo creó (`verified_by_*`, una vez).
 *  - Se revoca una vez (`revoked_*`) y NUNCA se borra. La identidad no cambia.
 *
 * Solo lo escribe {@see StudioPersonBindings}. Sin documento ni teléfono: de la
 * identidad solo se usa el id (y su uuid en el mensaje firmado de la cadena).
 *
 * @property int $id
 * @property string $uuid
 * @property string $client_request_id
 * @property string $origin
 * @property int $admin_id
 * @property int $identity_id
 * @property string|null $bootstrap_authorization
 * @property int|null $created_by_admin_id
 * @property string $created_by_name
 * @property string|null $created_by_role
 * @property int|null $verified_by_admin_id
 * @property string|null $verified_by_name
 * @property int|null $verified_by_identity_id
 * @property Carbon|null $verified_at
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by_admin_id
 * @property string|null $revoked_by_name
 * @property string|null $revoke_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Identity|null $identity
 */
class StudioPersonBinding extends Model
{
    /** Lo único que cambia: la verificación y la revocación, una vez cada una (`BINDING_MUTABLE_COLUMNS` de DB9). */
    public const MUTABLE = [
        'verified_by_admin_id', 'verified_by_name', 'verified_by_identity_id', 'verified_at',
        'revoked_by_admin_id', 'revoked_by_name', 'revoked_at', 'revoke_reason', 'updated_at',
    ];

    /** `crm`: lo crea una persona para otra cuenta; `bootstrap`: las dos primeras, por consola, verificadas al nacer. */
    public const ORIGIN_CRM = 'crm';

    public const ORIGIN_BOOTSTRAP = 'bootstrap';

    protected $table = 'ultron_studio_person_bindings';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'identity_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'verified_by_admin_id' => 'integer',
            'verified_by_identity_id' => 'integer',
            'verified_at' => 'datetime',
            'revoked_at' => 'datetime',
            'revoked_by_admin_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $b): void {
            if (array_diff(array_keys($b->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('ultron_studio: la cuenta y la identidad de un vínculo no cambian.');
            }
            foreach (['verified_at', 'revoked_at'] as $una) {
                if ($b->getOriginal($una) !== null && $b->isDirty($una)) {
                    throw new LogicException('ultron_studio: un vínculo se verifica y se revoca una sola vez.');
                }
            }
        });
        static::deleting(fn () => throw new LogicException('ultron_studio: un vínculo se revoca, no se borra.'));
    }

    /** @return BelongsTo<Identity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(Identity::class, 'identity_id');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null && $this->revoked_at === null;
    }
}
