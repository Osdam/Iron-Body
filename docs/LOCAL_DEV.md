# Iron Body CRM — entorno de desarrollo local

## Estructura del repo
```
CRM/Iron-Body/
├── backend/      Laravel (API real, fuente de verdad)
├── frontend/     Angular — SOLO artefactos de build (sin código fuente, ver abajo)
├── n8n/          flujos de automatización
└── docs/         esta documentación
```

## Backend (Laravel)

Requisitos: PHP 8.2+, Composer, PostgreSQL (es la **única** base real; no usar SQLite
salvo el tooling de tests).

```bash
cd backend
composer install
cp .env.example .env        # rellena credenciales reales SOLO aquí (no se versiona)
php artisan key:generate
php artisan migrate          # additivo; NO usar migrate:fresh en datos reales
php artisan storage:link     # para el fallback de imágenes en disco público
php artisan serve            # http://127.0.0.1:8000
```

### Procesos persistentes (producción)
```bash
php artisan queue:work --tries=3 --max-time=3600     # jobs (push, n8n)
* * * * * cd /ruta/backend && php artisan schedule:run >> /dev/null 2>&1   # cron
```

### Tests
```bash
php artisan test                 # suite completa
php artisan test --filter Membership
```

### Variables manuales (servicios externos) — ver `.env.example`
- **OTP/Twilio**: `OTP_DRIVER`, `TWILIO_*` (producción: `OTP_DRIVER=twilio`, `OTP_EXPOSE_CODE=false`).
- **Login adaptativo**: `SECURITY_ADAPTIVE_LOGIN` (ver `backend/docs/SEGURIDAD_LOGIN_ADAPTATIVO.md`).
- **Story Live**: `LIVE_ENABLED`, `LIVEKIT_*` (ver `backend/docs/STORY_LIVE.md`).
- **Firebase**: service account JSON (Storage/FCM); nunca se versiona.
- **ePayco / OpenAI / Meta**: claves en `.env` del servidor.

## Frontend (Angular) — PENDIENTE

⚠️ **No hay código fuente Angular en el repo.** `frontend/` solo contenía
artefactos (`dist/`, `.angular/cache`, `node_modules`, un `package-lock.json`
vacío) — sin `src/`, `angular.json` ni `package.json`. El `.gitignore` ignora esos
artefactos para no contaminar el repo.

Cuando se incorpore el frontend del CRM (o se reconstruya):
```bash
cd frontend
npm ci          # o npm install
npm run build   # genera dist/
ng serve        # desarrollo
```
El frontend debe consumir los endpoints de `docs/CRM_API_MODULES.md`. Nada de mocks.
Mientras tanto, el control del CRM se hace vía esos endpoints (curl/Postman); los
ejemplos están en `backend/docs/PUBLICIDAD_EVENTOS_API.md` y demás.
