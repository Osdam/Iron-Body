# Informe de devops · PT-19: una vía de despliegue declarada para `bootstrap/app.php` (NB-4)

**22-11-2026 · ops-pt19 (devops) · árbol `_worktrees/cierre-pagos`, rama `cierre/ops-pt19` · SOLO BANCO LOCAL: nada de producción, nada de `ssh`, ninguna petición a hosts reales, sin commit ni push.**
Marcas: **[C]** comprobado ejecutando · **[L]** leído · **[S]** supuesto.

## 0. En ocho líneas
1. `deploy-cierre-lote.sh` acepta dos banderas nuevas que van juntas: **`--bootstrap=<sha256 entero>`** y **`--marcador=<fichero>`**. Sin ellas se comporta igual que antes (regresión de los lotes 5 y 4: salida idéntica a la del lead) [C].
2. Con `--bootstrap`, `backend/bootstrap/app.php` solo pasa si su sha256 en `N` es **exactamente** el declarado. Para PT-19 es `d8e9cd27…cb710`, el del fichero revisado (`5cd201eb` = `c78e248e`) [C].
3. El marcador es `GET api/internal/marketing/ai/doctor 401 x-ratelimit-limit presente ausente`. El paso 0 exige el estado del previo; el paso 4, el del nuevo antes de `revalidate_freq` + 30 s (si no llega, vuelve atrás solo); la vuelta atrás, otra vez el del previo (si no, PARAR) [C].
4. PT-19 en el banco: comprobar 0 · desplegar 0 · volver 0. El marcador pasó a `ausente` en la 3.ª lectura (la revalidación de OPcache del servidor del banco) y volvió a `presente` [C].
5. Diez casos negativos, todos con el código y el motivo esperados. Entre ellos, **N9**: un servidor que no revalida el PHP, que el paso 0 no puede ver. El marcador no cambia y hay vuelta atrás automática (4) [C].
6. **El paso 0 ya abortaba si el FPM no revalida** (`validate_timestamps=0` en `/etc/php/*/fpm/`): comprobado (N7a). Con `--marcador` aborta además si `revalidate_freq` no es un número de como mucho 300 s (N7b). No se añade ninguna recarga de servicios [C].
7. Huella nueva del guion: **`ca03c1b5d5ec0c5d46918a91d37c188f09dd070093e5af3ad553cd16380503be`** (1 245 → 1 362 líneas). En el índice de `cierre/ops-pt19`, junto con la ficha (§5 ter), las declaraciones y la evidencia `11-…` [C].
8. Pendiente: tu commit. La revisión de la enmienda (reviewer y, por tocar el despliegue, security) antes de usarla. **`N` tiene que traer `cierre/puertas-internas` y `cierre/ops-pt19`.** Y la orden para desplegar, que no he ejecutado.

## 1. Entorno
- **Banco local, no producción** [C]: `banco-cierre/montar.sh` y `ensayar.sh` del árbol, sin tocar. PostgreSQL 14.24 en 127.0.0.1:55432; las bases `ultron_studio_ops2_pt19a`, `…_regr5a` y `…_regr4a`, **borradas al terminar** (recuento 0); `artisan serve` en :8597, parado.
- El guion que se ensaya es el del árbol `cierre/ops-pt19` (enmendado). `ensayar.sh` lo copia **con solo sus 15 líneas de cabecera sustituidas**, y lo comprueba en cada ejecución.
- Producción, según el lead: `f1fe6cac` [L]. `origin/main` = `bfe61ba6`, antecesor de `cierre/puertas-internas` (`c78e248e`) [C]. La fusión tiene el árbol de `c78e248e`: **46 ficheros** frente a `f1fe6cac`, de ellos 2 fuera de `docs/` (`bootstrap/app.php` M y la prueba nueva A) [C].

## 2. El diff del guion, explicado (líneas del fichero nuevo)
`git diff` en el índice: +133 −16. Cada pieza nueva **solo se activa con una bandera nueva**. Las líneas existentes que cambian son siete, y sin banderas nuevas su efecto es idéntico.

