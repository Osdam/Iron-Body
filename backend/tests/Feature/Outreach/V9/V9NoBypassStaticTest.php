<?php

namespace Tests\Feature\Outreach\V9;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * V9 · NINGÚN CAMINO ALTERNATIVO (contrato H9, §3 protección 4, D9-2, D9-9.4):
 * una llamada de estrategia que no pase por `OutboundGate` es IMPOSIBLE, por
 * construcción y por tokens (como `TelephonyIsolationTest` en el H8):
 *
 *  1. de la telefonía, la ejecución del H9 solo nombra `OutboundGate` en UN
 *     fichero (el canal de voz) y de ella solo llama a `requestForStrategy`;
 *     nunca el job de la saliente, el iniciador, los adaptadores, la política
 *     ni el registro de llamadas, eventos o exclusiones (ni sus tablas para
 *     escribir);
 *  2. `requestForStrategy` solo se llama desde ese canal, en toda la
 *     aplicación;
 *  3. la entrada recorre la puerta: el cerrojo, LA política del H8 y el job
 *     de la saliente (que es el único que marca, y vuelve a mirarlo todo); no
 *     marca ella misma;
 *  4. el solicitante de una saliente solo lo escribe la puerta, y el trabajo
 *     de la saliente solo lo despacha la puerta.
 */
class V9NoBypassStaticTest extends TestCase
{
    /** La ejecución del H9: lo que corre en producción y puede querer «llamar». */
    private const H9_RUN = ['app/Services/Outreach', 'app/Console/Commands/Outreach', 'app/Jobs/Outreach'];

    private const CHANNEL = 'app/Services/Outreach/Run/Channels/VoiceStrategyChannel.php';

    private const GATE = 'app/Services/Telephony/OutboundGate.php';

    /**
     * Lo que el H9 no puede NOMBRAR (como clase, función o método) de la voz
     * del H8: marcar, decidir por su cuenta o escribir su registro. Se mira en
     * los identificadores del código, no en cadenas ni comentarios (un mensaje
     * puede citar la política del H8).
     */
    private const FORBIDDEN_IDENTIFIERS = [
        'PlaceOutboundVoiceCall', 'VoiceCallStarter', 'startOutbound', 'VoiceAdapters', 'OutboundCallRequest', 'OutboundPolicy', 'VoiceCallPolicy',
        'VoiceEvents', 'VoiceContacts', 'VoiceHangup', 'InboundGate', 'VoiceCall', 'VoiceCallEvent', 'VoiceContact', 'VoiceExclusion', 'VoiceConsent',
    ];

