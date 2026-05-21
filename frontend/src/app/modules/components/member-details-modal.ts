import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, Output } from '@angular/core';
import { UserSummary } from '../../services/api.service';

@Component({
  selector: 'app-member-details-modal',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div *ngIf="isOpen" class="modal-backdrop" (click)="close()" aria-hidden="true"></div>

    <div *ngIf="isOpen && member" class="modal-container">
      <div class="modal-card">
        <header class="modal-header">
          <div class="header-title">
            <div class="avatar">{{ getInitials(member.name) }}</div>
            <div>
              <h2>{{ member.name }}</h2>
              <p>{{ member.email || 'Sin correo' }}</p>
            </div>
          </div>
          <button type="button" class="btn-close" (click)="close()" aria-label="Cerrar">
            <span class="material-symbols-outlined">close</span>
          </button>
        </header>

        <div class="status-row">
          <span class="badge" [class]="'status-' + (member.status || 'active')">
            {{ getStatusLabel(member.status) }}
          </span>
          <span class="member-id">ID #{{ member.id }}</span>
        </div>

        <section class="details-grid">
          <div class="detail-item">
            <span class="label">Documento</span>
            <strong>{{ member.document || '—' }}</strong>
          </div>
          <div class="detail-item">
            <span class="label">Teléfono</span>
            <strong>{{ member.phone || '—' }}</strong>
          </div>
          <div class="detail-item full">
            <span class="label">Correo electrónico</span>
            <strong>{{ member.email || '—' }}</strong>
          </div>
          <div class="detail-item full">
            <span class="label">Plan / Membresía</span>
            <strong>{{ member.plan || 'Sin plan' }}</strong>
          </div>
          <div class="detail-item">
            <span class="label">Inicio de membresía</span>
            <strong>{{
              member.membershipStartDate ? (member.membershipStartDate | date: 'dd MMM yyyy') : '—'
            }}</strong>
          </div>
          <div class="detail-item">
            <span class="label">Vencimiento</span>
            <strong>{{
              member.membershipEndDate ? (member.membershipEndDate | date: 'dd MMM yyyy') : '—'
            }}</strong>
          </div>
          <div class="detail-item full">
            <span class="label">Registrado</span>
            <strong>{{ member.created_at | date: 'dd MMM yyyy, HH:mm' }}</strong>
          </div>
        </section>

        <footer class="modal-footer">
          <button type="button" class="btn-secondary" (click)="close()">Cerrar</button>
          <button type="button" class="btn-primary" (click)="emitEdit()">
            <span class="material-symbols-outlined">edit</span>
            Editar
          </button>
        </footer>
      </div>
    </div>
  `,
  styles: [
    `
      .modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.72);
        backdrop-filter: blur(6px);
        z-index: 999;
      }

      .modal-container {
        position: fixed;
        inset: 0;
        z-index: 1000;
        display: grid;
        place-items: center;
        padding: 1.5rem;
      }

      .modal-card {
        width: 100%;
        max-width: 540px;
        background: #1c1b1b;
        border: 1px solid rgba(245, 197, 24, 0.12);
        color: #e5e2e1;
        border-radius: 16px;
        box-shadow: 0 24px 70px rgba(0, 0, 0, 0.48);
        overflow: hidden;
        animation: slideUp 220ms cubic-bezier(0.4, 0, 0.2, 1);
      }

      @keyframes slideUp {
        from {
          opacity: 0;
          transform: translateY(14px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }

      .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.5rem 1.75rem 1rem;
      }

      .header-title {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        min-width: 0;
      }

      .avatar {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        background: linear-gradient(135deg, #facc15, #f0c00e);
        color: #241a00;
        font-weight: 800;
        font-size: 1rem;
        display: grid;
        place-items: center;
        flex-shrink: 0;
      }

      .header-title h2 {
        font: 700 1.15rem Inter, sans-serif;
        margin: 0 0 0.2rem;
        color: #e5e2e1;
        overflow-wrap: anywhere;
      }

      .header-title p {
        font: 400 0.85rem Inter, sans-serif;
        color: #b4afa6;
        margin: 0;
        overflow-wrap: anywhere;
      }

      .btn-close {
        background: #1a1a1a;
        border: 1px solid #353534;
        border-radius: 8px;
        width: 36px;
        height: 36px;
        cursor: pointer;
        display: grid;
        place-items: center;
        color: #e5e2e1;
        transition: all 150ms ease;
        flex-shrink: 0;
      }

      .btn-close:hover {
        background: #2a2a2a;
        border-color: #f5c518;
        color: #ffe08b;
      }

      .status-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 1.75rem 1rem;
        border-bottom: 1px solid #353534;
      }

      .badge {
        display: inline-block;
        padding: 0.4rem 0.85rem;
        border-radius: 999px;
        font: 700 0.78rem Inter, sans-serif;
      }

      .status-active {
        background: rgba(245, 197, 24, 0.14);
        border: 1px solid rgba(245, 197, 24, 0.28);
        color: #ffe08b;
      }
      .status-inactive {
        background: rgba(180, 181, 181, 0.12);
        border: 1px solid rgba(180, 181, 181, 0.24);
        color: #c6c6c7;
      }
      .status-pending {
        background: rgba(245, 197, 24, 0.12);
        border: 1px solid rgba(245, 197, 24, 0.24);
        color: #ffe08b;
      }
      .status-expired {
        background: rgba(158, 197, 255, 0.12);
        border: 1px solid rgba(158, 197, 255, 0.24);
        color: #d6e3ff;
      }

      .member-id {
        font: 600 0.78rem 'Space Grotesk', sans-serif;
        color: #b4afa6;
        letter-spacing: 0.05em;
      }

      .details-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        padding: 1.5rem 1.75rem;
      }

      .detail-item.full {
        grid-column: 1 / -1;
      }

      .detail-item .label {
        display: block;
        font: 600 0.74rem 'Space Grotesk', sans-serif;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #d1c5ac;
        margin-bottom: 0.35rem;
      }

      .detail-item strong {
        font: 600 0.95rem Inter, sans-serif;
        color: #e5e2e1;
        overflow-wrap: anywhere;
      }

      .modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 0.65rem;
        padding: 1rem 1.75rem 1.5rem;
        border-top: 1px solid #353534;
      }

      .btn-secondary,
      .btn-primary {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.7rem 1.3rem;
        border-radius: 9px;
        font: 600 0.92rem Inter, sans-serif;
        cursor: pointer;
        border: none;
        transition: all 180ms ease;
      }

      .btn-secondary {
        border: 1px solid #353534;
        background: #1a1a1a;
        color: #e5e2e1;
      }

      .btn-secondary:hover {
        border-color: #f5c518;
        background: #2a2a2a;
        color: #ffe08b;
      }

      .btn-primary {
        background: #f5c518;
        color: #241a00;
        font-weight: 700;
      }

      .btn-primary:hover {
        background: #ffd43b;
      }

      .btn-primary .material-symbols-outlined {
        font-size: 1.05rem;
      }

      @media (max-width: 560px) {
        .details-grid {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export class MemberDetailsModalComponent {
  @Input() isOpen = false;
  @Input() member: UserSummary | null = null;
  @Output() onClose = new EventEmitter<void>();
  @Output() onEdit = new EventEmitter<UserSummary>();

  close(): void {
    this.onClose.emit();
  }

  emitEdit(): void {
    if (this.member) this.onEdit.emit(this.member);
  }

  getInitials(name: string): string {
    if (!name) return '—';
    return name
      .split(' ')
      .slice(0, 2)
      .map((n) => n[0])
      .join('')
      .toUpperCase();
  }

  getStatusLabel(status?: string): string {
    const labels: { [key: string]: string } = {
      active: 'Activo',
      inactive: 'Inactivo',
      pending: 'Pendiente',
      expired: 'Vencido',
    };
    return labels[status || 'active'] || 'Desconocido';
  }
}
