<?php

namespace Tests\Feature\Security;

use App\Models\Member;
use App\Models\Trainer;
use App\Models\ValuationAppointment;
use App\Models\ValuationAvailabilityBlock;
use App\Rules\PlainText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Caja\BuildsCashReceipts;
use Tests\Feature\Caja\BuildsReceiptAgent;
use Tests\Feature\Valuations\ValuationFixtures;
use Tests\TestCase;

/**
 * M-1 de la revisión final del H7 (decisión del lead: acotar la clase nueva al
 * Studio). La clase completa de invisibles es solo del Studio
 * (`new PlainText(strict: true)`, {@see StudioPlainTextInvisiblesTest}); fuera,
 * `PlainText` sigue con la lista de ANTES del H7 (`UntrustedText::isPlain`) y
 * con su mensaje de siempre.
 *
 * Fuera están la agenda de valoraciones y la Caja (equipos de impresión y el
 * servicio local), en producción desde antes del H7. Si allí se ampliara la
 * clase, una nota ya guardada con un guion blando o un U+2028 dejaría de poder
 * reenviarse (el CRM reenvía `notes` al cambiar el tipo de la cita) y el CRM
 * enseñaría su propio mensaje, que no es la causa.
 *
 * Se fija: (1) lo que el H7 empezó a rechazar se acepta otra vez fuera del
 * Studio y se sigue rechazando dentro; (2) la lista de antes se sigue
 * rechazando con el mismo mensaje; (3) el ZWJ, siempre, también dentro de un
 * emoji, como antes; (4) en todo Unicode, por defecto se rechaza EXACTAMENTE
 * la lista de antes; (5) lo mismo por HTTP en valoraciones y en la Caja.
 * Este fichero no lleva ningún invisible literal.
 */
class PlainTextOutsideStudioTest extends TestCase
{
    use BuildsCashReceipts;
    use BuildsReceiptAgent;
    use RefreshDatabase;
    use ValuationFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Lo que `PlainText` rechazó por primera vez con el H7 (la clase de `lab-invisibles`). */
    public static function nuevosDelH7(): array
    {
        return [
            'U+00AD guion blando' => [0x00AD],
            'U+2028 separador de línea' => [0x2028],
            'U+2029 separador de párrafo' => [0x2029],
            'U+2060 unión de palabras' => [0x2060],
            'U+2064 más invisible' => [0x2064],
            'U+206A' => [0x206A],
            'U+206F' => [0x206F],
            'U+061C marca de letra árabe' => [0x061C],
            'U+180E separador mongol' => [0x180E],
            'U+0600' => [0x0600],
            'U+06DD' => [0x06DD],
            'U+070F' => [0x070F],
            'U+0890' => [0x0890],
            'U+08E2' => [0x08E2],
            'U+FFF9' => [0xFFF9],
            'U+FFFB' => [0xFFFB],
            'U+E0001 etiqueta de idioma' => [0xE0001],
            'U+E0041 etiqueta' => [0xE0041],
            'U+E007F fin de etiqueta' => [0xE007F],
        ];
    }

    /** La lista de antes del H7: controles, anchura cero (con el ZWJ), bidi y BOM. */
    public static function listaDeAntes(): array
    {
        return [
            'NUL' => [0x0000], 'campana' => [0x0007], 'escape' => [0x001B], 'DEL' => [0x007F],
            'U+0085 C1' => [0x0085], 'U+009F C1' => [0x009F],
            'U+200B' => [0x200B], 'U+200C' => [0x200C], 'U+200D ZWJ' => [0x200D], 'U+200E' => [0x200E], 'U+200F' => [0x200F],
            'U+202A' => [0x202A], 'U+202E' => [0x202E], 'U+2066' => [0x2066], 'U+2069' => [0x2069],
            'U+FEFF BOM' => [0xFEFF],
        ];
    }

