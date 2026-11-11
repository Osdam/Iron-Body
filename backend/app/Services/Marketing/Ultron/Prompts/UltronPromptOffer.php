<?php

namespace App\Services\Marketing\Ultron\Prompts;

use App\Services\Marketing\Ultron\UltronPublishedConfig;
use App\Services\Observability\ChannelLog;
use Throwable;

/**
 * LA OFERTA DE PROMPTS DE `decide` (Enmienda M-1 del contrato H5; plan de
 * motores §B.5).
 *
 * Cuando n8n negocia `prompt_contract: "ultron-prompts/1"`, `decide` responde
 * con `studio_prompts`: o los cinco prompts compilados desde la parte
 * publicada de esta conversación (`mode: studio`), o nada (`mode: legacy`, con
 * el motivo) y n8n usa sus constantes Legacy, que son la base byte a byte.
 *
 * EL ALGORITMO (§B.5), en este orden; el primer paso que no se cumple da el
 * motivo y la respuesta es Legacy:
 *
 *   1. llave `marketing.ultron.studio_prompts` ≠ `canary`        → prompts_off
 *   2. llave `marketing.ultron.studio_config` = `off`             → studio_config_off
 *   3. canario del motor nulo o distinto de esta conversación     → out_of_scope
 *   4. la parte publicada (`UltronPublishedConfig::partFor()`):
 *        sin cabeza → no_publication; parte nula → part_legacy;
 *        `/1` → declaration_v1; fallo de verificación → publication_<motivo>
 *   5. base o versión del compilador firmadas ≠ las del código    → base_changed / compiler_changed
 *   6. compilar los gobernados firmados; tope superado → too_large; cualquier otro fallo → error
 *   7. huella calculada ≠ la firmada                              → bundle_mismatch
 *   8. todo cuadra                                                → studio
 *
 * Los pasos 1 a 3 no consultan nada: con cualquiera de las dos llaves apagada,
 * o fuera de la canaria, `decide` no lee ni una fila del Studio. El paso 4 es
 * UNA sentencia (la cabeza de la cadena). Con el canario quitado del motor,
 * nadie recibe prompts: falla cerrado.
 *
 * LO QUE APROBARON CUATRO OJOS ES LO QUE SE ENVÍA: el motor recompila desde la
 * base del repositorio con los valores firmados y solo entrega si su huella es
 * la firmada al publicar. No importa nada del Studio.
 *
 * NUNCA LANZA. Ante versión ausente, firma inválida, base cambiada o cualquier
 * error: Legacy, nunca un turno mudo. Al log va el motivo y la clase del
 * error, nunca un texto.
 *
 * SIN DATOS PERSONALES: la respuesta lleva códigos, huellas, el uuid de la
 * versión y de la entrada, y textos que solo salen de la base y de los
 * parches fijos del compilador. Ni ids de conversación ni nombres.
 */
final class UltronPromptOffer
{
    /** Los dos valores de la llave. Cualquier otra cosa es `off`. */
    public const KEY_OFF = 'off';

    public const KEY_CANARY = 'canary';

    public const MODE_STUDIO = UltronPromptStamp::MODE_STUDIO;

    public const MODE_LEGACY = UltronPromptStamp::MODE_LEGACY;

    // ── Motivos de `legacy` (§B.5) ──────────────────────────────────────────

    public const PROMPTS_OFF = 'prompts_off';

    public const STUDIO_CONFIG_OFF = 'studio_config_off';

    public const OUT_OF_SCOPE = 'out_of_scope';

    public const NO_PUBLICATION = 'no_publication';

    public const PART_LEGACY = 'part_legacy';

    public const DECLARATION_V1 = 'declaration_v1';

    /** Prefijo de los fallos de verificación de la cabeza: `publication_<contract_unknown|hash_mismatch|…>`. */
    public const PUBLICATION_PREFIX = 'publication_';

    public const BASE_CHANGED = 'base_changed';

    public const COMPILER_CHANGED = 'compiler_changed';

    public const BUNDLE_MISMATCH = 'bundle_mismatch';

    public const TOO_LARGE = 'too_large';

    public const ERROR = 'error';

    /** Motivos que avisan de algo raro (al log). Los demás son el funcionamiento normal. */
    private const WARN = [self::BASE_CHANGED, self::COMPILER_CHANGED, self::BUNDLE_MISMATCH, self::TOO_LARGE, self::ERROR];

    public function __construct(
        private readonly UltronPublishedConfig $published = new UltronPublishedConfig,
    ) {}

    /**
     * La oferta para esta conversación: lo que va en la respuesta de `decide`
     * (`studio_prompts`) y lo que va firmado en el token (`x.sp`). Nunca lanza.
     *
     * @return array{response:array{contract:string,mode:string,reason:?string,base:string,compiler:int,bundle_hash:?string,version:?string,content_hash:?string,entry:?string,seq:?int,roles:?array<string,string>},sp:array{m:string,r:?string,h:?string,v:?string,c:?string,e:?string,s:?int}}
     */
    public function forConversation(int $conversationId): array
    {
        try {
            return $this->offer($conversationId);
        } catch (Throwable $e) {
            return self::legacy(self::ERROR, $e);
        }
    }

    /** `canary` u `off`: el mismo criterio que el fichero de configuración. */
    public static function key(): string
    {
        $valor = config('marketing.ultron.studio_prompts');

        return is_string($valor) && strtolower(trim($valor)) === self::KEY_CANARY ? self::KEY_CANARY : self::KEY_OFF;
    }

