# Respaldos de `.env` — inventario y propuesta de borrado

**Nada se ha borrado.** Los 56 ficheros se movieron a un directorio privado y se
pusieron a `600`. Este documento entrega el inventario y una propuesta exacta
para que el propietario decida.

## Qué se encontró

En `/var/www/api/backend`, junto al código de la aplicación:

| Dato | Valor |
|---|---|
| Ficheros `.env*` | **59** |
| Con credenciales reales | **57** |
| Legibles por cualquier usuario (`644`) | **57**, incluido el `.env` en uso |
| Correctamente a `600` | 1 |
| Antigüedad | de 2026-06-24 a 2026-08-06 |
| Credenciales dentro | Meta (token de acceso, app secret, verify token), **Wompi de producción**, Factus, OpenAI, `APP_KEY`, contraseña de PostgreSQL |

Cada fichero contenía entre 7 y 14 claves sensibles con valor real.

## Alcance real de la exposición

**No eran accesibles por web.** Se comprobó contra el dominio real:

```
/.env                                      → 403
/../.env                                   → 400
/.env.backup-wompi-prod-2026-07-18-194241  → 403
/%2e%2e/.env  ·  /%252e%252e/.env          → 400
/.git/config                               → 403
```

Dos capas lo impedían: el docroot es `public/`, un nivel por debajo de donde
estaban los ficheros, y nginx tiene `location ~ /\.(?!well-known).* { deny all; }`.

**Sí eran accesibles localmente.** Los usuarios con shell en la máquina son
`root`, `ubuntu` y `postgres`. Cualquiera de ellos —o cualquier proceso corriendo
como ellos, incluido un plugin comprometido de n8n— podía leer las llaves de
producción de Wompi con un `cat`.

Riesgo real: medio-alto. No es una brecha, es una brecha esperando a un segundo
fallo.

## Qué se hizo

1. Los **56** respaldos se movieron a `/root/env-backups/` (directorio `700`),
   cada fichero a `600` y propiedad de `root`.
2. El **`.env` en uso** pasó de `644` a `600`, propiedad de `www-data`.
   Comprobado: `www-data` (php-fpm y el worker de facturación) lo lee; `postgres`
   ya no.
3. Se dejó un `INVENTARIO.txt` en el destino con la ubicación y permisos
   originales de cada fichero.
4. Se **conservaron en su sitio** `.env.example` y `.env.production.example`:
   están en Git, no tienen ningún valor real y deben seguir ahí.
5. Se añadió `security:check-secret-exposure` y una prueba automatizada para que
   esto no vuelva a pasar inadvertido.

## Propuesta de borrado

**Recomendación: borrar 50 de los 56, conservar 6.**

Un respaldo de `.env` no es un backup: es una copia de credenciales sin cifrar
cuya utilidad caduca en cuanto se rotan. Los de junio contienen tokens de Meta y
llaves de Wompi que probablemente ya no son válidos, pero que sí revelan la
estructura de la configuración y la política de nombres.

### Conservar (6)

| Fichero | Por qué |
|---|---|
| `.env.BEFORE-WHATSAPP-CHANNEL-20260806_050635` | Anterior al despliegue de este trabajo. Es el punto de vuelta atrás vigente. |
| `.env.BEFORE-FACTUS-CONTAINMENT-20260729_022208` | Estado previo al último cambio de facturación electrónica. |
| `.env.backup-wompi-prod-2026-07-18-194241` | Momento en que entró Wompi producción. |
| `.env.backup-clean-indent-2026-07-18-195133` | Última versión con formato normalizado. |
| `.env.backup-before-app-review-demo` | Configuración usada en la revisión de la app. |
| `.env.OK-AFTER-META-PUBLISHED-20260629_204800` | Último estado bueno conocido con Meta publicado. |

### Borrar (50)

Todo lo demás: los `ROTO`, `DANADO`, `INVALIDO`, `broken`, `actual-roto`,
`current-bad-before-repair`, `recovered`, y la larga serie de
`BEFORE-AUTO-EXECUTE-*` / `BEFORE-*-TEST-*` de finales de junio. Son estados
intermedios de una sesión de depuración de hace mes y medio; algunos están
explícitamente marcados como corruptos.

### Comando exacto (NO ejecutado)

```bash
ssh root@2.25.167.216

cd /root/env-backups

# 1. Ver primero qué se borraría
ls -A1 | grep -vE '^(INVENTARIO\.txt|\.env\.BEFORE-WHATSAPP-CHANNEL-20260806_050635|\.env\.BEFORE-FACTUS-CONTAINMENT-20260729_022208|\.env\.backup-wompi-prod-2026-07-18-194241|\.env\.backup-clean-indent-2026-07-18-195133|\.env\.backup-before-app-review-demo|\.env\.OK-AFTER-META-PUBLISHED-20260629_204800)$'

# 2. Archivo cifrado por si acaso, ANTES de borrar
tar -czf - $(ls -A1 | grep -vE '^INVENTARIO\.txt$') \
  | gpg --symmetric --cipher-algo AES256 -o /root/env-backups-archivo-$(date +%Y%m%d).tar.gz.gpg
chmod 600 /root/env-backups-archivo-*.tar.gz.gpg
# Guarda la frase de paso en el gestor de contraseñas del negocio.

# 3. Borrar (solo tras comprobar el paso 1)
ls -A1 | grep -vE '^(INVENTARIO\.txt|\.env\.BEFORE-WHATSAPP-CHANNEL-20260806_050635|\.env\.BEFORE-FACTUS-CONTAINMENT-20260729_022208|\.env\.backup-wompi-prod-2026-07-18-194241|\.env\.backup-clean-indent-2026-07-18-195133|\.env\.backup-before-app-review-demo|\.env\.OK-AFTER-META-PUBLISHED-20260629_204800)$' \
  | xargs -r shred -u
```

`shred` en lugar de `rm` porque son credenciales: sobrescribe antes de liberar.

## Lo que de verdad cierra el asunto

Mover y borrar reduce la superficie, pero **no invalida nada**. Si alguno de
esos secretos se filtró en algún momento, sigue siendo válido hoy.

Recomendación aparte, y con más prioridad que el borrado: **rotar las
credenciales que estuvieron expuestas**, empezando por las que mueven dinero.

| Credencial | Prioridad | Dónde se rota |
|---|---|---|
| Llaves de Wompi de producción | **Alta** | Panel de Wompi → Desarrolladores |
| Token de acceso de Meta | **Alta** | Business Manager → System User → generar token |
| `FACTUS_CLIENT_SECRET` | Media | Portal de Factus |
| `OPENAI_API_KEY` | Media | platform.openai.com → API keys |
| `APP_KEY` de Laravel | **No rotar sin más** | Invalida sesiones y cualquier dato cifrado con ella. Requiere plan aparte. |
| Contraseña de PostgreSQL | Baja | Solo escucha en loopback |

La rotación es una decisión del propietario porque implica ventanas de
indisponibilidad en pagos y facturación.

## Cómo se evita que vuelva a pasar

```bash
php artisan security:check-secret-exposure   # exit 1 si algo está expuesto
```

Se ejecuta en la suite (`SecretExposureTest`) y puede engancharse al despliegue.
Comprueba tres cosas: que ningún fichero con secretos reales sea legible por
«otros», que no haya ficheros de entorno dentro del docroot, y que los `.example`
no lleven valores de verdad.

Además: **no crear más respaldos `.env` en el directorio de la aplicación.** Si
hace falta uno antes de un cambio, va directo a `/root/env-backups/` con `600`.
