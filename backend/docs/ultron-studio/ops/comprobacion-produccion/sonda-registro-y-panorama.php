<?php

// SONDA DEL MOTOR (lead, 19-11-2026; ampliada el 21-11 a «regenerar antes de sustituir» y el 22-11 a la red de
// marcadores; cierre V2, apartado 4).
//
// Ejecuta en PHP a secas (sin artisan, sin base de datos, sin red) las clases NUEVAS O CAMBIADAS del commit que se
// va a desplegar, cargadas desde un directorio aparte ANTES de que el cargador de la aplicación resuelva las que
// están desplegadas, e imprime:
//   1. si alguna expresión regular de esas clases NO COMPILA con el PCRE de la máquina;
//   2. una huella de los resultados sobre un corpus fijo.
// Se ejecuta igual en local y en el servidor ANTES de desplegar y se comparan las huellas: el PCRE de producción
// (10.39) no es el de local (10.47), y una expresión que allí no compile daría resultados distintos o avisos
// (memoria: `\p{Extended_Pictographic}` compila en local y rompe producción). No escribe nada.
//
// Uso (desde el directorio `backend` de la aplicación, que pone el cargador y las clases que no cambian):
//   CLASES=/ruta php /ruta/sonda-registro-y-panorama.php
// En CLASES va, con su estructura, `app/Services/Marketing/Ultron/**` del commit (todos sus `.php`):
//   git archive <commit> backend/app/Services/Marketing/Ultron | tar -x -C /ruta --strip-components=1

error_reporting(E_ALL);
$avisos = [];
set_error_handler(static function (int $n, string $m, string $f, int $l) use (&$avisos): bool {
    $avisos[] = basename($f).':'.$l.' '.$m;

    return true;
});

require getcwd().'/vendor/autoload.php';

$dir = rtrim((string) getenv('CLASES'), '/');
$raiz = $dir.'/app/Services/Marketing/Ultron';
$ns = 'App\\Services\\Marketing\\Ultron\\';
$nuevas = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
foreach ($it as $fichero) {
    // Los `._*` son los ficheros de atributos que mete el tar de macOS: no son clases.
    if ($fichero->getExtension() !== 'php' || str_starts_with($fichero->getFilename(), '.')) {
        continue;
    }
    $relativa = substr($fichero->getPathname(), strlen($raiz) + 1, -4);
    $nuevas[$ns.str_replace('/', '\\', $relativa)] = $fichero->getPathname();
}
ksort($nuevas);
foreach ($nuevas as $clase => $ruta) {
    if (class_exists($clase, false) || interface_exists($clase, false) || enum_exists($clase, false)) {
        fwrite(STDERR, "YA ESTABA CARGADA (no sería la del commit): {$clase}\n");
        exit(2);
    }
    require_once $ruta;
}

// 1. Todas las expresiones regulares de las clases del commit compilan.
$esRegex = static fn (mixed $v): bool => is_string($v) && preg_match('/^([\/#~]).+\1[a-zA-Z]*$/s', $v) === 1;
$expresiones = 0;
$rotas = [];
foreach (array_keys($nuevas) as $clase) {
    if (! class_exists($clase, false)) {
        continue;
    }
    $r = new ReflectionClass($clase);
    foreach ($r->getReflectionConstants() as $constante) {
        if ($constante->getDeclaringClass()->getName() !== $clase) {
            continue;
        }
        try {
            $valor = $constante->getValue();
        } catch (Throwable $e) {
            $rotas[] = $r->getShortName().'::'.$constante->getName().' → no se pudo leer: '.$e->getMessage();

            continue;
        }
        $valores = [$valor];
        if (is_array($valor)) {
            $valores = [];
            array_walk_recursive($valor, static function (mixed $v) use (&$valores): void {
                $valores[] = $v;
            });
        }
        foreach ($valores as $v) {
            if (! $esRegex($v)) {
                continue;
            }
            $expresiones++;
            if (@preg_match($v, 'una prueba cualquiera') === false) {
                $rotas[] = $r->getShortName().'::'.$constante->getName().' → '.preg_last_error_msg();
            }
        }
    }
}

// 2. El corpus.
$D = $ns.'UltronRegisterDetector';
$F = $ns.'UltronFormalReplies';
$A = $ns.'UltronReplyFitter';
$P = $ns.'CommercialTurnPolicy';
$S = $ns.'StrategyContract';
$O = $ns.'UltronPlansOverview';
$B = $ns.'UltronBenefitAttribution';
$N = $ns.'UltronRegenerationNotes';
$X = $ns.'UltronDraftDefects';
$G = $ns.'UltronReplyOrigin';
$oferta = new ReflectionMethod($ns.'ConversationMemoryService', 'offerKindOf');
$memoria = (new ReflectionClass($ns.'ConversationMemoryService'))->newInstanceWithoutConstructor();

