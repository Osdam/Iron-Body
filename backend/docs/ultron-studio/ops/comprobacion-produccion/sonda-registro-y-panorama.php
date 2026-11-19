<?php

// SONDA DEL REGISTRO Y DEL PANORAMA (lead, 19-11-2026; cierre V2, apartado 4, H4-9 y H4-10).
//
// Ejecuta en PHP a secas (sin artisan, sin base de datos, sin red) las clases NUEVAS del commit que se va a
// desplegar, cargadas desde un directorio aparte ANTES de que el cargador de la aplicación resuelva las que están
// desplegadas, e imprime:
//   1. si alguna expresión regular de esas clases NO COMPILA con el PCRE de la máquina;
//   2. una huella de los resultados sobre un corpus fijo.
// Se ejecuta igual en local y en el servidor ANTES de desplegar y se comparan las huellas: el PCRE de producción
// (10.39) no es el de local (10.47), y una expresión que allí no compile daría resultados distintos o avisos
// (memoria: `\p{Extended_Pictographic}` compila en local y rompe producción). No escribe nada.
//
// Uso (desde el directorio `backend` de la aplicación, que pone el cargador y las clases que no cambian):
//   CLASES=/ruta/con/los/ficheros php /ruta/sonda-registro-y-panorama.php
// En CLASES van, planos, los ficheros del commit: UltronRegisterDetector.php, UltronFormalReplies.php,
// UltronReplyFitter.php, CommercialTurnPolicy.php, StrategyContract.php, ConversationMemoryService.php,
// UltronCommitService.php y UltronPromptCompiler.php.

error_reporting(E_ALL);
$avisos = [];
set_error_handler(static function (int $n, string $m, string $f, int $l) use (&$avisos): bool {
    $avisos[] = basename($f).':'.$l.' '.$m;

    return true;
});

require getcwd().'/vendor/autoload.php';

$dir = rtrim((string) getenv('CLASES'), '/');
$ns = 'App\\Services\\Marketing\\Ultron\\';
$nuevas = [
    $ns.'Prompts\\UltronPromptCompiler' => 'UltronPromptCompiler.php',
    $ns.'UltronRegisterDetector' => 'UltronRegisterDetector.php',
    $ns.'UltronFormalReplies' => 'UltronFormalReplies.php',
    $ns.'UltronReplyFitter' => 'UltronReplyFitter.php',
    $ns.'CommercialTurnPolicy' => 'CommercialTurnPolicy.php',
    $ns.'StrategyContract' => 'StrategyContract.php',
    $ns.'ConversationMemoryService' => 'ConversationMemoryService.php',
    $ns.'UltronCommitService' => 'UltronCommitService.php',
];
foreach ($nuevas as $clase => $fichero) {
    if (class_exists($clase, false)) {
        fwrite(STDERR, "YA ESTABA CARGADA (no sería la del commit): {$clase}\n");
        exit(2);
    }
    require_once $dir.'/'.$fichero;
}

// 1. Todas las expresiones regulares de las clases nuevas compilan.
$esRegex = static fn (mixed $v): bool => is_string($v) && preg_match('/^([\/#~]).+\1[a-zA-Z]*$/s', $v) === 1;
$expresiones = 0;
$rotas = [];
foreach (array_keys($nuevas) as $clase) {
    foreach ((new ReflectionClass($clase))->getReflectionConstants() as $constante) {
        $valor = $constante->getValue();
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
                $rotas[] = (new ReflectionClass($clase))->getShortName().'::'.$constante->getName().' → '.preg_last_error_msg();
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
$oferta = new ReflectionMethod($ns.'ConversationMemoryService', 'offerKindOf');
$memoria = (new ReflectionClass($ns.'ConversationMemoryService'))->newInstanceWithoutConstructor();

$borradores = [
    'Buenas tardes, bienvenido a Iron Body Neiva, que gusto atenderle. Cuénteme, ¿qué le gustaría conocer hoy del gimnasio?',
    'Claro, el Plan Valera tiene una mensualidad de $65.000 COP y ofrece acceso ilimitado al gimnasio, asesoría semi personalizada y seguimiento en tus ejercicios.',
    'Te entiendo, quieres saber el horario entre semana. El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m. ¿Quieres que te cuente también sobre las clases o los planes que tenemos?',
    'Claro. ¿Quieres que te cuente qué clases tenemos esta semana?',
    'Sí, claro. ¿Quieres que te pase la app para pagar desde ahí?',
    '¡Hola, buenas tardes! ¿En qué te puedo ayudar hoy?',
    'Es muy normal al inicio. ¿Qué es lo que más te preocupa?',
    'Le cuento: el gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m. Los sábados abre de 8:00 a. m. a 2:00 p. m. ¿Te sirve ese horario?',
    'Usted dijo «quiero algo para mí solo», y para eso el plan de un mes le sirve.',
    '"Anímate, ven a conocernos y cuéntame qué buscas"',
    'Venga cuando quiera. Tómese su tiempo y dígame qué días le quedan mejor.',
    'Cuando estés listo, pásate por la sede. Inscríbete en recepción cuando llegas.',
    'Estas son las opciones. Un té después de entrenar no cae mal.',
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
    'El pago lo haces tú mismo desde la app Iron Body Workout: creas tu cuenta con tu documento y ahí pagas con Nequi, PSE, tarjeta o Daviplata. También puedes pagar en el gimnasio cuando vengas. ¿Quieres que te pase la app para descargarla?',
    'No tengo registrada una visita tuya. Dime el día y la hora que te sirven y la dejo solicitada.',
    'Un texto que no está en la tabla y que debe volver igual: $80.000 COP, https://ironbodyneiva.cloud',
];

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

$j = static fn (mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo 'PCRE '.PCRE_VERSION.' · PHP '.PHP_VERSION."\n";
echo "expresiones regulares en las clases nuevas: {$expresiones} · no compilan: ".count($rotas)."\n";
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
exit($rotas === [] && $avisos === [] ? 0 : 1);
