<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;

/**
 * Páginas legales PÚBLICAS servidas por el propio backend (HTML limpio, sin
 * login, sin JavaScript, sin PDF).
 *
 * Se sirven por DOS rutas que devuelven exactamente el mismo documento:
 *
 *   - `/privacy-policy.html`  → URL canónica declarada en Google Play Console.
 *   - `/api/legal/privacy`    → la que abre la app desde Perfil y el contrato.
 *
 * Que sean el mismo método y no dos ficheros es deliberado: hasta ahora la URL
 * de Play era un `.html` estático suelto en `public/`, fuera de git, que hablaba
 * del CRM y de WhatsApp y no mencionaba ni la app, ni el paquete, ni el
 * desarrollador, ni la conservación de datos. Google la rechazó por eso. Con un
 * único origen no puede volver a haber dos políticas distintas conviviendo.
 *
 * El contenido describe el tratamiento REAL del sistema (Ley 1581 de 2012,
 * Habeas Data). El contrato/consentimiento formal sigue siendo el documento
 * oficial que el usuario firma en la app.
 */
class LegalController extends Controller
{
    public function privacy(): Response
    {
        // Sin e() aquí: html() escapa el título. Escaparlo dos veces convertiría
        // una tilde o un & del nombre en la entidad literal en pantalla.
        $title = 'Política de Privacidad — '.(string) Config::get('legal.app_name');

        return $this->html($title, $title, $this->privacyBody());
    }

    public function terms(): Response
    {
        return $this->html(
            'Términos y Condiciones',
            'Términos y Condiciones — '.(string) Config::get('legal.brand'),
            $this->termsBody(),
        );
    }

    private function support(): string
    {
        return (string) Config::get('legal.privacy_email')
            ?: (string) Config::get('contracts.support_contact', 'Ironbodyneiva@gmail.com');
    }

