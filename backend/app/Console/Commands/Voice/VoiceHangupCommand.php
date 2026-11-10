<?php

namespace App\Console\Commands\Voice;

use App\Models\Voice\VoiceCall;
use App\Services\Telephony\VoiceHangup;
use App\Services\Twilio\TwilioVoiceCarrier;
use App\Services\Voice\VoiceAdapters;
use Illuminate\Console\Command;

/**
 * `voice:hangup` — el CORTE de emergencia (contrato H8, D8-20 nivel 0b): cuelga
 * en Twilio (la llamada a `completed`, con la key de Laravel de la subcuenta)
 * una llamada o todas las `dialing` y `active`; una saliente aún sin marcar se
 * cancela sin red. Funciona con la voz apagada. Sin la credencial de la
 * subcuenta no puede colgar en Twilio: lo dice y sale con 1.
 *
 * E12-5 (B8-g): una saliente ya CORTADA (`canceled`) que el proveedor inició
 * igual y cuyo colgado no se confirmó se vuelve a colgar (por su uuid, o con
 * `--all` si se cortó hace menos de una hora: las que aún pueden estar vivas);
 * si tampoco se confirma, freno. Sin eso, una cortada no tiene nada que colgar.
 *
 * `--all` exige `--confirm`. Imprime uuids y resultados, nunca números.
 */
class VoiceHangupCommand extends Command
{
    protected $signature = 'voice:hangup
        {call? : el uuid de la llamada}
        {--all : todas las llamadas en curso (dialing y active), las pedidas sin marcar y las cortadas que el proveedor inició igual sin colgado confirmado}
        {--confirm : obligatorio con --all}';

    protected $description = 'Corte de emergencia de la voz (D8-20 nivel 0b): cuelga en Twilio una llamada o todas las que estén en curso. Nunca imprime números.';

    public function handle(): int
    {
        $uuid = $this->argument('call');
        $todas = (bool) $this->option('all');
        if ($todas === ($uuid !== null)) {
            $this->error('Indica el uuid de UNA llamada o --all (con --confirm).');

            return self::FAILURE;
        }
        if ($todas && ! $this->option('confirm')) {
            $this->error('--all cuelga TODAS las llamadas en curso: repite con --confirm.');

            return self::FAILURE;
        }
        $carrier = VoiceAdapters::carrier();
        if (! TwilioVoiceCarrier::configured()) {
            $this->warn('Sin la credencial de la subcuenta (TWILIO_VOICE_ACCOUNT_SID, TWILIO_VOICE_API_KEY_SID y su secreto) no se puede colgar en Twilio; las pedidas sin marcar sí se cancelan.');
        }

        $llamadas = $todas
            ? VoiceCall::query()->whereIn('status', [VoiceCall::STATUS_REQUESTED, VoiceCall::STATUS_DIALING, VoiceCall::STATUS_ACTIVE])->whereNull('transfer_of_call_id')->orderBy('id')->get()
                ->concat(VoiceHangup::unconfirmedAfterCancel())
            : VoiceCall::query()->where('uuid', strtolower((string) $uuid))->get();
        if ($llamadas->isEmpty()) {
            $this->line($todas ? 'No hay llamadas en curso.' : 'Esa llamada no existe.');

            return $todas ? self::SUCCESS : self::FAILURE;
        }

        $fallos = 0;
        foreach ($llamadas as $llamada) {
            $r = VoiceHangup::hangup($llamada, $carrier, null);
            $bien = in_array($r['result'], [VoiceHangup::RESULT_HUNG_UP, VoiceHangup::RESULT_CANCELED, VoiceHangup::RESULT_NOT_LIVE], true);
            $fallos += $bien ? 0 : 1;
            $this->line(sprintf('%s · %s → %s (%s)', $llamada->uuid, $llamada->direction, $r['status'], $r['result']));
        }

        return $fallos === 0 ? self::SUCCESS : self::FAILURE;
    }
}
