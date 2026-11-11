<?php

namespace App\Services\Telephony;

use App\Models\Plan;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Models\Voice\VoiceExclusion;
use App\Services\Voice\Dto\VoiceAgentSpec;
use App\Services\Voice\Dto\VoiceIds;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Las SERVER TOOLS de la voz (contrato H8, D8-10 capa 2, D8-14; E1-1; fixture
 * `elevenlabs-webhook-bodies.json` §tools), ya autenticadas por
 * `VoiceToolAuth`. Catálogo CERRADO: ni cobro, ni enlace de pago, ni
 * descuento, ni membresía, ni agenda.
 *
 * Cada petición:
 *  1. ESQUEMA ESTRICTO (DF-E8): un objeto JSON con, como mucho,
 *     `conversation_id`, `call_sid` y `call_ref` (los rellenan las variables
 *     del sistema, nunca el modelo [C-A3]); al menos uno de los dos primeros;
 *     cualquier otra clave → 422 `invalid_schema`.
 *  2. LA LLAMADA (DF-E9): existe (si no, `call_not_found`, sin evento), está
 *     en `dialing` o `active` (E1-1; una entrante denegada está `denied`), no
 *     hay freno y su cupo sigue vivo (20 por llamada). Un rechazo responde 200
 *     con un mensaje NEUTRO (nunca silencio, R-16) y deja su evento.
 *  3. La primera herramienta de una saliente en `dialing` la pasa a `active`
 *     (E1-1); el cupo crece en la misma sentencia.
 *  4. La herramienta, atada a ESA llamada: `no_volver_a_llamar` excluye el
 *     número SELLADO de la llamada que pide (su huella), nunca uno que llegue
 *     como parámetro (hallazgo A-8 de SEC8).
 */
final class VoiceTools
{
    public const TOOLS = VoiceAgentSpec::TOOLS;

    public const BODY_KEYS = ['call_ref', 'call_sid', 'conversation_id'];

    public const VERDICT_OK = 'ok';

    public const NEUTRAL = 'No puedo consultar eso en este momento. Ofrece a la persona que la llamen más tarde o que escriba al gimnasio.';

    /** @return array{http: int, body: array<string, mixed>} */
    public function handle(string $tool, string $rawBody): array
    {
        if (! in_array($tool, self::TOOLS, true)) {
            return ['http' => 404, 'body' => ['ok' => false, 'verdict' => 'unknown_tool']];
        }
        $cuerpo = self::body($rawBody);
        if ($cuerpo === null) {
            return ['http' => 422, 'body' => ['ok' => false, 'verdict' => 'invalid_schema']];
        }

        return DB::transaction(function () use ($tool, $cuerpo): array {
            $llamada = self::find($cuerpo);
            if ($llamada === null) {
                return self::refused('call_not_found', null, $tool);
            }
            $id = (int) $llamada->id;
            if (! in_array($llamada->status, VoiceCall::TOOL_STATUSES, true) || ($llamada->direction === VoiceCall::DIRECTION_INBOUND && $llamada->caller_authorized !== true)) {
                return self::refused('call_not_active', $id, $tool);
            }
            // Una baja dicha de viva voz (Ley 2300, la revocación oral) se acepta SIEMPRE, también con el freno echado:
            // el freno para las llamadas y las demás herramientas, nunca una revocación (E17-8; tras SC-3 el freno salta
            // también por el webhook post-llamada, y una llamada en curso no puede perder su «no me vuelvan a llamar»).
            if ($tool !== VoiceAgentSpec::TOOL_NO_VOLVER_A_LLAMAR && VoiceBrake::isOpen()) {
                return self::refused('voice_braked', $id, $tool);
            }
            if ($llamada->tool_calls >= VoiceCall::MAX_TOOL_CALLS) {
                return self::refused('tool_quota_exceeded', $id, $tool);
            }

            // E1-1: la primera herramienta de una saliente la pasa a `active`; el cupo crece en la misma sentencia.
            $valores = ['tool_calls' => $llamada->tool_calls + 1];
            $pasaAActiva = $llamada->status === VoiceCall::STATUS_DIALING
                && VoiceCall::transition($id, [VoiceCall::STATUS_DIALING], VoiceCall::STATUS_ACTIVE, $valores);
            if (! $pasaAActiva) {
                VoiceCall::query()->whereKey($id)->whereIn('status', VoiceCall::TOOL_STATUSES)->where('tool_calls', $llamada->tool_calls)->update($valores);
            }

            $respuesta = match ($tool) {
                VoiceAgentSpec::TOOL_CONSULTAR_PLANES => self::plans(),
                VoiceAgentSpec::TOOL_CONSULTAR_HORARIOS => self::hours(),
                VoiceAgentSpec::TOOL_SOLICITAR_HUMANO => self::human($llamada),
                VoiceAgentSpec::TOOL_NO_VOLVER_A_LLAMAR => self::optOut($llamada),
            };
            VoiceEvents::record('tool_call', $id, VoiceCallEvent::PROVIDER_ELEVENLABS, null, ['tool' => $tool, 'outcome' => self::VERDICT_OK]);
            Log::channel('voice')->info('voice.tool', ['call_uuid' => $llamada->uuid, 'tool' => $tool, 'verdict' => self::VERDICT_OK, 'activated' => $pasaAActiva]);

            return ['http' => 200, 'body' => ['ok' => true, 'verdict' => self::VERDICT_OK] + $respuesta];
        });
    }

