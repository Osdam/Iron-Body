# Metas nutricionales personalizadas (estilo Fitia)

Cada miembro tiene su **propia** meta diaria de calorías y macros, calculada por
el backend a partir de sus datos físicos, objetivo, experiencia y actividad. Se
acabó el `2200 kcal` universal.

> **Regla de oro:** el **backend Laravel es la única autoridad** del cálculo
> (BMR/TDEE/macros). Flutter captura datos, muestra preview y pinta resultados,
> pero **nunca** calcula la meta final ni hardcodea valores. La **IA explica**,
> nunca inventa la meta.

---

## 1. Fórmula — Mifflin-St Jeor

`BMR` (metabolismo basal) según sexo metabólico:

```
Hombre:  BMR = 10·peso_kg + 6.25·altura_cm − 5·edad + 5
Mujer:   BMR = 10·peso_kg + 6.25·altura_cm − 5·edad − 161
Neutral: BMR = 10·peso_kg + 6.25·altura_cm − 5·edad − 78   (promedio, ±menor precisión)
```

`formula = mifflin_st_jeor`, `formula_version = v1` (versionado en `config/nutrition.php`).

## 2. Datos necesarios

| Dato | Fuente |
|------|--------|
| Sexo metabólico | `members.gender` → `male/female`; "Otro" → el usuario elige |
| Edad | `members.birth_date` (o `birthdate` override) |
| Peso / estatura | última fila de `physical_evaluations` (o override) |
| Objetivo | `members.goal` (mapeado) o override |
| Experiencia | `members.training_level` (mapeado) o override |
| Nivel de actividad | onboarding de Nutrición (no estaba en el perfil) |

Si falta **edad, peso, estatura, objetivo o nivel de actividad** → `status =
setup_required` y la app pide **solo lo que falta** (no rehace el registro).

## 3. TDEE

```
TDEE = BMR × factor_actividad
```

| Nivel | Factor | Días/sem aprox. |
|-------|--------|-----------------|
| sedentary | 1.20 | 0–1 |
| light | 1.375 | 1–2 |
| moderate | 1.55 | 3–5 |
| very_active | 1.725 | 5–6 |
| athlete | 1.90 | 6–7 / trabajo físico |

## 4. Ajuste por objetivo (sobre el TDEE)

| Objetivo (canónico) | CRM (es) | Ajuste kcal |
|---------------------|----------|-------------|
| `muscle_gain` | Hipertrofia muscular | +300/+250/+200 (principiante/intermedio/avanzado) |
| `strength` | Fuerza | +250/+200/+150 |
| `fat_loss` | Pérdida de grasa | −250/−450/−600 (conservador/moderado/agresivo) |
| `endurance` | Resistencia | +100 |
| `general_wellness` | Bienestar general | 0 (mantenimiento) |

Piso de seguridad por sexo: `male 1500`, `female 1200`, `unspecified 1300` kcal.
Menores de edad: nunca déficit (warning `minor_conservative`).

## 5. Macros (g/kg de peso; carbohidratos por resto)

```
proteína_kcal = proteína_g × 4
grasa_kcal     = grasa_g × 9
carbohidratos_g = (target − proteína_kcal − grasa_kcal) / 4
```

| Objetivo | Proteína g/kg | Grasa g/kg |
|----------|---------------|------------|
| fat_loss | 2.0 | 0.7 |
| muscle_gain | 1.8 | 0.9 |
| strength | 1.8 | 0.9 |
| endurance | 1.6 | 0.85 |
| general_wellness | 1.6 | 0.9 |

Si los carbohidratos quedaran negativos: se baja primero la grasa (piso 0.5 g/kg)
y luego la proteína (piso 1.2 g/kg). **Ningún macro queda negativo.** Calorías se
redondean a múltiplos de 10; macros a gramos enteros.

## 6. Género "Otro"

`members.gender = "Otro"` → no se fuerza un cálculo incorrecto. El usuario elige
en el setup una **referencia metabólica** (masculina / femenina / **neutral**) o
una meta manual. La neutral promedia las constantes (−78) con warning explícito
de menor precisión. Se guarda `gender_identity` (lo elegido por el usuario) y
`metabolic_sex` (solo para el cálculo).

## 7. Recálculo

`GET /api/nutrition/goal` devuelve `needs_recalculation: true` cuando el peso de
la última evaluación difiere ≥ 2 kg del peso con que se calculó la meta. La app
**sugiere** recalcular; el usuario decide. Una meta **manual** no se sobreescribe
sin confirmación (`force`).

## 8. Qué hace / no hace la IA

- ✅ Explica la meta, sugiere revisar si no hay progreso.
- ❌ No calcula BMR/TDEE/macros, no inventa metas, no usa lenguaje médico.
- Todos los cambios reales pasan por `NutritionGoalService`.

## 9. Endpoints (prefijo `/api/nutrition`, `auth.member`)

| Método | Ruta | Acción |
|--------|------|--------|
| GET | `/goal` | meta actual o `setup_required` (+ prefill + missing) |
| POST | `/goal/calculate` | **preview** sin guardar |
| POST | `/goal` | calcula y **guarda** |
| POST | `/goal/recalculate` | recalcula con datos actuales (`force` para meta manual) |

Las metas **manuales** legacy siguen en `POST /api/app/nutrition/goals`.

## 10. Arquitectura backend

```
NutritionGoalController
  └─ NutritionGoalService            (orquesta: perfil + evaluación + overrides)
       ├─ NutritionGoalMapper        (es → claves canónicas)
       └─ NutritionGoalCalculatorService  (Mifflin-St Jeor, determinístico, puro)
nutrition_goals  (tabla: meta final + snapshot del cálculo + estado)
config/nutrition.php → goal_calculator  (TODAS las constantes de negocio)
```

`NutritionService::activeGoal()` (módulo legacy/app) ya **no** devuelve 2200:
sin meta → `status: setup_required`.

## 11. Límites del cálculo

- Estimación poblacional (Mifflin-St Jeor + factores estándar): orientativa, no
  clínica. La composición corporal real puede variar.
- Rangos válidos: edad 14–100, peso 30–300 kg, estatura 120–230 cm. Fuera de
  rango → 422 con error por campo (no se guarda nada absurdo).

## 12. Checklist de producción

- [ ] `php artisan migrate --force` (agrega columnas a `nutrition_goals`).
- [ ] `php artisan config:cache && php artisan route:cache`.
- [ ] `php artisan route:list | grep nutrition/goal` → 4 rutas.
- [ ] Usuario nuevo con datos completos → meta automática al entrar a Nutrición.
- [ ] Usuario sin datos → card "Configura tu objetivo nutricional".
- [ ] La gráfica de calorías/macros usa la meta real (no 2200).
- [ ] Cambiar peso → aparece sugerencia de recalcular.
- [ ] `php artisan test --filter=NutritionGoalTest` verde.
