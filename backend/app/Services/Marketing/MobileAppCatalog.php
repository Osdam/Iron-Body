<?php

namespace App\Services\Marketing;

/**
 * Lo que la app Iron Body Workout hace de verdad, escrito desde su código
 * (repo Flutter, rama fix/ios-tflite-symbols): pantallas en lib/features/*,
 * endpoints que consume y textos de sus propias pantallas. Que exista una
 * carpeta no bastó: cada entrada se comprobó en la pantalla o el endpoint. El
 * modelo solo puede afirmar lo que está aquí; lo que no está, no existe para
 * él. Sin cifras, sin promesas, sin URLs: los enlaces viajan en
 * {@see MobileAppLinks} y los envía Laravel.
 *
 * Dos entradas decían lo que la app NO hace, y el modelo las repetía como
 * verdad del gimnasio:
 *
 *  - ACCESO. Decía «con el documento o el teléfono». La pantalla de login tiene
 *    un único campo, «Número de documento» (login_screen.dart:438), bajo el
 *    subtítulo «Accede con tu documento o biometría» (:425); el backend solo
 *    valida `document_number` (LoginMemberRequest::rules()). Entrar por
 *    teléfono nunca existió. La biometría sí —el botón reusa la
 *    sesión viva del dispositivo contra `POST members/biometric-unlock`— y no
 *    estaba escrita. El rostro es un TERCER factor tras el SMS y solo si la
 *    cuenta tiene referencia facial (otp_verification_screen.dart:122-133).
 *  - REGISTRO. Decía «nombre, documento, correo y teléfono; el teléfono se
 *    confirma con el código SMS». El flujo real son seis pasos
 *    (register_screen.dart:40-46) e incluye la foto del documento por ambas
 *    caras con OCR y validación de edad; la verificación facial es OPCIONAL
 *    (:106, :1571-1585). En el registro NO hay SMS: no aparece ni en la
 *    pantalla ni en sus servicios.
 *
 * Y lo que se prometía del pago hecho por WhatsApp —«queda enlazado al
 * registrarte»— dejó de ser automático: lo enlaza el equipo tras verificarlo.
 */
final class MobileAppCatalog
{
    /** @var array<int, array{key:string,title:string,what:string}> */
    public const FEATURES = [
        ['key' => 'otp_login', 'title' => 'Acceso', 'what' => 'Se entra con el número de documento: llega un código de 6 dígitos por SMS y, si la cuenta tiene rostro registrado, la app pide además la verificación facial. Quien ya entró en ese mismo dispositivo vuelve a entrar con la biometría, sin código. No hay contraseña.'],
        ['key' => 'registration', 'title' => 'Registro', 'what' => 'Crear la cuenta son seis pasos dentro de la app: datos personales (documento, nombre, correo y teléfono), preferencias de entreno, foto del documento por las dos caras —la app la lee y valida la edad—, contrato y autorización, firma, y verificación facial, que es opcional. Si pagaste por WhatsApp, el equipo enlaza ese pago a tu cuenta después de verificarlo y te avisa.'],
        ['key' => 'membership_and_renewal', 'title' => 'Membresía', 'what' => 'Ver el plan activo, su vencimiento y su estado (activa, por vencer, vencida), renovarlo, y activar o cancelar la renovación automática con tarjeta.'],
        ['key' => 'wompi_payments', 'title' => 'Pagos', 'what' => 'Pagar o renovar la membresía desde la app con Wompi (Nequi, PSE, tarjeta, Daviplata) y ver el comprobante en PDF.'],
        ['key' => 'class_reservations', 'title' => 'Clases', 'what' => 'Planificador semanal de clases grupales: reservar y cancelar cupo y registrar la asistencia (check-in).'],
        ['key' => 'workouts', 'title' => 'Entrenos', 'what' => 'Rutinas por días, biblioteca de ejercicios, sesión de entreno activa y rutinas propias.'],
        ['key' => 'nutrition', 'title' => 'Nutrición', 'what' => 'Registro de comidas, escáner de código de barras y lectura de etiquetas con la cámara, metas y guía nutricional.'],
        ['key' => 'progress', 'title' => 'Progreso', 'what' => 'Resumen y evolución semanal, y las valoraciones físicas que hace el equipo.'],
        ['key' => 'store', 'title' => 'Tienda', 'what' => 'Catálogo de productos del gimnasio, carrito, pedido y comprobante.'],
        ['key' => 'iron_ai_coach', 'title' => 'IRON IA', 'what' => 'Coach dentro de la app, con chat, voz y visión en tiempo real.'],
        ['key' => 'weekly_streak', 'title' => 'Racha semanal', 'what' => 'Cuenta las semanas seguidas entrenando.'],
        ['key' => 'community', 'title' => 'Comunidad', 'what' => 'Historias y reels del gimnasio, eventos y transmisiones en vivo.'],
        ['key' => 'digital_contract', 'title' => 'Contrato digital', 'what' => 'Firma del contrato de membresía desde la app.'],
        ['key' => 'support', 'title' => 'Soporte', 'what' => 'Reportar un problema o pedir ayuda desde la app.'],
        ['key' => 'security', 'title' => 'Seguridad', 'what' => 'Cambio de número, recuperación segura del acceso, cierre de sesión en otros dispositivos y acceso biométrico: se activa al iniciar sesión con el documento y se puede volver a registrar el rostro desde la app.'],
    ];