    /**
     * El cuerpo con su esquema estricto, o null.
     *
     * @return array{conversation_id: ?string, call_sid: ?string, call_ref: ?string}|null
     */
    public static function body(string $rawBody): ?array
    {
        $datos = json_decode($rawBody, true, 4);
        if (! is_array($datos) || ($datos !== [] && array_is_list($datos))) {
            return null;
        }
        if (array_diff(array_keys($datos), self::BODY_KEYS) !== []) {
            return null;
        }
        $conversacion = $datos['conversation_id'] ?? null;
        $sid = $datos['call_sid'] ?? null;
        $ref = $datos['call_ref'] ?? null;
        if (($conversacion !== null && ! VoiceIds::isProviderId($conversacion)) || ($sid !== null && ! VoiceIds::isCallSid($sid))
            || ($ref !== null && ! VoiceIds::isUuid($ref)) || ($conversacion === null && $sid === null)) {
            return null;
        }

        return ['conversation_id' => $conversacion, 'call_sid' => $sid, 'call_ref' => $ref];
    }

    /** @param  array{conversation_id: ?string, call_sid: ?string, call_ref: ?string}  $cuerpo */
    private static function find(array $cuerpo): ?VoiceCall
    {
        $q = VoiceCall::query()->whereNull('transfer_of_call_id')->lockForUpdate();
        $fila = $cuerpo['conversation_id'] !== null
            ? (clone $q)->where('provider_conversation_id', $cuerpo['conversation_id'])->first()
            : null;
        if ($fila === null && $cuerpo['call_sid'] !== null) {
            $fila = (clone $q)->where('provider_call_sid', $cuerpo['call_sid'])->first();
        }
        if ($fila === null) {
            // Una saliente que el proveedor ya inició y cuyo job aún no guardó los
            // ids: solo su `call_ref` la encuentra (y seguirá `requested`: no responde).
            return $cuerpo['call_ref'] === null ? null : (clone $q)->where('call_ref', $cuerpo['call_ref'])->first();
        }
        // El `call_ref` de las variables, si viene, tiene que ser el de ESA llamada.
        if ($cuerpo['call_ref'] !== null && ! hash_equals((string) $fila->call_ref, $cuerpo['call_ref'])) {
            return null;
        }

        return $fila;
    }

    /** @return array{http: int, body: array<string, mixed>} */
    private static function refused(string $verdict, ?int $callId, string $tool): array
    {
        if ($callId !== null) {
            VoiceEvents::record('tool_call', $callId, VoiceCallEvent::PROVIDER_ELEVENLABS, null, ['tool' => $tool, 'outcome' => $verdict]);
        }
        Log::channel('voice')->info('voice.tool', ['tool' => $tool, 'verdict' => $verdict]);

        return ['http' => 200, 'body' => ['ok' => false, 'verdict' => $verdict, 'message' => self::NEUTRAL]];
    }

