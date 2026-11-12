# Informe de devops · los arneses con la apertura en la punta (H-1, I-2) y el camino después de la apertura (I-4)

Fecha: 12-11-2026 (Bogotá, noche del 11-11). Agente: devops.

Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código o en los documentos · **[S]** supuesto.

**Entorno: solo local. NO es producción.** No he hecho ssh al VPS, no he instalado nada en `/root`, no he hecho commits, push ni ramas en el repositorio, y no he tocado `backend/app/`, `backend/tests/` ni el CRM. El worktree de la apertura (`_worktrees/ultron-voz-abierta`, `prep/voice-calls-open-tests` = `216b0f1b`) lo he usado solo para leer y como origen de copias: sigue limpio y en el mismo commit [C].

## Resumen para el lead

1. **H-1 e I-2, hechos.** `ops/h8-e2e.sh` y `ops/v9-abierta.sh` aceptan la fuente en los dos estados de la constante: o una declaración `= false;` y ninguna `= true;`, o una `= true;` y ninguna `= false;`. Cualquier otra forma aborta con ruido. Ninguna comprobación se afloja; dos se endurecen (§1).
   - Con la apertura en la punta, `h8-e2e.sh` construye la copia **cerrada girando la línea al revés**. Así `H8E2ECerradaTest` vuelve a correr: **6/6, 113/113** con `216b0f1b` [C].
2. **Ensayados en los dos estados [C]:**
   - **`v9-abierta.sh`** (SQLite y PostgreSQL): `OK (26 tests, 224 assertions)` en cada uno. Cerrado, igual que el guion original.
   - **`h8-e2e.sh`, las tres fases:**
     - El hash de resultados es **idéntico** con el original y con el enmendado sobre el árbol cerrado (`345ae440…`), e idéntico también sobre el árbol abierto.
     - Con una fecha de las pruebas corregida en un clon del scratchpad: **0 fallos en los dos estados** (6/113, 9/301, 11/247) y el mismo hash (`cc9e4ef1…`).
   - **Los abortos ruidosos:** cuatro variantes malas, en los dos arneses.
3. **Hallazgo para tester (no es de la apertura):** las fases abiertas del E2E fallan desde el **10-11 15:00 UTC** por una bomba de tiempo.
   - `MARTES_EN_HORARIO = '2026-11-10T10:00:00-05:00'` ya es pasado. El worker con el reloj en ese martes no recoge un trabajo cuyo `available_at` es el ahora real [C].
   - El 15-11 y el 16-11 caducarán `DOMINGO` y `FESTIVO` [S].
   - Detalle en §2.4.
4. **I-4 confirmado y ensayado con git [C].** La parte de git de la 4a la extraje **literal** de `deploy-h8-backend-fase1.sh`, y `hotfix-backend.sh` lo ensayé en copias de banco, todo sobre la historia real con commits simulados (§3).
   - **(a) Reabrir.** Tras una vuelta atrás de nivel 4, la reapertura **aborta** en `:384-385`: hay dos `voice(calls-open):` en el tramo. El camino es este:
     1. tres reversiones en un push;
     2. `hotfix-backend.sh` lleva producción a la punta **cerrada**;
     3. un `voice(calls-open)` nuevo y otra 4a.
     
     Todo en verde. Escrito en el runbook, sección **E**.
   - **(b) Arreglos con la constante abierta.** `hotfix-backend.sh` sirve para ellos, pero **tenía un hueco**: desplegaba la apertura saltándose la 4a si alguien listaba `VoiceCallPolicy.php` [C, C4 y B3]. **Enmendado:** se niega a cualquier cambio neto de ese fichero salvo **cerrarla** (una línea, `true` → `false`), que es la vuelta atrás hacia delante cuando producción ya pasó de la apertura.
   - **(c) Precondiciones del tramo.** Hoy hay que desplegar antes **dos** pruebas, no una. `8c1f092e` es «docs/ops», pero añade `CommercialSuppression1581Test.php`. Se despliegan con `hotfix-backend.sh` **antes de subir la apertura**. Si no, la 4a aborta en `:402` [C].
5. **Correcciones a tu texto del paso 9:**
   - el tramo trae dos commits con pruebas;
   - en la segunda batería faltaba `tests/Unit/Twilio`, que lee el fixture que cambia `T`;
   - en el punto 4, el resto es correcto: el `cherry-pick` y el `range-diff` están ensayados sobre la punta real (§4).
6. **Guiones del banco.** Ninguno de los tres se reutilizará tal cual sobre una punta abierta. No los he tocado (§2.6). `telefonia-prep.sh`, además, ya no vale ni con la punta cerrada: compara con los mandos de HEAD y HEAD ya es la versión nueva.
7. **Guiones de `/root`:**
   - `smoke-run-h8.sh` no tiene fase para «constante abierta y salientes apagadas»;
   - `deploy-h9-backend-cierre.sh` y `deploy-m1-backend.sh` se niegan con la constante abierta.
   
   No están enmendados; propuestas en §5.
8. **Decisiones del propietario** (§7):
   - la autorización de los despliegues previos con `hotfix-backend.sh`;
   - si `AUT-VOZ-1` vale para una reapertura (yo pediría una nueva).

## 0. Entorno

