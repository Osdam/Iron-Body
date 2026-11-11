<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioMetaTemplates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * UN ENVÍO A META de una versión de plantilla de WhatsApp del Studio
 * (plantillas de Meta desde el CRM, PLANTILLAS_META_PLAN §5.4 y §5.7.1;
 * migración `2026_11_15_100001`). Nace SENDING justo antes del POST, fuera de
 * cualquier transacción con la red, y solo avanza: SENDING → SUBMITTED |
 * FAILED | UNKNOWN; UNKNOWN → SUBMITTED | FAILED. FAILED es definitivo y libera
 * la versión. Lo observado en Meta (`meta_*`, la coincidencia con el Studio)
 * es lo ÚLTIMO que se vio y solo existe con el envío SUBMITTED; el historial
 * va al libro. Lo que manda para el canal de W9 está en
 * `ultron_studio_template_meta_checks`, no aquí. Ver {@see StudioMetaTemplates}.
 *
 * Sin datos personales: quien envía es la instantánea del personal (id,
 * nombre y rol) y su persona por id. `error_user_message` es el texto de Meta
 * para el usuario, ya saneado.
 *
 * @property int $id
 * @property string $uuid
 * @property string $client_request_id
 * @property int $template_id
 * @property string $template_content_hash
 * @property string $meta_name
 * @property string $language
 * @property string $category
 * @property string $request_hash
 * @property string $state
 * @property string|null $meta_template_id
 * @property string|null $meta_status
 * @property string|null $meta_category
 * @property string|null $meta_correct_category
 * @property string|null $meta_rejected_reason
 * @property string|null $meta_quality
 * @property string|null $meta_body_hash
 * @property string|null $meta_components_hash
 * @property bool|null $matches_studio
 * @property string|null $mismatch_codes
 * @property bool $attester_unavailable
 * @property int|null $error_code
 * @property int|null $error_subcode
 * @property string|null $error_user_message
 * @property int $submitted_by_admin_id
 * @property int $submitted_by_person_id
 * @property string $submitted_by_name
 * @property string|null $submitted_by_role
 * @property Carbon $submitted_at
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $last_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StudioMetaTemplateSubmission extends Model
{
    public const STATE_SENDING = 'SENDING';

    public const STATE_SUBMITTED = 'SUBMITTED';

    public const STATE_FAILED = 'FAILED';

    public const STATE_UNKNOWN = 'UNKNOWN';

    public const STATES = [self::STATE_SENDING, self::STATE_SUBMITTED, self::STATE_FAILED, self::STATE_UNKNOWN];

    /** Hacia delante, solo así (`usmsub_state_forward`). FAILED es definitivo. */
    public const TRANSITIONS = [
        self::STATE_SENDING => [self::STATE_SUBMITTED, self::STATE_FAILED, self::STATE_UNKNOWN],
        self::STATE_UNKNOWN => [self::STATE_SUBMITTED, self::STATE_FAILED],
        self::STATE_SUBMITTED => [],
        self::STATE_FAILED => [],
    ];

    /** Lo único que cambia tras nacer (`MUTABLE_COLUMNS` de la migración). */
    public const MUTABLE = [
        'state', 'meta_template_id', 'meta_status', 'meta_category', 'meta_correct_category', 'meta_rejected_reason', 'meta_quality',
        'meta_body_hash', 'meta_components_hash', 'matches_studio', 'mismatch_codes', 'attester_unavailable',
        'error_code', 'error_subcode', 'error_user_message', 'last_checked_at', 'last_changed_at', 'updated_at',
    ];

    protected $table = 'ultron_studio_meta_template_submissions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'template_id' => 'integer',
            'matches_studio' => 'boolean',
            'attester_unavailable' => 'boolean',
            'error_code' => 'integer',
            'error_subcode' => 'integer',
            'submitted_by_admin_id' => 'integer',
            'submitted_by_person_id' => 'integer',
            'submitted_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_changed_at' => 'datetime',
        ];
    }

    /** ¿Sigue reclamando la versión y su nombre en Meta? (Todo lo que no es FAILED.) */
    public function isLive(): bool
    {
        return $this->state !== self::STATE_FAILED;
    }

    protected static function booted(): void
    {
        static::updating(function (self $s): void {
            if (array_diff(array_keys($s->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('ultron_studio: un envío a Meta fija al nacer la versión, lo que se envió y quién lo envió.');
            }
            $antes = (string) $s->getOriginal('state');
            if ($antes === self::STATE_FAILED) {
                throw new LogicException('ultron_studio: un envío a Meta fallido es definitivo.');
            }
            if ($s->isDirty('state') && ! in_array($s->state, self::TRANSITIONS[$antes] ?? [], true)) {
                throw new LogicException('ultron_studio: un envío a Meta solo avanza.');
            }
            if ($s->isDirty('meta_template_id') && $s->getOriginal('meta_template_id') !== null) {
                throw new LogicException('ultron_studio: el id de Meta de un envío se fija una sola vez.');
            }
        });
        static::deleting(fn () => throw new LogicException('ultron_studio: los envíos de plantillas a Meta no se borran.'));
    }
}
