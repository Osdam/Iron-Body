import { CommonModule } from '@angular/common';
import { Component, ElementRef, EventEmitter, HostListener, Input, Output, inject } from '@angular/core';
import { FormsModule } from '@angular/forms';

export interface MarketingFilters {
  searchTerm: string;
  status: string;
  type: string;
  channel: string;
  segment: string;
  dateRange: string;
}

type MarketingFilterSelect = Exclude<keyof MarketingFilters, 'searchTerm'>;

interface MarketingFilterOption {
  value: string;
  label: string;
  description: string;
  icon: string;
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

      <div class="filter-group" *ngFor="let config of filterConfigs">
        <label [attr.for]="config.key">{{ config.label }}</label>
        <div class="pretty-select" [class.open]="openSelect === config.key">
          <button
            type="button"
            class="pretty-trigger"
            [id]="config.key"
            (click)="toggleSelect(config.key)"
            [attr.aria-label]="'Filtrar por ' + config.label.toLowerCase()"
          >
            <span>{{ optionLabel(config.key) }}</span>
            <span class="select-chevron" aria-hidden="true"></span>
          </button>
          <div class="pretty-menu" *ngIf="openSelect === config.key">
            <button
              type="button"
              class="pretty-option"
              *ngFor="let option of config.options"
              [class.selected]="localFilters[config.key] === option.value"
              (click)="chooseFilter(config.key, option.value)"
            >
              <span class="option-main">
                <span class="option-icon material-symbols-outlined" aria-hidden="true">{{ option.icon }}</span>
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
  `,
  styles: [
    `
      .filters {
        position: relative;
        z-index: 30;
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 0.9rem;
        margin-bottom: 1.6rem;
        overflow: visible;
      }

      .filter-group {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
        min-width: 0;
        position: relative;
      }

