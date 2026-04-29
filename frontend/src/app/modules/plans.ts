import { CommonModule, CurrencyPipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { ApiService, PlanSummary } from '../services/api.service';

@Component({
  selector: 'module-plans',
  standalone: true,
  imports: [CommonModule, CurrencyPipe],
  template: `
  <section class="plans-page">
    <header class="plans-header">
      <div>
        <h1>Plans & Memberships</h1>
        <p>Manage your premium membership offerings, billing cycles, and access tiers.</p>
      </div>
      <div class="plans-actions">
        <button type="button" class="outline-btn">
          <span class="material-symbols-outlined" aria-hidden="true">table_rows</span>
          Table View
        </button>
        <button type="button" class="gold-btn">
          <span class="material-symbols-outlined" aria-hidden="true">add</span>
          Create Plan
        </button>
      </div>
    </header>

    <div *ngIf="loading()" class="state-card">Cargando planes...</div>
    <div *ngIf="error()" class="alert alert-danger">{{ error() }}</div>

    <ng-container *ngIf="!loading() && !error()">
      <section class="plan-stats">
        <article>
          <div class="stat-label">
            <span class="material-symbols-outlined" aria-hidden="true">loyalty</span>
            Active Plans
          </div>
          <div class="stat-value">
            <strong>{{ activePlans }}</strong>
            <span>Disponibles</span>
          </div>
        </article>
        <article>
          <div class="stat-label">
            <span class="material-symbols-outlined" aria-hidden="true">group</span>
            Total Subscribers
          </div>
          <div class="stat-value">
            <strong>{{ estimatedSubscribers }}</strong>
            <span>Estimado CRM</span>
          </div>
        </article>
        <article>
          <div class="stat-label">
            <span class="material-symbols-outlined" aria-hidden="true">payments</span>
            Monthly MRR
          </div>
          <div class="stat-value">
            <strong>{{ monthlyMrr | currency:'USD':'symbol':'1.0-0' }}</strong>
            <span>Potencial mensual</span>
          </div>
        </article>
      </section>

      <div *ngIf="plans.length === 0" class="state-card">
        No hay planes creados todavía.
      </div>

      <section *ngIf="plans.length > 0" class="plans-grid">
        <article *ngFor="let plan of plans; let i = index" class="plan-card">
          <div class="status-pill" [class.inactive]="!plan.active">
            <i></i>
            {{ plan.active ? 'Active' : 'Inactive' }}
          </div>

          <div class="plan-icon" [class.featured]="i === 0">
            <span class="material-symbols-outlined" aria-hidden="true">{{ i === 0 ? 'diamond' : 'fitness_center' }}</span>
          </div>

          <h2>{{ plan.name }}</h2>
          <p>{{ plan.benefits || 'Standard facility access with selected membership benefits.' }}</p>

          <div class="price-row">
            <strong>{{ plan.price | currency:'USD':'symbol':'1.0-0' }}</strong>
            <span>/ {{ billingLabel(plan.duration_days) }}</span>
          </div>

          <ul>
            <li>
              <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
              {{ plan.duration_days }} days access cycle
            </li>
            <li>
              <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
              Member check-in and billing tracking
            </li>
            <li>
              <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
              {{ plan.active ? 'Available for new subscribers' : 'Hidden from new sales' }}
            </li>
          </ul>

          <footer>
            <span>{{ subscriberEstimate(i) }} Subscribers</span>
            <div>
              <button type="button" aria-label="Editar plan">
                <span class="material-symbols-outlined" aria-hidden="true">edit</span>
              </button>
              <button type="button" aria-label="Eliminar plan">
                <span class="material-symbols-outlined" aria-hidden="true">delete</span>
              </button>
            </div>
          </footer>
        </article>
      </section>
    </ng-container>
  </section>
  `,
  styles: [`
    .plans-page{max-width:1280px;margin:0 auto;color:#121212}
    .plans-header{display:flex;justify-content:space-between;align-items:flex-end;gap:1.5rem;margin-bottom:3rem;flex-wrap:wrap}
    h1{font-family:Lexend,sans-serif;font-size:2rem;line-height:1.2;font-weight:700;margin:0 0 .5rem}
    .plans-header p{font-size:1.05rem;line-height:1.6;color:#5d5f5f;max-width:680px;margin:0}
    .plans-actions{display:flex;gap:1rem;flex-wrap:wrap}
    .outline-btn,.gold-btn{display:flex;align-items:center;gap:.5rem;border-radius:8px;padding:.7rem 1.25rem;font-family:Lexend,sans-serif;font-weight:700}
    .outline-btn{border:1px solid #121212;background:#fff;color:#121212}.gold-btn{border:1px solid #eab308;background:#eab308;color:#121212}
    .plan-stats,.plans-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1.25rem;margin-bottom:3rem}
    .plan-stats article,.plan-card,.state-card{background:#fff;border:1px solid #eee;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.04)}
    .plan-stats article{padding:1.5rem}.stat-label{display:flex;align-items:center;gap:.75rem;color:#5d5f5f;font-family:Lexend,sans-serif;font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;margin-bottom:1rem}
    .stat-value{display:flex;align-items:baseline;gap:.75rem}.stat-value strong{font-family:Lexend,sans-serif;font-size:2.4rem;line-height:1}.stat-value span{color:#059669;font-weight:600}
    .plans-grid{grid-template-columns:repeat(2,minmax(0,1fr));margin-bottom:0}.plan-card{position:relative;overflow:hidden;padding:2rem 2rem 0}
    .status-pill{position:absolute;right:1.5rem;top:1.5rem;display:flex;align-items:center;gap:.4rem;border-radius:999px;background:#ecfdf5;color:#047857;padding:.35rem .75rem;font-family:Lexend,sans-serif;font-size:.75rem}.status-pill i{width:.4rem;height:.4rem;border-radius:50%;background:#10b981}.status-pill.inactive{background:#f5f5f5;color:#737373}.status-pill.inactive i{background:#a3a3a3}
    .plan-icon{display:grid;place-items:center;width:48px;height:48px;border-radius:8px;background:#f5f5f5;color:#404040;margin-bottom:1.5rem}.plan-icon.featured{background:#0a0a0a;color:#eab308}
    .plan-card h2{font-family:Lexend,sans-serif;font-size:1.5rem;margin:0 0 .5rem}.plan-card p{color:#5d5f5f;line-height:1.5;margin:0 0 1.5rem}
    .price-row{display:flex;align-items:baseline;gap:.35rem;border-bottom:1px solid #eee;margin-bottom:1.5rem;padding-bottom:1.5rem}.price-row strong{font-family:Lexend,sans-serif;font-size:2.5rem;line-height:1}.price-row span{color:#5d5f5f}
    ul{display:grid;gap:1rem;list-style:none;margin:0 0 2rem;padding:0}li{display:flex;align-items:flex-start;gap:.75rem;color:#121212;font-size:.92rem}li span{color:#eab308;font-size:1.25rem}
    footer{display:flex;align-items:center;justify-content:space-between;margin:0 -2rem;background:#fafafa;border-top:1px solid #eee;padding:1rem 2rem;color:#5d5f5f;font-family:Lexend,sans-serif;font-size:.85rem}footer div{display:flex;gap:.5rem}footer button{display:grid;place-items:center;border:0;border-radius:8px;background:transparent;color:#5d5f5f;padding:.45rem}footer button:hover{background:#e5e5e5;color:#121212}
    .state-card{padding:1.25rem;color:#5d5f5f}
    @media(max-width:900px){.plan-stats,.plans-grid{grid-template-columns:1fr}.plans-header{margin-bottom:2rem}}
  `]
})
export default class PlansModule implements OnInit {
  private api = inject(ApiService);
  plans: PlanSummary[] = [];
  loading = signal(true);
  error = signal('');

  get activePlans(): number {
    return this.plans.filter((plan) => plan.active).length;
  }

  get estimatedSubscribers(): number {
    return this.plans.reduce((total, _plan, index) => total + this.subscriberEstimate(index), 0);
  }

  get monthlyMrr(): number {
    return this.plans.reduce((total, plan, index) => total + plan.price * this.subscriberEstimate(index), 0);
  }

  ngOnInit(): void {
    this.api.getPlans().subscribe({
      next: (res) => {
        this.plans = res.data || [];
        this.loading.set(false);
      },
      error: () => {
        this.error.set('No se pudieron cargar los planes desde Laravel.');
        this.loading.set(false);
      }
    });
  }

  billingLabel(days: number): string {
    if (days >= 360) return 'year';
    if (days >= 28 && days <= 31) return 'month';
    return `${days} days`;
  }

  subscriberEstimate(index: number): number {
    return [420, 820, 160, 95][index] ?? Math.max(25, 120 - index * 12);
  }
}
