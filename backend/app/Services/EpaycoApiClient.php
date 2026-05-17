<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente ePayco para pago IN-APP (sin navegador/WebView/checkout_url).
 *
 * Todo por el SDK OFICIAL `epayco/epayco-php` (sin checkout web):
 *  - TARJETA: token (`https://api.secure.payco.co/v1/tokens`) → customer →
 *    charge.
 *  - PSE: `bank->create` (`/pagos/debitos.json`). Devuelve urlbanco: NO se
 *    abre; se guarda como metadata y queda PENDIENTE (confirma webhook).
 *  - DAVIPLATA: `daviplata->create` (APIFY `/payment/process/daviplata`).
 *    Queda PENDIENTE hasta confirmación; sin aprobación falsa.
 *  - NEQUI: el SDK/APIFY oficial no expone Nequi → "no disponible" claro.
 * Credenciales de API: PUBLIC_KEY (apiKey) + PRIVATE_KEY (privateKey).
 * `p_cust_id_cliente`/`p_key` son SOLO para la firma del webhook.
 *
 * Reglas de seguridad:
 *  - Llaves SOLO del lado servidor (config/.env). Nunca en logs.
 *  - PAN/CVV solo se envían al SDK para tokenizar; jamás se persisten ni se
 *    registran (ver `redact()`); el token reemplaza a la tarjeta.
 *  - Toda falla se degrada a un resultado controlado (sin aprobación falsa).
 */
class EpaycoApiClient
{

    /** Bases reales del SDK oficial (solo para logging sanitizado). */
    private const TOKEN_BASE = 'https://api.secure.payco.co';
    private const TOKEN_PATH = '/v1/tokens';

    /**
     * Instancia el SDK oficial epayco/epayco-php con las llaves PÚBLICA/PRIVADA
     * (las de tokenización/cobro; `p_cust_id_cliente`/`p_key` son SOLO para la
     * firma del webhook, no para autenticar la API).
     */
    private function sdk(): ?\Epayco\Epayco
    {
        $cfg = config('services.epayco');
        if (empty($cfg['public_key']) || empty($cfg['private_key'])) {
            return null;
        }
        return new \Epayco\Epayco([
            'apiKey'     => $cfg['public_key'],
            'privateKey' => $cfg['private_key'],
            'test'       => (bool) $cfg['test'],
            'lenguage'   => 'ES',
        ]);
    }

    /** Convierte la respuesta del SDK (stdClass) a array para normalizar. */
    private function toArray($resp): array
    {
        if (is_array($resp)) {
            return $resp;
        }
        if (is_object($resp)) {
            return json_decode(json_encode($resp), true) ?: [];
        }
        if (is_string($resp)) {
            return json_decode($resp, true) ?: [];
        }
        return [];
    }

