<?php

namespace App\Models;

use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\Ultron\GymFactsProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Ítem de la base de conocimiento comercial (Fase 3.5). Contenido editable que
 * alimenta el prompt del cerebro IA. No guarda secretos: es información pública
 * orientada a la venta (planes, políticas, tono, objeciones, faq).
 *
 * RC-4 — LA FRONTERA DE CONFIANZA VIVE AQUÍ, NO EN EL CONTROLADOR.
 *
 * Esto no es «texto de apoyo»: es lo que Laravel entrega como
 * `context.knowledge_base` y `context.gym`, o sea los HECHOS del gimnasio para
 * el turno siguiente. Quien escribe una fila no propone: define la verdad.
 *
 * De ahí las dos columnas nuevas y una única regla:
 *
 *  - `origin` dice QUIÉN escribió. Hay orígenes confiables —código desplegado
 *    (seeder, consola), un humano identificado del panel del CRM, y lo que ya
 *    existía antes de esta frontera— y orígenes que NO lo son: `internal_api`
 *    (el secreto compartido de automatización, que identifica a una máquina
 *    anónima, no a una persona) e `import`.
 *  - `review_status` dice si ese texto puede publicarse. Sólo `approved` llega
 *    al prompt ({@see scopeActiveNow}).
 *
 * La regla: un cambio de SUSTANCIA (category/title/content) hecho por un origen
 * no confiable deja la fila en `pending` y borra la revisión anterior. Vive en
 * un hook del modelo y no en el controlador a propósito: así también cubre el
 * próximo camino de escritura que alguien añada sin acordarse de esto.
 *
 * `review_status` NO es asignable en masa. Se aprueba llamando a
 * {@see approve()}, que además deja constancia de quién y cuándo.
 */
class MarketingKnowledgeItem extends Model
{
    /** Categorías permitidas. */
    public const CATEGORIES = [
        'business_identity', 'location', 'schedule', 'plans', 'pricing_policy',
        'payment_policy', 'membership_policy', 'invoice_policy', 'objections',
        'tone', 'restrictions', 'faq', 'human_escalation',
    ];

    // ── Procedencia ───────────────────────────────────────────────────────────

    /** Sembrado por código desplegado. */
    public const ORIGIN_SEEDER = 'seeder';

    /** Escrito por código del servidor (consola, migración, job). */
    public const ORIGIN_SERVER = 'server';

    /** Escrito por un humano identificado desde el CRM. */
    public const ORIGIN_HUMAN_PANEL = 'human_panel';

    /** Existía antes de que hubiera frontera. Se respeta, y se puede auditar. */
    public const ORIGIN_LEGACY = 'legacy';

    /** Entró por el secreto compartido de automatización: máquina anónima. */
    public const ORIGIN_INTERNAL_API = 'internal_api';

    /** Carga masiva desde un fichero o un tercero. */
    public const ORIGIN_IMPORT = 'import';

    /**
     * Nadie dijo de dónde viene. NO es confiable, y ese es el punto: una
     * escritura que no declara su procedencia es indistinguible del camino que
     * alguien añada mañana sin acordarse de esta frontera.
     */
    public const ORIGIN_UNKNOWN = 'unknown';

    public const ORIGINS = [
        self::ORIGIN_SEEDER, self::ORIGIN_SERVER, self::ORIGIN_HUMAN_PANEL,
        self::ORIGIN_LEGACY, self::ORIGIN_INTERNAL_API, self::ORIGIN_IMPORT,
        self::ORIGIN_UNKNOWN,
    ];

    /**
     * Orígenes con autoridad para publicar sin revisión. Es una lista blanca:
     * cualquier valor desconocido cae del lado no confiable.
     */
    public const TRUSTED_ORIGINS = [
        self::ORIGIN_SEEDER, self::ORIGIN_SERVER, self::ORIGIN_HUMAN_PANEL, self::ORIGIN_LEGACY,
    ];

    // ── Estado de revisión ────────────────────────────────────────────────────

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    public const REVIEW_STATUSES = [self::REVIEW_PENDING, self::REVIEW_APPROVED, self::REVIEW_REJECTED];

    /** Campos cuyo cambio convierte la fila en un hecho distinto. */
    private const SUBSTANTIVE = ['category', 'title', 'content'];