    /** @var array<string,string> */
    public const HELP = [
        'login' => 'Para entrar: abre la app, escribe tu número de documento y luego el código de 6 dígitos que te llega por SMS; si tu cuenta tiene rostro registrado, la app pide también la verificación facial. Si ya entraste antes en ese dispositivo, puedes entrar con la biometría. Si el código no llega, revisa que el número registrado siga siendo el tuyo.',
        'register' => 'Para registrarte: descarga la app, toca CREAR CUENTA y sigue los pasos: datos personales (documento, nombre, correo y teléfono), preferencias de entreno, la foto de tu documento por las dos caras —la app la lee y valida tu edad—, el contrato con tu firma y, al final, la verificación facial, que es opcional. Si pagaste por WhatsApp, el equipo enlaza ese pago a tu cuenta después de verificarlo y te avisa.',
        'recovery' => 'Si cambiaste de número o no te llega el código, en la app está la recuperación segura del acceso; si tampoco funciona, el equipo lo revisa.',
        'membership_not_visible' => 'Si tu membresía no aparece en la app, suele ser porque el documento del registro no coincide con el del pago. Si pagaste por WhatsApp, el equipo enlaza ese pago a tu cuenta después de verificarlo y te avisa; si pagaste desde la app, el equipo lo revisa.',
    ];

    /** @return array{links:array{android:string,ios:string,web:string},features:array<int,array{key:string,title:string,what:string}>,help:array<string,string>,account:array{has_account:bool}} */
    public function forPrompt(bool $hasAccount): array
    {
        return [
            'links' => ['android' => MobileAppLinks::ANDROID, 'ios' => MobileAppLinks::IOS, 'web' => MobileAppLinks::WEB],
            'features' => self::FEATURES,
            'help' => self::HELP,
            'account' => ['has_account' => $hasAccount],
        ];
    }

    /** El mensaje con los enlaces oficiales, escrito por Laravel. */
    public static function linksMessage(): string
    {
        return 'Aquí tienes la app Iron Body Workout para descargarla y registrarte: '.MobileAppLinks::asLine();
    }

    /**
     * Los enlaces para ir DENTRO de la respuesta, donde el modelo puso el
     * marcador `{{APP_LINKS}}`. Un solo mensaje, no dos.
     */
    public static function linksInline(): string
    {
        return MobileAppLinks::asLine();
    }

    /**
     * Con qué se sustituye el marcador cuando los enlaces acaban de salir.
     *
     * Hace falta una frase y no una cadena vacía: el borrador ya anunció «te
     * paso los enlaces», y dejarlo sin nada convertiría la respuesta en una
     * promesa incumplida dentro del mismo mensaje.
     */
    public const LINKS_ALREADY_SENT = 'te los dejé aquí mismo en el chat hace un rato';
}
