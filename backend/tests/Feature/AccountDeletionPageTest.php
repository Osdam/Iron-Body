<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Página pública de eliminación de cuenta.
 *
 * Google Play exige, para toda app que permite crear cuenta, un recurso web
 * donde iniciar la baja SIN reinstalar la aplicación, aparte del borrado que ya
 * existe dentro de la app. Es la URL que se declara en Play Console →
 * Seguridad de los datos → Eliminación de datos.
 *
 * Estas pruebas fijan lo verificable: que la página exista sin login, que
 * identifique la app y a quien la publica, que explique las dos vías y la
 * verificación de identidad, y —lo que más importa— que no contradiga a la
 * política de privacidad en los plazos de conservación. Las dos páginas leen
 * los mismos valores de config/legal.php, y este test falla si alguien escribe
 * un plazo a mano en una de ellas.
 */
class AccountDeletionPageTest extends TestCase
{
    use RefreshDatabase;

    /** URL declarada en Play Console. */
    private const CANONICAL = '/account-deletion.html';

    /** La equivalente bajo /api, por si la app la enlaza. */
    private const IN_APP = '/api/legal/account-deletion';

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

    /**
     * Texto de la página en minúsculas y con los espacios colapsados.
     *
     * El HTML parte las frases en varias líneas para que el fuente sea legible,
     * así que buscar «correo electrónico registrado» tal cual falla por un salto
     * de línea. Lo que se afirma aquí es el contenido, no el formateo.
     */
    private function text(string $uri): string
    {
        return (string) preg_replace('/\s+/u', ' ', mb_strtolower($this->fetch($uri)->getContent()));
    }

    public function test_page_is_public_html_without_login(): void
    {
        $body = $this->fetch(self::CANONICAL)->getContent();

        $this->assertStringContainsString('Eliminar tu cuenta', $body);
        // Sin login y sin formulario: la baja real se verifica por un canal
        // atendido por una persona, no por un POST anónimo.
        $this->assertStringNotContainsString('<form', $body);
        $this->assertStringNotContainsString('<script', $body);
    }

    public function test_page_identifies_app_package_and_publisher(): void
    {
        $body = $this->fetch(self::CANONICAL)->getContent();

        $this->assertStringContainsString((string) config('legal.app_name'), $body);
        $this->assertStringContainsString((string) config('legal.android_package'), $body);
        $this->assertStringContainsString((string) config('legal.developer_name'), $body);
        $this->assertStringContainsString((string) config('legal.controller_name'), $body);
        $this->assertStringContainsString((string) config('legal.privacy_email'), $body);
    }

    public function test_page_explains_both_routes_to_delete(): void
    {
        $body = $this->text(self::CANONICAL);

        // Dentro de la app.
        $this->assertStringContainsString('perfil → eliminar cuenta y datos', $body);
        // Y sin ella: el motivo por el que Google pide esta página.
        $this->assertStringContainsString('desinstalaste', $body);
        $this->assertStringContainsString('eliminar cuenta', $body);
    }

    public function test_page_explains_identity_verification(): void
    {
        $body = $this->text(self::CANONICAL);

        // Conocer el documento no puede bastar para borrar la cuenta de otro.
        $this->assertStringContainsString('correo electrónico registrado', $body);
        $this->assertStringContainsString('teléfono registrado', $body);
        $this->assertStringContainsString('no la ejecutamos', $body);
        // Y la advertencia antiphishing.
        $this->assertStringContainsString('nunca te pediremos tu contraseña', $body);
    }

    public function test_page_distinguishes_deletion_from_suspension(): void
    {
        $body = $this->text(self::CANONICAL);

        $this->assertStringContainsString('suspensión', $body);
        $this->assertStringContainsString('no tiene vuelta atrás', $body);
    }

    public function test_retention_periods_match_the_privacy_policy(): void
    {
        $page = $this->fetch(self::CANONICAL)->getContent();
        $policy = $this->fetch('/privacy-policy.html')->getContent();

        $evidence = (int) config('legal.retention.moderation_evidence_days');
        $storyHours = (int) config('legal.retention.story_hours');

        // Los únicos plazos numéricos publicados. Si alguien inventa uno nuevo
        // en cualquiera de las dos páginas, esto deja de cuadrar.
        foreach (["{$evidence} días", "{$storyHours} horas"] as $needle) {
            $this->assertStringContainsString($needle, $page, "falta «{$needle}» en la página de eliminación");
            $this->assertStringContainsString($needle, $policy, "falta «{$needle}» en la política");
        }
    }

    public function test_page_declares_what_is_kept_and_why(): void
    {
        $body = $this->text(self::CANONICAL);

        $this->assertStringContainsString('obligación legal, contable y tributaria', $body);
        $this->assertStringContainsString('anonimizado', $body);
    }

    public function test_privacy_policy_links_to_this_page(): void
    {
        $policy = $this->fetch('/privacy-policy.html')->getContent();

        // Que la URL sea «readily discoverable» es parte del requisito de Play.
        $this->assertStringContainsString((string) config('legal.account_deletion_url'), $policy);
    }

    public function test_both_urls_serve_the_same_document(): void
    {
        $this->assertSame(
            $this->fetch(self::CANONICAL)->getContent(),
            $this->fetch(self::IN_APP)->getContent(),
            'Las dos rutas deben servir el MISMO documento; si divergen volvemos al problema de las dos políticas.'
        );
    }

    public function test_page_is_cacheable_for_minutes_not_days(): void
    {
        $cacheControl = (string) $this->fetch(self::CANONICAL)->headers->get('cache-control');

        $this->assertStringContainsString('max-age=300', $cacheControl);
        $this->assertStringNotContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('no-store', $cacheControl);
    }

    public function test_page_does_not_set_cookies(): void
    {
        $res = $this->fetch(self::CANONICAL);

        $this->assertCount(
            0,
            $res->headers->getCookies(),
            'Una página pública de privacidad no puede plantar cookies al visitante.'
        );
    }
}
