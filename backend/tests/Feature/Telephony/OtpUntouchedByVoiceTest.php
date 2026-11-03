<?php

namespace Tests\Feature\Telephony;

use App\Http\Controllers\Api\TwilioUsageTriggerController;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * EL OTP NO SE TOCA (contrato H8, protección 4 y D8-4). Es de SEC8: quien
 * implementa no la toca; si cae, avisa al lead.
 *
 * El OTP (Twilio Verify con el Auth Token maestro) entra a diario en el login
 * de los socios. La voz va en su subcuenta, con su configuración, su
 * validador y sus rutas. Esta prueba lo fija en las dos direcciones:
 *
 *  1. EL CÓDIGO DEL OTP ES EL DE PRODUCCIÓN, byte a byte (`cfcd0d1` = la base
 *     del H8): `app/Services/Sms/**`, `app/Services/Otp/**`, `config/otp.php` y
 *     `TwilioUsageTriggerController`, con la lista cerrada de ficheros. Es la
 *     «puerta de diff vacía» dentro de la suite.
 *  2. SUS RUTAS NO CAMBIAN: el aviso de gasto y el login de socios, sentencia a
 *     sentencia; en la tabla real, el controlador del aviso atiende una sola
 *     ruta, y `routes/api.php` no declara nada de la voz.
 *  3. LA VOZ NO LEE EL OTP: ni `otp.*`, ni las variables de la credencial
 *     maestra, ni sus clases.
 *  4. EL OTP NO LEE LA VOZ: ni `voice.*`/`telephony.*`, ni sus variables, ni
 *     sus clases.
 *
 * SI UN CAMBIO DEL OTP ESTÁ AUTORIZADO (por ejemplo B-6: la key restringida de
 * Verify y la rotación del maestro), va en su propio commit, con su revisión
 * de seguridad, y ese commit actualiza las huellas de abajo. Nunca dentro de
 * un paquete del H8.
 */
class OtpUntouchedByVoiceTest extends TestCase
{
    /** SHA-256 de cada fichero del OTP en `cfcd0d1` (producción) = `57bab8a` (base del H8). */
    private const HUELLAS = [
        'app/Http/Controllers/Api/TwilioUsageTriggerController.php' => 'ba9df146968360fec96dbec0909f7c0877c92c433d096b5c6e8e4c15a59a3051',
        'app/Services/Otp/OtpCostGuard.php' => 'c7dbd82f43d655eb78a07dcfd2013a9ee8b243556524ab3995699a93feb8d3d5',
        'app/Services/Otp/OtpPolicy.php' => '076e8aa5707d1ebbd790d9c7edb1d871ec05a94f33adbb8471604b4f190a8e1f',
        'app/Services/Sms/DevSmsSender.php' => '53647acbac8bf06c2ab369d6ee3fc9ffe5ab799c7272647c67558d0e73df6785',
        'app/Services/Sms/LabsMobileSmsSender.php' => '0d6799d1fb7c2e4b00b835d22b8e4cb130cb2de9ade69a1605d4bebcaea0e6d5',
        'app/Services/Sms/SmsSender.php' => 'bc265830c2d083be4bb19f5fd2adac35b3814d66fbecfd4ff3e980670d5f77ce',
        'app/Services/Sms/SmsSenderFactory.php' => '393655518a1fca59a77f6a31b1acc080e053d39160cd1ea36f49067d814c5856',
        'app/Services/Sms/TwilioSmsSender.php' => '15d3e2c806bbe28011b4cfd3047a8dbcf407a1564d8d4f000aab8ecbdf17497e',
        'app/Services/Sms/TwilioVerifyService.php' => 'cfa1ff2c74ea75a9b86792e357e14f80b589914aba60208601d45d4ac9072851',
        'config/otp.php' => '2882d8c0e86e032fcfd217534c08dcfa05e8afe70dac2407346d491dcef7f828',
    ];

    /** Los directorios del OTP: lista cerrada (ni un fichero más ni uno menos). */
    private const DIRECTORIOS = ['app/Services/Otp', 'app/Services/Sms'];

    /** Las rutas del OTP en `routes/api.php`, sentencia a sentencia (sin espacios ni comentarios). */
    private const RUTAS = [
        "Route::post('members/login',[AuthController::class,'login'])->middleware('throttle:6,1');",
        "Route::post('members/login/verify',[AuthController::class,'verifyOtp'])->middleware('throttle:10,1');",
        "Route::post('members/login/resend',[AuthController::class,'resendOtp'])->middleware('throttle:4,1');",
        "Route::post('webhooks/twilio/usage-trigger',[\\App\\Http\\Controllers\\Api\\TwilioUsageTriggerController::class,'handle'])->middleware('throttle:60,1');",
    ];

