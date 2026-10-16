<?php

namespace Tests\Feature;

use App\Models\Trainer;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El CRM antiguo en Blade (`/crm/*`) no tiene autenticación: apagado por
 * defecto tiene que responder 404 a cualquiera, también a una escritura con un
 * token CSRF válido obtenido del propio formulario (el CSRF no protege cuando
 * quien ataca es el cliente). Revisión de seguridad del 16-10-2026.
 */
class LegacyWebCrmDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_por_defecto_el_crm_antiguo_no_existe_para_nadie(): void
    {
        $this->assertFalse((bool) config('app.legacy_web_crm'));

        foreach (['/crm/trainers', '/crm/trainers/create', '/crm/exercises', '/crm/routines-custom', '/crm/member-routines'] as $ruta) {
            $this->get($ruta)->assertNotFound();
        }
    }

    public function test_por_defecto_una_escritura_anonima_no_crea_nada(): void
    {
        $antes = Trainer::query()->count();

        // Aunque llevara un token CSRF válido, el middleware responde antes.
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/crm/trainers', [
                'full_name' => 'Intruso',
                'document' => '999999999',
                'phone' => '3000000000',
            ])
            ->assertNotFound();

        $this->assertSame($antes, Trainer::query()->count());
    }

    public function test_encendido_a_proposito_sigue_respondiendo_como_antes(): void
    {
        config(['app.legacy_web_crm' => true]);

        $this->get('/crm/trainers')->assertOk();
    }
}
