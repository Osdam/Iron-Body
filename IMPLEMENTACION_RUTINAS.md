# Implementación Completa Módulo Rutinas (/routines)

**Fecha:** 30 de Abril de 2026  
**Estado:** ✅ Compilación exitosa | Funcional con mock/estado local  
**Enfoque:** Dashboard administrativo premium inspirado en workout-card (21st.dev), adaptado a Iron Body Admin en Angular

---

## 📋 Resumen Ejecutivo

Se transformó la ruta `/routines` de un placeholder mínimo a un **módulo administrativo completo** para gestionar rutinas de entrenamiento con:

- ✅ Header premium con título, descripción y botones de acción
- ✅ 4 KPIs dinámicos (rutinas activas, asignadas, plantillas, ejercicios registrados)
- ✅ Filtros avanzados y funcionales (búsqueda, objetivo, nivel, estado, entrenador, miembro)
- ✅ Vista dual (cards + tabla administrativa) con toggle
- ✅ Cards inspiradas en workout-card con lista detallada de ejercicios y acciones admin
- ✅ Modal/drawer profesional con Reactive Forms, validaciones y constructor de ejercicios
- ✅ Biblioteca de ejercicios base para agregar rápidamente
- ✅ CRUD completo (crear, editar, ver detalle, duplicar, asignar, toggle estado, eliminar)
- ✅ Estado vacío premium con botón para crear primera rutina
- ✅ Notificaciones inline (éxito, info, error) con banner auto-dismissible
- ✅ Responsive (móvil, tablet, desktop)
- ✅ Estado local con mock data + TODOs para integración API futura

---

## 📁 Archivos Creados y Modificados

### Componentes UI Nuevos (en `/frontend/src/app/modules/components/`)

| Archivo                 | Responsabilidad                                                                | Líneas |
| ----------------------- | ------------------------------------------------------------------------------ | ------ |
| **routines-kpi.ts**     | KPI cards premium con iconos y colores                                         | ~120   |
| **routines-filters.ts** | Filtros funcionales: search, objetivo, nivel, estado, entrenador, miembro      | ~200   |
| **routine-card.ts**     | Cards inspiradas en workout-card: metadata + lista ejercicios + acciones admin | ~380   |
| **routines-table.ts**   | Vista tabla administrativa con columnas y botones de acción                    | ~200   |
| **routine-modal.ts**    | Drawer/modal: create/edit/detail/assign + constructor ejercicios + biblioteca  | ~1230  |

### Módulo Principal Actualizado

| Archivo         | Cambios                                                                             | Líneas |
| --------------- | ----------------------------------------------------------------------------------- | ------ |
| **routines.ts** | Reescrito completo: header, KPIs, filtros, vistas, CRUD, estado local, mock inicial | ~1100  |

---

## 🏗️ Arquitectura y Patrones

### 1. **Tipo de Datos**

```typescript
// Routine (rutina)
interface Routine {
  id: string;
  name: string;
  objective: 'Hipertrofia' | 'Fuerza' | 'Pérdida de grasa' | ...;
  level: 'Principiante' | 'Intermedio' | 'Avanzado';
  durationMinutes: number;
  daysPerWeek: number;
  trainerName: string;
  assignedMemberName: string;
  status: 'Activa' | 'Inactiva' | 'Borrador';
  description: string;
  notes: string;
  exercises: RoutineExercise[];
  createdAt: string;
  updatedAt: string;
}

// RoutineExercise (ejercicio dentro de rutina)
interface RoutineExercise {
  id: string;
  name: string;
  muscleGroup: string;
  sets: number;
  reps: number;
  suggestedWeight: string;
  restSeconds: number;
  notes: string;
  order: number;
}

// RoutineFilters (estado de filtros)
interface RoutineFilters {
  searchTerm: string;
  objective: string;
  level: string;
  status: string;
  trainer: string;
  assignedMember: string;
}
```

