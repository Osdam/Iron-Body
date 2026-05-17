/// Configuración de entorno de la app.
///
/// La URL del backend Laravel se inyecta en build time con `--dart-define`:
///
///   flutter run --dart-define=BACKEND_BASE_URL=http://192.168.1.20:8080
///
/// Por defecto apunta a `10.0.2.2:8080` (host de la máquina desde el emulador
/// Android). En un dispositivo físico DEBES pasar la IP LAN de tu PC donde
/// corre `php artisan serve --host=0.0.0.0 --port=8080`.
///
/// Aquí NO vive ninguna llave de ePayco: la app solo conoce la URL del backend.
class AppConfig {
  AppConfig._();

  static const String backendBaseUrl = String.fromEnvironment(
    'BACKEND_BASE_URL',
    defaultValue: 'http://10.0.2.2:8080',
  );

  /// Prefijo de la API REST del backend.
  static String get apiBase => '$backendBaseUrl/api';
}
