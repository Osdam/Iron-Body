<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\RolePermission;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabVerdict;
use App\Models\UltronStudio\StudioPublication;
use App\Models\UltronStudio\StudioVersion;
use App\Models\UltronStudio\StudioVersionReview;
use App\Services\Marketing\Ultron\UltronPublishedConfig;
use App\Services\UltronStudio\StudioGraphValidator;
use App\Services\UltronStudio\StudioLabPrompts;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use App\Services\UltronStudio\StudioVersions;
use App\Support\Access\RolePermissionPolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Datos y peticiones de la PUBLICACIÓN del Studio (Hito 5) para las pruebas
 * Feature. Se apoya en {@see StudioFixtures} (personas con sesión real, versiones
 * sembradas, la línea base).
 *
 * El motor «como en producción» para publicar: encendido, con la conversación
 * canaria configurada (una propia de la prueba, con un id que no se confunde con
 * ningún otro número: así se puede comprobar que no sale en ninguna respuesta),
 * el cobro apagado; las dos llaves abiertas, sin ventana y con la puerta del
 * laboratorio dispensada, salvo que cada prueba diga otra cosa.
 */
trait PublicationFixtures
{
    /** El id de la conversación canaria de las pruebas: inconfundible, para buscarlo en las respuestas. */
    protected const CANARY_ID = 987654321;

    protected ?MarketingConversation $canaryConversation = null;

    /** La conversación canaria (la crea la primera vez, con su prospecto). */
    protected function canary(): MarketingConversation
    {
        if ($this->canaryConversation !== null) {
            return $this->canaryConversation;
        }
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3009998877', 'name' => 'Prospecto del canario']);

        return $this->canaryConversation = MarketingConversation::query()->forceCreate([
            'id' => self::CANARY_ID, 'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
        ]);
    }

    /**
     * El motor como en producción y las dos llaves abiertas (por defecto, la
     * del motor en `canary`).
     *
     * @param  array<string, mixed>  $extra
     */
    protected function openKeys(array $extra = []): void
    {
        $this->canary();
        config($extra + [
            'marketing.ultron.enabled' => true,
            'marketing.ultron.payment_links_enabled' => false,
            'marketing.ultron.canary_conversation_id' => self::CANARY_ID,
            'marketing.ultron.studio_config' => 'canary',
            'ultron_studio.publishing_enabled' => true,
            'ultron_studio.publishing.lab_gate' => 'waived',
            'ultron_studio.publishing.window' => '',
            'ultron_studio.publishing.canary_min_hours' => 24,
            'ultron_studio.publishing.canary_min_turns' => 3,
        ]);
    }

    /**
     * Una persona con EXACTAMENTE estos permisos (un rol propio), con su sesión.
     *
     * @param  list<string>  $permissions
     */
    protected function personWith(array $permissions, string $name): Admin
    {
        $rol = 'Prueba-'.substr(md5(implode(',', $permissions)), 0, 8);
        AdminRole::firstOrCreate(['name' => $rol], ['is_system' => false]);
        foreach ($permissions as $p) {
            RolePermission::updateOrCreate(['role' => $rol, 'permission' => $p], ['granted' => true]);
        }
        app(RolePermissionPolicy::class)->flush();

        return $this->person($rol, $name);
    }

    /**
     * Una versión APROBADA por la API (creada por `$author`, aprobada por
     * `$reviewer` reconociendo sus avisos). Por defecto, la línea base: lo que
     * lee hoy el motor.
     *
     * @param  array<string, array<string, mixed>>  $configs
     */
    protected function approvedVersion(Admin $author, Admin $reviewer, string $label = 'Propuesta para publicar', array $configs = []): StudioVersion
    {
        $uuid = (string) $this->createVersion($author, $label, null, $configs === [] ? null : $this->contentWith($configs))->assertCreated()->json('data.uuid');
        $avisos = $this->studio('POST', "versions/{$uuid}/submit", $author)->assertOk()->json('data.warnings');
        $this->studio('POST', "versions/{$uuid}/approve", $reviewer, ['acknowledgedWarnings' => $avisos])->assertOk();

        return StudioVersion::query()->with('review')->where('uuid', $uuid)->sole();
    }

