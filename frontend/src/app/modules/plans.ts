import { CommonModule } from '@angular/common';
import { Component, OnInit, inject, signal, effect } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ApiService, PlanSummary } from '../services/api.service';
import { PlansKPIComponent } from './components/plans-kpi';
import { PlanCardComponent, PlanCardData, BadgeType } from './components/plan-card';
import { PlansTableComponent, PlanTableData } from './components/plans-table';
import { PlansEmptyComponent } from './components/plans-empty';
import { CreatePlanModalComponent } from './components/create-plan-modal';

@Component({
  selector: 'module-plans',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    PlansKPIComponent,
    PlanCardComponent,
    PlansTableComponent,
    PlansEmptyComponent,
    CreatePlanModalComponent,
  ],
  template: `
    <section class="plans-page">
      <!-- Header premium -->
      <header class="plans-header">
        <div class="header-content">
          <h1>Planes y membresías</h1>
          <p>Administra membresías, ciclos de cobro y niveles de acceso del gimnasio.</p>
        </div>
        <div class="header-actions">
          <button type="button" class="btn-secondary" (click)="toggleView()">
            <span class="material-symbols-outlined" aria-hidden="true">{{
              isCardView() ? 'table_rows' : 'dashboard'
            }}</span>
            {{ isCardView() ? 'Vista de tabla' : 'Vista de cards' }}
          </button>
          <button type="button" class="btn-primary" (click)="openCreatePlan()">
            <span class="material-symbols-outlined" aria-hidden="true">add</span>
            Crear plan
          </button>
        </div>
      </header>

      <!-- Estados de carga y error -->
      <div *ngIf="loading()" class="loading-state">
        <div class="spinner"></div>
        <p>Cargando planes...</p>
      </div>

      <div *ngIf="error()" class="error-alert">
        <span class="material-symbols-outlined">error</span>
        <div>
          <strong>Error al cargar planes</strong>
          <p>{{ error() }}</p>
        </div>
      </div>

      <!-- Contenido principal -->
      <ng-container *ngIf="!loading() && !error()">
        <!-- KPIs Premium -->
        <section class="kpis-section">
          <app-plans-kpi
            label="Planes activos"
            icon="loyalty"
            [value]="activePlans()"
            suffix="Disponibles"
            color="primary"
          ></app-plans-kpi>
          <app-plans-kpi
            label="Suscriptores"
            icon="group"
            [value]="estimatedSubscribers()"
            suffix="Estimado"
            color="success"
          ></app-plans-kpi>
          <app-plans-kpi
            label="Ingreso mensual"
            icon="trending_up"
            [value]="formatCurrencyShort(monthlyMrr())"
            suffix="MRR"
            color="warning"
          ></app-plans-kpi>
        </section>

        <!-- Filtros y búsqueda -->
        <section class="filters-section">
          <div class="filter-group">
            <input
              type="text"
              class="search-input"
              placeholder="Buscar plan..."
              [(ngModel)]="searchQuery"
              aria-label="Buscar planes"
            />
            <span class="material-symbols-outlined">search</span>
          </div>
          <div class="filter-group">
            <select
              [(ngModel)]="filterStatus"
              class="filter-select"
              aria-label="Filtrar por estado"
            >
              <option value="">Todos los estados</option>
              <option value="active">Activos</option>
              <option value="inactive">Inactivos</option>
            </select>
          </div>
          <div class="filter-group">
            <select
              [(ngModel)]="filterDuration"
              class="filter-select"
              aria-label="Filtrar por duración"
            >
              <option value="">Todas las duraciones</option>
              <option value="monthly">Mensual</option>
              <option value="quarterly">Trimestral</option>
              <option value="semi">Semestral</option>
              <option value="annual">Anual</option>
            </select>
          </div>
        </section>

        <!-- Estado vacío -->
        <ng-container *ngIf="filteredPlans().length === 0">
          <app-plans-empty (onCreate)="openCreatePlan()"></app-plans-empty>
        </ng-container>

        <!-- Vista de Cards -->
        <section *ngIf="isCardView() && filteredPlans().length > 0" class="cards-section">
          <div class="cards-grid">
            <app-plan-card
              *ngFor="let plan of filteredPlans()"
              [plan]="enrichPlanData(plan)"
              (onEdit)="editPlan($event)"
              (onViewMembers)="viewMembers($event)"
              (onDuplicate)="duplicatePlan($event)"
              (onToggleStatus)="toggleStatus($event)"
              (onDelete)="deletePlan($event)"
            ></app-plan-card>
          </div>
        </section>

        <!-- Vista de Tabla -->
        <section *ngIf="!isCardView() && filteredPlans().length > 0" class="table-section">
          <app-plans-table
            [plans]="enrichPlansTableData(filteredPlans())"
            (onEdit)="editPlan($event)"
            (onViewMembers)="viewMembers($event)"
            (onDuplicate)="duplicatePlan($event)"
            (onDelete)="deletePlan($event)"
          ></app-plans-table>
        </section>
      </ng-container>
    </section>

    <!-- Modal -->
    <app-create-plan-modal
      [isOpen]="isCreatePlanOpen"
      (onClose)="onCreatePlanModalClose()"
      (onPlanCreated)="onPlanCreated($event)"
    ></app-create-plan-modal>
  `,

  styles: [
    `
      .plans-page {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0;
        color: #0a0a0a;
      }

      /* Header */
      .plans-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 2rem;
        margin-bottom: 2.5rem;
        flex-wrap: wrap;
        padding-bottom: 1.75rem;
        border-bottom: 2px solid #f0f0f0;
      }

      .header-content h1 {
        font-family: Inter, sans-serif;
        font-size: 2.5rem;
        font-weight: 700;
        margin: 0 0 0.6rem;
        letter-spacing: -0.02em;
        line-height: 1.1;
      }

      .header-content p {
        font-size: 1.05rem;
        line-height: 1.6;
        color: #666;
        margin: 0;
        max-width: 600px;
      }

      .header-actions {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
      }

      .btn-primary,
      .btn-secondary {
        display: inline-flex;
        align-items: center;
        gap: 0.6rem;
        padding: 0.9rem 1.75rem;
        border-radius: 10px;
        font-family: Inter, sans-serif;
        font-weight: 600;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 200ms cubic-bezier(0.4, 0, 0.2, 1);
        border: none;
      }

      .btn-primary {
        background: #facc15;
        color: #000;
        box-shadow: 0 4px 12px rgba(250, 204, 21, 0.2);
      }

      .btn-primary:hover {
        background: #f0c00e;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(250, 204, 21, 0.3);
      }

      .btn-secondary {
        border: 1.5px solid #d0d0d0;
        background: #fff;
        color: #0a0a0a;
      }

      .btn-secondary:hover {
        border-color: #a0a0a0;
        background: #f9f9f9;
      }

      /* Loading y Error States */
      .loading-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 400px;
        gap: 1.5rem;
        color: #666;
      }

      .spinner {
        width: 40px;
        height: 40px;
        border: 3px solid #f0f0f0;
        border-top: 3px solid #facc15;
        border-radius: 50%;
        animation: spin 1s linear infinite;
      }

      @keyframes spin {
        to {
          transform: rotate(360deg);
        }
      }

      .error-alert {
        display: flex;
        gap: 1rem;
        padding: 1.5rem;
        background: #fee2e2;
        border: 1px solid #fecaca;
        border-radius: 10px;
        color: #991b1b;
        margin-bottom: 2rem;
      }

      .error-alert span {
        flex-shrink: 0;
        font-size: 1.5rem;
      }

      .error-alert strong {
        display: block;
        margin-bottom: 0.25rem;
      }

      .error-alert p {
        margin: 0;
        font-size: 0.9rem;
      }

      /* KPIs Section */
      .kpis-section {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2.5rem;
      }

      /* Filters Section */
      .filters-section {
        display: flex;
        gap: 1rem;
        margin-bottom: 2.5rem;
        flex-wrap: wrap;
      }

      .filter-group {
        position: relative;
        flex: 1;
        min-width: 200px;
      }

      .search-input,
      .filter-select {
        width: 100%;
        padding: 0.875rem 1rem 0.875rem 2.75rem;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        font-family: Inter, sans-serif;
        font-size: 0.95rem;
        color: #0a0a0a;
        background: #fff;
        transition: all 200ms ease;
      }

      .search-input::placeholder {
        color: #999;
      }

      .search-input:focus,
      .filter-select:focus {
        outline: none;
        border-color: #facc15;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.1);
      }

      .filter-group span {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
        pointer-events: none;
      }

      /* Cards Section */
      .cards-section {
        animation: fadeIn 300ms ease;
      }

      @keyframes fadeIn {
        from {
          opacity: 0;
          transform: translateY(10px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }

      .cards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: 2rem;
        margin-bottom: 2rem;
      }

      /* Table Section */
      .table-section {
        animation: fadeIn 300ms ease;
      }

      /* Responsivo */
      @media (max-width: 1024px) {
        .plans-header {
          flex-direction: column;
          align-items: flex-start;
          gap: 1.5rem;
        }

        .header-actions {
          width: 100%;
        }

        .btn-primary,
        .btn-secondary {
          flex: 1;
        }

        .cards-grid {
          grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
          gap: 1.5rem;
        }

        .filters-section {
          flex-direction: column;
        }

        .filter-group {
          min-width: 100%;
        }
      }

      @media (max-width: 768px) {
        .header-content h1 {
          font-size: 1.75rem;
        }

        .header-content p {
          font-size: 0.95rem;
        }

        .kpis-section {
          grid-template-columns: 1fr;
        }

        .cards-grid {
          grid-template-columns: 1fr;
          gap: 1.25rem;
        }

        .btn-primary,
        .btn-secondary {
          width: 100%;
          justify-content: center;
        }
      }

      @media (max-width: 480px) {
        .header-content h1 {
          font-size: 1.5rem;
        }

        .header-content p {
          font-size: 0.9rem;
        }

        .plans-header {
          margin-bottom: 1.75rem;
          padding-bottom: 1.25rem;
        }

        .filters-section {
          flex-direction: column;
          gap: 0.75rem;
        }

        .kpis-section {
          gap: 1rem;
          margin-bottom: 1.75rem;
        }
      }
    `,
  ],
})
export default class PlansModule implements OnInit {
  private api = inject(ApiService);

