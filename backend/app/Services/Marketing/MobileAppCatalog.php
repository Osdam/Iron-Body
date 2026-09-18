<?php

namespace App\Services\Marketing;

/**
 * Lo que la app Iron Body Workout hace de verdad, escrito desde su código
 * (repo Flutter, rama fix/ios-tflite-symbols, 2026-09-17): pantallas en
 * lib/features/*, endpoints que consume y textos de sus propias pantallas. Que
 * exista una carpeta no bastó: cada entrada se comprobó en la pantalla o el
 * endpoint. El modelo solo puede afirmar lo que está aquí; lo que no está, no
 * existe para él. Sin cifras, sin promesas, sin URLs: los enlaces viajan en
 * {@see MobileAppLinks} y los envía Laravel.
 */
final class MobileAppCatalog
{
    /** @var array<int, array{key:string,title:string,what:string}> */
    public const FEATURES = [
        ['key' => 'otp_login', 'title' => 'Acceso', 'what' => 'Se entra con el número de documento o el teléfono y un código de 6 dígitos que llega por SMS. No hay contraseña que recordar.'],
        ['key' => 'registration', 'title' => 'Registro', 'what' => 'Nombre, documento, correo y teléfono; el teléfono se confirma con el código SMS. Quien pagó por WhatsApp con ese mismo número queda enlazado a su membresía al registrarse.'],
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
        ['key' => 'security', 'title' => 'Seguridad', 'what' => 'Cambio de número, recuperación segura del acceso y cierre de sesión en otros dispositivos.'],
    ];

    /** @var array<string,string> */
    public const HELP = [
        'login' => 'Para entrar: abre la app, escribe tu documento o tu teléfono y luego el código de 6 dígitos que te llega por SMS. Si no llega, revisa que el número sea el que registraste.',
        'register' => 'Para registrarte: descarga la app, elige Registrarme, ingresa nombre, documento, correo y teléfono, y confirma el código SMS. Si ya pagaste por WhatsApp con este mismo número, tu membresía queda enlazada al terminar.',
        'recovery' => 'Si cambiaste de número o no te llega el código, en la app está la recuperación segura del acceso; si tampoco funciona, el equipo lo revisa.',
        'membership_not_visible' => 'Si tu membresía no aparece en la app, suele ser porque el documento o el teléfono del registro no coinciden con los del pago; el equipo lo verifica.',
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
}
