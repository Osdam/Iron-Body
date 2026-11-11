# Rotación de credenciales — Iron Body

Herramienta y procedimiento para cambiar un secreto de producción sin que el
valor nuevo quede grabado en ningún sitio y sin que el sistema se entere tarde
de que algo se rompió.

Los scripts viven en `backend/scripts/rotation/` (versionados) y se despliegan a
`/opt/ironbody-rotation/` en el servidor (`root:www-data`, `750`/`640`).

## Las tres reglas

**1. El valor nunca viaja por `argv`.** Se teclea en un prompt oculto y se deja
en un fichero temporal `0600` que el resto de procesos leen por ruta. Un
argumento sería visible en `ps` durante toda la ejecución, en el historial del
shell y —por SSH— en el log del comando remoto. Por eso `admin:create
--password=…` no sirve para rotar y existe `admin:password`.

**2. Se prueba la credencial nueva ANTES de escribirla.** Si el proveedor la
rechaza, el `.env` ni se toca. La alternativa —escribir, recargar y descubrirlo
por los errores— deja el sistema roto durante el tiempo que se tarde en mirar.

**3. Todo fallo revierte.** Copia previa del `.env` antes de cada cambio, y
`rollback` ante configuración efectiva distinta, autenticación KO o health KO.
En PostgreSQL se guarda además el verificador SCRAM anterior, así que la
contraseña vieja vuelve a ser válida tal como era.

## Qué hay

| Script | Rota | Acción humana |
|---|---|---|
| `rotate-factus.sh` | `FACTUS_PASSWORD`, `FACTUS_CLIENT_ID/SECRET`, `FACTUS_USERNAME` | sí (panel Factus / soporte Halltec) |
| `rotate-smtp.sh` | `MAIL_PASSWORD`, `MAIL_USERNAME` | sí (hPanel de Hostinger) |
| `rotate-postgres.sh` | `DB_PASSWORD` del role `iron` | **no** — se genera en el servidor |
| `admin:password` (artisan) | contraseña de una cuenta del CRM | sí (se teclea) |
| `dedupe-env-keys.sh` | — | no |
| `secret-scan.sh` | — | no |
| `secret-scan-git.sh` | — | no |
| `smoke-production.sh` | — | no |

`/usr/local/sbin/ironbody-rotate-secret` es el rotador genérico anterior (con el
que se hicieron OpenAI y Wompi). Sigue siendo válido; se niega a tocar una clave
duplicada, que es la razón por la que existe `dedupe-env-keys.sh`.

## Cosas de este servidor que hay que saber

**Claves duplicadas en el `.env`.** El fichero definía `ADMIN_API_TOKEN`,
`MAIL_PASSWORD` y `MEMBER_REGISTRATION_TOKEN` dos veces: la primera con el
`null` de la plantilla de Laravel, la segunda con el valor real. Comprobado con
el repositorio de entorno que usa Laravel: **gana la última**. Funcionaba por
orden, no por diseño, y editar «la primera que aparezca» habría escrito una
línea que nadie lee. Ya están deduplicadas.

**Sangría.** 227 de las asignaciones empiezan con espacios. Dotenv los recorta,
pero un patrón anclado en `^CLAVE=` no las encuentra: o no cambia nada —y el
despliegue parece correcto con la credencial vieja— o añade una segunda
definición. Las dos formas de fallar son silenciosas.

**La configuración compilada es una copia de todos los secretos.**
`config:cache` vuelca en `bootstrap/cache/config.php` el valor resuelto de
APP_KEY, la contraseña de la base, Wompi, Meta, OpenAI y Factus, y lo escribe
con el umask del proceso: nacía a `664`, legible por cualquier usuario del
sistema, mientras el `.env` estaba impecable a `600`. Se vuelve a escribir en
cada recarga, así que el `chmod 600` va dentro de `reload_consumers`, no en un
arreglo de una vez. `security:check-secret-exposure` ahora lo comprueba.

