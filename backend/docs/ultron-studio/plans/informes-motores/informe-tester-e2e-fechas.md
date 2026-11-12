# Informe de tester · las fechas del E2E de la voz que caducaban

Fecha: 12-11-2026 (Bogotá, mañana). Agente: tester.

Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código o en los documentos · **[S]** supuesto.

**Entorno: solo local. NO es producción.** No hice commits, push, ramas ni ssh. En el árbol principal solo cambian los cuatro ficheros de `backend/tests/Feature/Voice/E2E/` y este informe. No toqué `backend/app/` ni `ops/`, y el worktree de la apertura lo usé solo para leer.

## Resumen para el lead

1. **Causa confirmada [C].** El ayudante despacha el trabajo `voice-e2e` con el reloj real (`available_at` = ahora). El worker de un trabajo corre con Carbon desplazado al `MARTES_EN_HORARIO` fijo (10-11-2026 10:00), que ya es pasado. `DatabaseQueue::isAvailable()` exige `available_at <= now` [L], así que el worker no ve el trabajo y la saliente se queda en `requested`. Reproducido hoy con `b90f84c4` sin tocar: abierta 3 fallos, salientes 12 (§1).
2. **Corrección: la (a), fechas relativas al ahora, solo en `tests/`.** Los tres instantes (martes, domingo, festivo) los calcula una función de la base del E2E con **el mismo oráculo que ya usan sus aserciones**, `calendarioLegal()` con el fixture de festivos de TEST8-a. Siempre a las 10:00 de Bogotá y al menos una hora por delante del ahora. Ninguna aserción cambia (§2, §3).
3. **Verde en los dos estados [C], con `todas` (las tres fases seguidas):**
   - fuente cerrada `b90f84c4`: cerrada **6/6 · 113/113**, abierta **9/9 · 300/300**, salientes **11/11 · 247/247**, 0 fallos, salida 0;
   - fuente abierta `33ba6299`: **los mismos recuentos**, 0 fallos, salida 0.
   
   Las 660 comprobaciones coinciden en nombre y resultado entre los dos estados, salvo una que ya existía y lleva en el nombre un recuento que depende de los tiempos (§4).
4. **No vuelve a caducar [C].**
   - Los 15 casos pedidos y algunos más (§5.1) salen bien con el calendario **del código**, que es lo que mira el job.
   - Barrido hora a hora del 12-11-2026 al 25-12-2028: **18 585 «ahoras», 0 incumplimientos**.
   - **Única caducidad que queda:** el fixture de festivos acaba en 2028. Desde el **25-12-2028 09:00 de Bogotá** la prueba falla con un mensaje que lo dice (§5.2).
5. **Las aserciones siguen mordiendo [C].** Un «festivo» que en realidad es domingo y un «martes» festivo hacen fallar exactamente la comprobación que toca (§6).
6. **Desplazamientos lejanos [C].** Con el martes a 19 días, el domingo a 10 y el festivo a 130 (los máximos posibles son 14, 7 y 70), todo en verde (§6).
7. **Batería normal sin cambios [C]:** `Tests: 334, Assertions: 21870, Skipped: 26` con el arreglo y sin él. Pint pasa (§7).
8. **Hallazgo para devops [C]:** `ops/h8-e2e.sh:244` y `:246` (`5d0a63c8`) matan el arnés en silencio cuando la ejecución no lleva la fase `salientes`. Afecta a `cerrada`, `abierta` y `ambas`, y **por eso también a `h8-e2e-crm.sh`**, que lanza una sola fase [L]. No lo toqué; la propuesta está en §8.
9. **Decisiones tuyas (§9):**
   - el hash de resultados ahora cambia cada semana, porque el instante va en el nombre de una comprobación;
   - quedan textos inexactos con la fuente abierta que no estaban en la lista de §8.5;
   - no hay una prueba permanente del cálculo de fechas, para no cambiar la batería.

## 0. Entorno y cómo trabajé

