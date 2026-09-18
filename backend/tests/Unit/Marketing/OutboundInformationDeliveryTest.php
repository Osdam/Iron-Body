<?php

namespace Tests\Unit\Marketing;

use App\Models\MarketingMessage;
use App\Services\Marketing\OutboundContentGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * «Te paso» tiene dos sentidos y el cerrojo solo debe cerrar uno: pasar a la
 * PERSONA con alguien (traspaso, prohibido sin autorización) frente a pasarle
 * INFORMACIÓN (un link, un dato, la dirección). Antes de esto el guard
 * bloqueaba «te paso el link»; con Wompi y los links de la app eso sería
 * silenciar justo la respuesta correcta.
 */
class OutboundInformationDeliveryTest extends TestCase
{
    private OutboundContentGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = app(OutboundContentGuard::class);
    }

    /** @return array<string, array{0:string}> */
    public static function informacion(): array
    {
        return [
            'link de pago' => ['Listo, te paso el link de pago para que lo hagas desde el celular.'],
            'enlace de la app' => ['Te paso el enlace de la app para que la descargues.'],
            'dato' => ['Te paso el dato: la sede queda en la Cl. 24 Sur.'],
            'direccion' => ['Claro, te paso la dirección y cómo llegar.'],
            'horarios de clase' => ['Te paso los horarios de las clases de la mañana.'],
            'info del plan' => ['Te paso la información del plan trimestral.'],
            'comunico que' => ['Te comunico que el plan mensual incluye clases grupales.'],
            'lista' => ['Ya te paso la lista de lo que incluye.'],
            'comunico horarios' => ['Te comunico los horarios de la mañana.'],
            'comunico precio' => ['Te comunico el precio apenas lo confirme.'],
            'numero de recepcion' => ['Te paso el número de recepción por si prefieres llamar.'],
            // Algo entre el verbo y el objeto (revisor): adverbios, cuantificadores, puntuación.
            'ya el link' => ['Te paso ya el link de pago.'],
            'enseguida la direccion' => ['Te paso enseguida la dirección.'],
            'rapido los horarios' => ['Te paso rápido los horarios.'],
            'aqui el enlace' => ['Te paso aquí el enlace de la app.'],
            'por aca la lista' => ['Te paso por acá la lista de beneficios.'],
            'tambien el resumen' => ['Te paso también el resumen del plan.'],
            'un par de opciones' => ['Te paso un par de opciones.'],
            'los dos links' => ['Te paso los dos links.'],
            'mas informacion' => ['Te paso más información ahora.'],
            'toda la informacion' => ['Te paso toda la información del plan.'],
            'dos puntos' => ['Te paso: el link de pago.'],
            'comunico coma' => ['Te comunico, el horario es de 5am a 10pm.'],
            'whatsapp de la sede' => ['Te paso el WhatsApp de la sede.'],
            'acompana un entrenador' => ['Te acompaña un entrenador de planta en tu primera sesión.'],
            // Locuciones de relleno y anuncios de información futura (revisor, ciclo 3).
            'de una vez' => ['Te paso de una vez el link de pago.'],
            'ahora mismo' => ['Te paso ahora mismo el link de pago.'],
            'apenas confirme' => ['Te comunico apenas confirme el pago.'],
            'en cuanto' => ['Te comunico en cuanto quede activa la membresía.'],
        ];
    }

    /** @return array<string, array{0:string}> */
    public static function traspaso(): array
    {
        return [
            'con alguien' => ['Te paso con alguien del equipo.'],
            'con la coordinadora' => ['Te paso con la coordinadora para que te explique.'],
            'a recepcion' => ['Te paso a recepción.'],
            'con un asesor' => ['Ahora te paso con un asesor.'],
            'comunico con' => ['Te comunico con un asesor.'],
            'conecto' => ['Te conecto con el equipo.'],
            'derivo' => ['Te derivo.'],
            'transfiero' => ['Te transfiero con alguien.'],
            // Los siete que se colaron en la primera versión (revisor): nombre propio, «al», «su número».
            'a nombre propio' => ['Te paso a Carlos para que te explique.'],
            'a nombre propio 2' => ['Enseguida te paso a Valentina.'],
            'al entrenador' => ['Te paso al entrenador para que te cuente.'],
            'al area' => ['Te paso al área comercial para que te ayuden.'],
            'al equipo' => ['Te paso al equipo de ventas.'],
            'a la encargada' => ['Te paso a la persona encargada.'],
            'su numero' => ['Te paso su número para que te atienda.'],
            'comunico a secas' => ['Ya te comunico.'],
            // Señuelos con palabra de la lista blanca (revisor): el objeto es una persona.
            'datos de la asesora' => ['Te paso los datos de la asesora que te va a atender.'],
            'datos del asesor' => ['Te paso los datos del asesor comercial.'],
            'info de contacto coordinadora' => ['Te paso la información de contacto de la coordinadora.'],
            'dato de carlos entrenador' => ['Te paso el dato de Carlos, el entrenador, para que lo llames.'],
            'info de la coordinadora' => ['Te paso la info de la coordinadora.'],
            'dato del asesor que atiende' => ['Te paso el dato del asesor que te atiende.'],
            'numero para que te atiendan' => ['Te paso el número para que te atiendan.'],
            // «Que…» no es salvoconducto y el futuro perifrástico también es traspaso.
            'comunico que contacta' => ['Te comunico que en un momento te contacta una asesora.'],
            'comunico que va a llamar' => ['Te comunico que una persona del equipo te va a llamar.'],
            'perifrastico suelto' => ['Una asesora te va a llamar en un momento.'],
            // El contacto de una PERSONA es traspaso aunque la palabra sea «número» (revisor, ciclo 3).
            'numero de carlos' => ['Te paso el número de Carlos.'],
            'celular de valentina' => ['Te paso el celular de Valentina, ella te ayuda.'],
            'contacto de ana' => ['Te paso el contacto de Ana.'],
            'numero nutricionista' => ['Te paso el número de la nutricionista.'],
            'contacto instructor' => ['Te paso el contacto del instructor de spinning.'],
            'whatsapp del profe' => ['Te paso el WhatsApp del profe.'],
            'numero de la duena' => ['Te paso el número de la dueña del gimnasio.'],
            'numero recepcionista' => ['Te paso el número de la recepcionista.'],
            'comunico que carlos escribe' => ['Te comunico que Carlos te escribe en un momento.'],
            'comunico que coordinadora escribe' => ['Te comunico que la coordinadora te escribe hoy.'],
            'le paso tu numero' => ['Le paso tu número a la coordinadora.'],
            'recepcion para que te atiendan' => ['Te paso el número de recepción para que te atiendan.'],
        ];
    }

    #[DataProvider('informacion')]
    public function test_handing_over_information_is_not_handing_over_the_person(string $texto): void
    {
        $r = $this->guard->inspect($texto, MarketingMessage::SENDER_AI, false);

        $this->assertTrue($r['safe'], $texto.' → '.json_encode($r));
    }

    #[DataProvider('traspaso')]
    public function test_handing_over_the_person_stays_locked(string $texto): void
    {
        $r = $this->guard->inspect($texto, MarketingMessage::SENDER_AI, false);

        $this->assertFalse($r['safe'], $texto);
        $this->assertSame(OutboundContentGuard::CODE_UNAUTHORIZED_HANDOFF, $r['code'], $texto);
    }
}
