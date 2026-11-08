<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioVoiceCompiler;
use App\Services\UltronStudio\StudioVoiceProfiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Un PERFIL DE VOZ (contrato H8, D8-6), una fila por revisión: lo único propio
 * de la voz (voz, saludos, huecos de la plantilla, duración, herramientas,
 * destinos de transferencia, privacidad y buzón), con un conjunto de campos
 * CERRADO que es el de {@see StudioVoiceCompiler::PROFILE_FIELDS}.
 *
 * NACE `draft` CON EL CONTENIDO CONGELADO (D-7 de DB8): no hay ruta de
 * edición; cambiar un perfil es insertar otra revisión (`based_on_profile_id`).
 * Solo avanza: draft → submitted → approved | rejected, y lo revisa OTRA
 * identidad (cuatro ojos por identidad, `usvp_four_eyes_chk`). Aprobado o
 * rechazado es definitivo.
 *
 * DATOS PERSONALES (Ley 1581, D8-17). En `content` cada destino de
 * transferencia es su alias y la HUELLA `voice-phone` de su teléfono; el número
 * va CIFRADO aparte (`transfer_destinations_encrypted`, cast `encrypted:array`
 * con la `APP_KEY`) y solo se descifra en memoria al desplegar. Nada de eso sale
 * en un DTO: fuera van el alias y los 4 últimos dígitos.
 *
 * `record_calls` y `record_voice` (E3-3) son banderas, nunca contenido, y las
 * dos van a `false` siempre (P-7, P-8; `usvp_privacy_chk`).
 *
 * LAS FILAS SOLO CAMBIAN CON UPDATE CONDICIONADOS ({@see transition()}), nunca
 * con `save()` del modelo entero: un inmutable en el SET, aunque lleve el mismo
 * valor, es P0001 (`usvp_forbid_immutable_update`), y el cast `encrypted`
 * volvería a cifrar. Solo los escribe {@see StudioVoiceProfiles}.
 *
 * @property int $id
 * @property string $uuid
 * @property string $client_request_id
 * @property int $revision
 * @property int|null $based_on_profile_id
 * @property string $label
 * @property string $language
 * @property int $max_duration_seconds
 * @property int $retention_days
 * @property bool $record_calls
 * @property bool $record_voice
 * @property int $transfer_count
 * @property list<array{alias:string,phoneHash:string,e164:string}>|null $transfer_destinations_encrypted
 * @property array<string, mixed> $content
 * @property string $content_hash
 * @property string $status
 * @property int $created_by_admin_id
 * @property string $created_by_name
 * @property string|null $created_by_role
 * @property Carbon|null $created_at
 * @property int|null $submitted_by_admin_id
 * @property string|null $submitted_by_name
 * @property string|null $submitted_by_role
 * @property Carbon|null $submitted_at
 * @property int|null $reviewed_by_admin_id
 * @property string|null $reviewed_by_name
 * @property string|null $reviewed_by_role
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $updated_at
 * @property-read StudioVoiceProfile|null $basedOn
 */
class StudioVoiceProfile extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_REJECTED];

    /** Lo que mueve la máquina de estados (`PROFILE_MUTABLE_COLUMNS` de la migración de DB8). */
    public const MUTABLE = [
        'status', 'submitted_by_admin_id', 'submitted_by_name', 'submitted_by_role', 'submitted_at',
        'reviewed_by_admin_id', 'reviewed_by_name', 'reviewed_by_role', 'reviewed_at', 'review_note',
        'status_changed_at', 'updated_at',
    ];

    protected $table = 'ultron_studio_voice_profiles';

    protected $guarded = ['*'];

    /** El número cifrado nunca sale por `toArray()` ni por `toJson()`. */
    protected $hidden = ['transfer_destinations_encrypted'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'based_on_profile_id' => 'integer',
            'max_duration_seconds' => 'integer',
            'retention_days' => 'integer',
            'record_calls' => 'boolean',
            'record_voice' => 'boolean',
            'transfer_count' => 'integer',
            'transfer_destinations_encrypted' => 'encrypted:array',
            'content' => 'array',
            'created_by_admin_id' => 'integer',
            'submitted_by_admin_id' => 'integer',
            'reviewed_by_admin_id' => 'integer',
            'created_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ultron_studio: un perfil de voz solo cambia con StudioVoiceProfile::transition().'));
        static::deleting(fn () => throw new LogicException('ultron_studio: los perfiles de voz no se borran.'));
    }

    /** @return BelongsTo<StudioVoiceProfile, $this> */
    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_profile_id');
    }

    /**
     * UPDATE condicionado: mueve el estado solo si la fila sigue en uno de
     * `$from`, y solo escribe columnas de {@see MUTABLE}. false = otra
     * petición la movió antes (la carrera la decide la base, sin `FOR UPDATE`).
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $values
     */
    public static function transition(int $id, array $from, array $values): bool
    {
        $ajenas = array_values(array_diff(array_keys($values), self::MUTABLE));
        if ($ajenas !== []) {
            throw new LogicException('ultron_studio: una transición del perfil de voz solo escribe '.implode(', ', self::MUTABLE).'; no '.implode(', ', $ajenas).'.');
        }
        if (array_key_exists('status', $values) && ! in_array($values['status'], self::STATUSES, true)) {
            throw new LogicException('ultron_studio: estado de perfil de voz desconocido.');
        }

        return static::query()->whereKey($id)->whereIn('status', $from)->toBase()->update($values + ['updated_at' => now()]) === 1;
    }
}
