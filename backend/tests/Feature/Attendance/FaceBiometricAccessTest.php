<?php

namespace Tests\Feature\Attendance;

use App\Models\Admin;
use App\Models\Member;
use App\Models\MemberBiometric;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LOS ROSTROS DEL CONTROL DE ACCESO SOLO LOS VE UNA PERSONA.
 *
 * EL FALLO QUE SE CIERRA. El catálogo de rostros (`face-references`), la
 * imagen de cada uno (`face-image/{id}`) y la lista de quién no tiene rostro
 * (`face-enrollment/pending`) son lecturas del controlador de asistencias, y
 * por eso resolvían a `classes.view`. El token compartido de automatizaciones
 * obtiene TODA lectura salvo los dominios de personal, así que podía listar
 * los rostros de todos los socios y descargarlos uno a uno. Es un dato
 * biométrico (Ley 1581): no se reparte a una credencial sin persona detrás.
 *
 * LO QUE NO PUEDE ROMPERSE. El lector local de la sede (control de ingreso)
 * se autentica con la SESIÓN de un administrador de Recepción y descarga esos
 * mismos rostros para reconocer a quien llega. Una persona con su permiso de
 * siempre tiene que seguir leyéndolos exactamente igual, por cabecera y por
 * vale (`?ticket=`), que es como los carga el navegador del CRM.
 */
class FaceBiometricAccessTest extends TestCase
{
    use RefreshDatabase;

    private const IMAGEN = "\xFF\xD8\xFF\xE0rostro-de-prueba";

