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
          <h2>{{ stats().revenue | currency:'USD':'symbol':'1.0-0' }}</h2>
          <p>Ingresos confirmados registrados por el backend. Usa esta vista para validar rendimiento mensual y planes activos.</p>
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
          <a routerLink="/reports">View all</a>
        </div>
        <div class="task-list">
          <div *ngFor="let task of tasks">
            <span class="task-icon material-symbols-outlined" aria-hidden="true">{{ task.icon }}</span>
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
  styles: [`
    .dashboard-page{max-width:1280px;margin:0 auto;color:#121212}.dashboard-header{display:flex;justify-content:space-between;align-items:flex-end;gap:1.5rem;margin-bottom:2rem;flex-wrap:wrap}.eyebrow{display:block;color:#785a00;font:700 .75rem Lexend,sans-serif;letter-spacing:.05em;text-transform:uppercase;margin-bottom:.55rem}h1{font:700 2.25rem/1.15 Lexend,sans-serif;margin:0 0 .6rem}.dashboard-header p{color:#5f5e5e;font-size:1.05rem;line-height:1.6;max-width:680px;margin:0}.header-actions{display:flex;gap:1rem;flex-wrap:wrap}.outline-btn,.gold-btn{display:flex;align-items:center;gap:.5rem;border-radius:10px;padding:.75rem 1.25rem;font:700 .82rem Lexend,sans-serif;text-decoration:none}.outline-btn{border:1px solid #121212;background:#fff;color:#121212}.gold-btn{border:1px solid #eab308;background:#eab308;color:#121212}
    .metrics-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem;margin-bottom:1.25rem}.metric-card,.panel-card,.feature-card{background:#fff;border:1px solid #eee;border-radius:18px;box-shadow:0 4px 20px rgba(0,0,0,.04)}.metric-card{display:flex;gap:1rem;align-items:center;padding:1.25rem}.metric-icon{display:grid;place-items:center;width:44px;height:44px;border-radius:12px;background:#0a0a0a;color:#eab308}.metric-card p{margin:0;color:#5f5e5e;font:700 .75rem Lexend,sans-serif;text-transform:uppercase}.metric-card strong{display:block;font:700 1.7rem/1.1 Lexend,sans-serif;margin:.25rem 0}.metric-card small{color:#059669;font-weight:600}
    .dashboard-grid{display:grid;grid-template-columns:1.35fr .9fr;gap:1.25rem;margin-bottom:1.25rem}.feature-card{display:flex;justify-content:space-between;gap:2rem;min-height:280px;padding:2rem;background:linear-gradient(135deg,#fff 0%,#fff8db 100%)}.feature-card h2{font:700 3rem/1 Lexend,sans-serif;margin:0 0 1rem}.feature-card p{color:#5f5e5e;max-width:560px}.progress-stack{align-self:end;min-width:220px}.progress-stack div:first-child{display:flex;justify-content:space-between;font-family:Lexend,sans-serif}.progress-track{height:10px;background:#f1f1f1;border-radius:999px;margin:1rem 0}.progress-track i{display:block;height:100%;border-radius:999px;background:#eab308}.progress-stack a{color:#785a00;font-weight:700;text-decoration:none}
    .panel-card{padding:1.5rem}.panel-title{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1.25rem}.panel-title h3{font:700 1.2rem Lexend,sans-serif;margin:0}.panel-title a{color:#785a00;font-weight:700;text-decoration:none}.quick-grid{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}.quick-grid a,.module-list a{border:1px solid #eee;border-radius:14px;color:#121212;text-decoration:none;transition:160ms ease}.quick-grid a{padding:1rem}.quick-grid a:hover,.module-list a:hover{border-color:#eab308;transform:translateY(-1px)}.quick-grid span{color:#eab308}.quick-grid strong,.quick-grid small{display:block}.quick-grid small,.module-list small,.task-list small{color:#5f5e5e}
    .lower-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem}.task-list{display:grid;gap:.85rem}.task-list>div{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:.85rem;padding:.9rem;border-radius:14px;background:#fafafa}.task-icon{display:grid;place-items:center;width:36px;height:36px;border-radius:10px;background:#fff7d6;color:#b45309}.task-list strong,.module-list strong{display:block}.task-list em{font-style:normal;background:#fff7d6;color:#92400e;border-radius:999px;padding:.3rem .65rem;font-size:.75rem;font-weight:700}.module-list{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}.module-list a{display:flex;gap:.75rem;align-items:flex-start;padding:.9rem}.module-list span{color:#eab308}
    @media(max-width:1000px){.metrics-grid,.dashboard-grid,.lower-grid{grid-template-columns:1fr}.feature-card{display:grid}.progress-stack{min-width:0}.module-list,.quick-grid{grid-template-columns:1fr}}@media(max-width:700px){h1{font-size:1.8rem}.feature-card h2{font-size:2.2rem}.metrics-grid{grid-template-columns:1fr}}
  `]
})
export default class HomeComponent implements OnInit {
  private api = inject(ApiService);
  protected stats = signal<DashboardStats>({
    users: 0,
    active_plans: 0,
    payments: 0,
    revenue: 0
  });

