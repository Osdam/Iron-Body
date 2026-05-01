import { Component, EventEmitter, Input, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

export interface TrainerFilters {
  searchTerm: string;
  status: string;
  specialty: string;
  availability: string;
  contractType: string;
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
          <select class="filter-select" [(ngModel)]="localFilters.status" (change)="emitChanges()">
            <option value="all">Todos</option>
            <option value="Activo">Activo</option>
            <option value="Inactivo">Inactivo</option>
            <option value="Pendiente">Pendiente</option>
          </select>
        </div>

        <div class="filter-group">
          <label class="filter-label">Especialidad</label>
          <select
            class="filter-select"
            [(ngModel)]="localFilters.specialty"
            (change)="emitChanges()"
          >
            <option value="all">Todas</option>
            <option value="Musculación">Musculación</option>
            <option value="Funcional">Funcional</option>
            <option value="Spinning">Spinning</option>
            <option value="Cross Training">Cross Training</option>
            <option value="Yoga">Yoga</option>
            <option value="Pilates">Pilates</option>
            <option value="Boxeo">Boxeo</option>
            <option value="Cardio">Cardio</option>
            <option value="Rehabilitación">Rehabilitación</option>
            <option value="Entrenamiento personalizado">Entrenamiento personalizado</option>
          </select>
        </div>

        <div class="filter-group">
          <label class="filter-label">Disponibilidad</label>
          <select
            class="filter-select"
            [(ngModel)]="localFilters.availability"
            (change)="emitChanges()"
          >
            <option value="all">Todas</option>
            <option value="Disponible">Disponible</option>
            <option value="Ocupado">Ocupado</option>
            <option value="Sin horario">Sin horario configurado</option>
          </select>
        </div>

        <div class="filter-group">
          <label class="filter-label">Tipo contrato</label>
          <select
            class="filter-select"
            [(ngModel)]="localFilters.contractType"
            (change)="emitChanges()"
          >
            <option value="all">Todos</option>
            <option value="Tiempo completo">Tiempo completo</option>
            <option value="Medio tiempo">Medio tiempo</option>
            <option value="Por horas">Por horas</option>
            <option value="Independiente">Independiente</option>
          </select>
        </div>
      </div>
    </section>
  `,
  styles: [
    `
      .filters-section {
        border: 1px solid #f0f0f0;
        border-radius: 16px;
        background: #ffffff;
        padding: 1.2rem;
        margin-bottom: 1.6rem;
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
      }

      .filter-label {
        font-size: 0.75rem;
        font-weight: 900;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.04em;
      }

      .filter-input,
      .filter-select {
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        padding: 0.75rem;
        font-size: 0.95rem;
        background: #ffffff;
        color: #0a0a0a;
        transition: border-color 0.15s ease;
        font-family: inherit;
      }

      .filter-input:focus,
      .filter-select:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.1);
      }

      .filter-input::placeholder {
        color: #ccc;
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
  @Input() filters: TrainerFilters = {
    searchTerm: '',
    status: 'all',
    specialty: 'all',
    availability: 'all',
    contractType: 'all',
  };

  @Output() filtersChange = new EventEmitter<TrainerFilters>();

  localFilters: TrainerFilters = { ...this.filters };

  ngOnInit(): void {
    this.localFilters = { ...this.filters };
  }

  ngOnChanges(): void {
    this.localFilters = { ...this.filters };
  }

  emitChanges(): void {
    this.filtersChange.emit(this.localFilters);
  }
}
