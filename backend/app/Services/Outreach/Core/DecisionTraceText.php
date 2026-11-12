<?php

namespace App\Services\Outreach\Core;

/**
 * Los TEXTOS de las decisiones en español claro (contrato H9, §8 «Simulación»:
 * «trazas explicadas en frases», y «Accesibilidad»). PURO: solo datos y
 * plantillas. El CRM los espeja en `commercial-automation-trace-text.ts`
 * (FE9) contra lo que exporta esta clase ({@see TEXTS}).
 *
 * Cada código de los conjuntos cerrados del núcleo tiene UN texto (una prueba
 * comprueba que no falta ninguno): los motivos de la escalera N1–N7, sus
 * avisos y efectos, los motivos de la puerta de inscripción, las anotaciones y
 * veredictos del selector, los 18 resultados, las 6 transiciones, los efectos
 * de los resultados, los motivos de la máquina de seguimientos y los
 * resultados de la cadena; y los CÓDIGOS DE DETALLE que los canales de W9 y
 * V9 escriben en trabajos, resultados y decisiones (la puerta de WhatsApp y su
 * normalizador; la puerta de las salientes del H8 con sus motivos y el
 * normalizador de llamadas), más las cancelaciones de la ejecución. Los
 * códigos ABIERTOS del H8 (`create_<x>`, `observe_<x>`) se enseñan tal cual.
 * Los textos hablan de reglas, nunca de quién: no llevan datos de la persona.
 *
 * EXPORTACIÓN (L9-25): {@see export()} da el diccionario que espeja el CRM
 * (niveles, veredictos, textos, plantillas y reservas de {@see explain()}) y
 * `tests/Unit/Outreach/Core/tools/export-trace-text-cases.php` lo escribe en
 * `trace-text-cases.json` con casos de {@see explain()} sobre decisiones reales.
 */
final class DecisionTraceText
{
    public const FORMAT = 'outreach-trace-text/1';

    /** Los `voice_policy:<motivo>` de N4 usan el texto de `voice_policy`. */
    public const VOICE_POLICY_PREFIX = 'voice_policy:';

    /** Los `meta_error_<código>` de WhatsApp (Meta rechazó el mensaje con ese código, W9) usan el texto de `meta_error`. */
    public const META_ERROR_PREFIX = 'meta_error_';

    /**
     * Las plantillas de {@see explain()}, literales (el CRM las rellena igual):
     * `{verdict}` y `{levelLabel}` son textos de {@see VERDICTS} y {@see LEVELS};
     * `{date}` es `dd-mm-aaaa` y `{time}` `HH:mm`, en Bogotá; `{reasons}` son
     * los textos de los motivos del canal separados por un espacio.
     */
    public const TEMPLATES = [
        'header' => '{verdict}.',
        'headerWithLevel' => '{verdict} ({level} · {levelLabel}).',
        'retryAt' => 'Se vuelve a intentar el {date} a las {time} (Bogotá).',
        'warning' => 'Aviso: {text}',
        'channel' => 'Canal {channel}: {verdict}.',
        'channelWithReasons' => 'Canal {channel}: {verdict} — {reasons}',
    ];

    /**
     * Lo que se escribe si un código no tiene texto (no debería pasar: una
     * prueba vigila que todo código cerrado lo tenga). En los motivos y el
     * veredicto de un canal, la reserva es el propio código.
     */
    public const FALLBACKS = [
        'verdict' => 'Decisión',
        'reason' => 'Motivo sin texto.',
        'warning' => 'sin texto.',
        'effect' => 'Efecto sin texto.',
        'annotation' => 'Anotación sin texto.',
    ];

    public const LEVELS = [
        'N1' => 'Seguridad, ley y consentimiento',
        'N2' => 'Exclusiones y frenos globales',
        'N3' => 'Versión comercial aprobada',
        'N4' => 'Configuración del canal',
        'N5' => 'Estrategia aprobada',
        'N6' => 'Contexto verificado del contacto',
        'N7' => 'Instrucciones de la persona',
    ];

