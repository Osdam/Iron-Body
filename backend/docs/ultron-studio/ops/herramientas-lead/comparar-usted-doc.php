<?php

// SOLO LECTURA (lead, 22-11-2026, gate 4-H): compara, texto por texto, cada «Hoy» con su «De usted» del documento
// que aprueba el propietario (`a4-textos-usted.md`, generado por una prueba con los productores reales), y el
// ajuste de los dos al tope publicado. No toca producto.
// Uso (desde backend/): php docs/ultron-studio/ops/herramientas-lead/comparar-usted-doc.php

require getcwd().'/vendor/autoload.php';

use App\Services\Marketing\Ultron\UltronReplyFitter as A;

$doc = (string) file_get_contents(getcwd().'/docs/ultron-studio/plans/informes-motores/a4-textos-usted.md');

$tok = static function (string $s): array {
    preg_match_all('/\p{L}+|\d[\d.,:]*\d|\d|[^\s\p{L}\d]/u', $s, $m);

    return $m[0];
};
$min = static fn (string $s): string => mb_strtolower($s);
$PROTEGIDAS = ['cop', 'iron', 'body', 'workout', 'wompi', 'app', 'gimnasio', 'equipo', 'plan', 'planes', 'precio', 'precios', 'pago', 'pagos', 'pagar',
    'enlace', 'enlaces', 'cobro', 'cortesía', 'visita', 'cita', 'clase', 'clases', 'horario', 'mensual', 'mensualidad', 'semana', 'día', 'días', 'mes', 'meses',
    'gratis', 'descuento', 'promoción', 'registrada', 'registrado', 'solicitud', 'solicitada', 'confirmó', 'confirma', 'confirmar', 'confirmada', 'cancelar',
    'cancelada', 'asesor', 'persona', 'recepción', 'sede', 'android', 'iphone', 'whatsapp', 'datos', 'autorización', 'contactar', 'mensajes',
    'nequi', 'pse', 'tarjeta', 'daviplata', 'documento', 'cuenta', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'sábados', 'domingo', 'domingos'];
$NEGACIONES = ['no', 'nunca', 'sin', 'ni', 'tampoco', 'nada', 'ningún', 'ninguna', 'ninguno'];
$cuenta = static function (array $tokens, array $lista) use ($min): array {
    $r = [];
    foreach ($tokens as $t) {
        $k = $min($t);
        if (in_array($k, $lista, true)) {
            $r[$k] = ($r[$k] ?? 0) + 1;
        }
    }
    ksort($r);

    return $r;
};
$duros = static function (array $tokens): array {
    $r = [];
    foreach ($tokens as $t) {
        if (preg_match('/^\d/u', $t) === 1 || in_array($t, ['$', '%', '{', '}', '@', '/', '#'], true)) {
            $r[] = $t;
        }
    }

    return $r;
};
$dif = static function (array $a, array $b): array {
    $n = count($a);
    $m = count($b);
    $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $L[$i][$j] = $a[$i] === $b[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
        }
    }
    $tramos = [];
    $viejo = [];
    $nuevo = [];
    $cierra = static function () use (&$tramos, &$viejo, &$nuevo): void {
        if ($viejo !== [] || $nuevo !== []) {
            $tramos[] = [implode(' ', $viejo), implode(' ', $nuevo)];
            $viejo = [];
            $nuevo = [];
        }
    };
    $i = 0;
    $j = 0;
    while ($i < $n && $j < $m) {
        if ($a[$i] === $b[$j]) {
            $cierra();
            $i++;
            $j++;
        } elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) {
            $viejo[] = $a[$i++];
        } else {
            $nuevo[] = $b[$j++];
        }
    }
    while ($i < $n) {
        $viejo[] = $a[$i++];
    }
    while ($j < $m) {
        $nuevo[] = $b[$j++];
    }
    $cierra();

    return $tramos;
};

// Las entradas, por sección.
$secciones = preg_split('/^## /mu', $doc);
array_shift($secciones);
$pares = [];
$porSeccion = [];
foreach ($secciones as $bloque) {
    $titulo = trim((string) strtok($bloque, "\n"));
    preg_match_all('/^- \*\*Hoy\*\* \(\d+\) «(.*?)»\n- \*\*De usted\*\* \(\d+\) «(.*?)»$/msu', $bloque, $m, PREG_SET_ORDER);
    $porSeccion[$titulo] = count($m);
    foreach ($m as $e) {
        $pares[] = [$titulo, $e[1], $e[2]];
    }
}
$distintos = [];
foreach ($pares as [$t, $h, $u]) {
    $distintos[$h."\x00".$u] ??= [$t, $h, $u];
}