    #[DataProvider('nuevosDelH7')]
    public function test_por_defecto_acepta_lo_que_el_h7_rechazaba_por_primera_vez_y_el_studio_no(int $cp): void
    {
        $texto = 'Nota '.mb_chr($cp, 'UTF-8').'pegada';

        foreach ([false, true] as $multi) {
            $this->assertNull($this->error($texto, new PlainText(multiline: $multi)), sprintf('U+%04X, varias líneas: %s', $cp, var_export($multi, true)));
            $this->assertStringContainsString(sprintf('(U+%04X)', $cp), (string) $this->error($texto, new PlainText(multiline: $multi, strict: true)));
        }
    }

    #[DataProvider('listaDeAntes')]
    public function test_por_defecto_sigue_rechazando_la_lista_de_antes_con_su_mensaje(int $cp): void
    {
        $texto = 'Nota '.mb_chr($cp, 'UTF-8').'pegada';

        foreach ([false, true] as $multi) {
            $this->assertSame(PlainText::MESSAGE_INVALID, $this->error($texto, new PlainText(multiline: $multi)));
        }
    }

    public function test_el_zwj_se_rechaza_siempre_como_antes_y_los_selectores_pasan(): void
    {
        $familia = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
        $arcoiris = "\u{1F3F3}\u{FE0F}\u{200D}\u{1F308}";

        foreach ([$familia, $arcoiris] as $emoji) {
            $this->assertSame(PlainText::MESSAGE_INVALID, $this->error("Plan {$emoji}", new PlainText));
            $this->assertSame(PlainText::MESSAGE_INVALID, $this->error("Plan {$emoji}", new PlainText(multiline: true)));
            // En el Studio, el emoji compuesto sí (H7).
            $this->assertNull($this->error("Plan {$emoji}", new PlainText(strict: true)));
        }
        foreach (["\u{2764}\u{FE0F}", "1\u{FE0F}\u{20E3}"] as $emoji) {
            $this->assertNull($this->error("Plan {$emoji}", new PlainText));
            $this->assertNull($this->error("Plan {$emoji}", new PlainText(strict: true)));
        }
    }

    public function test_saltos_utf8_invalido_y_no_texto_como_antes(): void
    {
        foreach (["a\tb", "a\nb", "a\r\nb"] as $salto) {
            $this->assertSame(PlainText::MESSAGE_INVALID, $this->error($salto, new PlainText));
            $this->assertNull($this->error($salto, new PlainText(multiline: true)));
        }
        foreach (["Nota \xFF\xFE rota", "Valoraci\xF3n", ['a'], 12] as $malo) {
            $this->assertSame(PlainText::MESSAGE_INVALID, $this->error($malo, new PlainText));
            $this->assertSame(PlainText::MESSAGE_INVALID, $this->error($malo, new PlainText(multiline: true)));
        }
        $this->assertFalse(Validator::make(['x' => null], ['x' => ['nullable', new PlainText]])->fails());
        // Los espacios visibles (NBSP, U+202F de las horas de Intl) no están en ninguna de las dos listas.
        $this->assertNull($this->error("Recepción · Caja 1 (POS-58) ñandú ü 10:00\u{202F}a.\u{00A0}m.", new PlainText));
    }

