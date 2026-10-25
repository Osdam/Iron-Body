<?php

namespace App\Services\UltronStudio;

use App\Models\UltronStudio\StudioVersion;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Las LLAVES de la publicación y lo que se puede saber sin tocar la base
 * (contrato H5 §A5 y §A13). Las puertas que leen la base, el motor o el
 * laboratorio, y las transacciones, son de {@see StudioPublications}.
 *
 * Tres llaves y un registro, y cada una abre una cosa:
 *
 *  - CÓDIGO: {@see ENGINE_READS_VERSIONS}, girada a true en el commit que
 *    implementa publicar (paso 3 del plan), cuando el lector del motor
 *    (`UltronPublishedConfig`, paso 1c) ya existe: hay código que publica y
 *    código que lee.
 *  - ENTORNO DEL MOTOR: `MARKETING_ULTRON_STUDIO_CONFIG` = off | canary | all
 *    (config/marketing.php). Con `off` el motor no consulta la cadena: publicar
 *    y promover responden 409 `engine_not_reading_versions` (declarar algo que
 *    nadie lee no tendría efecto); promover exige además `all`
 *    (`scope_not_allowed`). La normalización es la del lector del motor: una
 *    prueba compara las dos.
 *  - ENTORNO DEL STUDIO: `ULTRON_STUDIO_PUBLISHING_ENABLED` (apagada): sin ella,
 *    publicar y promover responden 409 `publishing_disabled`.
 *  - REGISTRO: la cabeza firmada de `ultron_studio_publications`.
 *
 * Revertir y volver a Legacy NO miran ninguna llave, ni los cuatro ojos, ni la
 * ventana: deshacer no puede depender de la llave que permite cambiar, y con el
 * motor en `off` es solo una fila que nadie lee (§A5.3, decisión 8 del lead).
 *
 * Con las llaves por defecto, publicar y promover son 409 y el motor no lee
 * nada: girar la constante no cambia al agente.
 */
final class StudioPublishing
{
    public const ENGINE_READS_VERSIONS = true;

    public const CODE_DISABLED = 'publishing_disabled';

    public const CODE_ENGINE_NOT_READING = 'engine_not_reading_versions';

    public const CODE_SCOPE_NOT_ALLOWED = 'scope_not_allowed';

    /**
     * La llave del motor tal como la lee `UltronPublishedConfig` (sus
     * constantes OFF, CANARY y ALL; una prueba las ata). Este fichero no nombra
     * clases del motor a propósito.
     */
    public const SCOPE_OFF = 'off';

    public const SCOPE_CANARY = 'canary';

    public const SCOPE_ALL = 'all';

    public const SCOPES = [self::SCOPE_OFF, self::SCOPE_CANARY, self::SCOPE_ALL];

    /** La ventana se mira en la hora de Bogotá (UTC−5, sin horario de verano). */
    public const TIMEZONE = 'America/Bogota';

    public const DEFAULT_WINDOW = '07:00-19:00';

    public const DEFAULT_MIN_HOURS = 24;

    public const DEFAULT_MIN_TURNS = 3;

    private const WINDOW = '/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/';

    /** ¿Está encendida la llave del Studio? */
    public static function flagEnabled(): bool
    {
        return (bool) config('ultron_studio.publishing_enabled', false);
    }

    /**
     * La llave del motor: `canary` o `all`, sin distinguir mayúsculas ni
     * espacios; cualquier otra cosa es `off`. Es el mismo criterio que el
     * lector del motor (`UltronPublishedConfig`), que es quien la obedece.
     */
    public static function engineScope(): string
    {
        $valor = config('marketing.ultron.studio_config');
        $valor = is_string($valor) ? strtolower(trim($valor)) : '';

        return $valor === self::SCOPE_CANARY || $valor === self::SCOPE_ALL ? $valor : self::SCOPE_OFF;
    }

    /** ¿Están abiertas las dos llaves de publicar? (Las puertas de la versión, aparte.) */
    public static function available(): bool
    {
        return self::flagEnabled() && self::engineScope() !== self::SCOPE_OFF;
    }

    /** `required` o `waived` (§A13): cualquier otra cosa es `required`. */
    public static function labGateMode(): string
    {
        return config('ultron_studio.publishing.lab_gate') === StudioPublicationDeclaration::LAB_WAIVED
            ? StudioPublicationDeclaration::LAB_WAIVED
            : StudioPublicationDeclaration::LAB_REQUIRED;
    }