- Mac, macOS 25.6, bash 3.2.57, PHP 8.3.33 y PostgreSQL 14.24 local en `127.0.0.1:55432` [C].
- **`shellcheck` no está instalado**: no lo he instalado sin permiso. El análisis estático es solo `bash -n`.
- Árbol de trabajo: `_worktrees/ultron-h8/Iron-Body` (`feat/ultron-studio-h9`, HEAD `4277f128` = `origin/main` local). Otro agente modifica a la vez `app/Console/Commands/ScanCommercialFacts.php` y `tests/Feature/Commercial/ScanCommercialFactsCeilingTest.php`; no son míos.
- **PHPUnit no corrió nunca sobre el árbol compartido.** `v9-abierta.sh` corrió en instantáneas limpias del scratchpad (`git archive` del commit + `vendor` clonado + `.env.testing`) y `h8-e2e.sh` en sus propias copias, con `H8_E2E_DIR` en el scratchpad, la base `ultron_studio_h8_e2e_devops` y `H8_E2E_EVIDENCIA=0`. **Nada escrito en `evidencia-h8/`.**
- «Árbol cerrado» = `4277f128`; «árbol abierto» = `216b0f1b` (`1ef1d72e` + `X'` + `T` + `T2`).
- Todo con `nice -n 10`, un ensayo cada vez.

## 1. Qué cambié

| Fichero | Líneas (después) | Qué y por qué |
|---|---|---|
| `ops/h8-e2e.sh` | 14-18 (cabecera) | Dice que la fuente se acepta en los dos estados y cómo se hace cada copia |
| | 194-232 | **H-1.** Cuenta en la fuente las dos declaraciones (`grep -cF`). Con `"1 0"`, la fuente está cerrada; con `"0 1"`, abierta; cualquier otra cosa → `muere` (es el `:191` de antes, generalizado). `gira()` gira la línea en el sentido que toque: `false` → `true` en abierta/salientes si la fuente está cerrada, y **`true` → `false` en la cerrada si la fuente está abierta**. `estado_ok()` exige después, en CADA copia, exactamente la declaración de su estado y ninguna del otro (**nuevo, más estricto** que el `grep -c … = 1` previo al giro). El bucle de `:234-240` (cada abierta difiere de la cerrada solo en `VoiceCallPolicy.php` y en 2 líneas de diff) **no cambia**: es simétrico |
| | 242-248, 562 | El mensaje y una línea nueva del `resumen.txt` («copias: …») dicen en qué estado venía la fuente y qué copia se giró. Ningún consumidor la parsea: `h8-e2e-crm.sh` solo lee «PILA EN PIE» (`h8-e2e-crm.sh:178`) [L] |
| `ops/v9-abierta.sh` | 9-10, 13-15 (cabecera) | Lo mismo en el texto |
| | 43-52 | **I-2.** Igual que arriba: `"1 0"` → gira la voz (`GIRA_VOZ=1`); `"0 1"` → no la gira; otra forma → `muere` |
| | 66-68, 74-81 | El giro de la voz, solo si toca. Los ficheros cambiados esperados son `PUERTA`+`POL`, o solo `PUERTA` si la voz ya venía abierta. Las líneas esperadas son `GIRA_VOZ + 2 - DECLARADA` (hoy, 2 cerrada o 1 abierta) |
| | 84-86 | **Más estricto:** antes bastaba que `= true;` apareciera (`grep -q`); ahora tiene que estar **exactamente una vez y ninguna `= false;`** |
| | 89-94 | El mensaje dice qué se giró |
| `ops/hotfix-backend.sh` | 4-7 (cabecera), 24-54 | **I-4 b.** `estado_vcp()` lee la constante en lo desplegado y en `origin/main` con las reglas de `deploy-h8-backend-fase1.sh` (declaración anclada y ninguna mención del otro valor). Si alguna está «incoherente» → aborta. Si `VoiceCallPolicy.php` está en el diff NETO, solo pasa si es **abierta → cerrada** y el cambio es exactamente la declaración `= true;` → `= false;` (numstat `1 1`, un trozo, misma sangría). Además, el fichero tiene que estar en la lista (el bucle de siempre). Cualquier otro cambio del fichero (abrir, tocar el comentario…) → aborta |
| | 60-64, 79 | Imprime el estado de la constante; con un cierre, avisa de que su «vuelta atrás» vuelve a ABRIRLA |
| `ops/RUNBOOK_PRIMERA_LLAMADA.md` | 183-200, 224, 226, 230-234 | Paso 9: las precondiciones con órdenes exactas y la corrección del tramo; `tests/Unit/Twilio`; los arneses ya enmendados; la vuelta atrás de nivel 4 con su límite; `hotfix-backend.sh` y el smoke con la constante abierta |
| | 322-418 | **Sección nueva E. «Después de la apertura»:** E.1 arreglo normal, E.2 cerrar (a/b), E.3 producción a la punta cerrada, E.4 reabrir, E.5 lo que queda |

Huellas tras el cambio [C]:

| Fichero | sha256 |
|---|---|
| `h8-e2e.sh` | `99ca61e4f1b8d7f8…` |
| `v9-abierta.sh` | `8ad49bfbd696d875…` |
| `hotfix-backend.sh` | `fc8ac2c83a5d6917…` (7 099 bytes; el de HEAD es `cb2b62608d6d0414…`) |
| `RUNBOOK_PRIMERA_LLAMADA.md` | `470ea68a056ebac2…` |

`git diff --stat`: 4 ficheros, +237 −25.

Análisis estático [C]:
- `bash -n` de los tres guiones: OK.
- `shellcheck`: no instalado.
- Con `pipefail`, evité el `printf | grep -q` en `hotfix-backend.sh`: un SIGPIPE daría un «no está» falso. Es la misma trampa que ya cita `deploy-h8-backend-fase1.sh:381`.

## 2. Los arneses en los dos estados (H-1, I-2)

### 2.1 El fallo, reproducido con los guiones de HEAD [C]

```
$ (instantánea abierta 216b0f1b) bash docs/ultron-studio/ops/v9-abierta.sh sqlite         → salida 2
v9-abierta: CALLS_ENABLED_IN_CODE no está en false exactamente una vez en app/Services/Voice/VoiceCallPolicy.php
$ H8_E2E_REF=216b0f1b … bash backend/docs/ultron-studio/ops/h8-e2e.sh todas                → salida 1
h8-e2e: la constante no está en false exactamente una vez en app/Services/Voice/VoiceCallPolicy.php
```