| Líneas | Qué admite | Por qué sigue fallando cerrado |
|---|---|---|
| 150 `RE_SHA256='^[0-9a-f]{64}$'` | la huella, entera y en minúsculas | 63, 65 o 16 cifras, o mayúsculas → código 2 (N5a; unidad) |
| 151 `RE_MARCADOR` | `GET api/<segmentos [a-z0-9_-]> <código 1xx–5xx> <cabecera en minúsculas> presente\|ausente presente\|ausente` | otro método, `{param}`, `..`, mayúsculas → no válida (unidad) |
| 171–174 `--bootstrap=?*` | la bandera, una vez | repetida o mal formada → 2 sin tocar nada |
| 175 `--marcador=?*` | un fichero legible, por la misma vía que las demás declaraciones | ilegible → 2 |
| 187 (vuelta) | — | `--marcador` o `--bootstrap` en `--vuelta-atras` → 2: la vuelta lee el respaldo (N5e) |
| 193–196 | — | `--bootstrap` sin `--marcador` o al revés → 2 (N5b, N5c); `--bootstrap` con `--rutas` → 2 (N5d), para que la vía del montaje no se mezcle con la de la huella |
| 289–299 `lee_marcador` | exactamente una línea | 0 o 2 líneas → no vale; **`presente presente` no vale**: un marcador que no distingue no marca nada |
| 350–358 `marcador_lee` | un `curl` sin credenciales, como `http()`; de la respuesta solo el código de la **última** línea de estado y si está la cabecera (sin distinguir mayúsculas) | no imprime ninguna cabecera ni su valor; sin respuesta → `000 ausente` |
| 361–369 `marcador_espera` | lee cada 5 s hasta verlo en el estado pedido o agotar el plazo; se para en cuanto cuadra (cada lectura con el previo gasta 1 de los 120 por minuto del asesor) | al agotar el plazo devuelve lo último que leyó, y quien la llama compara |
| 373–386 `plazo_marcador` | `revalidate_freq` (el mayor de `/etc/php/*/fpm/`; 2 s si no está) + 30 s | no numérico, más de 6 cifras o más de 300 → 1 → el paso 0 aborta (N7b) |
| 134 `DECLS += marcador` | el respaldo guarda `decl/marcador` (600) | sin bandera no hay fichero: el bucle no hace nada |
| 884–885 | valida el marcador antes de tocar git y lo dice en el registro | declaración inválida → ABORTA 1 |
| 920, **936–939** (la única rama existente que cambia) | con `--bootstrap`: `BOOT_DECL=1` y la comprobación va por la huella | **sin `--bootstrap`, la misma línea de antes**: sin `--rutas` aborta (N2), y con `--rutas` sigue al análisis del montaje, que aborta con `«$middleware->prependToPriorityList(»` (N3, el NB-4 de siempre). El texto del aborto sin `--rutas` añade una pista sobre `--bootstrap`; el código y el efecto, iguales. `backend/bootstrap/*` sigue abortando con cualquier bandera |
| 970–975 | `app.php` del commit nuevo con sha256 = el declarado | la huella de otro contenido → ABORTA (N4, con la de producción); la bandera sin `app.php` en el diff → ABORTA, «la ficha no es de este diff» (N10); `app.php` borrado → ABORTA. **La huella no se recalcula**: viene de la ficha |
| 979 | dice la huella en el registro | — |
| 1077–1083 (paso 0) | el marcador en su estado del previo (hasta 3 lecturas en 10 s) | otro código (503: N1) u otro estado (N6) → ABORTA; además fija el plazo |
| 1110 (respaldo) | `decl/bootstrap` con la huella, 600, dentro de `SHA256SUMS` | — |
| 1262–1267 (paso 4) | el marcador en su estado del nuevo antes del plazo | si no llega, `falla` → **VUELTA ATRÁS AUTOMÁTICA (4)** (N8, N9) |
| 801–806, 855–858, 864 (vuelta) | con `decl/marcador` en el respaldo: el marcador otra vez en su estado del previo | si no vuelve → `ok=0` → **PARAR (5)**. Con respaldos sin `decl/marcador` (todos los anteriores) no hace nada |

**Por qué el marcador no puede dar un falso verde** [C, por construcción]: exige un estado en el paso 0 y el contrario en el paso 4. Un analizador que leyera mal siempre lo mismo (por ejemplo, mawk en el servidor) haría abortar el paso 0 o volver atrás en el 4, pero nunca daría por bueno un despliegue. Por eso no he añadido una autoprueba del analizador como la de C1.

