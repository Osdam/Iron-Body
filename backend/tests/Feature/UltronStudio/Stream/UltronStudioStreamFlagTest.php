<?php

namespace Tests\Feature\UltronStudio\Stream;

use App\Http\Controllers\Crm\UltronStudio\UltronStudioStreamController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\UltronStudio\StudioFixtures;
use Tests\TestCase;

/**
 * T24 (contrato H9, «Paquete SSE9»), la bandera: el canal NACE APAGADO y,
 * apagado, responde 409 `stream_disabled` a quien tiene permiso (el CRM
 * vuelve al sondeo). La puerta y la persona van ANTES que la bandera: quien
 * no entra no sabe si el canal existe encendido o apagado.
 */
class UltronStudioStreamFlagTest extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    private const URL = '/api/admin/ultron-studio/stream';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32))]);
    }

    public function test_la_bandera_nace_apagada_sin_la_variable_en_el_entorno(): void
    {
        $nombre = 'ULTRON_STUDIO_STREAM_ENABLED';
        $antes = [$_SERVER[$nombre] ?? null, $_ENV[$nombre] ?? null, getenv($nombre)];

        try {
            unset($_SERVER[$nombre], $_ENV[$nombre]);
            putenv($nombre);
            $config = require base_path('config/ultron_studio_stream.php');
        } finally {
            [$servidor, $entorno, $proceso] = $antes;
            if ($servidor !== null) {
                $_SERVER[$nombre] = $servidor;
            }
            if ($entorno !== null) {
                $_ENV[$nombre] = $entorno;
            }
            if ($proceso !== false) {
                putenv("{$nombre}={$proceso}");
            }
        }

        $this->assertFalse($config['enabled']);
        $this->assertSame(15, $config['heartbeat_seconds']);
        $this->assertSame(55, $config['max_seconds']);
    }

    public function test_solo_true_enciende_la_bandera(): void
    {
        foreach (['false', 'off', 'no', '', 'encendido', '0'] as $valor) {
            config(['ultron_studio_stream.enabled' => filter_var($valor, FILTER_VALIDATE_BOOLEAN)]);
            $this->assertFalse(UltronStudioStreamController::enabled(), "«{$valor}» no enciende");
        }
        foreach (['true', '1', 'on', 'yes'] as $valor) {
            config(['ultron_studio_stream.enabled' => filter_var($valor, FILTER_VALIDATE_BOOLEAN)]);
            $this->assertTrue(UltronStudioStreamController::enabled(), "«{$valor}» enciende");
        }
    }

    public function test_apagado_responde_409_stream_disabled_con_sesion_y_con_vale(): void
    {
        config(['ultron_studio_stream.enabled' => false]);
        $ana = $this->person();

        $this->getJson(self::URL.'?topics=ledger', $this->as($ana))
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'code' => 'stream_disabled']);

        $ticket = $this->getJson('/api/admin/stream-ticket', $this->as($ana))->assertOk()->json('data.ticket');
        $this->getJson(self::URL.'?topics=ledger&ticket='.urlencode($ticket))
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'code' => 'stream_disabled']);
    }

    public function test_la_puerta_y_la_persona_van_antes_que_la_bandera(): void
    {
        config(['ultron_studio_stream.enabled' => false]);

        // Sin credencial: 401 (el vale es obligatorio en un stream sin cabecera).
        $this->getJson(self::URL.'?topics=ledger')->assertStatus(401);
        // Un vale inventado no es nadie.
        $this->getJson(self::URL.'?topics=ledger&ticket=no-es-un-vale')->assertStatus(403);
        // El token compartido de automatizaciones no es nadie: con la entrada del mapa (L9-59) lo
        // para la puerta del mapa (el dominio `ultron` está excluido del token compartido, D9-13)
        // con 403 `forbidden`, antes del controlador (`person_required`) y de la bandera.
        $this->getJson(self::URL.'?topics=ledger', $this->sharedTokenHeaders())->assertStatus(403)
            ->assertJson(['code' => 'forbidden']);
        // Una persona sin `ultron.view` tampoco llega a la bandera.
        $this->getJson(self::URL.'?topics=ledger', $this->adminWithPermissions(['classes.view']))->assertStatus(403)
            ->assertJson(['code' => 'forbidden']);
    }
}
