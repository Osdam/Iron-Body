<?php

namespace Tests\Feature\UltronStudio\MetaTemplates;

use App\Models\Admin;
use App\Models\UltronStudio\StudioMessageTemplate;
use App\Services\Meta\Templates\FakeWhatsappTemplateClient;
use App\Services\UltronStudio\StudioMessageTemplates as T;
use App\Services\UltronStudio\StudioTemplateMetaChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\UltronStudio\Strategies\StrategyFixtures;
use Tests\TestCase;

/**
 * PLANTILLAS DE META · PASO 2 (PLANTILLAS_META_PLAN §5.3 y §5.6; enmienda de la
 * protección 12 en L9-96): el cuarto cerrojo de W9 mira además la HUELLA DE LOS
 * COMPONENTES.
 *
 *  - una fila manual (sin huella de componentes) de una plantilla de solo
 *    cuerpo se acepta como hasta hoy; de una plantilla CON componentes, no;
 *  - una fila `meta_api` con la huella buena se acepta; con otra (los botones o
 *    el pie editados en el administrador de Meta), no;
 *  - manda la última fila (aprobada → pausada → aprobada);
 *  - el servicio (`compare()`) y el puente (`channelAccepts()`) dicen lo mismo;
 *  - la orden manual se niega con componentes (`components_require_meta_sync`)
 *    y con un envío vivo (`meta_sync_owns_template`), sin escribir; con un
 *    envío FALLIDO, no.
 * Lo que ya existía (W9, B9) no cambia: lo prueban sus propias suites, sin
 * tocar una aserción.
 */
class MetaTemplateW9AmendmentTest extends TestCase
{
    use RefreshDatabase;
    use StrategyFixtures;

    private const SOLO_CUERPO = [
        'metaName' => 'renovacion_cuerpo_v1', 'language' => 'es_CO', 'category' => 'marketing',
        'bodyPreview' => 'Hola {{1}}, tu plan vence pronto. Si no quieres más mensajes, responde BAJA.',
        'variables' => ['first_name'], 'examples' => ['first_name' => 'Laura'], 'optOutLine' => true,
    ];

