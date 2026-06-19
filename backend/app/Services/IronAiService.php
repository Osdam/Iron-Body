<?php

namespace App\Services;

use App\Models\IronAiConversation;
use App\Models\IronAiMessage;
use App\Models\IronAiMessageAttachment;
use App\Models\IronAiRecommendation;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Routine;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * IRON IA — asistente de Iron Body con OpenAI.
 *
 * Arquitectura: Flutter → Laravel → OpenAI.
 *  - La API key de OpenAI vive SOLO en el backend (config/services.openai).
 *  - Flutter nunca la ve ni llama a OpenAI: solo consume /api/iron-ai/*.
 *  - El contexto del usuario se arma aquí con datos REALES y disponibles;
 *    si un dato no existe, no se inventa.
 */
class IronAiService
{
    /** Mensaje seguro mostrado a Flutter cuando OpenAI no responde. */
    public const FRIENDLY_ERROR =
        'IRON IA no está disponible en este momento. Intenta nuevamente en unos minutos.';

    /**
     * System prompt oficial de IRON IA (no se expone a Flutter).
     */
    private const SYSTEM_PROMPT = <<<'TXT'
Eres IRON, el asistente IA oficial de Iron Body Centro de Acondicionamiento Físico. Respondes en español, con tono profesional, motivador, claro y directo. Ayudas al usuario con rutinas, técnica de ejercicios, organización del entrenamiento, hábitos saludables, nutrición general y seguimiento de progreso dentro de la app Iron Body.

Usa el contexto del usuario cuando esté disponible, pero no inventes datos. Si falta información, pregunta de forma breve. No des diagnósticos médicos, no formules tratamientos, no reemplaces a médicos, fisioterapeutas o nutricionistas. Si el usuario reporta dolor fuerte, lesión, mareo, enfermedad, medicación, embarazo o síntomas graves, recomienda consultar a un profesional de salud.

Mantén las respuestas enfocadas en fitness, entrenamiento y uso de Iron Body. No ayudes con temas peligrosos, ilegales o fuera del alcance. No recomiendes esteroides, sustancias ilegales, dietas extremas, deshidratación, ayunos peligrosos ni sobreentrenamiento. Da recomendaciones prácticas, seguras y adaptadas al nivel del usuario.
TXT;

    /** Instrucciones de seguridad adicionales cuando el usuario adjunta imagen. */
    private const IMAGE_SAFETY_PROMPT = <<<'TXT'

El usuario adjuntó una IMAGEN. Analízala SOLO en el contexto de fitness, entrenamiento o nutrición general. Reglas estrictas:
- No diagnostiques lesiones ni des conclusiones médicas.
- Si observas señales de dolor, lesión o riesgo, recomienda consultar a un profesional de la salud.
- Para técnica de ejercicio: da recomendaciones generales y seguras de postura.
- Para comida: orientación nutricional general (no dietas clínicas ni planes médicos).
- Si la imagen no es clara o no se relaciona con fitness, dilo con amabilidad y pide otra foto.
TXT;

    /** Mensaje amable cuando no se pudo transcribir el audio. */
    public const AUDIO_ERROR =
        'No pude procesar el audio. Intenta grabarlo nuevamente.';

    /** Mensaje amable cuando no se pudo analizar la imagen. */
    public const IMAGE_ERROR =
        'No pude analizar la imagen en este momento. Intenta con otra foto más clara.';

    /** System prompt oficial (para reusar en realtime/visión). */
    public function systemPrompt(): string
    {
        return self::SYSTEM_PROMPT;
    }

    /** Sugerencias rápidas por defecto que se devuelven a Flutter. */
    private const DEFAULT_SUGGESTIONS = [
        'Ayúdame con mi rutina de hoy',
        'Analiza mi progreso',
        'Recomiéndame una rutina',
        'Consejo de nutrición general',
    ];

    public function __construct(
        private readonly IronAiMediaService $media,
        private readonly IronAiTranscriptionService $transcription,
        private readonly IronAiVisionService $vision,
        private readonly GymEquipmentContextService $gymEquipment,
    ) {
    }

    /**
     * Bloque de contexto GENERAL del gimnasio (no por usuario): qué equipos/
     * máquinas hay disponibles. Es una restricción dura para que IRON no
     * recomiende ejercicios con máquinas inexistentes. Se actualiza solo cuando
     * el CRM crea/edita/elimina o marca un equipo como dañado/fuera de servicio
     * (ver GymEquipmentContextService, cacheado e invalidado por el CRUD).
     *
     * Devuelve '' si no hay equipos registrados (la IA opera sin la restricción).
     */
    public function gymEquipmentConstraint(): string
    {
        return $this->gymEquipment->promptConstraint();
    }

