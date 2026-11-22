<?php
// Sonda local, sin base de datos: la regla de beneficios (P-4) contra el catálogo REAL de producción (leído en solo lectura).
require getcwd().'/vendor/autoload.php';
$B = 'App\\Services\\Marketing\\Ultron\\UltronBenefitAttribution';
$cat = json_decode(file_get_contents($argv[1]), true);
$falsos = 0; $propios = 0; $ajenos = 0; $cazados = 0;
$norm = fn (string $s) => trim(preg_replace('/[^a-z0-9 ]+/', ' ', strtr(mb_strtolower($s), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n'])));
foreach ($cat as $p) {
    $mios = array_map($norm, (array) $p['benefits']);
    // (1) cada beneficio PROPIO, dicho de ese plan, no debe marcar
    foreach ((array) $p['benefits'] as $b) {
        $limpio = trim($b, " \t\n\r\0\x0B\"[]");
        if ($limpio === '') { continue; }
        $propios++;
        $r = $B::misattributed('El '.$p['name'].' incluye '.mb_strtolower($limpio).'.', $cat);
        if ($r !== []) { $falsos++; if ($falsos <= 6) { echo "FALSO POSITIVO · {$p['name']} · «{$limpio}» → ".json_encode($r, JSON_UNESCAPED_UNICODE)."\n"; } }
    }
    // (2) un beneficio que SOLO tienen otros planes, dicho de este, debe marcar
    foreach ($cat as $q) {
        if ($q['id'] === $p['id']) { continue; }
        foreach ((array) $q['benefits'] as $b) {
            $limpio = trim($b, " \t\n\r\0\x0B\"[]");
            $n = $norm($limpio);
            if ($n === '' || str_word_count($n) < 2) { continue; }
            $loTengo = false;
            foreach ($mios as $m) { if ($m !== '' && (str_contains($m, $n) || str_contains($n, $m))) { $loTengo = true; break; } }
            if ($loTengo) { continue; }
            $ajenos++;
            if ($B::misattributed('El '.$p['name'].' incluye '.mb_strtolower($limpio).'.', $cat) !== []) { $cazados++; }
        }
    }
}
echo "beneficios propios probados: {$propios} · falsos positivos: {$falsos}\n";
echo "beneficios ajenos probados: {$ajenos} · cazados: {$cazados}\n";
// (3) el borrador real del 18-11 (texto ya expandido)
$real = 'Claro, el Plan Valera tiene una mensualidad de $65.000 COP y ofrece acceso ilimitado al gimnasio, asesoría semi personalizada y seguimiento en tus ejercicios.';
echo 'borrador real del 18-11: '.json_encode($B::misattributed($real, $cat), JSON_UNESCAPED_UNICODE)."\n";
echo 'lista típica: '.json_encode($B::misattributed('Claro. Tenemos Plan Valera ($65.000 COP), Plan Mensual ($80.000 COP) y Élite ($180.000 COP), todos de un mes. ¿Cuál le interesa?', $cat), JSON_UNESCAPED_UNICODE)."\n";
echo 'recomendación típica: '.json_encode($B::misattributed('Para bajar de peso le recomiendo el Élite ($180.000 COP), que incluye sesiones funcionales por agenda y rutinas guiadas por entrenadores.', $cat), JSON_UNESCAPED_UNICODE)."\n";
