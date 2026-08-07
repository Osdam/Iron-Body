<?php

namespace App\Services\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingConversationTag;
use App\Models\MarketingTag;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Str;

/**
 * Etiquetas de conversación, con catálogo y con memoria de quién las puso.
 *
 * Idempotente: añadir una que ya está no falla, quitar una ausente tampoco.
 *
 * La regla que da sentido al módulo: **una persona no puede tocar una etiqueta
 * de origen**. «Meta Ads» no es una opinión del equipo, es la lectura de lo que
 * informó el canal; si alguien pudiera retirarla de una conversación que vino
 * de un anuncio, los números de esa campaña dejarían de cuadrar y nadie sabría
 * por qué. El sistema sí puede ponerlas y quitarlas, porque el sistema las
 * deriva de la atribución.
 *
 * Se conserva la firma anterior —`apply()` devolviendo babosas— a propósito: el
 * inbox actual y sus pruebas dependen de ella, y romperlas para estrenar el
 * catálogo habría sido cambiar dos cosas a la vez.
 */
class MarketingConversationTagService
{
    private const MAX_LEN = 40;

    /**
     * Aplica cambios MANUALES. Rechaza en silencio lo que no se puede tocar.
     *
     * @param  string[]  $add
     * @param  string[]  $remove
     * @return string[] etiquetas vigentes tras aplicar los cambios
     */
    public function apply(MarketingConversation $conversation, array $add, array $remove, ?int $actorAdminId): array
    {
        foreach ($add as $raw) {
            $slug = $this->slug($raw);

            if ($slug === null || ! $this->manuallyEditable($slug)) {
                continue;
            }

            $this->attach($conversation, $slug, MarketingTag::KIND_MANUAL, $actorAdminId);
        }

        $removeSlugs = array_values(array_filter(
            array_map(fn ($r) => $this->slug($r), $remove),
            fn (?string $slug) => $slug !== null && $this->manuallyEditable($slug),
        ));

        if ($removeSlugs !== []) {
            MarketingConversationTag::where('conversation_id', $conversation->id)
                ->whereIn('tag', $removeSlugs)
                ->delete();
        }

        return $this->current($conversation);
    }

    /**
     * Asigna una etiqueta EN NOMBRE DEL SISTEMA.
     *
     * Es la vía por la que entran las automáticas y las de origen, y la única
     * que puede tocar las bloqueadas. Lleva evidencia porque una etiqueta que
     * el sistema pone sin poder explicar por qué es indistinguible de un error.
     *
     * @param  array<string,mixed>  $evidence
     */
    public function attachSystem(
        MarketingConversation $conversation,
        string $slug,
        string $kind,
        array $evidence = [],
    ): ?MarketingConversationTag {
        $normalized = $this->slug($slug);

        if ($normalized === null) {
            return null;
        }

        return $this->attach($conversation, $normalized, $kind, null, $evidence);
    }

    /** Retira una etiqueta puesta por el sistema (p. ej. dejó de aplicar). */
    public function detachSystem(MarketingConversation $conversation, string $slug): void
    {
        $normalized = $this->slug($slug);

        if ($normalized === null) {
            return;
        }

        MarketingConversationTag::where('conversation_id', $conversation->id)
            ->where('tag', $normalized)
            ->whereIn('assigned_kind', [
                MarketingTag::KIND_AUTOMATIC,
                MarketingTag::KIND_SYSTEM,
                MarketingTag::KIND_SOURCE,
            ])
            ->delete();
    }

