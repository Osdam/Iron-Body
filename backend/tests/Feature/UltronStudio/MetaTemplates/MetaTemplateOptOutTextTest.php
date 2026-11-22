<?php

namespace Tests\Feature\UltronStudio\MetaTemplates;

use App\Models\UltronStudio\StudioMessageTemplate;
use App\Services\Outreach\Facts\WhatsappSignals;
use App\Services\UltronStudio\StudioMessageTemplates as T;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\UltronStudio\Strategies\StrategyFixtures;
use Tests\Feature\UltronStudio\StudioFixtures;
use Tests\TestCase;

/**
 * LA LÍNEA DE BAJA SE COMPRUEBA EN EL TEXTO (hallazgo «MALA 2c» de
 * `plans/informes-cierre/plantillas-propuestas.md` §3, 22-11-2026; D9-6 y
 * D9-15: «el texto dice cómo dejar de recibir mensajes»). Con
 * `optOutLine: true`, el cuerpo o el pie tienen que pedir una respuesta que el
 * normalizador de W9 registre como baja SEGURA (`WhatsappSignals::isOptOutText()`).
 * Si no, `content.bodyPreview: opt_out_text_missing`. Sobre la clase real
 * (`StudioMessageTemplates::content()`, lo que llama `create()`) y una vez por
 * la API.
 */
class MetaTemplateOptOutTextTest extends TestCase
{
    use RefreshDatabase;
    use StrategyFixtures;
    use StudioFixtures;

    /** La última línea de las cinco plantillas propuestas (§1). */
    private const LINEA = 'Si no desea recibir más mensajes, responda BAJA.';

    /** Las cinco plantillas propuestas, tal cual (plantillas-propuestas.md §1). */
    private const PROPUESTAS = [
        'A seguimiento_prospecto_v1' => ['Hola, {{1}}. Le escribimos de {{2}} para retomar la conversación que tuvimos por WhatsApp. ¿Le sigue interesando entrenar con nosotros? Si tiene alguna pregunta, responda este mensaje y con gusto le ayudamos.', ['first_name', 'gym_name']],
        'B membresia_por_vencer_v1' => ['Hola, {{1}}. Le recordamos que su membresía en {{2}} vence el {{3}}. Si desea renovarla o tiene alguna pregunta, responda este mensaje y con gusto le ayudamos.', ['first_name', 'gym_name', 'membership_end_date_text']],
        'C membresia_vencida_v1' => ['Hola, {{1}}. Su membresía en {{2}} terminó el {{3}}. Si desea retomar sus entrenamientos, responda este mensaje y le indicamos cómo renovarla.', ['first_name', 'gym_name', 'membership_end_date_text']],
        'D socio_inactivo_v1' => ['Hola, {{1}}. En {{2}} hace un tiempo no le vemos por el gimnasio y queremos saber cómo está. Si desea retomar su rutina o necesita ayuda para organizarse, responda este mensaje y con gusto le ayudamos.', ['first_name', 'gym_name']],
        'E valoracion_pendiente_v1' => ['Hola, {{1}}. En {{2}} vemos que ya pasó un tiempo desde su última valoración física. Si desea agendar una nueva para revisar su progreso, responda este mensaje y le ayudamos a programarla.', ['first_name', 'gym_name']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['ultron_studio.meta_templates.url_hosts' => [], 'ultron_studio.meta_templates.phone_button' => false]);
    }

    /** @param  list<string>  $variables @return array<string, mixed> */
    private static function plantilla(string $cuerpo, array $variables = ['first_name'], string $categoria = 'marketing', array $extra = []): array
    {
        return ['metaName' => 'prueba_baja', 'language' => 'es_CO', 'category' => $categoria, 'bodyPreview' => $cuerpo, 'variables' => $variables, 'optOutLine' => true] + $extra;
    }

    /** @return list<array{key:string,code:string}> */
    private static function errores(array $contenido, string $finalidad = 'commercial'): array
    {
        return T::content('whatsapp', $finalidad, $contenido)['errors'];
    }

    private const SIN_BAJA = [['key' => 'content.bodyPreview', 'code' => 'opt_out_text_missing']];

    public function test_las_cinco_plantillas_propuestas_siguen_siendo_validas_y_sin_su_ultima_linea_se_rechazan(): void
    {
        foreach (self::PROPUESTAS as $nombre => [$cuerpo, $variables]) {
            $this->assertSame([], self::errores(self::plantilla($cuerpo."\n\n".self::LINEA, $variables)), "{$nombre}: con su línea de baja");
            // MALA 2c: la misma, sin la línea y con la casilla marcada. Antes pasaba.
            $this->assertSame(self::SIN_BAJA, self::errores(self::plantilla($cuerpo, $variables)), "{$nombre}: sin su línea de baja (MALA 2c)");
        }
    }

