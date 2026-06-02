<?php

/*
|--------------------------------------------------------------------------
| Contratos / Consentimiento informado / Firma electrónica (Iron Body)
|--------------------------------------------------------------------------
| Este módulo NO rediseña los documentos oficiales del gimnasio: usa el PDF
| oficial como FONDO y estampa, por coordenadas controladas, únicamente los
| datos del usuario y la firma capturada. Las plantillas viven en disco
| privado (NO versionadas en git) y se registran en `contract_templates` con
| su checksum. Si una plantilla falta, el sistema FALLA con un error claro:
| jamás genera un "contrato parecido" ni un PDF falso.
|
| Coordenadas: puntos PDF (1/72"), origen arriba-izquierda, página Letter
| 612 x 792 pt. Calibradas con `pdftotext -bbox` sobre el documento real.
| `page` es 1-indexado (coincide con FPDI::importPage).
|
| Revisión legal: este andamiaje técnico NO reemplaza la revisión de un
| abogado. Ver docs/contracts/LEGAL_REVIEW_REQUIRED.md.
*/

return [

    // Disco privado donde viven plantillas y PDFs firmados (storage/app/private).
    'disk' => env('CONTRACTS_DISK', 'local'),

    // Carpeta (relativa al disco) con los PDFs fuente oficiales.
    'templates_path' => 'contract_templates/source',

    // URLs de privacidad/términos y contacto de soporte (visibles desde la app
    // antes de firmar). Configurables por entorno; documentadas en .env.example.
    'privacy_policy_url' => env('PRIVACY_POLICY_URL', 'https://ironbody.com.co/privacidad'),
    'terms_url'          => env('TERMS_URL', 'https://ironbody.com.co/terminos'),
    'support_contact'    => env('SUPPORT_CONTACT', 'Ironbodyneiva@gmail.com'),

    // Plantilla de inscripción por defecto para mayores de edad.
    // 'workout_registration' = formato completo con T&C y consentimiento.
    // 'basic_registration'   = inscripción simple (talonario).
    'default_registration_template' => env('CONTRACTS_DEFAULT_REGISTRATION', 'workout_registration'),

    /*
    | Definición de cada plantilla oficial. `file` es relativo a templates_path.
    | `applies_to`: adult | minor | any. `version` se versiona a mano cuando el
    | documento oficial cambie (nunca se reescribe el contenido legal).
    */
    'templates' => [

        // ── INSCRIPCIÓN (talonario) — convertido 1 sola vez de DOCX a PDF ──────
        'basic_registration' => [
            'name'        => 'Inscripción IRONBODY NEIVA',
            'version'     => '1.0.0',
            'applies_to'  => 'adult',
            'file'        => 'basic_registration.pdf',
            'pages'       => 2,
            // Se estampa el bloque SUPERIOR (copia principal); el inferior es el
            // duplicado tipo talonario y se deja como en el original.
            'fields' => [
                'fecha'                 => ['page' => 1, 'x' => 496, 'y' => 102.9, 'w' => 80,  'size' => 9],
                'document_number'       => ['page' => 1, 'x' => 160, 'y' => 139.5, 'w' => 84,  'size' => 9],
                'full_name'             => ['page' => 1, 'x' => 312, 'y' => 139.5, 'w' => 233, 'size' => 9],
                'birth_date'            => ['page' => 1, 'x' => 162, 'y' => 167.5, 'w' => 97,  'size' => 9],
                'rh'                    => ['page' => 1, 'x' => 291, 'y' => 167.5, 'w' => 30,  'size' => 9],
                'address'               => ['page' => 1, 'x' => 395, 'y' => 167.5, 'w' => 172, 'size' => 9],
                'phone'                 => ['page' => 1, 'x' => 102, 'y' => 195.5, 'w' => 110, 'size' => 9],
                'email'                 => ['page' => 1, 'x' => 260, 'y' => 195.5, 'w' => 278, 'size' => 9],
            ],
            'multiline' => [
                'medical_notes' => [
                    'page' => 1, 'size' => 9, 'line_height' => 13.2,
                    'lines' => [
                        ['x' => 199, 'y' => 223.5, 'w' => 352],
                        ['x' => 37,  'y' => 253.4, 'w' => 476],
                    ],
                ],
                'injuries' => [
                    'page' => 1, 'size' => 9, 'line_height' => 13.2,
                    'lines' => [
                        ['x' => 101, 'y' => 283.3, 'w' => 427],
                    ],
                ],
            ],
            'signature' => ['page' => 1, 'x' => 80, 'y' => 376, 'w' => 150, 'h' => 22],
            // Checkboxes que aplican a esta plantilla (ver 'checkbox_sets').
            'checkbox_set' => 'adult_registration',
        ],

        // ── FORMATO DE INSCRIPCIÓN IRONBODY WORKOUT (T&C + consentimiento) ─────
        'workout_registration' => [
            'name'        => 'Formato de Inscripción IRONBODY WORKOUT',
            'version'     => '1.0.0',
            'applies_to'  => 'adult',
            'file'        => 'workout_registration.pdf',
            'pages'       => 2,
            // Los campos del usuario están en la PÁGINA 2; la 1 es solo T&C.
            'fields' => [
                'full_name'        => ['page' => 2, 'x' => 180, 'y' => 327.9, 'w' => 318, 'size' => 9],
                'document_number'  => ['page' => 2, 'x' => 215, 'y' => 349.1, 'w' => 112, 'size' => 9],
                'birth_date'       => ['page' => 2, 'x' => 442, 'y' => 349.1, 'w' => 78,  'size' => 9],
                'rh'               => ['page' => 2, 'x' => 163, 'y' => 370.3, 'w' => 38,  'size' => 9],
                'address'          => ['page' => 2, 'x' => 258, 'y' => 370.3, 'w' => 248, 'size' => 9],
                'phone'            => ['page' => 2, 'x' => 168, 'y' => 391.6, 'w' => 88,  'size' => 9],
                'email'            => ['page' => 2, 'x' => 358, 'y' => 391.6, 'w' => 154, 'size' => 9],
                'fecha'            => ['page' => 2, 'x' => 121, 'y' => 577.1, 'w' => 94,  'size' => 9],
            ],
            'multiline' => [
                'medical_notes' => [
                    'page' => 2, 'size' => 9, 'line_height' => 13.2,
                    'lines' => [
                        ['x' => 86, 'y' => 425.0, 'w' => 438],
                        ['x' => 86, 'y' => 438.3, 'w' => 438],
                        ['x' => 86, 'y' => 451.5, 'w' => 318],
                    ],
                ],
                'injuries' => [
                    'page' => 2, 'size' => 9, 'line_height' => 13.2,
                    'lines' => [
                        ['x' => 86, 'y' => 486.0, 'w' => 438],
                        ['x' => 86, 'y' => 499.2, 'w' => 438],
                        ['x' => 86, 'y' => 512.4, 'w' => 318],
                    ],
                ],
            ],
            'signature' => ['page' => 2, 'x' => 175, 'y' => 600, 'w' => 170, 'h' => 22],
            'checkbox_set' => 'adult_registration',
        ],

        // ── LIBERACIÓN DE RESPONSABILIDAD (MENOR) — firmado por acudiente ──────
        'minor_release' => [
            'name'        => 'Liberación de Responsabilidad (Menor) IRONBODY',
            'version'     => '1.0.0',
            'applies_to'  => 'minor',
            'file'        => 'minor_release.pdf',
            'pages'       => 1,
            'fields' => [
                // Encabezado: "Yo ___ identificado con cedula N° ___ de ___ ..."
                'guardian_full_name'         => ['page' => 1, 'x' => 99,  'y' => 157.5, 'w' => 250, 'size' => 9],
                'guardian_document_number'   => ['page' => 1, 'x' => 162, 'y' => 171.1, 'w' => 95,  'size' => 9],
                'guardian_document_city'     => ['page' => 1, 'x' => 289, 'y' => 171.1, 'w' => 128, 'size' => 9],
                // "en mi condición de padre y/o madre de ___" (nombre del menor).
                'minor_full_name_intro'      => ['page' => 1, 'x' => 190, 'y' => 184.8, 'w' => 166, 'size' => 8],
                // "permito que mi Hijo(a) ___" (nombre del menor, 2ª aparición).
                'minor_full_name'            => ['page' => 1, 'x' => 305, 'y' => 255.5, 'w' => 200, 'size' => 8],
                // "documento de identidad N° ___"
                'minor_document_number'      => ['page' => 1, 'x' => 222, 'y' => 269.1, 'w' => 95,  'size' => 8],
                // "firmo el presente documento en ___, a los ___ días"
                'sign_city'                  => ['page' => 1, 'x' => 335, 'y' => 557.3, 'w' => 98,  'size' => 9],
                'sign_day'                   => ['page' => 1, 'x' => 467, 'y' => 557.3, 'w' => 33,  'size' => 9],
                'sign_month'                 => ['page' => 1, 'x' => 144, 'y' => 571.0, 'w' => 46,  'size' => 9],
                'sign_year'                  => ['page' => 1, 'x' => 247, 'y' => 571.0, 'w' => 18,  'size' => 9],
                // Bloque inferior de constancia.
                'minor_full_name_footer'     => ['page' => 1, 'x' => 87,  'y' => 604.7, 'w' => 280, 'size' => 9],
                'guardian_full_name_footer'  => ['page' => 1, 'x' => 255, 'y' => 625.1, 'w' => 268, 'size' => 9],
                'guardian_phone'             => ['page' => 1, 'x' => 146, 'y' => 645.5, 'w' => 108, 'size' => 9],
                'guardian_address'           => ['page' => 1, 'x' => 148, 'y' => 666.0, 'w' => 215, 'size' => 9],
                'guardian_city'              => ['page' => 1, 'x' => 87,  'y' => 678.4, 'w' => 114, 'size' => 9],
            ],
            'multiline' => [],
            'signature' => ['page' => 1, 'x' => 242, 'y' => 688, 'w' => 170, 'h' => 20],
            'checkbox_set' => 'minor_release',
        ],
    ],

    /*
    | Conjuntos de checkboxes (consentimiento explícito). El texto EXACTO se
    | guarda en acceptance_snapshot al firmar. `required=false` => el usuario
    | puede dejarlo en false y se refleja tal cual (p. ej. uso de imagen).
    | `consent_column` mapea (si existe) a la tabla legacy member_legal_consents.
    */
    'checkbox_sets' => [
        'adult_registration' => [
            ['key' => 'truthfulness',        'required' => true,  'consent_column' => 'truthfulness',
             'text' => 'Confirmo que mis datos personales suministrados son verídicos.'],
            ['key' => 'terms_and_conditions','required' => true,  'consent_column' => 'terms_and_conditions',
             'text' => 'Declaro que he leído y acepto los términos, condiciones y consentimiento informado.'],
            ['key' => 'data_processing',     'required' => true,  'consent_column' => 'data_processing',
             'text' => 'Autorizo el tratamiento de mis datos personales conforme a la política de privacidad de Iron Body.'],
            ['key' => 'health_data',         'required' => true,  'consent_column' => null,
             'text' => 'Autorizo el uso de datos de salud básicos/observaciones médicas únicamente para la gestión segura de mi entrenamiento.'],
            ['key' => 'inform_injuries',     'required' => true,  'consent_column' => null,
             'text' => 'Entiendo que debo informar lesiones, enfermedades o restricciones físicas antes de entrenar.'],
            ['key' => 'commercial_policies', 'required' => true,  'consent_column' => 'service_contract',
             'text' => 'Acepto las políticas comerciales: la mensualidad no es transferible, no hay reembolso después del pago y las clases no son acumulables cuando aplique.'],
            // Uso de imagen: OPCIONAL (puede quedar en false).
            ['key' => 'image_use',           'required' => false, 'consent_column' => null,
             'text' => 'Autorizo el uso de mi imagen en fotografías y videos con fines promocionales (opcional).'],
        ],
        'minor_release' => [
            ['key' => 'guardian_authorized', 'required' => true,  'consent_column' => 'guardian_authorization',
             'text' => 'Confirmo que soy el padre, madre o acudiente autorizado del menor.'],
            ['key' => 'minor_admission',     'required' => true,  'consent_column' => null,
             'text' => 'Autorizo el ingreso del menor a las instalaciones de IRONBODY.'],
            ['key' => 'accompaniment',       'required' => true,  'consent_column' => null,
             'text' => 'Me comprometo a brindar el acompañamiento requerido durante el entrenamiento del menor.'],
            ['key' => 'risk_waiver',         'required' => true,  'consent_column' => 'physical_risk_waiver',
             'text' => 'Acepto los riesgos y la exoneración de responsabilidad descritos en el documento oficial.'],
            ['key' => 'minor_data_processing','required' => true, 'consent_column' => 'data_processing',
             'text' => 'Autorizo el tratamiento de los datos personales del menor bajo mi responsabilidad.'],
        ],
    ],
];