    /**
     * Etiquetas con todo su contexto, para el panel derecho.
     *
     * @return array<int,array<string,mixed>>
     */
    public function detailed(MarketingConversation $conversation): array
    {
        $assignments = MarketingConversationTag::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('tag')
            ->get();

        if ($assignments->isEmpty()) {
            return [];
        }

        // Una consulta para todo el catálogo implicado: sin esto, el panel
        // haría una por etiqueta y la lista de conversaciones se volvería un
        // N+1 en cuanto alguien etiquetara de verdad.
        $catalog = MarketingTag::query()
            ->whereIn('slug', $assignments->pluck('tag')->unique())
            ->get()
            ->keyBy('slug');

        return $assignments->map(function (MarketingConversationTag $assignment) use ($catalog) {
            $tag = $catalog->get($assignment->tag);

            return [
                'slug' => $assignment->tag,
                'name' => $tag?->name ?? $assignment->tag,
                'description' => $tag?->description,
                'category' => $tag?->category ?? MarketingTag::CATEGORY_COMMERCIAL,
                'kind' => $assignment->assigned_kind ?? MarketingTag::KIND_MANUAL,
                'color' => $tag?->color ?? 'neutral',
                // Que sea editable depende del CATÁLOGO, no de quién pregunte.
                'editable' => $tag === null ? true : $tag->isManuallyEditable(),
                'evidence' => $assignment->evidence,
                'assigned_by' => $assignment->created_by,
                'assigned_at' => $assignment->created_at?->toIso8601String(),
                'priority' => $tag?->listPriority() ?? 30,
            ];
        })->sortBy('priority')->values()->all();
    }

    /**
     * Las dos que se enseñan en la lista de conversaciones.
     *
     * Dos y no todas: con cinco etiquetas por fila la lista se vuelve una pared
     * de colores y deja de servir para lo único que sirve, que es decidir a
     * quién atender primero. El resto vive en el panel.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forList(MarketingConversation $conversation): array
    {
        // Una por familia, no las dos primeras. Dos etiquetas operativas
        // informan menos que una operativa y una de origen: la primera dice
        // que hay que actuar, la segunda de donde vino la persona. Quedarse
        // con las dos mas prioritarias enterraba el origen en cuanto habia dos
        // asuntos abiertos, que es justo cuando mas se mira la lista.
        $seen = [];
        $chosen = [];

        foreach ($this->detailed($conversation) as $tag) {
            if (isset($seen[$tag['category']])) {
                continue;
            }

            $seen[$tag['category']] = true;
            $chosen[] = $tag;

            if (count($chosen) === 2) {
                break;
            }
        }

        return $chosen;
    }

    /** Normaliza a slug `[a-z0-9-]` acotado; null si queda vacío o inválido. */
    public function slug(string $raw): ?string
    {
        $slug = Str::slug(trim($raw));

        if ($slug === '') {
            return null;
        }

        return Str::limit($slug, self::MAX_LEN, '');
    }

    /**
     * ¿Existe en el catálogo y admite mano humana?
     *
     * Una etiqueta que NO está en el catálogo sí se puede crear: el equipo debe
     * poder inventarse las suyas sin pedir permiso. Lo que no se puede es tocar
     * una que el catálogo declara bloqueada.
     */
    private function manuallyEditable(string $slug): bool
    {
        $tag = MarketingTag::query()->where('slug', $slug)->first();

        if ($tag === null) {
            return true;
        }

        if (! $tag->isManuallyEditable()) {
            ChannelLog::info('marketing.tag.manual_edit_refused', [
                'slug' => $slug,
                'kind' => $tag->kind,
            ]);

            return false;
        }

        return true;
    }

    /** @param array<string,mixed> $evidence */
    private function attach(
        MarketingConversation $conversation,
        string $slug,
        string $kind,
        ?int $actorAdminId,
        array $evidence = [],
    ): MarketingConversationTag {
        $assignment = MarketingConversationTag::firstOrCreate(
            ['conversation_id' => $conversation->id, 'tag' => $slug],
            [
                'created_by' => $actorAdminId,
                'assigned_kind' => $kind,
                'evidence' => $evidence ?: null,
            ],
        );

        // Una etiqueta ya presente que ahora respalda el sistema conserva su
        // fila pero actualiza la evidencia: importa el porqué más reciente.
        if (! $assignment->wasRecentlyCreated && $evidence !== []) {
            $assignment->forceFill(['evidence' => $evidence])->save();
        }

        return $assignment;
    }

    /** @return string[] */
    private function current(MarketingConversation $conversation): array
    {
        return MarketingConversationTag::where('conversation_id', $conversation->id)
            ->orderBy('tag')
            ->pluck('tag')
            ->all();
    }
}
