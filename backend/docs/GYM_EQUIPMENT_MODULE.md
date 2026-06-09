# Módulo de Equipos del Gimnasio (Gym Equipment)

Inventario de las **máquinas físicas** que existen en la instalación. Su razón
principal de ser es **alimentar a IRON IA**: la IA debe conocer qué equipos hay
para **nunca recomendar un ejercicio que requiera una máquina inexistente**.

> No confundir con el módulo **Inventario** (`inventory`), que gestiona productos
> de venta/stock. Este módulo es el equipamiento de entrenamiento.

---

## 1. Modelo de datos — tabla `gym_equipment`

| Campo                 | Tipo      | Notas                                                                 |
|-----------------------|-----------|-----------------------------------------------------------------------|
| `id`                  | bigint    | PK                                                                     |
| `uuid`                | uuid      | Autogenerado                                                          |
| `name`                | string    | Nombre visible. Ej: "Prensa de piernas 45°"                           |
| `slug`                | string    | Normalizado (autogenerado del nombre). Clave de matching/idempotencia |
| `category`            | string    | `strength_machine`\|`free_weights`\|`cardio`\|`functional`\|`accessory`\|`bodyweight` |
| `muscle_groups`       | json      | `["cuádriceps","glúteos"]`                                            |
| `aliases`             | json      | Sinónimos para el matching de la IA: `["leg press","prensa"]`         |
| `brand` / `model`     | string    | Opcionales                                                            |
| `serial_number`       | string    | Opcional                                                              |
| `zone`                | string    | Ubicación física. Ej: "Sala cardio"                                   |
| `quantity`            | int       | Unidades disponibles                                                  |
| `status`              | string    | `operational`\|`maintenance`\|`out_of_service`                        |
| `image_url`           | string    | Opcional                                                              |
| `notes`               | text      | Opcional                                                              |
| `is_available_for_ai` | bool      | Si `false`, la IA lo ignora aunque exista                             |
| `acquired_at`         | date      | Opcional                                                              |
| `last_maintenance_at` | date      | Opcional                                                              |

Un equipo se considera **disponible para la IA** cuando
`status = operational` **y** `is_available_for_ai = true`
(scope `GymEquipment::forAi()`).

---

## 2. API

### 2.1 CRM admin (CRUD)

Patrón `/admin/*` del CRM (protegido por la capa de red/front, sin auth propia).

| Método | Ruta                          | Acción                          |
|--------|-------------------------------|---------------------------------|
| GET    | `/api/admin/equipment`        | Listar (filtros: `status`, `category`, `search`) |
| GET    | `/api/admin/equipment/stats`  | KPIs (totales por estado/categoría) |
| GET    | `/api/admin/equipment/{id}`   | Detalle                         |
| POST   | `/api/admin/equipment`        | Crear                           |
| PUT/PATCH | `/api/admin/equipment/{id}` | Actualizar                      |
| DELETE | `/api/admin/equipment/{id}`   | Eliminar (soft delete)          |

Cualquier mutación **invalida la caché** del catálogo de IA automáticamente.

### 2.2 IRON IA (solo lectura) ⭐ ESTE ES EL QUE CONSUME LA IA

```
GET /api/iron-ai/equipment-catalog
```

Respuesta (**forma estable** — contra esto se integra la IA):

```json
{
  "generated_at": "2026-06-09T10:00:00+00:00",
  "total": 24,
  "names": ["Prensa de piernas 45°", "Caminadora (trotadora)", "..."],
  "by_category": {
    "cardio": [
      { "name": "Caminadora (trotadora)", "slug": "caminadora-trotadora",
        "category": "cardio", "muscle_groups": ["cardio","piernas"],
        "aliases": ["treadmill","trotadora"], "zone": "Sala cardio", "quantity": 3 }
    ]
  },
  "items": [ { "name": "...", "slug": "...", "category": "...", "aliases": [],
               "muscle_groups": [], "zone": "...", "quantity": 1 } ]
}
```

- `names` → lista plana, ideal para una **validación rápida** ("¿este ejercicio
  necesita un equipo que esté en `names`?").
- `items` / `by_category` → detalle con sinónimos (`aliases`) y músculos para un
  matching más fino.
- El resultado está **cacheado 10 min** y se refresca al cambiar el catálogo.

---

## 3. Cómo lo usa la IA dentro del backend (ya integrado)

`App\Services\GymEquipmentContextService` es el punto único:

```php
$ctx = app(GymEquipmentContextService::class);

$ctx->catalog();          // array completo (igual que el endpoint)
$ctx->availableNames();   // string[] de nombres disponibles
$ctx->promptConstraint(); // frase lista para inyectar en el system prompt
$ctx->flush();            // invalida la caché (lo hace el CRUD por ti)
```

Ya conectado en dos lugares (referencia para extender a otros flujos de IA):

1. **`IronAiUserContextService::build()`** — añade el módulo opt-in
   `gym_equipment` (lista de nombres) al contexto del usuario. Pídelo así:
   ```php
   $context = $userContext->build($member, ['profile', 'gym_equipment']);
   // $context['gym_equipment'] === ["Prensa de piernas 45°", ...]
   ```
2. **`IronAiCoachService`** — el "plan de hoy" ya recibe `gym_equipment` y el
   system prompt instruye a la IA a tratarlo como **restricción dura**.

### Para el chat / realtime / otros servicios de IA

Inyecta la restricción en tu system prompt:

```php
$constraint = app(GymEquipmentContextService::class)->promptConstraint();
// "EQUIPOS DISPONIBLES EN EL GIMNASIO (úsalos como restricción dura): ... NO recomiendes ejercicios que requieran equipos que no estén en esta lista..."

$messages[] = ['role' => 'system', 'content' => $constraint];
```

O valida un ejercicio antes de mostrarlo:

```php
$names = app(GymEquipmentContextService::class)->availableNames();
$ok = in_array($exercise->equipment_required, $names, true); // matching simple
```

---

## 4. Carga de datos

Seeder idempotente (por `slug`):

```bash
php artisan migrate
php artisan db:seed --class=Database\\Seeders\\GymEquipmentSeeder
```

⚠️ El seeder trae un set de partida genérico. **Reemplázalo/amplíalo con las
máquinas reales** de Iron Body Neiva en `database/seeders/GymEquipmentSeeder.php`.
También se pueden cargar desde el CRM (módulo "Equipos").
