<?php
// SONDA DEL AJUSTADOR (lead, 13-11-2026): ejecuta la clase PURA `UltronReplyFitter` con los textos de la prueba real y
// de las revisiones, e imprime una huella de los resultados. Se ejecuta igual en local y en el servidor ANTES de
// desplegar: el PCRE de producción (10.39) no es el de local (10.47), y una expresión que allí no compile daría
// resultados distintos o avisos. No toca la base de datos ni escribe nada.
use App\Services\Marketing\Ultron\UltronReplyFitter as F;

if (! class_exists(F::class)) {
    require getenv('AJUSTADOR') ?: '/tmp/UltronReplyFitter.php';
}
$horario = 'Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases. ¿En qué franja te queda mejor entrenar?';
$casos = [
    'medida con enlaces' => F::measure("Te dejo la app:\nAndroid: https://play.google.com/store/apps/details?id=x · iPhone: https://apps.apple.com/app/id1 · Web: https://ironbodyneiva.cloud"),
    'frases del horario' => count(F::sentences($horario)),
    'horario a 200' => F::schedule($horario, 200, '¿Cuál es el horario de atención entre semana?'),
    'horario a 160' => F::schedule($horario, 160, '¿Cuál es el horario de atención entre semana?'),
    'horario sabados a 160' => F::schedule($horario, 160, '¿y los sábados?'),
    'abreviatura' => F::sentences('Estamos en la Av. La Toma # 10-20, barrio Altico. ¿Le gustaria agendar una visita?'),
    'negacion' => F::sentences('Creo que no. Mañana te cuento.'),
    'lista numerada' => count(F::sentences("Te cuento lo que incluye el Élite:\n1. Acceso total al gimnasio.\n2. Clases grupales funcionales todos los días.\n3. Seguimiento con IRON IA en la app. ¿Te animas a empezar esta semana?")),
    'borrador: solo la pregunta final' => F::draft('Con gusto. Nuestro horario entre semana es de 5:00 a. m. a 10:00 p. m. y los fines de semana y festivos abrimos de 8:00 a. m. a 2:00 p. m. para que entrenes cuando mejor le quede. ¿Le gustaría conocer nuestros planes?', 200),
    'borrador: muletilla (B-1)' => F::draft('Te entiendo. Muchas personas llegan sin haber entrenado nunca y aquí el equipo de entrenadores acompaña cada paso desde el primer día para que nadie se sienta perdido ni juzgado, siempre pendiente de ti. ¿Qué es lo que más te preocupa?', 160),
    'borrador: condicion (S-1)' => F::draft('Te reservo el cupo para mañana a las 6 p. m. Ojo: todavía no está confirmado hasta que pagues en recepción, porque los cupos se asignan por orden de pago y no se guardan sin el comprobante del sistema.', 160),
    'borrador: pregunta con cifra' => F::draft('El Plan Mensual incluye acceso ilimitado al gimnasio, zona de pesas y maquinaria y asesoría semipersonalizada con el entrenador de planta durante todo el mes. ¿Te animas con el Plan Mensual por $80.000 COP?', 200),
    'curado medico a 200' => F::curated('Gracias por contarlo. Con una lesión es mejor no recomendar ejercicios específicos por aquí para no darte una orientación irresponsable. Te dejo marcado que necesitas revisión del equipo, y mientras tanto puedo ayudarte con información general de planes o ubicación.', 200),
    'ficha por piezas a 200' => F::pieces(['Con gusto te cuento.', 'Iron Body Neiva es un centro de acondicionamiento físico en Neiva.', 'Iron Body Neiva está ubicado en Cl. 24 Sur #33-53, Neiva, Huila.', 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m.', '¿Qué te cuento más a fondo: los planes, las clases o cómo empezar?'], 200),
    'precio verificado con prioridad' => F::append('Claro que sí, con mucho gusto te ayudo con eso y con lo que necesites para empezar a entrenar con nosotros esta misma semana. ¿Quieres que te cuente los horarios?', 'El precio actual del Plan Mensual es $80.000 COP.', 160, true),
    'tope valido' => [F::cap(200), F::cap('200'), F::cap(159), F::cap(481)],
];
echo 'PCRE '.PCRE_VERSION.' · PHP '.PHP_VERSION.' · preg_last_error='.preg_last_error()."\n";
foreach ($casos as $nombre => $r) {
    $j = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo sprintf("%-34s %s  %s\n", $nombre, substr(hash('sha256', (string) $j), 0, 10), mb_substr((string) $j, 0, 150));
}
echo 'HUELLA '.substr(hash('sha256', json_encode($casos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 16)."\n";
