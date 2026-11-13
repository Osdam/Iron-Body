# Iron IA — organización muscular de las rutinas

**Un solo sistema:** `app/Services/Trainer/RoutineMuscleOrganizer.php`. Ahí viven:

- el reparto por días;
- la clasificación de los ejercicios por grupo muscular;
- la comprobación posterior de lo que devuelve el modelo.

Cualquier regla nueva de organización muscular, incluida la de un trabajo futuro
como el «looping maestro» de planificación muscular, se añade aquí y no en otra
clase.

## Por qué (13-11-2026)

El reparto y la clasificación vivían dentro de `IntegralPlanStrategy` y solo los
usaban los niveles 2–5. El nivel 1 pedía «entre 2 y 6 días», sin reparto.

En producción, una sonda de solo lectura (`repro/probe_coherencia_ro.php`) midió
para cada día qué parte de sus ejercicios pertenece a los grupos que le
correspondían:

| Rutinas de IA en producción | Peor día de cada rutina |
|---|---|
| Niveles 2, 3 y 4 (14) | todas ≥ 0,8; de media, 1,0 |
| Nivel 1 (58) | 25 con algún día por debajo de 0,4; mediana ≈ 0,5 |

Los 206 ejercicios del catálogo que ve Iron IA tienen todos un grupo
clasificable.

## Las tres piezas

1. **Reparto (`split()`, `dayPlan()`).** Las tablas son las de siempre, sin
   cambios: con 6 días, Empuje A · Tracción A · Pierna A · Empuje B ·
   Tracción B · Pierna B. Un principiante con 3 días hace cuerpo completo. Cada
   día se llama «Día N · Enfoque».
2. **Clasificación (`tagsOf()`).** El `muscle_group` y el `target` del ejercicio
   se traducen a grupos (`chest`, `back`, `legs`…).
3. **Comprobación posterior (`review()`).** El día *i* de la rutina se compara
   con el día *i* del reparto:
   - pasa si al menos el **60 %** de sus ejercicios es de sus grupos
     (`iron_ai.plan_powers.muscle_min_share`); con 5 ejercicios admite dos
     accesorios;
   - al comprobar, «brazos» vale en un día de bíceps o de tríceps, y
     «pantorrilla», en un día de pierna;
   - un ejercicio sin grupo reconocible cuenta como de otro grupo.

## Dónde se aplica

| Camino | Reparto en el prompt | Comprobación posterior |
|---|---|---|
| Niveles 2–5 (por secciones) | Siempre (catálogo por día, como antes) | Siempre |
| Nivel 1 (prompt v1) **con días indicados** (entrenador o propuesta de recuperación) | Sí: regla «Reparte los días EXACTAMENTE…» | Sí |
| Rutina sencilla del modo mixto, con días indicados | Sí | Sí |
| Nivel 1 con `{}` (apps hasta la 2.0.9) | **No**: el prompt es idéntico byte a byte (`IntegralPlanLevelOneParityTest`) | No |

**Si la comprobación falla**, no se guarda nada: la respuesta es un 422 que se
puede reintentar (`ai_muscle_mismatch`), con el día y el motivo. Por ejemplo,
«el día 2 (“Tracción A”) trae 2 de 5 ejercicios de espalda y bíceps».

**Si pasa**, cada día recibe el nombre de su enfoque y el detalle se guarda en la
corrida (`response_json.muscle_review`).

## Pruebas

- `tests/Unit/RoutineMuscleOrganizerTest.php`: las tablas, el umbral, las
  compatibilidades, el número de días y la regla del prompt.
- `tests/Feature/Trainer/IronIaTrainerContextTest.php`: el nivel 1 y el modo
  mixto piden y comprueban el reparto.
- El doble `tests/Support/FakeIronIaOpenAi.php` elige como un modelo
  razonable: primero los ejercicios de los grupos del día que pide la petición.
  Sin reparto en la petición, se comporta como antes.
- E2E con OpenAI real del 13-11 (`RECUPERACION-RUTINAS.md`): 5 de 5 propuestas
  con un reparto coherente; peor día entre 0,8 y 1,0.
