<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\Plan;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioVersion;
use App\Models\UltronStudio\StudioVoiceAgent;
use App\Models\UltronStudio\StudioVoiceProfile;
use App\Models\UltronStudio\StudioVoicePublication;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoicePublishedConfig;
use App\Services\UltronStudio\StudioVoiceDrift;
use App\Services\UltronStudio\StudioVoicePublications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * PT-14 · B, LA CAPTURA (diseño `plans/informes-cierre/diseno-arq-pt14.md` §3
 * D5, D6, D10–D12, §4.3, §5 B y §7 Studio 1–7): publicar la voz CAPTURA el
 * texto comercial de los planes vendibles (id, nombre y beneficios; nunca el
 * precio) con su huella y sus autores en la `evidence` de la entrada
 * `publish`, que es de solo inserción.
 *
 *  - Lo que se vio es lo que se publica: `expectedCatalogHash` → 409 `catalog_changed`.
 *  - Cuatro ojos: quien escribió el texto de un plan capturado (`by`) y quien
 *    lo puso a la venta (`soldBy`, B-1 de la revisión de security) no publican
 *    ni promueven la voz que lo recoge, sean Super Admin o no (403
 *    `four_eyes_required`, `detail: plan_text_author`). Un texto sin autor
 *    registrado no excluye a nadie. Revertir y apagar no lo miran.
 *  - La deriva del catálogo, pura y en la lectura, sin echar el freno.
 *
 * Por la API, con el desplegador falso (sin red).
 */
class UltronStudioVoiceCatalogTest extends TestCase
{
    // VoiceFixtures (la telefonía) solo para la llamada de la regresión de B-1: publicar es el del Studio.
    use PublicationFixtures, StudioVoiceFixtures, VoiceFixtures {
        PublicationFixtures::personWith insteadof VoiceFixtures;
        StudioVoiceFixtures::publishVoice insteadof VoiceFixtures;
    }
    use RefreshDatabase;
    use StudioFixtures;

    private Admin $ana;

    private Admin $root;

    private Admin $pablo;

    private Admin $paula;

    protected function setUp(): void
    {
        parent::setUp();
        // Cero red, explícito aquí (protección 3, VoiceIsolationTest): con los rasgos combinados, el rastreo no sigue a voiceSetUp().
        Http::preventStrayRequests();
        $this->voiceSetUp();
        $this->ana = $this->personWith([...self::VOICE_MANAGER, 'ultron.approve', 'ultron.edit_draft'], 'Ana Autora');
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root Revisa');
        // Quien publica previsualiza antes (como el Super Admin, que tiene las dos llaves).
        $this->pablo = $this->personWith([...self::VOICE_PUBLISHER, 'ultron.voice_manage'], 'Pablo Publica');
        $this->paula = $this->personWith([...self::VOICE_PUBLISHER, 'ultron.voice_manage'], 'Paula Promueve');
        $this->bindFake();
    }

