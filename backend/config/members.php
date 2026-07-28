<?php

/*
|--------------------------------------------------------------------------
| Reglas de cuenta de miembro
|--------------------------------------------------------------------------
|
| Parámetros de negocio del registro. Se leen desde configuración (no desde
| constantes incrustadas) para poder ajustarlos por entorno sin desplegar
| código, y para que las pruebas puedan fijarlos de forma determinista.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Edad mínima para CREAR una cuenta
    |--------------------------------------------------------------------------
    |
    | Años CUMPLIDOS exigidos para registrarse. La comprobación es de fecha
    | exacta, no de año calendario: cumplir 13 mañana no vale hoy.
    |
    | Alineado con el público declarado en Google Play (13-15, 16-17, 18+).
    | Se elevó de 11 a 13 al retirar el tramo 9-12.
    |
    | ALCANCE: aplica ÚNICAMENTE a registros NUEVOS. Las cuentas históricas no
    | se revisan, no se bloquean, no se suspenden y no requieren completar su
    | fecha de nacimiento. Cambiar este valor nunca actúa retroactivamente.
    |
    | La autoridad es el servidor (App\Rules\MinimumRegistrationAge y
    | App\Models\Member::minRegistrationAge). La app solo muestra el resultado.
    |
    */
    'min_registration_age' => (int) env('MIN_REGISTRATION_AGE', 13),

    /*
    |--------------------------------------------------------------------------
    | Mayoría de edad legal
    |--------------------------------------------------------------------------
    |
    | Umbral para marcar `members.is_minor` y exigir datos del acudiente en el
    | contrato. Es independiente de la edad mínima de registro: entre ambas hay
    | un tramo (13-17) que SÍ puede registrarse pero sigue siendo menor.
    |
    */
    'legal_adult_age' => (int) env('LEGAL_ADULT_AGE', 18),

];
