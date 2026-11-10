<?php

namespace Tests\Feature\UltronStudio\Strategies;

use App\Http\Middleware\EnforceAdminAuthorization;
use App\Models\Admin;
use App\Models\Identity;
use App\Models\RolePermission;
use App\Models\UltronStudio\StudioMessageTemplate;
use App\Models\UltronStudio\StudioPersonBinding;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioAudit;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioMessageTemplates;
use App\Services\UltronStudio\StudioPersonBindings;
use App\Services\UltronStudio\StudioStrategyConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Lo común de las pruebas del gobierno de estrategias (paquete S9).
 *
 * LAS LLAVES NUEVAS (D9-13) todavía no están en `AuthorizationMap` (las clasifica
 * B9-run con la lista `UltronStudioStrategyController::ROUTES`): mientras tanto,
 * las pruebas de la API desactivan SOLO la primera cerradura
 * (`EnforceAdminAuthorization`, {@see withoutRouteMap()}) y conceden las llaves
 * a los roles por la política persistida (`role_permissions`), igual que la
 * pantalla de Roles. La segunda y la tercera (una persona y la llave dentro del
 * servicio) siguen en pie: son las que se prueban aquí.
 *
 * Las PERSONAS: cada cuenta con su identidad; las dos primeras vinculadas por
 * el arranque (origen `bootstrap`, como la consola) y las demás por el CRM.
 */
trait StrategyFixtures
{
    /** @var array<int, array<string, string>> */
    private array $sesionesS9 = [];

    /** Las llaves de D9-13 por rol, como las reparte P9-21. */
    protected function grantStrategyPermissions(): void
    {
        $reparto = [
            Admin::ROLE_SUPER_ADMIN => [StudioAccess::VIEW, StudioAccess::APPROVE, StudioStrategyConfig::EDIT, StudioStrategyConfig::PUBLISH,
                StudioStrategyConfig::ROLLBACK, StudioStrategyConfig::PERSON_BINDINGS, StudioStrategyConfig::SIMULATE_REAL],
            Admin::ROLE_ADMINISTRADOR => [StudioAccess::VIEW, StudioAccess::APPROVE, StudioStrategyConfig::EDIT, StudioStrategyConfig::ROLLBACK, StudioStrategyConfig::SIMULATE_REAL],
        ];
        foreach ($reparto as $rol => $llaves) {
            foreach ($llaves as $llave) {
                RolePermission::query()->updateOrCreate(['role' => $rol, 'permission' => $llave], ['granted' => true]);
            }
        }
        Cache::forget('crm.role_permissions.v1');
    }

    /** Las dos banderas del gobierno: la maestra y la de publicar. */
    protected function strategyFlags(bool $enabled = true, bool $publishing = false, string $window = '', string $gate = 'required'): void
    {
        config([
            'ultron_studio.strategies.enabled' => $enabled,
            'ultron_studio.strategies.publishing_enabled' => $publishing,
            'ultron_studio.strategies.window' => $window,
            'ultron_studio.strategies.simulation_gate' => $gate,
        ]);
    }

    /** La primera cerradura (AuthorizationMap) fuera, mientras B9-run no clasifique las rutas. */
    protected function withoutRouteMap(): void {}

