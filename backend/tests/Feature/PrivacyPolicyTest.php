<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Política de privacidad publicada — requisitos de Google Play (Datos de Usuario
 * → Política de Privacidad).
 *
 * Google rechazó la versión anterior por dos motivos textuales:
 *
 *   1. «La política de privacidad no identifica claramente la aplicación, el
 *      nombre del desarrollador o la entidad legal asociada a tu ficha de
 *      Google Play Store.»
 *   2. «La política de privacidad de tu aplicación no revela sus prácticas de
 *      conservación de datos.»
 *
 * Estas pruebas fijan justo eso. No comprueban redacción —eso es criterio
 * humano— sino los hechos verificables que motivaron el rechazo: que el
 * documento nombre la app, el paquete, al desarrollador y un contacto real; que
 * exista una sección de conservación; y que las dos URLs públicas devuelvan
 * exactamente el mismo documento, para que no puedan volver a divergir.
 */
class PrivacyPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** URL canónica declarada en Play Console. */
    private const CANONICAL = '/privacy-policy.html';

    /** La que abre la app desde Perfil → Política de privacidad. */
    private const IN_APP = '/api/legal/privacy';

    private function fetch(string $uri): TestResponse
    {
        $res = $this->get($uri);
        $res->assertOk();
        $this->assertStringContainsString(
            'text/html',
            (string) $res->headers->get('content-type'),
            "{$uri} debe servirse como HTML: Google no acepta PDF ni descargas."
        );

        return $res;
    }

    public function test_canonical_url_serves_the_policy_as_public_html(): void
    {
        $body = $this->fetch(self::CANONICAL)->getContent();

        $this->assertStringContainsString('Política de Privacidad', $body);
        // Sin login: la respuesta no puede ser una redirección al formulario.
        $this->assertStringNotContainsString('<form', $body);
    }

    public function test_policy_identifies_app_package_developer_and_contact(): void
    {
        $body = $this->fetch(self::CANONICAL)->getContent();

        // Motivo de rechazo 1 — identidad.
        $this->assertStringContainsString(config('legal.app_name'), $body);
        $this->assertStringContainsString(config('legal.android_package'), $body);
        $this->assertStringContainsString(config('legal.developer_name'), $body);
        $this->assertStringContainsString(config('legal.privacy_email'), $body);

        // Un contacto de privacidad que se pueda usar, no solo leer.
        $this->assertStringContainsString('mailto:'.config('legal.privacy_email'), $body);
    }

    public function test_publisher_and_data_controller_are_named_separately(): void
    {
        $body = $this->fetch(self::CANONICAL)->getContent();

        $publisher = config('legal.developer_name');
        $controller = config('legal.controller_name');

        // Google exige el nombre del desarrollador TAL CUAL figura en la ficha.
        // Habeas Data exige identificar al responsable del tratamiento. Aquí no
        // son la misma persona, así que el documento tiene que nombrar a los dos
        // y decir cuál es cuál: poner solo uno deja cojo el otro requisito.
        $this->assertStringContainsString($publisher, $body);
        $this->assertStringContainsString($controller, $body);
        $this->assertStringContainsString('publicador en google play', mb_strtolower($body));
        $this->assertStringContainsString('responsable del tratamiento', mb_strtolower($body));

        $this->assertNotSame(
            $publisher,
            $controller,
            'Si algún día coinciden, revisa que el texto siga leyéndose bien: '
            .'esta prueba asume que son dos figuras distintas.'
        );
    }

    public function test_policy_discloses_data_retention_practices(): void
    {
        $body = $this->fetch(self::CANONICAL)->getContent();

        // Motivo de rechazo 2 — conservación.
        $this->assertStringContainsString('Conservación de los datos', $body);

        // Plazos concretos que el sistema cumple de verdad (los ejecuta el
        // scheduler): caducidad de los estados y purga de la evidencia de
        // moderación. Si alguien cambia el plazo operativo sin tocar el
        // documento, esta prueba lo delata.
        $this->assertStringContainsString(
            (string) config('legal.retention.story_hours').' horas',
            $body
        );
        $this->assertStringContainsString(
            (string) config('legal.retention.moderation_evidence_days').' días',
            $body
        );
    }

    public function test_policy_explains_in_app_account_deletion(): void
    {
        $body = $this->fetch(self::CANONICAL)->getContent();

        $this->assertStringContainsString('Eliminación de la cuenta', $body);
        // La ruta exacta que existe en la app, no una promesa vaga.
        $this->assertStringContainsString('Eliminar cuenta y datos', $body);
        // Y qué se conserva pese al borrado, que es lo que Google mira.
        $this->assertStringContainsString('obligación legal', $body);
    }

    public function test_policy_declares_the_real_processors(): void
    {
        $body = $this->fetch(self::CANONICAL)->getContent();

        // SDK y proveedores que el código usa de verdad. Declarar «no
        // compartimos datos» con estos dentro sería falso.
        foreach (['Firebase', 'OpenAI', 'Wompi', 'Twilio'] as $processor) {
            $this->assertStringContainsString($processor, $body);
        }
    }

    public function test_both_public_urls_serve_the_same_document(): void
    {
        // El fallo original fue tener dos políticas distintas: la estática que
        // leía Google y la del backend que leía la app. Un solo origen.
        $this->assertSame(
            $this->fetch(self::CANONICAL)->getContent(),
            $this->fetch(self::IN_APP)->getContent(),
        );
    }

    public function test_terms_page_still_works(): void
    {
        $this->fetch('/api/legal/terms');
        $this->fetch('/terms.html');
    }
}
