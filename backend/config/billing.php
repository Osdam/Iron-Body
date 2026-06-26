<?php

/*
|--------------------------------------------------------------------------
| Facturación electrónica DIAN — Factus / Halltec (API V2)
|--------------------------------------------------------------------------
| Toda la configuración de facturación vive aquí (centralizada y env-driven).
| NINGÚN secreto se hardcodea: usuario, password, client_id y client_secret
| salen SIEMPRE de variables de entorno y jamás se exponen al frontend/app.
| Toda llamada a Factus pasa por el backend (ver App\Services\Billing\*).
|
| Reglas de ambiente (validadas en arranque por FactusConfigValidator):
|   - sandbox     -> base_url api-sandbox.factus.com.co
|   - production  -> base_url productiva + credenciales reales
| Pasar a producción = cambiar SOLO el .env (credenciales, base URL, ambiente,
| resolución/rango/prefijo y datos fiscales del emisor). Cero cambios de código.
|
| Interruptor maestro: FACTUS_ENABLED=false => las facturas quedan en estado
| 'pending' y NUNCA se llama a Factus (sin emisión real accidental).
*/

$env = env('FACTUS_ENV', 'sandbox');

return [

    // Interruptor maestro. Con false, InvoicingService nunca despacha el job de
    // emisión: la factura se crea 'pending' como evidencia, pero no sale a Factus.
    'enabled' => filter_var(env('FACTUS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'provider' => 'factus',

    // 🔒 Bloqueo tributario: producción NO se considera lista hasta que el
    // contador confirme el tratamiento de IVA de membresías/productos y se
    // ponga esta variable en true. No se asume IVA.
    'tax_decision_confirmed' => filter_var(env('FACTUS_TAX_DECISION_CONFIRMED', false), FILTER_VALIDATE_BOOLEAN),

    // Emisión AUTOMÁTICA por origen. Por decisión del dueño, apagada por
    // defecto: la factura se crea 'pending' pero NO se envía a Factus salvo que
    // el cliente la solicite (emisión manual) o se active el flag respectivo.
    'auto_emit' => [
        'memberships'   => filter_var(env('FACTUS_MEMBERSHIPS_AUTO_EMIT', false), FILTER_VALIDATE_BOOLEAN),
        'product_sales' => filter_var(env('FACTUS_PRODUCT_SALES_AUTO_EMIT', false), FILTER_VALIDATE_BOOLEAN),
    ],

    // Envío automático del comprobante (PDF/XML) al correo del cliente usando el
    // envío NATIVO de Factus (payload send_email). Solo se solicita si el flag
    // está en true Y el cliente tiene un email válido; si no, la factura se
    // emite igual con send_email=false. No implica SMTP propio (eso es aparte).
    'send_email' => filter_var(env('FACTUS_SEND_EMAIL', false), FILTER_VALIDATE_BOOLEAN),

    // Envío PROPIO (SMTP de Laravel) del comprobante al correo del cliente. Es
    // un FALLBACK al envío nativo de Factus: en producción Factus respondió
    // send_email=false y customer.email=null, así que no podemos depender de él.
    // Es independiente y NO altera la emisión: la factura ya quedó 'validated';
    // el correo es best-effort y su fallo jamás revierte el comprobante.
    // Apagado por defecto (opt-in vía BILLING_SEND_CUSTOMER_EMAIL=true).
    'customer_email_delivery' => [
        'enabled'    => filter_var(env('BILLING_SEND_CUSTOMER_EMAIL', false), FILTER_VALIDATE_BOOLEAN),
        'attach_pdf' => filter_var(env('BILLING_CUSTOMER_EMAIL_ATTACH_PDF', true), FILTER_VALIDATE_BOOLEAN),
        'attach_xml' => filter_var(env('BILLING_CUSTOMER_EMAIL_ATTACH_XML', true), FILTER_VALIDATE_BOOLEAN),

        // Branding visual del correo del comprobante (solo presentación, no toca
        // la emisión ni los adjuntos). El logo debe ser una URL ABSOLUTA HTTPS
        // pública (los clientes de correo no resuelven rutas internas). Si queda
        // vacío, el header usa un fallback tipográfico de marca "IRON BODY".
        'logo_url'      => env('BILLING_EMAIL_LOGO_URL'),
        'support_email' => env('BILLING_EMAIL_SUPPORT', 'facturacion@ironbodyneiva.cloud'),
    ],

    // sandbox | production
    'env'        => $env,
    'production' => $env === 'production',

    // URL base de la API V2 según ambiente.
    'base_url' => rtrim((string) env('FACTUS_BASE_URL', 'https://api-sandbox.factus.com.co'), '/'),

    // -- Credenciales OAuth2 (password grant) --------------------------------
    // SECRETAS: solo backend. NUNCA se entregan al front/app ni se loguean.
    'credentials' => [
        'username'      => env('FACTUS_USERNAME'),
        'password'      => env('FACTUS_PASSWORD'),
        'client_id'     => env('FACTUS_CLIENT_ID'),
        'client_secret' => env('FACTUS_CLIENT_SECRET'),
    ],

    // -- Cliente HTTP --------------------------------------------------------
    'http' => [
        'timeout'         => (int) env('FACTUS_TIMEOUT', 30),
        'connect_timeout' => (int) env('FACTUS_CONNECT_TIMEOUT', 10),
        // Reintentos SOLO en operaciones idempotentes (GET). La emisión (POST)
        // no se reintenta a ciegas dentro del cliente; de eso se encarga el job
        // con backoff y guardas de idempotencia (ver EmitElectronicInvoiceJob).
        'retry_times'   => (int) env('FACTUS_RETRY_TIMES', 5),
        'retry_backoff' => (int) env('FACTUS_RETRY_BACKOFF_SECONDS', 60),
    ],

    // TTL del access_token en cache. Debe ser MENOR a la expiración real que
    // devuelva Factus (confirmar en la doc/colección). Ver FactusTokenManager.
    'token_cache_seconds' => (int) env('FACTUS_TOKEN_CACHE_SECONDS', 3000),

    // Cola dedicada para no competir con notificaciones/automations.
    'queue' => env('FACTUS_QUEUE', 'billing'),

    // -- Emisor (la empresa que factura) -------------------------------------
    // Datos fiscales propios. Van en .env, no en la BD de clientes.
    'company' => [
        'nit'             => env('FACTUS_COMPANY_NIT'),
        'dv'              => env('FACTUS_COMPANY_DV'),
        'name'            => env('FACTUS_COMPANY_NAME'),
        'email'           => env('FACTUS_COMPANY_EMAIL'),
        'phone'           => env('FACTUS_COMPANY_PHONE'),
        'address'         => env('FACTUS_COMPANY_ADDRESS'),
        'city_code'       => env('FACTUS_COMPANY_CITY_CODE'),
        'department_code' => env('FACTUS_COMPANY_DEPARTMENT_CODE'),
    ],

    // -- Numeración / resolución DIAN ----------------------------------------
    // La numeración legal la administra Factus por su rango/resolución. El CRM
    // envía el rango y RECIBE el número; no fabrica el consecutivo.
    'numbering' => [
        'range_id'        => env('FACTUS_NUMBERING_RANGE_ID'),
        'prefix'          => env('FACTUS_NUMBERING_PREFIX'),
        // Las notas crédito usan SU PROPIO rango de numeración (resolución NC).
        'credit_range_id' => env('FACTUS_CREDIT_NUMBERING_RANGE_ID'),
    ],

    // -- Valores por defecto del documento (códigos de catálogo Factus V2) ----
    // Confirmados contra la colección oficial (docs/factus). Los montos van como
    // string; payment_form es entero; payment_method_code y los demás, string.
    'defaults' => [
        'currency'             => 'COP',
        'document'             => env('FACTUS_DOCUMENT_CODE', '01'),        // 01 = Factura de venta
        'operation_type'       => env('FACTUS_OPERATION_TYPE', '10'),      // 10 = Estándar
        'unit_measure_code'    => env('FACTUS_DEFAULT_UNIT_MEASURE_CODE', '94'),
        'standard_code'        => env('FACTUS_DEFAULT_STANDARD_CODE', '999'),
        'tax_code'             => env('FACTUS_DEFAULT_TAX_CODE', '01'),     // 01 = IVA (items.taxes[].code)
        'tax_rate'             => env('FACTUS_DEFAULT_TAX_RATE', '19.00'),
        'payment_form'         => (int) env('FACTUS_DEFAULT_PAYMENT_FORM', 1),
        'payment_method_code'  => env('FACTUS_DEFAULT_PAYMENT_METHOD_CODE', '10'),
        'tribute_code'         => env('FACTUS_DEFAULT_TRIBUTE_CODE', 'ZZ'), // customer.tribute_code
        'legal_organization_code' => env('FACTUS_DEFAULT_LEGAL_ORGANIZATION_CODE', '2'), // 2 = Natural
        'municipality_code'    => env('FACTUS_DEFAULT_MUNICIPALITY_CODE'),
    ],

    // -- Notas crédito -------------------------------------------------------
    'credit_note' => [
        'correction_concept_code' => env('FACTUS_CREDIT_CORRECTION_CONCEPT_CODE', '2'), // 2 = Anulación
        'customization_id'        => env('FACTUS_CREDIT_CUSTOMIZATION_ID', '20'),
    ],

    // -- Mapa tipo de documento interno -> código DIAN/Factus ----------------
    // Si el valor almacenado ya es numérico (código), se usa tal cual.
    'document_type_map' => [
        'CC'  => '13',
        'NIT' => '31',
        'CE'  => '22',
        'PAS' => '41',
        'TI'  => '12',
    ],
    // -- Consumidor final ----------------------------------------------------
    // Cuando el pago no trae datos fiscales completos, se factura a consumidor
    // final (sin bloquear el cobro). Documento/tipo exactos según Factus/DIAN.
    'consumer_final' => [
        'document_type'   => env('FACTUS_CONSUMER_FINAL_DOCUMENT_TYPE'),
        'document_number' => env('FACTUS_CONSUMER_FINAL_DOCUMENT_NUMBER'),
        'name'            => env('FACTUS_CONSUMER_FINAL_NAME', 'Consumidor final'),
    ],

    // -- Webhook / callback (condicional) ------------------------------------
    // Solo si Factus ofrece callbacks. Si no, se usa reconciliación por job
    // (SyncFactusInvoiceStatusJob). Secreto para verificar firma del evento.
    'webhook' => [
        'enabled' => filter_var(env('FACTUS_WEBHOOK_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'secret'  => env('FACTUS_WEBHOOK_SECRET'),
    ],

    // -- Reconciliación (polling de facturas en 'processing') ----------------
    'reconciliation' => [
        'enabled'         => filter_var(env('FACTUS_RECONCILIATION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'minutes'         => (int) env('FACTUS_RECONCILIATION_MINUTES', 10),
        'retry_minutes'   => (int) env('FACTUS_RETRY_SWEEP_MINUTES', 15),
        'max_age_minutes' => (int) env('FACTUS_RECONCILIATION_MAX_AGE_MINUTES', 1440),
    ],

    // -- Almacenamiento de PDF/XML -------------------------------------------
    // Disco PRIVADO. Nunca público: se sirve por endpoint autenticado.
    'storage' => [
        'disk' => env('FACTUS_STORAGE_DISK', 'local'),
        'path' => env('FACTUS_STORAGE_PATH', 'invoices'),
    ],

    // -- Mapa de estados Factus/DIAN -> estado interno -----------------------
    // Confirmar los literales exactos que devuelve Factus V2 en la respuesta y
    // ajustar este mapa (ver FactusResponseMapper). Los internos están en
    // App\Enums\InvoiceStatus.
    'status_map' => [
        // 'Validada' / 'Aprobada' => 'validated',
        // 'Rechazada'             => 'rejected',
    ],

    // -- Claves prohibidas en logs (defensa en profundidad) ------------------
    // FactusPayloadSanitizer elimina recursivamente cualquier clave cuyo nombre
    // contenga una de estas (substring, case-insensitive) antes de persistir.
    'forbidden_log_keys' => [
        'password',
        'client_secret',
        'client_id',
        'access_token',
        'refresh_token',
        'authorization',
        'token',
        'secret',
        'bearer',
    ],
];