    /**
     * Los umbrales de la evidencia del canario (§A6, §A13), acotados otra vez a
     * sus topes duros (1–168 h y 1–100 turnos).
     *
     * @return array{minHours:int,minTurns:int}
     */
    public static function thresholds(): array
    {
        $acotar = static function (mixed $valor, int $defecto, int $min, int $max): int {
            return is_int($valor) || (is_string($valor) && preg_match('/^-?\d+$/', $valor) === 1)
                ? min($max, max($min, (int) $valor))
                : $defecto;
        };

        return [
            'minHours' => $acotar(config('ultron_studio.publishing.canary_min_hours'), self::DEFAULT_MIN_HOURS, StudioCanaryEvidence::MIN_HOURS, StudioCanaryEvidence::MAX_HOURS),
            'minTurns' => $acotar(config('ultron_studio.publishing.canary_min_turns'), self::DEFAULT_MIN_TURNS, StudioCanaryEvidence::MIN_TURNS, StudioCanaryEvidence::MAX_TURNS),
        ];
    }

    /**
     * La ventana de publicación en minutos de Bogotá, o null sin ventana (`''`).
     * Un valor que no se entiende (solo llega así si alguien lo pone en la
     * configuración sin pasar por el entorno) es la ventana del contrato.
     *
     * @return array{start:int,end:int,label:string}|null
     */
    public static function window(): ?array
    {
        return self::windowFor(config('ultron_studio.publishing.window', self::DEFAULT_WINDOW));
    }

    /**
     * {@see window()} sobre un valor dado (puro).
     *
     * @return array{start:int,end:int,label:string}|null
     */
    public static function windowFor(mixed $valor): ?array
    {
        if ($valor === '') {
            return null;
        }
        if (! is_string($valor) || preg_match(self::WINDOW, $valor, $m) !== 1 || (int) $m[1] * 60 + (int) $m[2] >= (int) $m[3] * 60 + (int) $m[4]) {
            preg_match(self::WINDOW, self::DEFAULT_WINDOW, $m);
        }

        return [
            'start' => (int) $m[1] * 60 + (int) $m[2],
            'end' => (int) $m[3] * 60 + (int) $m[4],
            // Como lo escribe el contrato (§A7): con raya.
            'label' => "{$m[1]}:{$m[2]}–{$m[3]}:{$m[4]}",
        ];
    }

    /**
     * ¿Está `$now` dentro de la ventana? [inicio, fin), por minutos y en la hora
     * de Bogotá: el inicio entra y el fin no; las 06:59:59 son las 06:59
     * (fixture del contrato, P-6). Sin ventana, siempre.
     */
    public static function windowOpen(DateTimeInterface $now): bool
    {
        return self::windowOpenFor(self::window(), $now);
    }

    /**
     * {@see windowOpen()} sobre una ventana dada (puro).
     *
     * @param  array{start:int,end:int,label:string}|null  $ventana
     */
    public static function windowOpenFor(?array $ventana, DateTimeInterface $now): bool
    {
        if ($ventana === null) {
            return true;
        }
        $local = CarbonImmutable::instance($now)->setTimezone(self::TIMEZONE);
        $minuto = $local->hour * 60 + $local->minute;

        return $minuto >= $ventana['start'] && $minuto < $ventana['end'];
    }

    /**
     * El rechazo de las dos llaves para publicar o promover (§A5.1, paso 2), en
     * su orden: la del Studio y después la del motor. Null con las dos abiertas.
     *
     * @param  'publish'|'promote'  $action
     * @return array{code:string,message:string}|null
     */
    public static function keyRefusal(string $action): ?array
    {
        return self::keyRefusalFor($action, self::flagEnabled(), self::engineScope());
    }

    /**
     * {@see keyRefusal()} con las llaves dadas (puro: lo usa también la
     * exportación de fixtures del CRM).
     *
     * @return array{code:string,message:string}|null
     */
    public static function keyRefusalFor(string $action, bool $studioKey, string $engineScope): ?array
    {
        $verbo = $action === StudioPublicationDeclaration::ACTION_PROMOTE ? 'Promover' : 'Publicar';

        if (! $studioKey) {
            return [
                'code' => self::CODE_DISABLED,
                'message' => "{$verbo} está desactivado en el servidor: lo enciende quien administra el sistema.",
            ];
        }
        if ($engineScope === self::SCOPE_OFF) {
            return [
                'code' => self::CODE_ENGINE_NOT_READING,
                'message' => 'El motor de ULTRON no está leyendo la cadena de publicaciones (su llave está apagada): '.mb_strtolower($verbo).' no tendría ningún efecto.',
            ];
        }

        return null;
    }

