<?php

namespace Tests\Feature\Outreach\Run;

use App\Http\Controllers\Crm\CommercialAutomation\OutreachComplaintController;
use App\Http\Controllers\Crm\CommercialAutomation\OutreachConsentController;
use App\Http\Middleware\LimitRequestBodySize;
use App\Http\Middleware\ValidatePostSizeWithStudioCap;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\MemberTrainerAssignment;
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
use Illuminate\Foundation\Http\Middleware\TrimStrings;
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

    /**
     * M-3 · El tope temprano de cuerpo (B7-d, `ValidatePostSizeWithStudioCap`)
     * cubre también este prefijo: un cuerpo enorme SIN SESIÓN se corta con 413
     * antes de que `TrimStrings` recorra un solo texto (antes: 401 después de
     * recorrerlo entero). El tope de la ruta (16 KB) sigue siendo la segunda capa.
     */
    public function test_un_cuerpo_enorme_sin_sesion_se_corta_antes_de_recorrerlo(): void
    {
        $recorte = new class extends TrimStrings
        {
            public int $textos = 0;

            protected function transform($key, $value)
            {
                $this->textos++;

                return parent::transform($key, $value);
            }
        };
        $this->app->instance(TrimStrings::class, $recorte);
        $cuerpo = '{"evidence":['.implode(',', array_fill(0, 40_000, '" x "')).']}';
        $this->assertGreaterThan(200_000, strlen($cuerpo));
        $server = $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Content-Type' => 'application/json']) + ['CONTENT_LENGTH' => (string) strlen($cuerpo)];

        foreach (['consents/revoke', 'consents', 'consents/'.Str::uuid().'/confirm'] as $ruta) {
            $this->call('POST', self::BASE.$ruta, [], [], [], $server, $cuerpo)->assertStatus(413)
                ->assertExactJson(['ok' => false, 'code' => 'payload_too_large', 'message' => 'La petición supera el tamaño máximo (64 KB).']);
        }
        $this->assertSame(0, $recorte->textos, 'TrimStrings recorrió el cuerpo antes del tope');
        // Entre 16 y 64 KB lo corta el tope de la ruta (la segunda capa, detrás de la sesión), con su tamaño.
        $medio = '{"evidence":"'.str_repeat('x', 20_000).'"}';
        $conSesion = $this->transformHeadersToServerVars($this->cuenta('Ana', self::ALL) + ['Accept' => 'application/json', 'Content-Type' => 'application/json']);
        $this->call('POST', self::BASE.'consents/revoke', [], [], [], ['CONTENT_LENGTH' => (string) strlen($medio)] + $conSesion, $medio)->assertStatus(413)
            ->assertJsonPath('message', 'La petición supera el tamaño máximo (16 KB).');
        $this->assertSame(0, DB::table('outreach_consents')->count());
        $this->assertTrue(Str::is(ValidatePostSizeWithStudioCap::STUDIO_PATHS, 'api/admin/commercial-automation/consents/revoke'));
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
        // Un prospecto de un socio es el socio: ver prospectos no basta. Y sin ver socios es 404, como uno que no existe: un 403
        // diría que está enlazado a un socio (M-1). Un prospecto suelto, 200.
        $mercadeo = $this->cuenta('Mercadeo', [OutreachAccess::CONSENTS_RECORD, 'marketing.view']);
        $enlazado = $this->getJson(self::BASE.'consents?personType=lead&personId='.$p['lead'], $mercadeo)->assertStatus(404)->assertJsonPath('code', 'person_not_found');
        $noExiste = $this->getJson(self::BASE.'consents?personType=lead&personId=999999', $mercadeo)->assertStatus(404);
        $this->assertSame($noExiste->getContent(), $enlazado->getContent(), 'la misma respuesta: no es un oráculo del vínculo');
        $this->getJson(self::BASE.'consents?personType=lead&personId='.$this->lead(['phone' => '3001119999']), $mercadeo)->assertOk();
        $this->postJson(self::BASE.'consents/revoke', ['personType' => 'lead', 'personId' => $p['lead']] + $this->retirada($p), $mercadeo)->assertStatus(404)->assertJsonPath('code', 'person_not_found');
        $this->assertSame(0, DB::table('outreach_consents')->count());
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

    /**
     * C-3 · La sonda P2 de la revisión de API: una cuenta de Entrenador
     * vinculada, con `members.view` y las dos llaves del libro, solo alcanza a
     * los socios que tiene asignados, como el resto del CRM (404, no 403): de un
     * socio ajeno, la lectura (también por su prospecto, que es el socio) y las
     * cuatro escrituras responden 404 y no escriben nada. Del suyo, sí.
     */
    public function test_el_entrenador_solo_alcanza_a_los_socios_que_tiene_asignados(): void
    {
        Log::spy();
        $ajeno = $this->socioConWhatsapp();
        $suyo = $this->socioConWhatsapp([], '3001110002');
        $entrenador = $this->trainer();
        MemberTrainerAssignment::create(['member_id' => $suyo['member'], 'trainer_id' => $entrenador, 'status' => MemberTrainerAssignment::STATUS_ACTIVE, 'assigned_at' => now()]);
        foreach (self::ALL as $llave) {
            RolePermission::query()->updateOrCreate(['role' => CrmPermission::ROLE_ENTRENADOR, 'permission' => $llave], ['granted' => true]);
        }
        Cache::forget('crm.role_permissions.v1');
        app(RolePermissionPolicy::class)->flush();
        $cuenta = Admin::create(['name' => 'Coach', 'email' => 'coach-'.uniqid().'@c9.test', 'password' => 'secret-password', 'role' => CrmPermission::ROLE_ENTRENADOR,
            'trainer_id' => $entrenador, 'status' => 'active']);
        $h = $this->actingAsAdmin($cuenta);
        $pet = $this->postJson(self::BASE.'consents', $this->peticion($ajeno), $this->cuenta('Ana', self::ALL))->assertCreated()->json('data.uuid');
        $antes = DB::table('outreach_consents')->count();

        // Como el resto del CRM: la ficha del socio ajeno no existe para él.
        $this->getJson('/api/admin/members/'.$ajeno['member'], $h)->assertStatus(404);
        $this->getJson(self::BASE.'consents?personType=member&personId='.$ajeno['member'], $h)->assertStatus(404)->assertJsonPath('code', 'person_not_found');
        $this->getJson(self::BASE.'consents?personType=lead&personId='.$ajeno['lead'], $h)->assertStatus(404)->assertJsonPath('code', 'person_not_found');
        $this->postJson(self::BASE.'consents', ['clientRequestId' => (string) Str::uuid()] + $this->peticion($ajeno), $h)->assertStatus(404)->assertJsonPath('code', 'person_not_found');
        $this->postJson(self::BASE.'consents/revoke', $this->retirada($ajeno), $h)->assertStatus(404)->assertJsonPath('code', 'person_not_found');
        $this->postJson(self::BASE."consents/{$pet}/confirm", ['clientRequestId' => (string) Str::uuid()], $h)->assertStatus(404)->assertJsonPath('code', 'consent_not_found');
        $this->postJson(self::BASE."consents/{$pet}/reject", ['clientRequestId' => (string) Str::uuid(), 'evidence' => 'La firma no coincide con la del contrato'], $h)
            ->assertStatus(404)->assertJsonPath('code', 'consent_not_found');
        $this->assertSame($antes, DB::table('outreach_consents')->count(), 'ninguna escritura sobre un socio ajeno');
        $this->assertSame('missing', $this->motor($ajeno['key']));
        Log::shouldHaveReceived('warning')->with('outreach.consent.out_of_trainer_scope', Mockery::on(fn (array $c): bool => $c === ['by' => $cuenta->id]));

        // Su socio asignado, sí: leer y retirar.
        $this->getJson(self::BASE.'consents?personType=member&personId='.$suyo['member'], $h)->assertOk();
        $this->postJson(self::BASE.'consents/revoke', $this->retirada($suyo), $h)->assertCreated();
        // Y una asignación terminada ya no es una llave.
        MemberTrainerAssignment::query()->where('member_id', $suyo['member'])->update(['status' => MemberTrainerAssignment::STATUS_INACTIVE]);
        $this->getJson(self::BASE.'consents?personType=member&personId='.$suyo['member'], $this->actingAsAdmin($cuenta))->assertStatus(404);
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
        // M-2: con otro texto legal (otra huella u otra versión) es otra petición; la misma huella en mayúsculas es la misma.
        $this->postJson(self::BASE.'consents', ['legalHash' => hash('sha256', 'Otro texto')] + $cuerpo, $this->cuenta('Ana', self::ALL))->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->postJson(self::BASE.'consents', ['legalVersion' => 'c9-whatsapp-comercial/2026-11-22.2'] + $cuerpo, $this->cuenta('Ana', self::ALL))->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->postJson(self::BASE.'consents', ['legalHash' => strtoupper($cuerpo['legalHash'])] + $cuerpo, $this->cuenta('Ana', self::ALL))->assertOk()->assertJsonPath('meta.replayed', true);
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

    /**
     * C-2 · La sonda P10 de la revisión de API, por HTTP: un socio con su
     * prospecto de WhatsApp (número X) y un prospecto SUELTO con X en otro
     * formato, su conversación y su propia concesión. Retirar la comercial del
     * socio guarda el seudónimo de X y calla también al suelto (como «no
     * contactar»); la lista del suelto lo dice (`numberRevoked`) y ninguna
     * respuesta lleva el número ni su seudónimo.
     */
    public function test_retirar_calla_tambien_al_prospecto_suelto_con_el_mismo_numero(): void
    {
        $p = $this->socioConWhatsapp();
        $suelto = $this->lead(['phone' => '+57 300 111 0001', 'consent_status' => 'granted']);
        $this->conversation($suelto, ['last_inbound_at' => self::utc('2026-11-01 15:00:00')]);
        $claveSuelto = SubjectKey::lead($suelto);
        $this->assertSame('granted', $this->motor($claveSuelto), 'antes, el suelto concede por sí mismo');
        $ana = $this->cuenta('Ana', self::ALL);

        $r = $this->postJson(self::BASE.'consents/revoke', $this->retirada($p), $ana)->assertCreated()->assertJsonPath('data.numberCurrent', null);
        $this->assertSame($p['ref'], DB::table('outreach_consents')->where('uuid', $r->json('data.uuid'))->value('phone_ref'), 'la retirada guarda el número de hoy');
        $this->assertSame('denied', $this->motor($p['key']));
        $this->assertSame('denied', $this->motor($claveSuelto), 'el suelto, por el número');

        $lista = $this->getJson(self::BASE.'consents?personType=lead&personId='.$suelto, $ana)->assertOk()
            ->assertJsonPath('data.purposes.commercial.state', 'none')->assertJsonPath('data.purposes.commercial.numberRevoked', true)
            ->assertJsonPath('data.purposes.commercial.effective', false)->assertJsonPath('data.purposes.service.numberRevoked', false)
            ->assertJsonPath('data.events', []);
        $socio = $this->getJson(self::BASE.'consents?personType=member&personId='.$p['member'], $ana)->assertOk()
            ->assertJsonPath('data.purposes.commercial.state', 'revoked')->assertJsonPath('data.purposes.commercial.numberRevoked', false)
            ->assertJsonPath('data.events.0.numberCurrent', null);
        foreach ([$r, $lista, $socio] as $respuesta) {
            foreach (['3001110001', '573001110001', '+57 300', (string) $p['ref'], (string) SubjectRef::of($p['key']), $p['key']] as $privado) {
                $this->assertStringNotContainsString($privado, (string) $respuesta->getContent());
            }
        }
    }

    /** C-2 · Una retirada sin número (la persona no lo tenía) es solo de su persona: no calla a quien tenga después ese número. */
    public function test_una_retirada_sin_numero_no_calla_a_nadie_mas(): void
    {
        $s = $this->socio([], ['phone' => null]);
        $uuid = $this->postJson(self::BASE.'consents/revoke', $this->retirada(['member' => $s['member']]), $this->cuenta('Ana', self::ALL))->assertCreated()->json('data.uuid');
        $this->assertNull(DB::table('outreach_consents')->where('uuid', $uuid)->value('phone_ref'));
        $suyo = $this->lead(['member_id' => $s['member'], 'phone' => self::PHONE, 'consent_status' => 'granted']);
        $this->conversation($suyo, ['last_inbound_at' => self::utc('2026-11-01 15:00:00')]);
        $suelto = $this->lead(['phone' => self::PHONE, 'consent_status' => 'granted']);
        $this->conversation($suelto, ['last_inbound_at' => self::utc('2026-11-01 15:00:00')]);
        $this->assertSame('denied', $this->motor(SubjectKey::member($s['member'])), 'a ella sí');
        $this->assertSame('granted', $this->motor(SubjectKey::lead($suelto)), 'el suelto no');
        $this->getJson(self::BASE.'consents?personType=lead&personId='.$suelto, $this->cuenta('Ana', self::ALL))->assertOk()
            ->assertJsonPath('data.purposes.commercial.numberRevoked', false);
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
            // M-7: la evidencia es texto, no marcado.
            'una etiqueta' => ['<script>alert(1)</script> firmado', 'invalid'],
            'un menor que' => ['Firmó en recepción < 5 min', 'invalid'],
            'un mayor que' => ['Firmó en recepción > ayer', 'invalid'],
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
            foreach (['Tel 300 123 45 67', 'ana@ejemplo.com', "a\tb", '<b>firmado</b>'] as $texto) {
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
        // C-2: un prospecto suelto con el mismo número, al que la retirada del socio tiene que callar también como la aplicación.
        $suelto = $this->lead(['phone' => self::PHONE, 'consent_status' => 'granted']);
        $this->conversation($suelto, ['last_inbound_at' => self::utc('2026-11-01 15:00:00')]);

        $this->withAppRole(function () use ($p, $ana, $beto, $suelto): void {
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
            [$estado, $mensaje] = $this->asApp(function () use ($p, $ana, $beto, $suelto): void {
                $pet = $this->postJson(self::BASE.'consents', $this->peticion($p), $ana)->assertCreated()->json('data.uuid');
                $this->postJson(self::BASE.'consents', $this->peticion($p), $ana)->assertStatus(409);
                $this->postJson(self::BASE."consents/{$pet}/confirm", ['clientRequestId' => (string) Str::uuid()], $beto)->assertCreated();
                $otra = $this->postJson(self::BASE.'consents', ['purpose' => 'commercial', 'clientRequestId' => (string) Str::uuid()] + $this->peticion($p), $ana)->assertCreated()->json('data.uuid');
                $this->postJson(self::BASE."consents/{$otra}/reject", ['clientRequestId' => (string) Str::uuid(), 'evidence' => 'Duplicada por error'], $beto)->assertCreated();
                $this->postJson(self::BASE.'consents/revoke', $this->retirada($p), $beto)->assertCreated();
                $this->getJson(self::BASE.'consents?personType=member&personId='.$p['member'], $ana)->assertOk()->assertJsonPath('data.purposes.commercial.state', 'revoked');
                $this->assertSame('denied', $this->motor($p['key']));
                $this->assertSame('denied', $this->motor(SubjectKey::lead($suelto)), 'la regla de los hermanos, como la aplicación');
                $this->getJson(self::BASE.'consents?personType=lead&personId='.$suelto, $ana)->assertOk()->assertJsonPath('data.purposes.commercial.numberRevoked', true);
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

    /**
     * informe-db-cierre §B.3, falta 1 · LA CARRERA VISTA DESDE EL HERMANO. Q, un prospecto suelto con el número de P
     * y su PROPIA confirmación del libro, concede. A deja EN VUELO una retirada de P con su número; B confirma, también
     * en vuelo, una petición ANTIGUA de P (anterior a la retirada): entra sin esperar, porque la guarda
     * (`ocon_grant_not_superseded`) no ve una retirada sin confirmar. Mientras A no confirma, Q y P conceden; en cuanto
     * confirma, P lee la retirada (cuenta en su lugar, después de la petición que B confirmó) y la regla de los hermanos
     * calla a Q: gana la negativa, aunque la confirmación de P haya llegado después.
     */
    public function test_solo_en_postgres_la_retirada_en_vuelo_vista_desde_el_hermano_que_comparte_el_numero(): void
    {
        $this->preparaCarrera();
        $p = $this->socioConWhatsapp();
        $q = $this->hermanoConSuConfirmacion($p);
        $pet = $this->pedida($p);
        $this->assertSame(['missing', 'granted'], [$this->motor($p['key']), $this->motor($q)], 'antes: P solo lo pidió, Q concede por su confirmación');

        [$a, $b] = [$this->pgConnect(), $this->pgConnect()];
        $this->pgExec($a, 'BEGIN');
        $this->assertSame(1, $this->pgExec($a, ...$this->insertOrIgnoreSql($this->consentRevocationRow(['member_id' => $p['member'], 'phone_ref' => $p['ref']]))));
        $this->pgExec($b, 'BEGIN');
        $this->assertSame([null, 1], $this->pgEntraSinEsperar($b, $this->insertOrIgnoreSql($this->consentResolutionRow($pet, 'granted')), 'la confirmación de la petición antigua'));
        $this->assertSame(['missing', 'granted'], [$this->motor($p['key']), $this->motor($q)], 'las dos en vuelo: ninguna existe');

        $this->pgExec($b, 'COMMIT');
        $this->assertSame(['granted', 'granted'], [$this->motor($p['key']), $this->motor($q)], 'confirmada la de B y la retirada aún en vuelo: P concede y Q también');

        $this->pgExec($a, 'COMMIT');
        $this->assertSame(['denied', 'denied'], [$this->motor($p['key']), $this->motor($q)], 'confirmada la retirada: P la lee y Q queda callado por el número');
        $this->assertSame(1, DB::connection(self::RACE)->table('outreach_consents')->where('request_uuid', $pet['uuid'])->where('kind', 'granted')->count(), 'la confirmación entró');
    }

    /**
     * informe-db-cierre §B.3, falta 2 · DOS RETIRADAS DE LA MISMA PERSONA EN VUELO, una con su número y otra sin él
     * (p. ej. la palabra de baja y recepción a la vez). No hay UNIQUE que las separe: entran las dos sin esperarse. La
     * que NO lleva número solo es de P (Q sigue concediendo cuando se confirma ella sola); la que lo lleva calla a Q.
     */
    public function test_solo_en_postgres_dos_retiradas_en_vuelo_entran_las_dos_y_la_del_numero_calla_al_hermano(): void
    {
        $this->preparaCarrera();
        $p = $this->socioConWhatsapp();
        $q = $this->hermanoConSuConfirmacion($p);
        $this->resolveConsent($this->pedida($p));
        $this->assertSame(['granted', 'granted'], [$this->motor($p['key']), $this->motor($q)], 'antes: los dos conceden');

        [$a, $b] = [$this->pgConnect(), $this->pgConnect()];
        $this->pgExec($a, 'BEGIN');
        $this->assertSame(1, $this->pgExec($a, ...$this->insertOrIgnoreSql($this->consentRevocationRow(['member_id' => $p['member'], 'phone_ref' => $p['ref']]))));
        $this->pgExec($b, 'BEGIN');
        $this->assertSame([null, 1], $this->pgEntraSinEsperar($b, $this->insertOrIgnoreSql($this->consentRevocationRow(['member_id' => $p['member'], 'actor_type' => 'admin',
            'recorded_by_admin_id' => 3, 'recorded_by_name' => 'Caro', 'recorded_by_role' => 'recepcion', 'evidence' => 'Pidió en recepción que no le escriban más'])), 'la retirada sin número'));
        $this->assertSame(['granted', 'granted'], [$this->motor($p['key']), $this->motor($q)], 'las dos en vuelo: ninguna existe');

        $this->pgExec($b, 'COMMIT');
        $this->assertSame(['denied', 'granted'], [$this->motor($p['key']), $this->motor($q)], 'la que no lleva número es solo de P');

        $this->pgExec($a, 'COMMIT');
        $this->assertSame(['denied', 'denied'], [$this->motor($p['key']), $this->motor($q)], 'la que lleva número calla también a Q');
        $c = DB::connection(self::RACE)->table('outreach_consents')->where('subject_key', $p['key'])->where('kind', 'revoked');
        $this->assertSame([2, 1], [(clone $c)->count(), (clone $c)->whereNotNull('phone_ref')->count()], 'entraron las dos');
    }

    /**
     * informe-db-cierre §B.3, falta 3 · EL ORDEN DE LLEGADA ES EL DE LOS IDS. El lector ordena por `id` (la posición
     * de cada evento; un `granted`, en la de su petición), y eso solo es el orden de llegada si la secuencia no reparte
     * bloques por sesión: `outreach_consents_id_seq` tiene CACHE 1 y no se debe cambiar. Con CACHE 20, la sesión A
     * daría 1 y 2 y la B 21, y una retirada de A posterior a una confirmación de B quedaría «antes».
     */
    public function test_solo_en_postgres_la_secuencia_del_libro_tiene_cache_1_y_los_ids_siguen_el_orden_de_llegada(): void
    {
        $this->preparaCarrera();
        $this->assertSame(1, (int) DB::scalar("SELECT s.seqcache FROM pg_sequence s WHERE s.seqrelid = pg_get_serial_sequence('outreach_consents', 'id')::regclass"));

        [$a, $b] = [$this->pgConnect(), $this->pgConnect()];
        $ids = [];
        foreach ([[$a, 7101], [$b, 7102], [$a, 7103], [$b, 7104]] as [$conexion, $socio]) {
            [$sql, $parametros] = $this->insertOrIgnoreSql($this->consentRevocationRow(['member_id' => $socio]));
            $r = @pg_query_params($conexion, $sql.' RETURNING id', $parametros);
            $this->assertNotFalse($r, (string) pg_last_error($conexion));
            $ids[] = (int) pg_fetch_result($r, 0, 0);
        }
        $ordenados = $ids;
        sort($ordenados);
        $this->assertSame($ordenados, $ids, 'A, B, A, B: cada id es mayor que el anterior, sea de la sesión que sea');
        $this->assertSame(3, $ids[3] - $ids[0], 'y sin huecos entre sesiones');
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

    /**
     * Q, un prospecto SUELTO con el número de P en otro formato, su conversación de WhatsApp y SU PROPIA confirmación
     * del libro para ese número (confirmada por la conexión de apoyo); su `consent_status` no concede nada por sí solo.
     *
     * @param  array{ref: string}  $p
     * @return string la clave de Q
     */
    private function hermanoConSuConfirmacion(array $p): string
    {
        $suelto = $this->lead(['phone' => '+57 300 111 0001', 'consent_status' => 'unknown']);
        $this->conversation($suelto, ['last_inbound_at' => self::utc('2026-11-01 15:00:00')]);
        $this->resolveConsent($this->requestConsent([
            'member_id' => null, 'lead_id' => $suelto, 'phone_ref' => $p['ref'], 'legal_version' => self::LEGAL_VERSION,
            'legal_hash' => hash('sha256', self::LEGAL_TEXT), 'recorded_by_admin_id' => 900,
        ]));

        return SubjectKey::lead($suelto);
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

    /**
     * La sentencia, asíncrona, tiene que TERMINAR sin esperar a nadie (como mucho 2 s): si se queda esperando un
     * cerrojo, la prueba falla diciendo a quién espera (y el tearDown deshace las dos conexiones).
     *
     * @param  array{0: string, 1: list<mixed>}  $sentencia
     * @return array{0: ?string, 1: int} [SQLSTATE o null, filas afectadas]
     */
    private function pgEntraSinEsperar(Connection $conexion, array $sentencia, string $que): array
    {
        $this->assertTrue(pg_send_query_params($conexion, ...$sentencia));
        for ($i = 0; $i < 80; $i++) {
            if (! pg_connection_busy($conexion)) {
                return $this->pgAsyncResult($conexion);
            }
            usleep(25000);
        }
        $bloqueadores = (string) DB::connection(self::RACE)->scalar('SELECT pg_blocking_pids(?::int)::text', [pg_get_pid($conexion)]);
        $this->fail("{$que} no entró sin esperar (la bloquean: {$bloqueadores}).");
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
