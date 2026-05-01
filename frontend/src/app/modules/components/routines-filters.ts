import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, Output } from '@angular/core';
import { FormsModule } from '@angular/forms';

export type RoutineObjective =
  | 'Hipertrofia'
  | 'Fuerza'
  | 'Pérdida de grasa'
  | 'Resistencia'
  | 'Funcional'
  | 'Rehabilitación'
  | 'Mantenimiento';

export type RoutineLevel = 'Principiante' | 'Intermedio' | 'Avanzado';
export type RoutineStatus = 'Activa' | 'Inactiva' | 'Borrador';

export interface RoutineFilters {
  searchTerm: string;
  objective: RoutineObjective | 'all';
  level: RoutineLevel | 'all';
  status: RoutineStatus | 'all';
  trainer: string | 'all';
  assignedMember: string | 'all';
}

@Component({
  selector: 'app-routines-filters',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <section class="filters">
      <div class="filter search">
        <label>Buscar</label>
        <div class="search-wrap">
          <span class="material-symbols-outlined" aria-hidden="true">search</span>
          <input
            type="text"
            class="input"
            placeholder="Buscar rutina, ejercicio, entrenador o miembro..."
            [(ngModel)]="local.searchTerm"
            (ngModelChange)="emit()"
          />
        </div>
      </div>

      <div class="filter">
        <label>Objetivo</label>
        <select class="select" [(ngModel)]="local.objective" (ngModelChange)="emit()">
          <option value="all">Todos</option>
          <option *ngFor="let o of objectives" [value]="o">{{ o }}</option>
        </select>
      </div>

      <div class="filter">
        <label>Nivel</label>
        <select class="select" [(ngModel)]="local.level" (ngModelChange)="emit()">
          <option value="all">Todos</option>
          <option *ngFor="let l of levels" [value]="l">{{ l }}</option>
        </select>
      </div>

      <div class="filter">
        <label>Estado</label>
        <select class="select" [(ngModel)]="local.status" (ngModelChange)="emit()">
          <option value="all">Todos</option>
          <option *ngFor="let s of statuses" [value]="s">{{ s }}</option>
        </select>
      </div>

      <div class="filter">
        <label>Entrenador</label>
        <select class="select" [(ngModel)]="local.trainer" (ngModelChange)="emit()">
          <option value="all">Todos</option>
          <option *ngFor="let t of trainers" [value]="t">{{ t }}</option>
        </select>
      </div>

      <div class="filter">
        <label>Asignada a</label>
        <select class="select" [(ngModel)]="local.assignedMember" (ngModelChange)="emit()">
          <option value="all">Todos</option>
          <option *ngFor="let m of members" [value]="m">{{ m }}</option>
        </select>
      </div>
    </section>
  `,
  styles: [
    `
      .filters {
        display: grid;
        grid-template-columns: 1.7fr repeat(5, minmax(0, 1fr));
        gap: 0.9rem;
        padding: 1.1rem 1.15rem;
        border-radius: 14px;
        border: 1px solid #ededed;
        background: #ffffff;
        box-shadow: 0 10px 22px rgba(0, 0, 0, 0.04);
        margin-bottom: 1.5rem;
      }

      .filter {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
        min-width: 0;
      }

      .filter label {
        font-size: 0.72rem;
        font-weight: 850;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.06em;
      }

      .input,
      .select {
        height: 42px;
        border-radius: 12px;
        border: 1px solid #e5e5e5;
        background: #fbfbfb;
        padding: 0 0.9rem;
        font-weight: 700;
        color: #0a0a0a;
        outline: none;
        transition:
          border-color 0.15s ease,
          box-shadow 0.15s ease,
          background 0.15s ease;
      }

      .input:focus,
      .select:focus {
        border-color: #fbbf24;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.12);
      }

      .search-wrap {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        padding: 0 0.8rem;
        background: #fbfbfb;
        height: 42px;
        transition:
          border-color 0.15s ease,
          box-shadow 0.15s ease,
          background 0.15s ease;
      }

      .search-wrap:focus-within {
        border-color: #fbbf24;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.12);
      }

      .search-wrap .material-symbols-outlined {
        color: #777;
        font-size: 1.1rem;
      }

      .search-wrap .input {
        border: none;
        background: transparent;
        padding: 0;
        height: 40px;
        flex: 1;
        min-width: 0;
      }

      @media (max-width: 1100px) {
        .filters {
          grid-template-columns: 1fr 1fr;
        }
      }

      @media (max-width: 640px) {
        .filters {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export default class RoutinesFiltersComponent {
  @Input({ required: true }) filters!: RoutineFilters;
  @Input() trainers: string[] = [
    'Sin asignar',
    'Carlos Ruiz',
    'Laura Gómez',
    'Andrés Martínez',
    'Camila Torres',
  ];
  @Input() members: string[] = [
    'Plantilla general',
    'Alejandro Gómez',
    'Juan Pérez',
    'María Rodríguez',
    'Carlos Martínez',
  ];
  @Output() filtersChange = new EventEmitter<RoutineFilters>();

  objectives: RoutineObjective[] = [
    'Hipertrofia',
    'Fuerza',
    'Pérdida de grasa',
    'Resistencia',
    'Funcional',
    'Rehabilitación',
    'Mantenimiento',
  ];
  levels: RoutineLevel[] = ['Principiante', 'Intermedio', 'Avanzado'];
  statuses: RoutineStatus[] = ['Activa', 'Inactiva', 'Borrador'];

  local: RoutineFilters = {
    searchTerm: '',
    objective: 'all',
    level: 'all',
    status: 'all',
    trainer: 'all',
    assignedMember: 'all',
  };

  ngOnChanges(): void {
    this.local = { ...this.filters };
  }

  emit(): void {
    this.filtersChange.emit({ ...this.local });
  }
}