    /**
     * Todo Unicode, punto de código a punto de código, frente a la lista de
     * antes del H7 escrita a mano (la de `UntrustedText` en producción
     * `1e5f9a0`): por defecto se rechaza EXACTAMENTE eso, ni un carácter más
     * ni uno menos: 80 en una línea y 77 en varias (sin tabulador ni saltos).
     */
    public function test_por_defecto_rechaza_exactamente_la_lista_de_antes_en_todo_unicode(): void
    {
        $deAntes = static fn (int $cp, bool $multi): bool => ($cp <= 0x1F && ! ($multi && in_array($cp, [0x09, 0x0A, 0x0D], true)))
            || ($cp >= 0x7F && $cp <= 0x9F)
            || ($cp >= 0x200B && $cp <= 0x200F)
            || ($cp >= 0x202A && $cp <= 0x202E)
            || ($cp >= 0x2066 && $cp <= 0x2069)
            || $cp === 0xFEFF;

        $reglas = [false => new PlainText, true => new PlainText(multiline: true)];
        $rechaza = false;
        $falla = static function () use (&$rechaza): void {
            $rechaza = true;
        };
        $discrepancias = [];
        $rechazados = [0, 0];
        for ($cp = 0; $cp <= 0x10FFFF; $cp++) {
            if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                continue;
            }
            $texto = 'a'.mb_chr($cp, 'UTF-8').'b';
            foreach ($reglas as $multi => $regla) {
                $rechaza = false;
                $regla->validate('x', $texto, $falla);
                $rechazados[(int) $multi] += $rechaza ? 1 : 0;
                if ($rechaza !== $deAntes($cp, (bool) $multi)) {
                    $discrepancias[] = sprintf('U+%04X%s', $cp, $multi ? ' (varias líneas)' : '');
                }
            }
        }

