import {
  Component,
  Output,
  EventEmitter,
  Input,
  ElementRef,
  HostListener,
  OnChanges,
  OnInit,
  SimpleChanges,
  inject,
  signal,
} from '@angular/core';
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

type ReportFilterKey = 'dateRange' | 'reportType' | 'plan' | 'status';

interface ReportFilterOption {
  value: string;
  label: string;
  description: string;
  icon: string;
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
          <div class="pretty-select" [class.open]="openSelect() === 'dateRange'">
            <button type="button" class="pretty-trigger" (click)="toggleSelect('dateRange')">
              <span>{{ optionLabel('dateRange') }}</span>
              <span class="select-chevron" aria-hidden="true"></span>
            </button>
            <div class="pretty-menu" *ngIf="openSelect() === 'dateRange'">
              <button
                type="button"
                class="pretty-option"
                *ngFor="let option of dateRangeOptions"
                [class.selected]="currentFilter.dateRange === option.value"
                (click)="chooseOption('dateRange', option.value)"
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
          <div class="pretty-select" [class.open]="openSelect() === 'reportType'">
            <button type="button" class="pretty-trigger" (click)="toggleSelect('reportType')">
              <span>{{ optionLabel('reportType') }}</span>
              <span class="select-chevron" aria-hidden="true"></span>
            </button>
            <div class="pretty-menu" *ngIf="openSelect() === 'reportType'">
              <button
                type="button"
                class="pretty-option"
                *ngFor="let option of reportTypeOptions"
                [class.selected]="currentFilter.reportType === option.value"
                (click)="chooseOption('reportType', option.value)"
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

        <!-- Plan -->
        <div class="filter-group">
          <label class="filter-label">Plan</label>
          <div class="pretty-select" [class.open]="openSelect() === 'plan'">
            <button type="button" class="pretty-trigger" (click)="toggleSelect('plan')">
              <span>{{ optionLabel('plan') }}</span>
              <span class="select-chevron" aria-hidden="true"></span>
            </button>
            <div class="pretty-menu" *ngIf="openSelect() === 'plan'">
              <button
                type="button"
                class="pretty-option"
                *ngFor="let option of planOptions"
                [class.selected]="currentFilter.plan === option.value"
                (click)="chooseOption('plan', option.value)"
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

        <!-- Status -->
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
                [class.selected]="currentFilter.status === option.value"
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
      </div>
    </div>
  `,
  styles: [
    `
      .filters-container {
        position: relative;
        z-index: 30;
        background: #ffffff;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        overflow: visible;
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
        position: relative;
        min-width: 0;
      }

      .filter-label {
        font-size: 0.875rem;
        font-weight: 500;
        color: #333;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }

      .filter-input {
        padding: 0.75rem;
        border: 1px solid #d0d0d0;
        border-radius: 8px;
        font-size: 0.95rem;
        color: #0a0a0a;
        background: #ffffff;
        transition: all 0.2s ease;
      }

      .filter-input:hover {
        border-color: #b0b0b0;
      }

      .filter-input:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.1);
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
        height: 46px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
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
        width: max(100%, 270px);
        min-width: 250px;
        z-index: 5000;
        display: grid;
        gap: 0.2rem;
        max-height: 280px;
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

