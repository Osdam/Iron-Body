<?php

namespace App\Services\Outreach\Facts;

use App\Support\Privacy\PhoneFingerprint;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Adaptador de SOLO LECTURA del registro de NÚMEROS CANARIOS del Studio
 * (contrato H9, D9-2 «canarios por huella», D9-11.6). Tabla:
 * `ultron_studio_canary_numbers` (solo `channel`, `status` y `phone_hash`; nunca
 * el cifrado). Los teléfonos de la persona salen de {@see FactPass} (sus
 * prospectos y sus fichas de socio) y se convierten, EN MEMORIA, en la huella
 * del H6 (`PhoneFingerprint`, etiqueta `ultron-canary-phone/1`), como hace
 * `VoiceCanaryDirectory` para la voz.
 *
 * `canary.whatsapp` / `canary.voice` ⇔ alguno de sus números es un canario
 * ACTIVO de ese canal. En CANARY y en `live` una estrategia solo alcanza a esas
 * personas (lo aplica B9-run con estos dos booleanos); en sombra solo se anota.
 */
final class CanaryFacts
{
    public const TABLES = ['ultron_studio_canary_numbers'];

    public const POLICY = ['canary.whatsapp', 'canary.voice'];

    private const CHANNELS = ['whatsapp' => 'canary.whatsapp', 'voice' => 'canary.voice'];

    /** @return array<string, array<string, bool>> por clave pedida */
    public function facts(FactPass $b): array
    {
        $huellasDe = [];
        $todas = [];
        foreach ($b->subjects as $clave => $s) {
            $huellasDe[$clave] = [];
            if (! $s->resolved()) {
                continue;
            }
            foreach ($b->phonesOf($s) as $numero) {
                $e164 = PhoneKey::e164($numero);
                if ($e164 === null) {
                    continue;
                }
                try {
                    $h = PhoneFingerprint::of($e164);
                } catch (InvalidArgumentException) {
                    // Tragado a propósito: un número que no es E.164 no puede ser canario.
                    continue;
                }
                $huellasDe[$clave][] = $h;
                $todas[$h] = true;
            }
        }

        $activos = [];
        if ($todas !== []) {
            $lista = array_keys($todas);
            sort($lista, SORT_STRING);
            foreach (DB::table('ultron_studio_canary_numbers')->where('status', 'active')
                ->whereIn('channel', array_keys(self::CHANNELS))->whereIn('phone_hash', $lista)
                ->get(['channel', 'phone_hash']) as $c) {
                $activos[(string) $c->channel][(string) $c->phone_hash] = true;
            }
        }

        $salida = [];
        foreach ($b->subjects as $clave => $s) {
            $politica = array_fill_keys(self::POLICY, false);
            foreach (self::CHANNELS as $canal => $hecho) {
                foreach ($huellasDe[$clave] as $h) {
                    if (isset($activos[$canal][$h])) {
                        $politica[$hecho] = true;
                    }
                }
            }
            $salida[$clave] = $politica;
        }

        return $salida;
    }
}
