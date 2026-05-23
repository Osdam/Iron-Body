<?php

use App\Http\Controllers\Crm\ExerciseController as CrmExerciseController;
use App\Http\Controllers\Crm\MemberRoutineController as CrmMemberRoutineController;
use App\Http\Controllers\Crm\RoutineController as CrmRoutineController;
use App\Http\Controllers\Crm\TrainerController as CrmTrainerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('crm')->name('crm.')->group(function () {
    // Entrenadores
    Route::resource('trainers', CrmTrainerController::class)
        ->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::get('trainers/{trainer}/ratings', [CrmTrainerController::class, 'ratings'])
        ->name('trainers.ratings');

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
    Route::get('member-routines',                        [CrmMemberRoutineController::class, 'index'])  ->name('member-routines.index');
    Route::get('member-routines/create',                 [CrmMemberRoutineController::class, 'create']) ->name('member-routines.create');
    Route::post('member-routines',                       [CrmMemberRoutineController::class, 'store'])  ->name('member-routines.store');
    Route::get('member-routines/{routine}/edit',         [CrmMemberRoutineController::class, 'edit'])   ->name('member-routines.edit');
    Route::put('member-routines/{routine}',              [CrmMemberRoutineController::class, 'update']) ->name('member-routines.update');
    Route::delete('member-routines/{routine}',           [CrmMemberRoutineController::class, 'destroy'])->name('member-routines.destroy');
});
