<?php

namespace App\Services\Nutrition;

/**
 * Normaliza y razona sobre códigos de barras de producto (EAN/UPC/GTIN).
 *
 * Reglas críticas:
 *  - SIEMPRE se trata como STRING (preserva ceros a la izquierda; nunca integer).
 *  - Soporta EAN-13, EAN-8, UPC-A (12), UPC-E (8) y GTIN-14.
 *  - UPC-A → EAN-13 anteponiendo un cero; GTIN-14 → EAN-13/UPC-A quitando ceros.
 *  - El dígito de control se valida pero NO bloquea (un mal dígito puede ser un
 *    producto recuperable; preferimos intentar resolver antes que rechazar).
 *  - Genera VARIANTES equivalentes para buscar el mismo producto escrito distinto.
 */
class BarcodeNormalizer
{
    /** Limpia a solo dígitos (quita espacios, guiones, etc.). Preserva ceros. */
    public function clean(?string $raw): string
    {
        return preg_replace('/\D/', '', (string) $raw) ?? '';
    }

    /**
     * Longitud plausible de un código de producto (8–14 dígitos). Las longitudes
     * estándar (8/12/13/14) habilitan expansión de variantes; las intermedias se
     * aceptan igual y se buscan tal cual (no se rechazan por ser recuperables).
     */
    public function isPlausible(string $code): bool
    {
        $len = strlen($code);
        return $len >= 8 && $len <= 14;
    }

    /** Tipo legible del código (para diagnóstico/logs). */
    public function type(string $code): string
    {
        return match (strlen($code)) {
            8  => 'EAN-8/UPC-E',
            12 => 'UPC-A',
            13 => 'EAN-13',
            14 => 'GTIN-14',
            default => 'unknown',
        };
    }

    /**
     * Forma canónica para almacenar/buscar: GTIN-13 (EAN-13) cuando es posible.
     * UPC-A → EAN-13 (con cero). GTIN-14 → EAN-13 si los ceros lo permiten.
     * EAN-8 se mantiene (no se puede expandir sin pérdida).
     */
    public function canonical(string $code): string
    {
        $len = strlen($code);
        if ($len === 12) {
            return '0' . $code; // UPC-A → EAN-13
        }
        if ($len === 14) {
            $trimmed = ltrim($code, '0');
            // GTIN-14 con un solo cero de relleno → EAN-13.
            return strlen($code) - strlen($trimmed) >= 1 && strlen($trimmed) <= 13
                ? str_pad($trimmed, 13, '0', STR_PAD_LEFT)
                : $code;
        }
        return $code;
    }

    /**
     * Conjunto de variantes equivalentes a probar (incluye el original y la
     * canónica). Cubre UPC-A↔EAN-13, GTIN-14, ceros de relleno y expansión UPC-E.
     *
     * @return string[] sin duplicados, en orden de preferencia
     */
    public function variants(string $code): array
    {
        $out = [$code, $this->canonical($code)];
        $len = strlen($code);

        if ($len === 12) {
            $out[] = '0' . $code;                 // EAN-13
            $out[] = str_pad($code, 14, '0', STR_PAD_LEFT); // GTIN-14
        } elseif ($len === 13) {
            if (str_starts_with($code, '0')) {
                $out[] = substr($code, 1);        // UPC-A
            }
            $out[] = '0' . $code;                 // GTIN-14
        } elseif ($len === 14) {
            $trim = ltrim($code, '0');
            if ($trim !== '' && strlen($trim) <= 13) {
                $out[] = str_pad($trim, 13, '0', STR_PAD_LEFT);
            }
            if ($trim !== '' && strlen($trim) <= 12) {
                $out[] = str_pad($trim, 12, '0', STR_PAD_LEFT);
            }
        } elseif ($len === 8) {
            // Puede ser EAN-8 (válido tal cual) o UPC-E (expandible a UPC-A→EAN-13).
            $upcA = $this->expandUpcE($code);
            if ($upcA !== null) {
                $out[] = $upcA;
                $out[] = '0' . $upcA;             // EAN-13
            }
        }

        // Dedup preservando orden.
        $seen = [];
        $result = [];
        foreach ($out as $v) {
            if ($v !== '' && ! isset($seen[$v])) {
                $seen[$v] = true;
                $result[] = $v;
            }
        }
        return $result;
    }

    /** Valida el dígito de control GTIN (mod-10). No bloquea: solo informa. */
    public function hasValidCheckDigit(string $code): bool
    {
        $len = strlen($code);
        if (! in_array($len, [8, 12, 13, 14], true)) {
            return false;
        }
        $digits = str_split($code);
        $check = (int) array_pop($digits);
        $sum = 0;
        // Desde la derecha del cuerpo, pesos alternos 3 y 1 (el más a la derecha = 3).
        $digits = array_reverse($digits);
        foreach ($digits as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 3 : 1);
        }
        $expected = (10 - ($sum % 10)) % 10;
        return $expected === $check;
    }

    /**
     * Expande un UPC-E (8 dígitos: NS + 6 cuerpo + check) a UPC-A (12). Devuelve
     * null si el formato no corresponde a UPC-E (p.ej. es un EAN-8 real).
     */
    public function expandUpcE(string $code): ?string
    {
        if (strlen($code) !== 8) {
            return null;
        }
        $ns = $code[0];
        if ($ns !== '0' && $ns !== '1') {
            return null; // UPC-E real solo usa sistema numérico 0/1
        }
        $body = substr($code, 1, 6); // 6 dígitos comprimidos
        $check = $code[7];
        $last = $body[5];
        $first5 = substr($body, 0, 5);

        $mfr = '';
        $item = '';
        switch ($last) {
            case '0': case '1': case '2':
                $mfr = substr($first5, 0, 2) . $last . '00';
                $item = '00' . substr($first5, 2, 3);
                break;
            case '3':
                $mfr = substr($first5, 0, 3) . '00';
                $item = '000' . substr($first5, 3, 2);
                break;
            case '4':
                $mfr = substr($first5, 0, 4) . '0';
                $item = '0000' . substr($first5, 4, 1);
                break;
            default: // 5-9
                $mfr = $first5;
                $item = '0000' . $last;
                break;
        }
        return $ns . $mfr . $item . $check;
    }
}