- Mac, PHP 8.3, PHPUnit 11.5.55 y PostgreSQL local en `127.0.0.1:55432`. Base del arnés: `ultron_studio_h8_e2e_tester`. Siempre con `H8_E2E_EVIDENCIA=0` (**nada escrito en `evidencia-h8/`**), `H8_E2E_VENTANA=` y `nice -n 10`, un arnés cada vez [C].
- **La copia** está en el scratchpad (`…/scratchpad/wt`). Es un `git clone --shared --no-checkout` del almacén común, con checkout de `b90f84c4` en HEAD separado, `vendor` clonado en APFS (`cp -c -R`) y `.env.testing` copiado [C].
  - No usé un `cp -c -R backend` sin más porque el arnés necesita un repositorio git:
    - hace `git rev-parse` y `git archive` del commit;
    - **toma las pruebas E2E del árbol de trabajo donde vive el guion** (`h8-e2e.sh`, `copia()`: `cp "$BE"/tests/Feature/Voice/E2E/*.php`) [L].
  - El aislamiento es el mismo: nada corrió sobre el árbol compartido. Es la misma técnica que el «clon» de devops [L].
- Antes de copiar, `git status --porcelain -- backend/tests/Feature/Voice` del árbol principal estaba vacío [C].
- **Estado abierto:** `H8_E2E_REF=33ba62993c1530a375fc472ae6779d2f6036ae6c` desde mi clon. El commit se ve por el almacén común.
  - El worktree de la apertura sigue en `33ba6299` y limpio (`status --porcelain`: 0 líneas, antes y después) [C].
  - Entre `b90f84c4` y `33ba6299`, `tests/Feature/Voice/E2E/`, `ops/h8-e2e/`, el fixture de festivos y `app/Services/Telephony/` son **idénticos**. Solo cambia `ops/h8-e2e.sh`, que en la rama de la apertura es el anterior al de devops [C, `git diff --stat`]. **En el estado abierto no hay que aplicar nada distinto.**
- **Parches de la apertura:** `ops/apertura-llamadas/000*.patch` no traen ningún `diff --git` en `tests/Feature/Voice/E2E/` (0 cabeceras). Solo lo mencionan en comentarios de otras pruebas [C, `grep`]. No chocan.
- **El HEAD del árbol principal avanzó durante el trabajo**, de `b90f84c4` a `240e2678`. Ese commit es solo documentación: 3 ficheros `.md` y nada de voz, telefonía, fixtures ni `ops/h8-e2e*` [C, `git diff --stat b90f84c4 HEAD -- …` vacío]. Los cuatro ficheros E2E del árbol eran idénticos a los de `b90f84c4` justo antes de copiar los míos [C, `cmp`].

## 1. La causa, reproducida con `b90f84c4` [C]

**Orden** (en el clon, las pruebas de `b90f84c4` tal cual, huella `86d342113573fce6`):

```
H8_E2E_DIR=…/runs/repro H8_E2E_DB=ultron_studio_h8_e2e_tester H8_E2E_EVIDENCIA=0 H8_E2E_VENTANA= nice -n 10 bash backend/docs/ultron-studio/ops/h8-e2e.sh todas
```

**Resultado** (16:04Z, salida 1, hash `ede5f6a5ac9a0522…`):

```
cerrada: pruebas 6 (6 OK, 0 FALLO, 0 OMITIDAS) · comprobaciones 113 (113 OK, 0 FALLO)
abierta: pruebas 9 (8 OK, 1 FALLO, 0 OMITIDAS) · comprobaciones 268 (265 OK, 3 FALLO)
salientes: pruebas 11 (4 OK, 7 FALLO, 0 OMITIDAS) · comprobaciones 134 (122 OK, 12 FALLO)
comprobaciones que fallaron: 15
```

- **Abierta**, `test_g`: `la saliente marcó: dialing …` → `{"status":"requested","provider_conversation_id":null,"provider_call_sid":null,…}`. El worker dice `h8-e2e reloj: desfase de -176718 s` (unos −2,05 días, al 10-11 10:00).
  - En el cierre falla además «ningún trabajo pendiente [1]» y «PHPUnit de la fase salió con 0 [código 1]».
