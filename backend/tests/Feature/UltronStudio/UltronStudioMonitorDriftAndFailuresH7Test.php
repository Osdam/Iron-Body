<?php

namespace Tests\Feature\UltronStudio;

use App\Console\Commands\UltronStudio\MonitorCommand;
use App\Models\Admin;
use App\Models\Incident;
use App\Services\IronGuard\IncidentRecorder;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use App\Services\UltronStudio\StudioPublications;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * TEST7 (Hito 7): los caminos del monitor del Studio que la prueba de OPS7
 * (`UltronStudioMonitorTest`) no ejercita y que las mutaciones dejaron vivos:
 *
 *  - la DERIVA de la publicación (lo que declara la cabeza no es lo que lee el
 *    motor), distinta de la cabeza que no verifica: MON-07;
 *  - la deriva de la CONFIGURACIÓN AUTORIZADA de una integración (H6 §I5.3):
 *    MON-14;
 *  - una comprobación que REVIENTA no tumba a las demás ni deja su mensaje
 *    (puede llevar SQL o una cadena de conexión) en la salida ni en el
 *    incidente: MON-10 y MON-11;
 *  - un incidente que NO SE PUEDE ANOTAR se dice en la salida y el código de
 *    salida sigue siendo 1: MON-15.
 *
 * Mismas piezas que la prueba de OPS7 (fixtures del Studio y la expectativa
 * de integraciones leída del doctor, no supuesta).
 */
