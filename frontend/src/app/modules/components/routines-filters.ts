import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, Output, signal } from '@angular/core';
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
        <div class="pretty-select" [class.open]="openSelect() === 'objective'">
          <button type="button" class="pretty-trigger" (click)="toggleSelect('objective')">
            <span>{{ filterLabel('objective') }}</span>
            <span class="select-chevron" aria-hidden="true"></span>
          </button>
          <div *ngIf="openSelect() === 'objective'" class="pretty-menu">
            <button type="button" class="pretty-option" [class.selected]="local.objective === 'all'" (click)="chooseFilter('objective', 'all')">
              <span class="option-main">
                <span class="option-icon material-symbols-outlined" aria-hidden="true">apps</span>
                <span class="option-copy"><strong>Todos</strong><small>Sin filtrar objetivo</small></span>
              </span>
              <span class="option-check" aria-hidden="true"></span>
            </button>
            <button type="button" class="pretty-option" *ngFor="let o of objectives" [class.selected]="local.objective === o" (click)="chooseFilter('objective', o)">
              <span class="option-main">
                <span class="option-icon material-symbols-outlined" aria-hidden="true">flag</span>
                <span class="option-copy"><strong>{{ o }}</strong><small>{{ optionHint(o) }}</small></span>
              </span>
              <span class="option-check" aria-hidden="true"></span>
            </button>
          </div>
        </div>
      </div>

      <div class="filter">
        <label>Nivel</label>
        <div class="pretty-select" [class.open]="openSelect() === 'level'">
          <button type="button" class="pretty-trigger" (click)="toggleSelect('level')">
            <span>{{ filterLabel('level') }}</span>
            <span class="select-chevron" aria-hidden="true"></span>
          </button>
          <div *ngIf="openSelect() === 'level'" class="pretty-menu">
            <button type="button" class="pretty-option" [class.selected]="local.level === 'all'" (click)="chooseFilter('level', 'all')">
              <span class="option-main">
                <span class="option-icon material-symbols-outlined" aria-hidden="true">apps</span>
                <span class="option-copy"><strong>Todos</strong><small>Sin filtrar nivel</small></span>
              </span>
              <span class="option-check" aria-hidden="true"></span>
            </button>
            <button type="button" class="pretty-option" *ngFor="let l of levels" [class.selected]="local.level === l" (click)="chooseFilter('level', l)">
              <span class="option-main">
                <span class="option-icon material-symbols-outlined" aria-hidden="true">speed</span>
                <span class="option-copy"><strong>{{ l }}</strong><small>{{ optionHint(l) }}</small></span>
              </span>
              <span class="option-check" aria-hidden="true"></span>
            </button>
          </div>
        </div>
      </div>

      <div class="filter">
        <label>Estado</label>
        <div class="pretty-select" [class.open]="openSelect() === 'status'">
          <button type="button" class="pretty-trigger" (click)="toggleSelect('status')">
            <span>{{ filterLabel('status') }}</span>
            <span class="select-chevron" aria-hidden="true"></span>
          </button>
          <div *ngIf="openSelect() === 'status'" class="pretty-menu">
            <button type="button" class="pretty-option" [class.selected]="local.status === 'all'" (click)="chooseFilter('status', 'all')">
              <span class="option-main">
                <span class="option-icon material-symbols-outlined" aria-hidden="true">apps</span>
                <span class="option-copy"><strong>Todos</strong><small>Sin filtrar estado</small></span>
              </span>
              <span class="option-check" aria-hidden="true"></span>
            </button>
            <button type="button" class="pretty-option" *ngFor="let s of statuses" [class.selected]="local.status === s" (click)="chooseFilter('status', s)">
              <span class="option-main">
                <span class="option-icon material-symbols-outlined" aria-hidden="true">task_alt</span>
                <span class="option-copy"><strong>{{ s }}</strong><small>{{ optionHint(s) }}</small></span>
              </span>
              <span class="option-check" aria-hidden="true"></span>
            </button>
          </div>
        </div>
      </div>

      <div class="filter">
        <label>Entrenador</label>
        <div class="pretty-select" [class.open]="openSelect() === 'trainer'">
          <button type="button" class="pretty-trigger" (click)="toggleSelect('trainer')">
            <span>{{ filterLabel('trainer') }}</span>
            <span class="select-chevron" aria-hidden="true"></span>
          </button>
          <div *ngIf="openSelect() === 'trainer'" class="pretty-menu">
            <button type="button" class="pretty-option" [class.selected]="local.trainer === 'all'" (click)="chooseFilter('trainer', 'all')">
              <span class="option-main">
                <span class="option-icon material-symbols-outlined" aria-hidden="true">apps</span>
                <span class="option-copy"><strong>Todos</strong><small>Todos los entrenadores</small></span>
              </span>
              <span class="option-check" aria-hidden="true"></span>
            </button>
            <button type="button" class="pretty-option" *ngFor="let t of trainers" [class.selected]="local.trainer === t" (click)="chooseFilter('trainer', t)">
              <span class="option-main">
                <span class="option-icon material-symbols-outlined" aria-hidden="true">badge</span>
                <span class="option-copy"><strong>{{ t }}</strong><small>{{ t === 'Sin asignar' ? 'Sin entrenador asignado' : 'Entrenador responsable' }}</small></span>
              </span>
              <span class="option-check" aria-hidden="true"></span>
            </button>
          </div>
        </div>
      </div>

      <div class="filter">
        <label>Asignada a</label>
        <div class="pretty-select" [class.open]="openSelect() === 'assignedMember'">
          <button type="button" class="pretty-trigger" (click)="toggleSelect('assignedMember')">
            <span>{{ filterLabel('assignedMember') }}</span>
            <span class="select-chevron" aria-hidden="true"></span>
          </button>
          <div *ngIf="openSelect() === 'assignedMember'" class="pretty-menu">
            <button type="button" class="pretty-option" [class.selected]="local.assignedMember === 'all'" (click)="chooseFilter('assignedMember', 'all')">
              <span class="option-main">
                <span class="option-icon material-symbols-outlined" aria-hidden="true">apps</span>
                <span class="option-copy"><strong>Todos</strong><small>Todas las asignaciones</small></span>
              </span>
              <span class="option-check" aria-hidden="true"></span>
            </button>
            <button type="button" class="pretty-option" *ngFor="let m of members" [class.selected]="local.assignedMember === m" (click)="chooseFilter('assignedMember', m)">
              <span class="option-main">
                <span class="option-icon material-symbols-outlined" aria-hidden="true">person</span>
                <span class="option-copy"><strong>{{ m }}</strong><small>{{ m === 'Plantilla general' ? 'Rutina reutilizable' : 'Miembro asignado' }}</small></span>
              </span>
              <span class="option-check" aria-hidden="true"></span>
            </button>
          </div>
        </div>
      </div>
    </section>
  `,
  styles: [
    `
      .filters {
        position: relative;
        z-index: 20;
        display: grid;
        grid-template-columns: 1.7fr repeat(5, minmax(0, 1fr));
        gap: 0.9rem;
        padding: 1.1rem 1.15rem;
        border-radius: 14px;
        border: 1px solid #ededed;
        background: #ffffff;
        box-shadow: 0 10px 22px rgba(0, 0, 0, 0.04);
        margin-bottom: 1.5rem;
        overflow: visible;
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

      .pretty-select {
        position: relative;
        width: 100%;
        z-index: 1;
      }

      .pretty-select.open {
        z-index: 40;
      }

      .pretty-trigger {
        width: 100%;
        height: 42px;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 0.5rem;
        border-radius: 12px;
        border: 1px solid #e5e5e5;
        background: #fbfbfb;
        color: #0a0a0a;
        padding: 0 0.9rem;
        font-weight: 800;
        text-align: left;
        cursor: pointer;
        transition:
          border-color 0.15s ease,
          box-shadow 0.15s ease,
          background 0.15s ease;
      }

      .pretty-trigger > span:first-child {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .select-chevron {
        width: 0.52rem;
        height: 0.52rem;
        border-bottom: 2px solid #a16207;
        border-right: 2px solid #a16207;
        transform: rotate(45deg) translateY(-1px);
        transition: transform 160ms ease;
      }

      .pretty-select.open .pretty-trigger,
      .pretty-trigger:hover {
        border-color: #fbbf24;
        background: #fffdf4;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.12);
      }

      .pretty-select.open .select-chevron {
        transform: rotate(225deg) translateY(-1px);
      }

      .pretty-menu {
        position: absolute;
        top: calc(100% + 0.35rem);
        left: 0;
        right: auto;
        width: max(100%, 250px);
        min-width: 250px;
        z-index: 5000;
        display: grid;
        gap: 0.2rem;
        max-height: 260px;
        overflow-y: auto;
        padding: 0.45rem;
        border: 1px solid #e4e4e7;
        border-radius: 10px;
        background: #ffffff;
        box-shadow: 0 18px 42px rgba(0, 0, 0, 0.18);
        animation: selectIn 140ms ease;
      }

      .filter:nth-last-child(-n + 2) .pretty-menu {
        left: auto;
        right: 0;
      }

      @keyframes selectIn {
        from {
          opacity: 0;
          transform: translateY(-4px) scale(0.98);
        }
        to {
          opacity: 1;
          transform: translateY(0) scale(1);
        }
      }

      .pretty-option {
        min-height: 3.35rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.85rem;
        border: 0;
        border-radius: 8px;
        background: transparent;
        color: #3f3f46;
        text-align: left;
        padding: 0.62rem 0.7rem;
        cursor: pointer;
        transition:
          background 140ms ease,
          color 140ms ease,
          transform 140ms ease;
      }

      .pretty-option:hover {
        background: #fffbeb;
        color: #18181b;
        transform: translateY(-1px);
      }

      .pretty-option.selected {
        background: rgba(250, 204, 21, 0.18);
        color: #111827;
      }

      .option-main {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        min-width: 0;
      }

      .option-icon {
        width: 2rem;
        height: 2rem;
        display: grid;
        place-items: center;
        border-radius: 8px;
        background: #f4f4f5;
        color: #a16207;
        flex-shrink: 0;
        font-size: 1.12rem;
      }

      .pretty-option.selected .option-icon {
        background: #facc15;
        color: #111827;
      }

      .option-copy {
        display: grid;
        gap: 0.12rem;
        min-width: 0;
      }

      .option-copy strong {
        color: inherit;
        font-weight: 900;
        font-size: 0.9rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .option-copy small {
        color: #71717a;
        font-weight: 650;
        font-size: 0.75rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .pretty-option.selected .option-copy small {
        color: #854d0e;
      }

      .option-check {
        width: 1.15rem;
        height: 1.15rem;
        position: relative;
        display: block;
        border: 2px solid transparent;
        border-radius: 999px;
        flex-shrink: 0;
      }

      .pretty-option.selected .option-check {
        border-color: #ca8a04;
        background: #ca8a04;
      }

      .pretty-option.selected .option-check::after {
        content: '';
        position: absolute;
        left: 0.31rem;
        top: 0.16rem;
        width: 0.3rem;
        height: 0.58rem;
        border: solid #ffffff;
        border-width: 0 2px 2px 0;
        transform: rotate(45deg);
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

      .filters {
        background: #1c1b1b;
        border-color: #353534;
        box-shadow: 0 14px 32px rgba(0, 0, 0, 0.22);
      }

      .filter label {
        color: #b4afa6;
      }

      .input,
      .select,
      .pretty-trigger,
      .search-wrap {
        background: #151515;
        border-color: #353534;
        color: #e5e2e1;
      }

      .input::placeholder {
        color: #77716a;
      }

      .input:focus,
      .select:focus,
      .pretty-select.open .pretty-trigger,
      .pretty-trigger:hover,
      .search-wrap:focus-within {
        background: #201f1f;
        border-color: #f5c518;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.13);
      }

      .pretty-menu {
        background: #151515;
        border-color: #3f3d39;
        box-shadow: 0 22px 54px rgba(0, 0, 0, 0.48);
      }

      .pretty-option {
        color: #e5e2e1;
      }

      .pretty-option:hover,
      .pretty-option.selected {
        background: rgba(245, 197, 24, 0.13);
        color: #ffe08b;
      }

      .option-icon,
      .pretty-option.selected .option-icon {
        background: rgba(245, 197, 24, 0.14);
        color: #ffe08b;
      }

      .option-copy small,
      .pretty-option.selected .option-copy small {
        color: #b4afa6;
      }

      .select-chevron {
        border-color: #f5c518;
      }

      .pretty-option.selected .option-check {
        border-color: #f5c518;
        background: #f5c518;
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
  @Input() trainers: string[] = ['Sin asignar'];
  @Input() members: string[] = [
    'Plantilla general',
    'Alejandro Gómez',
    'Juan Pérez',
    'María Rodríguez',
    'Carlos Martínez',
  ];
  @Output() filtersChange = new EventEmitter<RoutineFilters>();
  openSelect = signal<keyof Omit<RoutineFilters, 'searchTerm'> | null>(null);

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

  toggleSelect(select: keyof Omit<RoutineFilters, 'searchTerm'>): void {
    this.openSelect.update((current) => (current === select ? null : select));
  }

  chooseFilter(field: keyof Omit<RoutineFilters, 'searchTerm'>, value: string): void {
    this.local = { ...this.local, [field]: value } as RoutineFilters;
    this.openSelect.set(null);
    this.emit();
  }

  filterLabel(field: keyof Omit<RoutineFilters, 'searchTerm'>): string {
    const value = String(this.local[field] || 'all');
    return value === 'all' ? 'Todos' : value;
  }

  optionHint(value: string): string {
    const hints: Record<string, string> = {
      Hipertrofia: 'Aumento de masa muscular',
      Fuerza: 'Trabajo de cargas y progresión',
      'Pérdida de grasa': 'Enfoque metabólico y adherencia',
      Resistencia: 'Capacidad cardiovascular y muscular',
      Funcional: 'Movimiento, potencia y coordinación',
      Rehabilitación: 'Progresión controlada',
      Mantenimiento: 'Conservar hábitos y condición',
      Principiante: 'Base técnica y adaptación',
      Intermedio: 'Progresión estructurada',
      Avanzado: 'Mayor volumen e intensidad',
      Activa: 'Disponible para seguimiento',
      Inactiva: 'Pausada temporalmente',
      Borrador: 'Pendiente por completar',
    };

    return hints[value] || 'Filtrar rutinas';
  }

  emit(): void {
    this.filtersChange.emit({ ...this.local });
  }
}
