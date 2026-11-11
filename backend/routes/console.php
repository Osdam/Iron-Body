<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reanudar las membresías congeladas cuyo plazo terminó. Va TEMPRANO, antes de
// que el gimnasio abra: quien vuelve hoy de su pausa tiene que poder entrar a
// primera hora. Idempotente: al reanudar se borran las fechas del
// congelamiento, así que una segunda corrida no devuelve días dos veces.
Schedule::command('memberships:resume-frozen')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->onOneServer();

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

// Purga de las señales de tiempo real (socios, entrenadores, canal global y
// registro de clases del CRM). Son avisos efímeros: quien los lee vuelve a la
// base. El canal global y el registro de clases no se podaban nunca.
Schedule::command('realtime:prune')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// ── Vales de un solo uso de los canales en vivo y de las fotos (P9-25, SC-7) ──
// Borra solo las anotaciones CADUCADAS de los vales (`admin-stream-ticket:*`), sus
// cubos del limitador y la cuenta de fotos en la caché `database`, que Laravel no
// purga sola. Un vale vive 60 s. Sin bandera: el vale sirve a todo el CRM (L9-89).
Schedule::command('admin:prune-stream-tickets')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Kiosko de personal: salidas olvidadas → incidencia (sin cerrar ni inventar
// horas) y poda de nonces/tokens caducados. Cada hora: el umbral es de 16 h, el
// CRM no debe contar como «en jornada» a quien se fue sin marcar.
Schedule::command('workforce:maintenance')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Purga de la evidencia de moderación cuya retención ya venció.
//
// Sin esta entrada el comando existía pero no lo ejecutaba nadie: los binarios
// de contenido denunciado se conservaban indefinidamente. Eso contradice la
// retención declarada (`ugc.evidence_retention_days`) y convierte una medida de
// protección de datos en almacenamiento perpetuo de material sensible.
//
// El comando es idempotente y comprueba por sí mismo que no haya casos abiertos
// antes de borrar nada, así que una corrida diaria es suficiente y segura.
Schedule::command('moderation:purge-evidence')
    ->dailyAt('03:30')
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

// Mora de cuentas por cobrar. NO bloquea a nadie —el bloqueo se calcula al
// leer—: solo detecta la transición para alertar y refrescar. A primera hora,
// para que recepción llegue con la lista del día ya hecha.
Schedule::command('ironbody:detect-overdue-receivables')
    ->dailyAt('06:00')->withoutOverlapping()->onOneServer();

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

// ── Bienestar: motivación, hábitos y suplementos ──────────────────────────────
// INERTE por defecto: solo se agenda si NOTIFICATIONS_WELLNESS_ENABLED=true.
//
// Corre dos veces al día a propósito. Cada socio define sus propias horas de
// silencio en su zona horaria, así que una única pasada dejaría fuera a quien
// duerme justo cuando corre el cron. La llave de idempotencia
// (`wellness:socio:fecha`) hace que, aun con dos pasadas, nadie reciba más de
// una al día. El dispatcher además respeta preferencias y límites.
//
// Si el horario lo gobierna n8n (`orchestrator = n8n`), Laravel NO lo agenda:
// dos relojes disparando lo mismo hacen imposible saber cuál mandó qué. n8n
// llama a /api/internal/automation/wellness-run, que ejecuta este mismo
// planificador con las mismas comprobaciones.
if ((bool) config('notifications.wellness.enabled', false)
    && config('notifications.wellness.orchestrator', 'laravel') === 'laravel') {
    foreach ((array) config('notifications.wellness.runs_at', ['15:00']) as $wellnessRun) {
        Schedule::command('notifications:wellness')
            ->dailyAt($wellnessRun)
            ->withoutOverlapping()
            ->onOneServer();
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

// ── Wompi: cobro recurrente de suscripciones vencidas (pago automático) ────────
// INERTE por defecto: solo se agenda si WOMPI_RECURRING_ENABLED=true (el comando
// además revalida el flag en runtime). Idempotente (lock por suscripción +
// billing_period único): correrlo varias veces NO cobra dos veces. Corre a diario
// (el vencimiento es por FECHA) + reintentos por día según WOMPI_RECURRING_RETRY_DAYS.
if ((bool) config('wompi.recurring.enabled', false)) {
    Schedule::command('subscriptions:charge-due')
        ->dailyAt('06:00')
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

// ── Agente comercial: despacho de seguimientos vencidos (Fase 0) ──────────────
// INERTE por defecto: solo se agenda si MARKETING_FOLLOWUPS_SCHEDULER=true. El
// comando además es seguro en sí mismo (no envía nada si el agente/despacho
// están off). Idempotente + withoutOverlapping → nunca duplica mensajes/llamadas.
if ((bool) config('marketing.followups.scheduler_enabled', false)) {
    $mktMinutes = max(1, (int) config('marketing.followups.scheduler_minutes', 10));
    Schedule::command('marketing:dispatch-followups')
        ->cron('*/'.$mktMinutes.' * * * *')
        ->withoutOverlapping()
        ->onOneServer();
}

// ── Canal de WhatsApp: rescate de eventos atascados ───────────────────────────
// El worker se cae, la base se satura, un despliegue pilla un job a mitad. Antes
// ese mensaje del prospecto se perdía sin que nadie se enterara; ahora el cuerpo
// original está guardado y esta corrida lo devuelve a la cola.
//
// Es seguro ejecutarlo de más: reprocesar no duplica nada porque la idempotencia
// por meta_message_id sigue vigente aguas abajo. Cada 5 minutos es un
// compromiso entre no dejar a nadie esperando y no competir con el worker.
Schedule::command('marketing:replay-webhooks')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// ── Canal de WhatsApp: retención de adjuntos y eventos crudos ─────────────────
// Sin esta entrada, las fotos y notas de voz de los prospectos se acumularían
// para siempre en el disco del servidor y la retención declarada
// (`marketing.media.retention_days`) sería papel mojado. Borra el binario y deja
// la ficha, así que el historial del inbox no se agujerea.
Schedule::command('marketing:prune-media')
    ->dailyAt('04:15')
    ->withoutOverlapping()
    ->onOneServer();

// ── Canal de WhatsApp: reintento de los salientes que fallaron ────────────────
// Un 429 de Meta ocurre por diseño cuando se envía rápido. Sin esta corrida ese
// mensaje se quedaba en 'failed' para siempre y el prospecto sin respuesta.
// Solo entran los fallos que el outbox marcó como transitorios; los definitivos
// quedan 'dead' a la vista de un humano. Seguro de repetir: un mensaje que ya
// tiene meta_message_id nunca se reenvía.
Schedule::command('marketing:retry-outbox')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// ── Agenda de valoraciones: avisos al socio y a la bandeja del CRM ───────────
// Cada minuto, sin colas. Las ventanas (24 h antes, 60 min antes, 15 min antes,
// 15 min después) son INSTANTES UTC guardados en `valuation_reminders.due_at`,
// así que no hay `dailyAt` que pueda salir cinco horas desplazado. Idempotente:
// cada aviso tiene un dueño (UPDATE condicionado + SKIP LOCKED en PostgreSQL) y
// la notificación se deduplica por `event_key`; correrlo dos veces no duplica.
// El canal del socio está APAGADO por defecto (valuation_settings).
Schedule::command('valuations:process-reminders')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// ── IRON GUARD: pasada de detección ──────────────────────────────────────────
// Determinista y barata: consulta estado propio (eventos sin procesar, mensajes
// muertos, adjuntos fallidos, errores de Meta, jobs caídos). No manda nada a un
// modelo. Idempotente: correrla diez veces con el mismo problema deja UN
// incidente con diez ocurrencias, no diez incidentes.
// Respeta IRON_GUARD_ENABLED: con el flag apagado no hace nada.
Schedule::command('iron-guard:scan')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// ── Motor comercial: hechos que consisten en que algo dejó de pasar ──────────
// Los observers cubren lo que alguien HACE (pagar, venir, vincular la app). Esta
// corrida cubre lo contrario: la membresía que va a vencer, el socio que lleva
// tres semanas sin aparecer, el enlace de pago que nadie abrió. No hay ninguna
// fila que cambie cuando alguien deja de venir, así que sin esto esos hechos
// —los más caros comercialmente— no existirían para el sistema.
//
// Cada hora es suficiente: son fenómenos que se miden en días. Idempotente por
// construcción (la clave de cada hecho lleva su fecha), así que repetirla no
// duplica eventos. Respeta COMMERCIAL_EVENTS_ENABLED.
Schedule::command('commercial:scan')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// ── Motor comercial: red de seguridad de la evaluación ───────────────────────
// Recoge los hechos registrados que se quedaron sin evaluar —Redis caído al
// despachar, un worker que murió a mitad, o el motor apagado cuando ocurrieron—.
// `evaluated_at IS NULL` es literalmente la cola pendiente, así que nada se
// pierde: solo se retrasa. Respeta COMMERCIAL_NBA_ENABLED.
Schedule::command('commercial:evaluate-pending')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Alertas comerciales.
 *
 * Cada quince minutos, que es el ritmo al que una alerta sigue siendo util:
 * enterarse a la hora de que alguien lleva esperando respuesta ya es tarde, y
 * cada minuto seria evaluar constantemente para no encontrar nada.
 *
 * La deduplicacion por huella es lo que hace que esta frecuencia no llene la
 * bandeja: una misma situacion actualiza su alerta en vez de abrir otra.
 */
Schedule::call(function (): void {
    app(\App\Services\Commercial\CommercialAlertService::class)->evaluate();
})->everyFifteenMinutes()->name('commercial-alerts')->withoutOverlapping();

// ── ULTRON: el vigía de los turnos mudos ─────────────────────────────────────
// Un silencio no deja fila que revisar: de eso se trata. La única forma de
// encontrarlo es cruzar los eventos atendibles con los desenlaces, y eso no lo
// hacía nadie. En el canario físico dos personas preguntaron y no recibieron
// nada, y se descubrió leyendo el chat a mano.
//
// Cada cinco minutos con diez de gracia: un turno tarda ~25 s de punta a punta,
// así que diez minutos es margen de sobra para no acusar a un turno en vuelo.
// Si el huérfano está en la conversación del canario, ACCIONA EL FRENO y ULTRON
// se para solo; soltarlo es siempre un acto humano (`ultron:abort --release`).
Schedule::command('ultron:turn-watchdog')
    ->everyFiveMinutes()
    // Diez minutos de cerrojo, no las 24 h por defecto: una corrida que muera a
    // mitad no puede dejar al vigía callado un día entero contra una ventana de
    // seis horas, que es exactamente el hueco en el que no vería nada.
    ->withoutOverlapping(10)
    ->onOneServer();

// ── Meta Ads: el gasto real de la pauta ──────────────────────────────────────
// INERTE por defecto: solo se agenda si META_ADS_SYNC_ENABLED=true. Cada hora,
// en el minuto 15, repasa los últimos META_ADS_SYNC_DAYS días de
// `/act_{id}/insights`, porque Meta corrige el gasto de los días recientes.
// Solo LEE de Meta. Es un abanico: el job despacha uno por cuenta conectada
// (META_AD_ACCOUNT_IDS), y cada cuenta tiene su cerrojo, su transacción y sus
// reintentos; un fallo en una no toca los datos de las otras.
// Idempotente: la clave (cuenta, día, anuncio) actualiza en vez de duplicar, y
// un cerrojo por cuenta impide dos pasadas a la vez. Si Meta falla no se toca
// nada: la última foto buena sigue ahí y el panel dice que está
// desactualizada, nunca $0. El job reintenta solo cuando Meta pide frenar o no
// contesta.
if ((bool) config('meta.ads.sync_enabled', false)) {
    Schedule::job(new \App\Jobs\SyncMetaAdsInsights)
        ->hourlyAt(15)
        ->withoutOverlapping()
        ->onOneServer();
}

// ── ULTRON STUDIO: el monitor (Hito 7, contrato OPS7 (a)) ────────────────────
// INERTE por defecto: solo se agenda si ULTRON_STUDIO_MONITOR_ENABLED=true (y
// solo `true`, 1, on o yes). Con la bandera apagada no se programa NADA del
// Studio; los comandos siguen disponibles a mano. Vuelta atrás = la bandera en
// false + config:cache.
if ((bool) config('ultron_studio.monitor.enabled', false)) {
    // La cadena de publicaciones frente al motor (`publication-check`), las
    // integraciones frente a lo esperado (`integrations-doctor --expect=…`, con
    // `ULTRON_STUDIO_MONITOR_EXPECT`) y la configuración autorizada, y las colas
    // (`queue:health`). Solo lee; si algo no cuadra, abre o suma al incidente de
    // IRON GUARD de su clase (fuente `ultron_studio`). Las colas no abren
    // incidente propio: ya lo hace `iron-guard:scan`.
    //
    // Cada 30 minutos y no cada hora: la ventana de agrupación de IRON GUARD es de
    // 60 minutos (`IRON_GUARD_GROUPING_MINUTES`). Con una pasada por hora, cada
    // pasada caería fuera de la ventana de la anterior y REABRIRÍA el incidente
    // (ocurrencias a 1 y sin el reconocimiento de quien ya lo estaba mirando). A
    // 30 minutos, la misma deriva es un solo incidente que suma ocurrencias y
    // escala a las 10. Sin red: el coste son unas pocas lecturas de la base.
    // Diez minutos de cerrojo, no las 24 h por defecto (lección del vigía).
    Schedule::command('ultron-studio:monitor')
        ->everyThirtyMinutes()
        ->withoutOverlapping(10)
        ->onOneServer();

    // El registro de cambios de la configuración de las integraciones (H6
    // §I5.3): una instantánea de SISTEMA por proveedor solo si su huella cambió.
    // Diario, a primera hora en Bogotá (07:10 = 12:10 UTC). Sin red.
    Schedule::command('ultron-studio:integrations-snapshot')
        ->dailyAt('07:10')
        ->timezone('America/Bogota')
        ->withoutOverlapping(10)
        ->onOneServer();
}

// ── Voz y telefonía: el monitor de la voz (Hito 8, contrato H8 D8-21, E2-3, E2-6 y E6-5) ──
// INERTE por defecto: solo se agenda si VOICE_MONITOR_ENABLED=true (y solo
// `true`, 1, on o yes). Es un bloque PROPIO, nunca dentro del del monitor del
// Studio (m-3 del H7, E2-6): la bandera del Studio solo programa órdenes del
// Studio sin argumentos. Con esta apagada no se programa NADA de la voz; los
// comandos siguen disponibles a mano. Vuelta atrás = la bandera en false +
// config:cache. Lecturas a los proveedores, salvo una escritura acotada: volver
// a colgar una saliente cortada que Twilio enseñe viva (B8-e, E13-9); cualquier
// llamada que Twilio enseñe sin su fila, una deriva o el techo de gasto ECHAN EL
// FRENO (lo suelta una persona, D8-19).
if ((bool) config('voice.monitor.enabled', false)) {
    // La conciliación (D8-21): las llamadas de la subcuenta en Twilio frente a
    // voice_calls, las ambiguas, las salientes que perdieron su job, las
    // atascadas en dialing, la deriva de los agentes vigentes y el techo duro
    // de gasto. Cada 10 minutos en total: estas cinco pasadas, y la sexta (en
    // el minuto 0) es la de abajo, que hace lo mismo y además el número, así
    // que nunca corren dos a la vez en el mismo minuto. Diez minutos de cerrojo,
    // no las 24 h por defecto (lección del vigía).
    Schedule::command('voice:reconcile')
        ->cron('10,20,30,40,50 * * * *')
        ->withoutOverlapping(10)
        ->onOneServer();

    // La deriva del NÚMERO importado (E2-3, E4-8, E6-5): un GET por número a
    // ElevenLabs para ver a qué agente apunta frente al publicado en canario.
    // Se suma a la conciliación de llamadas, no la sustituye. Cada hora.
    Schedule::command('voice:reconcile --numbers')
        ->hourly()
        ->withoutOverlapping(10)
        ->onOneServer();

    // La salud del webhook post-llamada (D8-21, decisión f; auditoría B-T5):
    // dos GET de solo lectura comparan el webhook y los dos secretos registrados
    // con las referencias de Laravel; si no coinciden, incidente de IRON GUARD
    // (no echa el freno). Cada hora, en el minuto 5: nunca a la vez que la del
    // número (minuto 0) ni que la conciliación (10, 20…).
    Schedule::command('voice:webhook-health')
        ->hourlyAt(5)
        ->withoutOverlapping(10)
        ->onOneServer();

    // La purga de los límites de las rutas públicas (PERF8 P8-3, E8-6): borra de la
    // caché database SOLO las claves voice-rl:* CADUCADAS. Local, sin red. Una vez al día.
    Schedule::command('voice:prune-rate-limits')
        ->dailyAt('04:40')
        ->timezone('America/Bogota')
        ->withoutOverlapping(10)
        ->onOneServer();

    // La verificación semanal de ElevenLabs (D8-12 y D8-21): un GET de solo
    // lectura al agente de verificación que guarda su evidencia; con ella de
    // menos de 30 días, el Studio sigue afirmando ELEVENLABS_VERIFIED. Sin la
    // voz en UNVERIFIED no llama a nada y sale con 1. Los lunes a las 07:20 de
    // Bogotá (12:20 UTC), tras la instantánea diaria del monitor del Studio.
    Schedule::command('ultron-studio:integrations-snapshot --verify-voice')
        ->weeklyOn(1, '07:20')
        ->timezone('America/Bogota')
        ->withoutOverlapping(10)
        ->onOneServer();
}

// ── Automatización comercial: el planificador del H9 (contrato H9 §9.1, D9-8, D9-14, D9-16; paquete OPS9) ──
// INERTE por defecto: solo se agenda con sus banderas. Bloque activado en la rama
// del Hito 9 con los tres nombres confirmados por B9-run (L9-58, L9-60) y los dos
// normalizadores de W9 y V9 (L9-81). El mando h9-outreach-scope.sh cuenta 5 tareas
// con el programador encendido (el monitor va plano y comprueba su propia bandera)
// y smoke-run-h9.sh exige 0 con él apagado.
// Es un bloque PROPIO (como el de la voz, E2-6 del H8): nunca dentro del del
// monitor del Studio. Solo se agenda si COMMERCIAL_AUTOMATION_SCHEDULER_ENABLED=true
// (y solo `true`, 1, on o yes); el monitor, además, con
// COMMERCIAL_AUTOMATION_MONITOR_ENABLED. Las órdenes revalidan la maestra
// (COMMERCIAL_AUTOMATION_ENABLED) en cada pasada: apagada, salen con 0 y lo dicen
// (§9.1). Vuelta atrás = la bandera en false + config:cache (nivel 0 de §9.2), o
// la PARADA GLOBAL (`outreach:stop --all`), que no toca el entorno. Con
// COMMERCIAL_AUTOMATION_MODE=shadow todo es simulado: ni un contacto ni red saliente.
if ((bool) config('outreach.scheduler.enabled', false)) {
    // El barrido (etapas 1–5 de D9-8): ingesta de eventos y hechos por lotes,
    // prefiltros indexados, evaluación, prioridad y plan. Cada 10 minutos: los
    // hechos se miden en días y el tope es de 500 inscripciones por pasada
    // (L9-18). Diez minutos de cerrojo, no las 24 h por defecto (lección del vigía).
    Schedule::command('outreach:sweep')
        ->everyTenMinutes()
        ->withoutOverlapping(10)
        ->onOneServer();

    // Los normalizadores de los canales automáticos (D9-8 etapa 8, D9-10): los
    // estados de Meta, las respuestas y la palabra de baja de WhatsApp (W9) y el
    // final de las llamadas de voz por estrategia (V9), convertidos en resultados.
    // Van antes del despacho: el programador corre en orden las tareas del mismo
    // minuto. Apagada la maestra salen con 0 sin leer la base y lo dicen (L9-81).
    Schedule::command('outreach:whatsapp-outcomes')
        ->everyMinute()
        ->withoutOverlapping(10)
        ->onOneServer();

    Schedule::command('outreach:voice-outcomes')
        ->everyFiveMinutes()
        ->withoutOverlapping(10)
        ->onOneServer();

    // El despacho (etapas 6–9): reevaluación en el instante, autorización,
    // reserva atómica del presupuesto (L9-2) y ejecución por el canal (en sombra,
    // SimulatedChannel). Cada minuto: la PARADA GLOBAL promete ≤ 60 s (D9-14) y
    // el plan trabaja con `not_before`. ≤ 50 trabajos por pasada (D9-16).
    // L9-58: en línea (≤ 50 por pasada, cada uno en su transacción): sin cola ni
    // worker nuevos hasta la fase en vivo; sin consumidor del carril `commercial`,
    // lo encolado quedaría `claimed` y la conciliación lo marcaría `ambiguous`.
    Schedule::command('outreach:dispatch --inline')
        ->everyMinute()
        ->withoutOverlapping(10)
        ->onOneServer();

    // El monitor (D9-14): SOLO apaga. Abre frenos (de estrategia, de canal o
    // global) e incidentes de IRON GUARD (fuente `outreach`) ante opt-out, fallos,
    // gasto, `unknown_send`, la cadena ilegible o una estrategia a la deriva; nunca
    // suelta nada. Cada 10 minutos, tras su propia bandera.
    // Va plano dentro del bloque (la guarda de SEC9-a solo admite órdenes outreach:*
    // con argumentos literales): la propia orden comprueba su bandera,
    // outreach.monitor.enabled, además de la maestra; apagadas, sale con 0 y lo dice.
    Schedule::command('outreach:monitor')
        ->everyTenMinutes()
        ->withoutOverlapping(10)
        ->onOneServer();
}

// ── ULTRON STUDIO · Plantillas de WhatsApp de Meta desde el CRM (PLANTILLAS_META_PLAN §5.5 y §5.12; L9-96) ──
// Un bloque PROPIO, nunca dentro del monitor del Studio (como la voz y el H9): la
// sincronización por sondeo lee en Meta el estado de las plantillas enviadas desde
// el CRM (y de las registradas a mano) y registra, sola, lo que cambió en
// `ultron_studio_template_meta_checks`, que es lo que lee el canal de W9. 1–3 GET a
// Graph por pasada (unas 864 al día como mucho, frente a 5 000 por hora de Meta).
// INERTE por defecto: solo se agenda con ULTRON_STUDIO_META_TEMPLATES_SYNC_ENABLED=true
// (y solo `true`, 1, on o yes); la orden revalida además la maestra
// (ULTRON_STUDIO_META_TEMPLATES_ENABLED) en cada pasada: apagada, sale con 0 y lo
// dice. Lo único que esta bandera programa es esa orden, sin argumentos (enmienda
// de la guarda del programador: UltronEngineIsolationTest). Vuelta atrás = la
// bandera en false + config:cache. Diez minutos de cerrojo, no las 24 h por defecto.
if ((bool) config('ultron_studio.meta_templates.sync_enabled', false)) {
    Schedule::command('ultron-studio:meta-templates-sync')
        ->everyFiveMinutes()
        ->withoutOverlapping(10)
        ->onOneServer();
}