  // Estado
  plans = signal<PlanSummary[]>([]);
  loading = signal(true);
  error = signal('');
  isCreatePlanOpen = signal(false);

  // Controles de vista y filtros
  isCardView = signal(true);
  searchQuery = signal('');
  filterStatus = signal('');
  filterDuration = signal('');

  // Datos enriquecidos con estimaciones
  private readonly planEstimates = [
    { badge: 'recommended' as BadgeType, members: 24, income: 1920000, cycle: 'mensual' },
    { badge: 'bestseller' as BadgeType, members: 12, income: 2520000, cycle: 'trimestral' },
    { badge: 'featured' as BadgeType, members: 8, income: 5760000, cycle: 'anual' },
    { badge: 'premium' as BadgeType, members: 6, income: 900000, cycle: 'mensual' },
  ];

  // Computados
  activePlans = signal(0);
  estimatedSubscribers = signal(0);
  monthlyMrr = signal(0);

  filteredPlans = signal<PlanSummary[]>([]);

  ngOnInit(): void {
    this.api.getPlans().subscribe({
      next: (res) => {
        this.plans.set(res.data || []);
        this.updateMetrics();
        this.applyFilters();
        this.loading.set(false);
      },
      error: () => {
        this.error.set('No se pudieron cargar los planes desde Laravel.');
        this.loading.set(false);
      },
    });
  }

