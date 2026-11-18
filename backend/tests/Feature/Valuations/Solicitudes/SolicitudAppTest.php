<?php

namespace Tests\Feature\Valuations\Solicitudes;

use App\Models\Member;
use App\Models\Notification;
use App\Models\Trainer;
use App\Models\ValuationRequest;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * «Solicitar valoración» desde la app (CONTRATO_SOLICITUDES_VALORACION.md §2),
 * por los endpoints reales: el estado de cada caso, el POST idempotente, los
 * dos 409 con el estado, los avisos después del commit y el aislamiento entre
 * socios.
 */
class SolicitudAppTest extends TestCase
{
    use RefreshDatabase;
    use SolicitudFixtures;

    private Trainer $carlos;

    private Member $maria;

    private array $recepcion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-11-18 08:00'); // miércoles
        $this->carlos = $this->trainer('Carlos Pérez');
        $this->everyDay($this->carlos);
        $this->maria = $this->socio('María González');
        $this->recepcion = $this->reception();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Estado (GET current) ────────────────────────────────────────────────

    public function test_sin_solicitud_ni_cita_puede_pedirla(): void
    {
        $this->estado($this->maria)->assertOk()->assertExactJson(['ok' => true, 'data' => [
            'request' => null,
            'next_appointment' => null,
            'can_request' => true,
            'blocked_reason' => null,
        ]]);
    }

    public function test_con_cita_futura_la_muestra_con_hora_de_bogota_y_nombre_corto(): void
    {
        $uuid = $this->book($this->recepcion, $this->maria, $this->carlos, '2026-11-19T10:00:00-05:00')->assertCreated()->json('data.uuid');

        $this->estado($this->maria)->assertOk()->assertExactJson(['ok' => true, 'data' => [
            'request' => null,
            'next_appointment' => [
                'uuid' => $uuid,
                'starts_at' => '2026-11-19T10:00:00-05:00',
                'date_label' => 'jueves 19 de noviembre, 10:00 a. m.',
                'trainer_name' => 'Carlos P.',
                'status' => 'scheduled',
            ],
            'can_request' => false,
            'blocked_reason' => 'future_appointment',
        ]]);
    }

    public function test_la_cita_mas_cercana_y_las_cerradas_o_pasadas_no_cuentan(): void
    {
        $lejana = $this->book($this->recepcion, $this->maria, $this->carlos, '2026-11-25T10:00:00-05:00')->assertCreated()->json('data.uuid');
        $cercana = $this->book($this->recepcion, $this->maria, $this->carlos, '2026-11-18T15:00:00-05:00')->assertCreated()->json('data.uuid');
        $this->assertSame($cercana, $this->estado($this->maria)->json('data.next_appointment.uuid'));

        // Cancelada: deja de contar.
        $this->postJson("/api/admin/valuations/{$cercana}/cancel", ['expected_version' => 1, 'reason' => 'No puede venir'], $this->recepcion)->assertOk();
        $this->assertSame($lejana, $this->estado($this->maria)->json('data.next_appointment.uuid'));

        // Pasada aunque siga «programada»: deja de contar.
        $this->clock('2026-11-25 10:01');
        $this->estado($this->maria)->assertOk()
            ->assertJsonPath('data.next_appointment', null)
            ->assertJsonPath('data.can_request', true);
    }

    public function test_confirmada_y_en_curso_futura_cuentan_como_abiertas(): void
    {
        $root = $this->h($this->superAdmin());
        $uuid = $this->book($this->recepcion, $this->maria, $this->carlos, '2026-11-18T10:00:00-05:00')->assertCreated()->json('data.uuid');
        $this->postJson("/api/admin/valuations/{$uuid}/confirm", ['expected_version' => 1], $root)->assertOk();
        $this->estado($this->maria)->assertJsonPath('data.next_appointment.status', 'confirmed');

        // Se puede iniciar desde 15 minutos antes: sigue siendo futura.
        $this->clock('2026-11-18 09:50');
        $this->postJson("/api/admin/valuations/{$uuid}/start", ['expected_version' => 2], $root)->assertOk();
        $this->estado($this->maria)->assertJsonPath('data.next_appointment.status', 'in_progress')
            ->assertJsonPath('data.blocked_reason', 'future_appointment');
    }

    // ── POST ────────────────────────────────────────────────────────────────

