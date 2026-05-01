import { CommonModule, CurrencyPipe, DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal } from '@angular/core';
import { ApiService, PaymentSummary } from '../services/api.service';

@Component({
  selector: 'module-payments',
  standalone: true,
  imports: [CommonModule, CurrencyPipe, DatePipe],
  template: `
    <section class="payments-page">
      <header class="payments-header">
        <div>
          <h1>Pagos</h1>
          <p>Seguimiento de transacciones, referencias y estado de cobro.</p>
        </div>
        <button type="button" class="gold-btn">
          <span class="material-symbols-outlined" aria-hidden="true">add</span>
          Registrar pago
        </button>
      </header>

      <div *ngIf="loading()" class="state-card">Cargando pagos...</div>
      <div *ngIf="error()" class="alert alert-danger">{{ error() }}</div>

      <div *ngIf="!loading() && !error() && payments.length === 0" class="state-card">
        No hay pagos registrados todavía.
      </div>

      <div *ngIf="!loading() && !error() && payments.length > 0" class="table-container">
        <table class="payments-table">
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
            <tr *ngFor="let payment of payments" class="payment-row">
              <td class="client-cell">
                <div class="client-name">{{ payment.user?.name || 'Usuario eliminado' }}</div>
                <div class="client-ref">{{ payment.reference || 'Sin referencia' }}</div>
              </td>
              <td class="plan-name">{{ payment.plan?.name || 'Sin plan' }}</td>
              <td class="amount">{{ payment.amount | currency: 'COP' : 'symbol' : '1.0-0' }}</td>
              <td class="status-cell">
                <span class="status-badge" [class]="'status-' + (payment.status?.toLowerCase() || 'pending')">
                  {{ statusLabel(payment.status) }}
                </span>
              </td>
              <td class="date">{{ payment.paid_at || payment.created_at | date: 'mediumDate' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  `,
  styles: [
    `
      .payments-page {
        max-width: 1280px;
        margin: 0 auto;
        color: #0a0a0a;
      }
      .payments-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 2rem;
        margin-bottom: 2.5rem;
        flex-wrap: wrap;
        padding-bottom: 1.5rem;
        border-bottom: 1px solid #f0f0f0;
      }
      h1 {
        font-family: Inter, sans-serif;
        font-size: 2.25rem;
        line-height: 1.2;
        font-weight: 700;
        margin: 0 0 0.6rem;
        letter-spacing: -0.01em;
      }
      .payments-header p {
        font-size: 1rem;
        line-height: 1.65;
        color: #666;
        max-width: 700px;
        margin: 0;
      }
      .gold-btn {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        border-radius: 8px;
        padding: 0.85rem 1.5rem;
        font-family: Inter, sans-serif;
        font-weight: 600;
        font-size: 0.85rem;
        border: none;
        background: #facc15;
        color: #000;
        cursor: pointer;
        box-shadow: 0 2px 8px rgba(250, 204, 21, 0.15);
        transition: all 200ms ease;
      }
      .gold-btn:hover {
        background: #f0c00e;
        box-shadow: 0 4px 12px rgba(250, 204, 21, 0.25);
        transform: translateY(-1px);
      }
      .state-card {
        padding: 2rem;
        color: #666;
        font-family: Inter, sans-serif;
        text-align: center;
        background: #fff;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
        margin-bottom: 2rem;
      }
      .alert {
        padding: 1rem 1.25rem;
        border-radius: 8px;
        font-family: Inter, sans-serif;
        color: #991b1b;
        background: #fee2e2;
        border: 1px solid #fecaca;
        margin-bottom: 1.5rem;
      }
      .table-container {
        background: #fff;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
        overflow: hidden;
        transition: all 200ms ease;
      }
      .table-container:hover {
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
      }
      .payments-table {
        width: 100%;
        border-collapse: collapse;
      }
      .payments-table thead {
        background: #f8f8f8;
        border-bottom: 1px solid #e8e8e8;
      }
      .payments-table th {
        padding: 1.25rem;
        font-family: 'Space Grotesk', sans-serif;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: #666;
      }
      .payments-table tbody tr {
        border-bottom: 1px solid #f0f0f0;
        transition: background 200ms ease;
      }
      .payments-table tbody tr:hover {
        background: #fafafa;
      }
      .payments-table td {
        padding: 1.25rem;
        font-family: Inter, sans-serif;
      }
      .client-cell {
        font-weight: 600;
        color: #0a0a0a;
      }
      .client-name {
        font-size: 0.95rem;
        line-height: 1.4;
      }
      .client-ref {
        font-size: 0.85rem;
        color: #999;
        margin-top: 0.2rem;
      }
      .plan-name {
        color: #666;
        font-size: 0.95rem;
      }
      .amount {
        font-weight: 700;
        font-size: 1rem;
        color: #0a0a0a;
        letter-spacing: -0.01em;
      }
      .status-cell {
        text-align: center;
      }
      .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.5rem 0.85rem;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
        font-family: Inter, sans-serif;
        transition: all 200ms ease;
      }
      .status-paid {
        background: #dcfce7;
        color: #166534;
      }
      .status-pending {
        background: #fef3c7;
        color: #92400e;
      }
      .status-failed {
        background: #fee2e2;
        color: #991b1b;
      }
      .status-cancelled {
        background: #f3f4f6;
        color: #374151;
      }
      .status-refunded {
        background: #e0e7ff;
        color: #3730a3;
      }
      .status-active {
        background: #d1fae5;
        color: #065f46;
      }
      .status-inactive {
        background: #f0f0f0;
        color: #666;
      }
      .date {
        color: #999;
        font-size: 0.9rem;
      }
      @media (max-width: 1000px) {
        .payments-header {
          flex-direction: column;
          align-items: flex-start;
        }
        .payments-table th,
        .payments-table td {
          padding: 1rem;
          font-size: 0.9rem;
        }
      }
      @media (max-width: 700px) {
        h1 {
          font-size: 1.75rem;
        }
        .payments-header {
          margin-bottom: 1.75rem;
        }
        .payments-header p {
          font-size: 0.9rem;
        }
        .gold-btn {
          width: 100%;
        }
        .payments-table {
          font-size: 0.85rem;
        }
        .payments-table th,
        .payments-table td {
          padding: 0.75rem;
        }
        .client-name {
          font-size: 0.9rem;
        }
        .client-ref {
          font-size: 0.8rem;
        }
        .plan-name {
          font-size: 0.9rem;
        }
        .amount {
          font-size: 0.95rem;
        }
      }
    `,
  ],
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
      },
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
      inactive: 'Inactivo',
    };

    return labels[status?.toLowerCase()] ?? status;
  }
}
