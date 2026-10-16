<?php

namespace Tests\Feature;

use App\Models\ContractTemplate;
use App\Models\Member;
use App\Models\MemberContract;
use App\Models\Payment;
use App\Models\User;
use App\Services\OtpService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Segunda pasada sobre el registro público (token compartido de la APK).
 *
 * 43e6206 cerró las fichas activas. Quedaba abierto lo que la APK todavía
 * puede tocar —y eso es justo lo que un atacante con el token también toca—:
 *
 *   - Enlazar un usuario del CRM SIN ficha de socio (el cliente del mostrador,
 *     con su plan y sus pagos) sabiendo solo su cédula, o su correo si no
 *     tiene cédula. register le reescribía nombre, correo y teléfono y
 *     devolvía el access_hash: su plan, su OTP y su torniquete pasaban al
 *     atacante.
 *   - Reanudar por documento un alta a medias ajena (incluida toda alta
 *     completa que omitió el rostro: la APK nunca llama a biometric-skip y se
 *     queda «incompleta» hasta su primer login).
 *   - La ventana de los pasos: dos horas, y alcanzable desde cualquier red con
 *     solo el id secuencial.
 *   - Sin cupo de peticiones.
 *
 * La prueba que la APK publicada sigue sirviendo es la parte final: el mismo
 * formulario, reenviado desde el mismo teléfono, sigue funcionando.
 *
 * Límite que estas pruebas NO cierran, y conviene leerlo: el teléfono no es
 * prueba de posesión. Quien conozca la cédula Y el celular de alguien sigue
 * pudiendo enlazarse. El cierre robusto es un OTP en el registro, y exige
 * cambiar la app.
 */
class MemberRegistrationTakeoverTest extends TestCase
{
    use RefreshDatabase;

    private const APK_TOKEN = 'token-de-la-apk';

    private const DOC_CLIENTE = '1075777888';

    private const TEL_CLIENTE = '3007778888';

    private const TEL_ATACANTE = '3159998888';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.member_registration.token' => self::APK_TOKEN]);
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00', 'America/Bogota')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── ALTO-1: el cliente del mostrador ────────────────────────────────────

    public function test_un_cliente_del_mostrador_con_plan_no_se_reclama_solo_con_su_cedula(): void
    {
        $cliente = $this->clienteDelMostrador();
        $antes = $this->snapshot();

        $this->register([
            'document_number' => self::DOC_CLIENTE,
            'full_name' => 'Atacante',
            'email' => 'atacante@example.com',
            'phone' => self::TEL_ATACANTE,
            'gender' => 'Masculino',
            'birth_date' => '1990-01-01',
        ])
            ->assertStatus(409)
            // El mismo 409 que el de una ficha activa: no dice qué hay detrás.
            ->assertExactJson([
                'ok' => false,
                'message' => 'Ya existe una cuenta registrada con este documento o correo.',
                'status' => 'duplicate_document',
            ]);

        $this->assertSame($antes, $this->snapshot(), 'Un 409 no deja nada escrito.');
        $this->assertSame(0, Member::count(), 'Ninguna ficha nueva colgando del usuario del cliente.');
        $this->assertSame(self::TEL_CLIENTE, $cliente->fresh()->phone);
    }

    public function test_un_cliente_del_mostrador_se_enlaza_con_su_telefono_sin_perder_sus_datos(): void
    {
        // El CRM guardó el número con prefijo y espacios: se compara normalizado.
        $cliente = $this->clienteDelMostrador(['phone' => '+57 300 777 8888']);
        $campos = ['name', 'email', 'document', 'phone', 'gender', 'status', 'plan'];
        $antes = $cliente->fresh()->only($campos);
        $nacimiento = $cliente->fresh()->birth_date;

        $alta = $this->register([
            'document_number' => self::DOC_CLIENTE,
            'full_name' => 'Cliente Mostrador App',
            'email' => 'otro-correo@example.com',
            'phone' => self::TEL_CLIENTE,
            'gender' => 'Masculino',
            'birth_date' => '1991-02-02',
        ])->assertCreated();

        $this->assertSame($cliente->id, $alta->json('user_id'), 'Es SU usuario: conserva plan y pagos.');
        $this->assertNotEmpty($alta->json('access_hash'));
        $this->assertSame($antes, $cliente->fresh()->only($campos), 'Lo que el CRM ya tenía no se pisa, y el estado no baja a pending.');
        $this->assertEquals($nacimiento, $cliente->fresh()->birth_date);

        // Los pasos siguientes tampoco le bajan el estado.
        $id = (int) $alta->json('member_id');
        $this->paso('identity', $id)->assertOk();
        $this->assertSame('active', $cliente->fresh()->status);
        $this->contratoFirmado(Member::findOrFail($id));
        $this->paso('biometric', $id)->assertOk()->assertJsonPath('registration_status', Member::STATUS_ACTIVE);
        $this->assertSame('active', $cliente->fresh()->status);
    }

