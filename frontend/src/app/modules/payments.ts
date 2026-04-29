import { CommonModule, CurrencyPipe, DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { ApiService, PaymentSummary } from '../services/api.service';

@Component({
  selector: 'module-payments',
  standalone: true,
  imports: [CommonModule, CurrencyPipe, DatePipe],
  template: `
  <section class="module-page">
    <div class="module-header">
      <div>
        <h1>Pagos</h1>
        <p>Seguimiento de transacciones, referencias y estado de cobro.</p>
      </div>
      <button class="btn btn-primary btn-sm">Registrar pago</button>
    </div>

    <div *ngIf="loading()" class="state-panel">Cargando pagos...</div>
    <div *ngIf="error()" class="alert alert-danger">{{ error() }}</div>

    <div *ngIf="!loading() && !error() && payments.length === 0" class="state-panel">
      No hay pagos registrados todavía.
    </div>

    <div *ngIf="!loading() && !error() && payments.length > 0" class="responsive-table">
      <table class="table align-middle mb-0">
        <thead>
          <tr>
            <th>Cliente</th>
            <th>Plan</th>
            <th>Monto</th>
            <th>Estado</th>
            <th>Fecha</th>
          </tr>
        </thead>
        <tbody>
          <tr *ngFor="let payment of payments">
            <td>
              <div class="fw-semibold text-white">{{ payment.user?.name || 'Usuario eliminado' }}</div>
              <div class="small text-slate-400">{{ payment.reference || 'Sin referencia' }}</div>
            </td>
            <td>{{ payment.plan?.name || 'Sin plan' }}</td>
            <td>{{ payment.amount | currency:'USD':'symbol':'1.0-0' }}</td>
            <td><span class="badge bg-info text-dark">{{ statusLabel(payment.status) }}</span></td>
            <td>{{ (payment.paid_at || payment.created_at) | date:'mediumDate' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
  `
})
export default class PaymentsModule implements OnInit {
  private api = inject(ApiService);
  payments: PaymentSummary[] = [];
  loading = signal(true);
  error = signal('');

  ngOnInit(): void {
    this.api.getPayments().subscribe({
      next: (res) => {
        this.payments = res.data || [];
        this.loading.set(false);
      },
      error: () => {
        this.error.set('No se pudieron cargar los pagos desde Laravel.');
        this.loading.set(false);
      }
    });
  }

  statusLabel(status: string): string {
    const labels: Record<string, string> = {
      paid: 'Pagado',
      pending: 'Pendiente',
      failed: 'Fallido',
      cancelled: 'Cancelado',
      refunded: 'Reembolsado',
      active: 'Activo',
      inactive: 'Inactivo'
    };

    return labels[status?.toLowerCase()] ?? status;
  }
}
