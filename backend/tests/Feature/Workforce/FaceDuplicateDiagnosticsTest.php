<?php

namespace Tests\Feature\Workforce;

use App\Models\FaceDuplicateMatch;
use App\Models\Identity;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Models\TrainerFaceReference;
use App\Support\Workforce\FaceVector;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PDOException;
use Tests\Feature\Workforce\Concerns\WorkforceKit;
use Tests\TestCase;

/**
 * Diagnóstico del rechazo por «rostro de otra persona» (caso 2026-10-26: una
 * persona rechazada seis veces sin que la traza dijera contra qué plantilla).
 *
 * Lo que fija: el rechazo es el mismo de siempre; cada plantilla dentro del
 * umbral queda con su id y su distancia en `face_duplicate_matches`; eso no
 * sale por la respuesta, ni por la bitácora, ni por el CRM; caduca; y si no se
 * puede guardar, el rostro se rechaza igual.
 */
class FaceDuplicateDiagnosticsTest extends TestCase
{
    use RefreshDatabase;
    use WorkforceKit;

    private const ANA = '1075000001';

    private const PEDRO = '1075000009';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reloj('2026-10-26 14:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reloj(string $bogota): void
    {
        Carbon::setTestNow(Carbon::parse($bogota, 'America/Bogota')->utc());
    }

    // ── Ayudantes ───────────────────────────────────────────────────────────

    /**
     * Enrolamiento por el camino real con capturas EXACTAS (el de la utilería
     * genera las suyas): la distancia esperada se calcula con las mismas.
     *
     * @param  list<list<float>>  $captures
     */
    private function enrollWith(Identity $identity, string $document, array $captures, array $tablet): TestResponse
    {
        $code = $this->postJson("/api/admin/biometrics/identities/{$identity->uuid}/enrollment-code", ['reason' => 'Registro en recepción'], $this->adminWithPermissions(['biometrics.manage']))
            ->assertCreated()->json('code');
        $start = $this->signed('POST', '/api/kiosk/enrollment/start', ['employee_ref' => $this->ref($document, $tablet), 'authorization_code' => $code], $tablet)->assertOk();

        return $this->signed('POST', '/api/kiosk/enrollment/complete', [
            'enrollment_token' => $start->json('enrollment.token'),
            'consent_version' => $start->json('consent.version'),
            'consent_accepted' => true,
            'embeddings' => $captures,
            'operation_id' => (string) Str::uuid(),
        ], $tablet);
    }

    /** @return list<list<float>> */
    private function capturesOf(array $face): array
    {
        return [$this->capture($face, 1), $this->capture($face, 2), $this->capture($face, 3)];
    }

    /** Vector unitario a distancia euclídea `d` exacta del eje 0. */
    private function atDistanceFromAxis(float $d): array
    {
        $cos = 1 - ($d * $d) / 2;
        $v = array_fill(0, 192, 0.0);
        $v[0] = $cos;
        $v[1] = sqrt(1 - $cos * $cos);

        return $v;
    }

    private function axis(): array
    {
        $v = array_fill(0, 192, 0.0);
        $v[0] = 1.0;

        return $v;
    }

    /**
     * Una plantilla puesta directamente (estado de una galería real: heredada
     * del portal, o de otra persona que ya está registrada).
     */
    private function storedReference(?Identity $identity, ?Trainer $trainer, array $vector, bool $consent): TrainerFaceReference
    {
        $ref = new TrainerFaceReference([
            'identity_id' => $identity?->id,
            'trainer_id' => $trainer?->id,
            'status' => TrainerFaceReference::STATUS_ACTIVE,
            'enrolled_at' => now(),
            'template_version' => (string) config('workforce.face.template_version'),
            'enrolled_via' => $consent ? TrainerFaceReference::VIA_KIOSK : TrainerFaceReference::VIA_TRAINER_PORTAL,
            'consent_version' => $consent ? '2026-10-v1' : null,
        ]);
        $ref->storeVector($vector);
        $ref->save();

        return $ref;
    }

    private function referenceOf(Identity $identity): TrainerFaceReference
    {
        return TrainerFaceReference::query()->where('identity_id', $identity->id)->sole();
    }

    /** @param list<list<float>> $captures */
    private function expectedDistance(TrainerFaceReference $ref, array $captures): float
    {
        return round((float) FaceVector::distance($ref->fresh()->vector(), FaceVector::template($captures)), 4);
    }

    // ── El rechazo sigue igual, ahora con diagnóstico ───────────────────────

    public function test_el_rostro_de_otra_persona_se_sigue_rechazando_y_queda_con_que_plantilla_y_a_que_distancia(): void
    {
        $tablet = $this->tablet();
        $ana = $this->receptionist(self::ANA);
        $this->enroll($ana, self::ANA, $this->face(1), $tablet)->assertCreated();
        $anaRef = $this->referenceOf($ana);

        $pedro = $this->receptionist(self::PEDRO, 'Pedro Gómez');
        $captures = $this->capturesOf($this->capture($this->face(1), 50, 0.05));
        $this->enrollWith($pedro, self::PEDRO, $captures, $tablet)
            ->assertStatus(409)->assertJsonPath('code', 'face_already_linked');

        $this->assertNull(TrainerFaceReference::query()->where('identity_id', $pedro->id)->first(), 'El rechazo no deja plantilla.');
        $log = TrainerAuditLog::query()->where('event', 'biometric.duplicate_rejected')->sole();
        $this->assertSame($pedro->id, $log->identity_id);

        $match = FaceDuplicateMatch::query()->sole();
        $this->assertSame($log->id, (int) $match->audit_log_id);
        $this->assertSame($anaRef->id, (int) $match->face_reference_id);
        $this->assertGreaterThan(0.0, $match->distance);
        $this->assertLessThanOrEqual(1.0, $match->distance);
        $this->assertEqualsWithDelta($this->expectedDistance($anaRef, $captures), $match->distance, 0.0001);
    }

    public function test_guarda_cada_plantilla_que_coincide_ordenadas_por_distancia_y_ninguna_que_no(): void
    {
        $tablet = $this->tablet();
        $ana = $this->receptionist(self::ANA);
        $this->enroll($ana, self::ANA, $this->face(1), $tablet)->assertCreated();
        $marta = $this->receptionist('1075000003', 'Marta Díaz');
        $this->enroll($marta, '1075000003', $this->face(3), $tablet)->assertCreated();
        // Otra persona ya registrada, DESPUÉS que Ana y MÁS cerca de Pedro: el
        // orden de las filas no puede salir del orden de inserción.
        $pedroFace = $this->capture($this->face(1), 50, 0.05);
        $luis = $this->receptionist('1075000004', 'Luis Peña');
        $luisRef = $this->storedReference($luis, null, $this->capture($pedroFace, 61, 0.01), consent: true);

        $pedro = $this->receptionist(self::PEDRO, 'Pedro Gómez');
        $captures = $this->capturesOf($pedroFace);
        $this->enrollWith($pedro, self::PEDRO, $captures, $tablet)->assertStatus(409);

        $expected = [
            $this->referenceOf($ana)->id => $this->expectedDistance($this->referenceOf($ana), $captures),
            $luisRef->id => $this->expectedDistance($luisRef, $captures),
        ];
        asort($expected);
        $this->assertSame([$luisRef->id, $this->referenceOf($ana)->id], array_keys($expected), 'Premisa: Luis más cerca que Ana.');

        $rows = FaceDuplicateMatch::query()->orderBy('id')->get();
        $this->assertSame(array_keys($expected), $rows->map(fn ($r) => (int) $r->face_reference_id)->all(), 'Una fila por plantilla que coincide, la más cercana primero.');
        foreach ($rows as $row) {
            $this->assertEqualsWithDelta($expected[(int) $row->face_reference_id], $row->distance, 0.0001);
        }
        $this->assertNotContains($this->referenceOf($marta)->id, $rows->pluck('face_reference_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(1, TrainerAuditLog::query()->where('event', 'biometric.duplicate_rejected')->count(), 'Un rechazo, una traza.');
    }

    public function test_un_registro_sin_coincidencias_no_deja_diagnostico(): void
    {
        $tablet = $this->tablet();
        $this->enroll($this->receptionist(self::ANA), self::ANA, $this->face(1), $tablet)->assertCreated();
        $this->enroll($this->receptionist(self::PEDRO, 'Pedro Gómez'), self::PEDRO, $this->face(2), $tablet)->assertCreated();

        $this->assertSame(0, FaceDuplicateMatch::count());
        $this->assertSame(0, TrainerAuditLog::query()->where('event', 'biometric.duplicate_rejected')->count());
    }

    public function test_el_umbral_no_cambia_coincide_a_099_y_no_a_101(): void
    {
        $tablet = $this->tablet();
        $ana = $this->receptionist(self::ANA);
        $anaRef = $this->storedReference($ana, null, $this->axis(), consent: true);

        $pedro = $this->receptionist(self::PEDRO, 'Pedro Gómez');
        $near = $this->atDistanceFromAxis(0.99);
        $this->enrollWith($pedro, self::PEDRO, [$near, $near, $near], $tablet)
            ->assertStatus(409)->assertJsonPath('code', 'face_already_linked');
        $match = FaceDuplicateMatch::query()->sole();
        $this->assertSame($anaRef->id, (int) $match->face_reference_id);
        $this->assertEqualsWithDelta(0.99, $match->distance, 0.0001);

        $far = $this->atDistanceFromAxis(1.01);
        $this->enrollWith($pedro, self::PEDRO, [$far, $far, $far], $tablet)->assertCreated();
        $this->assertSame(1, FaceDuplicateMatch::count(), 'Fuera del umbral no hay coincidencia que guardar.');
    }

    // ── Revocar y volver a registrarse (caso del entrenador revocado) ────────

    public function test_una_plantilla_heredada_revocada_no_bloquea_que_la_misma_persona_se_registre_de_nuevo_con_codigo(): void
    {
        $tablet = $this->tablet();
        $coach = $this->floorTrainer('1075000002');
        $legacy = $this->storedReference($coach->identity, $coach, $this->face(7), consent: false);

        $this->postJson("/api/admin/biometrics/identities/{$coach->identity->uuid}/revoke", ['reason' => 'No funciona el código'], $this->adminWithPermissions(['biometrics.manage']))
            ->assertOk();
        $legacy->refresh();
        $this->assertSame(TrainerFaceReference::STATUS_REVOKED, $legacy->status);
        $this->assertNull($legacy->template, 'Revocar borra la plantilla.');

        // Sin el código de un administrador no hay registro nuevo.
        $this->signed('POST', '/api/kiosk/enrollment/start', ['employee_ref' => $this->ref('1075000002', $tablet), 'authorization_code' => '00000000'], $tablet)
            ->assertStatus(422)->assertJsonPath('code', 'authorization_code_not_issued');

        $this->enroll($coach->identity, '1075000002', $this->face(7), $tablet)
            ->assertCreated()->assertJsonPath('biometric.status', 'active');

        $this->assertSame(1, TrainerFaceReference::count(), 'Se renueva la misma fila.');
        $this->assertSame(0, FaceDuplicateMatch::count());
        $this->assertSame(0, TrainerAuditLog::query()->where('event', 'biometric.duplicate_rejected')->count());
        $this->assertSame(1, TrainerAuditLog::query()->where('event', 'biometric.revoked')->count(), 'La traza de la revocación se conserva.');
    }

    public function test_renovar_la_plantilla_propia_no_choca_consigo_misma(): void
    {
        $tablet = $this->tablet();
        $ana = $this->receptionist(self::ANA);
        $this->enroll($ana, self::ANA, $this->face(1), $tablet)->assertCreated();
        $this->enroll($ana, self::ANA, $this->face(1), $tablet)->assertCreated();

        $this->assertSame(1, TrainerFaceReference::count());
        $this->assertSame(0, FaceDuplicateMatch::count());
    }

    public function test_una_plantilla_heredada_sin_consentimiento_tambien_bloquea_y_el_diagnostico_la_identifica(): void
    {
        // Comportamiento ACTUAL, sin cambiar: `active()` no exige consentimiento.
        // Quitarlas del 1:N es una propuesta pendiente de decisión (2026-10-26);
        // mientras tanto el diagnóstico tiene que poder señalarlas.
        $tablet = $this->tablet();
        $coach = $this->floorTrainer('1075000002');
        $legacy = $this->storedReference($coach->identity, $coach, $this->face(1), consent: false);

        $pedro = $this->receptionist(self::PEDRO, 'Pedro Gómez');
        $this->enroll($pedro, self::PEDRO, $this->face(1), $tablet)
            ->assertStatus(409)->assertJsonPath('code', 'face_already_linked');

        $this->assertSame($legacy->id, (int) FaceDuplicateMatch::query()->sole()->face_reference_id);
    }

    // ── Privacidad: el diagnóstico no sale de la tabla ───────────────────────

    public function test_la_respuesta_del_kiosko_no_dice_con_quien_ni_a_que_distancia(): void
    {
        $tablet = $this->tablet();
        $this->enroll($this->receptionist(self::ANA), self::ANA, $this->face(1), $tablet)->assertCreated();

        $res = $this->enroll($this->receptionist(self::PEDRO, 'Pedro Gómez'), self::PEDRO, $this->face(1), $tablet)->assertStatus(409);

        $this->assertSame(['ok', 'code', 'message'], array_keys($res->json()));
        $this->assertSame(1, FaceDuplicateMatch::count());
    }

    public function test_ni_la_bitacora_ni_el_crm_exponen_el_diagnostico(): void
    {
        $tablet = $this->tablet();
        $this->enroll($this->receptionist(self::ANA), self::ANA, $this->face(1), $tablet)->assertCreated();
        // Quien intenta registrarse es un entrenador: también tiene la auditoría de su ficha.
        $coach = $this->floorTrainer('1075000002');
        $this->enrollWith($coach->identity, '1075000002', $this->capturesOf($this->capture($this->face(1), 50, 0.05)), $tablet)->assertStatus(409);
        $match = FaceDuplicateMatch::query()->sole();
        $this->assertGreaterThan(0.0, $match->distance);

        $log = TrainerAuditLog::query()->where('event', 'biometric.duplicate_rejected')->sole();
        $this->assertSame(['identity_id' => $coach->identity_id, 'trainer_id' => null], $log->metadata, 'La traza de siempre, sin distancia ni plantilla.');
        // `GET admin/trainers/{id}/audit` (metadata en crudo) filtra por la
        // columna trainer_id, que el rechazo deja vacía: no puede mostrarlo.
        $this->assertNull($log->trainer_id);

        // Claves EXACTAS del perfil biométrico del CRM: un campo nuevo (p. ej.
        // «parecidos») tiene que pasar por aquí.
        $dataKeys = ['status', 'verifiable', 'enrolled_at', 'updated_at', 'enrolled_via', 'enrolled_by', 'enrolled_device',
            'template_version', 'consent_version', 'consent_accepted_at', 'last_verified_at', 'revoked', 'pending_codes', 'history'];
        $leaks = ['distance', 'face_reference_id', 'audit_log_id', 'face_duplicate_matches', number_format($match->distance, 4)];
        foreach ([['biometrics.view'], ['biometrics.view', 'biometrics.manage']] as $permissions) {
            $response = $this->getJson("/api/admin/biometrics/identities/{$coach->identity->uuid}", $this->adminWithPermissions($permissions))->assertOk();
            $this->assertSame($dataKeys, array_keys($response->json('data')));
            foreach ($response->json('data.history') as $entry) {
                $this->assertSame(['event', 'label', 'at', 'actor', 'detail'], array_keys($entry));
            }
            foreach ($leaks as $leak) {
                $this->assertStringNotContainsString($leak, $response->getContent());
            }
            $this->assertContains('biometric.duplicate_rejected', $response->json('data.history.*.event'), 'El CRM sigue viendo el rechazo, sin el diagnóstico.');
        }
    }

    public function test_la_tabla_solo_guarda_ids_internos_y_la_distancia(): void
    {
        // Añadir una columna (un vector, un nombre, un documento) tiene que ser
        // una decisión consciente: esta prueba falla.
        $columns = Schema::getColumnListing('face_duplicate_matches');
        sort($columns);

        $this->assertSame(['audit_log_id', 'created_at', 'distance', 'face_reference_id', 'id'], $columns);
    }

    public function test_solo_el_servicio_biometrico_y_el_mantenimiento_tocan_el_diagnostico(): void
    {
        // Solo se consulta desde el servidor. Exponerlo en el CRM (o leerlo
        // desde otro servicio que un controlador devuelva) es otra decisión,
        // con su permiso y su prueba: esta lista blanca falla antes.
        $allowed = [
            'Console/Commands/WorkforceMaintenanceCommand.php',
            'Models/FaceDuplicateMatch.php',
            'Services/Workforce/FaceReferenceService.php',
        ];
        $found = [];
        foreach (File::allFiles(app_path()) as $file) {
            $source = $file->getContents();
            if (str_contains($source, 'FaceDuplicateMatch') || str_contains($source, 'face_duplicate_matches')) {
                $found[] = str_replace('\\', '/', $file->getRelativePathname());
            }
        }
        sort($found);
        $this->assertSame($allowed, $found);

        foreach (File::allFiles(base_path('routes')) as $file) {
            $this->assertStringNotContainsString('FaceDuplicateMatch', $file->getContents(), $file->getRelativePathname());
            $this->assertStringNotContainsString('face_duplicate_matches', $file->getContents(), $file->getRelativePathname());
        }
    }

    // ── Retención y robustez ────────────────────────────────────────────────

    public function test_el_mantenimiento_poda_los_diagnosticos_vencidos_y_conserva_la_traza(): void
    {
        $tablet = $this->tablet();
        $this->enroll($this->receptionist(self::ANA), self::ANA, $this->face(1), $tablet)->assertCreated();
        $pedro = $this->receptionist(self::PEDRO, 'Pedro Gómez');

        $this->enroll($pedro, self::PEDRO, $this->face(1), $tablet)->assertStatus(409);
        $old = FaceDuplicateMatch::query()->sole();

        $this->reloj('2026-11-20 14:00');
        $this->enroll($pedro, self::PEDRO, $this->face(1), $tablet)->assertStatus(409);
        $recent = FaceDuplicateMatch::query()->whereKeyNot($old->id)->sole();

        // 31 días después del primero, 6 después del segundo.
        $this->reloj('2026-11-26 14:00');
        $this->artisan('workforce:maintenance')->assertExitCode(0);

        $this->assertNull(FaceDuplicateMatch::find($old->id), 'Vencido: se poda.');
        $this->assertNotNull(FaceDuplicateMatch::find($recent->id), 'Dentro de la retención: se conserva.');
        $this->assertSame(2, TrainerAuditLog::query()->where('event', 'biometric.duplicate_rejected')->count(), 'La traza de los rechazos no se poda.');
    }

    public function test_si_el_diagnostico_no_se_puede_guardar_el_rostro_se_rechaza_igual_y_el_log_no_lleva_los_valores(): void
    {
        Log::spy();
        // Fallo simulado antes de tocar la base (en PostgreSQL un INSERT fallido
        // abortaría la transacción de la prueba), con la forma de uno real: el
        // mensaje de una QueryException lleva los valores del INSERT.
        $pdo = new PDOException('SQLSTATE[42P01]: Undefined table');
        $pdo->errorInfo = ['42P01', 7, 'relation "face_duplicate_matches" does not exist'];
        FaceDuplicateMatch::creating(fn (FaceDuplicateMatch $m) => throw new QueryException(
            'pgsql',
            'insert into "face_duplicate_matches" ("audit_log_id", "face_reference_id", "distance", "created_at") values (?, ?, ?, ?)',
            [$m->audit_log_id, $m->face_reference_id, $m->distance, (string) $m->created_at],
            $pdo,
        ));

        try {
            $tablet = $this->tablet();
            $ana = $this->receptionist(self::ANA);
            $this->enroll($ana, self::ANA, $this->face(1), $tablet)->assertCreated();
            $pedro = $this->receptionist(self::PEDRO, 'Pedro Gómez');

            $this->enroll($pedro, self::PEDRO, $this->face(1), $tablet)
                ->assertStatus(409)->assertJsonPath('code', 'face_already_linked');

            $this->assertNull(TrainerFaceReference::query()->where('identity_id', $pedro->id)->first());
            $log = TrainerAuditLog::query()->where('event', 'biometric.duplicate_rejected')->sole();
            $this->assertSame(0, FaceDuplicateMatch::count());

            Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context = []) => $message === 'biometric.duplicate_match_record_failed'
                && $context === ['audit_log_id' => $log->id, 'error' => 'QueryException 42P01']);
            Log::shouldNotHaveReceived('error');
        } finally {
            FaceDuplicateMatch::flushEventListeners();
        }
    }

    // ── Revocar se lleva el diagnóstico de esa plantilla ─────────────────────

    public function test_revocar_una_plantilla_borra_sus_coincidencias_y_conserva_la_traza(): void
    {
        $tablet = $this->tablet();
        $ana = $this->receptionist(self::ANA);
        $this->enroll($ana, self::ANA, $this->face(1), $tablet)->assertCreated();
        $marta = $this->receptionist('1075000003', 'Marta Díaz');
        $this->enroll($marta, '1075000003', $this->face(3), $tablet)->assertCreated();

        $this->enroll($this->receptionist(self::PEDRO, 'Pedro Gómez'), self::PEDRO, $this->face(1), $tablet)->assertStatus(409);
        $this->enroll($this->receptionist('1075000005', 'Sofía Ruiz'), '1075000005', $this->face(3), $tablet)->assertStatus(409);
        $this->assertSame(2, FaceDuplicateMatch::count());

        $this->postJson("/api/admin/biometrics/identities/{$ana->uuid}/revoke", ['reason' => 'Retira su consentimiento'], $this->adminWithPermissions(['biometrics.manage']))
            ->assertOk();

        $this->assertSame([$this->referenceOf($marta)->id], FaceDuplicateMatch::query()->pluck('face_reference_id')->map(fn ($v) => (int) $v)->all(), 'Solo se van las de la plantilla revocada.');
        $this->assertSame(2, TrainerAuditLog::query()->where('event', 'biometric.duplicate_rejected')->count(), 'La traza de los rechazos se conserva.');
    }
}
