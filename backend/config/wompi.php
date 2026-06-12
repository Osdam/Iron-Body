<?php

/*
|--------------------------------------------------------------------------
| Wompi (Bancolombia) — pasarela de pagos ACTIVA de Iron Body
|--------------------------------------------------------------------------
| Toda la configuración de Wompi vive aquí (centralizada). Las llaves
| PRIVATE/INTEGRITY/EVENTS son secretas y NUNCA salen del backend; la
| PUBLIC no es secreta y puede entregarse a la app vía /payments/wompi/config.
|
| Reglas de ambiente (validadas en arranque por WompiConfigValidator):
|   - sandbox     → llaves *_test_*  + api_url sandbox.wompi.co
|   - production  → llaves *_prod_*  + api_url production.wompi.co
| Nunca se mezclan: producción rechaza una llave de sandbox y viceversa.
|
| El monto SIEMPRE es autoritativo del backend (Plan::price → centavos).
| La app jamás define el precio final.
*/

$env = env('WOMPI_ENV', 'sandbox');

return [

    // sandbox | production
    'env' => $env,

    'production' => $env === 'production',

    // URL base de la API según ambiente. En producción se usa la URL productiva.
    'api_url' => $env === 'production'
        ? rtrim((string) env('WOMPI_PRODUCTION_API_URL', 'https://production.wompi.co/v1'), '/')
        : rtrim((string) env('WOMPI_API_URL', 'https://sandbox.wompi.co/v1'), '/'),

    'sandbox_api_url'    => rtrim((string) env('WOMPI_API_URL', 'https://sandbox.wompi.co/v1'), '/'),
    'production_api_url' => rtrim((string) env('WOMPI_PRODUCTION_API_URL', 'https://production.wompi.co/v1'), '/'),

    // ── Credenciales ────────────────────────────────────────────────────────
    // public_key: NO secreta (se entrega a la app). El resto: secretas, backend.
    'public_key'       => env('WOMPI_PUBLIC_KEY'),
    'private_key'      => env('WOMPI_PRIVATE_KEY'),
    'integrity_secret' => env('WOMPI_INTEGRITY_SECRET'),
    'events_secret'    => env('WOMPI_EVENTS_SECRET'),

    // ── Moneda ──────────────────────────────────────────────────────────────
    'currency' => 'COP',

    // ── URLs ────────────────────────────────────────────────────────────────
    // Si quedan vacías se derivan de APP_URL (rutas internas del backend).
    'webhook_url'  => env('WOMPI_WEBHOOK_URL') ?: rtrim((string) env('APP_URL'), '/').'/api/webhooks/wompi',
    'redirect_url' => env('WOMPI_REDIRECT_URL') ?: rtrim((string) env('APP_URL'), '/').'/api/payments/wompi/return',

    // ── Cliente HTTP ────────────────────────────────────────────────────────
    'timeout'         => (int) env('WOMPI_TIMEOUT_SECONDS', 30),
    'connect_timeout' => (int) env('WOMPI_CONNECT_TIMEOUT_SECONDS', 10),
    // Reintentos SOLO en operaciones idempotentes/seguras (GET). POST de
    // /transactions nunca se reintenta a ciegas (ver WompiClient).
    'retry_times'     => (int) env('WOMPI_RETRY_TIMES', 2),
    'retry_sleep_ms'  => (int) env('WOMPI_RETRY_SLEEP_MS', 300),

    // ── Métodos de pago habilitados ─────────────────────────────────────────
    // DaviPlata requiere habilitación COMERCIAL en la cuenta Wompi: queda
    // desactivado por defecto hasta confirmarlo (ver docs).
    'methods' => [
        'card'      => filter_var(env('WOMPI_METHOD_CARD', true), FILTER_VALIDATE_BOOLEAN),
        'pse'       => filter_var(env('WOMPI_METHOD_PSE', true), FILTER_VALIDATE_BOOLEAN),
        'nequi'     => filter_var(env('WOMPI_METHOD_NEQUI', true), FILTER_VALIDATE_BOOLEAN),
        'daviplata' => filter_var(env('WOMPI_METHOD_DAVIPLATA', false), FILTER_VALIDATE_BOOLEAN),
    ],

    // Mapa nombre interno de método → type de Wompi (transaction.payment_method.type).
    'method_types' => [
        'card'      => 'CARD',
        'pse'       => 'PSE',
        'nequi'     => 'NEQUI',
        'daviplata' => 'DAVIPLATA',
        'bancolombia_transfer' => 'BANCOLOMBIA_TRANSFER',
    ],

    // ── Reconciliación ──────────────────────────────────────────────────────
    'reconciliation' => [
        'enabled'      => filter_var(env('WOMPI_RECONCILIATION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // Cada cuántos minutos corre el job de conciliación.
        'minutes'      => (int) env('WOMPI_RECONCILIATION_MINUTES', 5),
        // Reintentos por pago antes de marcar expired.
        'max_retries'  => (int) env('WOMPI_RECONCILIATION_MAX_RETRIES', 24),
        // Edad máx (min) que un pago aguanta pending/requires_action.
        'max_pending_minutes' => (int) env('WOMPI_MAX_PENDING_MINUTES', 60),
    ],

    // ── Cache de tokens de aceptación (presigned_acceptance) ────────────────
    // Wompi rota las versiones; cache corto e invalidable.
    'acceptance_cache_ttl' => (int) env('WOMPI_ACCEPTANCE_CACHE_TTL', 600),
    // Cache de instituciones financieras PSE.
    'pse_cache_ttl'        => (int) env('WOMPI_PSE_CACHE_TTL', 3600),

    // ── Mapa de estados Wompi → estado interno ──────────────────────────────
    // Wompi: PENDING | APPROVED | DECLINED | VOIDED | ERROR
    'status_map' => [
        'PENDING'  => 'pending',
        'APPROVED' => 'approved',
        'DECLINED' => 'declined',
        'VOIDED'   => 'voided',
        'ERROR'    => 'error',
    ],

    // ── Mapa de errores legibles (sanitizados, para la app) ─────────────────
    'error_messages' => [
        'INSUFFICIENT_FUNDS'  => 'Fondos insuficientes. Verifica tu saldo o usa otro método.',
        'INVALID_CARD'        => 'Los datos de la tarjeta no son válidos. Revísalos e intenta de nuevo.',
        'EXPIRED_CARD'        => 'La tarjeta está vencida. Usa otra tarjeta.',
        'RESTRICTED_CARD'     => 'La tarjeta tiene restricciones. Contacta a tu banco o usa otro método.',
        'DECLINED'            => 'El pago fue rechazado por el banco. Intenta con otro método.',
        'ABANDONED'           => 'El pago no se completó a tiempo. Genera uno nuevo.',
        'ERROR'               => 'No pudimos procesar el pago. No se realizó ningún cobro.',
    ],
];
