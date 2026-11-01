<?php

namespace Tests\Unit\Twilio;

use App\Services\Twilio\TwilioProbeSanitizer as Saneador;
use App\Services\Twilio\TwilioReadOnlyClient as Cliente;
use Closure;
use DateTimeImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

/**
 * La cadena entera del paquete TW (contrato H6 §I5.4): el cliente REAL contra
 * `Http::fake` (con los cuerpos de `fixtures/twilio-api-responses.json`
 * servidos por ruta) y el saneador, con `APP_ENV=production` y una base de
 * simulador que tiene que ignorarse. Cero red: `Http::preventStrayRequests()`.
 *
 * Cada escenario tiene que dar EXACTAMENTE lo que fija su caso del fixture del
 * saneador (C01, C03, C05, C09, C10 y C11): 401 y tiempo agotado en la cuenta,
 * 429 en las llamadas, tiempo agotado en las alertas y un cuerpo inesperado en
 * los números. Ningún centinela sale de la cadena.
 *
 * La orquestación de aquí es la mínima que pide el contrato (§I5.4, paso 6: en
 * orden, y se para si la cuenta da `auth_failed` o `unreachable`). La de verdad
 * (cupo, enfriamiento, los 30 s, la instantánea y el libro) es de quien use el
 * paquete.
 */
class TwilioProbePipelineTest extends TestCase
{
    private const SID = 'ACdeadbeefdeadbeefdeadbeefdeadbeef';

    private const SECRETO = 'token-de-prueba-centinela-PIPE';

    /** Paso → el cuerpo del fixture que sirve el falso si no se dice otra cosa. */
    private const CUERPOS = ['account' => 'account_full_active', 'numbers' => 'numbers_two', 'caller_ids' => 'caller_ids_one', 'calls' => 'calls_week', 'alerts' => 'alerts_hostile'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->app['env'] = 'production';
    }

    /** @return array<string,mixed> */
    private static function fixture(string $nombre): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/'.$nombre), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> el resultado que fija el caso del fixture del saneador */
    private static function esperado(string $prefijo): array
    {
        foreach (self::fixture('sanitize-cases.json')['cases'] as $caso) {
            if (str_starts_with($caso['name'], $prefijo.' ')) {
                return $caso['expected'];
            }
        }

        throw new LogicException("El fixture del saneador no tiene el caso {$prefijo}.");
    }