      label {
        font-size: 0.8rem;
        font-weight: 700;
        color: #0a0a0a;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }

      .filter-input {
        padding: 0.7rem 0.9rem;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        background: #ffffff;
        color: #0a0a0a;
        font-size: 0.92rem;
        font-weight: 500;
        transition: all 0.15s ease;
      }

      .filter-input:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.1);
      }

      .filter-input::placeholder {
        color: #bbb;
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
        min-height: 42px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        background: #ffffff;
        color: #0a0a0a;
        padding: 0 0.9rem;
        font: 800 0.92rem Inter, sans-serif;
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
        from { opacity: 0; transform: translateY(-4px) scale(0.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
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

      .option-copy strong,
      .option-copy small {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .option-copy strong {
        color: inherit;
        font-weight: 900;
        font-size: 0.9rem;
      }

      .option-copy small {
        color: #71717a;
        font-weight: 650;
        font-size: 0.75rem;
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
  private elementRef = inject(ElementRef<HTMLElement>);

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
  openSelect: MarketingFilterSelect | null = null;

  readonly filterConfigs: Array<{
    key: MarketingFilterSelect;
    label: string;
    options: MarketingFilterOption[];
  }> = [
    {
      key: 'status',
      label: 'Estado',
      options: [
        { value: 'all', label: 'Todos', description: 'Todos los estados', icon: 'select_all' },
        { value: 'Borrador', label: 'Borrador', description: 'Campañas sin publicar', icon: 'edit_note' },
        { value: 'Programada', label: 'Programada', description: 'Lista para enviarse', icon: 'schedule' },
        { value: 'Activa', label: 'Activa', description: 'En ejecución ahora', icon: 'campaign' },
        { value: 'Pausada', label: 'Pausada', description: 'Temporalmente detenida', icon: 'pause_circle' },
        { value: 'Finalizada', label: 'Finalizada', description: 'Campañas completadas', icon: 'task_alt' },
      ],
    },
    {
      key: 'type',
      label: 'Tipo',
      options: [
        { value: 'all', label: 'Todos', description: 'Cualquier tipo', icon: 'apps' },
        { value: 'Promoción', label: 'Promoción', description: 'Oferta comercial', icon: 'local_offer' },
        { value: 'Descuento', label: 'Descuento', description: 'Precio reducido', icon: 'sell' },
        { value: 'Renovación', label: 'Renovación', description: 'Extender membresía', icon: 'autorenew' },
        { value: 'Reactivación', label: 'Reactivación', description: 'Recuperar miembros', icon: 'restart_alt' },
        { value: 'Cumpleaños', label: 'Cumpleaños', description: 'Fechas especiales', icon: 'cake' },
        { value: 'Referidos', label: 'Referidos', description: 'Invitar contactos', icon: 'group_add' },
        { value: 'Clase especial', label: 'Clase especial', description: 'Eventos de entrenamiento', icon: 'fitness_center' },
        { value: 'Evento', label: 'Evento', description: 'Activaciones y encuentros', icon: 'event' },
        { value: 'Comunicación general', label: 'Comunicación general', description: 'Anuncios informativos', icon: 'chat' },
      ],
    },
    {
      key: 'channel',
      label: 'Canal',
      options: [
        { value: 'all', label: 'Todos', description: 'Cualquier canal', icon: 'hub' },
        { value: 'WhatsApp', label: 'WhatsApp', description: 'Mensajería directa', icon: 'sms' },
        { value: 'Correo electrónico', label: 'Correo electrónico', description: 'Campañas por email', icon: 'mail' },
        { value: 'SMS', label: 'SMS', description: 'Mensaje de texto', icon: 'phone_iphone' },
        { value: 'Notificación interna', label: 'Notificación interna', description: 'Aviso dentro del sistema', icon: 'notifications' },
        { value: 'Redes sociales', label: 'Redes sociales', description: 'Publicaciones externas', icon: 'share' },
      ],
    },
    {
      key: 'segment',
      label: 'Segmento',
      options: [
        { value: 'all', label: 'Todos', description: 'Todos los segmentos', icon: 'select_all' },
        { value: 'Todos los miembros', label: 'Todos los miembros', description: 'Audiencia completa', icon: 'groups' },
        { value: 'Miembros activos', label: 'Miembros activos', description: 'Usuarios vigentes', icon: 'verified_user' },
        { value: 'Miembros inactivos', label: 'Miembros inactivos', description: 'Usuarios por recuperar', icon: 'person_off' },
        { value: 'Membresías por vencer', label: 'Membresías por vencer', description: 'Renovación próxima', icon: 'event_upcoming' },
        { value: 'Membresías vencidas', label: 'Membresías vencidas', description: 'Planes expirados', icon: 'event_busy' },
        { value: 'Nuevos miembros', label: 'Nuevos miembros', description: 'Registros recientes', icon: 'person_add' },
        { value: 'Miembros VIP', label: 'Miembros VIP', description: 'Clientes prioritarios', icon: 'workspace_premium' },
        { value: 'Leads', label: 'Leads', description: 'Prospectos comerciales', icon: 'travel_explore' },
      ],
    },
    {
      key: 'dateRange',
      label: 'Rango de fechas',
      options: [
        { value: 'all', label: 'Todos', description: 'Sin rango específico', icon: 'calendar_month' },
        { value: 'Activas', label: 'Activas hoy', description: 'Vigentes en este día', icon: 'today' },
        { value: 'Esta semana', label: 'Esta semana', description: 'Próximos siete días', icon: 'date_range' },
        { value: 'Este mes', label: 'Este mes', description: 'Mes actual', icon: 'calendar_view_month' },
        { value: 'Este trimestre', label: 'Este trimestre', description: 'Trimestre actual', icon: 'view_week' },
      ],
    },
  ];

  ngOnInit(): void {
    this.localFilters = { ...this.filters };
  }

  @HostListener('document:click', ['$event'])
  closeSelectOnOutsideClick(event: MouseEvent): void {
    if (!this.openSelect) return;
    if (!this.elementRef.nativeElement.contains(event.target as Node)) {
      this.openSelect = null;
    }
  }

  toggleSelect(select: MarketingFilterSelect): void {
    this.openSelect = this.openSelect === select ? null : select;
  }

  chooseFilter(select: MarketingFilterSelect, value: string): void {
    this.localFilters[select] = value;
    this.openSelect = null;
    this.onFiltersChange();
  }

  optionLabel(select: MarketingFilterSelect): string {
    const options = this.filterConfigs.find((config) => config.key === select)?.options || [];
    return options.find((option) => option.value === this.localFilters[select])?.label || options[0]?.label || 'Todos';
  }

  onFiltersChange(): void {
    this.filtersChange.emit(this.localFilters);
  }
}