### 2.2 `v9-abierta.sh` enmendado [C]

| Árbol | Orden | Salida |
|---|---|---|
| cerrado `4277f128`, guion de HEAD (referencia) | `bash docs/ultron-studio/ops/v9-abierta.sh ambas` | «giradas …: CALLS_ENABLED_IN_CODE y SENDS_ENABLED_IN_CODE; la voz ya estaba declarada (L9-81)» · SQLite `OK (26 tests, 224 assertions)` · PG `OK (26 tests, 224 assertions)` · salida 0 |
| cerrado `4277f128`, **enmendado** | ídem | **idéntico** al de arriba · salida 0 |
| abierto `216b0f1b`, **enmendado** | ídem | «giradas …: SENDS_ENABLED_IN_CODE (CALLS_ENABLED_IN_CODE ya venía abierta en la fuente: voice(calls-open)); la voz ya estaba declarada (L9-81)» · SQLite `OK (26 tests, 224 assertions)` · PG (`ultron_studio_v9_abierta`) `OK (26 tests, 224 assertions)` · salida 0 |

### 2.3 `h8-e2e.sh` enmendado, tres fases [C]

Todas con `H8_E2E_EVIDENCIA=0`, `H8_E2E_DB=ultron_studio_h8_e2e_devops` y `H8_E2E_DIR` en el scratchpad.

| # | Fuente | Guion | Copias (línea del arnés) | cerrada | abierta | salientes | hash de resultados |
|---|---|---|---|---|---|---|---|
| E1 | `4277f128` (con la ventana por defecto) | enmendado | «la fuente trae la constante CERRADA · cerrada: el commit tal cual · abierta y salientes: … (false → true)» | 4/6 (`outside_publication_window`) | 2/9 (ídem) | 2/11 (ídem) | — (descartada) |
| E2 | `4277f128`, `H8_E2E_VENTANA=` | **HEAD** | «cerrada: el commit tal cual · abierta: lo mismo + el giro de UNA línea» | **6/6 · 113/113** | 7/9 · 265/269 | 4/11 · 122/134 | `345ae440bf3fe14b…` |
| E3 | `4277f128`, `H8_E2E_VENTANA=` | **enmendado** | «… fuente CERRADA …» | **6/6 · 113/113** | 7/9 · 265/269 | 4/11 · 122/134 | **`345ae440bf3fe14b…`** (= E2) |
| E4 | **`216b0f1b`**, `H8_E2E_VENTANA=` | enmendado | «la fuente trae la constante ABIERTA (voice(calls-open)) · abierta y salientes: el commit tal cual · cerrada: lo mismo + el giro de UNA línea al revés (true → false)» | **6/6 · 113/113** | 7/9 · 265/269 | 4/11 · 122/134 | **`345ae440bf3fe14b…`** (= E2) |
| E5 | `216b0f1b`, clon con la fecha corregida (§2.4) | enmendado | «… ABIERTA … true → false» | **6/6 · 113/113** | **9/9 · 301/301** | **11/11 · 247/247** | `cc9e4ef15be85462…` · 0 fallos · salida 0 |
| E6 | `4277f128`, mismo clon | enmendado | «… CERRADA … false → true» | **6/6 · 113/113** | **9/9 · 301/301** | **11/11 · 247/247** | **`cc9e4ef15be85462…`** (= E5) · 0 fallos · salida 0 |

- E1 falló solo por la hora: las 23:10 en Bogotá, fuera de la ventana de publicar por defecto (07:00-19:00, P-25). Es independiente del cambio. Fuera de horario hay que lanzarlo con `H8_E2E_VENTANA=`, como dice la cabecera del arnés.
- E2 = E3: con la fuente cerrada, el enmendado se comporta **exactamente** como hoy (el mismo hash de las 516 comprobaciones).
- E4 = E2: con la fuente abierta pasa lo mismo, y la copia cerrada se construye al revés. En E4, `giro-constante.diff` es `23c23 <  … = false; --- >  … = true;`, igual que en el cerrado.
- E5 = E6: sin la bomba de tiempo, todo en verde en los dos estados, con los mismos recuentos que la última evidencia verde (`evidencia-h8/2026-11-10/…b8d00f7f`: 6/113, 9/301, 11/247).
- Duración: unos 2-4 min por pasada.
- **`h8-e2e-crm.sh`** envuelve a `h8-e2e.sh` (`:175`) y hereda el cambio. No lo ejecuté.

### 2.4 La bomba de tiempo de las pruebas E2E (hallazgo para tester; `tests/` no es mío)

- **Qué:** `tests/Feature/Voice/E2E/H8E2EAbiertaTest.php:32` y `H8E2ESalientesPrimeroTest.php:48` fijan `MARTES_EN_HORARIO = '2026-11-10T10:00:00-05:00'`. El «worker de un trabajo» corre con el reloj desplazado a ese instante (`ops/h8-e2e/reloj.php:31`, `Carbon::setTestNow`). `DatabaseQueue::isAvailable()` exige `available_at <= Carbon::now()`: `vendor/laravel/framework/src/Illuminate/Queue/DatabaseQueue.php:373-378` y `InteractsWithTime::currentTime()` [L].
- **Por qué falla:** desde el 10-11 15:00 UTC, el trabajo despachado con el ahora real queda en el futuro para ese worker.
- **Comprobado [C]** en la base de la fase salientes de E4:
  ```
  jobs: voice-e2e | available_at 2026-11-12 04:14:44 | created_at 2026-11-12 04:14:44 | attempts 0 | reserved_at (nulo)
  ```
  La fila se queda en `requested` («PARADA: la saliente marcó: dialing… {"status":"requested",…}»). Con la fecha movida al martes 17-11 (solo en un clon del scratchpad), todo pasa (E5, E6).