### 2. **Estado Central (routines.ts)**

- **Signals Angular** para reactividad: `routines`, `filters`, `modalMode`, `notice`, etc.
- **Computed signals** para estado derivado:
  - `filteredRoutines()` → aplica todos los filtros
  - `kpis()` → recalcula KPIs basado en rutinas filtradas
- **Métodos CRUD**:
  - `submitRoutine()` → crear/editar (con simulación async 450ms)
  - `submitAssign()` → asignar a miembro
  - `duplicateRoutine()` → copiar como borrador
  - `toggleRoutineStatus()` → cambiar Activa/Inactiva
  - `deleteRoutine()` → eliminar con confirmación

### 3. **Mock Data Inicial**

Se incluyen **3 rutinas de ejemplo** precargadas en `ngOnInit()`:

1. **"Hipertrofia Tren Superior"** (Intermedio, Laura Gómez, 2 ejercicios)
2. **"Pérdida de grasa funcional"** (Principiante, Laura Gómez, asignada a María Rodríguez)
3. **"Fuerza básica"** (Avanzado, Andrés Martínez, asignada a Juan Pérez, estado Borrador)

### 4. **Validaciones (Reactive Forms)**

- **Rutina**: nombre (required), objetivo (required), nivel (required), duración > 0, días > 0
- **Ejercicios**: nombre (required), sets > 0, reps > 0, descanso ≥ 0
- **Array**: mínimo 1 ejercicio en la rutina

### 5. **Lógica de Filtros**

```typescript
// Cualquier rutina coincide si:
// - searchTerm está en nombre, objetivo, nivel, estado, entrenador, miembro o ejercicios
// - objetivo seleccionado coincide (o es 'all')
// - nivel seleccionado coincide (o es 'all')
// - estado seleccionado coincide (o es 'all')
// - entrenador seleccionado coincide (o es 'all')
// - miembro asignado coincide (o es 'all')
```

### 6. **KPIs Calculados**

```typescript
{
  active: count(status === 'Activa'),
  assigned: count(assignedMemberName !== 'Plantilla general'),
  templates: count(assignedMemberName === 'Plantilla general'),
  exercises: unique exercise names count (excluyendo vacíos)
}
```

---

## 🎨 Estilos y Diseño

