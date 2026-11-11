<?php

namespace Tests\Feature\UltronStudio\MetaTemplates;

use App\Models\UltronStudio\StudioMessageTemplate;
use App\Models\UltronStudio\StudioMetaTemplateSubmission as Submission;
use App\Services\Meta\Templates\FakeWhatsappTemplateClient;
use App\Services\UltronStudio\StudioMessageTemplates as T;
use App\Services\UltronStudio\StudioTemplateMetaChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Monolog\Handler\NullHandler;
use Tests\TestCase;

/**
 * PLANTILLAS DE META · PASO 3, LO QUE DICE META (PLANTILLAS_META_PLAN §5.5,
 * §5.6; L9-96): la sincronización y «Comprobar ahora» escriben solas el
 * registro que lee el canal de W9, y lo negativo siempre.
 *
 *  - editar en el administrador de Meta (otros componentes) → fila → no acepta;
 *  - la recategorización UTILITY → MARKETING en cobranza → no acepta;
 *  - borrada fuera → NOT_FOUND → no acepta;
 *  - rechazada, y en revisión durante varias pasadas;
 *  - quien la envió ya no puede dar fe → no se escribe el APPROVED → «Comprobar
 *    ahora» de otra persona con la llave → se escribe a su nombre;
 *  - las pasadas sin cambios no escriben filas ni eventos;
 *  - una plantilla registrada a mano también se vigila;
 *  - en seco no escribe; apagada, sin configurar o sin nada que vigilar no sale a la red;
 *  - un SENDING huérfano pasa a UNKNOWN; si Meta falla no se inventa nada (y V-2).
 */