- **Lo que viene [S]:** `DOMINGO` (15-11) y `FESTIVO` (16-11) caducarán igual.
- **Arreglo propuesto (tester):**
  - o fechas relativas al ahora (el próximo martes hábil a las 10:00, el próximo domingo y el próximo festivo del fixture de TEST8-a);
  - o despachar el trabajo de `voice-e2e` con su `available_at` en el instante del reloj desplazado.

### 2.5 Abortos ruidosos [C]

Cuatro variantes de la fuente. En cada una, una sustitución exacta en `VoiceCallPolicy.php` de la instantánea abierta. Pasan por los dos arneses: `v9-abierta.sh` dentro de la variante y `h8-e2e.sh` con `H8_E2E_FUENTE`.

| Variante | `= false;` / `= true;` | `v9-abierta.sh` | `h8-e2e.sh` |
|---|---|---|---|
| ninguna (`= TRUE;`) | 0 / 0 | salida 2 · «no está en un solo estado … (= false: 0, = true: 0 …)» | salida 1 · «la constante no está en un solo estado … («= false;»: 0, «= true;»: 0 …)» |
| dos abiertas | 0 / 2 | salida 2 · «(= false: 0, = true: 2 …)» | salida 1 · ídem |
| las dos | 1 / 1 | salida 2 · «(= false: 1, = true: 1 …)» | salida 1 · ídem |
| dos cerradas | 2 / 0 | salida 2 · «(= false: 2, = true: 0 …)» | salida 1 · ídem |

Las dos abortan antes de PHPUnit.

### 2.6 Los guiones del banco

| Guion | ¿Asume la constante cerrada? | ¿Se reutilizará sobre una punta abierta? | Qué hice |
|---|---|---|---|
| `banco-h8/ensayar.sh:349` (`constante abrir\|cerrar`) | Sí [L] | **No.** Solo lo usa `ensayar-ops8c.sh` (OPS8-c, histórico). Sobre una punta abierta, `abrir` no tiene sentido y aborta con ruido (`:349`); `cerrar` solo deshace el giro del propio banco y aborta ante cualquier otro (`:353`) | Nada |
| `banco-h8/ensayar-ops8e.sh:113` | Sí [L] | **No.** Encadenado al estado final de `ensayar-ops8d.sh` (OPS8-e del 10-11). Sobre una punta abierta aborta al empezar («la constante no está cerrada en la «producción» del banco») | Nada |
| `banco-h9/telefonia-prep.sh:298` (`voz`) | Sí [L] | **No tal cual**, y no por la constante: compara cada mando del árbol con el de HEAD (`:199`, `head_de`), y HEAD ya ES la versión nueva [C: las huellas de HEAD y del árbol casan: `231e4a8a…`, `cd7a5a7a…`, `be6c3c47…`]. Su lado «HEAD acepta 0.70» (`:316`) ya no se cumple ni con la punta cerrada [L+S]. El próximo ensayo de esos mandos necesita un banco rehecho | Nada. **Para el que se rehaga:** si la punta está abierta, la partida cerrada se simula con un commit del banco `true` → `false` en el clon (como la reversión del repo) y después `gira()` como hoy; al final, volver a la punta |
| `banco-h8/ensayar-ops8d.sh:555` (no estaba en la lista) | Sí: mira el fichero del **repo** («el repo sigue con la constante cerrada») [L] | Ya está obsoleto por `prepara_el_verificar` (informe C-4, §8.5) | Nada |
| `banco-h9/ola.sh:159-165` (no estaba en la lista) | Sí: gira las dos constantes [L] | No: ensaya los desplegadores de ola, que se niegan con la constante abierta | Nada. Sobre una punta abierta muere en el `commit` (no hay nada que confirmar; `set -e` de `comun.sh`) [L] |

## 3. I-4: el camino después de la apertura

### 3.1 Cómo lo ensayé

Todo en el scratchpad (`…/scratchpad/i4/`), solo git:
- **`w`**: el repositorio de trabajo, un clon `--shared` del almacén común. Los commits simulados (`X`, la documentación `D1`/`D2`, las reversiones, una corrección `F`) existen **solo ahí**.
- **`origen.git`**: el «origin» de la producción del banco. Es un repositorio desnudo que lee los objetos de `w` (alternates); su `main` se mueve con `update-ref`. **Sin `push`.**
- **`app`**: la «producción», un clon de `origen.git` en HEAD separado.
- **Tramos literales de `deploy-h8-backend-fase1.sh`** (`:190-194`, `:373-410`, `:428`, `:444-450`, `:451-463`, `:480-496`, `:505-522`). Cada tramo se extrae por líneas y se comprueba su primera y su última línea. Solo cambian `g()` (el clon del banco) y `aborta()`. **No** ensayé el resto de la 4a (fase 2, respaldo, configuración efectiva, esquema, rutas, workers): no depende de la forma de la historia.
- **`deploy-h9-backend-cierre.sh:463-474`**, literal: los commits por parche.
- **`hotfix-backend.sh` de HEAD y el enmendado**, en copias de banco con 4 sustituciones exactas (`APP`, sin root, `sleep 0` y la URL a `127.0.0.1:9`). Sustitutos de `supervisorctl` (14 RUNNING), `sudo` (no ejecuta nada), `curl` (401, sin red) y `stat -c`. Comprobé que cada copia es su original salvo esas 4 líneas [C].
- **Antes y después [C]:** `for-each-ref` del repositorio real idéntico (número y sha256 de todas las refs), y el worktree de la apertura limpio en `216b0f1b`.

### 3.2 Las comprobaciones que fijan el camino [L, y ejercitadas en 3.3]

