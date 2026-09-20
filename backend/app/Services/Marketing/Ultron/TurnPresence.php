<?php

namespace App\Services\Marketing\Ultron;

use App\Models\MarketingMessage;
use App\Services\Meta\MetaMessagingService;
use App\Services\Observability\ChannelLog;
use Throwable;

/**
 * Lo que la persona VE mientras ULTRON piensa: el visto y el «escribiendo…».
 *
 * Son señales de presencia, no respuestas. No crean mensaje, ni acción, ni
 * cobran nada: sólo anotan en el propio entrante qué se intentó y qué salió,
 * para que el acta del canario pueda medir cuánto tardó en aparecer cada cosa.
 *
 * DOS REGLAS, y las dos se rompieron en otros sitios antes de escribirse aquí:
 *
 * 1. **Es mejor-esfuerzo de verdad.** Si Meta falla, o la red se cae, o el
 *    wamid resulta ilegible, el turno CONTINÚA. Una señal visual nunca puede
 *    convertirse en un turno mudo: la respuesta comercial vale más que el
 *    indicador.
 * 2. **Va después de las puertas, no antes.** Enseñar «escribiendo…» y no
 *    contestar es peor que no enseñarlo: promete una respuesta que nadie va a
 *    mandar. Por eso lo llama {@see UltronEventEmitter::emit()} cuando el turno
 *    ya nació —interruptor, freno, canario, opt-out, takeover, IA del hilo y
 *    forma del mensaje ya comprobados, y el evento ya escrito sin chocar con
 *    una reentrega—.
 *
 * El id que viaja a Meta es el `wamid` REAL del entrante. Ningún id nuestro
 * —del mensaje, de la conversación, del lead— significa nada para Graph.
 */
class TurnPresence
{
    /**
     * El cliente de Meta se resuelve del contenedor y no se construye aquí: sus
     * credenciales viven en el registro de integraciones, no en `config()`.
     */
    public function __construct(private ?MetaMessagingService $meta = null) {}

    private function meta(): MetaMessagingService
    {
        return $this->meta ??= app(MetaMessagingService::class);
    }

    /**
     * Marca el entrante como leído y enciende el «escribiendo…».
     *
     * @return array<string,mixed> lo anotado en el mensaje, para las pruebas
     */
    public function announce(MarketingMessage $message): array
    {
        $wamid = trim((string) $message->meta_message_id);

        if ($wamid === '') {
            // Un entrante sin wamid no nació en WhatsApp (la simulación, la
            // suite): no hay nada que marcar y no es un fallo.
            return $this->anotar($message, ['attempted' => false, 'reason' => 'no_wamid']);
        }

        try {
            $r = $this->meta()->markReadAndShowTyping($wamid);
        } catch (Throwable $e) {
            /*
             * El cliente de Meta ya promete no lanzar, así que esto no debería
             * ocurrir. Se captura igualmente porque el coste de equivocarse es
             * asimétrico: una excepción aquí mataría el turno entero por una
             * señal visual.
             */
            ChannelLog::warning('ultron.presence.exception', [
                'message_id' => (int) $message->id,
                'error_class' => class_basename($e),
            ]);

            return $this->anotar($message, ['attempted' => true, 'ok' => false, 'reason' => 'exception']);
        }

        ChannelLog::info('ultron.presence.sent', [
            'message_id' => (int) $message->id,
            'ok' => (bool) $r['ok'],
            'http_status' => $r['http_status'],
            'reason' => $r['reason'],
        ]);

        return $this->anotar($message, [
            'attempted' => true,
            'ok' => (bool) $r['ok'],
            // Código y motivo de Meta, ya saneados por el cliente. Nunca el
            // token, nunca el cuerpo del mensaje.
            'reason' => $r['ok'] ? null : ($r['reason'] ?? 'meta_error'),
            'http_status' => $r['http_status'],
            'error_code' => $r['error_code'],
        ]);
    }

    /**
     * Deja constancia en el propio entrante.
     *
     * `read` y `typing` comparten resultado a propósito: es UNA petición, la
     * que Cloud API acepta para las dos cosas. Se escriben las dos claves
     * porque el acta las mide por separado y porque, si algún día se separan,
     * el sitio donde mirar no cambia.
     *
     * @param  array<string,mixed>  $r
     * @return array<string,mixed>
     */
    private function anotar(MarketingMessage $message, array $r): array
    {
        $intentado = (bool) ($r['attempted'] ?? false);
        $ok = (bool) ($r['ok'] ?? false);

        $ux = array_filter([
            'read_attempted' => $intentado,
            'read_success' => $ok,
            'typing_attempted' => $intentado,
            'typing_success' => $ok,
            'typing_started_at' => $ok ? now()->toIso8601String() : null,
            'provider_error' => $r['reason'] ?? null,
            'provider_http_status' => $r['http_status'] ?? null,
            'provider_error_code' => $r['error_code'] ?? null,
        ], fn ($v) => $v !== null);

        try {
            $message->forceFill([
                'metadata' => array_merge((array) $message->metadata, ['ultron_presence' => $ux]),
            ])->save();
        } catch (Throwable $e) {
            // Ni siquiera anotar puede tumbar el turno.
            ChannelLog::warning('ultron.presence.note_failed', [
                'message_id' => (int) $message->id,
                'error_class' => class_basename($e),
            ]);
        }

        return $ux;
    }
}
