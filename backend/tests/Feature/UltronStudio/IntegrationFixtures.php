<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\RolePermission;
use App\Models\UltronStudio\StudioVersion;
use App\Support\Access\RolePermissionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Datos y peticiones de las INTEGRACIONES COMERCIALES del Studio (contrato H6)
 * para las pruebas Feature. Se usa junto a {@see StudioFixtures}.
 *
 * Las personas van con una sesión REAL y con EXACTAMENTE las llaves que pide
 * cada caso: los cuatro ojos comparan identidades, y sin dos personas
 * distintas no se prueba nada.
 */
trait IntegrationFixtures
{
    /** Las llaves de las dos personas tipo del contrato (I7.1). */
    protected const SUPER_KEYS = ['ultron.view', 'ultron.campaigns_manage', 'ultron.canary_manage', 'ultron.canary_authorize', 'ultron.integrations_manage'];

    protected const ADMIN_KEYS = ['ultron.view', 'ultron.campaigns_manage', 'ultron.canary_manage'];

    /** Tres cuentas de Meta de ficción, como las de producción. */
    protected const ACCOUNTS = ['1000000000004714', '1000000000009023', '1000000000005856'];

    /** El token de Meta de las pruebas: reconocible, para la prueba centinela. */
    protected const META_TOKEN = 'EAAh6pruebaMETATOKENcentinela0001';

    /** Enciende (o apaga) las banderas del hito para esta prueba. */
    protected function integrationsFlags(bool $writes = true, bool $probe = false): void
    {
        config([
            'ultron_studio.integrations.writes_enabled' => $writes,
            'ultron_studio.integrations.twilio_probe.enabled' => $probe,
        ]);
    }

    /**
     * Una persona con un rol a medida que tiene EXACTAMENTE estas llaves.
     *
     * @param  list<string>  $permissions
     */
    protected function personWith(array $permissions, string $name = 'Persona de prueba'): Admin
    {
        $rol = 'H6-'.substr(md5(implode(',', $permissions)), 0, 10);
        AdminRole::firstOrCreate(['name' => $rol], ['is_system' => false]);
        foreach ($permissions as $p) {
            RolePermission::updateOrCreate(['role' => $rol, 'permission' => $p], ['granted' => true]);
        }
        app(RolePermissionPolicy::class)->flush();

        return $this->person($rol, $name);
    }

    /** @param  array<string, mixed>  $body */
    protected function integrations(string $method, string $path, Admin $as, array $body = []): TestResponse
    {
        return $this->studio($method, 'integrations/'.ltrim($path, '/'), $as, $body);
    }

    /**
     * Registra un número canario por la API (con las escrituras encendidas).
     *
     * @param  array<string, mixed>  $extra
     */
    protected function registerCanary(Admin $as, string $phone, string $channel = 'whatsapp', string $alias = 'Móvil de pruebas 1', array $extra = []): TestResponse
    {
        return $this->integrations('POST', 'canaries', $as, $extra + [
            'clientRequestId' => (string) Str::uuid(),
            'channel' => $channel,
            'phone' => $phone,
            'alias' => $alias,
            'consentAttested' => true,
        ]);
    }

    /**
     * Un número ya activo: lo registra `$registrant`, lo autoriza y lo activa
     * `$other` (que tiene que ser otra persona). Devuelve su uuid.
     */
    protected function activeCanary(Admin $registrant, Admin $other, string $phone, string $channel = 'whatsapp', string $alias = 'Móvil de pruebas 1'): string
    {
        $uuid = (string) $this->registerCanary($registrant, $phone, $channel, $alias)->assertCreated()->json('data.number.uuid');
        $this->integrations('POST', "canaries/{$uuid}/authorize", $other)->assertOk();
        $this->integrations('POST', "canaries/{$uuid}/activate", $other)->assertOk();

        return $uuid;
    }

    /**
     * La conversación canaria del motor con un lead y su teléfono (o sin él),
     * y la configuración que la nombra. Devuelve el id de la conversación.
     */
    protected function engineCanary(?string $phone): int
    {
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => $phone, 'name' => 'Prospecto canario']);
        $conversacion = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open']);
        config(['marketing.ultron.canary_conversation_id' => $conversacion->id]);

        return (int) $conversacion->id;
    }

    /**
     * Meta Ads configurado con las tres cuentas, la sincronización encendida y
     * pasadas con éxito recientes (no viejas): el espejo «al día».
     */
    protected function metaConfigured(bool $syncEnabled = true): void
    {
        config([
            'meta.ads.access_token' => self::META_TOKEN,
            'meta.ads.ad_account_ids' => self::ACCOUNTS,
            'meta.ads.ad_account_id' => null,
            'meta.ads.graph_version' => 'v26.0',
            'meta.ads.sync_enabled' => $syncEnabled,
            'meta.ads.sync_days' => 7,
            'meta.ads.stale_after_minutes' => 180,
            'meta.ads.timezone' => 'America/Bogota',
        ]);
        $ahora = CarbonImmutable::now();
        foreach (self::ACCOUNTS as $i => $cuenta) {
            DB::table('meta_ad_entities')->insert([
                'ad_account_id' => $cuenta, 'level' => 'account', 'entity_id' => $cuenta, 'name' => 'Cuenta '.($i + 1),
                'campaign_id' => null, 'adset_id' => null, 'effective_status' => null, 'synced_at' => $ahora->subMinutes(20),
            ]);
            $this->metaRun($cuenta, $ahora->subMinutes(15));
        }
    }

    /** Una pasada de insights del motor (por defecto, con éxito y programada). */
    protected function metaRun(string $account, CarbonImmutable $startedAt, string $status = 'ok', string $trigger = 'schedule', string $kind = 'insights'): int
    {
        $hoy = $startedAt->setTimezone('America/Bogota')->toDateString();

        return (int) DB::table('meta_sync_runs')->insertGetId([
            'kind' => $kind, 'status' => $status, 'trigger' => $trigger, 'ad_account_id' => $account,
            'range_from' => $startedAt->setTimezone('America/Bogota')->subDays(6)->toDateString(), 'range_to' => $hoy,
            'rows_upserted' => 1, 'error_code' => null, 'error_message' => null,
            'started_at' => $startedAt->utc(), 'finished_at' => $status === 'running' ? null : $startedAt->addMinute()->utc(),
            'created_at' => $startedAt->utc(), 'updated_at' => $startedAt->utc(),
        ]);
    }

    /** Una campaña en el espejo de una cuenta. */
    protected function metaCampaign(string $account, string $entityId, ?string $name, ?string $status = 'ACTIVE', ?CarbonImmutable $syncedAt = null): void
    {
        DB::table('meta_ad_entities')->insert([
            'ad_account_id' => $account, 'level' => 'campaign', 'entity_id' => $entityId, 'name' => $name,
            'campaign_id' => $entityId, 'adset_id' => null, 'effective_status' => $status,
            'synced_at' => ($syncedAt ?? CarbonImmutable::now()->subMinutes(10))->utc(),
        ]);
    }

    /** El id opaco de una campaña, como lo da la API. */
    protected static function campaignId(string $entityId): string
    {
        return 'campaign:'.substr(hash_hmac('sha256', 'campaign|'.$entityId, (string) config('app.key')), 0, 16);
    }

    /** Una versión en un estado dado, íntegra (o no). */
    protected function versionIn(Admin $creator, string $status, bool $integrity = true): StudioVersion
    {
        return $this->seedVersion($creator, $this->baseline(), $status, $integrity ? null : str_repeat('0', 64));
    }
}