    /**
     * El bloque anterior como mensaje(s) de sistema para el payload del chat.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function gymEquipmentMessages(): array
    {
        $constraint = $this->gymEquipmentConstraint();
        if ($constraint === '') {
            return [];
        }

        return [['role' => 'system', 'content' => $constraint]];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Resolución del usuario actual (auth aún no unificado → mecanismo flexible)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resuelve el usuario/miembro actual SOLO desde la sesión autenticada.
     *
     * SEGURIDAD (anti-suplantación): la identidad jamás se toma de campos del
     * body (member_id/document/email). Esos campos permitirían que un usuario
     * cargara la memoria, conversaciones, cuota y contexto IA de otro. El orden
     * es estricto:
     *   1) `auth_member` (atributo puesto por el middleware auth.member) →
     *      prioridad ABSOLUTA: identidad verificada por token/sesión.
     *   2) Bearer token directo (defensa en profundidad; mismo origen verificado)
     *      por si alguna ruta interna resolviera el contexto sin auth.member.
     * Sin token válido → conversación anónima (IRON responde sin contexto
     * personal). La IA sigue aprendiendo por usuario porque el member_id sale
     * del token, no de un valor elegido por el cliente.
     *
     * @return array{member: ?Member, user: ?User, conversation_id: string, document: ?string}
     */
    public function resolveContext(Request $request): array
    {
        $member = null;
        $user = null;

        // 1) Miembro autenticado por el middleware auth.member (prioridad total).
        $authMember = $request->attributes->get('auth_member');
        if ($authMember instanceof Member) {
            $member = $authMember;
        }

        // 2) Bearer token = session_token de dispositivo (2FA) o access_hash.
        // Mismo origen verificado que (1); nunca identidad arbitraria del body.
        if (! $member && ($token = $request->bearerToken())) {
            $member = Member::resolveByToken($token);
        }

        if ($member && $member->user_id) {
            $user = $member->loadMissing('user')->user;
        }

        $conversationId = $this->conversationKey($member, $user, $request->input('conversation_id'));

        // Documento resuelto: SIEMPRE del miembro/usuario autenticado (no del body).
        $resolvedDocument = $member?->document_number ?? $user?->document;

        return [
            'member'          => $member,
            'user'            => $user,
            'conversation_id' => $conversationId,
            'document'        => $resolvedDocument,
        ];
    }

