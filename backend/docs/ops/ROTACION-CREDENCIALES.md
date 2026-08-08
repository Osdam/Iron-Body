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