    public const VERDICTS = [
        'allow' => 'Se permite el contacto',
        'defer' => 'Se aplaza el contacto',
        'deny' => 'No se contacta',
        'selected' => 'Se elige el canal',
        'apply' => 'Se aplica el resultado',
        'needs_review' => 'Necesita revisión humana',
        'ignore' => 'Se ignora el resultado',
    ];

    public const TEXTS = [
        // N1
        'calendar_outside_legal_hours' => 'Fuera del horario permitido: de lunes a viernes de 08:00 a 19:00 y sábados de 08:00 a 15:00 (la Ley 2300 permite desde las 07:00 entre semana; las horas de silencio del gimnasio terminan a las 08:00).',
        'calendar_sunday' => 'Es domingo: la ley no permite contactar.',
        'calendar_holiday' => 'Es festivo: la ley no permite contactar.',
        'no_consent_for_purpose_channel' => 'No hay autorización registrada para esta finalidad por este medio.',
        'consent_denied' => 'La persona negó la autorización para este medio.',
        'do_not_contact' => 'La persona pidió que no se le contacte.',
        'do_not_contact_sibling' => 'Otro registro con el mismo teléfono pidió que no se le contacte.',
        'commercial_opt_out' => 'La persona pidió no recibir ofertas comerciales.',
        'voice_excluded' => 'La persona está excluida de las llamadas.',
        'rne_check_missing' => 'Falta la consulta del Registro de Números Excluidos para llamar.',
        'subject_minor' => 'La persona es menor de edad.',
        'daily_contact_limit' => 'Ya hubo un contacto hoy con la persona o con su teléfono (como mucho uno al día sumando todos los canales y finalidades).',
        'weekly_contact_limit' => 'Ya tiene el máximo de contactos de los últimos 7 días.',
        'min_hours_between_contacts' => 'No han pasado las horas mínimas desde el último contacto.',
        'cross_channel_week_rule' => 'Esta semana ya se le contactó por otro medio: solo se puede usar ese mismo.',
        'collections_channel_week_rule' => 'Cobranza: esta semana ya se le contactó por otro medio.',
        'unknown_subject' => 'No se puede identificar a la persona.',
        'calendar_outside_own_hours' => 'Fuera del horario propio del gimnasio (más estricto que la ley).',
        'catalog_incompatible' => 'La estrategia usa un dato que el catálogo vigente ya no tiene: no se reinterpreta.',
        'collections_overdue_blocks_commercial' => 'Tiene cartera vencida (o no se pudo comprobar): no se le hacen ofertas comerciales.',
        // N2
        'master_flag_off' => 'La automatización comercial está apagada.',
        'mode_off' => 'El modo de la automatización está en apagado.',
        'outreach_brake_global' => 'Está activa la PARADA GLOBAL.',
        'outreach_brake_channel' => 'El canal está frenado.',
        'outreach_brake_strategy' => 'La estrategia está frenada.',
        'ultron_abort_latch' => 'El freno de ULTRON está echado.',
        'marketing_agent_paused' => 'El agente de mercadeo está en pausa.',
        'voice_braked' => 'El freno de voz está echado.',
        'human_in_control' => 'Una persona del equipo lleva la conversación.',
        'legacy_autonomy_on' => 'La autonomía del motor comercial anterior está encendida.',
        'legacy_followups_active' => 'Los seguimientos de mercadeo anteriores están programados: se evita trabajar el mismo seguimiento dos veces.',
        'global_suppression' => 'La persona está en la lista de supresión.',
        // N3
        'studio_reference_invalid' => 'La versión comercial de referencia no cuadra con su huella.',
        'payment_links_disabled' => 'La versión comercial no permite enlaces de pago.',
        'template_violates_restriction' => 'La plantilla incumple una regla de la versión comercial (precio, descuento, enlace o promesa).',
        'canary_only_declared' => 'La versión comercial limita la estrategia a su alcance canario.',
        // N4
        'sends_disabled_in_code' => 'El envío automático está cerrado en el código.',
        'channel_disabled' => 'El canal está apagado.',
        'channel_not_live' => 'El canal todavía no está disponible.',
        'template_not_approved' => 'La plantilla no está aprobada.',
        'template_category_mismatch' => 'La categoría de la plantilla no corresponde a la finalidad.',
        'no_whatsapp_identity' => 'La persona no tiene una conversación de WhatsApp registrada.',
        'no_voice_contact' => 'La persona no tiene un contacto de voz registrado.',
        'voice_policy' => 'La política de llamadas no lo permite.',
        'budget_zero' => 'El presupuesto o el tope del canal es cero.',
        'budget_exhausted' => 'El presupuesto del día del canal está agotado.',
        'channel_daily_cap' => 'El canal llegó a su tope de hoy.',
        'assisted_capacity_reached' => 'La bandeja asistida llegó a su tope de hoy.',
        // N5
        'strategy_not_published' => 'La estrategia no está publicada.',
        'strategy_paused' => 'La estrategia está en pausa.',
        'chain_unreadable' => 'No se puede verificar la publicación de la estrategia.',
        'canary_scope_mismatch' => 'La estrategia está en canario y la persona no está en su alcance.',
        'outside_strategy_schedule' => 'Fuera del horario de la estrategia.',
        'max_touches_reached' => 'Se llegó al máximo de contactos de esta inscripción.',
        'strategy_daily_cap' => 'La estrategia llegó a su tope de contactos de hoy.',
        'enrollment_window_over' => 'El contacto no cabe en la ventana de la inscripción.',
        'cooldown_active' => 'La persona está en enfriamiento tras salir de esta estrategia.',
        'step_guard_false' => 'La condición del paso no se cumple.',
        // N6 y sus efectos
        'success_reached' => 'El objetivo ya está cumplido.',
        'context_changed' => 'La persona ya no cumple la audiencia o aparece una exclusión.',
        'human_requested_recently' => 'La persona pidió hablar con alguien hace poco: se para y se deriva.',
        'complaint_recent' => 'La persona se quejó hace poco: se para el contacto automático.',
        'purpose_conflict_today' => 'Hoy ya hay un contacto de otra finalidad.',
        'lower_priority' => 'Otra estrategia tiene prioridad hoy.',
        'exit_converted' => 'La inscripción cierra como objetivo cumplido.',
        'handoff' => 'Se deriva a una persona del equipo.',
        'exit_complaint' => 'La inscripción sale por la queja reciente.',
        'cancel_context_changed' => 'Se cancela el trabajo: el contexto cambió.',
        // N7 y anotaciones del selector
        'callback_scheduled' => 'La persona pidió que se le contacte en otro momento.',
        'preference_applied' => 'Se usa el medio que prefirió la persona.',
        'instruction_ignored_by_policy' => 'Lo que pidió la persona no cabe en las reglas: manda el orden del paso.',
        // Puerta de inscripción
        'enroll' => 'Cumple la audiencia: se inscribe.',
        'not_in_audience' => 'No cumple la audiencia.',
        'insufficient_facts' => 'Faltan datos para decidir: no se inscribe.',
        'excluded' => 'Cumple una exclusión.',
        // Los 18 resultados (D9-10)
        'blocked' => 'Bloqueado por la política: no salió nada.',
        'not_attempted' => 'Simulado: no se intentó.',
        'failed' => 'Falló el envío.',
        'ambiguous' => 'No se sabe si salió: se concilia.',
        'sent' => 'Enviado.',
        'delivered' => 'Entregado.',
        'read' => 'Leído.',
        'no_answer' => 'Sin respuesta.',
        'answered' => 'Contestó.',
        'replied' => 'Respondió.',
        'callback_requested' => 'Pidió que se le contacte después.',
        'human_requested' => 'Pidió hablar con una persona.',
        'not_interested' => 'No le interesa.',
        'wrong_person' => 'No era la persona.',
        'opted_out' => 'Pidió no recibir más contactos.',
        'appointment_booked' => 'Agendó una cita.',
        'converted' => 'Objetivo cumplido.',
        'no_response' => 'La ventana venció sin respuesta.',
        // Transiciones
        'next_step' => 'Pasa al siguiente paso.',
        'retry_step' => 'Se reintenta el mismo paso.',
        'wait' => 'Se espera y se vuelve a intentar.',
        'end_success' => 'Termina con éxito.',
        'end_exit' => 'Termina sin éxito.',
        'handoff_human' => 'Se deriva a una persona del equipo.',
        // Efectos de los resultados (DF-O8)
        'reconcile' => 'Se concilia con el proveedor antes de seguir.',
        'assisted_task' => 'Se abre una tarea en la bandeja asistida.',
        'cooldown' => 'Empieza el enfriamiento antes de volver a inscribirla.',
        'suppress_channel' => 'Se suprime ese medio para la persona.',
        'suppress_purpose_channel' => 'Se suprime esa finalidad por ese medio.',
        'cancel_pending_all_strategies' => 'Se cancelan sus trabajos pendientes de todas las estrategias.',
        // Máquina de seguimientos
        'closed' => 'La inscripción se cierra.',
        'outcome_ignored' => 'El resultado no cuenta: no avanza.',
        'non_terminal' => 'Resultado intermedio: no cambia nada.',
        'sequence_done_waits_window' => 'No quedan pasos: la inscripción espera a que venza.',
        'window_over_no_job' => 'El siguiente hueco cae fuera de la ventana: no hay más contactos.',
        'enrollment_final' => 'La inscripción ya terminó.',
        // Cadena firmada
        'ok' => 'La publicación verifica.',
        'malformed' => 'La publicación no tiene la forma de la cadena.',
        'not_canonical' => 'Los bytes de la publicación no son los canónicos.',
        'signature_malformed' => 'La firma no tiene forma.',
        'signature_mismatch' => 'La firma no cuadra.',
        'seq_gap' => 'Falta un eslabón de la cadena.',
        'chain_broken' => 'La cadena está rota.',
        // La ejecución: cancelaciones y conciliación (B9-run; REV9 m-9)
        'strategy_moved' => 'La versión de la estrategia ya no está viva en la publicación (reversión, retirada o promoción): se cancela.',
        'stale' => 'El trabajo quedó a medias más de 10 minutos: no se sabe si salió y se concilia.',
        // W9 · WhatsApp por plantillas: el canal, la puerta de WhatsApp y el normalizador de Meta
        'job_channel_mismatch' => 'El trabajo no es de este canal o no está en vivo.',
        'mode_not_live' => 'La automatización ya no está en modo en vivo.',
        'budget_not_reserved' => 'El trabajo no tiene reservados su cupo y su presupuesto del día.',
        'template_not_approved_by_meta' => 'La plantilla no consta como aprobada por Meta o no corresponde a la del Studio.',
        'opt_out_word' => 'La persona escribió una palabra de baja por WhatsApp: no se le escribe más.',
        'reply_pending' => 'La persona respondió a un contacto anterior de esta inscripción: la secuencia se detiene.',
        'template_marker_unresolvable' => 'Un dato de la plantilla no se puede rellenar con los datos de este momento.',
        'template_parameter_invalid' => 'Un dato de la plantilla tiene una forma que Meta rechazaría.',
        'no_proactive_consent' => 'La persona no autorizó que se le escriba primero.',
        'lead_without_phone' => 'La persona no tiene un teléfono registrado.',
        'agent_paused' => 'El agente de mercadeo está en pausa.',
        'no_whatsapp_conversation' => 'La persona no tiene una conversación de WhatsApp en el CRM.',
        'human_takeover' => 'Una persona del equipo tomó la conversación: el mensaje automático no sale.',
        'meta_disabled_or_unconfigured' => 'WhatsApp está apagado o sin configurar: el mensaje quedó como simulacro y no llegó a nadie.',
        'meta_error' => 'Meta rechazó el mensaje (con su código de error).',
        'meta_failed' => 'Meta informó que el mensaje falló.',
        'outbox_dead' => 'El mensaje no salió: la cola de envío se rindió sin que Meta lo aceptara.',
        'dead' => 'El mensaje ya estaba descartado en la cola de envío: no sale.',
        'template_metadata_invalid' => 'El mensaje guardado no tiene la forma de una plantilla: no sale.',
        'provider_send_failed' => 'El proveedor no pudo enviar el mensaje.',
        'gate_refused' => 'La puerta de WhatsApp no lo dejó salir.',
        'gate_error' => 'La puerta de WhatsApp falló al intentarlo: no salió nada o se concilia.',
        'already_dispatched' => 'Este trabajo ya había pasado por la puerta: no se envía dos veces.',
        // V9 · la voz por estrategia: el canal, la puerta de las salientes del H8 (sus motivos) y el normalizador de llamadas
        'channel_mismatch' => 'El trabajo no es de este canal o no está en vivo.',
        'voice_call_ref_missing' => 'La puerta de llamadas la aceptó sin devolver la llamada: se concilia.',
        'voice_gate_error' => 'La puerta de llamadas falló al pedirla: se concilia por el trabajo.',
        'voice_gate_denied' => 'La puerta de llamadas no la dejó salir.',
        'outbound_denied' => 'La política de llamadas no la permite.',
        'outbound_race' => 'Otra llamada al mismo número se pidió a la vez.',
        'client_request_reused' => 'El identificador del trabajo ya se usó para otra llamada.',
        'job_uuid_invalid' => 'La petición de llamada no lleva un trabajo válido.',
        'purpose_key_invalid' => 'La finalidad no admite llamadas.',
        'subject_invalid' => 'La persona de la llamada no tiene una ficha válida.',
        'strategy_key_invalid' => 'La clave de la estrategia no tiene forma.',
        'calls_disabled_in_code' => 'Las llamadas están cerradas en el código.',
        'outbound_disabled' => 'Las llamadas salientes están apagadas.',
        'twilio_voice_disabled' => 'La voz del proveedor de telefonía está apagada.',
        'no_voice_number' => 'No hay número de voz configurado.',
        'voice_number_is_whatsapp' => 'El número de voz es el de WhatsApp: no se usa para llamar.',
        'number_unverified' => 'El número de voz no se ha comprobado hace poco.',
        'number_mismatch' => 'El número que llamaría no es el configurado.',
        'elevenlabs_not_configured' => 'El agente de voz no está configurado.',
        'elevenlabs_disabled' => 'El agente de voz está apagado.',
        'elevenlabs_unverified' => 'El agente de voz no está verificado.',
        'no_publication' => 'No hay una voz publicada.',
        'publication_unreadable' => 'No se puede verificar la publicación de la voz.',
        'agent_drift' => 'El agente desplegado no coincide con la voz publicada.',
        'source_not_allowed' => 'El origen del número no está permitido para llamar.',
        'not_an_active_voice_canary' => 'El número no es un canario de voz activo.',
        'contact_not_authorized' => 'El contacto de voz no está autorizado.',
        'no_voice_consent' => 'No hay autorización de voz para esta finalidad.',
        'outside_hours' => 'Fuera del horario de las llamadas.',
        'holiday' => 'Es festivo: no se llama.',
        'frequency_exceeded' => 'La persona ya recibió el máximo de llamadas de la semana.',
        'concurrency_exceeded' => 'Ya hay otra llamada en curso.',
        'daily_cap_zero' => 'El tope diario de llamadas es cero.',
        'daily_cap_reached' => 'Se llegó al tope de llamadas de hoy.',
        'duplicate_request' => 'Esa llamada ya se había pedido.',
        'no_volver_a_llamar' => 'La persona pidió en la llamada que no se le vuelva a llamar.',
        'solicitar_humano' => 'La persona pidió en la llamada hablar con alguien del equipo.',
        'guard_clean' => 'La revisión posterior de la llamada no encontró nada.',
        'guard_violation' => 'La revisión posterior de la llamada encontró una regla incumplida.',
        'busy' => 'La línea estaba ocupada.',
        'call_failed' => 'La llamada falló.',
        'provider_rejected' => 'El proveedor de telefonía rechazó la llamada.',
        'hung_up' => 'La llamada se cortó con el corte de emergencia.',
        'voice_call_absent' => 'La llamada nunca llegó a crearse.',
        'voice_unknown' => 'La telefonía devolvió un código desconocido.',
        'job_failed' => 'El proceso que hace la llamada falló.',
        'job_lost' => 'El proceso que hace la llamada se perdió.',
    ];

