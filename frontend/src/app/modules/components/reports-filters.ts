import { Component, Output, EventEmitter, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { DateWheelPickerComponent } from '../../shared/components/date-wheel-picker/date-wheel-picker.component';

export interface ReportsFilter {
  dateRange: 'today' | 'week' | 'month' | 'thisMonth' | 'lastMonth' | 'custom';
  reportType:
    | 'general'
    | 'income'
    | 'memberships'
    | 'members'
    | 'payments'
    | 'classes'
    | 'attendance';
  plan: 'all' | 'monthly' | 'quarterly' | 'biannual' | 'annual' | 'vip';
  status: 'all' | 'active' | 'inactive' | 'expired' | 'pending';
  startDate?: string;
  endDate?: string;
}

@Component({
  selector: 'app-reports-filters',
  standalone: true,
  imports: [CommonModule, FormsModule, DateWheelPickerComponent],
  template: `
    <div class="filters-container">
      <div class="filters-grid">
        <!-- Date Range -->
        <div class="filter-group">
          <label class="filter-label">Rango de fechas</label>
          <select
            [(ngModel)]="currentFilter.dateRange"
            (change)="onFilterChange()"
            class="filter-select"
          >
            <option value="today">Hoy</option>
            <option value="week">Últimos 7 días</option>
            <option value="month">Últimos 30 días</option>
            <option value="thisMonth">Este mes</option>
            <option value="lastMonth">Mes anterior</option>
            <option value="custom">Personalizado</option>
          </select>
        </div>

        <!-- Custom date range (if needed) -->
        <div *ngIf="currentFilter.dateRange === 'custom'" class="filter-group">
          <label class="filter-label">Desde</label>
          <app-date-wheel-picker
            [(ngModel)]="currentFilter.startDate"
            (dateChange)="onFilterChange()"
            [minYear]="currentYear - 5"
            [maxYear]="currentYear + 1"
            size="sm"
            ariaLabel="Fecha inicial del reporte"
          ></app-date-wheel-picker>
        </div>

        <div *ngIf="currentFilter.dateRange === 'custom'" class="filter-group">
          <label class="filter-label">Hasta</label>
          <app-date-wheel-picker
            [(ngModel)]="currentFilter.endDate"
            (dateChange)="onFilterChange()"
            [minYear]="currentYear - 5"
            [maxYear]="currentYear + 1"
            size="sm"
            ariaLabel="Fecha final del reporte"
          ></app-date-wheel-picker>
        </div>

        <!-- Report Type -->
        <div class="filter-group">
          <label class="filter-label">Tipo de reporte</label>
          <select
            [(ngModel)]="currentFilter.reportType"
            (change)="onFilterChange()"
            class="filter-select"
          >
            <option value="general">General</option>
            <option value="income">Ingresos</option>
            <option value="memberships">Membresías</option>
            <option value="members">Miembros</option>
            <option value="payments">Pagos</option>
            <option value="classes">Clases</option>
            <option value="attendance">Asistencia</option>
          </select>
        </div>

        <!-- Plan -->
        <div class="filter-group">
          <label class="filter-label">Plan</label>
          <select
            [(ngModel)]="currentFilter.plan"
            (change)="onFilterChange()"
            class="filter-select"
          >
            <option value="all">Todos</option>
            <option value="monthly">Plan Mensual</option>
            <option value="quarterly">Plan Trimestral</option>
            <option value="biannual">Plan Semestral</option>
            <option value="annual">Plan Anual</option>
            <option value="vip">Plan VIP</option>
          </select>
        </div>

        <!-- Status -->
        <div class="filter-group">
          <label class="filter-label">Estado</label>
          <select
            [(ngModel)]="currentFilter.status"
            (change)="onFilterChange()"
            class="filter-select"
          >
            <option value="all">Todos</option>
            <option value="active">Activos</option>
            <option value="inactive">Inactivos</option>
            <option value="expired">Vencidos</option>
            <option value="pending">Pendientes</option>
          </select>
        </div>
      </div>
    </div>
  `,
  styles: [
    `
      .filters-container {
        background: #ffffff;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 2rem;
      }

      .filters-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
      }

      .filter-group {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
      }

      .filter-label {
        font-size: 0.875rem;
        font-weight: 500;
        color: #333;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }

      .filter-select,
      .filter-input {
        padding: 0.75rem;
        border: 1px solid #d0d0d0;
        border-radius: 8px;
        font-size: 0.95rem;
        color: #0a0a0a;
        background: #ffffff;
        transition: all 0.2s ease;
      }

      .filter-select:hover,
      .filter-input:hover {
        border-color: #b0b0b0;
      }

      .filter-select:focus,
      .filter-input:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.1);
      }

      @media (max-width: 768px) {
        .filters-grid {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export default class ReportsFiltersComponent {
  @Input() filter: ReportsFilter = {
    dateRange: 'month',
    reportType: 'general',
    plan: 'all',
    status: 'all',
  };

  @Output() filterChange = new EventEmitter<ReportsFilter>();

  currentFilter: ReportsFilter = { ...this.filter };
  currentYear = new Date().getFullYear();

  ngOnInit() {
    this.currentFilter = { ...this.filter };
  }

  onFilterChange() {
    this.filterChange.emit({ ...this.currentFilter });
  }
}