    /**
     * Twilio de mentira: las cinco rutas OFICIALES con los cuerpos del fixture,
     * salvo los pasos cambiados. Otra URL u otro verbo reciben un 404.
     *
     * @param  array<string, mixed>  $cambios  paso → respuesta de `Http::response()` o cierre que la da
     */
    private static function twilio(array $cambios = []): void
    {
        $api = self::fixture('twilio-api-responses.json');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $peticion) use ($api, $cambios) {
            $paso = self::paso($peticion);
            if ($paso === null || $peticion->method() !== 'GET') {
                return Http::response(['code' => 20404, 'message' => 'The requested resource was not found', 'status' => 404], 404);
            }
            $cambio = $cambios[$paso] ?? null;

            return $cambio instanceof Closure ? $cambio($peticion) : ($cambio ?? Http::response($api[self::CUERPOS[$paso]]));
        });
    }

    private static function paso(Request $peticion): ?string
    {
        $cuenta = Cliente::PRODUCTION_API_BASE.'/2010-04-01/Accounts/'.self::SID;

        return match (explode('?', $peticion->url(), 2)[0]) {
            "{$cuenta}.json" => 'account',
            "{$cuenta}/IncomingPhoneNumbers.json" => 'numbers',
            "{$cuenta}/OutgoingCallerIds.json" => 'caller_ids',
            "{$cuenta}/Calls.json" => 'calls',
            Cliente::PRODUCTION_MONITOR_BASE.'/v1/Alerts' => 'alerts',
            default => null,
        };
    }

    /**
     * La orquestación mínima del contrato y el saneado.
     *
     * @return array{outcome: string, facts: array<string,mixed>}
     */
    private static function sondear(): array
    {
        // La base del simulador está, y en producción se ignora.
        $cliente = new Cliente(self::SID, self::SID, self::SECRETO, 'http://127.0.0.1:8095');
        $ahora = new DateTimeImmutable('2026-10-31T05:30:00-05:00');
        $respuestas = [];
        $parar = false;
        foreach (Cliente::STEPS as $paso) {
            if ($parar) {
                $respuestas[$paso] = ['skipped' => true];

                continue;
            }
            $respuestas[$paso] = $cliente->get($paso, $ahora);
            $parar = $paso === 'account' && in_array($respuestas[$paso]['error'], ['auth_failed', 'unreachable'], true);
        }

        return Saneador::sanitize($respuestas, 'otp');
    }

    private static function agotado(): Closure
    {
        return static fn (Request $r) => Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out after 10001 milliseconds', $r->toPsrRequest(), null, ['errno' => 28]));
    }

    /** Lo que se pidió: solo GET, solo a los dos hosts oficiales y por https. */
    private function assertSoloGetOficial(int $peticiones): void
    {
        Http::assertSentCount($peticiones);
        foreach (Http::recorded() as [$peticion]) {
            $this->assertSame('GET', $peticion->method());
            $this->assertSame('https', parse_url($peticion->url(), PHP_URL_SCHEME));
            $this->assertContains(parse_url($peticion->url(), PHP_URL_HOST), ['api.twilio.com', 'monitor.twilio.com']);
            $this->assertNotNull(self::paso($peticion), $peticion->url());
        }
    }

    /** @param  array<string,mixed>  $resultado */
    private function assertSinCentinelas(array $resultado): void
    {
        $plano = json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach ([...self::fixture('sanitize-cases.json')['sentinels'], self::SECRETO] as $centinela) {
            $this->assertStringNotContainsString($centinela, $plano);
        }
    }

    public function test_todo_bien_da_exactamente_el_caso_c01(): void
    {
        self::twilio();

        $r = self::sondear();

        $this->assertSame(self::esperado('C01'), $r);
        $this->assertSoloGetOficial(5);
        $this->assertSinCentinelas($r);
    }

    public function test_401_en_la_cuenta_para_la_sonda_sin_mas_red_c03(): void
    {
        self::twilio(['account' => Http::response(['code' => 20003, 'message' => 'Authenticate', 'more_info' => 'https://www.twilio.com/docs/errors/20003', 'status' => 401], 401)]);

        $r = self::sondear();

        $this->assertSame(self::esperado('C03'), $r);
        $this->assertSoloGetOficial(1);
        $this->assertSinCentinelas($r);
    }

    public function test_tiempo_agotado_en_la_cuenta_para_la_sonda_c05(): void
    {
        self::twilio(['account' => self::agotado()]);

        $r = self::sondear();

        $this->assertSame(self::esperado('C05'), $r);
        $this->assertSoloGetOficial(1);
    }

    public function test_429_en_las_llamadas_degrada_c09(): void
    {
        self::twilio(['calls' => Http::response(['code' => 20429, 'message' => 'Too Many Requests', 'more_info' => 'https://www.twilio.com/docs/errors/20429', 'status' => 429], 429)]);

        $r = self::sondear();

        $this->assertSame(self::esperado('C09'), $r);
        $this->assertSoloGetOficial(5);
        $this->assertSinCentinelas($r);
    }

    public function test_tiempo_agotado_en_las_alertas_degrada_c10(): void
    {
        self::twilio(['alerts' => self::agotado()]);

        $r = self::sondear();

        $this->assertSame(self::esperado('C10'), $r);
        $this->assertSoloGetOficial(5);
        $this->assertSinCentinelas($r);
    }

    public function test_un_cuerpo_inesperado_en_los_numeros_degrada_c11(): void
    {
        $api = self::fixture('twilio-api-responses.json');
        $inesperados = [
            'HTML con 200 (el cliente lo corta)' => Http::response('<html><body>(310) 555-0178</body></html>', 200),
            'JSON con la lista hecha texto (lo corta el saneador)' => Http::response($api['numbers_list_is_text']),
            'un error de Twilio con 200 (lo corta el saneador)' => Http::response($api['twilio_error_body']),
        ];
        foreach ($inesperados as $nombre => $respuesta) {
            self::twilio(['numbers' => $respuesta]);

            $r = self::sondear();

            $this->assertSame(self::esperado('C11'), $r, $nombre);
            $this->assertSoloGetOficial(5);
            $this->assertSinCentinelas($r);
        }
    }
}
