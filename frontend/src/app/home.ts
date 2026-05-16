import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterModule } from '@angular/router';
import { CommonModule } from '@angular/common';
import { ApiService, DashboardStats } from './services/api.service';
import { LottieIconComponent } from './shared/components/lottie-icon/lottie-icon.component';

interface Metric {
  label: string;
  value: string;
  helper: string;
  lottie: string;
}

interface QuickAction {
  label: string;
  detail: string;
  lottie: string;
  path: string;
}

interface Task {
  title: string;
  detail: string;
  lottie: string;
  tag: string;
  tagColor: string;
}

interface SystemModule {
  label: string;
  detail: string;
  lottie: string;
  path: string;
}

@Component({
  selector: 'app-home',
  standalone: true,
  imports: [RouterModule, CommonModule, LottieIconComponent],
  template: `
    <section class="dashboard-page">

      <!-- ═══════════════════════════════════════════════════════════
           HEADER con panel.png como fondo
      ═══════════════════════════════════════════════════════════ -->
      <header class="dashboard-header">
        <div class="header-overlay"></div>
        <div class="header-body">
          <div class="header-text">
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
        </div>
      </header>

      <!-- ═══════════════════════════════════════════════════════════
           KPIs — 4 métricas principales
      ═══════════════════════════════════════════════════════════ -->
      <section class="metrics-grid">
        <article
          class="metric-card"
          *ngFor="let metric of metrics(); trackBy: trackByIndex"
        >
          <div class="metric-lottie">
            <app-lottie-icon [src]="metric.lottie" [size]="48" [loop]="true"></app-lottie-icon>
          </div>
          <div class="metric-text">
            <p>{{ metric.label }}</p>
            <strong>{{ metric.value }}</strong>
            <small>{{ metric.helper }}</small>
          </div>
        </article>
      </section>

      <!-- ═══════════════════════════════════════════════════════════
           GRID PRINCIPAL — Resumen + Accesos rápidos
      ═══════════════════════════════════════════════════════════ -->
      <section class="dashboard-grid">

        <!-- Resumen comercial -->
        <article class="feature-card resumen-card">
          <div class="feature-main">
            <div class="feature-lottie-wrap">
              <app-lottie-icon src="/assets/crm/ingresos.json" [size]="52" [loop]="true"></app-lottie-icon>
            </div>
            <span class="eyebrow">Resumen comercial</span>
            <h2>{{ stats().revenue | currency: 'COP' : 'symbol' : '1.0-0' }}</h2>
            <p>
              Ingresos confirmados registrados por el backend. Usa esta vista para validar
              rendimiento mensual y planes activos.
            </p>
          </div>
          <div class="progress-stack">
            <div class="progress-header">
              <span>Meta mensual</span>
              <strong>78%</strong>
            </div>
            <div class="progress-track"><i style="width:78%"></i></div>
            <a routerLink="/payments" class="progress-link">
              <span class="material-symbols-outlined">arrow_forward</span>
              Ver pagos
            </a>
          </div>
        </article>

        <!-- Accesos rápidos -->
        <article class="panel-card quick-actions-card">
          <div class="panel-title">
            <h3>Accesos rápidos</h3>
            <div class="panel-title-icon">
              <app-lottie-icon src="/assets/crm/mas.json" [size]="26" [loop]="true"></app-lottie-icon>
            </div>
          </div>
          <div class="quick-grid">
            <a
              *ngFor="let action of quickActions; trackBy: trackByIndex"
              [routerLink]="action.path"
              class="quick-item"
            >
              <div class="quick-lottie">
                <app-lottie-icon [src]="action.lottie" [size]="38" [loop]="true"></app-lottie-icon>
              </div>
              <div class="quick-text">
                <strong>{{ action.label }}</strong>
                <small>{{ action.detail }}</small>
              </div>
            </a>
          </div>
        </article>

      </section>

      <!-- ═══════════════════════════════════════════════════════════
           GRID INFERIOR — Tareas + Módulos
      ═══════════════════════════════════════════════════════════ -->
      <section class="lower-grid">

        <!-- Tareas pendientes -->
        <article class="panel-card tasks-card">
          <div class="panel-title">
            <h3>Tareas pendientes</h3>
            <a routerLink="/reports" class="panel-link">Ver todo</a>
          </div>
          <div class="task-list">
            <div
              *ngFor="let task of tasks; trackBy: trackByIndex"
              class="task-item"
            >
              <div class="task-lottie">
                <app-lottie-icon [src]="task.lottie" [size]="36" [loop]="true"></app-lottie-icon>
              </div>
              <div class="task-text">
                <strong>{{ task.title }}</strong>
                <small>{{ task.detail }}</small>
              </div>
              <em class="task-tag" [class]="'tag-' + task.tagColor">{{ task.tag }}</em>
            </div>
          </div>
        </article>

        <!-- Módulos del sistema -->
        <article class="panel-card modules-card">
          <div class="panel-title">
            <h3>Módulos del sistema</h3>
            <div class="panel-title-icon">
              <app-lottie-icon src="/assets/crm/mas.json" [size]="26" [loop]="true"></app-lottie-icon>
            </div>
          </div>
          <div class="module-list">
            <a
              *ngFor="let module of modules; trackBy: trackByIndex"
              [routerLink]="module.path"
              class="module-item"
            >
              <div class="module-lottie">
                <app-lottie-icon [src]="module.lottie" [size]="38" [loop]="true"></app-lottie-icon>
              </div>
              <div class="module-text">
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
      /* ══════════════════════════════════════════════
         Página
      ══════════════════════════════════════════════ */
      .dashboard-page {
        max-width: 1280px;
        margin: 0 auto;
        color: #121212;
        background:
          linear-gradient(rgba(248, 248, 248, 0.82), rgba(248, 248, 248, 0.82)),
          url('/assets/crm/fodopanel.png') center / cover no-repeat;
        border-radius: 16px;
        padding: 1.25rem 1.25rem 2rem;
      }

      /* ══════════════════════════════════════════════
         HEADER con panel.png
      ══════════════════════════════════════════════ */
      .dashboard-header {
        position: relative;
        background-image: url('/assets/crm/PanelControl.png');
        background-size: cover;
        background-position: center top;
        border-radius: 20px;
        overflow: hidden;
        margin-bottom: 2.5rem;
        border: 1px solid rgba(250, 204, 21, 0.15);
        box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);
        min-height: 160px;
      }

      /* Overlay blanco suave para mantener legibilidad */
      .header-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(
          135deg,
          rgba(255, 255, 255, 0.78) 0%,
          rgba(255, 250, 220, 0.68) 55%,
          rgba(255, 244, 190, 0.55) 100%
        );
        z-index: 0;
      }

      .header-body {
        position: relative;
        z-index: 1;
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 2rem;
        padding: 2.25rem 2.5rem;
        flex-wrap: wrap;
      }

      .eyebrow {
        display: block;
        color: #735c00;
        font: 700 0.7rem 'Space Grotesk', sans-serif;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        margin-bottom: 0.6rem;
      }

      h1 {
        font: 700 2.5rem / 1.2 Inter, sans-serif;
        margin: 0 0 0.6rem;
        color: #0a0a0a;
        letter-spacing: -0.02em;
      }

      .header-text p {
        color: #555;
        font-size: 1rem;
        line-height: 1.65;
        max-width: 640px;
        margin: 0;
      }

      .header-actions {
        display: flex;
        gap: 0.875rem;
        flex-wrap: wrap;
      }

      .outline-btn,
      .gold-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        border-radius: 10px;
        padding: 0.85rem 1.5rem;
        font: 600 0.88rem Inter, sans-serif;
        text-decoration: none;
        transition: all 200ms ease;
        white-space: nowrap;
      }

      .outline-btn {
        border: 1.5px solid rgba(0, 0, 0, 0.15);
        background: rgba(255, 255, 255, 0.85);
        color: #0a0a0a;
        backdrop-filter: blur(4px);
      }

      .outline-btn:hover {
        border-color: rgba(0, 0, 0, 0.25);
        background: rgba(255, 255, 255, 0.95);
      }

      .gold-btn {
        border: none;
        background: #facc15;
        color: #000;
        font-weight: 700;
        box-shadow: 0 2px 10px rgba(250, 204, 21, 0.35);
      }

      .gold-btn:hover {
        background: #f0c00e;
        box-shadow: 0 4px 16px rgba(250, 204, 21, 0.45);
        transform: translateY(-1px);
      }

      /* ══════════════════════════════════════════════
         KPIs
      ══════════════════════════════════════════════ */
      .metrics-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1.25rem;
        margin-bottom: 2rem;
      }

      .metric-card {
        display: flex;
        gap: 1.25rem;
        align-items: center;
        padding: 1.5rem;
        background: #fff;
        border: 1px solid #e8e8e8;
        border-radius: 14px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
        transition: all 220ms ease;
        overflow: hidden;
      }

      .metric-card:hover {
        border-color: #facc15;
        box-shadow: 0 6px 24px rgba(250, 204, 21, 0.12);
        transform: translateY(-3px);
      }

      .metric-lottie {
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 60px;
        height: 60px;
        border-radius: 14px;
        background: #f5f5f5;
        overflow: hidden;
      }

      .metric-card:hover .metric-lottie {
        background: #fffbeb;
      }

      .metric-text p {
        margin: 0;
        color: #888;
        font: 600 0.7rem 'Space Grotesk', sans-serif;
        text-transform: uppercase;
        letter-spacing: 0.08em;
      }

      .metric-text strong {
        display: block;
        font: 700 1.85rem / 1.1 Inter, sans-serif;
        margin: 0.3rem 0 0.2rem;
        color: #0a0a0a;
        letter-spacing: -0.01em;
      }

      .metric-text small {
        color: #059669;
        font-weight: 600;
        font-size: 0.78rem;
      }

      /* ══════════════════════════════════════════════
         Grid principal
      ══════════════════════════════════════════════ */
      .dashboard-grid {
        display: grid;
        grid-template-columns: 1.4fr 0.93fr;
        gap: 1.5rem;
        margin-bottom: 1.75rem;
      }

      /* ══════════════════════════════════════════════
         Feature card (Resumen comercial)
      ══════════════════════════════════════════════ */
      .feature-card {
        background:
          linear-gradient(135deg, rgba(255, 255, 255, 0.80) 0%, rgba(255, 250, 218, 0.70) 100%),
          url('/assets/crm/ResumenComercial.png') center / cover no-repeat;
        border: 1px solid #f0e8c0;
        border-radius: 14px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
        padding: 2.25rem 2.5rem;
        display: flex;
        justify-content: space-between;
        gap: 2rem;
        min-height: 260px;
        transition: all 220ms ease;
      }

      .feature-card:hover {
        box-shadow: 0 6px 28px rgba(250, 204, 21, 0.1);
      }

      .feature-main { flex: 1; min-width: 0; }

      .feature-lottie-wrap {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 60px;
        height: 60px;
        border-radius: 14px;
        background: rgba(250, 204, 21, 0.08);
        margin-bottom: 1rem;
      }

      .feature-main .eyebrow { margin-bottom: 0.5rem; }

      .feature-main h2 {
        font: 700 3rem / 1 Inter, sans-serif;
        margin: 0 0 0.875rem;
        color: #0a0a0a;
        letter-spacing: -0.02em;
      }

      .feature-main p {
        color: #666;
        font-size: 0.97rem;
        line-height: 1.65;
        margin: 0;
        max-width: 440px;
      }

      .progress-stack {
        align-self: flex-end;
        min-width: 200px;
        flex-shrink: 0;
        padding: 1rem;
        border: 1px solid rgba(245, 197, 24, 0.18);
        border-radius: 12px;
        background: rgba(14, 14, 14, 0.58);
        backdrop-filter: blur(4px);
      }

      .progress-header {
        display: flex;
        justify-content: space-between;
        font: 600 0.9rem Inter, sans-serif;
        color: #e5e2e1;
        margin-bottom: 0.5rem;
      }

      .progress-header span {
        color: #d1c5ac;
      }

      .progress-header strong {
        color: #ffe08b;
      }

      .progress-track {
        height: 8px;
        background: #e8e8e8;
        border-radius: 4px;
        overflow: hidden;
        margin: 0 0 1rem;
      }

      .progress-track i {
        display: block;
        height: 100%;
        border-radius: 4px;
        background: linear-gradient(90deg, #f0c00e, #facc15);
        transition: width 400ms ease;
      }

      .progress-link {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        color: #ffe08b;
        font: 600 0.9rem Inter, sans-serif;
        text-decoration: none;
        transition: all 200ms ease;
      }

      .progress-link:hover { color: #f5c518; gap: 0.6rem; }
      .progress-link .material-symbols-outlined { font-size: 1rem; }

      /* ══════════════════════════════════════════════
         Panel card base
      ══════════════════════════════════════════════ */
      .panel-card {
        background: #fff;
        border: 1px solid #e8e8e8;
        border-radius: 14px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
        padding: 1.75rem;
        transition: box-shadow 220ms ease;
      }

      .panel-card:hover {
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.07);
      }

      .panel-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #f2f2f2;
      }

      .panel-title h3 {
        font: 600 1.2rem Inter, sans-serif;
        margin: 0;
        color: #0a0a0a;
        letter-spacing: -0.01em;
      }

      .panel-title-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        border-radius: 8px;
        background: #f5c518 !important;
        border: 1px solid rgba(255, 224, 139, 0.45) !important;
        box-shadow: 0 0 14px rgba(245, 197, 24, 0.18) !important;
        overflow: hidden;
        flex-shrink: 0;
      }

      .panel-title-icon app-lottie-icon {
        filter: brightness(0) saturate(100%);
      }

      .panel-link {
        font: 600 0.88rem Inter, sans-serif;
        color: #735c00;
        text-decoration: none;
        transition: color 200ms ease;
      }

      .panel-link:hover { color: #4a3900; }

      /* ══════════════════════════════════════════════
         Accesos rápidos
      ══════════════════════════════════════════════ */
      .quick-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.875rem;
      }

      .quick-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        gap: 0.75rem;
        padding: 1.25rem 0.875rem;
        border: 1px solid #e8e8e8;
        border-radius: 12px;
        background: #fafafa;
        color: #0a0a0a;
        text-decoration: none;
        transition: all 220ms ease;
      }

      .quick-item:hover {
        border-color: #facc15;
        background: #fffdf0;
        transform: translateY(-3px);
        box-shadow: 0 6px 16px rgba(250, 204, 21, 0.12);
      }

      .quick-lottie {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 52px;
        height: 52px;
        border-radius: 12px;
        background: #f0f0f0;
        overflow: hidden;
        flex-shrink: 0;
        transition: background 220ms ease;
      }

      .quick-item:hover .quick-lottie { background: rgba(250, 204, 21, 0.1); }

      .quick-text strong {
        display: block;
        font: 600 0.92rem Inter, sans-serif;
        color: #0a0a0a;
      }

      .quick-text small {
        display: block;
        color: #999;
        font-size: 0.8rem;
        margin-top: 0.2rem;
      }

      /* ══════════════════════════════════════════════
         Grid inferior
      ══════════════════════════════════════════════ */
      .lower-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
      }

      /* ══════════════════════════════════════════════
         Tareas
      ══════════════════════════════════════════════ */
      .task-list { display: flex; flex-direction: column; gap: 0.875rem; }

      .task-item {
        display: grid;
        grid-template-columns: auto 1fr auto;
        align-items: center;
        gap: 1rem;
        padding: 1rem 1.1rem;
        border-radius: 10px;
        background: #f9f9f9;
        border: 1px solid #eee;
        transition: all 200ms ease;
      }

      .task-item:hover {
        background: #fff;
        border-color: #e0e0e0;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
      }

      .task-lottie {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 46px;
        height: 46px;
        border-radius: 10px;
        background: #fff7e8;
        overflow: hidden;
        flex-shrink: 0;
      }

      .task-text strong {
        display: block;
        font: 600 0.92rem Inter, sans-serif;
        color: #0a0a0a;
        margin-bottom: 0.2rem;
      }

      .task-text small { color: #999; font-size: 0.82rem; }

      .task-tag {
        font-style: normal;
        border-radius: 20px;
        padding: 0.3rem 0.75rem;
        border: 1px solid transparent;
        font: 800 0.73rem Inter, sans-serif;
        white-space: nowrap;
        flex-shrink: 0;
        letter-spacing: 0.01em;
      }

      .tag-amber {
        background: rgba(245, 197, 24, 0.16) !important;
        border-color: rgba(245, 197, 24, 0.32) !important;
        color: #ffe08b !important;
      }

      .tag-red {
        background: rgba(255, 180, 171, 0.16) !important;
        border-color: rgba(255, 180, 171, 0.35) !important;
        color: #ffdad6 !important;
      }

      .tag-blue {
        background: rgba(158, 197, 255, 0.16) !important;
        border-color: rgba(158, 197, 255, 0.35) !important;
        color: #d6e3ff !important;
      }

      .tag-green {
        background: rgba(139, 220, 159, 0.16) !important;
        border-color: rgba(139, 220, 159, 0.35) !important;
        color: #b9f6c8 !important;
      }

      /* ══════════════════════════════════════════════
         Módulos
      ══════════════════════════════════════════════ */
      .module-list {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.875rem;
      }

      .module-item {
        display: flex;
        gap: 1rem;
        align-items: center;
        padding: 1.1rem 1.25rem;
        border: 1px solid #e8e8e8;
        border-radius: 12px;
        background: #fafafa;
        color: #0a0a0a;
        text-decoration: none;
        transition: all 220ms ease;
      }

      .module-item:hover {
        border-color: #facc15;
        background: #fffdf0;
        transform: translateY(-2px);
        box-shadow: 0 4px 14px rgba(250, 204, 21, 0.1);
      }

      .module-lottie {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 48px;
        height: 48px;
        border-radius: 10px;
        background: #f0f0f0;
        overflow: hidden;
        flex-shrink: 0;
        transition: background 220ms ease;
      }

      .module-item:hover .module-lottie { background: rgba(250, 204, 21, 0.1); }

      .module-text strong {
        display: block;
        font: 600 0.92rem Inter, sans-serif;
        color: #0a0a0a;
      }

      .module-text small { color: #999; font-size: 0.82rem; }

      /* ══════════════════════════════════════════════
         Panel cards con imagen de fondo
      ══════════════════════════════════════════════ */
      .quick-actions-card {
        background:
          linear-gradient(rgba(255, 255, 255, 0.80), rgba(255, 250, 218, 0.70)),
          url('/assets/crm/accesorapidopanel.png') center / cover no-repeat;
      }

      .tasks-card {
        background:
          linear-gradient(rgba(255, 255, 255, 0.80), rgba(255, 250, 218, 0.70)),
          url('/assets/crm/TareasPendientes.png') center / cover no-repeat;
      }

      .modules-card {
        background:
          linear-gradient(rgba(255, 255, 255, 0.80), rgba(255, 250, 218, 0.70)),
          url('/assets/crm/modulos.png') center / cover no-repeat;
      }

      /* Sub-elementos sobre fondos con imagen — refuerzan contraste */
      .quick-actions-card .quick-item,
      .tasks-card .task-item,
      .modules-card .module-item {
        background: rgba(255, 255, 255, 0.92);
        backdrop-filter: blur(2px);
      }

      .quick-actions-card .quick-item:hover,
      .modules-card .module-item:hover {
        background: rgba(255, 253, 240, 0.97);
      }

      .tasks-card .task-item:hover {
        background: rgba(255, 255, 255, 0.98);
      }

      .quick-actions-card .panel-title,
      .tasks-card .panel-title,
      .modules-card .panel-title {
        border-bottom-color: rgba(0, 0, 0, 0.08);
      }

      /* ══════════════════════════════════════════════
         Responsive
      ══════════════════════════════════════════════ */
      @media (max-width: 1100px) {
        .metrics-grid { grid-template-columns: repeat(2, 1fr); }
        .dashboard-grid { grid-template-columns: 1fr; }
        .feature-card {
          flex-direction: column;
          min-height: auto;
          gap: 1.5rem;
        }
        .progress-stack { min-width: 0; }
      }

      @media (max-width: 768px) {
        .lower-grid { grid-template-columns: 1fr; }
        .module-list { grid-template-columns: 1fr; }
        .quick-grid { grid-template-columns: 1fr 1fr; }
        h1 { font-size: 2rem; }
        .header-body { padding: 1.75rem; }
        .feature-main h2 { font-size: 2.4rem; }
      }

      @media (max-width: 560px) {
        .metrics-grid { grid-template-columns: 1fr; gap: 1rem; }
        .quick-grid { grid-template-columns: 1fr; }
        .header-body {
          flex-direction: column;
          align-items: flex-start;
          gap: 1.25rem;
        }
        .header-actions { width: 100%; }
        .gold-btn, .outline-btn { flex: 1; justify-content: center; }
        h1 { font-size: 1.75rem; }
        .dashboard-header { min-height: auto; }
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

  protected metrics = signal<Metric[]>([
    { label: 'Miembros',     value: '0',  helper: 'Registrados',      lottie: '/assets/crm/miembros.json'     },
    { label: 'Ingresos',     value: '$0', helper: 'Pagos completados', lottie: '/assets/crm/ingresos.json'     },
    { label: 'Planes activos', value: '0', helper: 'Disponibles',    lottie: '/assets/crm/planesactivos.json' },
    { label: 'Pagos',        value: '0',  helper: 'Historial total',   lottie: '/assets/crm/pagos.json'        },
  ]);

  protected quickActions: QuickAction[] = [
    { label: 'Miembros', detail: 'Gestionar perfiles',      lottie: '/assets/crm/miembros.json',     path: '/users'    },
    { label: 'Planes',   detail: 'Membresías y precios',    lottie: '/assets/crm/planesactivos.json', path: '/plans'    },
    { label: 'Pagos',    detail: 'Cobros y recibos',        lottie: '/assets/crm/pagos.json',         path: '/payments' },
    { label: 'Clases',   detail: 'Agenda y cupos',          lottie: '/assets/crm/clases.json',        path: '/classes'  },
  ];

  protected tasks: Task[] = [
    {
      title: 'Membresías por vencer',
      detail: 'Enviar recordatorios de renovación',
      lottie: '/assets/crm/pagospendientes.json',
      tag: 'Hoy',
      tagColor: 'amber',
    },
    {
      title: 'Pagos pendientes',
      detail: 'Revisar cobranza y referencias',
      lottie: '/assets/crm/pagospendientes.json',
      tag: 'Urgente',
      tagColor: 'red',
    },
    {
      title: 'Reporte mensual',
      detail: 'Consolidar ingresos y usuarios',
      lottie: '/assets/crm/reportemensual.json',
      tag: 'Planificado',
      tagColor: 'blue',
    },
  ];

  protected modules: SystemModule[] = [
    { label: 'Rutinas',        detail: 'Plantillas de entrenamiento', lottie: '/assets/crm/rutinas.json',       path: '/routines'  },
    { label: 'Entrenadores',   detail: 'Equipo y asignaciones',       lottie: '/assets/crm/entrenadores.json',  path: '/trainers'  },
    { label: 'Mercadeo',       detail: 'Campañas y cupones',          lottie: '/assets/crm/mercadeo.json',      path: '/marketing' },
    { label: 'Configuración',  detail: 'Ajustes globales',            lottie: '/assets/crm/configuracion.json', path: '/settings'  },
  ];

  trackByIndex(index: number): number {
    return index;
  }

  ngOnInit(): void {
    this.api.getDashboardStats().subscribe({
      next: (stats) => {
        this.stats.set(stats);
        this.metrics.set([
          { label: 'Miembros',       value: String(stats.users),       helper: 'Registrados',      lottie: '/assets/crm/miembros.json'     },
          { label: 'Ingresos',       value: this.formatCurrency(stats.revenue), helper: 'Pagos completados', lottie: '/assets/crm/ingresos.json' },
          { label: 'Planes activos', value: String(stats.active_plans), helper: 'Disponibles',     lottie: '/assets/crm/planesactivos.json' },
          { label: 'Pagos',          value: String(stats.payments),    helper: 'Historial total',   lottie: '/assets/crm/pagos.json'        },
        ]);
      },
      error: () => {
        this.stats.set({ users: 0, active_plans: 0, payments: 0, revenue: 0 });
      },
    });
  }

  private formatCurrency(value: number): string {
    return new Intl.NumberFormat('es-CO', {
      style: 'currency',
      currency: 'COP',
      maximumFractionDigits: 0,
    }).format(value);
  }
}
