<?php

use App\Http\Controllers\Crm\ExerciseController as CrmExerciseController;
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
});