    private function privacyBody(): string
    {
        $app = e((string) Config::get('legal.app_name'));
        $package = e((string) Config::get('legal.android_package'));
        $brand = e((string) Config::get('legal.brand'));
        $developer = e((string) Config::get('legal.developer_name'));
        $controller = e((string) Config::get('legal.controller_name'));
        $address = e((string) Config::get('legal.address'));
        $email = e($this->support());
        $phone = e((string) Config::get('legal.privacy_phone'));
        $url = e((string) Config::get('legal.privacy_url'));
        $updated = e((string) Config::get('legal.last_updated'));
        $evidence = (int) Config::get('legal.retention.moderation_evidence_days', 90);
        $storyHours = (int) Config::get('legal.retention.story_hours', 24);

        return <<<HTML
<p class="updated"><strong>Última actualización:</strong> {$updated}</p>

<h2>1. Identificación</h2>
<table class="id">
  <tr><th>Aplicación</th><td>{$app}</td></tr>
  <tr><th>Identificador Android (Google Play)</th><td><code>{$package}</code></td></tr>
  <tr><th>Servicio / marca</th><td>{$brand}</td></tr>
  <tr><th>Desarrollador / publicador en Google Play</th><td>{$developer}</td></tr>
  <tr><th>Responsable del tratamiento de datos</th><td>{$controller}</td></tr>
  <tr><th>Dirección</th><td>{$address}</td></tr>
  <tr><th>Contacto de privacidad</th><td><a href="mailto:{$email}">{$email}</a> · {$phone}</td></tr>
  <tr><th>Dirección de esta política</th><td><a href="{$url}">{$url}</a></td></tr>
</table>
<p><strong>{$developer}</strong> es el desarrollador y publicador de {$app} en
Google Play: es quien firma, publica y mantiene la aplicación en la tienda.</p>
<p><strong>{$controller}</strong> es el <em>responsable del tratamiento</em> de
los datos personales descritos en este documento: es quien decide para qué se
recogen y cómo se usan, y ante quien puedes ejercer tus derechos, conforme a la
Ley 1581 de 2012 y sus normas reglamentarias (Habeas Data, Colombia).</p>
<p>Son dos papeles distintos y se identifican por separado a propósito. Para
cualquier asunto de privacidad —acceso, rectificación, supresión de tus datos o
eliminación de tu cuenta— el destinatario es el responsable del tratamiento, en
el contacto indicado arriba.</p>

<h2>2. Alcance</h2>
<p>Esta política cubre la aplicación móvil <strong>{$app}</strong>
(<code>{$package}</code>) y los servicios de {$brand} a los que la app se
conecta: nuestro servidor <code>api.ironbodyneiva.cloud</code> y el sistema de
gestión del gimnasio.</p>
<p>No cubre lo que hagas fuera de la app: si nos escribes por WhatsApp, redes
sociales o te inscribes presencialmente en recepción, esos canales tienen su
propio tratamiento aunque el responsable sea el mismo.</p>

<h2>3. Datos que recopilamos</h2>
<p>Recopilamos únicamente lo que necesitamos para prestar el servicio. Casi todo
lo introduces tú; nada se obtiene de terceros sin tu conocimiento.</p>

<h3>3.1 Cuenta e identificación</h3>
<ul>
  <li>Nombre completo, número de documento, correo electrónico y teléfono.</li>
  <li>Género, fecha de nacimiento y edad derivada de ella.</li>
  <li>Objetivo físico, nivel de experiencia y lesiones u observaciones que declares.</li>
  <li>Identificadores internos de tu cuenta y su estado.</li>
  <li>Si eres menor de edad: nombre, documento, teléfono, correo y parentesco de
      tu padre, madre o acudiente, y su autorización.</li>
</ul>

<h3>3.2 Documento de identidad</h3>
<ul>
  <li>Fotografía del anverso y, cuando aplica, del reverso de tu documento.</li>
  <li>Tipo y número de documento, nombre y fecha de nacimiento leídos de él.</li>
</ul>
<p>La lectura del documento (OCR) ocurre <strong>en tu propio teléfono</strong>;
al servidor llega la imagen y el resultado, no se envía a ningún tercero. Las
imágenes se guardan en almacenamiento privado del servidor, nunca en una
dirección web pública.</p>

<h3>3.3 Datos biométricos — opcionales</h3>
<ul>
  <li>Fotografía de tu rostro, <em>solo si eliges</em> activar la verificación
      facial para el control de acceso.</li>
</ul>
<p>Es opcional y hay alternativa siempre: documento y código por SMS, o
verificación presencial en el gimnasio. Si además activas la huella o el
desbloqueo facial del teléfono para entrar más rápido, esos datos
<strong>no salen del dispositivo</strong>: los gestiona el sistema operativo y
nosotros no los recibimos ni los guardamos.</p>

<h3>3.4 Membresía, pagos y facturación</h3>
<ul>
  <li>Plan contratado, fechas de vigencia y estado de la membresía.</li>
  <li>Pagos: importe, fecha, medio, referencia y estado de la transacción.</li>
  <li>Si guardas un medio de pago: marca de la tarjeta, <strong>últimos cuatro
      dígitos</strong>, mes y año de vencimiento, correo asociado y el
      identificador que devuelve la pasarela.
      <strong>Nunca almacenamos el número completo de la tarjeta ni el CVV</strong>:
      esos datos los trata directamente la pasarela de pago.</li>
  <li>Datos de facturación cuando pides factura electrónica: tipo y número de
      documento, razón social, responsabilidades tributarias, correo, teléfono,
      dirección y ciudad.</li>
  <li>Contratos y consentimientos que firmas electrónicamente, con tu firma
      manuscrita digitalizada, la versión del documento y la fecha de aceptación.</li>
</ul>

<h3>3.5 Entrenamiento, salud y actividad física</h3>
<ul>
  <li>Rutinas asignadas, ejercicios y rutinas ocultas o favoritas.</li>
  <li>Entrenamientos realizados: series, repeticiones, peso, duración y récords
      personales.</li>
  <li>Evaluaciones físicas: peso, estatura, porcentaje de grasa y de masa
      muscular, perímetros corporales, lesiones y notas del entrenador.</li>
  <li>Valoraciones profesionales y seguimiento de progreso.</li>
  <li>Nutrición: objetivos, comidas registradas, alimentos, resúmenes diarios,
      favoritos y recomendaciones generadas.</li>
  <li>Fotografías de etiquetas nutricionales y códigos de barras que escanees,
      junto con el texto extraído.</li>
  <li>Reservas de clases, asistencia a clases y registros de acceso al gimnasio.</li>
  <li>Rachas semanales y días de actividad en la app.</li>
</ul>
<p>Estos datos son de <strong>bienestar y acondicionamiento físico</strong>. La
app no es un producto sanitario, no emite diagnósticos y no sustituye a un
profesional de la salud.</p>

<h3>3.6 Contenido que publicas y comunidad</h3>
<ul>
  <li>Estados con foto o vídeo, su texto, quién los ha visto y las reacciones.</li>
  <li>Bloqueos y reportes que envías o recibes.</li>
  <li>Cuando alguien reporta un contenido: una copia de ese contenido como
      prueba del caso, las decisiones de moderación y tus apelaciones.</li>
</ul>

<h3>3.7 IRON IA (asistente)</h3>
<ul>
  <li>El texto que escribes en el chat y las conversaciones completas.</li>
  <li>Audios que grabes y su transcripción.</li>
  <li>Imágenes y archivos que adjuntes.</li>
  <li>Contexto de tu entrenamiento, nutrición, progreso y membresía cuando es
      necesario para responderte.</li>
  <li>Registro técnico de uso: modelo empleado, tokens y coste estimado.</li>
</ul>
<p>IRON IA <strong>exige tu consentimiento expreso antes del primer envío</strong> y
puedes revocarlo cuando quieras desde <em>Perfil → Privacidad de IRON AI</em>.
Sin consentimiento, no se envía nada al proveedor de IA.</p>

<h3>3.8 Dispositivo, técnica y seguridad</h3>
<ul>
  <li>Token de notificaciones push (Firebase Cloud Messaging), identificador del
      dispositivo y plataforma (Android o iOS).</li>
  <li>Sesiones y dispositivos vinculados a tu cuenta, con su última actividad.</li>
  <li>Códigos de verificación por SMS y su estado (nunca el código en claro).</li>
  <li>Eventos de seguridad: inicios de sesión, cambios de teléfono, revocación de
      dispositivos y solicitudes de borrado, con dirección IP, versión de la app
      e identificación del dispositivo.</li>
  <li>Uso de módulos dentro de la app y días en que la abriste, para saber qué
      funciones sirven y cuáles no.</li>
  <li>Registros técnicos del servidor (errores, rendimiento) y de auditoría
      administrativa.</li>
</ul>
<p><strong>No usamos publicidad ni seguimiento entre aplicaciones.</strong> La app
no incorpora identificadores publicitarios, ni SDK de analítica de terceros, ni
perfilado con fines de marketing de terceros.</p>

<h2>4. Permisos del teléfono</h2>
<p>Cada permiso se pide <strong>en el momento en que vas a usar la función</strong>,
nunca al abrir la app, y puedes negarlo o retirarlo después desde los ajustes de
Android sin perder el acceso al resto del servicio:</p>
<ul>
  <li><strong>Cámara</strong>: fotografiar tu documento, publicar estados,
      escanear etiquetas y códigos de alimentos, la verificación facial si la
      activas y las funciones de visión de IRON IA.</li>
  <li><strong>Fotos y vídeos</strong>: elegir un archivo concreto que quieras
      subir. No leemos el resto de tu galería.</li>
  <li><strong>Micrófono</strong>: solo mientras hablas con IRON IA o grabas un
      vídeo con sonido. No hay grabación en segundo plano.</li>
  <li><strong>Notificaciones</strong>: avisos de clases, membresía, seguridad y
      decisiones de moderación.</li>
  <li><strong>Biometría del dispositivo</strong>: desbloqueo rápido opcional,
      resuelto por el sistema operativo.</li>
</ul>

<h2>5. Para qué usamos los datos</h2>
<ul>
  <li>Crear y mantener tu cuenta y verificar que eres quien dices ser.</li>
  <li>Gestionar tu membresía, tus reservas y tu acceso al gimnasio.</li>
  <li>Planificar y registrar tu entrenamiento y tu progreso físico.</li>
  <li>Responderte a través de IRON IA cuando lo autorizas.</li>
  <li>Cobrar, facturar y cumplir obligaciones contables y tributarias.</li>
  <li>Enviarte notificaciones operativas y recordatorios que puedes desactivar.</li>
  <li>Publicar y moderar el contenido de la comunidad.</li>
  <li>Proteger las cuentas: detectar accesos indebidos, prevenir el fraude y
      dejar traza de las acciones sensibles.</li>
  <li>Mejorar el producto sabiendo qué funciones se usan.</li>
</ul>
<p>No vendemos datos personales, no los cedemos a intermediarios de datos y no
los usamos para publicidad de terceros.</p>

<h2>6. Con quién se procesan o comparten</h2>
<p>No vendemos tus datos. Sí trabajamos con proveedores que los tratan
<strong>por nuestra cuenta y siguiendo nuestras instrucciones</strong>, solo con
lo imprescindible para su función:</p>
<table class="proc">
  <tr><th>Proveedor</th><th>Qué trata</th><th>Para qué</th></tr>
  <tr><td>Google (Firebase)</td><td>Token de notificaciones, identificador de sesión y archivos multimedia de los estados</td><td>Enviar notificaciones push y almacenar el contenido que publicas</td></tr>
  <tr><td>OpenAI, L.L.C.</td><td>Texto, audio, imágenes y contexto de entrenamiento que envías a IRON IA</td><td>Generar las respuestas del asistente, solo con tu consentimiento</td></tr>
  <tr><td>Wompi</td><td>Datos de la transacción y de la tarjeta</td><td>Procesar los pagos. El número de tarjeta lo trata la pasarela, no nosotros</td></tr>
  <tr><td>Twilio</td><td>Tu número de teléfono</td><td>Enviar los códigos de verificación por SMS</td></tr>
  <tr><td>Proveedor de facturación electrónica</td><td>Datos fiscales y del comprobante</td><td>Emitir la factura electrónica exigida por la DIAN</td></tr>
  <tr><td>Proveedor de alojamiento</td><td>Toda la información, en reposo</td><td>Operar el servidor de la aplicación</td></tr>
</table>
<p>También podemos entregar información a autoridades competentes cuando exista
una obligación legal o un requerimiento válido.</p>
<p>Distinguimos tres cosas y solo hacemos la tercera: <strong>vender datos</strong>
(no lo hacemos), <strong>compartirlos con terceros para sus propios fines</strong>
(no lo hacemos) y <strong>encargar su tratamiento a proveedores</strong> que los
usan únicamente para prestarnos el servicio (sí, y son los de la tabla).</p>

<h2>7. Transferencias internacionales</h2>
<p>Algunos de esos proveedores operan servidores fuera de Colombia, principalmente
en Estados Unidos. Cuando ocurre, la transferencia se ampara en la ejecución del
contrato contigo y en los compromisos contractuales de protección de datos que
mantenemos con cada proveedor.</p>

<h2>8. Seguridad de los datos</h2>
<p>Aplicamos medidas técnicas y organizativas razonables y proporcionadas al
riesgo:</p>
<ul>
  <li>Todo el tráfico entre la app y el servidor viaja cifrado con HTTPS/TLS. La
      app tiene prohibido el tráfico sin cifrar.</li>
  <li>Las imágenes de documento y de rostro se guardan en almacenamiento privado
      del servidor, fuera del directorio público: no hay URL directa que las sirva.</li>
  <li>El acceso a la aplicación exige sesión válida por dispositivo; las sesiones
      caducan y se pueden revocar desde tu perfil.</li>
  <li>Las acciones sensibles —eliminar la cuenta, cambiar de teléfono, revocar un
      dispositivo— exigen un segundo factor por SMS.</li>
  <li>Los códigos de verificación se guardan cifrados, con vigencia e intentos
      limitados, y nunca aparecen en los registros.</li>
  <li>El personal del gimnasio accede por roles y sus acciones quedan auditadas.</li>
  <li>La base de datos se respalda a diario y la restauración se prueba de forma
      periódica y automática.</li>
</ul>
<p>Ningún sistema conectado a internet es invulnerable, y no afirmamos lo
contrario. Si se produjera un incidente que afecte a tus datos personales,
actuaremos conforme a la normativa aplicable y te informaremos cuando proceda.</p>

<h2>9. Conservación de los datos</h2>
<p>Conservamos cada dato durante el tiempo necesario para la finalidad que lo
justifica, y no más. Estos son los plazos reales del sistema:</p>
<table class="ret">
  <tr><th>Dato</th><th>Cuánto se conserva</th></tr>
  <tr><td>Cuenta y perfil (nombre, documento, correo, teléfono, género, fecha de nacimiento, objetivo, nivel, lesiones)</td><td>Mientras la cuenta exista. Al eliminarla se anonimizan de inmediato.</td></tr>
  <tr><td>Imágenes del documento de identidad</td><td>Mientras la cuenta exista. Al eliminarla se <strong>borran del disco</strong>.</td></tr>
  <tr><td>Fotografía facial (verificación opcional)</td><td>Mientras mantengas activa la verificación facial. Al eliminar la cuenta se <strong>borra del disco</strong>.</td></tr>
  <tr><td>Datos del acudiente (cuentas de menores)</td><td>Mientras la cuenta exista, y después junto al contrato firmado por obligación legal.</td></tr>
  <tr><td>Entrenamientos, rutinas, series, repeticiones, peso y récords</td><td>Mientras la cuenta exista; es tu historial de progreso. Tras eliminarla quedan desvinculados de una persona identificada.</td></tr>
  <tr><td>Evaluaciones físicas, valoraciones y nutrición</td><td>Igual que el punto anterior.</td></tr>
  <tr><td>Reservas de clases y registros de asistencia</td><td>Mientras la cuenta exista; después, desvinculados, como registro de uso del servicio.</td></tr>
  <tr><td>Estados publicados (foto o vídeo)</td><td><strong>{$storyHours} horas.</strong> Caducan solos y una tarea automática borra el registro y el archivo. Puedes borrarlos antes.</td></tr>
  <tr><td>Pruebas de un contenido reportado</td><td>Hasta <strong>{$evidence} días</strong> desde el cierre del caso; después se eliminan automáticamente.</td></tr>
  <tr><td>Conversaciones y adjuntos de IRON IA</td><td>Mientras la cuenta exista. Al eliminarla se <strong>borran</strong> las conversaciones, los mensajes y los archivos adjuntos.</td></tr>
  <tr><td>Token de notificaciones, vínculos de dispositivo y códigos de verificación</td><td>Mientras el dispositivo siga vinculado. Al eliminar la cuenta se <strong>borran</strong>.</td></tr>
  <tr><td>Sesiones de dispositivo</td><td>Caducan por inactividad y se revocan al cerrar sesión o al eliminar la cuenta.</td></tr>
  <tr><td>Eventos de seguridad y registros de auditoría</td><td>Se conservan como prueba frente a accesos indebidos, fraude y reclamaciones, ya desvinculados de tus datos identificativos.</td></tr>
  <tr><td>Pagos, contratos firmados y facturas electrónicas</td><td>Se conservan por <strong>obligación legal, contable y tributaria</strong> colombiana, incluso después de eliminar la cuenta, con los datos personales anonimizados salvo lo que la propia norma exija mantener.</td></tr>
  <tr><td>Registros técnicos del servidor</td><td>Se rotan periódicamente y no se usan para perfilarte.</td></tr>
</table>
<p>Cuando un dato deja de tener finalidad y ninguna norma obliga a guardarlo, se
elimina o se anonimiza de forma irreversible.</p>

<h2>10. Eliminación de la cuenta y de los datos</h2>
<p>Puedes eliminar tu cuenta <strong>desde la propia aplicación</strong>, sin
escribir a nadie y sin necesidad de tener una membresía activa:</p>
<p class="path"><strong>Perfil → Eliminar cuenta y datos</strong><br>
También disponible en la pantalla de activación, para cuentas sin membresía.</p>
<p>Por seguridad pedimos una confirmación con un código por SMS: eliminar una
cuenta es irreversible y no queremos que ocurra por un descuido o desde un
teléfono ajeno. Si tu cuenta no tiene un teléfono para recibirlo, la eliminación
se ejecuta igual: nunca te dejamos atrapado.</p>
<p><strong>Qué se elimina:</strong></p>
<ul>
  <li>Tus datos identificativos: nombre, correo, teléfono, género, fecha de
      nacimiento, objetivo y lesiones.</li>
  <li>Las imágenes de tu documento de identidad y tu fotografía facial, borradas
      del disco del servidor.</li>
  <li>Tus conversaciones con IRON IA, sus mensajes y los archivos que adjuntaste.</li>
  <li>Los tokens de notificaciones, los vínculos de dispositivo y los códigos de
      verificación pendientes.</li>
  <li>Todas las sesiones abiertas, que se cierran de inmediato, y el acceso queda
      bloqueado.</li>
</ul>
<p><strong>Qué puede conservarse, y por qué:</strong></p>
<ul>
  <li>Los pagos, los contratos que firmaste y las facturas electrónicas, por
      obligación legal, contable y tributaria.</li>
  <li>Las pruebas de un caso de moderación abierto, durante el plazo indicado en
      la sección 9.</li>
  <li>Los registros de seguridad y auditoría necesarios para prevenir el fraude y
      atender reclamaciones.</li>
</ul>
<p>Lo que se conserva queda <strong>anonimizado</strong>: deja de estar asociado a
tu nombre, tu documento, tu correo o tu teléfono. El resto de tu historial de uso
—entrenamientos, nutrición, asistencia— permanece únicamente como registro
desvinculado de una persona identificada.</p>
<p>Eliminar la cuenta no es lo mismo que una suspensión: la suspensión es
temporal y reversible; la eliminación no tiene vuelta atrás.</p>
<p>Si prefieres no hacerlo desde la app, escríbenos a
<a href="mailto:{$email}">{$email}</a> desde el correo de tu cuenta, o indicando
tu número de documento, y lo tramitamos nosotros.</p>

<h2>11. Tus derechos</h2>
<p>Como titular de los datos puedes, en cualquier momento y de forma gratuita:</p>
<ul>
  <li><strong>Conocer</strong> qué datos tuyos tratamos y obtener una copia.</li>
  <li><strong>Actualizar y rectificar</strong> los que estén incompletos o sean inexactos.</li>
  <li><strong>Suprimir</strong> tus datos y eliminar tu cuenta.</li>
  <li><strong>Revocar</strong> la autorización, incluido el consentimiento de IRON IA
      y el uso de tu imagen.</li>
  <li><strong>Oponerte</strong> a recibir comunicaciones no operativas y ajustar tus
      preferencias de notificación desde la app.</li>
  <li><strong>Presentar una queja</strong> ante la Superintendencia de Industria y
      Comercio de Colombia si consideras que no atendimos tu solicitud.</li>
</ul>
<p>Escribe a <a href="mailto:{$email}">{$email}</a> indicando qué derecho quieres
ejercer. Podemos pedirte que acredites tu identidad antes de responder, para no
entregarle tus datos a otra persona.</p>

<h2>12. Menores de edad</h2>
<p>La edad mínima para crear una cuenta en {$app} es de <strong>13 años</strong>,
y la comprueba el servidor a partir de la fecha de nacimiento.</p>
<p>Las personas de <strong>13 a 17 años</strong> pueden usar el servicio con la
autorización de su padre, madre o acudiente, que se recoge y firma dentro del
contrato de inscripción junto con los datos de contacto de esa persona. El
tratamiento de datos de menores se limita a lo necesario para prestar el servicio
deportivo, respeta su interés superior y no se usa con fines publicitarios.</p>
<p>Si detectamos una cuenta de un menor de 13 años, la eliminamos. Si eres padre,
madre o acudiente y crees que un menor a tu cargo nos facilitó datos sin
autorización, escríbenos a <a href="mailto:{$email}">{$email}</a> y actuaremos.</p>

<h2>13. Cambios en esta política</h2>
<p>Podemos actualizar esta política cuando cambien las funciones de la app o la
normativa aplicable. La versión vigente es siempre la publicada en
<a href="{$url}">{$url}</a>, con su fecha de última actualización en la parte
superior. Si un cambio afecta de forma significativa al tratamiento de tus datos,
te lo comunicaremos por la app o por correo antes de aplicarlo.</p>

<h2>14. Contacto</h2>
<p>Para cualquier asunto relacionado con privacidad, eliminación de cuenta o
ejercicio de derechos:</p>
<table class="id">
  <tr><th>Responsable del tratamiento</th><td>{$controller}</td></tr>
  <tr><th>Publicador en Google Play</th><td>{$developer}</td></tr>
  <tr><td colspan="2" class="sep"></td></tr>
  <tr><th>Correo</th><td><a href="mailto:{$email}">{$email}</a></td></tr>
  <tr><th>Teléfono</th><td>{$phone}</td></tr>
  <tr><th>Dirección</th><td>{$address}</td></tr>
</table>
<p class="foot">Documento aplicable a {$app} (<code>{$package}</code>) ·
Última actualización: {$updated}</p>
HTML;
    }

