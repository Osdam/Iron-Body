<?php

namespace App\Services\Outreach\Run;

use App\Services\Outreach\Core\ContactBudget;
use App\Services\Outreach\Core\ContactCalendar;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Run\Classifier\ReplyClassifierPrompt;
use App\Services\Outreach\Run\Classifier\ReplyClassifierTransport;
use App\Services\Telephony\OutboundSchedule;

/**
 * Las LLAVES de la ejecución (contrato H9, §9.1, D9-14, L9-44): lo que
 * `config/outreach.php` dice, vuelto a comprobar al leerlo (una cadena no
 * enciende nada; un modo desconocido es `off`; un horario ilegible cierra).
 *
 * TODO NACE APAGADO (protección 1). Esta clase no toca la base ni la red: es
 * el único lector de `config('outreach.*')` fuera de las pruebas, salvo la
 * clave del clasificador (AI9), que solo lee su transporte, fuera del H9, en
 * el momento de llamar. La constante de código vive aparte, en
 * {@see OutreachSendGate}.
 */
final class OutreachConfig
{
    public const MODE_OFF = 'off';

    public const MODE_SHADOW = 'shadow';

    public const MODE_LIVE = 'live';

    public const MODES = [self::MODE_SHADOW, self::MODE_LIVE];

    /** El modo que se guarda en las filas: nunca `off` (con `off` no se escribe nada). */
    public static function enabled(): bool
    {
        return config('outreach.enabled') === true;
    }

    /** `off`, `shadow` o `live` (cualquier otra cosa, `off`). */
    public static function mode(): string
    {
        $modo = config('outreach.mode');

        return is_string($modo) && in_array($modo, self::MODES, true) ? $modo : self::MODE_OFF;
    }

    /** ¿Trabaja la ejecución? La maestra encendida y un modo distinto de `off`. */
    public static function active(): bool
    {
        return self::enabled() && self::mode() !== self::MODE_OFF;
    }

    public static function isShadow(): bool
    {
        return self::active() && self::mode() === self::MODE_SHADOW;
    }

    public static function isLive(): bool
    {
        return self::active() && self::mode() === self::MODE_LIVE;
    }

    public static function schedulerEnabled(): bool
    {
        return config('outreach.scheduler.enabled') === true;
    }

    public static function monitorEnabled(): bool
    {
        return config('outreach.monitor.enabled') === true;
    }

    public static function assistedEnabled(): bool
    {
        return config('outreach.assisted.enabled') === true;
    }

    public static function assistedDailyCap(): int
    {
        return self::int('outreach.assisted.daily_cap', 0, 0, 10000);
    }

    public static function whatsappEnabled(): bool
    {
        return config('outreach.whatsapp.enabled') === true;
    }

    public static function whatsappDailyCap(): int
    {
        return self::int('outreach.whatsapp.daily_cap', 0, 0, 100000);
    }

    /** El presupuesto diario de WhatsApp en MICROS de dólar (0 = sin presupuesto = `budget_zero`). */
    public static function whatsappDailyBudgetMicros(): int
    {
        return self::micros(config('outreach.whatsapp.daily_budget_usd'));
    }

    /** El precio de un mensaje de WhatsApp de esa categoría, en micros (0 = sin precio: la escalera lo trata como `budget_zero`). */
    public static function whatsappPriceMicros(string $category): int
    {
        return self::micros(config($category === 'utility' ? 'outreach.whatsapp.price_utility_usd' : 'outreach.whatsapp.price_marketing_usd'));
    }

    public static function voiceEnabled(): bool
    {
        return config('outreach.voice.enabled') === true;
    }

    /** El cupo diario de llamadas de la automatización (0–20; 0 = `budget_zero`). */
    public static function voiceDailyCap(): int
    {
        return self::int('outreach.voice.daily_cap', 0, 0, 20);
    }

    /** El techo diario de gasto de la voz en MICROS de dólar (0 = sin presupuesto = `budget_zero`). */
    public static function voiceDailyBudgetMicros(): int
    {
        return self::micros(config('outreach.voice.daily_budget_usd'));
    }

