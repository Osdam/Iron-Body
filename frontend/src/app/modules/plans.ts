import { CommonModule } from '@angular/common';
import { Component, OnInit, inject, signal, effect } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { ApiService, PlanSummary } from '../services/api.service';
import { PlansKPIComponent } from './components/plans-kpi';
import { PlanCardComponent, PlanCardData, BadgeType } from './components/plan-card';
import { PlansTableComponent, PlanTableData } from './components/plans-table';
import { PlansEmptyComponent } from './components/plans-empty';
import { CreatePlanModalComponent } from './components/create-plan-modal';
import { EditPlanModalComponent } from './components/edit-plan-modal';
import { LottieIconComponent } from '../shared/components/lottie-icon/lottie-icon.component';

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
    EditPlanModalComponent,
    LottieIconComponent,
  ],
  template: `
    <section class="plans-page">
      <!-- Toast de notificación -->
      <div
        *ngIf="notification()"
        class="toast-notification"
        [class.toast-success]="notification()?.type === 'success'"
        [class.toast-error]="notification()?.type === 'error'"
        role="alert"
      >
        <span class="material-symbols-outlined" aria-hidden="true">
          {{ notification()?.type === 'success' ? 'check_circle' : 'error' }}
        </span>
        <span>{{ notification()?.message }}</span>
        <button class="toast-close" (click)="clearNotification()" aria-label="Cerrar">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>

      <!-- Header premium -->
      <header class="plans-header">
        <div class="header-content">
          <h1>Planes y membresías</h1>
          <p>Administra membresías, ciclos de cobro y niveles de acceso del gimnasio.</p>
        </div>
        <div class="header-actions">
          <button type="button" class="btn-secondary" (click)="toggleView()">
            <span class="btn-lottie">
              <app-lottie-icon
                src="/assets/crm/vistatablavistacard.json"
                [size]="22"
                [loop]="true"
              ></app-lottie-icon>
            </span>
            {{ isCardView() ? 'Vista de tabla' : 'Vista de cards' }}
          </button>
          <button type="button" class="btn-primary" (click)="openCreatePlan()">
            <span class="btn-lottie">
              <app-lottie-icon src="/assets/crm/mas.json" [size]="22" [loop]="true"></app-lottie-icon>
            </span>
            Crear plan
          </button>
        </div>
      </header>

      <!-- Estado de carga -->
      <div *ngIf="loading()" class="loading-state">
        <div class="spinner"></div>
        <p>Cargando planes...</p>
      </div>

      <!-- Error -->
      <div *ngIf="error()" class="error-alert">
        <span class="material-symbols-outlined">error</span>
        <div>
          <strong>Error al cargar planes</strong>
          <p>{{ error() }}</p>
        </div>
        <button class="btn-retry" (click)="loadPlans()">Reintentar</button>
      </div>

      <!-- Contenido principal -->
      <ng-container *ngIf="!loading() && !error()">
        <!-- KPIs -->
        <section class="kpis-section">
          <app-plans-kpi
            label="Planes activos"
            lottie="/assets/crm/planesactivos.json"
            [value]="activePlans()"
            suffix="Disponibles"
            color="primary"
          ></app-plans-kpi>
          <app-plans-kpi
            label="Suscriptores"
            lottie="/assets/crm/suscripcion.json"
            [value]="estimatedSubscribers()"
            suffix="Estimado"
            color="success"
          ></app-plans-kpi>
          <app-plans-kpi
            label="Ingreso mensual"
            lottie="/assets/crm/ingresomensual.json"
            [value]="formatCurrencyShort(monthlyMrr())"
            suffix="MRR"
            color="warning"
          ></app-plans-kpi>
        </section>

        <!-- Filtros y búsqueda -->
        <section class="filters-section">
          <div class="filter-group search-group">
            <span class="material-symbols-outlined filter-icon">search</span>
            <input
              type="text"
              class="search-input"
              placeholder="Buscar plan..."
              [(ngModel)]="searchQuery"
              aria-label="Buscar planes"
            />
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
          <div class="filter-results" *ngIf="plans().length > 0">
            <span>{{ filteredPlans().length }} de {{ plans().length }} plan(es)</span>
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
              (onDelete)="requestDelete($event)"
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
            (onDelete)="requestDelete($event)"
          ></app-plans-table>
        </section>
      </ng-container>
    </section>

    <!-- Diálogo de confirmación de eliminación -->
    <div *ngIf="planToDelete()" class="modal-backdrop" (click)="cancelDelete()" aria-hidden="true"></div>
    <div *ngIf="planToDelete()" class="confirm-dialog" role="alertdialog" aria-modal="true">
      <div class="confirm-card">
        <div class="confirm-icon">
          <span class="material-symbols-outlined">delete_forever</span>
        </div>
        <h3>¿Eliminar plan?</h3>
        <p>
          Estás por eliminar el plan <strong>{{ planToDelete()?.name }}</strong>. Esta acción no se
          puede deshacer.
        </p>
        <div class="confirm-actions">
          <button class="btn-secondary" (click)="cancelDelete()" [disabled]="isDeleting()">
            Cancelar
          </button>
          <button class="btn-danger" (click)="confirmDelete()" [disabled]="isDeleting()">
            <span *ngIf="!isDeleting()">
              <span class="material-symbols-outlined" style="font-size:1rem">delete</span>
              Sí, eliminar
            </span>
            <span *ngIf="isDeleting()">Eliminando...</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Modal Crear -->
    <app-create-plan-modal
      [isOpen]="isCreatePlanOpen"
      (onClose)="onCreatePlanModalClose()"
      (onPlanCreated)="onPlanCreated($event)"
    ></app-create-plan-modal>

    <!-- Modal Editar -->
    <app-edit-plan-modal
      [isOpen]="isEditPlanOpen"
      [plan]="planToEdit()"
      (onClose)="onEditPlanModalClose()"
      (onPlanUpdated)="onPlanUpdated($event)"
    ></app-edit-plan-modal>
  `,

  styles: [
    `
      .plans-page {
        width: 100%;
        min-width: 0;
        max-width: 1400px;
        margin: 0 auto;
        padding: 1.25rem 1.25rem 2rem;
        color: #0a0a0a;
        background:
          linear-gradient(rgba(250, 250, 250, 0.78), rgba(250, 250, 250, 0.78)),
          url('/assets/crm/cardpalnesmembresia.png') center / cover no-repeat;
        border-radius: 16px;
      }

      .btn-lottie {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        border-radius: 7px;
        background: rgba(0, 0, 0, 0.06);
        overflow: hidden;
      }

      .btn-primary .btn-lottie {
        background: rgba(0, 0, 0, 0.08);
      }

      /* Toast */
      .toast-notification {
        position: fixed;
        bottom: 2rem;
        right: 2rem;
        z-index: 100;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 1rem 1.25rem;
        border-radius: 12px;
        font-size: 0.95rem;
        font-weight: 600;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        animation: slideInRight 300ms cubic-bezier(0.4, 0, 0.2, 1);
        max-width: 380px;
      }

      @keyframes slideInRight {
        from { opacity: 0; transform: translateX(50px); }
        to { opacity: 1; transform: translateX(0); }
      }

      .toast-success {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        color: #166534;
      }

      .toast-error {
        background: #fee2e2;
        border: 1px solid #fecaca;
        color: #991b1b;
      }

      .toast-close {
        display: grid;
        place-items: center;
        width: 28px;
        height: 28px;
        border: none;
        background: transparent;
        cursor: pointer;
        color: currentColor;
        opacity: 0.6;
        border-radius: 6px;
        transition: all 200ms ease;
        margin-left: auto;
        flex-shrink: 0;
      }

      .toast-close:hover { opacity: 1; background: rgba(0,0,0,0.05); }

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

      .btn-retry {
        padding: 0.5rem 1rem;
        border-radius: 8px;
        border: 1px solid #fecaca;
        background: transparent;
        color: #991b1b;
        cursor: pointer;
        font-size: 0.85rem;
        font-weight: 600;
        transition: all 200ms ease;
        margin-left: auto;
      }

      .btn-retry:hover { background: #fef2f2; }

      /* Loading */
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

      @keyframes spin { to { transform: rotate(360deg); } }

      .error-alert {
        display: flex;
        gap: 1rem;
        padding: 1.5rem;
        background: #fee2e2;
        border: 1px solid #fecaca;
        border-radius: 10px;
        color: #991b1b;
        margin-bottom: 2rem;
        align-items: center;
      }

      .error-alert strong { display: block; margin-bottom: 0.25rem; }
      .error-alert p { margin: 0; font-size: 0.9rem; }

      /* KPIs */
      .kpis-section {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2.5rem;
      }

      /* Filters */
      .filters-section {
        display: flex;
        gap: 1rem;
        margin-bottom: 2.5rem;
        flex-wrap: wrap;
        align-items: center;
      }

      .filter-group {
        position: relative;
        flex: 1;
        min-width: 200px;
      }

      .search-group { flex: 2; min-width: 240px; }

      .filter-icon {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
        pointer-events: none;
        font-size: 1.1rem;
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
        box-sizing: border-box;
      }

      .filter-select {
        padding-left: 1rem;
        cursor: pointer;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23999' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14L2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 0.75rem center;
        padding-right: 2.5rem;
      }

      .search-input::placeholder { color: #999; }

      .search-input:focus,
      .filter-select:focus {
        outline: none;
        border-color: #facc15;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.1);
      }

      .filter-results {
        font-size: 0.85rem;
        color: #999;
        white-space: nowrap;
        font-weight: 500;
      }

      /* Cards */
      .cards-section { animation: fadeIn 300ms ease; }

      @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
      }

      .cards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
      }

      .table-section { animation: fadeIn 300ms ease; }

      /* Confirm Dialog */
      .modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(2px);
        z-index: 40;
      }

      .confirm-dialog {
        position: fixed;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 50;
        padding: 1rem;
      }

      .confirm-card {
        background: #fff;
        border-radius: 14px;
        border: 1px solid #e5e5e5;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        padding: 2.5rem 2rem;
        max-width: 420px;
        width: 100%;
        text-align: center;
        animation: slideUp 300ms cubic-bezier(0.4, 0, 0.2, 1);
      }

      @keyframes slideUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
      }

      .confirm-icon {
        display: grid;
        place-items: center;
        width: 64px;
        height: 64px;
        border-radius: 50%;
        background: #fee2e2;
        color: #dc2626;
        font-size: 1.75rem;
        margin: 0 auto 1.25rem;
      }

      .confirm-card h3 {
        font-family: Inter, sans-serif;
        font-size: 1.35rem;
        font-weight: 700;
        color: #0a0a0a;
        margin: 0 0 0.75rem;
      }

      .confirm-card p {
        color: #666;
        line-height: 1.6;
        margin: 0 0 2rem;
        font-size: 0.95rem;
      }

      .confirm-actions {
        display: flex;
        gap: 0.75rem;
        justify-content: center;
      }

      .btn-danger {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.875rem 1.5rem;
        border-radius: 8px;
        font-family: Inter, sans-serif;
        font-weight: 600;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 200ms ease;
        border: none;
        background: #dc2626;
        color: #fff;
      }

      .btn-danger:hover:not(:disabled) {
        background: #b91c1c;
        box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
      }

      .btn-danger:disabled { opacity: 0.6; cursor: not-allowed; }

      /* Responsive */
      @media (max-width: 1024px) {
        .plans-header { flex-direction: column; align-items: flex-start; gap: 1.5rem; }
        .header-actions { width: 100%; }
        .btn-primary, .btn-secondary { flex: 1; justify-content: center; }
        .cards-grid { grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr)); }
        .filters-section { flex-direction: column; }
        .filter-group, .search-group { min-width: 100%; }
      }

      @media (max-width: 768px) {
        .header-content h1 { font-size: 1.75rem; }
        .header-content p { font-size: 0.95rem; }
        .kpis-section { grid-template-columns: 1fr; }
        .cards-grid { grid-template-columns: 1fr; gap: 1.25rem; }
      }

      @media (max-width: 480px) {
        .header-content h1 { font-size: 1.5rem; }
        .plans-header { margin-bottom: 1.75rem; padding-bottom: 1.25rem; }
        .kpis-section { gap: 1rem; margin-bottom: 1.75rem; }
        .toast-notification { right: 1rem; left: 1rem; bottom: 1rem; max-width: none; }
      }
    `,
  ],
})
export default class PlansModule implements OnInit {
  private api = inject(ApiService);
  private router = inject(Router);

