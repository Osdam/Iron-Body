# Informe de devops · el orden del cierre operativo: siete unidades, ensayadas encadenadas

**23-11-2026 (UTC; 22-11 en Bogotá) · ops-orden (devops) · árbol `_worktrees/cierre-pagos`, rama `cierre/ops-orden` · SOLO BANCO LOCAL: nada de producción, nada de `ssh`, ninguna petición a hosts reales, sin commit ni push.**
Marcas: **[C]** comprobado ejecutando o leyendo el código · **[L]** leído en un documento · **[S]** supuesto.

## 0. En diez líneas
1. **Las siete unidades, encadenadas en el banco, con 0 en comprobar, desplegar, volver atrás y desplegar otra vez** [C]. Cada una corrió sobre la anterior ya desplegada, dentro de tmux y sin `--sin-tmux`. Evidencia: `ops/evidencia-h9/2026-11-23/01-ensayo-de-la-cadena-de-despliegues.txt` (1961 líneas, sin secretos).
2. **El producto al final de la cadena es idéntico al de `integracion/final` `4e53f04a`**: `git diff --stat` vacío en `backend/app`, `bootstrap`, `config`, `database` y `routes`, y en todo lo que no es `docs/` ni `tests/` [C]. **Única diferencia: una prueba**, `UltronRecorridoWhatsappSimuladoTest.php` (PT-12, `14a99e69`), un commit hecho directamente en `integracion/final` que no está en ninguna rama de unidad.
3. **Una enmienda del guion, hecha**: `RE_CONFIG` admite «.» en `texto:<palabra>`. Sin ella, `ultron_studio.lab.openai.model=texto:gpt-4.1-mini` no pasa la validación. Son 2 líneas de código y 3 de comentario. Huella nueva **`db4bc1f5d856da8d945c9f305cded769ba6c9d8c14ebc8a4a8672f0bea78547a`**. Prueba unitaria 28/28, las 25 líneas de `--config` del repo aceptadas, y la cadena entera como regresión (§4.1). **Pendiente de revisión** (reviewer y security).
4. **Una enmienda propuesta y NO hecha** (unidad 6): el tipo «columna» admite la columna con llave foránea, pero **no la comprueba entera**: una columna por migración, ninguna llave y ningún cerrojo sobre `admins`. Propuesta en el §4.2; mientras tanto, consulta a mano obligatoria en el «Después».
5. **2b (el modelo del laboratorio) va dentro de la unidad 2**. Lo escribe `cierre-lotes/lote-5/entorno-lab.sh`, un script nuevo con el mecanismo de `h8-voice-scope.sh`, ANTES del despliegue. La caché la regenera el guion como `www-data` (en el paso M por `ironbody-migrate` y en el K por `config:cache`), y el paso 4 comprueba las 6 banderas. Ensayado, con un caso negativo (sin 2b → vuelta atrás automática, 4) y su vuelta con y sin `--regenera`.
6. **Unidad 3 (PT-17)**: la canaria cae a Legacy (`compiler_changed`) hasta publicar con el compilador 5. **`ultron-studio:publication-check` no lo ve** (sale 0); quien lo ve es **`ultron:prompt-trace --json`**. Además, la orden se llama `ultron-studio:publication-check`, no `ultron:publication-check`.
7. Los choques reaparecieron donde dijiste: unidad 6 (`AuthorizationMap.php`, `UltronStudioEngineReadOnlyTest.php`) y unidad 7 (`ULTRON_STUDIO_H5_CONTRACT.md`). **Tus dos guiones los resuelven igual que en `integracion/final`, byte a byte** [C]. Las unidades 2 a 5 no chocan; la 4 (`ba4b143e`) ya desciende de `f1fe6cac`.
8. Ficha: `ops/DEPLOY_CIERRE_LOTES.md` §5 quater (la tabla de las siete y las fichas 2/2b, 3, 4, 5, 6 y 7), más §4, §5, §5 bis, §7, §8, §9 y §10 al día. Declaraciones: `ops/cierre-lotes/{pt-19,lote-5,pt-17,lote-4,pt-16,lote-3,pt-15}/`, cada una con `fuera-de-docs.txt`, la lista exacta para el `diff` de la preparación.
9. **En el índice de `cierre/ops-orden`, 18 rutas, sin commit** (§8). Base del banco borrada, API parada, ningún tmux mío vivo.
10. Lo que no se puede comprobar sin el servidor, en el §6. Ningún secreto expuesto ni que rotar.

