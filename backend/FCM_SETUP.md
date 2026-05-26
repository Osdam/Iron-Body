# Activar Push Nativo (Firebase Cloud Messaging) — Iron Body

Proyecto Firebase: **iron-body-85fc3** · applicationId/bundle **com.example.ironbody**.

## ESTADO ACTUAL (lo ya aplicado)

- ✅ `android/app/google-services.json` colocado.
- ✅ `ios/Runner/GoogleService-Info.plist` colocado.
- ✅ Plugin Gradle `com.google.gms.google-services` añadido (settings + app, KTS).
- ✅ Código Dart (PushMessagingService) y backend (FcmService HTTP v1) integrados.
- ✅ `FCM_PROJECT_ID=iron-body-85fc3` en `.env`.

## FALTA PARA QUE ENVÍE PUSH (2 cosas)

1. **Backend service account** (es DISTINTO del google-services.json):
   Firebase Console → ⚙️ Configuración → Cuentas de servicio → Generar clave
   privada → guardar como
   `CRM/Iron-Body/backend/storage/app/firebase/service-account.json` y poner
   `FCM_ENABLED=true` en `.env` + `php artisan config:clear`.
   (Sin esto el backend NO puede enviar; la app igual registra su token.)
2. **iOS** (solo si compilas iOS, requiere Mac/Xcode): ver sección iOS abajo
   (agregar el plist al target Runner + APNs key + capabilities).

> El tiempo real in-app por **SSE** ya funciona al 100% sin nada de esto.

---

## 1. Crear el proyecto Firebase (una sola vez)

1. https://console.firebase.google.com → **Agregar proyecto**.
2. Agrega las apps:
   - **Android**: usa el `applicationId` real (mira
     `APP/Iron_Body_App/android/app/build.gradle` → `applicationId`).
   - **iOS**: usa el `Bundle ID` (`ios/Runner.xcodeproj` → Signing).

## 2. Backend (Laravel) — service account

1. Firebase Console → ⚙️ **Configuración del proyecto** → **Cuentas de servicio**
   → **Generar nueva clave privada** → descarga el JSON.
2. Cópialo a: `CRM/Iron-Body/backend/storage/app/firebase/service-account.json`
   (la carpeta ya existe; el archivo está fuera de git).
3. En `CRM/Iron-Body/backend/.env`:
   ```env
   FCM_ENABLED=true
   FCM_PROJECT_ID=tu-project-id        # el que aparece en el JSON / consola
   FCM_CREDENTIALS=storage/app/firebase/service-account.json
   ```
4. `php artisan config:clear`

Listo: el backend ya enviará push en cada notificación de miembro importante
(`should_popup`), sin tocar más código. Endpoints involucrados:
`POST /api/members/push-token` y `/push-token/remove` (registro/baja del token).

## 3. App Flutter — Android

1. Descarga `google-services.json` de la app Android en Firebase Console.
2. Cópialo a `APP/Iron_Body_App/android/app/google-services.json`.
3. `android/build.gradle` (o `settings.gradle` según versión) → plugin:
   ```gradle
   plugins {
     id 'com.google.gms.google-services' version '4.4.2' apply false
   }
   ```
4. `android/app/build.gradle` → al final / en la lista de plugins:
   ```gradle
   apply plugin: 'com.google.gms.google-services'
   ```
   (o `id 'com.google.gms.google-services'` en el bloque `plugins {}`).
5. `minSdkVersion` ≥ 21.

## 4. App Flutter — iOS

1. Descarga `GoogleService-Info.plist` y arrástralo a `ios/Runner` en Xcode
   (target Runner).
2. En Apple Developer → crea una **APNs Auth Key (.p8)** y súbela en Firebase
   Console → Cloud Messaging → Apple app config.
3. Xcode → Runner → Signing & Capabilities → **+ Push Notifications** y
   **Background Modes → Remote notifications**.

## 5. (Opcional) firebase_options.dart

Si usas FlutterFire CLI:
```bash
cd APP/Iron_Body_App
flutterfire configure
```
Genera `lib/firebase_options.dart`. Si lo usas, cambia en
`push_messaging_service.dart` el `Firebase.initializeApp()` por
`Firebase.initializeApp(options: DefaultFirebaseOptions.currentPlatform)`.
(No es obligatorio en Android/iOS si los archivos de config están en su sitio.)

---

## Cómo verificar

1. Backend con credenciales + `FCM_ENABLED=true`.
2. Corre la app en un dispositivo real (FCM no llega bien en emuladores sin
   Google Play). Inicia sesión: el token se registra automáticamente
   (`enableForMember()` en `app_shell`).
3. Genera un evento que cree una notificación de miembro `should_popup` (p. ej.
   completar una rutina, o crea una manual desde el CRM dirigida al miembro).
4. Con la app en segundo plano/cerrada → llega el push nativo. En foreground →
   aparece la cápsula premium in-app (vía SSE/checkNow).

## Diseño / comportamiento ya implementado

- Sólo se empuja a notificaciones de **miembro** marcadas `should_popup`
  (`FCM_ONLY_POPUP=true`); idempotente (sólo en filas nuevas, respeta
  `event_key`).
- Tokens muertos (UNREGISTERED/404) se eliminan solos al enviar.
- Foreground reusa el pipeline premium (cápsula 3D + badge + lista).
- Tap en el push → abre el centro de notificaciones.
- Logout da de baja el token del dispositivo.
