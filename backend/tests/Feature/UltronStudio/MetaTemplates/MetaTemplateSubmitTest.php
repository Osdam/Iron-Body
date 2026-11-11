<?php

namespace Tests\Feature\UltronStudio\MetaTemplates;

use App\Models\Admin;
use App\Models\UltronStudio\StudioMetaTemplateSubmission as Submission;
use App\Services\Meta\Templates\TemplateRequestGuard;
use App\Services\Outreach\Core\CanonicalJson;
use App\Services\UltronStudio\StudioMessageTemplates as T;
use App\Services\UltronStudio\StudioTemplateMetaChecks;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PLANTILLAS DE META · PASO 3, ENVIAR (PLANTILLAS_META_PLAN §5.4, §5.8; L9-96):
 *
 *  - el camino feliz completo: crear → aprobar otra persona → enviar → PENDING →
 *    APPROVED → el canal ACEPTA (por el puente) → PAUSED → no → APPROVED → sí;
 *  - lo que sale a Meta es la versión inmutable reconstruida, nunca lo que mande el CRM;
 *  - cada 409 y 422 de §5.8.3, con lo que el CRM ve en `actions.submit`;
 *  - la misma petición repetida no vuelve a enviar; otra con el mismo id, 409;
 *  - dos envíos a la vez hacen UN solo POST (cerrojo e índice único);
 *  - un tiempo agotado deja UNKNOWN; la sincronización la encuentra o, a los 15
 *    minutos, la da por no creada.
 * Con el doble (sin red) salvo donde se prueba el POST con `Http::fake`.
 */