    public function test_post_crea_la_solicitud_201_y_devuelve_el_estado(): void
    {
        $id = (string) Str::uuid();
        $r = $this->solicitar($this->maria, $id)->assertCreated();

        $fila = ValuationRequest::query()->sole();
        $r->assertExactJson(['ok' => true, 'data' => [
            'request' => ['uuid' => $fila->uuid, 'status' => 'pending', 'created_at' => '2026-11-18T08:00:00-05:00'],
            'next_appointment' => null,
            'can_request' => false,
            'blocked_reason' => 'pending_request',
        ]]);
        $this->assertSame((int) $this->maria->id, $fila->member_id);
        $this->assertSame('app', $fila->source);
        $this->assertSame($id, $fila->client_request_id);
        $this->assertSame(1, $fila->version);
        $this->assertNull($fila->appointment_id);
        $this->assertNull($fila->handled_by_admin_id);

        // El GET dice lo mismo.
        $this->assertSame($r->json('data'), $this->estado($this->maria)->json('data'));
    }

    public function test_repetir_el_mismo_client_request_id_devuelve_200_sin_crear_otra(): void
    {
        $id = (string) Str::uuid();
        $primera = $this->solicitar($this->maria, $id)->assertCreated();
        $segunda = $this->solicitar($this->maria, $id)->assertOk();
        // En mayúsculas también es la misma.
        $tercera = $this->solicitar($this->maria, strtoupper($id))->assertOk();

        $this->assertSame($primera->json('data'), $segunda->json('data'));
        $this->assertSame($primera->json('data'), $tercera->json('data'));
        $this->assertSame(1, ValuationRequest::query()->count());
        $this->assertCount(2, $this->clavesDeAvisos(), 'los reintentos no duplican avisos');
    }

