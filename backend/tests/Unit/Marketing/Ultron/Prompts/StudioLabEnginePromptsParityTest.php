<?php

namespace Tests\Unit\Marketing\Ultron\Prompts;

use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\Prompts\UltronPromptOffer;
use App\Services\Marketing\Ultron\UltronPublishedConfig as Lector;
use App\Services\UltronStudio\StudioPromptBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T1 DEL APARTADO 5 (diseño `plans/informes-cierre/diseno-arq-lab.md` §3.4):
 * LOS PROMPTS DEL RECORRIDO DEL MOTOR EN EL LABORATORIO SON LOS DEL MOTOR.
 *
 * {@see StudioPromptBundle::rolesFor()} tiene que dar, para lo gobernado de una
 * versión:
 *  - los cinco textos y la huella del compilador, y la huella que se firmaría
 *    al publicar ({@see StudioPromptBundle::compile()});
 *  - y, con esa versión PUBLICADA en la canaria (entrada real de la cadena,
 *    firmada con la firma del motor), exactamente los roles, la huella y el
 *    `sp` que ofrece `decide` ({@see UltronPromptOffer}), salvo la entrada y el
 *    `seq` de la cadena, que el laboratorio no tiene.
 *
 * Si el compilador o la oferta cambian sin el laboratorio, esto se pone en rojo.
 */
class StudioLabEnginePromptsParityTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const VERSION = 'c0000000-0000-4000-8000-00000000000c';

    private int $canario = 777;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('marketing.ultron.studio_config', Lector::CANARY);
        config()->set('marketing.ultron.studio_prompts', UltronPromptOffer::KEY_CANARY);
        config()->set('marketing.ultron.canary_conversation_id', $this->canario);
    }

    /** @return array<string, array{0:array<string,mixed>}> */
    public static function gobernados(): array
    {
        return [
            'la línea base' => [[]],
            'profesional y 200' => [['persona.tone' => 'profesional', 'persona.maxChars' => 200]],
            'solo el objetivo' => [['strategy.goal' => 'venta']],
            'con emojis y frases' => [['persona.emojis' => true, 'rules.forbiddenPhrases' => ['sin compromiso', 'te lo regalo']]],
            'entusiasta, 480 e informar' => [['persona.tone' => 'entusiasta', 'persona.maxChars' => 480, 'strategy.goal' => 'informar']],
        ];
    }

    /** @param  array<string,mixed>  $cambios */
    #[DataProvider('gobernados')]
    public function test_los_roles_y_la_huella_son_los_del_compilador_y_los_que_se_firmarian(array $cambios): void
    {
        $gobernado = array_replace(UltronPromptCompiler::BASELINE, $cambios);
        $lab = StudioPromptBundle::rolesFor($gobernado);
        $compilado = UltronPromptCompiler::compile($gobernado);

        $this->assertSame($compilado['roles'], $lab['roles']);
        $this->assertSame(StudioPromptBundle::roles(), array_keys($lab['roles']), 'los cinco, en su orden canónico');
        $this->assertSame($compilado['bundleHash'], $lab['bundleHash']);
        $this->assertSame(StudioPromptBundle::compile($gobernado)['prompts']['bundleHash'], $lab['bundleHash'], 'la que se firmaría al publicar');
    }

    /** @param  array<string,mixed>  $cambios */
    #[DataProvider('gobernados')]
    public function test_con_la_version_publicada_en_la_canaria_es_lo_que_ofrece_decide(array $cambios): void
    {
        $parte = self::parteV2(self::VERSION, $cambios);
        $this->publicaEntrada(Lector::CONTRACT_V2, $parte, null);
        $oferta = (new UltronPromptOffer)->forConversation($this->canario);
        $this->assertSame('studio', $oferta['response']['mode'], 'la oferta del motor es la de la versión');

        $lab = StudioPromptBundle::rolesFor($parte['governed'], self::VERSION, $parte['contentHash']);

        $this->assertSame($oferta['response']['roles'], $lab['roles']);
        $this->assertSame($oferta['response']['bundle_hash'], $lab['bundleHash']);
        $sinCadena = static fn (array $sp): array => array_diff_key($sp, ['e' => true, 's' => true]);
        $this->assertSame($sinCadena($oferta['sp']), $sinCadena($lab['sp']), 'el mismo sp, clave a clave y en el mismo orden');
        $this->assertSame([null, null], [$lab['sp']['e'], $lab['sp']['s']], 'sin entrada de la cadena: no está publicada');
        $this->assertNotNull($oferta['sp']['e']);
    }

    public function test_lo_que_no_compila_no_sale(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StudioPromptBundle::rolesFor(array_replace(UltronPromptCompiler::BASELINE, ['persona.maxChars' => 999]));
    }
}