    public function test_un_usuario_antiguo_sin_ficha_ni_cedula_no_se_enlaza_por_correo_sin_su_telefono(): void
    {
        $sinCedula = User::create([
            'name' => 'Sin Cédula', 'email' => 'sindoc@example.com', 'password' => bcrypt('x'),
            'document' => null, 'phone' => '3005550000', 'status' => 'active',
        ]);
        $sinCedula->forceFill(['created_at' => now()->subMonths(5)])->save();
        $antes = $this->snapshot();

        $this->register([
            'document_number' => '1075555666', 'email' => 'sindoc@example.com', 'phone' => self::TEL_ATACANTE,
        ])->assertStatus(409)->assertJsonMissingPath('user_id');

        $this->assertSame($antes, $this->snapshot());
        $this->assertNull($sinCedula->fresh()->document, 'No se le asigna la cédula del atacante.');
    }

    // ── ALTO-2: reanudar por documento ──────────────────────────────────────

    public function test_un_alta_a_medias_ajena_no_se_reanuda_con_otro_telefono(): void
    {
        $alta = $this->register()->assertCreated();
        $id = (int) $alta->json('member_id');
        Carbon::setTestNow(now()->addDays(3));
        $antes = $this->snapshot();

        $this->register(['full_name' => 'Atacante', 'email' => 'atacante@example.com', 'phone' => self::TEL_ATACANTE])
            ->assertStatus(409)
            ->assertExactJson([
                'ok' => false,
                'message' => 'Ya existe una cuenta registrada con este documento o correo.',
                'status' => 'duplicate_document',
            ]);

        $this->assertSame($antes, $this->snapshot());
        $this->paso('identity', $id)->assertStatus(409)->assertJsonPath('code', 'registration_closed');
    }

    public function test_un_alta_completa_sin_rostro_no_se_reanuda_con_otro_telefono(): void
    {
        // La APK omite el rostro, sube identidad y firma el contrato, y NUNCA
        // llama a biometric-skip: la ficha queda «incompleta» hasta su login.
        $alta = $this->register(['biometric_status' => 'skipped'])->assertCreated();
        $id = (int) $alta->json('member_id');
        $this->paso('identity', $id)->assertOk();
        $this->contratoFirmado(Member::findOrFail($id));
        $this->assertSame(Member::STATUS_INCOMPLETE, Member::findOrFail($id)->status);

        Carbon::setTestNow(now()->addDays(30));
        $this->register(['full_name' => 'Atacante', 'phone' => self::TEL_ATACANTE])
            ->assertStatus(409)
            ->assertJsonMissingPath('access_hash');

        $this->assertSame('3104445566', app(OtpService::class)->resolvePhone(Member::findOrFail($id)));
    }

    public function test_la_reanudacion_con_el_mismo_telefono_en_otro_formato_sigue_funcionando(): void
    {
        $primera = $this->register()->assertCreated();

        $this->register(['phone' => '+57 310 444 5566'])
            ->assertOk()
            ->assertJsonPath('status', 'resumed')
            ->assertJsonPath('member_id', $primera->json('member_id'))
            ->assertJsonPath('access_hash', $primera->json('access_hash'));
    }

    public function test_un_alta_a_medias_sin_telefono_guardado_no_se_reanuda(): void
    {
        // Nada contra qué comparar: no hay prueba, así que no se entrega.
        Member::create([
            'full_name' => 'Alta Sin Teléfono', 'document_number' => '1075999888',
            'status' => Member::STATUS_PENDING_REGISTRATION,
        ]);
        $antes = $this->snapshot();

        $this->register()->assertStatus(409)->assertJsonMissingPath('access_hash');

        $this->assertSame($antes, $this->snapshot());
    }

