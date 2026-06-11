# Despliegue en producción — Metas nutricionales

Guía para el compañero en la VPS. Bloque **aditivo y reversible**: no toca datos
existentes ni rompe módulos. Ver el detalle funcional en `NUTRITION_GOALS.md`.

## Qué cambia

- **Migración nueva** (aditiva): `2026_06_11_000001_add_calculation_fields_to_nutrition_goals_table`
  agrega columnas a `nutrition_goals` (bmr, tdee, metabolic_sex, activity_level,
  formula_version, status, source, etc.). Las metas existentes quedan como
  `source=manual`, `status=manual` (siguen funcionando).
- **Sin variables `.env` nuevas obligatorias.** Todo vive en `config/nutrition.php`
  (`goal_calculator`). Opcionales (con default): `NUTRITION_GOAL_FORMULA_VERSION`.
- 4 rutas nuevas bajo `/api/nutrition/goal*`.

## Comandos VPS

```bash
cd /ruta/backend/Iron-Body
git status
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan queue:restart
php artisan route:list | grep nutrition
tail -n 100 storage/logs/laravel.log
```

## Validar una meta real

```bash
# 4 rutas presentes:
php artisan route:list | grep nutrition/goal

# Test del cálculo (no toca la BD de producción; corre en sqlite::memory):
php artisan test --filter=NutritionGoalTest
```

Desde la app (usuario real):

1. Crear usuario / iniciar sesión → entrar a **Nutrición**.
2. Sin datos suficientes → card **"Configura tu objetivo nutricional"**.
3. Completar objetivo + actividad (+ lo que falte) → **CALCULAR META** (preview) →
   **GUARDAR META**.
4. La gráfica de calorías restantes y los macros usan la meta real (no 2200).
5. Registrar un alimento → el progreso usa la meta personalizada.
6. Registrar una nueva evaluación física con peso distinto → al volver a
   Nutrición aparece el aviso **"Tu peso cambió ¿recalcular?"**.

## Rollback

La migración es reversible (drop de columnas agregadas):

```bash
php artisan migrate:rollback --step=1 --force
php artisan config:cache && php artisan route:cache
```

> El rollback solo elimina las columnas nuevas de `nutrition_goals`. Las metas
> manuales (daily_calories/protein_g/carbs_g/fat_g/goal_type) permanecen intactas.

## Revisar logs

```bash
tail -n 200 storage/logs/laravel.log
grep -i "nutrition" storage/logs/laravel.log | tail -50
```

## No se toca

`.env`, secretos, auth, pagos, membresías, ePayco, Nequi, IRON IA Live,
Progreso/Evaluación Física ni el tracking de alimentos existente.
