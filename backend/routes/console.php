<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Notificaciones de membresías próximas a vencer — corre a diario.
// Idempotente: NotificationService deduplica por
// membership_expiring_MEMBERID_DATE, así que ejecutarlo varias veces (o el
// mismo día) NO crea notificaciones duplicadas. withoutOverlapping evita
// solapes si una corrida anterior sigue activa.
Schedule::command('notifications:membership-expiring --days=3')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();

// Membresías recién vencidas — corre a diario. Idempotente por
// membership_expired_MEMBERID_DATE.
Schedule::command('notifications:membership-expired --days=7')
    ->dailyAt('08:05')
    ->withoutOverlapping()
    ->onOneServer();

// Recordatorios de clases próximas (ventana 3 h). Corre cada 15 min.
// Idempotente: NotificationService deduplica por
// class_reminder_CLASSID_MEMBERID_FECHA_HORA, así que múltiples corridas dentro
// de la misma franja NO crean recordatorios duplicados.
Schedule::command('notifications:class-reminders --hours=3')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Purga de stories expirados — corre cada hora. Borra el row Y el archivo
// físico (disco público legacy o Firebase Storage). withoutOverlapping evita
// que dos corridas concurrentes intenten borrar lo mismo.
Schedule::command('stories:purge')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// ── Coach proactivo: detección de señales → eventos hacia n8n ────────────────
// Todos idempotentes por día/semana → no duplican si corren varias veces.
// (También existe `ironbody:emit-automation-events` para correr todo junto.)

Schedule::command('ironbody:detect-membership-expiring')
    ->dailyAt('09:00')->withoutOverlapping()->onOneServer();

Schedule::command('ironbody:detect-workout-missed')
    ->dailyAt('19:00')->withoutOverlapping()->onOneServer();

Schedule::command('ironbody:detect-nutrition-missing')
    ->dailyAt('20:00')->withoutOverlapping()->onOneServer();

// Evaluación desactualizada: semanal, lunes 09:00.
Schedule::command('ironbody:detect-evaluation-outdated')
    ->weeklyOn(1, '09:00')->withoutOverlapping()->onOneServer();

// Progreso estancado: semanal, domingo 18:00.
Schedule::command('ironbody:detect-progress-stalled')
    ->weeklyOn(0, '18:00')->withoutOverlapping()->onOneServer();

// Resúmenes semanales IA: domingo 19:00.
Schedule::command('ironbody:generate-weekly-ai-summaries')
    ->weeklyOn(0, '19:00')->withoutOverlapping()->onOneServer();