    private User $user;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create(['name' => 'Socia Con Rostro']);
        $this->member = Member::create([
            'user_id' => $this->user->id,
            'full_name' => 'Socia Con Rostro',
            'document_number' => '1075111222',
            'phone' => '+573001112222',
            'access_hash' => 'tok-rostro-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);

        Storage::disk('local')->put('members/rostro/face.jpg', self::IMAGEN);
        MemberBiometric::create([
            'member_id' => $this->member->id,
            'face_path' => 'members/rostro/face.jpg',
            'face_mime' => 'image/jpeg',
            'captured_at' => now(),
        ]);
    }

    private function recepcion(): array
    {
        return $this->adminAs(Admin::ROLE_RECEPCION);
    }

    private function imagen(): string
    {
        return "/api/attendances/face-image/{$this->user->id}";
    }

    // ── El token compartido no ve rostros ───────────────────────────────────

    public function test_el_token_compartido_no_lista_los_rostros(): void
    {
        $this->getJson('/api/attendances/face-references', $this->sharedTokenHeaders())
            ->assertStatus(403)
            ->assertJsonPath('code', 'person_required')
            ->assertJsonMissingPath('data');
    }

    public function test_el_token_compartido_no_descarga_una_imagen_facial(): void
    {
        $res = $this->get($this->imagen(), $this->sharedTokenHeaders());

        $res->assertStatus(403)->assertJsonPath('code', 'person_required');
        $this->assertStringNotContainsString('rostro-de-prueba', (string) $res->getContent());
    }

    public function test_el_token_compartido_no_ve_quien_falta_por_enrolar(): void
    {
        $this->getJson('/api/attendances/face-enrollment/pending', $this->sharedTokenHeaders())
            ->assertStatus(403)
            ->assertJsonPath('code', 'person_required');
    }

    public function test_el_token_compartido_no_enrola_ni_borra_rostros(): void
    {
        // La puerta ya lo impedía (escribir exige `classes.manage`, que el
        // token no tiene); esto fija que siga así aunque alguien le conceda
        // un día ese permiso a las automatizaciones.
        $this->post("/api/attendances/face-enrollment/{$this->member->id}", [
            'face' => UploadedFile::fake()->image('rostro.jpg'),
        ], $this->sharedTokenHeaders(['Accept' => 'application/json']))->assertStatus(403);

        $this->deleteJson("/api/attendances/face-enrollment/{$this->member->id}", [], $this->sharedTokenHeaders())
            ->assertStatus(403);

        $this->assertNotNull($this->member->refresh()->biometric);
        Storage::disk('local')->assertExists('members/rostro/face.jpg');
    }

    // ── Una persona sigue igual que antes ───────────────────────────────────

    public function test_recepcion_sigue_leyendo_el_catalogo_como_siempre(): void
    {
        $this->getJson('/api/attendances/face-references', $this->recepcion())
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.user_id', $this->user->id)
            ->assertJsonPath('data.0.member_id', $this->member->id)
            ->assertJsonPath('data.0.face_url', url($this->imagen()))
            ->assertJsonStructure(['data' => [['user_id', 'member_id', 'member_uuid', 'name', 'plan', 'face_url', 'captured_at']]]);
    }

    public function test_recepcion_sigue_descargando_la_imagen_por_cabecera(): void
    {
        $res = $this->get($this->imagen(), $this->recepcion())->assertOk();

        $this->assertSame(self::IMAGEN, $res->streamedContent());
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
    }

    public function test_recepcion_sigue_viendo_quien_falta_por_enrolar(): void
    {
        $sinRostro = Member::create([
            'user_id' => User::factory()->create()->id,
            'full_name' => 'Socio Sin Rostro',
            'document_number' => '1075333444',
            'phone' => '+573003334444',
            'access_hash' => 'tok-sin-rostro-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);

        $this->getJson('/api/attendances/face-enrollment/pending', $this->recepcion())
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.member_id', $sinRostro->id);
    }

    public function test_quien_gestiona_clases_sigue_enrolando_y_borrando_rostros(): void
    {
        $h = $this->adminWithPermissions(['classes.view', 'classes.manage']);
        $otro = Member::create([
            'user_id' => User::factory()->create()->id,
            'full_name' => 'Socio Nuevo',
            'document_number' => '1075555666',
            'phone' => '+573005556666',
            'access_hash' => 'tok-nuevo-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);

        $this->post("/api/attendances/face-enrollment/{$otro->id}", [
            'face' => UploadedFile::fake()->image('rostro.jpg'),
        ], $h + ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->assertNotNull($otro->refresh()->biometric);

        $this->deleteJson("/api/attendances/face-enrollment/{$otro->id}", [], $h)
            ->assertOk()
            ->assertJsonPath('biometric_status', Member::BIOMETRIC_PENDING);
        $this->assertNull($otro->refresh()->biometric);
    }

    public function test_el_lector_de_la_sede_hace_su_recorrido_completo_con_sesion_de_recepcion(): void
    {
        // Lo que hace el lector local en producción, en orden. Registrar
        // (`POST api/attendances`) exige `classes.manage`, que Recepción no
        // trae en el código: si el lector registra, es porque la pantalla de
        // Roles se lo concedió. Se reproduce esa concesión persistida.
        RolePermission::updateOrCreate(
            ['role' => Admin::ROLE_RECEPCION, 'permission' => 'classes.manage'],
            ['granted' => true],
        );
        app(RolePermissionPolicy::class)->flush();
        $h = $this->recepcion();

        $this->getJson('/api/users?page=1', $h)->assertOk();
        $this->getJson('/api/attendances/face-references', $h)->assertOk()->assertJsonPath('count', 1);
        $this->get($this->imagen(), $h)->assertOk();

        $this->postJson('/api/attendances', [
            'user_id' => $this->user->id, 'action' => 'entry', 'source' => 'facial', 'confidence' => 0.91,
        ], $h)->assertStatus(201)->assertJsonPath('attendance.source', 'facial');

        $this->postJson('/api/attendances', [
            'user_id' => $this->user->id, 'action' => 'exit', 'source' => 'auto-close',
            'confidence' => null, 'note' => 'Cierre automático del día',
        ], $h)->assertStatus(201)->assertJsonPath('attendance.source', 'auto-close');
    }

    // ── El vale de la URL (`<img src>`) ─────────────────────────────────────

    public function test_el_vale_de_una_persona_sigue_abriendo_la_imagen(): void
    {
        $ticket = $this->getJson('/api/admin/stream-ticket', $this->recepcion())
            ->assertOk()
            ->json('data.ticket');

        // Sin cabecera: así la pide `<img src>`.
        $res = $this->get($this->imagen().'?ticket='.urlencode($ticket))->assertOk();

        $this->assertSame(self::IMAGEN, $res->streamedContent());
    }

    public function test_el_token_compartido_no_obtiene_un_vale(): void
    {
        $this->getJson('/api/admin/stream-ticket', $this->sharedTokenHeaders())
            ->assertStatus(403);
    }

    public function test_un_vale_inventado_no_abre_la_imagen(): void
    {
        $this->get($this->imagen().'?ticket='.urlencode('no-soy-un-vale'))
            ->assertStatus(403);
    }
}
