<?php

namespace App\Services\Outreach\Facts;

use App\Services\Outreach\Core\FactSnapshot;

/**
 * LOS NOMBRES Y TIPOS de lo que produce `Outreach\Facts` (contrato H9, D9-4,
 * D9-7). PURO.
 *
 * LA UNIÓN CON EL NÚCLEO: los hechos escalares ({@see FACT_TYPES}) son los de
 * `OutreachCatalog::export()['facts']` (B9-core, alineado con el fixture
 * `catalog-snapshot.json` de TEST9-a) salvo los de `contact.*`, que el
 * evaluador calcula de la lista de contactos. `FactNamesUnionTest` lo ata: si
 * el catálogo cambia, esta lista cambia en el mismo commit. Los tipos son los
 * del catálogo (DF-T1/T2/T4): `int` (recuentos y días CON SIGNO), `duration`
 * (≥ 0), `decimal` (un ENTERO en centésimas), `date_relative` (desplazamiento
 * con signo, futuro > 0), `bool` y `enum` (`plans` de enteros, `class_types`
 * de textos).
 *
 * Los de POLÍTICA ({@see POLICY}) no se pueden componer en la interfaz: son
 * las entradas de la escalera N1–N4 que solo leen los adaptadores. Los
 * GLOBALES ({@see GLOBAL_BRAKES}) son los motivos de N2 que no dependen de la persona.
 */
final class FactNames
{
    public const TYPE_BOOL = 'bool';

    public const TYPE_INT = 'int';

    public const TYPE_DECIMAL = 'decimal';

    public const TYPE_ENUM = 'enum';

    public const TYPE_DURATION = 'duration';

    public const TYPE_DATE_RELATIVE = 'date_relative';

    /** Los hechos escalares de la foto y su tipo del catálogo (D9-4). */
    public const FACT_TYPES = [
        'attendance.count_last_30d' => self::TYPE_INT,
        'attendance.days_since_last' => self::TYPE_DURATION,
        'attendance.weekly_rate' => self::TYPE_DECIMAL,
        'class.attended_type_last_60d' => self::TYPE_ENUM,
        'class.reserved_next_7d' => self::TYPE_BOOL,
        'conversation.human_in_control' => self::TYPE_BOOL,
        'conversation.window_open' => self::TYPE_BOOL,
        'lead.commercial_phase' => self::TYPE_ENUM,
        'lead.consent_status' => self::TYPE_ENUM,
        'lead.days_since_last_inbound' => self::TYPE_DURATION,
        'lead.followup_due' => self::TYPE_BOOL,
        'lead.last_inbound_at' => self::TYPE_DATE_RELATIVE,
        'lead.temperature' => self::TYPE_ENUM,
        'membership.active' => self::TYPE_BOOL,
        'membership.days_as_member' => self::TYPE_DURATION,
        'membership.days_since_expiry' => self::TYPE_INT,
        'membership.days_to_expiry' => self::TYPE_INT,
        'membership.end_date' => self::TYPE_DATE_RELATIVE,
        'membership.frozen' => self::TYPE_BOOL,
        'membership.plan_duration_days' => self::TYPE_INT,
        'membership.plan_id' => self::TYPE_ENUM,
        'nba.open_goal' => self::TYPE_ENUM,
        'payment.approved_count' => self::TYPE_INT,
        'payment.declined_last_7d' => self::TYPE_INT,
        'payment.has_pending_link' => self::TYPE_BOOL,
        'payment.pending_link_age_hours' => self::TYPE_DURATION,
        'receivable.count_overdue' => self::TYPE_INT,
        'receivable.has_overdue' => self::TYPE_BOOL,
        'receivable.overdue_days_max' => self::TYPE_INT,
        'subject.has_voice_contact' => self::TYPE_BOOL,
        'subject.has_whatsapp_identity' => self::TYPE_BOOL,
        'subject.is_lead' => self::TYPE_BOOL,
        'subject.is_member' => self::TYPE_BOOL,
        'subject.is_minor' => self::TYPE_BOOL,
        'subscription.active' => self::TYPE_BOOL,
        'subscription.failed_attempts' => self::TYPE_INT,
        'valuation.days_since_last' => self::TYPE_DURATION,
        'valuation.has_upcoming' => self::TYPE_BOOL,
        'valuation.next_date' => self::TYPE_DATE_RELATIVE,
    ];

    /** Los hechos del catálogo que el evaluador calcula de la lista de contactos. */
    public const HISTORY_FACTS = ['contact.count', 'contact.days_since_last', 'contact.last_outcome'];

    /** Los que el catálogo declara NO nulos (`nullable: false`): siempre un booleano. */
    public const NOT_NULLABLE = ['subject.has_voice_contact', 'subject.has_whatsapp_identity', 'subject.is_lead', 'subject.is_member'];

    /** Las entradas de política por persona (siempre booleanas; ausencia de autorización = false). */
    public const POLICY = [
        'canary.voice', 'canary.whatsapp',
        'consent.voice_call.collections', 'consent.voice_call.commercial', 'consent.voice_call.service',
        'consent.whatsapp.collections', 'consent.whatsapp.commercial', 'consent.whatsapp.service',
        'lead.commercial_opt_out', 'lead.consent_denied', 'lead.do_not_contact', 'lead.do_not_contact_sibling',
        'voice.excluded', 'voice.rne_check_missing',
    ];

    /** Los motivos globales de N2 ({@see OperationalBrakes}). */
    public const GLOBAL_BRAKES = OperationalBrakes::KEYS;

    /** ¿Es válido este valor para este hecho? (null = desconocido; nunca en los NO nulos). */
    public static function validValue(string $fact, mixed $value): bool
    {
        $tipo = self::FACT_TYPES[$fact] ?? null;
        if ($tipo === null) {
            return false;
        }
        if ($value === null) {
            return ! in_array($fact, self::NOT_NULLABLE, true);
        }

        return match ($tipo) {
            self::TYPE_BOOL => is_bool($value),
            self::TYPE_ENUM => $fact === 'membership.plan_id'
                ? is_int($value) && $value >= 1
                : is_string($value) && preg_match(FactSnapshot::VALUE_PATTERN, $value) === 1,
            self::TYPE_DURATION => is_int($value) && $value >= 0,
            default => is_int($value),
        };
    }
}
