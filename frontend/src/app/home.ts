import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterModule } from '@angular/router';
import { CommonModule } from '@angular/common';
import { ApiService, DashboardStats } from './services/api.service';

@Component({
  selector: 'app-home',
  standalone: true,
  imports: [RouterModule, CommonModule],
  template: `
    <section class="dashboard-page">
      <header class="dashboard-header">
        <div>
          <span class="eyebrow">IRONBODY CRM</span>
          <h1>Panel de Control</h1>
          <p>Vista ejecutiva de miembros, ingresos y operaciones principales del gimnasio.</p>
        </div>
        <div class="header-actions">
          <a routerLink="/reports" class="outline-btn">
            <span class="material-symbols-outlined" aria-hidden="true">monitoring</span>
            Reportes
          </a>
          <a routerLink="/plans" class="gold-btn">
            <span class="material-symbols-outlined" aria-hidden="true">add</span>
            Nueva membresía
          </a>
        </div>
      </header>

      <section class="metrics-grid">
        <article class="metric-card" *ngFor="let metric of metrics()">
          <div class="metric-icon">
            <span class="material-symbols-outlined" aria-hidden="true">{{ metric.icon }}</span>
          </div>
          <div>
            <p>{{ metric.label }}</p>
            <strong>{{ metric.value }}</strong>
            <small>{{ metric.helper }}</small>
          </div>
        </article>
      </section>

      <section class="dashboard-grid">
        <article class="feature-card">
          <div>
            <span class="eyebrow">Resumen comercial</span>
            <h2>{{ stats().revenue | currency: 'COP' : 'symbol' : '1.0-0' }}</h2>
            <p>
              Ingresos confirmados registrados por el backend. Usa esta vista para validar
              rendimiento mensual y planes activos.
            </p>
          </div>
          <div class="progress-stack">
            <div>
              <span>Meta mensual</span>
              <strong>78%</strong>
            </div>
            <div class="progress-track"><i style="width:78%"></i></div>
            <a routerLink="/payments">Ver pagos</a>
          </div>
        </article>

        <article class="panel-card">
          <div class="panel-title">
            <h3>Accesos rápidos</h3>
            <span class="material-symbols-outlined" aria-hidden="true">bolt</span>
          </div>
          <div class="quick-grid">
            <a *ngFor="let action of quickActions" [routerLink]="action.path">
              <span class="material-symbols-outlined" aria-hidden="true">{{ action.icon }}</span>
              <strong>{{ action.label }}</strong>
              <small>{{ action.detail }}</small>
            </a>
          </div>
        </article>
      </section>

      <section class="lower-grid">
        <article class="panel-card">
          <div class="panel-title">
            <h3>Tareas pendientes</h3>
            <a routerLink="/reports">Ver todo</a>
          </div>
          <div class="task-list">
            <div *ngFor="let task of tasks">
              <span class="task-icon material-symbols-outlined" aria-hidden="true">{{
                task.icon
              }}</span>
              <div>
                <strong>{{ task.title }}</strong>
                <small>{{ task.detail }}</small>
              </div>
              <em>{{ task.tag }}</em>
            </div>
          </div>
        </article>

        <article class="panel-card modules-card">
          <div class="panel-title">
            <h3>Módulos del sistema</h3>
            <span class="material-symbols-outlined" aria-hidden="true">apps</span>
          </div>
          <div class="module-list">
            <a *ngFor="let module of modules" [routerLink]="module.path">
              <span class="material-symbols-outlined" aria-hidden="true">{{ module.icon }}</span>
              <div>
                <strong>{{ module.label }}</strong>
                <small>{{ module.detail }}</small>
              </div>
            </a>
          </div>
        </article>
      </section>
    </section>
  `,
  styles: [
    `
      .dashboard-page {
        max-width: 1280px;
        margin: 0 auto;
        color: #121212;
      }
      .dashboard-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 2rem;
        margin-bottom: 2.5rem;
        flex-wrap: wrap;
        padding-bottom: 1.5rem;
        border-bottom: 1px solid #f0f0f0;
      }
      .eyebrow {
        display: block;
        color: #735c00;
        font:
          600 0.7rem 'Space Grotesk',
          sans-serif;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        margin-bottom: 0.75rem;
        font-weight: 600;
      }
      h1 {
        font:
          700 2.5rem/1.2 Inter,
          sans-serif;
        margin: 0 0 0.8rem;
        color: #0a0a0a;
        letter-spacing: -0.02em;
      }
      .dashboard-header p {
        color: #666;
        font-size: 1rem;
        line-height: 1.65;
        max-width: 700px;
        margin: 0;
      }
      .header-actions {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
      }
      .outline-btn,
      .gold-btn {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        border-radius: 8px;
        padding: 0.85rem 1.5rem;
        font:
          600 0.85rem Inter,
          sans-serif;
        text-decoration: none;
        transition: all 200ms ease;
      }
      .outline-btn {
        border: 1.5px solid #d0d0d0;
        background: #fff;
        color: #0a0a0a;
      }
      .outline-btn:hover {
        border-color: #a0a0a0;
        background: #f5f5f5;
      }
      .gold-btn {
        border: none;
        background: #facc15;
        color: #000;
        font-weight: 600;
        box-shadow: 0 2px 8px rgba(250, 204, 21, 0.15);
      }
      .gold-btn:hover {
        background: #f0c00e;
        box-shadow: 0 4px 12px rgba(250, 204, 21, 0.25);
        transform: translateY(-1px);
      }
      .metrics-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1.25rem;
        margin-bottom: 2rem;
      }
      .metric-card,
      .panel-card,
      .feature-card {
        background: #fff;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
        transition: all 200ms ease;
      }
      .metric-card {
        display: flex;
        gap: 1.25rem;
        align-items: center;
        padding: 1.5rem;
      }
      .metric-card:hover {
        border-color: #d0d0d0;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        transform: translateY(-2px);
      }
      .metric-icon {
        display: grid;
        place-items: center;
        width: 48px;
        height: 48px;
        border-radius: 10px;
        background: #0a0a0a;
        color: #facc15;
        flex-shrink: 0;
      }
      .metric-card p {
        margin: 0;
        color: #666;
        font:
          600 0.7rem 'Space Grotesk',
          sans-serif;
        text-transform: uppercase;
        letter-spacing: 0.08em;
      }
      .metric-card strong {
        display: block;
        font:
          700 1.9rem/1.1 Inter,
          sans-serif;
        margin: 0.35rem 0;
        color: #0a0a0a;
        letter-spacing: -0.01em;
      }
      .metric-card small {
        color: #059669;
        font-weight: 600;
        font-size: 0.8rem;
      }
      .dashboard-grid {
        display: grid;
        grid-template-columns: 1.4fr 0.93fr;
        gap: 1.5rem;
        margin-bottom: 2rem;
      }
      .feature-card {
        display: flex;
        justify-content: space-between;
        gap: 2.5rem;
        min-height: 280px;
        padding: 2.5rem;
        background: linear-gradient(135deg, #fff 0%, #fffcf0 100%);
        border: 1px solid #f5e6d3;
      }
      .feature-card h2 {
        font:
          700 3.2rem/1 Inter,
          sans-serif;
        margin: 0 0 1rem;
        color: #0a0a0a;
        letter-spacing: -0.02em;
      }
      .feature-card p {
        color: #666;
        max-width: 560px;
        font-size: 0.98rem;
        line-height: 1.65;
      }
      .progress-stack {
        align-self: end;
        min-width: 240px;
      }
      .progress-stack div:first-child {
        display: flex;
        justify-content: space-between;
        font-family: Inter, sans-serif;
        font-weight: 600;
        color: #0a0a0a;
        margin-bottom: 0.5rem;
      }
      .progress-track {
        height: 8px;
        background: #e8e8e8;
        border-radius: 4px;
        margin: 1rem 0;
        overflow: hidden;
      }
      .progress-track i {
        display: block;
        height: 100%;
        border-radius: 4px;
        background: #facc15;
        transition: width 300ms ease;
      }
      .progress-stack a {
        color: #735c00;
        font-weight: 600;
        text-decoration: none;
        font-size: 0.9rem;
        transition: color 200ms ease;
      }
      .progress-stack a:hover {
        color: #5a4700;
        text-decoration: underline;
      }
      .panel-card {
        padding: 1.75rem;
      }
      .panel-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #f0f0f0;
      }
      .panel-title h3 {
        font:
          600 1.35rem Inter,
          sans-serif;
        margin: 0;
        color: #0a0a0a;
        letter-spacing: -0.01em;
      }
      .panel-title a {
        color: #735c00;
        font-weight: 600;
        text-decoration: none;
        font-size: 0.9rem;
        transition: color 200ms ease;
      }
      .panel-title a:hover {
        color: #5a4700;
      }
      .quick-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
      }
      .quick-grid a,
      .module-list a {
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        color: #0a0a0a;
        text-decoration: none;
        transition: all 200ms ease;
        background: #fafafa;
      }
      .quick-grid a {
        padding: 1.25rem;
      }
      .quick-grid a:hover,
      .module-list a:hover {
        border-color: #facc15;
        background: #fffcf0;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(250, 204, 21, 0.1);
      }
      .quick-grid span,
      .module-list span {
        color: #facc15;
        font-size: 1.25rem;
      }
      .quick-grid strong,
      .quick-grid small {
        display: block;
      }
      .quick-grid strong,
      .module-list strong {
        font:
          600 0.95rem Inter,
          sans-serif;
        color: #0a0a0a;
        margin: 0.35rem 0;
      }
      .quick-grid small,
      .module-list small,
      .task-list small {
        color: #888;
        font-size: 0.85rem;
      }
      .lower-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
      }
      .task-list {
        display: grid;
        gap: 0.9rem;
      }
      .task-list > div {
        display: grid;
        grid-template-columns: auto 1fr auto;
        align-items: center;
        gap: 1rem;
        padding: 1.1rem;
        border-radius: 10px;
        background: #f8f8f8;
        border: 1px solid #e8e8e8;
        transition: all 200ms ease;
      }
      .task-list > div:hover {
        background: #fff;
        border-color: #e0e0e0;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
      }
      .task-icon {
        display: grid;
        place-items: center;
        width: 40px;
        height: 40px;
        border-radius: 8px;
        background: #fff7e8;
        color: #b45309;
        flex-shrink: 0;
        font-size: 1.25rem;
      }
      .task-list strong {
        display: block;
        font:
          600 0.95rem Inter,
          sans-serif;
        color: #0a0a0a;
      }
      .task-list em {
        font-style: normal;
        background: #fff7e8;
        color: #92400e;
        border-radius: 12px;
        padding: 0.35rem 0.75rem;
        font-size: 0.75rem;
        font-weight: 600;
      }
      .module-list {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
      }
      .module-list a {
        display: flex;
        gap: 1rem;
        align-items: flex-start;
        padding: 1.25rem;
        background: #fafafa;
      }
      .module-list span {
        margin-top: 0.25rem;
      }
      @media (max-width: 1000px) {
        .metrics-grid,
        .dashboard-grid,
        .lower-grid {
          grid-template-columns: 1fr;
        }
        .feature-card {
          display: grid;
          gap: 1.5rem;
        }
        .progress-stack {
          min-width: 0;
        }
        .module-list,
        .quick-grid {
          grid-template-columns: 1fr;
        }
      }
      @media (max-width: 700px) {
        h1 {
          font-size: 2rem;
        }
        .dashboard-header {
          gap: 1rem;
          flex-direction: column;
          align-items: flex-start;
        }
        .header-actions {
          width: 100%;
        }
        .feature-card h2 {
          font-size: 2.4rem;
        }
        .metrics-grid {
          grid-template-columns: 1fr;
          gap: 1rem;
        }
        .metric-card {
          padding: 1.25rem;
        }
      }
    `,
  ],
})
export default class HomeComponent implements OnInit {
  private api = inject(ApiService);
  protected stats = signal<DashboardStats>({
    users: 0,
    active_plans: 0,
    payments: 0,
    revenue: 0,
  });

