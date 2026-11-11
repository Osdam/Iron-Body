<?php

namespace App\Services\Outreach\Run;

use App\Services\Outreach\Core\ContactBudget;
use App\Services\Outreach\Core\ContactCalendar;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Telephony\OutboundSchedule;

/**
 * Las LLAVES de la ejecución (contrato H9, §9.1, D9-14, L9-44): lo que
 * `config/outreach.php` dice, vuelto a comprobar al leerlo (una cadena no
 * enciende nada; un modo desconocido es `off`; un horario ilegible cierra).
 *
 * TODO NACE APAGADO (protección 1). Esta clase no toca la base ni la red: es
 * el único lector de `config('outreach.*')` fuera de las pruebas. La
 * constante de código vive aparte, en {@see OutreachSendGate}.
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

    /** @return array{optOutPermille: int, failurePermille: int, minSample: int} */
    public static function monitorThresholds(): array
    {
        return [
            'optOutPermille' => self::int('outreach.monitor.opt_out_threshold_permille', 50, 1, 1000),
            'failurePermille' => self::int('outreach.monitor.failure_threshold_permille', 200, 1, 1000),
            'minSample' => self::int('outreach.monitor.min_sample', 5, 1, 1000),
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
