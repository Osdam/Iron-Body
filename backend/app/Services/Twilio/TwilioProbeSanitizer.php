<?php

namespace App\Services\Twilio;

use InvalidArgumentException;

/**
 * De las respuestas crudas de {@see TwilioReadOnlyClient} a lo ÚNICO que se
 * conserva de una comprobación de solo lectura de Twilio: recuentos, valores de
 * conjuntos cerrados y los 4 últimos dígitos de los números DE LA CUENTA (los
 * del gimnasio). Pura: sin red, reloj, base, log ni configuración.
 *
 * LISTA BLANCA, NO LISTA NEGRA. No se borra lo peligroso: se copia lo que vale,
 * y nada más. Por eso no sobreviven `friendly_name` (lleva el número
 * formateado), `phone_number` (salvo sus 4 dígitos, y solo en los números de la
 * cuenta), `to`, `from`, `alert_text`, `request_url`, `request_variables`,
 * `response_body`, el `auth_token` de la ficha ni ningún otro texto, aunque
 * Twilio añada campos mañana. Los caller ID verificados pueden ser el móvil de
 * una persona: de ellos solo se cuenta cuántos hay.
 *
 * Lo que se copia sale de conjuntos cerrados (estado y tipo de la cuenta, estado
 * de una llamada, nivel de una alerta) o de una forma estricta (un código de
 * error de hasta 6 cifras, un número en E.164). Lo que no casa cuenta como
 * `other` en los recuentos y queda en null en la ficha de la cuenta: ni un texto
 * hostil ni un teléfono disfrazado de código llegan a ser una clave.
 *
 * Un paso que falla, se salta o trae un cuerpo con otra forma deja su bloque en
 * null y una entrada en `errors`: «sin dato» no es «cero».
 *
 * El resultado solo tiene null, booleanos, enteros, cadenas y arrays (ni
 * decimales ni objetos), con los recuentos ordenados por clave: es estable y se
 * puede huellar tal cual. Un recuento vacío es `[]`, también en JSON.
 */
final class TwilioProbeSanitizer
{
    /** Con qué credencial se hizo: la del OTP o una API key restringida. */
    public const CREDENTIALS = ['otp', 'restricted_key'];

    public const OUTCOMES = ['ok', 'degraded', 'auth_failed', 'unreachable', 'error'];

    /** El `outcome` de una entrada de `errors`. */
    public const STEP_OUTCOMES = ['auth_failed', 'unreachable', 'error', 'skipped'];

    public const ACCOUNT_STATUSES = ['active', 'suspended', 'closed'];

    public const ACCOUNT_TYPES = ['Trial', 'Full'];

    /** Los del recurso Call en la documentación oficial (31-10-2026). */
    public const CALL_STATUSES = ['queued', 'ringing', 'in-progress', 'completed', 'busy', 'failed', 'no-answer', 'canceled'];

    /** Los de Monitor Alerts en la documentación oficial (31-10-2026). */
    public const ALERT_LEVELS = ['error', 'warning', 'notice', 'debug'];

    /** El cubo de lo que no casa con su conjunto o su forma. */
    public const OTHER = 'other';

    /** Paso → su bloque en `facts`, en el orden de los pasos. */
    public const FACT_KEYS = ['account' => 'account', 'numbers' => 'numbers', 'caller_ids' => 'callerIds', 'calls' => 'calls7d', 'alerts' => 'alerts24h'];

    /** Paso → la lista de su respuesta. */
    private const LIST_KEYS = ['numbers' => 'incoming_phone_numbers', 'caller_ids' => 'outgoing_caller_ids', 'calls' => 'calls', 'alerts' => 'alerts'];

    /** Un número de la cuenta en E.164, como lo devuelve Twilio (`D`: sin salto de línea al final). */
    private const E164 = '/^\+[1-9][0-9]{7,14}$/D';   // 8 a 15 cifras, el E.164 del contrato (Enmienda 2, Q-13)

    /** Un código de error de Twilio: hasta 6 cifras sin cero a la izquierda. Nunca cabe un teléfono. */
    private const ALERT_CODE = '/^[1-9][0-9]{0,5}$/D';