    public function test_con_una_pendiente_otro_toque_es_409_con_el_estado(): void
    {
        $uuid = $this->solicitar($this->maria)->assertCreated()->json('data.request.uuid');

        $this->solicitar($this->maria)->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'valuation_request_pending')
            ->assertJsonPath('data.request.uuid', $uuid)
            ->assertJsonPath('data.can_request', false)
            ->assertJsonPath('data.blocked_reason', 'pending_request');
        $this->assertSame(1, ValuationRequest::query()->count());
    }

    public function test_con_una_cita_futura_es_409_con_el_estado_y_no_crea_nada(): void
    {
        $cita = $this->book($this->recepcion, $this->maria, $this->carlos, '2026-11-19T10:00:00-05:00')->assertCreated()->json('data.uuid');

        $this->solicitar($this->maria)->assertStatus(409)
            ->assertJsonPath('code', 'valuation_future_appointment')
            ->assertJsonPath('data.next_appointment.uuid', $cita)
            ->assertJsonPath('data.request', null)
            ->assertJsonPath('data.blocked_reason', 'future_appointment');
        $this->assertSame(0, ValuationRequest::query()->count());
        $this->assertSame([], $this->clavesDeAvisos());
    }

    public function test_pendiente_y_cita_a_la_vez_manda_la_cita(): void
    {
        $uuid = $this->solicitar($this->maria)->assertCreated()->json('data.request.uuid');
        $this->book($this->recepcion, $this->maria, $this->carlos, '2026-11-19T10:00:00-05:00')->assertCreated();

        $this->estado($this->maria)
            ->assertJsonPath('data.request.uuid', $uuid)
            ->assertJsonPath('data.next_appointment.date_label', 'jueves 19 de noviembre, 10:00 a. m.')
            ->assertJsonPath('data.blocked_reason', 'future_appointment');
        $this->solicitar($this->maria)->assertStatus(409)->assertJsonPath('code', 'valuation_future_appointment');
    }

    public function test_una_solicitud_cerrada_no_se_muestra_y_permite_pedir_otra(): void
    {
        $uuid = $this->solicitar($this->maria)->assertCreated()->json('data.request.uuid');
        $this->postJson("/api/admin/valuations/requests/{$uuid}/reject", ['reason' => 'No hay entrenadores esta semana', 'expected_version' => 1], $this->recepcion)->assertOk();

        $this->estado($this->maria)->assertJsonPath('data.request', null)->assertJsonPath('data.can_request', true);
        $nueva = $this->solicitar($this->maria)->assertCreated()->json('data.request.uuid');
        $this->assertNotSame($uuid, $nueva);
    }

    public function test_el_cuerpo_se_valida(): void
    {
        $this->postJson('/api/app/valuation-requests', [], $this->tokenDe($this->maria))->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['client_request_id']]);
        $this->solicitar($this->maria, 'no-es-un-uuid')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->solicitar($this->maria, "' OR 1=1 --")->assertStatus(422);
        $this->assertSame(0, ValuationRequest::query()->count());
    }

    // ── Avisos ──────────────────────────────────────────────────────────────

    public function test_avisos_al_crm_y_al_socio_con_su_event_key_y_sin_push(): void
    {
        $this->assertTrue((bool) config('fcm.only_popup'), 'sin popup no hay push');
        $uuid = $this->solicitar($this->maria)->assertCreated()->json('data.request.uuid');

        $this->assertSame(["valuation:request:{$uuid}:created", "valuation:request:{$uuid}:received"], $this->clavesDeAvisos());

        $crm = Notification::query()->where('event_key', "valuation:request:{$uuid}:created")->sole();
        $this->assertSame('admin', $crm->audience);
        $this->assertSame('valuation', $crm->type);
        $this->assertSame('Solicitud de valoración', $crm->title);
        $this->assertSame('María G. solicitó una valoración desde la app.', $crm->message);
        $this->assertSame('medium', $crm->priority);
        $this->assertSame('valuations.view_all', $crm->required_permission);
        $this->assertSame('valuation_request', $crm->action_type);
        $this->assertSame(['request_uuid' => $uuid, 'member_id' => (int) $this->maria->id], $crm->action_payload);
        $this->assertSame('valuation_request_created', $crm->metadata['source_event']);
        $this->assertNull($crm->member_id, 'sin socio enlazado: la fila no lleva el documento');
        $this->assertNull($crm->document);

        $socio = Notification::query()->where('event_key', "valuation:request:{$uuid}:received")->sole();
        $this->assertSame('member', $socio->audience);
        $this->assertSame((int) $this->maria->id, (int) $socio->member_id);
        $this->assertFalse($socio->should_popup);
        $this->assertSame('Recibimos tu solicitud de valoración. Te contactaremos para agendarla.', $socio->message);
        $this->assertSame('route', $socio->action_type);
        $this->assertSame('/evaluation', $socio->action_url);
        $this->assertSame(['request_uuid' => $uuid], $socio->action_payload);
        $this->assertSame('valuation_request_received', $socio->metadata['source_event']);

        // Sin medidas ni datos de salud en ninguno.
        foreach ([$crm, $socio] as $n) {
            $todo = json_encode($n->toPublicArray());
            foreach (['weight', 'peso', 'body_fat', 'injur', 'lesion', 'measure', 'medida', 'imc', 'bmi'] as $prohibido) {
                $this->assertStringNotContainsStringIgnoringCase($prohibido, $todo);
            }
        }

        // El socio lo ve en su bandeja de la app; otro socio no.
        $this->assertContains($socio->uuid, array_column($this->getJson('/api/notifications', $this->tokenDe($this->maria))->json('data') ?? [], 'uuid'));
    }

    /**
     * Sonda precisa del «después del commit»: el registro de la transacción que
     * insertó la solicitud ya no está pendiente cuando se escribe cada aviso.
     * (El nivel de transacción no sirve: `firstOrCreate` abre su propio punto
     * de guardado.) Si el aviso se escribiera dentro, el registro seguiría
     * pendiente y esto fallaría.
     */
    public function test_los_avisos_salen_despues_del_commit(): void
    {
        $gestor = app('db.transactions');
        $deLaSolicitud = null;
        $dentro = [];
        ValuationRequest::created(function () use ($gestor, &$deLaSolicitud): void {
            $deLaSolicitud = $gestor->getPendingTransactions()->last();
        });
        Notification::creating(function () use ($gestor, &$deLaSolicitud, &$dentro): void {
            $dentro[] = $deLaSolicitud !== null && $gestor->getPendingTransactions()->contains($deLaSolicitud);
        });

        $this->solicitar($this->maria)->assertCreated();

        $this->assertNotNull($deLaSolicitud, 'la solicitud se crea dentro de una transacción');
        $this->assertSame([false, false], $dentro, 'ningún aviso se escribe dentro de la transacción de la solicitud');
    }

    public function test_si_los_avisos_fallan_la_solicitud_se_confirma_y_el_reintento_los_completa(): void
    {
        Log::spy();
        $real = app(NotificationService::class);
        // Laravel reutiliza la instancia del controlador entre las peticiones de
        // una misma prueba: el doble decide en cada llamada si la bandeja está caída.
        $caida = true;
        $this->mock(NotificationService::class, function ($m) use ($real, &$caida): void {
            foreach (['createAdminNotification', 'createMemberNotification'] as $metodo) {
                $m->shouldReceive($metodo)->andReturnUsing(function (...$args) use ($real, $metodo, &$caida) {
                    if ($caida) {
                        throw new RuntimeException('bandeja caída');
                    }

                    return $real->{$metodo}(...$args);
                });
            }
        });

        $id = (string) Str::uuid();
        $uuid = $this->solicitar($this->maria, $id)->assertCreated()->json('data.request.uuid');
        $this->assertSame(1, ValuationRequest::query()->count());
        $this->assertSame([], $this->clavesDeAvisos());
        Log::shouldHaveReceived('warning')->with('valuations.request_notice_failed', \Mockery::on(fn ($c) => $c['request_uuid'] === $uuid && ! isset($c['message'])))->twice();

        // La app no recibió respuesta y reintenta con el mismo id: 200 y avisos.
        $caida = false;
        $this->solicitar($this->maria, $id)->assertOk();
        $this->assertSame(["valuation:request:{$uuid}:created", "valuation:request:{$uuid}:received"], $this->clavesDeAvisos());
    }

    // ── Seguridad ───────────────────────────────────────────────────────────

    public function test_un_socio_no_lee_ni_crea_la_de_otro(): void
    {
        $id = (string) Str::uuid();
        $deMaria = $this->solicitar($this->maria, $id)->assertCreated()->json('data.request.uuid');
        $pedro = $this->socio('Pedro Ruiz');

        // Pedro no ve la de María.
        $this->estado($pedro)->assertJsonPath('data.request', null)->assertJsonPath('data.can_request', true);

        // Con el id de María: ni la devuelve ni confirma nada de ella.
        $r = $this->solicitar($pedro, $id)->assertStatus(422)->assertJsonPath('code', 'client_request_id_conflict');
        $this->assertStringNotContainsString($deMaria, $r->getContent());
        $this->assertStringNotContainsString('María', $r->getContent());

        // Un `member_id` en el cuerpo se ignora: el socio sale del bearer.
        $dePedro = $this->solicitar($pedro, null, ['member_id' => $this->maria->id])->assertCreated()->json('data.request.uuid');
        $this->assertSame((int) $pedro->id, $this->solicitud($dePedro)->member_id);
        $this->assertSame((int) $this->maria->id, $this->solicitud($deMaria)->member_id);
    }

    public function test_sin_token_de_socio_no_entra_nadie(): void
    {
        $this->getJson('/api/app/valuation-requests/current')->assertUnauthorized();
        $this->postJson('/api/app/valuation-requests', ['client_request_id' => (string) Str::uuid()])->assertUnauthorized();

        // Ni el token compartido de automatizaciones ni una sesión del CRM son un socio.
        foreach ([$this->sharedTokenHeaders(), $this->h($this->superAdmin())] as $h) {
            $this->getJson('/api/app/valuation-requests/current', $h)->assertUnauthorized();
            $this->postJson('/api/app/valuation-requests', ['client_request_id' => (string) Str::uuid()], $h)->assertUnauthorized();
        }
        $this->assertSame(0, ValuationRequest::query()->count());
    }

    public function test_un_socio_dado_de_baja_o_anonimizado_no_puede_solicitar(): void
    {
        // Borrado (estado `deleted`): el middleware ya lo para.
        $borrado = $this->socio('Ana Borrada');
        $borrado->forceFill(['status' => Member::STATUS_DELETED])->save();
        $this->solicitar($borrado)->assertUnauthorized()->assertJsonPath('code', 'account_deleted');

        // Anonimizado con el estado aún activo (no debería existir, pero el
        // servicio no se fía): 403 y nada escrito.
        $anonimo = $this->socio('Luis Anónimo');
        $anonimo->forceFill(['anonymized_at' => now()])->save();
        $this->solicitar($anonimo)->assertForbidden()->assertJsonPath('code', 'member_unavailable');

        $this->assertSame(0, ValuationRequest::query()->count());
        $this->assertSame([], $this->clavesDeAvisos());
    }

    public function test_dar_de_baja_al_socio_se_lleva_sus_solicitudes(): void
    {
        $uuid = $this->solicitar($this->maria)->assertCreated()->json('data.request.uuid');
        $pedro = $this->socio('Pedro Ruiz');
        $dePedro = $this->solicitar($pedro)->assertCreated()->json('data.request.uuid');

        $this->deleteJson("/api/users/{$this->maria->user_id}", [], $this->h($this->superAdmin()))->assertNoContent();

        $this->assertNull(ValuationRequest::query()->where('uuid', $uuid)->first());
        $this->assertNotNull(ValuationRequest::query()->where('uuid', $dePedro)->first(), 'la de otro socio no se toca');
    }

    public function test_el_post_tiene_limite_por_minuto(): void
    {
        $this->solicitar($this->maria)->assertCreated();
        foreach (range(2, 6) as $_) {
            $this->solicitar($this->maria)->assertStatus(409);
        }
        $this->solicitar($this->maria)->assertStatus(429);
        // El GET no consume ese cupo.
        $this->estado($this->maria)->assertOk();
    }
}