## 1. Entorno
- **Banco local, no producción** [C]. PostgreSQL 14.24 en 127.0.0.1:55432; la base `ultron_studio_ops2_orden`, **borrada** (recuento 0). `artisan serve` en :8611, **parado**. bash 3.2 y el awk de macOS. `banco-cierre/ensayar.sh` del repo, sin tocar. Un montaje para la cadena, `montar-cadena.sh` (en `…/scratchpad/cierre/ops-orden-banco/`, fuera del repo): es `montar.sh` con dos cambios y nada más. Los commits vienen de un clon desechable con la cadena, y el fichero de entorno sintético lleva además un secreto interno (sin él, el marcador de PT-19 da 503) y cuatro valores «previos» del laboratorio (`modelo-previo-del-banco`, `medium`, `9.99`, `19.99`), todos sintéticos.
- **La cadena, en ramas temporales del banco** (el clon desechable; nada en el repo: ni refs, ni commits, ni fetch) [C]:
  - `u1 a9289647` = `origin/main` `bfe61ba6` + `cierre/puertas-internas` `c78e248e` + `cierre/ops-pt19` `df095ad9`
  - `u2 66fff950` = u1 + `cierre/lab` `bdb93199`
  - `u3 4b9b7677` = u2 + `cierre/planes-c5` `e5a673c1` + `cherry-pick -x c4323242`
  - `u4 dc7e7503` = u3 + `integracion/consent` `ba4b143e`
  - `u5 ab5440c8` = u4 + `cierre/pagos-guarda-iva` `e1c2af3c`
  - `u6 125ec246` = u5 + `cierre/voz-catalogo` `e610d035` (dos choques)
  - `u7 a55ba5cb` = u6 + `cierre/planes-condiciones` `a994b050` (un choque)

  En `main`, los SHA serán otros. Lo que vale es el árbol de producto, que es el mismo si se fusionan las mismas puntas con las mismas resoluciones; `fuera-de-docs.txt` lo comprueba en la preparación.
- Recursos: un ensayo cada vez, ninguna batería de PHPUnit, nada en paralelo.

## 2. Lo que hubo que resolver
| Dónde | Qué | Cómo |
|---|---|---|
| Unidad 3 | El pegamento `c4323242`: no está en `cierre/planes-c5` | `cherry-pick -x` sobre la fusión: entra limpio (3 ficheros: `StudioLabEngineFlow.php` y dos pruebas) |
| Unidad 6 | Choques en `AuthorizationMap.php` y `UltronStudioEngineReadOnlyTest.php` | `python3 -I …/pt14/resuelve-fusion-pt14.py <backend>`: los dos, byte a byte = `integracion/final` |
| Unidad 7 | Choque en `ULTRON_STUDIO_H5_CONTRACT.md` | `python3 -I …/resuelve-contrato.py <contrato>`: byte a byte = `integracion/final` |
| Unidad 2b | `texto:gpt-4.1-mini` no pasa `RE_CONFIG` (el punto) | **Enmienda mínima** del guion (§4.1) |
| Unidad 2b | `0.40` y `1.60`: `banderas()` normaliza los números (`0.4`, `1.6`), así que `=0.40` no cuadraría nunca | Se declaran `0.4` y `1.6` (comentado en `config.txt`) |
| Unidad 2b | `REASONING_EFFORT` «vacía»: con `KEY=`, `env()` da `""` y el guion imprime `vacia`; ausente daría `null`. Para el laboratorio es lo mismo: `EFFORT_PATTERN` rechaza `""` y no se envía [C] | Se escribe `KEY=` y se declara `vacia` |
| Unidad 7 | `routes/marketing.php` exige `--rutas`, pero no hay ninguna ruta nueva | `rutas.txt` con solo comentarios: el guion lo admite (diferencia vacía), regenera la caché de rutas y pasa `auth:coverage` [C] |
| Unidad 6 | El tipo «columna» y las dos columnas con llave | Se declara `commercial_sale_by` y el resto va a mano (§4.2); NO enmendado |

## 3. Por unidad: órdenes exactas y resultado
En todas, antes, la preparación del §5 quater, que compara con `diff` lo de fuera de `docs/` contra `cierre-lotes/<U>/fuera-de-docs.txt`; después, la vigilancia del §7. En el servidor, cada una dentro de `tmux new -s u<k>`. Los códigos del banco son comprobar · desplegar · volver · desplegar otra vez.

