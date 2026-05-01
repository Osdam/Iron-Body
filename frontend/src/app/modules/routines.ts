import { CommonModule } from '@angular/common';
import { Component, OnInit, computed, signal } from '@angular/core';
import RoutinesKpiComponent from './components/routines-kpi';
import RoutinesFiltersComponent, { RoutineFilters } from './components/routines-filters';
import RoutineCardComponent, { Routine, RoutineExercise } from './components/routine-card';
import RoutinesTableComponent from './components/routines-table';
import RoutineModalComponent, { RoutineModalMode } from './components/routine-modal';

@Component({
  selector: 'module-routines',
  standalone: true,
  imports: [
    CommonModule,
    RoutinesKpiComponent,
    RoutinesFiltersComponent,
    RoutineCardComponent,
    RoutinesTableComponent,
    RoutineModalComponent,
  ],
  template: `
    <section class="routines-page">
      <header class="header">
        <div class="header-left">
          <h1>Rutinas</h1>
          <p>Administra rutinas, ejercicios, objetivos y asignaciones de entrenamiento.</p>
        </div>

        <div class="header-right">
          <button type="button" class="btn-secondary" (click)="toggleView()">
            <span class="material-symbols-outlined" aria-hidden="true">{{
              selectedView() === 'cards' ? 'table_rows' : 'grid_view'
            }}</span>
            {{ selectedView() === 'cards' ? 'Vista tabla' : 'Vista cards' }}
          </button>

          <button type="button" class="btn-primary" (click)="openCreateRoutineModal()">
            <span class="material-symbols-outlined" aria-hidden="true">add</span>
            Crear rutina
          </button>
        </div>
      </header>

      <div *ngIf="notice() as n" class="notice" [ngClass]="'notice-' + n.kind" role="status">
        <span class="material-symbols-outlined" aria-hidden="true">{{ noticeIcon(n.kind) }}</span>
        <p class="notice-message">{{ n.message }}</p>
        <button type="button" class="notice-close" (click)="dismissNotice()" aria-label="Cerrar">
          close
        </button>
      </div>

      <section class="kpis">
        <app-routines-kpi
          title="Rutinas activas"
          icon="check_circle"
          color="success"
          [value]="kpis().active"
          subtitle="En estado Activa"
        ></app-routines-kpi>
        <app-routines-kpi
          title="Rutinas asignadas"
          icon="group"
          color="info"
          [value]="kpis().assigned"
          subtitle="Asignadas a miembro"
        ></app-routines-kpi>
        <app-routines-kpi
          title="Plantillas disponibles"
          icon="loyalty"
          color="primary"
          [value]="kpis().templates"
          subtitle="Plantilla general"
        ></app-routines-kpi>
        <app-routines-kpi
          title="Ejercicios registrados"
          icon="fitness_center"
          color="warning"
          [value]="kpis().exercises"
          subtitle="Únicos en rutinas"
        ></app-routines-kpi>
      </section>

      <app-routines-filters
        [filters]="filters()"
        [trainers]="trainerOptions"
        [members]="memberOptions"
        (filtersChange)="onFiltersChange($event)"
      ></app-routines-filters>

      <ng-container *ngIf="routines().length === 0; else content">
        <section class="empty">
          <div class="empty-icon" aria-hidden="true">
            <span class="material-symbols-outlined">fitness_center</span>
          </div>
          <h2>Todavía no hay rutinas creadas</h2>
          <p>
            Crea tu primera rutina para administrar ejercicios, objetivos, niveles y asignaciones de
            entrenamiento.
          </p>
          <button type="button" class="btn-primary" (click)="openCreateRoutineModal()">
            <span class="material-symbols-outlined" aria-hidden="true">add</span>
            Crear primera rutina
          </button>
        </section>
      </ng-container>

      <ng-template #content>
        <ng-container *ngIf="selectedView() === 'cards'">
          <section class="cards" *ngIf="filteredRoutines().length; else noResults">
            <app-routine-card
              *ngFor="let r of filteredRoutines(); trackBy: trackRoutine"
              [routine]="r"
              (view)="viewRoutineDetail($event)"
              (edit)="editRoutine($event)"
              (duplicate)="duplicateRoutine($event)"
              (assign)="assignRoutine($event)"
              (toggleStatus)="toggleRoutineStatus($event)"
              (remove)="deleteRoutine($event)"
            ></app-routine-card>
          </section>

          <ng-template #noResults>
            <div class="no-results">No hay rutinas para mostrar con los filtros actuales.</div>
          </ng-template>
        </ng-container>

        <ng-container *ngIf="selectedView() === 'table'">
          <app-routines-table
            [routines]="filteredRoutines()"
            (view)="viewRoutineDetail($event)"
            (edit)="editRoutine($event)"
            (duplicate)="duplicateRoutine($event)"
            (assign)="assignRoutine($event)"
            (toggleStatus)="toggleRoutineStatus($event)"
            (remove)="deleteRoutine($event)"
          ></app-routines-table>
        </ng-container>
      </ng-template>

      <app-routine-modal
        [isOpen]="isRoutineModalOpen()"
        [mode]="modalMode()"
        [routine]="selectedRoutine()"
        [isSaving]="isSavingRoutine()"
        [trainers]="trainerOptions"
        [members]="memberOptions"
        (close)="closeRoutineModal()"
        (save)="submitRoutine($event)"
        (assignSave)="submitAssign($event)"
      ></app-routine-modal>
    </section>
  `,
  styles: [
    `
      .routines-page {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0;
        color: #0a0a0a;
      }

      .header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 2rem;
        margin-bottom: 1.9rem;
        flex-wrap: wrap;
        padding-bottom: 1.5rem;
        border-bottom: 2px solid #f0f0f0;
      }

      .header-left h1 {
        font-family: Inter, sans-serif;
        font-size: 2.5rem;
        font-weight: 800;
        margin: 0 0 0.5rem;
        letter-spacing: -0.02em;
        line-height: 1.1;
      }

      .header-left p {
        font-size: 1.05rem;
        line-height: 1.6;
        color: #666;
        margin: 0;
        max-width: 720px;
      }

      .header-right {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        align-items: end;
      }

      .btn-primary,
      .btn-secondary {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        padding: 0.78rem 1.2rem;
        border-radius: 12px;
        font-size: 0.95rem;
        font-weight: 850;
        cursor: pointer;
        transition: all 0.2s ease;
        border: 1px solid transparent;
      }

      .btn-primary {
        background: #fbbf24;
        color: #0a0a0a;
        box-shadow: 0 10px 22px rgba(251, 191, 36, 0.2);
      }

      .btn-primary:hover {
        background: #f9a825;
        box-shadow: 0 14px 28px rgba(251, 191, 36, 0.25);
        transform: translateY(-1px);
      }

      .btn-primary:focus {
        outline: none;
        box-shadow:
          0 0 0 3px rgba(251, 191, 36, 0.12),
          0 14px 28px rgba(251, 191, 36, 0.25);
      }

      .btn-secondary {
        background: #ffffff;
        color: #0a0a0a;
        border-color: #e5e5e5;
      }

      .btn-secondary:hover {
        background: #f9f9f9;
        border-color: #d0d0d0;
      }

      .kpis {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
      }

      .cards {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1.1rem;
        margin-bottom: 2rem;
      }

      .empty {
        border: 1px solid #ededed;
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 14px 34px rgba(0, 0, 0, 0.05);
        padding: 2.2rem;
        text-align: center;
      }

      .empty-icon {
        width: 62px;
        height: 62px;
        border-radius: 18px;
        display: grid;
        place-items: center;
        margin: 0 auto 1rem;
        border: 1px solid rgba(251, 191, 36, 0.45);
        background: rgba(251, 191, 36, 0.12);
      }

      .empty-icon span {
        font-size: 1.8rem;
      }

      .empty h2 {
        margin: 0;
        font-size: 1.45rem;
        font-weight: 900;
        letter-spacing: -0.01em;
      }

      .empty p {
        margin: 0.6rem auto 1.35rem;
        color: #666;
        line-height: 1.6;
        max-width: 560px;
      }

      .no-results {
        padding: 1.2rem;
        border: 1px solid #ededed;
        border-radius: 14px;
        background: #ffffff;
        color: #666;
      }

      .notice {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 1rem 1.1rem;
        border-radius: 12px;
        border: 1px solid #e5e5e5;
        background: #ffffff;
        color: #0a0a0a;
        margin: 0 0 1.4rem;
      }

      .notice .material-symbols-outlined {
        font-size: 1.35rem;
      }

      .notice-message {
        margin: 0;
        flex: 1;
        font-weight: 700;
        color: #222;
      }

      .notice-close {
        border: none;
        background: transparent;
        cursor: pointer;
        color: #666;
        font-weight: 800;
        font-size: 0.9rem;
        padding: 0.25rem 0.35rem;
        border-radius: 8px;
        transition: background 0.15s ease;
      }

      .notice-close:hover {
        background: #f3f4f6;
      }

      .notice-success {
        border-color: #bbf7d0;
        background: #f0fdf4;
      }

      .notice-info {
        border-color: #e5e5e5;
        background: #fafafa;
      }

      .notice-error {
        border-color: #fecaca;
        background: #fef2f2;
      }

      @media (max-width: 1100px) {
        .cards {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }

      @media (max-width: 900px) {
        .kpis {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }

      @media (max-width: 640px) {
        .kpis {
          grid-template-columns: 1fr;
        }
        .cards {
          grid-template-columns: 1fr;
        }
        .header-left h1 {
          font-size: 2rem;
        }
      }
    `,
  ],
})
export default class RoutinesModule implements OnInit {
  trainerOptions = [
    'Sin asignar',
    'Carlos Ruiz',
    'Laura Gómez',
    'Andrés Martínez',
    'Camila Torres',
  ];
  memberOptions = [
    'Plantilla general',
    'Alejandro Gómez',
    'Juan Pérez',
    'María Rodríguez',
    'Carlos Martínez',
  ];