    protected function account(string $role = Admin::ROLE_SUPER_ADMIN, string $name = 'Persona de prueba'): Admin
    {
        return Admin::create([
            'name' => $name,
            'email' => 's9-'.Str::random(10).'@ironbody.test',
            'password' => 'secret-password',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    protected function identity(): Identity
    {
        return Identity::create(['document_normalized' => 'S9'.Str::upper(Str::random(10))]);
    }

    /**
     * Las dos primeras personas vinculadas por el arranque (como la consola).
     *
     * @return array{0: Admin, 1: Admin, 2: Identity, 3: Identity}
     */
    protected function bootstrapPair(string $roleA = Admin::ROLE_SUPER_ADMIN, string $roleB = Admin::ROLE_SUPER_ADMIN): array
    {
        $a = $this->account($roleA, 'Ana Autora');
        $b = $this->account($roleB, 'Beto Revisor');
        $ia = $this->identity();
        $ib = $this->identity();
        app(StudioPersonBindings::class)->bootstrap((int) $a->id, (string) $ia->uuid, (int) $b->id, (string) $ib->uuid, 'Orden escrita del propietario (prueba S9)');

        return [$a, $b, $ia, $ib];
    }

    /**
     * Una cuenta más vinculada a una identidad, creada por `$creator` y
     * verificada por `$verifier` (el camino del CRM, por el servicio).
     */
    protected function bindAccount(Admin $account, Identity $identity, Admin $creator, Admin $verifier): StudioPersonBinding
    {
        $persons = app(StudioPersonBindings::class);
        $r = $persons->create(new StudioAccess($creator), Request::create('/'), (string) Str::uuid(), (int) $account->id, (string) $identity->uuid, null);

        return $persons->verify(new StudioAccess($verifier), Request::create('/'), (string) $r['binding']->uuid);
    }

    /** @return array<string, string> */
    protected function headersOf(Admin $admin): array
    {
        return $this->sesionesS9[$admin->id] ??= $this->actingAsAdmin($admin);
    }

    /** @param  array<string, mixed>  $body */
    protected function s9(string $method, string $path, Admin $as, array $body = []): TestResponse
    {
        return $this->json($method, '/api/admin/ultron-studio/'.ltrim($path, '/'), $body, $this->headersOf($as));
    }

    /**
     * Una plantilla APROBADA (la crea `$author`, la aprueba `$reviewer`), por el servicio.
     *
     * @param  array<string, mixed>|null  $content
     */
    protected function approvedTemplate(Admin $author, Admin $reviewer, string $channel = 'assisted', string $purpose = 'commercial', ?array $content = null, string $key = 'guion-renovacion'): StudioMessageTemplate
    {
        $t = $this->draftTemplate($author, $channel, $purpose, $content, $key);

        return app(StudioMessageTemplates::class)->approve(new StudioAccess($reviewer), Request::create('/'), app(StudioPersonBindings::class), (string) $t->uuid, null);
    }

    /** @param  array<string, mixed>|null  $content */
    protected function draftTemplate(Admin $author, string $channel = 'assisted', string $purpose = 'commercial', ?array $content = null, string $key = 'guion-renovacion'): StudioMessageTemplate
    {
        $content ??= match ($channel) {
            'whatsapp' => ['metaName' => 'renovacion_7d', 'language' => 'es_CO', 'category' => $purpose === 'commercial' ? 'marketing' : 'utility',
                'bodyPreview' => 'Hola {{1}}, tu plan {{2}} vence pronto. Si no quieres más mensajes, responde BAJA.', 'variables' => ['first_name', 'plan_name'], 'optOutLine' => true],
            'voice' => ['purposeKey' => 'renew'],
            default => ['medium' => 'whatsapp', 'script' => 'Saluda, recuerda que su plan vence pronto y ofrece ayuda para renovarlo. Si no quiere más contactos, anótalo.'],
        };

        return app(StudioMessageTemplates::class)->create(new StudioAccess($author), Request::create('/'), app(StudioPersonBindings::class), (string) Str::uuid(), $key, $channel, $purpose, $content)['template'];
    }

    /**
     * Un contenido VÁLIDO (`strategy-content/1`) con la plantilla dada en su
     * único paso (asistido por WhatsApp) y la línea base del Studio.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    protected function strategyContent(StudioMessageTemplate $template, array $override = []): array
    {
        $canal = match ($template->channel) {
            'whatsapp' => 'whatsapp_template',
            'voice' => 'voice_call',
            default => 'assisted:'.(json_decode((string) $template->content_json, true)['medium'] ?? 'whatsapp'),
        };

        return array_replace([
            'format' => 'strategy-content/1',
            'goal' => 'renew',
            'purpose' => 'commercial',
            'priority' => 60,
            'trigger' => ['event' => 'membership.expiring_window', 'params' => ['daysBefore' => 7]],
            'audience' => ['all' => [
                ['fact' => 'membership.active', 'op' => 'eq', 'value' => true],
                ['fact' => 'membership.days_to_expiry', 'op' => 'between', 'value' => ['min' => 0, 'max' => 7, 'minInclusive' => true, 'maxInclusive' => true]],
            ]],
            'success' => ['fact' => 'membership.days_to_expiry', 'op' => 'gt', 'value' => 30],
            'sequence' => [[
                'id' => 'aviso',
                'delay' => ['n' => 0, 'unit' => 'hours', 'from' => 'enrollment'],
                'channels' => [$canal],
                'templates' => [$canal => ['uuid' => (string) $template->uuid, 'hash' => (string) $template->content_hash]],
                'on' => ['no_answer' => 'end_exit'],
            ]],
            'limits' => ['maxTouches' => 3, 'enrollmentDays' => 14, 'newEnrollmentsPerDay' => 100, 'contactsPerDay' => 50, 'cooldownDays' => 30],
            'canary' => ['channel' => 'whatsapp'],
            'studio' => ['kind' => 'baseline', 'catalogVersion' => StudioCatalog::VERSION, 'catalogHash' => StudioCatalog::hash()],
        ], $override);
    }

    /**
     * Personas sintéticas: una que entra (vence en 5 días, activa, con
     * consentimiento de WhatsApp y su canario) y otra que no (vence en 20).
     *
     * @return list<array<string, mixed>>
     */
    protected function syntheticPeople(): array
    {
        return [
            ['ref' => 'p01', 'facts' => ['membership.active' => true, 'membership.days_to_expiry' => 5, 'subject.has_whatsapp_identity' => true, 'subject.is_minor' => false, 'receivable.has_overdue' => false],
                'consent' => ['whatsapp' => 'granted'], 'canary' => ['whatsapp' => true], 'events' => []],
            ['ref' => 'p02', 'facts' => ['membership.active' => true, 'membership.days_to_expiry' => 20], 'consent' => ['whatsapp' => 'granted'], 'events' => []],
        ];
    }

    /** Un martes a las 10:00 de Bogotá (dentro de toda ventana legal), en UTC. */
    protected function weekdayMorning(): Carbon
    {
        return Carbon::parse('2026-11-17 15:00:00', 'UTC');
    }

    protected function audit(): StudioAudit
    {
        return app(StudioAudit::class);
    }

    /** @return list<string> los eventos del libro de un sujeto, en orden */
    protected function auditEventsOf(string $subjectUuid): array
    {
        return DB::table('ultron_studio_audit_events')->where('subject_uuid', $subjectUuid)->orderBy('id')->pluck('event')->all();
    }
}