- **Salientes**, `test_d`: `saliente 1: el job real marcó …` → `"status":"requested"`. Detrás caen en cascada `e`, `f`, `g`, `g2`, `h` y `z`, y el cierre.
- **La fila en la base**, después de la fase salientes:
  ```
   id |   queue   | attempts | reserved_at |  available_at_utc   |   created_at_utc
    1 | voice-e2e |        0 |             | 2026-11-12 16:06:33 | 2026-11-12 16:06:33
  voice_calls: 989e6dd3-… | requested | (sin motivo)
  ```
- **El mecanismo [L]:**
  - `ops/h8-e2e/reloj.php:29-31` fija `Carbon::setTestNow` en `H8_E2E_RELOJ_OBJETIVO` más lo transcurrido;
  - `ayudante.php despachar` (`:247`) corre con el reloj real;
  - `DatabaseQueue::isAvailable()` compara `available_at` con el `now()` de Carbon del worker (informe de devops §2.4).

## 2. La corrección elegida y por qué

**(a) Fechas relativas al ahora, calculadas en la prueba.** Mantiene el régimen de las ejecuciones verdes de siempre: el reloj del job por **delante** del real, de horas a días. Es la situación en que las fechas fijas estuvieron verdes del 08-11 al 10-11 y en los clones de devops E5/E6 [L, evidencia y §2.3].

**Por qué no (b), despachar con `available_at` en el instante desplazado:**
- Para hacerlo bien habría que tocar `ops/h8-e2e/ayudante.php`, que no es mío.
- Con fechas fijas en el pasado, el job correría con un reloj **anterior** a la creación de las filas, la publicación y el RNE. Es un régimen que ninguna ejecución verde ha probado: `ended_at` antes de `created_at`, sucesos fuera de la ventana de la evidencia, la marca del número «del futuro»… [S, no lo ensayé].
- Además, (b) dejaría el 2026 escrito a mano.

**Cómo elige cada instante:**
- La regla es la de cada paso, decidida por el **oráculo de la propia prueba** (`calendarioLegal()`: D8-15 más `tests/fixtures/voice/holidays-co-2026-2028.json`, el mismo fixture del que el código saca sus festivos en las pruebas de TEST8-a):
  - **martes:** martes, dentro del horario legal y no festivo;
  - **domingo:** domingo, fuera de horario y **no** festivo, para que el job cancele **solo** por `outside_hours`;
  - **festivo:** de lunes a sábado, dentro de la franja legal y festivo, para que cancele **solo** por `holiday`.
- Siempre a las **10:00 de Bogotá** y el primer día cuyas 10:00 caen **más de una hora** después del ahora. Así se cubre «es ese mismo martes pasada la hora» y «es martes a las 9:30».
- **Por qué una hora:** una fase dura minutos (19–127 s hoy). Con más de 5 min, el job nunca reutiliza la observación de deriva que la puerta deja en la caché de 5 min (D8-21), igual que en las ejecuciones verdes [L, `PlaceOutboundVoiceCall.php:187-198`].
- Se calcula **una vez por proceso**: todos los pasos de una fase usan los mismos instantes.
- Si el fixture no cubre el año al que hay que llegar, falla con `self::fail(…)` y lo dice. No inventa festivos.

**Lo que no cambia:**
- ninguna aserción, `calendarioLegal()` ni el número de pruebas o comprobaciones;
- el formato del instante: `DATE_ATOM`, igual que las constantes, p. ej. `2026-11-17T10:00:00-05:00`.

**Lo que sí cambia:** los nombres de tres `must()` (los textos de §8.5) y la fecha que va en el nombre de las tres cancelaciones del paso g de la abierta (§9).

## 3. Ficheros y líneas (árbol principal, sin confirmar)

`git diff --stat -- backend/tests/Feature/Voice/E2E` → **4 ficheros, +91 −24**. No hay nada más mío en el árbol [C].

