<?php

namespace Tests\Feature\Telephony;

use App\Models\Admin;
use App\Models\Incident;
use App\Models\Voice\VoiceBrakeEntry;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceNumberGuard;
use App\Services\Telephony\VoiceProviderState;
use App\Services\Telephony\VoiceWebhookHealth;
use App\Services\Voice\Dto\VoiceSecretList;
use App\Services\Voice\Dto\VoiceWebhookList;
use App\Services\Voice\VoiceWebhookObserver;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;
use Tests\Unit\Voice\Support\FreshHttpFake;

/**
 * SC-3 DEL CIERRE (R8-12; `plans/informes-h9/informe-sec-cierre.md` §6.3,
 * decisión de seguridad «SÍ, frenar»): `voice:webhook-health` ECHA EL FRENO de
 * voz (`webhook_unhealthy`) ante un problema DEFINITIVO del webhook post-llamada
 * o de los secretos; lo INCIERTO solo abre su incidente medio.
 *
 *  - Las pruebas negativas del informe: `webhook_auto_disabled` → freno echado
 *    y la puerta de salientes niega `voice_braked`; `webhooks_unreadable` →
 *    incidente medio y NINGÚN freno.
 *  - Los siete definitivos frenan, cada uno con su apertura; los cuatro
 *    inciertos y lo que falta en Laravel (la base y las tres referencias) no.
 *  - Idempotente mientras siga echado; lo suelta una persona (la vía de
 *    siempre); una observación posterior con el mismo problema lo vuelve a
 *    echar (`:r2`), la que ya estaba en vuelo al soltarlo no; arreglado, no.
 *  - En seco dice si lo echaría y no escribe nada.
 *  - La evidencia del freno son códigos: ni URL, ni ids del proveedor, ni
 *    secretos.
 *
 * Siempre con `Http::fake` y la red cerrada. A las 10:00 de Bogotá, en UTC
 * (dentro del horario legal de salientes).
 */
class VoiceWebhookHealthBrakeTest extends TestCase
{
    use FreshHttpFake;
    use RefreshDatabase;
    use VoiceFixtures;

    private const CLAVE = 'xi-centinela-SC3-0123456789';

    private const SIM = 'http://127.0.0.1:8096';

    private const BASE = 'https://api.ironbody.test';

    private const WEBHOOK = 'wh_propio_sc3_0001';

    private const HERRAMIENTAS = 'sec_herramientas_sc3_01';

    private const INICIACION = 'sec_iniciacion_sc3_02';

