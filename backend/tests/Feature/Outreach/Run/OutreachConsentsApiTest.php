<?php

namespace Tests\Feature\Outreach\Run;

use App\Http\Controllers\Crm\CommercialAutomation\OutreachComplaintController;
use App\Http\Controllers\Crm\CommercialAutomation\OutreachConsentController;
use App\Http\Middleware\LimitRequestBodySize;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\RolePermission;
use App\Services\Outreach\Facts\CoreProjection;
use App\Services\Outreach\Facts\PhoneRef;
use App\Services\Outreach\Facts\SubjectKey;
use App\Services\Outreach\Facts\SubjectRef;
use App\Services\Outreach\Run\OutreachAccess;
use App\Support\Access\AuthorizationMap;
use App\Support\Access\CrmPermission;
use App\Support\Access\PermissionCatalog;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use PgSql\Connection;
use Tests\Feature\Outreach\Facts\Support\FactsWorld;
use Tests\Feature\Outreach\Schema\OutreachConsentFixtures;
use Tests\Feature\Outreach\Schema\OutreachSchemaFixtures;
use Tests\TestCase;

/**
 * C9-3 y C9-8 · LAS RUTAS de las autorizaciones de contacto
 * (`/api/admin/commercial-automation/consents…`), por HTTP y con las clases
 * reales: la puerta de `AuthorizationMap`, una persona, la llave, la llave de
 * VER a la persona (BOLA), el texto legal (falla cerrado), los cuatro ojos por
 * cuenta, la idempotencia, la validación de la evidencia (nunca un 500 por un
 * CHECK: también en PostgreSQL) y que el motor (C9-2) lee lo que se registra.
 * En PostgreSQL, además: el ciclo entero como el rol de la aplicación (solo
 * SELECT e INSERT en el libro) y las dos carreras con dos conexiones reales.
 */
class OutreachConsentsApiTest extends TestCase
{
    use FactsWorld;
    use OutreachConsentFixtures;
    use OutreachSchemaFixtures;
    use RefreshDatabase;

    private const NOW = '2026-11-22 16:00:00';

    private const BASE = '/api/admin/commercial-automation/';

    private const PHONE = '3001110001';

    private const LEGAL_VERSION = 'c9-whatsapp-comercial/2026-11-22.1';

    private const LEGAL_TEXT = 'Autorizo a Iron Body a escribirme por WhatsApp con ofertas y novedades. Puedo retirarlo cuando quiera respondiendo BAJA.';

    private const RACE = 'c9_carreras';

    private const ALL = [OutreachAccess::CONSENTS_RECORD, OutreachAccess::CONSENTS_CONFIRM, 'members.view', 'marketing.view'];

    /** @var array<string, array{0: Admin, 1: array<string, string>}> */
    private array $cuentas = [];