| Fichero | Líneas (después) | Qué |
|---|---|---|
| `H8StackE2ETestCase.php` | 18-20 | Comentario (§8.5): qué copia es el commit tal cual y cuál lleva el giro, según el estado de la fuente |
| | 926-998 (nuevo) | `MARGEN_DEL_RELOJ = 3600` (`:933`), `$instantesDelReloj` (`:936`), `instanteDelReloj('martes'\|'domingo'\|'festivo')` (`:954`) e `instantesDelRelojDesde(\DateTimeInterface $ahora)` (`:969`, el cálculo sin reloj que usé para viajar en el tiempo) |
| `H8E2EAbiertaTest.php` | 30 (fuera 31-36) | Quitadas `MARTES_EN_HORARIO`, `DOMINGO` y `FESTIVO` |
| | 35 | Texto del `must` (§8.5): «(la fuente tal cual si viene abierta; girada a true si viene cerrada)» |
| | 393, 425 | `self::instanteDelReloj('martes')`, y `('domingo')`, `('festivo')`, `('martes')` en el bucle de las canceladas |
| `H8E2ESalientesPrimeroTest.php` | 47 (fuera 48-49) | Quitada `MARTES_EN_HORARIO` |
| | 57 | Texto del `must` (§8.5), igual que en la abierta |
| | 148, 308, 358, 527 | `self::instanteDelReloj('martes')` |
| `H8E2ECerradaTest.php` | 8-12 | Comentario (§8.5): con `false`; el commit tal cual si la fuente viene cerrada y con la línea girada a `false` si viene abierta |
| | 31 | Texto del `must` (§8.5): «(la fuente tal cual si viene cerrada; girada a false si viene abierta)» |

Huellas (sha256) de los cuatro ficheros, iguales en el clon, en `verif/` y en el árbol principal [C, `diff -rq`]:
- `H8E2EAbiertaTest.php` `b627b934…`
- `H8E2ECerradaTest.php` `28d751f3…`
- `H8E2ESalientesPrimeroTest.php` `1eab480e…`
- `H8StackE2ETestCase.php` `4cf75065…`

La huella que imprime el arnés (`cat *.php | sha256`, 16 cifras) es `5c1939e5fb77c6ee`, la de las pasadas verdes de §4.

## 4. El arnés con el arreglo, en los dos estados [C]

Las dos pasadas corren de día (16:13–16:22Z = 11:13–11:22 en Bogotá, jueves no festivo). La puerta del paso g toma la rama «dentro del horario legal».

**Fuente cerrada** (`b90f84c4` más mis pruebas):

```
H8_E2E_DIR=…/runs/cerrada H8_E2E_DB=ultron_studio_h8_e2e_tester H8_E2E_EVIDENCIA=0 H8_E2E_VENTANA= nice -n 10 bash backend/docs/ultron-studio/ops/h8-e2e.sh todas
```
```
copias: la fuente trae la constante CERRADA · cerrada: el commit tal cual · abierta y salientes: lo mismo + el giro de UNA línea (false → true)
cerrada: pruebas 6 (6 OK, 0 FALLO, 0 OMITIDAS) · comprobaciones 113 (113 OK, 0 FALLO) · 21 s
abierta: pruebas 9 (9 OK, 0 FALLO, 0 OMITIDAS) · comprobaciones 300 (300 OK, 0 FALLO) · 89 s
salientes: pruebas 11 (11 OK, 0 FALLO, 0 OMITIDAS) · comprobaciones 247 (247 OK, 0 FALLO) · 127 s
comprobaciones que fallaron: 0
hash de resultados (…): 31571b0b7276ff737f3617ae6a6d97c7ac3966d4ba4f158756f6e82086b34dea
salida=0
```

**Fuente abierta** (`33ba6299` más mis pruebas):