    private function termsBody(): string
    {
        $app = e((string) Config::get('legal.app_name'));
        $brand = e((string) Config::get('legal.brand'));
        $support = e($this->support());
        $url = e((string) Config::get('legal.privacy_url'));

        return <<<HTML
<p>Estos términos resumen las condiciones de uso de {$app} y del servicio de
{$brand}. El detalle vinculante es el documento oficial de inscripción y
consentimiento que firmas en la app.</p>

<h2>Servicio</h2>
<p>{$brand} ofrece entrenamiento y clases dirigidas. Los planes son mensuales,
no transferibles y no reembolsables una vez realizado el pago, según el plan
adquirido.</p>

<h2>Uso de la app y la cuenta</h2>
<ul>
  <li>Eres responsable de la veracidad de la información que registras.</li>
  <li>Debes informar lesiones, enfermedades o restricciones antes de entrenar.</li>
  <li>El acceso y las reservas se gestionan desde la app.</li>
  <li>Puedes eliminar tu cuenta desde <em>Perfil → Eliminar cuenta y datos</em>.</li>
</ul>

<h2>Aptitud física y responsabilidad</h2>
<p>La actividad física conlleva riesgos. Declaras estar en condiciones aptas o
informar lo contrario, y aceptas seguir las indicaciones del personal.</p>

<h2>Pagos</h2>
<p>Los pagos se procesan mediante la pasarela autorizada; la app no almacena el
número completo de tu tarjeta.</p>

<h2>Privacidad</h2>
<p>El tratamiento de tus datos se describe en la
<a href="{$url}">Política de Privacidad</a>.</p>

<h2>Contacto</h2>
<p>Para cualquier solicitud: <strong>{$support}</strong>.</p>
HTML;
    }