    /** Lo que es de la voz (código y configuración). */
    private const VOZ = ['app/Services/Voice', 'app/Services/Telephony', 'app/Models/Voice', 'app/Http/Controllers/Api/Voice', 'app/Http/Controllers/Crm/Voice',
        'app/Jobs/Voice', 'app/Console/Commands/Voice', 'app/Services/Twilio/TwilioVoiceCarrier.php', 'app/Services/Twilio/TwilioRequestSignature.php',
        'routes/voice.php', 'config/voice.php'];

    private const STUDIO_VOZ = ['app/Services/UltronStudio/StudioVoice*.php', 'app/Http/Controllers/Crm/UltronStudio/UltronStudioVoice*.php'];

    /** Las clases del OTP. */
    private const CLASES_OTP = ['TwilioVerifyService', 'TwilioSmsSender', 'SmsSender', 'SmsSenderFactory', 'LabsMobileSmsSender', 'DevSmsSender',
        'OtpCostGuard', 'OtpPolicy', 'TwilioUsageTriggerController'];

    /** Las variables de la credencial maestra y del OTP (`config/otp.php:141-149`, `:186`). */
    private const ENTORNO_OTP = '/^(TWILIO_(SID|ACCOUNT_SID|TOKEN|AUTH_TOKEN|FROM|BASE|VERIFY_[A-Z_]+|SEND_ENABLED)|OTP_[A-Z_]+|LABSMOBILE_[A-Z_]+)$/';