$n = count($distintos);
$ok = ['duros' => 0, 'protegidas' => 0, 'negaciones' => 0, 'frases' => 0, 'preguntas' => 0, 'tope200' => 0, 'tope160' => 0];
$sinCambio = 0;
$excepciones = [];
$tramos = [];
$ajustados = ['200' => 0, '160' => 0];
foreach ($distintos as [$titulo, $hoy, $usted]) {
    $a = $tok($hoy);
    $b = $tok($usted);
    $sinCambio += $hoy === $usted ? 1 : 0;
    $r = [
        'duros' => $duros($a) === $duros($b),
        'protegidas' => $cuenta($a, $PROTEGIDAS) === $cuenta($b, $PROTEGIDAS),
        'negaciones' => $cuenta($a, $NEGACIONES) === $cuenta($b, $NEGACIONES),
        'frases' => count(A::sentences($hoy)) === count(A::sentences($usted)),
        'preguntas' => substr_count($hoy, '?') === substr_count($usted, '?'),
    ];
    // El ajuste al tope: ¿quedan las mismas frases, con las mismas cifras y el mismo vocabulario, en el original y en la variante?
    foreach ([200, 160] as $tope) {
        $fh = A::curated($hoy, $tope);
        $fu = A::curated($usted, $tope);
        $igual = ($fh === null) === ($fu === null);
        if ($igual && $fh !== null) {
            $ta = $tok($fh);
            $tb = $tok($fu);
            $igual = count(A::sentences($fh)) === count(A::sentences($fu)) && $duros($ta) === $duros($tb) && $cuenta($ta, $PROTEGIDAS) === $cuenta($tb, $PROTEGIDAS)
                && $cuenta($ta, $NEGACIONES) === $cuenta($tb, $NEGACIONES);
            if ($fu !== $usted) {
                $ajustados[(string) $tope]++;
            }
        }
        $r['tope'.$tope] = $igual;
    }
    foreach ($r as $k => $v) {
        $ok[$k] += $v ? 1 : 0;
    }
    if (in_array(false, $r, true)) {
        $excepciones[] = [implode(', ', array_keys(array_filter($r, static fn (bool $v): bool => ! $v))), $titulo, $hoy, $usted,
            A::curated($hoy, 200), A::curated($usted, 200), A::curated($hoy, 160), A::curated($usted, 160)];
    }
    foreach ($dif($a, $b) as [$v, $nu]) {
        $k = $v.' → '.$nu;
        $tramos[$k] = ($tramos[$k] ?? 0) + 1;
    }
}
arsort($tramos);

echo 'ENTRADAS «Hoy / De usted» EN EL DOCUMENTO: '.count($pares)." · DISTINTAS: {$n}\n";
foreach ($porSeccion as $t => $c) {
    echo sprintf("  %3d · %s\n", $c, $t);
}
echo "\nSOBRE LAS {$n} DISTINTAS:\n";
echo "sin ningún cambio (ya estaban de usted o no tratan a nadie): {$sinCambio}\n";
echo "mismas cifras, \$ y signos, en el mismo orden: {$ok['duros']} de {$n}\n";
echo 'mismo vocabulario comercial y de compromiso ('.count($PROTEGIDAS)." palabras vigiladas), mismas veces: {$ok['protegidas']} de {$n}\n";
echo "mismas negaciones, mismas veces: {$ok['negaciones']} de {$n}\n";
echo "mismo número de frases: {$ok['frases']} de {$n} · mismo número de preguntas: {$ok['preguntas']} de {$n}\n";
echo "ajustadas a 200 caracteres, quedan las mismas frases, cifras y vocabulario que en el original ajustado: {$ok['tope200']} de {$n} (se recortan {$ajustados['200']})\n";
echo "ajustadas a 160 caracteres, ídem: {$ok['tope160']} de {$n} (se recortan {$ajustados['160']})\n";
echo "\nEXCEPCIONES: ".count($excepciones)."\n";
foreach ($excepciones as $i => $e) {
    echo '  ['.($i + 1)."] {$e[1]} · cambia: {$e[0]}\n      HOY:   ".str_replace("\n", '⏎', $e[2])."\n      USTED: ".str_replace("\n", '⏎', $e[3])."\n";
    if (str_contains($e[0], 'tope')) {
        echo '      HOY a 200:   '.str_replace("\n", '⏎', (string) ($e[4] ?? '(no cabe)'))."\n      USTED a 200: ".str_replace("\n", '⏎', (string) ($e[5] ?? '(no cabe)'))."\n";
        echo '      HOY a 160:   '.str_replace("\n", '⏎', (string) ($e[6] ?? '(no cabe)'))."\n      USTED a 160: ".str_replace("\n", '⏎', (string) ($e[7] ?? '(no cabe)'))."\n";
    }
}
echo "\nTRAMOS QUE CAMBIAN (lo que había → lo que hay · veces): ".count($tramos).' distintos, '.array_sum($tramos)." en total\n";
foreach ($tramos as $k => $c) {
    echo sprintf("  %3d · %s\n", $c, $k);
}