```
H8_E2E_REF=33ba62993c1530a375fc472ae6779d2f6036ae6c H8_E2E_DIR=…/runs/abierta … bash backend/docs/ultron-studio/ops/h8-e2e.sh todas
```
```
copias: la fuente trae la constante ABIERTA (voice(calls-open)) · abierta y salientes: el commit tal cual · cerrada: lo mismo + el giro de UNA línea al revés (true → false)
cerrada: pruebas 6 (6 OK, 0 FALLO, 0 OMITIDAS) · comprobaciones 113 (113 OK, 0 FALLO) · 28 s
abierta: pruebas 9 (9 OK, 0 FALLO, 0 OMITIDAS) · comprobaciones 300 (300 OK, 0 FALLO) · 75 s
salientes: pruebas 11 (11 OK, 0 FALLO, 0 OMITIDAS) · comprobaciones 247 (247 OK, 0 FALLO) · 82 s
comprobaciones que fallaron: 0
hash de resultados (…): c604e8ce5401d7d8f26b26ee2c27f9c687ce0632fa92d66ce8bd66f920788fb7
salida=0
```

**Instantes usados hoy:**
- domingo `2026-11-15T10:00:00-05:00`, festivo `2026-11-16T10:00:00-05:00` y martes `2026-11-17T10:00:00-05:00`. Van en los nombres de las tres cancelaciones del paso g.
- Desfase del worker: `427397 s` (unos 4,95 días).
- Son **los mismos instantes** con que devops pasó E5 y E6 de noche (rama «fuera de horario»). El cálculo da esos tres desde su hora, el 11-11 a las 23:14 de Bogotá [C, §5.1 con el 12-11; L, su §2.3].

**Por qué los dos hashes difieren [C, comparación de los `checks-*.jsonl`].** La **única** diferencia es `abierta | esperas por los limitadores reales (429 con Retry-After, un reintento cada una): 0` frente a `…: 1`. Esa comprobación ya existía (`H8E2EAbiertaTest.php:527`, `check(…, true, …)`) y lleva en el nombre cuántas veces el limitador hizo esperar, algo que depende de los tiempos de la ejecución. Las otras 659 coinciden en nombre y resultado.

**Abierta, 300 y no 301 [C, comparado con `evidencia-h8/2026-11-10/…b8d00f7f`, nocturna]:**
- Todos los pasos dan el mismo recuento salvo `test_g`: 50 hoy frente a 51.
- La diferencia es la rama de la puerta: de día salen 5 comprobaciones («dentro»: 202, la fila SQL, `duplicate`, `dialing` y el cierre limpio); de noche, 6 («fuera»: 409, sin fila ni petición, la costura, `despachar`, `canceled`).
- El resto de nombres distintos son el texto de §8.5 y la fecha del `excluded` (10-11 → 17-11).

## 5. Que no vuelve a caducar: viajar en el tiempo [C]

**Cómo:** con un guion del scratchpad (`tools/viaje.php`) sobre una copia con los ficheros finales (`verif/`).
- Llama por reflexión a `instantesDelRelojDesde($ahora)`, la función de las pruebas, con un «ahora» simulado.
- Cada instante se comprueba con el **calendario del código**, no con el oráculo de la prueba: `OutboundSchedule::evaluate($t, null)`, que es lo que hace el job con el entorno del E2E (`VOICE_OUTBOUND_HOURS_*` vacías, `entorno-banco.ejemplo:136-137`), junto con `ColombianHolidays`.
- Para cada instante se exige:
  - que sean las 10:00 de Bogotá (`-05:00`);
  - que quede más de una hora por delante;
  - que cumpla su regla;
  - que sea **el primero** que la cumple.

### 5.1 Los casos pedidos y algunos más

`php tools/viaje.php …/verif/backend casos`:

```
hoy (jueves 12-11, 11:00)                                         → martes 2026-11-17 (+4.96 d) · domingo 2026-11-15 (+2.96 d) · festivo 2026-11-16 (+3.96 d) · OK
jueves 13-11 (viernes, 12:00)                                     → martes 2026-11-17 (+3.92 d) · domingo 2026-11-15 (+1.92 d) · festivo 2026-11-16 (+2.92 d) · OK
domingo 15-11, 10:00                                              → martes 2026-11-17 (+2.00 d) · domingo 2026-11-22 (+7.00 d) · festivo 2026-11-16 (+1.00 d) · OK
lunes festivo 16-11, 10:00                                        → martes 2026-11-17 (+1.00 d) · domingo 2026-11-22 (+6.00 d) · festivo 2026-12-08 (+22.00 d) · OK
martes 17-11, 08:59:59 (antes de las 10:00, a más de una hora)    → martes 2026-11-17 (+0.04 d) · domingo 2026-11-22 · festivo 2026-12-08 · OK
martes 17-11, 09:00:00 (a una hora justa: no vale)                → martes 2026-11-24 (+7.04 d) · domingo 2026-11-22 · festivo 2026-12-08 · OK
martes 17-11, 09:30 (antes de las 10:00, a menos de una hora)     → martes 2026-11-24 (+7.02 d) · … · OK
martes 17-11, 10:30 (pasada la hora)                              → martes 2026-11-24 (+6.98 d) · … · OK
martes 17-11, 23:59:59                                            → martes 2026-11-24 (+6.42 d) · … · OK
jueves 03-12 (el martes 08-12 es festivo)                         → martes 2026-12-15 (+11.92 d) · domingo 2026-12-06 · festivo 2026-12-08 · OK
jueves 24-12 (el viernes 25-12 es festivo)                        → martes 2026-12-29 · domingo 2026-12-27 · festivo 2026-12-25 (+0.92 d) · OK
viernes 01-01-2027, 10:00 (año nuevo, festivo)                    → martes 2027-01-05 (+4.00 d) · domingo 2027-01-03 (+2.00 d) · festivo 2027-01-11 (+10.00 d) · OK
el ahora en UTC (01-01-2027 03:00Z = 31-12 22:00 en Bogotá)       → martes 2027-01-05 · domingo 2027-01-03 · festivo 2027-01-01 (+0.50 d) · OK
último día con los tres en el fixture: lunes 25-12-2028, 08:59:59 → martes 2028-12-26 · domingo 2028-12-31 · festivo 2028-12-25 (+0.04 d) · OK
ya sin festivo en el fixture: lunes 25-12-2028, 09:00:00          → FALLA (AssertionFailedError): E2E del H8: el fixture de festivos (tests/fixtures/voice/holidays-co-2026-2028.json) no cubre 2029 y sin él no se puede elegir el instante del reloj del job (festivo). Hay que ampliar el fixture (D8-15).
```

(«OK» = «OK con el calendario del código»; la salida completa da las fechas con hora y zona.)

### 5.2 Barrido

`php tools/viaje.php …/verif/backend barrido 60` (2 min 07 s):

```
barrido cada 60 min: 18585 «ahoras» comprobados, 0 con algún incumplimiento
  el martes más lejano: 14.04 días (2026-12-01T09:00:00-05:00 → 2026-12-15T10:00:00-05:00)
  el domingo más lejano: 7.04 días (2026-11-15T09:00:00-05:00 → 2026-11-22T10:00:00-05:00)
  el festivo más lejano: 70.04 días (2027-01-11T09:00:00-05:00 → 2027-03-22T10:00:00-05:00)
  primer «ahora» que falla: 2028-12-25T09:00:00-05:00 · E2E del H8: el fixture de festivos … no cubre 2029 … Hay que ampliar el fixture (D8-15).
```

- **El martes nunca queda a más de 14 días.** El paso 4b (`test_f` de salientes) registra el RNE con el reloj real y el entorno del E2E le da 30 días de validez (`entorno-banco.ejemplo:119`). Con 14 días el RNE del job no caduca [L]. Aunque caducara, el orden de `OutboundPolicy::REASONS` pone `no_voice_consent` antes que `rne_check_missing` [L].
- **El festivo puede quedar a 70 días.** Lo ejercité con 130 (§6, R1).

## 6. Que las aserciones siguen mordiendo, y los desplazamientos lejanos [C]

Las dos pasadas usan variantes **solo del scratchpad**, sobre la fuente cerrada y con `todas`. Al acabar se devolvió el fichero bueno al clon (`diff -rq` limpio).

**Mutaciones (`mut/`, huella `d692374662efdadd`):**
- **M1** (abierta): el «festivo» pasa a ser un domingo no festivo.
- **M2** (salientes): el «martes» pasa a ser un martes festivo.