    public function test_el_codigo_del_otp_es_byte_a_byte_el_de_produccion(): void
    {
        foreach (self::HUELLAS as $relativa => $huella) {
            $ruta = base_path($relativa);
            $this->assertFileExists($ruta, "{$relativa} desapareció");
            $this->assertSame($huella, hash_file('sha256', $ruta), "{$relativa} cambió: el OTP no se toca en el H8 (protección 4). Un cambio autorizado del OTP va en su propio commit y actualiza esta huella.");
        }

        $esperados = array_values(array_filter(array_keys(self::HUELLAS), static fn (string $f): bool => str_starts_with($f, 'app/Services/Otp/') || str_starts_with($f, 'app/Services/Sms/')));
        $presentes = [];
        foreach (self::DIRECTORIOS as $directorio) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directorio), RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile()) {
                    $presentes[] = substr($f->getPathname(), strlen(rtrim(base_path(), '/')) + 1);
                }
            }
        }
        sort($presentes);
        $this->assertSame($esperados, $presentes, 'Los directorios del OTP son una lista cerrada: ni un fichero más ni uno menos.');
    }

    public function test_las_rutas_del_otp_no_cambian_y_la_api_no_declara_nada_de_la_voz(): void
    {
        $sentencias = $this->sentencias(base_path('routes/api.php'));
        foreach (self::RUTAS as $ruta) {
            $this->assertSame(1, count(array_keys($sentencias, $ruta, true)), "La ruta del OTP cambió o se repite en routes/api.php:\n{$ruta}");
        }

        $codigo = $this->codigo(base_path('routes/api.php'));
        foreach (['Voice', 'Telephony', 'ElevenLabs', 'elevenlabs', 'webhooks/voice', 'voice/tools', 'admin/voice'] as $nombre) {
            $this->assertStringNotContainsString($nombre, $codigo, "routes/api.php nombra {$nombre}: la voz va en routes/voice.php (D8-4)");
        }

        // En la tabla real: el aviso del OTP atiende UNA ruta, la suya, y ninguna ruta de voz usa su controlador.
        $delAviso = [];
        foreach (Route::getRoutes() as $ruta) {
            if (str_starts_with(ltrim($ruta->getActionName(), '\\'), TwilioUsageTriggerController::class)) {
                $delAviso[] = implode('|', $ruta->methods()).' '.$ruta->uri();
            }
        }
        $this->assertSame(['POST api/webhooks/twilio/usage-trigger'], $delAviso, 'el controlador del aviso de gasto del OTP atiende una sola ruta (OTP-2: la subcuenta de voz lleva la suya)');
    }

    public function test_la_voz_no_lee_el_otp(): void
    {
        $vistos = 0;
        foreach ($this->php(...self::VOZ) + $this->glob(...self::STUDIO_VOZ) as $relativa => $ruta) {
            $vistos++;
            $tokens = $this->tokens($ruta);
            foreach ($tokens as $i => $t) {
                if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $valor = $this->literal($t[1]);
                    $this->assertDoesNotMatchRegularExpression('/^otp(\.|$)/', $valor, "{$relativa} nombra {$valor}: la voz no lee la configuración del OTP (protección 4)");
                    $this->assertDoesNotMatchRegularExpression(self::ENTORNO_OTP, $valor, "{$relativa} nombra {$valor}: la voz no usa la credencial del OTP (D8-4, D8-5)");
                }
                if (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $this->assertNotContains($this->corto($t[1]), self::CLASES_OTP, "{$relativa} nombra {$t[1]}: la voz no usa el OTP");
                    $this->assertDoesNotMatchRegularExpression('/Services\\\\(Sms|Otp)\\\\/', $t[1], "{$relativa} nombra {$t[1]}");
                }
            }
        }
        $this->assertGreaterThanOrEqual(12, $vistos, 'el rastreo ve el paquete de voz');
    }

    public function test_el_otp_no_lee_la_voz(): void
    {
        foreach (array_keys(self::HUELLAS) as $relativa) {
            foreach ($this->tokens(base_path($relativa)) as $t) {
                if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $valor = $this->literal($t[1]);
                    $this->assertDoesNotMatchRegularExpression('/^(voice|telephony)(\.|$)/', $valor, "{$relativa} nombra {$valor}: el OTP no lee la voz (protección 4)");
                    $this->assertDoesNotMatchRegularExpression('/^(ELEVENLABS_|TWILIO_VOICE_|VOICE_)/', $valor, "{$relativa} nombra {$valor}");
                }
                if (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $this->assertDoesNotMatchRegularExpression('/Services\\\\(Voice|Telephony)\\\\|TwilioVoiceCarrier|TwilioRequestSignature|ElevenLabs/', $t[1], "{$relativa} nombra {$t[1]}");
                }
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────────

    /** @return list<string> las sentencias de un fichero de rutas, sin espacios ni comentarios */
    private function sentencias(string $ruta): array
    {
        $out = [''];
        foreach ($this->tokens($ruta) as $t) {
            if ($t[1] === ';') {
                $out[array_key_last($out)] .= ';';
                $out[] = '';

                continue;
            }
            if (in_array($t[1], ['{', '}'], true)) {
                $out[] = '';

                continue;
            }
            $out[array_key_last($out)] .= $t[1];
        }

        return array_values(array_filter($out, static fn (string $s): bool => $s !== ''));
    }

    /** @return array<string,string> ruta relativa → absoluta, de todo el PHP bajo estos ficheros o directorios (los que no existan, se saltan) */
    private function php(string ...$raices): array
    {
        $base = rtrim(base_path(), '/').'/';
        $out = [];
        foreach ($raices as $raiz) {
            $absoluta = $base.$raiz;
            if (is_file($absoluta)) {
                $out[$raiz] = $absoluta;

                continue;
            }
            if (! is_dir($absoluta)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absoluta, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $out[substr($f->getPathname(), strlen($base))] = $f->getPathname();
                }
            }
        }
        ksort($out);

        return $out;
    }

    /** @return array<string,string> lo mismo, por patrones */
    private function glob(string ...$patrones): array
    {
        $base = rtrim(base_path(), '/').'/';
        $out = [];
        foreach ($patrones as $patron) {
            foreach (glob($base.$patron) ?: [] as $f) {
                $out[substr($f, strlen($base))] = $f;
            }
        }
        ksort($out);

        return $out;
    }

    /** El código sin comentarios (las cadenas se quedan). */
    private function codigo(string $ruta): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($ruta)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    /** @return list<array{0:int,1:string}> sin comentarios ni espacios */
    private function tokens(string $ruta): array
    {
        $out = [];
        foreach (token_get_all((string) file_get_contents($ruta)) as $t) {
            $t = is_array($t) ? $t : [0, $t];
            if (! in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG], true)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    private function corto(string $nombre): string
    {
        return substr((string) strrchr('\\'.$nombre, '\\'), 1);
    }

    private function literal(string $token): string
    {
        return stripcslashes(substr($token, 1, -1));
    }
}