  routines = signal<Routine[]>([]);

  selectedView = signal<'cards' | 'table'>('cards');

  filters = signal<RoutineFilters>({
    searchTerm: '',
    objective: 'all',
    level: 'all',
    status: 'all',
    trainer: 'all',
    assignedMember: 'all',
  });

  notice = signal<{ kind: 'success' | 'info' | 'error'; message: string } | null>(null);

  isRoutineModalOpen = signal<boolean>(false);
  isSavingRoutine = signal<boolean>(false);
  modalMode = signal<RoutineModalMode>('create');
  selectedRoutine = signal<Routine | null>(null);

  filteredRoutines = computed(() => this.filterRoutines(this.routines(), this.filters()));
  kpis = computed(() => this.calculateRoutineKpis(this.filteredRoutines()));

  ngOnInit(): void {
    // Backend no tiene rutinas todavía. Cargamos mock para visualizar el módulo.
    this.routines.set(this.buildMockRoutines());
  }

  toggleView(): void {
    this.selectedView.set(this.selectedView() === 'cards' ? 'table' : 'cards');
  }

  onFiltersChange(next: RoutineFilters): void {
    this.filters.set(next);
  }

  openCreateRoutineModal(): void {
    this.dismissNotice();
    this.modalMode.set('create');
    this.selectedRoutine.set(null);
    this.isRoutineModalOpen.set(true);
  }