    private function conversationKey(?Member $member, ?User $user, ?string $provided): string
    {
        if ($member) {
            return 'member-' . $member->id;
        }
        if ($user) {
            return 'user-' . $user->id;
        }
        if ($provided && Str::startsWith($provided, 'anon-')) {
            return $provided;
        }

        return 'anon-' . Str::uuid();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Chat
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Procesa un mensaje del usuario y devuelve la respuesta de IRON.
     *
     * $capabilities (opcional) controla el costo/contexto según la membresía:
     *   - max_output_tokens (int)
     *   - context_level ('basic'|'personalized'|'full')
     *
     * @return array{reply: string, conversation_id: string, suggestions: array<int, string>,
     *               is_fallback: bool, message_id: ?int, model: ?string,
     *               input_tokens: ?int, output_tokens: ?int}
     */
    public function chat(
        IronAiConversation $conversation,
        ?Member $member,
        ?User $user,
        string $message,
        array $capabilities = [],
    ): array {
        $message = trim($message);
        $contextLevel = $capabilities['context_level'] ?? 'full';
        $maxTokens = $this->maxTokensFrom($capabilities);

        // Guarda el mensaje del usuario (historial de ESTA conversación).
        $userMsg = $this->store($conversation, $member, $user, IronAiMessage::ROLE_USER, $message);

        $payload = $this->buildTextPayload($conversation, $member, $user, $contextLevel);
        $ai = $this->callOpenAi($payload, $member, $user, $maxTokens);

        return $this->finalizeReply($conversation, $member, $user, $message, $userMsg, $ai);
    }

    /**
     * Chat por VOZ: transcribe el audio (OpenAI) y lo procesa como un mensaje de
     * texto normal. El audio queda como adjunto del mensaje del usuario. Si la
     * transcripción falla, devuelve un resultado con `transcription_failed`.
     *
     * @return array{transcription_failed?: bool, transcript?: string, ...}
     */
    public function audioChat(
        IronAiConversation $conversation,
        ?Member $member,
        ?User $user,
        IronAiMessageAttachment $audio,
        array $capabilities = [],
    ): array {
        $path = $this->media->absolutePath($audio);
        $tr = $path ? $this->transcription->transcribe($path, $audio->original_name ?: 'audio.m4a') : null;

        if ($tr === null || trim($tr['text'] ?? '') === '') {
            // No se pudo transcribir → no consumimos cuota ni llamamos al chat.
            return [
                'transcription_failed' => true,
                'reply'                => self::AUDIO_ERROR,
                'conversation_id'      => $conversation->uuid,
                'conversation_uuid'    => $conversation->uuid,
                'transcript'           => null,
                'suggestions'          => [],
                'is_fallback'          => true,
                'message_id'           => null,
                'model'                => config('services.openai.transcription_model'),
                'input_tokens'         => null,
                'output_tokens'        => null,
            ];
        }

        $transcript = trim($tr['text']);
        $this->media->setTranscript($audio, $transcript);

        $contextLevel = $capabilities['context_level'] ?? 'full';
        $maxTokens = $this->maxTokensFrom($capabilities);

        $userMsg = $this->store(
            $conversation, $member, $user, IronAiMessage::ROLE_USER, $transcript,
            ['via' => 'audio', 'duration_seconds' => $audio->duration_seconds],
        );
        $this->media->attachToMessage($audio, $userMsg);

        $payload = $this->buildTextPayload($conversation, $member, $user, $contextLevel);
        $ai = $this->callOpenAi($payload, $member, $user, $maxTokens);

        $result = $this->finalizeReply($conversation, $member, $user, $transcript, $userMsg, $ai);
        $result['transcript'] = $transcript;
        $result['transcription_failed'] = false;
        $result['duration_seconds'] = $audio->duration_seconds;

        return $result;
    }

    /**
     * Análisis de IMAGEN (visión). La imagen se envía inline (base64) con un
     * prompt de seguridad reforzado. La imagen queda como adjunto del mensaje
     * del usuario.
     */
    public function imageChat(
        IronAiConversation $conversation,
        ?Member $member,
        ?User $user,
        IronAiMessageAttachment $image,
        ?string $message,
        array $capabilities = [],
    ): array {
        $contextLevel = $capabilities['context_level'] ?? 'full';
        $maxTokens = $this->maxTokensFrom($capabilities);
        $userText = trim((string) $message);
        $promptText = $userText !== '' ? $userText : 'Analiza esta imagen relacionada con mi entrenamiento.';

        // Historial PREVIO (sin el turno actual) → se añade como texto.
        $history = $this->recentHistory($conversation);

        // Persiste el mensaje del usuario (texto) y asocia la imagen.
        $userMsg = $this->store(
            $conversation, $member, $user, IronAiMessage::ROLE_USER,
            $userText !== '' ? $userText : '[Imagen adjunta]',
            ['via' => 'image'],
        );
        $this->media->attachToMessage($image, $userMsg);

        $dataUrl = $this->media->imageDataUrl($image);
        if ($dataUrl === null) {
            return $this->finalizeReply($conversation, $member, $user, $promptText, $userMsg, null, self::IMAGE_ERROR);
        }

        $context = $this->buildUserContext($member, $user, $contextLevel);
        $payload = [['role' => 'system', 'content' => self::SYSTEM_PROMPT . self::IMAGE_SAFETY_PROMPT]];
        $payload = array_merge($payload, $this->gymEquipmentMessages());
        if ($context !== '') {
            $payload[] = ['role' => 'system', 'content' => "CONTEXTO DEL USUARIO (datos reales; no inventes lo que no esté aquí):\n" . $context];
        }
        foreach ($history as $h) {
            $payload[] = ['role' => $h->role, 'content' => $h->content];
        }
        $payload[] = [
            'role'    => 'user',
            'content' => [
                ['type' => 'text', 'text' => $promptText],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
            ],
        ];

        $ai = $this->vision->complete($payload, $maxTokens);

        return $this->finalizeReply($conversation, $member, $user, $promptText, $userMsg, $ai, self::IMAGE_ERROR);
    }

    /** Resuelve max_output_tokens desde las capacidades (null = default config). */
    private function maxTokensFrom(array $capabilities): ?int
    {
        return isset($capabilities['max_output_tokens'])
            ? (int) $capabilities['max_output_tokens']
            : null;
    }

    /** Construye el payload de texto (system + contexto + historial). */
    private function buildTextPayload(IronAiConversation $conversation, ?Member $member, ?User $user, string $contextLevel): array
    {
        $context = $this->buildUserContext($member, $user, $contextLevel);
        $history = $this->recentHistory($conversation);

        $systemPrompt = self::SYSTEM_PROMPT;
        if ($contextLevel === 'basic') {
            $systemPrompt .= "\n\nResponde de forma breve y directa (máx. ~5 líneas). No hagas análisis profundos.";
        }

        $payload = [['role' => 'system', 'content' => $systemPrompt]];
        $payload = array_merge($payload, $this->gymEquipmentMessages());
        if ($context !== '') {
            $payload[] = ['role' => 'system', 'content' => "CONTEXTO DEL USUARIO (datos reales; no inventes lo que no esté aquí):\n" . $context];
        }
        foreach ($history as $h) {
            $payload[] = ['role' => $h->role, 'content' => $h->content];
        }

        return $payload;
    }

    /**
     * Persiste la respuesta del asistente (o fallback) y arma el resultado común
     * a chat/audio/imagen. $ai null → fallback amable (no rompe la UI).
     */
    private function finalizeReply(
        IronAiConversation $conversation,
        ?Member $member,
        ?User $user,
        string $userMessageForMeta,
        IronAiMessage $userMsg,
        ?array $ai,
        ?string $fallbackReply = null,
    ): array {
        if ($ai === null || ($ai['content'] ?? '') === '') {
            // El mensaje del usuario sí queda → actualizamos meta de la conversación.
            $this->updateConversationMeta($conversation, $userMessageForMeta, null);

            return [
                'reply'             => $fallbackReply ?? self::FRIENDLY_ERROR,
                'conversation_id'   => $conversation->uuid,
                'conversation_uuid' => $conversation->uuid,
                'suggestions'       => self::DEFAULT_SUGGESTIONS,
                'is_fallback'       => true,
                'message_id'        => $userMsg->id,
                'model'             => config('services.openai.model'),
                'input_tokens'      => null,
                'output_tokens'     => null,
            ];
        }

        $assistantMsg = $this->store($conversation, $member, $user, IronAiMessage::ROLE_ASSISTANT, $ai['content']);
        $this->updateConversationMeta($conversation, $userMessageForMeta, $ai['content']);

        return [
            'reply'             => $ai['content'],
            'conversation_id'   => $conversation->uuid,
            'conversation_uuid' => $conversation->uuid,
            'suggestions'       => self::DEFAULT_SUGGESTIONS,
            'is_fallback'       => false,
            'message_id'        => $assistantMsg->id,
            'model'             => $ai['model'] ?? config('services.openai.model'),
            'input_tokens'      => $ai['input_tokens'] ?? null,
            'output_tokens'     => $ai['output_tokens'] ?? null,
        ];
    }

    /** Últimos mensajes de la conversación para la ventana de contexto. */
    private function recentHistory(IronAiConversation $conversation)
    {
        $limit = (int) config('services.openai.max_context_messages', 12);

        return $conversation->messages()
            ->whereIn('role', [IronAiMessage::ROLE_USER, IronAiMessage::ROLE_ASSISTANT])
            ->orderByDesc('id')
            ->limit(max(2, $limit))
            ->get()
            ->reverse()
            ->values();
    }

    private function store(IronAiConversation $conversation, ?Member $member, ?User $user, string $role, string $content, array $metadata = []): IronAiMessage
    {
        return IronAiMessage::create([
            'user_id'                 => $user?->id,
            'member_id'               => $member?->id,
            'iron_ai_conversation_id' => $conversation->id,
            'conversation_uuid'       => $conversation->uuid,
            'conversation_id'         => $conversation->uuid, // legacy string = uuid
            'role'                    => $role,
            'content'                 => $content,
            'metadata'                => $metadata !== [] ? $metadata : null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Conversaciones (centro de chats por usuario)
    // ─────────────────────────────────────────────────────────────────────────

    /** Atributos de propietario para crear/filtrar conversaciones. */
    private function ownerAttributes(array $ctx): array
    {
        return [
            'user_id'   => $ctx['user']?->id,
            'member_id' => $ctx['member']?->id,
            'document'  => $ctx['identity_key'] ?? $ctx['document'] ?? null,
        ];
    }

    /** Query base de conversaciones del propietario (no borradas). */
    private function ownerScope(array $ctx)
    {
        return IronAiConversation::query()->where(function ($w) use ($ctx) {
            $any = false;
            if ($ctx['member'] ?? null) {
                $w->orWhere('member_id', $ctx['member']->id);
                $any = true;
            }
            if ($ctx['user'] ?? null) {
                $w->orWhere('user_id', $ctx['user']->id);
                $any = true;
            }
            $key = $ctx['identity_key'] ?? $ctx['document'] ?? null;
            if (! empty($key)) {
                $w->orWhere('document', $key);
                $any = true;
            }
            if (! $any) {
                $w->whereRaw('1 = 0');
            }
        });
    }

    /** Conversaciones activas del usuario (más reciente primero). */
    public function listConversations(array $ctx): array
    {
        return $this->ownerScope($ctx)
            ->where('status', IronAiConversation::STATUS_ACTIVE)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (IronAiConversation $c) => $c->toPublicArray())
            ->all();
    }

    /** Crea una conversación nueva para el usuario. No consume OpenAI. */
    public function createConversation(array $ctx, ?string $title = null, ?string $topic = null): IronAiConversation
    {
        $topic = $topic ?: 'general';
        $title = $title && trim($title) !== '' ? trim($title) : $this->titleForTopic($topic);

        $conversation = IronAiConversation::create(array_merge($this->ownerAttributes($ctx), [
            'uuid'   => (string) Str::uuid(),
            'title'  => $title,
            'topic'  => $topic,
            'status' => IronAiConversation::STATUS_ACTIVE,
        ]));

        Log::info('iron-ai conversation_created', [
            'conversation_id' => $conversation->id,
            'user_id'         => $ctx['user']?->id,
            'member_id'       => $ctx['member']?->id,
        ]);

        return $conversation;
    }

    /**
     * Devuelve la conversación SOLO si pertenece al usuario (aislamiento).
     * Excluye eliminadas (soft delete). null = no existe o es ajena.
     */
    public function findOwnedConversation(array $ctx, string $uuid): ?IronAiConversation
    {
        return $this->ownerScope($ctx)->where('uuid', $uuid)->first();
    }

    /**
     * Resuelve la conversación para el chat:
     *  - con uuid → debe ser del usuario (si no, null = 403).
     *  - sin uuid → crea una nueva (título/tema inferido del primer mensaje).
     */
    public function resolveConversationForChat(array $ctx, ?string $uuid, string $firstMessage): ?IronAiConversation
    {
        if ($uuid) {
            return $this->findOwnedConversation($ctx, $uuid);
        }

        [$topic, $title] = $this->classify($firstMessage);

        return $this->createConversation($ctx, $title, $topic);
    }

    /** Mensajes reales de una conversación (orden cronológico, con adjuntos). */
    public function conversationMessages(IronAiConversation $conversation): array
    {
        return $conversation->messages()
            ->whereIn('role', [IronAiMessage::ROLE_USER, IronAiMessage::ROLE_ASSISTANT])
            ->with('attachments')
            ->orderBy('id')
            ->get()
            ->map(fn (IronAiMessage $m) => [
                'role'        => $m->role,
                'content'     => $m->content,
                'created_at'  => optional($m->created_at)->toIso8601String(),
                'attachments' => $m->attachments
                    ->map(fn (IronAiMessageAttachment $a) => $a->toPublicArray())
                    ->all(),
            ])
            ->all();
    }

    /**
     * Persiste los turnos de una conversación de voz EN VIVO (realtime). No
     * llama a OpenAI ni consume cuota de chat: solo guarda la transcripción
     * (la sesión realtime ya se cobró al crearse). $turns: [{role, content}].
     */
    public function appendRealtimeTurns(IronAiConversation $conversation, ?Member $member, ?User $user, array $turns): int
    {
        $count = 0;
        $lastUser = '';
        $lastAssistant = null;

        foreach ($turns as $t) {
            if (! is_array($t)) {
                continue;
            }
            $role = ($t['role'] ?? '') === 'assistant'
                ? IronAiMessage::ROLE_ASSISTANT
                : IronAiMessage::ROLE_USER;
            $content = trim((string) ($t['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $this->store($conversation, $member, $user, $role, $content, ['via' => 'realtime']);
            if ($role === IronAiMessage::ROLE_USER) {
                $lastUser = $content;
            } else {
                $lastAssistant = $content;
            }
            $count++;
        }

        if ($count > 0) {
            $this->updateConversationMeta($conversation, $lastUser !== '' ? $lastUser : 'Conversación en vivo', $lastAssistant);
        }

        return $count;
    }

    public function archiveConversation(IronAiConversation $conversation): void
    {
        $conversation->update(['status' => IronAiConversation::STATUS_ARCHIVED]);
        Log::info('iron-ai conversation_archived', ['conversation_id' => $conversation->id]);
    }

    /** Soft delete + status=deleted (conserva trazabilidad; desaparece para el usuario). */
    public function deleteConversation(IronAiConversation $conversation): void
    {
        $conversation->update(['status' => IronAiConversation::STATUS_DELETED]);
        $conversation->delete(); // soft delete
        Log::info('iron-ai conversation_deleted', ['conversation_id' => $conversation->id]);
    }

    /** Limpia los mensajes pero conserva la conversación vacía. */
    public function clearConversation(IronAiConversation $conversation): void
    {
        $conversation->messages()->delete();
        $conversation->update([
            'messages_count'       => 0,
            'last_message_preview' => null,
            'summary'              => null,
            'last_message_at'      => null,
        ]);
        Log::info('iron-ai conversation_cleared', ['conversation_id' => $conversation->id]);
    }

    /** Actualiza título (si era genérico), tema, resumen, preview, conteo y fecha. */
    private function updateConversationMeta(IronAiConversation $conversation, string $userMessage, ?string $assistantReply): void
    {
        $count = $conversation->messages()->count();
        $preview = $assistantReply ?: $userMessage;

        $attrs = [
            'messages_count'       => $count,
            'last_message_preview' => mb_substr(trim($preview), 0, 200),
            'last_message_at'      => Carbon::now(),
        ];

        // Si el título sigue genérico, derívalo del primer mensaje del usuario.
        [$topic, $title] = $this->classify($userMessage);
        if (in_array($conversation->title, ['Consulta con IRON IA', 'Nuevo chat', ''], true) || $conversation->title === null) {
            $attrs['title'] = $title;
            $attrs['topic'] = $conversation->topic ?: $topic;
        }
        if (empty($conversation->summary)) {
            $attrs['summary'] = mb_substr(trim($userMessage), 0, 200);
        }

        $conversation->update($attrs);
    }

    /**
     * Clasifica un mensaje en [topic, title] por reglas simples (sin OpenAI).
     *
     * @return array{0:string,1:string}
     */
    public function classify(string $text): array
    {
        $t = $this->normalizeText($text);

        $rules = [
            ['progress',   'Análisis de progreso',   ['progreso', 'avance', 'resultado', 'estadistic']],
            ['nutrition',  'Nutrición general',      ['comida', 'caloria', 'proteina', 'nutricion', 'dieta', 'alimenta']],
            ['care',       'Cuidado y prevención',   ['dolor', 'lesion', 'molestia', 'lastim', 'fisio']],
            ['membership', 'Membresía y acceso',     ['pago', 'membresia', 'plan', 'suscrip', 'renov']],
            ['technique',  'Técnica de ejercicio',   ['tecnica', 'como hago', 'como se hace', 'postura', 'press', 'sentadilla', 'forma correcta']],
            ['routine',    'Rutina y entrenamiento', ['rutina', 'entrenar', 'entrenamiento', 'ejercicio', 'workout']],
        ];

        foreach ($rules as [$topic, $title, $keywords]) {
            foreach ($keywords as $kw) {
                if (str_contains($t, $kw)) {
                    return [$topic, $title];
                }
            }
        }

        return ['general', 'Consulta con IRON IA'];
    }

    /** Título por defecto a partir de un topic (para crear conversación). */
    public function titleForTopic(?string $topic): string
    {
        return match ($topic) {
            'routine'    => 'Rutina y entrenamiento',
            'progress'   => 'Análisis de progreso',
            'technique'  => 'Técnica de ejercicio',
            'nutrition'  => 'Nutrición general',
            'care'       => 'Cuidado y prevención',
            'membership' => 'Membresía y acceso',
            'motivation' => 'Motivación',
            default      => 'Consulta con IRON IA',
        };
    }

    private function normalizeText(string $s): string
    {
        $s = mb_strtolower(trim($s));

        return str_replace(['á','é','í','ó','ú','ü','ñ'], ['a','e','i','o','u','u','n'], $s);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Contexto del usuario (solo datos reales y disponibles)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Arma el contexto del usuario según el nivel permitido por su membresía:
     *   - basic        → nombre y objetivo (mínimo).
     *   - personalized → + edad, nivel, lesiones, membresía y rutinas recientes.
     *   - full         → + clases, pagos y todo lo disponible.
     */
    public function buildUserContext(?Member $member, ?User $user, string $level = 'full'): string
    {
        if (! $member && ! $user) {
            return '';
        }

        $lines = [];

        $name = $member->full_name ?? $user->name ?? null;
        if ($name) {
            $lines[] = "Nombre: {$name}";
        }

        if ($member?->goal) {
            $lines[] = "Objetivo fitness: {$member->goal}";
        }

        // basic → solo nombre + objetivo.
        if ($level === 'basic') {
            return implode("\n", array_filter($lines));
        }

        if ($member?->birth_date) {
            try {
                $age = Carbon::parse($member->birth_date)->age;
                if ($age > 0 && $age < 120) {
                    $lines[] = "Edad: {$age} años";
                }
            } catch (Throwable) {
                // fecha inválida → se omite
            }
        }

        if ($member?->gender) {
            $lines[] = "Género: {$member->gender}";
        }
        if ($member?->training_level) {
            $lines[] = "Nivel de entrenamiento: {$member->training_level}";
        }
        if ($member?->injuries) {
            $lines[] = "Restricciones/lesiones conocidas: {$member->injuries}";
        }

        // Membresía / plan (del User vinculado).
        $lines = array_merge($lines, $this->membershipLines($user));

        // Rutinas (asignadas + propias).
        if ($member) {
            $lines = array_merge($lines, $this->routineLines($member));
        }

        // full → además clases y pagos.
        if ($level === 'full' && $member) {
            $lines = array_merge($lines, $this->classLines($member));
            $lines = array_merge($lines, $this->paymentLines($member, $user));
        }

        return implode("\n", array_filter($lines));
    }

    /** @return array<int, string> */
    private function membershipLines(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $lines = [];

        if ($user->plan) {
            $lines[] = "Plan/membresía: {$user->plan}";
        }
        if ($user->status) {
            $lines[] = "Estado de la cuenta: {$user->status}";
        }

        if ($user->membershipEndDate) {
            try {
                $end = Carbon::parse($user->membershipEndDate)->endOfDay();
                $days = Carbon::now()->startOfDay()->diffInDays($end->startOfDay(), false);
                if ($days < 0) {
                    $lines[] = "Membresía: VENCIDA (venció el {$user->membershipEndDate})";
                } else {
                    $lines[] = "Membresía vence: {$user->membershipEndDate} (en {$days} día(s))";
                }
            } catch (Throwable) {
                $lines[] = "Membresía vence: {$user->membershipEndDate}";
            }
        }

        // Beneficios del plan (si existe en catálogo).
        if ($user->plan) {
            $plan = Plan::where('name', $user->plan)->first();
            if ($plan) {
                $benefits = array_slice($plan->benefitsArray(), 0, 5);
                if (! empty($benefits)) {
                    $lines[] = 'Beneficios del plan: ' . implode('; ', $benefits);
                }
            }
        }

        return $lines;
    }

    /** @return array<int, string> */
    private function routineLines(Member $member): array
    {
        $assigned = Routine::where(function ($q) use ($member) {
            $q->whereHas('assignments', fn ($a) => $a->where('member_id', $member->id))
                ->orWhere(fn ($w) => $w->where('member_id', $member->id)->where('is_assigned', true));
        })
            ->with('routineExercises.exercise')
            ->latest('id')
            ->limit(5)
            ->get();

        $custom = Routine::where('member_id', $member->id)
            ->where('is_assigned', false)
            ->with('routineExercises.exercise')
            ->latest('id')
            ->limit(5)
            ->get();

        $all = $assigned->merge($custom)->unique('id')->values();

        if ($all->isEmpty()) {
            return [];
        }

        $lines = [];
        $names = $all->map(function (Routine $r) {
            $bits = array_filter([
                $r->name,
                $r->objective ? "obj: {$r->objective}" : null,
                $r->level ? "nivel: {$r->level}" : null,
            ]);

            return implode(' — ', $bits);
        })->all();
        $lines[] = 'Rutinas disponibles: ' . implode(' | ', $names);

        // Ejercicios recientes presentes en esas rutinas.
        $exercises = $all
            ->flatMap(fn (Routine $r) => $r->routineExercises)
            ->map(fn ($re) => $re->exercise?->local_name ?: $re->exercise?->name)
            ->filter()
            ->unique()
            ->take(8)
            ->values()
            ->all();

        if (! empty($exercises)) {
            $lines[] = 'Ejercicios recientes en sus rutinas: ' . implode(', ', $exercises);
        }

        return $lines;
    }

    /** @return array<int, string> */
    private function classLines(Member $member): array
    {
        $reservations = \App\Models\ClassReservation::with('gymClass')
            ->where('member_id', $member->id)
            ->latest('id')
            ->limit(5)
            ->get();

        $upcoming = $reservations
            ->map(fn ($r) => $r->gymClass)
            ->filter()
            ->map(function ($c) {
                $when = $c->date_time ? Carbon::parse($c->date_time)->format('Y-m-d H:i') : ($c->day_of_week ?? null);
                return trim($c->name . ($when ? " ({$when})" : ''));
            })
            ->filter()
            ->take(3)
            ->values()
            ->all();

        if (empty($upcoming)) {
            return [];
        }

        return ['Clases reservadas/próximas: ' . implode(' | ', $upcoming)];
    }

    /** @return array<int, string> */
    private function paymentLines(?Member $member, ?User $user): array
    {
        $query = Payment::query()->latest('id');

        if ($member && $user) {
            $query->where(fn ($q) => $q->where('member_id', $member->id)->orWhere('user_id', $user->id));
        } elseif ($member) {
            $query->where('member_id', $member->id);
        } elseif ($user) {
            $query->where('user_id', $user->id);
        } else {
            return [];
        }

        $payment = $query->first();
        if (! $payment) {
            return [];
        }

        $bits = array_filter([
            $payment->status ? "estado: {$payment->status}" : null,
            $payment->amount ? 'monto: $' . number_format((float) $payment->amount, 0, ',', '.') : null,
            $payment->paid_at ? 'fecha: ' . Carbon::parse($payment->paid_at)->format('Y-m-d') : null,
        ]);

        return empty($bits) ? [] : ['Último pago registrado: ' . implode(', ', $bits)];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OpenAI
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Llama a OpenAI (chat completions). Devuelve un arreglo con el contenido y
     * el uso de tokens, o null si falla. Nunca lanza: cualquier error → null
     * (Flutter ve FRIENDLY_ERROR). $maxTokensOverride viene de la capacidad de
     * la membresía (controla el costo).
     *
     * @return array{content: string, model: ?string, input_tokens: ?int, output_tokens: ?int}|null
     */
    private function callOpenAi(array $messages, ?Member $member, ?User $user, ?int $maxTokensOverride = null): ?array
    {
        $cfg = config('services.openai');
        $started = microtime(true);

        if (empty($cfg['enabled'])) {
            Log::warning('iron-ai deshabilitado por configuración');
            return null;
        }
        if (empty($cfg['api_key'])) {
            Log::error('iron-ai sin OPENAI_API_KEY configurada');
            return null;
        }

        $maxTokens = $maxTokensOverride !== null && $maxTokensOverride > 0
            ? $maxTokensOverride
            : (int) ($cfg['max_tokens'] ?? 600);

        try {
            $response = Http::withToken($cfg['api_key'])
                ->timeout((int) ($cfg['timeout'] ?? 30))
                ->acceptJson()
                ->asJson()
                ->post(rtrim($cfg['base_url'], '/') . '/v1/chat/completions', [
                    'model'       => $cfg['model'] ?? 'gpt-4.1-mini',
                    'messages'    => $messages,
                    'temperature' => (float) ($cfg['temperature'] ?? 0.4),
                    'max_tokens'  => $maxTokens,
                ]);

            $latencyMs = (int) round((microtime(true) - $started) * 1000);

            if ($response->failed()) {
                // No registramos el cuerpo crudo (puede traer datos sensibles).
                Log::error('iron-ai openai http error', [
                    'user_id'     => $user?->id,
                    'member_id'   => $member?->id,
                    'status'      => $response->status(),
                    'latency_ms'  => $latencyMs,
                    'error_class' => 'OpenAIHttpError',
                ]);
                return null;
            }

            $json = $response->json();
            $content = data_get($json, 'choices.0.message.content');
            $content = is_string($content) ? trim($content) : '';

            if ($content === '') {
                Log::error('iron-ai openai respuesta vacía', [
                    'user_id'    => $user?->id,
                    'member_id'  => $member?->id,
                    'status'     => $response->status(),
                    'latency_ms' => $latencyMs,
                ]);
                return null;
            }

            Log::info('iron-ai chat ok', [
                'user_id'    => $user?->id,
                'member_id'  => $member?->id,
                'endpoint'   => 'chat',
                'status'     => $response->status(),
                'latency_ms' => $latencyMs,
                'model'      => $cfg['model'] ?? null,
            ]);

            return [
                'content'       => $content,
                'model'         => data_get($json, 'model', $cfg['model'] ?? null),
                'input_tokens'  => data_get($json, 'usage.prompt_tokens'),
                'output_tokens' => data_get($json, 'usage.completion_tokens'),
            ];
        } catch (Throwable $e) {
            Log::error('iron-ai openai exception', [
                'user_id'     => $user?->id,
                'member_id'   => $member?->id,
                'endpoint'    => 'chat',
                'latency_ms'  => (int) round((microtime(true) - $started) * 1000),
                'error_class' => get_class($e),
            ]);
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Recomendaciones / notificaciones inteligentes (base inicial, sin push)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Genera (idempotentemente) recomendaciones basadas en reglas y devuelve
     * la lista vigente del usuario. Sin contexto identificable → lista vacía.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recommendations(?Member $member, ?User $user): array
    {
        if (! $member && ! $user) {
            return [];
        }

        // Tipos de coaching propios de IRON IA (progreso, constancia, hábitos,
        // sugerencias personalizadas). Solo estos generan notificación: el
        // vencimiento de membresía lo maneja el comando y las clases su hook,
        // así cada módulo notifica lo suyo y no se duplica al usuario.
        $coachingTypes = ['motivation', 'progress'];

        foreach ($this->computeRecommendations($member, $user) as $rec) {
            $record = IronAiRecommendation::updateOrCreate(
                [
                    'member_id' => $member?->id,
                    'user_id'   => $user?->id,
                    'type'      => $rec['type'],
                    'status'    => IronAiRecommendation::STATUS_PENDING,
                ],
                [
                    'title'   => $rec['title'],
                    'message' => $rec['message'],
                ],
            );

            // Notifica SOLO recomendaciones de coaching y SOLO a miembros
            // (targeted). Idempotente por event_key iron_ai_{memberId}_{recId}:
            // aunque /recommendations se llame muchas veces, la notificación se
            // crea una sola vez por recomendación (no por cada mensaje de chat).
            if ($member && in_array($rec['type'], $coachingTypes, true)) {
                app(NotificationService::class)->notifyIronAiRecommendation($member, $record);
            }
        }

        return IronAiRecommendation::query()
            ->when($member, fn ($q) => $q->where('member_id', $member->id))
            ->when(! $member && $user, fn ($q) => $q->where('user_id', $user->id))
            ->whereIn('status', [IronAiRecommendation::STATUS_PENDING, IronAiRecommendation::STATUS_SENT])
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (IronAiRecommendation $r) => $r->toPublicArray())
            ->all();
    }

    /**
     * Reglas de recomendación (sin inventar datos).
     *
     * @return array<int, array{type: string, title: string, message: string}>
     */
    private function computeRecommendations(?Member $member, ?User $user): array
    {
        $recs = [];
        $name = $member->full_name ?? $user?->name ?? null;
        $firstName = $name ? explode(' ', trim($name))[0] : null;

        // 1) Membresía próxima a vencer / vencida.
        if ($user?->membershipEndDate) {
            try {
                $end = Carbon::parse($user->membershipEndDate)->endOfDay();
                $days = Carbon::now()->startOfDay()->diffInDays($end->startOfDay(), false);
                if ($days < 0) {
                    $recs[] = [
                        'type'    => 'membership',
                        'title'   => 'Tu membresía está vencida',
                        'message' => 'Renueva tu membresía para no perder el acceso al gimnasio y a tus rutinas. Puedes hacerlo desde la sección de Membresías.',
                    ];
                } elseif ($days <= 7) {
                    $recs[] = [
                        'type'    => 'reminder',
                        'title'   => 'Tu membresía vence pronto',
                        'message' => "Tu membresía vence en {$days} día(s). Renuévala desde Membresías para seguir entrenando sin interrupciones.",
                    ];
                }
            } catch (Throwable) {
                // se omite
            }
        }

        // 2) Clases próximas (recordatorio).
        if ($member) {
            $next = \App\Models\ClassReservation::with('gymClass')
                ->where('member_id', $member->id)
                ->latest('id')
                ->first();
            if ($next && $next->gymClass) {
                $when = $next->gymClass->date_time
                    ? Carbon::parse($next->gymClass->date_time)->format('Y-m-d H:i')
                    : null;
                $recs[] = [
                    'type'    => 'class',
                    'title'   => 'Tienes una clase reservada',
                    'message' => 'Recuerda tu clase ' . $next->gymClass->name . ($when ? " ({$when})" : '') . '. ¡Te esperamos!',
                ];
            }
        }

        // 3) Motivacional / progreso (siempre que haya objetivo).
        $goal = $member?->goal;
        if ($goal) {
            $recs[] = [
                'type'    => 'motivation',
                'title'   => $firstName ? "Sigue avanzando, {$firstName}" : 'Sigue avanzando',
                'message' => "Tu objetivo es {$goal}. La constancia es la clave: prográmate tus entrenamientos de esta semana y revisa tu técnica con IRON IA.",
            ];
        } else {
            $recs[] = [
                'type'    => 'progress',
                'title'   => 'Define tu objetivo',
                'message' => 'Cuéntame tu objetivo (perder grasa, ganar músculo, fuerza, salud) y armemos juntos tu plan de entrenamiento.',
            ];
        }

        return $recs;
    }
}
