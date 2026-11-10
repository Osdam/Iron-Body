<?php

namespace App\Services\Outreach\Core;

use App\Services\Telephony\VoiceFrequency;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * El PRESUPUESTO DE CONTACTO omnicanal por persona (contrato H9, D9-7
 * «Presupuesto de contacto omnicanal», [P9-8]; Ley 2300, art. 3; fixture
 * `contact-budget-cases.json`). PURO: el historial y «ahora» entran como
 * argumentos. Alimenta N1 de {@see PolicyLadder}.
 *
 * QUÉ CUENTA (DF-B1): `outreach` (salientes del H9 en `live`, también los
 * asistidos, por su medio), `ai_proactive` (`sender_type = ai` con
 * `metadata.kind ≠ reply`, como `ContactPolicy`) y `voice_call` (las salientes
 * de `voice_calls` en `VoiceFrequency::COUNTED_STATUSES`, que el adaptador ya
 * filtra). NUNCA: `ai_reply`, `inbound` ni `simulated`. Lo posterior a «ahora»
 * no cuenta. Una fila con otra fuente es un error de quien llama (se lanza).
 *
 * LOS MOTIVOS (en {@see REASON_ORDER}; todos TEMPORALES: si niega, aplaza).
 * Solo la LEY y la frecuencia: el horario propio es de N5 (L9-15).
 *  - el calendario de N1 ({@see ContactCalendar::window()}, sin horario propio);
 *  - `daily_contact_limit`: ya hubo uno hoy (día de Bogotá), sumando canales y
 *    finalidades; se suelta a las 00:00 de Bogotá del día siguiente (DF-B2).
 *    TAMBIÉN POR TELÉFONO (L9-27, L9-30): si alguno de los teléfonos de la
 *    persona (`phone_refs`, la lista completa) ya tuvo un contacto hoy, de quien
 *    sea (el teléfono no une personas, pero ocupa el día). Con el teléfono
 *    desconocido (`refs = []`) o sin el dato (`$phone` null) no se cruza;
 *  - `weekly_contact_limit`: el tope de los 7 días móviles CERRADOS
 *    `[ahora − 7 d, ahora]` (DF-B3, como `ContactPolicy`); se suelta 7 d + 1 s
 *    después del contacto que ocupa el puesto del tope;
 *  - `min_hours_between_contacts`: menos de las horas mínimas desde el último
 *    (a las 48 h justas ya no niega, como `ContactPolicy`; DF-B4);
 *  - `cross_channel_week_rule` (comercial y servicio) o
 *    `collections_channel_week_rule` (cobranza): en `[ahora − 7 d, ahora]` hubo
 *    un contacto por OTRO medio (DF-B5; cualquier finalidad: lectura estricta).
 * `releaseAt` dice cuándo deja de aplicar cada motivo del presupuesto (los del
 * calendario no lo llevan) y `retryAt` es el primer hueco LEGAL a partir del
 * más tardío entre «ahora» y esos instantes (DF-B6); la escalera calcula el
 * suyo con el horario propio y el de la estrategia (DF-L3).
 *
 * LÍMITES EFECTIVOS ({@see effectiveLimits()}, DF-B7): 1 al día siempre;
 * a la semana `min(commercial.max_proactive_per_week,
 * VoiceFrequency::perWeekFromConfig())` y el del H9 solo lo baja (≥ 1); las
 * horas mínimas son las del motor comercial y el H9 solo las sube. Un valor no
 * entero se ignora.
 *
 * Paridad (T9, DF-B8): con historiales solo de WhatsApp, todo lo que
 * `ContactPolicy` niega por frecuencia u horario, esto también lo niega.
 */
final class ContactBudget
{
    public const PER_DAY = 1;

    /** Los valores de hoy (la configuración del motor y de la voz). */
    public const DEFAULT_PER_WEEK = 2;

    public const DEFAULT_MIN_HOURS = 48;

    public const WEEK_SECONDS = 7 * 86400;

    public const COUNTED_SOURCES = ['ai_proactive', 'outreach', 'voice_call'];

    public const NOT_COUNTED_SOURCES = ['ai_reply', 'inbound', 'simulated'];

    public const DAILY_LIMIT = 'daily_contact_limit';

    public const WEEKLY_LIMIT = 'weekly_contact_limit';

    public const MIN_HOURS = 'min_hours_between_contacts';

    public const CROSS_CHANNEL = 'cross_channel_week_rule';

    public const COLLECTIONS_CHANNEL = 'collections_channel_week_rule';

    /** Las claves de la entrada del teléfono (L9-30), ordenadas. */
    public const PHONE_KEYS = ['contacts', 'refs'];

    public const REASON_ORDER = [
        ContactCalendar::OUTSIDE_LEGAL_HOURS, ContactCalendar::SUNDAY, ContactCalendar::HOLIDAY,
        self::DAILY_LIMIT, self::WEEKLY_LIMIT, self::MIN_HOURS, self::CROSS_CHANNEL, self::COLLECTIONS_CHANNEL,
    ];

