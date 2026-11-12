<?php

namespace Tests\Unit\Voice;

use App\Services\Telephony\InboundPolicy;
use App\Services\Telephony\OutboundPolicy;
use App\Services\UltronStudio\StudioIntegrations;
use App\Services\Voice\VoiceCallContext;
use App\Services\Voice\VoiceCallPolicy;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Voice\Support\ClosedPolicyCopy;

/**
 * EL ESTADO CERRADO, CON LAS LLAMADAS ABIERTAS EN EL ÁRBOL (C-4).
 *
 * El commit `voice(calls-open)` gira `CALLS_ENABLED_IN_CODE` y el árbol ya no
 * puede llegar al estado cerrado, que es justo el destino de la vuelta atrás
 * (`git revert` de esa línea). Esta prueba lo mantiene vivo sobre una COPIA de
 * las políticas puras con la constante cerrada ({@see ClosedPolicyCopy}): la
 * misma clase, línea a línea, salvo la constante y el nombre.
 *
 * LO QUE SE PROTEGE.
 *  - Que la copia es el fichero real con UNA línea de la constante cambiada
 *    (y el nombre de la clase, y en las de la telefonía el `use`): si alguien
 *    cambia otra cosa en la política, la copia lo hereda.
 *  - Que, cerrada, la política del H6 NUNCA permite y dice
 *    `calls_disabled_in_code` el primero: la tabla de casos que tenía
 *    `VoiceCallPolicyTest` antes de abrir, literal, y los 18 casos de paridad
 *    del H6 (`integrations-cases.json`) con ese motivo delante.
 *  - Que cerrar la constante es exactamente añadir ese motivo: el mismo caso,
 *    abierto en el árbol y cerrado en la copia, difiere solo en él.
 *
 * En un árbol con la constante cerrada (sin `voice(calls-open)`) no hay copia
 * cerrada que hacer: estas pruebas caen, como las guardas.
 *
 * Las políticas de la telefonía (`InboundPolicy`, `OutboundPolicy`) se cruzan
 * con sus columnas `codeClosed` en `TelephonyFixtureParityTest`; el iniciador y
 * el desplegador cerrados, en `ElevenLabsClosedCopyTest`; el job cerrado, en
 * `PlaceOutboundVoiceCallClosedCopyTest`.
 */
class VoiceCallPolicyClosedCopyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ClosedPolicyCopy::load();
    }

    /** @return array<string, array{0: array<string,mixed>, 1: list<string>}> los casos de `VoiceCallPolicyTest` tal cual eran con la constante cerrada (1ef1d72e) */
    public static function casosCerrados(): array
    {
        return [
            'todo en verde: solo el código lo cierra' => [[], ['calls_disabled_in_code']],
            'el estado de producción del H6 (§I6.6)' => [
                ['twilioVoiceEnabled' => false, 'twilioVoiceFromConfigured' => false, 'providerState' => 'ELEVENLABS_NOT_CONFIGURED', 'canary' => 'not_registered', 'dailyCap' => 0],
                ['calls_disabled_in_code', 'twilio_voice_disabled', 'no_voice_number', 'elevenlabs_not_configured', 'not_an_active_voice_canary', 'daily_cap_zero'],
            ],
            'todo en contra' => [
                ['twilioVoiceEnabled' => false, 'twilioVoiceFromConfigured' => false, 'providerState' => 'ELEVENLABS_NOT_CONFIGURED', 'canary' => 'not_active', 'source' => 'marketing_calls', 'dailyCap' => -1],
                ['calls_disabled_in_code', 'twilio_voice_disabled', 'no_voice_number', 'elevenlabs_not_configured', 'not_an_active_voice_canary', 'source_not_allowed', 'daily_cap_zero'],
            ],
            'ElevenLabs sin configurar' => [['providerState' => 'ELEVENLABS_NOT_CONFIGURED'], ['calls_disabled_in_code', 'elevenlabs_not_configured']],
            'ElevenLabs apagado' => [['providerState' => 'ELEVENLABS_DISABLED'], ['calls_disabled_in_code', 'elevenlabs_disabled']],
            'ElevenLabs sin verificar' => [['providerState' => 'ELEVENLABS_UNVERIFIED'], ['calls_disabled_in_code', 'elevenlabs_unverified']],
            'ElevenLabs verificado no añade nada' => [['providerState' => 'ELEVENLABS_VERIFIED'], ['calls_disabled_in_code']],
            'Twilio Voice apagado' => [['twilioVoiceEnabled' => false], ['calls_disabled_in_code', 'twilio_voice_disabled']],
            'sin número de voz' => [['twilioVoiceFromConfigured' => false], ['calls_disabled_in_code', 'no_voice_number']],
            'canario de voz vivo pero no activo' => [['canary' => 'not_active'], ['calls_disabled_in_code', 'not_an_active_voice_canary']],
            'sin canario de voz registrado' => [['canary' => 'not_registered'], ['calls_disabled_in_code', 'not_an_active_voice_canary']],
            'origen marketing_calls (T-7)' => [['source' => 'marketing_calls'], ['calls_disabled_in_code', 'source_not_allowed']],
            'otro origen' => [['source' => 'other'], ['calls_disabled_in_code', 'source_not_allowed']],
            'cupo 0' => [['dailyCap' => 0], ['calls_disabled_in_code', 'daily_cap_zero']],
            'cupo negativo' => [['dailyCap' => -3], ['calls_disabled_in_code', 'daily_cap_zero']],
            'cupo 1' => [['dailyCap' => 1], ['calls_disabled_in_code']],
            'cupo 20' => [['dailyCap' => 20], ['calls_disabled_in_code']],
            'la política no recorta el cupo (lo hace la configuración)' => [['dailyCap' => 21], ['calls_disabled_in_code']],
            'mezcla: sin verificar, marketing_calls y cupo 0' => [
                ['providerState' => 'ELEVENLABS_UNVERIFIED', 'source' => 'marketing_calls', 'dailyCap' => 0],
                ['calls_disabled_in_code', 'elevenlabs_unverified', 'source_not_allowed', 'daily_cap_zero'],
            ],
            'mezcla: apagado, sin número y sin canario' => [
                ['providerState' => 'ELEVENLABS_DISABLED', 'twilioVoiceFromConfigured' => false, 'canary' => 'not_registered'],
                ['calls_disabled_in_code', 'no_voice_number', 'elevenlabs_disabled', 'not_an_active_voice_canary'],
            ],
        ];
    }

    /** @param  array<string,mixed>  $cambios */
    private static function contexto(array $cambios = []): VoiceCallContext
    {
        $c = $cambios + [
            'twilioVoiceEnabled' => true, 'twilioVoiceFromConfigured' => true, 'providerState' => 'ELEVENLABS_VERIFIED',
            'canary' => 'active_voice', 'source' => 'canary_registry', 'dailyCap' => 5,
        ];

        return new VoiceCallContext($c['twilioVoiceEnabled'], $c['twilioVoiceFromConfigured'], $c['providerState'], $c['canary'], $c['source'], $c['dailyCap']);
    }

    public function test_la_copia_es_la_politica_real_con_solo_la_constante_cerrada(): void
    {
        $voz = 'app/Services/Voice/VoiceCallPolicy.php';
        $this->assertSame(1, substr_count(ClosedPolicyCopy::original($voz), ClosedPolicyCopy::OPEN_LINE), 'el árbol tiene la constante abierta, literal y una sola vez');
        $cambios = ClosedPolicyCopy::changedLines($voz);
        $this->assertCount(2, $cambios, 'la clase y la constante; nada más');
        $this->assertContains(['final class VoiceCallPolicy', 'final class VoiceCallPolicy'.ClosedPolicyCopy::SUFFIX], $cambios);
        $this->assertContains(['    '.ClosedPolicyCopy::OPEN_LINE, '    '.ClosedPolicyCopy::CLOSED_LINE], $cambios);
        $lectores = array_diff(array_keys(ClosedPolicyCopy::COPIES), [$voz]);
        $this->assertCount(5, $lectores, 'las dos políticas de la telefonía, el job, el iniciador y el desplegador');
        foreach ($lectores as $lector) {
            $this->assertCount(2, ClosedPolicyCopy::changedLines($lector), "{$lector}: la clase y el use de VoiceCallPolicy; nada más");
            $this->assertMatchesRegularExpression('/VoiceCallPolicy::(CALLS_ENABLED_IN_CODE|decide\()/', ClosedPolicyCopy::original($lector), "{$lector} lee la constante (o la política del H6) por el alias");
        }
        // Todo lo que lee la constante para DECIDIR tiene su copia (el doctor y el estado solo la dicen). Quien decide A
        // TRAVÉS de la política del H6 (`VoiceCallPolicy::decide(`) la lee igual, sin nombrarla (revisión de `T`, M-2).
        $lectoresDelArbol = $llamanALaPolitica = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ClosedPolicyCopy::backend().'/app', \FilesystemIterator::SKIP_DOTS)) as $fichero) {
            $codigo = (string) file_get_contents((string) $fichero);
            if (! str_contains($codigo, 'CALLS_ENABLED_IN_CODE') && ! str_contains($codigo, 'VoiceCallPolicy::decide(')) {
                continue;
            }
            $sinComentarios = implode('', array_map(static fn ($t): string => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all($codigo)));
            $relativa = substr((string) $fichero, strlen(ClosedPolicyCopy::backend()) + 1);
            if (str_contains($sinComentarios, 'VoiceCallPolicy::CALLS_ENABLED_IN_CODE')) {
                $lectoresDelArbol[] = $relativa;
            }
            if (str_contains($sinComentarios, 'VoiceCallPolicy::decide(')) {
                $llamanALaPolitica[] = $relativa;
            }
        }
        sort($lectoresDelArbol);
        sort($llamanALaPolitica);
        // `OutboundPolicy` decide la saliente y tiene su copia; `StudioIntegrations` solo pinta el estado del panel del H6.
        $this->assertSame(['app/Services/Telephony/OutboundPolicy.php', 'app/Services/UltronStudio/StudioIntegrations.php'], $llamanALaPolitica,
            'quien llama a la política del H6 necesita su copia cerrada (o decir por qué no)');
        $this->assertSame(['app/Services/UltronStudio/StudioIntegrations.php'], array_values(array_diff($llamanALaPolitica, array_keys(ClosedPolicyCopy::COPIES))),
            'el único que llama a la política sin copia cerrada es el panel del Studio, que no decide ninguna llamada');
        $this->assertSame(['app/Console/Commands/Voice/VoiceDoctorCommand.php', 'app/Jobs/Voice/PlaceOutboundVoiceCall.php', 'app/Services/Telephony/InboundPolicy.php',
            'app/Services/Telephony/VoiceTelephonyStatus.php', 'app/Services/Voice/ElevenLabs/ElevenLabsAgentDeployer.php', 'app/Services/Voice/ElevenLabs/ElevenLabsCallStarter.php'],
            $lectoresDelArbol, 'un lector nuevo de la constante necesita su copia cerrada (o decir por qué no)');

        $this->assertTrue(VoiceCallPolicy::CALLS_ENABLED_IN_CODE, 'el árbol');
        $this->assertFalse(constant(ClosedPolicyCopy::VOICE.'::CALLS_ENABLED_IN_CODE'), 'la copia');
        $this->assertSame(VoiceCallPolicy::REASONS, constant(ClosedPolicyCopy::VOICE.'::REASONS'));
        $this->assertSame(InboundPolicy::REASONS, constant(ClosedPolicyCopy::INBOUND.'::REASONS'));
        $this->assertSame(OutboundPolicy::REASONS, constant(ClosedPolicyCopy::OUTBOUND.'::REASONS'));
    }

    public function test_cerrada_la_politica_nunca_permite_y_dice_la_constante_la_primera(): void
    {
        /** @var class-string<VoiceCallPolicy> $cerrada */
        $cerrada = ClosedPolicyCopy::VOICE;
        foreach (self::casosCerrados() as $nombre => [$cambios, $motivos]) {
            $d = $cerrada::decide(self::contexto($cambios));
            $this->assertSame($motivos, $d->reasons, $nombre);
            $this->assertFalse($d->allowed, "{$nombre}: cerrada, nunca se permite una llamada");
            $this->assertSame('calls_disabled_in_code', $d->reasons[0], "{$nombre}: el código lo cierra primero");
            $this->assertSame(['allowed' => false, 'reasons' => $motivos], $d->toArray(), $nombre);
        }
    }

    public function test_cerrar_la_constante_es_exactamente_anadir_su_motivo_delante(): void
    {
        $abiertos = array_values(VoiceCallPolicyTest::casos());
        $cerrados = array_values(self::casosCerrados());
        $this->assertCount(count($cerrados), $abiertos, 'los mismos casos abiertos y cerrados');
        foreach ($cerrados as $i => [$cambios, $motivos]) {
            $this->assertSame($cambios, $abiertos[$i][0], "caso {$i}: el mismo contexto");
            $this->assertSame($motivos, ['calls_disabled_in_code', ...$abiertos[$i][1]], "caso {$i}: cerrado = abierto con la constante delante");
            $this->assertSame($abiertos[$i][1], VoiceCallPolicy::decide(self::contexto($cambios))->reasons, "caso {$i}: el árbol da el abierto");
        }
    }

    /** Los 18 casos `decide` del fixture de paridad del H6, con la copia cerrada: los mismos motivos con la constante delante, y los niveles nunca disponibles. */
    public function test_los_casos_de_paridad_del_h6_con_la_copia_cerrada(): void
    {
        /** @var class-string<VoiceCallPolicy> $cerrada */
        $cerrada = ClosedPolicyCopy::VOICE;
        $fx = json_decode((string) file_get_contents(ClosedPolicyCopy::backend().'/tests/fixtures/ultron-studio/integrations-cases.json'), true, 512, JSON_THROW_ON_ERROR);
        $vistos = 0;
        foreach ($fx['voiceCallPolicy'] as $caso) {
            if ($caso['kind'] !== 'decide') {
                continue;
            }
            $c = $caso['context'];
            $d = $cerrada::decide(new VoiceCallContext($c['twilioVoiceEnabled'], $c['twilioVoiceFromConfigured'], $c['providerState'], $c['canary'], $c['source'], $c['dailyCap']))->toArray();
            $motivos = ['calls_disabled_in_code', ...$caso['expected']['reasons']];
            $this->assertSame(['allowed' => false, 'reasons' => $motivos], $d, $caso['name']);
            $integracion = array_values(array_filter($caso['expected']['levels'], static fn (array $n): bool => $n['id'] === 'integration_test'))[0]['reasons'];
            $this->assertSame([
                ['id' => 'text_simulation', 'available' => true, 'cost' => 'none', 'where' => 'laboratorio'],
                ['id' => 'integration_test', 'available' => false, 'reasons' => ['calls_disabled_in_code', ...$integracion]],
                ['id' => 'real_call', 'available' => false, 'reasons' => $motivos],
            ], StudioIntegrations::voiceLevels($d['reasons']), $caso['name']);
            $vistos++;
        }
        $this->assertSame(18, $vistos);
    }
}