    /** Lo que se reserva por llamada al planificar, en micros (0 = sin reserva: ver {@see voiceBudget()}). */
    public static function voiceReserveMicros(): int
    {
        return self::micros(config('outreach.voice.reserve_usd_per_call'));
    }

    /**
     * El presupuesto EFECTIVO de la voz que usan la escalera (N4) y la reserva
     * del planificador (G-3…G-5 de V9): cupo, techo y reserva por llamada. Sin
     * reserva por llamada (0) el H9 no puede contar lo que gasta una llamada:
     * cupo y techo efectivos 0, la escalera niega `budget_zero` y la parcela no
     * cabe (falla cerrado, protección 12). El monitor compara con los techos
     * CONFIGURADOS ({@see voiceDailyCap()}, {@see voiceDailyBudgetMicros()}).
     *
     * @return array{dailyCap: int, dailyBudgetMicros: int, reserveMicros: int}
     */
    public static function voiceBudget(): array
    {
        $reserva = self::voiceReserveMicros();
        if ($reserva <= 0) {
            return ['dailyCap' => 0, 'dailyBudgetMicros' => 0, 'reserveMicros' => 0];
        }

        return ['dailyCap' => self::voiceDailyCap(), 'dailyBudgetMicros' => self::voiceDailyBudgetMicros(), 'reserveMicros' => $reserva];
    }

    public static function classifierEnabled(): bool
    {
        return config('outreach.classifier.enabled') === true;
    }

    /** AI9: llamadas al clasificador al día (0–2000; 0 = no clasifica). */
    public static function classifierDailyCap(): int
    {
        return self::int('outreach.classifier.daily_cap', 0, 0, 2000);
    }

    /** AI9: el techo diario del clasificador en MICROS de dólar (0 = no clasifica). */
    public static function classifierDailyBudgetMicros(): int
    {
        return self::micros(config('outreach.classifier.daily_budget_usd'));
    }

    /**
     * AI9: el modelo, o null si no hay uno con forma de nombre de modelo
     * (minúsculas, cifras, `.`, `_` y `-`, ≤ 36: viaja en la clave del
     * resultado). Sin modelo no se clasifica.
     */
    public static function classifierModel(): ?string
    {
        $m = config('outreach.classifier.model');

        return is_string($m) && preg_match('/^[a-z0-9][a-z0-9._-]{0,35}$/D', trim($m)) === 1 ? trim($m) : null;
    }

    /** AI9: el precio por millón de tokens de entrada (`input`) o de salida (`output`), en micros de dólar (0 = sin precio). */
    public static function classifierPriceMicrosPerMtok(string $kind): int
    {
        return self::micros(config($kind === 'input' ? 'outreach.classifier.price_input_usd_per_mtok' : 'outreach.classifier.price_output_usd_per_mtok'));
    }

    /** AI9: el tiempo máximo de UNA llamada (2–10 s; sin reintentos). */
    public static function classifierTimeoutSeconds(): int
    {
        return self::int('outreach.classifier.timeout_seconds', 5, 2, 10);
    }

    /** AI9: los tokens de salida de una llamada (16–256, incluido el razonamiento): el techo del coste de la salida. */
    public static function classifierMaxOutputTokens(): int
    {
        return self::int('outreach.classifier.max_output_tokens', 64, 16, 256);
    }

    /**
     * AI9 (M2 de security): las respuestas que una pasada de W9 le pasa al
     * clasificador (0–40; 0 = ninguna). Acota lo que la pasada puede esperar
     * a la red: el tope por el tiempo máximo de una llamada.
     */
    public static function classifierMaxPerPass(): int
    {
        return self::int('outreach.classifier.max_per_pass', 10, 0, 40);
    }

