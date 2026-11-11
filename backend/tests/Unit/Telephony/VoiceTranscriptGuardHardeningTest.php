<?php

namespace Tests\Unit\Telephony;

use App\Services\Telephony\VoiceTranscriptAllowlist;
use App\Services\Telephony\VoiceTranscriptGuard;
use App\Services\UltronStudio\StudioVoiceTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * REV8-c M-2, m-1 y m-2 (B8-h, cerrado por el lead): LA GUARDA ENDURECIDA.
 *
 *  - M-2: las negativas que MANDA la plantilla de voz (desde la `/3`, P-M2,
 *    palabra por palabra: «No puedo ofrecerte descuentos ni promociones») no
 *    son violaciones: salen del turno antes de buscar (lista cerrada de
 *    cláusulas exactas, {@see VoiceTranscriptAllowlist}) y una prueba de
 *    PARIDAD exige que cada petición citada siga literal en la plantilla y que
 *    la versión de la plantilla sea la que la lista conoce (la paridad en los
 *    dos sentidos y las 15 negativas naturales, en `VoiceTranscriptRefusalsTest`).
 *    Nada de análisis de negación: una oferta en la misma frase sigue marcando.
 *  - m-1: los huecos de normalización del revisor (tildes combinadas, homoglifos
 *    cirílicos, ancho completo, espacios de ancho cero, palabras partidas) y las
 *    variantes naturales («luquitas», «no te cuesta nada», «gratuitamente», «te
 *    la regalamos», «al día con sus pagos») se cazan; las frases legítimas y las
 *    negativas de la plantilla siguen limpias.
 *  - m-2: si PCRE no puede con el turno, el turno NO pasa por limpio: lleva
 *    `voice_guard_unprocessable` (fallo cerrado). Se demuestra con UTF-8
 *    inválido (null determinista con `/u`); la sonda del revisor (16 KB con el
 *    límite de retroceso) no se puede forzar de forma fiable en el proceso de
 *    pruebas porque el JIT de PCRE no la respeta: mismo camino de código.
 * Pura: sin base, red ni registro.
 */
class VoiceTranscriptGuardHardeningTest extends TestCase
{
    private const AVISO = 'Hola, te habla el asistente virtual de Iron Body Workout, un sistema de inteligencia artificial. Si no quieres recibir más llamadas, dímelo.';

    private const FAMILIA_DESCUENTO = ['machine_reply_invented_discount', 'voice_discount_extra'];

    /** @return list<string> los códigos del turno de la frase (el aviso va delante y no da nada) */
    private static function codigos(string $frase): array
    {
        $turnos = [
            ['index' => 0, 'text' => self::AVISO, 'tools' => [], 'transfers' => [], 'planResults' => []],
            ['index' => 1, 'text' => $frase, 'tools' => [], 'transfers' => [], 'planResults' => []],
        ];
        $violaciones = VoiceTranscriptGuard::inspect($turnos, [], true);
        foreach ($violaciones as $v) {
            self::assertSame(1, $v['turn'], 'el aviso no da nada');
        }

        return array_column($violaciones, 'code');
    }

    // ── M-2 · paridad con la plantilla ─────────────────────────────────────

    public function test_la_lista_permitida_conoce_la_version_de_la_plantilla_y_cada_negativa_citada_sigue_literal(): void
    {
        $this->assertSame(StudioVoiceTemplate::VERSION, VoiceTranscriptAllowlist::TEMPLATE_VERSION, 'si la plantilla cambia de versión, la lista se revisa');
        $fuente = (string) file_get_contents((new ReflectionClass(StudioVoiceTemplate::class))->getFileName());
        foreach (VoiceTranscriptAllowlist::ANCHORS as $clave => $cita) {
            $this->assertStringContainsString($cita, $fuente, "la negativa «{$clave}» ya no está literal en la plantilla: revisa la lista permitida");
        }
        foreach (VoiceTranscriptAllowlist::PHRASES as $frase => $ancla) {
            $this->assertArrayHasKey($ancla, VoiceTranscriptAllowlist::ANCHORS, "«{$frase}» apunta a una negativa que no existe");
        }
        $this->assertNotFalse(preg_match(VoiceTranscriptAllowlist::pattern(), ''), 'la expresión compila');
    }

    public function test_cada_negativa_de_la_lista_pasa_limpia_sola_y_con_su_entrada_y_su_cola(): void
    {
        foreach (array_keys(VoiceTranscriptAllowlist::PHRASES) as $frase) {
            $this->assertSame([], self::codigos(ucfirst($frase).'.'), "«{$frase}» sola");
            $this->assertSame([], self::codigos('Lo siento, pero '.$frase.' por este medio.'), "«{$frase}» con entrada y cola");
            $this->assertSame([], self::codigos(mb_strtoupper($frase).'.'), "«{$frase}» en mayúsculas");
        }
    }

