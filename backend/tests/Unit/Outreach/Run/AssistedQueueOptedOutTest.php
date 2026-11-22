<?php

namespace Tests\Unit\Outreach\Run;

use App\Services\Outreach\Facts\SubjectFacts;
use App\Services\Outreach\Run\AssistedQueue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * N-1, R3-1 y R3-3 de los informes de security de la bandeja, la pieza PURA, con la entrada de N1/N2 de la escalera
 * (`CoreProjection::policyInput()`) sobre una foto hecha a mano, incluidos los casos que los adaptadores de hoy no
 * producen (un valor de política o un hecho ausente):
 *  - `optedOut()`: ¿se SABE que la persona pidió que no la contacten (`true`), se sabe que no (`false`) o no se
 *    puede comprobar (`null`, la bandeja retiene con `policy_unknown`)?
 *  - `ineligible()` (R3-3): ¿la niega N1 por elegibilidad (`subject_minor`, `rne_check_missing`), no (`false`) o
 *    no se puede comprobar (`null`)?
 *
 * La persona LIMPIA es la que hoy se puede contactar por los dos medios: el consentimiento de voz concedido, una
 * consulta al RNE vigente, contacto de voz y adulta. Hasta R3-1 era una persona SIN consentimiento de voz y con el
 * RNE sin consulta vigente (la bandeja no miraba ninguna de las dos cosas); R3-1 y la directiva del propietario del
 * 21-11-2026 (R3-3) las hacen retener, así que esa persona ya no está limpia por voz.
 */
class AssistedQueueOptedOutTest extends TestCase
{
    /** La política de una persona resuelta sin ninguna señal (lo que dan hoy `LeadFacts` y `VoiceConsentFacts`). */
    private const LIMPIA = [
        'lead.do_not_contact' => false, 'lead.do_not_contact_sibling' => false, 'lead.commercial_opt_out' => false, 'lead.consent_denied' => false,
        'consent.whatsapp.commercial' => true, 'consent.whatsapp.service' => false, 'consent.whatsapp.collections' => false,
        'consent.voice_call.commercial' => true, 'consent.voice_call.service' => false, 'consent.voice_call.collections' => false,
        'voice.excluded' => false, 'voice.rne_check_missing' => false, 'voice.consent_revoked' => false,
    ];

    /** Los hechos de N1 que pide la bandeja, de una persona adulta con contacto de voz. */
    private const HECHOS = ['subject.has_voice_contact' => true, 'subject.is_minor' => false];

    /**
     * @param  array<string, bool>|null  $policy  null = sin foto de política; las claves ausentes, desconocidas
     * @param  array<string, bool|null>  $facts  las claves ausentes, no pedidas; null, desconocido
     */
    private static function persona(?array $policy, bool $resolved = true, array $facts = self::HECHOS): SubjectFacts
    {
        return new SubjectFacts(
            key: 'm:7', canonicalKey: $resolved ? 'm:7' : null, ref: str_repeat('a', 64), facts: $facts,
            policy: $policy, events: [], contacts: $resolved ? [] : null,
        );
    }

    private static function optedOut(?SubjectFacts $s, string $purpose, string $medium): ?bool
    {
        return (new ReflectionMethod(AssistedQueue::class, 'optedOut'))->invoke(null, $s, $purpose, $medium);
    }

    private static function ineligible(?SubjectFacts $s, string $purpose, string $medium): string|false|null
    {
        return (new ReflectionMethod(AssistedQueue::class, 'ineligible'))->invoke(null, $s, $purpose, $medium);
    }