    public function test_un_alta_a_medias_sin_usuario_no_se_reanuda_con_el_telefono_de_un_usuario_ajeno(): void
    {
        // La ficha a medias no tiene usuario del CRM. El atacante tiene uno
        // propio, sin documento ni ficha, y manda la cédula de la víctima con
        // SU correo y SU teléfono: el usuario que se encuentra por correo es el
        // suyo, y su teléfono no prueba nada sobre la ficha de la víctima
        // (revisión de seguridad de e8aa158, N1).
        Member::create([
            'full_name' => 'Víctima A Medias', 'document_number' => '1075999888',
            'phone' => '3104445566', 'status' => Member::STATUS_PENDING_REGISTRATION,
        ]);
        User::create([
            'name' => 'Atacante', 'email' => 'atacante@example.com', 'password' => bcrypt('x'),
            'phone' => self::TEL_ATACANTE, 'status' => 'active',
        ]);
        $antes = $this->snapshot();

        $this->register(['full_name' => 'Atacante', 'email' => 'atacante@example.com', 'phone' => self::TEL_ATACANTE])
            ->assertStatus(409)
            ->assertJsonMissingPath('access_hash');

        $this->assertSame($antes, $this->snapshot());
    }

    public function test_quien_cambio_de_numero_entra_por_otp_aunque_su_alta_este_incompleta(): void
    {
        // La salida de quien ya no tiene el número del alta: el login por OTP
        // (va al número guardado; el cambio de número es de recepción o de la
        // recuperación segura). El login admite fichas incompletas.
        $id = (int) $this->register(['biometric_status' => 'skipped'])->assertCreated()->json('member_id');
        $this->paso('identity', $id)->assertOk();
        $this->assertSame(Member::STATUS_INCOMPLETE, Member::findOrFail($id)->status);

        $login = $this->postJson('/api/members/login', ['document_number' => '1075999888', 'device_id' => 'equipo-1'])
            ->assertOk()
            ->assertJsonPath('data.requires_otp', true);

        $this->postJson('/api/members/login/verify', [
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => $login->json('data.dev_code'),
            'device_id' => 'equipo-1',
        ])->assertOk()->assertJsonPath('data.requires_otp', false);
    }

    // ── MEDIO-1: la ventana de los pasos ────────────────────────────────────

    public function test_la_ventana_del_alta_dura_quince_minutos(): void
    {
        $id = (int) $this->register()->assertCreated()->json('member_id');

        Carbon::setTestNow(now()->addMinutes(14));
        $this->paso('identity', $id)->assertOk();

        Carbon::setTestNow(now()->addMinutes(2));
        $this->paso('biometric', $id)->assertStatus(409)->assertJsonPath('code', 'registration_closed');
        $this->assertSame(Member::STATUS_INCOMPLETE, Member::findOrFail($id)->status);
    }

    public function test_la_ventana_queda_atada_a_la_red_que_hizo_register(): void
    {
        $id = (int) $this->register()->assertCreated()->json('member_id');
        $antes = $this->snapshot();

        // Otra red, con el id y el token de la APK: nada.
        $this->desde('203.0.113.7')->paso('identity', $id)
            ->assertStatus(409)->assertJsonPath('code', 'registration_closed');
        $this->desde('203.0.113.7')->paso('biometric', $id)->assertStatus(409);
        $this->assertSame($antes, $this->snapshot());

        // La misma persona que cambió de red (wifi → datos): la APK reintenta
        // empezando por register y la ventana se reabre en la red nueva.
        $this->desde('203.0.113.7')->register()->assertOk()->assertJsonPath('status', 'resumed');
        $this->desde('203.0.113.7')->paso('identity', $id)->assertOk();
    }

    public function test_en_ipv6_la_ventana_es_de_la_red_64_no_de_la_direccion(): void
    {
        // El teléfono rota su dirección temporal dentro del mismo /64.
        $id = (int) $this->desde('2001:db8:aa:1::10')->register()->assertCreated()->json('member_id');

        $this->desde('2001:db8:aa:1:9f3c::22')->paso('identity', $id)->assertOk();
        $this->desde('2001:db8:aa:2::10')->paso('biometric', $id)->assertStatus(409);
    }

    public function test_si_register_declaro_el_rostro_omitido_la_biometria_publica_se_rechaza(): void
    {
        $alta = $this->register(['biometric_status' => 'skipped'])->assertCreated();
        $id = (int) $alta->json('member_id');
        $this->paso('identity', $id)->assertOk();
        $this->contratoFirmado(Member::findOrFail($id));
        $antes = $this->snapshot();

        // Dentro de la ventana y desde la misma red (el wifi del gimnasio): un
        // tercero con el id ya no le pone su rostro ni la activa.
        $this->paso('biometric', $id)->assertStatus(409)->assertJsonPath('code', 'registration_closed');

        $this->assertSame($antes, $this->snapshot());
        $this->assertSame(Member::STATUS_INCOMPLETE, Member::findOrFail($id)->status);
    }