  protected metrics = signal([
    { label: 'Miembros', value: '0', helper: 'Registrados', icon: 'group' },
    { label: 'Ingresos', value: '$0', helper: 'Pagos completados', icon: 'payments' },
    { label: 'Planes activos', value: '0', helper: 'Disponibles', icon: 'loyalty' },
    { label: 'Pagos', value: '0', helper: 'Historial total', icon: 'receipt_long' },
  ]);

  protected quickActions = [
    { label: 'Miembros', detail: 'Gestionar perfiles', icon: 'group', path: '/users' },
    { label: 'Planes', detail: 'Membresías y precios', icon: 'loyalty', path: '/plans' },
    { label: 'Pagos', detail: 'Cobros y recibos', icon: 'payments', path: '/payments' },
    { label: 'Clases', detail: 'Agenda y cupos', icon: 'calendar_month', path: '/classes' },
  ];

  protected tasks = [
    {
      title: 'Membresías por vencer',
      detail: 'Enviar recordatorios de renovación',
      icon: 'notification_important',
      tag: 'Hoy',
    },
    {
      title: 'Pagos pendientes',
      detail: 'Revisar cobranza y referencias',
      icon: 'pending_actions',
      tag: 'Urgente',
    },
    {
      title: 'Reporte mensual',
      detail: 'Consolidar ingresos y usuarios',
      icon: 'analytics',
      tag: 'Planificado',
    },
  ];