      @media (max-width: 768px) {
        .filters-grid {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export default class ReportsFiltersComponent implements OnChanges, OnInit {
  private readonly elementRef = inject(ElementRef<HTMLElement>);
  @Input() filter: ReportsFilter = {
    dateRange: 'month',
    reportType: 'general',
    plan: 'all',
    status: 'all',
  };

  @Output() filterChange = new EventEmitter<ReportsFilter>();

  currentFilter: ReportsFilter = { ...this.filter };
  currentYear = new Date().getFullYear();
  openSelect = signal<ReportFilterKey | null>(null);

  readonly dateRangeOptions: ReportFilterOption[] = [
    { value: 'today', label: 'Hoy', description: 'Solo datos del día actual', icon: 'today' },
    { value: 'week', label: 'Últimos 7 días', description: 'Actividad de la última semana', icon: 'date_range' },
    { value: 'month', label: 'Últimos 30 días', description: 'Resumen móvil del último mes', icon: 'calendar_month' },
    { value: 'thisMonth', label: 'Este mes', description: 'Desde el inicio del mes actual', icon: 'event_available' },
    { value: 'lastMonth', label: 'Mes anterior', description: 'Periodo mensual anterior', icon: 'history' },
    { value: 'custom', label: 'Personalizado', description: 'Elige fecha inicial y final', icon: 'edit_calendar' },
  ];

  readonly reportTypeOptions: ReportFilterOption[] = [
    { value: 'general', label: 'General', description: 'Vista completa del gimnasio', icon: 'dashboard' },
    { value: 'income', label: 'Ingresos', description: 'Ventas y facturación', icon: 'payments' },
    { value: 'memberships', label: 'Membresías', description: 'Planes y renovaciones', icon: 'workspace_premium' },
    { value: 'members', label: 'Miembros', description: 'Altas, estados y actividad', icon: 'groups' },
    { value: 'payments', label: 'Pagos', description: 'Pagados, pendientes y fallidos', icon: 'receipt_long' },
    { value: 'classes', label: 'Clases', description: 'Sesiones y ocupación', icon: 'fitness_center' },
    { value: 'attendance', label: 'Asistencia', description: 'Visitas y frecuencia', icon: 'fact_check' },
  ];

  readonly planOptions: ReportFilterOption[] = [
    { value: 'all', label: 'Todos', description: 'Sin filtrar por plan', icon: 'select_all' },
    { value: 'monthly', label: 'Plan Mensual', description: 'Planes de 28 a 31 días', icon: 'calendar_view_month' },
    { value: 'quarterly', label: 'Plan Trimestral', description: 'Planes cercanos a 90 días', icon: 'date_range' },
    { value: 'biannual', label: 'Plan Semestral', description: 'Planes cercanos a 180 días', icon: 'event_repeat' },
    { value: 'annual', label: 'Plan Anual', description: 'Planes de 12 meses', icon: 'workspace_premium' },
    { value: 'vip', label: 'Plan VIP', description: 'Segmento premium', icon: 'diamond' },
  ];

  readonly statusOptions: ReportFilterOption[] = [
    { value: 'all', label: 'Todos', description: 'Todos los estados', icon: 'select_all' },
    { value: 'active', label: 'Activos', description: 'Registros vigentes', icon: 'check_circle' },
    { value: 'inactive', label: 'Inactivos', description: 'Registros pausados', icon: 'pause_circle' },
    { value: 'expired', label: 'Vencidos', description: 'Planes o pagos vencidos', icon: 'event_busy' },
    { value: 'pending', label: 'Pendientes', description: 'Por confirmar o resolver', icon: 'schedule' },
  ];

  ngOnInit() {
    this.currentFilter = { ...this.filter };
  }

  ngOnChanges(changes: SimpleChanges) {
    if (changes['filter']) {
      this.currentFilter = { ...this.filter };
    }
  }

  @HostListener('document:click', ['$event'])
  closeSelectOnOutsideClick(event: MouseEvent): void {
    if (!this.openSelect()) return;
    if (!this.elementRef.nativeElement.contains(event.target as Node)) {
      this.openSelect.set(null);
    }
  }

  onFilterChange() {
    this.filterChange.emit({ ...this.currentFilter });
  }

  toggleSelect(select: ReportFilterKey): void {
    this.openSelect.update((current) => (current === select ? null : select));
  }

  chooseOption(key: ReportFilterKey, value: string): void {
    this.currentFilter = {
      ...this.currentFilter,
      [key]: value,
    } as ReportsFilter;
    this.openSelect.set(null);
    this.onFilterChange();
  }

  optionLabel(key: ReportFilterKey): string {
    const value = this.currentFilter[key];
    const options = this.optionsFor(key);
    return options.find((option) => option.value === value)?.label || 'Seleccionar';
  }

  private optionsFor(key: ReportFilterKey): ReportFilterOption[] {
    if (key === 'dateRange') return this.dateRangeOptions;
    if (key === 'reportType') return this.reportTypeOptions;
    if (key === 'plan') return this.planOptions;
    return this.statusOptions;
  }
}
