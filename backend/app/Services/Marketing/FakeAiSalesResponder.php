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
    /**
     * @var array<string, string[]> intent => palabras clave (sin acentos).
     * El ORDEN importa: primero lo sensible (opt-out, médico, fraude, reclamo,
     * pedir humano), luego cierre/pago, luego objeciones, luego objetivos, luego
     * preguntas informativas. Lo genérico (general_info) queda al final.
     */
    private const RULES = [
        SalesIntents::DO_NOT_CONTACT_REQUEST => [
            'no me escriban', 'no quiero mensajes', 'no me contacten', 'dejen de escribir',
            'no me manden mensajes', 'eliminen mi numero', 'no me vuelvan a escribir',
        ],
        SalesIntents::MEDICAL_RISK_ESCALATION => [
            'tengo lesion', 'lesion', 'dolor', 'me duele', 'enfermedad', 'enfermo',
            'medico', 'cirugia', 'hernia', 'embarazada', 'lesionado', 'fractura',
            'operacion', 'operado', 'mareo', 'desmayo', 'presion alta', 'diabetes',
            'problema cardiaco', 'del corazon', 'medicacion', 'medicamento',
        ],
        SalesIntents::FRAUD_OR_PAYMENT_CLAIM => [
            'me dijeron que era gratis', 'era gratis', 'activame y luego pago',
            'actívame y luego pago', 'ya pague', 'ya pagué', 'pago y no me activan',
            'me activan ya', 'descuento especial', 'hazme descuento',
        ],
        SalesIntents::COMPLAINT => [
            'pesimo', 'pésimo', 'mal servicio', 'muy mal', 'estoy molesto', 'estoy inconforme',
            'una queja', 'poner una queja', 'me trataron mal', 'que mal servicio',
        ],
        SalesIntents::INVOICE_REQUEST => [
            'factura', 'facturacion', 'facturación', 'necesito factura', 'datos de factura',
            'correo de factura', 'nit', 'dian',
        ],
        SalesIntents::HUMAN_REQUEST => [
            'quiero hablar con alguien', 'hablar con alguien', 'hablar con una persona',
            'hablar con un asesor', 'hablar con un humano', 'me comunican con', 'atencion humana',
            'atención humana', 'una persona me atiende', 'hablar con un agente', 'pasame con alguien',
        ],
        SalesIntents::GOODBYE => [
            'chao', 'adios', 'adiós', 'hasta luego', 'nos vemos', 'me voy', 'bye',
        ],
        SalesIntents::NOT_INTERESTED => [
            'ya no quiero', 'no me interesa', 'no gracias', 'no me sirve', 'no quiero nada',
            'despues miro', 'después miro', 'luego miro', 'mejor no',
        ],
        SalesIntents::THANKS => [
            'gracias', 'muchas gracias', 'vale gracias',
        ],
        SalesIntents::BOT_QUESTION => [
            'eres un bot', 'eres bot', 'eres una ia', 'eres ia', 'es un bot', 'eres real',
            'eres humano', 'eres una persona', 'hablo con una persona', 'hablo con un humano',
        ],
        SalesIntents::PAYMENT_LINK_REQUEST => [
            'no quiero pagar por la app', 'mandame link', 'mándame link', 'link de pago',
            'pagar por whatsapp', 'enviame el link', 'envíame el link', 'pasame el link',
            'pásame el link', 'link para pagar', 'quiero pagar el mensual', 'quiero pagar',
            'como pago', 'cómo pago', 'donde pago', 'dónde pago', 'medio de pago',
        ],
        SalesIntents::HIGH_INTENT_CLOSE => [
            'quiero empezar hoy', 'me inscribo', 'pago ya', 'quiero inscribirme',
            'como me inscribo', 'cómo me inscribo', 'quiero empezar ya', 'arranco hoy',
            'me interesa iniciar', 'quiero el mensual', 'hagamosle', 'hagámosle',
        ],
        SalesIntents::INSECURITY_BODY => [
            'estoy gordo', 'estoy gorda', 'muy gordo', 'muy gorda', 'me veo mal',
            'me siento horrible', 'horrible con mi cuerpo', 'sobrepeso', 'fuera de forma',
            'me da pena que me miren', 'pena que me miren', 'me da pena porque', 'verguenza',
            'vergüenza', 'me siento muy mal con mi cuerpo',
        ],
        SalesIntents::DELAY_OBJECTION => [
            'lo pienso', 'lo voy a pensar', 'lo tengo que pensar', 'despues voy', 'después voy',
            'mas adelante', 'más adelante', 'luego voy', 'despues paso', 'después paso',
            'yo le aviso', 'yo aviso', 'lo pensare', 'lo pensaré',
        ],
        SalesIntents::PRICE_OBJECTION => [
            'esta caro', 'está caro', 'muy caro', 'carisimo', 'carísimo', 'esta costoso',
            'está costoso', 'no me alcanza', 'mucha plata', 'no tengo plata', 'mas barato',
            'más barato', 'en otro gym', 'esta caro chao',
        ],
        SalesIntents::TIME_OBJECTION => [
            'no tengo tiempo', 'no me queda tiempo', 'estoy muy ocupado', 'trabajo mucho',
            'no tengo cuando', 'no tengo cuándo', 'me queda lejos el tiempo',
        ],
        SalesIntents::BEGINNER_FEAR => [
            'me da pena', 'me da miedo', 'nunca he entrenado', 'nunca he ido a un gym',
            'soy principiante', 'no se nada', 'no sé nada', 'pena empezar', 'primera vez',
            'nunca he hecho ejercicio', 'me da cosa', 'no se usar maquinas', 'no sé usar máquinas',
            'hace años no entreno', 'hace anos no entreno',
        ],
        SalesIntents::GOAL_FAT_LOSS => [
            'bajar barriga', 'quitar barriga', 'bajar grasa', 'bajar de peso', 'adelgazar',
            'quemar grasa', 'rebajar', 'perder peso', 'bajar la panza', 'quemar barriga',
        ],
        SalesIntents::GOAL_MUSCLE_GAIN => [
            'ganar masa', 'masa muscular', 'ganar musculo', 'ganar músculo', 'aumentar masa',
            'ponerme fuerte', 'subir de masa', 'crecer musculo', 'volumen muscular', 'ganar fuerza',
        ],
        SalesIntents::PRICING_QUESTION => [
            'precio', 'cuanto vale', 'cuánto vale', 'mensualidad', 'cuanto cuesta',
            'cuánto cuesta', 'valor', 'cuanto es', 'tarifa', 'planes', 'que planes',
            'qué planes',
        ],
        SalesIntents::LOCATION_QUESTION => [
            'donde quedan', 'dónde quedan', 'ubicacion', 'ubicación', 'direccion',
            'dirección', 'donde estan', 'dónde están', 'como llego', 'cómo llego',
            'donde estan ubicados', 'donde queda',
        ],
        SalesIntents::SCHEDULE_QUESTION => [
            'horarios', 'horario', 'a que hora', 'a qué hora', 'que horas', 'qué horas',
            'abren', 'cierran', 'hasta que hora', 'hasta qué hora',
        ],
        SalesIntents::GREETING => [
            'hola', 'buenas', 'buenos dias', 'buenos días', 'buenas tardes', 'buenas noches',
            'que mas', 'qué más', 'buen dia', 'buen día',
        ],
        SalesIntents::GENERAL_INFO => [
            'informacion', 'información', 'quiero saber', 'me interesa', 'cuentame', 'cuéntame',
            'que ofrecen', 'qué ofrecen', 'como funciona', 'cómo funciona', 'que incluye',
            'qué incluye', 'que hay', 'mas info', 'más info',
        ],
    ];

    /** @var array<string, string[]> objetivo declarado → keywords. */
    private const OBJECTIVE_KEYWORDS = [
        'fat_loss'      => ['bajar barriga', 'quitar barriga', 'bajar grasa', 'bajar de peso', 'adelgazar', 'quemar grasa', 'rebajar', 'perder peso'],
        'muscle_gain'   => ['ganar masa', 'masa muscular', 'ganar musculo', 'ganar músculo', 'volumen', 'aumentar masa', 'ponerme fuerte'],
        'conditioning'  => ['condicion', 'condición', 'resistencia', 'cardio'],
        'return'        => ['volver a entrenar', 'retomar', 'volver al gym'],
    ];

    public function classify(string $body, array $context = []): array
    {
        $text = $this->normalize($body);

        // Spam / baja calidad: mensaje vacío, sin letras o demasiado corto.
        if ($this->looksLikeSpam($text)) {
            return [
                'intent' => SalesIntents::SPAM_LOW_QUALITY, 'confidence' => 0.6,
                'extracted_fields' => [], 'missing_fields' => [],
            ];
        }

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

    /** Heurística mínima de spam: vacío, un solo carácter o sin ninguna palabra. */
    private function looksLikeSpam(string $text): bool
    {
        $clean = trim($text);
        if ($clean === '' || mb_strlen($clean) <= 1) {
            return true;
        }
        // Sin ninguna secuencia de letras (solo emojis / signos / números sueltos).
        return preg_match('/[a-z]{2,}/', $clean) !== 1;
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