    /** El texto de un código (los `voice_policy:<motivo>` usan el de `voice_policy` y los `meta_error_<código>` el de `meta_error`); null si no existe. */
    public static function text(string $code): ?string
    {
        if (str_starts_with($code, self::VOICE_POLICY_PREFIX)) {
            $code = 'voice_policy';
        } elseif (str_starts_with($code, self::META_ERROR_PREFIX)) {
            $code = 'meta_error';
        }

        return self::TEXTS[$code] ?? null;
    }

    /**
     * Las frases de una decisión de la escalera (la salida de
     * {@see PolicyLadder::decide()}) y, si se pasa, de la selección de canal
     * ({@see ChannelSelector::choose()}).
     *
     * @param  array<string,mixed>  $ladder
     * @param  array<string,mixed>|null  $channel
     * @return list<string>
     */
    public static function explain(array $ladder, ?array $channel = null): array
    {
        $nivel = $ladder['level'] ?? null;
        $veredicto = self::VERDICTS[$ladder['verdict'] ?? 'deny'] ?? self::FALLBACKS['verdict'];
        $frases = [is_string($nivel) && isset(self::LEVELS[$nivel])
            ? strtr(self::TEMPLATES['headerWithLevel'], ['{verdict}' => $veredicto, '{level}' => $nivel, '{levelLabel}' => self::LEVELS[$nivel]])
            : strtr(self::TEMPLATES['header'], ['{verdict}' => $veredicto])];
        foreach ($ladder['reasons'] ?? [] as $motivo) {
            $frases[] = self::text((string) $motivo) ?? self::FALLBACKS['reason'];
        }
        $reintento = $ladder['retryAt'] ?? null;
        if (is_string($reintento) && RelativeTime::isInstant($reintento)) {
            $local = RelativeTime::local(RelativeTime::parseInstant($reintento));
            $frases[] = strtr(self::TEMPLATES['retryAt'], ['{date}' => $local->format('d-m-Y'), '{time}' => $local->format('H:i')]);
        }
        foreach ($ladder['warnings'] ?? [] as $aviso) {
            $frases[] = strtr(self::TEMPLATES['warning'], ['{text}' => self::text((string) ($aviso['reason'] ?? '')) ?? self::FALLBACKS['warning']]);
        }
        if (is_string($ladder['effect'] ?? null)) {
            $frases[] = self::text($ladder['effect']) ?? self::FALLBACKS['effect'];
        }
        foreach ($channel['perChannel'] ?? [] as $c) {
            $motivos = array_map(static fn (string $m): string => self::text($m) ?? $m, $c['reasons'] ?? []);
            $frases[] = strtr(self::TEMPLATES[$motivos === [] ? 'channel' : 'channelWithReasons'], [
                '{channel}' => $c['channel'], '{verdict}' => self::VERDICTS[$c['verdict']] ?? $c['verdict'], '{reasons}' => implode(' ', $motivos),
            ]);
        }
        foreach ($channel['annotations'] ?? [] as $nota) {
            $frases[] = self::text((string) $nota) ?? self::FALLBACKS['annotation'];
        }

        return $frases;
    }

    /**
     * El diccionario que espeja el CRM (L9-25), con los textos ordenados por
     * código. Es dato puro: lo escribe en disco la herramienta de pruebas.
     *
     * @return array{format: string, timeZone: string, voicePolicyPrefix: string, metaErrorPrefix: string, levels: array<string,string>, verdicts: array<string,string>, texts: array<string,string>, templates: array<string,string>, fallbacks: array<string,string>}
     */
    public static function export(): array
    {
        $textos = self::TEXTS;
        ksort($textos, SORT_STRING);

        return [
            'format' => self::FORMAT,
            'timeZone' => RelativeTime::ZONE,
            'voicePolicyPrefix' => self::VOICE_POLICY_PREFIX,
            'metaErrorPrefix' => self::META_ERROR_PREFIX,
            'levels' => self::LEVELS,
            'verdicts' => self::VERDICTS,
            'texts' => $textos,
            'templates' => self::TEMPLATES,
            'fallbacks' => self::FALLBACKS,
        ];
    }
}