    /**
     * @param  array<string, array{ok:bool,status:?int,body:?array,error:?string}|array{skipped:true}>  $responses  por paso; un paso que falta cuenta como saltado
     * @return array{outcome: 'ok'|'degraded'|'auth_failed'|'unreachable'|'error', facts: array<string,mixed>}
     *
     * @throws InvalidArgumentException si quien llama pasa un paso, una credencial o una respuesta que no existen (nunca por lo que diga Twilio)
     */
    public static function sanitize(array $responses, string $credential): array
    {
        if (! in_array($credential, self::CREDENTIALS, true)) {
            throw new InvalidArgumentException('Credencial de la comprobación desconocida; las válidas son: '.implode(', ', self::CREDENTIALS).'.');
        }
        foreach (array_keys($responses) as $paso) {
            if (! in_array($paso, TwilioReadOnlyClient::STEPS, true)) {
                throw new InvalidArgumentException('Paso de Twilio desconocido; los válidos son: '.implode(', ', TwilioReadOnlyClient::STEPS).'.');
            }
        }

        $facts = ['credential' => $credential];
        $errores = [];
        foreach (TwilioReadOnlyClient::STEPS as $paso) {
            // Un paso que no viene es un paso saltado; uno que viene con null es un error de quien llama.
            [$bloque, $fallo] = self::step($paso, array_key_exists($paso, $responses) ? $responses[$paso] : ['skipped' => true]);
            $facts[self::FACT_KEYS[$paso]] = $bloque;
            if ($fallo !== null) {
                $errores[] = ['step' => $paso] + $fallo;
            }
        }
        $facts['errors'] = $errores;

        return ['outcome' => self::outcome($errores), 'facts' => $facts];
    }

    /**
     * El bloque de un paso y, si no salió bien, su fallo.
     *
     * @return array{0: array<string,mixed>|null, 1: array{outcome: string, httpStatus: int|null}|null}
     */
    private static function step(string $paso, mixed $respuesta): array
    {
        if (! is_array($respuesta)) {
            throw new InvalidArgumentException("La respuesta del paso {$paso} no tiene la forma del cliente.");
        }
        if (($respuesta['skipped'] ?? false) === true) {
            return [null, ['outcome' => 'skipped', 'httpStatus' => null]];
        }

        $http = self::httpStatus($respuesta['status'] ?? null);
        if (($respuesta['ok'] ?? false) !== true) {
            $error = $respuesta['error'] ?? null;

            return [null, ['outcome' => in_array($error, ['auth_failed', 'unreachable', 'error'], true) ? $error : 'error', 'httpStatus' => $http]];
        }

        $bloque = self::block($paso, $respuesta['body'] ?? null);

        return $bloque === null ? [null, ['outcome' => 'error', 'httpStatus' => $http]] : [$bloque, null];
    }

    /**
     * El bloque de un paso que respondió bien, o null si su cuerpo no tiene la
     * forma de Twilio (un cuerpo inesperado es un `error`, no una lista vacía).
     *
     * @return array<string,mixed>|null
     */
    private static function block(string $paso, mixed $cuerpo): ?array
    {
        if (! is_array($cuerpo)) {
            return null;
        }

        if ($paso === 'account') {
            // La ficha de una cuenta lleva su SID; un cuerpo de error de Twilio
            // (`code`, `message`, `more_info`, `status`) no.
            if (! is_string($cuerpo['sid'] ?? null) || preg_match(TwilioReadOnlyClient::ACCOUNT_SID_PATTERN, $cuerpo['sid']) !== 1) {
                return null;
            }

            return [
                'status' => self::oneOf($cuerpo['status'] ?? null, self::ACCOUNT_STATUSES),
                'type' => self::oneOf($cuerpo['type'] ?? null, self::ACCOUNT_TYPES),
            ];
        }

        $lista = $cuerpo[self::LIST_KEYS[$paso]] ?? null;
        if (! is_array($lista) || ! array_is_list($lista)) {
            return null;
        }
        foreach ($lista as $item) {
            if (! is_array($item)) {
                return null;
            }
        }

        $tamano = TwilioReadOnlyClient::PAGE_SIZES[$paso];
        // «Truncada» = la primera página vino llena: puede haber más, y quien la pinta dice «N+».
        $truncada = count($lista) >= $tamano;
        // Nunca más de una página, aunque el cuerpo traiga más filas de las pedidas.
        $pagina = array_slice($lista, 0, $tamano);

        return match ($paso) {
            'numbers' => self::numbers($pagina, $truncada),
            'caller_ids' => ['count' => count($pagina), 'truncated' => $truncada],
            'calls' => [
                'total' => count($pagina),
                'truncated' => $truncada,
                'byStatus' => self::tally($pagina, static fn (array $llamada): string => self::oneOf($llamada['status'] ?? null, self::CALL_STATUSES) ?? self::OTHER),
            ],
            'alerts' => [
                'total' => count($pagina),
                'truncated' => $truncada,
                'byCode' => self::tally($pagina, static fn (array $alerta): string => self::alertCode($alerta['error_code'] ?? null)),
                'byLevel' => self::tally($pagina, static fn (array $alerta): string => self::oneOf($alerta['log_level'] ?? null, self::ALERT_LEVELS) ?? self::OTHER),
            ],
        };
    }

