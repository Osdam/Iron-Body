import { CommonModule } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import {
  MarketingLead,
  MarketingOverview,
  MarketingService,
} from '../../shared/services/marketing.service';

/**
 * Panel "Mercadeo digital (Meta)" — datos REALES de las tablas marketing_*.
 *
 * Componente autocontenido y aditivo: NO modifica la gestión de promos/cupones
 * existente del módulo Mercadeo. Mientras Meta no esté sincronizado (o
 * META_ENABLED=false), las tablas están vacías y se muestra un empty state real
 * ("Aún no hay datos de Meta sincronizados."). Cuando F1/F2 traigan datos, este
 * panel los muestra sin rediseñar.
 */
@Component({
  selector: 'app-marketing-digital-panel',
  standalone: true,
  imports: [CommonModule],
  template: `
    <section class="md-panel">
      <header class="md-head">
        <div>
          <h2>Mercadeo digital (Meta)</h2>
          <p>Pauta, leads y conversaciones de Instagram · Facebook · WhatsApp · Ads</p>
        </div>
        <button type="button" class="md-refresh" (click)="reload()" [disabled]="loading()">
          <span class="material-symbols-outlined">refresh</span> Actualizar
        </button>
      </header>

      <div class="md-state" *ngIf="loading()">
        <span class="material-symbols-outlined spin">progress_activity</span>
        <p>Cargando métricas…</p>
      </div>

      <div class="md-state error" *ngIf="!loading() && error()">
        <span class="material-symbols-outlined">cloud_off</span>
        <p>{{ error() }}</p>
        <button type="button" class="md-retry" (click)="reload()">Reintentar</button>
      </div>

      <ng-container *ngIf="!loading() && !error() && overview() as o">
        <div class="md-kpis">
          <div class="md-kpi"><span>Gasto</span><strong>{{ money(o.spend_total) }}</strong></div>
          <div class="md-kpi"><span>Leads</span><strong>{{ o.leads_total }}</strong></div>
          <div class="md-kpi"><span>Conversaciones</span><strong>{{ o.conversations_total }}</strong></div>
          <div class="md-kpi"><span>Convertidos</span><strong>{{ o.converted_leads }}</strong></div>
          <div class="md-kpi"><span>Ingresos atribuidos</span><strong>{{ money(o.revenue_total) }}</strong></div>
          <div class="md-kpi"><span>ROAS</span><strong>{{ o.roas !== null ? (o.roas + 'x') : '—' }}</strong></div>
          <div class="md-kpi"><span>CAC</span><strong>{{ o.cac !== null ? money(o.cac) : '—' }}</strong></div>
          <div class="md-kpi"><span>Conversión</span><strong>{{ o.conversion_rate !== null ? pct(o.conversion_rate) : '—' }}</strong></div>
          <div class="md-kpi"><span>Leads calientes</span><strong>{{ o.hot_leads }}</strong></div>
          <div class="md-kpi"><span>Seguimientos pend.</span><strong>{{ o.pending_followups }}</strong></div>
          <div class="md-kpi"><span>Acciones IA</span><strong>{{ o.ai_actions_count }}</strong></div>
          <div class="md-kpi"><span>Control humano</span><strong>{{ o.human_takeover_count }}</strong></div>
        </div>

        <!-- Empty state real cuando aún no hay actividad sincronizada. -->
        <div class="md-empty" *ngIf="isEmpty(o)">
          <span class="material-symbols-outlined">hub</span>
          <p>Aún no hay datos de Meta sincronizados.</p>
          <small>Cuando se conecte la pauta y la mensajería, las métricas y los leads aparecerán aquí.</small>
        </div>

        <!-- Leads recientes (solo si hay). -->
        <div class="md-leads" *ngIf="leads().length > 0">
          <h3>Leads recientes</h3>
          <ul>
            <li *ngFor="let l of leads()">
              <span class="md-temp" [ngClass]="'t-' + l.temperature"></span>
              <span class="md-lead-name">{{ l.name || l.instagram_username || l.phone || 'Lead' }}</span>
              <span class="md-chip">{{ l.channel }}</span>
              <span class="md-chip">{{ l.status }}</span>
            </li>
          </ul>
        </div>
      </ng-container>
    </section>
  `,
  styles: [
    `
      .md-panel { border: 1px solid #eef2f7; border-radius: 16px; padding: 18px 20px; margin: 0 0 1.5rem; background: #fff; }
      .md-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
      .md-head h2 { margin: 0; font-size: 18px; font-weight: 800; color: #111827; }
      .md-head p { margin: 3px 0 0; font-size: 12.5px; color: #6b7280; }
      .md-refresh { display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d1d5db; background: #fff; border-radius: 10px; padding: 8px 14px; font-size: 13px; cursor: pointer; color: #374151; }
      .md-refresh:disabled { opacity: .5; cursor: default; }
      .md-state { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 32px; color: #6b7280; }
      .md-state .material-symbols-outlined { font-size: 36px; color: #9ca3af; }
      .md-state.error .material-symbols-outlined { color: #ef4444; }
      .md-retry { border: 1px solid #d1d5db; background: #fff; border-radius: 9px; padding: 7px 14px; font-size: 13px; cursor: pointer; }
      .spin { animation: md-spin 1s linear infinite; } @keyframes md-spin { to { transform: rotate(360deg); } }
      .md-kpis { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
      .md-kpi { border: 1px solid #f1f5f9; border-radius: 12px; padding: 12px 14px; background: #f9fafb; }
      .md-kpi span { display: block; font-size: 11.5px; color: #6b7280; margin-bottom: 4px; }
      .md-kpi strong { font-size: 19px; color: #111827; font-weight: 800; }
      .md-empty { margin-top: 14px; border: 1px dashed #e5e7eb; border-radius: 12px; padding: 22px; text-align: center; color: #6b7280; }
      .md-empty .material-symbols-outlined { font-size: 34px; color: #9ca3af; }
      .md-empty p { margin: 8px 0 2px; font-weight: 600; color: #374151; }
      .md-empty small { font-size: 12px; color: #9ca3af; }
      .md-leads { margin-top: 16px; }
      .md-leads h3 { font-size: 14px; margin: 0 0 8px; color: #111827; }
      .md-leads ul { list-style: none; margin: 0; padding: 0; }
      .md-leads li { display: flex; align-items: center; gap: 8px; padding: 7px 0; border-bottom: 1px dashed #eef2f7; font-size: 13px; }
      .md-temp { width: 9px; height: 9px; border-radius: 999px; background: #9ca3af; }
      .md-temp.t-hot { background: #ef4444; } .md-temp.t-warm { background: #f59e0b; } .md-temp.t-cold { background: #60a5fa; }
      .md-lead-name { font-weight: 600; color: #111827; }
      .md-chip { font-size: 11px; background: #f3f4f6; color: #374151; border-radius: 999px; padding: 2px 9px; }
    `,
  ],
})
export default class MarketingDigitalPanelComponent implements OnInit {
  private readonly service = inject(MarketingService);

  overview = signal<MarketingOverview | null>(null);
  leads = signal<MarketingLead[]>([]);
  loading = signal<boolean>(false);
  error = signal<string | null>(null);

  ngOnInit(): void {
    this.reload();
  }

  reload(): void {
    this.loading.set(true);
    this.error.set(null);
    this.service.overview().subscribe({
      next: (res) => {
        this.overview.set(res?.data ?? null);
        this.loading.set(false);
        this.loadLeads();
      },
      error: () => {
        this.error.set('No pudimos cargar las métricas de Mercadeo digital.');
        this.loading.set(false);
      },
    });
  }

  private loadLeads(): void {
    this.service.leads({ perPage: 8 }).subscribe({
      next: (res) => this.leads.set(res?.data ?? []),
      error: () => this.leads.set([]),
    });
  }

  isEmpty(o: MarketingOverview): boolean {
    return o.leads_total === 0 && o.spend_total === 0 && o.conversations_total === 0;
  }

  money(v: number): string {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(v || 0);
  }

  pct(v: number): string {
    return `${Math.round(v * 1000) / 10}%`;
  }
}