| Guion:línea | Qué exige | Consecuencia |
|---|---|---|
| `deploy-h8-backend-fase1.sh:419-427` | 4a: `BASE` = `$BK/api-commit.txt`; producción en `BASE` o en `ESPERADO` (reanudación) | El respaldo se hace con producción ya en su sitio |
| `:428` | `BASE` contiene `PREVIO=a608450` | Siempre se cumple después del H8 |
| `:443`, `:444`, `:445` | checkout limpio · `origin/main` = `ESPERADO` · avance rápido desde `BASE` | — |
| `:375` | sin fusiones en `BASE..origin/main` | Las reversiones son commits normales: vale |
| `:377-378` | la punta empieza por `voice(calls-open): ` | `Revert "voice(calls-open): …"` no cuenta |
| `:379-383` | un padre; el mensaje cita `--autorizacion` | La reapertura cita su autorización |
| **`:384-385`** | **exactamente UN `voice(calls-open):`** en `BASE..origin/main` | **Con producción vuelta a `BK`, `X` y la nueva = 2 → aborta** |
| `:388-399` | el commit de la punta: un fichero, una línea, `= false;` → `= true;` | Un `X` nuevo igual que el primero |
| **`:400-402`** | `BASE..punta^` en conjunto: solo documentación | Las pruebas sin desplegar abortan aquí |
| **`:403-409`** | lo mismo, commit a commit; los de documentación sin aceptar van a `AJENOS` | Con `BASE` = `BK`, `X` y `T'` abortan aquí aunque el conjunto se anule |
| `:451-458` | ninguna migración en el tramo | — |
| `:480-482`, `:491-492` | cada `--acepta-main` tiene que estar entre los vistos; con documentación sin aceptar, la 4a aborta | La documentación nueva viaja con `--acepta-main` o se despliega antes |
| `:508-518` | constante cerrada en `BASE` (`:516`) y abierta en `origin/main` (`:517`) | — |
| `:519-521` | el modo normal exige la constante cerrada en `origin/main` | Con la apertura subida, el modo normal se niega. Además hoy ya aborta antes, en `:438-439`, porque producción no está en `PREVIO=a608450` |
| `:524-535` | `api-commit.txt` = `BASE`, volcado de < 2 h, entorno = respaldo | El respaldo, al final |
| `deploy-h9-backend-cierre.sh:463-474` (`:470`) y `deploy-m1-backend.sh:558` | un `voice(calls-open):` que producción no tiene (por parche) → aborta | Tras la vuelta a `BK`, la ola tampoco despliega |
| `deploy-h9-backend-cierre.sh:520-522`, `deploy-m1-backend.sh:609-611` | la constante exactamente cerrada en `origin/main` | Con la constante abierta, no despliegan |
| `h8-voice-scope.sh:220-230` | «abierta» solo si el último commit que tocó `VoiceCallPolicy.php` es `voice(calls-open):` | Tras E.2 b, cerrada (en ejecución `false`). Tras la reapertura, abierta |
| `hotfix-backend.sh:17`, `:20-22` | producción en `PREVIO`, `origin/main` = `ESPERADO`, avance rápido | Vale con HEAD separado (tras `BK`) |
| `hotfix-backend.sh:23` | **diff NETO** `HEAD..origin/main` | `X` y su reversión se anulan: llevar producción a la punta cerrada no toca `VoiceCallPolicy.php` |

### 3.3 Resultados [C]

Salida completa en `…/scratchpad/out/escenarios-i4.out`. Recorte:

**C · Precondiciones** (producción `9a6e3b1`, `origin/main` `4277f128`; `D1` = un commit de documentación; `X1` = la apertura simulada).

- Tramo `9a6e3b1..4277f128` fuera de la documentación: `backend/tests/Feature/Commercial/CommercialSuppression1581Test.php` y `…/ScanCommercialFactsCeilingTest.php`.
- **C1** · 4a con base `9a6e3b1` → salida 1: `ABORTA: entre 9a6e3b1 y el commit de la 4a hay cambios fuera de la documentación (…CommercialSuppression1581Test.php …ScanCommercialFactsCeilingTest.php)`.
- **C4 · el hueco.**
  - El `hotfix-backend.sh` de HEAD, `9a6e3b1 → X1` con la lista completa → **`CORRECCIÓN DESPLEGADA SIN DESVÍOS`**, producción en `X1`. **La apertura sale sin la 4a.**
  - El enmendado → salida 1: `ABORTA (no se ha tocado nada): la corrección cambia …VoiceCallPolicy.php (constante cerrada en lo desplegado y abierta en origin/main): abrir las llamadas es solo de deploy-h8-backend-fase1.sh --fase=4a…`.
- **C2** · enmendado, `9a6e3b1 → D1` (16 ficheros) → `constante de llamadas (VoiceCallPolicy): cerrada, sin cambios (el fichero no cambia)` · `CORRECCIÓN DESPLEGADA SIN DESVÍOS`.
- **C3** · 4a `X1`, base `D1` → `4a: … un solo padre · cita la autorización · diff: SOLO la constante … · 0 commit(s) de solo documentación` · `constante: cerrada en 952951d → abierta en cc86033` · **EN VERDE**.
- **C3b** · con base `4277f128`, `D1` viaja con la 4a:
  - sin `--acepta-main` → `ABORTA: commits de solo documentación … 952951d … --acepta-main=952951d`;
  - con él → **EN VERDE**.

**4a simulada y las pruebas.** `git cherry-pick 3287769b 216b0f1b` sobre `X1` (encima de `4277f128` + `D1`): limpio.
- `range-diff`: `1: 3287769b = 1: ddebd0e8` y `2: 216b0f1b = 2: a9687b5b`.
- Fuera de `backend/tests/`: nada.
- Último commit de `VoiceCallPolicy.php`: el `voice(calls-open)`.