    /**
     * Los números de la cuenta: sus 4 últimos dígitos y si admiten voz y SMS.
     *
     * @param  list<array<mixed>>  $pagina
     * @return array{count: int, truncated: bool, voiceCapable: int, smsCapable: int, items: list<array{last4: ?string, voice: bool, sms: bool}>}
     */
    private static function numbers(array $pagina, bool $truncada): array
    {
        $items = [];
        foreach ($pagina as $numero) {
            $capacidades = is_array($numero['capabilities'] ?? null) ? $numero['capabilities'] : [];
            $items[] = [
                'last4' => self::last4($numero['phone_number'] ?? null),
                'voice' => ($capacidades['voice'] ?? null) === true,
                'sms' => ($capacidades['sms'] ?? null) === true,
            ];
        }

        return [
            'count' => count($items),
            'truncated' => $truncada,
            'voiceCapable' => count(array_filter($items, static fn (array $item): bool => $item['voice'])),
            'smsCapable' => count(array_filter($items, static fn (array $item): bool => $item['sms'])),
            'items' => $items,
        ];
    }

    /** Los 4 últimos dígitos de un número en E.164; null si no tiene esa forma. */
    private static function last4(mixed $numero): ?string
    {
        return is_string($numero) && preg_match(self::E164, $numero) === 1 ? substr($numero, -4) : null;
    }

    /** El código de una alerta (Twilio lo da como cadena; se admite entero) si tiene la forma de uno; si no, `other`. */
    private static function alertCode(mixed $codigo): string
    {
        if (is_int($codigo)) {
            $codigo = (string) $codigo;
        }

        return is_string($codigo) && preg_match(self::ALERT_CODE, $codigo) === 1 ? $codigo : self::OTHER;
    }

    /** @param  list<string>  $conjunto */
    private static function oneOf(mixed $valor, array $conjunto): ?string
    {
        return is_string($valor) && in_array($valor, $conjunto, true) ? $valor : null;
    }

    /**
     * Cuántos hay de cada clave, ordenado por clave como cadena. PHP guarda una
     * clave numérica («60200») como entero; en JSON vuelve a salir «"60200"».
     *
     * @param  list<array<mixed>>  $items
     * @param  callable(array<mixed>): string  $clave
     * @return array<string|int, int>
     */
    private static function tally(array $items, callable $clave): array
    {
        $recuento = [];
        foreach ($items as $item) {
            $k = $clave($item);
            $recuento[$k] = ($recuento[$k] ?? 0) + 1;
        }
        ksort($recuento, SORT_STRING);

        return $recuento;
    }

    private static function httpStatus(mixed $estado): ?int
    {
        return is_int($estado) && $estado >= 100 && $estado <= 599 ? $estado : null;
    }

    /**
     * `ok` sin ningún fallo; `auth_failed` o `unreachable` si la cuenta falla
     * así; `degraded` si la cuenta salió bien y otro paso falló o se saltó;
     * `error` en cualquier otro caso (la cuenta con otro fallo, o saltada).
     *
     * @param  list<array{step: string, outcome: string, httpStatus: int|null}>  $errores
     */
    private static function outcome(array $errores): string
    {
        if ($errores === []) {
            return 'ok';
        }

        $cuenta = null;
        foreach ($errores as $error) {
            if ($error['step'] === 'account') {
                $cuenta = $error['outcome'];
            }
        }

        return match (true) {
            $cuenta === null => 'degraded',
            $cuenta === 'auth_failed', $cuenta === 'unreachable' => $cuenta,
            default => 'error',
        };
    }
}
