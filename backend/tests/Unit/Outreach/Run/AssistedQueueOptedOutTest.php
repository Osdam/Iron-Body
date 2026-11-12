<?php

namespace Tests\Unit\Outreach\Run;

use App\Services\Outreach\Facts\SubjectFacts;
use App\Services\Outreach\Run\AssistedQueue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * N-1 de `informe-security-bandeja-condiciones.md`, la pieza PURA: ¿se SABE que la persona pidió que no la
 * contacten (`true`), se sabe que no (`false`) o no se puede comprobar (`null`, la bandeja retiene con
 * `policy_unknown`)? Con la entrada de N1/N2 de la escalera (`CoreProjection::policyInput()`) sobre una foto
 * hecha a mano, incluidos los casos que los adaptadores de hoy no producen (un valor de política ausente).
 */
class AssistedQueueOptedOutTest extends TestCase
{
    /** La política de una persona resuelta sin ninguna señal (lo que dan hoy `LeadFacts` y `VoiceConsentFacts`). */
    private const LIMPIA = [
        'lead.do_not_contact' => false, 'lead.do_not_contact_sibling' => false, 'lead.commercial_opt_out' => false, 'lead.consent_denied' => false,
        'consent.whatsapp.commercial' => true, 'consent.whatsapp.service' => false, 'consent.whatsapp.collections' => false,
        'consent.voice_call.commercial' => false, 'consent.voice_call.service' => false, 'consent.voice_call.collections' => false,
        'voice.excluded' => false, 'voice.rne_check_missing' => true,
    ];

    /**
     * @param  array<string, bool>|null  $policy  null = sin foto de política; las claves ausentes, desconocidas
     */
    private static function persona(?array $policy, bool $resolved = true): SubjectFacts
    {
        return new SubjectFacts(
            key: 'm:7', canonicalKey: $resolved ? 'm:7' : null, ref: str_repeat('a', 64), facts: [],
            policy: $policy, events: [], contacts: $resolved ? [] : null,
        );
    }

    private static function optedOut(?SubjectFacts $s, string $purpose, string $medium): ?bool
    {
        return (new ReflectionMethod(AssistedQueue::class, 'optedOut'))->invoke(null, $s, $purpose, $medium);
    }

    /** @return array<string, array{0: ?array<string, bool>, 1: string, 2: string, 3: ?bool, 4?: bool}> */
    public static function casos(): array
    {
        $sin = static fn (string ...$claves): array => array_diff_key(self::LIMPIA, array_flip($claves));

        return [
            'limpia: se sabe que no' => [self::LIMPIA, 'commercial', 'whatsapp', false],
            'do_not_contact' => [['lead.do_not_contact' => true] + self::LIMPIA, 'commercial', 'whatsapp', true],
            'do_not_contact de un hermano' => [['lead.do_not_contact_sibling' => true] + self::LIMPIA, 'service', 'whatsapp', true],
            'opt-out comercial en comercial' => [['lead.commercial_opt_out' => true] + self::LIMPIA, 'commercial', 'whatsapp', true],
            'opt-out comercial en servicio: no aplica (N1)' => [['lead.commercial_opt_out' => true] + self::LIMPIA, 'service', 'whatsapp', false],
            'consentimiento denegado, por WhatsApp' => [['lead.consent_denied' => true] + self::LIMPIA, 'commercial', 'whatsapp', true],
            'consentimiento denegado, por voz' => [['lead.consent_denied' => true] + self::LIMPIA, 'commercial', 'voice', true],
            'exclusión de voz en un trabajo por voz' => [['voice.excluded' => true] + self::LIMPIA, 'commercial', 'voice', true],
            'exclusión de voz en un trabajo por WhatsApp: no aplica' => [['voice.excluded' => true] + self::LIMPIA, 'commercial', 'whatsapp', false],
            'el RNE sin consulta vigente no es una petición de la persona' => [['voice.rne_check_missing' => true] + self::LIMPIA, 'commercial', 'voice', false],
            'sin foto de política: no se sabe' => [null, 'commercial', 'whatsapp', null],
            'do_not_contact desconocido: no se sabe' => [$sin('lead.do_not_contact'), 'commercial', 'whatsapp', null],
            'hermano desconocido: no se sabe' => [$sin('lead.do_not_contact_sibling'), 'commercial', 'whatsapp', null],
            'opt-out comercial desconocido en comercial: no se sabe' => [$sin('lead.commercial_opt_out'), 'commercial', 'whatsapp', null],
            'opt-out comercial desconocido en servicio: no cuenta' => [$sin('lead.commercial_opt_out'), 'service', 'whatsapp', false],
            // La escalera lo da por `denied` (falla cerrado); la bandeja retiene igual, pero sin decir que lo pidió.
            'consentimiento denegado desconocido: no se sabe' => [$sin('lead.consent_denied'), 'commercial', 'whatsapp', null],
            'exclusión de voz desconocida, por voz: no se sabe' => [$sin('voice.excluded'), 'commercial', 'voice', null],
            'un sí sabido gana a un desconocido' => [['lead.do_not_contact' => true] + $sin('lead.do_not_contact_sibling'), 'commercial', 'whatsapp', true],
            'medio fuera del conjunto: no se sabe' => [self::LIMPIA, 'commercial', 'sms', null],
            'persona sin resolver: no se sabe' => [self::LIMPIA, 'commercial', 'whatsapp', null, false],
        ];
    }

    /** @param  array<string, bool>|null  $policy */
    #[DataProvider('casos')]
    public function test_la_senal_de_no_contactar_de_la_bandeja(?array $policy, string $purpose, string $medium, ?bool $esperado, bool $resolved = true): void
    {
        $this->assertSame($esperado, self::optedOut(self::persona($policy, $resolved), $purpose, $medium));
    }

    public function test_sin_foto_de_la_persona_no_se_sabe(): void
    {
        $this->assertNull(self::optedOut(null, 'commercial', 'whatsapp'));
    }
}