**Los respaldos no dependen de `DB_PASSWORD`.** `pg_dump` corre como el usuario
de sistema `postgres` con autenticación `peer`. Rotar el role `iron` no los
roza — y `rotate-postgres.sh` lo verifica en vez de suponerlo.

**Factus prueba `refresh_token` antes que `password`.** Con un refresh anterior
vivo en cache (TTL 14 días), una credencial equivocada pasaría por buena. Por
eso `factus_verify.php` purga los dos tokens derivados antes de autenticar: lo
que se comprueba tiene que ser la credencial, no un resto de la anterior.

## Procedimiento

```bash
# 1. Cambiar el secreto en el portal del proveedor (paso humano).
# 2. Rotar, desde un terminal REAL (el prompt es oculto):
ssh -t root@<servidor> /opt/ironbody-rotation/rotate-factus.sh FACTUS_PASSWORD

# 3. Comprobación transversal:
ssh root@<servidor> /opt/ironbody-rotation/smoke-production.sh

# 4. Revocar la credencial ANTERIOR en el proveedor, sólo ahora.
```

Rollback manual si hiciera falta:

```bash
ls -t /root/ironbody-rotation/env-backups/     # copias, 0600 root
/usr/local/sbin/ironbody-rotate-secret --rollback
```

## Verificación

- `secret-scan.sh` — busca los valores **vivos** por el disco. No es un detector
  de patrones: dice «esta clave, la que está en uso hoy, está también aquí».
- `secret-scan-git.sh` — el histórico completo de Git, todas las ramas,
  incluidos ficheros ya borrados. Un secreto que estuvo en un commit sigue ahí
  aunque se quitara después, y exige **rotarlo**, no reescribir la historia.
- `smoke-production.sh` — 29 comprobaciones de sólo lectura: API, Inbox V2,
  analítica, supervisión, base de datos, colas, workers, scheduler, doctores,
  IRON GUARD, respaldos e interruptores de seguridad.

El Inbox, la analítica y la supervisión **no** aceptan `ADMIN_API_TOKEN`: exigen
una sesión de administrador real. El smoke emite una efímera y la revoca al
terminar.

## Twilio: el Auth Token maestro del OTP (nota OPS8-e, 11-11-2026)

*Preparación autorizada por el propietario; **nada se ha ejecutado en el
servidor**. Mando: `docs/ultron-studio/ops/h8-otp-token-rotate.sh` (se copia a
`/root` cuando se vaya a usar). Ensayado en el banco del Hito 8 contra un
simulador de Twilio que rota como la consola (secundario y promoción):
`docs/ultron-studio/ops/banco-h8/ensayar-otp-token.sh`, 49 OK · 0 FALLO. El
informe: `docs/ultron-studio/plans/informes-h8/informe-ops8e.md`.*

**Por qué.** El número de voz (…5483) se importó en ElevenLabs con este token:
el workspace lo guarda como secreto (`twilio_token_account_AC936142bc…`, A-1 /
H-14 de `ULTRON_STUDIO_PROVEEDORES_ESTADO_REAL.md`). Es la recomendación B-6 del
roadmap (P-4 del contrato del H8: antes de la fase 2).

**Qué cambia.** `otp.twilio.token` lee `TWILIO_TOKEN` y, si no está,
`TWILIO_AUTH_TOKEN`: hoy existen las dos y el mando cambia las que haya, cada
una con su sangría (las de este servidor llevan dos espacios: por eso `grep
^TWILIO_` no las ve) y sin añadir ninguna. Lo notan: el OTP (Verify y SMS), la
firma del aviso de gasto del OTP (`webhooks/twilio/usage-trigger`), la huella de
Twilio del Studio (pasa a «drift» en `authToken` hasta reconocerla) y ElevenLabs.

**Por qué no usa `lib-env.sh`.** Cumple sus tres reglas (el valor nunca por
`argv`; la credencial nueva se prueba antes de escribir, con un GET de solo
lectura a la ficha de la cuenta; copia previa y vuelta atrás), pero su
`env_set` no ve `export CLAVE=` ni `CLAVE =` y añade la clave que falta (con dos
alias, cambiaría cuál gana), y `reload_consumers` reinicia `php8.3-fpm` y todo
supervisor. El mando no reinicia nada: `config:cache` como `www-data` y
`queue:restart`, como los mandos del Hito 8. Además se niega si la caché no es
igual al fichero de entorno (un `config:cache` publicaría otros cambios a medias,
o dejaría el OTP sin token).

