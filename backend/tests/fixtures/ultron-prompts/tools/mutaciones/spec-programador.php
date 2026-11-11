<?php

// Mutaciones de routes/console.php contra las guardas del programador (UltronEngineIsolationTest).
$F = 'routes/console.php';
$ISO = ['sqlite', ['tests/Feature/UltronStudio/UltronEngineIsolationTest.php'], null];

$cond = "if ((bool) config('ultron_studio.meta_templates.sync_enabled', false)) {";
$cmd = "    Schedule::command('ultron-studio:meta-templates-sync')\n        ->everyFiveMinutes()\n        ->withoutOverlapping(10)\n        ->onOneServer();\n}";
$B = $cond."\n".$cmd;
$mon = "    Schedule::command('ultron-studio:monitor')\n";

return [
    'S1' => ['Meta: un trabajo del motor dentro del bloque', [[$F, $B, $cond."\n".substr($cmd, 0, -1)."    Schedule::command('ultron:turn-watchdog')->everyMinute();\n}"]], [$ISO]],
    'S2' => ['Meta: otra orden DEL STUDIO dentro del bloque', [[$F, $B, $cond."\n".substr($cmd, 0, -1)."    Schedule::command('ultron-studio:monitor')->hourly();\n}"]], [$ISO]],
    'S3' => ['Meta: la orden con un argumento', [[$F, "Schedule::command('ultron-studio:meta-templates-sync')", "Schedule::command('ultron-studio:meta-templates-sync --dry-run')"]], [$ISO]],
    'S4' => ['Meta: un else que programa con la bandera apagada', [[$F, $B, $B." else {\n    Schedule::command('ultron-studio:meta-templates-sync')->hourly();\n}"]], [$ISO]],
    'S5' => ['Meta: la condición con «|| true»', [[$F, $cond, "if ((bool) config('ultron_studio.meta_templates.sync_enabled', false) || true) {"]], [$ISO]],
    'S6' => ['Meta: la condición negada', [[$F, $cond, "if (! (bool) config('ultron_studio.meta_templates.sync_enabled', false)) {"]], [$ISO]],
    'S7' => ['Meta: cada minuto en vez de cada cinco', [[$F, $B, str_replace('->everyFiveMinutes()', '->everyMinute()', $B)]], [$ISO]],
    'S8' => ['Meta: sin onOneServer', [[$F, $B, str_replace("\n        ->onOneServer();", ';', $B)]], [$ISO]],
    'S9' => ['Meta: la bandera nombrada otra vez fuera del if', [[$F, $B, $B."\n\$sincronizaMeta = config('ultron_studio.meta_templates.sync_enabled');"]], [$ISO]],
    'S10' => ['Meta: if sin llaves', [[$F, $B, "if ((bool) config('ultron_studio.meta_templates.sync_enabled', false))\n".substr($cmd, 0, -2)]], [$ISO]],
    'S11' => ['Meta: un trabajo de cola en vez de la orden', [[$F, $B, str_replace("Schedule::command('ultron-studio:meta-templates-sync')", 'Schedule::job(new \\App\\Jobs\\SyncMetaAdsInsights)', $B)]], [$ISO]],
    'R1' => ['Monitor (regresión del ayudante): un trabajo del motor dentro de su bloque', [[$F, $mon, "    Schedule::job(new \\App\\Jobs\\SyncMetaAdsInsights)->hourly();\n".$mon]], [$ISO]],
    'R2' => ['Monitor: su condición con «|| true» (hueco del H7, cerrado el 11-11)', [[$F, "if ((bool) config('ultron_studio.monitor.enabled', false)) {", "if ((bool) config('ultron_studio.monitor.enabled', false) || true) {"]], [$ISO]],
    'R3' => ['Monitor: su condición negada', [[$F, "if ((bool) config('ultron_studio.monitor.enabled', false)) {", "if (! (bool) config('ultron_studio.monitor.enabled', false)) {"]], [$ISO]],
];
