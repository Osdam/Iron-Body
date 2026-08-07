<?php

namespace App\Services\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Lectura de la bandeja: lista filtrable, detalle saneado y métricas. No envía
 * mensajes ni cambia el estado de la IA (eso vive en los servicios de acción).
 */
class MarketingInboxService
{
    /**
     * Catalogo de etiquetas ya leido en esta peticion.
     *
     * El servicio se resuelve una vez por peticion, asi que este valor vive lo
     * que vive la peticion: no hay riesgo de servir un catalogo obsoleto entre
     * peticiones distintas.
     *
     * @var array<string,array<string,mixed>>|null
     */
    private ?array $catalogMemo = null;

    /**
     * Minimo de caracteres para buscar dentro de los mensajes.
     *
     * Es el tamanio de un trigrama: por debajo, el indice no se puede usar y la
     * consulta degenera en un escaneo de la tabla entera.
     */
    private const MIN_MESSAGE_SEARCH_LENGTH = 3;

    /** Lista paginada de conversaciones con filtros del Inbox. */
    public function list(Request $request, ?int $viewerAdminId): LengthAwarePaginator
    {
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);

        $query = MarketingConversation::query()
            ->with([
                // OJO: lead_stage vive en marketing_conversations, NO en
                // marketing_leads. Pedirlo aquí rompía la consulta (SQL 42703)
                // y dejaba la lista vacía aunque las métricas sí contaran.
                'lead:id,name,phone,channel,status,temperature,objective,do_not_contact',
                'assignedAdmin:id,name',
                'tags:id,conversation_id,tag,assigned_kind',
            ])
            ->latest('last_message_at');

        $this->applyFilters($query, $request, $viewerAdminId);