**1 · PT-19** (`/root/cierre-pt19/`, la preparación del §5 ter) · **0 · 0 · 0 · 0**. Marcador presente → ausente (1.ª lectura) → presente → ausente. Paso 0: `0 de backend/app · … · 1 de bootstrap · 1 de tests · 48 de docs · 0 otros`. Es también la regresión de la vía `--bootstrap` con el guion enmendado.
```bash
H=d8e9cd270b928afe0e5495809c779c4cd0c753abd025f4919cc1c755c26cb710
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-pt19/lista.txt --bootstrap=$H --marcador=/root/cierre-pt19/marcador.txt --solo-comprobar
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-pt19/lista.txt --bootstrap=$H --marcador=/root/cierre-pt19/marcador.txt
```

**2 · lote 5 + 2b** (`/root/cierre-lote-5/`) · `entorno-lab comprobar` 0 · **comprobar 0** · `entorno-lab aplicar` 0 · **desplegar 0** · **volver 0** · `entorno-lab vuelta` 0 · **NEGATIVO** (desplegar sin 2b) **4** · `aplicar` 0 · **otra vez 0**. Al final de la cadena, `entorno-lab vuelta --regenera` 0. La migración, `[0 - - -]` → `[1 json null -]`. Las banderas: en el paso 0, `lab_source ausente | lab_gate texto:required | model cadena | reasoning_effort cadena | 9.99 | 19.99`; en el paso 4, `texto:simulation | texto:required | texto:gpt-4.1-mini | vacia | 0.4 | 1.6`. `--sin-filas` 0 dos veces. Respaldo de 2b: 700/600; «cambian exactamente las 4 claves, ninguna otra», comprobado con Dotenv antes y después de escribir.
```bash
cd /root/cierre-lote-5
bash entorno-lab.sh comprobar
bash /root/deploy-cierre-lote.sh $P $N lista.txt --migraciones=migraciones.txt --orden=migracion-primero --config=config.txt --sin-filas=sin-filas.txt --solo-comprobar
bash entorno-lab.sh aplicar                       # anota el respaldo /var/backups/ironbody/entorno-lab-<fecha>
bash /root/deploy-cierre-lote.sh $P $N lista.txt --migraciones=migraciones.txt --orden=migracion-primero --config=config.txt --sin-filas=sin-filas.txt
# vuelta: --vuelta-atras=<respaldo del guion> y, para 2b, bash entorno-lab.sh vuelta=<respaldo de 2b> (el orden y el porqué, en la ficha)
```

**3 · PT-17** (`/root/cierre-pt-17/`) · **0 · 0 · 0 · 0**. `prompt-trace`: `compiler` 4 → 5 → 4 → 5. `publication-check`: 0 en las cuatro. La sonda con las clases de `u3`: 213 expresiones, 0 que no compilan, `HUELLA ff5042f4fb3910f6`. Paso 0: `2 de backend/app · 0 de config · 0 de routes · 0 migración(es) · 0 de bootstrap · 20 de tests · 4 de docs · 9 otros`. Los 9 «otros» son de `n8n/`; el de `flujo-s2.json` es solo `_generado.compiler`, y el guion no toca n8n.
```bash
sudo -u www-data php /var/www/api/backend/artisan ultron:prompt-trace --json --last=1     # antes: compiler 4, current.mode studio
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-pt-17/lista.txt --solo-comprobar
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-pt-17/lista.txt
sudo -u www-data php /var/www/api/backend/artisan ultron:prompt-trace --json --last=1     # después: compiler 5, legacy, compiler_changed
sudo -u www-data php /var/www/api/backend/artisan ultron-studio:publication-check; echo $?  # 0 (la cadena verifica; no ve el compilador)
```

**4 · lote 4** (`/root/cierre-lote-4/`, `ba4b143e`) · **0 · 0 · 0 · 0**. `[presente h5_owner ra 3]`, los privilegios uno a uno exactos y las secuencias `[1 1]`; 1046 → 1052 rutas; `auth:coverage` vacío; la GET nueva, 401 → 404 → 401. En `bootstrap/app.php` solo se añade el montaje, sobre lo de PT-19: va por `--rutas`, sin `--bootstrap`.
```bash
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-lote-4/lista.txt --migraciones=/root/cierre-lote-4/migraciones.txt --orden=migracion-primero --config=/root/cierre-lote-4/config.txt --rutas=/root/cierre-lote-4/rutas.txt [--solo-comprobar]
```

