<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\FactusPayloadSanitizer;
use Tests\TestCase;

class FactusPayloadSanitizerTest extends TestCase
{
    public function test_removes_forbidden_keys_recursively(): void
    {
        $sanitizer = new FactusPayloadSanitizer([
            'password', 'client_secret', 'access_token', 'authorization', 'token',
        ]);

        $clean = $sanitizer->sanitize([
            'username'     => 'visible',
            'password'     => 'SECRET',
            'auth'         => ['access_token' => 'AAA', 'keep' => 1],
            'headers'      => ['Authorization' => 'Bearer xyz', 'Accept' => 'json'],
            'client_secret' => 'should-go',
        ]);

        $this->assertSame('visible', $clean['username']);
        $this->assertArrayNotHasKey('password', $clean);
        $this->assertArrayNotHasKey('client_secret', $clean);
        $this->assertArrayNotHasKey('access_token', $clean['auth']);
        $this->assertSame(1, $clean['auth']['keep']);
        $this->assertArrayNotHasKey('Authorization', $clean['headers']);
        $this->assertSame('json', $clean['headers']['Accept']);
    }

    public function test_excerpt_truncates_long_strings(): void
    {
        $sanitizer = new FactusPayloadSanitizer([]);
        $long = str_repeat('x', 1200);

        $out = $sanitizer->excerpt(['xml' => $long], 500);

        $this->assertStringEndsWith('…[truncated]', $out['xml']);
        $this->assertLessThan(1200, mb_strlen($out['xml']));
    }
}