    public function test_de_la_telefonia_el_h9_solo_nombra_la_puerta_en_su_canal_y_solo_su_entrada_de_estrategias(): void
    {
        $vistos = 0;
        $nombranLaPuerta = [];
        foreach ($this->php(...self::H9_RUN) as $relativa) {
            $vistos++;
            $codigo = $this->codeOnly($relativa);
            foreach ($this->identifiers($relativa) as $nombre) {
                $corto = substr((string) strrchr('\\'.$nombre, '\\'), 1);
                $this->assertNotContains($corto, self::FORBIDDEN_IDENTIFIERS, "{$relativa} nombra «{$nombre}»: la voz del H9 solo entra por OutboundGate::requestForStrategy()");
                $this->assertDoesNotMatchRegularExpression('/ElevenLabs|Twilio|^App\\\\(Models|Jobs)\\\\Voice\\\\/', $nombre, "{$relativa} nombra «{$nombre}»");
            }
            // Ninguna escritura de las tablas de la voz desde el H9 (leerlas es de `Facts`): ni por el constructor ni en SQL crudo.
            $this->assertDoesNotMatchRegularExpression("/table\\(\\s*'voice_[a-z_]+'\\s*\\)\\s*->\\s*(insert|insertOrIgnore|insertGetId|update|upsert|delete|increment|decrement)/i", $codigo, "{$relativa} escribe una tabla de la voz");
            foreach (token_get_all((string) file_get_contents(base_path($relativa))) as $t) {
                if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    $this->assertDoesNotMatchRegularExpression('/(insert\\s+(or\\s+ignore\\s+)?into|update|delete\\s+from|truncate)\\s+"?voice_/i', $t[1], "{$relativa} escribe una tabla de la voz en SQL");
                }
            }
            if (str_contains($codigo, 'Telephony\\OutboundGate') || preg_match('/\bOutboundGate\b/', $codigo) === 1) {
                $nombranLaPuerta[] = $relativa;
            }
            // De la telefonía, solo las reglas puras, el freno (lectura) y la puerta.
            foreach ($this->qualifiedNames($relativa) as $nombre) {
                if (str_starts_with($nombre, 'App\\Services\\Telephony\\')) {
                    $this->assertContains($nombre, ['App\\Services\\Telephony\\ColombianHolidays', 'App\\Services\\Telephony\\OutboundSchedule', 'App\\Services\\Telephony\\VoiceFrequency',
                        'App\\Services\\Telephony\\VoiceConsentRule', 'App\\Services\\Telephony\\VoiceExclusionRule', 'App\\Services\\Telephony\\VoiceBrake',
                        'App\\Services\\Telephony\\OutboundGate'], "{$relativa} importa {$nombre}");
                }
            }
        }
        $this->assertGreaterThan(50, $vistos, 'el rastreo ve la ejecución del H9');
        $this->assertSame([self::CHANNEL], $nombranLaPuerta, 'la puerta, desde un solo fichero del H9');

        // Y de la puerta, solo la entrada de las estrategias (ni `request`, ni los hechos, ni los destinos).
        $tokens = $this->significant(self::CHANNEL);
        $llamadas = [];
        foreach ($tokens as $i => $t) {
            if (is_array($t) && $t[0] === T_STRING && in_array($t[1], ['request', 'requestForStrategy', 'targetFacts', 'targetOf', 'currentScope', 'strategyRequestErrors'], true)
                && is_array($tokens[$i - 1] ?? null) && in_array($tokens[$i - 1][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $llamadas[] = $t[1];
            }
        }
        $this->assertSame(['requestForStrategy'], array_values(array_unique($llamadas)), 'el canal solo pide por la entrada de las estrategias');
    }

    public function test_requestforstrategy_solo_se_llama_desde_el_canal_de_voz(): void
    {
        $quien = [];
        foreach ($this->php('app', 'routes', 'config', 'bootstrap', 'database') as $relativa) {
            if (str_contains($this->codeOnly($relativa), 'requestForStrategy(') && $relativa !== self::GATE) {
                $quien[] = $relativa;
            }
        }
        $this->assertSame([self::CHANNEL], $quien);
    }

    public function test_la_entrada_recorre_la_puerta_entera_y_no_marca_ella_misma(): void
    {
        $codigo = $this->codeOnly(self::GATE);
        $entrada = $this->methodBody($codigo, 'requestForStrategy');
        $decision = $this->methodBody($codigo, 'decideForStrategy');
        $destino = $this->methodBody($codigo, 'strategyTarget');

        $this->assertStringContainsString('DB::transaction(', $entrada, 'una transacción');
        $this->assertStringContainsString('$this->decideForStrategy(', $entrada);
        $this->assertStringStartsWith('{'."\n        VoiceLocks::gate();", $decision, 'el cerrojo de la puerta, la primera sentencia');
        $this->assertSame(1, substr_count($decision, 'OutboundPolicy::decide('), 'LA política del H8, una vez');
        $this->assertStringContainsString('...self::targetFacts($destino, $alcance, $ahora)', $decision, 'los hechos del destino y del calendario de la puerta (los que repite el job)');
        $this->assertStringContainsString("'duplicate' => false", $decision);
        $this->assertSame(1, substr_count($decision, 'PlaceOutboundVoiceCall::dispatch('), 'el job de la saliente, el único que marca');
        $this->assertStringContainsString('->afterCommit()', $decision, 'al confirmar');
        foreach ([$entrada, $decision, $destino] as $cuerpo) {
            $this->assertStringNotContainsString('startOutbound', $cuerpo, 'la puerta nunca marca');
            $this->assertStringNotContainsString('VoiceAdapters', $cuerpo);
            $this->assertStringNotContainsString('Http::', $cuerpo);
        }
        // Cada hecho de la política que da la entrada de las personas, también aquí (la misma decisión).
        foreach (['outboundEnabled', 'twilioVoiceEnabled', 'voiceFromConfigured', 'voiceNumberCollides', 'numberCheck', 'providerState', 'publication', 'agentDrift',
            'braked', 'frequencyExceeded', 'activeOrDialing', 'maxConcurrent', 'dailyCap', 'placedToday', 'budgetFits', 'duplicate'] as $hecho) {
            $this->assertSame(substr_count($this->methodBody($codigo, 'decide'), "'{$hecho}' =>"), substr_count($decision, "'{$hecho}' =>"), "el hecho {$hecho}");
        }
    }

    public function test_el_solicitante_y_el_job_de_la_saliente_solo_los_escribe_la_puerta(): void
    {
        $escriben = [];
        $despachan = [];
        foreach ($this->php('app') as $relativa) {
            $codigo = $this->codeOnly($relativa);
            if (preg_match("/'requested_by_type'\\s*=>/", $codigo) === 1) {
                $escriben[] = $relativa;
            }
            if (str_contains($codigo, 'PlaceOutboundVoiceCall::dispatch')) {
                $despachan[] = $relativa;
            }
        }
        $this->assertSame([self::GATE], $escriben, 'quién pidió una saliente lo escribe solo la puerta');
        $this->assertSame([self::GATE], $despachan, 'el job de la saliente lo despacha solo la puerta');
        $this->assertSame(2, preg_match_all("/'requested_by_type'\\s*=>/", $this->codeOnly(self::GATE)), 'las dos entradas: personas y estrategias');
    }

    // ────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function php(string ...$raices): array
    {
        $base = rtrim(base_path(), '/').'/';
        $out = [];
        foreach ($raices as $raiz) {
            if (is_file($base.$raiz)) {
                $out[] = $raiz;

                continue;
            }
            if (! is_dir($base.$raiz)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.$raiz, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && $f->getExtension() === 'php' && ! str_contains($f->getPathname(), '/bootstrap/cache/')) {
                    $out[] = substr($f->getPathname(), strlen($base));
                }
            }
        }
        sort($out);

        return $out;
    }