    /**
     * @param  list<array{at: string, medium: string, purpose: string, source: string}>  $history
     * @param  array{perDay: int, perWeek: int, minHoursBetween: int}  $limits  los efectivos ({@see effectiveLimits()})
     * @param  array{refs: list<string>, contacts: list<array{at: string, phoneRef: string}>}|null  $phone  el tope por teléfono ({@see phoneToday()})
     *
     * @throws InvalidArgumentException con una fila, un medio, una finalidad, unos límites o un teléfono que no tienen su forma
     */
    public static function check(array $history, DateTimeInterface $now, string $purpose, string $medium, array $limits, ?array $phone = null): BudgetVerdict
    {
        if (! in_array($medium, OutreachCatalog::MEDIUMS, true) || ! in_array($purpose, OutreachCatalog::PURPOSES, true)) {
            throw new InvalidArgumentException('Medio o finalidad fuera del conjunto cerrado.');
        }
        $porDia = $limits['perDay'] ?? null;
        $porSemana = $limits['perWeek'] ?? null;
        $horas = $limits['minHoursBetween'] ?? null;
        if (! is_int($porDia) || $porDia < 1 || ! is_int($porSemana) || $porSemana < 1 || ! is_int($horas) || $horas < 0) {
            throw new InvalidArgumentException('Los límites son {perDay ≥ 1, perWeek ≥ 1, minHoursBetween ≥ 0}.');
        }
        $ahora = RelativeTime::utc($now);
        $contactos = self::counted($history, $ahora);
        $hoy = RelativeTime::bogotaDate($ahora);
        $desdeSemana = $ahora->getTimestamp() - self::WEEK_SECONDS;

        $deHoy = 0;
        $semana = [];
        foreach ($contactos as $c) {
            if (RelativeTime::bogotaDate($c['at']) === $hoy) {
                $deHoy++;
            }
            if ($c['at']->getTimestamp() >= $desdeSemana) {
                $semana[] = $c;
            }
        }

        $telefonoHoy = self::phoneToday($phone, $ahora, $hoy);

        $suelta = [];
        if ($deHoy >= $porDia || $telefonoHoy >= $porDia) {
            $suelta[self::DAILY_LIMIT] = RelativeTime::utc(RelativeTime::local($ahora)->setTime(0, 0)->modify('+1 day'));
        }
        if (count($semana) >= $porSemana) {
            // Para bajar del tope tiene que salir el contacto que ocupa el puesto `perWeek` (del más nuevo al más viejo).
            $suelta[self::WEEKLY_LIMIT] = $semana[$porSemana - 1]['at']->modify('+7 days +1 second');
        }
        $ultimo = $contactos[0]['at'] ?? null;
        if ($ultimo !== null && $ahora->getTimestamp() - $ultimo->getTimestamp() < $horas * 3600) {
            $suelta[self::MIN_HOURS] = $ultimo->modify("+{$horas} hours");
        }
        $otroMedio = null;
        foreach ($semana as $c) {
            if ($c['medium'] !== $medium && ($otroMedio === null || $c['at'] > $otroMedio)) {
                $otroMedio = $c['at'];
            }
        }
        if ($otroMedio !== null) {
            $suelta[$purpose === OutreachCatalog::PURPOSE_COLLECTIONS ? self::COLLECTIONS_CHANNEL : self::CROSS_CHANNEL] = $otroMedio->modify('+7 days +1 second');
        }

        $calendario = ContactCalendar::window($ahora, null)['n1'];
        $motivos = array_values(array_filter(self::REASON_ORDER, static fn (string $m): bool => in_array($m, $calendario, true) || array_key_exists($m, $suelta)));
        $reintento = null;
        if ($motivos !== []) {
            $desde = $ahora;
            foreach ($suelta as $instante) {
                $desde = max($desde, $instante);
            }
            $reintento = ContactCalendar::nextSlot($desde, null);
        }
        $releaseAt = [];
        foreach (self::REASON_ORDER as $m) {
            if (isset($suelta[$m])) {
                $releaseAt[$m] = RelativeTime::format($suelta[$m]);
            }
        }

        return new BudgetVerdict($motivos, $releaseAt, $reintento === null ? null : RelativeTime::format($reintento), ['today' => $deHoy, 'rolling7' => count($semana)]);
    }