    public function test_la_instruccion_vale_en_el_cuerpo_o_en_el_pie(): void
    {
        $cuerpo = 'Hola {{1}}, tu plan vence pronto. Si quieres seguir entrenando con nosotros, respóndenos y te ayudamos.';
        $this->assertSame(self::SIN_BAJA, self::errores(self::plantilla($cuerpo)));
        $this->assertSame([], self::errores(self::plantilla($cuerpo, extra: ['footer' => ['text' => 'Escribe BAJA para no recibir más mensajes']])));
        // Un pie inválido tiene su error y no cuenta como instrucción.
        $malo = self::errores(self::plantilla($cuerpo, extra: ['footer' => ['text' => 'Escribe *BAJA* {{1}}']]));
        $this->assertContains(self::SIN_BAJA[0], $malo);
        $this->assertNotSame([], array_filter($malo, static fn (array $e): bool => str_starts_with($e['key'], 'content.footer')));
        // El botón de baja sola no basta: la instrucción va en el cuerpo o en el pie.
        $boton = ['buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Darme de baja', 'role' => 'opt_out']]];
        $this->assertSame(self::SIN_BAJA, self::errores(self::plantilla($cuerpo, extra: $boton)));
    }

    public function test_una_palabra_que_el_motor_no_registra_como_baja_no_vale(): void
    {
        foreach ([
            'Hola {{1}}. Si no desea recibir más mensajes, responda SALIR.',
            'Hola {{1}}. Si no desea recibir más mensajes, responda CANCELAR.',
            'Hola {{1}}. Si no desea recibir más mensajes, responda NO.',
            'Hola {{1}}. Para no recibir más mensajes, escriba ALTO.',
            'Hola {{1}}. Si no desea recibir más mensajes, responda BAJAS.',
            'Hola {{1}}. Para darse de baja, contáctenos.',          // sin verbo de respuesta delante
            'Hola {{1}}. BAJA si no desea recibir más mensajes.',    // la palabra sin la instrucción
            'Hola {{1}}. Si no desea recibir más mensajes, llame a recepción.',
        ] as $cuerpo) {
            $this->assertSame(self::SIN_BAJA, self::errores(self::plantilla($cuerpo)), $cuerpo);
        }
    }

    /**
     * NB6 (`informe-apisec-quejas.md`, sonda F): una negación (no, nunca, jamás) en las dos palabras anteriores al
     * verbo anula la instrucción. Antes pasaban «No responda BAJA» y «Nunca escriba STOP». Más lejos no cuenta, y otra
     * instrucción válida en el mismo texto sí vale; lo demás lo ve la revisión a cuatro ojos.
     */
    public function test_nb6_una_negacion_delante_del_verbo_no_es_una_instruccion_de_baja(): void
    {
        foreach ([
            'Hola {{1}}. No responda BAJA.',
            'Hola {{1}}. Nunca escriba STOP.',
            'Hola {{1}}. Jamás conteste BAJA a este número.',
            'Hola {{1}}. NO RESPONDA «BAJA».',
            'Hola {{1}}. Por favor no nos escriba BAJA.',
            'Hola {{1}}. Por favor, no, responda con la palabra BAJA.',
            "Hola {{1}}. Nunca\nenvíe STOP.",
        ] as $cuerpo) {
            $this->assertSame(self::SIN_BAJA, self::errores(self::plantilla($cuerpo)), $cuerpo);
            if (! str_contains($cuerpo, "\n")) {   // el pie es una sola línea
                $pie = substr($cuerpo, strlen('Hola {{1}}. '));
                $this->assertSame(self::SIN_BAJA, self::errores(self::plantilla('Hola {{1}}, tu plan vence pronto.', extra: ['footer' => ['text' => $pie]])), "en el pie: {$pie}");
            }
        }
        foreach ([
            'Hola {{1}}. Si no desea recibir más mensajes, responda BAJA.',
            'Hola {{1}}. Si no quiere más mensajes responda BAJA.',
            'Hola {{1}}. No olvide: si desea salir, responda BAJA.',
            'Hola {{1}}. No responda STOP a este número. Para salir, responda BAJA.',
            'Hola {{1}}. Si nunca más quiere mensajes, escriba BAJA.',
        ] as $cuerpo) {
            $this->assertSame([], self::errores(self::plantilla($cuerpo)), $cuerpo);
        }
        $this->assertSame(['no', 'nunca', 'jamas'], T::OPT_OUT_NEGATIONS);
        foreach (T::OPT_OUT_NEGATIONS as $negacion) {
            $this->assertSame($negacion, WhatsappSignals::normalize($negacion), 'en la forma normalizada con la que se compara');
        }
    }

    public function test_mayusculas_tildes_comillas_rellenos_y_saltos_de_linea(): void
    {
        foreach ([
            "Hola {{1}}. Si no desea recibir más mensajes,\nresponda\nBAJA.",
            'Hola {{1}}. RESPÓNDANOS «BAJA» si no desea recibir más mensajes.',
            'Hola {{1}}. Respóndenos con la palabra Baja y no te escribimos más.',
            'Hola {{1}}. Si no desea recibir más mensajes, conteste "baja", gracias.',
            'Hola {{1}}. Envíe STOP para no recibir más mensajes.',
            'Hola {{1}}. Escríbenos «No más mensajes» y no te escribimos más.',
            'Hola {{1}}. Si prefieres no recibir más, responde: Darme de baja.',
        ] as $cuerpo) {
            $this->assertSame([], self::errores(self::plantilla($cuerpo)), $cuerpo);
        }
    }

    public function test_cada_frase_de_baja_segura_del_motor_vale_tras_cada_verbo(): void
    {
        // Atado a la lista del motor: si W9 la registra como baja segura, la plantilla que la pide vale.
        foreach (WhatsappSignals::OPT_OUT_PHRASES as $frase) {
            foreach (T::OPT_OUT_VERBS as $verbo) {
                $this->assertTrue(T::hasOptOutInstruction("Hola {{1}}. Si no deseas recibir más mensajes, {$verbo} {$frase}."), "{$verbo} {$frase}");
            }
            $this->assertTrue(WhatsappSignals::isOptOutText($frase), $frase);
        }
        $this->assertFalse(T::hasOptOutInstruction(null, ''));
        $this->assertFalse(T::hasOptOutInstruction("\xC3\x28 responda BAJA"), 'UTF-8 inválido: no se puede leer, no cuenta');
    }

    public function test_la_casilla_sigue_siendo_obligatoria_y_su_error_no_cambia(): void
    {
        $sin = self::plantilla('Hola {{1}}, tu plan vence pronto.');
        $this->assertSame([['key' => 'content.optOutLine', 'code' => 'opt_out_required']], self::errores(['optOutLine' => false] + $sin));
        unset($sin['optOutLine']);
        $this->assertSame([['key' => 'content.optOutLine', 'code' => 'missing_key']], self::errores($sin));
    }

    public function test_cobranza_y_servicio_tambien_lo_exigen_como_dice_el_contrato(): void
    {
        // D9-6 (la fila `whatsapp` de §5): «optOutLine: true obligatorio (el texto dice cómo dejar de recibir
        // mensajes)» para toda plantilla de WhatsApp, y D9-15: «la línea de baja en cada plantilla».
        foreach (['collections', 'service'] as $finalidad) {
            $this->assertSame(self::SIN_BAJA, self::errores(self::plantilla('Hola {{1}}, tu clase de mañana sigue en pie.', categoria: 'utility'), $finalidad), $finalidad);
            $this->assertSame([], self::errores(self::plantilla('Hola {{1}}, tu clase de mañana sigue en pie. Responde BAJA para no recibir más mensajes.', categoria: 'utility'), $finalidad), $finalidad);
        }
    }

    public function test_por_la_api_es_422_con_su_codigo_y_no_se_guarda_nada(): void
    {
        $this->grantStrategyPermissions();
        $this->strategyFlags(enabled: true);
        [$ana] = $this->bootstrapPair();
        [$cuerpo, $variables] = self::PROPUESTAS['A seguimiento_prospecto_v1'];
        $r = $this->s9('POST', 'strategies/templates', $ana, ['clientRequestId' => (string) Str::uuid(), 'key' => 'seguimiento-prospecto', 'channel' => 'whatsapp', 'purpose' => 'commercial',
            'content' => self::plantilla($cuerpo, $variables)])->assertStatus(422)->assertJsonPath('code', 'template_invalid');
        $this->assertContains(['key' => 'content.bodyPreview', 'code' => 'opt_out_text_missing'], array_map(static fn (array $f): array => ['key' => $f['key'], 'code' => $f['code']], $r->json('findings')));
        $this->assertSame(0, StudioMessageTemplate::query()->count());

        $this->s9('POST', 'strategies/templates', $ana, ['clientRequestId' => (string) Str::uuid(), 'key' => 'seguimiento-prospecto', 'channel' => 'whatsapp', 'purpose' => 'commercial',
            'content' => self::plantilla($cuerpo."\n\n".self::LINEA, $variables)])->assertCreated();
    }
}