    public function test_la_vista_previa_ensena_el_catalogo_su_huella_y_lo_que_escribiste_tu(): void
    {
        $mensual = $this->plan('Mensual', 89000, 1, ['Acceso ilimitado al gimnasio', 'Zona de pesas'], $this->ana);
        $trimestral = $this->plan('Trimestral', 239900, 2, ['Acceso ilimitado al gimnasio'], $this->root);
        $anual = $this->plan('Anual', 900000, 3, [], null);
        $this->plan('Interno', 1, 4, ['Solo mostrador'], $this->ana, ['sellable' => false]);
        // B-1: un texto sin autor registrado que Ana puso a la venta también es «suyo» para los cuatro ojos.
        $semestral = $this->plan('Semestral', 480000, 5, ['Valoración inicial'], null, [], $this->ana);
        [$v, $p] = $this->ready();

        $c = $this->preview($this->ana, $v, $p)->json('data.catalog');
        $planes = [
            ['id' => $mensual->id, 'name' => 'Mensual', 'benefits' => ['Acceso ilimitado al gimnasio', 'Zona de pesas']],
            ['id' => $trimestral->id, 'name' => 'Trimestral', 'benefits' => ['Acceso ilimitado al gimnasio']],
            ['id' => $anual->id, 'name' => 'Anual', 'benefits' => []],
            ['id' => $semestral->id, 'name' => 'Semestral', 'benefits' => ['Valoración inicial']],
        ];
        $hash = Plan::commercialCatalogHash($planes);
        $this->assertSame([
            'format' => 'ultron-voice-catalog/1',
            'hash' => $hash,
            'shortHash' => substr($hash, 0, 8),
            'plans' => [
                $planes[0] + ['authoredByYou' => true, 'authorRecorded' => true],
                $planes[1] + ['authoredByYou' => false, 'authorRecorded' => true],
                $planes[2] + ['authoredByYou' => false, 'authorRecorded' => false],
                $planes[3] + ['authoredByYou' => true, 'authorRecorded' => false],
            ],
            'fourEyes' => ['ok' => false, 'message' => 'Escribiste o pusiste a la venta el nombre o los beneficios de «Mensual» y «Semestral»: tiene que publicarla otra persona.'],
        ], $c, 'solo los vendibles, sin precio ni vigencia, y nunca quién escribió los demás');
        $this->assertStringNotContainsString('Root', (string) json_encode($c, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('239900', (string) json_encode($c));

        // A quien no escribió ninguno: cuatro ojos bien.
        $this->assertSame(['ok' => true], $this->preview($this->pablo, $v, $p)->json('data.catalog.fourEyes'));
        // El laboratorio no publica nada: sin catálogo.
        $this->assertNull($this->voice('POST', 'compile-preview', $this->ana, ['versionUuid' => $v->uuid, 'profileUuid' => $p->uuid, 'purpose' => 'lab'])
            ->assertOk()->json('data.catalog'));
        $this->assertArrayHasKey('catalog', $this->voice('POST', 'compile-preview', $this->ana, ['versionUuid' => $v->uuid, 'profileUuid' => $p->uuid, 'purpose' => 'lab'])->json('data'));
    }

    public function test_publicar_exige_la_huella_del_catalogo_vista(): void
    {
        $mensual = $this->plan('Mensual', 89000, 1, ['Acceso ilimitado al gimnasio', 'Zona de pesas'], null);
        [$v, $p] = $this->ready();
        $vista = $this->preview($this->pablo, $v, $p)->json('data.catalog.hash');
        $cuerpo = ['clientRequestId' => (string) Str::uuid(), 'versionUuid' => $v->uuid, 'profileUuid' => $p->uuid, 'expectedSpecHash' => self::specHashOf($v, $p)];

        // Sin ella (un CRM que no enseña el catálogo), no se publica.
        $this->voice('POST', 'publish', $this->pablo, $cuerpo)->assertStatus(422)->assertJsonValidationErrors('expectedCatalogHash');
        $this->voice('POST', 'publish', $this->pablo, $cuerpo + ['expectedCatalogHash' => 'no-es-una-huella'])->assertStatus(422)->assertJsonValidationErrors('expectedCatalogHash');

        // Un beneficio cambia entre la vista previa y la publicación: 409, antes de desplegar nada.
        $mensual->forceFill(['benefits' => json_encode(['Acceso ilimitado al gimnasio', 'Zona de pesas', 'Sauna'])])->save();
        $ahora = Plan::commercialCatalogHash(Plan::commercialCatalog());
        $this->voice('POST', 'publish', $this->pablo, $cuerpo + ['expectedCatalogHash' => $vista])
            ->assertStatus(409)
            ->assertExactJson(['ok' => false, 'code' => 'catalog_changed', 'expected' => $vista, 'actual' => $ahora,
                'message' => 'El catálogo de planes cambió desde que lo previsualizaste: vuelve a previsualizar y revisa lo que dirá Sofía antes de publicar.']);
        $this->assertSame(0, StudioVoiceAgent::query()->where('purpose', 'publication')->count(), 'ningún agente creado');
        $this->assertSame([], $this->fake->calls, 'ninguna petición al proveedor');
        Http::assertNothingSent();
        $this->assertSame(0, StudioVoicePublication::query()->count());

        // Con la de ahora, sí.
        $this->voice('POST', 'publish', $this->pablo, ['clientRequestId' => (string) Str::uuid()] + $cuerpo + ['expectedCatalogHash' => $ahora])->assertCreated();
    }

    public function test_publicar_guarda_el_catalogo_en_la_evidence_y_la_cadena_sigue_verificando(): void
    {
        $mensual = $this->plan('Mensual', 87654, 1, ['Acceso ilimitado al gimnasio'], $this->ana);
        $anual = $this->plan('Anual', 976543, 2, ['Acceso ilimitado al gimnasio', 'Valoración inicial'], null);
        [$v, $p] = $this->ready();
        $crid = (string) Str::uuid();
        $this->publishVoice($this->pablo, $v, $p, $crid)->assertCreated();

        $planes = [['id' => $mensual->id, 'name' => 'Mensual', 'benefits' => ['Acceso ilimitado al gimnasio']],
            ['id' => $anual->id, 'name' => 'Anual', 'benefits' => ['Acceso ilimitado al gimnasio', 'Valoración inicial']]];
        $fila = StudioVoicePublication::query()->sole();
        $this->assertSame(['number', 'catalog'], array_keys($fila->evidence));
        $this->assertSame(['format' => 'ultron-voice-catalog/1', 'hash' => Plan::commercialCatalogHash($planes), 'plans' => $planes,
            'authors' => [['planId' => $mensual->id, 'by' => (int) $this->ana->id, 'soldBy' => null], ['planId' => $anual->id, 'by' => null, 'soldBy' => null]]], $fila->evidence['catalog']);
        $cruda = (string) json_encode($fila->evidence);
        foreach (['87654', '976543', 'price', 'duration', 'precio'] as $nada) {
            $this->assertStringNotContainsString($nada, $cruda, "sin precios ni vigencias: {$nada}");
        }

        // El mensaje firmado no cambia: sus diez claves de siempre, y la cadena verifica.
        $mensaje = json_decode($fila->effective_json, true);
        $claves = array_keys($mensaje);
        sort($claves);
        $this->assertSame(VoicePublishedConfig::MESSAGE_KEYS, $claves);
        $this->assertSame(StudioVoicePublications::VERIFY_OK, StudioVoicePublications::verifyRow(StudioVoicePublications::current('canary')));

        // La telefonía lee lo que escribió el Studio.
        $leida = VoicePublishedConfig::read()['canary'];
        $this->assertSame('ok', $leida->status, (string) $leida->reason);
        $this->assertSame($planes, $leida->commercialCatalog);

        // El libro y el historial: la huella y cuántos planes, nunca la lista ni los autores.
        $evento = StudioAuditEvent::query()->where('event', 'voice_publication.published')->sole();
        $this->assertSame([Plan::commercialCatalogHash($planes), 2], [$evento->diff['catalogHash'] ?? null, $evento->diff['catalogPlans'] ?? null]);
        $h = Plan::commercialCatalogHash($planes);
        $this->assertSame(['hash' => $h, 'shortHash' => substr($h, 0, 8), 'plans' => 2],
            $this->voice('GET', 'publications', $this->pablo)->assertOk()->json('data.history.data.0.catalog'));

        // El reintento con la misma llave y la misma huella devuelve lo de la primera vez, aunque el catálogo cambie después.
        $anual->forceFill(['name' => 'Anual Plus'])->save();
        $this->publishVoice($this->pablo, $v, $p, $crid, null, null, $h)->assertOk()->assertJsonPath('replayed', true);
        $this->publishVoice($this->pablo, $v, $p, $crid, null, null, str_repeat('e', 64))->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
    }

    public function test_el_autor_del_texto_no_publica_ni_siendo_super_admin_y_otro_si(): void
    {
        $sara = $this->person(Admin::ROLE_SUPER_ADMIN, 'Sara Super');
        $mensual = $this->plan('Mensual', 89000, 1, ['Acceso ilimitado al gimnasio'], null);
        $this->plan('Anual', 900000, 2, ['Acceso ilimitado al gimnasio'], null);
        // Aprobación 1 (A): Sara cambia el nombre con su llave, por el CRM.
        $this->putJson("/api/plans/{$mensual->id}", ['name' => 'Mensual Plus'], $this->actingAsAdmin($sara))->assertOk();
        $this->assertSame((int) $sara->id, $mensual->fresh()->commercial_text_by);
        [$v, $p] = $this->ready();

        $this->assertSame(['ok' => false, 'message' => 'Escribiste o pusiste a la venta el nombre o los beneficios de «Mensual Plus»: tiene que publicarla otra persona.'],
            $this->preview($sara, $v, $p)->json('data.catalog.fourEyes'));

        // Aprobación 2 (B): la misma persona no.
        $this->publishVoice($sara, $v, $p)
            ->assertStatus(403)
            ->assertExactJson(['ok' => false, 'code' => 'four_eyes_required', 'detail' => 'plan_text_author',
                'planTextAuthor' => [['planId' => $mensual->id, 'name' => 'Mensual Plus']],
                'message' => 'Escribiste o pusiste a la venta el nombre o los beneficios de «Mensual Plus»: tiene que publicarla otra persona.']);
        $evento = StudioAuditEvent::query()->where('event', 'voice_publication.four_eyes_denied')->sole();
        $this->assertSame([$mensual->id], $evento->diff['planTextAuthor'] ?? null);
        $this->assertSame('publish', $evento->diff['action'] ?? null);
        $this->assertSame(0, StudioVoicePublication::query()->count());
        $this->assertSame([], $this->fake->calls, 'sin desplegar nada');

        // Otra persona, sí; y el texto sin autor registrado («Anual») no excluye a nadie.
        $this->publishVoice($this->pablo, $v, $p)->assertCreated();
        $this->assertSame([['planId' => $mensual->id, 'by' => (int) $sara->id, 'soldBy' => null], ['planId' => Plan::query()->where('name', 'Anual')->value('id'), 'by' => null, 'soldBy' => null]],
            StudioVoicePublication::query()->sole()->evidence['catalog']['authors']);
    }

    /** @return array<string, array{0: string}> */
    public static function manerasDePonerloALaVenta(): array
    {
        return ['«Activar» (el CRM manda solo {active: true})' => ['activar'], '«Reactivar»' => ['reactivar']];
    }

    /**
     * B-1 de la revisión de security (sonda B6): quien escribe el texto con el plan fuera de la venta y otra persona
     * con la llave lo pone a la venta sin ver el texto. Antes, ponerlo a la venta sobrescribía al autor y la que lo
     * escribió publicaba. Ahora ninguna de las dos publica (403 `plan_text_author`, el mismo de siempre) y una tercera
     * persona sí; y Sofía dice el texto que esa tercera aprobó.
     */
    #[DataProvider('manerasDePonerloALaVenta')]
    public function test_b1_ni_quien_escribio_el_texto_ni_quien_lo_puso_a_la_venta_publican_y_una_tercera_persona_si(string $via): void
    {
        $sara = $this->person(Admin::ROLE_SUPER_ADMIN, 'Sara Escribe');
        $sofia = $this->person(Admin::ROLE_SUPER_ADMIN, 'Sofía Activa');
        $plan = $this->plan('Mensual', 89000, 1, ['Acceso ilimitado al gimnasio'], null, $via === 'activar' ? ['active' => false]
            : ['active' => false, 'sellable' => false, 'archived_at' => now(), 'archived_by' => $this->root->id, 'archive_reason' => 'Fuera de temporada']);
        $texto = ['Acceso ilimitado al gimnasio', 'Entrenador personal ilimitado incluido'];

        // Sara escribe el texto con el plan fuera de la venta.
        $this->patchJson("/api/plans/{$plan->id}", ['benefits' => json_encode($texto)], $this->actingAsAdmin($sara))->assertOk();
        // Sofía, con la llave, lo pone a la venta sin tocar (ni ver) el texto.
        $via === 'activar'
            ? $this->patchJson("/api/plans/{$plan->id}", ['active' => true], $this->actingAsAdmin($sofia))->assertOk()
            : $this->postJson("/api/plans/{$plan->id}/reactivate", ['reason' => 'Vuelve a la venta'], $this->actingAsAdmin($sofia))->assertOk();
        $this->assertTrue($plan->fresh()->isSellable());
        $this->assertSame([(int) $sara->id, (int) $sofia->id], [$plan->fresh()->commercial_text_by, $plan->fresh()->commercial_sale_by]);
        [$v, $p] = $this->ready();

        foreach (['la que lo escribió' => $sara, 'la que lo puso a la venta' => $sofia] as $quien => $persona) {
            $this->assertFalse($this->preview($persona, $v, $p)->json('data.catalog.fourEyes.ok'), $quien);
            $this->publishVoice($persona, $v, $p)
                ->assertStatus(403)
                ->assertExactJson(['ok' => false, 'code' => 'four_eyes_required', 'detail' => 'plan_text_author',
                    'planTextAuthor' => [['planId' => $plan->id, 'name' => 'Mensual']],
                    'message' => 'Escribiste o pusiste a la venta el nombre o los beneficios de «Mensual»: tiene que publicarla otra persona.']);
        }
        $this->assertSame(0, StudioVoicePublication::query()->count());
        $this->assertSame([], $this->fake->calls, 'sin desplegar nada');

        // Una tercera persona, sí.
        $this->publishVoice($this->pablo, $v, $p)->assertCreated();
        $this->assertSame([['planId' => $plan->id, 'by' => (int) $sara->id, 'soldBy' => (int) $sofia->id]], StudioVoicePublication::query()->sole()->evidence['catalog']['authors']);

        // Y Sofía (la voz) dice en una llamada sellada a esa publicación el texto que la tercera aprobó.
        config(['voice.elevenlabs.tool_secret' => self::TOOL_SECRET]);
        $this->voiceCall('inbound', 'active', ['provider_conversation_id' => 'conv_b1_0001', 'provider_call_sid' => 'CA00000000000000000000000000b10001',
            'voice_publication_seq' => 1, 'provider_agent_id' => (string) StudioVoiceAgent::query()->where('serves_canary', true)->value('provider_agent_id')]);
        $this->rawPost('/api/voice/tools/consultar_planes', '{"conversation_id":"conv_b1_0001"}', ['Authorization' => 'Bearer '.self::TOOL_SECRET])
            ->assertOk()
            ->assertJsonPath('planes', [['nombre' => 'Mensual', 'condiciones' => '30 días de vigencia', 'precio' => '$ 89.000', 'beneficios' => $texto]]);
    }

    public function test_el_autor_del_catalogo_del_canario_no_promueve(): void
    {
        $mensual = $this->plan('Mensual', 89000, 1, ['Acceso ilimitado al gimnasio'], $this->paula);
        [$v, $p] = $this->ready();
        $this->publishVoice($this->pablo, $v, $p)->assertCreated();
        // Después de publicar, el texto lo «firma» otra persona (sin cambiarlo): lo que cuenta es la instantánea del canario.
        $mensual->forceFill(['commercial_text_by' => $this->root->id])->save();

        $this->promoteVoice($this->paula, str_repeat('a', 64))
            ->assertStatus(403)
            ->assertJsonPath('code', 'four_eyes_required')
            ->assertJsonPath('detail', 'plan_text_author')
            ->assertJsonPath('planTextAuthor', [['planId' => $mensual->id, 'name' => 'Mensual']])
            ->assertJsonPath('message', 'Escribiste o pusiste a la venta el nombre o los beneficios de «Mensual»: tiene que promoverla otra persona.');
        $this->assertSame('promote', StudioAuditEvent::query()->where('event', 'voice_publication.four_eyes_denied')->sole()->diff['action'] ?? null);

        // Quien figura ahora como autor, pero no en la instantánea, pasa los cuatro ojos (y se para en la evidencia).
        $this->promoteVoice($this->root, str_repeat('a', 64))->assertStatus(409)->assertJsonPath('code', 'canary_evidence_insufficient');
        $this->assertSame(0, StudioVoicePublication::query()->where('action', 'promote')->count());
    }

    public function test_revertir_y_apagar_no_miran_la_autoria(): void
    {
        $this->plan('Mensual', 89000, 1, ['Acceso ilimitado al gimnasio'], $this->paula);
        [$v, $p] = $this->ready('uno');
        [$v2, $p2] = $this->ready('dos', self::profileContent(['maxDurationSeconds' => 240]));
        $this->publishVoice($this->pablo, $v, $p)->assertCreated();
        $this->publishVoice($this->pablo, $v2, $p2)->assertCreated();

        // Van en la dirección segura: la autora del texto capturado revierte y apaga.
        $this->voice('POST', 'rollback', $this->paula, ['clientRequestId' => (string) Str::uuid(), 'scope' => 'canary', 'reason' => 'La nueva voz falla'])->assertCreated();
        $this->voice('POST', 'off', $this->paula, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Se apaga la voz'])->assertCreated();
        $this->assertSame(0, StudioAuditEvent::query()->where('event', 'voice_publication.four_eyes_denied')->count());

        // Una parte apagada no tiene agente: sin catálogo.
        $estado = $this->voice('GET', 'publications', $this->pablo)->assertOk()->json('data.state');
        $this->assertFalse($estado['canary']['serving']);
        $this->assertNull($estado['canary']['catalog']);
    }

    public function test_la_deriva_del_catalogo(): void
    {
        $mensual = $this->plan('Mensual', 89000, 1, ['Acceso ilimitado al gimnasio'], null);
        $trimestral = $this->plan('Trimestral', 239900, 2, ['Acceso ilimitado al gimnasio'], null);
        $semestral = $this->plan('Semestral', 480000, 3, ['Acceso ilimitado al gimnasio', 'Valoración inicial'], null);
        [$v, $p] = $this->ready();
        $this->publishVoice($this->pablo, $v, $p)->assertCreated();
        $h = (string) StudioVoicePublication::query()->sole()->evidence['catalog']['hash'];

        $catalogo = fn (): array => $this->voice('GET', 'publications', $this->pablo)->assertOk()->json('data.state.canary.catalog');
        $this->assertSame(['hash' => $h, 'shortHash' => substr($h, 0, 8), 'plans' => 3, 'drift' => ['outcome' => 'ok', 'changes' => []]], $catalogo());
        $this->assertNull($this->voice('GET', 'publications', $this->pablo)->json('data.state.rest'), 'la parte sin entradas, como siempre');

        // Un cambio de precio no es deriva.
        $mensual->forceFill(['price' => 99000])->save();
        $this->assertSame(['outcome' => 'ok', 'changes' => []], $catalogo()['drift']);

        // Un nombre, unos beneficios y un plan nuevo: tres cambios, en el orden del catálogo de ahora.
        $trimestral->forceFill(['name' => 'Trimestral Plus'])->save();
        $semestral->forceFill(['benefits' => json_encode(['Acceso ilimitado al gimnasio'])])->save();
        $anual = $this->plan('Anual', 900000, 4, [], null);
        $this->assertSame(['outcome' => 'changed', 'changes' => [
            ['planId' => $trimestral->id, 'name' => 'Trimestral Plus', 'change' => 'renamed'],
            ['planId' => $semestral->id, 'name' => 'Semestral', 'change' => 'benefits_changed'],
            ['planId' => $anual->id, 'name' => 'Anual', 'change' => 'new'],
        ]], $catalogo()['drift']);
        $this->assertSame($h, $catalogo()['hash'], 'la huella es la de lo publicado');
        $this->assertFalse(VoiceBrake::isOpen(), 'la deriva del catálogo no echa el freno');

        // Sin catálogo publicado (una publicación anterior a PT-14): `missing`.
        $this->assertSame(['outcome' => 'missing', 'changes' => []], StudioVoiceDrift::catalogVerdict(null, Plan::commercialCatalog()));
        $this->assertSame(['outcome' => 'ok', 'changes' => []], StudioVoiceDrift::catalogVerdict([], []), 'un catálogo publicado vacío frente a ninguno vendible');
    }

    // ────────────────────────────────────────────────────────────────────────

    /** @return array{0:StudioVersion,1:StudioVoiceProfile} una versión y un perfil aprobados por dos personas, con su veredicto del laboratorio */
    private function ready(string $etiqueta = 'Voz', ?array $contenido = null): array
    {
        $v = $this->approvedVersion($this->ana, $this->root, "Versión {$etiqueta}");
        $p = $this->approvedProfile($this->ana, $this->root, $contenido, "Perfil {$etiqueta}");
        $this->labVerdictFor($v, $p, $this->ana, $this->root);

        return [$v, $p];
    }

    private function preview(Admin $as, StudioVersion $v, StudioVoiceProfile $p): TestResponse
    {
        return $this->voice('POST', 'compile-preview', $as, ['versionUuid' => $v->uuid, 'profileUuid' => $p->uuid])->assertOk();
    }

    /**
     * @param  list<string>  $beneficios
     * @param  array<string, mixed>  $o  columnas (también las del archivo)
     * @param  Admin|null  $vendedor  quien lo puso a la venta (`commercial_sale_by`)
     */
    private function plan(string $nombre, int $precio, int $orden, array $beneficios, ?Admin $autor, array $o = [], ?Admin $vendedor = null): Plan
    {
        $archivo = array_intersect_key($o, array_flip(['archived_at', 'archived_by', 'archive_reason']));
        $plan = Plan::query()->create(array_merge(['name' => $nombre, 'price' => $precio, 'duration_days' => 30, 'active' => true, 'sellable' => true,
            'sort_order' => $orden, 'benefits' => json_encode($beneficios)], array_diff_key($o, $archivo)));
        $plan->forceFill(['commercial_text_by' => $autor?->id, 'commercial_sale_by' => $vendedor?->id] + $archivo)->save();

        return $plan;
    }
}
