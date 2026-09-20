<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\ConsentAuthority;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cuándo se puede reabrir un contacto que la persona cerró.
 *
 * La trampa de este guard tiene nombre y está en los datos: la frase que
 * CIERRA el contacto contiene, palabra por palabra, la frase que lo abriría.
 * «ya no quiero que me escriban» lleva dentro «quiero que me escriban». Sin
 * la ventana de negación, el mensaje con el que alguien pide que lo dejen en
 * paz sería el mensaje con el que se le vuelve a abrir la puerta.
 *
 * Por eso el banco de abajo está escrito al revés de lo normal: primero las
 * que NO deben reabrir, y son la mayoría.
 */
class ConsentAuthorityTest extends TestCase
{
    private ConsentAuthority $autoridad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autoridad = new ConsentAuthority;
    }

    // ── Lo que NO reabre ──────────────────────────────────────────────────────

    #[DataProvider('frasesQueNoReabren')]
    public function test_a_message_that_does_not_ask_for_contact_does_not_reopen(string $frase): void
    {
        $this->assertNull($this->autoridad->reconsentimientoEn($frase), $frase);
    }

    public static function frasesQueNoReabren(): array
    {
        return array_map(fn ($f) => [$f], [
            // La trampa: contienen la frase buena, negada.
            'ya no quiero que me escriban',
            'no quiero que me escriban mas',
            'nunca quiero que me contacten',
            'no me pueden escribir mas',
            'prefiero no recibir informacion',
            'dejen de escribirme',
            'paren de mandarme mensajes',
            'no acepto recibir promociones',
            'tampoco quiero que me llamen',
            'ni me escriban ni me llamen',
            // Ni petición ni permiso: hablan del pasado o de terceros.
            // La restriccion va DETRAS: mirar solo hacia atras dejaba pasar
            // justamente el canal que la persona acababa de excluir.
            'me pueden escribir al correo, por whatsapp no',
            'pueden escribirme al correo pero no por aqui',
            'quiero que me escriban solo por correo',
            'me pueden escribir cuando tenga tiempo, ahora no',
            'pueden escribirme unicamente por correo',
            'me pueden llamar pero nunca por whatsapp',
            'acepto recibir mensajes salvo los domingos',
            'me escribieron ayer y no vi el mensaje',
            'escriban al otro numero',
            'ustedes me escribieron primero',
            'quien me escribio?',
            // Cosas normales de un gimnasio.
            'cuanto vale la mensualidad',
            'a que hora abren',
            'quiero empezar a entrenar',
            'quiero informacion de los planes',
            'necesito recibir mi factura',
            'quiero que me devuelvan el dinero',
            'gracias, muy amables',
            '',
        ]);
    }

    // ── Lo que SÍ reabre ──────────────────────────────────────────────────────

    #[DataProvider('frasesQueReabren')]
    public function test_an_explicit_request_to_be_contacted_again_reopens(string $frase): void
    {
        $this->assertNotNull($this->autoridad->reconsentimientoEn($frase), $frase);
    }

    public static function frasesQueReabren(): array
    {
        return array_map(fn ($f) => [$f], [
            'si quiero que me escriban',
            'quiero que me contacten de nuevo',
            'ya me pueden escribir',
            'pueden escribirme cuando quieran',
            'me pueden volver a escribir',
            'vuelvan a escribirme por favor',
            'quiero recibir informacion otra vez',
            'acepto recibir mensajes',
            'autorizo que me contacten',
            'reactiven mi contacto',
            'me puedes llamar',
        ]);
    }

    /** La prueba se guarda citable: una reapertura sin cita es indistinguible de una inventada. */
    public function test_the_evidence_is_the_quoted_fragment(): void
    {
        $prueba = $this->autoridad->reconsentimientoEn('hola, ya me pueden escribir de nuevo gracias');

        $this->assertNotNull($prueba);
        $this->assertStringContainsString('pueden escribir', $prueba);
    }

    // ── La decisión completa ──────────────────────────────────────────────────

    public function test_an_unknown_source_is_refused(): void
    {
        $v = $this->autoridad->decideReopen('porque_si', 'un motivo', 'ya me pueden escribir');

        $this->assertFalse($v['allowed']);
        $this->assertSame('consent_source_not_allowed', $v['refusal']);
    }

    /** El motivo es obligatorio en las dos vías: es lo que se lee dentro de seis meses. */
    public function test_a_reopen_without_a_written_reason_is_refused(): void
    {
        foreach (ConsentAuthority::SOURCES as $via) {
            foreach ([null, '', '   '] as $vacio) {
                $v = $this->autoridad->decideReopen($via, $vacio, 'ya me pueden escribir');
                $this->assertFalse($v['allowed'], $via);
                $this->assertSame('consent_reason_missing', $v['refusal']);
            }
        }
    }

    /** La vía de la persona se corrobora contra lo que escribió. Siempre. */
    public function test_the_lead_path_needs_the_words_in_the_message(): void
    {
        $v = $this->autoridad->decideReopen(
            ConsentAuthority::SOURCE_LEAD_RECONSENT,
            'dice que si',
            'cuanto vale la mensualidad',
        );

        $this->assertFalse($v['allowed']);
        $this->assertSame('reconsent_not_corroborated', $v['refusal']);
    }

    public function test_the_lead_path_carries_the_evidence_when_it_is_corroborated(): void
    {
        $v = $this->autoridad->decideReopen(
            ConsentAuthority::SOURCE_LEAD_RECONSENT,
            'lo pidió por WhatsApp',
            'ya me pueden escribir',
        );

        $this->assertTrue($v['allowed']);
        $this->assertNotNull($v['evidence']);
    }

    /** La vía administrativa no se corrobora leyendo: la prueba es que una persona firma. */
    public function test_the_admin_path_does_not_need_any_text(): void
    {
        $v = $this->autoridad->decideReopen(ConsentAuthority::SOURCE_ADMIN, 'vino al gimnasio y lo pidió');

        $this->assertTrue($v['allowed']);
        $this->assertNull($v['evidence']);
    }
}
