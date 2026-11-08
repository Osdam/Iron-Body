<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioCampaignLink;
use App\Models\UltronStudio\StudioCanaryNumber;
use App\Models\UltronStudio\StudioIntegrationSnapshot;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioCampaignLinks;
use App\Services\UltronStudio\StudioCanaryCoherence;
use App\Services\UltronStudio\StudioIntegrationConfig;
use App\Services\UltronStudio\StudioIntegrations;
use App\Services\UltronStudio\StudioMetaCampaigns;
use App\Services\UltronStudio\StudioVersionStatus;
use App\Support\Access\CrmPermission;
use App\Support\Privacy\PhoneFingerprint;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\UltronStudio\StudioIntegrationsParityTest;

/**
 * PARIDAD de las secciones CON ESTADO del fixture del contrato
 * (`integrations-cases.json`, contrato H6 §I11) contra las rutas REALES: cada
 * caso describe los hechos que mira una ruta y la primera comprobación que
 * rechaza (o el éxito). El arnés siembra esos hechos en la base, con personas
 * de verdad y exactamente sus llaves, y pide la ruta.
 *
 * Secciones: `canaryTransitions` (rutas 49–54 y el bloque `actions` de la 42),
 * `campaignLinkRules` (47 y 48), `probeRules` (56), `acknowledgeRules` (55),
 * `coherence` (§I4.5), `metaClassification · possiblyDeleted` (lee las
 * pasadas de la base), `routeMatrix` (§I7.1) y `errorOrder` (§I7.4, EO-1), que
 * rompe cada comprobación junto con una posterior para probar el orden.
 */
class UltronStudioIntegrationsParityHttpTest extends TestCase
{
    use IntegrationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const PHONE = '+573000000901';

    private const CAMPAIGN = '120000000000000387';

    private const OTHER_CAMPAIGN = '120000000000000999';

    /** La llave de cada escritura (§I7.1): la exige la puerta y, por defensa, el dominio. */
    private const WRITE_KEYS = [
        47 => 'ultron.campaigns_manage', 48 => 'ultron.campaigns_manage', 49 => 'ultron.canary_manage', 50 => 'ultron.canary_authorize',
        51 => 'ultron.canary_manage', 52 => 'ultron.canary_manage', 53 => 'ultron.canary_manage', 54 => 'ultron.canary_manage',
        55 => 'ultron.integrations_manage', 56 => 'ultron.integrations_manage',
    ];

    /** Rutas sin ninguna entrada (ni consulta ni cuerpo): su paso de forma no se puede provocar. */
    private const NO_INPUT = [39, 43, 45, 46, 50, 51];

    /**
     * La COMPAÑERA de cada paso de `errorOrder`: una comprobación posterior que
     * se puede romper a la vez (casi siempre la siguiente). Si responde el paso,
     * el paso existe con su código y va antes que ella. Sin compañera, el paso
     * solo (la siguiente no se puede romper a la vez: el recurso que no existe
     * no tiene estado, la versión que no existe no tiene integridad…).
     */
    private const COMPANIONS = [
        'reads' => ['session' => 'shape', 'gate' => 'shape', 'person' => 'shape', 'view' => 'shape'],
        47 => ['session' => 'shape', 'gate' => 'shape', 'person' => 'shape', 'view' => 'shape', 'shape' => 'writesFlag', 'permission' => 'writesFlag',
            'writesFlag' => 'replay', 'replay' => 'metaConfigured', 'replayMismatch' => 'metaConfigured', 'metaConfigured' => 'campaignResolves',
            'campaignResolves' => 'versionExists', 'versionExists' => 'noActiveWithoutReplaces', 'versionLinkable' => 'versionIntegrity',
            'versionIntegrity' => 'noActiveWithoutReplaces', 'noActiveWithoutReplaces' => 'activeLimit', 'replacesIsActive' => 'activeLimit',
            'activeLimit' => 'uniqueViolation'],
        48 => ['session' => 'shape', 'gate' => 'shape', 'person' => 'shape', 'view' => 'shape', 'shape' => 'writesFlag', 'permission' => 'writesFlag',
            'writesFlag' => 'linkExists'],
        49 => ['session' => 'shape', 'gate' => 'shape', 'person' => 'shape', 'view' => 'shape', 'shape' => 'writesFlag', 'permission' => 'writesFlag',
            'writesFlag' => 'replay', 'replay' => 'liveLimit', 'replayMismatch' => 'versionExists', 'versionExists' => 'liveLimit',
            'versionLinkable' => 'versionIntegrity', 'versionIntegrity' => 'liveLimit', 'liveLimit' => 'uniqueViolation'],
        50 => ['session' => 'writesFlag', 'gate' => 'writesFlag', 'person' => 'writesFlag', 'view' => 'writesFlag', 'shape' => 'writesFlag',
            'permission' => 'writesFlag', 'writesFlag' => 'numberExists', 'identity' => 'state'],
        51 => ['session' => 'writesFlag', 'gate' => 'writesFlag', 'person' => 'writesFlag', 'view' => 'writesFlag', 'shape' => 'writesFlag',
            'permission' => 'writesFlag', 'writesFlag' => 'numberExists', 'numberExists' => 'activeLimit', 'identity' => 'state', 'state' => 'activeLimit'],
        52 => ['session' => 'shape', 'gate' => 'shape', 'person' => 'shape', 'view' => 'shape', 'shape' => 'writesFlag', 'permission' => 'writesFlag',
            'writesFlag' => 'numberExists'],
        53 => ['session' => 'shape', 'gate' => 'shape', 'person' => 'shape', 'view' => 'shape', 'shape' => 'writesFlag', 'permission' => 'writesFlag',
            'writesFlag' => 'numberExists'],
        54 => ['session' => 'shape', 'gate' => 'shape', 'person' => 'shape', 'view' => 'shape', 'shape' => 'writesFlag', 'permission' => 'writesFlag',
            'writesFlag' => 'numberExists', 'numberExists' => 'versionExists', 'state' => 'versionExists', 'versionLinkable' => 'versionIntegrity'],
        55 => ['session' => 'shape', 'gate' => 'shape', 'person' => 'shape', 'view' => 'shape', 'shape' => 'fingerprintIsEffective',
            'permission' => 'fingerprintIsEffective', 'replay' => 'fingerprintIsEffective', 'replayMismatch' => 'fingerprintIsEffective'],
        56 => ['session' => 'shape', 'gate' => 'shape', 'person' => 'shape', 'view' => 'shape', 'shape' => 'probeFlag', 'permission' => 'probeFlag',
            'probeFlag' => 'replay', 'replay' => 'twilioConfigured', 'replayMismatch' => 'twilioConfigured', 'twilioConfigured' => 'cooldown'],
    ];

    /** @var array<int, Admin> los ids simbólicos del fixture → personas de verdad */
    private array $people = [];

    /** @var array<string, string> los uuids de versión del fixture → los de las versiones sembradas */
    private array $uuids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::preventStrayRequests();
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    private static function section(string $section, ?string $kind = null): iterable
    {
        foreach (StudioIntegrationsParityTest::fixture()[$section] ?? [] as $caso) {
            if ($kind === null || $caso['kind'] === $kind) {
                yield $caso['name'] => [$caso];
            }
        }
    }

    public static function canaryCases(): iterable
    {
        return self::section('canaryTransitions');
    }

    public static function campaignCases(): iterable
    {
        return self::section('campaignLinkRules');
    }

    public static function probeCases(): iterable
    {
        return self::section('probeRules');
    }

