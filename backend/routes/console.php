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

// Renovación de clases fijas/recurrentes — corre cada hora. Reabre el ciclo de
// reservas de cada clase según su `renewal_hours` (8/12/24/48/168=semanal). Las
// franjas de renovación son por horas, así que la granularidad horaria basta.
// Idempotente (una sesión ya renovada no se vuelve a tocar).
Schedule::command('classes:renew')
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

// ── Iron Body Proactive Coach (Fase 2) ───────────────────────────────────────
// INERTE por defecto: solo se agenda si PROACTIVE_COACH_ENABLED=true. Permite
// activación gradual sin romper el flujo base. Rollback = poner el flag en false.
// Horarios pensados para NO saturar ni caer en madrugada; el presupuesto
// anti-spam (máx 1 fuerte / 2 totales por día) y la idempotencia día/semana
// hacen el resto. Activar uno por uno, no todos de golpe.
if ((bool) config('proactive_coach.enabled', false)) {
    // Entrenamiento esperado hoy sin iniciar: media tarde.
    Schedule::command('ironbody:detect-workout-not-started')
        ->dailyAt('17:00')->withoutOverlapping()->onOneServer();

    // Racha en riesgo: noche temprana (aún a tiempo, sin madrugada).
    Schedule::command('ironbody:detect-streak-at-risk')
        ->dailyAt('20:30')->withoutOverlapping()->onOneServer();

    // Cumplimiento diario nulo: tarde.
    Schedule::command('ironbody:detect-daily-compliance-missing')
        ->dailyAt('18:30')->withoutOverlapping()->onOneServer();

    // Nudge contextual (cumplimiento parcial): una vez al día, tarde.
    Schedule::command('ironbody:detect-coach-nudges')
        ->dailyAt('16:00')->withoutOverlapping()->onOneServer();

    // Racha no iniciada: semanal, miércoles 11:00 (frecuencia baja).
    Schedule::command('ironbody:detect-streak-not-started')
        ->weeklyOn(3, '11:00')->withoutOverlapping()->onOneServer();

    // Invitaciones IA: semanal, jueves 10:00 (frecuencia baja, no diaria).
    Schedule::command('ironbody:detect-iron-ai-invites')
        ->weeklyOn(4, '10:00')->withoutOverlapping()->onOneServer();

    // Reactivación: dos veces por semana (martes/viernes 10:30).
    // Se usan dos weeklyOn en vez de twiceWeekly (no disponible en esta versión
    // del Scheduler → "Method ...Event::twiceWeekly does not exist").
    Schedule::command('ironbody:detect-coach-reactivation')
        ->weeklyOn(2, '10:30')->withoutOverlapping()->onOneServer();

    Schedule::command('ironbody:detect-coach-reactivation')
        ->weeklyOn(5, '10:30')->withoutOverlapping()->onOneServer();

    // Plan de la semana: lunes 08:30.
    Schedule::command('ironbody:detect-weekly-coach-plan')
        ->weeklyOn(1, '08:30')->withoutOverlapping()->onOneServer();

    // module.discovery: solo si además hay tracking real (doble flag). Inerte hoy.
    if ((bool) config('proactive_coach.discovery_enabled', false)) {
        Schedule::command('ironbody:detect-module-discovery')
            ->weeklyOn(6, '11:00')->withoutOverlapping()->onOneServer();
    }
}

// ── Wompi: reconciliación de pagos en vuelo (respaldo del webhook) ─────────────
// Corre cada WOMPI_RECONCILIATION_MINUTES (default 5). Idempotente y con
// lockForUpdate: jamás duplica activaciones ni degrada un pago terminal.
// withoutOverlapping evita solapes; onOneServer en despliegues multi-nodo.
if ((bool) config('wompi.reconciliation.enabled', true)) {
    $wompiMinutes = max(1, (int) config('wompi.reconciliation.minutes', 5));
    Schedule::command('payments:wompi-reconcile')
        ->cron('*/'.$wompiMinutes.' * * * *')
        ->withoutOverlapping()
        ->onOneServer();
}

// ── Factus: reconciliación de comprobantes + reintento de errores técnicos ────
// INERTE por defecto: solo se agenda si FACTUS_ENABLED=true (y la reconciliación
// activa). Los jobs además revalidan el flag en runtime, así que un cambio de
// .env basta para activar/desactivar. Idempotentes y best-effort.
if ((bool) config('billing.enabled', false) && (bool) config('billing.reconciliation.enabled', true)) {
    $factusSync = max(1, (int) config('billing.reconciliation.minutes', 10));
    Schedule::job(new \App\Jobs\SyncFactusInvoiceStatusJob, config('billing.queue', 'billing'))
        ->cron('*/'.$factusSync.' * * * *')
        ->withoutOverlapping()
        ->onOneServer();

    $factusRetry = max(1, (int) config('billing.reconciliation.retry_minutes', 15));
    Schedule::job(new \App\Jobs\RetryElectronicInvoiceJob, config('billing.queue', 'billing'))
        ->cron('*/'.$factusRetry.' * * * *')
        ->withoutOverlapping()
        ->onOneServer();
}