**B · Arreglos con la constante abierta.**
- **B1** · `X1 → T2'` (29 ficheros de `tests/`) → `abierta, sin cambios` · `CORRECCIÓN DESPLEGADA SIN DESVÍOS`.
- **B2** · una corrección de `app/Jobs/Voice/PlaceOutboundVoiceCall.php` → `abierta, sin cambios` · `SIN DESVÍOS`.
- **B3** · un commit que solo cambia el comentario de `VoiceCallPolicy.php:10`:
  - HEAD lo **despliega** (`SIN DESVÍOS`), y `h8-voice-scope.sh` vería la constante «incoherente»;
  - el enmendado → salida 1: `ABORTA … (constante abierta en lo desplegado y abierta en origin/main) …`.

**A · Nivel 4 con producción en `X1`, y reapertura.**
- **A0** · checkout de `BK` (= `D1`). `git revert --no-edit T2' T' X1`: limpio, tres commits. El árbol de la punta queda idéntico al de `D1`.
- **A2** · reabrir (`X2`) con base `D1` → salida 1: **`ABORTA: hay 2 commits «voice(calls-open):» entre 952951d y origin/main: se admite exactamente uno`**.
- **A2b** · la ola del H9 con base `D1` → salida 1: `ABORTA: el commit cc86033 abre la constante de llamadas del H8 …`.
- **A3** · enmendado `D1 → D2` (punta cerrada; diff neto: 1 fichero de documentación) → `cerrada, sin cambios` · `SIN DESVÍOS`. Después, la ola del H9 con base `D2` → sin `voice(calls-open)` pendiente · salida 0.
- **A4** · 4a `X2`, base `D2` → **EN VERDE** (`cerrada en f240408 → abierta en 76920eb`).
- **A4b** · con base en las reversiones y `D2` viajando:
  - sin `--acepta-main` → aborta;
  - con él → EN VERDE.
- **A5** · `cherry-pick 3287769b 216b0f1b` sobre `X2`: limpio; `range-diff` «=» en los dos.

**A' · Producción ya pasada de `X1`** (la corrección `F` encima): cerrar hacia delante.
- Revert de `T2'`, `T'` y `X1` encima de `F`: limpio.
- **A'1** · sin `VoiceCallPolicy.php` en la lista → `ABORTA … no está en la lista permitida`.
- **A'2** · un «cierre» que además toca el comentario → `ABORTA … en algo más que cerrar la constante …`.
- **A'3** · con la lista completa → `constante de llamadas (VoiceCallPolicy): abierta → CERRADA …` · `OJO: esa vuelta atrás vuelve a ABRIR la constante …` · **`CORRECCIÓN DESPLEGADA SIN DESVÍOS`**.
- **A'4** · reabrir encima (base = la punta cerrada desplegada) → **EN VERDE**.

### 3.4 (a) El camino de la reapertura, por escrito

Está en el runbook, **E.2–E.4**. En resumen:
1. Nivel 0 (`salientes off`).
2. Nivel 4:
   - **(a)** con producción en `X`: el checkout de `BK`;
   - **(b)** si producción ya pasó de `X`: cerrar hacia delante con `hotfix-backend.sh`, con `VoiceCallPolicy.php` en la lista.
3. En el repo: `git revert --no-edit <T2'> <T'> <X>`, **un solo push**, con las baterías del árbol cerrado (los 21 ficheros sin los tres `*ClosedCopy*`, que añadió `T`).
4. **Solo en (a):** `hotfix-backend.sh` del commit de `BK` a la punta cerrada (diff neto: documentación). Sin esto, la reapertura aborta en `:384-385` y la ola, en `:470`.
5. Reabrir:
   - con una autorización escrita nueva;
   - respaldo nuevo;
   - un `voice(calls-open)` nuevo de una línea;
   - 4a con `--solo-comprobar` y sin él;
   - `cherry-pick 3287769b 216b0f1b` con su `range-diff` y sus baterías.

**Pushes en el repo:**
- uno para las tres reversiones;
- uno para el `voice(calls-open)` nuevo;
- uno para las pruebas.

Más los de documentación. **Guiones por paso:**

| Paso | Guion |
|---|---|
| Cerrar (a) | checkout a mano, como hoy |
| Cerrar (b) | `hotfix-backend.sh` |
| Punta cerrada | `hotfix-backend.sh` |
| Reabrir | `deploy-h8-backend-fase1.sh --fase=4a` |

**Ensayado [C]:** toda la lógica de git de los tres guiones, el `revert`, el `cherry-pick` y el `range-diff`.

**No ensayado:**
- el checkout de `BK` con las cachés y `queue:restart` en el servidor; esa vuelta atrás la ensayó OPS8-e en su banco, `ensayar-ops8d.sh:661` [L];
- el resto de la 4a, que no depende de la historia;
- nada en producción.

### 3.5 (b) `hotfix-backend.sh` con la constante abierta

**Sirve [C, B1/B2].** No lee la constante (antes) y solo hace `merge --ff-only`, `queue:restart` y sus tres lecturas.

**Qué comprueba [L]:**
- producción en `PREVIO` y limpia;
- `origin/main` = `ESPERADO` y avance rápido;
- cada fichero del diff, en la lista;
- ni `database/`, ni `routes/`, ni `config/`, ni `composer.*`, ni `bootstrap/`;
- después, workers, 401 y el recuento de `ERROR`.

