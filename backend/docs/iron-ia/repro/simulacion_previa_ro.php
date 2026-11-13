<?php
// SOLO LECTURA: la evaluación de la campaña (RoutineRecoveryCampaign::evaluate) sobre producción,
// ANTES de desplegar. Se carga solo la clase nueva; evaluate() no escribe nada. Solo ids.
require '/var/www/api/backend/vendor/autoload.php';
$app = require '/var/www/api/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require '/tmp/RoutineRecoveryCampaign.php';
use Illuminate\Support\Facades\DB;
DB::statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
DB::beginTransaction();
$svc = new App\Services\Trainer\Recovery\RoutineRecoveryCampaign(app(App\Services\Trainer\IntegralPlanGenerationService::class));
$ev = $svc->evaluate('recuperacion-2026-11', '2026-11-13');
DB::rollBack();
$motivos = [];
foreach ($ev as $d) { $motivos[$d['reason']] = ($motivos[$d['reason']] ?? 0) + 1; }
arsort($motivos);
$cand = array_values(array_filter($ev, fn ($d) => $d['eligible']));
echo json_encode(['evaluadas' => count($ev), 'motivos' => $motivos, 'candidatas' => count($cand),
  'socios' => count(array_unique(array_column($cand, 'member_id'))), 'entrenadores' => array_count_values(array_map('strval', array_column($cand, 'trainer_id'))),
  'dias' => array_count_values(array_map('strval', array_column($cand, 'days'))), 'potencia_corrida' => array_count_values(array_map(fn ($c) => (string) ($c['power'] ?? 'previa'), $cand)),
  'ids_rutinas' => array_column($cand, 'routine_id')], JSON_UNESCAPED_UNICODE), "\n";
