import { Component, Input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';
import { PlanSummary } from '../../services/api.service';

export interface PlanTableData extends PlanSummary {
  estimatedMembers?: number;
  estimatedIncome?: number;
  billingCycle?: string;
}

@Component({
  selector: 'app-plans-table',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="table-wrapper">
      <table class="plans-table">
        <thead class="table-head">
          <tr>
            <th class="col-name">Plan</th>
            <th class="col-price">Precio</th>
            <th class="col-duration">Duración</th>
            <th class="col-cycle">Ciclo</th>
            <th class="col-members">Miembros</th>
            <th class="col-income">Ingreso est.</th>
            <th class="col-status">Estado</th>
            <th class="col-actions">Acciones</th>
          </tr>
        </thead>
        <tbody class="table-body">
          <tr *ngFor="let plan of plans" class="table-row" [class.inactive-row]="!plan.active">
            <td class="col-name">
              <div class="plan-cell">
                <span class="plan-icon material-symbols-outlined">
                  {{ plan.id % 2 === 0 ? 'diamond' : 'fitness_center' }}
                </span>
                <span class="plan-name">{{ plan.name }}</span>
              </div>
            </td>
            <td class="col-price">
              <strong>{{ formatNumber(plan.price) }}</strong>
              <span class="cycle-text">/{{ plan.billingCycle || 'mes' }}</span>
            </td>
            <td class="col-duration">
              {{ getDurationLabel(plan.duration_days) }}
            </td>
            <td class="col-cycle">
              {{ getBillingLabel(plan.duration_days) }}
            </td>
            <td class="col-members">
              <span class="badge-count">{{ plan.estimatedMembers || 0 }}</span>
            </td>
            <td class="col-income">
              {{ formatCurrency(plan.estimatedIncome || 0) }}
            </td>
            <td class="col-status">
              <span class="status-pill" [class]="plan.active ? 'active' : 'inactive'">
                <i></i>
                {{ plan.active ? 'Activo' : 'Inactivo' }}
              </span>
            </td>
            <td class="col-actions">
              <div class="action-menu">
                <button class="action-icon" (click)="onEdit.emit(plan)" title="Editar">
                  <span class="material-symbols-outlined">edit</span>
                </button>
                <button class="action-icon" (click)="onViewMembers.emit(plan)" title="Ver miembros">
                  <span class="material-symbols-outlined">group</span>
                </button>
                <button class="action-icon" (click)="onDuplicate.emit(plan)" title="Duplicar">
                  <span class="material-symbols-outlined">content_copy</span>
                </button>
                <button class="action-icon" (click)="onDelete.emit(plan)" title="Eliminar">
                  <span class="material-symbols-outlined">delete</span>
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  `,
  styles: [
    `
      .table-wrapper {
        width: 100%;
        overflow-x: auto;
        border-radius: 12px;
        border: 1px solid #e5e5e5;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
      }

      .plans-table {
        width: 100%;
        border-collapse: collapse;
        font-family: Inter, sans-serif;
      }

      .table-head {
        background: #f9f9f9;
        border-bottom: 2px solid #e5e5e5;
      }

      .table-head th {
        padding: 1.25rem;
        text-align: left;
        font-size: 0.8rem;
        font-weight: 600;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        white-space: nowrap;
        font-family: 'Space Grotesk', sans-serif;
      }

      .table-body tr {
        border-bottom: 1px solid #e5e5e5;
        transition: all 200ms ease;
        background: #fff;
      }

      .table-body tr:hover {
        background: #f9f9f9;
      }

      .table-body tr.inactive-row {
        opacity: 0.85;
      }

      .table-body td {
        padding: 1.25rem;
        font-size: 0.95rem;
        color: #0a0a0a;
        vertical-align: middle;
      }

      .col-name {
        width: 22%;
      }

      .col-price {
        width: 15%;
      }

      .col-duration {
        width: 15%;
      }

      .col-cycle {
        width: 12%;
      }

      .col-members {
        width: 10%;
      }

      .col-income {
        width: 13%;
      }

      .col-status {
        width: 10%;
      }

      .col-actions {
        width: 8%;
        text-align: center;
      }

      .plan-cell {
        display: flex;
        align-items: center;
        gap: 0.75rem;
      }

      .plan-icon {
        width: 32px;
        height: 32px;
        display: grid;
        place-items: center;
        border-radius: 6px;
        background: #f5f5f5;
        color: #404040;
        flex-shrink: 0;
        font-size: 1rem;
      }

      .table-row:hover .plan-icon {
        background: #facc15;
        color: #000;
      }

      .plan-name {
        font-weight: 600;
        color: #0a0a0a;
      }

      .cycle-text {
        color: #999;
        font-size: 0.85rem;
        margin-left: 0.25rem;
      }

      .badge-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 8px;
        background: #ecfdf5;
        color: #047857;
        font-weight: 600;
        font-size: 0.9rem;
      }

      .status-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.4rem 0.75rem;
        border-radius: 8px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
      }

      .status-pill i {
        display: inline-block;
        width: 0.45rem;
        height: 0.45rem;
        border-radius: 50%;
        background: currentColor;
      }

      .status-pill.active {
        background: #ecfdf5;
        color: #047857;
      }

      .status-pill.inactive {
        background: #f5f5f5;
        color: #737373;
      }

      .action-menu {
        display: flex;
        gap: 0.4rem;
        justify-content: center;
      }

      .action-icon {
        display: grid;
        place-items: center;
        width: 32px;
        height: 32px;
        border: 1px solid #e5e5e5;
        border-radius: 6px;
        background: #fff;
        color: #666;
        cursor: pointer;
        transition: all 150ms ease;
        font-size: 1rem;
      }

      .action-icon:hover {
        border-color: #d0d0d0;
        background: #f9f9f9;
        color: #0a0a0a;
      }

      .action-icon:active {
        transform: scale(0.95);
      }

      @media (max-width: 1200px) {
        .table-head th,
        .table-body td {
          padding: 1rem;
          font-size: 0.85rem;
        }

        .col-name {
          width: 25%;
        }

        .col-price {
          width: 14%;
        }

        .col-duration {
          width: 14%;
        }

        .col-cycle {
          width: 11%;
        }

        .col-members {
          width: 10%;
        }

        .col-income {
          width: 12%;
        }

        .col-status {
          width: 10%;
        }

        .col-actions {
          width: 8%;
        }
      }

      @media (max-width: 900px) {
        .col-cycle {
          display: none;
        }

        .col-income {
          display: none;
        }

        .table-head th:nth-child(4),
        .table-body td:nth-child(4),
        .table-head th:nth-child(6),
        .table-body td:nth-child(6) {
          display: none;
        }
      }

      @media (max-width: 600px) {
        .table-head th,
        .table-body td {
          padding: 0.75rem;
          font-size: 0.8rem;
        }

        .col-duration {
          display: none;
        }

        .col-members {
          display: none;
        }

        .table-head th:nth-child(3),
        .table-body td:nth-child(3),
        .table-head th:nth-child(5),
        .table-body td:nth-child(5) {
          display: none;
        }

        .action-icon {
          width: 28px;
          height: 28px;
          font-size: 0.9rem;
        }
      }
    `,
  ],
})
export class PlansTableComponent {
  @Input() plans: PlanTableData[] = [];
  @Output() onEdit = new EventEmitter<PlanTableData>();
  @Output() onViewMembers = new EventEmitter<PlanTableData>();
  @Output() onDuplicate = new EventEmitter<PlanTableData>();
  @Output() onDelete = new EventEmitter<PlanTableData>();

  getDurationLabel(days: number): string {
    if (days >= 360) return '365 días';
    if (days >= 180) return '180 días';
    if (days >= 90) return '90 días';
    if (days >= 28 && days <= 31) return '30 días';
    return `${days} días`;
  }

  getBillingLabel(days: number): string {
    if (days >= 360) return 'Anual';
    if (days >= 180) return 'Semestral';
    if (days >= 90) return 'Trimestral';
    if (days >= 28 && days <= 31) return 'Mensual';
    return `C/ ${days}d`;
  }

  formatNumber(num: number): string {
    if (num >= 1000000) return '$' + (num / 1000000).toFixed(1) + 'M';
    if (num >= 1000) return '$' + (num / 1000).toFixed(0) + 'K';
    return '$' + num.toLocaleString('es-CO');
  }

  formatCurrency(num: number): string {
    if (num >= 1000000) return '$' + (num / 1000000).toFixed(1).replace('.0', '') + 'M';
    if (num >= 1000) return '$' + (num / 1000).toFixed(0) + 'K';
    return '$' + num.toLocaleString('es-CO');
  }
}