$borradores = [
    'Buenas tardes, bienvenido a Iron Body Neiva, que gusto atenderle. Cuénteme, ¿qué le gustaría conocer hoy del gimnasio?',
    'Claro, el Plan Valera tiene una mensualidad de $65.000 COP y ofrece acceso ilimitado al gimnasio, asesoría semi personalizada y seguimiento en tus ejercicios.',
    'Te entiendo, quieres saber el horario entre semana. El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m. ¿Quieres que te cuente también sobre las clases o los planes que tenemos?',
    'Claro. ¿Quieres que te cuente qué clases tenemos esta semana?',
    '¡Hola! Buenas tardes. ¿En qué te puedo ayudar hoy?',
    'Le cuento: el gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m. Los sábados abre de 8:00 a. m. a 2:00 p. m. ¿Te sirve ese horario?',
    'Usted dijo «quiero algo para mí solo», y para eso el plan de un mes le sirve.',
    '"Anímate, ven a conocernos y cuéntame qué buscas"',
    'Venga cuando quiera. Tómese su tiempo y dígame qué días le quedan mejor.',
    'Cuando estés listo, pásate por la sede. Inscríbete en recepción cuando llegas.',
    'Le dejo la app: Android: https://play.google.com/store/apps/details?id=x · iPhone: https://apps.apple.com/app/id1 ¿Quieres que te ayude a instalarla?',
    'El {{PLAN_NAME}} cuesta {{PLAN_PRICE}} al mes. ¿Le cuento qué incluye?',
    'Dijo "quiero empezar ya y que sea rápido, sin tanta vuelta ni tanto papeleo, tú me dices',
];
$entrantes = [
    '¿Qué planes tienen?', '¿Qué planes tienen y cuánto cuestan?', '¿Qué planes tienen y cuánto cuesta la mensualidad?', '¿Cuáles son los precios?',
    '¿Qué precios manejan?', 'Info de precios', 'Las tarifas', '¿Cuánto valen las membresías?', '¿Tienen planes?', 'Precios?', '¿Planes?', '¿Y los planes?',
    'Hola, precios por favor', 'Buenas tardes, planes y precios', 'Info planes', 'Mándame la lista de planes', 'Quiero conocer los planes y sus precios',
    '¿Cuánto cuesta el Mensual y qué otros hay?', '¿Cuánto cuesta el Mensual?', '¿Cuánto cuesta la mensualidad?', '¿Cuánto cuesta?', 'Precios del mensual', 'Precio?',
    '¿Qué planes tienen para estudiantes?', '¿Qué planes tienen de un mes?', '¿Qué opciones de pago tienen?', '¿Qué opciones de horario hay?',
    'No me interesan los planes, gracias', 'Quiero hablar con un asesor sobre los planes', '¿Cuál de los planes me recomienda para bajar de peso?',
    'Ya vi los planes, ¿cuál me recomiendas?', 'Tengo una lesión de rodilla, ¿qué planes tienen?', '¿Cuál es el horario y cuánto cuestan los planes?',
    'Quiero ir el sábado a las 10 de la mañana. ¿Qué planes tienen?', '¿Qué planes tienen y cómo se paga?', '¿Qué clases tienen y qué planes manejan?',
    'Ya pagué uno de los planes y no me llega nada', 'Dejen de mandarme los planes', 'Me gustaron los planes', 'Los planes que vi ayer', 'Hola', 'Quiero bajar de peso',
];
$ofertas = [
    '¿Quiere que le pase la app para descargarla?', '¿Quiere que le cuente cómo empezar?', '¿Le gustaría que le envíe el enlace de pago?', '¿Desea agendar una visita de cortesía?',
    '¿Sabe qué quiere decir el enlace de pago?', '¿El equipo desea revisar su caso?', '¿Quieres que te pase la app para descargarla?', '¿Le parece si le cuento los planes?',
];
$laravel = [
    'Sí, claro. Cuéntame qué buscas —bajar grasa, ganar masa o coger el hábito— y te digo el plan que mejor te encaja y su precio.',
    'Te entiendo. Más que pagar por pagar, la idea es que sientas que lo vas a aprovechar. ¿Quieres que te cuente para quién sí vale la pena el mensual?',
    'No tengo registrada una visita tuya. Dime el día y la hora que te sirven y la dejo solicitada.',
    'Un texto que no está en la tabla y que debe volver igual: $80.000 COP, https://ironbodyneiva.cloud',
];
// «Regenerar antes de sustituir»: la lista del modelo, los beneficios, las notas y los defectos.
$catalogo = [
    ['id' => 3, 'name' => 'Plan Valera', 'duration_days' => 30, 'benefits' => ['Acceso ilimitado al gimnasio', 'Asesoría de entrenador']],
    ['id' => 4, 'name' => 'Plan Mensual', 'duration_days' => 30, 'benefits' => ['Clases grupales', 'Acceso ilimitado al gimnasio']],
    ['id' => 8, 'name' => 'Élite', 'duration_days' => 30, 'benefits' => ['Clases grupales', 'Plan nutricional personalizado', 'Sauna']],
    ['id' => 9, 'name' => 'Plan Semana', 'duration_days' => 7, 'benefits' => ['Siete días de acceso al gimnasio y clases grupales']],
];
$po = [3, 4, 8];
$textos = [3 => 'Plan Valera ($65.000 COP)', 4 => 'Plan Mensual ($80.000 COP)', 8 => 'Élite ($180.000 COP)'];
$listas = [
    'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes. ¿Cuál le interesa más?',
    'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}, los dos de un mes. ¿Cuál le interesa?',
    'Claro. Tenemos {{PLAN_1}}. ¿Le cuento qué incluye?',
    'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_4}}. ¿Cuál le interesa?',
    'Claro. Tenemos el Plan Valera {{PLAN_1}} y {{PLAN_2}}. ¿Cuál le interesa?',
    'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}. El {{PLAN_NAME}} es el más pedido. ¿Cuál le interesa?',
    'Claro. Tenemos {{PLAN_1}} y {{PLAN_1}}. ¿Cuál le interesa?',
    'Claro. Tenemos {{PLAN_1}} por $65.000 y {{PLAN_2}}. ¿Cuál le interesa?',
    'Claro. Tenemos {{ PLAN_1 }} y {{PLAN_2}}; ¿cuál te interesa más?',
];
$beneficios = [
    'El Plan Valera incluye acceso ilimitado al gimnasio y asesoría de entrenador.',
    'El Plan Valera incluye clases grupales y sauna.',
    'El Plan Valera no incluye clases grupales.',
    'Élite incluye plan nutricional personalizado; el Plan Valera, acceso ilimitado al gimnasio.',
    'Con el Plan Mensual tiene CLASES GRUPALES y Sauna.',
    'El Plan Semana trae siete días de acceso al gimnasio y clases grupales.',
];
$sp = ['m' => 'studio', 't' => 'profesional', 'n' => 200, 'o' => 1];

