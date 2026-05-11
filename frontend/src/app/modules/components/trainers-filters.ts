import {
  Component,
  ElementRef,
  EventEmitter,
  HostListener,
  Input,
  Output,
  inject,
  signal,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

export interface TrainerFilters {
  searchTerm: string;
  status: string;
  specialty: string;
  availability: string;
  contractType: string;
}

type TrainerFilterKey = 'status' | 'specialty' | 'availability' | 'contractType';

interface TrainerFilterOption {
  value: string;
  label: string;
  description: string;
  icon: string;
}

@Component({
  selector: 'app-trainers-filters',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <section class="filters-section">
      <div class="filters-grid">
        <div class="filter-group">
          <label class="filter-label">Buscar entrenador</label>
          <input
            type="text"
            class="filter-input"
            placeholder="Nombre, correo, especialidad..."
            [(ngModel)]="localFilters.searchTerm"
            (change)="emitChanges()"
          />
        </div>

        <div class="filter-group">
          <label class="filter-label">Estado</label>
          <div class="pretty-select" [class.open]="openSelect() === 'status'">
            <button type="button" class="pretty-trigger" (click)="toggleSelect('status')">
              <span>{{ optionLabel('status') }}</span>
              <span class="select-chevron" aria-hidden="true"></span>
            </button>
            <div class="pretty-menu" *ngIf="openSelect() === 'status'">
              <button
                type="button"
                class="pretty-option"
                *ngFor="let option of statusOptions"
                [class.selected]="localFilters.status === option.value"
                (click)="chooseOption('status', option.value)"
              >
                <span class="option-main">
                  <span class="option-icon material-symbols-outlined">{{ option.icon }}</span>
                  <span class="option-copy">
                    <strong>{{ option.label }}</strong>
                    <small>{{ option.description }}</small>
                  </span>
                </span>
                <span class="option-check" aria-hidden="true"></span>
              </button>
            </div>
          </div>
        </div>

        <div class="filter-group">
          <label class="filter-label">Especialidad</label>
          <div class="pretty-select" [class.open]="openSelect() === 'specialty'">
            <button type="button" class="pretty-trigger" (click)="toggleSelect('specialty')">
              <span>{{ optionLabel('specialty') }}</span>
              <span class="select-chevron" aria-hidden="true"></span>
            </button>
            <div class="pretty-menu" *ngIf="openSelect() === 'specialty'">
              <button
                type="button"
                class="pretty-option"
                *ngFor="let option of specialtyOptions"
                [class.selected]="localFilters.specialty === option.value"
                (click)="chooseOption('specialty', option.value)"
              >
                <span class="option-main">
                  <span class="option-icon material-symbols-outlined">{{ option.icon }}</span>
                  <span class="option-copy">
                    <strong>{{ option.label }}</strong>
                    <small>{{ option.description }}</small>
                  </span>
                </span>
                <span class="option-check" aria-hidden="true"></span>
              </button>
            </div>
          </div>
        </div>

        <div class="filter-group">
          <label class="filter-label">Disponibilidad</label>
          <div class="pretty-select" [class.open]="openSelect() === 'availability'">
            <button type="button" class="pretty-trigger" (click)="toggleSelect('availability')">
              <span>{{ optionLabel('availability') }}</span>
              <span class="select-chevron" aria-hidden="true"></span>
            </button>
            <div class="pretty-menu" *ngIf="openSelect() === 'availability'">
              <button
                type="button"
                class="pretty-option"
                *ngFor="let option of availabilityOptions"
                [class.selected]="localFilters.availability === option.value"
                (click)="chooseOption('availability', option.value)"
              >
                <span class="option-main">
                  <span class="option-icon material-symbols-outlined">{{ option.icon }}</span>
                  <span class="option-copy">
                    <strong>{{ option.label }}</strong>
                    <small>{{ option.description }}</small>
                  </span>
                </span>
                <span class="option-check" aria-hidden="true"></span>
              </button>
            </div>
          </div>
        </div>

        <div class="filter-group">
          <label class="filter-label">Tipo contrato</label>
          <div class="pretty-select" [class.open]="openSelect() === 'contractType'">
            <button type="button" class="pretty-trigger" (click)="toggleSelect('contractType')">
              <span>{{ optionLabel('contractType') }}</span>
              <span class="select-chevron" aria-hidden="true"></span>
            </button>
            <div class="pretty-menu" *ngIf="openSelect() === 'contractType'">
              <button
                type="button"
                class="pretty-option"
                *ngFor="let option of contractTypeOptions"
                [class.selected]="localFilters.contractType === option.value"
                (click)="chooseOption('contractType', option.value)"
              >
                <span class="option-main">
                  <span class="option-icon material-symbols-outlined">{{ option.icon }}</span>
                  <span class="option-copy">
                    <strong>{{ option.label }}</strong>
                    <small>{{ option.description }}</small>
                  </span>
                </span>
                <span class="option-check" aria-hidden="true"></span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </section>
  `,
  styles: [
    `
      .filters-section {
        position: relative;
        z-index: 30;
        border: 1px solid #f0f0f0;
        border-radius: 16px;
        background: #ffffff;
        padding: 1.2rem;
        margin-bottom: 1.6rem;
        overflow: visible;
      }

      .filters-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 0.9rem;
      }

      .filter-group {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
        position: relative;
        min-width: 0;
      }

      .filter-label {
        font-size: 0.75rem;
        font-weight: 900;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.04em;
      }

      .filter-input {
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        padding: 0.75rem;
        font-size: 0.95rem;
        background: #ffffff;
        color: #0a0a0a;
        transition: border-color 0.15s ease;
        font-family: inherit;
      }

      .filter-input:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.1);
      }

      .filter-input::placeholder {
        color: #ccc;
      }

      .pretty-select {
        position: relative;
        width: 100%;
        min-width: 0;
      }

      .pretty-select.open {
        z-index: 80;
      }

      .pretty-trigger {
        width: 100%;
        min-height: 46px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        background: #fbfbfb;
        color: #0a0a0a;
        padding: 0 0.9rem;
        font-weight: 850;
        text-align: left;
        cursor: pointer;
        transition:
          border-color 0.15s ease,
          box-shadow 0.15s ease,
          background 0.15s ease;
      }

      .pretty-trigger > span:first-child {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .pretty-trigger:hover,
      .pretty-select.open .pretty-trigger {
        border-color: #fbbf24;
        background: #fffdf4;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.12);
      }

      .select-chevron {
        width: 0.52rem;
        height: 0.52rem;
        border-bottom: 2px solid #a16207;
        border-right: 2px solid #a16207;
        transform: rotate(45deg) translateY(-1px);
        transition: transform 160ms ease;
        flex-shrink: 0;
      }

      .pretty-select.open .select-chevron {
        transform: rotate(225deg) translateY(-1px);
      }

      .pretty-menu {
        position: absolute;
        top: calc(100% + 0.35rem);
        left: 0;
        width: max(100%, 280px);
        min-width: 250px;
        z-index: 5000;
        display: grid;
        gap: 0.2rem;
        max-height: 300px;
        overflow-y: auto;
        padding: 0.45rem;
        border: 1px solid #e4e4e7;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: 0 18px 42px rgba(0, 0, 0, 0.18);
        animation: selectIn 140ms ease;
      }

      .filter-group:nth-last-child(-n + 2) .pretty-menu {
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
        border-radius: 9px;
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

      @media (max-width: 1200px) {
        .filters-grid {
          grid-template-columns: repeat(3, minmax(0, 1fr));
        }
      }

      @media (max-width: 768px) {
        .filters-grid {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }

      @media (max-width: 480px) {
        .filters-grid {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export default class TrainersFiltersComponent {
  private readonly elementRef = inject(ElementRef<HTMLElement>);

  @Input() filters: TrainerFilters = {
    searchTerm: '',
    status: 'all',
    specialty: 'all',
    availability: 'all',
    contractType: 'all',
  };

  @Output() filtersChange = new EventEmitter<TrainerFilters>();

  localFilters: TrainerFilters = { ...this.filters };
  openSelect = signal<TrainerFilterKey | null>(null);

  readonly statusOptions: TrainerFilterOption[] = [
    { value: 'all', label: 'Todos', description: 'Todos los estados', icon: 'select_all' },
    { value: 'Activo', label: 'Activo', description: 'Entrenadores disponibles', icon: 'check_circle' },
    { value: 'Inactivo', label: 'Inactivo', description: 'Fuera de operación', icon: 'pause_circle' },
    { value: 'Pendiente', label: 'Pendiente', description: 'Por revisar o activar', icon: 'schedule' },
  ];

  readonly specialtyOptions: TrainerFilterOption[] = [
    { value: 'all', label: 'Todas', description: 'Sin filtrar especialidad', icon: 'select_all' },
    { value: 'Musculación', label: 'Musculación', description: 'Fuerza e hipertrofia', icon: 'fitness_center' },
    { value: 'Funcional', label: 'Funcional', description: 'Movimiento y rendimiento', icon: 'directions_run' },
    { value: 'Spinning', label: 'Spinning', description: 'Ciclismo indoor', icon: 'pedal_bike' },
    { value: 'Cross Training', label: 'Cross Training', description: 'Alta intensidad', icon: 'exercise' },
    { value: 'Yoga', label: 'Yoga', description: 'Movilidad y respiración', icon: 'self_improvement' },
    { value: 'Pilates', label: 'Pilates', description: 'Control y estabilidad', icon: 'accessibility_new' },
    { value: 'Boxeo', label: 'Boxeo', description: 'Técnica y cardio', icon: 'sports_mma' },
    { value: 'Cardio', label: 'Cardio', description: 'Resistencia cardiovascular', icon: 'monitor_heart' },
    { value: 'Rehabilitación', label: 'Rehabilitación', description: 'Recuperación guiada', icon: 'healing' },
    {
      value: 'Entrenamiento personalizado',
      label: 'Entrenamiento personalizado',
      description: 'Planes uno a uno',
      icon: 'person_check',
    },
  ];

  readonly availabilityOptions: TrainerFilterOption[] = [
    { value: 'all', label: 'Todas', description: 'Cualquier disponibilidad', icon: 'select_all' },
    { value: 'Disponible', label: 'Disponible', description: 'Con horario activo', icon: 'event_available' },
    { value: 'Ocupado', label: 'Ocupado', description: 'Con agenda comprometida', icon: 'event_busy' },
    { value: 'Sin horario', label: 'Sin horario configurado', description: 'Debe configurar agenda', icon: 'calendar_today' },
  ];

  readonly contractTypeOptions: TrainerFilterOption[] = [
    { value: 'all', label: 'Todos', description: 'Todos los contratos', icon: 'select_all' },
    { value: 'Tiempo completo', label: 'Tiempo completo', description: 'Jornada completa', icon: 'badge' },
    { value: 'Medio tiempo', label: 'Medio tiempo', description: 'Jornada parcial', icon: 'schedule' },
    { value: 'Por horas', label: 'Por horas', description: 'Pago por sesión u hora', icon: 'timer' },
    { value: 'Independiente', label: 'Independiente', description: 'Contrato externo', icon: 'work' },
  ];

  ngOnInit(): void {
    this.localFilters = { ...this.filters };
  }

  ngOnChanges(): void {
    this.localFilters = { ...this.filters };
  }

  @HostListener('document:click', ['$event'])
  closeSelectOnOutsideClick(event: MouseEvent): void {
    if (!this.openSelect()) return;
    if (!this.elementRef.nativeElement.contains(event.target as Node)) {
      this.openSelect.set(null);
    }
  }

  emitChanges(): void {
    this.filtersChange.emit(this.localFilters);
  }

  toggleSelect(select: TrainerFilterKey): void {
    this.openSelect.update((current) => (current === select ? null : select));
  }

  chooseOption(key: TrainerFilterKey, value: string): void {
    this.localFilters = {
      ...this.localFilters,
      [key]: value,
    };
    this.openSelect.set(null);
    this.emitChanges();
  }

  optionLabel(key: TrainerFilterKey): string {
    const value = this.localFilters[key];
    return this.optionsFor(key).find((option) => option.value === value)?.label || 'Seleccionar';
  }

  private optionsFor(key: TrainerFilterKey): TrainerFilterOption[] {
    if (key === 'status') return this.statusOptions;
    if (key === 'specialty') return this.specialtyOptions;
    if (key === 'availability') return this.availabilityOptions;
    return this.contractTypeOptions;
  }
}
