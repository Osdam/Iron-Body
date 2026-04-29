import { CommonModule, CurrencyPipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { ApiService, PlanSummary } from '../services/api.service';

@Component({
  selector: 'module-plans',
  standalone: true,
  imports: [CommonModule, CurrencyPipe],
  template: `
  <section class="module-page">
    <div class="module-header">
      <div>
        <h1>Membresías</h1>
        <p>Planes disponibles, duración y estado comercial.</p>
      </div>
      <button class="btn btn-primary btn-sm">Nuevo plan</button>
    </div>

    <div *ngIf="loading()" class="state-panel">Cargando planes...</div>
    <div *ngIf="error()" class="alert alert-danger">{{ error() }}</div>

    <div *ngIf="!loading() && !error() && plans.length === 0" class="state-panel">
      No hay planes creados todavía.
    </div>

    <div *ngIf="!loading() && !error() && plans.length > 0" class="responsive-table">
      <table class="table align-middle mb-0">
        <thead>
          <tr>
            <th>Plan</th>
            <th>Precio</th>
            <th>Duración</th>
            <th>Estado</th>
          </tr>
        </thead>
        <tbody>
          <tr *ngFor="let plan of plans">
            <td>
              <div class="fw-semibold text-white">{{ plan.name }}</div>
              <div class="small text-slate-400">{{ plan.benefits || 'Sin beneficios detallados' }}</div>
            </td>
            <td>{{ plan.price | currency:'USD':'symbol':'1.0-0' }}</td>
            <td>{{ plan.duration_days }} días</td>
            <td>
              <span class="badge" [class.bg-success]="plan.active" [class.bg-secondary]="!plan.active">
                {{ plan.active ? 'Activo' : 'Inactivo' }}
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
  `
})
export default class PlansModule implements OnInit {
  private api = inject(ApiService);
  plans: PlanSummary[] = [];
  loading = signal(true);
  error = signal('');

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
}