    /**
     * `review_status`, `reviewed_by` y `reviewed_at` quedan FUERA a propósito:
     * aprobarse a uno mismo por asignación en masa es justo el agujero.
     */
    protected $fillable = [
        'category', 'key', 'title', 'content', 'priority', 'is_active',
        'valid_from', 'valid_until', 'source', 'metadata',
        'origin', 'submitted_by', 'submitted_at',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            /*
             * Si esta escritura cambia `source` y no declara `origin`, manda el
             * `source` de ahora: el seeder que reescribe una key que alguien
             * había propuesto por la API vuelve a ser el dueño de esa fila. Sin
             * esto, un despliegue dejaría su propio contenido en borrador.
             */
            if ($item->isDirty('source') && ! $item->isDirty('origin')) {
                $item->origin = null;
            }
            $item->origin = self::normalizeOrigin($item->origin, $item->source);
            $item->submitted_by = self::actorLabel($item->submitted_by);
            $item->reviewed_by = self::actorLabel($item->reviewed_by);

            // Sólo se decide cuando nace o cuando cambia el hecho. Aprobar,
            // desactivar o reordenar no reabre la revisión.
            if ($item->exists && ! $item->isDirty(self::SUBSTANTIVE)) {
                return;
            }

            if ($item->isTrustedOrigin()) {
                $item->review_status = self::REVIEW_APPROVED;

                return;
            }

            $item->review_status = self::REVIEW_PENDING;
            $item->reviewed_by = null;
            $item->reviewed_at = null;
        });
    }

    /**
     * Origen efectivo. Si nadie lo declara se deduce de `source` (la columna
     * que ya existía), y solo para los valores que esa columna ya usaba.
     *
     * LA CONFIANZA SE DECLARA, NO SE DEDUCE. Antes, un `source` desconocido
     * —o ausente— caía en `server`, que es confiable, así que cualquier camino
     * de escritura nuevo publicaba directo al prompt sin que nadie lo aprobara:
     * exactamente lo contrario de lo que promete el encabezado de esta clase.
     * Ahora cae en `unknown`, que no lo es. El código del servidor que quiera
     * publicar sin revisión lo dice con `origin`, y decirlo cuesta una línea.
     */
    public static function normalizeOrigin(?string $origin, ?string $source = null): string
    {
        if (in_array($origin, self::ORIGINS, true)) {
            return (string) $origin;
        }
        if ($origin !== null && $origin !== '') {
            return self::ORIGIN_INTERNAL_API;
        }

        return match ($source) {
            'seeder' => self::ORIGIN_SEEDER,
            'api' => self::ORIGIN_INTERNAL_API,
            'import' => self::ORIGIN_IMPORT,
            default => self::ORIGIN_UNKNOWN,
        };
    }

    /**
     * Etiqueta corta de actor para la auditoría. NO es identidad ni sustituye a
     * una sesión: es un rótulo. Se sanea para que por aquí no entren correos,
     * teléfonos ni saltos de línea en el log.
     */
    public static function actorLabel(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $clean = preg_replace('/[^A-Za-z0-9._:\- ]+/', '', trim($raw)) ?? '';
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');

        return $clean === '' ? null : mb_substr($clean, 0, 60);
    }

    public function isTrustedOrigin(): bool
    {
        return in_array($this->origin, self::TRUSTED_ORIGINS, true);
    }

    /** ¿Puede este texto llegar al prompt como hecho del gimnasio? */
    public function isPublishable(): bool
    {
        return $this->review_status === self::REVIEW_APPROVED;
    }

    /**
     * ¿Está AHORA MISMO en el prompt?
     *
     * Aprobado no basta: {@see scopeActiveNow} pide además estar activo y
     * dentro de su vigencia. Responder solo por la aprobación hacía que un
     * horario caducado ayer se reportara como vigente, y quien depura «por qué
     * ULTRON no sabe el horario nuevo» recibía la respuesta contraria a la
     * verdad. La regla vive en un sitio y se contesta desde él.
     */
    public function reachesPrompt(): bool
    {
        $ahora = now();

        return (bool) $this->is_active
            && $this->isPublishable()
            && ($this->valid_from === null || $this->valid_from->lessThanOrEqualTo($ahora))
            && ($this->valid_until === null || $this->valid_until->greaterThanOrEqualTo($ahora));
    }

    /** Alguien con autoridad da por bueno el texto. Deja constancia. */
    public function approve(string $by): self
    {
        $this->forceFill([
            'review_status' => self::REVIEW_APPROVED,
            'reviewed_by' => self::actorLabel($by),
            'reviewed_at' => now(),
        ])->save();

        return $this;
    }

    /** Lo contrario. No borra la fila: deja el rastro de que se descartó. */
    public function reject(string $by): self
    {
        $this->forceFill([
            'review_status' => self::REVIEW_REJECTED,
            'reviewed_by' => self::actorLabel($by),
            'reviewed_at' => now(),
        ])->save();

        return $this;
    }

    /**
     * Activos, vigentes Y APROBADOS: lo único que puede presentarse al modelo
     * como hecho. El filtro va aquí y no en cada llamador porque los dos
     * consumidores del prompt —{@see MarketingKnowledgeBaseService}
     * para `knowledge_base` y {@see GymFactsProvider}
     * para `gym`— pasan por este scope; cerrarlo en un solo sitio cierra los dos.
     */
    public function scopeActiveNow(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where('review_status', self::REVIEW_APPROVED)
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now))
            ->orderBy('priority')
            ->orderBy('id');
    }

    /** Lo propuesto y todavía sin revisar. */
    public function scopePendingReview(Builder $query): Builder
    {
        return $query->where('review_status', self::REVIEW_PENDING);
    }

    /**
     * Publicado pese a venir de un origen sin autoridad y SIN que nadie lo haya
     * mirado nunca: lo que entró por la máquina anónima antes de que existiera
     * esta frontera. No se apaga solo —eso dejaría al asesor sin conocimiento en
     * el despliegue— pero queda contado para que alguien lo revise.
     */
    public function scopeApprovedFromUntrustedOrigin(Builder $query): Builder
    {
        return $query->where('review_status', self::REVIEW_APPROVED)
            /*
             * El nulo va explícito. `NOT IN` con `origin = NULL` no da
             * verdadero en SQL: da NULL, así que una fila sin procedencia no
             * la contaba nadie mientras el prompt sí la servía. La columna es
             * NOT NULL desde la migración `2026_09_19_060000`, y esto es el
             * cinturón además de los tirantes: un dump viejo restaurado no
             * puede volverse invisible para el único contador que lo vigila.
             */
            ->where(fn (Builder $q) => $q->whereNull('origin')->orWhereNotIn('origin', self::TRUSTED_ORIGINS))
            ->whereNull('reviewed_at');
    }

    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }
}
