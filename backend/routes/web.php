<?php

use App\Http\Controllers\Api\LegalController;
use App\Http\Controllers\Crm\ExerciseController as CrmExerciseController;
use App\Http\Controllers\Crm\MemberRoutineController as CrmMemberRoutineController;
use App\Http\Controllers\Crm\RoutineController as CrmRoutineController;
use App\Http\Controllers\Crm\TrainerController as CrmTrainerController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', function () {
    return view('welcome');
});

/*
 * URL CANÓNICA de la política de privacidad — la que se declara en Google Play
 * Console → Contenido de la aplicación → Política de privacidad.
 *
 * Hasta ahora esta dirección la servía un `.html` estático suelto en `public/`,
 * creado a mano en el servidor y fuera de git: hablaba del CRM y de WhatsApp, no
 * nombraba la app ni el paquete ni al desarrollador, y no decía nada sobre
 * conservación de datos. Google la rechazó por esos dos motivos exactos.
 *
 * Ahora la sirve el MISMO método que `/api/legal/privacy` (el enlace que abre la
 * app desde Perfil), así que las dos direcciones no pueden divergir nunca más.
 *
 * IMPORTANTE al desplegar: nginx resuelve `try_files $uri` antes de llegar a
 * Laravel, así que mientras el fichero `public/privacy-policy.html` siga
 * existiendo en el servidor seguirá ganando él y esta ruta no se ejecutará. Hay
 * que borrarlo. Ver docs/PRIVACY_POLICY_DEPLOY.md.
 */
/*
 * Sin sesión ni CSRF. Son documentos públicos que se leen sin identificarse: no
 * hay nada que recordar entre peticiones y nada que enviar de vuelta. Dejarlos
 * en el grupo `web` completo tenía dos consecuencias molestas — la página de
 * privacidad respondía plantando una cookie de sesión al visitante (mal sitio
 * para hacer eso, precisamente), y `StartSession` pisaba la cabecera de caché
 * con `no-cache, private`, que es lo contrario de lo que queremos: un documento
 * legal debe poder cachearse un poco, pero nunca durante días.
 */
$publicLegal = [
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    ValidateCsrfToken::class,
];

Route::get('privacy-policy.html', [LegalController::class, 'privacy'])
    ->withoutMiddleware($publicLegal)
    ->name('legal.privacy.canonical');

Route::get('terms.html', [LegalController::class, 'terms'])
    ->withoutMiddleware($publicLegal)
    ->name('legal.terms.canonical');

// Pagos: NO hay bridge web ni WebView. Todo el flujo es Wompi IN-APP; cuando se
// requiere autenticación (PSE/3DS) se abre la URL OFICIAL que entrega la propia
// transacción de Wompi en el navegador del sistema.

Route::prefix('crm')->name('crm.')->group(function () {
    // Entrenadores
    Route::resource('trainers', CrmTrainerController::class)
        ->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::get('trainers/{trainer}/ratings', [CrmTrainerController::class, 'ratings'])
        ->name('trainers.ratings');
    // Miembros asignados al entrenador (dentro del mismo módulo, sin pantalla aparte).
    Route::post('trainers/{trainer}/members', [CrmTrainerController::class, 'assignMembers'])
        ->name('trainers.members.assign');
    Route::delete('trainers/{trainer}/members/{member}', [CrmTrainerController::class, 'unassignMember'])
        ->name('trainers.members.unassign');

    // Catálogo de ejercicios
    Route::resource('exercises', CrmExerciseController::class)
        ->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);

    // Rutinas globales (admin)
    Route::resource('routines', CrmRoutineController::class)
        ->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::post('routines/{routine}/assign', [CrmRoutineController::class, 'assign'])
        ->name('routines.assign');
    Route::get('routines-custom', [CrmRoutineController::class, 'customIndex'])
        ->name('routines.custom');

    // Rutinas por cliente (asignación directa a miembro)
    Route::get('member-routines', [CrmMemberRoutineController::class, 'index'])->name('member-routines.index');
    Route::get('member-routines/create', [CrmMemberRoutineController::class, 'create'])->name('member-routines.create');
    Route::post('member-routines', [CrmMemberRoutineController::class, 'store'])->name('member-routines.store');
    Route::get('member-routines/{routine}/edit', [CrmMemberRoutineController::class, 'edit'])->name('member-routines.edit');
    Route::put('member-routines/{routine}', [CrmMemberRoutineController::class, 'update'])->name('member-routines.update');
    Route::delete('member-routines/{routine}', [CrmMemberRoutineController::class, 'destroy'])->name('member-routines.destroy');
});