    private Admin $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-09 15:00:00', 'UTC'));
        Http::preventStrayRequests();
        Queue::fake();
        $this->voiceWorld([
            'voice.monitor.enabled' => true,
            'voice.webhook_base_url' => self::BASE,
            'voice.elevenlabs.enabled' => true, 'voice.elevenlabs.api_key' => self::CLAVE, 'voice.elevenlabs.agent_id' => 'agent_verify_0001',
            'voice.elevenlabs.region' => 'default', 'voice.elevenlabs.base_url' => self::SIM, 'voice.elevenlabs.timeout_seconds' => 7,
            'voice.elevenlabs.post_call_webhook_ref' => self::WEBHOOK, 'voice.elevenlabs.tool_secret_ref' => self::HERRAMIENTAS,
            'voice.elevenlabs.initiation_secret_ref' => self::INICIACION,
            'meta.whatsapp_display_phone' => null, 'meta.protected_numbers' => [],
        ]);
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root Suelta');
    }

    /**
     * El proveedor: el webhook propio (con lo que cambie la prueba), los secretos
     * y el número importado (que llama con `TWILIO_VOICE_FROM` y apunta al agente
     * sellado: la conciliación del número cuadra).
     *
     * @param  array<string, mixed>  $propio
     * @param  list<string>|null  $secretos
     */
    private function proveedor(array $propio = [], ?array $secretos = null, ?string $cursor = null, int $httpWebhooks = 200, int $httpSecretos = 200): void
    {
        $webhook = $propio + ['name' => 'ironbody-voz-post-llamada', 'webhook_id' => self::WEBHOOK, 'webhook_url' => self::BASE.VoiceWebhookHealth::POST_CALL_PATH,
            'is_disabled' => false, 'is_auto_disabled' => false, 'auth_type' => 'hmac'];
        $lista = array_map(static fn (string $id): array => ['type' => 'stored', 'secret_id' => $id, 'name' => 'ironbody-voz-'.$id],
            $secretos ?? [self::HERRAMIENTAS, self::INICIACION]);
        self::freshFake([
            self::SIM.'/v1/workspace/webhooks' => Http::response(['webhooks' => [$webhook]], $httpWebhooks),
            self::SIM.'/v1/convai/secrets*' => Http::response(['secrets' => $lista, 'next_cursor' => $cursor], $httpSecretos),
            self::SIM.'/v1/convai/phone-numbers/'.self::PHONE_NUMBER_ID => Http::response(['phone_number_id' => self::PHONE_NUMBER_ID, 'provider' => 'twilio',
                'phone_number' => self::VOICE_FROM, 'assigned_agent' => ['agent_id' => self::SEALED_AGENT, 'agent_name' => 'x']], 200),
            self::SIM.'/v1/convai/agents/*' => Http::response(['detail' => 'x'], 404),
        ]);
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} el código, el JSON y la salida */
    private function salud(array $opciones = []): array
    {
        $codigo = Artisan::call('voice:webhook-health', $opciones + ['--json' => true]);
        $salida = Artisan::output();

        return [$codigo, json_decode($salida, true, 64, JSON_THROW_ON_ERROR), $salida];
    }

    /** @return list<string> las claves de las aperturas, en orden */
    private static function aperturas(): array
    {
        return VoiceBrakeEntry::query()->where('kind', VoiceBrakeEntry::KIND_OPEN)->orderBy('id')->pluck('open_key')->all();
    }

    /**
     * El proveedor sano y, en Laravel, `$cambio` (una base o una referencia que falta).
     *
     * @param  array<string, mixed>  $cambio
     */
    private function conLaravel(array $cambio): Closure
    {
        return function () use ($cambio): void {
            $this->proveedor();
            config($cambio);
        };
    }

    private function soltar(VoiceBrakeEntry $apertura): void
    {
        VoiceBrake::release($this->root, (string) $apertura->uuid, 'Webhook reactivado en ElevenLabs y llamadas revisadas', (string) Str::uuid());
    }

    /**
     * Un canario de voz activo con todo abierto salvo la constante del código:
     * publicación, verificación de ElevenLabs y una conciliación del número
     * fresca que cuadra. Así la puerta solo dice `calls_disabled_in_code`… o eso
     * más lo que añada el freno.
     */
    private function puertaLista(): string
    {
        $canario = $this->activeVoiceCanary();
        $this->publishVoice();
        VoiceProviderState::recordStudioVerification(true, now());
        $this->proveedor();
        $this->assertSame(0, Artisan::call('voice:reconcile', ['--numbers' => true, '--json' => true]));
        $this->assertSame('verified', VoiceNumberGuard::policyCheck());

        return $canario;
    }

    /** @return list<string> los motivos de la puerta de salientes para el canario, con las banderas y el presupuesto abiertos */
    private function motivosDeLaPuerta(string $canario): array
    {
        config(['voice.outbound.enabled' => true, 'voice.twilio.enabled' => true, 'voice.twilio.daily_call_cap' => 3, 'voice.limits.daily_budget_usd' => '5']);

        return $this->postJson('/api/admin/voice/calls', ['clientRequestId' => (string) Str::uuid(), 'target' => ['kind' => 'canary', 'uuid' => $canario]],
            $this->adminWithPermissions(['telephony.call_place']))->assertStatus(409)->json('reasons');
    }

    private function assertSinSecretosNiUrls(string $texto, string $donde): void
    {
        foreach ([self::CLAVE, self::BASE, 'ironbody.test', 'otra.example', self::WEBHOOK, self::HERRAMIENTAS, self::INICIACION,
            self::WEBHOOK_SECRET, self::TOOL_SECRET, self::INITIATION_SECRET] as $s) {
            $this->assertStringNotContainsString($s, $texto, "{$donde}: {$s}");
        }
    }

    // ── Las dos pruebas negativas del informe ──────────────────────────────

    public function test_webhook_desactivado_por_elevenlabs_echa_el_freno_y_la_puerta_niega_voice_braked(): void
    {
        $canario = $this->puertaLista();
        $this->assertSame(['calls_disabled_in_code'], $this->motivosDeLaPuerta($canario), 'la línea base: solo la constante del código');

        // Sano: ni freno ni incidente.
        [$codigo, $r] = $this->salud();
        $this->assertSame([0, 'ok', null], [$codigo, $r['state'], $r['brake']]);
        $this->assertFalse(VoiceBrake::isOpen());

        // R8-12: ElevenLabs lo desactiva solo tras 10 fallos seguidos.
        $this->proveedor(['is_auto_disabled' => true]);
        [$codigo, $r, $salida] = $this->salud();
        $this->assertSame([1, ['webhook_auto_disabled']], [$codigo, $r['problems']]);
        $this->assertSame(['webhook_unhealthy', ['webhook_auto_disabled'], true, true], [$r['brake']['reason'], $r['brake']['problems'], $r['brake']['opened'], $r['brake']['engaged']]);
        $this->assertTrue(VoiceBrake::isOpen());

        $apertura = VoiceBrakeEntry::query()->where('kind', VoiceBrakeEntry::KIND_OPEN)->sole();
        $clave = VoiceWebhookHealth::brakeKey(['webhook_auto_disabled']);
        $this->assertSame(['webhook_unhealthy', $clave, 'system', null, null, $r['brake']['uuid']],
            [$apertura->reason, $apertura->open_key, $apertura->actor_type, $apertura->call_id, $apertura->event_id, $apertura->uuid]);
        $this->assertMatchesRegularExpression('/^webhook:[0-9a-f]{32}$/', $clave);
        $this->assertSame(['where' => 'webhook_health', 'codes' => ['webhook_auto_disabled'], 'incidentId' => $r['incident']], $apertura->evidence);

        // Dos avisos: el del webhook (alto) y el del freno (alto).
        $this->assertSame(Incident::SEVERITY_HIGH, Incident::query()->where('kind', VoiceWebhookHealth::INCIDENT_KIND)->sole()->severity);
        $freno = Incident::query()->where('kind', VoiceBrake::INCIDENT_KIND)->sole();
        $this->assertSame(['voice', Incident::SEVERITY_HIGH, 'webhook_unhealthy', $apertura->uuid], [$freno->source, $freno->severity, $freno->evidence['reason'], $freno->evidence['brake_uuid']]);

        // La puerta de salientes niega por el freno.
        $this->assertSame(['calls_disabled_in_code', 'voice_braked'], $this->motivosDeLaPuerta($canario));

        // Sin secretos ni URLs en la orden, el freno, su incidente ni el listado del CRM.
        $lista = $this->studio('GET', 'voice/brakes', $this->root)->assertOk()->json('data');
        $this->assertSame('webhook_unhealthy', $lista['brakes'][0]['reason']);
        $this->assertSame(['webhook_auto_disabled'], $lista['brakes'][0]['evidence']['codes']);
        $this->assertSinSecretosNiUrls($salida.json_encode([$apertura->evidence, $freno->title, $freno->evidence, $lista]), 'freno');
    }

    public function test_webhooks_ilegibles_solo_abren_un_incidente_medio_y_ningun_freno(): void
    {
        $canario = $this->puertaLista();

        // El proveedor responde 500 a las dos listas: no se pudo comprobar.
        $this->proveedor([], null, null, 500, 500);
        [$codigo, $r, $salida] = $this->salud();
        $this->assertSame([1, ['webhooks_unreadable', 'secrets_unreadable'], null], [$codigo, $r['problems'], $r['brake']]);
        $this->assertSame(Incident::SEVERITY_MEDIUM, Incident::query()->sole()->severity, 'un solo aviso, medio: el del webhook; ningún incidente del freno');
        $this->assertSame([], self::aperturas());
        $this->assertFalse(VoiceBrake::isOpen());
        $this->assertSame(['calls_disabled_in_code'], $this->motivosDeLaPuerta($canario), 'la puerta no ve ningún freno');

        Artisan::call('voice:webhook-health');
        $this->assertStringContainsString('Freno de voz: no se echa (solo frena un problema definitivo: ', Artisan::output());
        $this->assertSinSecretosNiUrls($salida, 'la orden');
    }

    // ── Qué frena y qué no ──────────────────────────────────────────────────

    public function test_los_siete_problemas_definitivos_echan_el_freno_cada_uno_con_su_apertura(): void
    {
        $casos = [
            'desactivado solo tras fallos (R8-12)' => [['is_auto_disabled' => true], null, ['webhook_auto_disabled']],
            'desactivado a mano' => [['is_disabled' => true], null, ['webhook_disabled']],
            'otra URL' => [['webhook_url' => 'https://otra.example'.VoiceWebhookHealth::POST_CALL_PATH], null, ['webhook_url_mismatch']],
            'sin HMAC' => [['auth_type' => 'oauth2'], null, ['webhook_auth_not_hmac']],
            'el proveedor no dice la autenticación (falla cerrado)' => [['auth_type' => null], null, ['webhook_auth_not_hmac']],
            'el webhook no está' => [['webhook_id' => 'wh_otro_sc3_0003'], null, ['webhook_not_found']],
            'falta el secreto de las herramientas' => [[], [self::INICIACION], ['tool_secret_not_found']],
            'falta el secreto de la iniciación' => [[], [self::HERRAMIENTAS], ['initiation_secret_not_found']],
        ];
        $vistas = [];
        foreach ($casos as $caso => [$propio, $secretos, $problemas]) {
            $this->proveedor($propio, $secretos);
            [$codigo, $r] = $this->salud();
            $this->assertSame([1, $problemas], [$codigo, $r['problems']], $caso);
            $clave = VoiceWebhookHealth::brakeKey($problemas);
            if (in_array($clave, $vistas, true)) {
                // La misma avería que un caso anterior: su apertura sigue echada (idempotente).
                $this->assertFalse($r['brake']['opened'], $caso);
            } else {
                $this->assertTrue($r['brake']['opened'], $caso);
                $vistas[] = $clave;
            }
            $this->assertSame([$problemas, true], [$r['brake']['problems'], $r['brake']['engaged']], $caso);
            $this->assertSame(Incident::SEVERITY_HIGH, Incident::query()->where('kind', VoiceWebhookHealth::INCIDENT_KIND)->latest('id')->first()->severity, $caso);
        }
        $this->assertSame($vistas, self::aperturas(), 'una apertura por avería distinta (siete)');
        $this->assertCount(7, $vistas);
        $this->assertSame(['webhook_unhealthy'], VoiceBrakeEntry::query()->where('kind', 'open')->distinct()->pluck('reason')->all());
        $this->assertEqualsCanonicalizing(VoiceWebhookHealth::BRAKING, VoiceBrakeEntry::query()->where('kind', 'open')->get()->flatMap(fn ($a) => $a->evidence['codes'])->unique()->values()->all());
    }

    public function test_lo_incierto_y_lo_que_falta_en_laravel_no_frenan(): void
    {
        $casos = [
            'las dos listas ilegibles' => [fn () => $this->proveedor([], null, null, 500, 500), ['webhooks_unreadable', 'secrets_unreadable'], Incident::SEVERITY_MEDIUM],
            'credencial rechazada' => [fn () => $this->proveedor([], null, null, 401, 200), ['webhooks_unreadable'], Incident::SEVERITY_MEDIUM],
            'lista de secretos truncada' => [fn () => $this->proveedor([], [self::HERRAMIENTAS], 'cursor_2'), ['secrets_list_truncated'], Incident::SEVERITY_MEDIUM],
            'proveedor sin preparar' => [function (): void {
                config(['voice.elevenlabs.enabled' => false]);
                self::freshFake();
            }, ['provider_not_ready'], Incident::SEVERITY_MEDIUM],
            // Lo que falta en Laravel: alto como hasta ahora (hay que arreglarlo), pero no es un problema del webhook.
            'sin la base de los webhooks' => [$this->conLaravel(['voice.webhook_base_url' => '']), ['base_url_invalid'], Incident::SEVERITY_HIGH],
            'sin la referencia del webhook' => [$this->conLaravel(['voice.elevenlabs.post_call_webhook_ref' => null]), ['webhook_ref_missing'], Incident::SEVERITY_HIGH],
            'sin la referencia del secreto de las herramientas' => [$this->conLaravel(['voice.elevenlabs.tool_secret_ref' => '']), ['tool_secret_ref_missing'], Incident::SEVERITY_HIGH],
            'sin la referencia del secreto de la iniciación' => [$this->conLaravel(['voice.elevenlabs.initiation_secret_ref' => null]), ['initiation_secret_ref_missing'], Incident::SEVERITY_HIGH],
        ];
        $antes = config()->all();
        foreach ($casos as $caso => [$prepara, $problemas, $gravedad]) {
            config($antes);
            Incident::query()->delete();
            $prepara();
            [$codigo, $r] = $this->salud();
            $this->assertSame([1, $problemas, null], [$codigo, $r['problems'], $r['brake']], $caso);
            $this->assertSame($gravedad, Incident::query()->sole()->severity, $caso.': solo el incidente del webhook');
            $this->assertSame([], self::aperturas(), $caso);
        }
        $this->assertFalse(VoiceBrake::isOpen());
    }

    public function test_un_problema_definitivo_frena_aunque_venga_con_algo_incierto_y_la_huella_solo_mira_lo_definitivo(): void
    {
        // Desactivado + lista de secretos ilegible: frena por lo definitivo.
        $this->proveedor(['is_auto_disabled' => true], null, null, 200, 500);
        [, $r] = $this->salud();
        $this->assertSame(['webhook_auto_disabled', 'secrets_unreadable'], $r['problems']);
        $this->assertSame([['webhook_auto_disabled'], true], [$r['brake']['problems'], $r['brake']['opened']]);
        $this->assertSame(Incident::SEVERITY_HIGH, Incident::query()->where('kind', VoiceWebhookHealth::INCIDENT_KIND)->sole()->severity);

        // La lectura de los secretos vuelve: la misma avería definitiva, la MISMA apertura (no abre otra).
        $this->travel(1)->hours();
        $this->proveedor(['is_auto_disabled' => true]);
        [, $r] = $this->salud();
        $this->assertSame([['webhook_auto_disabled'], false, true], [$r['brake']['problems'], $r['brake']['opened'], $r['brake']['engaged']]);
        $this->assertSame([VoiceWebhookHealth::brakeKey(['webhook_auto_disabled'])], self::aperturas());

        // Otra combinación de definitivos: otra apertura.
        $this->proveedor(['is_auto_disabled' => true], [self::INICIACION]);
        [, $r] = $this->salud();
        $this->assertSame([['tool_secret_not_found', 'webhook_auto_disabled'], true], [$r['brake']['problems'], $r['brake']['opened']]);
        $this->assertSame([VoiceWebhookHealth::brakeKey(['webhook_auto_disabled']), VoiceWebhookHealth::brakeKey(['tool_secret_not_found', 'webhook_auto_disabled'])], self::aperturas());
    }

    // ── Idempotencia, suelta y rearmado ─────────────────────────────────────

    public function test_idempotente_mientras_sigue_echado_lo_suelta_una_persona_y_una_observacion_posterior_lo_vuelve_a_echar(): void
    {
        $canario = $this->puertaLista();
        $clave = VoiceWebhookHealth::brakeKey(['webhook_disabled']);

        // Tres pasadas con la misma avería (minutos aparte: la conciliación del número vale 2 h): una sola apertura y un solo aviso del freno.
        $this->proveedor(['is_disabled' => true]);
        $abiertas = [];
        for ($i = 0; $i < 3; $i++) {
            $abiertas[] = $this->salud()[1]['brake']['opened'];
            $this->travel(5)->minutes();
        }
        $this->assertSame([true, false, false], $abiertas);
        $this->assertSame([$clave], self::aperturas());
        $this->assertSame(1, (int) Incident::query()->where('kind', VoiceBrake::INCIDENT_KIND)->sum('occurrences'));
        $this->assertSame(3, (int) Incident::query()->where('kind', VoiceWebhookHealth::INCIDENT_KIND)->sole()->occurrences, 'el aviso del webhook suma cada pasada');
        $this->assertSame(['calls_disabled_in_code', 'voice_braked'], $this->motivosDeLaPuerta($canario));

        // Lo suelta una persona por la vía de siempre (la del CRM: «Publicar la voz» y una nota) MIENTRAS una comprobación
        // está en vuelo: esa observación empezó antes de la suelta y no lo vuelve a echar.
        // El reloj avanza DENTRO de la lectura (un minuto antes y otro después de soltar): la observación empezó antes de la
        // suelta y terminó después; cuenta su comienzo.
        $primera = VoiceBrakeEntry::query()->where('open_key', $clave)->sole();
        $r = app(VoiceWebhookHealth::class)->run(self::observador(['disabled' => true], function () use ($primera): void {
            $this->travel(1)->minutes();
            $this->studio('POST', 'voice/brakes/'.$primera->uuid.'/release', $this->root,
                ['clientRequestId' => (string) Str::uuid(), 'note' => 'Webhook reactivado en ElevenLabs y llamadas revisadas'])->assertOk();
            $this->travel(1)->minutes();
        }));
        $this->assertSame([['webhook_disabled'], false, false], [$r['brake']['problems'], $r['brake']['opened'], $r['brake']['engaged']]);
        $this->assertSame([$clave], self::aperturas());
        $this->assertFalse(VoiceBrake::isOpen());
        $this->assertSame(['calls_disabled_in_code'], $this->motivosDeLaPuerta($canario));

        // Una observación POSTERIOR con el mismo problema, sí: la generación siguiente, con su incidente y `rearmOf`.
        $this->travel(5)->minutes();
        [, $r] = $this->salud();
        $this->assertSame([true, true], [$r['brake']['opened'], $r['brake']['engaged']]);
        $this->assertSame([$clave, $clave.':r2'], self::aperturas());
        $segunda = VoiceBrakeEntry::query()->where('open_key', $clave.':r2')->sole();
        $this->assertSame(['where' => 'webhook_health', 'codes' => ['webhook_disabled'], 'incidentId' => $r['incident'], 'rearmOf' => $primera->uuid], $segunda->evidence);
        $this->assertSame(2, (int) Incident::query()->where('kind', VoiceBrake::INCIDENT_KIND)->sum('occurrences'), 'cada apertura avisa');
        $this->assertSame(['calls_disabled_in_code', 'voice_braked'], $this->motivosDeLaPuerta($canario));

        // Arreglado y suelto: la comprobación siguiente no frena.
        $this->soltar($segunda);
        $this->travel(5)->minutes();
        $this->proveedor();
        [$codigo, $r] = $this->salud();
        $this->assertSame([0, 'ok', null], [$codigo, $r['state'], $r['brake']]);
        $this->assertFalse(VoiceBrake::isOpen());
        $this->assertSame(['calls_disabled_in_code'], $this->motivosDeLaPuerta($canario));
    }

    public function test_en_seco_dice_si_lo_echaria_y_no_escribe_nada(): void
    {
        $this->proveedor(['is_auto_disabled' => true]);
        [$codigo, $r] = $this->salud(['--dry-run' => true]);
        $this->assertSame([1, true, null], [$codigo, $r['dryRun'], $r['incident']]);
        $this->assertSame(['reason' => 'webhook_unhealthy', 'problems' => ['webhook_auto_disabled'], 'opened' => null, 'uuid' => null, 'wouldOpen' => true, 'engaged' => false], $r['brake']);
        $this->assertSame([], self::aperturas());
        $this->assertSame(0, Incident::query()->count());
        $this->assertNull(Cache::get(VoiceWebhookHealth::CACHE_KEY));

        // Echado de verdad, en seco ya no abriría otra.
        $this->salud();
        [, $r] = $this->salud(['--dry-run' => true]);
        $this->assertSame([false, true], [$r['brake']['wouldOpen'], $r['brake']['engaged']]);
        $this->assertCount(1, self::aperturas());

        Artisan::call('voice:webhook-health', ['--dry-run' => true]);
        $this->assertStringContainsString('Freno de voz: EN SECO, no abriría otra apertura (webhook_unhealthy · webhook_auto_disabled); ahora está echado', Artisan::output());
    }

    public function test_con_el_monitor_apagado_no_frena_ni_sale_a_la_red(): void
    {
        config(['voice.monitor.enabled' => false]);
        self::freshFake();
        [$codigo, $r] = $this->salud();
        $this->assertSame([0, 'disabled', null], [$codigo, $r['state'], $r['brake']]);
        Http::assertNothingSent();
        $this->assertSame([], self::aperturas());
    }

    public function test_la_orden_en_texto_dice_que_echo_el_freno_y_como_se_suelta(): void
    {
        $this->proveedor(['webhook_url' => 'https://otra.example/x']);
        $this->assertSame(1, Artisan::call('voice:webhook-health'));
        $texto = Artisan::output();
        $uuid = VoiceBrakeEntry::query()->where('kind', 'open')->sole()->uuid;
        $this->assertStringContainsString('No coincide: webhook_url_mismatch (incidente ', $texto);
        $this->assertStringContainsString('Freno de voz: ECHADO (webhook_unhealthy · webhook_url_mismatch; apertura '.$uuid.'). Lo suelta una persona con «Publicar la voz» y una nota', $texto);
        $this->assertSinSecretosNiUrls($texto, 'la orden en texto');

        // La segunda pasada: sin apertura nueva, y lo dice.
        Artisan::call('voice:webhook-health');
        $this->assertStringContainsString('Freno de voz: sin apertura nueva (webhook_unhealthy · webhook_url_mismatch: la de esta avería sigue echada o se soltó durante la comprobación); ahora está echado', Artisan::output());
    }

    /**
     * Un observador propio: el webhook de la referencia con `$cambio`, los dos
     * secretos, y `$during` corre MIENTRAS se lee (la observación ya empezó).
     *
     * @param  array<string, mixed>  $cambio
     */
    private static function observador(array $cambio, ?Closure $during = null): VoiceWebhookObserver
    {
        return new class($cambio, $during) implements VoiceWebhookObserver
        {
            /** @param  array<string, mixed>  $cambio */
            public function __construct(private readonly array $cambio, private readonly ?Closure $during) {}

            public function describeWebhooks(): VoiceWebhookList
            {
                if ($this->during !== null) {
                    ($this->during)();
                }

                // El DTO exige las claves en este orden: array_merge conserva el orden de la primera y sobrescribe los valores.
                return new VoiceWebhookList(VoiceWebhookList::OUTCOME_OBSERVED, [array_merge(['id' => VoiceWebhookHealthBrakeTest::webhookRef(),
                    'url' => 'https://api.ironbody.test'.VoiceWebhookHealth::POST_CALL_PATH, 'disabled' => false, 'autoDisabled' => false, 'authType' => 'hmac'], $this->cambio)], 200, null);
            }

            public function describeSecrets(): VoiceSecretList
            {
                return new VoiceSecretList(VoiceSecretList::OUTCOME_OBSERVED, VoiceWebhookHealthBrakeTest::secretRefs(), false, 200, null);
            }
        };
    }

    public static function webhookRef(): string
    {
        return self::WEBHOOK;
    }

    /** @return list<string> */
    public static function secretRefs(): array
    {
        return [self::HERRAMIENTAS, self::INICIACION];
    }
}
