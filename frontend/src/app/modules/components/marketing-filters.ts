import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, Output } from '@angular/core';
import { FormsModule } from '@angular/forms';

export interface MarketingFilters {
  searchTerm: string;
  status: string;
  type: string;
  channel: string;
  segment: string;
  dateRange: string;
}

@Component({
  selector: 'app-marketing-filters',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="filters">
      <div class="filter-group">
        <label for="search">Buscar campaña</label>
        <input
          type="text"
          id="search"
          [(ngModel)]="localFilters.searchTerm"
          (input)="onFiltersChange()"
          placeholder="Nombre, código, segmento…"
          class="filter-input"
        />
      </div>

      <div class="filter-group">
        <label for="status">Estado</label>
        <select
          id="status"
          [(ngModel)]="localFilters.status"
          (change)="onFiltersChange()"
          class="filter-select"
        >
          <option value="all">Todos</option>
          <option value="Borrador">Borrador</option>
          <option value="Programada">Programada</option>
          <option value="Activa">Activa</option>
          <option value="Pausada">Pausada</option>
          <option value="Finalizada">Finalizada</option>
        </select>
      </div>

      <div class="filter-group">
        <label for="type">Tipo</label>
        <select
          id="type"
          [(ngModel)]="localFilters.type"
          (change)="onFiltersChange()"
          class="filter-select"
        >
          <option value="all">Todos</option>
          <option value="Promoción">Promoción</option>
          <option value="Descuento">Descuento</option>
          <option value="Renovación">Renovación</option>
          <option value="Reactivación">Reactivación</option>
          <option value="Cumpleaños">Cumpleaños</option>
          <option value="Referidos">Referidos</option>
          <option value="Clase especial">Clase especial</option>
          <option value="Evento">Evento</option>
          <option value="Comunicación general">Comunicación general</option>
        </select>
      </div>

      <div class="filter-group">
        <label for="channel">Canal</label>
        <select
          id="channel"
          [(ngModel)]="localFilters.channel"
          (change)="onFiltersChange()"
          class="filter-select"
        >
          <option value="all">Todos</option>
          <option value="WhatsApp">WhatsApp</option>
          <option value="Correo electrónico">Correo electrónico</option>
          <option value="SMS">SMS</option>
          <option value="Notificación interna">Notificación interna</option>
          <option value="Redes sociales">Redes sociales</option>
        </select>
      </div>

      <div class="filter-group">
        <label for="segment">Segmento</label>
        <select
          id="segment"
          [(ngModel)]="localFilters.segment"
          (change)="onFiltersChange()"
          class="filter-select"
        >
          <option value="all">Todos</option>
          <option value="Todos los miembros">Todos los miembros</option>
          <option value="Miembros activos">Miembros activos</option>
          <option value="Miembros inactivos">Miembros inactivos</option>
          <option value="Membresías por vencer">Membresías por vencer</option>
          <option value="Membresías vencidas">Membresías vencidas</option>
          <option value="Nuevos miembros">Nuevos miembros</option>
          <option value="Miembros VIP">Miembros VIP</option>
          <option value="Leads">Leads</option>
        </select>
      </div>

      <div class="filter-group">
        <label for="dateRange">Rango de fechas</label>
        <select
          id="dateRange"
          [(ngModel)]="localFilters.dateRange"
          (change)="onFiltersChange()"
          class="filter-select"
        >
          <option value="all">Todos</option>
          <option value="Activas">Activas hoy</option>
          <option value="Esta semana">Esta semana</option>
          <option value="Este mes">Este mes</option>
          <option value="Este trimestre">Este trimestre</option>
        </select>
      </div>
    </div>
  `,
  styles: [
    `
      .filters {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 0.9rem;
        margin-bottom: 1.6rem;
      }

      .filter-group {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
      }

      label {
        font-size: 0.8rem;
        font-weight: 700;
        color: #0a0a0a;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }

      .filter-input,
      .filter-select {
        padding: 0.7rem 0.9rem;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        background: #ffffff;
        color: #0a0a0a;
        font-size: 0.92rem;
        font-weight: 500;
        transition: all 0.15s ease;
      }

      .filter-input:focus,
      .filter-select:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.1);
      }

      .filter-input::placeholder {
        color: #bbb;
      }

      @media (max-width: 1400px) {
        .filters {
          grid-template-columns: repeat(4, minmax(0, 1fr));
        }
      }

      @media (max-width: 900px) {
        .filters {
          grid-template-columns: repeat(3, minmax(0, 1fr));
        }
      }

      @media (max-width: 640px) {
        .filters {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }
    `,
  ],
})
export default class MarketingFiltersComponent {
  @Input() filters: MarketingFilters = {
    searchTerm: '',
    status: 'all',
    type: 'all',
    channel: 'all',
    segment: 'all',
    dateRange: 'all',
  };

  @Output() filtersChange = new EventEmitter<MarketingFilters>();

  localFilters: MarketingFilters = { ...this.filters };

  ngOnInit(): void {
    this.localFilters = { ...this.filters };
  }

  onFiltersChange(): void {
    this.filtersChange.emit(this.localFilters);
  }
}