class MetaTemplateSyncTest extends TestCase
{
    use MetaTemplateFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['logging.channels.channel' => ['driver' => 'monolog', 'handler' => NullHandler::class]]);
        $this->metaWorld();
    }

    private function enviada(string $metaName, string $purpose = 'commercial', array $cambios = []): StudioMessageTemplate
    {
        $t = $this->approvedMeta($metaName, $purpose, $cambios);
        $this->submitMeta($t)->assertCreated();

        return $t;
    }

    public function test_editar_los_componentes_en_el_administrador_de_meta_escribe_una_fila_que_no_casa(): void
    {
        $t = $this->enviada('editar_renovacion_v1');
        $this->syncPass();
        $this->assertTrue($this->accepts($t));

        $this->syncPass();

        $filas = $this->checksOf($t);
        $this->assertSame(['APPROVED', 'APPROVED'], array_column($filas, 'status'));
        $this->assertNotSame($filas[0]['meta_components_hash'], $filas[1]['meta_components_hash']);
        $this->assertSame('Ana Autora', $filas[1]['recorded_by_name'], 'lo negativo, a nombre de quien dio fe la última vez, sin esperar a nadie');
        $this->assertFalse($this->accepts($t), 'Meta enseña otro pie: el canal no la usa');
        $envio = $this->submissionOf($t);
        $this->assertSame([false, 'components_mismatch'], [$envio->matches_studio, $envio->mismatch_codes]);
        $this->assertContains('meta_template.mismatch_detected', $this->eventsOf($t));
        $this->assertSame(['components_mismatch'], $this->meta('GET', "strategies/templates/{$t->uuid}", $this->beto)->json('data.meta.campaignReasons'));
    }

    public function test_la_recategorizacion_de_una_de_cobranza_a_marketing_deja_de_casar(): void
    {
        $t = $this->enviada('recategorizar_cobro_v1', 'collections');
        $this->assertSame('UTILITY', $this->submissionOf($t)->meta_category);
        $this->syncPass();
        $this->assertTrue($this->accepts($t));

        $this->syncPass();

        $this->assertSame(['utility', 'marketing'], array_column($this->checksOf($t), 'category'));
        $this->assertFalse($this->accepts($t));
        $this->assertSame('MARKETING', $this->submissionOf($t)->meta_category);
        $this->assertContains('meta_template.category_changed', $this->eventsOf($t));
        $this->assertSame(['category_mismatch'], $this->meta('GET', "strategies/templates/{$t->uuid}", $this->beto)->json('data.meta.campaignReasons'));
    }

    public function test_borrada_fuera_del_crm_queda_not_found_y_no_se_repite(): void
    {
        $t = $this->enviada('borrar_renovacion_v1');
        $this->syncPass();
        $this->assertTrue($this->accepts($t));

        $this->syncPass();
        $this->syncPass();

        $this->assertSame(['APPROVED', 'NOT_FOUND'], array_column($this->checksOf($t), 'status'), 'NOT_FOUND una sola vez: la pasada siguiente no cambia nada');
        $this->assertFalse($this->accepts($t));
        $envio = $this->submissionOf($t);
        $this->assertSame(['NOT_FOUND', false], [$envio->meta_status, $envio->matches_studio]);
        $this->assertStringStartsWith('meta_template_not_found', (string) $envio->mismatch_codes);
    }

    public function test_rechazada_y_en_revision_durante_varias_pasadas(): void
    {
        $r = $this->enviada('rechazar_renovacion_v1');
        $tarda = $this->enviada('tardar_renovacion_v1');

        $this->syncPass();
        $this->assertSame(['REJECTED'], array_column($this->checksOf($r), 'status'));
        $this->assertSame('INVALID_FORMAT', $this->meta('GET', "strategies/templates/{$r->uuid}", $this->beto)->json('data.meta.submission.rejectedReason'));
        $this->assertFalse($this->accepts($r));

        // tardar_: PENDING tres pasadas (una sola fila) y luego APPROVED.
        $this->syncPass();
        $this->syncPass();
        $this->assertSame(['PENDING'], array_column($this->checksOf($tarda), 'status'));
        $this->syncPass();
        $this->assertSame(['PENDING', 'APPROVED'], array_column($this->checksOf($tarda), 'status'));
        $this->assertTrue($this->accepts($tarda));
    }

    public function test_si_quien_la_envio_ya_no_puede_dar_fe_no_se_escribe_el_approved_hasta_que_otra_persona_comprueba(): void
    {
        $t = $this->enviada('aprobar_testigo_v1');
        $this->ana->forceFill(['status' => 'inactive'])->save();

        $pasada = $this->syncPass();

        $this->assertTrue($pasada['templates'][0]['attesterUnavailable']);
        $this->assertSame([], $this->checksOf($t), 'sin nadie que pueda dar fe, el APPROVED no se escribe');
        $this->assertFalse($this->accepts($t));
        $envio = $this->submissionOf($t);
        $this->assertSame(['APPROVED', true], [$envio->meta_status, $envio->attester_unavailable]);
        $this->assertContains('meta_template.attester_unavailable', $this->eventsOf($t));
        $detalle = $this->meta('GET', "strategies/templates/{$t->uuid}", $this->beto);
        $this->assertTrue($detalle->json('data.meta.submission.attesterUnavailable'));
        $this->assertSame(['allowed' => true], $detalle->json('data.meta.actions.refresh'));

        // Otra pasada sin nadie: tampoco.
        $this->syncPass();
        $this->assertSame([], $this->checksOf($t));

        // «Comprobar ahora» de Beto (llave y persona verificada): se escribe a SU nombre.
        $r = $this->meta('POST', "strategies/templates/{$t->uuid}/meta/refresh", $this->beto)->assertOk();
        $this->assertSame(['outcome' => 'ok', 'error' => null, 'changed' => true, 'rowWritten' => true, 'channelAccepts' => true], $r->json('check'));
        $filas = $this->checksOf($t);
        $this->assertCount(1, $filas);
        $this->assertSame([(int) $this->beto->id, 'Beto Revisor', 'APPROVED'], [(int) $filas[0]['recorded_by_admin_id'], $filas[0]['recorded_by_name'], $filas[0]['status']]);
        $this->assertFalse($this->submissionOf($t)->attester_unavailable);
        $this->assertTrue($this->accepts($t));
        $this->assertTrue($r->json('data.meta.campaignReady'));
        $this->assertContains('meta_template.check_requested', $this->eventsOf($t));
    }

    public function test_las_pasadas_sin_cambios_no_escriben_filas_ni_eventos(): void
    {
        $t = $this->enviada('aprobar_quieta_v1');
        $this->syncPass();
        $filas = count($this->checksOf($t));
        $eventos = count($this->eventsOf($t));

        foreach (range(1, 3) as $i) {
            $p = $this->syncPass();
            $this->assertFalse($p['templates'][0]['rowWritten'], "pasada {$i}");
            $this->assertFalse($p['templates'][0]['changed'], "pasada {$i}");
        }

        $this->assertSame($filas, count($this->checksOf($t)));
        $this->assertSame($eventos, count($this->eventsOf($t)));
        $this->assertNotNull($this->submissionOf($t)->last_checked_at);
    }

    public function test_una_plantilla_registrada_a_mano_tambien_se_vigila(): void
    {
        $cuerpo = 'Hola {{1}}, tu plan vence pronto. Si no quieres más mensajes, responde BAJA.';
        $solo = ['header' => null, 'footer' => null, 'buttons' => null];
        $pausada = $this->approvedMeta('manual_pausada_v1', cambios: $solo);
        $igual = $this->approvedMeta('manual_igual_v1', cambios: $solo);
        $doble = new FakeWhatsappTemplateClient(Cache::store());
        $ids = [];
        foreach (['manual_pausada_v1' => 'PAUSED', 'manual_igual_v1' => 'APPROVED'] as $nombre => $estado) {
            $ids[$nombre] = $doble->seed(self::WABA, ['name' => $nombre, 'language' => 'es_CO', 'category' => 'MARKETING', 'status' => $estado, 'components' => [['type' => 'BODY', 'text' => $cuerpo]]]);
        }
        foreach ([$pausada, $igual] as $t) {
            $c = json_decode($t->content_json, true);
            DB::table(StudioTemplateMetaChecks::TABLE)->insert([
                'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'template_id' => $t->id, 'template_content_hash' => $t->content_hash,
                'meta_name' => $c['metaName'], 'language' => 'es_CO', 'category' => 'marketing', 'meta_body_hash' => hash('sha256', $cuerpo),
                'meta_template_id' => $ids[$c['metaName']], 'status' => 'APPROVED', 'checked_at' => now()->subHour(), 'recorded_by_admin_id' => $this->beto->id,
                'recorded_by_name' => 'Beto Revisor', 'created_at' => now()->subHour(),
            ]);
            $this->assertTrue($this->accepts($t), 'la fila manual de una plantilla de solo cuerpo vale, como hoy');
        }

        $pasada = $this->syncPass();
        $this->syncPass();

        $this->assertSame(2, $pasada['watched']);
        $filas = $this->checksOf($pausada);
        $this->assertSame([['manual', 'APPROVED'], ['meta_api', 'PAUSED']], array_map(static fn (array $f): array => [$f['source'], $f['status']], $filas));
        $this->assertSame(['Beto Revisor', null], [$filas[1]['recorded_by_name'], $filas[1]['submission_id']], 'a nombre de quien la registró, sin envío');
        $this->assertFalse($this->accepts($pausada));
        $this->assertCount(1, $this->checksOf($igual), 'Meta dice lo mismo que la fila manual: nada que escribir');
        $this->assertTrue($this->accepts($igual));
        $this->assertSame(0, Submission::query()->count());
    }

    public function test_en_seco_lee_y_dice_que_cambiaria_sin_escribir_nada(): void
    {
        $t = $this->enviada('aprobar_seco_v1');
        $this->travel(1)->minutes();
        $antes = [count($this->eventsOf($t)), Cache::get('ultron-studio:meta-templates:last-sync'), $this->submissionOf($t)->toArray()];

        Artisan::call('ultron-studio:meta-templates-sync', ['--dry-run' => true, '--json' => true]);
        $r = json_decode(Artisan::output(), true);

        $this->assertSame(['ok', true, 1], [$r['outcome'], $r['dryRun'], $r['watched']]);
        $this->assertTrue($r['templates'][0]['rowWritten'], 'la escribiría');
        $this->assertTrue($r['templates'][0]['channelAccepts'], 'y el canal la aceptaría');
        $this->assertSame([], $this->checksOf($t));
        $this->assertSame($antes, [count($this->eventsOf($t)), Cache::get('ultron-studio:meta-templates:last-sync'), $this->submissionOf($t)->toArray()], 'ni eventos, ni la última pasada, ni el envío');
    }

    public function test_la_orden_sale_con_0_apagada_sin_configurar_o_sin_nada_que_vigilar_y_sin_red(): void
    {
        Http::fake();
        $casos = [
            'disabled' => fn () => $this->metaFlags(enabled: false),
            'disabled ' => fn () => $this->metaFlags(sync: false),
            'not_configured' => fn () => $this->metaFlags(driver: 'graph', base: null),
            'nothing_to_watch' => fn () => $this->metaFlags(),
        ];
        foreach ($casos as $esperado => $prepara) {
            $prepara();
            $this->assertSame(0, Artisan::call('ultron-studio:meta-templates-sync', ['--json' => true]), $esperado);
            $this->assertSame(trim($esperado), json_decode(Artisan::output(), true)['outcome'], $esperado);
        }
        $this->metaFlags();
        $this->assertSame(0, Artisan::call('ultron-studio:meta-templates-sync', ['--template' => (string) Str::uuid(), '--json' => true]));
        $this->assertSame('not_watched', json_decode(Artisan::output(), true)['outcome']);
        $this->assertSame(2, Artisan::call('ultron-studio:meta-templates-sync', ['--template' => 'no-es-un-uuid']));
        $this->assertSame(0, Artisan::call('ultron-studio:meta-templates-sync'));
        $this->assertStringContainsString('NADA QUE VIGILAR', Artisan::output());
        $this->assertCount(0, Http::recorded(), 'ninguna petición');
    }

    public function test_una_sola_version_con_template_y_la_pasada_queda_en_el_estado(): void
    {
        $t = $this->enviada('aprobar_una_v1');
        $otra = $this->enviada('aprobar_otra_v1');

        $this->assertSame(0, Artisan::call('ultron-studio:meta-templates-sync', ['--template' => (string) $t->uuid, '--json' => true]));
        $r = json_decode(Artisan::output(), true);

        $this->assertSame([1, (string) $t->uuid], [$r['watched'], $r['templates'][0]['uuid']]);
        $this->assertCount(1, $this->checksOf($t));
        $this->assertSame([], $this->checksOf($otra));
        $estado = $this->meta('GET', 'strategies/templates/meta/status', $this->beto)->assertOk();
        $this->assertSame(['outcome' => 'ok', 'watched' => 1, 'rowsWritten' => 1], array_intersect_key($estado->json('data.lastSync'), array_flip(['outcome', 'watched', 'rowsWritten'])));
        $this->assertSame($r['at'], $estado->json('data.lastSyncAt'));
    }

    public function test_un_envio_que_se_quedo_en_sending_pasa_a_unknown(): void
    {
        $t = $this->approvedMeta('perder_huerfano_v1');
        DB::table('ultron_studio_meta_template_submissions')->insert([
            'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'template_id' => $t->id, 'template_content_hash' => $t->content_hash,
            'meta_name' => 'perder_huerfano_v1', 'language' => 'es_CO', 'category' => 'marketing', 'request_hash' => str_repeat('b', 64), 'state' => 'SENDING',
            'attester_unavailable' => false, 'submitted_by_admin_id' => $this->ana->id,
            'submitted_by_person_id' => DB::table('ultron_studio_person_bindings')->where('admin_id', $this->ana->id)->value('identity_id'),
            'submitted_by_name' => 'Ana Autora', 'submitted_at' => now()->subMinutes(3), 'created_at' => now()->subMinutes(3), 'updated_at' => now()->subMinutes(3),
        ]);
        $this->assertSame(['allowed' => false, 'code' => 'meta_check_in_progress'], $this->meta('GET', "strategies/templates/{$t->uuid}", $this->beto)->json('data.meta.actions.refresh'));

        $this->syncPass();

        $this->assertSame('UNKNOWN', $this->submissionOf($t)->state);
        $this->assertContains('meta_template.submit_unknown', $this->eventsOf($t));
    }

    public function test_si_meta_falla_no_se_inventa_nada_y_lo_ultimo_sigue_mandando(): void
    {
        $t = $this->enviada('aprobar_falla_v1');
        $this->syncPass();
        $this->assertTrue($this->accepts($t));
        $registros = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$registros): void {
            $registros[] = $m->message;
        });

        // Ahora Graph (el simulador) contesta 500 a todo.
        $this->metaFlags(driver: 'graph', base: 'http://127.0.0.1:8097');
        config(['meta.access_token' => 'EAAtokenDeLaPasadaFallida1']);
        Http::fake(['*' => Http::response(['error' => ['code' => 2, 'message' => 'Service temporarily unavailable']], 500)]);

        $p = $this->syncPass();

        $this->assertSame(['meta_failed', 'server'], [$p['outcome'], $p['error']]);
        $this->assertCount(1, $this->checksOf($t));
        $this->assertTrue($this->accepts($t));
        $this->assertContains('ultron_studio.meta_templates.sync_failed', $registros);
        $this->assertSame('meta_failed', Cache::get('ultron-studio:meta-templates:last-sync')['outcome']);
    }

    /**
     * M-3 (revisión de la ola): el código de salida de la orden. Bien → 0; alguna versión sin comprobar
     * (`partial`) → 0, pero lo dice (el recuento en la salida y `unresolved` en `--json`); Meta falla para
     * TODA la pasada (`meta_failed`) → 1, que el programador ve como un fallo. Apagada, 0 como siempre.
     */
    public function test_la_orden_sale_con_1_si_meta_falla_en_toda_la_pasada_y_con_0_si_falla_en_parte_diciendolo(): void
    {
        $t = $this->enviada('aprobar_salida_v1');
        $otra = $this->enviada('aprobar_salida_otra_v1');

        // Bien: 0 y nada sin comprobar.
        $this->assertSame(0, Artisan::call('ultron-studio:meta-templates-sync', ['--json' => true]));
        $r = json_decode(Artisan::output(), true);
        $this->assertSame(['ok', 2, 0], [$r['outcome'], $r['watched'], $r['unresolved']]);
        $this->assertSame(0, Artisan::call('ultron-studio:meta-templates-sync'));
        $this->assertStringNotContainsString('Sin comprobar', Artisan::output());

        // Graph (el simulador): la lista contesta sin la primera y su lectura suelta falla; la otra sale en la lista.
        $id = (string) $this->submissionOf($t)->meta_template_id;
        $componentes = T::metaPayload(json_decode($otra->content_json, true))['components'];
        $deLaOtra = ['id' => (string) $this->submissionOf($otra)->meta_template_id, 'name' => 'aprobar_salida_otra_v1', 'language' => 'es_CO', 'status' => 'APPROVED',
            'category' => 'MARKETING', 'components' => $componentes, 'quality_score' => ['score' => 'GREEN']];
        $cae = false;
        $this->metaFlags(driver: 'graph', base: 'http://127.0.0.1:8097');
        config(['meta.access_token' => 'EAAtokenDeLaPruebaDeSalida1']);
        Http::fake(function (HttpRequest $p) use (&$cae, $deLaOtra) {
            $ruta = (string) parse_url($p->url(), PHP_URL_PATH);
            if ($cae || ! str_ends_with($ruta, '/message_templates')) {
                return Http::response(['error' => ['code' => 2, 'message' => 'Service temporarily unavailable']], 500);
            }

            return Http::response(['data' => [$deLaOtra]]);
        });

        $this->assertSame(0, Artisan::call('ultron-studio:meta-templates-sync', ['--json' => true]), 'en parte: 0');
        $r = json_decode(Artisan::output(), true);
        $this->assertSame(['partial', 1], [$r['outcome'], $r['unresolved']]);
        $this->assertSame([(string) $t->uuid => 'unresolved', (string) $otra->uuid => null], array_column(array_map(static fn (array $f): array => ['uuid' => $f['uuid'], 'note' => $f['note'] ?? null], $r['templates']), 'note', 'uuid'));
        Http::assertSent(fn (HttpRequest $p): bool => str_ends_with((string) parse_url($p->url(), PHP_URL_PATH), '/'.$id));
        $this->assertSame(0, Artisan::call('ultron-studio:meta-templates-sync'));
        $texto = Artisan::output();
        $this->assertStringContainsString('PASADA HECHA EN PARTE', $texto);
        $this->assertStringContainsString('Sin comprobar en esta pasada: 1 (sale con 0', $texto);

        // Meta falla para toda la pasada: 1, en JSON y en texto, sin inventar nada.
        $cae = true;
        $filas = [count($this->checksOf($t)), count($this->checksOf($otra))];
        $this->assertSame(1, Artisan::call('ultron-studio:meta-templates-sync', ['--json' => true]));
        $r = json_decode(Artisan::output(), true);
        $this->assertSame(['meta_failed', 'server', 0], [$r['outcome'], $r['error'], $r['unresolved']]);
        $this->assertSame(1, Artisan::call('ultron-studio:meta-templates-sync'));
        $texto = Artisan::output();
        $this->assertStringContainsString('META FALLÓ', $texto);
        $this->assertStringContainsString('Sale con 1.', $texto);
        $this->assertSame(1, Artisan::call('ultron-studio:meta-templates-sync', ['--dry-run' => true]), 'en seco, lo mismo');
        $this->assertSame($filas, [count($this->checksOf($t)), count($this->checksOf($otra))]);

        // Con la sincronización apagada, 0 como siempre y sin red.
        $antes = count(Http::recorded());
        $this->metaFlags(sync: false);
        $this->assertSame(0, Artisan::call('ultron-studio:meta-templates-sync'));
        $this->assertCount($antes, Http::recorded());
    }

    public function test_v2_si_la_version_de_graph_no_conoce_rejected_reason_se_lee_otra_vez_sin_el(): void
    {
        $t = $this->approvedMeta('renovacion_v2_campos');
        $c = json_decode($t->content_json, true);
        $componentes = T::metaPayload($c)['components'];
        $this->metaFlags(driver: 'graph', base: 'http://127.0.0.1:8097');
        config(['meta.access_token' => 'EAAtokenDeLaPruebaV2']);
        Http::fake(function (HttpRequest $p) use ($componentes) {
            if ($p->method() === 'POST') {
                return Http::response(['id' => '7100000000000001', 'status' => 'PENDING', 'category' => 'MARKETING']);
            }
            parse_str((string) parse_url($p->url(), PHP_URL_QUERY), $q);
            if (str_contains((string) ($q['fields'] ?? ''), 'rejected_reason')) {
                return Http::response(['error' => ['code' => 100, 'message' => '(#100) Tried accessing nonexisting field (rejected_reason)']], 400);
            }

            return Http::response(['data' => [['id' => '7100000000000001', 'name' => 'renovacion_v2_campos', 'language' => 'es_CO', 'status' => 'APPROVED',
                'category' => 'MARKETING', 'components' => $componentes, 'quality_score' => ['score' => 'GREEN']]]]);
        });
        $this->submitMeta($t)->assertCreated();

        $p = $this->syncPass();

        $this->assertSame(['ok', 2], [$p['outcome'], $p['requests']]);
        $this->assertTrue($this->accepts($t), 'Meta devuelve los textos tal cual (V-1, con el eco de lo enviado): casa');
        Http::assertSentCount(3);
    }
}