  closeRoutineModal(): void {
    if (this.isSavingRoutine()) return;
    this.isRoutineModalOpen.set(false);
    this.selectedRoutine.set(null);
  }

  viewRoutineDetail(routine: Routine): void {
    this.dismissNotice();
    this.modalMode.set('detail');
    this.selectedRoutine.set(routine);
    this.isRoutineModalOpen.set(true);
  }

  editRoutine(routine: Routine): void {
    this.dismissNotice();
    this.modalMode.set('edit');
    this.selectedRoutine.set(routine);
    this.isRoutineModalOpen.set(true);
  }

  assignRoutine(routine: Routine): void {
    this.dismissNotice();
    this.modalMode.set('assign');
    this.selectedRoutine.set(routine);
    this.isRoutineModalOpen.set(true);
  }

  async submitRoutine(payload: Partial<Routine>): Promise<void> {
    this.isSavingRoutine.set(true);
    this.notice.set(null);

    try {
      await new Promise((r) => setTimeout(r, 450));

      const mode = this.modalMode();
      if (mode === 'edit') {
        const current = this.selectedRoutine();
        if (!current) throw new Error('Rutina no encontrada para edición.');

        const updated: Routine = {
          ...current,
          ...payload,
          exercises: this.normalizeExercises(payload.exercises || current.exercises),
          updatedAt: new Date().toISOString(),
        };

        this.routines.set(this.routines().map((r) => (r.id === current.id ? updated : r)));
        this.notice.set({ kind: 'success', message: 'Rutina actualizada correctamente.' });
        this.closeRoutineModal();
        return;
      }

      // Create
      const now = new Date().toISOString();
      const routine: Routine = {
        id: this.newId('routine'),
        name: String(payload.name || '').trim(),
        objective: String(payload.objective || ''),
        level: String(payload.level || ''),
        durationMinutes: Number(payload.durationMinutes || 0),
        daysPerWeek: Number(payload.daysPerWeek || 0),
        trainerName: payload.trainerName || 'Sin asignar',
        assignedMemberName: payload.assignedMemberName || 'Plantilla general',
        status: payload.status || 'Activa',
        description: payload.description || '',
        notes: payload.notes || '',
        exercises: this.normalizeExercises(payload.exercises || []),
        createdAt: now,
        updatedAt: now,
      };

      this.routines.set([routine, ...this.routines()]);
      this.notice.set({ kind: 'success', message: 'Rutina creada correctamente.' });
      this.closeRoutineModal();
    } catch (e: any) {
      this.notice.set({ kind: 'error', message: e?.message || 'No se pudo guardar la rutina.' });
    } finally {
      this.isSavingRoutine.set(false);
    }
  }