    /**
     * Cobro con tarjeta 100% por API usando el SDK OFICIAL ePayco:
     *   token (https://api.secure.payco.co/v1/tokens) → customer → charge.
     * Resultado normalizado. PAN/CVV/token/llaves nunca se loguean.
     */
    public function payCard(array $p): array
    {
        $epayco = $this->sdk();
        if (!$epayco) {
            return $this->fail('Pago no disponible temporalmente. Intenta luego.');
        }
        $c = $p['customer'];

        // ── Normalización + validación local ANTES de llamar a ePayco ────────
        $rawCard  = $p['card'] ?? [];
        $rawNum   = (string) ($rawCard['number'] ?? '');
        $rawMonth = (string) ($rawCard['exp_month'] ?? '');
        $rawYear  = (string) ($rawCard['exp_year'] ?? '');
        $rawCvc   = (string) ($rawCard['cvc'] ?? '');

        $number = preg_replace('/\D/', '', $rawNum);          // solo dígitos
        $cvc    = preg_replace('/\D/', '', $rawCvc);          // solo dígitos
        $monthD = preg_replace('/\D/', '', $rawMonth);
        $yearD  = preg_replace('/\D/', '', $rawYear);

        // Caso borde: viene "MM/AA" o "MMAA" todo junto en exp_month.
        if ($yearD === '' && strlen($monthD) >= 4) {
            $yearD  = substr($monthD, 2);
            $monthD = substr($monthD, 0, 2);
        }
        $month = substr($monthD, 0, 2);
        if (strlen($month) === 1) {
            $month = '0' . $month;
        }
        if (strlen($yearD) === 2) {
            $yearD = '20' . $yearD;             // 27 → 2027
        } elseif (strlen($yearD) > 4) {
            $yearD = substr($yearD, 0, 4);
        }
        $year = $yearD;

        // Titular: nunca vacío; si solo viene un nombre, replicar como apellido
        // para que name y last_name no vayan vacíos.
        $fullName = trim((string) ($c['name'] ?? ''));
        $parts = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $firstName = $parts[0] ?? '';
        $lastName  = trim((string) ($c['last_name'] ?? ''));
        if ($lastName === '') {
            $lastName = count($parts) > 1
                ? implode(' ', array_slice($parts, 1))
                : $firstName; // "ALEJANDRO" → name/last_name = ALEJANDRO
        }
        if ($firstName !== '') {
            $c['name'] = $firstName;
            $c['last_name'] = $lastName;
        }

        // Log SANITIZADO (sin PAN/CVV/token/llaves) antes de tokenizar.
        Log::info('pay-card sanitizado (pre-tokenize)', [
            'card_digits_count'      => strlen($number),
            'card_last4'             => $number !== '' ? substr($number, -4) : null,
            'exp_month'              => $month,
            'exp_year'               => $year,
            'cvc_length'             => strlen($cvc),
            'name_present'           => $fullName !== '',
            'has_non_digits_in_card' => preg_replace('/\s+/', '', $rawNum) !== $number,
            'has_non_digits_in_cvc'  => preg_replace('/\s+/', '', $rawCvc) !== $cvc,
        ]);

        // Validación local: si algo no cuadra, NO se llama a ePayco.
        $mInt = (int) $month;
        if (strlen($number) < 13 || strlen($number) > 19
            || $mInt < 1 || $mInt > 12
            || strlen($year) !== 4
            || !in_array(strlen($cvc), [3, 4], true)
            || $fullName === '') {
            return $this->fail('Revisa los datos de la tarjeta.');
        }

        // 1) Tokenizar tarjeta (endpoint oficial /v1/tokens).
        try {
            $tokenResp = $epayco->token->create([
                'card[number]'    => $number,
                'card[exp_year]'  => $year,
                'card[exp_month]' => $month,
                'card[cvc]'       => $cvc,
            ]);
        } catch (Throwable $e) {
            $this->logStage('tokenize', $e, []);
            return $this->fail('No se pudo validar la tarjeta. Verifica los datos.');
        }
        $tok = $this->toArray($tokenResp);
        $tokenId = $tok['id']
            ?? ($tok['data']['id'] ?? ($tok['data']['token'] ?? null));
        if (!$tokenId) {
            $this->logStage('tokenize', null, $tok);
            return $this->fail('No se pudo validar la tarjeta. Verifica los datos.');
        }

        // 2) Crear cliente con el token.
        try {
            $custResp = $epayco->customer->create([
                'token_card' => $tokenId,
                'name'       => $c['name'] ?? 'Cliente',
                'last_name'  => $c['last_name'] ?? '',
                'email'      => $c['email'] ?? 'sinemail@ironbody.co',
                'phone'      => $c['phone'] ?? '',
                'cell_phone' => $c['phone'] ?? '',
                'default'    => true,
            ]);
        } catch (Throwable $e) {
            $this->logStage('create_customer', $e, []);
            return $this->fail('No se pudo registrar el pago. Intenta nuevamente.');
        }
        $cust = $this->toArray($custResp);
        $customerId = $cust['data']['customerId']
            ?? ($cust['data']['id'] ?? ($cust['customerId'] ?? null));
        if (!$customerId) {
            $this->logStage('create_customer', null, $cust);
            return $this->fail('No se pudo registrar el pago. Intenta nuevamente.');
        }

        // 3) Cobrar (charge oficial).
        try {
            $chargeResp = $epayco->charge->create([
                'token_card'          => $tokenId,
                'customer_id'         => $customerId,
                'doc_type'            => $c['doc_type'] ?? 'CC',
                'doc_number'          => $c['doc_number'] ?? '',
                'name'                => $c['name'] ?? 'Cliente',
                'last_name'           => $c['last_name'] ?? '',
                'email'               => $c['email'] ?? 'sinemail@ironbody.co',
                'city'                => $c['city'] ?? 'Neiva',
                'address'             => $c['address'] ?? 'Neiva, Huila',
                'phone'               => $c['phone'] ?? '',
                'cell_phone'          => $c['phone'] ?? '',
                'bill'                => $p['reference'],
                'description'         => $p['description'],
                'value'               => $p['value'],
                'tax'                 => '0',
                'tax_base'            => '0',
                'currency'            => $p['currency'],
                'dues'                => (string) (int) ($p['dues'] ?? 1),
                'ip'                  => $p['ip'],
                'url_response'        => $p['url_response'],
                'url_confirmation'    => $p['url_confirmation'],
                'method_confirmation' => 'POST',
            ]);
        } catch (Throwable $e) {
            $this->logStage('charge', $e, []);
            return $this->fail('No pudimos procesar el pago. Intenta más tarde.');
        }

        $chargeArr = $this->toArray($chargeResp);
        // Siempre dejamos rastro sanitizado de la respuesta real de ePayco.
        $this->logStage('charge', null, $chargeArr);
        return $this->normalize($chargeArr, true);
    }