    /** @return array<string, array{0: ?array<string, bool>, 1: string, 2: string, 3: ?bool, 4?: bool}> */
    public static function casos(): array
    {
        $sin = static fn (string ...$claves): array => array_diff_key(self::LIMPIA, array_flip($claves));

        return [
            'limpia por WhatsApp: se sabe que no' => [self::LIMPIA, 'commercial', 'whatsapp', false],
            'limpia por voz: se sabe que no' => [self::LIMPIA, 'commercial', 'voice', false],
            'do_not_contact' => [['lead.do_not_contact' => true] + self::LIMPIA, 'commercial', 'whatsapp', true],
            'do_not_contact de un hermano' => [['lead.do_not_contact_sibling' => true] + self::LIMPIA, 'service', 'whatsapp', true],
            'opt-out comercial en comercial' => [['lead.commercial_opt_out' => true] + self::LIMPIA, 'commercial', 'whatsapp', true],
            'opt-out comercial en servicio: no aplica (N1)' => [['lead.commercial_opt_out' => true] + self::LIMPIA, 'service', 'whatsapp', false],
            'consentimiento denegado, por WhatsApp' => [['lead.consent_denied' => true] + self::LIMPIA, 'commercial', 'whatsapp', true],
            'consentimiento denegado, por voz' => [['lead.consent_denied' => true] + self::LIMPIA, 'commercial', 'voice', true],
            'exclusión de voz en un trabajo por voz' => [['voice.excluded' => true] + self::LIMPIA, 'commercial', 'voice', true],
            'exclusión de voz en un trabajo por WhatsApp: no aplica' => [['voice.excluded' => true] + self::LIMPIA, 'commercial', 'whatsapp', false],
            // R3-1: revocar (o el contacto desactivado) deja el consentimiento de voz en `missing`; N1 lo niega y la foto no dice cuál de los dos.
            'R3-1: voz sin el consentimiento concedido y sin revocación que conste (contacto retirado): no se sabe (retiene)' => [['consent.voice_call.commercial' => false] + self::LIMPIA, 'commercial', 'voice', null],
            'R3-1: voz revocada (consta: voice.consent_revoked): lo pidió' => [['consent.voice_call.commercial' => false, 'voice.consent_revoked' => true] + self::LIMPIA, 'commercial', 'voice', true],
            'R3-1: la revocación sin leer: no se sabe' => [['consent.voice_call.commercial' => false] + $sin('voice.consent_revoked'), 'commercial', 'voice', null],
            // Otro contacto activo la mantiene concedida: N1 no niega y la bandeja tampoco (la revocación de un número no es de la persona entera).
            'R3-1: revocó en un contacto y otro la tiene concedida: no cuenta' => [['voice.consent_revoked' => true] + self::LIMPIA, 'commercial', 'voice', false],
            'R3-1: la revocación de voz no cuenta en un trabajo por WhatsApp' => [['consent.voice_call.commercial' => false, 'voice.consent_revoked' => true] + self::LIMPIA, 'commercial', 'whatsapp', false],
            'R3-1: voz con el consentimiento sin leer: no se sabe' => [$sin('consent.voice_call.commercial'), 'commercial', 'voice', null],
            'R3-1: la voz sin consentimiento no cuenta en un trabajo por WhatsApp' => [['consent.voice_call.commercial' => false] + self::LIMPIA, 'commercial', 'whatsapp', false],
            // R3-1 NO se extiende a WhatsApp: el de servicio depende de la ventana de 24 h (`LeadFacts`) y retendría trabajos válidos.
            'R3-1: WhatsApp de servicio fuera de la ventana de 24 h: no cuenta' => [['consent.whatsapp.service' => false] + self::LIMPIA, 'service', 'whatsapp', false],
            'R3-1: WhatsApp comercial sin consentimiento: no cuenta' => [['consent.whatsapp.commercial' => false] + self::LIMPIA, 'commercial', 'whatsapp', false],
            'R3-1: un sí sabido gana a la voz sin consentimiento' => [['lead.do_not_contact' => true, 'consent.voice_call.commercial' => false] + self::LIMPIA, 'commercial', 'voice', true],
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
        $this->assertNull(self::ineligible(null, 'commercial', 'voice'));
    }

    /** @return array<string, array{0: ?array<string, bool>, 1: array<string, bool|null>, 2: string, 3: string, 4: string|false|null, 5?: bool}> */
    public static function elegibilidad(): array
    {
        $sinPolitica = static fn (string ...$claves): array => array_diff_key(self::LIMPIA, array_flip($claves));
        $sinHecho = static fn (string ...$claves): array => array_diff_key(self::HECHOS, array_flip($claves));
        $rneCaducado = ['voice.rne_check_missing' => true] + self::LIMPIA;

        return [
            'limpia por voz' => [self::LIMPIA, self::HECHOS, 'commercial', 'voice', false],
            'limpia por WhatsApp' => [self::LIMPIA, self::HECHOS, 'commercial', 'whatsapp', false],
            // `subject_minor`: en cualquier medio y finalidad, como N1.
            'menor, por WhatsApp' => [self::LIMPIA, ['subject.is_minor' => true] + self::HECHOS, 'commercial', 'whatsapp', 'subject_minor'],
            'menor, por voz' => [self::LIMPIA, ['subject.is_minor' => true] + self::HECHOS, 'commercial', 'voice', 'subject_minor'],
            'menor, en servicio' => [self::LIMPIA, ['subject.is_minor' => true] + self::HECHOS, 'service', 'whatsapp', 'subject_minor'],
            // La escalera solo niega `isMinor === true`: la edad no se infiere (D9-15); un prospecto sin ficha nunca la tiene.
            'edad desconocida: no niega, como la escalera' => [self::LIMPIA, ['subject.is_minor' => null] + self::HECHOS, 'commercial', 'whatsapp', false],
            'edad no pedida: no niega, como la escalera' => [self::LIMPIA, $sinHecho('subject.is_minor'), 'commercial', 'voice', false],
            // `rne_check_missing`: solo por voz (N1).
            'RNE caducado, por voz' => [$rneCaducado, self::HECHOS, 'commercial', 'voice', 'rne_check_missing'],
            'RNE caducado, por WhatsApp: no aplica' => [$rneCaducado, self::HECHOS, 'commercial', 'whatsapp', false],
            'RNE sin leer, por voz: no se sabe' => [$sinPolitica('voice.rne_check_missing'), self::HECHOS, 'commercial', 'voice', null],
            'sin contacto de voz activo, por voz: no se sabe (la consulta no vale sin él)' => [self::LIMPIA, ['subject.has_voice_contact' => false] + self::HECHOS, 'commercial', 'voice', null],
            'el contacto de voz sin leer, por voz: no se sabe' => [self::LIMPIA, $sinHecho('subject.has_voice_contact'), 'commercial', 'voice', null],
            'sin foto de política, por voz: no se sabe' => [null, self::HECHOS, 'commercial', 'voice', null],
            'sin foto de política, por WhatsApp: no niega por esto (lo dice optedOut)' => [null, self::HECHOS, 'commercial', 'whatsapp', false],
            'un menor sabido gana al RNE desconocido' => [$sinPolitica('voice.rne_check_missing'), ['subject.is_minor' => true] + self::HECHOS, 'commercial', 'voice', 'subject_minor'],
            'medio fuera del conjunto: no se sabe' => [self::LIMPIA, self::HECHOS, 'commercial', 'sms', null],
            'persona sin resolver: no se sabe' => [self::LIMPIA, self::HECHOS, 'commercial', 'voice', null, false],
        ];
    }

    /**
     * @param  array<string, bool>|null  $policy
     * @param  array<string, bool|null>  $facts
     */
    #[DataProvider('elegibilidad')]
    public function test_r3_3_la_elegibilidad_que_niega_n1(?array $policy, array $facts, string $purpose, string $medium, string|false|null $esperado, bool $resolved = true): void
    {
        $this->assertSame($esperado, self::ineligible(self::persona($policy, $resolved, $facts), $purpose, $medium));
    }
}
