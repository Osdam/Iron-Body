<?php

namespace Tests\Unit\Wompi;

use App\Services\Wompi\WompiSignatureService;
use PHPUnit\Framework\TestCase;

/**
 * Vectores DETERMINÍSTICOS de la firma de integridad y el checksum de webhook.
 * Los hashes esperados son literales precomputados: si alguien cambia el orden
 * de concatenación o la normalización, estos tests fallan (guardia de regresión).
 */
class WompiSignatureTest extends TestCase
{
    private function service(): WompiSignatureService
    {
        return new WompiSignatureService([
            'integrity_secret' => 'test_integrity_xyz',
            'events_secret'    => 'test_events_xyz',
        ]);
    }

    public function test_integrity_signature_basic_vector(): void
    {
        $sig = $this->service()->integritySignature('ref-123', 2490000, 'COP');

        $this->assertSame(
            'd93825a18592c5e8125fdcac873ded6f9ab5fea6a3776c4fd0619e99fb00c53a',
            $sig
        );
    }

    public function test_integrity_signature_with_expiration_vector(): void
    {
        $sig = $this->service()->integritySignature(
            'ref-9',
            1500000,
            'COP',
            '2025-01-01T00:00:00.000Z'
        );

        $this->assertSame(
            '8965b0470642cd24ef433b9825195677f434912e3a06ef3f92d050160704d573',
            $sig
        );
    }

    public function test_integrity_signature_uppercases_currency(): void
    {
        $a = $this->service()->integritySignature('ref-123', 2490000, 'cop');
        $b = $this->service()->integritySignature('ref-123', 2490000, 'COP');

        $this->assertSame($b, $a);
    }

    public function test_webhook_checksum_valid(): void
    {
        $payload = $this->event('APPROVED', checksum: strtoupper(
            'ff56f143c274912bbb93eaf781e3a5394d6c9bc5cbcc95bf98fdfba5c3fcc2bd'
        ));

        $this->assertTrue($this->service()->verifyWebhookChecksum($payload));
    }

    public function test_webhook_checksum_case_insensitive(): void
    {
        // Wompi entrega el checksum en mayúsculas; aceptamos ambos casos.
        $payload = $this->event('APPROVED', checksum:
            'ff56f143c274912bbb93eaf781e3a5394d6c9bc5cbcc95bf98fdfba5c3fcc2bd'
        );

        $this->assertTrue($this->service()->verifyWebhookChecksum($payload));
    }

    public function test_webhook_checksum_rejects_tampered_status(): void
    {
        // Checksum LEGÍTIMO de un evento DECLINED; el atacante flipa el status a
        // APPROVED sin recalcular el checksum → debe rechazarse.
        $payload = $this->event('DECLINED', checksum: strtoupper(
            '8f5e75b1703b779ef0df6ee4bab19a58cff3d617fc21da6746e0a47e5907243a'
        ));
        $payload['data']['transaction']['status'] = 'APPROVED';

        $this->assertFalse($this->service()->verifyWebhookChecksum($payload));
    }

    public function test_webhook_checksum_rejects_wrong_secret(): void
    {
        $payload = $this->event('APPROVED', checksum: strtoupper(
            'ff56f143c274912bbb93eaf781e3a5394d6c9bc5cbcc95bf98fdfba5c3fcc2bd'
        ));

        $other = new WompiSignatureService([
            'integrity_secret' => 'x',
            'events_secret'    => 'wrong_secret',
        ]);

        $this->assertFalse($other->verifyWebhookChecksum($payload));
    }

    public function test_webhook_checksum_rejects_missing_signature(): void
    {
        $payload = $this->event('APPROVED', checksum: '');
        unset($payload['signature']['checksum']);

        $this->assertFalse($this->service()->verifyWebhookChecksum($payload));
    }

    /**
     * Evento mínimo cuyo checksum se calcula sobre:
     *   transaction.id + transaction.status + transaction.amount_in_cents
     *   + timestamp + events_secret
     * Con id="01-1532", amount=2490000, timestamp=1530291411.
     */
    private function event(string $status, string $checksum): array
    {
        return [
            'event' => 'transaction.updated',
            'data'  => [
                'transaction' => [
                    'id'              => '01-1532',
                    'status'          => $status,
                    'amount_in_cents' => 2490000,
                ],
            ],
            'environment' => 'test',
            'signature'   => [
                'properties' => [
                    'transaction.id',
                    'transaction.status',
                    'transaction.amount_in_cents',
                ],
                'checksum' => $checksum,
            ],
            'timestamp' => 1530291411,
        ];
    }
}
