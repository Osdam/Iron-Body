<?php

namespace App\Services\Telephony;

use App\Models\Plan;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Models\Voice\VoiceExclusion;
use App\Services\Voice\Dto\VoiceAgentSpec;
use App\Services\Voice\Dto\VoiceIds;
use Illuminate\Database\Eloquent\Builder;
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
 *
 * EL LABORATORIO HABLADO (contrato H8, Enmienda 18, E18-8): sin llamada que
 * lo reconozca, un cuerpo de una conversación WEB (sin `call_sid`) que la
 * telefonía no conoce y cuyo `call_ref` es el uuid de una sesión del
 * laboratorio ABIERTA y vigente recibe las dos herramientas de SOLO LECTURA
 * ({@see LAB_TOOLS}), con los mismos datos que una llamada ({@see lab()}).
 * Todo lo demás, la negativa de siempre (`call_not_found`).
 */
final class VoiceTools
{
    public const TOOLS = VoiceAgentSpec::TOOLS;

    public const BODY_KEYS = ['call_ref', 'call_sid', 'conversation_id'];

    public const VERDICT_OK = 'ok';

    public const NEUTRAL = 'No puedo consultar eso en este momento. Ofrece a la persona que la llamen más tarde o que escriba al gimnasio.';

    /**
     * E18-8: lo único que se atiende en el laboratorio hablado, las dos de
     * SOLO LECTURA (información comercial: el catálogo vendible, la sede y el
     * horario). `solicitar_humano` y `no_volver_a_llamar` no: no hay llamada,
     * ni destino, ni número que excluir.
     */
    public const LAB_TOOLS = [VoiceAgentSpec::TOOL_CONSULTAR_PLANES, VoiceAgentSpec::TOOL_CONSULTAR_HORARIOS];

    /**
     * E18-8: el evento CON NOMBRE con el que la telefonía pregunta al
     * laboratorio hablado si un `call_ref` es una sesión SUYA abierta y
     * vigente (la telefonía no nombra el Studio: TelephonyIsolationTest; el
     * Studio escucha, como con `VoicePostCall::LAB_EVENT`). Se entrega
     * deteniéndose en la primera respuesta. Carga: el `call_ref` (uuid ya
     * validado), si la petición cuenta en el cupo (no con el freno echado) y
     * el cupo, el mismo que el de una llamada ({@see VoiceCall::MAX_TOOL_CALLS}).
     * Sin oyente, o si no es suya, la negativa de siempre (falla cerrado).
     */
    public const LAB_TOOL_EVENT = 'voice.lab.tool_call';

    /** E18-8: la sesión es suya, abierta y vigente, y la petición contó en su cupo. */
    public const LAB_COUNTED = 'counted';

    /** E18-8: la sesión es suya, abierta y vigente; no se contó (se preguntó con el freno echado). */
    public const LAB_OPEN = 'open';

    /** E18-8: la sesión es suya, abierta y vigente, pero ya agotó su cupo. */
    public const LAB_QUOTA_EXCEEDED = 'quota_exceeded';

