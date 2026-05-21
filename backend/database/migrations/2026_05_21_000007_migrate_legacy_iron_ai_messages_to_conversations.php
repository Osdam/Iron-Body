<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Migra mensajes IRON IA previos (sin conversación) a conversaciones reales.
 * Agrupa por el `conversation_id` legacy (member-X / user-X / anon-uuid) y crea
 * una conversación "Historial anterior" por grupo. Idempotente: solo toca
 * mensajes con iron_ai_conversation_id NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $groups = DB::table('iron_ai_messages')
            ->select('conversation_id')
            ->whereNull('iron_ai_conversation_id')
            ->whereNotNull('conversation_id')
            ->groupBy('conversation_id')
            ->pluck('conversation_id');

        foreach ($groups as $legacyKey) {
            $messages = DB::table('iron_ai_messages')
                ->where('conversation_id', $legacyKey)
                ->whereNull('iron_ai_conversation_id')
                ->orderBy('id')
                ->get();

            if ($messages->isEmpty()) {
                continue;
            }

            $first = $messages->first();
            $last = $messages->last();
            $uuid = (string) Str::uuid();
            $now = Carbon::now();

            $lastUserOrAssistant = $messages
                ->whereIn('role', ['user', 'assistant'])
                ->last() ?? $last;

            $conversationId = DB::table('iron_ai_conversations')->insertGetId([
                'uuid'                 => $uuid,
                'user_id'              => $first->user_id,
                'member_id'            => $first->member_id,
                'document'             => null,
                'title'                => 'Historial anterior',
                'topic'                => 'general',
                'summary'              => 'Conversaciones previas a la organización por chats.',
                'last_message_preview' => mb_substr((string) ($lastUserOrAssistant->content ?? ''), 0, 200),
                'messages_count'       => $messages->count(),
                'status'               => 'active',
                'metadata'             => json_encode(['legacy_key' => $legacyKey]),
                'last_message_at'      => $last->created_at ?? $now,
                'created_at'           => $first->created_at ?? $now,
                'updated_at'           => $now,
            ]);

            DB::table('iron_ai_messages')
                ->where('conversation_id', $legacyKey)
                ->whereNull('iron_ai_conversation_id')
                ->update([
                    'iron_ai_conversation_id' => $conversationId,
                    'conversation_uuid'       => $uuid,
                ]);
        }
    }

    public function down(): void
    {
        // No revertimos la migración de datos (no se pierde historial).
    }
};
