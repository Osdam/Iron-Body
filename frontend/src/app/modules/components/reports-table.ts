import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

export interface ActivityRecord {
  date: string;
  type: 'payment' | 'member' | 'membership' | 'class' | 'plan';
  description: string;
  value: number;
  status: 'completed' | 'active' | 'expired' | 'pending' | 'paid';
  icon: string;
}

@Component({
  selector: 'app-reports-table',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="table-card">
      <div class="table-header">
        <h3>Actividad reciente</h3>
        <p class="table-subtitle">Últimos 20 registros</p>
      </div>

      <div class="table-container">
        <table class="activity-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Tipo</th>
              <th>Descripción</th>
              <th>Valor</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>
            <tr *ngFor="let record of records">
              <td class="date-cell">
                <span class="date-badge">{{ formatDate(record.date) }}</span>
              </td>
              <td class="type-cell">
                <div class="type-badge">
                  <span class="material-symbols-outlined">{{ record.icon }}</span>
                  <span>{{ getTypeLabel(record.type) }}</span>
                </div>
              </td>
              <td class="description-cell">{{ record.description }}</td>
              <td class="value-cell">
                <span *ngIf="record.value > 0" class="value-positive"
                  >+{{ formatCurrency(record.value) }}</span
                >
                <span *ngIf="record.value <= 0" class="value-neutral">-</span>
              </td>
              <td class="status-cell">
                <span [ngClass]="['status-badge', 'status-' + record.status]">{{
                  getStatusLabel(record.status)
                }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  `,
  styles: [
    `
      .table-card {
        background: #ffffff;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        padding: 1.5rem;
        overflow: hidden;
      }

      .table-header {
        margin-bottom: 1.5rem;
      }

      .table-header h3 {
        font-size: 1.125rem;
        font-weight: 600;
        color: #0a0a0a;
        margin: 0 0 0.25rem;
      }

      .table-subtitle {
        font-size: 0.85rem;
        color: #999;
        margin: 0;
      }

      .table-container {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
      }

      .activity-table {
        width: 100%;
        border-collapse: collapse;
      }

      thead tr {
        border-bottom: 2px solid #e5e5e5;
      }

      th {
        text-align: left;
        padding: 0.875rem;
        font-size: 0.75rem;
        font-weight: 600;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: #f9f9f9;
      }

      tbody tr {
        border-bottom: 1px solid #e5e5e5;
        transition: background-color 0.2s ease;
      }

      tbody tr:hover {
        background-color: #f9f9f9;
      }

      td {
        padding: 1rem 0.875rem;
        font-size: 0.95rem;
      }

      .date-cell {
        color: #666;
        font-weight: 500;
      }

      .date-badge {
        display: inline-block;
        padding: 0.4rem 0.8rem;
        background: #f0f0f0;
        border-radius: 6px;
        font-size: 0.8rem;
      }

      .type-cell {
        color: #0a0a0a;
      }

      .type-badge {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 500;
      }

      .type-badge .material-symbols-outlined {
        font-size: 1.2rem;
        color: #fbbf24;
      }

      .description-cell {
        color: #333;
        max-width: 300px;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .value-cell {
        text-align: right;
        font-weight: 600;
      }

      .value-positive {
        color: #10b981;
      }

      .value-neutral {
        color: #999;
      }

      .status-cell {
        text-align: center;
      }

      .status-badge {
        display: inline-block;
        padding: 0.4rem 0.8rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }

      .status-completed {
        background: #d1fae5;
        color: #065f46;
      }

      .status-active {
        background: #d1fae5;
        color: #065f46;
      }

      .status-paid {
        background: #d1fae5;
        color: #065f46;
      }

      .status-pending {
        background: #fef3c7;
        color: #92400e;
      }

      .status-expired {
        background: #fee2e2;
        color: #991b1b;
      }

      @media (max-width: 768px) {
        .table-container {
          overflow-x: scroll;
        }

        td,
        th {
          padding: 0.75rem 0.5rem;
          font-size: 0.85rem;
        }

        .description-cell {
          max-width: 150px;
        }
      }
    `,
  ],
})
export default class ReportsTableComponent {
  @Input() records: ActivityRecord[] = [];

  formatDate(date: string): string {
    const d = new Date(date);
    return d.toLocaleDateString('es-CO', { month: 'short', day: 'numeric' });
  }

  formatCurrency(value: number): string {
    if (value >= 1000000) return '$' + (value / 1000000).toFixed(1) + 'M';
    if (value >= 1000) return '$' + (value / 1000).toFixed(0) + 'K';
    return '$' + value.toLocaleString('es-CO');
  }

  getTypeLabel(type: string): string {
    const labels: Record<string, string> = {
      payment: 'Pago',
      member: 'Miembro',
      membership: 'Membresía',
      class: 'Clase',
      plan: 'Plan',
    };
    return labels[type] || type;
  }

  getStatusLabel(status: string): string {
    const labels: Record<string, string> = {
      completed: 'Completado',
      active: 'Activo',
      paid: 'Pagado',
      pending: 'Pendiente',
      expired: 'Vencido',
    };
    return labels[status] || status;
  }
}