**Qué no comprueba:**
- **Antes de hoy, la constante** (C4/B3: abría o tocaba `VoiceCallPolicy.php` si se listaba): **enmendado**.
- **`OutreachSendGate::SENDS_ENABLED_IN_CODE`:** el mismo hueco para `outreach(sends-open):`. No lo enmendé porque no me lo pidieron. Propuesta: el mismo `estado_…()` sobre `backend/app/Services/Outreach/Run/OutreachSendGate.php` con `SENDS_ENABLED_IN_CODE`, sin admitir el cierre o admitiéndolo igual.
- No hace respaldo: su vuelta atrás es el checkout.
- No mira las migraciones pendientes en la base (no migra).
- No recarga FPM: no hace falta, `validate_timestamps` está por defecto y revalida cada 2 s (L9-95) [L].
- `:66` (antes `:29`): con `pipefail`, si ningún `laravel*.log` tiene `production.ERROR`, `grep -c` sale con 1 y el guion muere en silencio tras el paso 0, antes del merge. Producción tiene 3 260 líneas, así que hoy no ocurre. Menor, sin tocar.

**Dónde vive:** se usa por tubería (`bash -s --`, su cabecera). La copia del repo es la que corre, si se toma del commit (`git show "$C:…" | ssh … "bash -s -- …"`). **[S]** No sé si hay una copia en `/root`. Si la hay, es la de HEAD (`cb2b6260…`), que deja abrir.

### 3.6 (c) Las precondiciones del día, exactas

**Hoy [C local; L la producción, por L9-115].**
- Producción `9a6e3b1`.
- `origin/main` `4277f128`: tres commits, `8c1f092e`, `d1d6e2b3` y `4277f128`.
- Fuera de la documentación, dos ficheros de `tests/Feature/Commercial/` (uno de `8c1f092e` y otro de `d1d6e2b3`).

**Orden:**
1. Antes de subir la apertura: `hotfix-backend.sh` de producción a la punta de `origin/main`, con la lista completa. Órdenes en el paso 9 del runbook.
2. Hasta la 4a, solo documentación en `main`.
3. El respaldo `BK`.
4. La apertura y su push.
5. La 4a con `--solo-comprobar` y sin él. Con `--acepta-main` para cada commit de documentación que haya entrado después del paso 1.

**Si no se hace:** C1. La 4a aborta en `:402` sin tocar nada, con `main` ya en rojo. La corrección puntual enmendada ya no puede llevar las pruebas (C4). La salida es revertir la apertura y seguir E.3 y E.4.

**Impacto del paso 1** (un despliegue a producción):
- no cambia el código que se ejecuta (solo pruebas y documentación);
- `queue:restart`: los 14 workers terminan su trabajo y se reinician;
- sin corte de HTTP.

**Vuelta atrás:** la que imprime.

## 4. Correcciones a lo que escribió el lead (paso 9)

1. **«con uno que toca `backend/tests/` (`d1d6e2b3`)»** → son **dos**. `8c1f092e` («docs/ops», L9-115) añade `tests/Feature/Commercial/CommercialSuppression1581Test.php` [C]. La 4a lo trata igual: aborta (C1).
2. **Punto 4, segunda batería:** faltaba `tests/Unit/Twilio`. `TwilioRequestSignatureTest` lee `integrations-cases.json`, que `T` regenera [C, `git grep`]. El §7 del tester sí la llevaba. Añadida.
3. **«Si `main` lleva pruebas o código sin desplegar, se despliega antes con `ops/hotfix-backend.sh`»: correcto**, con dos precisiones:
   - tiene que ser **antes de subir la apertura**: después, el diff trae la apertura y la corrección puntual se niega;
   - la lista tiene que ser la **completa**, porque `hotfix-backend.sh` aborta con cualquier fichero no listado, también la documentación.
4. **Punto 4: correcto** (`git fetch` + `rev-parse`, `cherry-pick`, `range-diff`, `git show --name-only … | grep -v '^backend/tests/'`). Las tres primeras órdenes de git están ensayadas sobre la punta real; la cuarta no imprime ninguna línea en blanco entre commits [C].

## 5. Guiones instalados en `/root` que habría que reinstalar algún día

Ninguno de estos lo he tocado.

| Guion | ¿Muere o falla con la constante abierta? | Cambio propuesto |
|---|---|---|
| `smoke-run-h8.sh` | **Sí, si se lanza con la constante abierta y las salientes apagadas:** `SMOKE_PHASE=1` exige `calls_in_code:false` (`:211`) y `4a` exige `outbound`, `publishing`, `twilio_voice` y `voice_monitor` en `true` (`:207`) [L] | Una fase más (p. ej. `SMOKE_PHASE=abierta`) que exija `calls_in_code: True`, `inbound: False` y `outbound: False`, sin fijar el resto. Se reinstala con su autorización |
| `deploy-h9-backend-cierre.sh` | Sí: `:520-522` exige la constante cerrada en `origin/main`; `:470` aborta ante un `voice(calls-open)` que producción no tiene (correcto) [L, `:470` ejercitado] | Para el desplegador de la siguiente ola: admitir «el mismo estado en `BASE` y en `origin/main`, y `VoiceCallPolicy.php` sin cambios en el tramo», y dejar `:470` como está. La apertura sigue siendo solo de la 4a |
| `deploy-m1-backend.sh` | Igual (`:609-611`, `:558`) [L] | Igual |
| `deploy-h8-backend-fase1.sh` | El modo 4a sirve para reabrir tras E.3 [C, tramos literales]. El modo normal ya no se puede usar (`PREVIO=a608450`, `:438-439`) | Ninguno |
| `h8-voice-scope.sh` | No: `constante()` trata los dos estados y la reapertura (`:220-230`) [L] | Ninguno |
| `h9-outreach-scope.sh` | No: la fase 6 EXIGE la constante abierta (`:614`) [L] | Ninguno |
| `el-verificar.sh` | No: solo la imprime (`:170`). Los comentarios de `:5` y `:136` («sigue en false») quedan falsos [L] | Solo el texto, cuando se reinstale por otro motivo |
| `h7-monitor-scope.sh` | No la lee [L] | Ninguno |
| `hotfix-backend.sh` | No vive en `/root` según su uso (`bash -s`) [L]. **[S]** Si hay copia, es la de HEAD | Usar siempre `git show "$C:…"` con `C` = el commit que confirme este trabajo |

