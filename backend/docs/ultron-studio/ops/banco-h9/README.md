# Banco local del Hito 9 (`ops/banco-h9/`)

Una «producción» de juguete en el Mac para ensayar, sin tocar producción y sin red, lo que OPS9 va a ejecutar en el
servidor: el despliegue de la fase 1 del H9 (`deploy-h9-backend-fase1.sh`), el paso aparte de los índices (`--indices`),
la comprobación del esquema (`h9-schema-check.sql`), el smoke (`smoke-run-h9.sh` + `smoke-h9.py`) y el mando
(`h9-outreach-scope.sh`). Es el banco del Hito 8 (`banco-h8/`) sin los simuladores de ElevenLabs y Twilio: la fase 1 del
H9 tiene CERO red (§9.3 del contrato), así que basta la valla del H8 (`banco-h8/valla.cjs`, reutilizada tal cual).

## Qué monta `montar.sh`

- `$B/src`: el origen local. `main` = HEAD del worktree más lo que no está confirmado en `backend/{app,config,routes,
  database,bootstrap/app.php,.env.example}`, en **dos commits** (L9-34): primero `wip(ultron-studio-h8)` con el cierre
  del H8 (todo lo que no es del H9) y después `wip(ultron-studio-h9)` con el H9 (lo que `comun.sh::es_h9` reconoce por
  su ruta). El paso 0 del despliegue reconoce el segundo por su prefijo y ve el primero como la «producción».
- `$B/api`: la «producción», en el commit del cierre del H8 (`PREVIO`), con `vendor` clonado del worktree.
- PostgreSQL 14 desechable (`127.0.0.1:55432`, `pgunit.sh`): base `ultron_studio_banco_h9_ops` con el modelo de roles de
  producción (`h5_owner` = `iron`, dueño sin superusuario; `h5_app` = `iron_app`, solo DML), migrada hasta el H8 **con
  sus REVOKE** (`ULTRON_STUDIO_DB_APP_ROLE=h5_app`): 18 tablas `ultron_studio_*`, 7 `voice_*`, 0 `outreach_*`.
- El fichero de entorno SINTÉTICO de la fase 1 del H8 cerrada (todo apagado, sin piezas de voz) y **sin ninguna clave
  del H9**: las 15 las añade el despliegue (paso 2).
- Los sustitutos de `sudo`, `stat` (GNU), `supervisorctl` (13 programas), `systemctl`, `sha256sum` y `flock`, el
  respaldo con el formato del runbook (`$B/bk`) y `banco.env`.

Nada vive dentro del repo (`banco_dir` lo impide): el banco lleva un volcado y un fichero de entorno.

## Uso

```bash
B=${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-banco-h9
bash docs/ultron-studio/ops/banco-h9/montar.sh "$B"            # BANCO_WIP=1 por defecto: el H9 vive sin confirmar
bash docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" arrancar   # la valla :8504 y la API :8501
bash docs/ultron-studio/ops/banco-h9/sembrar.sh "$B"            # la cuenta del smoke (y --personas=N para la sombra)
bash docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" todo       # paso 0 → despliegue → índices → esquema → smoke → cierre
# piezas sueltas
bash docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" desplegar --solo-comprobar
bash docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" desplegar
bash docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" desplegar --indices --acepta-hora
bash docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" esquema
bash docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" smoke            # fase 1; «smoke <id> 2» = sombra
bash docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" alcance estado   # el mando, con RED
bash docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" cierre
# limpiar
bash docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" parar && rm -rf "$B"
```

`todo` es repetible: sobre un banco recién montado hace la fase 1 entera; sobre uno ya desplegado, el despliegue
reanuda («nada que migrar») y el resto se repite. Para empezar de cero, `parar`, borrar el banco y `montar.sh`.

## Antes de que B9-run entregue la ejecución (OPS9-a, puerta 1)

**Con el árbol integrado (L9-59; re-ensayado por OPS9-b) estas dos muletas ya no se activan:** `desplegar` pasa el paso 0
(la constante está en `origin/main`), `config/outreach.php` existe en la «producción» del banco tras el despliegue y las
13 rutas de la ejecución responden bajo `admin/ultron-studio/outreach/**`. Se dejan por si el banco se monta sobre un
origen anterior a la integración. Lo que sigue describe ese caso.

El paso 0 del despliegue **aborta** sin `OutreachSendGate.php` en `origin/main` (la constante de envío tiene que
comprobarse cerrada antes del merge) y el smoke exige `config/outreach.php` desplegado: las dos cosas son de B9-run y
en la puerta 1 no existen. Para ensayar igualmente DB9 + OPS9, `todo` hace dos cosas, y las dice:

