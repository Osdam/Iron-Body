<?php

namespace Tests\Feature\UltronStudio\MetaTemplates;

use App\Models\Admin;
use App\Models\UltronStudio\StudioMessageTemplate;
use App\Models\UltronStudio\StudioMetaTemplateSubmission;
use App\Services\UltronStudio\StudioMetaTemplates;
use App\Services\UltronStudio\StudioTemplateMetaChecks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\UltronStudio\Strategies\StrategyFixtures;

/**
 * Lo común de las pruebas del paso 3 de las plantillas de Meta
 * (PLANTILLAS_META_PLAN §5.4–§5.12): dos personas vinculadas (Ana escribe y
 * envía, Beto aprueba), las banderas del módulo, una versión de WhatsApp
 * aprobada cuyo nombre en Meta elige el escenario del doble (`aprobar_`,
 * `pausar_`, `tardar_`, `editar_`, `recategorizar_`, `borrar_`, `caer_`,
 * `perder_`, `fallar_`) y atajos a las rutas y a la sincronización.
 */
trait MetaTemplateFixtures
{
    use StrategyFixtures;

    protected const WABA = '102938475610293';

    protected Admin $ana;

    protected Admin $beto;

    protected function metaWorld(): void
    {
        $this->grantStrategyPermissions();
        $this->strategyFlags(enabled: true);
        [$this->ana, $this->beto] = $this->bootstrapPair();
        $this->metaFlags();
    }

    /** Las banderas del módulo y la conexión con Meta (por defecto, el doble: sin red). */
    protected function metaFlags(bool $enabled = true, bool $submit = true, bool $sync = true, bool $metaEnabled = true, string $driver = 'fake', ?string $base = null, ?string $waba = self::WABA): void
    {
        config([
            'ultron_studio.meta_templates.enabled' => $enabled,
            'ultron_studio.meta_templates.submit_enabled' => $submit,
            'ultron_studio.meta_templates.sync_enabled' => $sync,
            'ultron_studio.meta_templates.driver' => $driver,
            'ultron_studio.meta_templates.graph_version' => 'v25.0',
            'ultron_studio.meta_templates.graph_base' => $base,
            'meta.enabled' => $metaEnabled,
            'meta.whatsapp_business_account_id' => $waba,
        ]);
    }

    /**
     * Una versión de WhatsApp APROBADA (Ana la escribe, Beto la aprueba) con
     * encabezado, pie y botones; su nombre en Meta decide el escenario del doble.
     *
     * @param  array<string, mixed>  $cambios
     */
    protected function approvedMeta(string $metaName, string $purpose = 'commercial', array $cambios = []): StudioMessageTemplate
    {
        return $this->approvedTemplate($this->ana, $this->beto, 'whatsapp', $purpose, self::metaContent($metaName, $purpose, $cambios), $metaName);
    }

    /** @param  array<string, mixed>  $cambios @return array<string, mixed> */
    protected static function metaContent(string $metaName, string $purpose = 'commercial', array $cambios = []): array
    {
        return array_replace([
            'metaName' => $metaName,
            'language' => 'es_CO',
            'category' => $purpose === 'commercial' ? 'marketing' : 'utility',
            'header' => ['format' => 'TEXT', 'text' => 'Tu plan está por vencer'],
            'bodyPreview' => 'Hola {{1}}, tu plan vence pronto. Si no quieres más mensajes, responde BAJA.',
            'variables' => ['first_name'],
            'examples' => ['first_name' => 'Laura'],
            'footer' => ['text' => 'Escribe BAJA para no recibir más mensajes'],
            'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Quiero renovar'], ['type' => 'QUICK_REPLY', 'text' => 'Darme de baja', 'role' => 'opt_out']],
            'optOutLine' => true,
        ], $cambios);
    }

    /** @param  array<string, mixed>  $body */
    protected function meta(string $method, string $path, Admin $as, array $body = []): TestResponse
    {
        return $this->s9($method, $path, $as, $body);
    }

    protected function submitMeta(StudioMessageTemplate $t, ?Admin $as = null, ?string $peticion = null): TestResponse
    {
        return $this->meta('POST', "strategies/templates/{$t->uuid}/meta/submit", $as ?? $this->ana, ['clientRequestId' => $peticion ?? (string) Str::uuid()]);
    }

    /** @return array<string, mixed> una pasada de la sincronización (como la orden) */
    protected function syncPass(bool $dryRun = false, ?string $template = null): array
    {
        return app(StudioMetaTemplates::class)->sync($template, $dryRun, now());
    }

    protected function accepts(StudioMessageTemplate $t): bool
    {
        return app(StudioTemplateMetaChecks::class)->channelAccepts((int) $t->id);
    }

    protected function submissionOf(StudioMessageTemplate $t): ?StudioMetaTemplateSubmission
    {
        return StudioMetaTemplateSubmission::query()->where('template_id', $t->id)->orderByDesc('id')->first();
    }

    /** @return list<array<string, mixed>> las comprobaciones de una versión, en su orden */
    protected function checksOf(StudioMessageTemplate $t): array
    {
        return DB::table(StudioTemplateMetaChecks::TABLE)->where('template_id', $t->id)->orderBy('id')->get()->map(static fn (object $f): array => (array) $f)->all();
    }

    /** @return list<string> los eventos del libro de una versión, en su orden */
    protected function eventsOf(StudioMessageTemplate $t): array
    {
        return DB::table('ultron_studio_audit_events')->where('subject_uuid', $t->uuid)->orderBy('id')->pluck('event')->all();
    }
}