## 6. Impacto y vuelta atrás de este trabajo

**Hoy, ninguno:**
- son guiones locales y un runbook;
- `hotfix-backend.sh` solo tiene efecto cuando se ejecute, con su orden;
- desde ese momento, se niega a desplegar la apertura o cualquier cambio de `VoiceCallPolicy.php` que no sea cerrarla;
- **irreversible:** nada.

**Vuelta atrás:** volver los cuatro ficheros a HEAD, con un commit nuevo, sin reescribir.

| Fichero | sha256 en HEAD |
|---|---|
| `h8-e2e.sh` | `eb882527…` |
| `v9-abierta.sh` | `31f2cfcf…` |
| `hotfix-backend.sh` | `cb2b6260…` |
| `RUNBOOK_PRIMERA_LLAMADA.md` | `e8b99059…` |

Copias en `…/scratchpad/orig/`.

## 7. Pendiente de autorización y decisiones del propietario

1. **El despliegue previo** (paso 9, precondiciones), con `hotfix-backend.sh` y la orden escrita del propietario. Comando exacto en el runbook:

   ```bash
   P=$(ssh ironbody-vps 'git -C /var/www/api rev-parse --short=7 HEAD'); E=$(git rev-parse --short=7 origin/main)
   git show "$C:backend/docs/ultron-studio/ops/hotfix-backend.sh" | ssh ironbody-vps "bash -s -- $P $E $(git diff --name-only $P $E | tr '\n' ' ')"
   ```

2. **¿Vale `AUT-VOZ-1` para una reapertura?** El guion acepta cualquier referencia que el commit cite. Yo pediría una nueva (`AUT-VOZ-1b`), porque A1 autoriza «un commit» y «su despliegue».
3. **Confirmar los cuatro ficheros** (lo hace el lead) antes de usar `hotfix-backend.sh` enmendado: el runbook lo toma de `$C`.
4. **Si existe `/root/hotfix-backend.sh`**, sustituirlo o borrarlo. Necesita autorización.

## 8. Riesgos residuales, hallazgos y deuda

1. **Bomba de tiempo del E2E** (§2.4, tester): hoy las fases abiertas fallan sin culpa del código; el 15-11 y el 16-11 caerán más.
2. **El hueco gemelo de `SENDS_ENABLED_IN_CODE`** en `hotfix-backend.sh` (§3.5): sin enmendar.
3. **`smoke-run-h8.sh` sin fase para la constante abierta y las salientes apagadas** (§5).
4. **La siguiente ola no podrá desplegar con los guiones actuales** mientras la constante esté abierta (§5).
5. **Textos de `tests/` que quedan inexactos con la fuente abierta** (tester):
   - `H8E2ECerradaTest.php:8` y `:30` («la copia del árbol es EXACTAMENTE el commit», «(el commit tal cual)»);
   - `H8E2EAbiertaTest.php:42` («solo en la del banco»);
   - `H8StackE2ETestCase.php:18`;
   - `H8E2ESalientesPrimeroTest.php:59` («el giro de una línea del banco»).
   
   Las aserciones son sobre el valor y siguen siendo correctas [C, E4].
6. **`h8-e2e.sh` fuera de 07:00-19:00 (Bogotá)** necesita `H8_E2E_VENTANA=`. Está documentado en su cabecera; lo recuerdo porque E1 cayó por eso.
7. **`hotfix-backend.sh:66`**: muerte silenciosa si no hay `production.ERROR` en los registros (§3.5). Menor.
8. **Deriva:** ninguna. No hice cambios en ningún entorno. **Secretos:** no leí ni imprimí ninguno; las piezas del E2E son sintéticas y su barrido pasó en todas las fases. Nada que rotar.

## 9. Lo que no pude comprobar

- **Producción:** su commit (`9a6e3b1`) lo tomo de L9-115 [L]. No hice ssh.
- **La 4a entera en un banco con PostgreSQL y simuladores** (`ensayar-ops8d.sh`): solo sus tramos de git, literales. El resto no depende de la historia [L].
- **`hotfix-backend.sh` en el servidor:** `queue:restart`, `supervisorctl`, `curl` contra la API real, bash 5 y GNU `stat`. En el banco son sustitutos; bash 3.2.
- **Si existe `/root/hotfix-backend.sh`** [S].
- **`h8-e2e-crm.sh`** con el arnés enmendado: no lo lancé.
- **`telefonia-prep.sh voz`:** su desfase con HEAD lo deduzco leyendo (§2.6). No lo ejecuté.
- **`shellcheck`:** no está instalado.

## 10. Restos

**En el scratchpad de la sesión** (`/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend/7d50fe75-5a88-47b3-bcc3-50ffb022999e/scratchpad/`):

| Ruta | Qué es |
|---|---|
| `orig/` | Copias de HEAD de los cuatro ficheros |
| `snap-cerrada/`, `snap-abierta/` | Las instantáneas |
| `clon/` | El clon con la fecha corregida |
| `h8e2e*/` | Las ejecuciones del E2E |
| `i4/` | El ensayo de git |
| `out/` | Todas las salidas |
| `tools/` | `instantaneas.sh`, `negativos.sh`, `prepara-i4.sh` y `escenarios-i4.sh` |

**En PostgreSQL local:** la base `ultron_studio_h8_e2e_devops` (desechable, se recrea en cada ejecución).

**En el repositorio:** solo los cuatro ficheros de §1 y este informe, sin confirmar.
