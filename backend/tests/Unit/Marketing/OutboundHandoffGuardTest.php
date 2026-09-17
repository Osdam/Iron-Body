<?php

namespace Tests\Unit\Marketing;

use App\Models\MarketingMessage;
use App\Services\Marketing\OutboundContentGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La segunda barrera contra el traspaso no autorizado.
 *
 * Lo difícil aquí no es detectar «te conecto con alguien»: es NO bloquear
 * «tenemos cuatro entrenadores». Un guard que confunda hablar DE personas con
 * pasar la conversación A una persona deja mudo al asesor justo cuando está
 * dando la información que evita el traspaso.
 */
class OutboundHandoffGuardTest extends TestCase
{
    private OutboundContentGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new OutboundContentGuard;
    }

    private function inspect(string $texto, bool $permitido = false): array
    {
        return $this->guard->inspect($texto, MarketingMessage::SENDER_AI, $permitido);
    }

    // ── Lo que hay que parar ─────────────────────────────────────────────────

    public static function ofertasDeTraspaso(): array
    {
        return [
            // Las frases EXACTAS de la conversación real que falló.
            ['Listo, Alejandro. Te conecto con alguien del equipo para que te explique cómo empezar.'],
            ['Tranquilo, esa info la tiene el equipo humano. Te conecto con alguien para que te cuente bien todo.'],
            ['¿Quieres que te pase con alguien del equipo que te pueda contar?'],
            ['¿Quieres que te conecte con alguien del equipo para detalles y formas de pago?'],
            ['Te comunico con un asesor ahora mismo.'],
            ['Ya pasé tu caso al equipo para que lo revisen.'],
            // Las dos que las fixtures de arranque daban por buenas.
            ['Tienes razón y lo siento. Ya le paso tu caso a alguien del equipo.'],
            ['Claro, ya le aviso a alguien del equipo para que te escriba.'],
            ['En un momento te atenderán para todo lo que necesites.'],
            ['Alguien del equipo te escribirá enseguida.'],
            ['Un asesor te contactará para ayudarte.'],
        ];
    }

    #[DataProvider('ofertasDeTraspaso')]
    public function test_an_unauthorised_transfer_offer_is_stopped(string $texto): void
    {
        $r = $this->inspect($texto);

        $this->assertFalse($r['safe'], "Debería bloquearse: «{$texto}»");
        $this->assertSame(OutboundContentGuard::CODE_UNAUTHORIZED_HANDOFF, $r['code']);
    }

    /** Con permiso del backend, la misma frase sí sale. */
    #[DataProvider('ofertasDeTraspaso')]
    public function test_the_same_sentence_passes_when_authorised(string $texto): void
    {
        $this->assertTrue($this->inspect($texto, permitido: true)['safe']);
    }

    // ── Lo que NO hay que parar ──────────────────────────────────────────────

    public static function informacionLegitima(): array
    {
        return [
            ['Tenemos cuatro entrenadores de planta, todos certificados.'],
            ['El Plan Mensual incluye asesoría semi personalizada con entrenador de planta.'],
            ['Nuestro equipo de entrenadores te acompaña en la ejecución de los ejercicios.'],
            ['Hay seguimiento de un entrenador durante todo el proceso.'],
            ['Somos un equipo pequeño y nos conocemos a todos los que entrenan aquí.'],
            ['La valoración inicial la hace una persona del equipo técnico cuando llegas.'],
            ['Soy un asistente automático del equipo de Iron Body.'],
            ['Claro, el Plan Mensual tiene acceso ilimitado al gimnasio y zona de pesas.'],
            ['¿Cuál es tu objetivo principal para ayudarte mejor?'],
            // Mismos verbos, ningún traspaso.
            ['Te aviso que el equipo confirma el medio de pago al final.'],
            ['Paso a contarte los planes que tenemos.'],
            ['Le digo a mi compañera que revise tu factura y te cuento.'],
            ['Gracias por contármelo. Prefiero que lo vea alguien del equipo antes de recomendarte nada.'],
        ];
    }

    /**
     * @see informacionLegitima
     *
     * Todas mencionan personas. Ninguna ofrece pasarle la conversación a una.
     */
    #[DataProvider('informacionLegitima')]
    public function test_talking_about_people_is_not_transferring_to_one(string $texto): void
    {
        $r = $this->inspect($texto);

        $this->assertTrue(
            $r['safe'],
            "El guard bloqueó información legítima: «{$texto}» (señal: ".var_export($r['signal'], true).')',
        );
    }

    /** Y un humano escribiendo no pasa por este filtro: sólo se mira a la máquina. */
    public function test_a_human_can_still_offer_to_transfer(): void
    {
        $r = $this->guard->inspect('Te paso con mi compañera que lleva pagos.', MarketingMessage::SENDER_HUMAN);

        $this->assertTrue($r['safe']);
    }
}
