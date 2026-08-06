<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un problema del canal, agrupado por CLASE y no por ocurrencia.
 *
 * Doscientos mensajes que fallan por el mismo código de Meta son un incidente
 * con doscientas ocurrencias. Esa distinción es la que mantiene el panel
 * legible: si cada fallo abriera su propia alarma, un worker caído una hora
 * llenaría la pantalla y nadie volvería a mirarla.
 */
class Incident extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_IGNORED = 'ignored';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    /** De menor a mayor. Se usa para decidir si un incidente escala. */
    public const SEVERITY_ORDER = [
        self::SEVERITY_LOW => 1,
        self::SEVERITY_MEDIUM => 2,
        self::SEVERITY_HIGH => 3,
        self::SEVERITY_CRITICAL => 4,
    ];

    protected $fillable = [
        'fingerprint', 'source', 'kind', 'title', 'severity', 'status',
        'first_seen_at', 'last_seen_at', 'occurrences',
        'affected_conversations', 'affected_messages',
        'evidence', 'correlation_ids',
        'root_cause', 'confidence', 'recommended_action', 'prevention',
        'analyzed_by', 'analyzed_at', 'release',
        'assigned_to_admin_id', 'acknowledged_at', 'resolved_at', 'resolution',
    ];

    protected $casts = [
        'evidence' => 'array',
        'correlation_ids' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'analyzed_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'occurrences' => 'integer',
        'affected_conversations' => 'integer',
        'affected_messages' => 'integer',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(IncidentEvent::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_ACKNOWLEDGED], true);
    }

    public static function severityRank(?string $severity): int
    {
        return self::SEVERITY_ORDER[$severity ?? ''] ?? 0;
    }

    /** ¿La severidad entrante es peor que la actual? Un incidente solo empeora. */
    public function shouldEscalateTo(string $severity): bool
    {
        return self::severityRank($severity) > self::severityRank($this->severity);
    }
}