    /** @return array{http: int, body: array<string, mixed>} */
    public function handle(string $tool, string $rawBody): array
    {
        if (! in_array($tool, self::TOOLS, true)) {
            return ['http' => 404, 'body' => ['ok' => false, 'verdict' => 'unknown_tool']];
        }
        $cuerpo = self::body($rawBody);
        if ($cuerpo === null) {
            // A-2 (revisión de seguridad de E18-8): el rechazo deja su línea, con los NOMBRES de las claves recibidas y el
            // motivo, nunca un valor. Sin ella, un cuerpo que el proveedor mande con otra forma en una conversación web
            // (un `call_sid` vacío, por ejemplo) solo se vería en su panel. El esquema no se relaja.
            // El motivo se calcula FUERA de la llamada de registro: en ella no se nombra un cuerpo crudo (guarda de D8-4).
            $motivo = self::schemaProblem($rawBody);
            Log::channel('voice')->warning('voice.tool.invalid_schema', ['tool' => $tool] + $motivo);

            return ['http' => 422, 'body' => ['ok' => false, 'verdict' => 'invalid_schema']];
        }

        return DB::transaction(function () use ($tool, $cuerpo): array {
            $llamada = self::find($cuerpo);
            if ($llamada === null) {
                return self::lab($tool, $cuerpo) ?? self::refused('call_not_found', null, $tool);
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

    /**
     * Por qué un cuerpo no pasa el esquema, SIN sus valores (A-2): los nombres de las claves recibidas (como mucho
     * ocho y solo las que tienen forma de nombre: llegan de fuera), cuál falla y la forma de lo que trae (vacío,
     * un texto de N caracteres u otro tipo).
     *
     * @return array{keys: list<string>, problem: string, shape?: string}
     */
    private static function schemaProblem(string $rawBody): array
    {
        $datos = json_decode($rawBody, true, 4);
        if (! is_array($datos) || ($datos !== [] && array_is_list($datos))) {
            return ['keys' => [], 'problem' => 'not_an_object'];
        }
        $claves = array_values(array_filter(array_map('strval', array_keys($datos)), static fn (string $k): bool => preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,39}$/D', $k) === 1));
        sort($claves, SORT_STRING);
        $claves = array_slice($claves, 0, 8);
        if (array_diff(array_keys($datos), self::BODY_KEYS) !== []) {
            return ['keys' => $claves, 'problem' => 'unexpected_keys'];
        }
        $formas = ['conversation_id' => VoiceIds::isProviderId(...), 'call_sid' => VoiceIds::isCallSid(...), 'call_ref' => VoiceIds::isUuid(...)];
        foreach ($formas as $clave => $vale) {
            $valor = $datos[$clave] ?? null;
            if ($valor !== null && ! $vale($valor)) {
                return ['keys' => $claves, 'problem' => $clave, 'shape' => match (true) {
                    $valor === '' => 'empty',
                    is_string($valor) => 'string:'.min(strlen($valor), 999),
                    default => gettype($valor),
                }];
            }
        }

        return ['keys' => $claves, 'problem' => 'no_call_ids'];
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
            // E18-9: SOLO esa. Una fila con otro id del proveedor es otra llamada.
            return $cuerpo['call_ref'] === null ? null
                : self::withoutOtherIds((clone $q)->where('call_ref', $cuerpo['call_ref']), $cuerpo['conversation_id'], $cuerpo['call_sid'])->first();
        }
        // El `call_ref` de las variables, si viene, tiene que ser el de ESA llamada.
        if ($cuerpo['call_ref'] !== null && ! hash_equals((string) $fila->call_ref, $cuerpo['call_ref'])) {
            return null;
        }

        return $fila;
    }

    /**
     * E18-9 (P-1 de la revisión de seguridad de E18-8): el último recurso por
     * `call_ref` solo encuentra una fila cuyos ids del proveedor, si ya tiene
     * alguno, son los del cuerpo. Tras no encontrarla por conversación ni por
     * `call_sid`, eso es una fila SIN ids: la saliente cuyo job aún no los
     * guardó (`requested`, o `ambiguous` hasta la conciliación; DB8 no le
     * admite ninguno). Una que ya tiene OTRA conversación u OTRA llamada de
     * Twilio no se alcanza con su `call_ref`: en el laboratorio hablado ese
     * valor lo pone el navegador. Va en el filtro, no después: no se bloquea
     * una llamada ajena, y si el job guarda los ids mientras se espera su
     * cerrojo, PostgreSQL vuelve a evaluar el filtro sobre la fila nueva y la
     * sigue encontrando si son los del cuerpo. Lo mismo en `VoicePostCall::find()`.
     *
     * @param  Builder<VoiceCall>  $q
     * @return Builder<VoiceCall>
     */
    private static function withoutOtherIds(Builder $q, ?string $conversacion, ?string $sid): Builder
    {
        return $q
            ->where(static fn (Builder $w) => $w->whereNull('provider_conversation_id')->when($conversacion !== null, static fn (Builder $w) => $w->orWhere('provider_conversation_id', $conversacion)))
            ->where(static fn (Builder $w) => $w->whereNull('provider_call_sid')->when($sid !== null, static fn (Builder $w) => $w->orWhere('provider_call_sid', $sid)));
    }

    /**
     * E18-8: una herramienta de SOLO LECTURA en una conversación del
     * laboratorio hablado, o null si no lo es (y el cuerpo sigue el camino de
     * siempre, `call_not_found`, sin distinguir una sesión que no existe de
     * una cerrada, fallida o caducada: nadie con el secreto enumera sesiones).
     *
     * Solo se pregunta al laboratorio por una de {@see LAB_TOOLS}, con su
     * `call_ref`, sin `call_sid` (una conversación web: sin llamada de Twilio,
     * como el informe del laboratorio en `VoicePostCall`) y de una
     * conversación que la telefonía no conoce (la de una llamada con otro
     * `call_ref` no se vuelve del laboratorio). El mismo orden que una
     * llamada: la sesión, el freno, el cupo. Nada se escribe en `voice_*`; el
     * cupo lo lleva la sesión.
     *
     * @param  array{conversation_id: ?string, call_sid: ?string, call_ref: ?string}  $cuerpo
     * @return array{http: int, body: array<string, mixed>}|null
     */
    private static function lab(string $tool, array $cuerpo): ?array
    {
        if (! in_array($tool, self::LAB_TOOLS, true) || $cuerpo['call_ref'] === null || $cuerpo['call_sid'] !== null || $cuerpo['conversation_id'] === null) {
            return null;
        }
        if (VoiceCall::query()->where('provider_conversation_id', $cuerpo['conversation_id'])->exists()) {
            return null;
        }
        $frenado = VoiceBrake::isOpen();
        $respuesta = event(self::LAB_TOOL_EVENT, [$cuerpo['call_ref'], ! $frenado, VoiceCall::MAX_TOOL_CALLS], true);

        return match (true) {
            $frenado && $respuesta === self::LAB_OPEN => self::refused('voice_braked', null, $tool, true),
            ! $frenado && $respuesta === self::LAB_QUOTA_EXCEEDED => self::refused('tool_quota_exceeded', null, $tool, true),
            ! $frenado && $respuesta === self::LAB_COUNTED => self::labAnswer($tool),
            default => null,
        };
    }

    /** @return array{http: int, body: array<string, mixed>} E18-8: la respuesta de una sesión del laboratorio, los mismos datos que en una llamada */
    private static function labAnswer(string $tool): array
    {
        $respuesta = $tool === VoiceAgentSpec::TOOL_CONSULTAR_PLANES ? self::plans() : self::hours();
        Log::channel('voice')->info('voice.tool', ['tool' => $tool, 'verdict' => self::VERDICT_OK, 'lab' => true]);

        return ['http' => 200, 'body' => ['ok' => true, 'verdict' => self::VERDICT_OK] + $respuesta];
    }

    /** @return array{http: int, body: array<string, mixed>} */
    private static function refused(string $verdict, ?int $callId, string $tool, bool $lab = false): array
    {
        if ($callId !== null) {
            VoiceEvents::record('tool_call', $callId, VoiceCallEvent::PROVIDER_ELEVENLABS, null, ['tool' => $tool, 'outcome' => $verdict]);
        }
        Log::channel('voice')->info('voice.tool', ['tool' => $tool, 'verdict' => $verdict] + ($lab ? ['lab' => true] : []));

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
