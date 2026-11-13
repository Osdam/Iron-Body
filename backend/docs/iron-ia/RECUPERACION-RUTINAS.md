# Iron IA — recuperación de rutinas del flujo antiguo

Aprobada por el propietario el 13-11-2026, con condiciones. El reporte de
impacto previo está en `RECUPERACION-RUTINAS-IMPACTO-2026-11-13.md`.

## El problema

Hasta la app 2.0.9, «Contexto para el plan» no llegaba al backend
(`CONTEXTO-DEL-ENTRENADOR.md`). Los días los elegía el modelo o salían de un
supuesto. El formulario nunca se guardó, así que **no hay forma de saber qué
disponibilidad pidió el entrenador**: ninguna rutina tiene 6 días confirmados.

## La decisión

Cada candidato recibe una **propuesta en borrador** de 6 días, marcada como «por
confirmar», que el entrenador revisa antes de publicar. **Nada se publica
solo.**

## Quién entra

`RoutineRecoveryCampaign::evaluate()` revisa todas las rutinas de Iron IA. Cada
una sale como `eligible` o con el motivo por el que no entra:

| Motivo | Qué significa |
|---|---|
| `not_from_integral_plan` | No viene de una corrida del plan integral |
| `corrected_generation` | Su corrida ya recibió el contexto (app 2.0.10) |
| `after_cutoff` | Generada después del corte (`--cutoff`, día de Bogotá) |
| `never_published` | Borrador que el socio nunca vio |
| `not_visible` | Ya no está visible (por ejemplo, retirada) |
| `superseded` | El socio tiene una rutina personalizada visible posterior |
| `corrected_draft_exists` | Hay una generación posterior que ya recibió el contexto |
| `member_missing` / `member_anonymized` | El socio ya no existe o está anonimizado |
| `membership_expired` | Membresía vencida (hoy en Bogotá) |
| `no_active_trainer` / `trainer_inactive` | Sin entrenador activo que pueda revisarla y publicarla |
| `already_six_days` | Ya tiene 6 días |
| `in_other_campaign` | Ya está en otra campaña: un nombre distinto o mal escrito no paga dos veces |
| `older_affected_routine` | El socio tiene otra rutina afectada más reciente: una propuesta por socio |

### Simulación previa sobre producción (13-11, antes del despliegue, solo lectura)

72 rutinas evaluadas, **29 candidatas**: 29 socios y 3 entrenadores (23, 3 y 3).

| Excluidas | Rutinas |
|---|---|
| `never_published` | 18 |
| `membership_expired` | 11 |
| `superseded` | 8 |
| `corrected_generation` | 3 |
| `trainer_inactive` | 2 |
| `no_active_trainer` | 1 |

El reporte de impacto contaba 31. Los 2 de diferencia son socios con una
asignación activa a un entrenador **desactivado**: el 9 desde el 30-09 y el 11
desde el 20-10. Esos entrenadores no pueden entrar a publicar. Si se les asigna
un entrenador activo, una nueva pasada de la campaña los incluye.

## Cómo se ejecuta (en el servidor, como `www-data`)

```bash
cd /var/www/api/backend
sudo -u www-data php artisan iron-ia:recover-routines recuperacion-2026-11              # simulación: no escribe nada
sudo -u www-data php artisan iron-ia:recover-routines recuperacion-2026-11 --execute    # propuestas en borrador
```

| Opción | Uso |
|---|---|
| `--cutoff=2026-11-13` | Último día (Bogotá) de generaciones que se tratan |
| `--limit=N` | Máximo de propuestas en esta ejecución (por tandas) |
| `--max-tokens=2000000` | Tope de tokens de **toda** la campaña; al llegar, se para |
| `--routine=ID` (repetible) | Solo esas rutinas originales |
| `--queue=COLA` | Mandar cada propuesta a la cola de un carril (`config/queue.php` → `lanes`; p. ej. `commercial`); cualquier otra se rechaza antes de tocar nada. Por defecto se generan en el mismo proceso, de una en una: producción no tiene worker para `default` |
| `--report=RUTA` | Informe JSON (solo ids). Por defecto, `storage/app/iron-ia/recovery/<campaña>/` |

**Volver a lanzarla es seguro:**

- hay una fila por (campaña, rutina original);
- lo hecho no se repite;
- lo fallido se reintenta hasta 3 veces;
- una ejecución cortada adopta el borrador que dejó, en vez de pagar otra
  generación;
- dos ejecuciones a la vez no pueden coexistir (cerrojo de campaña y reclamo
  atómico de cada fila).

## Qué crea la propuesta

- **Una rutina en borrador,** invisible para el socio: `is_assigned = false`, sin
  asignación y `status` «Borrador».
