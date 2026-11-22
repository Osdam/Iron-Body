<?php

namespace App\Services\Outreach\Run;

use App\Models\OutreachConsent;

/**
 * C9-3 · Lo que el PROPIETARIO decide de las autorizaciones de contacto
 * (`config/outreach_consents.php`; Enmienda C9): el texto legal de cada
 * finalidad y las fuentes admitidas para registrar. Lector propio (no
 * `OutreachConfig`). FALLA CERRADO: una versión o un texto que no tienen la
 * forma que la base admite (`ocon_legal_chk`) cuentan como NO configurados.
 */
final class OutreachConsentConfig
{
    /** El 409 de registrar o confirmar sin texto legal (estable; lo lee el CRM). */
    public const CODE_LEGAL_TEXT_MISSING = 'consent_legal_text_missing';

    /** La de `ocon_legal_chk` (`LEGAL_VERSION_PATTERN` de la migración C9-1). */
    public const VERSION_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,47}$/D';

    public const TEXT_MAX = 4000;

    /**
     * El texto legal vigente de una finalidad, o null si el propietario aún no lo ha fijado.
     *
     * @return array{version: string, text: string, hash: string}|null
     */
    public static function legalText(string $purpose): ?array
    {
        if (! in_array($purpose, OutreachConsent::PURPOSES, true)) {
            return null;
        }
        $c = config("outreach_consents.legal.{$purpose}");
        $version = is_array($c) ? ($c['version'] ?? null) : null;
        $texto = is_array($c) ? ($c['text'] ?? null) : null;
        if (! is_string($version) || preg_match(self::VERSION_PATTERN, $version) !== 1) {
            return null;
        }
        if (! is_string($texto) || trim($texto) === '' || ! mb_check_encoding($texto, 'UTF-8') || mb_strlen($texto, 'UTF-8') > self::TEXT_MAX
            || preg_match('/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}-\x{009F}]/u', $texto) !== 0) {
            return null;
        }

        return ['version' => $version, 'text' => $texto, 'hash' => hash('sha256', $texto)];
    }

    /** ¿Hay texto legal para alguna finalidad? Sin ninguno, registrar y confirmar dan 409 antes de leer el cuerpo. */
    public static function anyLegalText(): bool
    {
        foreach (OutreachConsent::PURPOSES as $p) {
            if (self::legalText($p) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Las fuentes admitidas para REGISTRAR: las configuradas que el libro admite
     * (`ocon_source_chk`), en su orden. Retirar admite siempre las del libro.
     *
     * @return list<string>
     */
    public static function sources(): array
    {
        $c = config('outreach_consents.sources');
        if (! is_array($c)) {
            return OutreachConsent::SOURCES;
        }

        return array_values(array_filter(OutreachConsent::SOURCES, static fn (string $s): bool => in_array($s, $c, true)));
    }
}