  // Estado
  plans = signal<PlanSummary[]>([]);
  loading = signal(true);
  error = signal('');

  // Notificación
  notification = signal<{ type: 'success' | 'error'; message: string } | null>(null);
  private notifTimer: any;

  // Modales
  isCreatePlanOpen = signal(false);
  isEditPlanOpen = signal(false);
  planToEdit = signal<PlanCardData | null>(null);

  // Confirmación de eliminación
  planToDelete = signal<PlanCardData | null>(null);
  isDeleting = signal(false);

  // Vista y filtros
  isCardView = signal(true);
  searchQuery = signal('');
  filterStatus = signal('');
  filterDuration = signal('');

  // Estimaciones por índice
  private readonly planEstimates = [
    { badge: 'recommended' as BadgeType, members: 24, income: 1920000, cycle: 'mensual' },
    { badge: 'bestseller' as BadgeType, members: 12, income: 2520000, cycle: 'trimestral' },
    { badge: 'featured' as BadgeType, members: 8, income: 5760000, cycle: 'anual' },
    { badge: 'premium' as BadgeType, members: 6, income: 900000, cycle: 'mensual' },
  ];

  activePlans = signal(0);
  estimatedSubscribers = signal(0);
  monthlyMrr = signal(0);
  filteredPlans = signal<PlanSummary[]>([]);

