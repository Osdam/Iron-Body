<?php

namespace App\Support;

/**
 * Un documento de identidad tapado: «••••••1234».
 *
 * Es el algoritmo con que el estado de la app ya enseñaba el documento del
 * socio (MemberAppStateController, `document_number_masked`), sacado aquí para
 * que el CRM lo use igual donde no hace falta el documento entero. Mismo largo
 * que el original y los cuatro últimos a la vista: basta para distinguir a dos
 * personas que se llaman igual y no sirve para suplantar a ninguna.
 *
 * Un documento de cuatro caracteres o menos se tapa entero: sus «cuatro
 * últimos» serían el documento completo.
 */
final class DocumentMask
{
    public static function mask(?string $doc): ?string
    {
        $doc = (string) $doc;
        if (strlen($doc) <= 4) {
            return $doc === '' ? null : str_repeat('•', strlen($doc));
        }

        return str_repeat('•', strlen($doc) - 4).substr($doc, -4);
    }
}