        $this->assertSame([], array_slice($discrepancias, 0, 20));
        $this->assertSame([80, 77], $rechazados);
    }

    // ── Por HTTP: valoraciones ──────────────────────────────────────────────

    /**
     * El escenario de M-1: una nota con un guion blando y un U+2028 (pegada de
     * un PDF) se guarda; al cambiar el tipo de la cita el CRM la reenvía tal
     * cual, y se acepta. Con la clase completa, ese reenvío era un 422 con un
     * mensaje del CRM que no decía la causa.
     */
    public function test_valoraciones_aceptan_y_guardan_lo_que_aceptaban_antes_del_h7(): void
    {
        [$h, $laura, $andres] = $this->agenda();
        $nota = "Trae ropa c\u{00AD}ómoda.\u{2028}Llega 10 min antes \u{2060}(ayuno) \u{E0041}.";

        $uuid = $this->book($h, $laura, $andres, '2026-10-24T09:00:00-05:00', ['notes' => $nota])
            ->assertCreated()->assertJsonPath('data.notes', $nota)->json('data.uuid');

        $this->patchJson("/api/admin/valuations/{$uuid}", ['expected_version' => 1, 'type' => 'follow_up', 'notes' => $nota], $h)
            ->assertOk()->assertJsonPath('data.notes', $nota);
        // Editar tipo o notas no sube la versión (contrato de la agenda §1).
        $cita = $this->appointment($uuid);
        $this->assertSame(['follow_up', 1, $nota], [$cita->type, (int) $cita->version, $cita->notes]);

        $motivo = "No puede venir.\u{2029}Llamó a rec\u{00AD}epción.";
        $this->postJson("/api/admin/valuations/{$uuid}/cancel", ['expected_version' => 1, 'reason' => $motivo], $h)
            ->assertOk()->assertJsonPath('data.cancellation_reason', $motivo);

        // Una línea (el motivo del bloqueo y la búsqueda): también, como antes.
        $this->postJson("/api/admin/valuations/trainers/{$andres->id}/blocks", [
            'starts_at' => '2026-10-26T08:00:00-05:00', 'ends_at' => '2026-10-26T09:00:00-05:00', 'kind' => 'break',
            'reason' => "Cita m\u{00AD}édica \u{180E}revisión",
        ], $h)->assertCreated();
        $this->assertSame(1, ValuationAvailabilityBlock::count());
        $this->getJson('/api/admin/valuations/members/search?q='.rawurlencode("La\u{00AD}ura"), $h)->assertOk();
    }

    public function test_valoraciones_siguen_rechazando_la_lista_de_antes_con_su_mensaje(): void
    {
        [$h, $laura, $andres] = $this->agenda();
        $familia = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";

        foreach (["Hola\u{200B}mundo", "Hola \u{202E}odnum", "Viene en familia {$familia}"] as $malo) {
            $this->book($h, $laura, $andres, '2026-10-24T11:00:00-05:00', ['notes' => $malo])
                ->assertStatus(422)->assertJsonPath('code', 'validation_failed')
                ->assertJsonPath('errors.notes.0', PlainText::MESSAGE_INVALID);
        }
        $this->postJson("/api/admin/valuations/trainers/{$andres->id}/blocks", [
            'starts_at' => '2026-10-26T08:00:00-05:00', 'ends_at' => '2026-10-26T09:00:00-05:00', 'kind' => 'break',
            'reason' => "Cita\u{FEFF} médica",
        ], $h)->assertStatus(422)->assertJsonPath('errors.reason.0', PlainText::MESSAGE_INVALID);

        $this->assertSame([0, 0], [ValuationAppointment::count(), ValuationAvailabilityBlock::count()]);
    }

    // ── Por HTTP: la Caja ───────────────────────────────────────────────────

    public function test_la_caja_acepta_lo_que_aceptaba_antes_del_h7(): void
    {
        $sup = $this->caja();
        $clave = (string) Str::uuid();
        $nombre = "Caja\u{00AD} recepción \u{2060}1";

        $this->postJson('/api/admin/receipt-stations', ['station_key' => $clave, 'name' => $nombre], $sup)
            ->assertStatus(201)->assertJsonPath('data.name', $nombre);
        $this->patchJson("/api/admin/receipt-stations/{$clave}", ['printer_name' => "POS\u{2060}58"], $sup)
            ->assertOk()->assertJsonPath('data.printer_name', "POS\u{2060}58");

        // El servicio local declara su impresora al canjear y al reportar.
        $estacion = $this->estacion();
        $token = $this->ticketDeEstacion($estacion, 'test', null, $sup)->assertStatus(201)->json('data.token');
        $this->canjear($token, $estacion, ['printer_name' => "POS\u{00AD}58"])->assertOk();
        $this->reportar($token, $estacion, 'accepted', ['printer_name' => "POS\u{2028}58"])->assertOk();
    }

    public function test_la_caja_sigue_rechazando_la_lista_de_antes(): void
    {
        $sup = $this->caja();

        $this->postJson('/api/admin/receipt-stations', ['station_key' => (string) Str::uuid(), 'name' => "Ca\u{200B}ja 2"], $sup)
            ->assertStatus(422)->assertJsonPath('errors.name.0', PlainText::MESSAGE_INVALID);

        $estacion = $this->estacion();
        $token = $this->ticketDeEstacion($estacion, 'test', null, $sup)->assertStatus(201)->json('data.token');
        $this->canjear($token, $estacion, ['printer_name' => "POS\u{200B}58"])->assertStatus(422)->assertJsonPath('code', 'invalid_request');
        // El ticket no se gastó: con un nombre limpio, el mismo canje pasa.
        $this->canjear($token, $estacion, ['printer_name' => 'POS-58'])->assertOk();
    }

    // ────────────────────────────────────────────────────────────────────────

    /** El primer mensaje con que falla la regla, o null si pasa. */
    private function error(mixed $valor, PlainText $regla): ?string
    {
        $v = Validator::make(['x' => $valor], ['x' => [$regla]]);

        return $v->fails() ? $v->errors()->first('x') : null;
    }

    /** @return array{0: array<string, string>, 1: Member, 2: Trainer} */
    private function agenda(): array
    {
        $this->clock('2026-10-19 08:00');
        $andres = $this->trainer('Andrés Pérez');
        $this->everyDay($andres);

        return [$this->h($this->superAdmin()), $this->member('Laura Gómez'), $andres];
    }

    /** @return array<string, string> cabeceras de quien supervisa la Caja */
    private function caja(): array
    {
        $this->relojDelMostrador();
        $this->empresa();

        return $this->supervisor();
    }
}