  protected modules = [
    {
      label: 'Rutinas',
      detail: 'Plantillas de entrenamiento',
      icon: 'fitness_center',
      path: '/routines',
    },
    { label: 'Entrenadores', detail: 'Equipo y asignaciones', icon: 'badge', path: '/trainers' },
    { label: 'Mercadeo', detail: 'Campañas y cupones', icon: 'campaign', path: '/marketing' },
    { label: 'Configuración', detail: 'Ajustes globales', icon: 'settings', path: '/settings' },
  ];

  ngOnInit(): void {
    this.api.getDashboardStats().subscribe({
      next: (stats) => {
        this.stats.set(stats);
        this.metrics.set([
          { label: 'Miembros', value: String(stats.users), helper: 'Registrados', icon: 'group' },
          {
            label: 'Ingresos',
            value: this.formatCurrency(stats.revenue),
            helper: 'Pagos completados',
            icon: 'payments',
          },
          {
            label: 'Planes activos',
            value: String(stats.active_plans),
            helper: 'Disponibles',
            icon: 'loyalty',
          },
          {
            label: 'Pagos',
            value: String(stats.payments),
            helper: 'Historial total',
            icon: 'receipt_long',
          },
        ]);
      },
      error: () => {
        this.stats.set({ users: 0, active_plans: 0, payments: 0, revenue: 0 });
      },
    });
  }

  private formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'COP',
      maximumFractionDigits: 0,
    }).format(value);
  }
}