    private bool $carrera = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['outreach_consents.legal.commercial' => ['version' => self::LEGAL_VERSION, 'text' => self::LEGAL_TEXT]]);
    }

    protected function tearDown(): void
    {
        foreach ($this->pg as $conexion) {
            @pg_query($conexion, 'ROLLBACK');
            pg_close($conexion);
        }
        $this->pg = [];
        if ($this->carrera) {
            // Primero se deshace la transacción de la prueba: sus escrituras toman cerrojos sobre las filas confirmadas
            // (la FK de una confirmación a su petición) y el borrado de la conexión de apoyo esperaría para siempre.
            while (DB::connection()->transactionLevel() > 0) {
                DB::connection()->rollBack();
            }
            $c = DB::connection(self::RACE);
            $c->statement('SET session_replication_role = replica');
            try {
                $c->table('outreach_consents')->where('id', '>', 0)->delete();
            } finally {
                $c->statement('SET session_replication_role = DEFAULT');
            }
            DB::purge(self::RACE);
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Las rutas, las llaves y los roles ───────────────────────────────────

    public function test_las_5_rutas_con_su_llave_en_authorization_map_su_tope_y_sus_cupos(): void
    {
        $this->assertCount(OutreachConsentController::ROUTE_COUNT, OutreachConsentController::ROUTES);
        $registradas = [];
        foreach (Route::getRoutes() as $r) {
            if (str_starts_with($r->uri(), 'api/admin/commercial-automation/')) {
                $registradas[AuthorizationMap::routeKey($r)] = $r;
            }
        }
        $mapa = OutreachConsentController::authorizationMap();
        $this->assertSame(array_keys($mapa), array_keys(array_intersect_key($mapa, $registradas)));
        // Q1-8 (Enmienda Q1-8): bajo el mismo prefijo vive también la ruta de registrar una queja (OutreachComplaintsApiTest).
        $this->assertCount(OutreachConsentController::ROUTE_COUNT + OutreachComplaintController::ROUTE_COUNT, $registradas, 'ninguna ruta de más bajo el prefijo');
        foreach ($mapa as $clave => $llave) {
            $this->assertSame($llave, AuthorizationMap::resolve($registradas[$clave]), $clave);
            $mw = $registradas[$clave]->gatherMiddleware();
            $this->assertContains(LimitRequestBodySize::class.':16384', $mw, $clave);
            $this->assertContains(str_starts_with($clave, 'GET') ? 'throttle:ultron-studio-read' : 'throttle:ultron-studio-write', $mw, $clave);
        }
        $this->assertSame('uuid', array_key_first($registradas['POST api/admin/commercial-automation/consents/{uuid}/confirm']->wheres));
    }

    public function test_las_dos_llaves_nuevas_estan_en_el_catalogo_solo_para_super_admin_y_fuera_del_token_compartido(): void
    {
        foreach ([OutreachAccess::CONSENTS_RECORD, OutreachAccess::CONSENTS_CONFIRM] as $llave) {
            $this->assertContains($llave, PermissionCatalog::all());
            $this->assertContains($llave, CrmPermission::defaultsFor(Admin::ROLE_SUPER_ADMIN));
            foreach ([Admin::ROLE_ADMINISTRADOR, Admin::ROLE_RECEPCION, Admin::ROLE_ADMINISTRATIVO, CrmPermission::ROLE_ENTRENADOR] as $rol) {
                $this->assertNotContains($llave, CrmPermission::defaultsFor($rol), "{$llave} en {$rol}");
            }
            $this->assertStringContainsString('No llega por defecto al Administrador', (string) array_column(PermissionCatalog::rows(), null, 'key')[$llave]['help']);
        }
        $this->assertContains('commercial_automation', CrmPermission::SHARED_TOKEN_EXCLUDED_DOMAINS);
        $p = $this->socioConWhatsapp();
        $this->getJson(self::BASE.'consents?personType=member&personId='.$p['member'], $this->sharedTokenHeaders())->assertStatus(403);
    }

    public function test_cada_ruta_exige_su_llave_y_la_de_ver_a_la_persona_sin_ser_un_oraculo(): void
    {
        $p = $this->socioConWhatsapp();
        $lista = self::BASE.'consents?personType=member&personId=';
        // Sin ninguna de las dos llaves: la puerta.
        $this->getJson($lista.$p['member'], $this->cuenta('Sin', ['members.view']))->assertStatus(403)->assertJsonPath('code', 'forbidden');
        // Con la llave pero sin ver socios: 403 ANTES de leer la persona; igual exista o no (no es un oráculo).
        foreach ([$p['member'], 999999] as $id) {
            $this->getJson($lista.$id, $this->cuenta('Ciega', [OutreachAccess::CONSENTS_RECORD]))->assertStatus(403)
                ->assertJsonPath('code', 'forbidden')->assertJsonPath('required_permission', 'members.view');
        }
        // Con las dos: la que no existe, 404.
        $this->getJson($lista.'999999', $this->cuenta('Ana', self::ALL))->assertStatus(404)->assertJsonPath('code', 'person_not_found');
        // Un prospecto de un socio es el socio: ver prospectos no basta.
        $this->getJson(self::BASE.'consents?personType=lead&personId='.$p['lead'], $this->cuenta('Mercadeo', [OutreachAccess::CONSENTS_RECORD, 'marketing.view']))
            ->assertStatus(403)->assertJsonPath('required_permission', 'members.view');
        // Pedir exige registrar; confirmar y rechazar, confirmar.
        $this->postJson(self::BASE.'consents', $this->peticion($p), $this->cuenta('SoloConfirma', [OutreachAccess::CONSENTS_CONFIRM, 'members.view']))->assertStatus(403);
        $pet = $this->postJson(self::BASE.'consents', $this->peticion($p), $this->cuenta('Ana', self::ALL))->assertCreated()->json('data.uuid');
        $this->postJson(self::BASE."consents/{$pet}/confirm", ['clientRequestId' => (string) Str::uuid()], $this->cuenta('SoloRegistra', [OutreachAccess::CONSENTS_RECORD, 'members.view']))->assertStatus(403);
        // Confirmar una autorización de una persona que no puede ver: 404, como si no existiera.
        $this->postJson(self::BASE."consents/{$pet}/confirm", ['clientRequestId' => (string) Str::uuid()], $this->cuenta('ConfirmaCiega', [OutreachAccess::CONSENTS_CONFIRM]))
            ->assertStatus(404)->assertJsonPath('code', 'consent_not_found');
        $this->postJson(self::BASE.'consents/'.Str::uuid().'/confirm', ['clientRequestId' => (string) Str::uuid()], $this->cuenta('Beto', self::ALL))
            ->assertStatus(404)->assertJsonPath('code', 'consent_not_found');
        // Retirar: basta UNA de las dos.
        $this->postJson(self::BASE.'consents/revoke', $this->retirada($p), $this->cuenta('SoloConfirma', [OutreachAccess::CONSENTS_CONFIRM, 'members.view']))->assertCreated();
    }

    // ── El texto legal: falla cerrado ───────────────────────────────────────

    public function test_sin_texto_legal_pedir_y_confirmar_dan_409_y_no_escriben_pero_rechazar_y_retirar_si(): void
    {
        $p = $this->socioConWhatsapp();
        $pet = $this->postJson(self::BASE.'consents', $this->peticion($p), $this->cuenta('Ana', self::ALL))->assertCreated()->json('data.uuid');
        $antes = DB::table('outreach_consents')->count();

        config(['outreach_consents.legal.commercial' => ['version' => null, 'text' => null]]);
        // Antes de validar el cuerpo: también con un cuerpo vacío.
        $this->postJson(self::BASE.'consents', [], $this->cuenta('Ana', self::ALL))->assertStatus(409)->assertJsonPath('code', 'consent_legal_text_missing');
        $this->postJson(self::BASE.'consents', $this->peticion($p), $this->cuenta('Ana', self::ALL))->assertStatus(409)->assertJsonPath('code', 'consent_legal_text_missing');
        $this->postJson(self::BASE."consents/{$pet}/confirm", ['clientRequestId' => (string) Str::uuid()], $this->cuenta('Beto', self::ALL))
            ->assertStatus(409)->assertJsonPath('code', 'consent_legal_text_missing');
        $this->assertSame($antes, DB::table('outreach_consents')->count(), 'no escribe nada');
        // La lista lo dice (sin texto: null), y rechazar y retirar siguen funcionando.
        $this->getJson(self::BASE.'consents?personType=member&personId='.$p['member'], $this->cuenta('Beto', self::ALL))->assertOk()
            ->assertJsonPath('data.purposes.commercial.legal', null);
        $this->postJson(self::BASE."consents/{$pet}/reject", ['clientRequestId' => (string) Str::uuid(), 'evidence' => 'La firma no coincide con la del contrato'], $this->cuenta('Beto', self::ALL))->assertCreated();
        $this->postJson(self::BASE.'consents/revoke', $this->retirada($p), $this->cuenta('Beto', self::ALL))->assertCreated();

        // Una versión o un texto que la base no admitiría cuentan como «sin texto».
        foreach ([['version' => 'con espacios', 'text' => 'x'], ['version' => self::LEGAL_VERSION, 'text' => "con\x07control"], ['version' => self::LEGAL_VERSION, 'text' => '  ']] as $mal) {
            config(['outreach_consents.legal.commercial' => $mal]);
            $this->postJson(self::BASE.'consents', $this->peticion($p), $this->cuenta('Ana', self::ALL))->assertStatus(409)->assertJsonPath('code', 'consent_legal_text_missing');
        }
    }

    public function test_el_texto_legal_viaja_en_la_lista_y_en_pedir_y_si_cambia_hay_que_volver_a_enseñarlo(): void
    {
        $p = $this->socioConWhatsapp();
        $legal = ['version' => self::LEGAL_VERSION, 'text' => self::LEGAL_TEXT, 'hash' => hash('sha256', self::LEGAL_TEXT)];
        $this->getJson(self::BASE.'consents?personType=member&personId='.$p['member'], $this->cuenta('Ana', self::ALL))->assertOk()
            ->assertJsonPath('data.purposes.commercial.legal', $legal)->assertJsonPath('data.purposes.collections.legal', null)
            ->assertJsonPath('data.person.recordsAs', ['type' => 'member', 'id' => $p['member']])->assertJsonPath('data.whatsappIdentity', true)
            ->assertJsonPath('data.can', ['record' => true, 'confirm' => true]);
        $r = $this->postJson(self::BASE.'consents', $this->peticion($p), $this->cuenta('Ana', self::ALL))->assertCreated();
        $r->assertJsonPath('meta.legal', $legal)->assertJsonPath('data.legalVersion', self::LEGAL_VERSION)->assertJsonPath('data.legalHash', $legal['hash']);

        // Otra versión del texto: lo que la pantalla enseñó ya no es lo vigente.
        $otro = self::LEGAL_TEXT.' (versión 2)';
        config(['outreach_consents.legal.commercial' => ['version' => 'c9-whatsapp-comercial/2026-12-01.1', 'text' => $otro]]);
        $this->postJson(self::BASE.'consents', $this->peticion($this->socioConWhatsapp([], '3001110002')), $this->cuenta('Ana', self::ALL))
            ->assertStatus(409)->assertJsonPath('code', 'consent_legal_text_changed')->assertJsonPath('legal.hash', hash('sha256', $otro));
        // Y la petición registrada con el texto anterior ya no se confirma.
        $this->postJson(self::BASE.'consents/'.$r->json('data.uuid').'/confirm', ['clientRequestId' => (string) Str::uuid()], $this->cuenta('Beto', self::ALL))
            ->assertStatus(409)->assertJsonPath('code', 'consent_legal_text_changed');
    }

    // ── El flujo, hasta el motor ────────────────────────────────────────────

    public function test_pedir_confirmar_con_otra_cuenta_y_retirar_y_el_motor_lo_lee(): void
    {
        Log::spy();
        $p = $this->socioConWhatsapp();
        $this->assertSame('missing', $this->motor($p['key']));
        [$ana] = $this->cuentaCon('Ana', self::ALL);
        [$beto] = $this->cuentaCon('Beto', self::ALL);

        $pet = $this->postJson(self::BASE.'consents', $this->peticion($p), $this->cuenta('Ana', self::ALL))->assertCreated()
            ->assertJsonPath('data.kind', 'requested')->assertJsonPath('data.numberCurrent', true)->assertJsonPath('data.recordedBy.id', $ana->id)
            ->assertJsonPath('meta.replayed', false)->json('data.uuid');
        $this->assertSame('missing', $this->motor($p['key']), 'una petición sola no autoriza nada');
        $lista = $this->getJson(self::BASE.'consents?personType=member&personId='.$p['member'], $this->cuenta('Beto', self::ALL))->assertOk();
        $lista->assertJsonPath('data.purposes.commercial.state', 'none')->assertJsonPath('data.purposes.commercial.pending.0.uuid', $pet)
            ->assertJsonPath('data.purposes.commercial.pending.0.ownRequest', false)->assertJsonPath('data.purposes.commercial.pending.0.superseded', false);

        // Cuatro ojos por cuenta: quien registró no confirma.
        $this->postJson(self::BASE."consents/{$pet}/confirm", ['clientRequestId' => (string) Str::uuid()], $this->cuenta('Ana', self::ALL))
            ->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        $ok = $this->postJson(self::BASE."consents/{$pet}/confirm", ['clientRequestId' => (string) Str::uuid()], $this->cuenta('Beto', self::ALL))->assertCreated();
        $ok->assertJsonPath('data.kind', 'granted')->assertJsonPath('data.requestUuid', $pet)->assertJsonPath('data.confirmedBy.id', $beto->id)
            ->assertJsonPath('data.recordedBy.id', $ana->id)->assertJsonPath('data.numberCurrent', true);
        $this->assertSame('granted', $this->motor($p['key']));
        $this->getJson(self::BASE.'consents?personType=member&personId='.$p['member'], $this->cuenta('Ana', self::ALL))->assertOk()
            ->assertJsonPath('data.purposes.commercial.state', 'granted')->assertJsonPath('data.purposes.commercial.effective', true)
            ->assertJsonPath('data.purposes.commercial.pending', []);
        // Confirmar otra vez la misma petición (otra cuenta, otro identificador): ya está resuelta.
        $this->postJson(self::BASE."consents/{$pet}/confirm", ['clientRequestId' => (string) Str::uuid()], $this->cuenta('Caro', self::ALL))
            ->assertStatus(409)->assertJsonPath('code', 'consent_request_resolved');

        // Retirar, sin segunda cuenta: el motor deja de poder.
        $this->postJson(self::BASE.'consents/revoke', $this->retirada($p), $this->cuenta('Ana', self::ALL))->assertCreated()->assertJsonPath('data.kind', 'revoked');
        $this->assertSame('denied', $this->motor($p['key']));
        $this->getJson(self::BASE.'consents?personType=member&personId='.$p['member'], $this->cuenta('Ana', self::ALL))->assertOk()
            ->assertJsonPath('data.purposes.commercial.state', 'revoked')->assertJsonPath('data.purposes.commercial.effective', false);

        // El registro: el uuid, la finalidad, la fuente y la cuenta; nada de la persona.
        Log::shouldHaveReceived('info')->with('outreach.consent.requested', Mockery::on(fn (array $c): bool => array_keys($c) === ['consent_uuid', 'purpose', 'source', 'by'] && $c['by'] === $ana->id));
        Log::shouldHaveReceived('info')->with('outreach.consent.confirmed', Mockery::on(fn (array $c): bool => array_keys($c) === ['consent_uuid', 'request_uuid', 'purpose', 'by']));
        Log::shouldHaveReceived('info')->with('outreach.consent.revoked', Mockery::on(fn (array $c): bool => array_keys($c) === ['consent_uuid', 'purpose', 'source', 'by']));
        Log::shouldHaveReceived('warning')->with('outreach.consent.four_eyes_denied', Mockery::on(fn (array $c): bool => $c['by'] === $ana->id));
    }

    public function test_ninguna_respuesta_lleva_el_telefono_ni_los_seudonimos_y_el_cliente_no_lo_puede_mandar(): void
    {
        $p = $this->socioConWhatsapp();
        $pet = $this->postJson(self::BASE.'consents', $this->peticion($p) + ['phone' => '3009999999', 'phone_ref' => str_repeat('a', 64)], $this->cuenta('Ana', self::ALL))->assertCreated();
        $this->assertSame(PhoneRef::fromRaw(self::PHONE), DB::table('outreach_consents')->where('uuid', $pet->json('data.uuid'))->value('phone_ref'), 'el número lo calcula el servidor');
        $ok = $this->postJson(self::BASE.'consents/'.$pet->json('data.uuid').'/confirm', ['clientRequestId' => (string) Str::uuid()], $this->cuenta('Beto', self::ALL));
        $lista = $this->getJson(self::BASE.'consents?personType=member&personId='.$p['member'], $this->cuenta('Beto', self::ALL));
        foreach ([$pet, $ok, $lista] as $r) {
            foreach (['3001110001', '573001110001', (string) PhoneRef::fromRaw(self::PHONE), (string) SubjectRef::of($p['key']), $p['key']] as $privado) {
                $this->assertStringNotContainsString($privado, (string) $r->getContent());
            }
        }
    }

    public function test_repetir_la_misma_peticion_devuelve_lo_mismo_y_con_otro_contenido_409(): void
    {
        $p = $this->socioConWhatsapp();
        $cuerpo = $this->peticion($p);
        $uno = $this->postJson(self::BASE.'consents', $cuerpo, $this->cuenta('Ana', self::ALL))->assertCreated();
        $this->postJson(self::BASE.'consents', $cuerpo, $this->cuenta('Ana', self::ALL))->assertOk()->assertJsonPath('meta.replayed', true)
            ->assertJsonPath('data.uuid', $uno->json('data.uuid'));
        $this->postJson(self::BASE.'consents', ['evidence' => 'Otra evidencia distinta'] + $cuerpo, $this->cuenta('Ana', self::ALL))->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->postJson(self::BASE.'consents', $cuerpo, $this->cuenta('Caro', self::ALL))->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        // Otra petición nueva de lo mismo, mientras la primera sigue pendiente: 409.
        $this->postJson(self::BASE.'consents', ['clientRequestId' => (string) Str::uuid()] + $cuerpo, $this->cuenta('Caro', self::ALL))->assertStatus(409)
            ->assertJsonPath('code', 'consent_request_pending')->assertJsonPath('pendingUuid', $uno->json('data.uuid'));

        $conf = ['clientRequestId' => (string) Str::uuid()];
        $c1 = $this->postJson(self::BASE.'consents/'.$uno->json('data.uuid').'/confirm', $conf, $this->cuenta('Beto', self::ALL))->assertCreated();
        $this->postJson(self::BASE.'consents/'.$uno->json('data.uuid').'/confirm', $conf, $this->cuenta('Beto', self::ALL))->assertOk()
            ->assertJsonPath('meta.replayed', true)->assertJsonPath('data.uuid', $c1->json('data.uuid'));
        $this->postJson(self::BASE.'consents/revoke', ['clientRequestId' => $conf['clientRequestId']] + $this->retirada($p), $this->cuenta('Beto', self::ALL))
            ->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $ret = $this->retirada($p);
        $r1 = $this->postJson(self::BASE.'consents/revoke', $ret, $this->cuenta('Beto', self::ALL))->assertCreated();
        $this->postJson(self::BASE.'consents/revoke', $ret, $this->cuenta('Beto', self::ALL))->assertOk()->assertJsonPath('data.uuid', $r1->json('data.uuid'));
        $this->assertSame(3, DB::table('outreach_consents')->count());
    }

    public function test_rechazar_resuelve_la_peticion_y_quien_la_registro_puede_retirarla_asi(): void
    {
        $p = $this->socioConWhatsapp();
        $pet = $this->postJson(self::BASE.'consents', $this->peticion($p), $this->cuenta('Ana', self::ALL))->assertCreated()->json('data.uuid');
        $cuerpo = ['clientRequestId' => (string) Str::uuid(), 'evidence' => 'Se registró por error, no era esta persona'];
        $this->postJson(self::BASE."consents/{$pet}/reject", $cuerpo, $this->cuenta('Ana', self::ALL))->assertCreated()->assertJsonPath('data.kind', 'rejected')
            ->assertJsonPath('data.legalVersion', null)->assertJsonPath('data.numberCurrent', null);
        $this->postJson(self::BASE."consents/{$pet}/reject", $cuerpo, $this->cuenta('Ana', self::ALL))->assertOk()->assertJsonPath('meta.replayed', true);
        $this->postJson(self::BASE."consents/{$pet}/confirm", ['clientRequestId' => (string) Str::uuid()], $this->cuenta('Beto', self::ALL))
            ->assertStatus(409)->assertJsonPath('code', 'consent_request_resolved');
        $this->assertSame('missing', $this->motor($p['key']));
    }

    public function test_una_retirada_posterior_a_la_peticion_impide_confirmarla_y_el_numero_cambiado_tambien(): void
    {
        $p = $this->socioConWhatsapp();
        $pet = $this->postJson(self::BASE.'consents', $this->peticion($p), $this->cuenta('Ana', self::ALL))->assertCreated()->json('data.uuid');
        $this->postJson(self::BASE.'consents/revoke', $this->retirada($p), $this->cuenta('Ana', self::ALL))->assertCreated();
        $this->getJson(self::BASE.'consents?personType=member&personId='.$p['member'], $this->cuenta('Beto', self::ALL))
            ->assertJsonPath('data.purposes.commercial.pending.0.superseded', true);
        $this->postJson(self::BASE."consents/{$pet}/confirm", ['clientRequestId' => (string) Str::uuid()], $this->cuenta('Beto', self::ALL))
            ->assertStatus(409)->assertJsonPath('code', 'consent_request_superseded');

        $q = $this->socioConWhatsapp([], '3001110003');
        $pet2 = $this->postJson(self::BASE.'consents', $this->peticion($q), $this->cuenta('Ana', self::ALL))->assertCreated()->json('data.uuid');
        DB::table('marketing_leads')->where('id', $q['lead'])->update(['phone' => '3009990009']);
        $this->postJson(self::BASE."consents/{$pet2}/confirm", ['clientRequestId' => (string) Str::uuid()], $this->cuenta('Beto', self::ALL))
            ->assertStatus(409)->assertJsonPath('code', 'consent_number_changed');
        $this->assertSame(0, DB::table('outreach_consents')->where('kind', 'granted')->count());
    }

    public function test_las_fuentes_las_fija_la_configuracion_pero_retirar_las_admite_todas(): void
    {
        config(['outreach_consents.sources' => ['reception_form']]);
        $p = $this->socioConWhatsapp();
        $this->postJson(self::BASE.'consents', ['source' => 'member_request'] + $this->peticion($p), $this->cuenta('Ana', self::ALL))->assertStatus(422)->assertJsonPath('errors.source.0', 'in');
        $this->postJson(self::BASE.'consents', $this->peticion($p), $this->cuenta('Ana', self::ALL))->assertCreated();
        $this->postJson(self::BASE.'consents/revoke', ['source' => 'member_request'] + $this->retirada($p), $this->cuenta('Ana', self::ALL))->assertCreated();
        $this->getJson(self::BASE.'consents?personType=member&personId='.$p['member'], $this->cuenta('Ana', self::ALL))
            ->assertJsonPath('data.sources', ['reception_form'])->assertJsonPath('data.revokeSources', ['contract_checkbox', 'reception_form', 'member_request']);
    }

    public function test_las_escrituras_tienen_el_cupo_de_la_api_de_la_ejecucion(): void
    {
        $p = $this->socioConWhatsapp();
        $ana = $this->cuenta('Ana', self::ALL);
        for ($i = 0; $i < 30; $i++) {
            $this->postJson(self::BASE.'consents/revoke', $this->retirada($p), $ana)->assertCreated();
        }
        $this->postJson(self::BASE.'consents/revoke', $this->retirada($p), $ana)->assertStatus(429);
        $this->assertSame(30, DB::table('outreach_consents')->count());
        // Las lecturas van con su propio cupo.
        $this->getJson(self::BASE.'consents?personType=member&personId='.$p['member'], $ana)->assertOk();
    }

    public function test_sin_numero_no_hay_a_que_autorizar(): void
    {
        $s = $this->socio([], ['phone' => null]);
        $this->postJson(self::BASE.'consents', $this->peticion(['member' => $s['member']]), $this->cuenta('Ana', self::ALL))->assertStatus(409)->assertJsonPath('code', 'consent_no_phone');
        $this->assertSame(0, DB::table('outreach_consents')->count());
    }

    /**
     * La evidencia: TODO lo que la base rechazaría es un 422 (nunca un 500 por
     * un CHECK), en los dos motores; en PostgreSQL esto prueba el CHECK real.
     */
    public function test_la_evidencia_que_la_base_rechazaria_es_un_422_y_no_escribe(): void
    {
        $p = $this->socioConWhatsapp();
        $malas = [
            '7 cifras seguidas' => ['Cédula 1234567 verificada', 'contact_data'],
            'un celular' => ['Llamó desde 3001234567', 'contact_data'],
            'celular con espacios' => ['Tel 300 123 45 67', 'contact_data'],
            'serie con puntos y guiones' => ['Ref 3.0.0-1.2.3-4.5', 'contact_data'],
            'serie con letras no ASCII' => ['Ref 3é0é0é1é2é3é4é5', 'contact_data'],
            'una fecha con año completo' => ['Firmado el 22/11/2026', 'contact_data'],
            'un correo' => ['Lo pidió desde ana@ejemplo.com', 'contact_data'],
            'tabulador' => ["Formulario\tfirmado", 'invalid'],
            'salto de línea' => ["Formulario\nfirmado", 'invalid'],
            'separador de línea' => ["Formulario\u{2028}firmado", 'invalid'],
            'control C1' => ["Formulario\u{0085}firmado", 'invalid'],
            'invisible' => ["Formulario\u{200B}firmado", 'invalid'],
            'demasiado larga' => [str_repeat('a', 301), 'invalid'],
            'demasiado corta' => ['ab', 'length'],
            'solo espacios' => ['     ', 'invalid'],
        ];
        foreach ($malas as $que => [$texto, $motivo]) {
            // Una cuenta por caso: el cupo de escritura (30 por minuto y persona) no es lo que se prueba aquí.
            $quien = $this->cuenta('Evidencia '.md5($que), self::ALL);
            $this->postJson(self::BASE.'consents', ['evidence' => $texto] + $this->peticion($p), $quien)
                ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonPath('errors.evidence.0', $motivo);
            $this->postJson(self::BASE.'consents/revoke', ['evidence' => $texto] + $this->retirada($p), $quien)->assertStatus(422);
            $this->assertSame(0, DB::table('outreach_consents')->count(), $que);
        }
        // Lo que sí: texto plano con tildes, signos y cifras sueltas.
        $this->postJson(self::BASE.'consents', ['evidence' => 'Formulario firmado en recepción el 22 de noviembre, carpeta 12 (Ana)'] + $this->peticion($p), $this->cuenta('Ana', self::ALL))->assertCreated();
        // Lo que la base también rechaza, insertado a mano, la base lo rechaza (no es una regla solo del servicio).
        if ($this->isPostgres()) {
            foreach (['Tel 300 123 45 67', 'ana@ejemplo.com', "a\tb"] as $texto) {
                try {
                    DB::transaction(fn () => $this->requestConsent(['member_id' => $p['member'], 'evidence' => $texto]));
                    $this->fail("la base admitió «{$texto}»");
                } catch (QueryException $e) {
                    $this->assertStringContainsString('ocon_evidence_chk', $e->getMessage());
                }
            }
        }
    }

    // ── PostgreSQL: el rol de la aplicación y las carreras ──────────────────

    public function test_solo_en_postgres_el_ciclo_entero_por_http_como_el_rol_de_la_aplicacion_sin_update_ni_delete(): void
    {
        $this->requirePostgres('los roles y los privilegios solo existen en PostgreSQL.');
        $p = $this->socioConWhatsapp();
        $ana = $this->cuenta('Ana', self::ALL);
        $beto = $this->cuenta('Beto', self::ALL);

        $this->withAppRole(function () use ($p, $ana, $beto): void {
            $m = $this->consentMigration();
            $m->down();
            $m->up();
            $rol = $this->appRoleName();
            // El resto de la base, como la aplicación de hoy en producción (todo); el libro, como lo deja C9-1.
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO '.$rol);
            DB::statement('GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO '.$rol);
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON outreach_consents FROM '.$rol);
            $this->assertSame([true, true, false, false, false], $this->privileges('outreach_consents'));

            $sql = [];
            DB::listen(function (QueryExecuted $q) use (&$sql): void {
                $sql[] = $q->sql;
            });
            [$estado, $mensaje] = $this->asApp(function () use ($p, $ana, $beto): void {
                $pet = $this->postJson(self::BASE.'consents', $this->peticion($p), $ana)->assertCreated()->json('data.uuid');
                $this->postJson(self::BASE.'consents', $this->peticion($p), $ana)->assertStatus(409);
                $this->postJson(self::BASE."consents/{$pet}/confirm", ['clientRequestId' => (string) Str::uuid()], $beto)->assertCreated();
                $otra = $this->postJson(self::BASE.'consents', ['purpose' => 'commercial', 'clientRequestId' => (string) Str::uuid()] + $this->peticion($p), $ana)->assertCreated()->json('data.uuid');
                $this->postJson(self::BASE."consents/{$otra}/reject", ['clientRequestId' => (string) Str::uuid(), 'evidence' => 'Duplicada por error'], $beto)->assertCreated();
                $this->postJson(self::BASE.'consents/revoke', $this->retirada($p), $beto)->assertCreated();
                $this->getJson(self::BASE.'consents?personType=member&personId='.$p['member'], $ana)->assertOk()->assertJsonPath('data.purposes.commercial.state', 'revoked');
                $this->assertSame('denied', $this->motor($p['key']));
                $this->assertSame($this->appRoleName(), DB::scalar('SELECT current_user'));
            });
            DB::getEventDispatcher()->forget(QueryExecuted::class);
            $this->assertNull($estado, "el rol de la aplicación no completa el ciclo: {$mensaje}");
            foreach ($sql as $s) {
                $this->assertDoesNotMatchRegularExpression('/\b(update|delete\s+from|truncate)\s+"?outreach_consents/i', $s, $s);
                $this->assertDoesNotMatchRegularExpression('/\bfor\s+(update|share|no\s+key\s+update|key\s+share)\b/i', $s, $s);
            }
            $this->assertGreaterThan(10, count($sql));
        });
    }

    /**
     * Dos confirmaciones de la misma petición a la vez: una gana y la otra, 409
     * `consent_request_resolved` (el UNIQUE de `request_uuid`, sin cerrojos).
     *  1. Con dos conexiones en vuelo: la segunda ESPERA a la primera
     *     (`pg_blocking_pids`) y, al confirmar la primera, no inserta nada
     *     (`ON CONFLICT DO NOTHING`, la sentencia del servicio).
     *  2. Con el servicio real: otra conexión confirma la suya JUSTO antes de
     *     la sentencia del servicio (`DB::beforeExecuting`), después de que el
     *     servicio comprobara que seguía pendiente: el servicio responde 409.
     */
    public function test_solo_en_postgres_dos_confirmaciones_a_la_vez_una_gana_y_la_otra_409(): void
    {
        $this->preparaCarrera();
        $p = $this->socioConWhatsapp();
        $c = DB::connection(self::RACE);

        // 1 · Dos conexiones de verdad, la segunda asíncrona.
        $pet = $this->pedida($p);
        [$a, $b] = [$this->pgConnect(), $this->pgConnect()];
        $uno = $this->consentResolutionRow($pet, 'granted');
        $dos = $this->consentResolutionRow($pet, 'granted', ['confirmed_by_admin_id' => 3]);
        $this->pgExec($a, 'BEGIN');
        $this->assertSame(1, $this->pgExec($a, ...$this->insertOrIgnoreSql($uno)));
        $this->pgExec($b, 'BEGIN');
        $this->assertTrue(pg_send_query_params($b, ...$this->insertOrIgnoreSql($dos)));
        $this->pgWaitBlocked($b, $a);
        $this->pgExec($a, 'COMMIT');
        [$estado, $filas] = $this->pgAsyncResult($b);
        $this->assertSame([null, 0], [$estado, $filas], 'la segunda no inserta ni falla: ON CONFLICT DO NOTHING');
        $this->pgExec($b, 'COMMIT');
        $this->assertSame(1, $c->table('outreach_consents')->where('request_uuid', $pet['uuid'])->count());

        // 2 · El servicio real pierde la carrera y lo dice.
        $pet2 = $this->pedida($this->socioConWhatsapp([], '3001110005'));
        $this->antesDeLaEscrituraDelServicio(fn () => $c->table('outreach_consents')->insert($this->consentResolutionRow($pet2, 'granted', ['confirmed_by_admin_id' => 3])));
        $this->postJson(self::BASE.'consents/'.$pet2['uuid'].'/confirm', ['clientRequestId' => (string) Str::uuid()], $this->cuenta('Beto', self::ALL))
            ->assertStatus(409)->assertJsonPath('code', 'consent_request_resolved');
        $this->assertSame(1, $c->table('outreach_consents')->where('request_uuid', $pet2['uuid'])->count());
    }

    /**
     * Confirmar y retirar a la vez: GANA LA RETIRADA, sea cual sea el orden.
     *  1. La retirada se confirma JUSTO antes de la sentencia del servicio: la
     *     guarda de la base la ve (`ocon_grant_not_superseded`) y el servicio
     *     responde 409 `consent_request_superseded`.
     *  2. Las dos en vuelo, sin verse (la retirada aún sin confirmar cuando el
     *     servicio inserta): entran las dos, y al LEER gana la retirada (la
     *     confirmación cuenta en el lugar de su petición).
     */
    public function test_solo_en_postgres_confirmar_y_retirar_a_la_vez_gana_la_retirada(): void
    {
        $this->preparaCarrera();
        $p = $this->socioConWhatsapp();
        $c = DB::connection(self::RACE);
        $pet = $this->pedida($p);

        // 1 · La retirada llega antes de la escritura del servicio.
        $this->antesDeLaEscrituraDelServicio(fn () => $c->table('outreach_consents')->insert($this->consentRevocationRow(['member_id' => $p['member']])));
        $this->postJson(self::BASE.'consents/'.$pet['uuid'].'/confirm', ['clientRequestId' => (string) Str::uuid()], $this->cuenta('Beto', self::ALL))
            ->assertStatus(409)->assertJsonPath('code', 'consent_request_superseded');
        $this->assertSame('denied', $this->motor($p['key']));

        // 2 · Las dos en vuelo: la retirada sin confirmar mientras el servicio confirma (no se bloquean: filas distintas).
        $q = $this->socioConWhatsapp([], '3001110004');
        $pet2 = $this->pedida($q);
        $a = $this->pgConnect();
        $this->pgExec($a, 'BEGIN');
        $this->assertSame(1, $this->pgExec($a, ...$this->insertOrIgnoreSql($this->consentRevocationRow(['member_id' => $q['member']]))));
        $this->postJson(self::BASE.'consents/'.$pet2['uuid'].'/confirm', ['clientRequestId' => (string) Str::uuid()], $this->cuenta('Beto', self::ALL))->assertCreated();
        $this->assertSame('granted', $this->motor($q['key']), 'mientras la retirada no se confirma, no existe');
        $this->pgExec($a, 'COMMIT');
        $this->assertSame('denied', $this->motor($q['key']), 'al leer, gana la retirada');
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** @return array{member: int, lead: int, key: string, ref: string} */
    private function socioConWhatsapp(array $lead = [], string $phone = self::PHONE): array
    {
        $s = $this->socio();
        $l = $this->lead(array_merge(['member_id' => $s['member'], 'phone' => $phone, 'consent_status' => 'unknown'], $lead));
        $this->conversation($l, ['last_inbound_at' => self::utc('2026-11-01 15:00:00')]);

        return ['member' => $s['member'], 'lead' => $l, 'key' => SubjectKey::member($s['member']), 'ref' => (string) PhoneRef::fromRaw($phone)];
    }

    /** @param  array{member: int}  $p  @return array<string, mixed> */
    private function peticion(array $p): array
    {
        return [
            'clientRequestId' => (string) Str::uuid(), 'personType' => 'member', 'personId' => $p['member'], 'purpose' => 'commercial',
            'source' => 'reception_form', 'evidence' => 'Formulario firmado en recepción, carpeta de noviembre',
            'legalVersion' => self::LEGAL_VERSION, 'legalHash' => hash('sha256', self::LEGAL_TEXT),
        ];
    }

    /** @param  array{member: int}  $p  @return array<string, mixed> */
    private function retirada(array $p): array
    {
        return [
            'clientRequestId' => (string) Str::uuid(), 'personType' => 'member', 'personId' => $p['member'], 'purpose' => 'commercial',
            'source' => 'member_request', 'evidence' => 'Pidió en recepción que no le escriban más',
        ];
    }

    /** La autorización que el motor (C9-2) lee para esta persona, por WhatsApp y comercial. */
    private function motor(string $clave, string $finalidad = 'commercial'): string
    {
        $foto = $this->snap([$clave], self::NOW, CoreProjection::selectionFor([]));

        return CoreProjection::policyInput($foto->subject($clave), $finalidad, null)['consent']['whatsapp'];
    }

    /** @param  list<string>  $llaves  @return array<string, string> */
    private function cuenta(string $nombre, array $llaves): array
    {
        return $this->cuentaCon($nombre, $llaves)[1];
    }

    /**
     * Una cuenta del personal con EXACTAMENTE esas llaves (un rol propio), una vez por nombre.
     *
     * @param  list<string>  $llaves
     * @return array{0: Admin, 1: array<string, string>}
     */
    private function cuentaCon(string $nombre, array $llaves): array
    {
        $clave = $nombre.'|'.implode(',', $llaves);
        if (isset($this->cuentas[$clave])) {
            return $this->cuentas[$clave];
        }
        $rol = 'c9-'.substr(md5(implode(',', $llaves)), 0, 8);
        AdminRole::firstOrCreate(['name' => $rol], ['is_system' => false]);
        foreach ($llaves as $llave) {
            RolePermission::query()->updateOrCreate(['role' => $rol, 'permission' => $llave], ['granted' => true]);
        }
        Cache::forget('crm.role_permissions.v1');
        app(RolePermissionPolicy::class)->flush();
        $admin = Admin::create(['name' => $nombre, 'email' => strtolower($nombre).'-'.uniqid().'@c9.test', 'password' => 'secret-password', 'role' => $rol, 'status' => 'active']);

        return $this->cuentas[$clave] = [$admin, $this->actingAsAdmin($admin)];
    }

    /**
     * Una petición CONFIRMADA en la base (por la conexión de apoyo) con el texto
     * legal vigente de esta prueba y registrada por una cuenta que no es
     * ninguna de las que crea la prueba (900): así confirmarla no choca con los
     * cuatro ojos por casualidad de ids.
     *
     * @param  array{member: int, ref: string}  $p
     * @return array<string, mixed>
     */
    private function pedida(array $p): array
    {
        return $this->requestConsent([
            'member_id' => $p['member'], 'phone_ref' => $p['ref'], 'legal_version' => self::LEGAL_VERSION,
            'legal_hash' => hash('sha256', self::LEGAL_TEXT), 'recorded_by_admin_id' => 900,
        ]);
    }

    private function preparaCarrera(): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('Solo PostgreSQL: las carreras necesitan dos conexiones reales.');
        }
        if (! function_exists('pg_send_query_params')) {
            $this->markTestSkipped('Sin la extensión pgsql de PHP no hay consultas asíncronas.');
        }
        config(['database.connections.'.self::RACE => config('database.connections.'.config('database.default'))]);
        $this->assertSame(0, DB::connection(self::RACE)->table('outreach_consents')->count(), 'el libro tiene filas confirmadas de otra prueba: no se borran filas ajenas');
        $this->carrera = true;
        // Las filas del libro de la carrera se CONFIRMAN por la conexión de apoyo (la de la prueba no las vería
        // confirmadas), con el texto legal vigente de esta prueba; las personas viven en la de la prueba (sin FK).
        $this->fixtureConnection = self::RACE;
    }

    /** Una vez: `$fn` corre por la conexión de apoyo JUSTO antes de la primera escritura del servicio en el libro. */
    private function antesDeLaEscrituraDelServicio(\Closure $fn): void
    {
        $hecho = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$hecho, $fn): void {
            if (! $hecho && preg_match('/^insert\s+into\s+"outreach_consents"/i', $sql) === 1) {
                $hecho = true;
                $fn();
            }
        });
    }

    /** @param  array<string, mixed>  $fila  @return array{0: string, 1: list<mixed>} la sentencia del servicio (`insertOrIgnore`) en PG */
    private function insertOrIgnoreSql(array $fila): array
    {
        $columnas = array_keys($fila);
        $marcas = implode(', ', array_map(static fn (int $i): string => '$'.($i + 1), array_keys($columnas)));

        return ['INSERT INTO outreach_consents ('.implode(', ', array_map(static fn (string $c): string => '"'.$c.'"', $columnas)).") VALUES ({$marcas}) ON CONFLICT DO NOTHING",
            array_values($fila)];
    }

    /** @var list<Connection> */
    private array $pg = [];

    private function pgConnect(): Connection
    {
        $cfg = (array) config('database.connections.'.self::RACE);
        $partes = ['host='.$cfg['host'], 'port='.$cfg['port'], 'dbname='.$cfg['database'], 'user='.$cfg['username'], 'gssencmode=disable', 'application_name=c9_carreras'];
        if ((string) ($cfg['password'] ?? '') !== '') {
            $partes[] = "password='".addcslashes((string) $cfg['password'], "'\\")."'";
        }
        $conexion = pg_connect(implode(' ', $partes), PGSQL_CONNECT_FORCE_NEW);
        $this->assertInstanceOf(Connection::class, $conexion);
        $this->pg[] = $conexion;

        return $conexion;
    }

    /** @param  list<mixed>  $parametros */
    private function pgExec(Connection $conexion, string $sql, array $parametros = []): int
    {
        $r = @pg_query_params($conexion, $sql, $parametros);
        if ($r === false) {
            $this->fail('La base rechazó lo que tenía que aceptar: '.pg_last_error($conexion));
        }

        return pg_affected_rows($r);
    }

    private function pgWaitBlocked(Connection $espera, Connection $tiene): void
    {
        for ($i = 0; $i < 200; $i++) {
            if ((bool) DB::connection(self::RACE)->scalar('SELECT ?::int = ANY(pg_blocking_pids(?::int))', [pg_get_pid($tiene), pg_get_pid($espera)])) {
                $this->addToAssertionCount(1);

                return;
            }
            usleep(25000);
        }
        $this->fail('La segunda conexión no llegó a esperar a la primera: la carrera no se produjo.');
    }

    /** @return array{0: ?string, 1: int} [SQLSTATE o null, filas afectadas] */
    private function pgAsyncResult(Connection $conexion): array
    {
        $r = pg_get_result($conexion);
        while (pg_get_result($conexion) !== false) {
            // vaciar
        }
        $estado = pg_result_error_field($r, PGSQL_DIAG_SQLSTATE);

        return [$estado === false || $estado === null ? null : (string) $estado, $estado ? 0 : pg_affected_rows($r)];
    }
}