    private function html(string $h1, string $docTitle, string $body): Response
    {
        $safeH1 = e($h1);
        $safeDocTitle = e($docTitle);
        $content = <<<HTML
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="index, follow">
  <title>{$safeDocTitle}</title>
  <style>
    :root { color-scheme: light dark; }
    * { box-sizing: border-box; }
    body { font-family: -apple-system, system-ui, Segoe UI, Roboto, sans-serif;
           margin: 0 auto; max-width: 820px; padding: 24px 18px 56px;
           line-height: 1.6; color: #1f2937; background: #ffffff;
           overflow-wrap: break-word; }
    h1 { font-size: 1.45rem; margin: 0 0 4px; line-height: 1.3; }
    h2 { font-size: 1.08rem; margin: 30px 0 8px; }
    h3 { font-size: .96rem; margin: 20px 0 6px; color: #374151; }
    p, li, td, th { font-size: 0.95rem; }
    ul { padding-left: 20px; }
    li { margin-bottom: 5px; }
    code { font-size: .88em; background: rgba(127,127,127,.14);
           padding: 1px 5px; border-radius: 4px; }
    a { color: #b45309; }
    .tag { display:inline-block; font-size:.7rem; letter-spacing:.06em;
           text-transform:uppercase; color:#b45309; margin-bottom:14px; }
    .updated { color:#4b5563; margin-top: 0; }
    .path { background: rgba(127,127,127,.10); border-left: 3px solid #b45309;
            padding: 10px 14px; border-radius: 0 6px 6px 0; }
    .foot { margin-top: 34px; padding-top: 14px; font-size: .82rem; color:#6b7280;
            border-top: 1px solid rgba(127,127,127,.28); }
    table { border-collapse: collapse; width: 100%; margin: 10px 0 4px;
            table-layout: fixed; }
    th, td { text-align: left; vertical-align: top; padding: 8px 10px;
             border-bottom: 1px solid rgba(127,127,127,.28); }
    table.id th, table.ret th { width: 38%; font-weight: 600; color:#374151; }
    table.proc tr:first-child th { color:#374151; }
    table.proc th:first-child, table.proc td:first-child { width: 26%; }
    td.sep { border: 0; padding: 0; height: 6px; }
    @media (max-width: 520px) {
      table.id th, table.ret th { width: 42%; }
      th, td { padding: 7px 8px; }
    }
    @media (prefers-color-scheme: dark) {
      body { background:#111315; color:#e5e7eb; }
      h1, h2 { color:#f5c518; }
      h3 { color:#d1d5db; }
      a { color:#f5c518; }
      .updated { color:#9ca3af; }
      .foot { color:#9ca3af; }
      table.id th, table.ret th, table.proc tr:first-child th { color:#d1d5db; }
    }
  </style>
</head>
<body>
  <div class="tag">Iron Body</div>
  <h1>{$safeH1}</h1>
  {$body}
</body>
</html>
HTML;

        return response($content, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