    /**
     * Una versión SEMBRADA en un estado cualquiera, con su reseña aprobada si
     * ya pasó por la revisión. `$acknowledged` null = los avisos de verdad.
     *
     * @param  array<string, mixed>  $content
     * @param  list<string>|null  $acknowledged
     */
    protected function seededVersion(Admin $author, Admin $reviewer, array $content, string $status = 'APPROVED', ?string $hash = null, ?array $acknowledged = null): StudioVersion
    {
        $version = $this->seedVersion($author, $content, $status, $hash);
        if (! in_array($status, ['DRAFT', 'VALIDATED', 'REVIEW_REQUIRED', 'REJECTED', 'VALIDATING'], true)) {
            $avisos = StudioGraphValidator::warnings(StudioVersions::findings($version->content));
            StudioVersionReview::query()->forceCreate([
                'version_id' => $version->id,
                'decision' => StudioVersionReview::DECISION_APPROVED,
                'reviewer_admin_id' => $reviewer->id,
                'reviewer_name' => $reviewer->name,
                'reviewer_role' => $reviewer->role,
                'note' => null,
                'acknowledged_warnings' => $acknowledged ?? $avisos,
                'validation' => ['catalogVersion' => $version->catalog_version, 'warnings' => $avisos],
                'content_hash' => $version->content_hash,
            ]);
        }

        return StudioVersion::query()->with('review')->whereKey($version->id)->sole();
    }

    /** @return array<string, mixed> `GET versions/{uuid}/publication-preview` */
    protected function previewOf(Admin $as, string $uuid): array
    {
        return $this->studio('GET', "versions/{$uuid}/publication-preview", $as)->assertOk()->json('data');
    }

    /**
     * Publicar como lo hace el CRM: con la vista previa abierta, su huella y su
     * conjunto «sin efecto» reconocido.
     *
     * @param  array<string, mixed>  $override
     */
    protected function publishAs(Admin $as, string $uuid, ?string $clientRequestId = null, array $override = []): TestResponse
    {
        $vista = $this->previewOf($as, $uuid);

        return $this->studio('POST', "versions/{$uuid}/publish", $as, $override + [
            'clientRequestId' => $clientRequestId ?? (string) Str::uuid(),
            'previewHash' => $vista['previewHash'],
            'acknowledgedNoEffect' => $vista['noEffect'],
        ]);
    }

    /** @return array<string, mixed> `GET versions/{uuid}/canary-evidence` */
    protected function evidenceOf(Admin $as, string $uuid): array
    {
        return $this->studio('GET', "versions/{$uuid}/canary-evidence", $as)->assertOk()->json('data');
    }

    /** Promover como lo hace el CRM: con la evidencia abierta y su huella. */
    protected function promoteAs(Admin $as, string $uuid, ?string $clientRequestId = null, ?string $evidenceHash = null): TestResponse
    {
        return $this->studio('POST', "versions/{$uuid}/promote", $as, [
            'clientRequestId' => $clientRequestId ?? (string) Str::uuid(),
            'evidenceHash' => $evidenceHash ?? $this->evidenceOf($as, $uuid)['evidenceHash'],
        ]);
    }

    protected function rollbackAs(Admin $as, string $uuid, string $reason = 'Revertir en la prueba', ?string $clientRequestId = null): TestResponse
    {
        return $this->studio('POST', "versions/{$uuid}/rollback", $as, ['clientRequestId' => $clientRequestId ?? (string) Str::uuid(), 'reason' => $reason]);
    }

    protected function legacyAs(Admin $as, string $reason = 'Volver a Legacy en la prueba', ?string $clientRequestId = null): TestResponse
    {
        return $this->studio('POST', 'publications/legacy', $as, ['clientRequestId' => $clientRequestId ?? (string) Str::uuid(), 'reason' => $reason]);
    }

