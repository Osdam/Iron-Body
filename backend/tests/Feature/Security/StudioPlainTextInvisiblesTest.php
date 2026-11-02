<?php

namespace Tests\Feature\Security;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioPublication;
use App\Models\UltronStudio\StudioVersion;
use App\Rules\PlainText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\UltronStudio\PublicationFixtures;
use Tests\Feature\UltronStudio\StudioFixtures;
use Tests\TestCase;

/**
 * AS-5 del H5 cerrado en H7 (SEC7): `PlainText` con `strict: true` rechaza con
 * 422 la clase completa de `lab-invisibles` (menos lo que forma un emoji) en
 * las entradas del Studio, y el 422 llega ANTES de escribir nada: en
 * particular, antes de la cadena de publicaciones, que no se puede editar ni
 * borrar. Fuera del Studio, `PlainText` sigue con la lista de antes del H7
 * (M-1 de la revisión final del H7): lo fija `PlainTextOutsideStudioTest`.
 *
 * Los caracteres son los que AS-5 dejó probados como «pasan la validación»
 * (U+2028, U+00AD, U+2060, U+061C, U+180E, U+E0041) más el ZWJ suelto.
 * Este fichero no lleva ningún invisible literal.
 */
class StudioPlainTextInvisiblesTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private Admin $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->importBaseline();
        $this->openKeys(['marketing.ultron.studio_config' => 'all']);
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
    }

    /** Los de AS-5 que pasaban la validación en H5, y el ZWJ suelto. */
    public static function invisiblesDeAs5(): array
    {
        return [
            'U+2028 separador de línea' => [0x2028],
            'U+00AD guion blando' => [0x00AD],
            'U+2060 unión de palabras' => [0x2060],
            'U+061C marca de letra árabe' => [0x061C],
            'U+180E separador mongol' => [0x180E],
            'U+E0041 etiqueta' => [0xE0041],
            'U+200D ZWJ suelto' => [0x200D],
        ];
    }

    #[DataProvider('invisiblesDeAs5')]
    public function test_el_motivo_de_volver_a_legacy_con_un_invisible_es_422_y_no_toca_la_cadena(int $cp): void
    {
        $antes = [StudioPublication::query()->count(), StudioAuditEvent::query()->count()];
        $codigo = sprintf('U+%04X', $cp);

        $res = $this->legacyAs($this->root, 'Volver a Legacy '.mb_chr($cp, 'UTF-8').'por la prueba');

        $res->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->assertStringContainsString("({$codigo})", (string) $res->json('errors.reason.0'));
        $this->assertSame($antes, [StudioPublication::query()->count(), StudioAuditEvent::query()->count()]);
    }

    public function test_el_mismo_motivo_sin_el_invisible_supera_la_forma(): void
    {
        $res = $this->legacyAs($this->root, 'Volver a Legacy por la prueba');

        // Lo que responda el estado (201 o un 409 de la cadena) da igual: no es la forma.
        $this->assertNotSame(422, $res->status(), (string) $res->getContent());
    }

    public function test_la_etiqueta_de_una_version_admite_un_emoji_compuesto_y_no_un_zwj_suelto(): void
    {
        $levantadora = "\u{1F3CB}\u{FE0F}\u{200D}\u{2640}\u{FE0F}";

        $bien = $this->createVersion($this->root, "Promo de enero {$levantadora}")->assertCreated();
        $this->assertSame("Promo de enero {$levantadora}", StudioVersion::query()->where('uuid', $bien->json('data.uuid'))->value('label'));

        foreach (["Pro\u{200D}mo de enero" => 'U+200D', "Pro\u{00AD}mo de enero" => 'U+00AD', "Promo\u{E0049}" => 'U+E0049'] as $etiqueta => $codigo) {
            $res = $this->studio('POST', 'versions', $this->root, [
                'label' => $etiqueta, 'note' => null, 'draftRevision' => 1, 'clientRequestId' => (string) Str::uuid(),
            ]);
            $res->assertStatus(422)->assertJsonValidationErrors(['label']);
            $this->assertStringContainsString("({$codigo})", (string) $res->json('errors.label.0'));
        }
    }

    public function test_las_etiquetas_del_borrador_pasan_por_la_clase_completa(): void
    {
        $actual = $this->studio('GET', 'draft', $this->root)->assertOk()->json('data');

        $sucio = $this->contentWith(['knowledge' => ['sources' => ['Planes', "Pre\u{2060}cios"]]]);
        $res = $this->saveDraft($this->root, $sucio, (int) $actual['revision']);
        $res->assertStatus(422);
        $this->assertStringContainsString('(U+2060)', (string) $res->getContent());

        $limpio = $this->contentWith(['knowledge' => ['sources' => ['Planes', "Precios \u{2764}\u{FE0F}"]]]);
        $this->saveDraft($this->root, $limpio, (int) $actual['revision'])->assertOk();
    }

    public function test_la_regla_en_una_linea_y_en_varias(): void
    {
        $falla = static fn (string $v, bool $multi): bool => Validator::make(['x' => $v], ['x' => [new PlainText(multiline: $multi, strict: true)]])->fails();

        // Lo de siempre sigue fallando, y lo nuevo también.
        foreach (["a\u{200B}b", "a\u{202E}b", "a\u{FEFF}b", "a\x1Bb", "a\u{00AD}b", "a\u{2028}b", "a\u{E0041}b", "a\u{200D}b"] as $malo) {
            $this->assertTrue($falla($malo, false));
            $this->assertTrue($falla($malo, true));
        }
        // Saltos: solo en varias líneas.
        $this->assertTrue($falla("a\nb", false));
        $this->assertFalse($falla("a\nb", true));
        // Emojis compuestos: en las dos.
        $familia = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
        $this->assertFalse($falla("Plan {$familia}", false));
        $this->assertFalse($falla("Plan \u{2764}\u{FE0F}\nfamiliar {$familia}", true));
        // Nulo y no-texto como antes.
        $this->assertFalse(Validator::make(['x' => null], ['x' => ['nullable', new PlainText(strict: true)]])->fails());
        $v = Validator::make(['x' => ['a']], ['x' => [new PlainText(strict: true)]]);
        $this->assertTrue($v->fails());
        $this->assertSame(PlainText::MESSAGE_INVALID, $v->errors()->first('x'));
    }

    /**
     * M-1 de la revisión final del H7: la clase completa es SOLO del Studio, y
     * en el Studio es en TODAS sus entradas. Un campo nuevo con `new PlainText`
     * sin `strict: true` volvería en silencio a la lista de antes. Se mira el
     * código (tokens, sin comentarios) de las zonas del Studio.
     */
    public function test_todas_las_entradas_del_studio_usan_el_modo_estricto(): void
    {
        $laxas = [];
        $vistas = 0;
        foreach (['app/Http/Controllers/Crm/UltronStudio', 'app/Services/UltronStudio', 'app/Console/Commands/UltronStudio'] as $zona) {
            foreach (File::allFiles(base_path($zona)) as $fichero) {
                if ($fichero->getExtension() !== 'php') {
                    continue;
                }
                $tokens = array_values(array_filter(
                    token_get_all($fichero->getContents()),
                    static fn ($t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
                ));
                foreach ($tokens as $i => $t) {
                    $clase = $tokens[$i + 1] ?? null;
                    if (! is_array($t) || $t[0] !== T_NEW || ! is_array($clase) || ! in_array(ltrim($clase[1], '\\'), ['PlainText', 'App\\Rules\\PlainText'], true)) {
                        continue;
                    }
                    $vistas++;
                    // Los argumentos, hasta el paréntesis que los cierra.
                    $args = '';
                    if (($tokens[$i + 2] ?? null) === '(') {
                        for ($j = $i + 3, $nivel = 1; isset($tokens[$j]); $j++) {
                            $nivel += $tokens[$j] === '(' ? 1 : ($tokens[$j] === ')' ? -1 : 0);
                            if ($nivel === 0) {
                                break;
                            }
                            $args .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                        }
                    }
                    if (! str_contains($args, 'strict:true')) {
                        $laxas[] = "{$zona}/{$fichero->getRelativePathname()}:{$clase[2]}";
                    }
                }
            }
        }

        $this->assertSame([], $laxas, 'Entradas del Studio con la lista de antes del H7 (falta `strict: true`).');
        // Los 18 de los controladores y el del normalizador del borrador: si
        // el rastreo dejara de verlos, no estaría comprobando nada.
        $this->assertGreaterThanOrEqual(19, $vistas, 'el rastreo ve las entradas del Studio');
    }
}