    public static function acknowledgeCases(): iterable
    {
        return self::section('acknowledgeRules');
    }

    public static function coherenceCases(): iterable
    {
        return self::section('coherence');
    }

    public static function possiblyDeletedCases(): iterable
    {
        return self::section('metaClassification', 'possiblyDeleted');
    }

    public static function routeCases(): iterable
    {
        return self::section('routeMatrix');
    }

    /** Un caso por paso de cada ruta: `[caso del fixture, índice del paso]`. */
    public static function errorOrderCases(): iterable
    {
        foreach (StudioIntegrationsParityTest::fixture()['errorOrder'] ?? [] as $caso) {
            foreach ($caso['expected']['order'] as $i => $paso) {
                yield $caso['name'].' · '.$i.' · '.$paso['check'] => [$caso, $i];
            }
        }
    }

    // ── canaryTransitions ───────────────────────────────────────────────────

    #[DataProvider('canaryCases')]
    public function test_canary_transitions(array $caso): void
    {
        $f = $caso['facts'];
        $e = $caso['expected'];

        if ($caso['kind'] === 'actions') {
            $this->integrationsFlags(writes: $f['writesEnabled']);
            config(['ultron_studio.integrations.canary.max_active' => $f['maxActive']]);
            $mira = $this->actor($f['viewer']);
            $numero = $this->seedNumber($f['number']['channel'], $f['number']['status'], $this->personFor($f['number']['registeredByAdminId'], $f['viewer']));
            $this->seedActive($f['number']['channel'], $f['activeInChannel'] - ($f['number']['status'] === 'active' ? 1 : 0));
            $fila = collect($this->integrations('GET', 'canaries?status=all&per_page=50', $mira)->assertOk()->json('data.numbers.data'))->firstWhere('uuid', $numero->uuid);
            $this->assertSame($e['actions'], $fila['actions']);

            return;
        }

        $actor = $f['actor'];
        $quien = $actor['person'] ? $this->actor($actor) : null;
        $cuerpo = [];
        $ruta = '';
        if ($caso['kind'] === 'register') {
            [$ruta, $cuerpo] = $this->prepareRegister($f, $quien);
        } else {
            $numero = ($f['number']['exists'] ?? false)
                ? $this->seedNumber($f['number']['channel'], $f['number']['status'], $this->personFor($f['number']['registeredByAdminId'], $actor), $f['number']['registeredByName'] ?? null)
                : null;
            if ($caso['kind'] === 'activate') {
                config(['ultron_studio.integrations.canary.max_active' => $f['maxActive']]);
                $this->seedActive($f['number']['channel'] ?? 'whatsapp', $f['activeInChannel']);
            }
            $ruta = 'canaries/'.($numero?->uuid ?? (string) Str::uuid()).'/'.$caso['kind'];
            $cuerpo = match ($caso['kind']) {
                'deactivate', 'revoke' => ['reason' => $f['reason']],
                'expectation' => ['versionUuid' => $f['versionUuid'] === null ? null : $this->versionFor($f['version'], $f['versionUuid'])],
                default => [],
            };
        }

        $this->integrationsFlags(writes: $f['writesEnabled']);
        $antes = StudioAuditEvent::query()->max('id') ?? 0;
        $r = $quien === null
            ? $this->json('POST', '/api/admin/ultron-studio/integrations/'.$ruta, $cuerpo, $this->sharedTokenHeaders())
            : $this->integrations('POST', $ruta, $quien, $cuerpo);
        $this->assertOutcome($r, $e, 'number');

        $nuevos = StudioAuditEvent::query()->where('id', '>', $antes)->orderBy('id')->get();
        if (array_key_exists('audit', $e) && $e['audit'] === null) {
            $this->assertCount(0, $nuevos, 'sin evento en el libro');
        } elseif (isset($e['audit'])) {
            $this->assertCount(1, $nuevos, json_encode($nuevos->pluck('event')));
            $this->assertSame([$e['audit']['event'], $e['audit']['from'], $e['audit']['to']], [$nuevos[0]->event, $nuevos[0]->from_status, $nuevos[0]->to_status]);
            if (array_key_exists('diff', $e['audit'])) {
                $this->assertSame($this->translate($e['audit']['diff']), $nuevos[0]->diff);
            }
        }
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function prepareRegister(array $f, ?Admin $quien): array
    {
        $id = (string) Str::uuid();
        $esperada = $f['expectedVersionUuid'] === null ? null : $this->versionFor($f['expectedVersion'], $f['expectedVersionUuid']);
        $cuerpo = ['clientRequestId' => $id, 'channel' => $f['channel'], 'phone' => self::PHONE, 'alias' => 'Móvil de la paridad', 'consentAttested' => true]
            + ($esperada === null ? [] : ['expectedVersionUuid' => $esperada]);
        $vivos = 0;

        if ($quien !== null && $f['clientRequest'] !== 'new') {
            // La primera petición con esta llave, con las escrituras encendidas y sin topes.
            $this->integrationsFlags(writes: true);
            $previa = match ($f['clientRequest']) {
                'replay' => [$quien, $cuerpo],
                'reusedOtherBody' => [$quien, ['alias' => 'Otra función'] + $cuerpo],
                default => [$this->personWith(self::ADMIN_KEYS, 'Otra persona'), $cuerpo],
            };
            // Con la versión esperada ya no asociable, la primera se hizo cuando lo era.
            if ($esperada !== null && ! in_array($f['expectedVersion']['status'] ?? null, ['APPROVED', 'CANARY', 'PUBLISHED'], true)) {
                $v = DB::table('ultron_studio_versions')->where('uuid', $esperada)->first(['id', 'status']);
                DB::table('ultron_studio_versions')->where('id', $v->id)->update(['status' => 'APPROVED']);
                $this->integrations('POST', 'canaries', $previa[0], $previa[1])->assertCreated();
                DB::table('ultron_studio_versions')->where('id', $v->id)->update(['status' => $v->status]);
            } else {
                $this->integrations('POST', 'canaries', $previa[0], $previa[1])->assertCreated();
            }
            $vivos = 1;
        }
        foreach ($f['registry']['sameNumber'] as $i => $mismo) {
            $this->seedNumber($mismo['channel'], $mismo['status'], $this->personWith(self::ADMIN_KEYS, 'Otra'), phone: self::PHONE);
            $vivos += $mismo['channel'] === $f['channel'] && $mismo['status'] !== 'revoked' ? 1 : 0;
        }
        $this->seedLive($f['channel'], $f['registry']['liveInChannel'] - $vivos);

        return ['canaries', $cuerpo];
    }

    // ── campaignLinkRules ───────────────────────────────────────────────────

    #[DataProvider('campaignCases')]
    public function test_campaign_link_rules(array $caso): void
    {
        $f = $caso['facts'];
        $e = $caso['expected'];
        $actor = $f['actor'];
        $quien = $actor['person'] ? $this->actor($actor) : null;
        $this->metaConfigured();
        $this->metaCampaign(self::ACCOUNTS[0], self::CAMPAIGN, 'Pauta de la paridad');

        if ($caso['kind'] === 'deactivate') {
            $l = $f['link'];
            $enlace = ($l['exists'] ?? false) ? $this->seedLink(self::CAMPAIGN, $l['status'], $this->personFor($l['createdByAdminId'], $actor), $l['replacedBy'] !== null) : null;
            $this->integrationsFlags(writes: $f['writesEnabled']);
            $antes = StudioAuditEvent::query()->max('id') ?? 0;
            $ruta = 'meta/links/'.($enlace?->uuid ?? (string) Str::uuid()).'/deactivate';
            $r = $quien === null
                ? $this->json('POST', '/api/admin/ultron-studio/integrations/'.$ruta, ['reason' => $f['reason']], $this->sharedTokenHeaders())
                : $this->integrations('POST', $ruta, $quien, ['reason' => $f['reason']]);
            $this->assertOutcome($r, $e, 'link');
            $this->assertAuditList($e, $antes);

            return;
        }

        if (($f['target']['version']['status'] ?? null) === 'VALIDATING') {
            // Irrepresentable en la base: `VALIDATING` no se guarda (el modelo y `usv_status_chk` lo impiden).
            // Se comprueba la regla: no es asociable, y la respuesta sería la de cualquier otro estado no asociable.
            $this->assertFalse(StudioVersionStatus::Validating->reachable());
            $this->assertNotContains('VALIDATING', StudioCampaignLinks::linkableStatuses());
            $this->assertSame(['status' => 409, 'code' => 'version_not_linkable', 'payload' => ['currentStatus' => 'VALIDATING']], $e);

            return;
        }

        // Meta y el tope.
        config(['meta.ads.sync_enabled' => $f['metaSyncEnabled'], 'ultron_studio.integrations.campaign_links.max_active' => $f['maxActive']]);
        $campana = $f['campaignResolvable'] ? self::campaignId(self::CAMPAIGN) : self::campaignId('120000000000000111');
        $destino = $f['target']['kind'] === 'baseline' ? ['kind' => 'baseline'] : ['kind' => 'version', 'versionUuid' => $this->versionFor($f['target']['version'])];
        $cuerpo = ['clientRequestId' => (string) Str::uuid(), 'campaign' => $campana, 'target' => $destino];

        // Las asociaciones que ya hay: L1 (la activa de esta campaña), L0 (una inactiva de esta campaña) y LX (la activa de otra).
        $otra = $this->personWith(self::ADMIN_KEYS, 'Autora de asociaciones');
        $l1 = null;
        $activas = 0;
        if ($quien !== null && $f['clientRequest'] !== 'new') {
            $this->integrationsFlags(writes: true);
            [$primera, $cuerpoPrevio] = match ($f['clientRequest']) {
                'replay' => [$quien, $cuerpo],
                'reusedOtherBody' => [$quien, ['target' => ['kind' => 'version', 'versionUuid' => $this->versionFor(['exists' => true, 'status' => 'APPROVED', 'integrityOk' => true])]] + $cuerpo],
                default => [$otra, $cuerpo],
            };
            if (! $f['campaignResolvable']) {
                $cuerpoPrevio['campaign'] = self::campaignId(self::CAMPAIGN);
            }
            $previa = $this->integrations('POST', 'meta/links', $primera, $cuerpoPrevio)->assertCreated()->json('data.link.uuid');
            if ($f['activeLink'] === 'L1' && $f['clientRequest'] === 'replay') {
                $l1 = StudioCampaignLink::query()->where('uuid', $previa)->first();
                $activas = 1;
            } else {
                StudioCampaignLink::close((int) StudioCampaignLink::query()->where('uuid', $previa)->value('id'), ['id' => $otra->id, 'name' => 'x', 'role' => null], 'Cerrada para la paridad', now());
            }
        }
        if ($f['activeLink'] === 'L1' && $l1 === null) {
            $l1 = $this->seedLink(self::CAMPAIGN, 'active', $otra);
            $activas = 1;
        }
        $l0 = $this->seedLink(self::CAMPAIGN, 'inactive', $otra);
        $lx = $f['replaces'] === 'LX' ? $this->seedLink(self::OTHER_CAMPAIGN, 'active', $otra) : null;
        $activas += $lx !== null ? 1 : 0;
        for ($i = $activas; $i < $f['activeTotal']; $i++) {
            $this->seedLink('12000000000000'.str_pad((string) (5000 + $i), 4, '0', STR_PAD_LEFT), 'active', $otra);
        }
        $reemplaza = match ($f['replaces']) {
            null => null,
            'L1' => $l1?->uuid,
            'L0' => $l0->uuid,
            'LX' => $lx?->uuid,
            default => (string) Str::uuid(),
        };
        if ($reemplaza !== null) {
            $cuerpo['replaces'] = $reemplaza;
        }

        // El problema de configuración de Meta.
        match ($f['metaProblem']) {
            null => null,
            'missing_access_token' => config(['meta.ads.access_token' => null]),
            'missing_ad_account_id' => config(['meta.ads.ad_account_ids' => [], 'meta.ads.ad_account_id' => null]),
            'invalid_ad_account_id' => config(['meta.ads.ad_account_ids' => ['act_no_es_un_id'], 'meta.ads.ad_account_id' => null]),
            'invalid_timezone' => config(['meta.ads.timezone' => 'Marte/Fobos']),
        };
        if (! $f['campaignResolvable'] && $f['activeLink'] === 'L1' && ($f['activeTotal'] ?? 0) === 1) {
            // «De una cuenta que dejó de estar conectada»: la campaña está en el espejo, pero de otra cuenta.
            DB::table('meta_ad_entities')->where('entity_id', self::CAMPAIGN)->update(['ad_account_id' => '9999999999990001']);
            $cuerpo['campaign'] = self::campaignId(self::CAMPAIGN);
        }

        $this->integrationsFlags(writes: $f['writesEnabled']);
        $antes = StudioAuditEvent::query()->max('id') ?? 0;
        $r = $quien === null
            ? $this->json('POST', '/api/admin/ultron-studio/integrations/meta/links', $cuerpo, $this->sharedTokenHeaders())
            : $this->integrations('POST', 'meta/links', $quien, $cuerpo);
        $this->assertOutcome($r, $e, 'link', ['L1' => $l1?->uuid]);

        if (array_key_exists('replaced', $e) && $r->status() === 201) {
            $this->assertSame($e['replaced'] === null ? null : $l1?->uuid, $r->json('data.replaced.uuid'));
            if (isset($e['replacedRow'])) {
                $vieja = StudioCampaignLink::query()->where('uuid', $l1->uuid)->firstOrFail();
                $this->assertSame([$e['replacedRow']['status'], $e['replacedRow']['deactivationReason']], [$vieja->status, $vieja->deactivation_reason]);
                $this->assertSame($r->json('data.link.uuid'), StudioCampaignLink::query()->whereKey($vieja->replaced_by_link_id)->value('uuid'));
            }
        }
        $this->assertAuditList($e, $antes);
    }

    // ── probeRules ──────────────────────────────────────────────────────────

    #[DataProvider('probeCases')]
    public function test_probe_rules(array $caso): void
    {
        // El enfriamiento se mide en segundos exactos: con el reloj congelado, 299 s no
        // se convierten en 300 aunque la máquina vaya lenta (pasó en la puerta PG).
        $this->freezeSecond();
        $f = $caso['facts'];
        $e = $caso['expected'];
        $actor = $f['actor'];
        $quien = $actor['person'] ? $this->actor($actor) : null;
        $id = (string) Str::uuid();
        Http::fake(fn (HttpRequest $r) => Http::response(['sid' => 'AC0123456789abcdef0123456789abcdef', 'status' => 'active', 'type' => 'Full'], 200));

        if ($quien !== null && $f['clientRequest'] !== 'new') {
            $de = $f['clientRequest'] === 'replay' ? $quien : $this->personWith(self::SUPER_KEYS, 'Otra persona');
            $this->seedSnapshot('probe', 'twilio', $de, $id, CarbonImmutable::now()->subHour());
        }
        if ($f['lastProbeAgeSeconds'] !== null) {
            $this->seedSnapshot('probe', 'twilio', $this->personWith(self::SUPER_KEYS, 'Quien sondeó antes'), null, CarbonImmutable::now()->subSeconds($f['lastProbeAgeSeconds']));
        }
        config([
            'ultron_studio.integrations.writes_enabled' => $f['writesEnabled'],
            'ultron_studio.integrations.twilio_probe.enabled' => $f['probeEnabled'],
            'ultron_studio.integrations.twilio_probe.cooldown_seconds' => $f['cooldownSeconds'],
            'ultron_studio.integrations.twilio_probe.key_sid' => $f['probeKey']['sid'],
            'ultron_studio.integrations.twilio_probe.key_secret' => $f['probeKey']['secret'],
            'otp.twilio.sid' => $f['otp']['sid'],
            'otp.twilio.token' => $f['otp']['token'],
        ]);
        $antes = StudioAuditEvent::query()->max('id') ?? 0;
        $r = $quien === null
            ? $this->json('POST', '/api/admin/ultron-studio/integrations/twilio/probe', ['clientRequestId' => $id], $this->sharedTokenHeaders())
            : $this->integrations('POST', 'twilio/probe', $quien, ['clientRequestId' => $id]);

        $this->assertOutcome($r, $e, 'snapshot');
        if (isset($e['credential'])) {
            $this->assertSame($e['credential'], $r->json('data.snapshot.facts.credential'));
        }
        if (array_key_exists('network', $e)) {
            $e['network'] ? $this->assertNotEmpty(Http::recorded()) : $this->assertCount(0, Http::recorded(), 'sin red');
        }
        $nuevos = StudioAuditEvent::query()->where('id', '>', $antes)->get();
        if (array_key_exists('audit', $e)) {
            $e['audit'] === null ? $this->assertCount(0, $nuevos) : $this->assertSame([$e['audit']['event']], $nuevos->pluck('event')->all());
        }
    }

    // ── acknowledgeRules ────────────────────────────────────────────────────

    #[DataProvider('acknowledgeCases')]
    public function test_acknowledge_rules(array $caso): void
    {
        $f = $caso['facts'];
        $e = $caso['expected'];
        $actor = $f['actor'];
        $quien = $actor['person'] ? $this->actor($actor) : null;
        $id = (string) Str::uuid();
        $efectiva = StudioIntegrationConfig::fingerprintOf(StudioIntegrationConfig::facts($f['provider']));
        // Las huellas del fixture son simbólicas: «la enviada es la efectiva» o no.
        $enviada = $f['sentFingerprint'] === $f['effectiveFingerprint'] ? $efectiva : str_repeat('c', 64);

        if ($quien !== null && $f['clientRequest'] !== 'new') {
            $this->integrations('POST', 'config/acknowledge', $quien, ['clientRequestId' => $id, 'provider' => $f['provider'], 'fingerprint' => $efectiva]
                + ($f['clientRequest'] === 'reusedOtherBody' ? ['note' => 'Otra nota'] : []))->assertCreated();
            $enviada = $efectiva;
            if ($f['sentFingerprint'] !== $f['effectiveFingerprint']) {
                // La configuración cambió después de la primera vez.
                config(['otp.cost.daily_soft' => 9.5]);
            }
        }
        $this->integrationsFlags(writes: $f['writesEnabled']);
        $antes = StudioAuditEvent::query()->max('id') ?? 0;
        $cuerpo = ['clientRequestId' => $id, 'provider' => $f['provider'], 'fingerprint' => $enviada];
        $r = $quien === null
            ? $this->json('POST', '/api/admin/ultron-studio/integrations/config/acknowledge', $cuerpo, $this->sharedTokenHeaders())
            : $this->integrations('POST', 'config/acknowledge', $quien, $cuerpo);

        $this->assertOutcome($r, $e, 'snapshot');
        if (isset($e['payloadIncludes'])) {
            $this->assertSame($e['payloadIncludes']['provider'], $r->json('provider'));
            $this->assertSame(StudioIntegrationConfig::fingerprintOf(StudioIntegrationConfig::facts($f['provider'])), $r->json('effectiveFingerprint'));
            $this->assertNotSame($enviada, $r->json('effectiveFingerprint'));
        }
        if (isset($e['snapshot'])) {
            $fila = StudioIntegrationSnapshot::query()->where('uuid', $r->json('data.snapshot.uuid'))->firstOrFail();
            $this->assertSame([$e['snapshot']['provider'], $e['snapshot']['kind'], $e['snapshot']['actorType'], $enviada], [$fila->provider, $fila->kind, $fila->actor_type, $fila->fingerprint]);
        }
        if (isset($e['audit'])) {
            $this->assertSame([$e['audit']['event']], StudioAuditEvent::query()->where('id', '>', $antes)->pluck('event')->all());
        }
    }

    // ── coherence ───────────────────────────────────────────────────────────

    #[DataProvider('coherenceCases')]
    public function test_coherence(array $caso): void
    {
        $e = $caso['expected'];
        $motor = $caso['engine'];
        $quien = $this->personWith(self::ADMIN_KEYS, 'Quien registra');
        $refs = [];
        foreach ($caso['rows'] as $fila) {
            $refs[$fila['ref']] = $this->seedNumber($fila['channel'], $fila['status'], $quien, phone: $fila['phone'], appKey: $fila['appKey']);
        }
        config(['app.key' => $caso['appKey']]);

        if ($motor['canaryConversationId'] === null) {
            config(['marketing.ultron.canary_conversation_id' => null]);
        } elseif ($motor['conversation'] === null) {
            config(['marketing.ultron.canary_conversation_id' => 987654]);
        } else {
            // `lead_id` es NOT NULL con FK en el esquema: «sin lead» y «el lead no existe» llegan igual (un lead que ya no está).
            $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => $motor['lead']['phone'] ?? null, 'name' => 'Lead canario']);
            $conversacion = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open']);
            if ($motor['lead'] === null) {
                // Borrar el lead se lleva su conversación (FK en cascada): llega como «no existe», con el mismo resultado.
                DB::table('marketing_leads')->where('id', $lead->id)->delete();
            }
            config(['marketing.ultron.canary_conversation_id' => $conversacion->id]);
        }
        $fallar = $caso['failure'] === 'read_failed';
        DB::connection()->beforeExecuting(function () use (&$fallar): void {
            if ($fallar) {
                throw new RuntimeException('la base no responde');
            }
        });

