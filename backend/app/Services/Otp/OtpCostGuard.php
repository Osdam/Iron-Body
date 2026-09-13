<?php

namespace App\Services\Otp;

use App\Exceptions\OtpException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Techo financiero propio para el gasto en SMS.
 *
 * Es la última red: aunque fallen el reuso, el candado y todos los límites, el
 * gasto de un día no puede dispararse. Vive en la cache COMPARTIDA (en producción
 * `database`), de modo que sobrevive a un reinicio, a un despliegue y a varios
 * workers a la vez — un contador en memoria del proceso no serviría de nada.
 *
 * El importe se guarda en diezmilésimas de dólar como ENTERO, porque los
 * contadores atómicos de la cache son enteros y 0,0592 USD no sobrevive a una
 * suma en coma flotante repetida cientos de veces.
 *
 * IMPORTANTE: esto es una ESTIMACIÓN para decidir en caliente, no contabilidad.
 * La cifra facturada real siempre es la de los Usage Records de Twilio; los
 * precios de config/otp.php son la referencia local para poder frenar sin tener
 * que preguntarle a Twilio en cada petición.
 */
class OtpCostGuard
{
    /** Diezmilésimas de dólar por unidad: 0,0592 USD → 592. */
    private const ESCALA = 10000;

    /** Tope del `retry_after` que se devuelve al cliente (s). */
    private const RETRY_AFTER_MAX = 1800;

    /**
     * ¿Se puede iniciar una operación que gastaría dinero?
     *
     * @throws OtpException 503 si el interruptor está bajado o se tocó el techo duro.
     */
    public function assertCanSend(): void
    {
        if (! (bool) config('otp.send_enabled', true)) {
            throw $this->noDisponible('kill_switch', 'El envío de códigos está temporalmente suspendido.');
        }

        $gastado = $this->spentToday();
        $duro = (float) config('otp.cost.daily_hard', 12.0);

        if ($duro > 0 && $gastado >= $duro) {
            Log::warning('otp.cost_guard.hard_limit', [
                'estimated_spent_today_usd' => round($gastado, 4),
                'daily_hard_limit_usd' => $duro,
            ]);

            throw $this->noDisponible(
                'temporarily_unavailable',
                'No podemos enviar códigos en este momento. Inténtalo más tarde.',
            );
        }
    }

    /** Suma el coste ESTIMADO de un SMS recién disparado. */
    public function recordSmsStart(): void
    {
        $this->add((float) config('otp.cost.sms_start', 0.0592));
    }

    /** Suma el coste ESTIMADO de una verificación completada (Twilio solo cobra esas). */
    public function recordVerification(): void
    {
        $this->add((float) config('otp.cost.verification', 0.05));
    }

    /** Gasto ESTIMADO acumulado hoy, en dólares. */
    public function spentToday(): float
    {
        return ((int) (Cache::get($this->claveDia()) ?? 0)) / self::ESCALA;
    }

    /** Fotografía para métricas y para el informe operativo. */
    public function snapshot(): array
    {
        $gastado = $this->spentToday();

        return [
            'date' => $this->hoy(),
            'estimated_spent_usd' => round($gastado, 4),
            'daily_soft_limit_usd' => (float) config('otp.cost.daily_soft', 6.0),
            'daily_hard_limit_usd' => (float) config('otp.cost.daily_hard', 12.0),
            'send_enabled' => (bool) config('otp.send_enabled', true),
        ];
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    private function add(float $usd): void
    {
        $unidades = (int) round($usd * self::ESCALA);
        if ($unidades <= 0) {
            return;
        }

        $clave = $this->claveDia();
        // El contador atómico exige que la clave exista: `add` no pisa el valor
        // si otro worker la creó primero.
        Cache::add($clave, 0, now()->endOfDay()->addHour());
        Cache::increment($clave, $unidades);

        $this->avisarSiCruzaElBlando();
    }

    /** El aviso del límite blando se emite UNA vez al día, no en cada envío. */
    private function avisarSiCruzaElBlando(): void
    {
        $blando = (float) config('otp.cost.daily_soft', 6.0);
        if ($blando <= 0 || $this->spentToday() < $blando) {
            return;
        }

        // `add` devuelve false si la marca ya existe: así solo avisa el primero.
        if (! Cache::add('otp:cost:soft-notified:'.$this->hoy(), 1, now()->endOfDay()->addHour())) {
            return;
        }

        Log::warning('otp.cost_guard.soft_limit', [
            'estimated_spent_today_usd' => round($this->spentToday(), 4),
            'daily_soft_limit_usd' => $blando,
            'daily_hard_limit_usd' => (float) config('otp.cost.daily_hard', 12.0),
        ]);
    }

    /**
     * Degradación controlada: 503 con `retry_after`, nunca un 500 ni un timeout.
     * Las sesiones vivas y la comprobación de códigos ya enviados no pasan por
     * aquí, así que siguen funcionando.
     */
    private function noDisponible(string $code, string $mensaje): OtpException
    {
        return new OtpException($mensaje, 503, [
            'code' => $code,
            'retry_after' => min((int) now()->diffInSeconds(now()->endOfDay()), self::RETRY_AFTER_MAX),
        ]);
    }

    private function claveDia(): string
    {
        return 'otp:cost:'.$this->hoy();
    }

    private function hoy(): string
    {
        return now()->format('Y-m-d');
    }
}
