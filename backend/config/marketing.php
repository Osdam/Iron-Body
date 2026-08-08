<?php

/*
|--------------------------------------------------------------------------
| IRON BODY — Agente Comercial IA (módulo Mercadeo / marketing_*)
|--------------------------------------------------------------------------
| Configuración de los cimientos del agente comercial (Fase 0/1). Todo es
| ADITIVO y SEGURO: con `agent_enabled=false` (default) los endpoints y el
| comando de seguimientos NO ejecutan acciones externas reales (no envían
| WhatsApp/IG/FB ni inician llamadas). Generar un link de pago NUNCA activa una
| membresía: la activación sigue siendo exclusiva del webhook Wompi aprobado /
| reconciliación / PaymentMembershipActivator.
*/

return [

    // Interruptor general del agente comercial. Si false, los disparadores
    // externos quedan inertes (modo seguro). Generar links de pago SÍ está
    // permitido aunque esté en false (solo crea la transacción + URL; no
    // contacta a Meta ni activa nada), salvo que se decida lo contrario abajo.
    'agent_enabled' => filter_var(env('MARKETING_AGENT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // Seguimientos automáticos (marketing:dispatch-followups).
    'followups' => [
        // Si false, el comando recorre y registra, pero NO envía mensajes ni
        // programa llamadas reales (preparado para fases siguientes).
        // Hoy, además, el envío real depende de agent_enabled + META_ENABLED.
        'dispatch_enabled'   => filter_var(env('MARKETING_FOLLOWUPS_DISPATCH', false), FILTER_VALIDATE_BOOLEAN),
        // Agendar el comando en el scheduler. INERTE por defecto: se activa por
        // env sin tocar código (igual que el patrón proactive_coach).
        'scheduler_enabled'  => filter_var(env('MARKETING_FOLLOWUPS_SCHEDULER', false), FILTER_VALIDATE_BOOLEAN),
        // Cada cuántos minutos corre (5–10 recomendado).
        'scheduler_minutes'  => (int) env('MARKETING_FOLLOWUPS_MINUTES', 10),
        // Máximo de seguimientos vencidos a procesar por corrida (anti-avalancha).
        'batch_limit'        => (int) env('MARKETING_FOLLOWUPS_BATCH', 100),
    ],

    // Link de pago por WhatsApp/Meta (Fase 1). El monto es SIEMPRE autoritativo
    // del backend (Plan::price); el cliente/n8n nunca define el precio.
    'payment_links' => [
        // Origen que se sella en metadata.source de la PaymentTransaction.
        'source' => 'marketing_agent',
    ],

    // Mensajes ENTRANTES de Meta/WhatsApp (Fase 4-A). Defaults SEGUROS:
    //  - analizar (cerebro) SÍ puede estar habilitado,
    //  - EJECUTAR herramientas reales queda en false (y además exige
    //    agent_enabled), y el envío real sigue bloqueado por META_ENABLED.
    'inbox' => [
        // Mensajes por pagina del historial. 40 cabe de sobra en cualquier
        // pantalla y evita traer de mas al abrir una conversacion.
        'message_page_size' => (int) env('MARKETING_INBOX_PAGE_SIZE', 40),
    ],

    'inbound' => [
        // Permite el procesamiento de entrantes; si false, el webhook solo
        // registra (no analiza). Derivado/independiente de META_ENABLED.
        'meta_enabled'  => filter_var(env('MARKETING_INBOUND_META_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // ¿Enrutar el texto entrante al cerebro (analyze)?
        'auto_analyze'  => filter_var(env('MARKETING_INBOUND_AUTO_ANALYZE', true), FILTER_VALIDATE_BOOLEAN),
        // ¿Ejecutar herramientas (link/followup/takeover) de forma automática?
        // Falso por defecto; además requiere marketing.agent_enabled=true.
        'auto_execute'  => filter_var(env('MARKETING_INBOUND_AUTO_EXECUTE', false), FILTER_VALIDATE_BOOLEAN),
        // Guardar el evento crudo de Meta en metadata (debug). Off por defecto.
        'store_raw_payload' => filter_var(env('MARKETING_INBOUND_STORE_RAW_PAYLOAD', false), FILTER_VALIDATE_BOOLEAN),
        // ¿El agente piensa EN LÍNEA, dentro de la misma ejecución que guardó
        // el mensaje? Falso en el camino real: la llamada al modelo se despacha
        // al carril `agent` para que el worker que atiende a Meta quede libre
        // en el acto. Solo se enciende donde alguien espera la decisión de
        // vuelta —el comando de simulación y las pruebas del razonamiento—,
        // porque ahí no hay un cliente al otro lado del teléfono.
        'analyze_inline' => filter_var(env('MARKETING_INBOUND_ANALYZE_INLINE', false), FILTER_VALIDATE_BOOLEAN),
        // Tipos que el CEREBRO COMERCIAL puede analizar por sí mismo. Ojo: NO
        // es la lista de lo que el inbox acepta. El inbox guarda y muestra todo
        // lo que Meta entregue; esta lista decide únicamente qué puede leer la
        // IA. Un audio o una foto se registran, se descargan y se escalan a un
        // humano aunque no estén aquí.
        'supported_message_types' => ['text', 'interactive', 'button'],
    ],

    /*
    | Salida hacia Meta. Un envío fallido no era recuperable: se marcaba
    | 'failed' y ahí moría, aunque el motivo fuera un límite de tasa pasajero.
    | Ahora los fallos transitorios se reintentan con espera creciente y los
    | definitivos quedan 'dead' y visibles para un humano.
    */
    'outbox' => [
        // Intentos totales por mensaje (el primero incluido). Cuatro cubre un
        // pico de límite de tasa sin dejar a nadie esperando media hora.
        'max_attempts' => (int) env('WHATSAPP_OUTBOX_MAX_ATTEMPTS', 4),
        // Base de la espera exponencial, en segundos: 30s, 60s, 120s…
        'retry_base_seconds' => (int) env('WHATSAPP_OUTBOX_RETRY_BASE', 30),
        // Techo de la espera: pasado esto, insistir más tarde no ayuda.
        'retry_max_seconds' => (int) env('WHATSAPP_OUTBOX_RETRY_MAX', 1800),
        // Mensajes reintentados por corrida (anti-avalancha tras una caída).
        'retry_batch' => (int) env('WHATSAPP_OUTBOX_RETRY_BATCH', 50),
    ],

    /*
    | Adjuntos de WhatsApp (imagen, audio, nota de voz, video, documento,
    | sticker). Todo archivo entrante es CONTENIDO NO CONFIABLE: se descarga a
    | un disco privado, se le comprueba el tipo real y solo se sirve mediante
    | URLs firmadas de vida corta y con permiso del CRM.
    */
    'media' => [
        // Descargar los adjuntos entrantes. Si se apaga, el mensaje se sigue
        // registrando y viéndose en el inbox: solo falta el archivo.
        'download_enabled' => filter_var(env('WHATSAPP_MEDIA_DOWNLOAD_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        'disk' => env('WHATSAPP_MEDIA_DISK', 'whatsapp'),

        // Techo por archivo. Los límites de WhatsApp hoy son 16 MB para audio,
        // imagen y video y 100 MB para documento; se recorta a 25 MB porque a
        // un gimnasio nadie le manda un video de 100 MB con buena intención y
        // cada archivo se guarda en el mismo disco del servidor.
        'max_size_bytes' => (int) env('WHATSAPP_MEDIA_MAX_SIZE', 25 * 1024 * 1024),

        // Días que se conserva el BINARIO. Pasado el plazo se borra el archivo
        // y la ficha queda como 'expired': el inbox sigue mostrando que hubo un
        // adjunto y de qué tipo, sin conservar el contenido para siempre.
        'retention_days' => (int) env('WHATSAPP_MEDIA_RETENTION_DAYS', 180),

        // Vida de la URL firmada que recibe el navegador. Corta a propósito:
        // si alguien la copia y la pega fuera, caduca sola.
        'signed_url_minutes' => (int) env('WHATSAPP_MEDIA_URL_MINUTES', 10),

        // Reintentos de descarga ante fallo transitorio (la URL de Meta caduca
        // en minutos, así que insistir mucho tampoco sirve).
        'max_attempts' => (int) env('WHATSAPP_MEDIA_MAX_ATTEMPTS', 3),
        'timeout' => (int) env('WHATSAPP_MEDIA_TIMEOUT', 60),

        // Tipos REALES aceptados, comprobados sobre los bytes del archivo y no
        // sobre lo que declare Meta o la extensión. Lo que no esté aquí se
        // rechaza: nada de HTML (XSS servido desde nuestro dominio), nada de
        // SVG (lleva scripts), nada ejecutable, nada de comprimidos (zip bomb).
        'allowed_mime_types' => [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/amr', 'audio/wav', 'audio/opus',
            'video/mp4', 'video/3gpp',
            'application/pdf',
            'text/plain', 'text/csv',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],

        // Transcripción de notas de voz. APAGADA por defecto: manda audio de
        // clientes a un tercero y eso se activa a conciencia, no por descuido.
        'transcription' => [
            'enabled' => filter_var(env('WHATSAPP_MEDIA_TRANSCRIBE', false), FILTER_VALIDATE_BOOLEAN),
            'max_seconds' => (int) env('WHATSAPP_MEDIA_TRANSCRIBE_MAX_SECONDS', 120),
        ],

        /*
        | Archivos que SALEN del CRM hacia el cliente.
        |
        | Se validan con el mismo rigor que los que entran, y no por simetría:
        | un archivo subido desde el CRM va a acabar guardado en el teléfono de
        | un cliente con el nombre del gimnasio encima. Que el origen sea un
        | asesor de confianza no cambia que el navegador de ese asesor pueda
        | estar comprometido, ni que un despiste mande el archivo equivocado.
        */
        'outbound' => [
            // Adjuntar desde el inbox. Apagarlo deja el compositor solo con
            // texto, sin romper nada de lo ya enviado.
            'enabled' => filter_var(env('WHATSAPP_OUTBOUND_MEDIA_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

            // Techos REALES de WhatsApp Cloud API por familia. Mandar algo más
            // grande no es un error nuestro que se pueda reintentar: Meta lo
            // rechaza, así que se corta antes de ocupar disco.
            'max_size_bytes' => [
                'image' => (int) env('WHATSAPP_OUTBOUND_MAX_IMAGE', 5 * 1024 * 1024),
                'audio' => (int) env('WHATSAPP_OUTBOUND_MAX_AUDIO', 16 * 1024 * 1024),
                'video' => (int) env('WHATSAPP_OUTBOUND_MAX_VIDEO', 16 * 1024 * 1024),
                'document' => (int) env('WHATSAPP_OUTBOUND_MAX_DOCUMENT', 25 * 1024 * 1024),
            ],

            // Cuántos archivos caben en un envío. Cada uno viaja como mensaje
            // propio, así que este número es también cuántas notificaciones
            // recibe el cliente de golpe.
            'max_per_send' => (int) env('WHATSAPP_OUTBOUND_MAX_PER_SEND', 5),

            // Horas que sobrevive un borrador sin enviar. Pasadas, el binario
            // se borra: son archivos que alguien soltó en el compositor y
            // nunca mandó, y no tienen por qué quedarse para siempre.
            'draft_ttl_hours' => (int) env('WHATSAPP_OUTBOUND_DRAFT_TTL_HOURS', 24),

            /*
            | Notas de voz.
            |
            | WhatsApp solo reproduce como nota de voz el OGG/Opus. El problema
            | es que ningún navegador graba en los tres formatos: Firefox da
            | ogg/opus, Safari da mp4, y Chrome —el que usa casi todo el
            | mundo— solo da WebM, que WhatsApp no acepta.
            |
            | Sin conversión, "grabar una nota de voz" funcionaría en Firefox y
            | fallaría en Chrome, que es la peor forma posible de entregar algo.
            | Por eso el WebM se transcodifica aquí antes de salir. Si el
            | servidor no tiene ffmpeg, la capacidad se anuncia como no
            | disponible y el botón no aparece: mejor no ofrecerlo que ofrecerlo
            | roto.
            */
            'voice' => [
                'enabled' => filter_var(env('WHATSAPP_VOICE_NOTES_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
                'ffmpeg' => env('WHATSAPP_FFMPEG_PATH', 'ffmpeg'),
                'transcode_timeout' => (int) env('WHATSAPP_FFMPEG_TIMEOUT', 30),
                'max_seconds' => (int) env('WHATSAPP_VOICE_MAX_SECONDS', 300),
            ],
        ],
    ],

    // Cerebro comercial IA (Fase 2). Por defecto usa un responder DETERMINISTA
    // (reglas, sin OpenAI). Cuando exista infraestructura segura se podrá
    // cambiar el driver SIN tocar el orquestador. La IA solo DECIDE y registra;
    // las acciones reales siguen protegidas por flags/guardrails.
    'ai' => [
        // fake (reglas locales) | openai (requiere config segura + OPENAI_API_KEY).
        // MARKETING_SALES_AI_DRIVER es el nombre canónico; se conserva el alias
        // MARKETING_AI_DRIVER por retrocompatibilidad con la Fase 2.
        'driver'  => env('MARKETING_SALES_AI_DRIVER', env('MARKETING_AI_DRIVER', 'fake')),
        // Interruptor del cerebro; con false el orquestador devuelve unknown.
        'enabled' => filter_var(env('MARKETING_AI_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        // Retrasos de seguimiento por temperatura (minutos). Usados al programar
        // marketing_followups; nada se envía solo si los flags de envío están off.
        'followup_delays' => [
            'very_hot' => (int) env('MARKETING_AI_FOLLOWUP_VERY_HOT', 60),
            'hot'      => (int) env('MARKETING_AI_FOLLOWUP_HOT', 120),
            'warm'     => (int) env('MARKETING_AI_FOLLOWUP_WARM', 360),
        ],

        // Cerebro OpenAI (Fase 3). INERTE por defecto: aunque driver=openai, solo
        // se usa si openai.enabled=true Y existe OPENAI_API_KEY (services.openai).
        // La API key NO se duplica aquí: se reutiliza config('services.openai').
        // Laravel SIEMPRE tiene la última palabra (validator + guardrails).
        // Hermes (motor de razonamiento adicional). INERTE por defecto y por
        // partida doble: driver debe ser 'hermes' Y hermes.enabled true Y debe
        // haber base_url. Si algo falta, cae a OpenAI y de ahí a fake, así que
        // apagar el flag devuelve el comportamiento exacto de hoy (kill switch).
        //
        // Hermes SOLO razona: no habla con PostgreSQL, no controla WhatsApp y no
        // se salta Laravel. Su salida pasa por SalesAgentDecisionValidator y por
        // los guardrails igual que la de OpenAI.
        'hermes' => [
            'enabled'     => filter_var(env('MARKETING_HERMES_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            // Loopback SIEMPRE: Hermes escucha en 127.0.0.1 del propio servidor
            // y no debe ser accesible desde fuera. Si algún día esto apunta a un
            // host remoto, es que algo se hizo mal.
            'base_url'    => rtrim((string) env('MARKETING_HERMES_BASE_URL', ''), '/'),
            'api_key'     => env('MARKETING_HERMES_API_KEY'),
            'model'       => env('MARKETING_HERMES_MODEL', 'gpt-4.1'),
            'timeout'     => (int) env('MARKETING_HERMES_TIMEOUT', 15),
            // Cero reintentos a propósito: si Hermes tarda, se cae a OpenAI en
            // lugar de hacer esperar al prospecto de WhatsApp.
            'max_retries' => (int) env('MARKETING_HERMES_MAX_RETRIES', 0),
            'temperature' => (float) env('MARKETING_HERMES_TEMPERATURE', 0.2),
            'max_output_tokens' => (int) env('MARKETING_HERMES_MAX_OUTPUT_TOKENS', 1200),

            // Cortacircuitos. Sin él, con Hermes caído cada prospecto paga el
            // timeout completo antes de degradar a OpenAI: quince personas
            // escribiendo son quince esperas inútiles y quince workers ocupados.
            'circuit_breaker' => [
                'failure_threshold' => (int) env('MARKETING_HERMES_CB_THRESHOLD', 3),
                'window_seconds'    => (int) env('MARKETING_HERMES_CB_WINDOW', 120),
                'cooldown_seconds'  => (int) env('MARKETING_HERMES_CB_COOLDOWN', 60),
            ],

            // Techo de gasto. MEDIDO en el servidor: Hermes antepone su propio
            // andamiaje (identidad, skills, memoria) al prompt, de modo que una
            // clasificación trivial consume ~14 000 tokens de entrada, no ~500.
            // Con el límite de 30 000 TPM de la organización eso son ~2
            // clasificaciones por minuto antes de que OpenAI empiece a rechazar.
            // Este contador corta ANTES de llegar ahí y degrada a OpenAI directo,
            // que para la misma tarea gasta una fracción.
            'budget' => [
                'max_calls_per_hour' => (int) env('MARKETING_HERMES_MAX_CALLS_HOUR', 60),
                // Coste observado por llamada, para estimar gasto sin adivinar.
                'observed_prompt_tokens' => (int) env('MARKETING_HERMES_OBSERVED_PROMPT_TOKENS', 14350),
            ],
        ],

        'openai' => [
            'enabled'           => filter_var(env('MARKETING_OPENAI_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            // Modelo; por defecto reusa el de IRON IA (services.openai.model).
            'model'             => env('MARKETING_OPENAI_MODEL', env('OPENAI_MODEL', 'gpt-4.1-mini')),
            'timeout'           => (int) env('MARKETING_OPENAI_TIMEOUT', 20),
            'max_retries'       => (int) env('MARKETING_OPENAI_MAX_RETRIES', 1),
            'temperature'       => (float) env('MARKETING_OPENAI_TEMPERATURE', 0.2),
            'max_output_tokens' => (int) env('MARKETING_OPENAI_MAX_OUTPUT_TOKENS', 1200),
            // Por seguridad/privacidad, NO se loguean prompts por defecto.
            'log_prompts'       => filter_var(env('MARKETING_OPENAI_LOG_PROMPTS', false), FILTER_VALIDATE_BOOLEAN),
            // true → ante error/JSON inválido se devuelve una decisión SEGURA
            // (unknown). false → cae al responder determinista (fake).
            'fail_closed'       => filter_var(env('MARKETING_OPENAI_FAIL_CLOSED', true), FILTER_VALIDATE_BOOLEAN),
        ],
    ],
];