**5 · PT-16** (`/root/cierre-pt-16/`) · **0 · 0 · 0 · 0**. Paso 0: `1 de backend/app · … · 1 de tests · 0 de docs · 0 otros`.
```bash
sudo -u www-data php /var/www/api/backend/artisan config:show billing.pricing.v2_enabled    # decide si hay impacto
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-pt-16/lista.txt [--solo-comprobar]
```

**6 · voz: lote 3 + PT-14** (`/root/cierre-lote-3/`; **autorización específica del propietario**) · **0 · 0 · 0 · 0**. La transición, 20 ms; PT-14, 24 ms. Volver: PT-14 `SE CONSERVA` y el disparador `DESHECHA` («equivalente»: PostgreSQL reescribe los cast). La consulta a mano, antes y después de volver: 2 columnas `bigint null -` con `UPDATE` de la aplicación y 2 llaves → `admins`, `ON DELETE n`, validadas. Otra vez: PT-14 se reanuda y la transición se aplica de nuevo.
```bash
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-lote-3/lista.txt --migraciones=/root/cierre-lote-3/migraciones.txt --orden=migracion-primero --config=/root/cierre-lote-3/config.txt [--solo-comprobar]
# después, obligatoria: la consulta de las dos columnas y las dos llaves (DEPLOY_CIERRE_LOTES.md §5 quater, unidad 6)
```

**7 · PT-15** (`/root/cierre-pt-15/`) · **0 · 0 · 0 · 0**. `marketing_ai_check`: `ausente` → `60`. `route:list` 1052 → 1052, sin diferencia; `auth:coverage` vacío. `route:list -v`: `throttle:internal-marketing-ai` → `…-ai-check` → `…-ai` → `…-ai-check`. La sonda con las clases de `u7`: 215 expresiones, 0 que no compilan, la misma huella (su corpus no ejercita los casos nuevos de C-1: mide la compatibilidad del PCRE, no el comportamiento).
```bash
cd /var/www/api/backend && sudo -u www-data php artisan route:list -v --path=internal/marketing/ai/check   # antes: throttle:internal-marketing-ai
bash /root/deploy-cierre-lote.sh $P $N /root/cierre-pt-15/lista.txt --config=/root/cierre-pt-15/config.txt --rutas=/root/cierre-pt-15/rutas.txt [--solo-comprobar]
cd /var/www/api/backend && sudo -u www-data php artisan route:list -v --path=internal/marketing/ai/check   # después: throttle:internal-marketing-ai-check
```
La sonda (unidades 3 y 7), en el servidor y antes de desplegar, como dice su cabecera: `git archive $N backend/app/Services/Marketing/Ultron | tar -x -C <dir> --strip-components=1` y, desde `backend/`, `CLASES=<dir> php <sonda>`. Tiene que dar `no compilan: 0` y `HUELLA ff5042f4fb3910f6`.

## 4. Enmiendas del guion
### 4.1 Hecha: `RE_CONFIG` (unidad 2b)
- **Diff** (+5 −2; dos de código, el resto comentario): `texto:[a-z][a-z0-9_-]{0,31}` → `texto:[a-z][a-z0-9_.-]{0,31}`, más el comentario de `--config` y el de la expresión.
- **Falla cerrado**: la palabra sigue empezando por letra; no admite mayúsculas, espacios, `/`, `$`, comillas ni barras invertidas; hasta 32. Y `banderas()` solo imprime `texto:<palabra>` si la caché tiene EXACTAMENTE esa cadena (si no, `cadena`), así que el punto no abre nada nuevo. Respeta la regla m2: va entre corchetes, sin barras.
- **Ensayo de regresión**: (a) prueba unitaria con la expresión leída del guion, 28/28; con `ca03c1b5…`, 26 (rechaza las dos con punto). (b) Las 25 líneas de `--config` del repo: 25/25; con `ca03c1b5…`, 24/25. (c) La cadena entera con el guion enmendado: la vía `--bootstrap` (u1), `--rutas` + montaje (u4), los tipos `columna`, `tabla` y `transicion`, `--config`, `--sin-filas` y la vuelta automática (el negativo de u2). El tipo `check` no lo ejercita ninguna unidad.
- **Pendiente**: la revisión de reviewer y security, y que `N` traiga `cierre/ops-orden` desde la unidad 1. El §5 ter manda subir el guion desde `N`, y la unidad 2 necesita esta huella.

