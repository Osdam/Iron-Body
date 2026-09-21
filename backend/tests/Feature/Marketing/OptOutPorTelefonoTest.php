<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingLead;
use App\Services\Marketing\MarketingMessageDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EL OPT-OUT ES DE LA PERSONA, NO DE LA FILA.
 *
 * La fila de lead que resuelve un mensaje entrante se elige por (canal,
 * meta_user_id): el teléfono no entra en esa decisión. Así que el mismo número
 * puede tener varias filas, y el «no me escriban» quedarse en una mientras el
 * mensaje sale por otra —según cuál resolviera Laravel ese día—.
 *
 * En esta base ya conviven dos filas del mismo número guardado de dos formas,
 * una con indicativo y otra sin él, así que ni una comparación de texto las
 * uniría. El mensaje llega a la PERSONA; el permiso tiene que ser suyo.
 */
class OptOutPorTelefonoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config()->set('meta.enabled', false);
    }

    private function lead(array $attrs): MarketingLead
    {
        return MarketingLead::create(array_merge([
            'channel' => 'whatsapp', 'source' => 'inbound', 'name' => 'Prospecto',
            'status' => MarketingLead::STATUS_HOT,
        ], $attrs));
    }

    private function enviar(MarketingLead $lead): array
    {
        return app(MarketingMessageDispatcher::class)
            ->dispatchWhatsapp($lead, 'whatsapp', 'Hola, ¿retomamos?', ['kind' => 'test']);
    }

    /**
     * La hermana con el mismo número y el contacto cerrado manda.
     *
     * Los formatos son los que hay de verdad en esa columna: la llenan el
     * panel y las importaciones a mano —`MetaLeadService` ni siquiera la
     * escribe—, así que los `+57`, los guiones y los espacios son la norma.
     *
     * Y son casos de primera clase porque la primera versión de esta guardia
     * los dejaba pasar: filtraba con un `LIKE` sobre el texto guardado, y una
     * hermana con separadores no llegaba nunca a la comparación. Fallaba
     * abierta, que en un opt-out es la peor forma de fallar.
     *
     * @param  string  $guardado  el teléfono tal y como está en la fila hermana
     */
    #[DataProvider('formatosDelMismoNumero')]
    public function test_a_sibling_row_that_opted_out_silences_the_number(string $guardado): void
    {
        $this->lead(['phone' => $guardado, 'meta_user_id' => 'viejo', 'do_not_contact' => true]);
        $activo = $this->lead(['phone' => '573150536026', 'meta_user_id' => 'nuevo', 'do_not_contact' => false]);

        $r = $this->enviar($activo);

        $this->assertFalse($r['sent']);
        $this->assertSame('do_not_contact_sibling', $r['reason']);
        Http::assertNothingSent();
    }

    /** @return array<string,array{0:string}> */
    public static function formatosDelMismoNumero(): array
    {
        return [
            'diez dígitos' => ['3150536026'],
            'con indicativo' => ['573150536026'],
            'internacional con espacios' => ['+57 315 053 6026'],
            'con guiones' => ['315-053-6026'],
            'con paréntesis' => ['(57) 3150536026'],
            'con espacios sueltos' => [' 57 3150536026 '],
        ];
    }

    /** Y sin hermanas cerradas, el mensaje sigue su camino de siempre. */
    public function test_without_opted_out_siblings_the_message_goes_on(): void
    {
        $this->lead(['phone' => '3150536026', 'meta_user_id' => 'viejo', 'do_not_contact' => false]);
        $activo = $this->lead(['phone' => '573150536026', 'meta_user_id' => 'nuevo', 'do_not_contact' => false]);

        $r = $this->enviar($activo);

        $this->assertNotSame('do_not_contact_sibling', $r['reason']);
        $this->assertNotSame('do_not_contact', $r['reason']);
    }

    /**
     * Un número DISTINTO no se contagia.
     *
     * Sin esto, la guardia nueva sería una mordaza: un prefiltro demasiado
     * ancho callaría a cualquiera que compartiera unos dígitos finales.
     */
    public function test_a_different_number_is_not_silenced(): void
    {
        $this->lead(['phone' => '573001112233', 'meta_user_id' => 'otro', 'do_not_contact' => true]);
        $activo = $this->lead(['phone' => '573150536026', 'meta_user_id' => 'nuevo', 'do_not_contact' => false]);

        $r = $this->enviar($activo);

        $this->assertNotSame('do_not_contact_sibling', $r['reason']);
    }

    /** Y la guardia de siempre, sobre la propia fila, sigue en pie. */
    public function test_the_row_s_own_opt_out_still_wins_first(): void
    {
        $propio = $this->lead(['phone' => '573150536026', 'meta_user_id' => 'nuevo', 'do_not_contact' => true]);

        $r = $this->enviar($propio);

        $this->assertSame('do_not_contact', $r['reason'], 'el motivo de siempre no puede cambiar');
    }
}
