<?php

namespace Tests\Unit\Voice\Support;

use RuntimeException;

/**
 * LA COPIA CERRADA DE LOS LECTORES DE LA CONSTANTE, EN EL MISMO PROCESO (C-4).
 *
 * Desde el commit `voice(calls-open)` el árbol tiene
 * `VoiceCallPolicy::CALLS_ENABLED_IN_CODE = true`. El estado cerrado —el
 * destino de la vuelta atrás— ya no se alcanza desde el árbol: la constante no
 * es un hecho del contexto, la lee la clase. Como el arnés del E2E
 * (`docs/ultron-studio/ops/h8-e2e.sh`, que gira la constante en una copia del
 * árbol y comprueba con `diff` que es lo único que cambia), esto carga una
 * COPIA, con la constante cerrada y otro nombre de clase, de todo lo que la
 * lee para decidir:
 *  - `VoiceCallPolicy`: `= true;` → `= false;` y la clase con el sufijo;
 *  - las dos políticas de la telefonía (`InboundPolicy`, `OutboundPolicy`), el
 *    job de la saliente (`PlaceOutboundVoiceCall`), el iniciador
 *    (`ElevenLabsCallStarter`) y el desplegador (`ElevenLabsAgentDeployer`):
 *    la clase con el sufijo y su `use` de `VoiceCallPolicy` apuntando a la
 *    copia cerrada (con alias: el cuerpo no cambia ni una letra).
 * (El doctor y el estado de la telefonía también la leen, pero solo para
 * decirla.)
 *
 * Cada sustitución tiene que darse EXACTAMENTE una vez y no cambia nada más
 * (lo comprueba {@see changedLines()}). En un árbol sin la constante abierta
 * literal no hay copia: cargarla lanza, y su prueba cae como una guarda más.
 *
 * No toca `app/` ni escribe en el árbol: el código transformado va a un fichero
 * temporal del sistema, se carga y se borra.
 */
final class ClosedPolicyCopy
{
    public const SUFFIX = 'CopiaCerrada';

    public const VOICE = 'App\\Services\\Voice\\VoiceCallPolicy'.self::SUFFIX;

    public const INBOUND = 'App\\Services\\Telephony\\InboundPolicy'.self::SUFFIX;

    public const OUTBOUND = 'App\\Services\\Telephony\\OutboundPolicy'.self::SUFFIX;

    public const JOB = 'App\\Jobs\\Voice\\PlaceOutboundVoiceCall'.self::SUFFIX;

    public const STARTER = 'App\\Services\\Voice\\ElevenLabs\\ElevenLabsCallStarter'.self::SUFFIX;

    public const DEPLOYER = 'App\\Services\\Voice\\ElevenLabs\\ElevenLabsAgentDeployer'.self::SUFFIX;

    /** Fichero → la clase de su copia, en el orden de carga (la política del H6 primero: las demás la usan). */
    public const COPIES = [
        'app/Services/Voice/VoiceCallPolicy.php' => self::VOICE,
        'app/Services/Telephony/InboundPolicy.php' => self::INBOUND,
        'app/Services/Telephony/OutboundPolicy.php' => self::OUTBOUND,
        'app/Jobs/Voice/PlaceOutboundVoiceCall.php' => self::JOB,
        'app/Services/Voice/ElevenLabs/ElevenLabsCallStarter.php' => self::STARTER,
        'app/Services/Voice/ElevenLabs/ElevenLabsAgentDeployer.php' => self::DEPLOYER,
    ];

    /** La línea abierta, tal cual la escribe el commit `voice(calls-open)`. */
    public const OPEN_LINE = 'public const CALLS_ENABLED_IN_CODE = true;';

    public const CLOSED_LINE = 'public const CALLS_ENABLED_IN_CODE = false;';

