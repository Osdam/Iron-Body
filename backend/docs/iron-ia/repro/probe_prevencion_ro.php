<?php
// SOLO LECTURA: ¿siguen los entrenadores generando con apps antiguas? ¿llegan los 7 campos?
// Solo ids y agregados: ningún nombre, documento, IP ni texto libre.
require '/var/www/api/backend/vendor/autoload.php';
$app = require '/var/www/api/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

DB::statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
DB::beginTransaction();
$out = [];
$q = function (string $k, string $sql) use (&$out) {
    DB::statement('SAVEPOINT s');
    try {
        $out[$k] = array_map(fn ($r) => (array) $r, DB::select($sql));
        DB::statement('RELEASE SAVEPOINT s');
    } catch (Throwable $e) {
        DB::statement('ROLLBACK TO SAVEPOINT s');
        $out[$k] = ['ERROR' => substr($e->getMessage(), 0, 200)];
    }
};

$corridas = "with c as (
    select x.id, x.created_at, (x.response_json::jsonb -> 'trainer_context') is not null con_contexto,
      x.response_json::jsonb -> 'trainer_context' -> 'received' recibidos,
      (select r.trainer_id from routines r where r.member_id = x.member_id and r.generated_by_ai
         and r.created_at between x.created_at - interval '5 seconds' and x.updated_at + interval '5 seconds' order by r.id desc limit 1) entrenador
    from nutrition_ai_runs x where x.mode = 'integral_plan' and x.status = 'ok' and x.created_at >= timestamp '2026-11-12 12:15:00')
";

// 1. Desde el arreglo del backend: por entrenador, generaciones con y sin el contexto.
$q('generaciones_desde_el_arreglo', $corridas."select entrenador, count(*) total,
    sum(case when con_contexto then 1 else 0 end) con_contexto, sum(case when con_contexto then 0 else 1 end) sin_contexto,
    max(case when con_contexto then null else created_at end) ultima_sin_contexto
  from c group by 1 order by 1");

// 2. En las corregidas: qué campos llegaron (de los 7).
$q('campos_recibidos', $corridas."select campo, count(*) veces from c, jsonb_array_elements_text(coalesce(recibidos, '[]'::jsonb)) campo
  where con_contexto group by 1 order by 1");

// 3. Versión de la app en las sesiones de los entrenadores que generan planes (últimos 30 días).
$q('sesiones_entrenadores', "select s.trainer_id, s.platform, s.app_version, max(s.last_seen_at) ultima_actividad, count(*) sesiones
  from trainer_device_sessions s
  where s.revoked_at is null and s.last_seen_at >= now() - interval '30 days'
    and s.trainer_id in (select distinct trainer_id from routines where generated_by_ai and trainer_id is not null)
  group by 1,2,3 order by 1,4 desc");

// 4. Tabletas de personal autorizadas: versión de la app.
$q('tabletas', "select platform, app_version, status, max(last_seen_at) ultima_actividad, count(*) n
  from authorized_devices where revoked_at is null group by 1,2,3 order by 4 desc nulls last");

DB::rollBack();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
