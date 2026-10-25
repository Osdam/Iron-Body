# Operación de ULTRON STUDIO: scripts reutilizables

Viven en el repo porque el scratchpad de las sesiones (`/tmp`) se purga a los tres días y el 24-10-2026 se perdieron los originales. Ninguno contiene secretos; ninguno toca producción salvo los dos de despliegue, que se ejecutan solo con autorización y con el ciclo completo en verde.

| Script | Para qué | Dónde se ejecuta |
|---|---|---|
| `pgunit.sh <args phpunit>` | Puerta obligatoria: PHPUnit del Studio contra un PostgreSQL 14 desechable (`~/.ironbody-pg14-disposable`, puerto 55432, usuario `ironbody`). `UNIT_DB` debe empezar por `ultron_studio_`. Solo PG detecta la falta del cerrojo de reglas | Mac de desarrollo |
| `smoke-h4.py` | Smoke del laboratorio (Hito 4) sin filas positivas: `SMOKE_LAB_EXPECT_ENABLED=0` (fase 1, apagado) o `=1` (fase 2, `fake`). `SMOKE_API` para apuntarlo a una pila local | Servidor (vía `smoke-run-h4.sh`) o local |
| `smoke-run-h4.sh` | Reactiva la cuenta temporal admin id 32 con contraseña nueva, ejecuta `smoke-h4.py`, la desactiva y comprueba el 403 | Servidor, como root, tras copiar los dos ficheros a `/root/` |
| `deploy-crm.sh <build> <prod-actual>` | Publica el CRM: exige build sellado y limpio, producción en el commit indicado y `origin/main` = build; respaldo de `index.html`/`version.json`; `rsync` **sin `--delete`**; verificación pública | Mac de desarrollo |

Runbooks completos: `../ULTRON_STUDIO_DEPLOYMENT_PLAN.md` (por hito y por fase) y `../ULTRON_STUDIO_ROLLBACK.md`. Migraciones en producción: siempre `sudo /usr/local/bin/ironbody-migrate` (rol dueño, `lock_timeout` 5 s, regenera la caché de configuración como `www-data`); `route:cache` aparte como `www-data` + `chmod 600`.