    /**
     * `consultar_planes`: nombres y condiciones del catálogo VIGENTE
     * (`Plan::sellable()`, la única autoridad sobre qué se vende) y, por P-3,
     * el precio EXACTO de la base, nunca calculado ni rebajado.
     *
     * @return array<string, mixed>
     */
    private static function plans(): array
    {
        $planes = Plan::query()->sellable()->orderBy('sort_order')->orderBy('duration_days')->limit(20)->get(['id', 'name', 'price', 'duration_days', 'benefit_only']);
        if ($planes->isEmpty()) {
            return ['planes' => [], 'nota' => 'No hay planes vendibles en el catálogo: no des precios y ofrece que una persona le escriba.'];
        }

        return ['planes' => $planes->map(static fn (Plan $p): array => [
            'nombre' => (string) $p->name,
            'condiciones' => ((int) $p->duration_days).' días de vigencia'.($p->benefit_only ? ', se compra encima de una membresía' : ''),
            'precio' => self::price((float) $p->price),
        ])->all()];
    }

    /** El precio tal como está en la base, en pesos y con separador de miles: «$ 89.000». */
    public static function price(float $valor): string
    {
        $entero = (int) round($valor);

        return '$ '.number_format($entero, 0, ',', '.');
    }

    /** @return array<string, mixed> `consultar_horarios`: la sede y el horario (los hechos configurados; nunca inventados) */
    private static function hours(): array
    {
        $sede = (string) config('voice.facts.site', '');
        $horario = (string) config('voice.facts.hours', '');
        if ($sede === '' || $horario === '') {
            return ['disponible' => false, 'nota' => 'No tengo la sede ni el horario a mano: ofrece que una persona le escriba.'];
        }

        return ['disponible' => true, 'sede' => $sede, 'horario' => $horario];
    }

    /**
     * `solicitar_humano` (D8-14): si hay transferencia AHORA (el horario del
     * personal [P-12] y un destino aprobado) y el ALIAS del destino, nunca su
     * número. Sin horario o sin destinos, no hay nadie: el agente no transfiere.
     * Los destinos son los del perfil de la entrada que SELLÓ la llamada (E4-7).
     *
     * @return array<string, mixed>
     */
    private static function human(VoiceCall $llamada): array
    {
        $alias = VoiceTransferDirectory::aliasesFor($llamada)[0] ?? null;
        $hay = $alias !== null && OutboundSchedule::within(now(), OutboundSchedule::staffFromConfig());

        return ['disponible' => $hay, 'alias' => $hay ? $alias : null];
    }

    /**
     * `no_volver_a_llamar` (Ley 2300, la revocación oral): una exclusión
     * INMEDIATA del número sellado de esta llamada, sin confirmación del LLM.
     * Idempotente (una por llamada, `vex_one_opt_out_per_call`).
     *
     * @return array<string, mixed>
     */
    private static function optOut(VoiceCall $llamada): array
    {
        if ($llamada->remote_hash === null || $llamada->remote_last4 === null) {
            return ['excluido' => false];
        }
        if (! VoiceExclusion::query()->where('call_id', $llamada->id)->where('source', VoiceExclusion::SOURCE_SPOKEN_OPT_OUT)->exists()) {
            try {
                DB::transaction(static fn () => (new VoiceExclusion)->forceFill([
                    'phone_hash' => $llamada->remote_hash,
                    'phone_last4' => $llamada->remote_last4,
                    'source' => VoiceExclusion::SOURCE_SPOKEN_OPT_OUT,
                    'call_id' => $llamada->id,
                    'contact_id' => $llamada->voice_contact_id,
                    'actor_type' => 'system',
                    'actor_name' => 'Llamada de voz (no_volver_a_llamar)',
                ])->save());
            } catch (UniqueConstraintViolationException) {
                // Otra petición de la misma llamada ya la creó: la exclusión existe, que es lo que importa.
            }
        }

        return ['excluido' => true];
    }
}
