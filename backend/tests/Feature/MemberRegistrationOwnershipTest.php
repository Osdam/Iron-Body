<?php

namespace Tests\Feature;

use App\Models\ContractTemplate;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\MemberDeviceSession;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\User;
use App\Services\OtpService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El grupo `member.registration.token` es, en la práctica, PÚBLICO: el token va
 * compilado dentro de la APK y se extrae. Estas pruebas atacan las rutas reales
 * con ese token, como lo haría cualquiera que lo saque de la APK, y fijan que:
 *
 *   - una ficha con el alta TERMINADA (activa) —o suspendida, o eliminada— no
 *     se modifica, no se borra y no se expone por ninguna de estas rutas, y un
 *     rechazo deja la base y el disco exactamente como estaban;
 *   - un correo ajeno no sirve para enlazarse a otro usuario ni reescribirle el
 *     documento o el teléfono;
 *   - el alta legítima de la APK publicada (register → identity → [contrato] →
 *     biometric | biometric-skip) y su reanudación siguen funcionando.
 */
class MemberRegistrationOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private const APK_TOKEN = 'token-de-la-apk';

    private const DOC_VICTIMA = '1075200300';

    /** Teléfono real de la víctima: es a donde DEBE seguir yendo su OTP. */
    private const TEL_VICTIMA = '3001112222';

    private const TEL_ATACANTE = '3159998888';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.member_registration.token' => self::APK_TOKEN]);
        Storage::fake('local');
        // Reloj en un INSTANTE UTC (producción corre en UTC).
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00', 'America/Bogota')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Hallazgo 2: register ────────────────────────────────────────────────

    public function test_registro_con_el_documento_de_un_socio_activo_da_409_y_no_escribe_nada(): void
    {
        // Socio activo SIN members.phone (en producción hay 94): su OTP sale
        // de users.phone, que es justo lo que el ataque reescribía.
        $yuri = $this->socioActivo();
        $antes = $this->snapshot();

        $this->register([
            'full_name' => 'Atacante',
            'email' => 'atacante@example.com',
            'document_number' => '1.075.200-300',   // mismo documento, otro formato
            'phone' => self::TEL_ATACANTE,
        ])
            ->assertStatus(409)
            // Contrato que la app maneja (solo lee `message`). Ya sin
            // member_id, member_uuid, user_id ni registration_status: con el
            // token público eran un oráculo cédula → socio.
            ->assertExactJson([
                'ok' => false,
                'message' => 'Ya existe una cuenta registrada con este documento o correo.',
                'status' => 'duplicate_document',
            ]);

        $this->assertSame($antes, $this->snapshot(), 'Un 409 no puede dejar NADA escrito.');
        $this->assertSame(
            self::TEL_VICTIMA,
            app(OtpService::class)->resolvePhone($yuri->fresh()),
            'El código de login de Yuri tiene que seguir yendo a SU teléfono.',
        );
    }

    #[DataProvider('estadosCerrados')]
    public function test_una_ficha_suspendida_o_activa_no_se_reanuda_ni_entrega_su_access_hash(string $estado): void
    {
        $yuri = $this->socioActivo(member: ['status' => $estado]);
        $antes = $this->snapshot();

        $this->register(['document_number' => self::DOC_VICTIMA, 'phone' => self::TEL_ATACANTE])
            ->assertStatus(409)
            ->assertJsonPath('status', 'duplicate_document')
            ->assertJsonMissingPath('access_hash');

        $this->assertSame($antes, $this->snapshot());
        $this->assertSame($estado, $yuri->fresh()->status);
    }

    public static function estadosCerrados(): array
    {
        return [
            'activa' => [Member::STATUS_ACTIVE],
            'suspendida' => [Member::STATUS_SUSPENDED],
        ];
    }

    public function test_el_correo_de_un_socio_activo_no_le_reescribe_documento_ni_telefono(): void
    {
        $yuri = $this->socioActivo();
        $campos = ['name', 'email', 'document', 'phone', 'status'];
        $usuarioAntes = $yuri->user->only($campos);

        $res = $this->register([
            'full_name' => 'Atacante',
            'email' => 'yuri@example.com',       // el correo de Yuri
            'document_number' => '1099000111',   // documento propio
            'phone' => self::TEL_ATACANTE,
        ]);

        // Es un alta nueva y PROPIA, no la de Yuri.
        $res->assertCreated();
        $this->assertNotSame($yuri->id, $res->json('member_id'));
        $this->assertNotSame($yuri->user_id, $res->json('user_id'));

        $this->assertSame($usuarioAntes, $yuri->user->fresh()->only($campos), 'El usuario de Yuri no se toca.');
        $this->assertSame(self::DOC_VICTIMA, $yuri->fresh()->document_number);
        $this->assertSame(self::TEL_VICTIMA, app(OtpService::class)->resolvePhone($yuri->fresh()));

        // El usuario nuevo lleva su documento y un correo de reemplazo: el
        // correo es único en `users` y ya es de Yuri.
        $nuevo = User::findOrFail($res->json('user_id'));
        $this->assertSame('1099000111', $nuevo->document);
        $this->assertNotSame('yuri@example.com', $nuevo->email);
    }

    public function test_el_correo_de_un_registro_a_medias_ajeno_no_lo_secuestra(): void
    {
        $primera = $this->register([
            'full_name' => 'Primera Persona',
            'document_number' => '1075111222',
            'email' => 'compartido@example.com',
            'phone' => '3101112233',
        ])->assertCreated();
        $suya = Member::findOrFail($primera->json('member_id'));
        $antes = [$suya->only(['document_number', 'phone', 'full_name', 'user_id']), $suya->user->only(['document', 'phone', 'name'])];

        // Otra persona, otro documento, MISMO correo.
        $res = $this->register([
            'full_name' => 'Otra Persona',
            'document_number' => '1075333444',
            'email' => 'compartido@example.com',
            'phone' => self::TEL_ATACANTE,
        ]);

        $res->assertCreated();
        $this->assertNotSame($suya->id, $res->json('member_id'));
        $this->assertNotSame($primera->json('access_hash'), $res->json('access_hash'), 'No recibe el bearer de la otra ficha.');
        $suya->refresh();
        $this->assertSame($antes, [$suya->only(['document_number', 'phone', 'full_name', 'user_id']), $suya->user->only(['document', 'phone', 'name'])]);
    }

    public function test_el_usuario_del_crm_de_un_socio_activo_no_se_reescribe_aunque_el_documento_sea_el_suyo(): void
    {
        // Datos legados: el usuario del CRM tiene el documento D y su ficha de
        // socio activa otro (D'). Un registro con D encontraba ese usuario por
        // documento, lo REESCRIBÍA y solo después veía que la ficha está activa.
        $yuri = $this->socioActivo(member: ['document_number' => '1075200399']);
        $antes = $this->snapshot();

        $this->register(['document_number' => self::DOC_VICTIMA, 'phone' => self::TEL_ATACANTE, 'full_name' => 'Atacante'])
            ->assertStatus(409)
            ->assertJsonMissingPath('member_id');

        $this->assertSame($antes, $this->snapshot());
    }

    public function test_un_cliente_del_crm_sin_ficha_se_sigue_enlazando_por_documento(): void
    {
        // Camino legítimo de siempre: cliente del mostrador (creado hace
        // tiempo) que se da de alta en la app con su documento y SU teléfono.
        // Con otro teléfono, 409: ver MemberRegistrationTakeoverTest.
        $cliente = User::create([
            'name' => 'Cliente Mostrador', 'email' => 'mostrador@example.com', 'password' => bcrypt('x'),
            'document' => '1075777888', 'phone' => '3007778888', 'status' => 'active',
        ]);
        $cliente->forceFill(['created_at' => now()->subMonths(3)])->save();

        $res = $this->register(['document_number' => '1075777888', 'email' => 'otro@example.com', 'phone' => '3007778888'])
            ->assertCreated();

        $this->assertSame($cliente->id, $res->json('user_id'));
    }

    public function test_un_usuario_sin_ficha_ni_documento_se_enlaza_por_correo(): void
    {
        // Usuario recién creado y vacío (sin historia): se enlaza sin pedir
        // teléfono. Uno antiguo o con plan exige el suyo (TakeoverTest).
        $sinDocumento = User::create([
            'name' => 'Sin Documento', 'email' => 'sindoc@example.com', 'password' => bcrypt('x'),
            'document' => null, 'status' => 'pending',
        ]);

        $res = $this->register(['document_number' => '1075555666', 'email' => 'sindoc@example.com'])->assertCreated();

        $this->assertSame($sinDocumento->id, $res->json('user_id'));
        $this->assertSame('1075555666', $sinDocumento->fresh()->document);
    }

    public function test_un_registro_a_medias_que_ya_inicio_sesion_no_se_reanuda_por_documento(): void
    {
        // El alta quedó a medias (omitió la biometría) pero la persona ya entró
        // por OTP: es una cuenta en uso. Reanudarla por documento entregaba su
        // access_hash y reescribía su teléfono.
        $alta = $this->register(['document_number' => '1075444555', 'biometric_status' => 'skipped'])->assertCreated();
        $member = Member::findOrFail($alta->json('member_id'));
        $this->sesionDeDispositivo($member);
        $antes = $this->snapshot();

        $this->register(['document_number' => '1075444555', 'phone' => self::TEL_ATACANTE])
            ->assertStatus(409)
            ->assertJsonPath('status', 'duplicate_document')
            ->assertJsonMissingPath('access_hash');

        $this->assertSame($antes, $this->snapshot());
    }

    public function test_la_reanudacion_legitima_sigue_funcionando(): void
    {
        $primera = $this->register()->assertCreated();

        // La app vuelve a pulsar «Finalizar» tras un fallo de red: mismos datos.
        $this->register()
            ->assertOk()
            ->assertJsonPath('status', 'resumed')
            ->assertJsonPath('member_id', $primera->json('member_id'))
            ->assertJsonPath('member_uuid', $primera->json('member_uuid'))
            ->assertJsonPath('access_hash', $primera->json('access_hash'));
        $this->assertSame(1, Member::count());

        $this->paso('identity', $primera->json('member_id'))->assertOk();
    }

    public function test_la_reanudacion_bloquea_la_fila_del_alta_en_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            // SQLite no tiene SELECT … FOR UPDATE: el grammar lo omite.
            $this->markTestSkipped('El bloqueo de fila solo existe en PostgreSQL (producción).');
        }

        $this->register()->assertCreated();
        DB::enableQueryLog();
        $this->register()->assertOk();

        $bloqueos = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, 'from "members"') && str_contains($sql, 'for update'));
        $this->assertNotEmpty($bloqueos, 'La ficha encontrada por documento se lee con FOR UPDATE.');
    }

    public function test_una_alta_que_pierde_la_carrera_por_el_documento_no_deja_usuario_huerfano(): void
    {
        // Otra petición con el mismo documento inserta entre la comprobación y
        // el INSERT: el índice único salta. Respuesta 409 —nunca 500— y la
        // transacción deshace el usuario del CRM que esta petición ya creó.
        $colado = false;
        Member::creating(function (Member $m) use (&$colado): void {
            if ($colado) {
                return;
            }
            $colado = true;
            DB::table('members')->insert([
                'member_uuid' => (string) Str::uuid(),
                'access_hash' => Str::random(64),
                'full_name' => 'La otra petición',
                'document_number' => $m->document_number,
                'status' => Member::STATUS_PENDING_REGISTRATION,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->register(['document_number' => '1075121212'])
            ->assertStatus(409)
            ->assertJsonPath('status', 'duplicate_account');

        $this->assertSame(0, User::query()->where('document', '1075121212')->count());
    }

    // ── Alta completa de la APK publicada ──────────────────────────────────

    public function test_alta_completa_como_la_hace_la_apk_publicada(): void
    {
        $alta = $this->register()->assertCreated()
            ->assertJsonPath('registration_status', Member::STATUS_PENDING_REGISTRATION);
        $id = $alta->json('member_id');
        $this->assertNotEmpty($alta->json('access_hash'));

        $this->paso('identity', $id)->assertOk()->assertJsonPath('registration_status', Member::STATUS_INCOMPLETE);

        // Paso 4 de la APK: contrato por `member/contracts/*` con el
        // access_hash (auth.member). Sus plantillas PDF no existen en este
        // árbol (MemberContractTest falla en setUp por eso), así que se deja
        // el estado que ese paso deja: un contrato firmado, sin tocar
        // `members.status` (MemberContractService no lo cambia).
        $this->contratoFirmado(Member::findOrFail($id));

        $this->paso('biometric', $id)->assertOk()->assertJsonPath('registration_status', Member::STATUS_ACTIVE);

        $member = Member::findOrFail($id);
        $this->assertSame(Member::BIOMETRIC_REGISTERED, $member->biometric_status);
        $this->assertTrue(Storage::disk('local')->exists($member->identityDocument->front_path));
        $this->assertTrue(Storage::disk('local')->exists($member->biometric->face_path));

        // Desde aquí es un socio activo: la ruta pública ya no lo toca.
        $antes = $this->snapshot();
        $this->paso('biometric', $id)->assertStatus(409);
        $this->assertSame($antes, $this->snapshot());
    }

    public function test_alta_con_biometria_omitida_y_pasos_de_versiones_anteriores(): void
    {
        $id = $this->register(['biometric_status' => 'skipped'])->assertCreated()->json('member_id');

        $this->paso('identity', $id)->assertOk();
        $this->paso('legal-consent', $id)->assertOk();
        $this->paso('signature', $id)->assertOk();
        $this->paso('biometric-skip', $id)->assertOk()->assertJsonPath('registration_status', Member::STATUS_ACTIVE);

        $member = Member::findOrFail($id);
        $this->assertSame(Member::BIOMETRIC_SKIPPED, $member->biometric_status);
        $this->assertNotNull($member->legalConsent);
        $this->assertTrue(Storage::disk('local')->exists($member->signature->signature_path));
    }

    public function test_si_la_ventana_del_alta_caduca_el_reintento_de_la_app_la_reabre(): void
    {
        $id = $this->register()->assertCreated()->json('member_id');

        Carbon::setTestNow(now()->addDay());
        $this->paso('identity', $id)->assertStatus(409)->assertJsonPath('code', 'registration_closed');

        // Lo que hace la APK al reintentar: vuelve a empezar por register.
        $this->register()->assertOk()->assertJsonPath('status', 'resumed');
        $this->paso('identity', $id)->assertOk();
    }

    // ── Hallazgo 3: pasos por {member} ─────────────────────────────────────

    public static function pasos(): array
    {
        return [
            'identity' => ['identity'],
            'legal-consent' => ['legal-consent'],
            'signature' => ['signature'],
            'biometric' => ['biometric'],
            'biometric-skip' => ['biometric-skip'],
        ];
    }

    #[DataProvider('pasos')]
    public function test_ningun_paso_publico_toca_a_un_socio_activo(string $paso): void
    {
        $yuri = $this->socioActivo(member: ['biometric_status' => Member::BIOMETRIC_PENDING]);
        $this->ficherosPrevios($yuri);
        $antes = $this->snapshot();

        $this->paso($paso, $yuri->id)
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'registration_closed');

        $this->assertSame($antes, $this->snapshot(), "«{$paso}» no puede escribir ni borrar nada de un socio activo.");
    }

    #[DataProvider('pasos')]
    public function test_una_cuenta_eliminada_o_suspendida_no_revive_por_la_ruta_publica(string $paso): void
    {
        foreach ([Member::STATUS_DELETED, Member::STATUS_SUSPENDED] as $estado) {
            $doc = $estado === Member::STATUS_DELETED ? 'DEL-'.random_int(1000, 9999) : (string) random_int(1000000000, 1999999999);
            $ficha = $this->socioActivo($doc, ['status' => $estado], ['email' => "{$doc}@example.com"]);
            $antes = $this->snapshot();

            $this->paso($paso, $ficha->id)->assertStatus(409)->assertJsonPath('code', 'registration_closed');

            $this->assertSame($antes, $this->snapshot(), "«{$paso}» sobre una cuenta {$estado}.");
            $this->assertSame($estado, $ficha->fresh()->status);
        }
    }

    #[DataProvider('pasos')]
    public function test_un_alta_a_medias_ajena_no_se_escribe_recorriendo_ids(string $paso): void
    {
        // Alta abandonada hace semanas: nadie la ha retomado por register en
        // esta sesión. Antes bastaba su id (secuencial) y el token de la APK.
        $abandonada = Member::create([
            'full_name' => 'Alta Abandonada', 'document_number' => '1075808080', 'phone' => '3108080808',
            'status' => Member::STATUS_INCOMPLETE, 'biometric_status' => Member::BIOMETRIC_PENDING,
        ]);
        $antes = $this->snapshot();

        $this->paso($paso, $abandonada->id)->assertStatus(409)->assertJsonPath('code', 'registration_closed');

        $this->assertSame($antes, $this->snapshot());
    }

    public function test_la_ruta_publica_de_registros_incompletos_no_expone_datos(): void
    {
        Member::create([
            'full_name' => 'Alta A Medias', 'email' => 'amedias@example.com', 'document_number' => '1075606060',
            'phone' => '3106060606', 'status' => Member::STATUS_INCOMPLETE,
        ]);

        $res = $this->getJson('/api/members/incomplete', $this->apk());

        // La ruta GET ya no existe; la URL solo coincide con
        // DELETE members/{member}, de ahí el 405 en vez de un 404.
        $res->assertStatus(405);
        foreach (['1075606060', '3106060606', 'amedias@example.com', 'Alta A Medias'] as $dato) {
            $this->assertStringNotContainsString($dato, $res->getContent());
        }

        // El CRM los sigue viendo por su ruta, con sesión admin.
        $this->getJson('/api/admin/members/incomplete', $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.document', '1075606060');
    }

    // ── Hallazgo 1: DELETE members/{member} ────────────────────────────────

    public function test_delete_publico_sobre_un_socio_activo_se_rechaza_y_no_borra_nada(): void
    {
        $yuri = $this->socioActivo();
        $this->ficherosPrevios($yuri);
        Payment::create(['user_id' => $yuri->user_id, 'amount' => 80000, 'status' => 'paid', 'paid_at' => now()]);
        $antes = $this->snapshot();

        $this->deleteJson("/api/members/{$yuri->id}", [], $this->apk())
            ->assertStatus(409)
            ->assertJsonPath('code', 'registration_closed');

        $this->assertSame($antes, $this->snapshot(), 'Ni ficha, ni usuario, ni pagos, ni ficheros.');
    }

    public function test_delete_publico_no_borra_ni_un_alta_recien_empezada_y_sin_historia(): void
    {
        // La APK nunca llamó a esta ruta. Antes descartaba el alta en curso de
        // la ventana: la de otra persona en el mismo wifi. Ahora no borra nada.
        $alta = $this->register()->assertCreated();
        $id = $alta->json('member_id');
        $this->paso('identity', $id)->assertOk();
        $uuid = $alta->json('member_uuid');
        $this->assertNotEmpty(Storage::disk('local')->allFiles("members/{$uuid}"));
        $antes = $this->snapshot();

        $this->deleteJson("/api/members/{$id}", [], $this->apk())
            ->assertStatus(409)
            ->assertJsonPath('code', 'registration_closed');

        $this->assertSame($antes, $this->snapshot());
        $this->assertNotNull(Member::find($id));
        $this->assertNotNull(User::find($alta->json('user_id')));
    }

    public function test_delete_publico_de_un_alta_a_medias_fuera_de_su_sesion_se_rechaza(): void
    {
        $abandonada = Member::create([
            'full_name' => 'Alta Abandonada', 'document_number' => '1075909090', 'status' => Member::STATUS_PENDING_REGISTRATION,
        ]);

        $this->deleteJson("/api/members/{$abandonada->id}", [], $this->apk())->assertStatus(409);

        $this->assertNotNull($abandonada->fresh());
    }

    #[DataProvider('historias')]
    public function test_delete_publico_de_un_alta_a_medias_con_historia_se_rechaza(string $historia): void
    {
        $alta = $this->register()->assertCreated();
        $member = Member::findOrFail($alta->json('member_id'));
        $this->paso('identity', $member->id)->assertOk();

        match ($historia) {
            'pago' => Payment::create(['user_id' => $member->user_id, 'amount' => 80000, 'status' => 'paid', 'paid_at' => now()]),
            'contrato firmado' => $this->contratoFirmado($member),
            'cuenta por cobrar' => Receivable::create([
                'member_id' => $member->id, 'debtor_type' => 'member', 'debtor_id' => $member->id,
                'type' => 'gym', 'concept' => 'Plan a plazos', 'total_amount' => 50000, 'status' => 'pending',
            ]),
            'usuario previo del crm' => User::whereKey($member->user_id)->update(['created_at' => now()->subMonths(6)]),
        };
        $antes = $this->snapshot();

        $this->deleteJson("/api/members/{$member->id}", [], $this->apk())->assertStatus(409);

        $this->assertSame($antes, $this->snapshot());
    }

    public static function historias(): array
    {
        return [
            'pago' => ['pago'],
            'contrato firmado' => ['contrato firmado'],
            'cuenta por cobrar' => ['cuenta por cobrar'],
            'usuario previo del crm' => ['usuario previo del crm'],
        ];
    }

    public function test_el_borrado_del_crm_sigue_funcionando(): void
    {
        $socio = $this->socioActivo();
        $this->ficherosPrevios($socio);

        $this->deleteJson("/api/admin/members/{$socio->id}", [], $this->adminHeaders())->assertNoContent();

        $this->assertNull(Member::find($socio->id));
        $this->assertNull(User::find($socio->user_id));
        $this->assertSame([], Storage::disk('local')->allFiles("members/{$socio->member_uuid}"));
    }

    public function test_si_el_borrado_falla_los_ficheros_del_socio_no_se_pierden(): void
    {
        // receivables.member_id es RESTRICT: el DELETE de la ficha falla dentro
        // de la transacción. Antes los ficheros ya se habían borrado.
        $socio = $this->socioActivo();
        $this->ficherosPrevios($socio);
        Receivable::create([
            'member_id' => $socio->id, 'debtor_type' => 'member', 'debtor_id' => $socio->id,
            'type' => 'gym', 'concept' => 'Plan a plazos', 'total_amount' => 50000, 'status' => 'pending',
        ]);
        $ficheros = Storage::disk('local')->allFiles("members/{$socio->member_uuid}");
        $this->assertCount(4, $ficheros);

        $this->deleteJson("/api/admin/members/{$socio->id}", [], $this->adminHeaders())->assertStatus(500);

        $this->assertNotNull($socio->fresh());
        $this->assertSame($ficheros, Storage::disk('local')->allFiles("members/{$socio->member_uuid}"));
    }

    // ── Auditoría ──────────────────────────────────────────────────────────

    public function test_los_rechazos_quedan_registrados_sin_el_documento_en_claro(): void
    {
        $yuri = $this->socioActivo();
        Log::spy();

        $this->register(['document_number' => self::DOC_VICTIMA, 'phone' => self::TEL_ATACANTE])->assertStatus(409);
        $this->paso('biometric', $yuri->id)->assertStatus(409);
        $this->deleteJson("/api/members/{$yuri->id}", [], $this->apk())->assertStatus(409);

        foreach (['register', 'biometric', 'discard'] as $accion) {
            Log::shouldHaveReceived('warning')
                ->with('member:registration:rejected', Mockery::on(function (array $ctx) use ($accion, $yuri): bool {
                    return $ctx['action'] === $accion
                        && $ctx['member_id'] === $yuri->id
                        && ! str_contains(json_encode($ctx), self::DOC_VICTIMA)
                        && ! str_contains(json_encode($ctx), self::TEL_ATACANTE)
                        && ($accion !== 'register' || preg_match('/^[0-9a-f]{16}$/', (string) $ctx['document_hash']) === 1);
                }))
                ->once();
        }
    }

    // ── Utilidades ─────────────────────────────────────────────────────────

    private function apk(): array
    {
        return ['Authorization' => 'Bearer '.self::APK_TOKEN];
    }

    /** POST members/register con el cuerpo que manda la APK publicada. */
    private function register(array $override = []): TestResponse
    {
        return $this->postJson('/api/members/register', array_merge([
            'full_name' => 'Laura Gómez',
            'email' => 'laura@example.com',
            'document_number' => '1075999888',
            'phone' => '3104445566',
            'gender' => 'Femenino',
            'goal' => 'Fuerza',
            'training_level' => 'Principiante',
            'injuries' => '',
            'document_type' => 'CC',
            'birth_date' => '1996-04-12',
            'is_minor' => false,
        ], $override), $this->apk());
    }

    /** Un paso por {member} con el cuerpo (multipart) que manda la APK. */
    private function paso(string $paso, int $memberId): TestResponse
    {
        $uri = "/api/members/{$memberId}/{$paso}";

        return match ($paso) {
            'identity' => $this->post($uri, [
                'document_type' => 'CC', 'document_number' => '1075999888', 'birth_date' => '1996-04-12',
                'ocr_full_name' => 'LAURA GOMEZ', 'ocr_confidence' => '0.82', 'identity_status' => 'verified',
                'front' => UploadedFile::fake()->image('front.jpg'), 'back' => UploadedFile::fake()->image('back.jpg'),
            ], $this->apk() + ['Accept' => 'application/json']),
            'legal-consent' => $this->postJson($uri, [
                'terms_and_conditions' => true, 'data_processing' => true, 'truthfulness' => true,
                'service_contract' => true, 'physical_risk_waiver' => true,
            ], $this->apk()),
            'signature' => $this->post($uri, [
                'kind' => 'drawn', 'signature' => UploadedFile::fake()->image('firma.png'),
            ], $this->apk() + ['Accept' => 'application/json']),
            'biometric' => $this->post($uri, [
                'captured_at' => now()->toIso8601String(), 'bytes_length' => '2048',
                'normalizer_version' => 'v2', 'platform' => 'android',
                'face' => UploadedFile::fake()->image('face.jpg'),
            ], $this->apk() + ['Accept' => 'application/json']),
            'biometric-skip' => $this->postJson($uri, [], $this->apk()),
        };
    }

    /** Socio con alta terminada, como lo deja el CRM (User + Member). */
    private function socioActivo(string $doc = self::DOC_VICTIMA, array $member = [], array $user = []): Member
    {
        $u = User::create(array_merge([
            'name' => 'Yuri Víctima',
            'email' => 'yuri@example.com',
            'password' => bcrypt('secret-password'),
            'document' => $doc,
            'phone' => self::TEL_VICTIMA,
            'status' => 'active',
        ], $user));

        return Member::create(array_merge([
            'user_id' => $u->id,
            'full_name' => 'Yuri Víctima',
            'email' => $u->email,
            'document_number' => $doc,
            'phone' => null,
            'gender' => 'Femenino',
            'status' => Member::STATUS_ACTIVE,
        ], $member))->fresh();
    }

    /** Documento, firma y rostro ya guardados (lo que un ataque borraría). */
    private function ficherosPrevios(Member $member): void
    {
        $guardar = fn (string $carpeta, string $nombre): string => UploadedFile::fake()->image($nombre)
            ->store("members/{$member->member_uuid}/{$carpeta}", 'local');

        $member->identityDocument()->create([
            'document_number' => $member->document_number, 'identity_status' => 'verified',
            'front_path' => $guardar('identity/front', 'f.jpg'), 'front_mime' => 'image/jpeg', 'front_size' => 10,
            'back_path' => $guardar('identity/back', 'b.jpg'), 'back_mime' => 'image/jpeg', 'back_size' => 10,
        ]);
        $member->signature()->create([
            'kind' => 'drawn', 'signature_path' => $guardar('legal/signatures', 's.png'),
            'signature_mime' => 'image/png', 'signature_size' => 10,
        ]);
        $member->biometric()->create([
            'face_path' => $guardar('biometrics/faces', 'face.jpg'), 'face_mime' => 'image/jpeg', 'face_size' => 10,
            'captured_at' => now(), 'bytes_length' => 10,
        ]);
    }

    private function contratoFirmado(Member $member): MemberContract
    {
        $plantilla = ContractTemplate::firstOrCreate(
            ['template_key' => 'workout_registration'],
            ['name' => 'Registro', 'version' => '1', 'source_file_path' => 'contracts/workout.pdf'],
        );

        return MemberContract::create([
            'contract_uuid' => (string) Str::uuid(), 'member_id' => $member->id,
            'contract_template_id' => $plantilla->id, 'contract_type' => 'workout_registration',
            'status' => MemberContract::STATUS_SIGNED, 'signed_at' => now(),
        ]);
    }

    private function sesionDeDispositivo(Member $member): void
    {
        MemberDeviceSession::create([
            'uuid' => (string) Str::uuid(), 'member_id' => $member->id, 'device_id' => 'dev-1',
            'token_hash' => hash('sha256', Str::random(40)), 'last_seen_at' => now(),
        ]);
    }

    /**
     * Todo lo que un ataque por estas rutas podría tocar, fila a fila, más los
     * ficheros del disco privado. Comparar dos fotos es comprobar que un
     * rechazo no escribió NADA.
     */
    private function snapshot(): array
    {
        $tablas = [
            'users', 'members', 'payments', 'receivables', 'member_contracts', 'member_identity_documents',
            'member_signatures', 'member_biometrics', 'member_legal_consents', 'member_guardians',
        ];

        $foto = [];
        foreach ($tablas as $tabla) {
            $foto[$tabla] = DB::table($tabla)->orderBy('id')->get()->map(fn ($fila): array => (array) $fila)->all();
        }
        $foto['ficheros'] = collect(Storage::disk('local')->allFiles())->sort()->values()->all();

        return $foto;
    }
}