  async submitAssign(payload: { assignedMemberName: string }): Promise<void> {
    this.isSavingRoutine.set(true);
    this.notice.set(null);

    try {
      await new Promise((r) => setTimeout(r, 350));

      const current = this.selectedRoutine();
      if (!current) throw new Error('Rutina no encontrada para asignación.');

      const updated: Routine = {
        ...current,
        assignedMemberName: payload.assignedMemberName || 'Plantilla general',
        updatedAt: new Date().toISOString(),
      };

      this.routines.set(this.routines().map((r) => (r.id === current.id ? updated : r)));
      this.notice.set({ kind: 'success', message: 'Asignación actualizada correctamente.' });
      this.closeRoutineModal();
    } catch (e: any) {
      this.notice.set({ kind: 'error', message: e?.message || 'No se pudo asignar la rutina.' });
    } finally {
      this.isSavingRoutine.set(false);
    }
  }

  duplicateRoutine(routine: Routine): void {
    const now = new Date().toISOString();
    const copy: Routine = {
      ...routine,
      id: this.newId('routine'),
      name: `Copia de ${routine.name}`,
      status: 'Borrador',
      assignedMemberName: 'Plantilla general',
      exercises: (routine.exercises || []).map((e, idx) => ({
        ...e,
        id: this.newId('ex'),
        order: idx + 1,
      })),
      createdAt: now,
      updatedAt: now,
    };

    this.routines.set([copy, ...this.routines()]);
    this.notice.set({ kind: 'success', message: 'Rutina duplicada como borrador.' });
  }

  toggleRoutineStatus(routine: Routine): void {
    const current = (routine.status || '').toLowerCase();
    const next = current.includes('activa') ? 'Inactiva' : 'Activa';
    const updated: Routine = { ...routine, status: next, updatedAt: new Date().toISOString() };
    this.routines.set(this.routines().map((r) => (r.id === routine.id ? updated : r)));
    this.notice.set({ kind: 'success', message: `Estado actualizado a ${next}.` });
  }

  deleteRoutine(routine: Routine): void {
    const ok = window.confirm(
      `¿Eliminar la rutina "${routine.name}"? Esta acción no se puede deshacer.`,
    );
    if (!ok) return;
    this.routines.set(this.routines().filter((r) => r.id !== routine.id));
    this.notice.set({ kind: 'success', message: 'Rutina eliminada.' });
  }

  trackRoutine = (_: number, r: Routine) => r.id;