```
cerrada: pruebas 6 (6 OK, 0 FALLO, 0 OMITIDAS) · comprobaciones 113 (113 OK, 0 FALLO)
abierta: pruebas 9 (8 OK, 1 FALLO, 0 OMITIDAS) · comprobaciones 300 (298 OK, 2 FALLO)
salientes: pruebas 11 (4 OK, 7 FALLO, 0 OMITIDAS) · comprobaciones 140 (128 OK, 12 FALLO)
salida=1
```

- **M1:** falla exactamente `job a las 2026-11-15T10:00:00-05:00: cancelada con holiday, …` → `{"status":"canceled","policy_reason":"outside_hours",…}`, más el «PHPUnit salió con 0» del cierre.
- **M2:** falla el `must` `saliente 1: el job real marcó …` → `canceled`. En la base, `policy_reason = holiday` el 08-12-2026 (Inmaculada Concepción, martes), con desfase de `2241291 s`.

**Régimen R1 (`regimen/`, huella `f29e2d132e9f9aca`):** martes `2026-12-01`, domingo `2026-11-22` y festivo `2027-03-22`. Antes comprobé que los tres cumplen el calendario del código.

```
cerrada: … 113 (113 OK, 0 FALLO) · abierta: … 300 (300 OK, 0 FALLO) · salientes: … 247 (247 OK, 0 FALLO)
comprobaciones que fallaron: 0 · salida=0
```

Desfases: `1636269–1636385 s` (18,9 días), `858777 s` (9,9 días) y `11226770 s` (129,9 días). Cubren los máximos del barrido.

## 7. Pint y la batería normal [C]

- **Pint** sobre los cuatro ficheros, en el clon y en el árbol principal (`vendor/bin/pint --test tests/Feature/Voice/E2E/{H8StackE2ETestCase,H8E2EAbiertaTest,H8E2ECerradaTest,H8E2ESalientesPrimeroTest}.php`) → `{"tool":"pint","result":"passed"}`.
- **`php -l`**: sin errores.
- **Batería** (`nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/Voice tests/Unit/Voice`):
  - en el clon con el arreglo:
    ```
    OK, but some tests were skipped!
    Tests: 334, Assertions: 21870, Skipped: 26.     (salida 0)
    ```
  - en una copia con los cuatro ficheros originales de `b90f84c4` (línea base): **idéntico**, `Tests: 334, Assertions: 21870, Skipped: 26.` (salida 0).
  
  Las 26 omitidas son las 6 + 9 + 11 pruebas del E2E sin el arnés.

## 8. Para devops (`ops/` no es mío; no lo he tocado)

### 8.1 `h8-e2e.sh` muere en silencio sin la fase salientes [C]

- **Dónde:** `ops/h8-e2e.sh:244` (fuente cerrada) y `:246` (fuente abierta), del commit `5d0a63c8`:
  ```bash
  COPIAS="… · abierta$([ -d "$RUN/salientes/backend" ] && echo ' y salientes'): …"
  ```
- **Por qué:** sin la copia `salientes`, la sustitución sale con 1. Una asignación toma el estado de su última sustitución y, con `set -e`, el guion termina con salida 1 **sin ningún mensaje** después de `== 1. copias de …`.
- **Comprobado:** con `bash -x … h8-e2e.sh abierta`, la traza acaba en `+ COPIAS='la fuente trae la constante CERRADA · …'` seguido de `+ rc=1` (`out/00-dbg.out`). La primera reproducción con `abierta` salió igual (`out/01-repro-abierta-b90f84c4.out`: `salida=1` tras «copias»).
- **A qué afecta:**
  - `cerrada`, `abierta` y `ambas` con cualquier fuente [C para `abierta`; L para el resto, mismo camino];
  - **`h8-e2e-crm/h8-e2e-crm.sh:175`**, que lanza `h8-e2e.sh "$FASE"` con una sola fase (`abierta` o `cerrada`, `:56`). Se quedaría esperando un «PILA EN PIE» que no llega [L, no lo ejecuté].
  - Solo `salientes` y `todas` funcionan; por eso todas mis pasadas son `todas`.