### 4.2 Propuesta y NO hecha: `columna_fk` (unidad 6)
**Lo que pasa hoy** [C, `lee_migraciones` y `estado_mig`, y el ensayo]: (1) `lee_migraciones` exige los nombres en orden estricto (`[[ "$ant" < "$nom" ]]`), así que una migración con dos columnas solo declara una. (2) `estado_mig` de una columna solo lee `pg_attribute`, nunca `pg_constraint`. (3) `T_MIG` (los cerrojos del paso 0) solo lleva las tablas declaradas: `admins` no está. La unidad pasa igual, y bien, **porque la migración de PT-14 va en una transacción**: «registrada y con `commercial_sale_by`» implica lo demás. Pero eso es atomicidad, no comprobación.

**La enmienda mínima** (unos 30–40 líneas, con su ensayo):
- Tipo nuevo `columna_fk`: `nombre columna_fk tabla columna <tipo>><tabla referida> - conservar`, con la marca validada por una expresión del estilo de `RE_MARCA_TRANS`.
- `estado_mig`: además de `pg_attribute`, `count(*)` en `pg_constraint` con `contype = 'f'`, `conkey = ARRAY[attnum]`, `confrelid = to_regclass(ref)` y `convalidated`, más `confdeltype`. Antes `0 - - - -`; después `1 bigint null - fk:<ref>:<deltype>`.
- `lee_migraciones`: el mismo nombre en líneas seguidas, solo para `columna`/`columna_fk`.
- `ESPERADAS` y `PEND_N`: los nombres sin repetir.
- `migra`: no relanzar `ironbody-migrate` para la segunda línea de una migración ya aplicada en este despliegue.
- `T_MIG`: con las tablas referidas.

Y un ensayo de la unidad 6 con las dos líneas, más un caso negativo: una llave que falte → vuelta atrás automática.

**Mientras no esté**, el «Después» de la unidad 6 lleva, obligatoria, la consulta de solo lectura de las dos columnas y las dos llaves. Decides tú si se enmienda antes de la unidad 6.

## 5. `entorno-lab.sh` (unidad 2b), lo nuevo
- **Qué hace**. `comprobar` es solo lectura. `aplicar` hace el respaldo (`/var/backups/ironbody/entorno-lab-<UTC>`, 700 de root; `env.antes` 600), lo compara con Dotenv **como `www-data`** y escribe de forma atómica, conservando el dueño y el modo. Después lo relee, y si no cuadra repone el respaldo solo (4). `vuelta=<respaldo> [--regenera]` repone el fichero byte a byte; con `--regenera`, además, `config:cache` como `www-data` con umask 077, la caché en 600 y `queue:restart`.
- **Lo que imprime**: los nombres de las claves y su estado (`ausente`, `vacía`, `= lo decidido`, `otro valor`) y lo decidido, que es público. **Nunca una línea del fichero ni un valor previo.** La declaración rechaza cualquier clave con `API_KEY`, `SECRET`, `TOKEN` o `PASSWORD`, y solo admite claves `ULTRON_STUDIO_LAB_*`.
- **Casos límite ensayados** [C]: claves ausentes, que añade sin tocar lo demás; una clave repetida, que aborta (1) sin respaldo; `export` y sangría; modo 640, que conserva; una declaración secreta (1); `.env` como enlace (1); un respaldo alterado (2); un fichero sin salto de línea final.
- **Quién regenera la caché y con qué usuario**: el guion, en la unidad 2. En el paso M lo hace `ironbody-migrate`, que pasa a `www-data` con `setpriv`; en el paso K, `config:cache` con `sudo -u www-data` y umask 077. Nunca root.
- **Lo que lo hace seguro de ordenar así**: mientras exista la caché, nadie lee el fichero de entorno, así que escribirlo antes del despliegue no cambia nada hasta el paso M. `--sin-filas` impide que una ejecución del laboratorio quede a caballo del cambio.