  protected metrics = signal([
    { label: 'Members', value: '0', helper: 'Registrados', icon: 'group' },
    { label: 'Revenue', value: '$0', helper: 'Pagos completados', icon: 'payments' },
    { label: 'Active Plans', value: '0', helper: 'Disponibles', icon: 'loyalty' },
    { label: 'Payments', value: '0', helper: 'Historial total', icon: 'receipt_long' }
  ]);

  protected quickActions = [
    { label: 'Members', detail: 'Gestionar perfiles', icon: 'group', path: '/users' },
    { label: 'Plans', detail: 'Membresías y precios', icon: 'loyalty', path: '/plans' },
    { label: 'Payments', detail: 'Cobros y recibos', icon: 'payments', path: '/payments' },
    { label: 'Classes', detail: 'Agenda y cupos', icon: 'calendar_month', path: '/classes' }
  ];

  protected tasks = [
    { title: 'Membresías por vencer', detail: 'Enviar recordatorios de renovación', icon: 'notification_important', tag: 'Hoy' },
    { title: 'Pagos pendientes', detail: 'Revisar cobranza y referencias', icon: 'pending_actions', tag: 'Urgente' },
    { title: 'Reporte mensual', detail: 'Consolidar ingresos y usuarios', icon: 'analytics', tag: 'Planificado' }
  ];

  protected modules = [
    { label: 'Rutinas', detail: 'Plantillas de entrenamiento', icon: 'fitness_center', path: '/routines' },
    { label: 'Entrenadores', detail: 'Staff y asignaciones', icon: 'badge', path: '/trainers' },
    { label: 'Marketing', detail: 'Campañas y cupones', icon: 'campaign', path: '/marketing' },
    { label: 'Configuración', detail: 'Ajustes globales', icon: 'settings', path: '/settings' }
  ];

  ngOnInit(): void {
    this.api.getDashboardStats().subscribe({
      next: (stats) => {
        this.stats.set(stats);
        this.metrics.set([
          { label: 'Members', value: String(stats.users), helper: 'Registrados', icon: 'group' },
          { label: 'Revenue', value: this.formatCurrency(stats.revenue), helper: 'Pagos completados', icon: 'payments' },
          { label: 'Active Plans', value: String(stats.active_plans), helper: 'Disponibles', icon: 'loyalty' },
          { label: 'Payments', value: String(stats.payments), helper: 'Historial total', icon: 'receipt_long' }
        ]);
      },
      error: () => {
        this.stats.set({ users: 0, active_plans: 0, payments: 0, revenue: 0 });
      }
    });
  }

  private formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'USD',
      maximumFractionDigits: 0
    }).format(value);
  }
}