        return $query->paginate($perPage);
    }

    private function applyFilters(Builder $query, Request $request, ?int $viewerAdminId): void
    {
        if ($channel = $request->query('channel')) {
            $query->where('channel', $channel);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // IA activa / pausada.
        $ai = $request->query('ai');
        if ($ai === 'active') {
            $query->where('ai_enabled', true);
        } elseif ($ai === 'paused') {
            $query->where('ai_enabled', false);
        }

        // staff_review.
        $sr = $request->query('staff_review');
        if ($sr === 'pending') {
            $query->where('staff_review_pending', true);
        } elseif ($sr === 'resolved') {
            $query->where('staff_review_pending', false)->whereNotNull('staff_review_resolved_at');
        }

        // No leídos.
        if ($request->boolean('unread')) {
            $query->where('unread_count', '>', 0);
        }

        // Asignación: mine | unassigned | {id}.
        $assigned = $request->query('assigned');
        if ($assigned === 'mine') {
            $query->where('assigned_to_admin_id', $viewerAdminId);
        } elseif ($assigned === 'unassigned') {
            $query->whereNull('assigned_to_admin_id');
        } elseif (is_numeric($assigned)) {
            $query->where('assigned_to_admin_id', (int) $assigned);
        }

        // Tag.
        if ($tag = $request->query('tag')) {
            $query->whereHas('tags', fn (Builder $q) => $q->where('tag', $tag));
        }

        $this->applySearch($query, trim((string) $request->query('q', '')));
    }

    /**
     * Búsqueda libre sobre nombre, teléfono y texto de los mensajes.
     *
     * Dos decisiones que no se ven y sostienen el rendimiento:
     *
     * · **Con menos de tres caracteres NO se busca en los mensajes.** El índice
     *   de trigramas necesita tres para poder usarse; por debajo, PostgreSQL
     *   recorre los cientos de miles de mensajes uno a uno. Se buscaba así
     *   antes y costaba ~190 ms por pulsación. Dos letras tampoco identifican
     *   a nadie, así que no se pierde nada útil.
     *
     * · **Un teléfono se busca por sus dígitos.** La gente escribe
     *   «315 053 60» o «+57 315…» y lo guardado es «3150536026»; comparar tal
     *   cual no encontraba nada y parecía que el buscador estaba roto.
     */
    private function applySearch(Builder $query, string $q): void
    {
        if ($q === '') {
            return;
        }

        $like = '%'.$this->escapeLike($q).'%';
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        $searchesMessages = mb_strlen($q) >= self::MIN_MESSAGE_SEARCH_LENGTH;

        /*
         * Una sola clausula, escrita a mano, y por dos motivos.
         *
         * El primero es el escape. `LIKE` necesita saber cual es el caracter de
         * escape: PostgreSQL asume la barra invertida y SQLite no asume
         * ninguna, asi que buscar un `%` literal -«50%»- encontraba cosas en
         * produccion y nada en las pruebas. Declararlo con `ESCAPE` lo iguala.
         *
         * El segundo es que hubo que aprenderlo por las malas: escrito con
         * `whereRaw` dentro de los closures de `whereHas`, Laravel pierde las
         * ataduras al montar la subconsulta `exists`. SQLite lo dejaba pasar y
         * PostgreSQL contestaba «Invalid parameter number», o sea que el fallo
         * solo existia en el motor de produccion. En una sola llamada al
         * constructor principal, las ataduras van donde tienen que ir.
         *
         * El `IN (SELECT DISTINCT ...)` tampoco es cosmetico. Como `EXISTS`
         * correlacionado, el planificador resolvia un semi join anidado -un
         * escaneo por conversacion, 5.004 de ellos- y no tocaba el indice de
         * trigramas ni una vez: 173 ms. Con DISTINCT lo recorre UNA vez: 44 ms,
         * y sin recortar ni un resultado.
         *
         * Todos los nombres de tabla y columna son constantes de este archivo.
         * Lo unico que viene de fuera es el patron, y va enlazado.
         */
        $conditions = [
            "EXISTS (
                SELECT 1 FROM marketing_leads l
                WHERE l.id = marketing_conversations.lead_id
                  AND (l.name LIKE ? ESCAPE '!' OR l.phone LIKE ? ESCAPE '!')
            )",
        ];
        $bindings = [$like, $like];

        // El telefono tal y como lo escribe la gente: «315 053 60», «+57 315…».
        // Guardado esta sin separadores, asi que se busca tambien por digitos.
        if (strlen($digits) >= 3) {
            $conditions[] = "EXISTS (
                SELECT 1 FROM marketing_leads l2
                WHERE l2.id = marketing_conversations.lead_id AND l2.phone LIKE ?
            )";
            $bindings[] = '%'.$digits.'%';
        }

        if ($searchesMessages) {
            $conditions[] = "marketing_conversations.id IN (
                SELECT DISTINCT conversation_id FROM marketing_messages
                WHERE body LIKE ? ESCAPE '!'
            )";
            $bindings[] = $like;
        }

        $query->whereRaw('('.implode(' OR ', $conditions).')', $bindings);
    }

    /**
     * Neutraliza los comodines de SQL para que se busquen como texto.
     *
     * El caracter de escape es `!` y NO la barra invertida, que seria lo
     * habitual. La barra rompia por un sitio inesperado: dentro de `ESCAPE
     * '\\'`, el analizador de PDO que cuenta los `?` toma la barra como si
     * escapara la comilla de cierre, cree que el literal sigue abierto, cuenta
     * mal los parametros y devuelve «Invalid parameter number» aunque el SQL y
     * las ataduras esten bien. Con `!` no hay ambiguedad en ningun motor.
     *
     * El propio `!` se escapa primero: si no, buscar «hola!» dejaria un escape
     * suelto al final del patron.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /** Tarjeta resumida para la lista. */
    public function presentListItem(MarketingConversation $c): array
    {
        // La previsualizacion viene YA en la conversacion. Antes se consultaba
        // el ultimo mensaje aqui, o sea una consulta POR FILA: veinte
        // conversaciones costaban veinte viajes a la base solo para enseniar
        // un trozo de texto. La mantiene un observador sobre la tabla de
        // mensajes, asi que no puede quedarse atrasada.
        $preview = $c->last_message_preview;

        return [
            'id' => $c->id,
            'lead_id' => $c->lead_id,
            'lead_name' => $c->lead?->name,
            'phone' => $c->lead?->phone,
            'channel' => $c->channel,
            'status' => $c->status,
            'ai_enabled' => (bool) $c->ai_enabled,
            'human_takeover' => (bool) $c->human_takeover,
            'human_takeover_source' => $c->human_takeover_source,
            'assigned_to' => $c->assignedAdmin ? ['id' => $c->assignedAdmin->id, 'name' => $c->assignedAdmin->name] : null,
            'unread_count' => (int) $c->unread_count,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'last_inbound_at' => $c->last_inbound_at?->toIso8601String(),
            'last_outbound_at' => $c->last_outbound_at?->toIso8601String(),
            'staff_review_pending' => (bool) $c->staff_review_pending,
            'staff_review_reason' => $c->staff_review_reason,
            'tags' => $c->tags->pluck('tag')->all(),
            // Como mucho DOS y de familias distintas: la lista sirve para
            // decidir a quien atender primero, no para enumerar atributos.
            // Se decora en memoria contra un catalogo cacheado; consultar el
            // catalogo por conversacion seria un N+1 en cuanto alguien
            // etiquetara de verdad.
            'tags_detailed' => $this->decorateTags($c->tags, limit: 2),
            'last_message_preview' => $preview !== null ? mb_strimwidth((string) $preview, 0, 120, '…') : null,
        ];
    }

    /** Detalle completo y saneado de una conversación (marca como leída). */
    public function detail(MarketingConversation $conversation): array
    {
        // Marcar como leída al abrir el detalle.
        if ((int) $conversation->unread_count !== 0 || $conversation->last_read_at === null) {
            $conversation->forceFill(['unread_count' => 0, 'last_read_at' => now()])->save();
        }

        $conversation->load([
            'lead',
            'assignedAdmin:id,name',
            'tags',
            'notes' => fn ($q) => $q->with('author:id,name')->latest('created_at'),
        ]);

        // Solo la ULTIMA pagina. Antes se cargaba la conversacion entera: con
        // cinco mil mensajes eso son varios megabytes de JSON y unos segundos
        // de espera para leer el ultimo, que es lo unico que se quiere ver al
        // abrir. El resto llega al subir, por cursor.
        $page = $this->messagePage($conversation, before: null);
        $messages = $page['items'];

        $aiActions = MarketingAiAction::where('conversation_id', $conversation->id)
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($a) => [
                'action_type' => $a->action_type,
                'status' => $a->status,
                'reason' => $a->reason,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->all();

        $lead = $conversation->lead;

        return [
            'conversation' => [
                'id' => $conversation->id,
                'channel' => $conversation->channel,
                'status' => $conversation->status,
                'ai_enabled' => (bool) $conversation->ai_enabled,
                'human_takeover' => (bool) $conversation->human_takeover,
                'human_takeover_source' => $conversation->human_takeover_source,
                'unread_count' => 0,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'staff_review' => [
                    'pending' => (bool) $conversation->staff_review_pending,
                    'reason' => $conversation->staff_review_reason,
                    'resolved_at' => $conversation->staff_review_resolved_at?->toIso8601String(),
                ],
                'assignment' => $conversation->assignedAdmin
                    ? ['id' => $conversation->assignedAdmin->id, 'name' => $conversation->assignedAdmin->name]
                    : null,
            ],
            'lead' => $lead ? [
                'id' => $lead->id,
                'name' => $lead->name,
                'phone' => $lead->phone,
                'channel' => $lead->channel,
                'status' => $lead->status,
                'temperature' => $lead->temperature,
                'lead_stage' => $conversation->lead_stage,
                'do_not_contact' => (bool) $lead->do_not_contact,
            ] : null,
            'messages' => $messages,
            'messages_page' => [
                'has_more' => $page['has_more'],
                'next_cursor' => $page['next_cursor'],
                'oldest_id' => $page['oldest_id'],
                'newest_id' => $page['newest_id'],
            ],
            'ai_actions' => $aiActions,
            'notes' => $conversation->notes->map(fn ($n) => [
                'id' => $n->id,
                'author' => $n->author?->name,
                'body' => $n->body,
                'created_at' => $n->created_at?->toIso8601String(),
            ])->all(),
            'tags' => $conversation->tags->pluck('tag')->all(),
            'tags_detailed' => $this->decorateTags($conversation->tags),
        ];
    }

    /**
     * Adjuntos de un mensaje para el inbox.
     *
     * NO se devuelve la ruta ni el disco: el frontend recibe un id y pide la
     * URL firmada cuando de verdad va a mostrar el archivo. Sí se devuelve el
     * estado, porque "esta nota de voz no se pudo descargar y por qué" es
     * información que quien atiende necesita ver.
     *
     * @return array<int, array<string,mixed>>
     */
    private function attachmentsFor(MarketingMessage $message): array
    {
        return $message->attachments->map(fn ($a) => [
            'id' => $a->id,
            'kind' => $a->kind,
            'status' => $a->status,
            'available' => $a->isServable(),
            'reason' => $a->failure_reason,
            'mime_type' => $a->detected_mime_type,
            'size_bytes' => $a->size_bytes,
            'filename' => $a->original_filename,
            'voice' => (bool) $a->voice,
            'width' => $a->width,
            'height' => $a->height,
            'duration_seconds' => $a->duration_seconds,
            'caption' => data_get($a->metadata, 'caption'),
            'transcript' => $a->transcript,
        ])->all();
    }

    /**
     * Motivo por el que Meta rechazó un envío, en términos que un asesor pueda
     * accionar. El código crudo se conserva para soporte técnico.
     *
     * @return array<string,mixed>|null
     */
    private function failureFor(MarketingMessage $message): ?array
    {
        $failure = data_get($message->metadata, 'failure');

        if (! is_array($failure) || $failure === []) {
            return null;
        }

        return [
            'code' => $failure['code'] ?? null,
            'title' => $failure['title'] ?? null,
            'message' => $failure['message'] ?? null,
            'hint' => $this->failureHint($failure['code'] ?? null),
        ];
    }

    /**
     * Traducción de los códigos de Meta que de verdad aparecen en el día a día
     * de un gimnasio. Un código sin traducir devuelve null en vez de inventar.
     */
    private function failureHint(mixed $code): ?string
    {
        return match ((int) $code) {
            131047 => 'Pasaron más de 24 horas desde el último mensaje del cliente. Para retomar hay que usar una plantilla aprobada.',
            131026 => 'El número no tiene WhatsApp o no puede recibir mensajes.',
            131051 => 'Ese tipo de mensaje no se puede enviar por este canal.',
            131000, 131016 => 'Error temporal de Meta. Se puede reintentar.',
            132000, 132001, 132005, 132007, 132012 => 'La plantilla no coincide con lo aprobado o no existe en este idioma.',
            130429, 131048 => 'Se superó el límite de envíos de Meta. Hay que esperar antes de reintentar.',
            131031 => 'La cuenta de WhatsApp está restringida por Meta.',
            default => null,
        };
    }

    /** Métricas básicas de operación de la bandeja. */
    public function metrics(?int $viewerAdminId = null): array
    {
        $base = fn () => MarketingConversation::query();

        $open = (clone $base())->where('status', 'open')->count();
        $unassigned = (clone $base())->whereNull('assigned_to_admin_id')->where('status', '!=', 'closed')->count();
        $unreadTotal = (int) (clone $base())->sum('unread_count');
        $staffReviewPending = (clone $base())->where('staff_review_pending', true)->count();
        $handledByHuman = (clone $base())->where('human_takeover', true)->count();
        $handledByAi = (clone $base())->where('ai_enabled', true)->count();

        // Tiempo medio de primera respuesta (segundos) sobre conversaciones que
        // ya tuvieron primera respuesta.
        $ttfrRows = (clone $base())
            ->whereNotNull('first_response_at')
            ->get(['created_at', 'first_response_at']);
        $ttfr = null;
        if ($ttfrRows->isNotEmpty()) {
            $sum = 0;
            foreach ($ttfrRows as $row) {
                $sum += max(0, $row->first_response_at->getTimestamp() - $row->created_at->getTimestamp());
            }
            $ttfr = (int) round($sum / $ttfrRows->count());
        }

        $byStatus = (clone $base())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        return [
            'open_conversations' => $open,
            'unassigned' => $unassigned,
            'unread_total' => $unreadTotal,
            'staff_review_pending' => $staffReviewPending,
            'handled_by_ai' => $handledByAi,
            'handled_by_human' => $handledByHuman,
            'first_response_time_avg_seconds' => $ttfr,
            'conversations_by_status' => $byStatus,
        ];
    }

    /**
     * El catalogo de etiquetas, leido UNA vez.
     *
     * Son menos de veinte filas que cambian casi nunca, y la alternativa es
     * consultarlas por cada conversacion de la lista. Se cachea corto para que
     * crear una etiqueta nueva se vea enseguida sin tener que invalidar nada a
     * mano.
     *
     * @return array<string,array<string,mixed>>
     */
    private function tagCatalog(): array
    {
        // Memorizado ADEMAS de cacheado. El cache evita la consulta, pero no
        // el viaje al almacen: con el driver de base de datos, decorar veinte
        // filas hacia veinte lecturas de la tabla `cache`. Dentro de una misma
        // peticion el catalogo no puede cambiar, asi que se lee una vez.
        if ($this->catalogMemo !== null) {
            return $this->catalogMemo;
        }

        return $this->catalogMemo = \Illuminate\Support\Facades\Cache::remember(
            'marketing:tag-catalog',
            now()->addMinutes(5),
            fn () => \App\Models\MarketingTag::query()
                ->get(['slug', 'name', 'description', 'category', 'kind', 'color', 'locked'])
                ->keyBy('slug')
                ->map(fn ($t) => [
                    'name' => $t->name,
                    'description' => $t->description,
                    'category' => $t->category,
                    'kind' => $t->kind,
                    'color' => $t->color,
                    'editable' => $t->isManuallyEditable(),
                    'priority' => $t->listPriority(),
                ])
                ->all(),
        );
    }

    /**
     * Enriquece las asignaciones con el catalogo, en memoria.
     *
     * Con `limit` devuelve una por FAMILIA: dos etiquetas operativas informan
     * menos que una operativa y una de origen.
     *
     * @return array<int,array<string,mixed>>
     */
    private function decorateTags(\Illuminate\Support\Collection $assignments, ?int $limit = null): array
    {
        if ($assignments->isEmpty()) {
            return [];
        }

        $catalog = $this->tagCatalog();

        $decorated = $assignments->map(function ($assignment) use ($catalog) {
            $slug = $assignment->tag;
            // Una etiqueta que alguien invento y aun no esta en el catalogo se
            // muestra igual: no desaparece por no estar registrada.
            $meta = $catalog[$slug] ?? [
                'name' => $slug, 'description' => null, 'category' => 'commercial',
                'kind' => 'manual', 'color' => 'neutral', 'editable' => true, 'priority' => 30,
            ];

            return [
                'slug' => $slug,
                'kind' => $assignment->assigned_kind ?? $meta['kind'],
                ...$meta,
            ];
        })->sortBy('priority')->values();

        if ($limit === null) {
            return $decorated->all();
        }

        $seen = [];
        $chosen = [];

        foreach ($decorated as $tag) {
            if (isset($seen[$tag['category']])) {
                continue;
            }
            $seen[$tag['category']] = true;
            $chosen[] = $tag;

            if (count($chosen) === $limit) {
                break;
            }
        }

        return $chosen;
    }

    /** Cuantos mensajes trae una pagina. Configurable sin tocar codigo. */
    private function pageSize(): int
    {
        return max(10, min(200, (int) config('marketing.inbox.message_page_size', 40)));
    }

    /**
     * Una pagina del historial, de la mas reciente hacia atras.
     *
     * El cursor lleva `created_at` Y `id`. Solo con la fecha, dos mensajes del
     * mismo lote -y WhatsApp los entrega en lotes- quedan sin orden definido
     * entre si; con paginacion eso hace que uno aparezca dos veces o no
     * aparezca nunca. El identificador desempata y el orden pasa a ser estable
     * entre peticiones.
     *
     * Se ordena por fecha y no por identificador porque los mensajes pueden
     * llegar fuera de orden: uno recibido tarde pertenece cronologicamente
     * donde le toca, no al final.
     *
     * @param  string|null  $before  cursor opaco devuelto por la llamada anterior
     * @return array{items:array<int,array<string,mixed>>, has_more:bool, next_cursor:?string, oldest_id:?int, newest_id:?int}
     */
    public function messagePage(MarketingConversation $conversation, ?string $before = null, ?int $limit = null): array
    {
        $size = $limit !== null ? max(1, min(200, $limit)) : $this->pageSize();

        $query = MarketingMessage::query()
            ->where('conversation_id', $conversation->id)
            ->with('attachments')
            // Descendente para tomar las MAS RECIENTES; se invierte despues.
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $cursor = $this->decodeCursor($before);

        if ($cursor !== null) {
            [$at, $id] = $cursor;

            // Estrictamente anterior en (fecha, id): sin el desempate por id se
            // repetirian los mensajes que comparten marca de tiempo con el
            // ultimo de la pagina previa.
            $query->where(function ($q) use ($at, $id): void {
                $q->where('created_at', '<', $at)
                    ->orWhere(function ($q2) use ($at, $id): void {
                        $q2->where('created_at', '=', $at)->where('id', '<', $id);
                    });
            });
        }

        // Se pide uno de mas para saber si queda historial sin traer otra
        // consulta de conteo, que sobre conversaciones largas es cara.
        $rows = $query->limit($size + 1)->get();
        $hasMore = $rows->count() > $size;
        $rows = $rows->take($size);

        // De vuelta a orden cronologico, que es como se leen.
        $ordered = $rows->reverse()->values();

        $oldest = $ordered->first();
        $newest = $ordered->last();

        return [
            'items' => $ordered->map(fn (MarketingMessage $m) => $this->presentMessage($m))->all(),
            'has_more' => $hasMore,
            // El cursor apunta al mas ANTIGUO de esta pagina: es desde donde
            // sigue la siguiente hacia atras.
            'next_cursor' => $hasMore && $oldest !== null ? $this->encodeCursor($oldest) : null,
            'oldest_id' => $oldest?->id,
            'newest_id' => $newest?->id,
        ];
    }

    /** @return array<string,mixed> */
    private function presentMessage(MarketingMessage $m): array
    {
        return [
            'id' => $m->id,
            'direction' => $m->direction,
            'sender_type' => $m->sender_type,
            'sender_user_id' => $m->sender_user_id,
            'body' => $m->body,
            'status' => $m->status,
            'created_at' => $m->created_at?->toIso8601String(),
            // Motivo legible cuando Meta rechazo el envio. Sin esto, un
            // "failed" en la burbuja no le dice nada a quien atiende.
            'failure' => $this->failureFor($m),
            'attachments' => $this->attachmentsFor($m),
            'quoted_meta_message_id' => data_get($m->metadata, 'context.quoted_meta_message_id'),
            // No se expone metadata cruda del proveedor.
        ];
    }

    private function encodeCursor(MarketingMessage $m): string
    {
        /*
         * Se usa el valor TAL COMO ESTA GUARDADO, no una version formateada.
         *
         * Formatear con microsegundos parecia mas preciso y era un error: SQLite
         * guarda la fecha sin fraccion y compara como TEXTO, asi que
         * '...11:13:00' < '...11:13:00.000000' resulta cierto y el mensaje del
         * borde volvia en la pagina siguiente. En PostgreSQL no se notaba porque
         * compara como fecha. Comparar contra lo almacenado funciona igual en
         * los dos motores.
         *
         * Opaco a proposito: quien consume no debe construirlo a mano ni
         * depender de su forma.
         */
        $storedAt = $m->getRawOriginal('created_at') ?? $m->created_at?->format('Y-m-d H:i:s');

        return base64_encode($storedAt.'|'.$m->id);
    }

    /** @return array{0:string,1:int}|null */
    private function decodeCursor(?string $cursor): ?array
    {
        if (blank($cursor)) {
            return null;
        }

        $raw = base64_decode($cursor, true);

        if ($raw === false || ! str_contains($raw, '|')) {
            // Un cursor ilegible se ignora y se sirve la primera pagina. Es
            // preferible a un error: quien atiende ve la conversacion igual.
            return null;
        }

        [$at, $id] = explode('|', $raw, 2);

        return ctype_digit($id) ? [$at, (int) $id] : null;
    }
}