## 6. Lo que NO se puede comprobar sin el servidor real
- **bash 5, glibc y mawk** con la enmienda: en el `--solo-comprobar` de la unidad 2 no tiene que salir `línea de --config no válida`. Y los demás puntos del §9 de la ficha: m2, C1, m4, C2, supervisor, FPM, el envoltorio.
- **2b**: el dueño y el modo reales del `.env` (los imprime `comprobar`); si alguna clave está repetida o con `export`; el valor de hoy, que nunca se imprime (según `ULTRON_STUDIO_FINAL_REPORT.md`, el esfuerzo es hoy `medium` [L]); si el pool de FPM o supervisor fijan alguna de esas variables; y que `GET lab/status` muestre `gpt-4.1-mini` [S: la forma, sin credenciales].
- **Unidad 3**: el paso de `studio` a `legacy`/`compiler_changed`. El banco no tiene una publicación firmada con el compilador 4 [C por lectura de `UltronPromptOffer`, paso 5]. La huella de la sonda con el PCRE de producción (10.39 [L]).
- **Unidad 5**: el valor real de `billing.pricing.v2_enabled`.
- **Unidad 6**: el tamaño de `plans` y los cobros en curso durante el ALTER (ACCESS EXCLUSIVE sobre `plans`; SHARE ROW EXCLUSIVE sobre `admins`, que el paso 0 no mira); qué cuentas editan hoy planes vendibles sin ser Super Admin (la llave nueva `plans.commercial_text` las deja fuera hasta que se les asigne); `voice:doctor` real.
- **Unidad 7**: `route:list -v` sobre la caché real; que `MARKETING_AI_CHECK_RATE_LIMIT_PER_MINUTE` no exista ya en el entorno (si existe, el paso 4 vuelve atrás; PARAR antes).
- **PT-19**: lo del §5 ter y §9 (nginx/HTTP2, `revalidate_freq`, `php_admin_value`).
- El tráfico real (n8n, la canaria, cobros): lo mira una persona en cada «Después».

## 7. Riesgos, deuda y lo adyacente (no arreglado)
- **Unidad 3 cambia lo que oye la canaria** (Legacy) hasta que el propietario publique con el compilador 5. Lo esperado; conviene que lo sepa antes de autorizarla.
- **Unidad 6 restringe la edición de planes vendibles a Super Admin** hasta asignar la llave: puede parecer un fallo a quien edite planes hoy.
- **La prueba de PT-12** solo está en `integracion/final`.
- **`fuera-de-docs.txt` es del banco**: si `main` mete algo fuera de `docs/` entre unidades, el `diff` lo caza y hay que PARAR; no hay que «arreglar» la lista.
- **Cosmético del guion, preexistente**: en una reanudación (la migración ya registrada) el paso M imprime «la regeneró ironbody-migrate» aunque `ironbody-migrate` no corrió. La comprobación es correcta (`cmp`); el texto, no. No lo he tocado.
- **Del banco, no del despliegue**: el AVISO del migrador, que es la copia con la ruta del banco; `ensayar.sh arrancar` retiene la salida si se le pone una tubería (me pasó con `| tail`), así que se lanza sin tubería. `montar.sh` no sabe de cadenas: el montaje de la cadena vive fuera del repo.
- `shellcheck`, sin pasar (no está instalado).
- **Secretos**: el banco llevaba una clave de aplicación y un secreto interno sintéticos, con 0 apariciones en la evidencia (comprobado al escribirla). No he leído ninguna credencial real. Nada que rotar.

## 8. Lo que queda en tus manos (no lo he hecho)
1. **El commit de `cierre/ops-orden`**. En el índice están exactamente estas 18 rutas, todas bajo `backend/docs/ultron-studio/ops/`:
   - `DEPLOY_CIERRE_LOTES.md` y `deploy-cierre-lote.sh`
   - `cierre-lotes/lote-3/{config.txt,migraciones.txt,fuera-de-docs.txt}`
   - `cierre-lotes/lote-4/fuera-de-docs.txt`
   - `cierre-lotes/lote-5/{config.txt,config-sin-2b.txt,entorno-lab.sh,entorno-lab.txt,fuera-de-docs.txt}`
   - `cierre-lotes/pt-15/{config.txt,rutas.txt,fuera-de-docs.txt}`
   - `cierre-lotes/pt-16/fuera-de-docs.txt`, `cierre-lotes/pt-17/fuera-de-docs.txt` y `cierre-lotes/pt-19/fuera-de-docs.txt`
   - `evidencia-h9/2026-11-23/01-ensayo-de-la-cadena-de-despliegues.txt`
2. **La revisión** de la enmienda de `RE_CONFIG` y de `entorno-lab.sh` (reviewer y security: el segundo escribe el fichero de entorno de producción).
3. **Decidir sobre `columna_fk`** (§4.2) antes de la unidad 6.
4. **Integrar en `main` unidad a unidad**, con `cierre/ops-orden` en la primera; la batería de cada una; y la autorización del propietario para cada despliegue, con la específica para la 6.
