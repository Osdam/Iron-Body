<?php

namespace Tests\Feature\Voice;

use App\Services\Voice\ElevenLabs\ElevenLabsVoiceProvider;
use App\Services\Voice\ElevenLabs\ElevenLabsWebhookEnvelope;
use App\Services\Voice\ElevenLabs\ElevenLabsWebhookSignature;
use App\Services\Voice\NullVoiceProvider;
use App\Services\Voice\VoiceCallContext;
use App\Services\Voice\VoiceCallPolicy;
use App\Services\Voice\VoiceProviders;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * La frontera de la voz con la aplicación arrancada (contrato H6 §I9.5, §I1
 * P2 y §I6.4): lo que la prueba estática no puede ver.
 *
 * LO QUE SE PROTEGE.
 *  - En la tabla de rutas REAL (todas las que registra la aplicación, vengan
 *    del fichero que vengan) no hay ninguna ruta de voz: ninguna la atiende
 *    una clase de `App\Services\Voice`, un controlador de voz o de
 *    ElevenLabs, ni cuelga de una URI con `elevenlabs`. El webhook
 *    post-llamada no tiene ruta en este hito.
 *  - El único comando `voice:*` es el doctor, y no está programado.
 *  - Con la configuración por defecto (la de producción en el H6), ninguna
 *    entrada pública del paquete sale a la red.
 */
class VoiceBoundaryTest extends TestCase
{
    public function test_en_la_tabla_de_rutas_real_no_hay_ninguna_ruta_de_voz(): void
    {
        $rutas = Route::getRoutes()->getRoutes();
        $this->assertGreaterThan(100, count($rutas), 'la tabla de rutas está cargada (si no, la prueba no prueba nada)');

        $deVoz = [];
        foreach ($rutas as $ruta) {
            $accion = $ruta->getActionName();
            $clase = str_contains($accion, '@') ? strstr($accion, '@', true) : $accion;
            $corta = class_basename($clase);
            if (str_starts_with(ltrim($clase, '\\'), 'App\\Services\\Voice\\')
                || preg_match('/Voice|ElevenLabs/', $corta) === 1
                || stripos($ruta->uri(), 'elevenlabs') !== false) {
                $deVoz[] = implode('|', $ruta->methods()).' '.$ruta->uri().' → '.$accion;
            }
            foreach ($ruta->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && preg_match('/Services\\\\Voice|ElevenLabs/', $middleware) === 1) {
                    $deVoz[] = $ruta->uri().' (middleware '.$middleware.')';
                }
            }
        }

        $this->assertSame([], $deVoz, "Rutas de voz registradas:\n".implode("\n", $deVoz));
    }

    public function test_el_unico_comando_de_voz_es_el_doctor_y_no_esta_programado(): void
    {
        $comandos = array_values(array_filter(array_keys(Artisan::all()), static fn (string $c): bool => str_starts_with($c, 'voice:')));
        $this->assertSame(['voice:doctor'], $comandos);

        foreach (app(Schedule::class)->events() as $evento) {
            $this->assertDoesNotMatchRegularExpression('/(?<![a-z])voice:/', (string) $evento->command, 'un comando de voz programado');
        }
        $this->assertDoesNotMatchRegularExpression('/(?<![a-z])voice:/', (string) file_get_contents(base_path('routes/console.php')), 'routes/console.php no se toca en este hito');
    }

    public function test_la_configuracion_cargada_es_la_de_produccion_del_h6(): void
    {
        $this->assertFalse(config('voice.elevenlabs.enabled'));
        $this->assertSame('default', config('voice.elevenlabs.region'));
        $this->assertSame(10, config('voice.elevenlabs.timeout_seconds'));
        $this->assertFalse(config('voice.twilio.enabled'));
        $this->assertSame(0, config('voice.twilio.daily_call_cap'));
        $this->assertSame('ELEVENLABS_NOT_CONFIGURED', VoiceProviders::status()->state);
    }

    public function test_por_defecto_ninguna_entrada_publica_sale_a_la_red(): void
    {
        // Graba y, sin ningún stub, cualquier petición lanza StrayRequestException.
        Http::preventStrayRequests();
        Http::fake([]);

        $this->assertSame('none', VoiceProviders::current()->id());
        $this->assertSame('not_configured', VoiceProviders::current()->verifyCredentials()->outcome);
        $this->assertSame('not_configured', (new ElevenLabsVoiceProvider)->verifyCredentials()->outcome, 'ni instanciando el adaptador a mano');
        $this->assertSame('not_configured', (new NullVoiceProvider)->verifyCredentials()->outcome);
        $this->assertSame(['api_key' => null, 'webhook_secret' => null, 'tool_secret' => null], ElevenLabsVoiceProvider::secretFingerprints('clave-de-huellas'));
        $this->assertFalse(VoiceCallPolicy::decide(new VoiceCallContext(true, true, 'ELEVENLABS_VERIFIED', 'active_voice', 'canary_registry', 20))->allowed);
        $this->assertSame('missing_signature', ElevenLabsWebhookSignature::verify('{}', null, 'secreto', 1761955200));
        $this->assertNull(ElevenLabsWebhookEnvelope::type('{}'));
        foreach ([[], ['--json' => true], ['--verify' => true], ['--verify' => true, '--json' => true]] as $opciones) {
            Artisan::call('voice:doctor', $opciones);
        }

        Http::assertNothingSent();
    }
}