    /**
     * Fichero → [lo que se busca (exactamente una vez) → lo que se pone], en el
     * orden en que se cargan (la de voz primero: las otras dos la usan).
     *
     * @return array<string, array<string, string>>
     */
    public static function replacements(): array
    {
        $usoVoz = "\nuse App\\Services\\Voice\\VoiceCallPolicy;\n";
        $usoCopia = "\nuse App\\Services\\Voice\\VoiceCallPolicy".self::SUFFIX." as VoiceCallPolicy;\n";

        return [
            'app/Services/Voice/VoiceCallPolicy.php' => [
                self::OPEN_LINE => self::CLOSED_LINE,
                "\nfinal class VoiceCallPolicy\n" => "\nfinal class VoiceCallPolicy".self::SUFFIX."\n",
            ],
            'app/Services/Telephony/InboundPolicy.php' => [
                $usoVoz => $usoCopia,
                "\nfinal class InboundPolicy\n" => "\nfinal class InboundPolicy".self::SUFFIX."\n",
            ],
            'app/Services/Telephony/OutboundPolicy.php' => [
                $usoVoz => $usoCopia,
                "\nfinal class OutboundPolicy\n" => "\nfinal class OutboundPolicy".self::SUFFIX."\n",
            ],
            'app/Jobs/Voice/PlaceOutboundVoiceCall.php' => [
                $usoVoz => $usoCopia,
                "\nclass PlaceOutboundVoiceCall implements " => "\nclass PlaceOutboundVoiceCall".self::SUFFIX.' implements ',
            ],
            'app/Services/Voice/ElevenLabs/ElevenLabsCallStarter.php' => [
                $usoVoz => $usoCopia,
                "\nfinal class ElevenLabsCallStarter implements " => "\nfinal class ElevenLabsCallStarter".self::SUFFIX.' implements ',
            ],
            'app/Services/Voice/ElevenLabs/ElevenLabsAgentDeployer.php' => [
                $usoVoz => $usoCopia,
                "\nfinal class ElevenLabsAgentDeployer implements " => "\nfinal class ElevenLabsAgentDeployer".self::SUFFIX.' implements ',
            ],
        ];
    }

    public static function backend(): string
    {
        return dirname(__DIR__, 4);
    }

    /** El fichero real del árbol. */
    public static function original(string $relativa): string
    {
        $codigo = file_get_contents(self::backend().'/'.$relativa);
        if (! is_string($codigo)) {
            throw new RuntimeException("No se pudo leer {$relativa}.");
        }

        return $codigo;
    }

    /** La copia cerrada de `$relativa`: sus sustituciones, cada una exactamente una vez. */
    public static function source(string $relativa): string
    {
        $codigo = self::original($relativa);
        foreach (self::replacements()[$relativa] as $busca => $pone) {
            $veces = substr_count($codigo, $busca);
            if ($veces !== 1) {
                throw new RuntimeException("La copia cerrada de {$relativa} exige «".trim($busca)."» exactamente una vez y está {$veces}: sin la constante abierta en el árbol no hay copia cerrada.");
            }
            $codigo = str_replace($busca, $pone, $codigo);
        }

        return $codigo;
    }

    /**
     * Las líneas (1-based) en que la copia difiere del original, con las dos
     * versiones. Mismo número de líneas: las sustituciones no añaden ni quitan.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function changedLines(string $relativa): array
    {
        $antes = explode("\n", self::original($relativa));
        $despues = explode("\n", self::source($relativa));
        if (count($antes) !== count($despues)) {
            throw new RuntimeException("La copia cerrada de {$relativa} cambió el número de líneas.");
        }
        $cambios = [];
        foreach ($antes as $i => $linea) {
            if ($linea !== $despues[$i]) {
                $cambios[$i + 1] = [$linea, $despues[$i]];
            }
        }

        return $cambios;
    }

    /** Carga las copias (una vez por proceso). */
    public static function load(): void
    {
        foreach (self::COPIES as $relativa => $clase) {
            if (class_exists($clase, false)) {
                continue;
            }
            // La copia solo puede diferir del original en dos líneas (la clase y la constante, o la clase y su `use`):
            // se exige AQUÍ, no solo en una prueba, para que valga también cuando una prueba de la copia corre sola.
            $cambios = count(self::changedLines($relativa));
            if ($cambios !== 2) {
                throw new RuntimeException("La copia cerrada de {$relativa} cambia {$cambios} líneas y solo puede cambiar 2.");
            }
            $fichero = tempnam(sys_get_temp_dir(), 'ib-copia-cerrada-');
            if ($fichero === false) {
                throw new RuntimeException('Sin fichero temporal para la copia cerrada.');
            }
            try {
                file_put_contents($fichero, self::source($relativa));
                require $fichero;
            } finally {
                if (is_file($fichero)) {
                    unlink($fichero);
                }
            }
            if (! class_exists($clase, false)) {
                throw new RuntimeException("La copia cerrada de {$relativa} no declaró {$clase}.");
            }
        }
    }
}
