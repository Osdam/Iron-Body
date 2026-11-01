<?php

namespace Tests\Feature\UltronStudio;

use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioCanaryNumber;
use App\Support\Privacy\PhoneFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El REGISTRO DE NÚMEROS CANARIOS por HTTP (contrato H6 §I4): la máquina de
 * estados de §I4.4 con los cuatro ojos por identidad, los topes, el reintento,
 * la bandera de escrituras, los textos fijos de los 422, el cifrado de voz que
 * se borra al revocar, la coherencia con el canario del motor (§I4.5) y la
 * expectativa frente a la versión que rige (§I4.6). Nada de esto cambia el
 * motor: el registro no lo lee nadie en H6.
 */
class UltronStudioIntegrationsCanaryTest extends TestCase
{
    use IntegrationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        // Los recorridos superan el cupo de escritura (30/min), que aquí no es lo que se prueba.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->integrationsFlags(writes: true);
    }

    public function test_el_ciclo_completo_con_dos_personas_y_el_libro_en_cada_paso(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana Registra');
        $root = $this->personWith(self::SUPER_KEYS, 'Root Autoriza');

        $r = $this->registerCanary($ana, '+57 300 000 4821')->assertCreated();
        $r->assertJsonPath('ok', true)->assertJsonPath('data.replayed', false);
        $numero = $r->json('data.number');
        $this->assertSame('pending', $numero['status']);
        $this->assertSame('Pendiente de autorizar (registro)', $numero['statusLabel']);
        $this->assertSame('+57 ••• ••• 4821', $numero['masked']);
        $this->assertSame('Móvil de pruebas 1', $numero['alias']);
        $this->assertSame(['name' => 'Ana Registra', 'role' => $ana->role], $numero['registeredBy']);
        $this->assertNull($numero['authorizedBy']);
        $this->assertSame(['allowed' => false, 'reason' => 'forbidden'], $numero['actions']['authorize'], 'Ana no tiene canary_authorize');
        $this->assertStringNotContainsString('3000004821', $r->getContent());

        $uuid = $numero['uuid'];
        $fila = StudioCanaryNumber::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(PhoneFingerprint::of('+573000004821'), $fila->phone_hash);
        $this->assertSame('4821', $fila->phone_last4);
        $this->assertSame('57', $fila->country_code);
        $this->assertNull($fila->getRawOriginal('phone_encrypted'), 'WhatsApp no guarda el número cifrado');

        $aut = $this->integrations('POST', "canaries/{$uuid}/authorize", $root)->assertOk()->json('data.number');
        $this->assertSame('authorized', $aut['status']);
        $this->assertSame('Root Autoriza', $aut['authorizedBy']['name']);
        $act = $this->integrations('POST', "canaries/{$uuid}/activate", $root)->assertOk()->json('data.number');
        $this->assertSame('active', $act['status']);
        $this->assertSame('Activo (registro)', $act['statusLabel']);
        $des = $this->integrations('POST', "canaries/{$uuid}/deactivate", $ana, ['reason' => 'Fin de la prueba de hoy'])->assertOk()->json('data.number');
        $this->assertSame('authorized', $des['status']);
        $this->assertSame('Root Autoriza', $des['activatedBy']['name'], 'la última activación se conserva');
        $rev = $this->integrations('POST', "canaries/{$uuid}/revoke", $ana, ['reason' => 'Ya no se usa este móvil'])->assertOk()->json('data.number');
        $this->assertSame('revoked', $rev['status']);
        $this->assertSame(['name' => 'Ana Registra', 'role' => $ana->role], $rev['revoked']['by']);
        $this->assertSame('Ya no se usa este móvil', $rev['revoked']['reason']);

        $eventos = StudioAuditEvent::query()->where('subject_type', 'canary_number')->where('subject_uuid', $uuid)->orderBy('id')->get();
        $this->assertSame(
            ['canary_number.registered', 'canary_number.authorized', 'canary_number.activated', 'canary_number.deactivated', 'canary_number.revoked'],
            $eventos->pluck('event')->all(),
        );
        $this->assertSame([null, 'pending', 'authorized', 'active', 'authorized'], $eventos->pluck('from_status')->all());
        $this->assertSame(['pending', 'authorized', 'active', 'authorized', 'revoked'], $eventos->pluck('to_status')->all());
        $this->assertSame(['channel' => 'whatsapp', 'expectedVersionUuid' => null], $eventos[0]->diff);
        $this->assertSame(['reason' => 'Fin de la prueba de hoy'], $eventos[3]->diff);
        $this->assertSame(['reason' => 'Ya no se usa este móvil', 'cipherErased' => false], $eventos[4]->diff);
        // Dos identidades en el libro (T1 de la fase 2).
        $this->assertSame([$ana->id, $root->id, $root->id], $eventos->take(3)->pluck('actor_admin_id')->all());
        // `GET audit` filtra por los sujetos nuevos sin tocar su controlador (§I8).
        $libro = $this->studio('GET', 'audit?subjectType=canary_number', $ana)->assertOk()->json('data');
        $this->assertSame(5, $libro['total']);
        $this->assertSame(['canary_number'], array_values(array_unique(array_column($libro['data'], 'subjectType'))));
        $this->studio('GET', 'audit?subjectType=otro', $ana)->assertStatus(422);
        foreach ($eventos as $e) {
            $this->assertStringNotContainsString('4821', json_encode([$e->summary, $e->diff]), 'el libro no lleva dígitos del teléfono');
            $this->assertStringNotContainsString('Móvil de pruebas', json_encode([$e->summary, $e->diff]), 'ni el alias');
        }
    }

    public function test_cuatro_ojos_por_identidad_al_autorizar_y_al_activar_con_el_intento_en_el_libro(): void
    {
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        $otro = $this->personWith(self::SUPER_KEYS, 'Otro Root');

        $uuid = (string) $this->registerCanary($root, '+573000000001')->assertCreated()->json('data.number.uuid');
        $r = $this->integrations('POST', "canaries/{$uuid}/authorize", $root)->assertStatus(403);
        $r->assertJsonPath('code', 'four_eyes_required')->assertJsonPath('action', 'authorize');
        // Repetir el intento no inunda el libro (una fila cada 10 minutos).
        $this->integrations('POST', "canaries/{$uuid}/authorize", $root)->assertStatus(403);
        $negados = StudioAuditEvent::query()->where('event', 'canary_number.four_eyes_denied')->get();
        $this->assertCount(1, $negados);
        $this->assertSame(['action' => 'authorize'], $negados[0]->diff);

        $this->integrations('POST', "canaries/{$uuid}/authorize", $otro)->assertOk();
        $this->integrations('POST', "canaries/{$uuid}/activate", $root)->assertStatus(403)
            ->assertJsonPath('code', 'four_eyes_required')->assertJsonPath('action', 'activate');
        $this->assertSame(['authorize', 'activate'], StudioAuditEvent::query()->where('event', 'canary_number.four_eyes_denied')->orderBy('id')->get()->pluck('diff.action')->all());
        $this->integrations('POST', "canaries/{$uuid}/activate", $otro)->assertOk()->assertJsonPath('data.number.status', 'active');
    }

    public function test_activar_basta_con_canary_manage_si_no_es_quien_registro(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $bea = $this->personWith(self::ADMIN_KEYS, 'Bea');
        $root = $this->personWith(self::SUPER_KEYS, 'Root');

        $uuid = (string) $this->registerCanary($ana, '+573000000002')->assertCreated()->json('data.number.uuid');
        $this->integrations('POST', "canaries/{$uuid}/authorize", $root)->assertOk();
        $vista = $this->integrations('GET', 'canaries', $ana)->assertOk()->json('data.numbers.data.0.actions');
        $this->assertSame(['allowed' => false, 'reason' => 'four_eyes_required'], $vista['activate']);
        $this->assertSame(['allowed' => true, 'reason' => null], $this->integrations('GET', 'canaries', $bea)->json('data.numbers.data.0.actions.activate'));
        $this->integrations('POST', "canaries/{$uuid}/activate", $bea)->assertOk()->assertJsonPath('data.number.activatedBy.name', 'Bea');
    }

    public function test_las_transiciones_desde_un_estado_que_no_las_admite_dan_409_con_el_estado_de_ahora(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        $uuid = (string) $this->registerCanary($ana, '+573000000003')->assertCreated()->json('data.number.uuid');

        $this->integrations('POST', "canaries/{$uuid}/activate", $root)->assertStatus(409)
            ->assertJsonPath('code', 'invalid_transition')->assertJsonPath('currentStatus', 'pending')->assertJsonPath('allowedFrom', ['authorized']);
        $this->integrations('POST', "canaries/{$uuid}/deactivate", $root, ['reason' => 'No estaba activo'])->assertStatus(409)
            ->assertJsonPath('currentStatus', 'pending')->assertJsonPath('allowedFrom', ['active']);
        $this->integrations('POST', "canaries/{$uuid}/authorize", $root)->assertOk();
        // Repetir la autorización: «ya estaba hecho» (currentStatus = el destino).
        $this->integrations('POST', "canaries/{$uuid}/authorize", $root)->assertStatus(409)
            ->assertJsonPath('code', 'invalid_transition')->assertJsonPath('currentStatus', 'authorized')->assertJsonPath('allowedFrom', ['pending']);
        $this->integrations('POST', "canaries/{$uuid}/revoke", $root, ['reason' => 'Revocado en la prueba'])->assertOk();
        foreach (['authorize', 'activate'] as $accion) {
            $this->integrations('POST', "canaries/{$uuid}/{$accion}", $root)->assertStatus(409)->assertJsonPath('currentStatus', 'revoked');
        }
        $this->integrations('POST', "canaries/{$uuid}/revoke", $root, ['reason' => 'Otra vez revocado'])->assertStatus(409)
            ->assertJsonPath('currentStatus', 'revoked')->assertJsonPath('allowedFrom', ['pending', 'authorized', 'active']);
        $this->integrations('POST', "canaries/{$uuid}/expectation", $root, ['versionUuid' => null])->assertStatus(409)->assertJsonPath('currentStatus', 'revoked');
        $this->integrations('POST', 'canaries/'.Str::uuid().'/authorize', $root)->assertStatus(404)->assertJsonPath('code', 'canary_not_found');
    }

    public function test_un_numero_de_voz_se_cifra_y_el_cifrado_se_borra_al_revocar_en_la_misma_sentencia(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $uuid = (string) $this->registerCanary($ana, '+57 (601) 000-0001', 'voice', 'Fijo de recepción')->assertCreated()->json('data.number.uuid');

        $crudo = DB::table('ultron_studio_canary_numbers')->where('uuid', $uuid)->value('phone_encrypted');
        $this->assertIsString($crudo);
        $this->assertStringNotContainsString('6010000001', $crudo, 'cifrado, no en claro');
        $this->assertSame('+576010000001', Crypt::decryptString($crudo));

        DB::enableQueryLog();
        $this->integrations('POST', "canaries/{$uuid}/revoke", $ana, ['reason' => 'Prueba terminada'])->assertOk();
        $actualizaciones = array_values(array_filter(DB::getQueryLog(), static fn (array $q): bool => str_starts_with(strtolower($q['query']), 'update "ultron_studio_canary_numbers"')));
        DB::disableQueryLog();
        $this->assertCount(1, $actualizaciones, 'revocar es UNA sentencia');
        $this->assertStringContainsString('"phone_encrypted" = ?', $actualizaciones[0]['query']);
        $this->assertNull(DB::table('ultron_studio_canary_numbers')->where('uuid', $uuid)->value('phone_encrypted'));
        $this->assertSame(['reason' => 'Prueba terminada', 'cipherErased' => true], StudioAuditEvent::query()->where('event', 'canary_number.revoked')->value('diff'));
    }

    public function test_el_mismo_telefono_puede_tener_una_fila_viva_por_canal_y_volver_tras_revocar(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $uuid = (string) $this->registerCanary($ana, '+573000000004')->assertCreated()->json('data.number.uuid');
        $this->registerCanary($ana, '+57 300 000 0004', alias: 'Otra función')->assertStatus(409)->assertJsonPath('code', 'canary_number_exists');
        $this->registerCanary($ana, '+573000000004', 'voice', 'El mismo en voz')->assertCreated();
        $this->integrations('POST', "canaries/{$uuid}/revoke", $ana, ['reason' => 'Se vuelve a registrar'])->assertOk();
        $this->registerCanary($ana, '+573000000004', alias: 'Vuelto a registrar')->assertCreated();
    }

    public function test_los_topes_de_vivos_y_de_activos_por_canal(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        config(['ultron_studio.integrations.canary.max_active' => 2]);

        $uuids = [];
        foreach (range(1, 10) as $i) {
            $uuids[] = (string) $this->registerCanary($ana, sprintf('+5730000001%02d', $i), alias: "Móvil {$i}")->assertCreated()->json('data.number.uuid');
        }
        $this->registerCanary($ana, '+573000000199', alias: 'El undécimo')->assertStatus(409)
            ->assertJsonPath('code', 'canary_live_limit')->assertJsonPath('max', 10)->assertJsonPath('channel', 'whatsapp');
        // El tope es por canal: en voz cabe.
        $this->registerCanary($ana, '+576010000002', 'voice', 'Fijo')->assertCreated();

        foreach (array_slice($uuids, 0, 3) as $uuid) {
            $this->integrations('POST', "canaries/{$uuid}/authorize", $root)->assertOk();
        }
        $this->integrations('POST', "canaries/{$uuids[0]}/activate", $root)->assertOk();
        $this->integrations('POST', "canaries/{$uuids[1]}/activate", $root)->assertOk();
        $this->integrations('POST', "canaries/{$uuids[2]}/activate", $root)->assertStatus(409)
            ->assertJsonPath('code', 'canary_active_limit')->assertJsonPath('max', 2)->assertJsonPath('channel', 'whatsapp');
        $this->assertSame(['allowed' => false, 'reason' => 'active_limit'],
            collect($this->integrations('GET', 'canaries?per_page=50', $root)->json('data.numbers.data'))->firstWhere('uuid', $uuids[2])['actions']['activate']);
    }

    public function test_el_reintento_devuelve_el_numero_de_la_primera_vez_y_otro_cuerpo_es_409(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $bea = $this->personWith(self::ADMIN_KEYS, 'Bea');
        $id = (string) Str::uuid();
        $cuerpo = ['clientRequestId' => $id, 'channel' => 'whatsapp', 'phone' => '+573000000005', 'alias' => 'Móvil de pruebas 5', 'consentAttested' => true];

        $primera = $this->integrations('POST', 'canaries', $ana, $cuerpo)->assertCreated()->json('data.number.uuid');
        $otra = $this->integrations('POST', 'canaries', $ana, $cuerpo + ['phone' => '+57 300 000 0005'])->assertOk();
        $otra->assertJsonPath('data.replayed', true)->assertJsonPath('data.number.uuid', $primera);
        $this->integrations('POST', 'canaries', $ana, ['alias' => 'Otra función'] + $cuerpo)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->integrations('POST', 'canaries', $bea, $cuerpo)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->assertSame(1, StudioCanaryNumber::query()->count());
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'canary_number.registered')->count());
    }

    public function test_la_forma_va_antes_que_la_bandera_y_los_textos_fijos_no_repiten_el_telefono(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $this->integrationsFlags(writes: false);

        $this->integrations('POST', 'canaries', $ana, [])->assertStatus(422);
        $casos = [
            ['+57 300 000 0001 ext. 12', 'whatsapp', 'El teléfono no tiene formato internacional (+57…).'],
            ['+1 202 555 0101', 'whatsapp', 'Ese país no está permitido para números canarios.'],
            ['+57 601 000 0001', 'whatsapp', 'Ese número no sirve para este canal (WhatsApp exige un móvil).'],
        ];
        foreach ($casos as [$telefono, $canal, $mensaje]) {
            $r = $this->registerCanary($ana, $telefono, $canal)->assertStatus(422);
            $this->assertSame([$mensaje], $r->json('errors.phone'));
            $this->assertStringNotContainsString(preg_replace('/\D/', '', $telefono), $r->getContent());
        }
        $this->registerCanary($ana, '+573000000006', alias: 'Ana 3001234567')->assertStatus(422)
            ->assertJsonPath('errors.alias.0', 'Usa una función de 3 a 40 caracteres, sin teléfonos ni correos.');
        $this->registerCanary($ana, '+573000000006', extra: ['consentAttested' => 'true'])->assertStatus(422)->assertJsonValidationErrors('consentAttested');
        $this->registerCanary($ana, '+573000000006', extra: ['consentAttested' => false])->assertStatus(422)->assertJsonValidationErrors('consentAttested');

        // Con la forma bien, la bandera: 409, sin nada en el libro.
        $this->registerCanary($ana, '+573000000006')->assertStatus(409)->assertJsonPath('code', 'integrations_writes_disabled');
        $this->assertSame(0, StudioAuditEvent::query()->count());
        $this->integrations('POST', 'canaries/'.Str::uuid().'/deactivate', $ana, [])->assertStatus(422);
        $this->integrations('POST', 'canaries/'.Str::uuid().'/deactivate', $ana, ['reason' => 'Motivo válido'])->assertStatus(409)
            ->assertJsonPath('code', 'integrations_writes_disabled');
        $this->integrations('GET', 'canaries', $ana)->assertOk()->assertJsonPath('data.limits.writesEnabled', false);
    }

    public function test_la_version_esperada_se_valida_y_la_expectativa_se_compara_con_la_cadena(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        $aprobada = $this->versionIn($ana, 'APPROVED');
        $borrador = $this->versionIn($ana, 'DRAFT');
        $rota = $this->versionIn($ana, 'CANARY', integrity: false);

        $this->registerCanary($ana, '+573000000007', extra: ['expectedVersionUuid' => (string) Str::uuid()])->assertStatus(404)->assertJsonPath('code', 'version_not_found');
        $this->registerCanary($ana, '+573000000007', extra: ['expectedVersionUuid' => $borrador->uuid])->assertStatus(409)
            ->assertJsonPath('code', 'version_not_linkable')->assertJsonPath('currentStatus', 'DRAFT');
        $this->registerCanary($ana, '+573000000007', extra: ['expectedVersionUuid' => $rota->uuid])->assertStatus(409)->assertJsonPath('code', 'integrity_mismatch');

        $numero = $this->registerCanary($ana, '+573000000007', extra: ['expectedVersionUuid' => $aprobada->uuid])->assertCreated()->json('data.number');
        $this->assertSame(['uuid' => $aprobada->uuid, 'label' => $aprobada->label, 'status' => 'APPROVED', 'statusLabel' => 'Aprobada', 'stale' => false], $numero['expectedVersion']);
        // Sin canario del motor, la fila no es el canario: la que rige no aplica.
        $this->assertSame(['source' => 'none', 'reason' => 'coherence_unreadable'], $numero['effectiveVersion'], 'sin canario del motor, isEngineCanary es null');
        $this->assertSame('not_applicable', $numero['expectation']);

        $this->integrations('POST', "canaries/{$numero['uuid']}/expectation", $root, ['versionUuid' => null])->assertOk()->assertJsonPath('data.number.expectedVersion', null)
            ->assertJsonPath('data.number.expectation', 'none');
        $this->integrations('POST', "canaries/{$numero['uuid']}/expectation", $root, [])->assertStatus(422);
        $this->assertSame(['versionUuid' => null], StudioAuditEvent::query()->where('event', 'canary_number.expectation_set')->value('diff'));

        // El canario del motor con ese teléfono: la que rige es la de la cadena (aquí, Legacy).
        $this->engineCanary('3000000007');
        $vista = $this->integrations('GET', 'canaries', $ana)->assertOk()->json('data');
        $this->assertSame('engine_canary_registered_inactive', $vista['coherence']['verdict']);
        $this->assertTrue($vista['numbers']['data'][0]['isEngineCanary']);
        $this->assertSame(['source' => 'publication_chain', 'state' => 'off'], $vista['numbers']['data'][0]['effectiveVersion']);
        $this->assertArrayNotHasKey('engineHash', $vista['coherence']);
    }

    public function test_la_coherencia_con_el_canario_del_motor_hasta_in_sync_y_la_extra_activa(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $root = $this->personWith(self::SUPER_KEYS, 'Root');

        $this->assertSame('no_engine_canary', $this->integrations('GET', 'canaries', $ana)->json('data.coherence.verdict'));
        $this->engineCanary('(300) 000-0008');
        $this->assertSame('engine_canary_not_registered', $this->integrations('GET', 'canaries', $ana)->json('data.coherence.verdict'));

        $this->activeCanary($ana, $root, '+573000000008');
        $vista = $this->integrations('GET', 'canaries', $ana)->assertOk()->json('data');
        $this->assertSame(['engineCanaryConfigured' => true, 'verdict' => 'in_sync', 'activeWhatsapp' => 1], $vista['coherence']);
        $this->assertTrue($vista['numbers']['data'][0]['isEngineCanary']);

        $this->activeCanary($ana, $root, '+573000000009', alias: 'Móvil extra');
        $vista = $this->integrations('GET', 'canaries', $ana)->assertOk()->json('data');
        $this->assertSame('registry_has_extra_active', $vista['coherence']['verdict']);
        $this->assertSame([false, true], array_column($vista['numbers']['data'], 'isEngineCanary'));
        $this->assertSame('registry_has_extra_active', collect($this->integrations('GET', 'status', $ana)->json('data.providers'))->firstWhere('id', 'canaries')['evidence']['verdict']);
    }

    public function test_la_lista_filtra_por_estado_y_canal_y_nunca_lleva_el_numero(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $a = (string) $this->registerCanary($ana, '+573000000010')->json('data.number.uuid');
        $this->registerCanary($ana, '+576010000010', 'voice', 'Fijo');
        $this->integrations('POST', "canaries/{$a}/revoke", $ana, ['reason' => 'Revocado para la lista'])->assertOk();

        $this->assertSame(1, $this->integrations('GET', 'canaries', $ana)->json('data.numbers.total'), 'por defecto, todos menos los revocados');
        $this->assertSame(2, $this->integrations('GET', 'canaries?status=all', $ana)->json('data.numbers.total'));
        $this->assertSame(1, $this->integrations('GET', 'canaries?status=revoked', $ana)->json('data.numbers.total'));
        $this->assertSame(0, $this->integrations('GET', 'canaries?channel=whatsapp', $ana)->json('data.numbers.total'));
        $this->integrations('GET', 'canaries?status=otro', $ana)->assertStatus(422);
        $this->integrations('GET', 'canaries?per_page=0', $ana)->assertStatus(422);
        $todo = $this->integrations('GET', 'canaries?status=all', $ana)->getContent();
        $this->assertStringNotContainsString('3000000010', $todo);
        $this->assertStringNotContainsString('6010000010', $todo);
        $this->assertStringNotContainsString('phone_hash', $todo);
        $this->assertStringNotContainsString(PhoneFingerprint::of('+573000000010'), $todo);
    }
}
