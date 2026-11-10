<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Outreach\Core\CanonicalCondition;
use App\Services\Outreach\Core\CanonicalJson;
use App\Services\Outreach\Core\ChannelSelector;
use App\Services\Outreach\Core\ConditionEvaluator;
use App\Services\Outreach\Core\ContactBudget;
use App\Services\Outreach\Core\DecisionTrace;
use App\Services\Outreach\Core\DecisionTraceText;
use App\Services\Outreach\Core\EnrollmentGate;
use App\Services\Outreach\Core\FactSnapshot;
use App\Services\Outreach\Core\FollowUpMachine;
use App\Services\Outreach\Core\OutcomeTable;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\PolicyLadder;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Core\StrategyChainSignature;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * La TRAZA de una decisión (T12: misma entrada → misma `traceHash`; T5: sin
 * datos personales; tope de 16 KB determinista), sus TEXTOS (cada código
 * cerrado tiene uno) y la PUREZA del espacio `Outreach\Core` (D9-2: sin base,
 * HTTP, log, caché, configuración ni reloj; solo las importaciones permitidas).
 */
class DecisionTraceAndPurityTest extends TestCase
{
    use LoadsOutreachFixtures;

    public function test_la_traza_es_reproducible_y_derivada(): void
    {
        $caso = self::fixture('policy-ladder-cases')['n1'][1];
        $ahora = RelativeTime::parseInstant($caso['input']['now']);
        $condicion = CanonicalCondition::tryParse(['all' => [['fact' => 'membership.active', 'op' => 'eq', 'value' => true], ['fact' => 'receivable.overdue_days_max', 'op' => 'gt', 'value' => 15]]])['condition'];
        $foto = FactSnapshot::fromArray(['facts' => ['membership.active' => true, 'receivable.overdue_days_max' => 40]]);
        $construir = static fn (): array => DecisionTrace::build(
            ['kind' => 'defer', 'level' => 'N1', 'reasons' => ['calendar_sunday'], 'retryAt' => '2026-11-09T13:00:00Z'],
            $foto,
            $ahora,
            ['audience' => ConditionEvaluator::evaluate($condicion, $foto, $ahora), 'exclusions' => null],
            ['policy' => PolicyLadder::decide($caso['input'])],
        );

        $a = $construir();
        $b = $construir();
        $this->assertSame(DecisionTrace::hash($a), DecisionTrace::hash($b));
        $this->assertSame(DecisionTrace::json($a), DecisionTrace::json($b));
        $this->assertSame('31-90', $a['conditions']['audience']['trace'][2]['observed'], 'la cartera, en tramos');
        $this->assertArrayNotHasKey('exclusions', $a['conditions']);
        $this->assertSame(OutreachCatalog::hash(), $a['catalogHash']);
        $this->assertSame($foto->hash(), $a['factsHash']);
        $this->assertSame('2026-11-08T15:00:00Z', $a['evaluatedAt']);
        $this->assertLessThanOrEqual(DecisionTrace::MAX_BYTES, strlen(DecisionTrace::json($a)));
        // Otro instante, otra huella.
        $this->assertNotSame(DecisionTrace::hash($a), DecisionTrace::hash(DecisionTrace::build(['kind' => 'defer'], $foto, $ahora->modify('+1 second'))));
    }

