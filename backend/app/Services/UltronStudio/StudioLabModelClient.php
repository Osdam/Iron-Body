<?php

namespace App\Services\UltronStudio;

/**
 * Un proveedor de modelo para el laboratorio (contrato H4 §A3 y §A5): el de
 * OpenAI ({@see StudioLabOpenAiClient}) o el de prueba sin red
 * ({@see StudioLabFakeClient}). Nunca se cae en silencio de uno a otro: el
 * modo va en cada ejecución.
 *
 * `complete()` NO LANZA (salvo con un papel que no es `actor` ni `judge`,
 * que es un error del código): todo fallo vuelve clasificado en `class`, con un
 * mensaje fijo en español ({@see MESSAGES}) y nunca el cuerpo ni las
 * cabeceras del proveedor. La política de cada clase ({@see POLICY}: qué se
 * cobra, si para la ejecución, si se retrasa el paso y si cuenta para «dos
 * errores seguidos») la aplica quien llama.
 *
 * Resultado:
 *  - `status`: `ok` o `error`;
 *  - `output`: el JSON decodificado que cumple el esquema (solo con `ok`);
 *  - `usage`: `{inputTokens, outputTokens, reasoningTokens}` tal como lo
 *    informó el proveedor, o null si no lo informó (o no hubo llamada);
 *  - `class`: null con `ok`, o una de {@see CLASSES};
 *  - `http`: el estado HTTP, o null si no hubo respuesta;
 *  - `latencyMs`: lo que tardó la llamada (0 sin red);
 *  - `exception`: el NOMBRE CORTO de la clase de la excepción del cliente
 *    HTTP, si la hubo (para el registro: nunca su mensaje).
 */
interface StudioLabModelClient
{
    public const ROLE_ACTOR = StudioLabPrompts::ROLE_ACTOR;

    public const ROLE_JUDGE = StudioLabPrompts::ROLE_JUDGE;

    public const STATUS_OK = 'ok';

    public const STATUS_ERROR = 'error';

    public const CLASS_TIMEOUT = 'timeout';

    public const CLASS_CONNECTION = 'connection';

    public const CLASS_AUTH = 'auth';

    public const CLASS_QUOTA = 'quota';

    public const CLASS_RATE_LIMITED = 'rate_limited';

    public const CLASS_BAD_REQUEST = 'bad_request';

    public const CLASS_SERVER = 'server';

    public const CLASS_REFUSAL = 'refusal';

    public const CLASS_INCOMPLETE = 'incomplete';

    public const CLASS_INVALID_OUTPUT = 'invalid_output';

    /** Sin llamada: el laboratorio no está configurado para `openai` (no hay clave propia, modelo o precio). */
    public const CLASS_NOT_CONFIGURED = 'not_configured';

    public const CLASSES = [
        self::CLASS_TIMEOUT, self::CLASS_CONNECTION, self::CLASS_AUTH, self::CLASS_QUOTA, self::CLASS_RATE_LIMITED,
        self::CLASS_BAD_REQUEST, self::CLASS_SERVER, self::CLASS_REFUSAL, self::CLASS_INCOMPLETE, self::CLASS_INVALID_OUTPUT,
        self::CLASS_NOT_CONFIGURED,
    ];

    /** Qué se cobra de una llamada que termina con esa clase: la reserva (no se sabe si se cobró), nada o su `usage`. */
    public const COST_RESERVATION = 'reserve';

    public const COST_ZERO = 'zero';

    public const COST_USAGE = 'usage';