  dismissNotice(): void {
    this.notice.set(null);
  }

  noticeIcon(kind: 'success' | 'info' | 'error'): string {
    if (kind === 'success') return 'check_circle';
    if (kind === 'error') return 'error';
    return 'info';
  }

  private filterRoutines(routines: Routine[], filters: RoutineFilters): Routine[] {
    const term = (filters.searchTerm || '').trim().toLowerCase();
    const objective = String(filters.objective || 'all');
    const level = String(filters.level || 'all');
    const status = String(filters.status || 'all');
    const trainer = String(filters.trainer || 'all');
    const member = String(filters.assignedMember || 'all');

    return (routines || []).filter((r) => {
      const name = (r.name || '').toLowerCase();
      const obj = (r.objective || '').toLowerCase();
      const lvl = (r.level || '').toLowerCase();
      const st = (r.status || '').toLowerCase();
      const t = (r.trainerName || '').toLowerCase();
      const m = (r.assignedMemberName || '').toLowerCase();
      const exText = (r.exercises || [])
        .map((e) => `${e.name || ''} ${e.muscleGroup || ''}`.toLowerCase())
        .join(' ');

      const matchesTerm =
        !term ||
        name.includes(term) ||
        obj.includes(term) ||
        lvl.includes(term) ||
        st.includes(term) ||
        t.includes(term) ||
        m.includes(term) ||
        exText.includes(term);
      const matchesObjective = objective === 'all' || (r.objective || '') === objective;
      const matchesLevel = level === 'all' || (r.level || '') === level;
      const matchesStatus = status === 'all' || (r.status || '') === status;
      const matchesTrainer = trainer === 'all' || (r.trainerName || '') === trainer;
      const matchesMember = member === 'all' || (r.assignedMemberName || '') === member;

      return (
        matchesTerm &&
        matchesObjective &&
        matchesLevel &&
        matchesStatus &&
        matchesTrainer &&
        matchesMember
      );
    });
  }

  private calculateRoutineKpis(routines: Routine[]): {
    active: number;
    assigned: number;
    templates: number;
    exercises: number;
  } {
    const list = routines || [];
    const active = list.filter((r) => String(r.status || '').toLowerCase() === 'activa').length;
    const assigned = list.filter((r) => {
      const member = (r.assignedMemberName || '').trim();
      return member && member.toLowerCase() !== 'plantilla general';
    }).length;
    const templates = list.filter((r) => {
      const member = (r.assignedMemberName || '').trim();
      return !member || member.toLowerCase() === 'plantilla general';
    }).length;

    const uniqueExerciseNames = new Set<string>();
    list.forEach((r) =>
      (r.exercises || []).forEach((e) =>
        uniqueExerciseNames.add(
          String(e.name || '')
            .trim()
            .toLowerCase(),
        ),
      ),
    );
    uniqueExerciseNames.delete('');

    return { active, assigned, templates, exercises: uniqueExerciseNames.size };
  }

  private normalizeExercises(exercises: RoutineExercise[] | any): RoutineExercise[] {
    const list: any[] = Array.isArray(exercises) ? exercises : [];
    return list
      .map((e, idx) => ({
        id: e.id || this.newId('ex'),
        name: String(e.name || '').trim(),
        muscleGroup: String(e.muscleGroup || ''),
        sets: Number(e.sets || 0),
        reps: Number(e.reps || 0),
        suggestedWeight: String(e.suggestedWeight || ''),
        restSeconds: Number(e.restSeconds ?? 0),
        notes: String(e.notes || ''),
        order: Number(e.order ?? idx + 1),
      }))
      .filter((e) => e.name);
  }

  private newId(prefix: string): string {
    const rand = Math.random().toString(16).slice(2, 10);
    return `${prefix}_${Date.now()}_${rand}`;
  }

