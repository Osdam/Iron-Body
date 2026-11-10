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
 * resultados de la cadena. Los textos hablan de reglas, nunca de quién: no
 * llevan datos de la persona.
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
        'purpose_conflict_today' => 'Hoy ya hay un contacto de otra finalidad.',
        'lower_priority' => 'Otra estrategia tiene prioridad hoy.',
        'exit_converted' => 'La inscripción cierra como objetivo cumplido.',
        'handoff' => 'Se deriva a una persona del equipo.',
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
    ];

    /** El texto de un código (los `voice_policy:<motivo>` usan el de `voice_policy`); null si no existe. */
    public static function text(string $code): ?string
    {
        if (str_starts_with($code, self::VOICE_POLICY_PREFIX)) {
            $code = 'voice_policy';
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
     * @return array{format: string, timeZone: string, voicePolicyPrefix: string, levels: array<string,string>, verdicts: array<string,string>, texts: array<string,string>, templates: array<string,string>, fallbacks: array<string,string>}
     */
    public static function export(): array
    {
        $textos = self::TEXTS;
        ksort($textos, SORT_STRING);

        return [
            'format' => self::FORMAT,
            'timeZone' => RelativeTime::ZONE,
            'voicePolicyPrefix' => self::VOICE_POLICY_PREFIX,
            'levels' => self::LEVELS,
            'verdicts' => self::VERDICTS,
            'texts' => $textos,
            'templates' => self::TEMPLATES,
            'fallbacks' => self::FALLBACKS,
        ];
    }
}