    /** @return array{response:array<string,mixed>,sp:array<string,mixed>} */
    private function offer(int $conversationId): array
    {
        // 1-3: sin consultar nada.
        if (self::key() !== self::KEY_CANARY) {
            return self::legacy(self::PROMPTS_OFF);
        }
        if (UltronPublishedConfig::scope() === UltronPublishedConfig::OFF) {
            return self::legacy(self::STUDIO_CONFIG_OFF);
        }
        $canario = config('marketing.ultron.canary_conversation_id');
        if ($canario === null || (int) $canario !== $conversationId) {
            return self::legacy(self::OUT_OF_SCOPE);
        }

        // 4: la parte publicada, en UNA sentencia.
        $parte = $this->published->partFor($conversationId);
        if ($parte === null) {
            return self::legacy(self::STUDIO_CONFIG_OFF);
        }
        switch ($parte['state']) {
            case UltronPublishedConfig::LEGACY:
                return self::legacy($parte['entry'] === null ? self::NO_PUBLICATION : self::PART_LEGACY);
            case UltronPublishedConfig::OUT_OF_SCOPE:
                return self::legacy(self::OUT_OF_SCOPE);
            case UltronPublishedConfig::FALLBACK:
                return self::legacy(self::PUBLICATION_PREFIX.(is_string($parte['reason']) ? $parte['reason'] : UltronPublishedConfig::READ_FAILED));
            case UltronPublishedConfig::APPLIED:
                break;
            default:
                return self::legacy(self::ERROR);
        }
        if ($parte['contract'] !== UltronPublishedConfig::CONTRACT_V2 || ! is_array($parte['governed']) || ! is_array($parte['prompts'])) {
            return self::legacy(self::DECLARATION_V1);
        }

        // 5: la base y el compilador con los que se firmó.
        if (($parte['prompts']['base'] ?? null) !== UltronPromptCompiler::BASE) {
            return self::legacy(self::BASE_CHANGED);
        }
        if (($parte['prompts']['compiler'] ?? null) !== UltronPromptCompiler::VERSION) {
            return self::legacy(self::COMPILER_CHANGED);
        }

        // 6: compilar lo firmado.
        try {
            $paquete = UltronPromptCompiler::compile($parte['governed']);
        } catch (UltronPromptException $e) {
            return self::legacy($e->reason === UltronPromptException::TOO_LARGE ? self::TOO_LARGE : self::ERROR, $e);
        }

        // 7: lo que se envía es exactamente lo que se firmó.
        $firmada = $parte['prompts']['bundleHash'] ?? null;
        if (! is_string($firmada) || ! hash_equals($paquete['bundleHash'], $firmada)) {
            return self::legacy(self::BUNDLE_MISMATCH);
        }

        // 8.
        $respuesta = [
            'contract' => UltronPromptBase::CONTRACT,
            'mode' => self::MODE_STUDIO,
            'reason' => null,
            'base' => UltronPromptCompiler::BASE,
            'compiler' => UltronPromptCompiler::VERSION,
            'bundle_hash' => $paquete['bundleHash'],
            'version' => $parte['version'],
            'content_hash' => $parte['contentHash'],
            'entry' => $parte['entry'],
            'seq' => $parte['seq'],
            'roles' => $paquete['roles'],
        ];

        return ['response' => $respuesta, 'sp' => self::sp($respuesta)];
    }

    /**
     * Legacy con su motivo: sin roles, sin huella y sin publicación.
     *
     * @return array{response:array<string,mixed>,sp:array<string,mixed>}
     */
    private static function legacy(string $motivo, ?Throwable $error = null): array
    {
        if (in_array($motivo, self::WARN, true)) {
            try {
                ChannelLog::warning('ultron.studio_prompts.offer_legacy', array_filter([
                    'reason' => $motivo,
                    'error_class' => $error === null ? null : class_basename($error),
                    'error_reason' => $error instanceof UltronPromptException ? $error->reason : null,
                ], fn ($v) => $v !== null));
            } catch (Throwable) {
                // Tragado a propósito: un log que no se puede escribir no es
                // motivo para que `decide` falle; la oferta ya es Legacy.
            }
        }

        $respuesta = [
            'contract' => UltronPromptBase::CONTRACT,
            'mode' => self::MODE_LEGACY,
            'reason' => $motivo,
            'base' => UltronPromptCompiler::BASE,
            'compiler' => UltronPromptCompiler::VERSION,
            'bundle_hash' => null,
            'version' => null,
            'content_hash' => null,
            'entry' => null,
            'seq' => null,
            'roles' => null,
        ];

        return ['response' => $respuesta, 'sp' => self::sp($respuesta)];
    }

    /**
     * Lo que viaja firmado en el token (`x.sp`), con claves cortas: modo,
     * motivo, huella del paquete, versión, huella de contenido, entrada y seq.
     *
     * @param  array<string,mixed>  $respuesta
     * @return array{m:string,r:?string,h:?string,v:?string,c:?string,e:?string,s:?int}
     */
    private static function sp(array $respuesta): array
    {
        return [
            'm' => $respuesta['mode'],
            'r' => $respuesta['reason'],
            'h' => $respuesta['bundle_hash'],
            'v' => $respuesta['version'],
            'c' => $respuesta['content_hash'],
            'e' => $respuesta['entry'],
            's' => $respuesta['seq'],
        ];
    }
}