## 3. Los casos negativos (todos en el banco, salida en la evidencia §A–C)
| Caso | Esperado | Obtenido |
|---|---|---|
| N1 · marcador que no cumple en el paso 0 (banco sin secreto: la puerta da 503) | ABORTA 1 | 1 · `[503 presente]`, no `[401 presente]` |
| N2 · cambio de bootstrap **sin bandera** | ABORTA 1 | 1 · `el diff trae backend/bootstrap/app.php y no va --rutas…` |
| N3 · con `--rutas`, sin `--bootstrap` | ABORTA 1 | 1 · `añade código que no es el montaje…` (como antes) |
| N4 · **huella equivocada** (la del `app.php` de producción, `294e57ab…`) | ABORTA 1 | 1 · `tiene sha256 d8e9cd27…, no la declarada 294e57ab…` |
| N5 · 16 cifras / sin `--marcador` / sin `--bootstrap` / con `--rutas` / en la vuelta | 2 ×5 | 2 ×5 |
| N6 · marcador declarado al revés | ABORTA 1 | 1 · `[401 presente]`, no `[401 ausente]` |
| N7a · `opcache.validate_timestamps=0` en `etc-php/8.3/fpm/conf.d/` | ABORTA 1 | 1 · la comprobación anterior |
| N7b · `revalidate_freq=900` | ABORTA 1 | 1 · la nueva |
| N8 · marcador mal declarado que no cambia tras desplegar (`content-type`) | 4 | 4; el marcador vuelve a `presente` en la vuelta |
| N9 · el servidor **no revalida** el PHP (`PHP_INI_SCAN_DIR` con `validate_timestamps=0` solo en ese proceso) | 4 | 4: `401 presente` durante 32 s → vuelta atrás automática a `f1fe6cac` |
| N10 · `--bootstrap` sobre el lote 5 (sin `app.php`) | ABORTA 1 | 1 · `la ficha no es de este diff` |
| Unidad: analizador de cabeceras (HTTP/1.1, HTTP/2, 100 Continue, cabecera parecida, sin respuesta), `lee_marcador`, `RE_SHA256`, `plazo_marcador` | — | todo como se espera (evidencia §A) |

## 4. Los dos ensayos (y uno más)
- **PT-19** [C]: comprobar 0. Despliegue 0: `0 de backend/app · 0 de config · 0 de routes · 0 migración(es) · 1 de bootstrap · 1 de tests · 44 de docs · 0 otros`; la huella `= la declarada`; 14/14 workers; marcador `401 ausente tras 3 lectura(s)`; 0 errores. Vuelta atrás 0: marcador `401 presente tras 1 lectura(s)`, `route:list` y cachés las de antes. Las sondas a mano dieron `401 presente → 401 ausente → 401 presente`.
- **Regresión del lote 5** [C] (los mismos commits que el lead: `fbd5010f` + `72e76551`, previo `f1fe6cac`): 0 · 0 · 0. Normalizada (rutas del banco, base, puerto, horas, `N` y fusiones del banco, milisegundos de la migración, la huella del migrador del banco, que lleva su ruta), **idéntica a `ensayo-lead-lote5.out`** salvo una línea: la que añade este ensayo para decir qué guion se ejecutó (`ca03c1b5…`).
- **Regresión del lote 4** [C], de más: la rama del `case` que toca la enmienda es la vía `--rutas` + montaje en `app.php`. Con `f7f00fdc` + `72e76551`: 0 · 0 · 0, idéntica a `ensayo-lead-lote4.out` salvo esa misma línea.
- Secretos: el banco llevaba una clave de aplicación y un secreto interno **sintéticos**; 0 apariciones en la evidencia. La única copia de la clave fuera del fichero de entorno del banco es la caché de configuración de cada respaldo (600 en directorios 700), como en producción y como en el ensayo del lead.