  private buildMockRoutines(): Routine[] {
    const now = new Date().toISOString();
    return [
      {
        id: this.newId('routine'),
        name: 'Hipertrofia Tren Superior',
        objective: 'Hipertrofia',
        level: 'Intermedio',
        durationMinutes: 60,
        daysPerWeek: 4,
        trainerName: 'Carlos Ruiz',
        assignedMemberName: 'Plantilla general',
        status: 'Activa',
        description: 'Enfoque en volumen y técnica para tren superior.',
        notes: 'Priorizar control excéntrico y progresión semanal.',
        exercises: [
          {
            id: this.newId('ex'),
            name: 'Press banca',
            muscleGroup: 'Pecho',
            sets: 4,
            reps: 10,
            suggestedWeight: '60 kg',
            restSeconds: 90,
            notes: 'Escápulas retraídas, tempo controlado.',
            order: 1,
          },
          {
            id: this.newId('ex'),
            name: 'Remo con barra',
            muscleGroup: 'Espalda',
            sets: 4,
            reps: 12,
            suggestedWeight: '50 kg',
            restSeconds: 90,
            notes: 'Espalda neutra, codos hacia atrás.',
            order: 2,
          },
          {
            id: this.newId('ex'),
            name: 'Press militar',
            muscleGroup: 'Hombros',
            sets: 3,
            reps: 10,
            suggestedWeight: '35 kg',
            restSeconds: 75,
            notes: 'Evitar hiperextensión lumbar.',
            order: 3,
          },
        ],
        createdAt: now,
        updatedAt: now,
      },
      {
        id: this.newId('routine'),
        name: 'Pérdida de grasa funcional',
        objective: 'Pérdida de grasa',
        level: 'Principiante',
        durationMinutes: 45,
        daysPerWeek: 3,
        trainerName: 'Laura Gómez',
        assignedMemberName: 'María Rodríguez',
        status: 'Activa',
        description: 'Circuitos simples para acondicionamiento general.',
        notes: 'Priorizar técnica y pausas según tolerancia.',
        exercises: [
          {
            id: this.newId('ex'),
            name: 'Jumping Jacks',
            muscleGroup: 'Cardio',
            sets: 3,
            reps: 30,
            suggestedWeight: 'Peso corporal',
            restSeconds: 45,
            notes: 'Ritmo constante y respiración controlada.',
            order: 1,
          },
          {
            id: this.newId('ex'),
            name: 'Sentadilla',
            muscleGroup: 'Piernas',
            sets: 4,
            reps: 15,
            suggestedWeight: 'Peso corporal',
            restSeconds: 60,
            notes: 'Rodillas alineadas con pies, rango seguro.',
            order: 2,
          },
          {
            id: this.newId('ex'),
            name: 'Plancha',
            muscleGroup: 'Abdomen',
            sets: 3,
            reps: 45,
            suggestedWeight: 'Peso corporal',
            restSeconds: 45,
            notes: 'Mantener línea hombro-cadera-tobillo.',
            order: 3,
          },
        ],
        createdAt: now,
        updatedAt: now,
      },
      {
        id: this.newId('routine'),
        name: 'Fuerza básica',
        objective: 'Fuerza',
        level: 'Avanzado',
        durationMinutes: 75,
        daysPerWeek: 4,
        trainerName: 'Andrés Martínez',
        assignedMemberName: 'Juan Pérez',
        status: 'Borrador',
        description: 'Base de fuerza con movimientos compuestos.',
        notes: 'Controlar RPE y técnica; progresión semanal.',
        exercises: [
          {
            id: this.newId('ex'),
            name: 'Peso muerto',
            muscleGroup: 'Full body',
            sets: 5,
            reps: 5,
            suggestedWeight: '100 kg',
            restSeconds: 120,
            notes: 'Barra pegada al cuerpo, neutralidad lumbar.',
            order: 1,
          },
          {
            id: this.newId('ex'),
            name: 'Sentadilla',
            muscleGroup: 'Piernas',
            sets: 5,
            reps: 5,
            suggestedWeight: '90 kg',
            restSeconds: 120,
            notes: 'Brace abdominal; profundidad consistente.',
            order: 2,
          },
          {
            id: this.newId('ex'),
            name: 'Press banca',
            muscleGroup: 'Pecho',
            sets: 5,
            reps: 5,
            suggestedWeight: '75 kg',
            restSeconds: 120,
            notes: 'Pausa corta en pecho; salida explosiva.',
            order: 3,
          },
        ],
        createdAt: now,
        updatedAt: now,
      },
    ];
  }
}
