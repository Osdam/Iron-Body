<?php

namespace Tests\Feature\Moderation;

use App\Models\Admin;
use App\Models\Member;
use App\Models\MemberUgcConsent;
use App\Models\Story;
use App\Models\User;
use App\Services\Admin\AdminSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Base común de la suite de moderación.
 *
 * Aísla TODA integración externa:
 *  - `Http::preventStrayRequests()` hace fallar el test si algún código
 *    intentara una llamada HTTP real (Firebase, FCM, Wompi, Factus).
 *  - Storage fake para el disco público.
 *  - Queue fake: los push de FCM se encolan `afterResponse` y nunca se ejecutan.
 *
 * Ningún test de este directorio toca facturación, Wompi, IVA ni membresías.
 */
abstract class ModerationTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sin red real. Si algo intenta salir, el test falla en vez de colgarse.
        Http::preventStrayRequests();
        Http::fake();

        Storage::fake('public');
        Queue::fake();
        Notification::fake();

        // Baseline determinista: la configuración por defecto del sistema.
        config([
            'ugc.reports_enabled' => true,
            'ugc.blocking_enabled' => true,
            'ugc.appeals_enabled' => true,
            'ugc.auto_quarantine_enabled' => false,
            'ugc.guidelines_required_to_post' => true,
            'ugc.guidelines_version' => '1.0',
            'ugc.posting_age_enforced' => false,
            'ugc.report_rate_limit_per_hour' => 10,
        ]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    protected function makeMember(string $name = 'Miembro Uno', array $overrides = []): Member
    {
        $suffix = uniqid();

        $user = User::create([
            'name' => $name,
            'email' => "u{$suffix}@example.test",
            'password' => 'secret',
            'document' => substr((string) crc32($suffix), 0, 10),
            'phone' => '3001234567',
            'status' => 'active',
            'plan' => 'PLAN TOTAL',
            'membership_end_date' => now()->addDays(30)->toDateString(),
        ]);

        return Member::create(array_merge([
            'user_id' => $user->id,
            'full_name' => $name,
            'email' => "u{$suffix}@example.test",
            'document_number' => substr((string) crc32($suffix), 0, 10),
            'phone' => '3001234567',
            'access_hash' => 'tok-'.$suffix,
            'status' => Member::STATUS_ACTIVE,
        ], $overrides));
    }

    /** Headers de un miembro autenticado (bearer = access_hash). */
    protected function asMember(Member $member): array
    {
        return ['Authorization' => 'Bearer '.$member->access_hash];
    }

    protected function makeAdmin(string $role = Admin::ROLE_SUPER_ADMIN): Admin
    {
        return Admin::create([
            'name' => 'Admin '.$role,
            'email' => 'admin'.uniqid().'@ironbody.test',
            'password' => 'super-secret',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    /** Headers de un admin con sesión REAL (no el token compartido de n8n). */
    protected function asAdmin(Admin $admin): array
    {
        $issued = app(AdminSessionService::class)->issueSession($admin);

        return ['Authorization' => 'Bearer '.$issued['token']];
    }

    /** Story de un miembro, almacenada en Firebase (caso real de la app). */
    protected function makeStory(Member $author, array $overrides = []): Story
    {
        return Story::create(array_merge([
            'author_type' => 'member',
            'author_id' => $author->id,
            'author_name' => $author->full_name,
            'type' => 'image',
            'file_path' => 'stories/'.$author->id.'/'.uniqid().'.jpg',
            'download_url' => 'https://firebasestorage.example/o/story?token=abc',
            'disk' => 'firebase',
            'expires_at' => now()->addHours(24),
        ], $overrides));
    }

    /**
     * URL de descarga VÁLIDA del bucket propio.
     *
     * El endpoint `stories/firebase` rechaza medios alojados fuera de nuestro
     * Storage, así que los tests deben usar una URL con la misma forma que
     * emite el SDK de Firebase. Se construye desde la config para que cambiar
     * el bucket no obligue a tocar cada test.
     */
    protected function storageUrl(string $objectPath): string
    {
        return sprintf(
            'https://firebasestorage.googleapis.com/v0/b/%s/o/%s?alt=media&token=%s',
            (string) config('services.firebase.storage_bucket'),
            rawurlencode($objectPath),
            uniqid(),
        );
    }

    /** Acepta los lineamientos para poder publicar en los tests que lo requieran. */
    protected function acceptGuidelines(Member $member): void
    {
        MemberUgcConsent::create([
            'member_id' => $member->id,
            'community_guidelines_version' => (string) config('ugc.guidelines_version'),
            'accepted_at' => now(),
        ]);
    }
}
