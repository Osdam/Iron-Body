<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioCanaryNumbers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * Un NÚMERO CANARIO del registro del Studio (contrato H6 §I4.1): un teléfono
 * de prueba, con el consentimiento de su titular, que pasa por pendiente →
 * autorizado (otra persona) → activo (otra persona que quien registró) y se
 * revoca. En H6 el motor NO lee este registro: ULTRON sigue atendiendo solo la
 * conversación canaria configurada en el servidor.
 *
 * DATOS PERSONALES (Ley 1581, §I13). Del teléfono se guardan su huella HMAC
 * (`phone_hash`) y sus cuatro últimos dígitos; en voz, además, el número
 * cifrado (`phone_encrypted`, cast `encrypted` con la `APP_KEY`), que se borra
 * al revocar. Ninguna de las tres cosas sale en un DTO: fuera va el número
 * enmascarado («+57 ••• ••• 4821»).
 *
 * LAS FILAS SOLO CAMBIAN CON UPDATE CONDICIONADOS ({@see transition()}), nunca
 * con `save()` del modelo entero: un inmutable en el SET, aunque lleve el mismo
 * valor, es P0001; en una fila revocada CUALQUIER UPDATE lo es; y el cast
 * `encrypted` volvería a cifrar (la guarda `uscn_cipher_only_shrinks` lo
 * pararía). Ver {@see StudioCanaryNumbers}.
 *
 * @property int $id
 * @property string $uuid
 * @property string $client_request_id
 * @property string $channel
 * @property string $alias
 * @property string $phone_hash
 * @property string $phone_last4
 * @property string $country_code
 * @property string|null $phone_encrypted
 * @property string $status
 * @property int|null $expected_version_id
 * @property bool $consent_attested
 * @property int $registered_by_admin_id
 * @property string $registered_by_name
 * @property string|null $registered_by_role
 * @property int|null $authorized_by_admin_id
 * @property string|null $authorized_by_name
 * @property string|null $authorized_by_role
 * @property Carbon|null $authorized_at
 * @property int|null $activated_by_admin_id
 * @property string|null $activated_by_name
 * @property string|null $activated_by_role
 * @property Carbon|null $activated_at
 * @property int|null $revoked_by_admin_id
 * @property string|null $revoked_by_name
 * @property string|null $revoked_by_role
 * @property Carbon|null $revoked_at
 * @property string|null $revoke_reason
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StudioVersion|null $expectedVersion
 */
class StudioCanaryNumber extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_AUTHORIZED, self::STATUS_ACTIVE, self::STATUS_REVOKED];

    /** Vivos: todos menos el revocado (terminal). */
    public const LIVE = [self::STATUS_PENDING, self::STATUS_AUTHORIZED, self::STATUS_ACTIVE];

    /** Lo que mueve la máquina de estados (§I4.4); el cifrado, solo hacia NULL. */
    public const MUTABLE = [
        'phone_encrypted', 'status', 'expected_version_id',
        'authorized_by_admin_id', 'authorized_by_name', 'authorized_by_role', 'authorized_at',
        'activated_by_admin_id', 'activated_by_name', 'activated_by_role', 'activated_at',
        'revoked_by_admin_id', 'revoked_by_name', 'revoked_by_role', 'revoked_at', 'revoke_reason',
        'status_changed_at', 'updated_at',
    ];

    /** Lo que fija el registro. Las dos listas son las de la migración 2026_11_01_100002, que no las lee de aquí. */
    public const IMMUTABLE = [
        'id', 'uuid', 'client_request_id', 'channel', 'alias', 'phone_hash', 'phone_last4', 'country_code',
        'consent_attested', 'registered_by_admin_id', 'registered_by_name', 'registered_by_role', 'created_at',
    ];

    protected $table = 'ultron_studio_canary_numbers';

    protected $guarded = ['*'];

    /** El cifrado y la huella no salen en ninguna serialización del modelo. */
    protected $hidden = ['phone_encrypted', 'phone_hash'];

    protected function casts(): array
    {
        return [
            'phone_encrypted' => 'encrypted',
            'consent_attested' => 'boolean',
            'expected_version_id' => 'integer',
            'registered_by_admin_id' => 'integer',
            'authorized_by_admin_id' => 'integer',
            'activated_by_admin_id' => 'integer',
            'revoked_by_admin_id' => 'integer',
            'authorized_at' => 'datetime',
            'activated_at' => 'datetime',
            'revoked_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $numero): void {
            $numero->uuid ??= (string) Str::uuid();
        });

        // Nadie guarda el modelo entero: ver la cabecera.
        static::updating(fn () => throw new LogicException('ultron_studio: un número canario cambia solo con transiciones condicionadas.'));
        static::deleting(fn () => throw new LogicException('ultron_studio: los números canarios se revocan, no se borran.'));
    }

    /** @return BelongsTo<StudioVersion, $this> */
    public function expectedVersion(): BelongsTo
    {
        return $this->belongsTo(StudioVersion::class, 'expected_version_id');
    }

    /**
     * La transición CONDICIONADA al estado esperado: un solo UPDATE con
     * `WHERE status IN (…)`, sin `FOR UPDATE`. Devuelve false si otra petición
     * la movió antes (0 filas): quien llama responde 409 con el estado de ahora.
     *
     * Solo admite columnas de {@see MUTABLE}, y el cifrado solo como NULL.
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $values
     */
    public static function transition(int $id, array $from, array $values): bool
    {
        $ajenas = array_values(array_diff(array_keys($values), self::MUTABLE));
        if ($ajenas !== []) {
            throw new LogicException('ultron_studio: una transición de un número canario no escribe '.implode(', ', $ajenas).'.');
        }
        if (array_key_exists('phone_encrypted', $values) && $values['phone_encrypted'] !== null) {
            throw new LogicException('ultron_studio: el cifrado de un número canario solo se borra.');
        }
        if (array_key_exists('status', $values) && ! in_array($values['status'], self::STATUSES, true)) {
            throw new LogicException('ultron_studio: estado de número canario desconocido.');
        }

        // `update()` del constructor de Eloquent añade `updated_at` (mutable).
        return static::query()->whereKey($id)->whereIn('status', $from)->update($values) === 1;
    }
}
