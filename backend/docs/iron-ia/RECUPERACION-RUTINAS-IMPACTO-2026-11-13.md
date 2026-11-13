# Recuperación de rutinas de Iron IA — reporte de impacto (13-11-2026)

**Producción:** `cd5dce0e`.
**Medición:** sonda de solo lectura `repro/probe_recuperacion_ro.php`, con
sesión `READ ONLY` y rollback. Solo se miden agregados; no se lee ningún dato
personal.
**Corte:** generaciones hasta el 13-11-2026, hora de Bogotá.

## Cómo se identifica una rutina afectada

No se usa la fecha, sino la evidencia que guarda la corrida. Cada rutina de IA
se une a su corrida de `nutrition_ai_runs` (mismo socio, creada durante la
corrida):

- Si la corrida **no** tiene `trainer_context`, salió del flujo antiguo: la app
  envió `{}` y lo que escribió el entrenador se perdió.
- Si lo tiene, ya es una corrida corregida.

Todas las rutinas de IA tienen corrida: ninguna salió de otro generador.

## Las 10 cifras pedidas

| # | Cifra | Valor |
|---|---|---|
| 1 | Rutinas históricas | 128 personalizadas, **72 de Iron IA** (desde el 10-09) |
| 2 | Potencialmente afectadas (flujo antiguo) | **69** (las otras 3 ya son corregidas) |
| 3 | Socios únicos afectados | **51** |
| 4 | Vigentes (visibles para el socio) | **51**; de ellas, 43 sin otra rutina posterior visible |
| 5 | Ya sustituidas | 8 visibles con una rutina posterior visible; además, 18 borradores que el socio nunca vio (10 con posterior). 0 retiradas |
| 6 | Con disponibilidad confirmada de 6 días | **0** |
| 7 | Sin disponibilidad verificable | **51 de 51** socios |
| 8 | Con lesiones o limitaciones | 3 socios con lesiones registradas; 16 rutinas con limitaciones anotadas por el análisis de la IA |
| 9 | Que ya tienen 6 días | **0** |
| 10 | Coste estimado (`gpt-4.1-mini`) | ≈ 0,8 USD por los 31 candidatos; techo ≈ 2 USD con reintentos |

**Por qué el punto 6 es 0.** La única fuente guardada de disponibilidad es
`nutrition_goals.training_days_per_week`. Ninguno de los 51 socios la tiene:
los 5 con meta la tienen calculada pero sin días. Ninguna nota de valoración
menciona «6 días». Ninguna rutina afectada tiene 6 días. El formulario
antiguo nunca guardó lo que escribía el entrenador.

**De dónde salieron los días de las 69 rutinas:**

- **57:** los eligió el modelo del nivel 1, con la consigna «entre 2 y 6».
- **12:** salieron del supuesto del brief por nivel. El análisis mostraba al
  entrenador «No consta la disponibilidad semanal: se asumen N días».

Reparto resultante: 1 día (2 rutinas), 2 días (2), 3 días (29), 4 días (36).

**El defecto sigue activo.** Hay 5 rutinas defectuosas generadas **después**
del arreglo del backend (12-11, 12:15 UTC), la última hoy a las 15:59 UTC: los
entrenadores con apps antiguas siguen enviando `{}`. Ya hay 3 rutinas
corregidas (2 socios), desde el 12-11 a las 13:12 UTC.

## Embudo hasta los candidatos

| Paso | Rutinas |
|---|---|
| Afectadas | 69 |
| Visibles para el socio | 51 |
| Sin otra rutina posterior visible | 43 |
| Con membresía vigente | 32 |
| Con entrenador activo asignado | **31** |

Esto da **31 socios** con una rutina cada uno, revisados por **4 entrenadores**.
En 1 caso el entrenador activo no es el que generó la rutina. 1 candidato
tiene lesiones registradas.

- **Días de los 31 candidatos:** 2 días (1), 3 días (19), 4 días (11).
- **Potencia actual de sus planes:** nivel 1 (11 socios), nivel 2 (3),
  nivel 3 (1) y rutina 3 / nutrición 5 (16).

**Rutinas excluidas y motivo:**

| Rutinas | Motivo |
|---|---|
| 8 | Ya tienen una rutina posterior visible |
| 18 | Borradores que el socio nunca vio |
| 11 | Visibles, pero con la membresía vencida |
| 1 | Visible, pero sin entrenador activo |

**Historial que no se puede tocar.** 3 rutinas afectadas tienen
entrenamientos registrados: 5 sesiones (`workout_sessions`) y 5 completados
(`routine_completions`).

## Reparto muscular con 6 días (E2E con OpenAI real del 12-11)

- **Niveles 2–5:** Empuje A, Tracción A, Pierna A, Empuje B, Tracción B y
  Pierna B. Es la distribución de referencia del encargo.
- **Nivel 1:** «Día 1» a «Día 6», sin enfoque declarado. No hay garantía de un
  reparto coherente.

El «sistema de organización muscular» del looping maestro no existe en el
código ni en ninguna otra sesión activa.

## Consecuencia para la recuperación

Con las reglas del encargo («no inventar disponibilidad»), **ninguna rutina
cumple para publicarse automáticamente con 6 días**. Las 31 son propuestas
para revisión del entrenador.