    /**
     * Los límites efectivos (DF-B7) de la configuración: `commercial_per_week`,
     * `commercial_min_hours`, `voice_per_week`, `h9_per_week` y `h9_min_hours`
     * (los ausentes, con los valores de hoy).
     *
     * @param  array<string, mixed>  $config
     * @return array{perDay: int, perWeek: int, minHoursBetween: int}
     */
    public static function effectiveLimits(array $config = []): array
    {
        $comercialSemana = is_int($config['commercial_per_week'] ?? null) ? $config['commercial_per_week'] : self::DEFAULT_PER_WEEK;
        $voz = is_int($config['voice_per_week'] ?? null) ? min(VoiceFrequency::PER_WEEK_DEFAULT, max(1, $config['voice_per_week'])) : VoiceFrequency::PER_WEEK_DEFAULT;
        $porSemana = min($comercialSemana, $voz);
        if (is_int($config['h9_per_week'] ?? null)) {
            $porSemana = min($porSemana, max(1, $config['h9_per_week']));
        }
        $horas = is_int($config['commercial_min_hours'] ?? null) ? max(0, $config['commercial_min_hours']) : self::DEFAULT_MIN_HOURS;
        if (is_int($config['h9_min_hours'] ?? null)) {
            $horas = max($horas, $config['h9_min_hours']);
        }

        return ['perDay' => self::PER_DAY, 'perWeek' => max(1, $porSemana), 'minHoursBetween' => $horas];
    }

    /**
     * Cuántos contactos tuvieron HOY (día de Bogotá, hasta «ahora») los
     * teléfonos de la persona (L9-27, L9-30). `refs` son sus `phone_refs` de la
     * foto (64 hex; `[]` = desconocido: no se cruza) y `contacts` los contactos
     * a esos teléfonos que lee el adaptador (`{at, phoneRef}`, de cualquier
     * persona: el trabajo guarda su `phone_ref`). Un contacto a un teléfono que
     * no es de la persona no cuenta; uno posterior a «ahora», tampoco. Solo el
     * tope diario: ni la semana ni las horas mínimas, que son de la persona.
     *
     * @throws InvalidArgumentException si no tiene esa forma
     */
    private static function phoneToday(?array $phone, DateTimeImmutable $ahora, string $hoy): int
    {
        if ($phone === null) {
            return 0;
        }
        $claves = array_map('strval', array_keys($phone));
        sort($claves, SORT_STRING);
        $refs = $phone['refs'] ?? null;
        $contactos = $phone['contacts'] ?? null;
        if ($claves !== self::PHONE_KEYS || ! is_array($refs) || ! array_is_list($refs) || ! is_array($contactos) || ! array_is_list($contactos)) {
            throw new InvalidArgumentException('El teléfono es {refs, contacts}, dos listas.');
        }
        foreach ($refs as $i => $r) {
            if (! self::isPhoneRef($r)) {
                throw new InvalidArgumentException("El phone_ref {$i} no son 64 hex en minúsculas.");
            }
        }
        $n = 0;
        foreach ($contactos as $i => $c) {
            if (! is_array($c) || count($c) !== 2 || ! RelativeTime::isInstant($c['at'] ?? null) || ! self::isPhoneRef($c['phoneRef'] ?? null)) {
                throw new InvalidArgumentException("El contacto {$i} del teléfono es {at, phoneRef}.");
            }
            $en = RelativeTime::parseInstant($c['at']);
            if (in_array($c['phoneRef'], $refs, true) && $en <= $ahora && RelativeTime::bogotaDate($en) === $hoy) {
                $n++;
            }
        }

        return $n;
    }

    private static function isPhoneRef(mixed $r): bool
    {
        return is_string($r) && preg_match(OutreachCatalog::HEX64_PATTERN, $r) === 1;
    }

    /**
     * Los contactos que cuentan, del más nuevo al más viejo.
     *
     * @return list<array{at: DateTimeImmutable, medium: string}>
     */
    private static function counted(array $history, DateTimeImmutable $ahora): array
    {
        $out = [];
        foreach ($history as $i => $fila) {
            $fuente = is_array($fila) ? ($fila['source'] ?? null) : null;
            $cuenta = in_array($fuente, self::COUNTED_SOURCES, true);
            if (! $cuenta && ! in_array($fuente, self::NOT_COUNTED_SOURCES, true)) {
                throw new InvalidArgumentException("La fila {$i} del historial no tiene una fuente del conjunto cerrado.");
            }
            if (! in_array($fila['medium'] ?? null, OutreachCatalog::MEDIUMS, true) || ! RelativeTime::isInstant($fila['at'] ?? null)) {
                throw new InvalidArgumentException("La fila {$i} del historial necesita medio e instante.");
            }
            if (! $cuenta) {
                continue;
            }
            $en = RelativeTime::parseInstant($fila['at']);
            if ($en > $ahora) {
                continue;
            }
            $out[] = ['at' => $en, 'medium' => $fila['medium'], 'i' => $i];
        }
        // Del más nuevo al más viejo; a igual instante, el que llegó después primero (estable).
        usort($out, static fn (array $a, array $b): int => $b['at'] <=> $a['at'] ?: $b['i'] <=> $a['i']);

        return array_map(static fn (array $c): array => ['at' => $c['at'], 'medium' => $c['medium']], $out);
    }
}
