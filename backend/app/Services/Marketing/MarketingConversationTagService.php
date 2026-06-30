<?php

namespace App\Services\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingConversationTag;
use Illuminate\Support\Str;

/**
 * Tags de conversación. Normaliza a slug simple, valida tamaño y unicidad.
 * Idempotente: añadir un tag existente no falla, quitar uno ausente tampoco.
 */
class MarketingConversationTagService
{
    private const MAX_LEN = 40;

    /**
     * @param  string[] $add
     * @param  string[] $remove
     * @return string[] tags vigentes tras aplicar los cambios
     */
    public function apply(MarketingConversation $conversation, array $add, array $remove, ?int $actorAdminId): array
    {
        foreach ($add as $raw) {
            $slug = $this->slug($raw);
            if ($slug === null) {
                continue;
            }
            MarketingConversationTag::firstOrCreate(
                ['conversation_id' => $conversation->id, 'tag' => $slug],
                ['created_by' => $actorAdminId],
            );
        }

        $removeSlugs = array_values(array_filter(array_map(fn ($r) => $this->slug($r), $remove)));
        if ($removeSlugs !== []) {
            MarketingConversationTag::where('conversation_id', $conversation->id)
                ->whereIn('tag', $removeSlugs)
                ->delete();
        }

        return MarketingConversationTag::where('conversation_id', $conversation->id)
            ->orderBy('tag')
            ->pluck('tag')
            ->all();
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
}