        $c = app(StudioCanaryCoherence::class)->read();
        $fallar = false;

        $this->assertSame([$e['verdict'], $e['engineCanaryConfigured'], $e['engineHash']], [$c['verdict'], $c['engineCanaryConfigured'], $c['engineHash']]);
        if (! ($caso['failure'] !== null && $e['activeWhatsapp'] === null)) {
            $this->assertSame($e['activeWhatsapp'], $c['activeWhatsapp']);
        }
        foreach ($e['rows'] as $fila) {
            $n = $refs[$fila['ref']];
            $this->assertSame($fila['isEngineCanary'], StudioCanaryCoherence::isEngineCanary($c, (string) $n->channel, (string) $n->status, (string) $n->phone_hash), $fila['ref']);
        }
    }

    // ── metaClassification · possiblyDeleted ────────────────────────────────

    #[DataProvider('possiblyDeletedCases')]
    public function test_possibly_deleted(array $caso): void
    {
        $f = $caso['facts'];
        $porId = [];
        foreach ($f['runs'] as $run) {
            $en = CarbonImmutable::parse($run['startedAt'])->utc();
            DB::table('meta_sync_runs')->insert(['id' => $run['id'], 'kind' => $run['kind'], 'status' => $run['status'], 'trigger' => $run['trigger'], 'ad_account_id' => $run['account'],
                'rows_upserted' => 0, 'started_at' => $en, 'finished_at' => $en, 'created_at' => $en, 'updated_at' => $en]);
            $porId[$run['id']] = $en;
        }
        $referencia = StudioMetaCampaigns::referenceRuns([$f['account']])[$f['account']] ?? null;
        $sincronizada = $f['syncedAt'] === null ? null : CarbonImmutable::parse($f['syncedAt']);

        $this->assertSame($caso['expected']['possiblyDeleted'], StudioMetaCampaigns::possiblyDeleted($sincronizada, $referencia));
        $ref = $caso['expected']['referenceRunId'];
        $this->assertSame($ref === null ? null : $porId[$ref]->toIso8601String(), $referencia?->toIso8601String());
    }

    // ── routeMatrix ─────────────────────────────────────────────────────────

    #[DataProvider('routeCases')]
    public function test_route_matrix(array $caso): void
    {
        if ($caso['kind'] === 'legacy') {
            // Hito 8: las 21 de la voz tienen su propia matriz (UltronStudioVoiceRoutesTest).
            $todas = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/admin/ultron-studio')
                && ! str_starts_with($r->uri(), 'api/admin/ultron-studio/voice/') && ! str_starts_with($r->uri(), 'api/admin/ultron-studio/voice-lab/'));
            $this->assertCount($caso['expected']['studioRoutesTotal'], $todas);
            $this->assertCount($caso['count'], $todas->reject(fn ($r) => str_starts_with($r->uri(), 'api/admin/ultron-studio/integrations/')));

            return;
        }
        $this->integrationsFlags(writes: true);
        $uuid = (string) $this->registerCanary($this->person(Admin::ROLE_SUPER_ADMIN, 'Autora'), self::PHONE)->assertCreated()->json('data.number.uuid');
        $this->integrationsFlags(writes: false);
        $ruta = str_replace('{uuid}', $caso['kind'] === 'noView' ? (string) Str::uuid() : $uuid, $caso['path']);
        $cuerpo = self::routeBody($caso['route']);

        if ($caso['kind'] === 'noView') {
            $r = $this->integrations($caso['method'], substr($ruta, strlen('integrations/')), $this->personWith($caso['permissions'], 'Sin ver'), $cuerpo);
            $r->assertStatus($caso['expected']['status'])->assertJsonPath('code', $caso['expected']['code'])->assertJsonPath('required_permission', $caso['expected']['payload']['required_permission']);

            return;
        }

        $url = '/api/admin/ultron-studio/'.$ruta;
        foreach ($caso['expected']['roles'] as $rol => $esperado) {
            $r = match ($rol) {
                'sinSesion' => $this->json($caso['method'], $url, $cuerpo),
                'tokenCompartido' => $this->json($caso['method'], $url, $cuerpo, $this->sharedTokenHeaders()),
                default => $this->json($caso['method'], $url, $cuerpo, $this->as($this->person($rol === 'Entrenador' ? CrmPermission::ROLE_ENTRENADOR : $rol, "Persona {$rol}"))),
            };
            if ($esperado === 'allowed') {
                $this->assertNotContains($r->status(), [401, 500], "{$rol}: ".$r->getContent());
                $this->assertFalse($r->status() === 403 && in_array($r->json('code'), ['forbidden', 'person_required'], true), "{$rol}: ".$r->getContent());
            } else {
                $this->assertSame($esperado, $r->status(), "{$rol}: ".$r->getContent());
            }
        }
    }

    // ── errorOrder ──────────────────────────────────────────────────────────

    /**
     * EO-1: el orden de cada una de las 18 rutas, comprobación a comprobación
     * (§I7.4). Cada caso rompe el paso y su compañera ({@see COMPANIONS}); tiene
     * que responder el paso. `done` es la petición buena.
     *
     * Dos pasos los contesta la PUERTA (`AuthorizationMap`), no el dominio:
     *  - `person`: con el token compartido, la puerta responde antes (CN-4);
     *  - `permission`: la llave de cada escritura está en la puerta (§I7.1 y el
     *    paso 1 del orden de §I3.4), con el mismo 403 `forbidden
     *    {required_permission}`; el `require()` del dominio es la defensa en
     *    profundidad y por HTTP no se alcanza. Por eso su compañera es la
     *    bandera y no la forma (ver informe-api.md).
     * En las rutas sin entradas ({@see NO_INPUT}) la forma no se puede romper:
     * una consulta o un campo ajenos se ignoran y responde la compañera.
     */
    #[DataProvider('errorOrderCases')]
    public function test_error_order(array $caso, int $i): void
    {
        $this->freezeSecond();
        $ruta = $caso['route'];
        $orden = $caso['expected']['order'];
        $paso = $orden[$i];
        $companera = (self::COMPANIONS[$ruta] ?? self::COMPANIONS['reads'])[$paso['check']] ?? null;
        $romper = array_values(array_filter([$paso['check'] === 'done' ? null : $paso['check'], $companera]));

        $r = $this->errorOrderRequest($caso, $romper);

        if ($paso['check'] === 'shape' && in_array($ruta, self::NO_INPUT, true)) {
            $paso = $companera === null ? $orden[array_key_last($orden)] : collect($orden)->firstWhere('check', $companera);
        }
        $this->assertErrorStep($r, $paso, $ruta);
    }

    /** @param  list<string>  $romper */
    private function errorOrderRequest(array $caso, array $romper): TestResponse
    {
        $ruta = $caso['route'];
        $rompe = static fn (string $check): bool => in_array($check, $romper, true);
        [$metodo, $plantilla] = explode(' ', substr($caso['name'], strrpos($caso['name'], ' · ') + strlen(' · ')), 2);
        $yo = $this->personWith(in_array($ruta, [50, 55, 56], true) ? self::SUPER_KEYS : self::ADMIN_KEYS, 'Quien actúa');
        $id = (string) Str::uuid();
        $this->integrationsFlags(writes: true, probe: true);

        $plan = match (true) {
            $ruta <= 46 => $this->readPlan($ruta, substr($plantilla, strlen('integrations/')), $rompe),
            $ruta === 47 => $this->linkCreatePlan($id, $rompe),
            $ruta === 48 => $this->linkDeactivatePlan($rompe),
            $ruta === 49 => $this->canaryRegisterPlan($id, $rompe),
            $ruta <= 54 => $this->canaryTransitionPlan($ruta, $yo, $rompe),
            $ruta === 55 => $this->acknowledgePlan($id, $rompe),
            default => $this->probePlan($id, $yo, $rompe),
        };
        if (($plan['prior'] ?? null) !== null) {
            // La primera petición con esta clientRequestId, con las escrituras encendidas.
            $this->integrations($metodo, $plan['path'], $yo, $plan['prior'])->assertSuccessful();
        }
        ($plan['after'] ?? static fn (): null => null)();

        // 55 y 56 no dependen de la bandera de escrituras (AK-1, PR-1): van siempre con ella apagada.
        $this->integrationsFlags(writes: ! $rompe('writesFlag') && $ruta < 55, probe: ! $rompe('probeFlag'));
        $url = '/api/admin/ultron-studio/integrations/'.$plan['path'];

        return match (true) {
            $rompe('session') => $this->json($metodo, $url, $plan['body']),
            $rompe('gate') => $this->json($metodo, $url, $plan['body'], $this->as($this->person(CrmPermission::ROLE_ENTRENADOR, 'Entrenador'))),
            $rompe('person') => $this->json($metodo, $url, $plan['body'], $this->sharedTokenHeaders()),
            $rompe('view') => $this->integrations($metodo, $plan['path'], $this->personWith($ruta <= 46 ? [] : [self::WRITE_KEYS[$ruta]], 'Sin ver'), $plan['body']),
            $rompe('permission') => $this->integrations($metodo, $plan['path'], $this->personWith([StudioAccess::VIEW], 'Sin la llave'), $plan['body']),
            default => $this->integrations($metodo, $plan['path'], $yo, $plan['body']),
        };
    }

    /** 39–46: la forma rota es un filtro fuera de su lista (o, sin entradas, una consulta ajena). */
    private function readPlan(int $ruta, string $path, Closure $rompe): array
    {
        if ($ruta === 40) {
            $this->metaConfigured();
        }
        $consulta = match ($ruta) {
            40, 41, 42 => 'status=zzz',
            44 => 'per_page=zzz',
            default => 'zzz=1',
        };

        return ['path' => $path.($rompe('shape') ? '?'.$consulta : ''), 'body' => []];
    }

    /** 47: asociar una campaña a una versión aprobada. */
    private function linkCreatePlan(string $id, Closure $rompe): array
    {
        $this->metaConfigured();
        $this->metaCampaign(self::ACCOUNTS[0], self::CAMPAIGN, 'Pauta del orden');
        $otra = $this->personWith(self::ADMIN_KEYS, 'Otra persona');
        $version = $this->versionIn($otra, $rompe('versionLinkable') ? 'DRAFT' : 'APPROVED', ! $rompe('versionIntegrity'));
        $cuerpo = ['clientRequestId' => $id, 'campaign' => self::campaignId(self::CAMPAIGN), 'target' => ['kind' => 'version', 'versionUuid' => (string) $version->uuid]];
        $previa = match (true) {
            $rompe('replay') => $cuerpo,
            $rompe('replayMismatch') => ['target' => ['kind' => 'baseline']] + $cuerpo,
            default => null,
        };

        if ($rompe('shape')) {
            $cuerpo['target']['kind'] = 'zzz';
        }
        if ($rompe('campaignResolves')) {
            $cuerpo['campaign'] = self::campaignId('120000000000000111');
        }
        if ($rompe('versionExists')) {
            $cuerpo['target']['versionUuid'] = (string) Str::uuid();
        }
        if ($rompe('noActiveWithoutReplaces') || $rompe('replacesIsActive')) {
            $this->seedLink(self::CAMPAIGN, 'active', $otra);
        }
        if ($rompe('replacesIsActive')) {
            $cuerpo['replaces'] = (string) $this->seedLink(self::CAMPAIGN, 'inactive', $otra)->uuid;
        }
        if ($rompe('activeLimit')) {
            config(['ultron_studio.integrations.campaign_links.max_active' => 1]);
            $this->seedLink(self::OTHER_CAMPAIGN, 'active', $otra);
        }

        return ['path' => 'meta/links', 'body' => $cuerpo, 'prior' => $previa, 'after' => function () use ($rompe, $otra): void {
            if ($rompe('metaConfigured')) {
                config(['meta.ads.access_token' => null]);
            }
            if ($rompe('uniqueViolation')) {
                // La carrera: otra asociación de la misma campaña se confirma JUSTO
                // después de la comprobación previa y antes del INSERT (fuera de su
                // transacción, como la de otra petición): el 23505 de `uscl_one_active`.
                $armada = true;
                DB::listen(function (QueryExecuted $q) use (&$armada, $otra): void {
                    if ($armada && str_starts_with($q->sql, 'select') && str_contains($q->sql, 'ultron_studio_campaign_links') && str_contains($q->sql, '"campaign_id" = ?')) {
                        $armada = false;
                        $this->seedLink(self::CAMPAIGN, 'active', $otra);
                    }
                });
            }
        }];
    }

    /** 48: desactivar una asociación activa. */
    private function linkDeactivatePlan(Closure $rompe): array
    {
        $enlace = $this->seedLink(self::CAMPAIGN, $rompe('state') ? 'inactive' : 'active', $this->personWith(self::ADMIN_KEYS, 'Autora'));

        return [
            'path' => 'meta/links/'.($rompe('linkExists') ? (string) Str::uuid() : $enlace->uuid).'/deactivate',
            'body' => ['reason' => $rompe('shape') ? 'abc' : 'Motivo del orden'],
        ];
    }

    /** 49: registrar un WhatsApp con versión esperada. */
    private function canaryRegisterPlan(string $id, Closure $rompe): array
    {
        $otra = $this->personWith(self::ADMIN_KEYS, 'Otra persona');
        $version = $this->versionIn($otra, $rompe('versionLinkable') ? 'DRAFT' : 'APPROVED', ! $rompe('versionIntegrity'));
        $cuerpo = ['clientRequestId' => $id, 'channel' => 'whatsapp', 'phone' => self::PHONE, 'alias' => 'Móvil del orden', 'consentAttested' => true,
            'expectedVersionUuid' => (string) $version->uuid];
        $previa = match (true) {
            $rompe('replay') => $cuerpo,
            $rompe('replayMismatch') => ['alias' => 'Otra función'] + $cuerpo,
            default => null,
        };

        if ($rompe('shape')) {
            $cuerpo['consentAttested'] = false;
        }
        if ($rompe('versionExists')) {
            $cuerpo['expectedVersionUuid'] = (string) Str::uuid();
        }
        if ($rompe('uniqueViolation')) {
            // El mismo teléfono ya vivo en el canal: sin comprobación previa, lo para el INSERT (`uscn_one_live`).
            $this->seedNumber('whatsapp', 'pending', $otra, phone: self::PHONE);
        }

        return ['path' => 'canaries', 'body' => $cuerpo, 'prior' => $previa, 'after' => function () use ($rompe): void {
            if ($rompe('liveLimit')) {
                $vivos = StudioCanaryNumber::query()->where('channel', 'whatsapp')->whereIn('status', StudioCanaryNumber::LIVE)->count();
                $this->seedLive('whatsapp', StudioIntegrations::canaryMaxLive() - $vivos);
            }
        }];
    }

    /** 50–54: un número en el estado de partida de cada acción; quien registró es otra persona salvo en `identity`. */
    private function canaryTransitionPlan(int $ruta, Admin $yo, Closure $rompe): array
    {
        $accion = [50 => 'authorize', 51 => 'activate', 52 => 'deactivate', 53 => 'revoke', 54 => 'expectation'][$ruta];
        [$bueno, $malo] = ['authorize' => ['pending', 'authorized'], 'activate' => ['authorized', 'pending'], 'deactivate' => ['active', 'pending'],
            'revoke' => ['active', 'revoked'], 'expectation' => ['pending', 'revoked']][$accion];
        $registrante = $rompe('identity') ? $yo : $this->personWith(self::SUPER_KEYS, 'Quien registró');
        $numero = $this->seedNumber('whatsapp', $rompe('state') ? $malo : $bueno, $registrante);
        if ($rompe('activeLimit')) {
            config(['ultron_studio.integrations.canary.max_active' => 1]);
            $this->seedActive('whatsapp', 1);
        }

        $cuerpo = match ($accion) {
            'authorize', 'activate' => $rompe('shape') ? ['zzz' => 1] : [],
            'deactivate', 'revoke' => ['reason' => $rompe('shape') ? 'abc' : 'Motivo del orden'],
            'expectation' => ['versionUuid' => match (true) {
                $rompe('shape') => 'zzz',
                $rompe('versionExists') => (string) Str::uuid(),
                default => (string) $this->versionIn($registrante, $rompe('versionLinkable') ? 'DRAFT' : 'APPROVED', ! $rompe('versionIntegrity'))->uuid,
            }],
        };

        return ['path' => 'canaries/'.($rompe('numberExists') ? (string) Str::uuid() : $numero->uuid).'/'.$accion, 'body' => $cuerpo];
    }

    /** 55: reconocer la configuración de Twilio con su huella efectiva. */
    private function acknowledgePlan(string $id, Closure $rompe): array
    {
        $cuerpo = ['clientRequestId' => $id, 'provider' => 'twilio', 'fingerprint' => StudioIntegrationConfig::fingerprintOf(StudioIntegrationConfig::facts('twilio'))];
        $previa = match (true) {
            $rompe('replay') => $cuerpo,
            $rompe('replayMismatch') => $cuerpo + ['note' => 'Otra nota'],
            default => null,
        };
        if ($rompe('shape')) {
            $cuerpo['provider'] = 'zzz';
        }
        if ($rompe('fingerprintIsEffective') && $previa === null) {
            $cuerpo['fingerprint'] = str_repeat('c', 64);
        }

        return ['path' => 'config/acknowledge', 'body' => $cuerpo, 'prior' => $previa, 'after' => static function () use ($rompe, $previa): void {
            if ($rompe('fingerprintIsEffective') && $previa !== null) {
                // La configuración cambió después de la primera vez.
                config(['otp.cost.daily_soft' => 9.5]);
            }
        }];
    }

    /** 56: la sonda con el token del OTP y sin sonda reciente. */
    private function probePlan(string $id, Admin $yo, Closure $rompe): array
    {
        Http::fake(fn (HttpRequest $r) => Http::response(['sid' => 'AC0123456789abcdef0123456789abcdef', 'status' => 'active', 'type' => 'Full'], 200));
        config([
            'ultron_studio.integrations.twilio_probe.cooldown_seconds' => 300,
            'ultron_studio.integrations.twilio_probe.key_sid' => null,
            'ultron_studio.integrations.twilio_probe.key_secret' => null,
            'otp.twilio.sid' => $rompe('twilioConfigured') ? null : 'AC0123456789abcdef0123456789abcdef',
            'otp.twilio.token' => 'h6fixturetwiliotoken000000000001',
        ]);
        if ($rompe('replay') || $rompe('replayMismatch')) {
            $de = $rompe('replay') ? $yo : $this->personWith(self::SUPER_KEYS, 'Otra persona');
            $this->seedSnapshot('probe', 'twilio', $de, $id, CarbonImmutable::now()->subHour());
        }
        if ($rompe('cooldown')) {
            $this->seedSnapshot('probe', 'twilio', $this->personWith(self::SUPER_KEYS, 'Quien sondeó antes'), null, CarbonImmutable::now()->subSeconds(10));
        }

        return ['path' => 'twilio/probe', 'body' => ['clientRequestId' => $rompe('shape') ? 'zzz' : $id]];
    }

    /**
     * El paso esperado: su estado y su código. La sesión (401) y la puerta (403)
     * llevan el código de su middleware, no uno del dominio (`code: null` en el
     * fixture), y el 422 es el estándar de Laravel.
     */
    private function assertErrorStep(TestResponse $r, array $paso, int $ruta): void
    {
        $donde = $paso['check'].': '.$r->getContent();
        $this->assertSame($paso['status'], $r->status(), $donde);
        $codigo = match (true) {
            $r->status() >= 400 => $r->json('code'),
            $r->json('data.replayed') === true => 'replayed',
            $r->json('data.reused') === true => 'reused',
            default => null,
        };
        match ($paso['check']) {
            'session', 'gate' => null,
            'shape' => $this->assertNotEmpty($r->json('errors'), $donde),
            'person' => $this->assertContains($codigo, ['person_required', 'forbidden'], $donde),
            default => $this->assertSame($paso['code'], $codigo, $donde),
        };
        match ($paso['check']) {
            'view' => $this->assertSame(StudioAccess::VIEW, $r->json('required_permission'), $donde),
            'permission' => $this->assertSame(self::WRITE_KEYS[$ruta], $r->json('required_permission'), $donde),
            'identity' => $this->assertSame($ruta === 50 ? 'authorize' : 'activate', $r->json('action'), $donde),
            default => null,
        };
    }

    // ── Piezas del arnés ────────────────────────────────────────────────────

    /** Una persona del fixture: con exactamente sus llaves, y la misma si el id simbólico se repite. */
    private function actor(array $actor): Admin
    {
        return $this->people[$actor['adminId']] ??= $this->personWith($actor['permissions'], (string) $actor['name']);
    }

    /** Quien registró o creó: la persona que actúa si el id coincide; si no, otra. */
    private function personFor(int $symbolicId, array $actor): Admin
    {
        if ($actor['person'] && $symbolicId === $actor['adminId']) {
            return $this->actor($actor);
        }

        return $this->people[$symbolicId] ??= $this->personWith(self::ADMIN_KEYS, 'Persona '.$symbolicId);
    }

    /** Un número sembrado directamente en la base, en el estado pedido y con lo que exige cada estado. */
    private function seedNumber(string $channel, string $status, Admin $registrant, ?string $name = null, ?string $phone = null, ?string $appKey = null): StudioCanaryNumber
    {
        $telefono = $phone ?? sprintf('+5730000%05d', random_int(10000, 99999));
        $clave = $appKey ?? (string) config('app.key');
        $cruda = str_starts_with($clave, 'base64:') ? (string) base64_decode(substr($clave, 7)) : $clave;
        $otra = 999999;
        $ahora = now();

        return StudioCanaryNumber::query()->forceCreate([
            'client_request_id' => (string) Str::uuid(),
            'channel' => $channel,
            'alias' => 'Sembrado',
            'phone_hash' => PhoneFingerprint::withKey($telefono, $cruda),
            'phone_last4' => substr($telefono, -4),
            'country_code' => '57',
            'phone_encrypted' => $channel === 'voice' && $status !== 'revoked' ? $telefono : null,
            'status' => $status,
            'consent_attested' => true,
            'registered_by_admin_id' => $registrant->id,
            'registered_by_name' => $name ?? $registrant->name,
            'registered_by_role' => $registrant->role,
            'authorized_by_admin_id' => in_array($status, ['authorized', 'active'], true) ? $otra : null,
            'authorized_by_name' => in_array($status, ['authorized', 'active'], true) ? 'Otra' : null,
            'authorized_at' => in_array($status, ['authorized', 'active'], true) ? $ahora : null,
            'activated_by_admin_id' => $status === 'active' ? $otra : null,
            'activated_by_name' => $status === 'active' ? 'Otra' : null,
            'activated_at' => $status === 'active' ? $ahora : null,
            'revoked_by_admin_id' => $status === 'revoked' ? $otra : null,
            'revoked_by_name' => $status === 'revoked' ? 'Otra' : null,
            'revoked_at' => $status === 'revoked' ? $ahora : null,
            'revoke_reason' => $status === 'revoked' ? 'Revocado para la paridad' : null,
            'status_changed_at' => $ahora,
        ]);
    }

    private function seedLive(string $channel, int $count): void
    {
        $quien = $this->personWith(self::ADMIN_KEYS, 'Relleno');
        for ($i = 0; $i < $count; $i++) {
            $this->seedNumber($channel, 'pending', $quien);
        }
    }

    private function seedActive(string $channel, int $count): void
    {
        $quien = $this->personWith(self::ADMIN_KEYS, 'Relleno');
        for ($i = 0; $i < $count; $i++) {
            $this->seedNumber($channel, 'active', $quien);
        }
    }

    /** Una asociación sembrada: activa, o inactiva (sustituida por otra si se pide). */
    private function seedLink(string $campaign, string $status, Admin $creator, bool $replaced = false): StudioCampaignLink
    {
        $sustituta = $replaced ? $this->seedLink($campaign, 'active', $creator) : null;
        $cerrada = $status === 'inactive';

        return StudioCampaignLink::query()->forceCreate([
            'client_request_id' => (string) Str::uuid(),
            'ad_account_id' => self::ACCOUNTS[0],
            'campaign_id' => $campaign,
            'campaign_name_at_link' => 'Sembrada',
            'target_kind' => 'baseline',
            'status' => $status,
            'created_by_admin_id' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_role' => $creator->role,
            'created_at' => now()->subDay(),
            'deactivated_at' => $cerrada ? now()->subHour() : null,
            'deactivated_by_admin_id' => $cerrada ? $creator->id : null,
            'deactivated_by_name' => $cerrada ? $creator->name : null,
            'deactivation_reason' => $cerrada ? 'Cerrada para la paridad' : null,
            'replaced_by_link_id' => $sustituta?->id,
        ]);
    }

    private function seedSnapshot(string $kind, string $provider, Admin $actor, ?string $clientRequestId, CarbonImmutable $at): void
    {
        $facts = ['credential' => 'otp', 'account' => null, 'numbers' => null, 'callerIds' => null, 'calls7d' => null, 'alerts24h' => null, 'errors' => []];
        StudioIntegrationSnapshot::query()->forceCreate([
            'client_request_id' => $clientRequestId, 'provider' => $provider, 'kind' => $kind, 'fingerprint' => StudioIntegrationConfig::fingerprintOf($facts),
            'facts' => $facts, 'outcome' => 'ok', 'actor_type' => 'admin', 'actor_admin_id' => $actor->id, 'actor_name' => $actor->name, 'actor_role' => $actor->role,
            'created_at' => $at,
        ]);
    }

    /**
     * El uuid de la versión que pide un caso: sembrada en su estado (o uno que
     * no existe). Con el uuid simbólico del fixture, se recuerda para traducir
     * los `diff` esperados.
     */
    private function versionFor(?array $version, ?string $fixtureUuid = null): string
    {
        if ($version === null || ! ($version['exists'] ?? false)) {
            return $fixtureUuid ?? (string) Str::uuid();
        }
        // Los estados del ciclo solo admiten una CANARY y una PUBLISHED a la vez.
        $existente = DB::table('ultron_studio_versions')->where('status', $version['status'])->whereIn('status', ['CANARY', 'PUBLISHED'])->value('uuid');
        $uuid = $existente !== null && $version['integrityOk']
            ? (string) $existente
            : (string) $this->versionIn($this->personWith(self::ADMIN_KEYS, 'Autora de la versión'), $version['status'], $version['integrityOk'])->uuid;
        if ($fixtureUuid !== null) {
            $this->uuids[$fixtureUuid] = $uuid;
        }

        return $uuid;
    }

    /** Un valor esperado con los uuids simbólicos del fixture cambiados por los sembrados. */
    private function translate(mixed $valor): mixed
    {
        if (is_string($valor)) {
            return $this->uuids[$valor] ?? $valor;
        }

        return is_array($valor) ? array_map(fn (mixed $v): mixed => $this->translate($v), $valor) : $valor;
    }

    /**
     * Compara el resultado con el del fixture: estado, código, carga (con las
     * referencias simbólicas resueltas) y el estado de destino.
     *
     * @param  array<string, mixed>  $e
     * @param  array<string, ?string>  $refs
     */
    private function assertOutcome(TestResponse $r, array $e, string $dto, array $refs = []): void
    {
        if (($e['code'] ?? null) === 'person_required' && $r->json('code') === 'forbidden') {
            // CN-4: con el token compartido, la puerta de AuthorizationMap responde antes que el dominio.
            $this->assertSame(403, $r->status());

            return;
        }
        $this->assertSame($e['status'], $r->status(), $r->getContent());
        $codigo = match (true) {
            $r->status() >= 400 => $r->json('code'),
            $r->json('data.replayed') === true => 'replayed',
            $r->json('data.reused') === true => 'reused',
            default => null,
        };
        $this->assertSame($e['code'], $codigo, $r->getContent());
        foreach ($e['payload'] ?? [] as $clave => $valor) {
            if ($clave === 'current') {
                $this->assertSame($valor === null ? null : $refs[$valor], $r->json('current.uuid'), 'current');

                continue;
            }
            $this->assertSame($valor, $r->json($clave), $clave);
        }
        if (isset($e['toStatus'])) {
            $this->assertSame($e['toStatus'], $r->json("data.{$dto}.status"));
        }
    }

    /** El libro de una asociación: el conjunto de eventos nuevos (o ninguno con `audit: null`). */
    private function assertAuditList(array $e, int $antes): void
    {
        if (! array_key_exists('audit', $e)) {
            return;
        }
        $nuevos = StudioAuditEvent::query()->where('id', '>', $antes)->orderBy('id')->get();
        if ($e['audit'] === null) {
            $this->assertCount(0, $nuevos);

            return;
        }
        $vistos = $nuevos->map(fn (StudioAuditEvent $ev): array => ['event' => $ev->event, 'from' => $ev->from_status, 'to' => $ev->to_status])->all();
        $esperados = array_map(static fn (array $a): array => ['event' => $a['event'], 'from' => $a['from'], 'to' => $a['to']], $e['audit']);
        $orden = static fn (array $l): array => collect($l)->sortBy(fn ($x) => json_encode($x))->values()->all();
        $this->assertSame($orden($esperados), $orden($vistos), 'el conjunto de eventos del libro');
        foreach ($e['audit'] as $a) {
            if (isset($a['diff'])) {
                $this->assertSame($a['diff'], $nuevos->firstWhere('event', $a['event'])->diff);
            }
        }
    }

    /** @return array<string, mixed> */
    private static function routeBody(int $n): array
    {
        return match ($n) {
            47 => ['clientRequestId' => (string) Str::uuid(), 'campaign' => 'campaign:0000000000000000', 'target' => ['kind' => 'baseline']],
            48, 52, 53 => ['reason' => 'Motivo de la paridad'],
            49 => ['clientRequestId' => (string) Str::uuid(), 'channel' => 'whatsapp', 'phone' => '+573000000902', 'alias' => 'Móvil de la paridad', 'consentAttested' => true],
            54 => ['versionUuid' => null],
            55 => ['clientRequestId' => (string) Str::uuid(), 'provider' => 'twilio', 'fingerprint' => str_repeat('0', 64)],
            56 => ['clientRequestId' => (string) Str::uuid()],
            default => [],
        };
    }
}
