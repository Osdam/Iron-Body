<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\MarketingKnowledgeItem;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Observability\ChannelLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestión INTERNA (n8n / operación, firmado HMAC) de la base de conocimiento
 * comercial. SIN endpoints públicos. No expone secretos: el contenido es
 * información comercial pública. No toca pagos ni facturación.
 *
 * RC-4 — QUÉ AUTORIDAD TIENE ESTA PUERTA, Y POR QUÉ NO ES LA QUE PARECÍA.
 *
 * Lo que se escribe aquí no es una nota: es el material con el que Laravel
 * construye `context.knowledge_base` y `context.gym`, es decir los hechos que
 * ULTRON puede afirmarle a un cliente. Antes, un POST publicaba en el acto.
 *
 * El problema no es el contenido, es el actor. `automation.internal` autentica
 * un SECRETO COMPARTIDO por todo el workflow: prueba que la llamada viene de
 * dentro, no quién la hizo. Eso es una máquina anónima, no una persona con
 * autoridad para definir la política de pagos del gimnasio.
 *
 * Así que la puerta sigue abierta —es la única vía que la operación tiene hoy
 * para redactar conocimiento, y cerrarla no haría el sistema más seguro, sólo
 * más manco— pero con la autoridad recortada a lo que corresponde:
 *
 *  1. Lo que entra por aquí nace en BORRADOR (`review_status=pending`) y NO
 *     llega al prompt hasta que alguien lo aprueba. La respuesta lo dice con
 *     `published:false`, para que n8n no crea que publicó.
 *  2. NO puede tocar un ítem ya aprobado: eso es 409. Envenenar `payment.wompi`
 *     o `identity.brand` —que existen y están aprobados— sería mucho peor que
 *     colar una entrada nueva, porque hereda la credibilidad de lo anterior.
 *     Editar un hecho vigente es trabajo de un humano identificado.
 *  3. Cada escritura deja rastro: procedencia, etiqueta del proponente y hora.
 *
 * La aprobación NO vive aquí, y no puede: aprobar con el mismo secreto que
 * propone no es aprobar. Hoy se hace desde el servidor
 * ({@see MarketingKnowledgeItem::approve()}); el panel del CRM es trabajo de
 * `backend` y usará `origin=human_panel`.
 */
class InternalMarketingKnowledgeController extends Controller
{
    /** Lo que se le responde a quien intenta reescribir un hecho vigente. */
    private const CODE_APPROVED = 'knowledge_item_approved';

    public function __construct(private readonly MarketingKnowledgeBaseService $kb) {}

