<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;

/**
 * Páginas legales PÚBLICAS servidas por el propio backend (HTML limpio). Se usan
 * cuando no hay un dominio público configurado: la app las muestra en un visor
 * interno (WebView), evitando enviar al usuario a Safari con un dominio muerto.
 *
 * Describen el tratamiento REAL de datos del sistema (finalidad, datos, derechos
 * Habeas Data — Ley 1581 de 2012). El contrato/consentimiento formal sigue
 * siendo el documento oficial que el usuario firma en la app.
 */
class LegalController extends Controller
{
    public function privacy(): Response
    {
        return $this->html('Política de Privacidad', $this->privacyBody());
    }

    public function terms(): Response
    {
        return $this->html('Términos y Condiciones', $this->termsBody());
    }

    private function support(): string
    {
        return (string) Config::get('contracts.support_contact', 'Ironbodyneiva@gmail.com');
    }

    private function privacyBody(): string
    {
        $support = e($this->support());

        return <<<HTML
<p>En Iron Body tratamos tus datos personales conforme a la Ley 1581 de 2012 y
sus normas reglamentarias (Habeas Data, Colombia).</p>

<h2>Responsable del tratamiento</h2>
<p>IRONBODY (Fredy Alberto Pajoy Medina). Contacto: <strong>{$support}</strong>.</p>

<h2>Datos que recolectamos</h2>
<ul>
  <li>Identificación y contacto: nombre, documento, teléfono, correo, dirección.</li>
  <li>Datos de salud básicos que tú declaras: observaciones médicas y lesiones,
      únicamente para una gestión segura de tu entrenamiento.</li>
  <li>Datos de uso del servicio: asistencia, progreso, reservas y pagos.</li>
  <li>Imagen (fotos/videos), <em>solo si autorizas</em> ese uso.</li>
  <li>Verificación facial, <em>opcional</em>: si decides usarla para el control
      de acceso. Puedes omitirla y verificarla presencialmente en el gimnasio.</li>
</ul>

<h2>Finalidad</h2>
<ul>
  <li>Gestión de inscripción y prestación del servicio.</li>
  <li>Seguimiento físico y deportivo.</li>
  <li>Comunicación operativa y seguridad del usuario.</li>
  <li>Facturación y pagos.</li>
  <li>Marketing y uso de imagen, solo si lo autorizas.</li>
</ul>

<h2>Datos sensibles</h2>
<p>Los datos de salud y los biométricos reciben protección reforzada, no se usan
para marketing y no se comparten salvo obligación legal.</p>

<h2>Tus derechos</h2>
<p>Puedes conocer, actualizar, rectificar y suprimir tus datos, y revocar la
autorización, escribiendo a <strong>{$support}</strong>.</p>

<h2>Documento firmado</h2>
<p>El consentimiento informado completo es el documento oficial de inscripción
que firmas electrónicamente en la app; puedes descargarlo desde tu perfil.</p>
HTML;
    }

    private function termsBody(): string
    {
        $support = e($this->support());

        return <<<HTML
<p>Estos términos resumen las condiciones de uso del servicio de IRONBODY. El
detalle vinculante es el documento oficial de inscripción y consentimiento que
firmas en la app.</p>

<h2>Servicio</h2>
<p>IRONBODY ofrece entrenamiento y clases dirigidas. Los planes son mensuales,
no transferibles y no reembolsables una vez realizado el pago, según el plan
adquirido.</p>

<h2>Uso de la app y la cuenta</h2>
<ul>
  <li>Eres responsable de la veracidad de la información que registras.</li>
  <li>Debes informar lesiones, enfermedades o restricciones antes de entrenar.</li>
  <li>El acceso y las reservas se gestionan desde la app.</li>
</ul>

<h2>Aptitud física y responsabilidad</h2>
<p>La actividad física conlleva riesgos. Declaras estar en condiciones aptas o
informar lo contrario, y aceptas seguir las indicaciones del personal.</p>

<h2>Pagos</h2>
<p>Los pagos se procesan mediante la pasarela autorizada; la app no almacena los
datos de tu tarjeta.</p>

<h2>Contacto</h2>
<p>Para cualquier solicitud: <strong>{$support}</strong>.</p>
HTML;
    }

    private function html(string $title, string $body): Response
    {
        $safeTitle = e($title);
        $content = <<<HTML
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>{$safeTitle} — Iron Body</title>
  <style>
    :root { color-scheme: light dark; }
    body { font-family: -apple-system, system-ui, Segoe UI, Roboto, sans-serif;
           margin: 0; padding: 20px 18px 40px; line-height: 1.55; color: #1f2937;
           background: #ffffff; }
    h1 { font-size: 1.35rem; margin: 0 0 4px; }
    h2 { font-size: 1.02rem; margin: 22px 0 6px; }
    p, li { font-size: 0.95rem; }
    ul { padding-left: 20px; }
    .tag { display:inline-block; font-size:.7rem; letter-spacing:.06em;
           text-transform:uppercase; color:#b45309; margin-bottom:14px; }
    @media (prefers-color-scheme: dark) {
      body { background:#111315; color:#e5e7eb; } h1,h2{ color:#f5c518; }
    }
  </style>
</head>
<body>
  <div class="tag">Iron Body</div>
  <h1>{$safeTitle}</h1>
  {$body}
</body>
</html>
HTML;

        return response($content, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