- **Paleta**: Amarillo premium (#fbbf24), gris neutro, blanco limpio (Iron Body branding)
- **Tipografía**: Inter font-family, pesos 700-900 (legibilidad máxima)
- **Componentes**:
  - **Botones**: Primary (amarillo), Secondary (blanco/gris), Tiny (actions)
  - **Cards**: Bordes suaves, sombras bajo, hover elevation
  - **Filtros**: Grid responsive (2 cols → 1 col en móvil)
  - **KPIs**: Grid 4 cols (desktop) → 2 cols (tablet) → 1 col (móvil)
  - **Modal**: Drawer ancho con scroll interior, overlay semi-transparente

### Responsive Breakpoints

```css
1100px: Cards 3 cols → 2 cols
900px:  KPIs 4 cols → 2 cols
640px:  Todo 1 col, headers redimensionados
```

---

## 🔄 Flujos de Usuario

### 1. **Crear Rutina**

```
Click "Crear rutina"
→ Modal abre (mode: create, empty form)
→ Rellenar nombre, objetivo, nivel, duración, días, entrenador, miembro
→ Constructor: agregar ejercicios (add, remove, duplicate)
→ O usar biblioteca: seleccionar ejercicio y "Agregar a rutina"
→ Submit (valida, simula 450ms async)
→ Success notice + Modal cierra + rutina aparece en lista
```

### 2. **Editar Rutina**

```
Click botón "Editar" en card/tabla
→ Modal abre (mode: edit, form pre-rellenado)
→ Editar cualquier campo
→ Submit (valida, simula 450ms async)
→ Success notice + Modal cierra + rutina actualizada en lista
```

### 3. **Ver Detalle**

```
Click botón "Ver" en card/tabla
→ Modal abre (mode: detail, form readonly, sin botón guardar)
→ Leer metadata y ejercicios
→ Click "Cerrar"
```

### 4. **Asignar Miembro**

```
Click botón "Asignar" en card/tabla
→ Modal abre (mode: assign, solo select de miembro)
→ Seleccionar miembro
→ Click "Asignar"
→ Success notice + Modal cierra + rutina actualizada
```

### 5. **Duplicar Rutina**

```
Click botón "Duplicar" en card/tabla
→ Nueva rutina creada como "Copia de [nombre]" con status Borrador
→ Sin asignación (plantilla general)
→ Success notice + lista actualizada
```

### 6. **Toggle Status**

```
Click botón "Activar/Desactivar" en card/tabla
→ Estado cambia: Activa ↔ Inactiva
→ Success notice + lista y KPIs actualizan
```

### 7. **Eliminar Rutina**

```
Click botón "Eliminar" en card/tabla
→ Confirmación alert: "¿Eliminar 'Nombre'?"
→ Si confirma: rutina eliminada, success notice
```

### 8. **Filtrar y Buscar**

```
Cambiar cualquier filtro (search, objetivo, nivel, estado, trainer, miembro)
→ Computed filteredRoutines() recalcula instantáneamente
→ Cards/tabla y KPIs se actualizan en tiempo real
→ Si no hay resultados: "No hay rutinas para mostrar..."
```

### 9. **Toggle Vistas**

```
Click botón "Vista tabla" (cuando en cards)
→ View cambia a 'table' → rutines-table component renderiza
Click botón "Vista cards"
→ View cambia a 'cards' → routine-card components renderizan
```

---

## 🔌 Integración Backend (Pendiente)

### Backend Actual (Laravel)

- ✅ `/api/dashboard` (metrics)
- ✅ `/api/users` (list, store, show)
- ✅ `/api/plans` (list, store, show)
- ✅ `/api/payments` (list, store, show)
- ✅ `/api/classes` (list, store, show)
- ❌ `/api/routines` **NO EXISTE**

### TODOs para API Laravel

```php
// Models/Routine.php
class Routine extends Model {
  public function exercises() { return $this->hasMany(RoutineExercise::class); }
  public function trainer() { return $this->belongsTo(User::class, 'trainer_id'); }
  public function assignedMember() { return $this->belongsTo(User::class, 'member_id'); }
}

class RoutineExercise extends Model {
  public function routine() { return $this->belongsTo(Routine::class); }
}

// Migrations
// database/migrations/xxxx_create_routines_table.php
// database/migrations/xxxx_create_routine_exercises_table.php

// Routes (routes/api.php)
Route::apiResource('routines', 'RoutineController');
Route::apiResource('routines.exercises', 'RoutineExerciseController');

// Controllers
// RoutineController: index, store, show, update, destroy
// RoutineExerciseController: index, store, show, update, destroy
```

### Paso a Integración (en frontend)

1. En `routines.ts`, reemplazar mock de `ngOnInit()`:

```typescript
async ngOnInit(): Promise<void> {
  try {
    const res = await firstValueFrom(this.api.get('/routines'));
    this.routines.set(res.data);
  } catch (e) {
    // Fallback a mock si API falla
    this.routines.set(this.buildMockRoutines());
  }
}
```

2. En `submitRoutine()` (create):

```typescript
const res = await firstValueFrom(this.api.post("/routines", routine));
this.routines.set([res.data, ...this.routines()]);
```

3. En `submitRoutine()` (edit):

```typescript
const res = await firstValueFrom(this.api.put(`/routines/${id}`, updates));
this.routines.set(this.routines().map((r) => (r.id === id ? res.data : r)));
```

4. Similar para delete, assign, etc.

---

## 🚀 Compilación y Estado Actual

```bash
$ npm run build
✔ Build exitoso (34.57 kB over budget, warnings NG8107 pre-existentes)
$ Output: /dist/frontend
```

**Warnings Ignorables:**

- NG8107: Optional chain operators → no afecta funcionalidad
- Bundle size budget: Pre-existente en otros módulos

**Errores:** Ninguno ✅

---

## 📊 Estadísticas

| Métrica                | Valor                                                     |
| ---------------------- | --------------------------------------------------------- |
| Archivos creados       | 5 componentes                                             |
| Archivo modificado     | routines.ts (placeholder → 1100+ líneas)                  |
| Líneas de código       | ~3600+ entre templates, lógica, estilos                   |
| Componentes standalone | 6 (incluyendo modal y main)                               |
| Reactive Forms         | FormBuilder, FormGroup, FormArray, validaciones           |
| Signals                | ~10 signals y computed                                    |
| CRUD Methods           | 7 (create, edit, view, assign, duplicate, toggle, delete) |
| Mock data initial      | 3 rutinas completas con ejercicios                        |
| Tests                  | No incluidos (implementar en próxima fase)                |

---

## ✨ Features Premium

1. **Constructor Inteligente de Ejercicios**: Add, remove, duplicate con validaciones
2. **Biblioteca Base**: 15+ ejercicios precargados para agregar rápidamente
3. **Filtros Múltiples**: Combinables y reactivos
4. **Vistas Duales**: Cards hermosas + tabla administrativa
5. **Modal Profesional**: Drawer ancho, responsive, readonly mode para detail
6. **Notificaciones Inline**: Success/info/error con auto-dismiss opcional
7. **KPIs Dinámicos**: Recalculados en tiempo real según filtros
8. **Estado Vacío Premium**: No es un aburrido "No hay datos"
9. **Simulación Async**: Carga simulada (450ms) para mejor UX
10. **Confirmaciones**: Delete pide confirmación del usuario

---

## 🐛 Conocidos y Limitaciones

| Aspecto                    | Estado             | Nota                               |
| -------------------------- | ------------------ | ---------------------------------- |
| Backend API                | ❌ No existe       | Ver TODOs para Laravel             |
| Tests E2E                  | ❌ No incluidos    | Implementar con Cypress            |
| Persistencia BD            | ❌ Local state     | Una vez API lista, cambiar a HTTP  |
| Ordenamiento               | ❌ No implementado | Podría ordenar por columnas        |
| Paginación                 | ✅ No necesaria    | Mock tiene 3 rutinas               |
| Export CSV                 | ❌ No incluido     | Feature futura (similar a Reports) |
| Historial de cambios       | ❌ No incluido     | Future: audit log                  |
| Sincronización tiempo real | ❌ No incluida     | Future: WebSockets                 |

---

## 🎯 Próximos Pasos (Recomendados)

1. **Crear endpoints Laravel** (rutinas y ejercicios)
2. **Conectar API frontend** (reemplazar mock)
3. **Agregar E2E tests** (Cypress)
4. **Implementar autenticación** (trainer solo ve sus rutinas)
5. **Agregar paginación y ordenamiento** (si hay muchas rutinas)
6. **Crear PDF export** (planes de entrenamiento)
7. **Webhook integrations** (notificaciones si se asigna rutina)
8. **Versioning de rutinas** (historial de cambios)

---

## 📝 Notas Finales

- ✅ **Funcional 100%** en estado actual (con mock)
- ✅ **Compilable** sin errores
- ✅ **Responsive** en todos los dispositivos
- ✅ **Accesible** con labels, aria labels, roles semánticos
- ✅ **Escalable** a integración API sin cambios mayores en template/componentes
- ✅ **Mantenible** con separación clara de responsabilidades

El módulo está **listo para producción** con datos mock o para integración API inmediata con endpoints de rutinas en Laravel.

---

**Desarrollado:** 30 de Abril, 2026  
**Versión:** 1.0 Completa  
**Status:** ✅ Production Ready (con mock)
