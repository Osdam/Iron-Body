<?php

namespace App\Services\Telephony;

use App\Models\MarketingMessage;
use App\Services\Marketing\OutboundContentGuard;

/**
 * La guarda POSTERIOR de la voz (contrato H8, D8-10 capa 4; fixture
 * `elevenlabs-webhook-bodies.json` §guard). El ÚNICO adaptador de la
 * telefonía hacia la guarda del motor: solo `OutboundContentGuard::inspect()`
 * (pura, auditoría SEC8 A-9), con el autor de máquina; nunca `assertSafe`, que
 * registra. No escribe ni registra nada: devuelve CÓDIGOS y números de turno,
 * y quien la llama guarda solo eso.
 *
 * Cada turno del AGENTE (los de la persona no se juzgan), con su índice en la
 * transcripción (DF-E11):
 *  - el primero tiene que llevar el aviso [P-9, E1-3]: que atiende un
 *    asistente virtual, que es inteligencia artificial y cómo pedir no recibir
 *    más llamadas (`voice_notice_missing`);
 *  - P-3: las cifras que devolvió `consultar_planes` EN ESA LLAMADA se tapan
 *    con la expresión monetaria entera ANTES de inspeccionar (tapar después
 *    ensombrecería los motivos de detrás: hallazgo A-9 de SEC8); una cifra
 *    que sigue ahí es `voice_money_not_from_tool`;
 *  - el traspaso a una persona solo se admite en el turno que transfiere;
 *  - cualquier otro código del motor se guarda tal cual;
 *  - cada destino pedido a `transfer_to_number` tiene que ser uno aprobado
 *    (su huella `voice-phone`); sin la lista de aprobados, ninguno lo es:
 *    `voice_transfer_not_approved` (imposible por configuración, se
 *    comprueba igual).
 */
final class VoiceTranscriptGuard
{
    public const CODE_NOTICE_MISSING = 'voice_notice_missing';

    public const CODE_MONEY_NOT_FROM_TOOL = 'voice_money_not_from_tool';

    public const CODE_TRANSFER_NOT_APPROVED = 'voice_transfer_not_approved';

    public const TRANSFER_TOOL = 'transfer_to_number';

    /** Lo que el aviso tiene que decir (D8-17), sin tildes ni mayúsculas. */
    public const NOTICE_ELEMENTS = [['asistente virtual'], ['inteligencia artificial'], ['no quieres recibir mas llamadas', 'no recibir mas llamadas']];

    /** Una cantidad de dinero hablada: «$ 89.000», «89.000 pesos», «COP 89000»… */
    private const MONEY = '/(?:\$\s?|COP\s?)?\d{1,3}(?:[.,\s]\d{3})+(?:,\d{1,2})?(?:\s?(?:pesos|COP))?|(?:\$\s?|COP\s?)\d+|\d{4,}\s?(?:pesos|COP)/iu';

    private const MASK = '[precio del catálogo]';

    /**
     * @param  list<array{index:int, text:string, tools:list<string>, transfers:list<string>, planResults:list<string>}>  $turns  los del agente
     * @param  list<string>|null  $approvedTransferHashes  null = la lista no está disponible (fallo cerrado)
     * @return list<array{turn:int, code:string}>
     */
    public static function inspect(array $turns, ?array $approvedTransferHashes, bool $pricesFromToolAllowed = true): array
    {
        $guarda = new OutboundContentGuard;
        $violaciones = [];
        $cifras = [];
        $primero = true;

        foreach ($turns as $turno) {
            foreach ($turno['planResults'] as $resultado) {
                foreach (self::moneyDigits($resultado) as $d) {
                    $cifras[$d] = true;
                }
            }
            if ($primero) {
                $primero = false;
                if (! self::hasNotice($turno['text'])) {
                    $violaciones[] = ['turn' => $turno['index'], 'code' => self::CODE_NOTICE_MISSING];
                }
                // Y se inspecciona como los demás: el modelo pudo seguir hablando en el mismo turno.
            }

            $texto = $pricesFromToolAllowed ? self::maskApproved($turno['text'], $cifras) : $turno['text'];
            $transfiere = in_array(self::TRANSFER_TOOL, $turno['tools'], true);
            $veredicto = $guarda->inspect($texto, MarketingMessage::SENDER_AI, $transfiere);
            if (($veredicto['safe'] ?? true) !== true && is_string($veredicto['code'] ?? null)) {
                $codigo = $veredicto['code'] === OutboundContentGuard::CODE_INVENTED_PRICE ? self::CODE_MONEY_NOT_FROM_TOOL : $veredicto['code'];
                $violaciones[] = ['turn' => $turno['index'], 'code' => $codigo];
            }

            foreach ($turno['transfers'] as $destino) {
                $huella = VoicePhone::isE164($destino) ? VoicePhone::hash($destino) : null;
                if ($huella === null || $approvedTransferHashes === null || ! in_array($huella, $approvedTransferHashes, true)) {
                    $violaciones[] = ['turn' => $turno['index'], 'code' => self::CODE_TRANSFER_NOT_APPROVED];
                    break;
                }
            }
        }

        return $violaciones;
    }

    public static function hasNotice(string $text): bool
    {
        $t = self::fold($text);
        foreach (self::NOTICE_ELEMENTS as $alternativas) {
            $esta = false;
            foreach ($alternativas as $frase) {
                $esta = $esta || str_contains($t, $frase);
            }
            if (! $esta) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> las cifras de dinero de un texto, solo sus dígitos (sin decimales de 2) */
    public static function moneyDigits(string $text): array
    {
        if (preg_match_all(self::MONEY, $text, $m) === false) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(self::digitsOf(...), $m[0]), static fn (string $d): bool => $d !== '')));
    }

    /** @param  array<string, true>  $cifras */
    private static function maskApproved(string $text, array $cifras): string
    {
        if ($cifras === []) {
            return $text;
        }

        return (string) preg_replace_callback(self::MONEY, static fn (array $m): string => isset($cifras[self::digitsOf($m[0])]) ? self::MASK : $m[0], $text);
    }

    private static function digitsOf(string $expresion): string
    {
        $sinCentavos = (string) preg_replace('/,\d{1,2}(?=\D*$)/u', '', $expresion);

        return ltrim((string) preg_replace('/\D+/', '', $sinCentavos), '0');
    }

    private static function fold(string $text): string
    {
        $t = mb_strtolower($text, 'UTF-8');

        return strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }
}
