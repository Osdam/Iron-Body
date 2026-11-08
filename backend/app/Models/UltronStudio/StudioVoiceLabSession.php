<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioVoiceLabSessions;
use App\Services\UltronStudio\StudioVoiceLabVerdicts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * Una sesión del LABORATORIO HABLADO (contrato H8, D8-23): una conversación de
 * prueba, en el navegador y sin teléfono, con el AGENTE DE LABORATORIO
 * compilado de una versión y un perfil de voz (`purpose = lab`, nunca el
 * publicado). Su agente es uno por sesión ({@see StudioVoiceLabSessions}).
 *
 *   open ─▶ ended (con veredicto de OTRA identidad, una vez) | failed
 *
 * Al abrirse congela su agente, su reserva, su día de Bogotá, su duración
 * máxima y su autoría ({@see IMMUTABLE}). Lo demás ({@see MUTABLE}) lo mueven
 * la apertura (la URL), el cierre (por quien la abrió, por el informe del
 * proveedor o por plazo) y el veredicto ({@see StudioVoiceLabVerdicts}), con
 * UPDATE condicionados ({@see advance()}). Una sesión fallida o con veredicto
 * es definitiva: la base rechaza cualquier UPDATE (`usvl_closed_is_final`).
 *
 * LA URL FIRMADA NO SE GUARDA: solo cuándo caduca (`url_expires_at`). En
 * `guard_codes` solo van códigos y números (los de la guarda posterior, las
 * herramientas usadas y lo que contó quien probó, de conjuntos cerrados),
 * nunca texto de la conversación (D8-17).
 *
 * Nada se borra (este modelo y `usvl_forbid_delete`). Las dos listas son las
 * de la migración 2026_11_10_100001 (DB8).
 *
 * @property int $id
 * @property string $uuid
 * @property string $client_request_id
 * @property int $agent_id
 * @property string $status
 * @property string $budget_day
 * @property int $reserved_micros
 * @property int|null $cost_micros
 * @property int $max_seconds
 * @property int|null $duration_seconds
 * @property Carbon|null $url_expires_at
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property string|null $end_reason
 * @property array<string, mixed>|null $guard_codes
 * @property string|null $verdict
 * @property string|null $verdict_note
 * @property int|null $verdict_by_admin_id
 * @property string|null $verdict_by_name
 * @property string|null $verdict_by_role
 * @property Carbon|null $verdict_at
 * @property int $created_by_admin_id
 * @property string $created_by_name
 * @property string|null $created_by_role
 * @property Carbon|null $created_at
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $updated_at
 */
class StudioVoiceLabSession extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_ENDED = 'ended';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_ENDED, self::STATUS_FAILED];

    public const VERDICT_APPROVED = 'approved';

    public const VERDICT_REJECTED = 'rejected';

    public const VERDICTS = [self::VERDICT_APPROVED, self::VERDICT_REJECTED];

    /** Lo único que la apertura, el cierre y el veredicto cambian. */
    public const MUTABLE = [
        'status', 'cost_micros', 'duration_seconds', 'url_expires_at', 'started_at', 'ended_at', 'end_reason',
        'guard_codes', 'verdict', 'verdict_note', 'verdict_by_admin_id', 'verdict_by_name', 'verdict_by_role',
        'verdict_at', 'status_changed_at', 'updated_at',
    ];

    /** Lo que la sesión congela al abrirse (con `id`, que nadie cambia). */
    public const IMMUTABLE = [
        'id', 'uuid', 'client_request_id', 'agent_id', 'budget_day', 'reserved_micros', 'max_seconds',
        'created_by_admin_id', 'created_by_name', 'created_by_role', 'created_at',
    ];

    protected $table = 'ultron_studio_voice_lab_sessions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'agent_id' => 'integer',
            'reserved_micros' => 'integer',
            'cost_micros' => 'integer',
            'max_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'guard_codes' => 'array',
            'verdict_by_admin_id' => 'integer',
            'verdict_at' => 'datetime',
            'created_by_admin_id' => 'integer',
            'status_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            $session->uuid ??= (string) Str::uuid();
        });

        // Ningún valor que el contrato no conoce llega a la base, tampoco en
        // SQLite (donde no existen los CHECK `usvl_*_chk`).
        static::saving(function (self $session): void {
            if (! in_array($session->status, self::STATUSES, true) || ($session->verdict !== null && ! in_array($session->verdict, self::VERDICTS, true))) {
                throw new LogicException('ultron_studio_voice_lab: estado o veredicto de sesión desconocido.');
            }
        });

        static::updating(function (self $session): void {
            $cambiadas = array_values(array_intersect(self::IMMUTABLE, array_keys($session->getDirty())));
            if ($cambiadas !== []) {
                throw new LogicException('ultron_studio_voice_lab: una sesión congela al abrirse '.implode(', ', $cambiadas).'.');
            }
        });

        static::deleting(fn () => throw new LogicException('ultron_studio_voice_lab: las sesiones no se borran.'));
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * El cambio CONDICIONADO: un solo UPDATE con `WHERE status IN (…)` (y,
     * si se pide, sin veredicto), sin `FOR UPDATE`. Devuelve false si otra
     * petición la movió antes (0 filas). Solo admite columnas de
     * {@see MUTABLE}; cualquier otra es un fallo de código y lanza antes de
     * llegar al disparador.
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $values
     */
    public static function advance(int $id, array $from, array $values, bool $withoutVerdict = true): bool
    {
        $ajenas = array_values(array_diff(array_keys($values), self::MUTABLE));
        if ($ajenas !== []) {
            throw new LogicException('ultron_studio_voice_lab: una sesión solo cambia '.implode(', ', self::MUTABLE).'; no '.implode(', ', $ajenas).'.');
        }
        if (array_key_exists('status', $values) && ! in_array($values['status'], self::STATUSES, true)) {
            throw new LogicException('ultron_studio_voice_lab: estado de sesión desconocido.');
        }
        if (array_key_exists('verdict', $values) && ! in_array($values['verdict'], self::VERDICTS, true)) {
            throw new LogicException('ultron_studio_voice_lab: veredicto desconocido.');
        }
        if (array_key_exists('guard_codes', $values) && is_array($values['guard_codes'])) {
            // El UPDATE del constructor no aplica los casts del modelo.
            $values['guard_codes'] = json_encode($values['guard_codes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        // `update()` del constructor de Eloquent añade `updated_at` (mutable).
        return static::query()->whereKey($id)->whereIn('status', $from)
            ->when($withoutVerdict, fn ($q) => $q->whereNull('verdict'))
            ->update($values) === 1;
    }
}