    /**
     * La tabla de A5. `cost`: la reserva (no se sabe si se cobró), 0 o lo que
     * diga `usage`. `stop`: el `stopReason` con el que para la ejecución, o
     * null si sigue. `delaySeconds`: cuánto se retrasa el siguiente paso.
     * `consecutive`: si cuenta para «2 errores de proveedor seguidos» →
     * `provider_errors`. La racha se cuenta POR CASO y la comparten
     * `timeout`, `connection`, `server`, `refusal`, `incomplete` e
     * `invalid_output` (Enmienda 1, decisión 2); `rate_limited` no cuenta; ver
     * {@see StudioLabOutcome::callEffect()}.
     *
     * `not_configured` no está en la tabla de A5: es la defensa del cliente
     * cuando lo llaman sin configuración, y para con el `stopReason` que A3 ya
     * tiene para eso.
     */
    public const POLICY = [
        self::CLASS_TIMEOUT => ['cost' => self::COST_RESERVATION, 'stop' => null, 'delaySeconds' => 0, 'consecutive' => true],
        self::CLASS_CONNECTION => ['cost' => self::COST_ZERO, 'stop' => null, 'delaySeconds' => 0, 'consecutive' => true],
        self::CLASS_AUTH => ['cost' => self::COST_ZERO, 'stop' => 'provider_auth', 'delaySeconds' => 0, 'consecutive' => false],
        self::CLASS_QUOTA => ['cost' => self::COST_ZERO, 'stop' => 'provider_quota', 'delaySeconds' => 0, 'consecutive' => false],
        self::CLASS_RATE_LIMITED => ['cost' => self::COST_ZERO, 'stop' => null, 'delaySeconds' => 30, 'consecutive' => false],
        self::CLASS_BAD_REQUEST => ['cost' => self::COST_ZERO, 'stop' => 'provider_rejected', 'delaySeconds' => 0, 'consecutive' => false],
        self::CLASS_SERVER => ['cost' => self::COST_ZERO, 'stop' => null, 'delaySeconds' => 0, 'consecutive' => true],
        self::CLASS_REFUSAL => ['cost' => self::COST_USAGE, 'stop' => null, 'delaySeconds' => 0, 'consecutive' => true],
        self::CLASS_INCOMPLETE => ['cost' => self::COST_USAGE, 'stop' => null, 'delaySeconds' => 0, 'consecutive' => true],
        self::CLASS_INVALID_OUTPUT => ['cost' => self::COST_USAGE, 'stop' => null, 'delaySeconds' => 0, 'consecutive' => true],
        self::CLASS_NOT_CONFIGURED => ['cost' => self::COST_ZERO, 'stop' => 'not_configured', 'delaySeconds' => 0, 'consecutive' => false],
    ];

    /** Lo que se le enseña al personal de cada clase: fijo, en español y sin nada del proveedor. */
    public const MESSAGES = [
        self::CLASS_TIMEOUT => 'El proveedor tardó demasiado en responder; no se sabe si cobró la llamada.',
        self::CLASS_CONNECTION => 'No se pudo conectar con el proveedor.',
        self::CLASS_AUTH => 'El proveedor rechazó la clave del laboratorio.',
        self::CLASS_QUOTA => 'El proyecto del laboratorio en el proveedor se quedó sin saldo o alcanzó su límite de gasto.',
        self::CLASS_RATE_LIMITED => 'El proveedor pidió esperar: se superó su límite de peticiones.',
        self::CLASS_BAD_REQUEST => 'El proveedor rechazó la petición: el modelo o el formato no se admiten.',
        self::CLASS_SERVER => 'El proveedor falló al procesar la petición.',
        self::CLASS_REFUSAL => 'El modelo se negó a responder.',
        self::CLASS_INCOMPLETE => 'La respuesta del modelo quedó incompleta.',
        self::CLASS_INVALID_OUTPUT => 'La respuesta del modelo no tiene el formato esperado.',
        self::CLASS_NOT_CONFIGURED => 'El laboratorio no está configurado para llamar a OpenAI.',
    ];

    /**
     * Una llamada al modelo con salida estricta.
     *
     * @param  string  $role  `actor` o `judge`
     * @param  array<string,mixed>  $schema  el esquema estricto de la salida
     * @return array{status:string,output:array<string,mixed>|null,usage:array{inputTokens:int,outputTokens:int,reasoningTokens:int}|null,class:string|null,http:int|null,latencyMs:int,exception:string|null}
     */
    public function complete(string $role, string $instructions, string $inputJson, array $schema, int $maxOutputTokens): array;
}