    /**
     * Log SANITIZADO por etapa (tokenize|create_customer|charge). NUNCA
     * número de tarjeta, CVV, token, llaves ni p_key.
     */
    private function logStage(string $stage, ?Throwable $e, array $resp): void
    {
        $data = $resp['data'] ?? $resp;
        Log::warning('ePayco pay-card etapa', [
            'stage'             => $stage,
            'exception_class'   => $e ? get_class($e) : null,
            'exception_message' => $e ? mb_substr($e->getMessage(), 0, 200) : null,
            'http_status'       => $resp['status']
                ?? ($data['status'] ?? null),
            'epayco_code'       => $data['cod_response']
                ?? $data['cod_respuesta']
                ?? $resp['cod_response']
                ?? null,
            'epayco_text'       => $this->shortText($resp, $data),
        ]);
    }

    /** Extrae un texto de error de ePayco (sanitizado, sin secretos). */
    private function shortText(array $resp, array $data): ?string
    {
        $t = $resp['text_response']
            ?? $resp['textResponse']
            ?? $resp['titleResponse']
            ?? $resp['message']
            ?? $data['response']
            ?? $data['x_response_reason_text']
            ?? $data['respuesta']
            ?? null;
        if (is_array($t)) {
            $t = implode(' · ', array_map('strval', $t));
        }
        return $t !== null ? mb_substr((string) $t, 0, 200) : null;
    }

    /**
     * Log SANITIZADO del fallo de tokenización: base, path, status/código y
     * mensaje de ePayco. NUNCA PAN/CVV/token/llaves.
     */
    private function logTokenFailure(?array $resp, ?string $message, string $code): void
    {
        Log::warning('ePayco tokenize falló (SDK oficial)', [
            'base_url'      => self::TOKEN_BASE,
            'endpoint'      => self::TOKEN_PATH,
            'http_status'   => $resp['status'] ?? null,
            'epayco_code'   => $code !== '' ? $code : null,
            'epayco_msg'    => $message ? mb_substr($message, 0, 180) : null,
        ]);
    }

    /**
     * Nequi: el SDK oficial de ePayco NO expone un servicio Nequi por API
     * (APIFY no lista endpoint Nequi). Se reporta como NO DISPONIBLE de forma
     * clara, sin abrir navegador y sin simular aprobación.
     */
    public function payNequi(array $p): array
    {
        $this->logMethod('nequi', $p['reference'] ?? null, null, null,
            'Nequi no expuesto por el SDK/APIFY oficial');
        return $this->unavailable(
            'Nequi no está habilitado por ePayco para esta cuenta.'
        );
    }