    /**
     * El bloque `publishing` de `GET status`: si se puede publicar con las
     * llaves de ahora y, si no, por qué (los códigos de §A5.1, paso 2).
     *
     * @return array{available:bool,code:string|null,message:string}
     */
    public static function status(): array
    {
        return self::statusFor(self::flagEnabled(), self::engineScope());
    }

    /**
     * {@see status()} con las llaves dadas (puro).
     *
     * @return array{available:bool,code:string|null,message:string}
     */
    public static function statusFor(bool $studioKey, string $engineScope): array
    {
        $rechazo = self::keyRefusalFor(StudioPublicationDeclaration::ACTION_PUBLISH, $studioKey, $engineScope);
        if ($rechazo !== null) {
            return ['available' => false] + $rechazo;
        }

        return [
            'available' => true,
            'code' => null,
            'message' => $engineScope === self::SCOPE_ALL
                ? 'Se puede publicar: el motor lee la cadena en todas las conversaciones.'
                : 'Se puede publicar en canario: el motor lee la cadena solo en la conversación canaria.',
        ];
    }

    /**
     * Lo que puede hacer quien mira con esta versión en la publicación
     * (`actions.publish`, `promote` y `rollback` del detalle), y si no, por qué.
     * Solo lo que se sabe sin leer nada más: el permiso, las llaves, los cuatro
     * ojos y el estado. El resto (laboratorio, motor, freno, ventana…) lo dice
     * la vista previa o la evidencia, y el servidor lo vuelve a comprobar todo
     * al actuar.
     *
     * @return array{publish:array{allowed:bool,code?:string,message?:string},promote:array{allowed:bool,code?:string,message?:string},rollback:array{allowed:bool,code?:string,message?:string}}
     */
    public static function actions(StudioAccess $access, StudioVersion $version): array
    {
        $estado = $version->statusEnum();
        $mia = $access->isCreator($version);
        $no = static fn (string $code, string $message): array => ['allowed' => false, 'code' => $code, 'message' => $message];
        $llavePublicar = self::keyRefusal(StudioPublicationDeclaration::ACTION_PUBLISH);
        $llavePromover = self::keyRefusal(StudioPublicationDeclaration::ACTION_PROMOTE);

        $publicar = match (true) {
            ! $access->can(StudioAccess::PUBLISH) => $no('forbidden', 'Hace falta el permiso «Publicar versiones».'),
            $llavePublicar !== null => $no($llavePublicar['code'], $llavePublicar['message']),
            $mia => $no('four_eyes_required', 'Nadie publica lo que creó: tiene que publicarla otra persona.'),
            $estado !== StudioVersionStatus::Approved => $no('invalid_transition', 'Solo se publica una versión aprobada.'),
            default => ['allowed' => true],
        };

        $promover = match (true) {
            ! $access->can(StudioAccess::PUBLISH) => $no('forbidden', 'Hace falta el permiso «Publicar versiones».'),
            $llavePromover !== null => $no($llavePromover['code'], $llavePromover['message']),
            $mia => $no('four_eyes_required', 'Nadie promueve lo que creó: tiene que promoverla otra persona.'),
            self::engineScope() !== self::SCOPE_ALL => $no(self::CODE_SCOPE_NOT_ALLOWED, 'Promover exige que el motor lea la cadena en todas las conversaciones (su llave en «all»).'),
            $estado !== StudioVersionStatus::Canary => $no('invalid_transition', 'Solo se promueve la versión en canario.'),
            default => ['allowed' => true],
        };

        $revertir = match (true) {
            ! $access->can(StudioAccess::ROLLBACK) => $no('forbidden', 'Hace falta el permiso «Revertir versiones».'),
            ! $estado->isActivePublication() => $no('not_active_publication', 'Solo se revierte la versión en canario o la publicada.'),
            default => ['allowed' => true],
        };

        return ['publish' => $publicar, 'promote' => $promover, 'rollback' => $revertir];
    }
}