  constructor() {
    effect(() => { this.searchQuery(); this.applyFilters(); });
    effect(() => { this.filterStatus(); this.applyFilters(); });
    effect(() => { this.filterDuration(); this.applyFilters(); });
  }

  ngOnInit(): void {
    this.loadPlans();
  }

  loadPlans(): void {
    this.loading.set(true);
    this.error.set('');
    this.api.getPlans().subscribe({
      next: (res) => {
        this.plans.set(res.data || []);
        this.updateMetrics();
        this.applyFilters();
        this.loading.set(false);
      },
      error: () => {
        this.error.set('No se pudieron cargar los planes desde el servidor.');
        this.loading.set(false);
      },
    });
  }

  toggleView(): void { this.isCardView.update((v) => !v); }

  openCreatePlan(): void { this.isCreatePlanOpen.set(true); }

  onCreatePlanModalClose(): void { this.isCreatePlanOpen.set(false); }

  onPlanCreated(newPlan: PlanSummary): void {
    this.plans.update((plans) => [newPlan, ...plans]);
    this.updateMetrics();
    this.applyFilters();
    this.isCreatePlanOpen.set(false);
    this.showNotification('success', `Plan "${newPlan.name}" creado correctamente.`);
  }

  editPlan(plan: PlanCardData): void {
    this.planToEdit.set(plan);
    this.isEditPlanOpen.set(true);
  }