    /**
     * PSE 100% por API (SDK oficial → /pagos/debitos.json). ePayco devuelve una
     * URL de banco para autorizar: NO se abre; se guarda como metadata y la
     * transacción queda PENDIENTE (se confirma por webhook/consulta).
     */
    public function payPse(array $p): array
    {
        $epayco = $this->sdk();
        if (!$epayco) {
            return $this->fail('Pago no disponible temporalmente. Intenta luego.');
        }
        $c   = $p['customer'];
        $pse = $p['pse'] ?? [];
        $typePerson = (($pse['person_type'] ?? 'natural') === 'juridica') ? 1 : 0;

        try {
            $resp = $epayco->bank->create([
                'bank'                => (string) ($pse['bank'] ?? ''),
                'invoice'             => $p['reference'],
                'description'         => $p['description'],
                'value'               => $p['value'],
                'tax'                 => '0',
                'tax_base'            => '0',
                'currency'            => $p['currency'],
                'type_person'         => (string) $typePerson,
                'doc_type'            => $c['doc_type'] ?? 'CC',
                'document'            => $c['doc_number'] ?? '',
                'name'                => $c['name'] ?? 'Cliente',
                'last_name'           => $c['last_name'] ?? '',
                'email'               => $c['email'] ?? 'sinemail@ironbody.co',
                'country'             => $c['country'] ?? 'CO',
                'cell_phone'          => $c['phone'] ?? '',
                'ip'                  => $p['ip'],
                'url_response'        => $p['url_response'],
                'url_confirmation'    => $p['url_confirmation'],
                'method_confirmation' => 'POST',
            ]);
        } catch (Throwable $e) {
            $this->logMethod('pse', $p['reference'] ?? null, null,
                (string) $e->getCode(), $e->getMessage());
            return $this->fail('No pudimos iniciar el pago PSE. Intenta más tarde.');
        }

        $d = $this->toArray($resp);
        $r = $this->normalize($d, true);
        $ref = $r['ref_payco'] ?? $r['transaction_id'];
        $bankUrl = $d['data']['urlbanco']
            ?? $d['urlbanco']
            ?? ($d['data']['url'] ?? null);

        if (!$ref) {
            return $this->fail(
                'No pudimos iniciar el pago PSE. Verifica el banco y los datos.'
            );
        }
        // Si ePayco no rechazó explícitamente, PSE queda PENDIENTE (el usuario
        // autoriza en su banco; se confirma por webhook/consulta).
        if (!in_array($r['state'], [2, 4, 6, 9, 11], true)) {
            $r['state'] = 3;
        }
        $r['requires_external'] = true;
        $r['message'] =
            'Tu solicitud PSE quedó registrada. Autorízala en el portal de tu '
            . 'banco; el pago se confirmará automáticamente.';
        // urlbanco SOLO como metadata (no se abre desde la app).
        $r['raw']['urlbanco'] = $bankUrl;
        $this->logMethod('pse', $p['reference'] ?? null, $ref, null,
            $r['message']);
        return $r;
    }

    /**
     * Daviplata 100% por API (SDK oficial → APIFY /payment/process/daviplata).
     * Crea la transacción y queda PENDIENTE hasta confirmación (no se simula
     * aprobado). Datos de prueba oficiales: CC 1134568019 / CE 786630.
     */
    public function payDaviplata(array $p): array
    {
        $epayco = $this->sdk();
        if (!$epayco) {
            return $this->fail('Pago no disponible temporalmente. Intenta luego.');
        }
        $c = $p['customer'];
        $phone = $p['phone'] ?: ($c['phone'] ?? '');

        try {
            $resp = $epayco->daviplata->create([
                'doc_type'         => $c['doc_type'] ?? 'CC',
                'document'         => $c['doc_number'] ?? '',
                'name'             => $c['name'] ?? 'Cliente',
                'last_name'        => $c['last_name'] ?? '',
                'email'            => $c['email'] ?? 'sinemail@ironbody.co',
                'indicative'       => '57',
                'phone'            => $phone,
                'value'            => $p['value'],
                'tax'              => '0',
                'tax_base'         => '0',
                'currency'         => $p['currency'],
                'dues'             => '1',
                'ip'               => $p['ip'],
                'description'      => $p['description'],
                'invoice'          => $p['reference'],
                'url_response'     => $p['url_response'],
                'url_confirmation' => $p['url_confirmation'],
                'test'             => $p['test'] ? 'true' : 'false',
            ]);
        } catch (Throwable $e) {
            $this->logMethod('daviplata', $p['reference'] ?? null, null,
                (string) $e->getCode(), $e->getMessage());
            return $this->unavailable(
                'Daviplata no está habilitado por ePayco para esta cuenta.'
            );
        }

        $d = $this->toArray($resp);
        $r = $this->normalize($d, true);
        $ref = $r['ref_payco'] ?? $r['transaction_id'];
        if (!$ref && !$r['ok']) {
            return $this->unavailable(
                'Daviplata no está habilitado por ePayco para esta cuenta.'
            );
        }
        // Sin aprobación falsa: si no fue rechazo explícito, queda PENDIENTE.
        if (!in_array($r['state'], [1, 2, 4, 6, 9, 11], true)) {
            $r['state'] = 3;
        }
        if ($r['state'] !== 1) {
            $r['message'] =
                'Te enviamos una solicitud a Daviplata. Confírmala desde tu '
                . 'app Daviplata para completar el pago.';
        }
        $this->logMethod('daviplata', $p['reference'] ?? null, $ref, null,
            $r['message'] ?? 'creada');
        return $r;
    }