    /**
     * AI9 · lo que dice el panel del clasificador (sin la base ni la red y sin
     * secretos): que existe, su versión y su huella, la bandera, si hay
     * transporte enlazado y configurado (clave propia), el modelo, los techos,
     * el tope por pasada y si de verdad clasificaría ahora (`effective`: todo
     * lo anterior, cupo, techo y tope por pasada distintos de 0, y la
     * ejecución en vivo). El consumo del día lo añade la lectura del estado
     * (`OutreachReadModels`).
     *
     * @return array<string, mixed>
     */
    public static function classifierSetup(): array
    {
        $enlazado = app()->bound(ReplyClassifierTransport::class);
        $precios = self::classifierPriceMicrosPerMtok('input') > 0 && self::classifierPriceMicrosPerMtok('output') > 0;
        $configurado = $enlazado && self::classifierModel() !== null && $precios && app(ReplyClassifierTransport::class)->configured();

        return [
            'implemented' => true,
            'version' => ReplyClassifierPrompt::VERSION,
            'promptRef' => ReplyClassifierPrompt::ref(),
            'labels' => ReplyClassifierPrompt::LABELS,
            'enabled' => self::classifierEnabled(),
            'transport' => $enlazado,
            'configured' => $configurado,
            'model' => self::classifierModel(),
            'dailyCap' => self::classifierDailyCap(),
            'dailyBudgetMicros' => self::classifierDailyBudgetMicros(),
            'timeoutSeconds' => self::classifierTimeoutSeconds(),
            'maxPerPass' => self::classifierMaxPerPass(),
            'effective' => self::classifierEnabled() && $configurado && self::classifierDailyCap() > 0 && self::classifierDailyBudgetMicros() > 0 && self::classifierMaxPerPass() > 0 && self::isLive(),
        ];
    }

    /** El texto del horario propio tal como está en la configuración (para el panel). */
    public static function ownHoursRaw(): string
    {
        $v = config('outreach.own_hours');

        return is_string($v) ? trim($v) : '';
    }

    /**
     * El horario PROPIO con la forma de {@see ContactCalendar} (P9-17, L9-15):
     * null = sin horario propio (rige el legal); `{weekdays, saturday}` con
     * `[abre, cierra]` en minutos (o null = ese tipo de día cerrado); o
     * `ContactCalendar::OWN_INVALID` si está mal escrito (todo fuera: falla
     * cerrado, DF-W4). `legal` y vacío son «sin horario propio». Un horario
     * propio nunca amplía la ventana legal: el calendario lo interseca.
     *
     * @return array{weekdays: array{0:int,1:int}|null, saturday: array{0:int,1:int}|null}|string|null
     */
    public static function ownHours(): array|string|null
    {
        $raw = self::ownHoursRaw();
        if ($raw === '' || strtolower($raw) === 'legal') {
            return null;
        }
        $tramos = explode(';', $raw, 2);
        $semana = trim($tramos[0]);
        $sabado = isset($tramos[1]) ? trim($tramos[1]) : $semana;
        // `-` como tramo cierra ese tipo de día; el lector del H8 (`OutboundSchedule::window`) valida `HH:MM-HH:MM`.
        $ventanas = OutboundSchedule::windows($semana === '-' ? '' : $semana, $sabado === '-' ? '' : $sabado);
        if ($ventanas === false) {
            return ContactCalendar::OWN_INVALID;
        }
        if ($ventanas === null) {
            // Los dos tramos cerrados a propósito: no hay día permitido (falla cerrado, no «sin horario»).
            return ContactCalendar::OWN_INVALID;
        }

        return $ventanas;
    }

    /**
     * Los límites efectivos del presupuesto de contacto (D9-7, DF-B7): el
     * suelo del motor comercial y la voz, endurecido por el H9 si lo pide.
     *
     * @return array{perDay: int, perWeek: int, minHoursBetween: int}
     */
    public static function effectiveLimits(): array
    {
        $entero = static fn (mixed $v): ?int => is_int($v) ? $v : (is_string($v) && preg_match('/^\d+$/', $v) === 1 ? (int) $v : null);

        return ContactBudget::effectiveLimits(array_filter([
            'commercial_per_week' => $entero(config('commercial.contact_limits.max_proactive_per_week')),
            'commercial_min_hours' => $entero(config('commercial.contact_limits.min_hours_between')),
            'voice_per_week' => $entero(config('voice.outbound.frequency.per_week')),
            'h9_per_week' => $entero(config('outreach.limits.per_week')),
            'h9_min_hours' => $entero(config('outreach.limits.min_hours_between')),
        ], static fn (?int $v): bool => $v !== null));
    }