    public function test_una_oferta_en_la_misma_frase_que_la_negativa_sigue_marcando(): void
    {
        $this->assertNotEmpty(array_intersect(self::FAMILIA_DESCUENTO, self::codigos('No puedo ofrecerte descuentos, pero te lo dejo en promoción.')));
        $this->assertNotEmpty(array_intersect(self::FAMILIA_DESCUENTO, self::codigos('No puedo ofrecerte descuentos mayores al diez por ciento.')), 'no es una cláusula exacta de la lista');
        $this->assertNotEmpty(array_intersect(self::FAMILIA_DESCUENTO, self::codigos('¿Quién dijo que no puedo ofrecerte descuentos? Te hago uno ya.')));
    }

    public function test_una_negativa_escrita_con_homoglifos_no_es_de_la_plantilla_y_la_guarda_la_ve(): void
    {
        // «nо» con la о cirílica (U+043E): no es una frase de la plantilla, no se quita, y normalizada la guarda ve «descuentos».
        $this->assertNotEmpty(array_intersect(self::FAMILIA_DESCUENTO, self::codigos("N\u{043E} puedo ofrecerte descuentos.")));
    }

    // ── m-1 · huecos de normalización y variantes ──────────────────────────

    /** @return array<string, array{string, string}> */
    public static function huecos(): array
    {
        return [
            'tilde combinada (NFD)' => ["Tenemos una promocio\u{0301}n para ti.", 'machine_reply_invented_discount'],
            'homoglifo cirílico' => ["Tenemos d\u{0435}scuentos para estudiantes.", 'voice_discount_extra'],
            'ancho completo' => ['Tenemos ｄｅｓｃｕｅｎｔｏｓ para estudiantes.', 'voice_discount_extra'],
            'espacio de ancho cero' => ["Tenemos des\u{200B}cuentos para estudiantes.", 'voice_discount_extra'],
            'palabra partida con guiones' => ['Tenemos des-cuen-tos para estudiantes.', 'voice_discount_extra'],
            'palabra partida con puntos' => ['El primer mes es g.r.a.t.i.s.', 'machine_reply_invented_discount'],
            'luquitas' => ['Ese plan te sale en ochenta luquitas.', 'voice_money_slang'],
            'no te cuesta nada' => ['La valoración no te cuesta nada.', 'voice_discount_extra'],
            'gratuitamente' => ['Te hacemos la valoración gratuitamente.', 'voice_discount_extra'],
            'te la regalamos' => ['La inscripción te la regalamos.', 'voice_discount_extra'],
            'al día con sus pagos (dato ajeno)' => ['Pedro Gómez está al día con sus pagos.', 'voice_member_or_payment_claim'],
            'afirmación en segunda persona (confirma un pago)' => ['Sí, estás al día con tus pagos.', 'voice_member_or_payment_claim'],
        ];
    }

    #[DataProvider('huecos')]
    public function test_los_huecos_del_revisor_se_cazan(string $frase, string $codigo): void
    {
        $this->assertContains($codigo, self::codigos($frase), $frase);
    }

    public function test_las_frases_legitimas_siguen_limpias_con_la_normalizacion_nueva(): void
    {
        foreach ([
            'Tenemos planes mensuales, trimestrales y anuales. ¿Cuál te interesa?',
            'La valoración la hace uno de nuestros entrenadores en la sede.',
            'Entendido, no te volveremos a llamar. Que estés muy bien.',
            'El plan trimestral incluye la valoración inicial.',
            'No tengo esa información; el equipo te la da por WhatsApp.',
            'Con gusto, el equipo te confirma por WhatsApp.',
            'Las barras olímpicas y las paralelas están en la zona de peso libre.',
            // REV8-c (segunda mirada): la condicional no afirma nada sobre los pagos de nadie.
            'Si estás al día con tus pagos, puedes congelar el plan desde la app.',
            'Cuando estés al día con tus pagos, el equipo te activa el acceso.',
        ] as $frase) {
            $this->assertSame([], self::codigos($frase), $frase);
        }
    }

    // ── m-2 · fallo cerrado ────────────────────────────────────────────────

    public function test_si_pcre_no_puede_con_el_turno_la_guarda_falla_cerrada(): void
    {
        // UTF-8 inválido en el turno: con `/u`, PCRE devuelve null (PREG_BAD_UTF8_ERROR) pase lo que pase con el JIT.
        $turnos = [
            ['index' => 0, 'text' => self::AVISO, 'tools' => [], 'transfers' => [], 'planResults' => []],
            ['index' => 1, 'text' => "Tenemos descuentos \xC3\x28 para estudiantes.", 'tools' => [], 'transfers' => [], 'planResults' => []],
        ];
        $violaciones = VoiceTranscriptGuard::inspect($turnos, [], true);
        $delTurno = array_column(array_filter($violaciones, static fn (array $v): bool => $v['turn'] === 1), 'code');
        $this->assertContains(VoiceTranscriptGuard::CODE_UNPROCESSABLE, $delTurno, 'el turno que PCRE no puede evaluar no pasa por limpio');
        $this->assertSame([], array_column(array_filter($violaciones, static fn (array $v): bool => $v['turn'] === 0), 'code'), 'el aviso, bien formado, no da nada');
    }
}
