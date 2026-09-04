<?php

namespace Tests\Feature\Audit;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Qué campos dice la traza que cambiaron.
 *
 * Registraban las CLAVES ENVIADAS, no los cambios: editar el teléfono de un
 * socio dejaba una traza afirmando que se habían tocado los dieciséis campos del
 * formulario —`name`, `document`, `email`, `status`, `birthDate`…—. Cierto en lo
 * literal e inútil para auditar: quien revisara no podía saber qué se modificó.
 *
 * Ahora el diff lo hace Eloquent. No es una preferencia de estilo: una
 * comparación estricta contra el cuerpo de la petición marca cambios que no
 * existen. Se midió en este mismo proyecto —`price: "80000"` sobre un plan que
 * ya vale `80000`, o `active: 1` sobre uno ya activo— y los daba por
 * modificados. `getChanges()` conoce los casts y no se equivoca.
 */
class AuditChangesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): array
    {
        return $this->actingAsAdmin(Admin::create([
            'name' => 'Prueba', 'email' => 'a-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_ADMINISTRADOR, 'status' => 'active',
        ]));
    }

    private function plan(array $over = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30,
            'benefits' => 'Acceso libre', 'active' => true,
        ], $over));
    }

    /** Los nombres de campo que quedaron registrados. */
    private function camposDe(string $entity): array
    {
        $log = AuditLog::query()->where('entity', $entity)->where('action', 'update')->sole();

        return array_column($log->changes ?? [], 'field');
    }

    // ── 1 · Solo lo que cambió ──────────────────────────────────────────────

    public function test_enviar_diez_campos_y_cambiar_uno_registra_uno(): void
    {
        $plan = $this->plan();

        $this->patchJson("/api/plans/{$plan->id}", [
            'name' => 'Mensual',            // igual
            'price' => 50000,               // ← el único cambio
            'duration_days' => 30,          // igual
            'benefits' => 'Acceso libre',   // igual
            'active' => true,               // igual
        ], $this->admin())->assertOk();

        $this->assertSame(['price'], $this->camposDe('plan'));
    }

    // ── 2-5 · Sin falsos cambios por serialización ──────────────────────────

    public function test_los_mismos_valores_no_generan_cambios(): void
    {
        $plan = $this->plan();

        $this->patchJson("/api/plans/{$plan->id}", [
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30,
            'benefits' => 'Acceso libre', 'active' => true,
        ], $this->admin())->assertOk();

        $this->assertSame([], $this->camposDe('plan'), 'no se tocó nada: la traza no puede decir lo contrario');
    }

    public function test_un_numero_como_texto_no_es_un_cambio(): void
    {
        // El CRM manda formularios: los números llegan como cadena. Marcarlos
        // como cambio llenaría la traza de ruido en cada guardado.
        $plan = $this->plan();

        $this->patchJson("/api/plans/{$plan->id}", ['price' => '80000', 'active' => true], $this->admin())
            ->assertOk();

        $this->assertNotContains('price', $this->camposDe('plan'));
    }

    public function test_un_booleano_como_uno_no_es_un_cambio(): void
    {
        $plan = $this->plan(['active' => true]);

        $this->patchJson("/api/plans/{$plan->id}", ['active' => 1], $this->admin())->assertOk();

        $this->assertNotContains('active', $this->camposDe('plan'));
    }

    public function test_un_booleano_que_si_cambia_se_registra(): void
    {
        $plan = $this->plan(['active' => true]);

        $this->patchJson("/api/plans/{$plan->id}", ['active' => false], $this->admin())->assertOk();

        $this->assertContains('active', $this->camposDe('plan'));
    }

    public function test_una_cadena_vacia_si_cuenta_como_cambio(): void
    {
        // Laravel convierte las cadenas vacías en null antes de validar, así que
        // enviar `benefits: ""` sobre un texto guardado SÍ cambia el valor: pasa
        // de texto a NULL. La traza lo registra porque de verdad ocurrió; no es
        // un falso positivo del diff.
        $plan = $this->plan(['benefits' => 'Acceso libre']);

        $this->patchJson("/api/plans/{$plan->id}", ['benefits' => ''], $this->admin())->assertOk();

        $this->assertContains('benefits', $this->camposDe('plan'));
        $this->assertNull($plan->fresh()->benefits);
    }

    // ── 6 · Lo no enviado no aparece ────────────────────────────────────────

    public function test_un_campo_que_no_se_envia_no_aparece(): void
    {
        $plan = $this->plan();

        $this->patchJson("/api/plans/{$plan->id}", ['price' => 50000], $this->admin())->assertOk();

        $campos = $this->camposDe('plan');
        $this->assertSame(['price'], $campos);
        $this->assertNotContains('duration_days', $campos);
        $this->assertNotContains('name', $campos);
    }

    // ── 7 · Sin filtrar datos personales ────────────────────────────────────

    public function test_la_ficha_de_un_socio_registra_campos_pero_nunca_valores(): void
    {
        $user = User::factory()->create(['name' => 'Socio', 'phone' => '3001112233']);

        $this->patchJson("/api/users/{$user->id}", ['phone' => '3009998877'], $this->admin())
            ->assertOk();

        $log = AuditLog::query()->where('entity', 'cliente')->sole();
        $this->assertContains('phone', array_column($log->changes, 'field'));

        // Ni el número viejo ni el nuevo pueden acabar en la traza.
        $serializado = json_encode($log->changes);
        $this->assertStringNotContainsString('3001112233', $serializado);
        $this->assertStringNotContainsString('3009998877', $serializado);
        foreach ($log->changes as $cambio) {
            $this->assertSame(['field'], array_keys($cambio), 'la ficha del socio va sin valores');
        }
    }

    public function test_el_precio_de_un_plan_si_registra_el_antes_y_el_despues(): void
    {
        // No es dato personal, y un cambio de tarifa sin el salto obliga a
        // reconstruirlo a mano desde otra parte.
        $plan = $this->plan(['price' => 80000]);

        $this->patchJson("/api/plans/{$plan->id}", ['price' => 50000], $this->admin())->assertOk();

        $cambio = AuditLog::query()->where('entity', 'plan')->where('action', 'update')->sole()->changes[0];
        $this->assertSame('price', $cambio['field']);
        $this->assertSame('80000', $cambio['from']);
        $this->assertSame('50000', $cambio['to']);
    }

    // ── 8 · El resto de la traza sigue intacto ──────────────────────────────

    public function test_actor_ip_y_entidad_siguen_siendo_correctos(): void
    {
        $plan = $this->plan();

        $this->patchJson("/api/plans/{$plan->id}", ['price' => 50000], $this->admin())->assertOk();

        $log = AuditLog::query()->where('entity', 'plan')->where('action', 'update')->sole();
        $this->assertNotNull($log->actor_id);
        $this->assertSame('Administrador', $log->actor_role);
        $this->assertSame((string) $plan->id, $log->entity_id);
        $this->assertNotNull($log->ip_address);
    }

    // ── 9 · Una operación rechazada no deja traza ───────────────────────────

    public function test_una_edicion_rechazada_no_deja_traza_ni_cambia_el_plan(): void
    {
        $plan = $this->plan(['price' => 80000]);

        $this->patchJson("/api/plans/{$plan->id}", ['price' => -5], $this->admin())
            ->assertStatus(422);

        $this->assertSame(0, AuditLog::query()->where('entity', 'plan')->count());
        $this->assertEquals(80000, $plan->fresh()->price);
    }

    // ── Falsos positivos vistos en producción ───────────────────────────────

    /**
     * El guardado ocurre MÁS TARDE que la creación, como en la vida real.
     *
     * Es la diferencia que escondió el fallo: creando y editando en el mismo
     * segundo, `updated_at` no llega a cambiar y no aparece en el diff. En
     * producción el plan llevaba meses creado y salía en todas las trazas.
     */
    private function masTarde(): void
    {
        Carbon::setTestNow(now()->addMinutes(5));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_updated_at_nunca_aparece(): void
    {
        $plan = $this->plan();
        $this->masTarde();

        $this->patchJson("/api/plans/{$plan->id}", ['price' => 50000], $this->admin())->assertOk();

        $campos = $this->camposDe('plan');
        $this->assertNotContains('updated_at', $campos);
        $this->assertSame(['price'], $campos, 'un cambio de precio es UN cambio');
    }

    public function test_created_at_nunca_aparece(): void
    {
        $plan = $this->plan();
        $this->masTarde();

        $this->patchJson("/api/plans/{$plan->id}", ['price' => 50000], $this->admin())->assertOk();

        $this->assertNotContains('created_at', $this->camposDe('plan'));
    }

    public function test_el_mismo_json_en_otro_orden_no_es_un_cambio(): void
    {
        // El formulario reenvía `features` en cada guardado, y basta con que el
        // orden de las claves varíe para que Eloquent lo marque: el cast `array`
        // compara la serialización, no el contenido.
        //
        // Se parte del conjunto COMPLETO de banderas porque el controlador
        // fusiona lo enviado con `resolvedFeatures()`. Con un conjunto parcial
        // la fusión añade claves y el cambio sería real, no de orden.
        $plan = $this->plan();
        $completo = $plan->resolvedFeatures();
        $plan->features = $completo;
        $plan->save();
        $this->masTarde();

        $this->patchJson("/api/plans/{$plan->id}", [
            'price' => 50000,
            'features' => array_reverse($completo, true),
        ], $this->admin())->assertOk();

        $campos = $this->camposDe('plan');
        $this->assertNotContains('features', $campos);
        $this->assertSame(['price'], $campos);
    }

    public function test_un_cambio_real_dentro_del_json_si_aparece(): void
    {
        $plan = $this->plan();
        $plan->features = ['iron_ia' => true, 'workouts' => true];
        $plan->save();
        $this->masTarde();

        $this->patchJson("/api/plans/{$plan->id}", [
            'features' => ['iron_ia' => false, 'workouts' => true],
        ], $this->admin())->assertOk();

        $this->assertContains('features', $this->camposDe('plan'));
    }

    public function test_una_lista_en_otro_orden_si_es_un_cambio(): void
    {
        // En una lista el orden ES el dato: mover el primer ejercicio de una
        // rutina al final cambia la rutina. Solo se normalizan los objetos.
        $trail = app(AuditTrail::class);
        $plan = $this->plan();
        $plan->features = ['orden' => ['a', 'b', 'c']];
        $plan->save();

        $previo = $plan->getOriginal();
        $plan->features = ['orden' => ['c', 'b', 'a']];
        $plan->save();

        $this->assertSame(['features'], array_column($trail->changesOf($plan, $previo), 'field'));
    }

    public function test_el_precio_sigue_llevando_su_antes_y_despues(): void
    {
        $plan = $this->plan(['price' => 80000]);
        $this->masTarde();

        $this->patchJson("/api/plans/{$plan->id}", ['price' => 50000], $this->admin())->assertOk();

        $cambio = AuditLog::query()->where('entity', 'plan')->where('action', 'update')->sole()->changes[0];
        $this->assertSame(['field' => 'price', 'from' => '80000', 'to' => '50000'], $cambio);
    }

    public function test_el_caso_exacto_de_produccion(): void
    {
        // Ana cambió el precio de un plan y la traza dijo tres campos:
        // [price, updated_at, features]. `updated_at` sobraba; `features` no,
        // porque su columna tenía menos banderas que `resolvedFeatures()` y la
        // fusión del controlador la completó. Una vez normalizada —como aquí—,
        // un cambio de precio debe dejar exactamente un campo.
        $plan = $this->plan(['price' => 0]);
        $completo = $plan->resolvedFeatures();
        $plan->features = $completo;
        $plan->save();
        $this->masTarde();

        $this->patchJson("/api/plans/{$plan->id}", [
            'name' => 'Mensual',
            'price' => 1,
            'duration_days' => 30,
            'benefits' => 'Acceso libre',
            'active' => true,
            'features' => array_reverse($completo, true),
        ], $this->admin())->assertOk();

        $this->assertSame(
            [['field' => 'price', 'from' => '0', 'to' => '1']],
            AuditLog::query()->where('entity', 'plan')->where('action', 'update')->sole()->changes,
        );
    }
}