- si `desplegar` aborta, aplica `migrar`: el código del H9 en la «producción» del banco (`merge --ff-only origin/main`),
  las tres migraciones con el migrador del banco (rol dueño, `lock_timeout` 5 s) y las cachés. **No sustituye al
  despliegue**: no escribe las 15 variables ni hace las aserciones del paso 0;
- si `config/outreach.php` no existe en la «producción» del banco, lanza el smoke con `SMOKE_ALLOW_MISSING_EXEC=1`, que
  solo relaja la lectura de las banderas de la ejecución (sale como AVISO) y deja el bloque de la ejecución del smoke en
  OMITIDO (404). En producción no se usa nunca.

Cuando B9-run entregue, `todo` deja de necesitar las dos muletas solo.

## Los hechos de la voz y el recuento del monitor del H7 (`voz-hechos.sh`, 11-11-2026)

Sobre el banco de la ola M-1 (`m1.sh montar → ola → desplegar`), sin cambiar `m1.sh`:

```bash
B=${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-banco-m1
bash docs/ultron-studio/ops/banco-h9/voz-hechos.sh "$B" arrancar   # valla :8504 y API en BANCO_HECHOS_PORT (8541)
bash docs/ultron-studio/ops/banco-h9/voz-hechos.sh "$B" hechos     # h8-voice-scope.sh «hechos» (122 casos)
bash docs/ultron-studio/ops/banco-h9/voz-hechos.sh "$B" monitor    # h7-monitor-scope.sh con la sincronización de Meta on/off (20)
bash docs/ultron-studio/ops/banco-h9/voz-hechos.sh "$B" parar
```

Usa un `flock` DE VERDAD (fcntl sobre el descriptor heredado, en `$B/bin-hechos`), no el sustituto que no bloquea:
el caso del cerrojo común ocupado sí se ensaya. Informe y resultados: `plans/informes-motores/informe-devops-hechos-voz.md`
y `evidencia-h9/2026-11-11/hechos-voz/`.

## La preparación de la primera llamada (`telefonia-prep.sh`, 11-11-2026)

Ensaya los tres mandos que cambió la preparación de la primera llamada: `h8-voice-scope.sh salientes on` (C-1),
`el-verificar.sh` (C-3) y `h7-monitor-scope.sh` (`--check=prompts`). Usa un banco PROPIO, montado a partir de la «producción» del banco
de la ola M-1:
- una copia de su `api`;
- una base propia, clonada con `CREATE DATABASE … TEMPLATE`;
- el código en HEAD del worktree;
- el simulador de ElevenLabs del H8 (`banco-h8/sim-elevenlabs.cjs`) en 127.0.0.1, con piezas SINTÉTICAS;
- un `flock` de verdad.

No cambia `m1.sh` ni el banco de origen.

```bash
B=${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-banco-telefonia
bash docs/ultron-studio/ops/banco-h9/telefonia-prep.sh "$B" montar     # BANCO_ORIGEN = el banco M-1 (parado)
bash docs/ultron-studio/ops/banco-h9/telefonia-prep.sh "$B" arrancar   # valla :8574, ElevenLabs simulado :8572, API :8571
bash docs/ultron-studio/ops/banco-h9/telefonia-prep.sh "$B" voz        # C-1 (54 casos)
bash docs/ultron-studio/ops/banco-h9/telefonia-prep.sh "$B" el         # C-3 (86 casos)
bash docs/ultron-studio/ops/banco-h9/telefonia-prep.sh "$B" monitor    # --check=prompts (12 casos)
bash docs/ultron-studio/ops/banco-h9/telefonia-prep.sh "$B" parar      # o «borrar»: también la base y el directorio
```

**La constante.** Se abre con un commit `voice(calls-open):` que solo existe en el CLON del banco, nunca en el repo. Se
vuelve a cerrar con el checkout del runbook.

**Informe y salida íntegra:**
- `plans/informes-motores/informe-devops-telefonia-prep.md`;
- `evidencia-h9/2026-11-11/telefonia-prep/`.

## Puertos

| Qué | Variable | Por defecto |
|---|---|---|
| API (`artisan serve`, 4 workers) | `BANCO_PORT` | 8501 |
| Valla (proxy que bloquea y anota) | `BANCO_PUERTO_VALLA` | 8504 |
| PostgreSQL desechable | `BANCO_PG_PORT` | 55432 |

## Qué no prueba

- Los proveedores: no hay simuladores (la fase 1 del H9 no habla con nadie; W9/V9 tendrán los suyos en su enmienda).
- `flock`: en macOS no bloquea; la exclusión del mando se prueba en el servidor.
- Los usuarios de sistema (`www-data`, `root`, `ironbody-migrate`): todo corre como el usuario del Mac; los sustitutos
  de `sudo`/`stat` solo dan la forma.
- La hora de poco tráfico del paso aparte: `todo` pasa `--acepta-hora`.