    public function test_la_traza_rechaza_datos_personales(): void
    {
        $foto = FactSnapshot::fromArray(['facts' => []]);
        $ahora = RelativeTime::parseInstant('2026-11-10T15:00:00Z');
        foreach (['+573000000001', '3000000001', 'ana@example.test', 'Ana María Pérez', 'Llamar a la socia el lunes', '<script>'] as $dato) {
            try {
                DecisionTrace::build(['kind' => 'deny', 'reasons' => [$dato]], $foto, $ahora);
                $this->fail("La traza aceptó «{$dato}».");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            DecisionTrace::build(['kind' => 'deny'], $foto, $ahora, [], ['policy' => ['nota libre' => 1]]);
            $this->fail('La traza aceptó una clave libre.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        foreach ([['kind' => 'enviar'], ['kind' => 'deny', 'extra' => 1.5]] as $mala) {
            try {
                DecisionTrace::build($mala, $foto, $ahora, [], $mala['kind'] === 'deny' ? ['otra' => 1] : []);
                $this->fail('La traza aceptó una forma mala.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        // Lo que sí lleva: códigos, rutas, instantes, huellas, uuids y tramos.
        DecisionTrace::assertDerived(['conditions.all.0', 'voice_policy:no_voice_consent', '2026-11-10T15:00:00Z', str_repeat('a', 64), '6a000000-0000-4000-8000-000000000001', '>90', 'NEW_LEAD', 12, null, true]);
        $this->addToAssertionCount(1);
    }

    public function test_el_tope_de_la_traza_es_determinista(): void
    {
        $hojas = array_map(static fn (int $i): array => ['fact' => 'membership.days_to_expiry', 'op' => 'eq', 'value' => $i], range(0, 48));
        $c = CanonicalCondition::tryParse(['any' => $hojas])['condition'];
        $foto = FactSnapshot::fromArray(['facts' => ['membership.days_to_expiry' => 3]]);
        $ahora = RelativeTime::parseInstant('2026-11-10T15:00:00Z');
        $r = ConditionEvaluator::evaluate($c, $foto, $ahora);
        $condiciones = ['audience' => $r, 'exclusions' => $r, 'success' => $r, 'guard' => $r];

        $a = DecisionTrace::build(['kind' => 'enroll'], $foto, $ahora, $condiciones);
        $this->assertTrue($a['truncated']);
        $this->assertCount(1, $a['conditions']['audience']['trace']);
        $this->assertLessThanOrEqual(DecisionTrace::MAX_BYTES, strlen(DecisionTrace::json($a)));
        $this->assertSame(DecisionTrace::hash($a), DecisionTrace::hash(DecisionTrace::build(['kind' => 'enroll'], $foto, $ahora, $condiciones)));
    }

    public function test_cada_codigo_cerrado_tiene_su_texto(): void
    {
        $codigos = [
            ...array_merge(...array_values(PolicyLadder::REASON_ORDER)),
            ...PolicyLadder::SHADOW_WARNINGS, ...PolicyLadder::N7_ANNOTATIONS, ...array_keys(PolicyLadder::EFFECTS), ...array_values(PolicyLadder::EFFECTS),
            EnrollmentGate::ENROLL, EnrollmentGate::NOT_IN_AUDIENCE, EnrollmentGate::INSUFFICIENT_FACTS, EnrollmentGate::EXCLUDED, EnrollmentGate::SUCCESS_REACHED,
            ...OutcomeTable::OUTCOMES, ...OutcomeTable::TRANSITIONS, ...array_merge(...array_column(OutcomeTable::TABLE, 'effects')), 'assisted_task',
            ...FollowUpMachine::REASONS, ...StrategyChainSignature::RESULTS,
        ];
        foreach (array_unique($codigos) as $codigo) {
            $this->assertNotNull(DecisionTraceText::text($codigo), "Falta el texto de {$codigo}.");
        }
        $this->assertSame(DecisionTraceText::text('voice_policy'), DecisionTraceText::text('voice_policy:frequency_exceeded'));
        $this->assertNull(DecisionTraceText::text('no_existe'));
        $this->assertSame(PolicyLadder::LEVELS, array_keys(DecisionTraceText::LEVELS));
        foreach ([ChannelSelector::SELECTED, ChannelSelector::DEFER, ChannelSelector::DENY, 'allow', OutcomeTable::VERDICT_NEEDS_REVIEW, OutcomeTable::VERDICT_IGNORE] as $v) {
            $this->assertArrayHasKey($v, DecisionTraceText::VERDICTS);
        }
        // Ningún texto lleva un hueco para datos de la persona.
        foreach (DecisionTraceText::TEXTS as $texto) {
            $this->assertDoesNotMatchRegularExpression('/\{|%s|:name|:phone/', $texto);
        }
    }

    public function test_las_frases_de_una_decision(): void
    {
        $f = self::fixture('policy-ladder-cases');
        $domingo = PolicyLadder::decide($f['n1'][1]['input']);
        $this->assertSame([
            'Se aplaza el contacto (N1 · Seguridad, ley y consentimiento).',
            'Es domingo: la ley no permite contactar.',
            'Se vuelve a intentar el 09-11-2026 a las 08:00 (Bogotá).',
        ], DecisionTraceText::explain($domingo));
        $sombra = PolicyLadder::decide($f['shadow'][0]['input']);
        $this->assertContains('Aviso: El envío automático está cerrado en el código.', DecisionTraceText::explain($sombra));
        $exito = PolicyLadder::decide($f['n6'][0]['input']);
        $this->assertContains('La inscripción cierra como objetivo cumplido.', DecisionTraceText::explain($exito));
        $sel = array_values(array_filter(self::fixture('channel-selection-cases')['choose'], static fn (array $c): bool => str_contains($c['name'], '«mejor por voz»')))[0];
        $canal = ChannelSelector::choose($sel['channels'], $sel['perChannel'], $sel['preferredMedium']);
        $frases = DecisionTraceText::explain(['verdict' => 'allow'], $canal);
        $this->assertSame('Se permite el contacto.', $frases[0]);
        $this->assertContains('Se usa el medio que prefirió la persona.', $frases);
    }

    /** D9-2: `Outreach\Core` es PURO. Lo vigila también la guarda de SEC9; esta es la del dueño. */
    public function test_el_nucleo_es_puro(): void
    {
        $permitidas = [
            'App\Services\Telephony\ColombianHolidays', 'App\Services\Telephony\VoiceFrequency', 'Illuminate\Validation\ValidationException',
            'Closure', 'DateTimeImmutable', 'DateTimeInterface', 'DateTimeZone', 'InvalidArgumentException', 'JsonException',
        ];
        $prohibidos = [
            '/\bDB::/', '/\bHttp::/', '/\bLog::/', '/\bCache::/', '/\bStorage::/', '/\bQueue::/', '/\bEvent::/', '/\bBus::/',
            '/\bconfig\(/', '/\benv\(/', '/\bnow\(/', '/\btoday\(/', '/Carbon/', '/\btime\(\)/', '/\bdate\(/', '/\bmicrotime\(/',
            '/\bm?t?_?rand\(/', '/random_(int|bytes)\(/', '/\bapp\(/', '/\brequest\(/', '/\bauth\(/', '/\bsession\(/',
            '/new DateTime(Immutable)?\(\s*\)/', "/new DateTime(Immutable)?\\('now'/", '/\bfile_get_contents\(/', '/\bcurl_/',
            '/\bsleep\(/', '/\bexec\(/', '/\bshell_exec\(/', '/MetaMessagingService|WhatsappOutboxService|ElevenLabs|Twilio/',
        ];
        $ficheros = glob(dirname(__DIR__, 4).'/app/Services/Outreach/Core/*.php');
        $this->assertNotEmpty($ficheros);
        foreach ($ficheros as $fichero) {
            $codigo = (string) file_get_contents($fichero);
            preg_match_all('/^use ([^;]+);$/m', $codigo, $m);
            foreach ($m[1] as $uso) {
                $this->assertContains($uso, $permitidas, basename($fichero)." importa {$uso}.");
            }
            $sinComentarios = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $codigo);
            foreach ($prohibidos as $patron) {
                $this->assertDoesNotMatchRegularExpression($patron, $sinComentarios, basename($fichero)." usa {$patron}.");
            }
            $this->assertStringContainsString('namespace App\Services\Outreach\Core;', $codigo);
            $this->assertMatchesRegularExpression('/^final class \w+/m', $codigo, basename($fichero).' es una clase final');
        }
    }

    /** L9-25: `trace-text-cases.json` es, byte a byte, el export vigente, y cada caso se reproduce con explain() y text(). */
    public function test_trace_text_cases_es_el_export_vigente(): void
    {
        $this->assertFileExists(TraceTextCases::PATH);
        $this->assertSame(TraceTextCases::json(), file_get_contents(TraceTextCases::PATH), 'Regenera con: php tests/Unit/Outreach/Core/tools/export-trace-text-cases.php');
        $f = json_decode((string) file_get_contents(TraceTextCases::PATH), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(DecisionTraceText::export(), $f['dictionary']);
        $this->assertSame(CanonicalJson::hash(DecisionTraceText::export()), $f['dictionaryHash']);
        $this->assertGreaterThanOrEqual(15, count($f['explain']));
        foreach ($f['explain'] as $c) {
            $this->assertSame($c['expected'], DecisionTraceText::explain($c['ladder'], $c['channel']), $c['name']);
        }
        foreach ($f['text'] as $c) {
            $this->assertSame($c['expected'], DecisionTraceText::text($c['code']), $c['code']);
        }
        // Todo código de la escalera y del presupuesto tiene su texto en el diccionario exportado.
        foreach ([...array_merge(...array_values(PolicyLadder::REASON_ORDER)), ...ContactBudget::REASON_ORDER] as $codigo) {
            $this->assertNotNull(DecisionTraceText::text($codigo === 'voice_policy:*' ? 'voice_policy:x' : $codigo), $codigo);
        }
    }
}