    public function test_si_el_reintento_ya_no_omite_el_rostro_la_biometria_se_acepta(): void
    {
        // Omitió el rostro, el envío falló, volvió atrás y se lo tomó.
        $id = (int) $this->register(['biometric_status' => 'skipped'])->assertCreated()->json('member_id');
        $this->register()->assertOk()->assertJsonPath('status', 'resumed');

        $this->paso('identity', $id)->assertOk();
        $this->paso('biometric', $id)->assertOk()->assertJsonPath('registration_status', Member::STATUS_ACTIVE);
        $this->assertSame(Member::BIOMETRIC_REGISTERED, Member::findOrFail($id)->biometric_status);
    }

    // ── BAJO-2: cupos por red ───────────────────────────────────────────────

    public function test_register_tiene_un_cupo_por_red(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->register(['document_number' => (string) (1075300000 + $i), 'email' => "e{$i}@example.com"])
                ->assertCreated();
        }

        $tope = $this->register(['document_number' => '1075300099', 'email' => 'e99@example.com'])
            ->assertStatus(429)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'too_many_requests');
        $this->assertIsString($tope->json('message'));
        $this->assertGreaterThan(0, $tope->json('retry_after'));
        $this->assertSame(10, Member::count());

        // El login de esta misma red no se ve afectado (cubo propio)...
        $this->postJson('/api/members/login', ['document_number' => '1075300000', 'device_id' => 'equipo-1'])
            ->assertOk();
        // ...y otra red no hereda el cupo.
        $this->desde('198.51.100.20')->register(['document_number' => '1075300098', 'email' => 'e98@example.com'])
            ->assertCreated();
    }

    public function test_los_pasos_tienen_su_propio_cupo_por_red(): void
    {
        $id = (int) $this->register()->assertCreated()->json('member_id');

        for ($i = 0; $i < 30; $i++) {
            $this->paso('legal-consent', $id)->assertOk();
        }
        $this->paso('legal-consent', $id)->assertStatus(429)->assertJsonPath('code', 'too_many_requests');

        // El cupo de los pasos no gasta el de register.
        $this->register()->assertOk()->assertJsonPath('status', 'resumed');
    }

    public function test_el_delete_publico_tiene_su_propio_cupo_por_red(): void
    {
        $id = (int) $this->register()->assertCreated()->json('member_id');

        for ($i = 0; $i < 10; $i++) {
            $this->deleteJson("/api/members/{$id}", [], $this->apk())->assertStatus(409);
        }
        $this->deleteJson("/api/members/{$id}", [], $this->apk())->assertStatus(429);
        $this->assertNotNull(Member::find($id));
    }

    // ── Utilidades ─────────────────────────────────────────────────────────

    private function apk(): array
    {
        return ['Authorization' => 'Bearer '.self::APK_TOKEN];
    }

    private function desde(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
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
            'biometric' => $this->post($uri, [
                'captured_at' => now()->toIso8601String(), 'bytes_length' => '2048',
                'normalizer_version' => 'v2', 'platform' => 'android',
                'face' => UploadedFile::fake()->image('face.jpg'),
            ], $this->apk() + ['Accept' => 'application/json']),
        };
    }

    /** Usuario del CRM creado en el mostrador hace meses: plan vigente y un pago. */
    private function clienteDelMostrador(array $user = []): User
    {
        $cliente = User::create(array_merge([
            'name' => 'Cliente Mostrador', 'email' => 'mostrador@example.com', 'password' => bcrypt('x'),
            'document' => self::DOC_CLIENTE, 'phone' => self::TEL_CLIENTE, 'status' => 'active',
            'gender' => 'Femenino', 'birth_date' => '1990-05-05',
            'plan' => 'Mensual', 'membership_end_date' => now()->addDays(20)->toDateString(),
        ], $user));
        $cliente->forceFill(['created_at' => now()->subMonths(8)])->save();
        Payment::create(['user_id' => $cliente->id, 'amount' => 80000, 'status' => 'paid', 'paid_at' => now()->subDays(10)]);

        return $cliente;
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

    private function snapshot(): array
    {
        $tablas = [
            'users', 'members', 'payments', 'member_contracts', 'member_identity_documents',
            'member_signatures', 'member_biometrics', 'member_legal_consents',
        ];

        $foto = [];
        foreach ($tablas as $tabla) {
            $foto[$tabla] = DB::table($tabla)->orderBy('id')->get()->map(fn ($fila): array => (array) $fila)->all();
        }
        $foto['ficheros'] = collect(Storage::disk('local')->allFiles())->sort()->values()->all();

        return $foto;
    }
}
