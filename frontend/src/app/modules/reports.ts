import { Component, OnInit, signal, computed, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import {
  ApiService,
  ClassSummary,
  DashboardStats,
  PaginatedResponse,
  PaymentSummary,
  UserSummary,
} from '../services/api.service';
import { Chart, registerables } from 'chart.js';
import { firstValueFrom, Observable } from 'rxjs';
import ReportsKPIComponent from './components/reports-kpi';
import ReportsFiltersComponent, { ReportsFilter } from './components/reports-filters';
import ReportsChartComponent from './components/reports-chart';
import ReportsTableComponent, { ActivityRecord } from './components/reports-table';
import ReportsQuickActionsComponent, { QuickReport } from './components/reports-quick-actions';
import { LottieIconComponent } from '../shared/components/lottie-icon/lottie-icon.component';

Chart.register(...registerables);

type ReportType = ReportsFilter['reportType'];
type PlanFilter = ReportsFilter['plan'];
type StatusFilter = ReportsFilter['status'];

type MembershipStatusLabel = 'Activos' | 'Inactivos' | 'Vencidos' | 'Pendientes';
type PaymentStatusLabel = 'Pagados' | 'Pendientes' | 'Vencidos' | 'Anulados';

interface KPIData {
  totalRevenue: number;
  monthlyRevenue: number;
  activeMembers: number;
  newMembers: number;
  expiredMemberships: number;
  expiringSoon: number;
  pendingPayments: number;
  completedClasses: number;
  averageAttendance: number;
}

interface RevenuePoint {
  label: string;
  revenue: number;
  date?: string; // yyyy-MM-dd
}

interface PlanSalesPoint {
  plan: string;
  sales: number;
}

interface MembersStatusPoint {
  status: MembershipStatusLabel;
  value: number;
}

interface AttendancePoint {
  className: string;
  attendance: number;
}

interface ActivityRow {
  date: string; // yyyy-MM-dd
  type: 'Pago' | 'Miembro' | 'Membresía' | 'Clase' | 'Plan';
  description: string;
  value: number;
  status: string;
}

interface ReportsPayload {
  kpis: KPIData;
  revenueSeries: RevenuePoint[];
  planSales: PlanSalesPoint[];
  membersByStatus: MembersStatusPoint[];
  attendanceByClass: AttendancePoint[];
  paymentsByStatus: { status: PaymentStatusLabel; value: number }[];
  activity: ActivityRow[];
}

@Component({
  selector: 'module-reports',
  standalone: true,
  imports: [
    CommonModule,
    ReportsKPIComponent,
    ReportsFiltersComponent,
    ReportsChartComponent,
    ReportsTableComponent,
    ReportsQuickActionsComponent,
    LottieIconComponent,
  ],
  template: `
    <section class="reports-page">
      <!-- Header superior profesional -->
      <header class="reports-header">
        <div class="header-left">
          <h1>Analítica y reportes</h1>
          <p>
            Visualiza ingresos, membresías, asistencia, pagos y rendimiento general del gimnasio.
          </p>
        </div>

        <div class="header-right">
          <div class="header-range">
            <label class="header-range-label" for="reportsDateRange">Rango</label>
            <select
              id="reportsDateRange"
              class="header-range-select"
              [value]="selectedFilters().dateRange"
              (change)="onHeaderDateRangeChange($event)"
              aria-label="Filtro de rango de fechas"
            >
              <option value="today">Hoy</option>
              <option value="week">Últimos 7 días</option>
              <option value="month">Últimos 30 días</option>
              <option value="thisMonth">Este mes</option>
              <option value="lastMonth">Mes anterior</option>
              <option value="custom">Personalizado</option>
            </select>
          </div>

          <button
            type="button"
            class="btn-secondary"
            (click)="refreshReports()"
            [disabled]="isRefreshingReports()"
          >
            <span class="btn-lottie">
              <app-lottie-icon src="/assets/crm/reload.json" [size]="22" [loop]="true"></app-lottie-icon>
            </span>
            {{ isRefreshingReports() ? 'Actualizando...' : 'Actualizar datos' }}
          </button>

          <button
            type="button"
            class="btn-primary"
            (click)="exportReport()"
            [disabled]="isExportingReport()"
          >
            <span class="btn-lottie">
              <app-lottie-icon src="/assets/crm/exportar.json" [size]="22" [loop]="true"></app-lottie-icon>
            </span>
            {{ isExportingReport() ? 'Exportando...' : 'Exportar reporte' }}
          </button>
        </div>
      </header>

      <div *ngIf="reportsNotice() as n" class="notice" [ngClass]="'notice-' + n.kind" role="status">
        <span class="material-symbols-outlined" aria-hidden="true">{{ noticeIcon(n.kind) }}</span>
        <p class="notice-message">{{ n.message }}</p>
        <button type="button" class="notice-close" (click)="dismissNotice()" aria-label="Cerrar">
          close
        </button>
      </div>

      <!-- Estados de carga y error -->
      <div *ngIf="isLoadingReports()" class="loading-state">
        <div class="spinner" aria-hidden="true"></div>
        <p>Cargando analítica...</p>
      </div>

      <div *ngIf="reportsError()" class="error-alert">
        <span class="material-symbols-outlined" aria-hidden="true">error</span>
        <div>
          <strong>Error al cargar reportes</strong>
          <p>{{ reportsError() }}</p>
        </div>
      </div>

      <!-- Contenido principal -->
      <ng-container *ngIf="!isLoadingReports() && !reportsError()">
        <!-- Filtros funcionales -->
        <app-reports-filters
          [filter]="selectedFilters()"
          (filterChange)="onFiltersChanged($event)"
        ></app-reports-filters>

        <!-- Estado vacío -->
        <div *ngIf="isEmptyState()" class="empty-state">
          <span class="material-symbols-outlined" aria-hidden="true">analytics</span>
          <h2>Aún no hay datos suficientes</h2>
          <p>
            Cuando registres miembros, pagos, planes y clases, aquí verás métricas y reportes del
            rendimiento del gimnasio.
          </p>
          <div class="empty-actions">
            <button type="button" class="btn-outline" (click)="goToMembers()">
              <span class="material-symbols-outlined" aria-hidden="true">group</span>
              Ir a miembros
            </button>
            <button type="button" class="btn-outline" (click)="goToPlans()">
              <span class="material-symbols-outlined" aria-hidden="true">loyalty</span>
              Ir a planes
            </button>
          </div>
        </div>

        <ng-container *ngIf="!isEmptyState()">
          <!-- KPIs principales -->
          <section class="kpis-grid">
            <app-reports-kpi
              label="Ingreso total"
              icon="wallet"
              [value]="formatCurrency(kpis().totalRevenue)"
              suffix="acumulado"
              color="primary"
              bgImage="/assets/crm/fondo2.png"
            ></app-reports-kpi>
            <app-reports-kpi
              label="Ingreso mensual"
              icon="trending_up"
              [value]="formatCurrency(kpis().monthlyRevenue)"
              suffix="período"
              color="success"
              [trend]="revenueGrowth()"
              bgImage="/assets/crm/fondo3.png"
            ></app-reports-kpi>
            <app-reports-kpi
              label="Miembros activos"
              icon="group"
              [value]="kpis().activeMembers"
              suffix="activos"
              color="info"
              bgImage="/assets/crm/fondo4.png"
            ></app-reports-kpi>
            <app-reports-kpi
              label="Nuevos miembros"
              icon="person_add"
              [value]="kpis().newMembers"
              suffix="en el período"
              color="success"
              bgImage="/assets/crm/fondo5.png"
            ></app-reports-kpi>
            <app-reports-kpi
              label="Membresías vencidas"
              icon="event_busy"
              [value]="kpis().expiredMemberships"
              suffix="vencidas"
              color="danger"
              bgImage="/assets/crm/fondo6.png"
            ></app-reports-kpi>
            <app-reports-kpi
              label="Por vencer"
              icon="event_upcoming"
              [value]="kpis().expiringSoon"
              suffix="próximas"
              color="warning"
              bgImage="/assets/crm/fondo7.png"
            ></app-reports-kpi>
            <app-reports-kpi
              label="Pagos pendientes"
              icon="pending_actions"
              [value]="kpis().pendingPayments"
              suffix="pendientes"
              color="warning"
              bgImage="/assets/crm/fondo2.png"
            ></app-reports-kpi>
            <app-reports-kpi
              label="Clases realizadas"
              icon="school"
              [value]="kpis().completedClasses"
              suffix="realizadas"
              color="primary"
              bgImage="/assets/crm/fondo3.png"
            ></app-reports-kpi>
            <app-reports-kpi
              label="Asistencia promedio"
              icon="event_available"
              [value]="formatPercentage(kpis().averageAttendance)"
              suffix="promedio"
              color="success"
              bgImage="/assets/crm/fondo4.png"
            ></app-reports-kpi>
          </section>

          <!-- Reportes rápidos -->
          <app-reports-quick-actions
            (reportSelect)="onQuickReport($event)"
          ></app-reports-quick-actions>

          <!-- Gráfico principal inspirado en line-charts-9 -->
          <section class="main-chart">
            <app-reports-chart
              type="line"
              title="Ingresos por período"
              [subtitle]="mainChartSubtitle()"
              [chartData]="revenueChartData()"
              [stats]="revenueStats()"
              bgImage="/assets/crm/fondo7.png"
            ></app-reports-chart>

            <div class="main-chart-metrics">
              <div class="metric">
                <div class="metric-label">Ingreso del período</div>
                <div class="metric-value">{{ formatCurrency(currentPeriodRevenue()) }}</div>
                <div
                  class="metric-sub"
                  [class.metric-up]="revenueGrowth() >= 0"
                  [class.metric-down]="revenueGrowth() < 0"
                >
                  <span class="material-symbols-outlined" aria-hidden="true">{{
                    revenueGrowth() >= 0 ? 'trending_up' : 'trending_down'
                  }}</span>
                  <span>{{ formatPercentage(revenueGrowth(), true) }}</span>
                  <span class="muted">comparado con el período anterior</span>
                </div>
              </div>

              <div class="metric-row">
                <div class="metric-mini">
                  <span class="muted">Máximo</span>
                  <strong>{{ formatCurrency(revenueStats().max) }}</strong>
                </div>
                <div class="metric-mini">
                  <span class="muted">Mínimo</span>
                  <strong>{{ formatCurrency(revenueStats().min) }}</strong>
                </div>
                <div class="metric-mini">
                  <span class="muted">Variación</span>
                  <strong
                    [class.metric-up]="revenueGrowth() >= 0"
                    [class.metric-down]="revenueGrowth() < 0"
                    >{{ formatPercentage(revenueGrowth(), true) }}</strong
                  >
                </div>
              </div>
            </div>
          </section>

          <!-- Gráficos secundarios -->
          <section class="charts-grid">
            <app-reports-chart
              type="bar"
              title="Ventas por plan"
              subtitle="Membresías vendidas"
              [chartData]="planSalesChartData()"
              bgImage="/assets/crm/fondo6.png"
            ></app-reports-chart>
            <app-reports-chart
              type="doughnut"
              title="Miembros por estado"
              subtitle="Distribución"
              [chartData]="membersStatusChartData()"
              bgImage="/assets/crm/fondo7.png"
            ></app-reports-chart>
            <app-reports-chart
              type="bar"
              title="Asistencia a clases"
              subtitle="Promedio por clase"
              [chartData]="attendanceChartData()"
              bgImage="/assets/crm/fondo2.png"
            ></app-reports-chart>
            <app-reports-chart
              type="doughnut"
              title="Pagos por estado"
              subtitle="Distribución"
              [chartData]="paymentsStatusChartData()"
              bgImage="/assets/crm/fondo3.png"
            ></app-reports-chart>
          </section>

          <!-- Tabla de actividad -->
          <app-reports-table [records]="activityRecords()" bgImage="/assets/crm/fondo4.png"></app-reports-table>
        </ng-container>
      </ng-container>
    </section>
  `,
  styles: [
    `
      .reports-page {
        max-width: 1400px;
        margin: 0 auto;
        padding: 1.25rem 1.25rem 2rem;
        color: #0a0a0a;
        background:
          linear-gradient(rgba(248, 248, 248, 0.82), rgba(248, 248, 248, 0.82)),
          url('/assets/crm/fondo1.png') center / cover no-repeat;
        border-radius: 16px;
      }

      .reports-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 2rem;
        margin-bottom: 2rem;
        flex-wrap: wrap;
        padding-bottom: 1.5rem;
        border-bottom: 2px solid #f0f0f0;
      }

      .header-left h1 {
        font-family: Inter, sans-serif;
        font-size: 2.5rem;
        font-weight: 700;
        margin: 0 0 0.5rem;
        letter-spacing: -0.02em;
        line-height: 1.1;
      }

      .header-left p {
        font-size: 1.05rem;
        line-height: 1.6;
        color: #666;
        margin: 0;
        max-width: 720px;
      }

      .header-right {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        align-items: end;
      }

      .header-range {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        min-width: 190px;
      }

      .header-range-label {
        font-size: 0.72rem;
        font-weight: 800;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.06em;
      }

      .header-range-select {
        height: 42px;
        border-radius: 10px;
        border: 1px solid #e5e5e5;
        padding: 0 0.9rem;
        background: #ffffff;
        color: #0a0a0a;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s ease;
      }

      .header-range-select:hover {
        border-color: #d0d0d0;
        background: #f9f9f9;
      }

      .header-range-select:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.12);
      }

      .btn-primary,
      .btn-secondary,
      .btn-outline,
      .btn-primary {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem 1.25rem;
        border-radius: 10px;
        font-size: 0.95rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s ease;
        border: 1px solid transparent;
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
        flex-shrink: 0;
      }

      .btn-primary .btn-lottie {
        background: rgba(0, 0, 0, 0.08);
      }

      .btn-primary {
        background: #fbbf24;
        color: #0a0a0a;
      }

      .btn-primary:hover:not(:disabled) {
        background: #f9a825;
        box-shadow: 0 6px 14px rgba(251, 191, 36, 0.25);
      }

      .btn-secondary {
        background: #ffffff;
        color: #0a0a0a;
        border-color: #e5e5e5;
      }

      .btn-secondary:hover:not(:disabled) {
        background: #f9f9f9;
        border-color: #d0d0d0;
      }

      .btn-outline {
        background: #ffffff;
        color: #0a0a0a;
        border-color: #e5e5e5;
      }

      .btn-outline:hover {
        border-color: #fbbf24;
        box-shadow: 0 6px 14px rgba(0, 0, 0, 0.06);
      }

      .btn-primary:disabled,
      .btn-secondary:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }

      .loading-state {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1.25rem;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        background: #ffffff;
      }

      .notice {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 1rem 1.1rem;
        border-radius: 12px;
        border: 1px solid #e5e5e5;
        background: #ffffff;
        color: #0a0a0a;
        margin: 0 0 1.75rem;
      }

      .notice .material-symbols-outlined {
        font-size: 1.35rem;
      }

      .notice-message {
        margin: 0;
        flex: 1;
        font-weight: 650;
        color: #222;
      }

      .notice-close {
        border: none;
        background: transparent;
        cursor: pointer;
        color: #666;
        font-weight: 700;
        font-size: 0.9rem;
        padding: 0.25rem 0.35rem;
        border-radius: 8px;
        transition: background 0.15s ease;
      }

      .notice-close:hover {
        background: #f3f4f6;
      }

      .notice-success {
        border-color: #bbf7d0;
        background: #f0fdf4;
      }

      .notice-info {
        border-color: #e5e5e5;
        background: #fafafa;
      }

      .notice-error {
        border-color: #fecaca;
        background: #fef2f2;
      }

      .spinner {
        width: 22px;
        height: 22px;
        border: 3px solid #e5e5e5;
        border-top: 3px solid #fbbf24;
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
        align-items: flex-start;
        gap: 1rem;
        background: #fee2e2;
        border: 1px solid #fecaca;
        border-radius: 12px;
        padding: 1.25rem;
      }

      .error-alert strong {
        color: #991b1b;
      }

      .error-alert p {
        color: #7f1d1d;
        margin: 0.25rem 0 0;
      }

      .kpis-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1.25rem;
        margin: 1.75rem 0 2.25rem;
      }

      .main-chart {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 1.25rem;
        margin-bottom: 1.75rem;
        align-items: start;
      }

      .main-chart-metrics {
        background:
          linear-gradient(rgba(255, 255, 255, 0.93), rgba(255, 252, 235, 0.88)),
          url('/assets/crm/fondo5.png') center / cover no-repeat;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
      }

      .metric-label {
        font-size: 0.85rem;
        color: #666;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
      }

      .metric-value {
        font-size: 1.9rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        margin-top: 0.25rem;
      }

      .metric-sub {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        font-weight: 700;
        margin-top: 0.5rem;
      }

      .metric-sub .muted {
        font-weight: 600;
        color: #999;
        margin-left: 0.35rem;
      }

      .metric-up {
        color: #10b981;
      }

      .metric-down {
        color: #ef4444;
      }

      .metric-row {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.75rem;
      }

      .metric-mini {
        background: #f9f9f9;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        padding: 0.9rem;
        display: grid;
        gap: 0.25rem;
      }

      .metric-mini .muted {
        font-size: 0.78rem;
        color: #999;
        font-weight: 700;
        text-transform: uppercase;
      }

      .charts-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1.25rem;
        margin-bottom: 1.75rem;
      }

      .empty-state {
        margin-top: 1.75rem;
        background: #ffffff;
        border: 1px solid #e5e5e5;
        border-radius: 14px;
        padding: 3rem 2rem;
        text-align: center;
      }

      .empty-state span.material-symbols-outlined {
        font-size: 3rem;
        color: #fbbf24;
      }

      .empty-state h2 {
        margin: 0.75rem 0 0.5rem;
        font-size: 1.35rem;
        font-weight: 800;
      }

      .empty-state p {
        margin: 0 auto 1.25rem;
        max-width: 600px;
        color: #666;
        line-height: 1.6;
      }

      .empty-actions {
        display: flex;
        justify-content: center;
        gap: 0.75rem;
        flex-wrap: wrap;
      }

      @media (max-width: 1200px) {
        .kpis-grid {
          grid-template-columns: repeat(3, minmax(0, 1fr));
        }
      }

      @media (max-width: 1024px) {
        .main-chart {
          grid-template-columns: 1fr;
        }
        .charts-grid {
          grid-template-columns: 1fr;
        }
      }

      @media (max-width: 768px) {
        .kpis-grid {
          grid-template-columns: 1fr;
        }
        .header-left h1 {
          font-size: 2rem;
        }

        .header-range {
          width: 100%;
          min-width: 0;
        }
      }
    `,
  ],
})
export default class ReportsModule implements OnInit {
  private apiService = inject(ApiService);
  private router = inject(Router);

  private readonly MAX_PAGES_FETCH = 10;

  // State
  reportsData = signal<ReportsPayload | null>(null);
  isLoadingReports = signal<boolean>(false);
  isExportingReport = signal<boolean>(false);
  isRefreshingReports = signal<boolean>(false);
  reportsError = signal<string>('');

  reportsNotice = signal<{ kind: 'success' | 'info' | 'error'; message: string } | null>(null);

  selectedFilters = signal<ReportsFilter>({
    dateRange: 'month',
    reportType: 'general',
    plan: 'all',
    status: 'all',
  });

  // Derived
  filteredReportsData = computed(() =>
    this.applyReportFilters(this.reportsData(), this.selectedFilters()),
  );

  kpis = computed(() => this.calculateKpis(this.filteredReportsData()));

  isEmptyState = computed(() => {
    const k = this.kpis();
    return !k || (k.activeMembers === 0 && k.totalRevenue === 0 && k.monthlyRevenue === 0);
  });

  currentPeriodRevenue = computed(() => this.kpis().monthlyRevenue);
  revenueGrowth = computed(() =>
    this.calculateGrowth(this.kpis().monthlyRevenue, this.previousPeriodRevenue()),
  );

  mainChartSubtitle = computed(() => {
    const range = this.selectedFilters().dateRange;
    const labels: Record<ReportsFilter['dateRange'], string> = {
      today: 'Hoy',
      week: 'Últimos 7 días',
      month: 'Últimos 30 días',
      thisMonth: 'Este mes',
      lastMonth: 'Mes anterior',
      custom: 'Rango personalizado',
    };
    return labels[range] || 'Período';
  });

  revenueChartData = computed(() => this.buildRevenueChartData(this.filteredReportsData()));
  planSalesChartData = computed(() => this.buildPlanSalesChartData(this.filteredReportsData()));
  membersStatusChartData = computed(() =>
    this.buildMembersStatusChartData(this.filteredReportsData()),
  );
  attendanceChartData = computed(() => this.buildAttendanceChartData(this.filteredReportsData()));
  paymentsStatusChartData = computed(() =>
    this.buildPaymentsStatusChartData(this.filteredReportsData()),
  );

  revenueStats = computed(() => {
    const series = this.filteredReportsData()?.revenueSeries || [];
    const values = series.map((p) => p.revenue);
    const max = values.length ? Math.max(...values) : 0;
    const min = values.length ? Math.min(...values) : 0;
    const avg = values.length ? values.reduce((a, b) => a + b, 0) / values.length : 0;
    return { max, min, avg };
  });

  activityRecords = computed<ActivityRecord[]>(() => {
    const rows = (this.filteredReportsData()?.activity || []).slice(0, 20);
    return rows.map((r) => {
      const mappedType: ActivityRecord['type'] =
        r.type === 'Pago'
          ? 'payment'
          : r.type === 'Miembro'
            ? 'member'
            : r.type === 'Membresía'
              ? 'membership'
              : r.type === 'Clase'
                ? 'class'
                : 'plan';

      const mappedStatus: ActivityRecord['status'] =
        r.status === 'Completado'
          ? 'completed'
          : r.status === 'Activo'
            ? 'active'
            : r.status === 'Vencida'
              ? 'expired'
              : r.status === 'Pagado'
                ? 'paid'
                : 'pending';

      return {
        date: r.date,
        type: mappedType,
        description: r.description,
        value: r.value,
        status: mappedStatus,
        icon: this.iconForActivityType(mappedType),
      };
    });
  });

  ngOnInit(): void {
    this.loadReports();
  }

  async loadReports(): Promise<void> {
    this.isLoadingReports.set(true);
    this.reportsError.set('');
    this.reportsNotice.set(null);

    try {
      const payload = await this.tryLoadFromApi();
      this.reportsData.set(payload);
    } catch (e: any) {
      // Fallback a datos de ejemplo si el backend no está disponible.
      try {
        const payload = this.buildMockPayload();
        await new Promise((r) => setTimeout(r, 250));
        this.reportsData.set(payload);
        this.reportsNotice.set({
          kind: 'info',
          message: 'Backend no disponible; mostrando datos de ejemplo por ahora.',
        });
      } catch (inner: any) {
        this.reportsError.set(inner?.message || e?.message || 'No se pudieron cargar los datos.');
      }
    } finally {
      this.isLoadingReports.set(false);
    }
  }

  async refreshReports(): Promise<void> {
    this.isRefreshingReports.set(true);
    this.reportsError.set('');
    try {
      await this.loadReports();
      if (!this.reportsError()) {
        this.reportsNotice.set({ kind: 'success', message: 'Datos actualizados.' });
      }
    } finally {
      this.isRefreshingReports.set(false);
    }
  }

  onFiltersChanged(next: ReportsFilter): void {
    this.selectedFilters.set(next);
  }

  onHeaderDateRangeChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value as ReportsFilter['dateRange'];
    const current = this.selectedFilters();
    this.selectedFilters.set({ ...current, dateRange: value });
  }

  onQuickReport(report: QuickReport): void {
    const current = this.selectedFilters();
    const map: Record<string, ReportType> = {
      income: 'income',
      'pending-payments': 'payments',
      'active-members': 'members',
      'expired-memberships': 'memberships',
      'best-plans': 'memberships',
      'class-attendance': 'attendance',
    };
    this.selectedFilters.set({ ...current, reportType: map[report.id] || 'general' });
  }

  async exportReport(): Promise<void> {
    this.isExportingReport.set(true);
    this.reportsError.set('');

    try {
      // TODO: Si luego se agrega exportación en backend (CSV/PDF/XLSX), conectar endpoint aquí.
      const csv = this.buildCsvExport();
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      const date = new Date().toISOString().split('T')[0];
      a.href = url;
      a.download = `iron-body-reporte-${date}.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      this.reportsNotice.set({ kind: 'success', message: 'Reporte CSV descargado.' });
    } catch (e: any) {
      this.reportsNotice.set({
        kind: 'error',
        message: e?.message || 'No se pudo exportar el reporte.',
      });
    } finally {
      this.isExportingReport.set(false);
    }
  }

  dismissNotice(): void {
    this.reportsNotice.set(null);
  }

  noticeIcon(kind: 'success' | 'info' | 'error'): string {
    if (kind === 'success') return 'check_circle';
    if (kind === 'error') return 'error';
    return 'info';
  }

  goToMembers(): void {
    this.router.navigateByUrl('/users');
  }

  goToPlans(): void {
    this.router.navigateByUrl('/plans');
  }

  // Helpers
  formatCurrency(value: number): string {
    const formatted = new Intl.NumberFormat('es-CO', {
      style: 'currency',
      currency: 'COP',
      maximumFractionDigits: 0,
    }).format(value || 0);
    return `${formatted} COP`;
  }

  formatNumber(value: number): string {
    return new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(value || 0);
  }

  formatPercentage(value: number, signed: boolean = false): string {
    const v = Number.isFinite(value) ? value : 0;
    const prefix = signed && v > 0 ? '+' : '';
    return `${prefix}${v.toFixed(1)}%`;
  }

  calculateGrowth(current: number, previous: number): number {
    const c = Number.isFinite(current) ? current : 0;
    const p = Number.isFinite(previous) ? previous : 0;
    if (p === 0) return c === 0 ? 0 : 100;
    return ((c - p) / p) * 100;
  }

  private iconForActivityType(type: ActivityRecord['type']): string {
    if (type === 'payment') return 'paid';
    if (type === 'member') return 'person';
    if (type === 'membership') return 'loyalty';
    if (type === 'class') return 'school';
    return 'sell';
  }

  private applyReportFilters(
    payload: ReportsPayload | null,
    filters: ReportsFilter,
  ): ReportsPayload | null {
    if (!payload) return null;

    const { start, end } = this.resolveDateRange(filters);

    const withinRange = (date: string) => {
      const d = new Date(date);
      return d.getTime() >= start.getTime() && d.getTime() <= end.getTime();
    };

    const reportType = filters.reportType;
    const plan = filters.plan;
    const status = filters.status;

    const planLabelMap: Record<Exclude<PlanFilter, 'all'>, string> = {
      monthly: 'Mensual',
      quarterly: 'Trimestral',
      biannual: 'Semestral',
      annual: 'Anual',
      vip: 'VIP',
    };

    const statusLabelMap: Record<Exclude<StatusFilter, 'all'>, MembershipStatusLabel> = {
      active: 'Activos',
      inactive: 'Inactivos',
      expired: 'Vencidos',
      pending: 'Pendientes',
    };

    const activityStatusFilter = (rowStatus: string) => {
      if (status === 'all') return true;
      const s = (rowStatus || '').toLowerCase();
      if (status === 'active')
        return s.includes('activo') || s.includes('complet') || s.includes('pag');
      if (status === 'pending') return s.includes('pend');
      if (status === 'expired') return s.includes('venc');
      if (status === 'inactive') return s.includes('inact') || s.includes('anul');
      return true;
    };

    const planFilterText = plan !== 'all' ? `plan ${planLabelMap[plan]}`.toLowerCase() : '';

    // Plan share: si se filtra por plan, escalamos ingresos/KPIs por la proporción de ventas del plan.
    const totalSales = payload.planSales.reduce((acc, p) => acc + p.sales, 0);
    const selectedPlanSales =
      plan === 'all'
        ? totalSales
        : payload.planSales.find((p) => p.plan.toLowerCase() === planLabelMap[plan].toLowerCase())
            ?.sales || 0;
    const planShare = totalSales > 0 ? selectedPlanSales / totalSales : 1;

    // Nota: En mock no hay datos por plan/estado a nivel fila para todo. Dejamos la estructura preparada.
    // - dateRange filtra activity y revenueSeries
    // - reportType puede enfocar series (aquí solo impacta activity)

    const filteredActivity = payload.activity
      .filter((a) => withinRange(a.date))
      .filter((a) => activityStatusFilter(a.status))
      .filter((a) => {
        if (!planFilterText) return true;
        return (a.description || '').toLowerCase().includes(planFilterText);
      })
      .filter((a) => {
        if (reportType === 'general') return true;
        if (reportType === 'income') return a.type === 'Pago' || a.type === 'Plan';
        if (reportType === 'memberships') return a.type === 'Membresía' || a.type === 'Plan';
        if (reportType === 'members') return a.type === 'Miembro';
        if (reportType === 'payments') return a.type === 'Pago';
        if (reportType === 'classes') return a.type === 'Clase';
        if (reportType === 'attendance') return a.type === 'Clase';
        return true;
      });

    const revenueSeries = payload.revenueSeries
      .map((p) => ({ ...p, date: p.date || this.labelToDate(p.label) }))
      .filter((p) => (p.date ? withinRange(p.date) : true));

    const scaledRevenueSeries =
      plan === 'all'
        ? revenueSeries
        : revenueSeries.map((p) => ({ ...p, revenue: Math.round(p.revenue * planShare) }));

    const planSales =
      plan === 'all'
        ? payload.planSales
        : payload.planSales.filter(
            (p) => p.plan.toLowerCase() === planLabelMap[plan].toLowerCase(),
          );

    const membersByStatus =
      status === 'all'
        ? payload.membersByStatus
        : payload.membersByStatus.filter((m) => m.status === statusLabelMap[status]);

    const paymentsByStatus = (() => {
      if (status === 'all') return payload.paymentsByStatus;
      // mapeo simple status->pagos para que el filtro impacte el gráfico
      const map: Record<Exclude<StatusFilter, 'all'>, PaymentStatusLabel> = {
        active: 'Pagados',
        inactive: 'Anulados',
        expired: 'Vencidos',
        pending: 'Pendientes',
      };
      return payload.paymentsByStatus.filter((p) => p.status === map[status]);
    })();

    const attendanceByClass = payload.attendanceByClass; // plan/status no aplica en mock

    const scoped = (key: ReportType) => reportType === 'general' || reportType === key;

    const scopedRevenue =
      scoped('income') || scoped('payments') || scoped('memberships')
        ? scaledRevenueSeries
        : scaledRevenueSeries;
    const scopedPlanSales = scoped('memberships') || scoped('income') ? planSales : planSales;
    const scopedMembers =
      scoped('members') || scoped('memberships') ? membersByStatus : membersByStatus;
    const scopedAttendance =
      scoped('classes') || scoped('attendance') ? attendanceByClass : attendanceByClass;
    const scopedPayments =
      scoped('payments') || scoped('income') ? paymentsByStatus : paymentsByStatus;

    const scaledKpis: KPIData = {
      ...payload.kpis,
      totalRevenue:
        plan === 'all'
          ? payload.kpis.totalRevenue
          : Math.round(payload.kpis.totalRevenue * planShare),
      monthlyRevenue:
        plan === 'all'
          ? payload.kpis.monthlyRevenue
          : Math.round(payload.kpis.monthlyRevenue * planShare),
      // Si se filtra por estado, reflejamos conteos desde los segmentos
      activeMembers:
        scopedMembers.find((m) => m.status === 'Activos')?.value ||
        (status === 'active' ? payload.kpis.activeMembers : payload.kpis.activeMembers),
      expiredMemberships:
        scopedMembers.find((m) => m.status === 'Vencidos')?.value ||
        (status === 'expired' ? payload.kpis.expiredMemberships : payload.kpis.expiredMemberships),
      pendingPayments: payload.kpis.pendingPayments,
      averageAttendance: payload.kpis.averageAttendance,
    };

    return {
      ...payload,
      kpis: scaledKpis,
      revenueSeries: scopedRevenue,
      planSales: scopedPlanSales,
      membersByStatus: scopedMembers,
      attendanceByClass: scopedAttendance,
      paymentsByStatus: scopedPayments,
      activity: filteredActivity,
    };
  }

  private calculateKpis(payload: ReportsPayload | null): KPIData {
    if (!payload) {
      return {
        totalRevenue: 0,
        monthlyRevenue: 0,
        activeMembers: 0,
        newMembers: 0,
        expiredMemberships: 0,
        expiringSoon: 0,
        pendingPayments: 0,
        completedClasses: 0,
        averageAttendance: 0,
      };
    }

    // KPIs recalculados desde la data filtrada (manteniendo base como fallback)
    const base = payload.kpis;

    const revenueValues = (payload.revenueSeries || []).map((p) => p.revenue);
    const revenueSum = revenueValues.reduce((a, b) => a + b, 0);

    const activeMembers =
      payload.membersByStatus.find((m) => m.status === 'Activos')?.value ?? base.activeMembers;
    const inactiveMembers =
      payload.membersByStatus.find((m) => m.status === 'Inactivos')?.value ?? 0;
    const expiredMemberships =
      payload.membersByStatus.find((m) => m.status === 'Vencidos')?.value ??
      base.expiredMemberships;
    const pendingMemberships =
      payload.membersByStatus.find((m) => m.status === 'Pendientes')?.value ?? 0;

    const avgAttendance = payload.attendanceByClass.length
      ? Math.round(
          payload.attendanceByClass.reduce((a, b) => a + b.attendance, 0) /
            payload.attendanceByClass.length,
        )
      : base.averageAttendance;

    const completedClasses =
      payload.activity.filter((a) => a.type === 'Clase').length || base.completedClasses;

    // Si el usuario filtra por estado, ajustamos KPIs de miembros para reflejar selección
    const status = this.selectedFilters().status;
    const statusAdjusted = (() => {
      if (status === 'active') return { activeMembers, expiredMemberships: 0, expiringSoon: 0 };
      if (status === 'inactive')
        return { activeMembers: 0, expiredMemberships: 0, expiringSoon: 0, inactiveMembers };
      if (status === 'expired') return { activeMembers: 0, expiredMemberships, expiringSoon: 0 };
      if (status === 'pending')
        return { activeMembers: 0, expiredMemberships: 0, expiringSoon: 0, pendingMemberships };
      return {};
    })();

    return {
      ...base,
      // Ingreso total viene del payload (idealmente all-time). El ingreso del período es la suma de la serie filtrada.
      totalRevenue: base.totalRevenue,
      monthlyRevenue: revenueSum || base.monthlyRevenue,
      activeMembers,
      expiredMemberships,
      averageAttendance: avgAttendance,
      completedClasses,
      ...statusAdjusted,
    };
  }

  private buildRevenueChartData(payload: ReportsPayload | null) {
    const series = payload?.revenueSeries || [];
    const labels = series.map((p) => p.label);
    const data = series.map((p) => p.revenue);
    return {
      labels,
      datasets: [
        {
          label: 'Ingresos (COP)',
          data,
          borderColor: '#fbbf24',
          backgroundColor: 'rgba(251, 191, 36, 0.10)',
          fill: true,
          tension: 0.35,
          borderWidth: 2,
        },
      ],
    };
  }

  private buildPlanSalesChartData(payload: ReportsPayload | null) {
    const rows = payload?.planSales || [];
    return {
      labels: rows.map((r) => `Plan ${r.plan}`),
      datasets: [
        {
          label: 'Ventas',
          data: rows.map((r) => r.sales),
          backgroundColor: '#fbbf24',
          borderWidth: 0,
        },
      ],
    };
  }

  private buildMembersStatusChartData(payload: ReportsPayload | null) {
    const rows = payload?.membersByStatus || [];
    const colors: Record<MembersStatusPoint['status'], string> = {
      Activos: '#10b981',
      Inactivos: '#9ca3af',
      Vencidos: '#ef4444',
      Pendientes: '#fbbf24',
    };
    return {
      labels: rows.map((r) => r.status),
      datasets: [
        {
          label: 'Miembros',
          data: rows.map((r) => r.value),
          backgroundColor: rows.map((r) => colors[r.status]),
          borderColor: '#ffffff',
          borderWidth: 2,
        },
      ],
    };
  }

  private buildAttendanceChartData(payload: ReportsPayload | null) {
    const rows = payload?.attendanceByClass || [];
    return {
      labels: rows.map((r) => r.className),
      datasets: [
        {
          label: 'Asistencia (%)',
          data: rows.map((r) => r.attendance),
          backgroundColor: 'rgba(251, 191, 36, 0.85)',
          borderColor: '#fbbf24',
          borderWidth: 1,
        },
      ],
    };
  }

  private buildPaymentsStatusChartData(payload: ReportsPayload | null) {
    const rows = payload?.paymentsByStatus || [];
    const colors: Record<PaymentStatusLabel, string> = {
      Pagados: '#10b981',
      Pendientes: '#fbbf24',
      Vencidos: '#ef4444',
      Anulados: '#9ca3af',
    };
    return {
      labels: rows.map((r) => r.status),
      datasets: [
        {
          label: 'Pagos',
          data: rows.map((r) => r.value),
          backgroundColor: rows.map((r) => colors[r.status]),
          borderColor: '#ffffff',
          borderWidth: 2,
        },
      ],
    };
  }

  private resolveDateRange(filters: ReportsFilter): { start: Date; end: Date } {
    const now = new Date();
    const end = new Date(now);
    end.setHours(23, 59, 59, 999);

    const start = new Date(now);
    start.setHours(0, 0, 0, 0);

    if (filters.dateRange === 'today') {
      return { start, end };
    }

    if (filters.dateRange === 'week') {
      start.setDate(start.getDate() - 6);
      return { start, end };
    }

    if (filters.dateRange === 'month') {
      start.setDate(start.getDate() - 29);
      return { start, end };
    }

    if (filters.dateRange === 'thisMonth') {
      start.setDate(1);
      return { start, end };
    }

    if (filters.dateRange === 'lastMonth') {
      const s = new Date(now.getFullYear(), now.getMonth() - 1, 1);
      const e = new Date(now.getFullYear(), now.getMonth(), 0);
      s.setHours(0, 0, 0, 0);
      e.setHours(23, 59, 59, 999);
      return { start: s, end: e };
    }

    if (filters.dateRange === 'custom') {
      const s = filters.startDate ? new Date(filters.startDate) : start;
      const e = filters.endDate ? new Date(filters.endDate) : end;
      s.setHours(0, 0, 0, 0);
      e.setHours(23, 59, 59, 999);
      return { start: s, end: e };
    }

    return { start, end };
  }

  private labelToDate(label: string): string {
    // Para series por mes (Enero, Febrero...) usamos el día 1 del mes en el año actual.
    const map: Record<string, number> = {
      Enero: 0,
      Febrero: 1,
      Marzo: 2,
      Abril: 3,
      Mayo: 4,
      Junio: 5,
      Julio: 6,
      Agosto: 7,
      Septiembre: 8,
      Octubre: 9,
      Noviembre: 10,
      Diciembre: 11,
    };
    const monthIndex = map[label] ?? new Date().getMonth();
    const d = new Date(new Date().getFullYear(), monthIndex, 1);
    return d.toISOString().split('T')[0];
  }

  private previousPeriodRevenue(): number {
    const filters = this.selectedFilters();
    const { start, end } = this.resolveDateRange(filters);
    const durationMs = end.getTime() - start.getTime() + 1;

    const prevEnd = new Date(start.getTime() - 1);
    const prevStart = new Date(prevEnd.getTime() - durationMs + 1);

    const baseSeries = this.reportsData()?.revenueSeries || [];
    const sum = baseSeries
      .map((p) => ({ ...p, date: p.date || this.labelToDate(p.label) }))
      .filter((p) => {
        if (!p.date) return false;
        const d = new Date(p.date).getTime();
        return d >= prevStart.getTime() && d <= prevEnd.getTime();
      })
      .reduce((acc, p) => acc + p.revenue, 0);

    if (sum > 0) return sum;

    const current = this.kpis().monthlyRevenue;
    const growth = 12.7;
    return current / (1 + growth / 100);
  }

  private async fetchAllPages<T>(
    fetchPage: (page: number) => Observable<PaginatedResponse<T>>,
    maxPages = this.MAX_PAGES_FETCH,
  ): Promise<T[]> {
    const all: T[] = [];
    let page = 1;

    while (page <= maxPages) {
      const res = await firstValueFrom(fetchPage(page));
      all.push(...(res?.data || []));
      if (!res || res.current_page >= res.last_page) break;
      page += 1;
    }

    return all;
  }

  private async tryLoadFromApi(): Promise<ReportsPayload> {
    const [dashboard, payments, classes, users] = await Promise.all([
      firstValueFrom(this.apiService.getDashboardStats()),
      this.fetchAllPages<PaymentSummary>((page) => this.apiService.getPayments(page)),
      this.fetchAllPages<ClassSummary>((page) => this.apiService.getClasses(page)),
      this.fetchAllPages<UserSummary>((page) => this.apiService.getUsers(page)),
    ]);

    return this.buildPayloadFromApi(dashboard, payments, classes, users);
  }

  private buildPayloadFromApi(
    dashboard: DashboardStats,
    payments: PaymentSummary[],
    classes: ClassSummary[],
    users: UserSummary[],
  ): ReportsPayload {
    const fallback = this.buildMockPayload();
    const filters = this.selectedFilters();
    const { start, end } = this.resolveDateRange(filters);

    const getDateKey = (isoLike?: string | null) => {
      if (!isoLike) return null;
      const d = new Date(isoLike);
      if (Number.isNaN(d.getTime())) return null;
      return d.toISOString().split('T')[0];
    };

    const paymentDate = (p: PaymentSummary) => getDateKey(p.paid_at || p.created_at);

    const paidPayments = payments.filter((p) => String(p.status).toLowerCase() === 'paid');
    const totalRevenueFromPayments = paidPayments.reduce(
      (acc, p) => acc + (Number(p.amount) || 0),
      0,
    );

    const periodRevenue = paidPayments
      .filter((p) => {
        const key = paymentDate(p);
        if (!key) return false;
        const d = new Date(key);
        return d.getTime() >= start.getTime() && d.getTime() <= end.getTime();
      })
      .reduce((acc, p) => acc + (Number(p.amount) || 0), 0);

    // Revenue series: diaria para el último año.
    const seriesEnd = new Date();
    seriesEnd.setHours(23, 59, 59, 999);
    const seriesStart = new Date(seriesEnd);
    seriesStart.setDate(seriesStart.getDate() - 364);
    seriesStart.setHours(0, 0, 0, 0);

    const revenueByDay = new Map<string, number>();
    paidPayments.forEach((p) => {
      const key = paymentDate(p);
      if (!key) return;
      const ts = new Date(key).getTime();
      if (ts < seriesStart.getTime() || ts > seriesEnd.getTime()) return;
      revenueByDay.set(key, (revenueByDay.get(key) || 0) + (Number(p.amount) || 0));
    });

    const revenueSeries: RevenuePoint[] = [];
    for (
      let d = new Date(seriesStart);
      d.getTime() <= seriesEnd.getTime();
      d.setDate(d.getDate() + 1)
    ) {
      const key = d.toISOString().split('T')[0];
      revenueSeries.push({
        label: this.formatShortDateLabel(d),
        revenue: Math.round(revenueByDay.get(key) || 0),
        date: key,
      });
    }

    const planCounts = new Map<string, number>();
    paidPayments.forEach((p) => {
      const planName = (p.plan?.name || 'Sin plan').trim();
      planCounts.set(planName, (planCounts.get(planName) || 0) + 1);
    });

    const planSales: PlanSalesPoint[] = Array.from(planCounts.entries())
      .map(([plan, sales]) => ({ plan, sales }))
      .sort((a, b) => b.sales - a.sales)
      .slice(0, 8);

    const attendanceByClass: AttendancePoint[] = (classes || [])
      .map((c) => {
        const cap = Number(c.max_capacity) || 0;
        const enrolled = Number(c.enrolled_count) || 0;
        const pct = cap > 0 ? Math.round((enrolled / cap) * 100) : 0;
        return {
          className: c.name,
          attendance: Math.max(0, Math.min(100, pct)),
        };
      })
      .sort((a, b) => b.attendance - a.attendance)
      .slice(0, 8);

    const paymentsByStatus = (() => {
      const map: Record<PaymentStatusLabel, number> = {
        Pagados: 0,
        Pendientes: 0,
        Vencidos: 0,
        Anulados: 0,
      };

      payments.forEach((p) => {
        const s = String(p.status || '').toLowerCase();
        if (s === 'paid') map.Pagados += 1;
        else if (s === 'pending') map.Pendientes += 1;
        else if (s === 'failed' || s === 'refunded') map.Anulados += 1;
      });

      return (Object.keys(map) as PaymentStatusLabel[]).map((status) => ({
        status,
        value: map[status],
      }));
    })();

    const activity: ActivityRow[] = [];

    payments
      .slice()
      .sort((a, b) => {
        const da = new Date(a.paid_at || a.created_at).getTime();
        const db = new Date(b.paid_at || b.created_at).getTime();
        return db - da;
      })
      .slice(0, 20)
      .forEach((p) => {
        const dateKey = paymentDate(p) || new Date().toISOString().split('T')[0];
        const planName = p.plan?.name ? `Plan ${p.plan.name}` : 'Pago';
        const userName = p.user?.name ? ` - ${p.user.name}` : '';
        const statusLabel = (() => {
          const s = String(p.status || '').toLowerCase();
          if (s === 'paid') return 'Pagado';
          if (s === 'pending') return 'Pendiente';
          if (s === 'failed' || s === 'refunded') return 'Anulado';
          return 'Pendiente';
        })();

        activity.push({
          date: dateKey,
          type: 'Pago',
          description: `${planName}${userName}`,
          value: Number(p.amount) || 0,
          status: statusLabel,
        });
      });

    users
      .slice()
      .sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())
      .slice(0, 10)
      .forEach((u) => {
        const dateKey = getDateKey(u.created_at) || new Date().toISOString().split('T')[0];
        activity.push({
          date: dateKey,
          type: 'Miembro',
          description: `Nuevo miembro: ${u.name}`,
          value: 0,
          status: 'Activo',
        });
      });

    classes
      .slice()
      .sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())
      .slice(0, 10)
      .forEach((c) => {
        const dateKey = getDateKey(c.created_at) || new Date().toISOString().split('T')[0];
        activity.push({
          date: dateKey,
          type: 'Clase',
          description: `Clase creada: ${c.name}`,
          value: Number(c.enrolled_count) || 0,
          status: String(c.status || 'Activo'),
        });
      });

    activity.sort((a, b) => new Date(b.date).getTime() - new Date(a.date).getTime());

    const newMembersInPeriod = users.filter((u) => {
      const d = new Date(u.created_at);
      return d.getTime() >= start.getTime() && d.getTime() <= end.getTime();
    }).length;

    const avgAttendance = attendanceByClass.length
      ? Math.round(
          attendanceByClass.reduce((acc, p) => acc + p.attendance, 0) / attendanceByClass.length,
        )
      : fallback.kpis.averageAttendance;

    const pendingPayments = payments.filter(
      (p) => String(p.status || '').toLowerCase() === 'pending',
    ).length;

    return {
      ...fallback,
      kpis: {
        ...fallback.kpis,
        totalRevenue: Math.round(
          Number(dashboard?.revenue) || totalRevenueFromPayments || fallback.kpis.totalRevenue,
        ),
        monthlyRevenue: Math.round(periodRevenue || fallback.kpis.monthlyRevenue),
        activeMembers: Number(dashboard?.users) || fallback.kpis.activeMembers,
        newMembers: newMembersInPeriod,
        pendingPayments,
        averageAttendance: avgAttendance,
        completedClasses: Number(dashboard?.classes) || fallback.kpis.completedClasses,
      },
      revenueSeries,
      planSales: planSales.length ? planSales : fallback.planSales,
      attendanceByClass: attendanceByClass.length ? attendanceByClass : fallback.attendanceByClass,
      paymentsByStatus,
      activity: activity.length ? activity : fallback.activity,
    };
  }

  private formatShortDateLabel(date: Date): string {
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    return `${day}/${month}`;
  }

  private buildCsvExport(): string {
    const filters = this.selectedFilters();
    const k = this.kpis();
    const activity = this.filteredReportsData()?.activity || [];
    const date = new Date().toISOString().split('T')[0];

    const lines: string[] = [];
    lines.push('Iron Body Admin - Analítica y reportes');
    lines.push(`Fecha de exportación,${date}`);
    lines.push(`Rango,${filters.dateRange}`);
    lines.push(`Tipo de reporte,${filters.reportType}`);
    lines.push(`Plan,${filters.plan}`);
    lines.push(`Estado,${filters.status}`);
    lines.push('');
    lines.push('KPIs');
    lines.push(`Ingreso total,${k.totalRevenue}`);
    lines.push(`Ingreso mensual,${k.monthlyRevenue}`);
    lines.push(`Miembros activos,${k.activeMembers}`);
    lines.push(`Nuevos miembros,${k.newMembers}`);
    lines.push(`Membresías vencidas,${k.expiredMemberships}`);
    lines.push(`Membresías por vencer,${k.expiringSoon}`);
    lines.push(`Pagos pendientes,${k.pendingPayments}`);
    lines.push(`Clases realizadas,${k.completedClasses}`);
    lines.push(`Asistencia promedio (%),${k.averageAttendance}`);
    lines.push('');
    lines.push('Actividad reciente');
    lines.push('Fecha,Tipo,Descripción,Valor,Estado');
    activity.forEach((a) => {
      const desc = String(a.description).replace(/"/g, '""');
      lines.push(`${a.date},${a.type},"${desc}",${a.value},${a.status}`);
    });
    return lines.join('\n');
  }

  private buildMockPayload(): ReportsPayload {
    return {
      kpis: {
        totalRevenue: 24500000,
        monthlyRevenue: 8450000,
        activeMembers: 126,
        newMembers: 18,
        expiredMemberships: 9,
        expiringSoon: 14,
        pendingPayments: 7,
        completedClasses: 42,
        averageAttendance: 78,
      },
      revenueSeries: [
        { label: 'Enero', revenue: 5200000, date: '2026-01-01' },
        { label: 'Febrero', revenue: 6100000, date: '2026-02-01' },
        { label: 'Marzo', revenue: 7300000, date: '2026-03-01' },
        { label: 'Abril', revenue: 8450000, date: '2026-04-01' },
      ],
      planSales: [
        { plan: 'Mensual', sales: 48 },
        { plan: 'Trimestral', sales: 22 },
        { plan: 'Semestral', sales: 14 },
        { plan: 'Anual', sales: 9 },
        { plan: 'VIP', sales: 7 },
      ],
      membersByStatus: [
        { status: 'Activos', value: 126 },
        { status: 'Inactivos', value: 32 },
        { status: 'Vencidos', value: 9 },
        { status: 'Pendientes', value: 5 },
      ],
      attendanceByClass: [
        { className: 'Spinning', attendance: 85 },
        { className: 'Funcional', attendance: 72 },
        { className: 'Yoga', attendance: 44 },
        { className: 'Cross Training', attendance: 68 },
        { className: 'Boxeo', attendance: 51 },
        { className: 'Cardio', attendance: 76 },
      ],
      paymentsByStatus: [
        { status: 'Pagados', value: 312 },
        { status: 'Pendientes', value: 45 },
        { status: 'Vencidos', value: 12 },
        { status: 'Anulados', value: 8 },
      ],
      activity: [
        {
          date: '2026-04-30',
          type: 'Pago',
          description: 'Pago recibido - Plan Mensual',
          value: 80000,
          status: 'Completado',
        },
        {
          date: '2026-04-29',
          type: 'Miembro',
          description: 'Nuevo miembro registrado',
          value: 0,
          status: 'Activo',
        },
        {
          date: '2026-04-28',
          type: 'Membresía',
          description: 'Membresía vencida',
          value: 0,
          status: 'Vencida',
        },
        {
          date: '2026-04-27',
          type: 'Clase',
          description: 'Clase Spinning completada',
          value: 18,
          status: 'Completado',
        },
      ],
    };
  }
}