class UltronStudioMonitorDriftAndFailuresH7Test extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    /** Un mensaje de error con forma de fuga: no puede salir de la excepción. */
    private const CENTINELA = 'SQLSTATE[08006] host=10.20.30.40 user=iron password=centinela-h7-monitor';

    /** Las llaves de quien reconoce la configuración de una integración (las del Super Admin del H6, I7.1). */
    private const SUPER_KEYS = ['ultron.view', 'ultron.campaigns_manage', 'ultron.canary_manage', 'ultron.canary_authorize', 'ultron.integrations_manage'];

    /** Un token de Twilio reconocible, para comprobar que no llega a la salida ni al incidente. */
    private const TWILIO_TOKEN = 'h7pruebaTWILIOTOKENcentinela00007';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Lo que afirma el doctor ahora mismo sobre la voz (como el setUp de la prueba de OPS7). */
    private function expectativaDelDoctor(): string
    {
        Artisan::call('ultron-studio:integrations-doctor', ['--json' => true]);
        $pares = [];
        foreach (json_decode(Artisan::output(), true)['providers'] ?? [] as $p) {
            if (in_array($p['id'] ?? null, ['twilio_voice', 'elevenlabs'], true) && ($p['available'] ?? false) === true) {
                $pares[] = $p['id'].':'.($p['id'] === 'elevenlabs' ? ($p['code'] ?? $p['classification']) : $p['classification']);
            }
        }
        $this->assertNotSame([], $pares, 'el doctor tiene que clasificar la voz en la suite');

        return implode(',', $pares);
    }

    /** @return array{0:int,1:array<string,mixed>,2:string} el código, el JSON y la salida en bruto */
    private function monitor(array $opciones = []): array
    {
        $codigo = Artisan::call('ultron-studio:monitor', $opciones + ['--json' => true]);
        $salida = Artisan::output();
        $json = json_decode($salida, true);
        $this->assertIsArray($json, 'el monitor tiene que devolver JSON: '.$salida);

        return [$codigo, $json, $salida];
    }

    public function test_la_deriva_entre_lo_declarado_y_lo_que_lee_el_motor_abre_publication_drift_con_solo_el_nombre_del_parametro(): void
    {
        config(['ultron_studio.monitor.expect' => $this->expectativaDelDoctor()]);
        $this->importBaseline();
        Carbon::setTestNow(Carbon::parse('2026-11-03T15:00:00Z'));
        $this->openKeys();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->approvedVersion($ana, $root, 'Versión para la deriva');
        $this->publishAs($root, $v->uuid)->assertCreated();

        // Publicada y leída igual por el motor: cuadra.
        [$codigo, $r] = $this->monitor(['--check' => ['publication']]);
        $this->assertSame([0, []], [$codigo, $r['checks']['publication']['problems']]);
        $this->assertTrue($r['checks']['publication']['head']['signatureOk']);

        // El entorno cambia el cobro que la versión declara apagado: el motor ya no lee lo declarado.
        config(['marketing.ultron.payment_links_enabled' => true]);
        [$codigo, $r, $salida] = $this->monitor(['--check' => ['publication']]);

        $this->assertSame(1, $codigo);
        $p = $r['checks']['publication'];
        $this->assertSame(['drift'], $p['problems'], 'la cabeza verifica: es deriva, no head_invalid');
        $this->assertTrue($p['head']['signatureOk']);
        $this->assertSame(['rules.paymentLinksEnabled'], $p['driftParams']);
        // Paridad con el comando que agrupa.
        Artisan::call('ultron-studio:publication-check', ['--json' => true]);
        $this->assertSame(json_decode(Artisan::output(), true)['problems'], $p['problems']);

        $incidente = Incident::query()->sole();
        $this->assertSame([MonitorCommand::SOURCE, MonitorCommand::KIND_PUBLICATION, Incident::SEVERITY_HIGH, Incident::STATUS_OPEN],
            [$incidente->source, $incidente->kind, $incidente->severity, $incidente->status]);
        $this->assertSame('Lo que declara la publicación vigente de ULTRON STUDIO no es lo que lee el motor', $incidente->title);
        $this->assertSame(['drift'], $incidente->evidence['problems']);
        $this->assertSame(['rules.paymentLinksEnabled'], $incidente->evidence['drift_params']);

        // Solo el NOMBRE del parámetro: ni lo declarado ni lo leído, ni la conversación canaria, ni el texto firmado.
        $todo = $salida.json_encode($incidente->evidence).$incidente->title;
        $this->assertStringNotContainsString('"declared"', $todo);
        $this->assertStringNotContainsString('"engine"', $todo);
        $this->assertStringNotContainsString((string) self::CANARY_ID, $todo);
        $cabeza = $this->chainHead();
        $this->assertNotNull($cabeza);
        $this->assertStringNotContainsString((string) $cabeza->effective_hash, $todo);
        $this->assertStringNotContainsString((string) $cabeza->uuid, $todo);
    }

    public function test_la_deriva_de_la_configuracion_autorizada_de_una_integracion_abre_integrations_drift_sin_secretos(): void
    {
        config([
            'otp.driver' => 'twilio',
            'otp.twilio.sid' => 'AC'.str_repeat('0', 28).'7777',
            'otp.twilio.token' => self::TWILIO_TOKEN,
            'otp.twilio.verify_service_sid' => 'VA'.str_repeat('0', 28).'9f0e',
            'ultron_studio.integrations.twilio_aliases' => ['sid_source' => 'TWILIO_SID', 'token_source' => 'TWILIO_TOKEN', 'sid_consistent' => true, 'token_consistent' => true],
            'ultron_studio.integrations.writes_enabled' => false,
        ]);
        config(['ultron_studio.monitor.expect' => $this->expectativaDelDoctor()]);

        // Sin reconocimiento previo no hay deriva posible: cuadra.
        [$codigo, $r] = $this->monitor(['--check' => ['integrations']]);
        $this->assertSame(0, $codigo, json_encode($r['checks']['integrations']['problems']));
        $this->assertSame('never_authorized', $r['checks']['integrations']['configStates']['twilio']);

        // Una persona reconoce la configuración de Twilio.
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        $huella = $this->studio('GET', 'integrations/config', $root)->assertOk()->json('data.providers.1.effectiveFingerprint');
        $this->studio('POST', 'integrations/config/acknowledge', $root, ['clientRequestId' => (string) Str::uuid(), 'provider' => 'twilio', 'fingerprint' => $huella])->assertCreated();
        [$codigo, $r] = $this->monitor(['--check' => ['integrations']]);
        $this->assertSame([0, 'authorized'], [$codigo, $r['checks']['integrations']['configStates']['twilio']]);
        $this->assertSame(0, Incident::query()->count());

        // Cambia el servicio de Verify sin que nadie lo reconozca: deriva de la configuración autorizada.
        config(['otp.twilio.verify_service_sid' => 'VA'.str_repeat('0', 28).'1a2b']);
        [$codigo, $r, $salida] = $this->monitor(['--check' => ['integrations']]);

        $this->assertSame(1, $codigo);
        $i = $r['checks']['integrations'];
        $this->assertSame(['config_drift:twilio'], $i['problems'], 'la expectativa del doctor sigue cuadrando: solo la deriva');
        $this->assertSame([], $i['mismatches']);
        $this->assertSame('drift', $i['configStates']['twilio']);

        $incidente = Incident::query()->sole();
        $this->assertSame([MonitorCommand::SOURCE, MonitorCommand::KIND_INTEGRATIONS, Incident::SEVERITY_HIGH],
            [$incidente->source, $incidente->kind, $incidente->severity]);
        $this->assertSame(['config_drift:twilio'], $incidente->evidence['problems']);
        $this->assertSame('drift', $incidente->evidence['config_states']['twilio']);

        // Ni el token, ni los SID, ni sus colas de cuatro cifras.
        $todo = $salida.json_encode($incidente->evidence).$incidente->title;
        foreach ([self::TWILIO_TOKEN, 'AC'.str_repeat('0', 28).'7777', '1a2b', '9f0e', '7777'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $todo);
        }
    }

    public function test_una_comprobacion_que_revienta_no_tumba_a_las_demas_ni_deja_su_mensaje(): void
    {
        config(['ultron_studio.monitor.expect' => $this->expectativaDelDoctor()]);
        // La lectura de la cadena revienta (como una base caída a mitad): el contenedor no puede dar el servicio.
        $this->app->bind(StudioPublications::class, static function (): never {
            throw new RuntimeException(self::CENTINELA);
        });

        // En seco: lo dice y no anota nada.
        [$codigo, $r, $salida] = $this->monitor(['--dry-run' => true]);
        $this->assertSame(1, $codigo);
        $this->assertSame(['publication', 'integrations', 'queues'], array_keys($r['checks']), 'las tres se comprobaron');
        $this->assertSame([false, 'error', ['check_failed'], 'RuntimeException'],
            [$r['checks']['publication']['ok'], $r['checks']['publication']['status'], $r['checks']['publication']['problems'], $r['checks']['publication']['error']]);
        $this->assertTrue($r['checks']['integrations']['ok']);
        $this->assertTrue($r['checks']['queues']['ok']);
        $this->assertSame(0, Incident::query()->count());
        $this->assertStringNotContainsString('centinela-h7', $salida);

        // De verdad: un incidente «no se pudo comprobar» (medio), con la clase y sin el mensaje.
        [$codigo, $r, $salida] = $this->monitor();
        $this->assertSame(1, $codigo);
        $incidente = Incident::query()->sole();
        $this->assertSame([MonitorCommand::SOURCE, MonitorCommand::KIND_CHECK_FAILED, Incident::SEVERITY_MEDIUM, ['check_failed'], 'RuntimeException'],
            [$incidente->source, $incidente->kind, $incidente->severity, $incidente->evidence['problems'], $incidente->evidence['error_class']]);
        $this->assertSame([['check' => 'publication', 'id' => $incidente->id, 'kind' => MonitorCommand::KIND_CHECK_FAILED, 'severity' => Incident::SEVERITY_MEDIUM, 'occurrences' => 1]], $r['incidents']);
        $todo = $salida.json_encode($incidente->evidence).$incidente->title;
        foreach (['centinela-h7', 'SQLSTATE', '10.20.30.40', 'password='] as $fuga) {
            $this->assertStringNotContainsString($fuga, $todo);
        }

        // En texto, también sin el mensaje.
        $this->artisan('ultron-studio:monitor', ['--dry-run' => true])
            ->expectsOutputToContain('publication: NO CUADRA (check_failed)')
            ->doesntExpectOutputToContain('centinela-h7')
            ->assertFailed();
    }

    public function test_si_el_incidente_no_se_puede_anotar_lo_dice_y_sale_con_1(): void
    {
        config(['ultron_studio.monitor.expect' => $this->expectativaDelDoctor()]);
        $this->openKeys();
        // Una cabeza que no verifica: hay que anotar un incidente…
        $this->seedEntry(D::ACTION_LEGACY, null, null, null, ['signature' => str_repeat('d', 64)]);
        // …y IRON GUARD no puede anotarlo (base de incidentes caída).
        $this->mock(IncidentRecorder::class, function ($m): void {
            $m->shouldReceive('record')->once()->andThrow(new RuntimeException(self::CENTINELA));
        });

        [$codigo, $r, $salida] = $this->monitor(['--check' => ['publication']]);

        $this->assertSame(1, $codigo);
        $this->assertSame(['head_invalid'], $r['checks']['publication']['problems']);
        $this->assertSame(['not_recorded', 'RuntimeException'], [$r['checks']['publication']['incident'] ?? null, $r['checks']['publication']['incidentError'] ?? null]);
        $this->assertSame([], $r['incidents']);
        $this->assertStringNotContainsString('centinela-h7', $salida);
        $this->assertSame(0, Incident::query()->count());
    }
}
