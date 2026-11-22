<?php

/*
|--------------------------------------------------------------------------
| Autorizaciones de contacto por canal (C9; Enmienda C9 del contrato H9)
|--------------------------------------------------------------------------
| Lo lee App\Services\Outreach\Run\OutreachConsentConfig. Lo decide el
| PROPIETARIO con su asesoría (P9-14, P9-15); nada de esto lo fija el código.
|
| EL TEXTO LEGAL que la persona acepta, por finalidad (WhatsApp). HOY NO
| EXISTE: mientras una finalidad no tenga versión y texto válidos, registrar y
| confirmar autorizaciones de esa finalidad responden 409
| `consent_legal_text_missing` y no escriben nada (falla cerrado). Retirar y
| rechazar funcionan siempre. La huella que se guarda es el sha256 del texto
| EXACTO (bytes tal cual, sin normalizar): cambiar una coma es otro texto y
| necesita otra versión.
|  - versión: `^[A-Za-z0-9][A-Za-z0-9._:/-]{0,47}$` (p. ej.
|    `c9-whatsapp-comercial/2026-12-01.1`);
|  - texto: UTF-8, de 1 a 4 000 caracteres, sin controles salvo el salto de línea.
|
| LAS FUENTES admitidas para REGISTRAR (D7): un subconjunto de las del libro
| (`contract_checkbox`, `reception_form`, `member_request`). Retirar admite
| siempre las tres.
*/

return [
    'legal' => [
        'commercial' => [
            'version' => env('OUTREACH_CONSENT_LEGAL_COMMERCIAL_VERSION'),
            'text' => env('OUTREACH_CONSENT_LEGAL_COMMERCIAL_TEXT'),
        ],
        'collections' => [
            'version' => env('OUTREACH_CONSENT_LEGAL_COLLECTIONS_VERSION'),
            'text' => env('OUTREACH_CONSENT_LEGAL_COLLECTIONS_TEXT'),
        ],
        'service' => [
            'version' => env('OUTREACH_CONSENT_LEGAL_SERVICE_VERSION'),
            'text' => env('OUTREACH_CONSENT_LEGAL_SERVICE_TEXT'),
        ],
    ],

    'sources' => ['contract_checkbox', 'reception_form', 'member_request'],
];
