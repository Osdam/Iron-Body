<?php

namespace Tests\Feature\UltronStudio\Strategies;

use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioStrategy;
use App\Models\UltronStudio\StudioStrategyVersion;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioMessageTemplates;
use App\Services\UltronStudio\StudioStrategies;
use App\Services\UltronStudio\StudioStrategyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\UltronStudio\StudioFixtures;
use Tests\TestCase;

/**
 * LA VALIDACIÓN (contrato H9, D9-5, D9-6, D9-11.4, D9-12; T11 «nada sin
 * tipar», T18 «precedencia N3», P9-10) y las PLANTILLAS (D9-6): 422 con TODAS
 * las claves a la vez; nunca SQL, regex ni código; nada que no haya pasado la
 * validación se congela ni se evalúa; la referencia a la versión comercial
 * (línea base o versión aprobada) y lo que declara; las plantillas, cerradas,
 * versionadas y a cuatro ojos por persona. Cero red.
 */
class UltronStudioStrategyValidationTest extends TestCase
{
    use RefreshDatabase;
    use StrategyFixtures;
    use StudioFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->withoutRouteMap();
        $this->grantStrategyPermissions();
        $this->strategyFlags(enabled: true);
    }

    // ── T11: nada sin tipar ─────────────────────────────────────────────────

    public function test_t11_un_contenido_con_hecho_desconocido_operador_ajeno_texto_libre_sql_y_regex_da_422_con_todas_las_claves_a_la_vez(): void
    {
        [$ana, $beto] = $this->bootstrapPair();
        $t = $this->approvedTemplate($ana, $beto);
        $malo = $this->strategyContent($t, [
            'audience' => ['all' => [
                ['fact' => 'membership.secret_column', 'op' => 'eq', 'value' => true],                    // hecho fuera del catálogo
                ['fact' => 'membership.active', 'op' => 'gt', 'value' => 3],                               // operador que no es del tipo
                ['fact' => 'lead.temperature', 'op' => 'eq', 'value' => "hot' OR 1=1 --"],                 // texto libre / SQL
                ['fact' => 'lead.temperature', 'op' => 'in', 'value' => ['^.*$']],                          // «regex»
            ]],
            'priority' => 500,                                                                              // fuera de rango
        ]);
        $s = $this->s9('POST', 'strategies', $ana, ['clientRequestId' => (string) Str::uuid(), 'key' => 'mala-1', 'name' => 'Mala', 'purpose' => 'commercial', 'draft' => $malo])->assertCreated()->json('data');

        // validate: 200 con los errores (pura, no escribe).
        $antes = StudioAuditEvent::query()->count();
        $v = $this->s9('POST', "strategies/{$s['uuid']}/validate", $ana, [])->assertOk()->json('data');
        $this->assertFalse($v['valid']);
        $claves = array_column($v['errors'], 'key');
        foreach (['content.audience.all.0.fact', 'content.audience.all.1.op', 'content.audience.all.2.value', 'content.audience.all.3.value.0', 'content.priority'] as $k) {
            $this->assertContains($k, $claves, "falta {$k}: ".json_encode($v['errors']));
        }
        $this->assertSame($antes, StudioAuditEvent::query()->count(), 'validar no escribe');

        // congelar: 422 con todas las claves a la vez, y nada queda congelado.
        $r = $this->s9('POST', "strategies/{$s['uuid']}/versions", $ana, ['clientRequestId' => (string) Str::uuid()])->assertStatus(422);
        $this->assertSame('strategy_invalid', $r->json('code'));
        foreach (['content.audience.all.0.fact', 'content.audience.all.1.op', 'content.audience.all.2.value', 'content.priority'] as $k) {
            $this->assertArrayHasKey($k, $r->json('errors'));
        }
        $this->assertSame(0, StudioStrategyVersion::query()->count());
        // Lo que no validó no se evalúa: la simulación del borrador también es 422.
        $this->s9('POST', "strategies/{$s['uuid']}/simulations", $ana, ['kind' => 'synthetic', 'people' => $this->syntheticPeople()])->assertStatus(422)->assertJsonPath('code', 'strategy_invalid');
    }

    public function test_t11_profundidad_y_tamano_excesivos_son_422(): void
    {
        [$ana, $beto] = $this->bootstrapPair();
        $t = $this->approvedTemplate($ana, $beto);
        $hoja = ['fact' => 'membership.active', 'op' => 'eq', 'value' => true];
        $hondo = $hoja;
        for ($i = 0; $i < 6; $i++) {
            $hondo = ['not' => $hondo];
        }
        $ancho = ['all' => array_fill(0, 60, $hoja)];
        foreach (['hondo' => $hondo, 'ancho' => $ancho] as $nombre => $arbol) {
            $r = StudioStrategyValidator::validate($this->strategyContent($t, ['audience' => $arbol]), StudioStrategyValidator::context(), 'commercial');
            $this->assertFalse($r['valid'], $nombre);
            $this->assertNotSame([], array_filter($r['errors'], static fn (array $e): bool => in_array($e['code'], ['depth_exceeded', 'too_many_nodes'], true)), $nombre.': '.json_encode($r['errors']));
        }
    }

    public function test_la_finalidad_de_la_estrategia_no_cambia_desde_el_contenido(): void
    {
        [$ana, $beto] = $this->bootstrapPair();
        $t = $this->approvedTemplate($ana, $beto);
        $r = StudioStrategyValidator::validate($this->strategyContent($t, ['purpose' => 'service', 'goal' => 'book_assessment']), StudioStrategyValidator::context(), 'commercial');
        $this->assertContains(['key' => 'content.purpose', 'code' => 'purpose_immutable'], array_map(static fn (array $e): array => ['key' => $e['key'], 'code' => $e['code']], $r['errors']));
    }

    // ── Plantillas en la validación ─────────────────────────────────────────

    public function test_una_plantilla_sin_aprobar_de_otra_finalidad_o_con_otra_huella_bloquea(): void
    {
        [$ana, $beto] = $this->bootstrapPair();
        $borrador = $this->draftTemplate($ana, key: 'guion-a');
        $r = StudioStrategyValidator::validate($this->strategyContent($borrador), StudioStrategyValidator::context(), 'commercial');
        $this->assertTrue($r['errors'] === []);
        $this->assertContains('template_not_approved', array_column($r['blocking'], 'code'));

        $aprobada = $this->approvedTemplate($ana, $beto, key: 'guion-b');
        $contenido = $this->strategyContent($aprobada);
        $contenido['sequence'][0]['templates']['assisted:whatsapp']['hash'] = str_repeat('a', 64);
        $r = StudioStrategyValidator::validate($contenido, StudioStrategyValidator::context(), 'commercial');
        $this->assertContains('template_hash_mismatch', array_column($r['blocking'], 'code'));

        $servicio = $this->approvedTemplate($ana, $beto, purpose: 'service', key: 'guion-c');
        $r = StudioStrategyValidator::validate($this->strategyContent($servicio), StudioStrategyValidator::context(), 'commercial');
        $this->assertContains('template_purpose_mismatch', array_column($r['blocking'], 'code'));
    }

    // ── T18: la versión comercial aprobada (N3) ─────────────────────────────

    public function test_t18_una_referencia_del_studio_que_no_cuadra_es_studio_reference_invalid(): void
    {
        [$ana, $beto] = $this->bootstrapPair();
        $t = $this->approvedTemplate($ana, $beto);
        $r = StudioStrategyValidator::validate($this->strategyContent($t, ['studio' => ['kind' => 'baseline', 'catalogVersion' => '2026-10-19.1', 'catalogHash' => str_repeat('b', 64)]]),
            StudioStrategyValidator::context(), 'commercial');
        $this->assertSame(['studio_reference_invalid'], array_values(array_unique(array_column($r['blocking'], 'code'))));

        $r = StudioStrategyValidator::validate($this->strategyContent($t, ['studio' => ['kind' => 'version', 'uuid' => (string) Str::uuid(), 'contentHash' => str_repeat('c', 64)]]),
            StudioStrategyValidator::context(), 'commercial');
        $this->assertContains('studio_reference_invalid', array_column($r['blocking'], 'code'));
    }

    public function test_t18_un_paso_con_plantilla_de_enlace_de_pago_y_los_enlaces_apagados_se_niega(): void
    {
        [$ana, $beto] = $this->bootstrapPair();
        $pago = $this->approvedTemplate($ana, $beto, channel: 'whatsapp', content: [
            'metaName' => 'pago_pendiente', 'language' => 'es_CO', 'category' => 'marketing',
            'bodyPreview' => 'Hola {{1}}, puedes completar tu pago aquí: {{2}}. Si no quieres más mensajes, responde BAJA.',
            'variables' => ['first_name', 'payment_link'], 'optOutLine' => true,
        ], key: 'pago-pendiente');
        // La línea base del Studio tiene `rules.paymentLinksEnabled = false`.
        $r = StudioStrategyValidator::validate($this->strategyContent($pago), StudioStrategyValidator::context(), 'commercial');
        $this->assertContains('payment_links_disabled', array_column($r['blocking'], 'code'));
        $this->assertContains('channel_not_live', array_column($r['warnings'], 'code'), 'WhatsApp por plantilla todavía se simula (W9)');
    }

    public function test_p9_10_con_una_version_del_studio_aprobada_la_linea_base_ya_no_vale_y_la_version_si(): void
    {
        [$ana, $beto] = $this->bootstrapPair();
        $t = $this->approvedTemplate($ana, $beto);
        $estudio = $this->seedVersion($ana, $this->contentWith(['governance' => ['canaryOnly' => false]]), 'APPROVED');
        $r = StudioStrategyValidator::validate($this->strategyContent($t), StudioStrategyValidator::context(), 'commercial');
        $this->assertContains('studio_baseline_superseded', array_column($r['blocking'], 'code'));

        $r = StudioStrategyValidator::validate($this->strategyContent($t, ['studio' => ['kind' => 'version', 'uuid' => (string) $estudio->uuid, 'contentHash' => (string) $estudio->content_hash]]),
            StudioStrategyValidator::context(), 'commercial');
        $this->assertSame([], $r['blocking'], json_encode($r['blocking']));
        $this->assertNotContains('canary_only_declared', array_column($r['warnings'], 'code'), 'esa versión no declara «solo canario»');
    }

    // ── Plantillas ──────────────────────────────────────────────────────────

    public function test_el_contenido_de_una_plantilla_es_cerrado_y_sus_textos_no_incumplen_r01_r02_r03_r06(): void
    {
        $bien = ['metaName' => 'aviso', 'language' => 'es', 'category' => 'marketing', 'bodyPreview' => 'Hola {{1}}. Si no quieres más mensajes, responde BAJA.', 'variables' => ['first_name'], 'optOutLine' => true];
        $this->assertSame([], StudioMessageTemplates::content('whatsapp', 'commercial', $bien)['errors']);

        $casos = [
            'sin línea de baja' => [['optOutLine' => false] + $bien, 'content.optOutLine', 'opt_out_required'],
            'categoría de otra finalidad' => [['category' => 'utility'] + $bien, 'content.category', 'category_purpose_mismatch'],
            'marcador fuera del conjunto' => [['variables' => ['phone']] + $bien, 'content.variables.0', 'value_not_in_domain'],
            'marcadores que no cuadran' => [['bodyPreview' => 'Hola {{1}} y {{2}}. Responde BAJA.'] + $bien, 'content.bodyPreview', 'placeholders_mismatch'],
            'R-01 importe' => [['bodyPreview' => 'Hola {{1}}, renueva por $120.000. Responde BAJA.'] + $bien, 'content.bodyPreview', 'restriction_violated'],
            'R-02 descuento' => [['bodyPreview' => 'Hola {{1}}, ¡mes GRATIS! Responde BAJA.'] + $bien, 'content.bodyPreview', 'restriction_violated'],
            'R-03 URL' => [['bodyPreview' => 'Hola {{1}}, entra a www.ironbody.co. Responde BAJA.'] + $bien, 'content.bodyPreview', 'restriction_violated'],
            'R-06 promesa' => [['bodyPreview' => 'Hola {{1}}, resultados garantizados. Responde BAJA.'] + $bien, 'content.bodyPreview', 'restriction_violated'],
            'datos de contacto' => [['bodyPreview' => 'Hola {{1}}, llama al 3001234567. Responde BAJA.'] + $bien, 'content.bodyPreview', 'contact_data'],
            'clave de más' => [['sql' => 'drop table'] + $bien, 'content.sql', 'unexpected_key'],
        ];
        foreach ($casos as $nombre => [$contenido, $clave, $codigo]) {
            $errores = StudioMessageTemplates::content('whatsapp', 'commercial', $contenido)['errors'];
            $this->assertContains(['key' => $clave, 'code' => $codigo], $errores, $nombre.': '.json_encode($errores));
        }
        // La cobranza: solo utility y sin precio de plan.
        $this->assertContains(['key' => 'content.variables.0', 'code' => 'marker_not_allowed'],
            StudioMessageTemplates::content('whatsapp', 'collections', ['category' => 'utility', 'variables' => ['plan_price'], 'bodyPreview' => 'Hola {{1}}. Responde BAJA.'] + $bien)['errors']);
        // Voz: un objetivo de la lista cerrada; asistido: medio y guion.
        $this->assertSame([], StudioMessageTemplates::content('voice', 'commercial', ['purposeKey' => 'renew'])['errors']);
        $this->assertNotSame([], StudioMessageTemplates::content('voice', 'commercial', ['purposeKey' => 'cualquier_cosa'])['errors']);
        $this->assertNotSame([], StudioMessageTemplates::content('assisted', 'commercial', ['medium' => 'sms', 'script' => 'Hola'])['errors']);
    }

    public function test_una_plantilla_se_versiona_por_clave_no_se_edita_y_la_revisa_otra_persona(): void
    {
        [$ana, $beto] = $this->bootstrapPair();
        $v1 = $this->s9('POST', 'strategies/templates', $ana, ['clientRequestId' => (string) Str::uuid(), 'key' => 'guion-x', 'channel' => 'assisted', 'purpose' => 'commercial',
            'content' => ['medium' => 'voice', 'script' => 'Saluda y pregunta si quiere renovar.']])->assertCreated()->json('data');
        $v2 = $this->s9('POST', 'strategies/templates', $ana, ['clientRequestId' => (string) Str::uuid(), 'key' => 'guion-x', 'channel' => 'assisted', 'purpose' => 'commercial',
            'content' => ['medium' => 'voice', 'script' => 'Saluda, pregunta si quiere renovar y ofrece ayuda.']])->assertCreated()->json('data');
        $this->assertSame([1, 2], [$v1['number'], $v2['number']]);
        $this->assertSame(['uuid' => $v2['uuid'], 'hash' => $v2['contentHash']], $v2['reference']);
        // La misma clave no cambia de canal.
        $this->s9('POST', 'strategies/templates', $ana, ['clientRequestId' => (string) Str::uuid(), 'key' => 'guion-x', 'channel' => 'voice', 'purpose' => 'commercial',
            'content' => ['purposeKey' => 'renew']])->assertStatus(409)->assertJsonPath('code', 'template_key_mismatch');
        // Rechazar exige motivo; la autora no revisa.
        $this->s9('POST', "strategies/templates/{$v1['uuid']}/reject", $beto, [])->assertStatus(422);
        $this->s9('POST', "strategies/templates/{$v1['uuid']}/reject", $ana, ['note' => 'No me gusta el tono'])->assertStatus(403);
        $this->s9('POST', "strategies/templates/{$v1['uuid']}/reject", $beto, ['note' => 'Falta decir cómo dejar de recibir mensajes'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $this->s9('POST', "strategies/templates/{$v1['uuid']}/approve", $beto, [])->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        // Una cuenta sin vínculo verificado no crea ni revisa.
        $sinVinculo = $this->account();
        $this->s9('POST', "strategies/templates/{$v2['uuid']}/approve", $sinVinculo, [])->assertStatus(403)->assertJsonPath('code', 'person_binding_required');
        $this->s9('POST', 'strategies/templates', $sinVinculo, ['clientRequestId' => (string) Str::uuid(), 'key' => 'guion-y', 'channel' => 'assisted', 'purpose' => 'commercial',
            'content' => ['medium' => 'voice', 'script' => 'Hola.']])->assertStatus(403)->assertJsonPath('code', 'person_binding_required');
        // La vista previa no lleva datos de nadie.
        $detalle = $this->s9('GET', "strategies/templates/{$v2['uuid']}", $beto)->assertOk()->json('data');
        $this->assertSame('Saluda, pregunta si quiere renovar y ofrece ayuda.', $detalle['preview']);
        $this->assertTrue($detalle['actions']['approve']['allowed']);
    }

    public function test_el_validador_del_servicio_y_la_api_dan_lo_mismo_y_validar_es_puro(): void
    {
        [$ana, $beto] = $this->bootstrapPair();
        $t = $this->approvedTemplate($ana, $beto);
        $s = app(StudioStrategies::class)->create(new StudioAccess($ana), Request::create('/'), (string) Str::uuid(), 'buena', 'Buena', 'commercial', $this->strategyContent($t))['strategy'];
        $api = $this->s9('POST', "strategies/{$s->uuid}/validate", $ana, [])->assertOk()->json('data');
        $servicio = StudioStrategyValidator::record(StudioStrategyValidator::validate($this->strategyContent($t), StudioStrategyValidator::context(), 'commercial'));
        $this->assertSame($servicio['contentHash'], $api['contentHash']);
        $this->assertSame($servicio['warningIds'], $api['warningIds']);
        $this->assertSame(1, StudioStrategy::query()->count());
    }
}