  toggleView(): void {
    this.isCardView.update((v) => !v);
  }

  openCreatePlan(): void {
    this.isCreatePlanOpen.set(true);
  }

  onCreatePlanModalClose(): void {
    this.isCreatePlanOpen.set(false);
  }

  onPlanCreated(newPlan: PlanSummary): void {
    // Agregar nuevo plan a la lista
    this.plans.update((plans) => [newPlan, ...plans]);

    // Actualizar métricas
    this.updateMetrics();

    // Aplicar filtros
    this.applyFilters();

    // Cerrar modal
    this.isCreatePlanOpen.set(false);
  }

  editPlan(plan: PlanCardData): void {
    console.log('Editando plan:', plan);
    alert(`Editar plan: ${plan.name}`);
  }

  viewMembers(plan: PlanCardData): void {
    console.log('Ver miembros del plan:', plan);
    alert(`Ver ${plan.estimatedMembers} miembros del plan ${plan.name}`);
  }

  duplicatePlan(plan: PlanCardData): void {
    console.log('Duplicando plan:', plan);
    alert(`Duplicar plan: ${plan.name}`);
  }

  toggleStatus(plan: PlanCardData): void {
    console.log('Cambiar estado:', plan);
    alert(`${plan.active ? 'Desactivar' : 'Activar'} plan: ${plan.name}`);
  }