    /**
     * Turnos del canario como los deja el commit: filas de `marketing_ai_actions`
     * del mensaje entrante, cada una con su sello. Por defecto `applied` con la
     * versión dada.
     *
     * @param  array<string, mixed>|null  $studio  el sello entero (null: sin sello)
     * @param  array<string, mixed>  $metadata
     */
    protected function stampTurns(int $n, ?string $versionUuid, ?CarbonInterface $at = null, ?array $studio = [], array $metadata = [], string $sourceType = 'inbound_message', ?int $conversationId = null): void
    {
        $conversacion = $conversationId !== null ? MarketingConversation::query()->findOrFail($conversationId) : $this->canary();
        $sello = $studio === null ? null : $studio + [
            'v' => 1, 'env' => 'canary', 'state' => 'applied', 'entry' => null, 'seq' => 1,
            'part' => 'canary', 'version' => $versionUuid, 'contentHash' => str_repeat('a', 64), 'reason' => null,
        ];
        for ($i = 0; $i < $n; $i++) {
            $cuando = $at ?? now();
            (new MarketingAiAction)->forceFill([
                'lead_id' => $conversacion->lead_id,
                'conversation_id' => $conversacion->id,
                'action_type' => 'reply',
                'status' => 'executed',
                'source_type' => $sourceType,
                'source_event_id' => random_int(1, 2_000_000_000),
                'idempotency_key' => 'prueba-h5-'.Str::uuid(),
                'metadata' => array_filter(['origin' => 'ultron', 'studio' => $sello] + $metadata, static fn (mixed $v): bool => $v !== null),
                'created_at' => $cuando,
                'updated_at' => $cuando,
            ])->save();
        }
    }