- **Con nombre marcado:** «Propuesta: 6 días por confirmar · …». El portal del
  entrenador muestra el nombre, no la descripción.
- **Asignada al entrenador activo del socio,** que es quien puede publicarla.
- **Generada con la potencia de rutina del plan del socio,** sin cambiarla. La
  nutrición se genera al nivel 1 y **no se guarda**: una guía con «6 días» de
  actividad haría pasar por real una disponibilidad sin confirmar.
- **Con el reparto muscular de 6 días** de `ORGANIZACION-MUSCULAR.md` y su
  comprobación posterior.
- **Su corrida tiene un modo propio,** `integral_plan_recovery`. No consume el
  limitador del entrenador ni el guardián diario del plan integral; la campaña
  tiene su propio tope de tokens.

**No escribe nada en el perfil, la meta ni la guía del socio.** Los 6 días son
una propuesta (`days_source: recovery_proposal`), no un dato del socio.

## Qué hace el entrenador

Desde el 13-11 (decisión del propietario: opción A) el backend **exige** la
revisión; el contrato está en `RECUPERACION-REVISION-CONTRATO.md`. En su portal
(Seguimiento → rutinas del socio, con el parche OTA de la 2.0.10), el entrenador
abre la propuesta, la revisa y, para publicarla, escribe lo que confirmó con el
socio: días reales, minutos por sesión, nivel, objetivo y lesiones. Además marca
dos casillas: «Confirmé la disponibilidad con el socio» y «Revisé el reparto
muscular, los ejercicios y la recuperación».

- **Si no cuadra con la rutina** (por ejemplo, 6 días en la propuesta y el socio
  puede 4), el backend NO publica (`recovery_days_mismatch`, `_level_`,
  `_minutes_`). Hay dos salidas:
  - quitar días en el editor de siempre (la frecuencia se recalcula);
  - **regenerar** con lo confirmado (`POST /trainer/routines/{id}/recovery/regenerate`).
    Hay hasta 3 regeneraciones, que no gastan sus cupos. La propuesta anterior queda
    retirada y marcada como sustituida, y ya no se publica por ninguna vía.
- **Si todo está bien,** la publica: se retira la original y la confirmación
  queda guardada en la fila.
- **Si no la quiere,** la retira con el botón de siempre; la fila queda
  `discarded`.

## Lo que bloqueaba la publicación (resuelto en el backend; falta el parche)

Lo encontró la revisión final del 13-11: la app 2.0.10 (`568cdc3`) no deja
publicar ni descartar un borrador. En el Seguimiento, toda rutina no publicada
salía como «Retirada» (`follow_up_sheet.dart:172`). Al abrir un borrador, el
editor dejaba el plan en `idle` (`integral_assessment_screen.dart:289`).

**Estado al 13-11:**

- **Backend:** confirmación obligatoria, regeneración, propuestas sustituidas y
  bloqueo de las otras vías. El CRM (`Crm/RoutineController::update/assign`) y
  `PATCH /api/routines/{id}/assign` rechazan una propuesta pendiente o sustituida
  con `recovery_publish_from_trainer`.
- **App:** parche OTA de la 2.0.10 (Seguimiento con «Borrador», revisión
  obligatoria, regenerar o ajustar). Solo llega a quien tenga la 2.0.10: los
  entrenadores y la tableta que sigan en la 2.0.9+30 verán el 422 con su mensaje
  si intentan publicar una propuesta.
- **La campaña sigue sin ejecutarse.** Se ejecutará cuando el flujo de revisión
  y publicación esté verificado de extremo a extremo y el propietario lo autorice.

## Al publicar (`RoutinePublisher::publish`)

En la misma transacción:

1. La propuesta pasa a vigente (`is_assigned`, asignación, «Activa»).
2. Pierde la marca «por confirmar».
3. La rutina original se retira con el mismo efecto que el botón «retirar»: deja
   de verse, pero la rutina, sus ejercicios, las sesiones (`workout_sessions`) y
   los completados (`routine_completions`) se quedan. También cuando la generó
   otro entrenador: la propuesta la sustituye por decisión aprobada de la
   campaña. Solo se toca si es del mismo socio.

**Concurrencia.** La fila de la campaña se bloquea: dos publicaciones a la vez
hacen una sola sustitución.

**Aviso al socio.** Después del commit, el aviso en tiempo real de siempre
(`RealtimeEvents::routine`, SSE), que refresca Mis Rutinas. No hay notificaciones
push.

**Rutinas posteriores.** Una nueva valoración y su rutina funcionan como
siempre: se generan, se publican y la de recuperación se retira con el botón de
siempre. Ningún estado lo impide.

