<?php

namespace Tests\Feature\Telephony;

use App\Models\Admin;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceConsent;
use App\Models\Voice\VoiceContact;
use App\Models\Voice\VoiceExclusion;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceCallFacts;
use App\Services\Telephony\VoiceContacts;
use App\Services\Telephony\VoiceEvents;
use App\Services\Telephony\VoiceEvidence;
use App\Services\Telephony\VoicePhone;
use App\Services\Telephony\VoiceTelephonyStatus;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * EL PRESUPUESTO DE SENTENCIAS DEL CRM DE LA TELEFONÍA (PERF8, hallazgos P8-1
 * y P8-2; B8-c).
 *
 *  - P8-1: `VoiceEvidence::summary()` recorría `voice_calls` siete veces por
 *    parte (ids, mínimo, máximo, completadas, verificadas, suma…) y los
 *    eventos con `whereIn` de todos los ids; `GET admin/voice/status` leía
 *    además la cadena TRES veces. Ahora: una agregación de `voice_calls` por
 *    parte (con el índice `vc_publication_idx`), una de eventos con
 *    semi-join, la cadena una vez y el freno una vez.
 *  - P8-2: `GET admin/voice/contacts` hacía 2 sentencias por fila
 *    (consentimiento y exclusión). Ahora, dos `whereIn` por página: el mismo
 *    recuento con 25 filas que con 100.
 *
 * Los RESULTADOS no cambian: los valores esperados se fijan a mano desde la
 * siembra (y se comprobaron contra el código anterior antes de cambiarlo), y
 * cada fila de contactos se compara con las lecturas de la política
 * (`VoiceContacts::hasConsent()` y `VoiceCallFacts::excluded()`), que siguen
 * siendo de una fila. Los topes son FIJOS: no se miden contra sí mismos.
 */
class VoiceCrmQueryBudgetTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    /** `summary()` de una parte: una agregación de llamadas, una de eventos y las desconocidas desde la primera llamada; y el freno si no se lo pasan. */
    private const SUMMARY_MAX = 3;

    private const SUMMARY_ALONE_MAX = self::SUMMARY_MAX + 1;

    /** `snapshot()` (la ruta `status` sin la pila HTTP), con las dos partes vigentes y llamadas en las dos. */
    private const STATUS_SERVICE_MAX = 14;

    /** Las rutas enteras (sesión, permisos y limitadores de la suite incluidos). */
    private const STATUS_HTTP_MAX = 20;

    private const METRICS_HTTP_MAX = 18;

    /** `GET admin/voice/contacts`: la página, su total y DOS `whereIn`, tenga 25 filas o 100. */
    private const CONTACTS_LIST_MAX = 4;

    private const REST_AGENT = 'agent_h8fx_rest_0004';

    private Carbon $t0;

    /** @var list<string>|null */
    private ?array $sql = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->voiceWorld();
        // Lunes 9 de noviembre de 2026, 10:00 de Bogotá, en UTC (los relojes de prueba, siempre en UTC).
        $this->t0 = Carbon::parse('2026-11-09T15:00:00Z');
        $this->travelTo($this->t0);
        DB::listen(function (QueryExecuted $q): void {
            if ($this->sql !== null) {
                $this->sql[] = $q->sql;
            }
        });
    }

    // ── P8-1 ────────────────────────────────────────────────────────────────

    public function test_el_indice_de_la_entrada_y_la_parte_existe_y_no_es_parcial(): void
    {
        $indice = collect(Schema::getIndexes('voice_calls'))->firstWhere('name', 'vc_publication_idx');

        $this->assertNotNull($indice, 'P8-1: (voice_publication_seq, part) en voice_calls');
        $this->assertSame(['voice_publication_seq', 'part'], $indice['columns']);
        $this->assertFalse($indice['unique']);
    }

    public function test_summary_lee_las_llamadas_de_su_parte_una_vez_y_da_lo_mismo_que_antes(): void
    {
        $s = $this->seedCalls();

        [$canario, $sentencias] = $this->measured(fn (): array => VoiceEvidence::summary('canary', $s['canary']));
        $this->assertSame($this->expectedCanary($s), $canario);
        $this->assertLessThanOrEqual(self::SUMMARY_ALONE_MAX, count($sentencias), $this->listing($sentencias));
        $this->assertCount(1, $this->touching($sentencias, 'voice_calls', 'voice_call_events'), 'una sola lectura de voice_calls (P8-1)');

        // Con el freno ya leído (como lo llaman las métricas), lo mismo con una sentencia menos.
        [$conFreno, $sentencias] = $this->measured(fn (): array => VoiceEvidence::summary('canary', $s['canary'], true));
        $this->assertSame($canario, $conFreno);
        $this->assertLessThanOrEqual(self::SUMMARY_MAX, count($sentencias), $this->listing($sentencias));
        $this->assertSame([], $this->touching($sentencias, 'voice_brakes'));

        [$resto, $sentencias] = $this->measured(fn (): array => VoiceEvidence::summary('rest', $s['rest']));
        $this->assertSame($this->expectedRest($s), $resto);
        $this->assertLessThanOrEqual(self::SUMMARY_ALONE_MAX, count($sentencias), $this->listing($sentencias));

        // Una entrada sin llamadas: ceros y nulos, sin leer eventos.
        [$vacia, $sentencias] = $this->measured(fn (): array => VoiceEvidence::summary('canary', $s['canary'] + 50));
        $this->assertSame(['part' => 'canary', 'seq' => $s['canary'] + 50, 'sealedCalls' => 0, 'completedCalls' => 0, 'sealVerified' => 0, 'sealMismatches' => 0,
            'guardViolations' => 0, 'unknownProviderCalls' => 0, 'committedMicros' => 0, 'firstCallAt' => null, 'lastCallAt' => null, 'hoursObserved' => 0,
            'brakeOpen' => true], $vacia);
        $this->assertCount(2, $sentencias, 'la agregación y el freno: sin llamadas no se leen eventos ni desconocidas');
    }

    public function test_status_lee_la_cadena_y_el_freno_una_vez_y_cada_parte_en_una_agregacion(): void
    {
        $s = $this->seedCalls();

        [$estado, $sentencias] = $this->measured(fn (): array => VoiceTelephonyStatus::snapshot());
        $this->assertLessThanOrEqual(self::STATUS_SERVICE_MAX, count($sentencias), $this->listing($sentencias));
        $this->assertCount(1, $this->touching($sentencias, 'ultron_studio_voice_publications'), 'la cadena se lee UNA vez (antes, tres)');
        $this->assertCount(1, $this->touching($sentencias, 'voice_brakes'), 'el freno se lee UNA vez (antes, cuatro)');
        $this->assertCount(2, array_filter($sentencias, static fn (string $q): bool => str_contains($q, 'voice_publication_seq') && ! str_contains($q, 'voice_call_events')),
            'una agregación de voice_calls por parte vigente');

        // Lo mismo que antes.
        $this->assertSame(['canary' => $this->expectedCanary($s), 'rest' => $this->expectedRest($s)], $estado['today']['publications']);
        // Sin destinos compilados en los agentes sembrados (columna nula): ninguno se puede leer.
        $this->assertSame(['canary' => null, 'rest' => null], $estado['transferDirectory']);
        $this->assertSame(['canary', 'rest'], array_keys($estado['publication']));
        $this->assertSame([$s['canary'], self::SEALED_AGENT, $s['rest'], self::REST_AGENT],
            [$estado['publication']['canary']['seq'], $estado['publication']['canary']['providerAgentId'], $estado['publication']['rest']['seq'], $estado['publication']['rest']['providerAgentId']]);
        $this->assertTrue($estado['brakeOpen']);
        $this->assertTrue($estado['today']['brakeOpen']);
        $this->assertSame(1, $estado['today']['live'] + $estado['today']['ambiguous'], 'la activa de la siembra');

        // Las rutas enteras, con la pila HTTP.
        $vista = $this->adminAs(Admin::ROLE_SUPER_ADMIN);
        $this->getJson('/api/admin/voice/status', $vista)->assertOk();
        [$r, $sentencias] = $this->measured(fn () => $this->getJson('/api/admin/voice/status', $vista)->assertOk());
        $this->assertLessThanOrEqual(self::STATUS_HTTP_MAX, count($sentencias), $this->listing($sentencias));
        $this->assertSame(['canary' => $this->expectedCanary($s), 'rest' => $this->expectedRest($s)], $r->json('today.publications'));

        [$r, $sentencias] = $this->measured(fn () => $this->getJson('/api/admin/voice/metrics', $vista)->assertOk());
        $this->assertLessThanOrEqual(self::METRICS_HTTP_MAX, count($sentencias), $this->listing($sentencias));
        $this->assertSame(['canary' => $this->expectedCanary($s), 'rest' => $this->expectedRest($s)], $r->json('publications'));
        $this->assertTrue($r->json('brakeOpen'));
        Http::assertNothingSent();
    }

    // ── P8-2 ────────────────────────────────────────────────────────────────

    public function test_la_lista_de_contactos_hace_las_mismas_sentencias_con_25_filas_que_con_100_y_cada_fila_es_la_de_antes(): void
    {
        $this->seedContacts(110);
        $contactos = new VoiceContacts;

        [$pagina25, $sentencias25] = $this->measured(fn (): array => $contactos->list(1, 25));
        [$pagina100, $sentencias100] = $this->measured(fn (): array => $contactos->list(1, 100));

        $this->assertCount(25, $pagina25['data']);
        $this->assertCount(100, $pagina100['data']);
        $this->assertSame(110, $pagina100['meta']['total']);
        $this->assertLessThanOrEqual(self::CONTACTS_LIST_MAX, count($sentencias25), $this->listing($sentencias25));
        $this->assertSame(count($sentencias25), count($sentencias100), 'sin N+1: el recuento no crece con la página');

        // Cada fila, igual que la de una fila sola, y sus dos lecturas, las de la política (una fila cada vez).
        $porUuid = VoiceContact::query()->get()->keyBy('uuid');
        $vistos = ['voiceConsent' => [0, 0], 'excluded' => [0, 0]];
        foreach ($pagina100['data'] as $fila) {
            $c = $porUuid[$fila['uuid']];
            $this->assertSame(VoiceContacts::row($c), $fila);
            $this->assertSame(VoiceContacts::hasConsent((int) $c->id), $fila['voiceConsent']);
            $this->assertSame(VoiceCallFacts::excluded((string) $c->phone_hash), $fila['excluded']);
            $vistos['voiceConsent'][(int) $fila['voiceConsent']]++;
            $vistos['excluded'][(int) $fila['excluded']]++;
        }
        // La siembra cubre los dos valores de cada campo.
        $this->assertGreaterThan(0, min([...$vistos['voiceConsent'], ...$vistos['excluded']]), json_encode($vistos));

        // La ruta, con la pila HTTP: el mismo contenido.
        $r = $this->getJson('/api/admin/voice/contacts?perPage=100', $this->adminWithPermissions(['telephony.contacts_manage']))->assertOk();
        $this->assertSame($pagina100['data'], $r->json('data'));
    }

    // ── Siembra ─────────────────────────────────────────────────────────────

    /**
     * Las dos partes vigentes con llamadas de varios estados, eventos y un
     * freno abierto. Devuelve las entradas y las llamadas que fijan los
     * extremos.
     *
     * @return array{canary: int, rest: int, calls: array<string, VoiceCall>}
     */
    private function seedCalls(): array
    {
        $canario = $this->publishVoice()['seq'];
        $resto = $this->publishVoice(self::REST_AGENT, 'rest')['seq'];
        $en = fn (int $minutos, Closure $hacer) => $this->at($this->t0->copy()->subMinutes($minutos), $hacer);

        // Una desconocida ANTES de la primera sellada: no cuenta en ninguna parte.
        $en(300, fn () => VoiceEvents::record('unknown_provider_call', null, 'twilio', 'CA_budget_antes', ['outcome' => 'post_call_transcription']));

        $llamadas = [];
        $llamadas['primera'] = $en(240, fn () => $this->verified($this->voiceCall('inbound', 'completed', ['voice_publication_seq' => $canario, 'remote' => '+573000000051'])));
        $mal = $en(180, fn () => $this->voiceCall('inbound', 'completed', ['voice_publication_seq' => $canario, 'remote' => '+573000000052']));
        DB::table('voice_calls')->where('id', $mal->id)->update(['observed_agent_id' => 'agent_ajeno_0009', 'seal_verified' => false]);
        $en(120, fn () => $this->voiceCall('outbound', 'no_answer', ['voice_publication_seq' => $canario, 'remote' => '+573000000053', 'reserved_micros' => 120000]));
        $en(60, fn () => $this->voiceCall('inbound', 'denied', ['voice_publication_seq' => $canario, 'remote' => '+573000000054']));
        $en(30, fn () => $this->voiceCall('outbound', 'canceled', ['voice_publication_seq' => $canario, 'remote' => '+573000000055', 'reserved_micros' => 0]));
        // En curso: cuenta en la entrada (sealedCalls) y en las vivas; es la última.
        $llamadas['ultima'] = $en(20, fn () => $this->voiceCall('inbound', 'active', ['voice_publication_seq' => $canario, 'remote' => '+573000000056']));
        // La parte `rest`, y otra entrada que no es de ninguna de las dos.
        $llamadas['resto'] = $en(90, fn () => $this->verified($this->voiceCall('inbound', 'completed', ['voice_publication_seq' => $resto, 'part' => 'rest',
            'provider_agent_id' => self::REST_AGENT, 'remote' => '+573000000057'])));
        $en(100, fn () => $this->voiceCall('inbound', 'completed', ['voice_publication_seq' => $canario + $resto + 7, 'remote' => '+573000000058']));

        $en(239, function () use ($llamadas, $mal): void {
            VoiceEvents::record('guard_verdict', (int) $llamadas['primera']->id, 'elevenlabs', 'conv_budget_1', ['outcome' => 'violation', 'codes' => ['violations' => [['turn' => 0, 'code' => 'voice_notice_missing']]]]);
            VoiceEvents::record('guard_verdict', (int) $mal->id, 'elevenlabs', 'conv_budget_2', ['outcome' => 'clean']);
            VoiceEvents::record('seal_mismatch', (int) $mal->id, 'elevenlabs', 'conv_budget_2', ['outcome' => 'agent_differs']);
            VoiceEvents::record('guard_verdict', (int) $llamadas['resto']->id, 'elevenlabs', 'conv_budget_3', ['outcome' => 'clean']);
        });
        // Una desconocida DESPUÉS de las dos primeras: cuenta en las dos partes.
        $en(45, fn () => VoiceEvents::record('unknown_provider_call', null, 'twilio', 'CA_budget_despues', ['outcome' => 'post_call_transcription']));
        $en(10, fn () => VoiceBrake::open('guard_violation', 'guard:conv_budget_1', []));

        return ['canary' => $canario, 'rest' => $resto, 'calls' => $llamadas];
    }

    /** @param  array{canary: int, rest: int, calls: array<string, VoiceCall>}  $s */
    private function expectedCanary(array $s): array
    {
        return [
            'part' => 'canary',
            'seq' => $s['canary'],
            'sealedCalls' => 6,
            'completedCalls' => 2,
            'sealVerified' => 1,
            'sealMismatches' => 1,
            'guardViolations' => 1,
            'unknownProviderCalls' => 1,
            'committedMicros' => 610500 * 4 + 120000,
            'firstCallAt' => $s['calls']['primera']->created_at->toIso8601String(),
            'lastCallAt' => $s['calls']['ultima']->created_at->toIso8601String(),
            'hoursObserved' => 4,
            'brakeOpen' => true,
        ];
    }

    /** @param  array{canary: int, rest: int, calls: array<string, VoiceCall>}  $s */
    private function expectedRest(array $s): array
    {
        return [
            'part' => 'rest',
            'seq' => $s['rest'],
            'sealedCalls' => 1,
            'completedCalls' => 1,
            'sealVerified' => 1,
            'sealMismatches' => 0,
            'guardViolations' => 0,
            'unknownProviderCalls' => 1,
            'committedMicros' => 610500,
            'firstCallAt' => $s['calls']['resto']->created_at->toIso8601String(),
            'lastCallAt' => $s['calls']['resto']->created_at->toIso8601String(),
            'hoursObserved' => 1,
            'brakeOpen' => true,
        ];
    }

    /** El sello observado y verificado, como lo deja el webhook post-llamada (una vez). */
    private function verified(VoiceCall $c): VoiceCall
    {
        DB::table('voice_calls')->where('id', $c->id)->update(['observed_agent_id' => $c->provider_agent_id, 'seal_verified' => true]);

        return $c->refresh();
    }

    /**
     * Contactos con todo lo que cambia la fila: consentimiento confirmado por
     * otra persona (cuatro ojos), revocado después, el de una entrante grabada
     * (no vale con la grabación apagada, P-7), uno pendiente de la segunda
     * persona y exclusiones.
     */
    private function seedContacts(int $n): void
    {
        $ana = $this->person(Admin::ROLE_SUPER_ADMIN, 'Ana Carga');
        $beto = $this->person(Admin::ROLE_SUPER_ADMIN, 'Beto Confirma');
        for ($i = 0; $i < $n; $i++) {
            $e164 = '+5730000'.str_pad((string) (10000 + $i), 5, '0', STR_PAD_LEFT);
            $pendiente = $i % 3 === 0 && $i % 5 === 0;
            $contacto = (new VoiceContact)->forceFill([
                'client_request_id' => (string) Str::uuid(),
                'phone_hash' => VoicePhone::hash($e164),
                'phone_last4' => VoicePhone::last4($e164),
                'phone_encrypted' => $e164,
                'source_type' => VoiceContact::SOURCE_MANUAL,
                'source_id' => null,
                'status' => VoiceContact::STATUS_ACTIVE,
                'registered_by_admin_id' => $ana->id,
                'registered_by_name' => (string) $ana->name,
                'registered_by_role' => $ana->role,
                'status_changed_at' => now(),
            ] + ($pendiente ? [
                'consent_request_source' => 'written_form',
                'consent_request_evidence' => 'Formulario firmado en recepción',
                'consent_requested_by_admin_id' => $ana->id,
                'consent_requested_by_name' => (string) $ana->name,
                'consent_requested_by_role' => $ana->role,
                'consent_requested_at' => $this->t0->copy()->subHour(),
            ] : []));
            $contacto->save();
            if ($i % 3 !== 0) {
                $this->consent($contacto, 'granted', 'written_form', $ana, $beto, $i);
            } elseif ($i % 11 === 0) {
                $this->consent($contacto, 'granted', 'recorded_inbound_request', null, null, $i);
            }
            if ($i % 7 === 0) {
                $this->consent($contacto, 'revoked', 'written_form', $ana, null, $i + 1);
            }
            if ($i % 4 === 0) {
                (new VoiceExclusion)->forceFill([
                    'phone_hash' => $contacto->phone_hash, 'phone_last4' => $contacto->phone_last4, 'source' => VoiceExclusion::SOURCE_ADMIN,
                    'contact_id' => $contacto->id, 'actor_type' => 'admin', 'actor_admin_id' => $ana->id, 'actor_name' => (string) $ana->name, 'actor_role' => $ana->role,
                ])->save();
            }
        }
    }

    private function consent(VoiceContact $c, string $kind, string $source, ?Admin $by, ?Admin $confirmedBy, int $minute): void
    {
        (new VoiceConsent)->forceFill([
            'client_request_id' => (string) Str::uuid(),
            'contact_id' => $c->id,
            'kind' => $kind,
            'channel' => VoiceConsent::CHANNEL,
            'purpose' => VoiceConsent::PURPOSE,
            'source' => $source,
            'evidence' => 'Formulario firmado en recepción',
            'actor_type' => $by === null ? 'system' : 'admin',
            'recorded_by_admin_id' => $by?->id,
            'recorded_by_name' => $by === null ? 'Sistema' : (string) $by->name,
            'recorded_by_role' => $by?->role,
            'recorded_at' => $this->t0->copy()->subDay()->addMinutes($minute),
            'confirmed_by_admin_id' => $confirmedBy?->id,
            'confirmed_by_name' => $confirmedBy?->name,
            'confirmed_by_role' => $confirmedBy?->role,
        ])->save();
    }

    // ── Medida ──────────────────────────────────────────────────────────────

    /**
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return array{0: T, 1: list<string>} lo que devuelve y las sentencias que lanzó
     */
    private function measured(Closure $fn): array
    {
        $this->sql = [];
        try {
            $resultado = $fn();

            return [$resultado, $this->sql];
        } finally {
            $this->sql = null;
        }
    }

    /**
     * @param  list<string>  $sentencias
     * @return list<string> las que nombran la tabla (y no la segunda, si se da)
     */
    private function touching(array $sentencias, string $table, ?string $not = null): array
    {
        return array_values(array_filter($sentencias, static fn (string $q): bool => preg_match('/["`\s]'.preg_quote($table, '/').'["`\s]/', $q.' ') === 1
            && ($not === null || ! str_contains($q, $not))));
    }

    /** @param  list<string>  $sentencias */
    private function listing(array $sentencias): string
    {
        return count($sentencias)." sentencias:\n".implode("\n", $sentencias);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    private function at(Carbon $when, Closure $fn): mixed
    {
        $this->travelTo($when);
        try {
            return $fn();
        } finally {
            $this->travelTo($this->t0);
        }
    }
}
