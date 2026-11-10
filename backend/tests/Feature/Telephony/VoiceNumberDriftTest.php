<?php

namespace Tests\Feature\Telephony;

use App\Models\Incident;
use App\Models\Voice\VoiceBrakeEntry;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceReconciler;
use App\Services\Voice\Dto\VoiceNumberAssignment;
use App\Services\Voice\NullVoiceNumberObserver;
use App\Services\Voice\VoiceNumberObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * LA DERIVA DEL NÚMERO (contrato H8, D8-21; Enmienda 2, E2-3; Enmienda 4,
 * E4-8): `voice:reconcile --numbers` compara el agente al que apunta el número
 * importado en ElevenLabs (`describeNumber`, solo GET) con el de la parte
 * `canary` de la cadena; si difieren, freno (`number_drift`, guardado como
 * `agent_drift`) e incidente de IRON GUARD. Sin red (`Http::fake`).
 *
 * B8-g (salientes antes que entrantes): un número «Outbound Only» (caller ID
 * verificado en Twilio; declarado con `VOICE_NUMBER_INBOUND_CAPABLE=false` o
 * con `supports_inbound: false` en ElevenLabs) no se puede asignar a ningún
 * agente: sin agente es `outbound_only` y no abre el freno; un agente ajeno en
 * él sigue siendo deriva.
 */
class VoiceNumberDriftTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const SIM = 'http://127.0.0.1:8096';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->voiceWorld([
            'voice.elevenlabs.enabled' => true, 'voice.elevenlabs.api_key' => 'xi-centinela-DRIFT-0123456789', 'voice.elevenlabs.agent_id' => 'agent_verify_0001',
            'voice.elevenlabs.agent_phone_number_id' => self::PHONE_NUMBER_ID, 'voice.elevenlabs.base_url' => self::SIM, 'voice.elevenlabs.region' => 'default',
        ]);
    }

    /**
     * El número apunta a `$agente` (null = sin asignar). La deriva del AGENTE
     * no es lo que se prueba aquí (la cubre VoiceReconcilerTest): su lectura
     * da 404, que no abre nada.
     */
    /** @param  array<string, mixed>  $extra  campos del número (p. ej. `supports_inbound`); con `sinAgente`, la respuesta no trae `assigned_agent` */
    private function provider(?string $agente, array $extra = [], bool $sinAgente = false): void
    {
        $numero = ['phone_number' => self::VOICE_FROM, 'phone_number_id' => self::PHONE_NUMBER_ID, 'provider' => 'twilio']
            + ($sinAgente ? [] : ['assigned_agent' => $agente === null ? null : ['agent_id' => $agente, 'agent_name' => 'x']]) + $extra;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            self::SIM.'/v1/convai/phone-numbers/'.self::PHONE_NUMBER_ID => Http::response($numero, 200),
            self::SIM.'/v1/convai/agents/*' => Http::response(['detail' => 'x'], 404),
        ]);
    }

    /** @return array<string, mixed> */
    private function reconcile(): array
    {
        $codigo = Artisan::call('voice:reconcile', ['--numbers' => true, '--json' => true]);
        $resumen = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        return ['code' => $codigo] + $resumen['number'];
    }

    public function test_el_numero_en_el_agente_publicado_no_es_deriva(): void
    {
        $pub = $this->publishVoice();
        $this->provider(self::SEALED_AGENT);
        $r = (new VoiceReconciler)->numbers(new class implements VoiceNumberObserver
        {
            public function describeNumber(string $phoneNumberId): VoiceNumberAssignment
            {
                return new VoiceNumberAssignment(VoiceNumberAssignment::OUTCOME_OBSERVED, $phoneNumberId, 'agent_h8fx_canary_0003', 200, null);
            }
        });
        $this->assertSame(['ok', 'assigned', 'assigned', $pub['seq'], 'observed', false], [$r['result'], $r['observed'], $r['expected'], $r['seq'], $r['outcome'], $r['brakeOpened']]);
        $this->assertFalse(VoiceBrake::isOpen());

        // Por el comando y el desplegador real (un GET al número).
        $this->assertSame('ok', $this->reconcile()['result']);
        Http::assertSent(fn ($req) => $req->method() === 'GET' && $req->url() === self::SIM.'/v1/convai/phone-numbers/'.self::PHONE_NUMBER_ID);
        $this->assertFalse(VoiceBrake::isOpen());
    }

    public function test_el_numero_en_otro_agente_abre_el_freno_y_el_incidente(): void
    {
        $pub = $this->publishVoice();
        $this->provider('agent_fuera_de_laravel_01');

        $r = $this->reconcile();
        $this->assertSame([1, 'drift', 'assigned', 'assigned', true], [$r['code'], $r['result'], $r['observed'], $r['expected'], $r['brakeOpened']]);
        $freno = VoiceBrakeEntry::query()->where('kind', 'open')->sole();
        $this->assertSame(['agent_drift', 'number:'.self::PHONE_NUMBER_ID.':agent_fuera_de_laravel_01'], [$freno->reason, $freno->open_key]);
        $this->assertSame(['trigger' => 'number_drift', 'where' => 'reconcile', 'part' => 'canary', 'seq' => $pub['seq'],
            'observedAgentId' => 'agent_fuera_de_laravel_01', 'expectedAgentId' => self::SEALED_AGENT], $freno->evidence);
        $this->assertSame(1, Incident::query()->where('source', 'voice')->where('kind', 'voice_brake')->count());

        // Idempotente: la misma deriva no abre otra vez.
        $this->assertSame([1, 'drift', false], [($s = $this->reconcile())['code'], $s['result'], $s['brakeOpened']]);
        $this->assertSame(1, VoiceBrakeEntry::query()->count());
    }

    public function test_sin_publicacion_el_numero_tiene_que_estar_sin_asignar(): void
    {
        $this->provider(null);
        $this->assertSame(['ok', 'unassigned', 'unassigned'], [($r = $this->reconcile())['result'], $r['observed'], $r['expected']]);

        $this->provider('agent_fuera_de_laravel_02');
        $this->assertSame(['drift', 'assigned', 'unassigned', 1], [($r = $this->reconcile())['result'], $r['observed'], $r['expected'], $r['code']]);
        $this->assertTrue(VoiceBrake::isOpen());
    }

    /**
     * B8-g, la regresión: el número importado «Outbound Only» (ElevenLabs no lo
     * deja asignar a ningún agente) con una publicación canaria viva. Antes era
     * deriva: freno, incidente y el comando con 1 cada hora, y con el freno
     * ninguna saliente pasaba la puerta (`voice_braked`).
     */
    public function test_un_numero_solo_saliente_sin_agente_no_es_deriva_ni_abre_el_freno(): void
    {
        $pub = $this->publishVoice();
        $this->provider(null, ['supports_inbound' => false, 'supports_outbound' => true]);

        $r = $this->reconcile();
        $this->assertSame([0, 'outbound_only', 'unassigned', 'unassigned', $pub['seq'], 'observed', false, null, false],
            [$r['code'], $r['result'], $r['observed'], $r['expected'], $r['seq'], $r['outcome'], $r['inboundCapable'], $r['inboundDeclared'], $r['brakeOpened']]);
        $this->assertFalse(VoiceBrake::isOpen());
        $this->assertSame(0, VoiceBrakeEntry::query()->count());
        $this->assertSame(0, Incident::query()->where('source', 'voice')->count());

        // En seco, lo mismo: no echaría nada.
        Artisan::call('voice:reconcile', ['--numbers' => true, '--json' => true, '--dry-run' => true]);
        $seco = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['number'];
        $this->assertSame(['outbound_only', false], [$seco['result'], $seco['wouldOpenBrake']]);

        // Y en texto lo dice por su nombre.
        Artisan::call('voice:reconcile', ['--numbers' => true]);
        $this->assertStringContainsString('solo salientes', Artisan::output());
    }

    /**
     * B8-g: lo DECLARADO (`VOICE_NUMBER_INBOUND_CAPABLE=false`, lo que lee S8 para
     * no asignar al publicar) basta aunque el proveedor no lo diga (el campo es
     * obsoleto y `assigned_agent`, opcional).
     */
    public function test_declarado_solo_saliente_basta_aunque_el_proveedor_no_lo_diga(): void
    {
        $this->publishVoice();
        config(['voice.number.inbound_capable' => false]);
        $this->provider(null, [], sinAgente: true);

        $r = $this->reconcile();
        $this->assertSame([0, 'outbound_only', 'unassigned', null, false, false], [$r['code'], $r['result'], $r['observed'], $r['inboundCapable'], $r['inboundDeclared'], $r['brakeOpened']]);
        $this->assertFalse(VoiceBrake::isOpen());
    }

    /** B8-g: solo saliente no es un salvoconducto: un agente que no es el publicado en el número sigue siendo deriva. */
    public function test_un_numero_solo_saliente_con_un_agente_ajeno_sigue_siendo_deriva(): void
    {
        $this->publishVoice();
        $this->provider('agent_fuera_de_laravel_03', ['supports_inbound' => false]);
        $r = $this->reconcile();
        $this->assertSame([1, 'drift', 'assigned', true], [$r['code'], $r['result'], $r['observed'], $r['brakeOpened']]);
        $this->assertTrue(VoiceBrake::isOpen());

        // SEC8-c M-2: declarado solo saliente, ni el agente publicado vale: publicar no asigna, así que es deriva.
        $this->provider(self::SEALED_AGENT);
        config(['voice.number.inbound_capable' => false]);
        $this->assertSame(['drift', 'unassigned', 2], [($r = $this->reconcile())['result'], $r['expected'], VoiceBrakeEntry::query()->where('kind', 'open')->count()]);
    }

    /** B8-g: lo de siempre no cambia: un número que admite entrantes (o que no lo dice) sin el agente publicado es deriva. */
    public function test_un_numero_que_admite_entrantes_sin_el_agente_publicado_sigue_siendo_deriva(): void
    {
        $this->publishVoice();
        foreach ([['supports_inbound' => true], []] as $i => $extra) {
            $this->provider(null, $extra);
            $r = $this->reconcile();
            $this->assertSame([1, 'drift', 'unassigned', 'assigned'], [$r['code'], $r['result'], $r['observed'], $r['expected']], (string) json_encode($extra));
            $this->assertSame($extra === [] ? null : true, $r['inboundCapable']);
        }
        $this->assertTrue(VoiceBrake::isOpen());
    }

    public function test_sin_id_del_numero_o_sin_proveedor_no_sale_nada_y_con_la_cadena_ilegible_no_se_compara(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();
        config(['voice.elevenlabs.agent_phone_number_id' => null]);
        $this->assertSame(['not_configured', null], [($r = (new VoiceReconciler)->numbers(new NullVoiceNumberObserver))['result'], $r['outcome']]);
        config(['voice.elevenlabs.agent_phone_number_id' => self::PHONE_NUMBER_ID]);
        $this->assertSame(['unobserved_not_configured', 'unknown'], [($r = (new VoiceReconciler)->numbers(new NullVoiceNumberObserver))['result'], $r['observed']]);
        Http::assertNothingSent();

        // La cadena ilegible: ya lo deniega todo; aquí no se abre un freno por una comparación que no se puede hacer.
        $this->publishVoice();
        config(['app.key' => 'base64:'.base64_encode(str_repeat("\x07", 32))]);   // otra clave: la firma de la entrada ya no verifica
        $r = (new VoiceReconciler)->numbers(new class implements VoiceNumberObserver
        {
            public function describeNumber(string $phoneNumberId): VoiceNumberAssignment
            {
                return new VoiceNumberAssignment(VoiceNumberAssignment::OUTCOME_OBSERVED, $phoneNumberId, 'agent_otro_0001', 200, null);
            }
        });
        $this->assertSame(['expected_unknown', 'unknown', false], [$r['result'], $r['expected'], $r['brakeOpened']]);
        $this->assertFalse(VoiceBrake::isOpen());
    }
}
