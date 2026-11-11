<?php

namespace Tests\Feature\Security;

use App\Models\Admin;
use App\Models\Member;
use App\Models\MemberBiometric;
use App\Models\User;
use App\Services\Admin\AdminSessionService;
use App\Services\Admin\StreamTicketService;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * SC-7 (revisión de seguridad del cierre, `informe-sec-cierre.md`): la emisión
 * de vales (`GET admin/stream-ticket`) tiene un limitador por PERSONA y
 * finalidad, holgado, en el controlador (sin tocar `routes/api.php`). Cada vale
 * deja una anotación en la caché `database` hasta que se canjea o la purga: sin
 * techo, una sesión robada o un bucle del CRM las acumulaban sin fin.
 *
 * MEDIDO EN EL CRM (no supuesto): un canal SSE genérico pide ≈ 3 vales por
 * minuto (`SseStream` cierra a los 20 s y `openStream()` reabre con uno nuevo);
 * el del Studio, ≈ 1. El catálogo facial pide UNO POR SOCIO CON FOTO y todos a
 * la vez (`buildReferences()`, `Promise.all`) al abrir Asistencia, tras cada
 * rostro enrolado o borrado y con «Actualizar rostros». Por eso el cupo de
 * `stream` es fijo (600) y el de `face-image` sigue al catálogo (fotos × 8,
 * nunca menos de 1 000).
 */
class StreamTicketIssueRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $email = ''): Admin
    {
        return Admin::create([
            'name' => 'Cupo', 'email' => ($email ?: 'sc7-'.uniqid()).'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
    }

    /** @return array<string,string> la cabecera de una sesión NUEVA de esa persona (otra pestaña u otro equipo) */
    private function sesion(Admin $admin): array
    {
        return ['Authorization' => 'Bearer '.app(AdminSessionService::class)->issueSession($admin)['token']];
    }

    /** @param  array<string,string>  $cabecera */
    private function pedir(array $cabecera, string $scope = StreamTicketService::SCOPE_STREAM): TestResponse
    {
        return $this->getJson('/api/admin/stream-ticket?scope='.$scope, $cabecera);
    }

    /** Llena el cubo de la persona hasta dejarle `$quedan` vales en esta ventana. */
    private function llenar(Admin $admin, string $scope, int $quedan): void
    {
        $cubo = StreamTicketService::rateKey($scope, (int) $admin->id);
        RateLimiter::increment($cubo, StreamTicketService::RATE_WINDOW_SECONDS, app(StreamTicketService::class)->perMinute($scope) - $quedan - RateLimiter::attempts($cubo));
    }

    /** Socios con foto biométrica (lo que cuenta el catálogo facial). */
    private function sociosConFoto(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $user = User::factory()->create();
            $member = Member::create([
                'user_id' => $user->id, 'full_name' => 'Socio '.$i, 'document_number' => (string) (1080000000 + $i),
                'phone' => '+57300'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'access_hash' => 'tok-sc7-'.$i.'-'.uniqid(), 'status' => Member::STATUS_ACTIVE,
            ]);
            MemberBiometric::create(['member_id' => $member->id, 'face_path' => "members/sc7/{$i}.jpg", 'face_mime' => 'image/jpeg', 'captured_at' => now()]);
        }
        Cache::forget(StreamTicketService::CACHE_PREFIX.'faces');
    }

    public function test_pasado_el_cupo_de_canales_la_persona_recibe_429_con_retry_after_y_sin_vale(): void
    {
        $ana = $this->admin();
        $pestana = $this->sesion($ana);
        $this->pedir($pestana)->assertOk();
        $this->llenar($ana, StreamTicketService::SCOPE_STREAM, 1);
        $this->pedir($pestana)->assertOk();

        $r = $this->pedir($pestana)->assertStatus(429)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'too_many_requests')
            ->assertJsonMissingPath('data');
        $this->assertGreaterThanOrEqual(1, (int) $r->headers->get('Retry-After'));
        $this->assertSame((int) $r->headers->get('Retry-After'), $r->json('retry_after'));

        // Es por PERSONA: otra pestaña u otro equipo de Ana comparten el cubo…
        $this->pedir($this->sesion($ana))->assertStatus(429);
        // …otra persona no, y la ráfaga de canales no le quita a Ana las fotos.
        $this->pedir($this->sesion($this->admin()))->assertOk();
        $this->pedir($pestana, StreamTicketService::SCOPE_FACE_IMAGE)->assertOk();

        // Pasada la ventana, vuelve a tener vales.
        $this->travel(StreamTicketService::RATE_WINDOW_SECONDS + 1)->seconds();
        $this->pedir($pestana)->assertOk()->assertJsonPath('data.scope', StreamTicketService::SCOPE_STREAM);
    }

    public function test_el_429_no_anota_nada_en_la_cache(): void
    {
        // La caché `database`, como en producción: las anotaciones son filas de `cache`.
        config(['cache.default' => 'database', 'cache.stores.database.connection' => null, 'cache.stores.database.lock_connection' => null]);
        app('cache')->forgetDriver(['database', 'array']);
        app()->forgetInstance(CacheRateLimiter::class);
        Facade::clearResolvedInstances();
        $anotaciones = static fn (): int => DB::table('cache')->where('key', 'like', '%'.StreamTicketService::CACHE_PREFIX.'%')
            ->where('key', 'not like', '%'.StreamTicketService::CACHE_PREFIX.'rate:%')->where('key', 'not like', '%'.StreamTicketService::CACHE_PREFIX.'faces')->count();

        $ana = $this->admin();
        $pestana = $this->sesion($ana);
        $this->pedir($pestana)->assertOk();
        $this->assertSame(1, $anotaciones(), 'un vale, una anotación');
        $this->llenar($ana, StreamTicketService::SCOPE_STREAM, 0);

        $this->pedir($pestana)->assertStatus(429);
        $this->pedir($pestana)->assertStatus(429);
        $this->assertSame(1, $anotaciones(), 'el 429 no anota nada');
    }

    public function test_una_finalidad_desconocida_es_422_y_no_gasta_cupo(): void
    {
        $ana = $this->admin();
        $this->pedir($this->sesion($ana), 'todo')->assertStatus(422)->assertJsonPath('code', 'ticket_scope_invalid');
        $this->assertSame(0, RateLimiter::attempts(StreamTicketService::rateKey(StreamTicketService::SCOPE_STREAM, (int) $ana->id)));
    }

    public function test_el_cupo_de_canales_es_fijo_y_el_de_fotos_sigue_al_catalogo(): void
    {
        $tickets = app(StreamTicketService::class);
        $this->assertSame(StreamTicketService::STREAM_PER_MINUTE, $tickets->perMinute(StreamTicketService::SCOPE_STREAM));
        // Sin fotos, el suelo.
        $this->assertSame(StreamTicketService::FACE_IMAGE_FLOOR_PER_MINUTE, $tickets->perMinute(StreamTicketService::SCOPE_FACE_IMAGE));

        // 130 socios con foto: 8 reconstrucciones del catálogo por minuto = 1 040 > 1 000.
        $this->sociosConFoto(130);
        $this->assertSame(130 * StreamTicketService::FACE_IMAGE_REBUILDS_PER_MINUTE, $tickets->perMinute(StreamTicketService::SCOPE_FACE_IMAGE));

        // La cuenta se guarda diez minutos: un catálogo de 1 000 fotos ya contado da 8 000.
        Cache::put(StreamTicketService::CACHE_PREFIX.'faces', 1000, 600);
        $this->assertSame(8000, $tickets->perMinute(StreamTicketService::SCOPE_FACE_IMAGE));

        $this->expectException(InvalidArgumentException::class);
        $tickets->perMinute('todo');
    }

    public function test_la_rafaga_del_catalogo_facial_cabe_entera_ocho_veces_por_minuto(): void
    {
        // El catálogo de 130 fotos (cupo 1 040): siete reconstrucciones ya pedidas en este minuto…
        $this->sociosConFoto(130);
        $recepcion = $this->admin();
        $pestana = $this->sesion($recepcion);
        $this->llenar($recepcion, StreamTicketService::SCOPE_FACE_IMAGE, 130);

        // …y la octava entera, una petición por foto, como `Promise.all`: ninguna se queda sin vale.
        for ($i = 0; $i < 130; $i++) {
            $this->pedir($pestana, StreamTicketService::SCOPE_FACE_IMAGE)->assertOk();
        }
        // La novena ya no cabe en este minuto.
        $this->pedir($pestana, StreamTicketService::SCOPE_FACE_IMAGE)->assertStatus(429);
        // Y los canales de esa persona siguen a lo suyo.
        $this->pedir($pestana)->assertOk();
    }
}