    private function codeOnly(string $relativa): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(base_path($relativa))) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    /** @return list<array{0:int,1:string,2:int}|string> */
    private function significant(string $relativa): array
    {
        return array_values(array_filter(token_get_all((string) file_get_contents(base_path($relativa))),
            static fn ($t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    }

    /** Los identificadores del código (clases, funciones, métodos, constantes), sin cadenas ni comentarios. @return list<string> */
    private function identifiers(string $relativa): array
    {
        $out = [];
        foreach (token_get_all((string) file_get_contents(base_path($relativa))) as $t) {
            if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $out[] = ltrim($t[1], '\\');
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private function qualifiedNames(string $relativa): array
    {
        $out = [];
        foreach (token_get_all((string) file_get_contents(base_path($relativa))) as $t) {
            if (is_array($t) && in_array($t[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $out[] = ltrim($t[1], '\\');
            }
        }

        return $out;
    }

    private function methodBody(string $codigo, string $metodo): string
    {
        $inicio = strpos($codigo, 'function '.$metodo.'(');
        $this->assertNotFalse($inicio, "No existe el método {$metodo}().");
        $abre = strpos($codigo, '{', strpos($codigo, ')', $inicio));
        for ($i = $abre, $nivel = 0, $n = strlen($codigo); $i < $n; $i++) {
            $nivel += $codigo[$i] === '{' ? 1 : ($codigo[$i] === '}' ? -1 : 0);
            if ($nivel === 0) {
                return substr($codigo, $abre, $i - $abre + 1);
            }
        }
        $this->fail("El método {$metodo}() no se cierra.");
    }
}