  onEditPlanModalClose(): void {
    this.isEditPlanOpen.set(false);
    this.planToEdit.set(null);
  }

  onPlanUpdated(updated: PlanSummary): void {
    this.plans.update((list) => list.map((p) => (p.id === updated.id ? updated : p)));
    this.updateMetrics();
    this.applyFilters();
    this.isEditPlanOpen.set(false);
    this.planToEdit.set(null);
    this.showNotification('success', `Plan "${updated.name}" actualizado correctamente.`);
  }

  viewMembers(plan: PlanCardData): void {
    this.router.navigate(['/users'], { queryParams: { plan: plan.id } });
  }

  duplicatePlan(plan: PlanCardData): void {
    const duplicateData = {
      name: `${plan.name} (copia)`,
      price: plan.price,
      duration_days: plan.duration_days,
      benefits: plan.benefits || '',
      active: true,
      billing_cycle: plan.billingCycle || 'monthly',
      plan_type: 'general',
      description: plan.description || '',
    };
    this.api.createPlan(duplicateData as any).subscribe({
      next: (newPlan) => {
        this.plans.update((list) => [newPlan, ...list]);
        this.updateMetrics();
        this.applyFilters();
        this.showNotification('success', `Plan "${plan.name}" duplicado correctamente.`);
      },
      error: () => this.showNotification('error', 'No se pudo duplicar el plan. Intenta de nuevo.'),
    });
  }