$casos = [];
foreach ($borradores as $i => $t) {
    $sin = $A::withoutFinalQuestion($t);
    $casos["borrador {$i}"] = [
        'marcas' => $D::tuteo($t, [['name' => 'Tu Plan']]),
        'frases' => count($A::sentences($t)),
        'sin pregunta' => $sin,
        'marcas sin pregunta' => $sin === null ? null : $D::tuteo($sin),
        'a 200' => $A::draft($t, 200),
        'descubre' => [$S::asksDiscovery($t, 'profesional'), $S::asksDiscovery($t)],
    ];
}
foreach ($entrantes as $t) {
    $casos['entrante · '.$t] = [
        'panorama' => [$P::pideElPanorama($t, ['intent' => 'pricing_question'], []), $P::pideElPanorama($t, ['intent' => 'not_interested'], [])],
        'precio' => $P::preguntaPorPrecio($t),
    ];
}
foreach ($ofertas as $t) {
    $casos['oferta · '.$t] = [$oferta->invoke($memoria, $t, 'profesional'), $oferta->invoke($memoria, $t, null)];
}
foreach ($laravel as $i => $t) {
    $casos["laravel {$i}"] = [$F::segun('profesional', $t), $F::segun('cercano', $t), $F::segun(null, $t), $F::aplica('profesional'), $F::aplica('entusiasta')];
}
foreach ($listas as $i => $t) {
    $expandida = $O::expand($t, $textos, $po);
    $casos["lista {$i}"] = [
        'verifica' => $O::verify($t, $po, $catalogo),
        'sin lista' => $O::verify($t, [], $catalogo),
        'expandida' => $expandida,
        'defectos' => $X::of(['reply_draft' => $t, 'intent' => 'pricing_question', 'recommended_plan_id' => 3, 'tools_requested' => []], (string) $expandida['text'], $sp, $po, $catalogo, '¿Qué planes tienen y cuánto cuestan?'),
    ];
}
foreach ($beneficios as $i => $t) {
    $casos["beneficio {$i}"] = $B::misattributed($t, $catalogo);
}
$casos['marcadores'] = [$O::markers(3), $O::signedList($po), $O::signedList([3, 3]), $O::signedList('x'), $O::isMoneyTurn('payment_link_request', []), $O::isMoneyTurn('pricing_question', ['payment_link_send'])];
$casos['notas'] = array_map(static fn (array $e): array => [$e, $N::issue((string) ($e['code'] ?? $e[0] ?? ''), $e['detail'] ?? ($e[1] ?? null))], $N::entries());
$casos['origen'] = [
    $G::classify(['critic' => ['verdict' => 'pass', 'attempt' => 1]]),
    $G::classify(['critic' => ['verdict' => 'fail', 'attempt' => 2], 'fallback_mode' => 'SAFE_CURATED_REPLY']),
    $G::classify(['policy_violations' => ['policy_greeting_sells'], 'policy_discarded_draft' => 'x', 'critic' => ['verdict' => 'pass', 'attempt' => 1]]),
    $G::classify(['visit_hour' => ['mode' => 'ask']]),
    $G::classify(['critic' => ['verdict' => 'pass', 'attempt' => 3], 'regeneration' => ['drafts' => 3, 'critics' => 2, 'checks' => 2, 'rewrites' => [['by' => 'server', 'codes' => ['register_tuteo'], 'issues' => ['naturalness']], ['by' => 'critic', 'codes' => [], 'issues' => ['relevance']]]]]),
];

