<?php

namespace App\Console\Commands\Voice;

use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\VoiceAmbiguousResolution;
use App\Services\Telephony\VoiceEvents;
use App\Services\Voice\Dto\VoiceIds;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `voice:resolve-ambiguous` — la salida MANUAL de una saliente `ambiguous` que
 * no salió (Enmienda del 22-11-2026, E19-3; H-1; {@see VoiceAmbiguousResolution}).
 * Solo consola: no hay ruta. La decide una persona que ya comprobó que la
 * llamada no existe (el runbook, tabla D), con su motivo.
 *
 * Se NIEGA (sale con 1, sin tocar nada) si la llamada no existe o no es una
 * saliente `ambiguous`, si es más joven que el plazo de gracia
 * ({@see VoiceAmbiguousResolution::GRACE_MINUTES} min desde que quedó
 * ambigua), si algún evento suyo trae un id del proveedor (pudo salir: la
 * resuelve la conciliación por su SID), sin `--confirm`, con un motivo que no
 * tiene forma (de 10 a 200 caracteres, sin caracteres de control, sin
 * teléfonos ni correos) o mientras el esquema no admita la salida (la
 * migración E19-3).
 *
 * Si no, la fila pasa a `failed_to_start` (la reserva se libera) con su evento
 * `reconciled` (`ambiguous_resolved`), el motivo y quién (la consola) como
 * evidencia. Imprime uuids y estados, nunca números.
 */
class VoiceResolveAmbiguousCommand extends Command
{
    public const REASON_MIN = 10;

    public const REASON_MAX = 200;

    public const ACTOR_NAME = 'Consola (voice:resolve-ambiguous)';

    protected $signature = 'voice:resolve-ambiguous
        {call : el uuid de la llamada ambigua}
        {--reason= : por qué se da por no iniciada (obligatorio: de 10 a 200 caracteres, sin teléfonos ni correos)}
        {--confirm : obligatorio}';

    protected $description = 'Cierra como no iniciada (failed_to_start) una saliente ambiguous que no salió (E19-3), con su motivo y su evento. Se niega si es más joven que el plazo de gracia.';

    public function handle(): int
    {
        $uuid = strtolower(trim((string) $this->argument('call')));
        if (! VoiceIds::isUuid($uuid)) {
            $this->error('Indica el uuid de la llamada.');

            return self::FAILURE;
        }
        $motivo = self::reason($this->option('reason'));
        if ($motivo === null) {
            $this->error('--reason es obligatorio: de '.self::REASON_MIN.' a '.self::REASON_MAX.' caracteres, sin caracteres de control, sin teléfonos ni correos.');

            return self::FAILURE;
        }
        if (! $this->option('confirm')) {
            $this->error('Esto da la llamada por NO iniciada y libera su reserva: repite con --confirm.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($uuid, $motivo): int {
            $fila = VoiceCall::query()->where('uuid', $uuid)->lockForUpdate()->first();
            if ($fila === null) {
                $this->error('Esa llamada no existe.');

                return self::FAILURE;
            }
            $retenida = VoiceAmbiguousResolution::rowHold($fila, now());
            if ($retenida !== null) {
                $this->error(match ($retenida) {
                    VoiceAmbiguousResolution::HELD_WITHIN_GRACE => 'Es más joven que el plazo de gracia ('.VoiceAmbiguousResolution::GRACE_MINUTES.' min desde que quedó ambiguous): la llamada aún puede aparecer. No se toca.',
                    VoiceAmbiguousResolution::HELD_PROVIDER_IDS => 'Algún evento suyo trae un id del proveedor: la llamada pudo salir. La resuelve la conciliación por su SID (voice:reconcile). No se toca.',
                    default => "No es una saliente ambiguous (está {$fila->status}): no hay nada que resolver.",
                });

                return self::FAILURE;
            }
            if (! VoiceAmbiguousResolution::schemaAllows()) {
                $this->error('El esquema todavía no admite pasar de ambiguous a '.VoiceAmbiguousResolution::FINAL.' (la migración E19-3, pendiente): la fila sigue ambiguous.');

                return self::FAILURE;
            }
            if (! VoiceCall::transition((int) $fila->id, [VoiceCall::STATUS_AMBIGUOUS], VoiceAmbiguousResolution::FINAL, VoiceAmbiguousResolution::transitionValues())) {
                $this->error('La fila cambió mientras tanto: no se toca.');

                return self::FAILURE;
            }
            VoiceEvents::record('reconciled', (int) $fila->id, VoiceCallEvent::PROVIDER_LARAVEL, 'manual:'.$fila->uuid, [
                'outcome' => VoiceAmbiguousResolution::OUTCOME,
                'codes' => ['by' => 'console', 'reason' => $motivo, 'graceMinutes' => VoiceAmbiguousResolution::GRACE_MINUTES,
                    'before' => VoiceCall::STATUS_AMBIGUOUS, 'after' => VoiceAmbiguousResolution::FINAL],
                'actor_type' => VoiceCallEvent::ACTOR_SYSTEM, 'actor_name' => self::ACTOR_NAME,
            ]);
            Log::channel('voice')->warning('voice.ambiguous.resolved_manually', ['call_uuid' => $fila->uuid]);
            $this->info("Resuelta: {$fila->uuid} · ambiguous → ".VoiceAmbiguousResolution::FINAL.' (la reserva se libera).');

            return self::SUCCESS;
        });
    }

    /** El motivo, recortado, o null si no tiene forma: de 10 a 200 caracteres, sin control, sin 7 cifras seguidas (un teléfono) ni `@` (un correo). */
    public static function reason(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }
        $motivo = trim($valor);
        $largo = mb_strlen($motivo, 'UTF-8');
        if ($largo < self::REASON_MIN || $largo > self::REASON_MAX || preg_match('/[\p{Cc}\p{Cf}]/u', $motivo) !== 0
            || preg_match('/\d(?:[\s.\-]?\d){6,}/', $motivo) === 1 || str_contains($motivo, '@')) {
            return null;
        }

        return $motivo;
    }
}