class MetaTemplateSubmitTest extends TestCase
{
    use MetaTemplateFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->metaWorld();
    }

    /** Graph de mentira detrás del cliente de verdad (fuera de producción: el simulador). */
    private function graphFalso(mixed $respuesta): void
    {
        $this->metaFlags(driver: 'graph', base: 'http://127.0.0.1:8097');
        config(['meta.access_token' => 'EAAtokenDePruebaDelEnvio123']);
        Http::fake(['*' => $respuesta]);
    }

    public function test_el_camino_feliz_completo_hasta_que_el_canal_la_acepta_y_la_pausa_la_quita(): void
    {
        $t = $this->approvedMeta('pausar_renovacion_v1');
        $antes = $this->meta('GET', "strategies/templates/{$t->uuid}", $this->ana)->assertOk();
        $this->assertSame(['allowed' => true], $antes->json('data.meta.actions.submit'));
        $this->assertSame(['allowed' => false, 'code' => 'meta_not_tracked'], $antes->json('data.meta.actions.refresh'));
        $this->assertNull($antes->json('data.meta.submission'));
        $this->assertSame(['template_not_approved_by_meta'], $antes->json('data.meta.campaignReasons'));

        $r = $this->submitMeta($t)->assertCreated();
        $this->assertSame('SUBMITTED', $r->json('data.meta.state'));
        $this->assertSame('PENDING', $r->json('data.meta.status'));
        $this->assertFalse($r->json('data.meta.campaignReady'));
        $this->assertMatchesRegularExpression('/^[0-9]+$/', (string) $r->json('data.meta.submission.metaTemplateId'));
        $this->assertSame(['name' => 'Ana Autora', 'role' => Admin::ROLE_SUPER_ADMIN], $r->json('data.meta.submission.submittedBy'));
        $this->assertSame(['allowed' => false, 'code' => 'already_submitted'], $r->json('data.meta.actions.submit'));
        $this->assertSame(['allowed' => true], $r->json('data.meta.actions.refresh'));
        $this->assertSame([], $this->checksOf($t), 'enviar no escribe comprobaciones: las escribe lo que se LEE de Meta');
        $this->assertFalse($this->accepts($t));

        // 1.ª pasada: APPROVED y todo casa → la fila meta_api a nombre de quien la envió, y el canal la acepta.
        $this->assertSame('ok', $this->syncPass()['outcome']);
        $filas = $this->checksOf($t);
        $this->assertCount(1, $filas);
        $this->assertSame(['APPROVED', 'meta_api', (int) $this->ana->id, 'Ana Autora'], [$filas[0]['status'], $filas[0]['source'], (int) $filas[0]['recorded_by_admin_id'], $filas[0]['recorded_by_name']]);
        $this->assertSame(T::componentsFingerprint(json_decode($t->content_json, true)), $filas[0]['meta_components_hash']);
        $this->assertSame((int) $this->submissionOf($t)->id, (int) $filas[0]['submission_id']);
        $this->assertTrue($this->accepts($t), 'el PUENTE del canal la acepta');
        $detalle = $this->meta('GET', "strategies/templates/{$t->uuid}", $this->beto)->assertOk();
        $this->assertTrue($detalle->json('data.meta.campaignReady'));
        $this->assertSame([], $detalle->json('data.meta.campaignReasons'));
        $this->assertSame(['status' => 'APPROVED', 'source' => 'meta_api', 'recordedBy' => 'Ana Autora'], array_intersect_key($detalle->json('data.meta.latestCheck'), array_flip(['status', 'source', 'recordedBy'])));
        $this->assertSame('GREEN', $detalle->json('data.meta.submission.quality'));
        $this->assertTrue($detalle->json('data.meta.submission.matchesStudio'));
        $lista = $this->meta('GET', 'strategies/templates', $this->beto)->assertOk();
        $this->assertSame(['status' => 'APPROVED', 'state' => 'SUBMITTED', 'campaignReady' => true], $lista->json('data.data.0.meta'));

        // 2.ª pasada: PAUSED → fila negativa en el acto; el canal deja de aceptarla.
        $this->syncPass();
        $this->assertSame(['APPROVED', 'PAUSED'], array_column($this->checksOf($t), 'status'));
        $this->assertFalse($this->accepts($t));
        $this->assertSame(['meta_status_not_approved'], $this->meta('GET', "strategies/templates/{$t->uuid}", $this->beto)->json('data.meta.campaignReasons'));
        $this->assertSame(['PAUSED', 'RED'], [$this->submissionOf($t)->meta_status, $this->submissionOf($t)->meta_quality]);

        // 3.ª pasada: APPROVED otra vez → otra fila (ir y volver deja tres) y el canal la acepta.
        $this->syncPass();
        $this->assertSame(['APPROVED', 'PAUSED', 'APPROVED'], array_column($this->checksOf($t), 'status'));
        $this->assertTrue($this->accepts($t));

        $eventos = $this->eventsOf($t);
        $this->assertSame(['message_template.created', 'message_template.approved', 'meta_template.submit_requested', 'meta_template.submitted'], array_slice($eventos, 0, 4));
        $this->assertSame(3, count(array_keys($eventos, 'meta_template.meta_check_recorded', true)));
        $this->assertContains('meta_template.status_changed', $eventos);
        $this->assertContains('meta_template.quality_changed', $eventos);
        // El historial del detalle: del libro, del más reciente al más antiguo.
        $historial = array_column($detalle->json('data.meta.timeline'), 'event');
        $this->assertSame(['meta_template.meta_check_recorded', 'meta_template.submitted', 'meta_template.submit_requested', 'message_template.approved', 'message_template.created'],
            array_values(array_intersect($historial, ['meta_template.meta_check_recorded', 'meta_template.submitted', 'meta_template.submit_requested', 'message_template.approved', 'message_template.created'])));
    }

    public function test_lo_que_sale_a_meta_es_la_version_inmutable_reconstruida_y_su_huella_queda_en_el_envio(): void
    {
        $this->graphFalso(Http::response(['id' => '7000000000000001', 'status' => 'PENDING', 'category' => 'MARKETING']));
        $t = $this->approvedMeta('renovacion_aviso_v2');
        $esperado = T::metaPayload(json_decode($t->content_json, true));

        // El cuerpo del CRM solo lleva el id de la petición: lo demás se ignora.
        $this->meta('POST', "strategies/templates/{$t->uuid}/meta/submit", $this->ana, ['clientRequestId' => (string) Str::uuid(), 'name' => 'otro_nombre', 'components' => []])->assertCreated();

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $p): bool => $p->method() === 'POST'
            && $p->url() === 'http://127.0.0.1:8097/v25.0/'.self::WABA.'/message_templates'
            && $p->body() === TemplateRequestGuard::payload($esperado));
        $envio = $this->submissionOf($t);
        $this->assertSame(CanonicalJson::hash($esperado), $envio->request_hash);
        $this->assertSame(['SUBMITTED', '7000000000000001', 'PENDING', 'MARKETING'], [$envio->state, $envio->meta_template_id, $envio->meta_status, $envio->meta_category]);
        $this->assertSame([$t->content_hash, 'renovacion_aviso_v2', 'es_CO', 'marketing'], [$envio->template_content_hash, $envio->meta_name, $envio->language, $envio->category]);
    }

    public function test_cada_409_y_422_del_envio_y_lo_que_el_crm_ve_antes_de_pulsar(): void
    {
        $t = $this->approvedMeta('aprobar_errores_v1');
        $url = "strategies/templates/{$t->uuid}/meta/submit";
        $intenta = fn (?Admin $quien = null) => $this->meta('POST', $url, $quien ?? $this->ana, ['clientRequestId' => (string) Str::uuid()]);
        $accion = fn () => $this->meta('GET', "strategies/templates/{$t->uuid}", $this->ana)->json('data.meta.actions.submit');

        foreach ([
            'meta_templates_disabled' => ['enabled' => false],
            'meta_submit_disabled' => ['submit' => false],
            'meta_messaging_disabled' => ['metaEnabled' => false],
            'meta_not_configured' => ['driver' => 'graph', 'waba' => null],
        ] as $codigo => $banderas) {
            $this->metaFlags(...$banderas);
            $intenta()->assertStatus(409)->assertJsonPath('code', $codigo);
            $this->assertSame(['allowed' => false, 'code' => $codigo], $accion(), $codigo);
        }
        $this->metaFlags();
        $this->assertSame(0, Submission::query()->count(), 'con las banderas apagadas no se escribe nada');

        // Sin la llave (el Administrador no la tiene por defecto) y sin persona verificada.
        $admin = $this->account(Admin::ROLE_ADMINISTRADOR, 'Adela Administradora');
        $intenta($admin)->assertStatus(403);
        $sinPersona = $this->account(Admin::ROLE_SUPER_ADMIN, 'Sin Vínculo');
        $intenta($sinPersona)->assertStatus(403)->assertJsonPath('code', 'person_binding_required');

        // La versión: no existe, no es de WhatsApp, no está aprobada, le faltan los ejemplos.
        $this->meta('POST', 'strategies/templates/'.Str::uuid().'/meta/submit', $this->ana, ['clientRequestId' => (string) Str::uuid()])->assertStatus(404)->assertJsonPath('code', 'template_not_found');
        $asistida = $this->approvedTemplate($this->ana, $this->beto, 'assisted', 'commercial', null, 'guion-meta');
        $this->meta('POST', "strategies/templates/{$asistida->uuid}/meta/submit", $this->ana, ['clientRequestId' => (string) Str::uuid()])->assertStatus(422)->assertJsonPath('code', 'template_not_whatsapp');
        $borrador = $this->draftTemplate($this->ana, 'whatsapp', 'commercial', self::metaContent('aprobar_borrador_v1'), 'aprobar_borrador_v1');
        $this->meta('POST', "strategies/templates/{$borrador->uuid}/meta/submit", $this->ana, ['clientRequestId' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'template_not_approved');
        $this->assertSame(['allowed' => false, 'code' => 'template_not_approved'], $this->meta('GET', "strategies/templates/{$borrador->uuid}", $this->ana)->json('data.meta.actions.submit'));
        $sinEjemplos = $this->approvedMeta('aprobar_sin_ejemplos_v1', cambios: ['examples' => null]);
        $this->assertTrue(T::missingMetaExamples(json_decode($sinEjemplos->content_json, true)));
        $this->meta('POST', "strategies/templates/{$sinEjemplos->uuid}/meta/submit", $this->ana, ['clientRequestId' => (string) Str::uuid()])->assertStatus(422)->assertJsonPath('code', 'meta_examples_required');
        $this->meta('POST', $url, $this->ana, [])->assertStatus(422);
        $this->meta('POST', $url, $this->ana, ['clientRequestId' => 'no-es-un-uuid'])->assertStatus(422);

        // Ya enviada; y otra versión con el mismo nombre e idioma.
        $intenta()->assertCreated();
        $intenta()->assertStatus(409)->assertJsonPath('code', 'already_submitted');
        // La versión siguiente de la misma clave, con el MISMO nombre en Meta (el CRM propone `_v<n+1>`, pero no se impone al crear).
        $gemela = $this->approvedMeta('aprobar_errores_v1', cambios: ['footer' => ['text' => 'Otro pie distinto']]);
        $this->assertSame(2, (int) $gemela->number);
        $this->meta('POST', "strategies/templates/{$gemela->uuid}/meta/submit", $this->ana, ['clientRequestId' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('code', 'meta_name_taken')->assertJsonPath('template.uuid', (string) $t->uuid);
        $this->assertSame(['allowed' => false, 'code' => 'meta_name_taken'], $this->meta('GET', "strategies/templates/{$gemela->uuid}", $this->ana)->json('data.meta.actions.submit'));
        $this->assertSame(1, Submission::query()->count());
    }

    /**
     * El `four_eyes_required` del envío es DEFENSIVO (§5.8.3): la base (DB9,
     * `usmt_review_once` y la guarda de cuatro ojos de la plantilla) ya impide
     * que una versión quede revisada por la persona que la escribió, ni de
     * entrada ni cambiándolo después a mano. Esta prueba fija esa primera línea;
     * la del servicio queda como segunda, sin datos que la alcancen.
     */
    public function test_cuatro_ojos_la_base_impide_que_la_autora_figure_como_revisora_y_no_hay_envio(): void
    {
        $t = $this->approvedMeta('aprobar_cuatro_ojos_v1');
        foreach ([['reviewed_by_person_id' => $t->created_by_person_id], ['reviewed_by_admin_id' => $t->created_by_admin_id]] as $cambio) {
            try {
                // En su propio punto de guardado: en PG un fallo aborta la transacción de la prueba.
                DB::transaction(static fn () => DB::table('ultron_studio_message_templates')->where('id', $t->id)->update($cambio));
                $this->fail('la base dejó que la autora figurase como revisora: '.json_encode(array_keys($cambio)));
            } catch (QueryException $e) {
                $this->assertStringContainsString('ultron_studio', $e->getMessage());
            }
        }
        $this->assertSame((int) $this->beto->id, (int) $t->fresh()->reviewed_by_admin_id);
        $this->submitMeta($t)->assertCreated();
        $this->assertSame((int) $this->ana->id, $this->submissionOf($t)->submitted_by_admin_id, 'la autora sí puede enviar (Q3: sin cuatro ojos adicionales)');
    }

    public function test_la_misma_peticion_repetida_no_vuelve_a_enviar_y_con_otra_version_es_409(): void
    {
        $t = $this->approvedMeta('aprobar_idempotente_v1');
        $otra = $this->approvedMeta('aprobar_idempotente_v2');
        $peticion = (string) Str::uuid();

        $this->submitMeta($t, peticion: $peticion)->assertCreated();
        $this->submitMeta($t, peticion: $peticion)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.meta.state', 'SUBMITTED');
        $this->submitMeta($otra, peticion: $peticion)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->submitMeta($t, $this->beto, $peticion)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');

        $this->assertSame(1, Submission::query()->count());
        $this->assertSame(1, count(array_keys($this->eventsOf($t), 'meta_template.submit_requested', true)));
    }

    public function test_dos_envios_a_la_vez_hacen_un_solo_post(): void
    {
        $this->graphFalso(Http::response(['id' => '7000000000000002', 'status' => 'PENDING', 'category' => 'MARKETING']));
        $t = $this->approvedMeta('renovacion_carrera_v1');

        // a) Otro proceso tiene el cerrojo de la versión (está enviándola): 409 sin red.
        $cerrojo = Cache::lock('ultron-studio:meta-template:'.$t->id, 60);
        $this->assertTrue($cerrojo->get());
        $this->submitMeta($t)->assertStatus(409)->assertJsonPath('code', 'already_submitted');
        Http::assertNothingSent();
        $cerrojo->release();

        // b) Otro proceso ya confirmó su envío (SENDING) sin la caché compartida: el índice único y la relectura mandan.
        DB::table('ultron_studio_meta_template_submissions')->insert([
            'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'template_id' => $t->id, 'template_content_hash' => $t->content_hash,
            'meta_name' => 'renovacion_carrera_v1', 'language' => 'es_CO', 'category' => 'marketing', 'request_hash' => str_repeat('a', 64), 'state' => 'SENDING',
            'attester_unavailable' => false, 'submitted_by_admin_id' => $this->beto->id,
            'submitted_by_person_id' => DB::table('ultron_studio_person_bindings')->where('admin_id', $this->beto->id)->value('identity_id'),
            'submitted_by_name' => 'Beto Revisor', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->submitMeta($t)->assertStatus(409)->assertJsonPath('code', 'already_submitted');
        Http::assertNothingSent();
        $this->assertSame(1, Submission::query()->count());
    }

    public function test_un_tiempo_agotado_deja_unknown_con_un_solo_post_y_nunca_se_reenvia(): void
    {
        $this->graphFalso(static fn (HttpRequest $r) => Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out after 15001 milliseconds', $r->toPsrRequest(), null, ['errno' => 28])));
        $t = $this->approvedMeta('renovacion_tiempo_v1');

        $r = $this->submitMeta($t)->assertCreated();

        $this->assertSame('UNKNOWN', $r->json('data.meta.state'));
        $this->assertNull($r->json('data.meta.submission.metaTemplateId'));
        Http::assertSentCount(1);
        $this->assertSame(['allowed' => false, 'code' => 'already_submitted'], $r->json('data.meta.actions.submit'), 'no se vuelve a enviar a ciegas');
        $this->assertContains('meta_template.submit_unknown', $this->eventsOf($t));
    }

    public function test_desconocido_la_sincronizacion_la_encuentra_por_nombre_o_a_los_15_minutos_la_da_por_no_creada(): void
    {
        // caer_: Meta la creó aunque no contestó.
        $cae = $this->approvedMeta('caer_renovacion_v1');
        $this->assertSame('UNKNOWN', $this->submitMeta($cae)->assertCreated()->json('data.meta.state'));
        $this->syncPass();
        $envio = $this->submissionOf($cae);
        $this->assertSame('SUBMITTED', $envio->state);
        $this->assertNotNull($envio->meta_template_id);
        $this->assertContains('meta_template.submitted', $this->eventsOf($cae));
        $this->assertTrue($this->accepts($cae), 'encontrada y aprobada: lista');

        // perder_: no se creó. Antes de 15 minutos espera; después, FAILED y la versión queda libre.
        $pierde = $this->approvedMeta('perder_renovacion_v1');
        $this->submitMeta($pierde)->assertCreated();
        $this->syncPass();
        $this->assertSame('UNKNOWN', $this->submissionOf($pierde)->state);
        $this->travel(16)->minutes();
        $this->syncPass();
        $this->assertSame('FAILED', $this->submissionOf($pierde)->state);
        $this->assertSame(['allowed' => true], $this->meta('GET', "strategies/templates/{$pierde->uuid}", $this->ana)->json('data.meta.actions.submit'), 'FAILED libera la versión');
        $this->assertSame([], $this->checksOf($pierde));
    }

    public function test_un_rechazo_de_la_peticion_queda_failed_con_el_error_de_meta_y_libera_la_version(): void
    {
        $t = $this->approvedMeta('fallar_renovacion_v1');

        $r = $this->submitMeta($t)->assertCreated();

        $this->assertSame('FAILED', $r->json('data.meta.state'));
        $this->assertSame(100, $r->json('data.meta.submission.error.code'));
        $this->assertStringContainsString('fallar_', (string) $r->json('data.meta.submission.error.message'));
        $this->assertSame(['allowed' => true], $r->json('data.meta.actions.submit'));
        $this->assertContains('meta_template.submit_failed', $this->eventsOf($t));
        $this->assertSame(0, DB::table(StudioTemplateMetaChecks::TABLE)->count());
    }
}
