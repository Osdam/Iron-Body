<?php

namespace Tests\Feature;

use App\Exceptions\IdentityLinkException;
use App\Models\Identity;
use App\Models\Member;
use App\Models\Trainer;
use App\Services\Identity\IdentityLinkService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 1 — Identidad y perfiles. Verifica el backfill, la unicidad por documento
 * (anti-duplicado) y que el enlace de perfiles exige verificación de propiedad.
 * Garantiza además la independencia entre ser miembro y ser entrenador.
 */
class IdentityProfilesTest extends TestCase
{
    use RefreshDatabase;

    private function service(): IdentityLinkService
    {
        return app(IdentityLinkService::class);
    }

    private function makeMember(string $document, array $attrs = []): Member
    {
        return Member::create(array_merge([
            'full_name' => 'Member '.$document,
            'document_number' => $document,
            'phone' => '+573001112233',
            'status' => Member::STATUS_ACTIVE,
        ], $attrs));
    }

    private function makeTrainer(?string $document, array $attrs = []): Trainer
    {
        return Trainer::create(array_merge([
            'full_name' => 'Trainer '.($document ?? 'nodoc'),
            'document' => $document,
            'phone' => '+573004445566',
            'status' => 'active',
        ], $attrs));
    }

    public function test_backfill_links_member_and_trainer_sharing_document_to_one_identity(): void
    {
        // Documento equivalente pero escrito distinto: misma persona.
        $member = $this->makeMember('1.234.567-8');
        $trainer = $this->makeTrainer('12345678');

        $this->assertNull($member->identity_id);
        $this->assertNull($trainer->identity_id);

        $counts = $this->service()->backfillExisting();

        $member->refresh();
        $trainer->refresh();

        $this->assertSame(1, $counts['members']);
        $this->assertSame(1, $counts['trainers']);
        $this->assertNotNull($member->identity_id);
        $this->assertSame($member->identity_id, $trainer->identity_id);
        $this->assertSame(1, Identity::count());
    }

    public function test_backfill_creates_separate_identities_for_different_documents(): void
    {
        $this->makeMember('1111');
        $this->makeTrainer('2222');

        $this->service()->backfillExisting();

        $this->assertSame(2, Identity::count());
    }

    public function test_backfill_is_idempotent(): void
    {
        $this->makeMember('1234567890');
        $this->makeTrainer('1234567890');

        $first = $this->service()->backfillExisting();
        $second = $this->service()->backfillExisting();

        $this->assertSame(['members' => 1, 'trainers' => 1], $first);
        $this->assertSame(['members' => 0, 'trainers' => 0], $second);
        $this->assertSame(1, Identity::count());
    }

    public function test_ensure_identity_does_not_duplicate_for_same_document(): void
    {
        // Mismo número escrito con y sin separadores: misma identidad.
        $a = $this->service()->ensureIdentity('99.888.777');
        $b = $this->service()->ensureIdentity('99888777');

        $this->assertTrue($a->is($b));
        $this->assertSame(1, Identity::count());
    }

    public function test_document_normalized_is_unique_at_database_level(): void
    {
        Identity::create(['document_normalized' => '55555']);

        $this->expectException(QueryException::class);
        Identity::create(['document_normalized' => '55555']);
    }

    public function test_trainer_without_document_gets_dedicated_identity(): void
    {
        $this->makeTrainer(null);
        $this->makeTrainer(null);

        $this->service()->backfillExisting();

        // Sin documento no se fusionan: una identidad por entrenador.
        $this->assertSame(2, Identity::count());
    }

    public function test_attach_requires_ownership_verification(): void
    {
        $member = $this->makeMember('70001');
        $identity = $this->service()->ensureIdentity('70001');

        $this->expectException(IdentityLinkException::class);
        $this->service()->attachMember($member, $identity, ownershipVerified: false);
    }

    public function test_attach_is_idempotent_without_verification_when_already_linked(): void
    {
        $member = $this->makeMember('70002');
        $this->service()->backfillExisting();
        $member->refresh();
        $identity = $member->identity;

        // Ya está enlazado a esa identidad: re-enlazar es no-op, sin OTP.
        $result = $this->service()->attachMember($member, $identity, ownershipVerified: false);

        $this->assertSame($member->identity_id, $result->identity_id);
    }

    public function test_verified_attach_links_member_and_trainer_to_same_identity(): void
    {
        // Una persona ya entrenador que ahora abre cuenta de miembro: tras OTP
        // verificado, ambos perfiles comparten UNA identidad (no se duplica).
        $trainer = $this->makeTrainer('80001');
        $this->service()->backfillExisting();
        $trainer->refresh();
        $identity = $trainer->identity;

        $member = $this->makeMember('80001');
        $this->service()->attachMember($member, $identity, ownershipVerified: true);
        $member->refresh();

        $this->assertSame($trainer->identity_id, $member->identity_id);
        $this->assertTrue($identity->fresh()->hasMemberProfile());
        $this->assertTrue($identity->fresh()->hasActiveTrainerProfile());
    }

    public function test_deactivating_trainer_keeps_member_profile_and_identity(): void
    {
        $member = $this->makeMember('90001');
        $trainer = $this->makeTrainer('90001');
        $this->service()->backfillExisting();
        $member->refresh();
        $trainer->refresh();
        $identityId = $member->identity_id;

        // El entrenador se desactiva en el CRM.
        $trainer->update(['status' => 'inactive']);

        $member->refresh();
        $this->assertSame($identityId, $member->identity_id, 'El perfil de miembro conserva su identidad.');
        $this->assertSame(Member::STATUS_ACTIVE, $member->status);
        $this->assertFalse($trainer->identity->fresh()->hasActiveTrainerProfile());
        $this->assertTrue($trainer->identity->fresh()->hasMemberProfile());
    }
}