    private const CON_COMPONENTES = [
        'metaName' => 'renovacion_botones_v1', 'language' => 'es_CO', 'category' => 'marketing',
        'header' => ['format' => 'TEXT', 'text' => 'Tu plan está por vencer'],
        'bodyPreview' => 'Hola {{1}}, tu plan vence pronto. Si no quieres más mensajes, responde BAJA.',
        'variables' => ['first_name'], 'examples' => ['first_name' => 'Laura'],
        'footer' => ['text' => 'Escribe BAJA para no recibir más mensajes'],
        'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Quiero renovar'], ['type' => 'QUICK_REPLY', 'text' => 'Darme de baja', 'role' => 'opt_out']],
        'optOutLine' => true,
    ];

    private Admin $ana;

    private Admin $beto;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->grantStrategyPermissions();
        $this->strategyFlags(enabled: true);
        [$this->ana, $this->beto] = $this->bootstrapPair();
    }

    /** @param  array<string, mixed>  $contenido */
    private function aprobada(array $contenido, string $clave = 'renovacion-meta'): StudioMessageTemplate
    {
        return $this->approvedTemplate($this->ana, $this->beto, 'whatsapp', 'commercial', $contenido, $clave);
    }

    /** @return array<string, mixed> */
    private static function contenido(StudioMessageTemplate $t): array
    {
        return json_decode((string) $t->content_json, true);
    }

    /**
     * Una comprobación de Meta: por defecto, la que casa con la plantilla y SIN huella de componentes (manual).
     *
     * @param  array<string, mixed>  $o
     * @return array<string, mixed> la fila escrita
     */
    private function fila(StudioMessageTemplate $t, array $o = []): array
    {
        $c = self::contenido($t);
        $fila = array_merge([
            'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(),
            'template_id' => (int) $t->id, 'template_content_hash' => (string) $t->content_hash,
            'meta_name' => $c['metaName'], 'language' => $c['language'], 'category' => $c['category'],
            'meta_body_hash' => hash('sha256', $c['bodyPreview']), 'meta_template_id' => '1234567890', 'status' => 'APPROVED',
            'checked_at' => now()->subMinute()->format('Y-m-d H:i:s'), 'recorded_by_admin_id' => (int) $this->beto->id, 'recorded_by_name' => 'Beto Revisor',
            'created_at' => now()->format('Y-m-d H:i:s'),
        ], $o);
        DB::table(StudioTemplateMetaChecks::TABLE)->insert($fila);

        return $fila;
    }

    /** Lo que dicen el servicio (en seco) y el puente (tras registrar) de la última fila. */
    private function veredictos(StudioMessageTemplate $t, array $fila): array
    {
        $plantilla = DB::table(StudioTemplateMetaChecks::TEMPLATES)->where('id', $t->id)->first();
        $comparacion = StudioTemplateMetaChecks::compare($fila, $plantilla, self::contenido($t));

        return [$comparacion['channelAccepts'], app(StudioTemplateMetaChecks::class)->channelAccepts((int) $t->id), $comparacion['reasons']];
    }

    public function test_una_fila_manual_de_una_plantilla_de_solo_cuerpo_se_acepta_como_hasta_hoy(): void
    {
        $t = $this->aprobada(self::SOLO_CUERPO);
        $this->assertFalse(T::hasComponents(self::contenido($t)));

        [$servicio, $puente, $motivos] = $this->veredictos($t, $this->fila($t));

        $this->assertTrue($puente);
        $this->assertTrue($servicio);
        $this->assertSame([], $motivos);
    }

    public function test_una_fila_manual_de_una_plantilla_con_componentes_no_se_acepta(): void
    {
        $t = $this->aprobada(self::CON_COMPONENTES);

        [$servicio, $puente, $motivos] = $this->veredictos($t, $this->fila($t));

        $this->assertFalse($puente, 'la orden manual no sabe leer los componentes: falla cerrado');
        $this->assertFalse($servicio);
        $this->assertSame(['components_mismatch'], $motivos);
    }

    public function test_una_fila_meta_api_con_la_huella_buena_se_acepta_y_con_otra_no(): void
    {
        $t = $this->aprobada(self::CON_COMPONENTES);
        $buena = T::componentsFingerprint(self::contenido($t));
        // Lo que Meta enseñaría con el pie editado en su administrador (la misma normalización que la sincronización).
        $editada = T::metaComponentsFingerprint([
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Tu plan está por vencer'],
            ['type' => 'BODY', 'text' => self::CON_COMPONENTES['bodyPreview']],
            ['type' => 'FOOTER', 'text' => FakeWhatsappTemplateClient::EDITED_FOOTER],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Quiero renovar'], ['type' => 'QUICK_REPLY', 'text' => 'Darme de baja']]],
        ]);
        $this->assertNotSame($buena, $editada);

        $this->assertSame([true, true, []], $this->veredictos($t, $this->fila($t, ['source' => 'meta_api', 'meta_components_hash' => $buena])));
        $this->assertSame([false, false, ['components_mismatch']], $this->veredictos($t, $this->fila($t, ['source' => 'meta_api', 'meta_components_hash' => $editada])), 'manda la última: la editada');

        // Y una plantilla de solo cuerpo con una fila de la API: la huella de «sin componentes» casa; otra no.
        $s = $this->aprobada(self::SOLO_CUERPO, 'renovacion-cuerpo');
        $sinNada = T::metaComponentsFingerprint([['type' => 'BODY', 'text' => self::SOLO_CUERPO['bodyPreview']]]);
        $this->assertSame(T::componentsFingerprint(self::contenido($s)), $sinNada);
        $this->assertSame([true, true, []], $this->veredictos($s, $this->fila($s, ['source' => 'meta_api', 'meta_components_hash' => $sinNada])));
        $this->assertSame([false, false, ['components_mismatch']], $this->veredictos($s, $this->fila($s, ['source' => 'meta_api', 'meta_components_hash' => $buena])), 'Meta tiene botones que el Studio no aprobó');
    }

    public function test_manda_la_ultima_fila_aprobada_pausada_y_aprobada_otra_vez(): void
    {
        $t = $this->aprobada(self::CON_COMPONENTES);
        $huella = T::componentsFingerprint(self::contenido($t));
        $api = ['source' => 'meta_api', 'meta_components_hash' => $huella];

        $this->assertTrue($this->veredictos($t, $this->fila($t, $api))[1]);
        [$servicio, $puente, $motivos] = $this->veredictos($t, $this->fila($t, $api + ['status' => 'PAUSED']));
        $this->assertSame([false, false, ['meta_status_not_approved']], [$servicio, $puente, $motivos]);
        $this->assertTrue($this->veredictos($t, $this->fila($t, $api))[1]);
    }

    public function test_el_servicio_y_el_puente_dicen_lo_mismo_en_toda_la_matriz(): void
    {
        $con = $this->aprobada(self::CON_COMPONENTES);
        $solo = $this->aprobada(self::SOLO_CUERPO, 'renovacion-cuerpo');
        $huellaCon = T::componentsFingerprint(self::contenido($con));
        $huellaSolo = T::componentsFingerprint(self::contenido($solo));
        $otra = hash('sha256', 'otra cosa');

        $casos = [];
        foreach (['con' => [$con, $huellaCon], 'solo' => [$solo, $huellaSolo]] as $nombre => [$t, $huella]) {
            foreach ([
                'manual' => [],
                'api buena' => ['source' => 'meta_api', 'meta_components_hash' => $huella],
                'api otra' => ['source' => 'meta_api', 'meta_components_hash' => $otra],
                'api buena pausada' => ['source' => 'meta_api', 'meta_components_hash' => $huella, 'status' => 'PAUSED'],
                'api buena pendiente' => ['source' => 'meta_api', 'meta_components_hash' => $huella, 'status' => 'PENDING'],
                'api buena no encontrada' => ['source' => 'meta_api', 'meta_components_hash' => $huella, 'status' => 'NOT_FOUND'],
                'api buena recategorizada' => ['source' => 'meta_api', 'meta_components_hash' => $huella, 'category' => 'utility'],
                'api buena a autenticación' => ['source' => 'meta_api', 'meta_components_hash' => $huella, 'category' => 'authentication'],
                'api con otro texto' => ['source' => 'meta_api', 'meta_components_hash' => $huella, 'meta_body_hash' => $otra],
            ] as $caso => $o) {
                [$servicio, $puente] = $this->veredictos($t, $this->fila($t, $o));
                $casos["{$nombre} · {$caso}"] = $puente;
                $this->assertSame($servicio, $puente, "{$nombre} · {$caso}: el servicio y el puente no dicen lo mismo");
            }
        }
        $this->assertSame(['con · api buena', 'solo · manual', 'solo · api buena'], array_keys(array_filter($casos)), 'solo esas aceptan');
    }

    // ── La orden manual (§5.6) ──────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function opcionesDeLaOrden(StudioMessageTemplate $t, string $cuerpo): array
    {
        $c = self::contenido($t);
        $fichero = tempnam(sys_get_temp_dir(), 'tpl-meta-');
        file_put_contents($fichero, $cuerpo);

        return [
            '--plantilla' => (string) $t->uuid, '--nombre-meta' => $c['metaName'], '--idioma' => $c['language'], '--categoria' => $c['category'],
            '--estado' => 'APPROVED', '--cuerpo-fichero' => $fichero, '--por' => (string) $this->beto->id, '--json' => true,
        ];
    }

    private function envio(StudioMessageTemplate $t, string $estado = 'SENDING'): int
    {
        $c = self::contenido($t);
        $persona = DB::table('ultron_studio_person_bindings')->where('admin_id', $this->ana->id)->whereNotNull('verified_at')->whereNull('revoked_at')->value('identity_id');
        $id = (int) DB::table(StudioTemplateMetaChecks::SUBMISSIONS)->insertGetId([
            'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'template_id' => (int) $t->id, 'template_content_hash' => (string) $t->content_hash,
            'meta_name' => $c['metaName'], 'language' => $c['language'], 'category' => $c['category'], 'request_hash' => hash('sha256', 'x'), 'state' => 'SENDING',
            'attester_unavailable' => false, 'submitted_by_admin_id' => (int) $this->ana->id, 'submitted_by_person_id' => (int) $persona,
            'submitted_by_name' => 'Ana Autora', 'submitted_at' => now(), 'created_at' => now(),
        ]);
        if ($estado !== 'SENDING') {
            DB::table(StudioTemplateMetaChecks::SUBMISSIONS)->where('id', $id)->update(['state' => $estado, 'updated_at' => now()]);
        }

        return $id;
    }

    public function test_la_orden_manual_se_niega_con_componentes_sin_escribir_tambien_con_aplicar(): void
    {
        $t = $this->aprobada(self::CON_COMPONENTES);
        $opciones = $this->opcionesDeLaOrden($t, self::CON_COMPONENTES['bodyPreview']);

        foreach ([[], ['--aplicar' => true]] as $mas) {
            $this->artisan('ultron-studio:template-meta-check', $opciones + $mas)->expectsOutputToContain('components_require_meta_sync')->assertExitCode(2);
        }
        $this->assertSame(0, DB::table(StudioTemplateMetaChecks::TABLE)->count());
    }

    public function test_la_orden_manual_se_niega_con_un_envio_vivo_y_no_con_uno_fallido(): void
    {
        $t = $this->aprobada(self::SOLO_CUERPO);
        $opciones = $this->opcionesDeLaOrden($t, self::SOLO_CUERPO['bodyPreview']);
        $envio = $this->envio($t);

        $this->artisan('ultron-studio:template-meta-check', $opciones + ['--aplicar' => true])->expectsOutputToContain('meta_sync_owns_template')->assertExitCode(2);
        $this->assertSame(0, DB::table(StudioTemplateMetaChecks::TABLE)->count());

        DB::table(StudioTemplateMetaChecks::SUBMISSIONS)->where('id', $envio)->update(['state' => 'FAILED', 'updated_at' => now()]);
        $this->artisan('ultron-studio:template-meta-check', $opciones + ['--aplicar' => true])->assertExitCode(0);
        $this->assertSame(['manual'], DB::table(StudioTemplateMetaChecks::TABLE)->pluck('source')->all(), 'la fila de la orden entra como manual, sin huella de componentes');
        $this->assertNull(DB::table(StudioTemplateMetaChecks::TABLE)->value('meta_components_hash'));
        $this->assertTrue(app(StudioTemplateMetaChecks::class)->channelAccepts((int) $t->id));
    }
}
