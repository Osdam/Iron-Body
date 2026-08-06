<?php

namespace Tests\Unit;

use App\Services\Observability\LogRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Los logs del canal se leen a diario, se rotan y se copian. Nada de lo que
 * pase por aquí puede llevar un token de Meta ni el teléfono completo de un
 * prospecto: el día que alguien pegue un log en un chat de soporte, no debe
 * estar filtrando ni credenciales ni datos personales.
 */
class LogRedactorTest extends TestCase
{
    #[DataProvider('secretKeys')]
    public function test_anything_that_looks_like_a_credential_is_removed(string $key): void
    {
        $scrubbed = LogRedactor::scrub([$key => 'EAAM4IsMZCvalor-real-larguisimo']);

        $this->assertSame('[redacted]', $scrubbed[$key]);
    }

    public static function secretKeys(): array
    {
        return [
            ['token'], ['access_token'], ['meta_access_token'], ['api_key'], ['apiKey'],
            ['app_secret'], ['webhook_secret'], ['password'], ['authorization'],
            ['x-hub-signature-256'], ['bearer'], ['session_id'], ['private_key'],
        ];
    }

    /** Un secreto parcial sigue siendo una pista: se borra entero, no se enmascara. */
    public function test_a_credential_is_never_partially_shown(): void
    {
        $scrubbed = LogRedactor::scrub(['access_token' => 'EAAM4IsMZC0123456789']);

        $this->assertStringNotContainsString('EAAM', json_encode($scrubbed) ?: '');
        $this->assertStringNotContainsString('6789', json_encode($scrubbed) ?: '');
    }

    /**
     * El teléfono sí se enmascara en vez de borrarse: quien investiga necesita
     * poder reconocer de qué conversación habla sin ver el número completo.
     */
    public function test_a_phone_keeps_only_what_support_needs_to_recognise_it(): void
    {
        $scrubbed = LogRedactor::scrub(['wa_id' => '573143455483']);

        $this->assertSame('57******5483', $scrubbed['wa_id']);
    }

    #[DataProvider('phoneKeys')]
    public function test_every_field_that_carries_a_phone_is_masked(string $key): void
    {
        $scrubbed = LogRedactor::scrub([$key => '573150536026']);

        $this->assertStringNotContainsString('3150536', (string) $scrubbed[$key]);
    }

    public static function phoneKeys(): array
    {
        return [['phone'], ['wa_id'], ['recipient'], ['from'], ['to'], ['display_phone_number']];
    }

    /** Un id corto no es un teléfono y no debe quedar destrozado. */
    public function test_a_short_value_is_left_alone(): void
    {
        $this->assertSame('abc123', LogRedactor::scrub(['from' => 'abc123'])['from']);
    }

    public function test_nested_structures_are_scrubbed_too(): void
    {
        $scrubbed = LogRedactor::scrub([
            'event' => 'meta.webhook.received',
            'payload' => [
                'contacts' => [['wa_id' => '573143455483']],
                'auth' => ['access_token' => 'secretísimo'],
            ],
        ]);

        $this->assertSame('57******5483', $scrubbed['payload']['contacts'][0]['wa_id']);
        $this->assertSame('[redacted]', $scrubbed['payload']['auth']['access_token']);
        $this->assertSame('meta.webhook.received', $scrubbed['event']);
    }

    /** Una estructura absurdamente anidada no puede colgar el logger. */
    public function test_deep_nesting_is_cut_instead_of_looping(): void
    {
        $deep = ['v' => 'hoja'];
        for ($i = 0; $i < 40; $i++) {
            $deep = ['nivel' => $deep];
        }

        $scrubbed = LogRedactor::scrub($deep);

        $this->assertStringContainsString('max_depth', json_encode($scrubbed) ?: '');
    }

    public function test_long_free_text_is_trimmed_for_the_log(): void
    {
        $preview = LogRedactor::preview(str_repeat('hola ', 200), 40);

        $this->assertLessThanOrEqual(41, mb_strlen((string) $preview));
        $this->assertStringEndsWith('…', (string) $preview);
    }

    public function test_whitespace_in_a_preview_is_collapsed(): void
    {
        $this->assertSame('hola que tal', LogRedactor::preview("hola\n\n  que   tal"));
    }

    public function test_an_empty_preview_is_null_not_an_empty_string(): void
    {
        $this->assertNull(LogRedactor::preview(null));
        $this->assertNull(LogRedactor::preview(''));
    }
}
