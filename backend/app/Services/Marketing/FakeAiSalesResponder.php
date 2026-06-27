<?php

namespace App\Services\Marketing;

use App\Services\Marketing\Contracts\AiSalesResponderInterface;

/**
 * Responder DETERMINISTA por reglas de palabras clave (sin OpenAI). Cubre los
 * casos comerciales frecuentes de Iron Body. Es la implementación por defecto:
 * 100% offline, testeable y segura. NO inventa precios ni ejecuta acciones.
 *
 * El orden de evaluación importa: primero lo sensible (médico, fraude, opt-out),
 * luego intención de cierre/pago, luego objeción, luego preguntas informativas.
 */
class FakeAiSalesResponder implements AiSalesResponderInterface
{
    /** @var array<string, string[]> intent => palabras clave (sin acentos). */
    private const RULES = [
        SalesIntents::DO_NOT_CONTACT_REQUEST => [
            'no me escriban', 'no quiero mensajes', 'no me contacten', 'dejen de escribir',
            'no me manden mensajes', 'eliminen mi numero', 'no me vuelvan a escribir',
        ],
        SalesIntents::MEDICAL_RISK_ESCALATION => [
            'tengo lesion', 'lesion', 'dolor', 'me duele', 'enfermedad', 'enfermo',
            'medico', 'cirugia', 'hernia', 'embarazada', 'lesionado', 'fractura',
        ],
        SalesIntents::FRAUD_OR_PAYMENT_CLAIM => [
            'me dijeron que era gratis', 'era gratis', 'activame y luego pago',
            'actívame y luego pago', 'ya pague', 'ya pagué', 'pago y no me activan',
            'me activan ya', 'descuento especial', 'hazme descuento',
        ],
        SalesIntents::PAYMENT_LINK_REQUEST => [
            'no quiero pagar por la app', 'mandame link', 'mándame link', 'link de pago',
            'pagar por whatsapp', 'enviame el link', 'envíame el link', 'pasame el link',
            'pásame el link', 'link para pagar',
        ],
        SalesIntents::HIGH_INTENT_CLOSE => [
            'quiero empezar hoy', 'me inscribo', 'pago ya', 'quiero inscribirme',
            'como me inscribo', 'cómo me inscribo', 'quiero empezar ya', 'arranco hoy',
        ],
        SalesIntents::PRICE_OBJECTION => [
            'esta caro', 'está caro', 'muy caro', 'carisimo', 'carísimo', 'esta costoso',
            'está costoso', 'no me alcanza', 'mucha plata',
        ],
        SalesIntents::PRICING_QUESTION => [
            'precio', 'cuanto vale', 'cuánto vale', 'mensualidad', 'cuanto cuesta',
            'cuánto cuesta', 'valor', 'cuanto es', 'tarifa', 'planes',
        ],
        SalesIntents::LOCATION_QUESTION => [
            'donde quedan', 'dónde quedan', 'ubicacion', 'ubicación', 'direccion',
            'dirección', 'donde estan', 'dónde están', 'como llego', 'cómo llego',
        ],
        SalesIntents::SCHEDULE_QUESTION => [
            'horarios', 'horario', 'a que hora', 'a qué hora', 'que horas', 'qué horas',
            'abren', 'cierran', 'hasta que hora', 'hasta qué hora',
        ],
    ];

    /** @var array<string, string[]> objetivo declarado → keywords. */
    private const OBJECTIVE_KEYWORDS = [
        'fat_loss'      => ['bajar grasa', 'bajar de peso', 'adelgazar', 'quemar grasa', 'rebajar'],
        'muscle_gain'   => ['masa muscular', 'ganar musculo', 'ganar músculo', 'volumen', 'aumentar masa'],
        'conditioning'  => ['condicion', 'condición', 'resistencia', 'cardio'],
        'return'        => ['volver a entrenar', 'retomar', 'volver al gym'],
    ];

    public function classify(string $body, array $context = []): array
    {
        $text = $this->normalize($body);
        $intent = SalesIntents::UNKNOWN;
        $confidence = 0.3;

        foreach (self::RULES as $candidate => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($text, $kw)) {
                    $intent = $candidate;
                    // Más confianza para frases largas/específicas.
                    $confidence = str_word_count($kw) >= 3 ? 0.92 : 0.8;
                    break 2;
                }
            }
        }

        $extracted = $this->extractFields($text);
        $missing = [];
        if ($intent === SalesIntents::PRICING_QUESTION && ! isset($extracted['objective'])) {
            $missing[] = 'objective';
        }

        return [
            'intent'           => $intent,
            'confidence'       => $confidence,
            'extracted_fields' => $extracted,
            'missing_fields'   => $missing,
        ];
    }

    public function name(): string
    {
        return 'fake';
    }

    /** Extrae campos simples (objetivo fitness declarado). */
    private function extractFields(string $text): array
    {
        foreach (self::OBJECTIVE_KEYWORDS as $objective => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($text, $kw)) {
                    return ['objective' => $objective];
                }
            }
        }
        return [];
    }

    /** minúsculas + sin acentos para un match robusto. */
    private function normalize(string $body): string
    {
        $lower = mb_strtolower(trim($body));
        return strtr($lower, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }
}