    /** GET /api/internal/marketing/knowledge/doctor — resumen del conocimiento. */
    public function doctor(): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $this->kb->summary()]);
    }

    /** GET /api/internal/marketing/knowledge — lista ítems (filtros opcionales). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', Rule::in(MarketingKnowledgeItem::CATEGORIES)],
            'only_active' => 'nullable|boolean',
            // Sin este filtro, revisar los borradores obligaría a leerse la tabla entera.
            'review_status' => ['nullable', Rule::in(MarketingKnowledgeItem::REVIEW_STATUSES)],
            'origin' => ['nullable', Rule::in(MarketingKnowledgeItem::ORIGINS)],
        ]);

        $query = MarketingKnowledgeItem::query()
            ->when($data['category'] ?? null, fn ($q, $c) => $q->category($c))
            ->when($data['review_status'] ?? null, fn ($q, $s) => $q->where('review_status', $s))
            ->when($data['origin'] ?? null, fn ($q, $o) => $q->where('origin', $o))
            ->when($data['only_active'] ?? false, fn ($q) => $q->where('is_active', true))
            ->orderBy('category')->orderBy('priority')->orderBy('id');

        return response()->json([
            'ok' => true,
            'data' => $query->get()->map(fn (MarketingKnowledgeItem $i) => [
                'id' => $i->id,
                'category' => $i->category,
                'key' => $i->key,
                'title' => $i->title,
                'content' => $i->content,
                'priority' => $i->priority,
                'is_active' => (bool) $i->is_active,
                'valid_from' => optional($i->valid_from)->toIso8601String(),
                'valid_until' => optional($i->valid_until)->toIso8601String(),
                'source' => $i->source,
                // Procedencia, estado y auditoría: sin esto, «revisar lo
                // pendiente» es imposible desde fuera del servidor.
                'origin' => $i->origin,
                'review_status' => $i->review_status,
                'in_prompt' => (bool) $i->is_active && $i->isPublishable(),
                'submitted_by' => $i->submitted_by,
                'submitted_at' => optional($i->submitted_at)->toIso8601String(),
                'reviewed_by' => $i->reviewed_by,
                'reviewed_at' => optional($i->reviewed_at)->toIso8601String(),
                'updated_at' => optional($i->updated_at)->toIso8601String(),
            ]),
        ]);
    }

    /**
     * POST /api/internal/marketing/knowledge — PROPONE un ítem por `key`.
     *
     * No publica. Crea o actualiza un borrador, y se niega en redondo a tocar
     * un ítem aprobado.
     */
    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(MarketingKnowledgeItem::CATEGORIES)],
            'key' => 'required|string|max:120',
            'title' => 'nullable|string|max:160',
            'content' => 'required|string|max:4000',
            'priority' => 'nullable|integer|min:0|max:10000',
            'is_active' => 'nullable|boolean',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date',
            'metadata' => 'nullable|array',
            /*
             * Etiqueta de quien dice estar proponiendo. Es una AFIRMACIÓN del
             * llamante, no una identidad: quien tiene el secreto puede escribir
             * aquí lo que quiera. Sirve para la traza operativa, jamás para
             * conceder autoridad, y por eso no cambia nada del flujo.
             */
            'submitted_by' => 'nullable|string|max:60',
        ]);

        $existing = MarketingKnowledgeItem::where('key', $data['key'])->first();

        /*
         * Un hecho ya aprobado no se reescribe desde una máquina anónima. La
         * alternativa —dejar escribir y degradar a borrador— le daría a
         * cualquiera con el secreto la capacidad de vaciar el conocimiento del
         * asesor con un bucle de POSTs, y de borrar el contenido bueno al
         * hacerlo. Aquí no se pierde nada y no se publica nada.
         */
        if ($existing !== null && $existing->isPublishable()) {
            ChannelLog::warning('marketing.knowledge.rejected_overwrite', [
                'key' => $existing->key,
                'category' => $existing->category,
                'origin' => $existing->origin,
                'submitted_by' => MarketingKnowledgeItem::actorLabel($data['submitted_by'] ?? null),
            ]);

            return response()->json([
                'ok' => false,
                'code' => self::CODE_APPROVED,
                'message' => 'Ese ítem ya está aprobado: la edición de conocimiento vigente la hace una persona identificada.',
                'key' => $existing->key,
            ], 409);
        }

        $item = MarketingKnowledgeItem::updateOrCreate(
            ['key' => $data['key']],
            [
                'category' => $data['category'],
                'title' => $data['title'] ?? null,
                'content' => $data['content'],
                'priority' => $data['priority'] ?? 100,
                'is_active' => $data['is_active'] ?? true,
                'valid_from' => $data['valid_from'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'source' => $existing?->source ?? 'api',
                // Declarado explícitamente: el modelo no tiene que adivinar de
                // dónde vino, y el estado `pending` se deriva de esto.
                'origin' => MarketingKnowledgeItem::ORIGIN_INTERNAL_API,
                'submitted_by' => $data['submitted_by'] ?? null,
                'submitted_at' => now(),
            ],
        );

        /*
         * Auditoría: quién dice ser, qué key, cuándo. El CONTENIDO no se
         * escribe en el log —puede ser cualquier cosa, incluido un intento de
         * inyección— pero sí su huella, que permite comparar dos propuestas sin
         * guardar el texto.
         */
        ChannelLog::warning('marketing.knowledge.proposed', [
            'key' => $item->key,
            'category' => $item->category,
            'origin' => $item->origin,
            'review_status' => $item->review_status,
            'created' => $item->wasRecentlyCreated,
            'submitted_by' => $item->submitted_by,
            'content_sha256' => substr(hash('sha256', (string) $item->content), 0, 16),
        ]);

        return response()->json([
            'ok' => true,
            'created' => $item->wasRecentlyCreated,
            'id' => $item->id,
            'key' => $item->key,
            // El contrato explícito con n8n: esto NO está en el prompt.
            'published' => $item->isPublishable(),
            'review_status' => $item->review_status,
            'origin' => $item->origin,
            'message' => 'Guardado como borrador. No llega al asesor hasta que una persona lo apruebe.',
        ]);
    }
}