    /** P9-7, L9-20: el umbral del servidor del bloqueo comercial por cartera vencida (días). */
    public static function collectionsBlockDays(): int
    {
        return self::int('outreach.collections.commercial_block_days', 15, 0, 365);
    }

    /** L9-41: `human_requested` reciente, en días. */
    public static function humanRequestedDays(): int
    {
        return self::int('outreach.human_requested_days', 30, 1, 365);
    }

    /** Q1-8 (NB3): las quejas que una cuenta registra al día mientras `outreach.complaints.per_account_per_day` no valga. */
    public const STAFF_COMPLAINTS_PER_ACCOUNT_PER_DAY_DEFAULT = 20;

    /**
     * Q1-8 (NB3 de la revisión de API): el tope de quejas que una cuenta del
     * personal puede registrar en un día de Bogotá. Un entero de 1 a 200
     * manda (más de 200 = 200); cualquier otra cosa (nada, 0, un negativo, un
     * texto) deja {@see STAFF_COMPLAINTS_PER_ACCOUNT_PER_DAY_DEFAULT}: no hay
     * forma de quitar el tope por configuración.
     */
    public static function staffComplaintsPerAccountPerDay(): int
    {
        $v = config('outreach.complaints.per_account_per_day');

        return is_int($v) && $v >= 1 ? min(200, $v) : self::STAFF_COMPLAINTS_PER_ACCOUNT_PER_DAY_DEFAULT;
    }

    /** Q1-3: los días de la ventana de la queja mientras `outreach.complaint_days` no exista o no valga. */
    public const COMPLAINT_DAYS_DEFAULT = 30;

    /**
     * Q1-3 (N6 `complaint_recent`, DF-L14): la ventana de la queja reciente,
     * en días de Bogotá. {@see COMPLAINT_DAYS_DEFAULT} hasta que el propietario
     * decida (D2 del plan de quejas); propia, no la de `human_requested`:
     * acortar una no acorta la otra (M-1 de security).
     * `outreach.complaint_days` todavía no existe en `config/outreach.php`: si
     * llega a existir y es un entero de 0 a 365, manda (más de 365 = 365).
     * SOLO 0 apaga el freno: un negativo, un texto o nada dejan el valor por
     * defecto (el lado seguro).
     */
    public static function complaintDays(): int
    {
        $v = config('outreach.complaint_days');

        return is_int($v) && $v >= 0 ? min(365, $v) : self::COMPLAINT_DAYS_DEFAULT;
    }

    /**
     * Q1-3 (DF-L14): las finalidades a las que frena una queja. Por defecto,
     * TODAS las del catálogo menos los recordatorios de servicio (hoy,
     * cobranza y comercial; D3 del plan): una finalidad nueva nace frenada.
     * `outreach.complaint_purposes` todavía no existe: si llega a existir,
     * solo vale una lista de finalidades del catálogo que incluya la
     * comercial; cualquier otra cosa (vacía, sin la comercial, un nombre mal
     * escrito, algo que no es una lista de textos) se ignora y queda el valor
     * por defecto (M-1 de security: ante una mala configuración se frena). El
     * freno se apaga con `complaint_days = 0`, no vaciando esta lista.
     *
     * @return list<string>
     */
    public static function complaintPurposes(): array
    {
        $v = config('outreach.complaint_purposes');
        if (is_array($v) && array_is_list($v) && array_filter($v, 'is_string') === $v
            && array_diff($v, OutreachCatalog::PURPOSES) === [] && in_array(OutreachCatalog::PURPOSE_COMMERCIAL, $v, true)) {
            return array_values(array_intersect(OutreachCatalog::PURPOSES, $v));
        }

        return array_values(array_diff(OutreachCatalog::PURPOSES, [OutreachCatalog::PURPOSE_SERVICE]));
    }

