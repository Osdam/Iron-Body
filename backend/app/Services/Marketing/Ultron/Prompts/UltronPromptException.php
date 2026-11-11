<?php

namespace App\Services\Marketing\Ultron\Prompts;

use RuntimeException;

/**
 * Por qué no se pudo cargar la base o compilar un paquete de prompts.
 *
 * La lanzan {@see UltronPromptBase} y {@see UltronPromptCompiler}; quien las
 * llama decide qué hacer. En el motor la respuesta es siempre la misma: Legacy
 * ({@see UltronPromptOffer} nunca lanza). En el Studio, publicar responde 409
 * `prompts_compile_failed`: nunca se firma una parte que no compila.
 *
 * `reason` es un código cerrado (las constantes de abajo), que es lo único que
 * se puede registrar. El mensaje es para quien depura en local: nombra el rol,
 * el parámetro o el ancla, nunca un texto del prompt.
 */
final class UltronPromptException extends RuntimeException
{
    /** Un fichero de la base no se pudo leer. */
    public const BASE_UNREADABLE = 'base_unreadable';

    /** Un fichero de la base no tiene los bytes fijados (su sha256 no cuadra). */
    public const BASE_CORRUPT = 'base_corrupt';

    /** Los valores gobernados no tienen las claves, los tipos o los valores del contrato. */
    public const INVALID_GOVERNED = 'invalid_governed';

    /** Un ancla no aparece exactamente una vez en el texto que va a parchear. */
    public const ANCHOR = 'anchor';

    /** Un parche, un rol o el paquete superan su tope. */
    public const TOO_LARGE = 'too_large';

    /** Un rol que no es de los cinco, o un paquete al que le falta o le sobra alguno. */
    public const ROLES = 'roles';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