// La red de marcadores (ronda de correcciones, C1): lo que tiene forma de marcador no sale por WhatsApp.
// Las 13 primeras formas tienen que pararse y las 7 últimas tienen que salir (las mismas de
// `UltronDraftPlaceholdersTest`); se imprime la cuenta para verla también en el servidor.
$M = $ns.'UltronDraftPlaceholders';
$formas = [
    'Tenemos {{PLAN_1}} y otro.',
    'Tenemos el plan }} de un mes.',
    'Le dejo {{ la app.',
    'Claro. Tenemos PLAN_1 y PLAN_2, los dos de un mes. Le dejo la app.',
    'Claro. Tenemos {PLAN_1} y {PLAN_2}, los dos de un mes.',
    'Tenemos { PLAN_3 } hoy.',
    'El plan cuesta PLAN_PRICE al mes.',
    'El PLAN_NAME es el más pedido.',
    'Dura PLAN_DURATION.',
    'Aquí los tiene: APP_LINKS',
    'Tenemos plan_1 y plan_2.',
    '¿PLAN_2?',
    'Tenemos Plan PLAN_PRICE ($65.000 COP).',
    'Claro. Tenemos Plan Valera ($65.000 COP), Plan Mensual ($80.000 COP) y Élite ($180.000 COP), todos de un mes. ¿Cuál le interesa más?',
    'El PLAN DÍA cuesta $10.000 COP.',
    'El Plan 1 y el Plan 2 son de un mes.',
    'Horario {lunes a viernes}: de 5:00 a. m. a 10:00 p. m.',
    'Le mando PLAN_PRICES_X y PLAN_10X por error de copia.',
    "Android: https://play.google.com/store/apps/details?id=com.ironbody\niPhone: https://apps.apple.com/app/id1",
    '',
];
$paran = 0;
$malClasificadas = [];
foreach ($formas as $i => $t) {
    $para = $M::looksLikeMarker($t);
    $paran += $para ? 1 : 0;
    if ($para !== ($i < 13)) {
        $malClasificadas[] = $i;
    }
    $limpio = $M::withoutMarkers($t);
    $casos["marcador {$i}"] = [$para, $limpio, $M::looksLikeMarker($limpio), $M::nameWithoutBraces($t)];
}

$j = static fn (mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo 'PCRE '.PCRE_VERSION.' · PHP '.PHP_VERSION."\n";
echo 'red de marcadores: '.$paran.' de '.count($formas).' formas no salen (esperado: 13) · mal clasificadas: '.($malClasificadas === [] ? 'ninguna' : implode(',', $malClasificadas))."\n";
echo 'clases del commit cargadas: '.count($nuevas)."\n";
echo "expresiones regulares en esas clases: {$expresiones} · no compilan: ".count($rotas)."\n";
foreach ($rotas as $r) {
    echo "  ROTA {$r}\n";
}
echo 'avisos de PHP: '.count($avisos)."\n";
foreach (array_slice($avisos, 0, 10) as $a) {
    echo "  AVISO {$a}\n";
}
foreach ($casos as $nombre => $r) {
    echo sprintf("%-78s %s\n", mb_substr($nombre, 0, 78), substr(hash('sha256', $j($r)), 0, 10));
}
echo 'casos: '.count($casos).' · preg_last_error='.preg_last_error()."\n";
echo 'HUELLA '.substr(hash('sha256', $j($casos)), 0, 16)."\n";
exit($rotas === [] && $avisos === [] && $malClasificadas === [] ? 0 : 1);