  toggleStatus(plan: PlanCardData): void {
    const newActive = !plan.active;
    this.api.updatePlan(plan.id, { active: newActive }).subscribe({
      next: (updated) => {
        this.plans.update((list) => list.map((p) => (p.id === updated.id ? updated : p)));
        this.updateMetrics();
        this.applyFilters();
        const label = newActive ? 'activado' : 'desactivado';
        this.showNotification('success', `Plan "${plan.name}" ${label} correctamente.`);
      },
      error: () => this.showNotification('error', 'No se pudo cambiar el estado del plan.'),
    });
  }

  requestDelete(plan: PlanCardData): void {
    this.planToDelete.set(plan);
  }

  cancelDelete(): void {
    if (!this.isDeleting()) this.planToDelete.set(null);
  }

  confirmDelete(): void {
    const plan = this.planToDelete();
    if (!plan) return;
    this.isDeleting.set(true);
    this.api.deletePlan(plan.id).subscribe({
      next: () => {
        this.plans.update((list) => list.filter((p) => p.id !== plan.id));
        this.updateMetrics();
        this.applyFilters();
        this.isDeleting.set(false);
        this.planToDelete.set(null);
        this.showNotification('success', `Plan "${plan.name}" eliminado.`);
      },
      error: () => {
        this.isDeleting.set(false);
        this.planToDelete.set(null);
        this.showNotification('error', 'No se pudo eliminar el plan. Puede estar siendo usado por miembros.');
      },
    });
  }

  applyFilters(): void {
    const search = this.searchQuery().toLowerCase();
    const status = this.filterStatus();
    const duration = this.filterDuration();

    const filtered = this.plans().filter((plan) => {
      if (search && !plan.name.toLowerCase().includes(search)) return false;
      if (status === 'active' && !plan.active) return false;
      if (status === 'inactive' && plan.active) return false;
      if (duration) {
        if (duration === 'monthly' && (plan.duration_days < 28 || plan.duration_days > 31)) return false;
        if (duration === 'quarterly' && (plan.duration_days < 85 || plan.duration_days > 95)) return false;
        if (duration === 'semi' && (plan.duration_days < 170 || plan.duration_days > 190)) return false;
        if (duration === 'annual' && plan.duration_days < 360) return false;
      }
      return true;
    });

    this.filteredPlans.set(filtered);
  }

  updateMetrics(): void {
    const allPlans = this.plans();
    this.activePlans.set(allPlans.filter((p) => p.active).length);
    let totalSubs = 0;
    let totalIncome = 0;
    allPlans.forEach((plan, index) => {
      const estimate = this.planEstimates[index] || { members: 5, income: 500000 };
      totalSubs += estimate.members;
      totalIncome += estimate.income;
    });
    this.estimatedSubscribers.set(totalSubs);
    this.monthlyMrr.set(totalIncome);
  }

  enrichPlanData(plan: PlanSummary): PlanCardData {
    const idx = this.plans().indexOf(plan);
    const estimate = this.planEstimates[idx] || { badge: undefined, members: 5, income: 500000, cycle: 'mes' };
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
    return plansToEnrich.map((plan) => {
      const allIdx = this.plans().indexOf(plan);
      const estimate = this.planEstimates[allIdx] || { members: 5, income: 500000, cycle: 'mes' };
      return { ...plan, estimatedMembers: estimate.members, estimatedIncome: estimate.income, billingCycle: estimate.cycle };
    });
  }

  formatCurrencyShort(amount: number): string {
    if (amount >= 1000000) return '$' + (amount / 1000000).toFixed(1).replace('.0', '') + 'M';
    if (amount >= 1000) return '$' + (amount / 1000).toFixed(0) + 'K';
    return '$' + amount.toLocaleString('es-CO');
  }

  showNotification(type: 'success' | 'error', message: string): void {
    clearTimeout(this.notifTimer);
    this.notification.set({ type, message });
    this.notifTimer = setTimeout(() => this.notification.set(null), 4500);
  }

  clearNotification(): void {
    clearTimeout(this.notifTimer);
    this.notification.set(null);
  }
}
