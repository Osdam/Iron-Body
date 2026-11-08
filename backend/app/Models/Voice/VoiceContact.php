<?php

namespace App\Models\Voice;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * Un contacto de la fase 4b (contrato H8, D8-18; `voice_contacts`). El número
 * va CIFRADO (cast `encrypted`) y solo se descifra en memoria al marcar; su
 * huella `voice-phone` y sus 4 dígitos sirven para todo lo demás. El origen
 * (`lead`, `member` o `manual`) es una referencia sin FK al motor. Se retira;
 * no se borra.
 *
 * La petición de consentimiento pendiente de la SEGUNDA persona (E3-4) vive en
 * las columnas `consent_request_*` hasta que otra cuenta la confirma.
 */
class VoiceContact extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    public const SOURCE_LEAD = 'lead';

    public const SOURCE_MEMBER = 'member';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCES = [self::SOURCE_LEAD, self::SOURCE_MEMBER, self::SOURCE_MANUAL];

    public const MUTABLE = [
        'consent_request_source', 'consent_request_evidence', 'consent_requested_by_admin_id',
        'consent_requested_by_name', 'consent_requested_by_role', 'consent_requested_at', 'updated_at',
    ];

    protected $table = 'voice_contacts';

    protected $guarded = ['*'];

    protected $hidden = ['phone_encrypted', 'phone_hash'];

    protected function casts(): array
    {
        return [
            'phone_encrypted' => 'encrypted',
            'source_id' => 'integer',
            'registered_by_admin_id' => 'integer',
            'consent_requested_by_admin_id' => 'integer',
            'retired_by_admin_id' => 'integer',
            'consent_requested_at' => 'datetime',
            'retired_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $contacto): void {
            $contacto->uuid ??= (string) Str::uuid();
        });
        static::updating(fn () => throw new LogicException('voice: un contacto cambia solo con actualizaciones condicionadas.'));
        static::deleting(fn () => throw new LogicException('voice: los contactos de voz se retiran, no se borran.'));
    }

    public function consents(): HasMany
    {
        return $this->hasMany(VoiceConsent::class, 'contact_id');
    }

    /**
     * La petición de consentimiento: se pone (todas las columnas) o se borra
     * (todas a null), solo en un contacto activo.
     *
     * @param  array<string, mixed>  $values
     */
    public static function setConsentRequest(int $id, array $values, bool $onlyIfEmpty): bool
    {
        $ajenas = array_values(array_diff(array_keys($values), self::MUTABLE));
        if ($ajenas !== []) {
            throw new LogicException('voice: un contacto no cambia '.implode(', ', $ajenas).' por aquí.');
        }
        $q = static::query()->whereKey($id)->where('status', self::STATUS_ACTIVE);
        if ($onlyIfEmpty) {
            $q->whereNull('consent_request_source');
        } else {
            $q->whereNotNull('consent_request_source');
        }

        return $q->update($values) === 1;
    }
}
