<?php

/*
|--------------------------------------------------------------------------
| Identidad legal publicada (política de privacidad / términos)
|--------------------------------------------------------------------------
|
| Google Play rechazó la política por dos motivos concretos (Datos de Usuario
| → Política de Privacidad):
|
|   1. «La política de privacidad no identifica claramente la aplicación, el
|      nombre del desarrollador o la entidad legal asociada a tu ficha de
|      Google Play Store.»
|   2. «La política de privacidad de tu aplicación no revela sus prácticas de
|      conservación de datos.»
|
| Por eso la identidad NO se escribe suelta dentro del HTML: vive aquí, en un
| único sitio, y la página la imprime. Si mañana cambia el nombre de la ficha
| de Play basta con esta variable de entorno — no hay que buscar el dato en
| medio de un documento de veinte secciones.
|
| REGLA: `developer_name` debe coincidir EXACTAMENTE, carácter por carácter,
| con el «Desarrollador» que muestra la ficha de Google Play Store (Play
| Console → Configuración → Detalles de la cuenta de desarrollador). Si no
| coincide, Google vuelve a rechazar por el motivo 1.
|
*/

return [

    // Nombre de la app tal como aparece en la ficha de Google Play.
    'app_name' => env('LEGAL_APP_NAME', 'Iron Body Workout'),

    // applicationId Android de la ficha de Play. OJO: es distinto del namespace
    // Kotlin (com.ironbodyneiva.app) y del bundle id de iOS, a propósito.
    'android_package' => env('LEGAL_ANDROID_PACKAGE', 'com.ironbodyneiva.workout'),

    // Marca / nombre del servicio.
    'brand' => env('LEGAL_BRAND', 'Iron Body'),

    // Desarrollador / entidad legal de la ficha de Play. Ver REGLA de arriba.
    'developer_name' => env('LEGAL_DEVELOPER_NAME', 'IRONBODY — Fredy Alberto Pajoy Medina'),

    // Responsable del tratamiento (Ley 1581 de 2012, Colombia). Puede coincidir
    // con el desarrollador o no; se imprime siempre, aunque coincida.
    'controller_name' => env('LEGAL_CONTROLLER_NAME', 'Fredy Alberto Pajoy Medina'),

    // Dirección física del establecimiento.
    'address' => env('LEGAL_ADDRESS', 'Cl. 24 Sur #33-53, Neiva, Huila, Colombia'),

    // Contacto de privacidad. `support_contact` de contracts.php sigue siendo la
    // fuente para el resto del producto; aquí se lee de él para no divergir.
    'privacy_email' => env('LEGAL_PRIVACY_EMAIL', env('SUPPORT_CONTACT', 'Ironbodyneiva@gmail.com')),
    'privacy_phone' => env('LEGAL_PRIVACY_PHONE', '+57 314 3455483'),

    // URL canónica declarada en Play Console → Contenido de la aplicación.
    'privacy_url' => env('LEGAL_PRIVACY_URL', 'https://api.ironbodyneiva.cloud/privacy-policy.html'),
    'terms_url' => env('LEGAL_TERMS_URL', 'https://api.ironbodyneiva.cloud/terms.html'),

    // Fecha de última actualización que se imprime en la página. Se cambia a
    // mano cuando el texto cambia: una fecha automática (now()) haría creer que
    // la política se revisa a diario y no dejaría rastro de la versión.
    'last_updated' => env('LEGAL_LAST_UPDATED', '18 de agosto de 2026'),

    // Retenciones REALES que la página cita. Se leen de la MISMA variable de
    // entorno que gobierna la purga, no de un número escrito a mano: si alguien
    // cambia el plazo operativo, el documento cambia con él. (No se usa
    // config('ugc...') porque los ficheros de config se cargan por orden
    // alfabético y `legal` se resuelve antes que `ugc`.)
    'retention' => [
        'moderation_evidence_days' => (int) env('UGC_EVIDENCE_RETENTION_DAYS', 90),
        'story_hours' => 24,
    ],
];
