<?php

namespace Tests\Feature\Outreach\B9Cierre;

use App\Console\Commands\UltronStudio\TemplateMetaCheckCommand;
use App\Models\Admin;
use App\Services\Outreach\Run\OutreachPublishedStrategies;
use App\Services\UltronStudio\StudioTemplateMetaChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\W9\Support\W9World;
use Tests\TestCase;

/**
 * Cierre de B9 · W9 gancho 4: la orden `ultron-studio:template-meta-check`
 * registra lo que aprobó Meta en `ultron_studio_template_meta_checks` (solo
 * inserción, migración `2026_11_13_200001`). EN SECO por defecto (no escribe y
 * dice si el canal la aceptará); con `--aplicar`, UNA fila con los hechos de
 * Meta tal cual y la huella del texto (que nunca se imprime). Quien lo
 * comprobó: activa, con `ultron.approve` y su persona verificada. Después, el
 * canal de WhatsApp de W9 pasa su comprobación de Meta y el trabajo sale
 * (Graph falso, la puerta abierta solo con el doble de W9).
 *
 * Corre igual en SQLite y en PostgreSQL (en PG, además, los CHECK y la guarda
 * entre tablas de la migración aceptan la fila).
 */
class TemplateMetaCheckCommandTest extends TestCase
{
    use RefreshDatabase;
    use W9World;

    private const NOW = '2026-11-17 15:00:00';

    private const COMMAND = 'ultron-studio:template-meta-check';