    /**
     * Un veredicto del laboratorio sobre una ejecución de esta versión, sembrado
     * (el laboratorio de verdad lo prueba su propia suite).
     */
    protected function labVerdict(StudioVersion $version, string $decision, string $promptVersion = StudioLabPrompts::PROMPT_VERSION): StudioLabVerdict
    {
        $juez = $this->person(Admin::ROLE_SUPER_ADMIN, 'Juez del laboratorio');
        $run = StudioLabRun::query()->forceCreate([
            'client_request_id' => (string) Str::uuid(),
            'target_kind' => StudioLabRun::TARGET_VERSION,
            'target_version_id' => $version->id,
            'target_label' => mb_substr((string) $version->label, 0, 80),
            'target_content_hash' => $version->content_hash,
            'target_snapshot' => ['kind' => 'version', 'uuid' => $version->uuid],
            'catalog_version' => $version->catalog_version,
            'mode' => 'openai',
            'model' => 'gpt-lab-pruebas',
            'prompt_version' => $promptVersion,
            'prices' => ['input' => 1, 'output' => 1, 'actorMaxOutputTokens' => 400, 'judgeMaxOutputTokens' => 900],
            'status' => StudioLabRun::STATUS_COMPLETED,
            'case_count' => 1,
            'estimate_expected_micros' => 0,
            'estimate_max_micros' => 0,
            'accepted_max_micros' => 0,
            'results_hash' => str_repeat('c', 64),
            'budget_day' => now()->toDateString(),
            'created_by_admin_id' => $juez->id,
            'created_by_name' => $juez->name,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        return StudioLabVerdict::query()->forceCreate([
            'run_id' => $run->id,
            'version_id' => $version->id,
            'version_content_hash' => $version->content_hash,
            'results_hash' => str_repeat('c', 64),
            'decision' => $decision,
            'note' => null,
            'acknowledged_cases' => $decision === StudioLabVerdict::DECISION_APPROVED ? [] : null,
            'reviewer_admin_id' => $juez->id,
            'reviewer_name' => $juez->name,
            'reviewer_role' => $juez->role,
            'created_at' => now(),
        ]);
    }

    /**
     * La parte de una versión (§A4.2), como la escribiría el Studio.
     *
     * @return array<string, mixed>
     */
    protected function partOf(StudioVersion $version, int $approvedBy = 1, int $publishedBy = 1): array
    {
        return D::part((string) $version->uuid, (string) $version->content_hash, (string) $version->catalog_version, $approvedBy, $publishedBy, D::declared($version->content['configs']));
    }

    /**
     * Una entrada de la cadena SEMBRADA, firmada con la firma del motor (o con
     * lo que se diga en `$over`, para alterarla): solo inserción, como la base
     * exige.
     *
     * @param  array<string, mixed>|null  $canary
     * @param  array<string, mixed>|null  $rest
     * @param  array<string, mixed>  $over
     */
    protected function seedEntry(string $action, ?StudioVersion $version, ?array $canary, ?array $rest, array $over = [], ?int $previous = null): StudioPublication
    {
        $cabeza = StudioPublication::query()->orderByDesc('seq')->first();
        $seq = ($cabeza !== null ? (int) $cabeza->seq : 0) + 1;
        $uuid = strtolower((string) Str::uuid());
        $d = D::declaration($uuid, $seq, now(), $canary, $rest, UltronPublishedConfig::signatureFor(...));

        return StudioPublication::query()->forceCreate($over + [
            'uuid' => $uuid,
            'seq' => $seq,
            'client_request_id' => (string) Str::uuid(),
            'action' => $action,
            'version_id' => $version?->id,
            'restores_publication_id' => null,
            'previous_publication_id' => $previous ?? $cabeza?->id,
            'engine_contract' => $d['contract'],
            'effective_json' => $d['effectiveJson'],
            'effective_hash' => $d['effectiveHash'],
            'signature' => $d['signature'],
            'catalog_version' => (string) ($version?->catalog_version ?? '2026-10-19.1'),
            'lab_gate' => ['mode' => $action === D::ACTION_PUBLISH ? 'waived' : null],
            'acknowledged' => null,
            'evidence' => null,
            'reason' => in_array($action, [D::ACTION_ROLLBACK, D::ACTION_LEGACY], true) ? 'Sembrada en la prueba' : null,
            'actor_admin_id' => 1,
            'actor_name' => 'Siembra',
            'actor_role' => null,
            'created_at' => now(),
        ]);
    }

    /** Tras mover el reloj más de 12 h (la caducidad de la sesión del personal), sesiones nuevas. */
    protected function freshSessions(): void
    {
        $this->sesiones = [];
    }

    protected function chainHead(): ?StudioPublication
    {
        return StudioPublication::query()->orderByDesc('seq')->first();
    }

    /**
     * Las versiones que declara la cabeza, por parte (sin cabeza, las dos null).
     *
     * @return array{canary:string|null,rest:string|null}
     */
    protected function headVersions(): array
    {
        $cabeza = $this->chainHead();
        if ($cabeza === null) {
            return ['canary' => null, 'rest' => null];
        }
        $partes = D::parts((string) $cabeza->effective_json, (string) $cabeza->uuid, (int) $cabeza->seq);

        return ['canary' => $partes['canary']['version'] ?? null, 'rest' => $partes['rest']['version'] ?? null];
    }

    /**
     * La INVARIANTE de §A2: como mucho una CANARY y una PUBLISHED, y la cabeza
     * coincide con ellas (la CANARY es la parte canaria cuando difiere del resto;
     * la PUBLISHED, la parte resto).
     */
    protected function assertChainMatchesStates(): void
    {
        $canarias = StudioVersion::query()->where('status', 'CANARY')->pluck('uuid')->all();
        $publicadas = StudioVersion::query()->where('status', 'PUBLISHED')->pluck('uuid')->all();
        $this->assertLessThanOrEqual(1, count($canarias), 'dos versiones en CANARY');
        $this->assertLessThanOrEqual(1, count($publicadas), 'dos versiones en PUBLISHED');

        ['canary' => $c, 'rest' => $r] = $this->headVersions();
        $this->assertSame($c !== $r ? $c : null, $canarias[0] ?? null, 'la versión en CANARY no es la parte canaria de la cabeza');
        $this->assertSame($r, $publicadas[0] ?? null, 'la versión PUBLISHED no es la parte resto de la cabeza');
    }

    /**
     * El sello que pondría el motor ahora en un turno de esa conversación: el
     * LECTOR REAL del motor (escritor → lector).
     *
     * @return array<string, mixed>|null
     */
    protected function engineStamp(int $conversationId): ?array
    {
        return app(UltronPublishedConfig::class)->forConversation($conversationId);
    }
}
