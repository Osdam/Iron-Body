import { Component, EventEmitter, Input, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import type { Trainer } from './trainer-card';

@Component({
  selector: 'app-trainers-table',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="table-wrap" *ngIf="trainers.length; else empty">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Entrenador</th>
            <th>Especialidad</th>
            <th>Teléfono</th>
            <th>Correo</th>
            <th>Clases</th>
            <th>Miembros</th>
            <th>Disponibilidad</th>
            <th>Estado</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr *ngFor="let t of trainers; trackBy: trackTrainer">
            <td class="cell-name">
              <span class="trainer-name">{{ t.fullName }}</span>
              <span class="trainer-doc">{{ t.document }}</span>
            </td>
            <td class="cell-specialty">
              <span class="specialty-badge">{{ t.mainSpecialty }}</span>
            </td>
            <td class="cell-phone">
              <a [href]="'tel:' + t.phone">{{ t.phone }}</a>
            </td>
            <td class="cell-email">
              <a [href]="'mailto:' + t.email">{{ truncateEmail(t.email) }}</a>
            </td>
            <td class="cell-centered">{{ t.assignedClasses }}</td>
            <td class="cell-centered">{{ t.assignedMembers }}</td>
            <td class="cell-availability">
              <span class="avail-text">{{ getAvailabilityLabel(t) }}</span>
            </td>
            <td class="cell-status">
              <span
                class="status-badge"
                [ngClass]="'status-' + (t.status || 'Activo').toLowerCase()"
              >
                {{ t.status }}
              </span>
            </td>
            <td class="cell-actions">
              <button
                type="button"
                class="action-cell-btn view"
                (click)="onView(t)"
                title="Ver"
                aria-label="Ver perfil"
              >
                <span class="material-symbols-outlined">visibility</span>
              </button>
              <button
                type="button"
                class="action-cell-btn edit"
                (click)="onEdit(t)"
                title="Editar"
                aria-label="Editar"
              >
                <span class="material-symbols-outlined">edit</span>
              </button>
              <button
                type="button"
                class="action-cell-btn toggle"
                (click)="onToggleStatus(t)"
                title="Cambiar estado"
                aria-label="Cambiar estado"
              >
                <span class="material-symbols-outlined">
                  {{ t.status === 'Activo' ? 'check_circle' : 'cancel' }}
                </span>
              </button>
              <button
                type="button"
                class="action-cell-btn delete"
                (click)="onDelete(t)"
                title="Eliminar"
                aria-label="Eliminar"
              >
                <span class="material-symbols-outlined">delete</span>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <ng-template #empty>
      <div class="table-empty">No hay entrenadores para mostrar.</div>
    </ng-template>
  `,
  styles: [
    `
      .table-wrap {
        border: 1px solid #f0f0f0;
        border-radius: 16px;
        background: #ffffff;
        overflow-x: auto;
        box-shadow: 0 8px 18px rgba(0, 0, 0, 0.04);
      }

      .admin-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9rem;
      }

      .admin-table thead {
        background: #fafafa;
        border-bottom: 1px solid #f0f0f0;
      }

      .admin-table th {
        padding: 0.9rem 1rem;
        text-align: left;
        font-weight: 900;
        color: #0a0a0a;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
      }

      .admin-table td {
        padding: 0.95rem 1rem;
        border-bottom: 1px solid #f5f5f5;
        vertical-align: middle;
      }

      .admin-table tbody tr:hover {
        background: #fafafa;
      }

      .cell-name {
        font-weight: 700;
        color: #0a0a0a;
      }

      .trainer-name {
        display: block;
        line-height: 1.2;
      }

      .trainer-doc {
        display: block;
        font-size: 0.75rem;
        color: #999;
        font-weight: 400;
        margin-top: 0.2rem;
      }

      .cell-specialty {
        color: #666;
      }

      .specialty-badge {
        display: inline-block;
        padding: 0.35rem 0.6rem;
        border-radius: 999px;
        background: rgba(251, 191, 36, 0.12);
        color: #ca8a04;
        font-size: 0.75rem;
        font-weight: 800;
        border: 1px solid rgba(251, 191, 36, 0.3);
      }

      .cell-phone,
      .cell-email {
        color: #0066cc;
        text-decoration: none;
      }

      .cell-phone a,
      .cell-email a {
        color: #0066cc;
        text-decoration: none;
        transition: color 0.15s ease;
      }

      .cell-phone a:hover,
      .cell-email a:hover {
        color: #0052a3;
        text-decoration: underline;
      }

      .cell-centered {
        text-align: center;
        font-weight: 700;
        color: #0a0a0a;
      }

      .cell-availability {
        font-size: 0.85rem;
        color: #666;
      }

      .avail-text {
        display: inline-block;
        padding: 0.3rem 0.5rem;
        border-radius: 8px;
        background: #f0f0f0;
      }

      .cell-status {
        text-align: center;
      }

      .status-badge {
        display: inline-block;
        padding: 0.35rem 0.65rem;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.03em;
      }

      .status-badge.status-activo {
        background: rgba(34, 197, 94, 0.12);
        color: #16a34a;
        border: 1px solid rgba(34, 197, 94, 0.3);
      }

      .status-badge.status-inactivo {
        background: rgba(107, 114, 128, 0.12);
        color: #4b5563;
        border: 1px solid rgba(107, 114, 128, 0.3);
      }

      .status-badge.status-pendiente {
        background: rgba(249, 115, 22, 0.12);
        color: #ea580c;
        border: 1px solid rgba(249, 115, 22, 0.3);
      }

      .cell-actions {
        text-align: center;
        display: flex;
        gap: 0.4rem;
        justify-content: center;
      }

      .action-cell-btn {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: 1px solid #e5e5e5;
        background: #ffffff;
        cursor: pointer;
        display: grid;
        place-items: center;
        transition: all 0.15s ease;
      }

      .action-cell-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08);
      }

      .action-cell-btn.view:hover {
        background: #f0f0f0;
        color: #0066cc;
      }

      .action-cell-btn.edit:hover {
        background: #fef3c7;
        border-color: #fbbf24;
        color: #ca8a04;
      }

      .action-cell-btn.toggle:hover {
        background: #dbeafe;
        border-color: #0066cc;
        color: #0066cc;
      }

      .action-cell-btn.delete:hover {
        background: #fee2e2;
        border-color: #ef4444;
        color: #991b1b;
      }

      .action-cell-btn span {
        font-size: 0.95rem;
      }

      .table-empty {
        padding: 1.5rem;
        text-align: center;
        color: #999;
        font-size: 0.95rem;
      }
    `,
  ],
})
export default class TrainersTableComponent {
  @Input() trainers: Trainer[] = [];
  @Output() view = new EventEmitter<Trainer>();
  @Output() edit = new EventEmitter<Trainer>();
  @Output() toggleStatus = new EventEmitter<Trainer>();
  @Output() delete = new EventEmitter<Trainer>();

  trackTrainer = (_: number, t: Trainer) => t.id;

  onView(trainer: Trainer): void {
    this.view.emit(trainer);
  }

  onEdit(trainer: Trainer): void {
    this.edit.emit(trainer);
  }

  onToggleStatus(trainer: Trainer): void {
    this.toggleStatus.emit(trainer);
  }

  onDelete(trainer: Trainer): void {
    this.delete.emit(trainer);
  }

  truncateEmail(email: string): string {
    if (!email || email.length <= 20) return email;
    return email.slice(0, 17) + '...';
  }

  getAvailabilityLabel(trainer: Trainer): string {
    if (!trainer.availability || trainer.availability.length === 0) {
      return 'Sin configurar';
    }

    const available = trainer.availability.filter((a) => a.enabled);
    if (available.length === 0) return 'No disponible';
    if (available.length === 7) return 'Disponible todos';
    return `${available.length} días`;
  }
}
