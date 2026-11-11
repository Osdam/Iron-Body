<?php

namespace Tests\Feature\UltronStudio\Strategies;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use stdClass;
use Tests\TestCase;

/**
 * H9-E2E-1 (tester final, E2E #26 con navegador real; L9-84): el borrador vacío del alta salía como `"content":[]` y el
 * CRM, con razón, no abría el constructor (`readDraft` exige un objeto o `null`). La API devuelve ahora el JSON guardado
 * tal cual: el vacío es `{}` en el alta, en la lectura y en el 409 `draft_moved`, y un borrador lleno sigue siendo un
 * objeto. Mira el JSON CRUDO, que es lo que lee el CRM.
 */
class UltronStudioStrategyDraftShapeTest extends TestCase
{
    use RefreshDatabase;
    use StrategyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->withoutRouteMap();
        $this->grantStrategyPermissions();
        $this->strategyFlags(enabled: true);
        Carbon::setTestNow($this->weekdayMorning());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_el_borrador_vacio_del_alta_es_un_objeto_en_el_alta_en_la_lectura_y_en_el_conflicto(): void
    {
        [$ana, $beto] = $this->bootstrapPair();
        $t = $this->approvedTemplate($ana, $beto);

        $alta = $this->s9('POST', 'strategies', $ana, ['clientRequestId' => (string) Str::uuid(), 'key' => 'forma-del-borrador', 'name' => 'Forma del borrador', 'purpose' => 'commercial'])
            ->assertCreated();
        $this->assertInstanceOf(stdClass::class, json_decode((string) $alta->getContent(), false)->data->draft->content, 'alta: el borrador vacío es {}');
        $this->assertStringNotContainsString('"content":[]', (string) $alta->getContent());
        $uuid = (string) $alta->json('data.uuid');

        $lectura = $this->s9('GET', "strategies/{$uuid}", $ana)->assertOk();
        $this->assertInstanceOf(stdClass::class, json_decode((string) $lectura->getContent(), false)->data->draft->content, 'lectura: el borrador vacío es {}');

        // Guardado con contenido: sigue siendo un objeto, con lo guardado.
        $guardado = $this->s9('PUT', "strategies/{$uuid}/draft", $ana, ['baseRevision' => 1, 'draft' => $this->strategyContent($t)])->assertOk();
        $contenido = json_decode((string) $guardado->getContent(), false)->data->draft->content;
        $this->assertInstanceOf(stdClass::class, $contenido);
        $this->assertSame($this->strategyContent($t)['format'] ?? null, $contenido->format ?? null);

        // Otra pestaña con la revisión vieja: el 409 trae lo guardado, también como objeto.
        $conflicto = $this->s9('PUT', "strategies/{$uuid}/draft", $beto, ['baseRevision' => 1, 'draft' => $this->strategyContent($t)])->assertStatus(409)->assertJsonPath('code', 'draft_moved');
        $this->assertStringNotContainsString('"content":[]', (string) $conflicto->getContent());
    }
}
