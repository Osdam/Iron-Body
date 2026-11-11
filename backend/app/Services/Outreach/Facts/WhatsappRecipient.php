<?php

namespace App\Services\Outreach\Facts;

use App\Services\Outreach\Core\RelativeTime;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * W9 · A QUIÉN y CON QUÉ VALORES se escribe una plantilla de WhatsApp
 * (contrato H9, D9-6: «los marcadores los resuelve Laravel al despachar, con
 * los hechos de ese instante»; D9-9.3). SOLO LECTURA, como todo `Facts`
 * (`select` y nada más, lista cerrada de tablas en {@see TABLES}).
 *
 *  - {@see leadFor()}: el prospecto por el que se escribiría, con LA MISMA
 *    regla que `LeadFacts` usa para `subject.has_whatsapp_identity`: el más
 *    reciente de la persona con conversación de WhatsApp y un número que la
 *    puerta sabe normalizar. Sin él, no hay identidad de WhatsApp (la puerta
 *    nunca crea leads ni conversaciones: §6.3, D9-9.3).
 *  - {@see markers()}: los valores de los marcadores CERRADOS de la plantilla
 *    que esta versión sabe resolver sin escribir nada ({@see RESOLVABLE}).
 *    Los demás salen en `missing` y el canal NO envía
 *    (`template_marker_unresolvable`, falla cerrado):
 *      · `class_name`, `class_time_text`: la ocurrencia de la clase no viaja
 *        en el sello del trabajo;
 *      · `payment_link`: crear un enlace de pago escribiría en las tablas de
 *        pagos, que el H9 nunca escribe (§6.3);
 *      · `plan_price`: el precio del catálogo (R-01) pide elegir el plan
 *        vendible y su formato; queda para cuando el propietario lo decida.
 *
 * DATOS PERSONALES (protección 10). Los valores (el nombre de pila, el plan,
 * la fecha) salen SOLO hacia la puerta de WhatsApp —el mensaje de Marketing,
 * que es lo que recibe la persona y lo que ve el Inbox—; nunca se guardan en
 * `outreach_*`, ni en trazas, ni en registros.
 */
final class WhatsappRecipient
{
    public const TABLES = ['members', 'marketing_leads', 'marketing_conversations', 'users'];

    /** Los marcadores de `StudioMessageTemplates::MARKERS` que W9 resuelve (los demás: `missing`). */
    public const RESOLVABLE = ['days_to_expiry', 'first_name', 'gym_name', 'membership_end_date_text', 'plan_name'];

    /** Un valor de marcador que viaja a Meta: corto, sin saltos ni control (la plantilla lo vuelve a validar). */
    private const VALUE_MAX = 60;

    private const MONTHS = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
        7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    public function __construct(private readonly SubjectResolver $resolver = new SubjectResolver) {}

    /** Todos los prospectos de la persona (los entrantes llegan por cualquiera de ellos). @return list<int> */
    public function leadIdsOf(string $subjectKey): array
    {
        $s = $this->subject($subjectKey);

        return $s === null ? [] : array_values(array_map('intval', $s->leadIds));
    }

    /** El prospecto de WhatsApp de la persona (la regla de `LeadFacts`), o null. */
    public function leadFor(string $subjectKey): ?int
    {
        $s = $this->subject($subjectKey);
        if ($s === null || $s->leadIds === []) {
            return null;
        }
        $conWhatsapp = DB::table('marketing_conversations')->whereIn('lead_id', $s->leadIds)->where('channel', 'whatsapp')
            ->distinct()->pluck('lead_id')->map(static fn ($id): int => (int) $id)->all();
        if ($conWhatsapp === []) {
            return null;
        }
        $elegido = null;
        foreach (DB::table('marketing_leads')->whereIn('id', $conWhatsapp)->orderBy('id')->get(['id', 'phone']) as $l) {
            if (PhoneKey::normalize($l->phone) !== null) {
                $elegido = (int) $l->id;
            }
        }

        return $elegido;
    }

    /**
     * Los valores de los marcadores pedidos para esta persona en este instante.
     *
     * @param  list<string>  $markers
     * @return array{values: array<string, string>, missing: list<string>}
     */
    public function markers(string $subjectKey, ?int $leadId, array $markers, DateTimeInterface $now, string $gymName): array
    {
        $s = $this->subject($subjectKey);
        $usuario = $s?->primaryUserId !== null
            ? DB::table('users')->where('id', $s->primaryUserId)->first(['id', 'name', 'plan', 'membership_end_date', 'membership_frozen_at'])
            : null;
        $lead = $leadId !== null ? DB::table('marketing_leads')->where('id', $leadId)->first(['id', 'name']) : null;
        $fin = $usuario !== null && $usuario->membership_frozen_at === null ? FactValues::date($usuario->membership_end_date) : null;

        $valores = [];
        $faltan = [];
        foreach (array_values(array_unique($markers)) as $m) {
            $v = match ($m) {
                'first_name' => self::firstName($usuario?->name) ?? self::firstName($lead?->name),
                'gym_name' => self::plain($gymName),
                'plan_name' => self::plain(is_string($usuario?->plan) ? $usuario->plan : null),
                'membership_end_date_text' => $fin !== null ? self::dateText($fin) : null,
                'days_to_expiry' => $fin !== null && RelativeTime::daysUntilDate($fin, $now) >= 0 ? (string) RelativeTime::daysUntilDate($fin, $now) : null,
                default => null,
            };
            if ($v === null) {
                $faltan[] = (string) $m;
            } else {
                $valores[(string) $m] = $v;
            }
        }

        return ['values' => $valores, 'missing' => $faltan];
    }

    /** «15 de noviembre de 2026» (el día de Bogotá de la membresía, sin hora). */
    public static function dateText(string $date): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return (int) $m[3].' de '.self::MONTHS[(int) $m[2]].' de '.$m[1];
    }

    /**
     * El nombre de pila: la primera palabra, solo letras (con apóstrofo o
     * guion), de 2 a 30, con mayúscula inicial. Un apodo con emojis, cifras o
     * símbolos no se usa (mejor ningún nombre que uno raro).
     */
    public static function firstName(?string $name): ?string
    {
        $limpio = trim((string) $name);
        if ($limpio === '' || ! mb_check_encoding($limpio, 'UTF-8')) {
            return null;
        }
        $primera = preg_split('/\s+/u', $limpio)[0] ?? '';
        if (preg_match("/^\\p{L}[\\p{L}'’-]{1,29}$/u", $primera) !== 1) {
            return null;
        }

        return mb_convert_case(mb_strtolower($primera, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    /** Un texto corto y limpio (sin control ni espacios repetidos), o null. */
    private static function plain(?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '' || ! mb_check_encoding($v, 'UTF-8') || mb_strlen($v) > self::VALUE_MAX || preg_match('/[\x00-\x1F\x7F]/u', $v) === 1) {
            return null;
        }

        return (string) preg_replace('/\s{2,}/u', ' ', $v);
    }

    private function subject(string $subjectKey): ?Subject
    {
        if (! SubjectKey::isValid($subjectKey)) {
            return null;
        }
        $s = $this->resolver->forKeys([$subjectKey])[$subjectKey] ?? null;

        return $s !== null && $s->resolved() ? $s : null;
    }
}