- **Propuesta:** `$( [ -d "$RUN/salientes/backend" ] && echo ' y salientes' || true )` en las dos líneas, o un `if` que fije una variable antes. Las demás `$([ … ] && …)` del guion (`:500-523`, `:559`) son argumentos de una orden (`caso`, `echo`), cuyo estado no depende de ellas: no les pasa [L].

### 8.2 Nada más

Para las fechas no hace falta tocar `reloj.php`, `ayudante.php` ni el arnés.

## 9. Lo que queda abierto, lo que no cubrí y lo que decides tú

1. **El fixture de festivos caduca:** desde el **25-12-2028 09:00 de Bogotá**, el E2E falla con un mensaje que lo dice (§5.2). Hay que ampliar `holidays-co-2026-2028.json` antes; lo aprueba el propietario (D8-15). Es una caducidad ruidosa, no silenciosa.
2. **El hash de resultados ya no es comparable entre semanas.** Las cancelaciones del paso g de la abierta llevan el instante en el nombre (`job a las {instante}: …`), como antes, y ahora el instante cambia con la semana. A eso se suman dos cosas que ya existían:
   - la comprobación `esperas por los limitadores reales …: N` (`H8E2EAbiertaTest.php:527`, `H8E2ESalientesPrimeroTest.php:513`), cuyo recuento depende de los tiempos (es la que separa los dos hashes de hoy);
   - la rama de la puerta, distinta de día y de noche.
   
   Si queréis un hash estable, propongo sacar esos valores del **nombre** al **detalle**, que no entra en el hash: es un cambio de dos líneas. No lo hice porque cambia nombres que no estaban en el encargo.
3. **Textos inexactos con la fuente abierta que no estaban en la lista de §8.5** (no los toqué, por alcance):
   - el docblock de `H8E2EAbiertaTest.php:8-9` («la COPIA DEL BANCO … el giro de una línea … nunca en el árbol compartido, protección 2»);
   - el de `H8E2ESalientesPrimeroTest.php:10-11` («el mismo giro de UNA línea que la abierta»);
   - el **nombre** del método `H8E2ECerradaTest::test_0_la_copia_es_el_commit_y_…` (`:29`). Cambiarlo cambia el `step` del hash y los nombres JUnit.
   
   Los dos comentarios son de una línea cada uno, si los quieres.
4. **No hay una prueba permanente del cálculo de fechas.** La demostración de §5 es un guion del scratchpad. Una prueba en la batería cambiaría el recuento de 334/26, que pediste que no cambie. Si la quieres, propongo una prueba de Feature (necesita `base_path()`) con los casos de §5.1.
5. **La rama «fuera del horario» del paso g no la ejercité con el arreglo:** todas mis pasadas fueron de día. Esa rama usa el reloj real, no los instantes. Las pasadas nocturnas E5/E6 de devops corrieron con **los mismos tres instantes** que da el cálculo [L, su §2.3; C, que el cálculo da esos instantes]. [S] Con el arreglo seguirá en verde de noche.
6. **`h8-e2e-crm.sh`:** no lo ejecuté. Además del §8.1, hereda las pruebas E2E cambiadas.
7. **El sábado** como día del festivo (p. ej. 25-12-2027) sale del barrido con el calendario del código [C], pero ninguna pasada del arnés lo usó.

## 10. Restos

**En el scratchpad** (`/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend/09063364-0207-45a9-9901-7df784fe9b4f/scratchpad/`):

| Ruta | Qué es |
|---|---|
| `wt/` | El clon (`b90f84c4` más los cuatro ficheros) |
| `verif/`, `base/` | Copias para el viaje en el tiempo y la línea base de la batería |
| `orig/`, `edit/` | Los originales y los finales |
| `mut/`, `regimen/` | Las variantes de §6 |
| `tools/viaje.php` | El guion de §5 |
| `runs/` | Las ejecuciones del arnés |
| `out/` | Todas las salidas, numeradas 00–10 |

**En PostgreSQL local:** la base desechable `ultron_studio_h8_e2e_tester`. El arnés la borra y la recrea en cada pasada.

**Puertos:** los cinco del arnés (8401–8405) quedaron libres [C].
