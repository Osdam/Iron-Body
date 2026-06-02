<?php

namespace App\Services\Contracts;

use RuntimeException;

/**
 * Se lanza cuando una plantilla oficial no existe, no está registrada o su
 * archivo fuente no coincide con el checksum esperado. El sistema NUNCA genera
 * un contrato "parecido" ni un PDF falso: falla con un error claro.
 */
class ContractTemplateException extends RuntimeException
{
}
