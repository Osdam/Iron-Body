<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * Una fila del LIBRO DE AUTORIZACIONES DE CONTACTO (cierre de ULTRON STUDIO,
 * C9-1; `outreach_consents`, SOLO INSERCIÓN; migración
 * 2026_11_22_100001_create_outreach_consents_table). La petición, su
 * confirmación (cuatro ojos por cuenta), su rechazo y la retirada son filas.
 * Las reglas las impone la base; aquí solo están los conjuntos cerrados, los
 * casts y el bloqueo de toda modificación.
 *
 * Vive en `App\Models` y no en `App\Models\Outreach`: la ejecución del H9 no
 * usa modelos propios (D9-8; `OutreachStaticGuardsTest`).
 *
 * No hay redacción aquí: la supresión de la Ley 1581 la hace
 * `docs/ultron-studio/ops/supresion-1581.sql` con el rol dueño (la aplicación
 * no tiene UPDATE sobre esta tabla).
 */
class OutreachConsent extends Model
{
    public const KIND_REQUESTED = 'requested';

    public const KIND_GRANTED = 'granted';

    public const KIND_REJECTED = 'rejected';

    public const KIND_REVOKED = 'revoked';

    /** @var list<string> */
    public const KINDS = [self::KIND_REQUESTED, self::KIND_GRANTED, self::KIND_REJECTED, self::KIND_REVOKED];

    /** @var list<string> */
    public const PURPOSES = ['commercial', 'collections', 'service'];

    public const CHANNEL_WHATSAPP = 'whatsapp';

    /** La voz sigue en `voice_consents` (D8-18). @var list<string> */
    public const CHANNELS = [self::CHANNEL_WHATSAPP];

    /** Por defecto mientras el propietario decide con su asesoría (P9-15). @var list<string> */
    public const SOURCES = ['contract_checkbox', 'reception_form', 'member_request'];

    public const ACTOR_ADMIN = 'admin';

    /** El sistema solo retira (la palabra de baja). */
    public const ACTOR_SYSTEM = 'system';

    /** @var list<string> */
    public const ACTOR_TYPES = [self::ACTOR_ADMIN, self::ACTOR_SYSTEM];

    public const EVIDENCE_MAX = 300;

    /** La marca de la supresión 1581. */
    public const REDACTED = '[suprimido]';

    /** Lo que el modelo no hace nunca (la base tampoco lo deja). */
    private const INSERT_ONLY = 'outreach: las autorizaciones de contacto son de solo inserción (se resuelven, rechazan o retiran con otra fila).';

    public const UPDATED_AT = null;

    protected $table = 'outreach_consents';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'member_id' => 'integer',
            'lead_id' => 'integer',
            'recorded_by_admin_id' => 'integer',
            'confirmed_by_admin_id' => 'integer',
            'redacted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $autorizacion): void {
            $autorizacion->uuid ??= (string) Str::uuid();
        });
        static::updating(fn () => throw new LogicException(self::INSERT_ONLY));
        static::deleting(fn () => throw new LogicException(self::INSERT_ONLY));
    }

    /**
     * Ni las actualizaciones ni los borrados en bloque del constructor (que no
     * pasan por los eventos del modelo).
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            private const FORWARDED = ['updateorinsert', 'truncate', 'incrementeach', 'decrementeach', 'updatefrom'];

            public function update(array $values)
            {
                throw new LogicException(OutreachConsent::insertOnlyMessage());
            }

            public function upsert(array $values, $uniqueBy, $update = null)
            {
                throw new LogicException(OutreachConsent::insertOnlyMessage());
            }

            public function touch($column = null)
            {
                throw new LogicException(OutreachConsent::insertOnlyMessage());
            }

            public function increment($column, $amount = 1, array $extra = [])
            {
                throw new LogicException(OutreachConsent::insertOnlyMessage());
            }

            public function decrement($column, $amount = 1, array $extra = [])
            {
                throw new LogicException(OutreachConsent::insertOnlyMessage());
            }

            public function delete()
            {
                throw new LogicException(OutreachConsent::insertOnlyMessage());
            }

            public function forceDelete()
            {
                throw new LogicException(OutreachConsent::insertOnlyMessage());
            }

            public function __call($method, $parameters)
            {
                if (in_array(strtolower((string) $method), self::FORWARDED, true)) {
                    throw new LogicException(OutreachConsent::insertOnlyMessage());
                }

                return parent::__call($method, $parameters);
            }
        };
    }

    public static function insertOnlyMessage(): string
    {
        return self::INSERT_ONLY;
    }
}