## 5. Lo que NO pude verificar sin el servidor real
- **El `revalidate_freq` real del FPM**, y si hay un `php_admin_value[opcache.*]` en un pool o `opcache.preload`. La comprobación de OPcache del paso 0, anterior a la enmienda, solo lee líneas `opcache.*=` de `/etc/php/*/fpm/`. Si el FPM no revalida por otra vía, lo caza el marcador (N9) y vuelve atrás: el despliegue fallaría, pero nunca daría un falso verde.
- **La respuesta real por nginx y HTTP/2**: que hoy el 401 lleve `x-ratelimit-limit` (la evidencia `10-…` [3] dice que sí [L]) y que nginx no añada ni quite esas cabeceras después.
- **bash 5, glibc y mawk** con las piezas nuevas: las expresiones no llevan barras invertidas (regla m2) y el awk usa solo `substr`, `index`, `tolower` y una expresión sencilla. Con el marcador, el error de analizador posible no da falso verde (§2). En el `--solo-comprobar` tiene que salir `marcador sin credenciales: … → 401, x-ratelimit-limit presente (= el previo; 1 lectura(s))`.
- **El tráfico real de n8n** después: lo mira una persona (§5 ter, «Después»).
- `shellcheck`: no está instalado.

## 6. Acciones pendientes (no las he hecho)
1. **Commit de `cierre/ops-pt19`** (el índice tiene exactamente estas 5 rutas): `ops/deploy-cierre-lote.sh`, `ops/DEPLOY_CIERRE_LOTES.md`, `ops/cierre-lotes/pt-19/marcador.txt`, `ops/cierre-lotes/pt-19/lista-referencia.txt` y `ops/evidencia-h9/2026-11-22/11-ensayo-pt19-y-regresion-lote5.txt`.
2. **Revisión de la enmienda** (reviewer y security): el guion `7c7298d8…` estaba revisado; esta enmienda, no.
3. **Integrar en `main` `cierre/puertas-internas` y `cierre/ops-pt19`** y subir. Fuera de `docs/` tienen que salir solo los dos ficheros. Las dos ramas no chocan: desde `0a8d3c61` no tocan ningún fichero en común [C].
4. **Desplegar, con la orden del lead** (§5 ter). Desde el Mac, la preparación de la ficha. En el servidor, en `tmux new -s pt19`:
   ```bash
   H=d8e9cd270b928afe0e5495809c779c4cd0c753abd025f4919cc1c755c26cb710
   bash /root/deploy-cierre-lote.sh $P $N /root/cierre-pt19/lista.txt --bootstrap=$H --marcador=/root/cierre-pt19/marcador.txt --solo-comprobar
   bash /root/deploy-cierre-lote.sh $P $N /root/cierre-pt19/lista.txt --bootstrap=$H --marcador=/root/cierre-pt19/marcador.txt
   bash /root/deploy-cierre-lote.sh $P $N /root/cierre-pt19/lista.txt --vuelta-atras=/var/backups/ironbody/cierre-lote-<fecha UTC>   # solo si hace falta
   ```

## 7. Riesgos, deuda y lo adyacente (no arreglado)
- **`hotfix-backend.sh:77` sigue rechazando todo `backend/bootstrap/*`**, a propósito. PT-19 va solo por `deploy-cierre-lote.sh`.
- **Huella vieja en documentos del lead**: `MATRIZ_ACEPTACION_TECNICA.md:35` y `MATRIZ_CIERRE_V2.md` (PT-06) citan `7c7298d8…`. No los he tocado.
- **Ceguera previa de la comprobación de OPcache** (`php_admin_value` en un pool, `preload`): solo la cubre el marcador, y solo en los lotes que lo declaren.
- **El marcador gasta cupo con el previo**: 1 lectura en el paso 0, hasta unas 7 en el paso 4 si el FPM tarda, y 1 tras una vuelta, de los 120 por minuto del asesor. Es despreciable, pero real.
- **NB-2** (de seguridad, decisión del propietario): con PT-19 la aplicación ya no frena los intentos con un secreto equivocado; quedan nginx y el `access.log`.
- **Artefacto del banco**: el `sleep` de prueba (0,3 s) hace que el marcador se lea muy seguido (92–96 lecturas en 32 s en N8 y N9). En el servidor son cada 5 s. Por eso N8 usa un marcador sin limitador: uno sobre `admin/voice/status` (60 por minuto) habría agotado su cupo en el banco y falseado el caso.
- **Ningún secreto expuesto ni que rotar.** No he leído ninguna credencial real.