## Estados de la fila (`iron_ia_recovery_items`)

`pending` → `generating` → `proposed` → `published` | `discarded`

`failed` se reintenta hasta 3 veces.

Para ver el avance:

```bash
sudo -u www-data php artisan tinker --execute="echo App\Models\IronIaRecoveryItem::where('campaign','recuperacion-2026-11')->selectRaw('status, count(*) n')->groupBy('status')->get()->toJson();"
```

## Pruebas

| Prueba | Resultado |
|---|---|
| `tests/Feature/Trainer/IronIaRoutineRecoveryTest.php` (SQLite y PostgreSQL 14) | 29 de 29 |
| `tests/Unit/RoutineMuscleOrganizerTest.php` | 8 de 8 |
| Batería de Iron IA | 499 en verde (17 omitidas: solo PostgreSQL o en vivo); batería amplia con rutinas, nutrición y pruebas unitarias: 8.015 en verde |
| PostgreSQL 14, con los planes integrales, las potencias y la paridad | 203 de 203 |
| Concurrencia real en PostgreSQL (`repro/concurrencia_recuperacion_pg.php`), con dos procesos | Correcta (ver abajo) |
| E2E con OpenAI real (`tests/Feature/Trainer/IronIaRecoveryLiveTest.php`, grupo `live`) | 5 de 5 (ver abajo) |

**Correcciones tras las revisiones de seguridad y final (13-11):**

- Las corridas de recuperación ya no cuentan en la IA de nutrición de los
  socios (`NutritionAiCostGuard::check`).
- El tope de tokens cuenta también los intentos fallidos y se comprueba dentro
  de cada propuesta, así que vale también con `--queue`.
- Una fila abandonada respeta el máximo de intentos.
- Otra campaña no vuelve a tratar la misma rutina.
- Publicar una propuesta descartada la sustituye igual.
- Al publicar se quita solo la marca: un nombre cambiado a mano se respeta.
- El socio ya no puede borrar por API una rutina de su entrenador
  (`AppRoutineController::destroy`, la misma regla que el listado `custom()`).
- El umbral muscular se puede ajustar sin desplegar
  (`IRON_AI_PLAN_MUSCLE_MIN_SHARE`; 0 lo desactiva).

**Qué cubre la batería de recuperación:**

- quién entra y quién no;
- varios programas del mismo socio;
- la propuesta invisible y marcada;
- una meta de 6 días que tampoco publica sola;
- un principiante;
- las lesiones;
- la potencia 3 con las potencias intactas;
- los reintentos y su tope;
- la doble ejecución;
- la ejecución cortada;
- la concurrencia;
- las tandas y el tope de tokens;
- publicar sin borrar historia, con su aviso SSE;
- ajustar a menos días;
- descartar;
- los cupos del entrenador;
- una nueva valoración que la sustituye;
- que la API no pueda colar `recovery_campaign`;
- los payloads de siempre para el portal y la app.

**Concurrencia real en PostgreSQL:**

- **Reclamo:** el segundo proceso espera 3 s y devuelve `not_claimed`, sin
  generar ni pagar.
- **Publicación:** espera 3 s y no hace una segunda sustitución.

**E2E con OpenAI real (13-11), cinco perfiles:**

- **Perfiles:** nivel 1 intermedio, nivel 1 principiante con la rodilla
  lesionada, nivel 2 avanzado, nivel 3 y el plan 5/3.
- **Resultado:** las cinco salieron al primer intento, en borrador y con 6 días
  Empuje / Tracción / Pierna A y B. Peor día entre 0,8 y 1,0.
- **La rodilla lesionada:** prensa, patadas de glúteo, abducción y peso muerto
  rumano con banda; sin zancadas ni impacto.
- **Coste:** 92.000 tokens las cinco, unos 0,02 USD por propuesta.

## Riesgos y pendientes

- **Siguen entrando generaciones defectuosas.** Desde el arreglo del backend, el
  entrenador 6 generó 4 planes sin contexto y el 7, uno. Usan la app 2.0.9+30,
  igual que la tableta. Se cierra cuando tengan la 2.0.10. Lo generado después
  del corte se puede tratar con otra campaña (`--cutoff` posterior).
- **Principiantes con 6 días propuestos:** el entrenador decide si los reduce.
- **Los 2 socios con entrenador desactivado** necesitan un entrenador activo.

## Vuelta atrás

- **Código:** `ops/deploy-recuperacion-rutinas.sh`, en su cabecera.
- **Propuestas sin publicar:** se retiran con el botón de siempre o con
  `RoutinePublisher::retire()`. No se borra nada.
- **Una propuesta ya publicada:** se retira la propuesta y se vuelve a publicar
  la original desde el portal.