| Paso | Quién | Qué |
|---|---|---|
| 0 | lead, solo lectura | `bash /root/h8-otp-token-rotate.sh estado`: las claves con su forma (sin valores), caché = fichero, un GET con el token de ahora, la voz y el Studio. Y C-8: `opcache.validate_timestamps` del FPM (comando en el informe) |
| 1 | propietario | Consola de Twilio, cuenta del OTP, Auth Tokens: crear el token **secundario** (sin promocionarlo) |
| 2 | operador (root) | El fichero del token, sin que pase por la pantalla ni el historial: `( umask 077; read -rsp 'token: ' T; echo; printf '%s\n' "$T" > /root/otp-token-nuevo; unset T )` |
| 3 | operador | `bash /root/h8-otp-token-rotate.sh rotar /root/otp-token-nuevo --solo-comprobar` → `COMPROBACIÓN EN VERDE` (GET 200 con el nuevo y con el de ahora) |
| 4 | operador, con la orden escrita | Lo mismo sin `--solo-comprobar` → `MANDO otp-token-rotar APLICADO`. Destruye el fichero del token e imprime la copia previa (`/root/env-pre-otp-token-<fecha>`), la huella nueva del Studio y las dos vueltas atrás |
| 5 | propietario | **La vista del proceso web** (el mando no la ve): CRM → Studio → Integraciones → Twilio con la huella del token que imprimió el mando (y «drift» en `authToken` si estaba reconocida). Si sigue la de antes, el FPM no ve la caché nueva (C-8): **no promocionar**; recargarlo con autorización (`systemctl reload php8.3-fpm`, como `install-meta-ads-token.sh`) y volver a mirar |
| 6 | propietario | Un inicio de sesión con OTP (un SMS). Con el secundario sin promocionar valen los dos tokens: esta prueba aún no los distingue |
| 7 | propietario, enseguida | **Promocionar** el secundario en la consola. El viejo deja de valer: desde aquí **no hay vuelta atrás**. Hasta este paso, Twilio firma con el principal viejo y el aviso de gasto del OTP responde 403: la ventana entre el paso 4 y el 7 tiene que ser de minutos |
| 8 | operador | `bash /root/h8-otp-token-rotate.sh vuelta /root/env-pre-otp-token-<fecha> --solo-comprobar` tiene que **negarse con 401** (confirma la promoción). Otro inicio de sesión con OTP: ahora sí prueba el token nuevo |
| 9 | propietario | Studio → Integraciones → Twilio: reconocer la configuración (con el monitor del H7 encendido, `config_drift:twilio` hasta entonces) |
| 10 | propietario | **ElevenLabs:** desde el paso 7 el secreto del workspace con el token viejo no vale y el número importado no puede llamar. Rehacer la importación con una credencial **restringida** (D-2 / D-3 / B-6: key de solo voz o subcuenta (b′)), **no** con el token maestro nuevo, que si no queda en la misma custodia que el viejo. Borrar después el secreto viejo del workspace |

**Vuelta atrás.** Antes del paso 7: `bash /root/h8-otp-token-rotate.sh vuelta
<copia previa>` (solo el token; antes de escribir comprueba con un GET que
Twilio sigue aceptando el de la copia) o la vuelta exacta que imprime el mando
(la copia previa entera). Después del paso 7 no existe: si algo falla, el camino
es un token nuevo (pasos 1 a 8).

**Si la voz usara la cuenta del OTP** y su token de firma
(`TWILIO_VOICE_SIGNING_TOKEN`) fuera este, el mando lo avisa: al promocionar
habría que cambiarlo con `h8-voice-scope.sh fase 2 secreto`, con las llamadas
apagadas. Hoy no es el caso: las piezas `TWILIO_VOICE_*` no existen.