  deletePlan(plan: PlanCardData): void {
    console.log('Eliminar plan:', plan);
    alert(`¿Eliminar plan: ${plan.name}?`);
  }

  applyFilters(): void {
    const search = this.searchQuery().toLowerCase();
    const status = this.filterStatus();
    const duration = this.filterDuration();

    const filtered = this.plans().filter((plan) => {
      // Filtro por búsqueda
      if (search && !plan.name.toLowerCase().includes(search)) {
        return false;
      }

      // Filtro por estado
      if (status === 'active' && !plan.active) return false;
      if (status === 'inactive' && plan.active) return false;

      // Filtro por duración
      if (duration) {
        if (duration === 'monthly' && (plan.duration_days < 28 || plan.duration_days > 31))
          return false;
        if (duration === 'quarterly' && (plan.duration_days < 85 || plan.duration_days > 95))
          return false;
        if (duration === 'semi' && (plan.duration_days < 170 || plan.duration_days > 190))
          return false;
        if (duration === 'annual' && plan.duration_days < 360) return false;
      }

      return true;
    });

    this.filteredPlans.set(filtered);
  }

  updateMetrics(): void {
    const allPlans = this.plans();
    this.activePlans.set(allPlans.filter((p) => p.active).length);

    let totalSubscribers = 0;
    let totalIncome = 0;

    allPlans.forEach((plan, index) => {
      const estimate = this.planEstimates[index] || { members: 5, income: 500000 };
      totalSubscribers += estimate.members;
      totalIncome += estimate.income;
    });

    this.estimatedSubscribers.set(totalSubscribers);
    this.monthlyMrr.set(totalIncome);
  }

  enrichPlanData(plan: PlanSummary, index?: number): PlanCardData {
    const idx = index ?? this.plans().indexOf(plan);
    const estimate = this.planEstimates[idx] || {
      badge: undefined,
      members: 5,
      income: 500000,
      cycle: 'mes',
    };

    return {
      ...plan,
      badge: estimate.badge,
      estimatedMembers: estimate.members,
      estimatedIncome: estimate.income,
      billingCycle: estimate.cycle,
      description: plan.benefits || 'Plan de membresía para acceso al gimnasio',
    };
  }

  enrichPlansTableData(plansToEnrich: PlanSummary[]): PlanTableData[] {
    return plansToEnrich.map((plan, idx) => {
      const allIdx = this.plans().indexOf(plan);
      const estimate = this.planEstimates[allIdx] || { members: 5, income: 500000, cycle: 'mes' };

      return {
        ...plan,
        estimatedMembers: estimate.members,
        estimatedIncome: estimate.income,
        billingCycle: estimate.cycle,
      };
    });
  }

  formatCurrencyShort(amount: number): string {
    if (amount >= 1000000) return '$' + (amount / 1000000).toFixed(1).replace('.0', '') + 'M';
    if (amount >= 1000) return '$' + (amount / 1000).toFixed(0) + 'K';
    return '$' + amount.toLocaleString('es-CO');
  }

  // Watchers para aplicar filtros cuando cambian
  constructor() {
    // Watch para búsqueda
    effect(() => {
      this.searchQuery();
      this.applyFilters();
    });

    // Watch para filtro de estado
    effect(() => {
      this.filterStatus();
      this.applyFilters();
    });

    // Watch para filtro de duración
    effect(() => {
      this.filterDuration();
      this.applyFilters();
    });
  }
}