    /** Log SANITIZADO por método (sin llaves/tokens/datos sensibles). */
    private function logMethod(
        string $method,
        ?string $reference,
        ?string $providerRef,
        ?string $httpStatus,
        ?string $msg
    ): void {
        Log::info('ePayco método (in-app)', [
            'method'       => $method,
            'reference'    => $reference,
            'provider_ref' => $providerRef,
            'http_status'  => $httpStatus,
            'epayco_msg'   => $msg ? mb_substr($msg, 0, 160) : null,
        ]);
    }

    // ── Normalización ────────────────────────────────────────────────────────

    private function normalize(?array $j, bool $httpOk): array
    {
        if (!$httpOk || !is_array($j)) {
            return $this->fail('No pudimos procesar el pago. Intenta más tarde.');
        }
        $data = $j['data'] ?? $j;
        $codeRaw = $data['cod_response']
            ?? $data['cod_respuesta']
            ?? $data['codResponse']
            ?? $data['x_cod_response']
            ?? $data['estado']
            ?? $data['x_transaction_state']
            ?? $data['x_response']
            ?? $j['cod_response']
            ?? null;
        $state = $this->stateToInt($codeRaw);
        $ref = $data['ref_payco']
            ?? $data['x_ref_payco']
            ?? $data['refPayco']
            ?? ($data['transaction']['ref_payco'] ?? null)
            ?? $j['ref_payco']
            ?? null;
        $txId = $data['transactionID']
            ?? $data['x_transaction_id']
            ?? $data['transaction_id']
            ?? null;
        $msg = $data['response']
            ?? $data['x_response_reason_text']
            ?? $data['respuesta']
            ?? $data['mensaje']
            ?? $j['text_response']
            ?? $j['textResponse']
            ?? $j['titleResponse']
            ?? $j['message']
            ?? null;

        // 'status'/'success' string 'error'/'false' también es fallo.
        $sRaw = $j['success'] ?? $data['success'] ?? null;
        $stRaw = $j['status'] ?? null;
        $success = !(
            $sRaw === false
            || $stRaw === false
            || (is_string($sRaw) && strtolower($sRaw) === 'false')
            || (is_string($stRaw) &&
                in_array(strtolower($stRaw), ['error', 'false'], true))
        );

        return [
            'ok'                => $success,
            'state'             => $state,           // 1..11 o null
            'state_text'        => is_string($codeRaw) ? $codeRaw : null,
            'ref_payco'         => $ref ? (string) $ref : null,
            'transaction_id'    => $txId ? (string) $txId : null,
            'message'           => $msg ? (string) $msg : null,
            'requires_external' => false,
            'raw'               => $this->redact($data),
        ];
    }

    /** 'Aceptada'/'1' → 1, 'Rechazada'/'2' → 2, 'Pendiente'/'3' → 3, etc. */
    private function stateToInt($v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return (int) $v;
        }
        return match (mb_strtolower(trim((string) $v))) {
            'aceptada', 'aprobada', 'approved', 'accepted' => 1,
            'rechazada', 'rejected', 'declined'            => 2,
            'pendiente', 'pending', 'en proceso'           => 3,
            'fallida', 'failed', 'error'                   => 4,
            'reversada', 'reversed'                        => 6,
            'retenida', 'held'                             => 7,
            'expirada', 'expired'                          => 9,
            'abandonada', 'abandoned'                      => 10,
            'cancelada', 'cancelled', 'canceled'           => 11,
            default                                        => null,
        };
    }

    private function fail(string $message): array
    {
        return [
            'ok' => false, 'state' => 4, 'state_text' => null,
            'ref_payco' => null, 'transaction_id' => null,
            'message' => $message, 'requires_external' => false, 'raw' => [],
        ];
    }

    private function unavailable(?string $message = null): array
    {
        return [
            'ok' => false, 'state' => 2, 'state_text' => null,
            'ref_payco' => null, 'transaction_id' => null,
            'message' => $message
                ?? 'Este método requiere validación externa del proveedor. '
                 . 'Usa tarjeta o continúa con el flujo seguro habilitado.',
            'requires_external' => false, 'raw' => [],
        ];
    }

    /** Elimina cualquier rastro de datos de tarjeta antes de persistir/loguear. */
    private function redact(array $d): array
    {
        foreach ([
            'card', 'cardnumber', 'card_number', 'cardNumber', 'number',
            'cvc', 'cvv', 'cvc2', 'token_card', 'tokenCard', 'pan',
        ] as $k) {
            unset($d[$k]);
        }
        return $d;
    }
}