    /** @var list<string> */
    private array $ficheros = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->ficheros as $f) {
            @unlink($f);
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Un fichero con el texto que aprobó Meta (como lo guardaría el propietario: con su salto final). */
    private function bodyFile(string $texto): string
    {
        $ruta = (string) tempnam(sys_get_temp_dir(), 'b9c-meta-');
        file_put_contents($ruta, $texto);
        $this->ficheros[] = $ruta;

        return $ruta;
    }

    /**
     * Las opciones de una comprobación que CASA con la plantilla de W9.
     *
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function metaOptions(int $templateId, array $o = []): array
    {
        return array_merge([
            '--plantilla' => (string) DB::table('ultron_studio_message_templates')->where('id', $templateId)->value('uuid'),
            '--nombre-meta' => self::META_NAME,
            '--idioma' => 'es',
            '--categoria' => 'marketing',
            '--estado' => 'APPROVED',
            '--id-meta' => '7700112233',
            '--cuerpo-fichero' => $this->bodyFile(self::BODY."\n"),
            '--por' => (string) $this->threePeople()['ana']->id,
        ], $o);
    }

    /** @param  array<string, mixed>  $o @return array<string, mixed> */
    private function commandJson(array $o): array
    {
        $codigo = Artisan::call(self::COMMAND, $o + ['--json' => true]);
        $salida = Artisan::output();
        $this->assertSame(0, $codigo, $salida);

        return json_decode($salida, true, 64, JSON_THROW_ON_ERROR);
    }

    private function checks(): int
    {
        return DB::table('ultron_studio_template_meta_checks')->count();
    }

    public function test_en_seco_no_escribe_nada_y_dice_que_el_canal_la_aceptara(): void
    {
        $plantilla = $this->whatsappTemplate();

        $this->artisan(self::COMMAND, $this->metaOptions($plantilla))
            ->expectsOutputToContain('EN SECO: no se escribe nada')
            ->expectsOutputToContain('el canal de WhatsApp ACEPTARÁ la plantilla')
            ->doesntExpectOutputToContain('tu plan vence el')
            ->assertExitCode(0);

        $this->assertSame(0, $this->checks());
    }

    public function test_con_aplicar_registra_los_hechos_de_meta_tal_cual_y_el_canal_de_w9_pasa_la_comprobacion_y_sale(): void
    {
        $p = $this->plannedWhatsappJob(self::utcAt(self::NOW), metaCheck: false);
        $puente = new OutreachPublishedStrategies;
        $t = $puente->approvedWhatsappTemplate($p['template'], (string) DB::table('ultron_studio_message_templates')->where('id', $p['template'])->value('content_hash'), 'commercial');
        $this->assertFalse($puente->metaApproved($t), 'sin comprobación de Meta el canal no envía');
        $ana = $this->threePeople()['ana'];
        $peticion = (string) Str::uuid();

        $this->artisan(self::COMMAND, $this->metaOptions($p['template'], ['--aplicar' => true, '--peticion' => $peticion]))
            ->expectsOutputToContain('REGISTRADA.')
            ->expectsOutputToContain('el canal de WhatsApp ACEPTA la plantilla')
            ->doesntExpectOutputToContain('tu plan vence el')
            ->assertExitCode(0);

        $fila = DB::table('ultron_studio_template_meta_checks')->sole();
        $this->assertSame([
            $peticion, $p['template'], (string) DB::table('ultron_studio_message_templates')->where('id', $p['template'])->value('content_hash'),
            self::META_NAME, 'es', 'marketing', hash('sha256', self::BODY), '7700112233', 'APPROVED', (int) $ana->id, 'Ana', now()->format('Y-m-d H:i:s'),
        ], [
            (string) $fila->client_request_id, (int) $fila->template_id, (string) $fila->template_content_hash,
            (string) $fila->meta_name, (string) $fila->language, (string) $fila->category, (string) $fila->meta_body_hash, (string) $fila->meta_template_id,
            (string) $fila->status, (int) $fila->recorded_by_admin_id, (string) $fila->recorded_by_name, substr((string) $fila->checked_at, 0, 19),
        ], 'el salto final del fichero no cuenta: la huella es la del texto del Studio; comprobado «ahora» (por defecto)');
        $this->assertTrue(Str::isUuid((string) $fila->uuid));
        $this->assertTrue($puente->metaApproved($t));

        // El canal de W9, con los dobles de sus pruebas: el trabajo planificado SALE por la puerta de Marketing.
        $r = $this->openDispatcher()->dispatch(now(), null, true);
        $this->assertSame(['started' => 1], $r['results'], json_encode($r));
        $this->assertSame(1, DB::table('marketing_messages')->count());
        Http::assertSentCount(1);
    }

    public function test_repetir_con_la_misma_peticion_no_escribe_otra_fila_y_con_otros_hechos_se_niega(): void
    {
        $plantilla = $this->whatsappTemplate();
        $opciones = $this->metaOptions($plantilla, ['--aplicar' => true, '--peticion' => (string) Str::uuid()]);
        $this->artisan(self::COMMAND, $opciones)->assertExitCode(0);

        $this->artisan(self::COMMAND, $opciones)->expectsOutputToContain('YA REGISTRADA')->assertExitCode(0);
        $this->assertSame(1, $this->checks());

        $this->artisan(self::COMMAND, ['--estado' => 'PAUSED'] + $opciones)->expectsOutputToContain('client_request_reused')->assertExitCode(2);
        $this->assertSame(1, $this->checks());
    }

    public function test_los_hechos_que_no_casan_se_registran_tal_cual_y_el_canal_no_la_acepta(): void
    {
        $plantilla = $this->whatsappTemplate();
        $this->artisan(self::COMMAND, $this->metaOptions($plantilla, ['--aplicar' => true]))->assertExitCode(0);

        // Meta la PAUSÓ después: una fila nueva (manda la última) y el canal deja de aceptarla.
        Carbon::setTestNow(now()->addMinute());
        $this->artisan(self::COMMAND, $this->metaOptions($plantilla, ['--estado' => 'paused', '--aplicar' => true]))
            ->expectsOutputToContain('NO la acepta (meta_status_not_approved)')
            ->assertExitCode(0);
        $this->assertSame(['APPROVED', 'PAUSED'], DB::table('ultron_studio_template_meta_checks')->orderBy('id')->pluck('status')->all());

        // Otro nombre en Meta: en seco lo dice campo a campo y nada se escribe.
        $this->artisan(self::COMMAND, $this->metaOptions($plantilla, ['--nombre-meta' => 'otra_plantilla']))
            ->expectsOutputToContain('nombre NO casa')
            ->expectsOutputToContain('NO la aceptará (meta_name_mismatch)')
            ->assertExitCode(0);
        $this->assertSame(2, $this->checks());
    }

    public function test_el_veredicto_en_seco_es_el_del_puente_tras_registrar(): void
    {
        $aprobada = $this->whatsappTemplate();
        $borrador = $this->insertTemplate(['channel' => 'whatsapp', 'purpose' => 'commercial', 'content' => [
            'bodyPreview' => self::BODY, 'category' => 'marketing', 'language' => 'es', 'metaName' => self::META_NAME, 'optOutLine' => true, 'variables' => ['first_name', 'membership_end_date_text'],
        ]]);
        $variantes = [
            'casa' => [$aprobada, []],
            'otro nombre' => [$aprobada, ['--nombre-meta' => 'otra_plantilla']],
            'otro idioma' => [$aprobada, ['--idioma' => 'es_CO']],
            'otra categoría' => [$aprobada, ['--categoria' => 'utility']],
            'otro texto' => [$aprobada, ['--cuerpo-fichero' => $this->bodyFile('Otro texto que no es el del Studio.')]],
            'rechazada en Meta' => [$aprobada, ['--estado' => 'REJECTED']],
            'desactivada en Meta' => [$aprobada, ['--estado' => 'DISABLED']],
            'borrador en el Studio' => [$borrador, []],
        ];
        foreach ($variantes as $nombre => [$plantilla, $o]) {
            Carbon::setTestNow(now()->addMinute());
            $enSeco = $this->commandJson($this->metaOptions($plantilla, $o));
            $aplicada = $this->commandJson($this->metaOptions($plantilla, $o + ['--aplicar' => true]));
            $this->assertSame('dry_run', $enSeco['mode']);
            $this->assertSame($enSeco['comparison']['channelAccepts'], $aplicada['result']['channelAccepts'], "en seco y el puente dicen lo mismo: {$nombre}");
            $this->assertSame($nombre === 'casa', $aplicada['result']['channelAccepts'], $nombre);
            $this->assertArrayNotHasKey('body', $aplicada['check'], 'el texto no viaja');
        }
        $this->assertSame(count($variantes), $this->checks());
    }

    public function test_la_lista_ensena_la_ultima_comprobacion_y_si_el_canal_la_acepta(): void
    {
        $plantilla = $this->whatsappTemplate();
        $this->artisan(self::COMMAND, ['--lista' => true])->expectsOutputToContain('sin comprobar')->assertExitCode(0);
        $this->artisan(self::COMMAND, $this->metaOptions($plantilla, ['--aplicar' => true]))->assertExitCode(0);

        Artisan::call(self::COMMAND, ['--lista' => true, '--json' => true]);
        $lista = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $lista);
        $this->assertTrue($lista[0]['channelAccepts']);
        $this->assertStringStartsWith('APPROVED · '.self::META_NAME, (string) $lista[0]['meta']);
    }

    // ── Negativas: salen con 2 y NO escriben ───────────────────────────────

    public function test_una_plantilla_que_no_existe_o_no_es_de_whatsapp(): void
    {
        $plantilla = $this->whatsappTemplate();
        $this->artisan(self::COMMAND, $this->metaOptions($plantilla, ['--plantilla' => (string) Str::uuid(), '--aplicar' => true]))->expectsOutputToContain('template_not_found')->assertExitCode(2);
        $asistida = $this->assistedTemplate();
        $this->artisan(self::COMMAND, $this->metaOptions($plantilla, ['--plantilla' => (string) DB::table('ultron_studio_message_templates')->where('id', $asistida)->value('uuid'), '--aplicar' => true]))
            ->expectsOutputToContain('template_not_whatsapp')->assertExitCode(2);
        $this->assertSame(0, $this->checks());
    }

    public function test_quien_lo_comprobo_tiene_que_estar_activa_tener_la_llave_de_aprobar_y_su_persona_verificada(): void
    {
        $plantilla = $this->whatsappTemplate();
        $recepcion = Admin::create(['name' => 'Recepción', 'email' => 'recepcion-'.Str::random(8).'@run.test', 'password' => 'secret-password', 'role' => Admin::ROLE_RECEPCION, 'status' => 'active']);
        $inactiva = Admin::create(['name' => 'Inactiva', 'email' => 'inactiva-'.Str::random(8).'@run.test', 'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'inactive']);
        $sinPersona = $this->unboundAccount();

        foreach ([
            [(string) $recepcion->id, 'forbidden'],
            [(string) $inactiva->id, 'admin_not_found'],
            [(string) $sinPersona->id, 'person_binding_required'],
            ['999999', 'admin_not_found'],
            ['nadie@run.test', 'admin_not_found'],
            ['', 'validation_failed'],
        ] as [$por, $codigo]) {
            $this->artisan(self::COMMAND, $this->metaOptions($plantilla, ['--por' => $por, '--aplicar' => true]))->expectsOutputToContain($codigo)->assertExitCode(2);
        }
        // Por su correo también vale.
        $this->artisan(self::COMMAND, $this->metaOptions($plantilla, ['--por' => (string) $this->threePeople()['beto']->email]))->expectsOutputToContain('Beto')->assertExitCode(0);
        $this->assertSame(0, $this->checks());
    }

    public function test_los_hechos_fuera_de_su_dominio_o_de_su_forma_no_se_registran(): void
    {
        $plantilla = $this->whatsappTemplate();
        foreach ([
            ['--estado' => 'ACTIVE'], ['--estado' => 'pending'], ['--idioma' => 'en_US'], ['--categoria' => 'authentication'],
            ['--nombre-meta' => 'Renovación Marzo'], ['--id-meta' => 'abc-123'], ['--plantilla' => 'no-es-un-uuid'], ['--peticion' => 'tampoco'],
            ['--comprobado-el' => '2026-11-17 14:00'], ['--comprobado-el' => '2026-11-18T15:00:00Z'],
            ['--cuerpo-fichero' => $this->bodyFile(str_repeat('a', 1025))], ['--cuerpo-fichero' => $this->bodyFile("\n")], ['--cuerpo-fichero' => $this->bodyFile("\xC3\x28 texto roto")],
        ] as $o) {
            $this->artisan(self::COMMAND, $this->metaOptions($plantilla, $o + ['--aplicar' => true]))->expectsOutputToContain('validation_failed')->assertExitCode(2);
        }
        $this->artisan(self::COMMAND, $this->metaOptions($plantilla, ['--cuerpo-fichero' => '/no/existe/b9c.txt', '--aplicar' => true]))->expectsOutputToContain('no se puede leer')->assertExitCode(2);
        $grande = $this->bodyFile(str_repeat('a', TemplateMetaCheckCommand::BODY_FILE_MAX_BYTES + 1));
        $this->artisan(self::COMMAND, $this->metaOptions($plantilla, ['--cuerpo-fichero' => $grande, '--aplicar' => true]))->expectsOutputToContain('pasa de 8192 bytes')->assertExitCode(2);
        $this->assertSame(0, $this->checks());
    }

    public function test_la_huella_del_texto_ignora_solo_el_bom_los_crlf_y_un_salto_final(): void
    {
        $esperada = hash('sha256', "Línea uno\nLínea dos");
        $this->assertSame($esperada, StudioTemplateMetaChecks::bodyHash("Línea uno\nLínea dos"));
        $this->assertSame($esperada, StudioTemplateMetaChecks::bodyHash("\u{FEFF}Línea uno\r\nLínea dos\r\n"));
        $this->assertNotSame($esperada, StudioTemplateMetaChecks::bodyHash("Línea uno\nLínea dos\n\n"), 'solo UN salto final');
        $this->assertNotSame($esperada, StudioTemplateMetaChecks::bodyHash(' Línea uno'."\nLínea dos"), 'los espacios cuentan');
        $this->assertNull(StudioTemplateMetaChecks::bodyHash('   '));
        $this->assertNull(StudioTemplateMetaChecks::bodyHash(str_repeat('ñ', 1025)));
        $this->assertSame(hash('sha256', str_repeat('ñ', 1024)), StudioTemplateMetaChecks::bodyHash(str_repeat('ñ', 1024)), '1 024 caracteres, no bytes');
    }
}