    /**
     * Los umbrales del monitor. `classifierFailures` (AI9, C4 de security):
     * las llamadas SEGUIDAS del clasificador sin salida usable a partir de las
     * que abre un incidente, sin freno.
     *
     * @return array{optOutPermille: int, failurePermille: int, minSample: int, classifierFailures: int}
     */
    public static function monitorThresholds(): array
    {
        return [
            'optOutPermille' => self::int('outreach.monitor.opt_out_threshold_permille', 50, 1, 1000),
            'failurePermille' => self::int('outreach.monitor.failure_threshold_permille', 200, 1, 1000),
            'minSample' => self::int('outreach.monitor.min_sample', 5, 1, 1000),
            'classifierFailures' => self::int('outreach.monitor.classifier_failures', 5, 1, 1000),
        ];
    }

    public static function sweepBatch(): int
    {
        return self::int('outreach.sweep.batch', 500, 1, 500);
    }

    public static function dispatchBatch(): int
    {
        return self::int('outreach.dispatch.batch', 50, 1, 50);
    }

    public static function staleMinutes(): int
    {
        return self::int('outreach.reconcile.stale_minutes', 10, 1, 1440);
    }

    /** El carril de cola del despacho (`config/queue.php` → `lanes.<carril>`). */
    public static function queueLane(): string
    {
        $carril = config('outreach.queue.lane');

        return is_string($carril) && preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $carril) === 1 ? $carril : 'commercial';
    }

    /** ¿Está encendido el canal de este trabajo (`simulated` | `assisted` | `whatsapp_template` | `voice_call`)? El simulado siempre. */
    public static function channelEnabled(string $jobChannel): bool
    {
        return match ($jobChannel) {
            'simulated' => true,
            'assisted' => self::assistedEnabled(),
            OutreachCatalog::CHANNEL_WHATSAPP_TEMPLATE => self::whatsappEnabled(),
            OutreachCatalog::CHANNEL_VOICE_CALL => self::voiceEnabled(),
            default => false,
        };
    }

    /**
     * El bloque de banderas del panel (`GET outreach/status`): nada de secretos.
     *
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        $horario = self::ownHours();

        return [
            'enabled' => self::enabled(),
            'mode' => self::mode(),
            'active' => self::active(),
            'scheduler' => self::schedulerEnabled(),
            'monitor' => self::monitorEnabled(),
            'sendsEnabledInCode' => OutreachSendGate::sendsEnabledInCode(),
            'channels' => [
                'assisted' => ['enabled' => self::assistedEnabled(), 'live' => OutreachSendGate::channelLive('assisted'), 'dailyCap' => self::assistedDailyCap()],
                'whatsapp_template' => ['enabled' => self::whatsappEnabled(), 'live' => OutreachSendGate::channelLive(OutreachCatalog::CHANNEL_WHATSAPP_TEMPLATE), 'dailyCap' => self::whatsappDailyCap(), 'dailyBudgetMicros' => self::whatsappDailyBudgetMicros()],
                'voice_call' => ['enabled' => self::voiceEnabled(), 'live' => OutreachSendGate::channelLive(OutreachCatalog::CHANNEL_VOICE_CALL)],
            ],
            'classifier' => self::classifierEnabled(),
            // AI9 (aditivo): `classifier` sigue siendo la bandera; esto dice si existe, si está listo y con qué techos.
            'classifierSetup' => self::classifierSetup(),
            'ownHours' => ['raw' => self::ownHoursRaw(), 'valid' => $horario !== ContactCalendar::OWN_INVALID, 'legal' => $horario === null],
            'limits' => self::effectiveLimits(),
            'collectionsBlockDays' => self::collectionsBlockDays(),
        ];
    }

    private static function int(string $key, int $default, int $min, int $max): int
    {
        $v = config($key);

        return is_int($v) ? min($max, max($min, $v)) : $default;
    }

    /** Un importe en dólares (texto o número con ≤ 6 decimales) → micros; cualquier otra cosa, 0. */
    private static function micros(mixed $usd): int
    {
        if (is_int($usd)) {
            return $usd >= 0 ? $usd * 1_000_000 : 0;
        }
        if (! is_string($usd) || preg_match('/^(\d{1,6})(?:\.(\d{1,6}))?$/', trim($usd), $m) !== 1) {
            return 0;
        }

        return (int) $m[1] * 1_000_000 + (int) str_pad($m[2] ?? '0', 6, '0');
    }
}
