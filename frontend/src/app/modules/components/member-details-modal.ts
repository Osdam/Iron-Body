import { CommonModule } from '@angular/common';
import {
  Component,
  EventEmitter,
  Input,
  Output,
  OnChanges,
  SimpleChanges,
  inject,
} from '@angular/core';
import {
  ApiService,
  MemberContractSummary,
  MemberLegalSummary,
  UserSummary,
} from '../../services/api.service';

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

        <section class="contracts-section">
          <h3>Contratos y estado legal</h3>

          <div *ngIf="memberLegal" class="legal-status">
            <span class="legal-chip">
              Biometría: <strong>{{ biometricLabel(memberLegal.biometric_status) }}</strong>
            </span>
            <span class="legal-chip" *ngIf="memberLegal.is_minor">Menor de edad</span>
          </div>

          <div *ngIf="contractsLoading" class="contracts-empty">Cargando contratos…</div>

          <div *ngIf="!contractsLoading && contractsError" class="contracts-empty error">
            {{ contractsError }}
          </div>

          <div
            *ngIf="!contractsLoading && !contractsError && contracts.length === 0"
            class="contracts-empty"
          >
            Este miembro aún no tiene contratos firmados.
          </div>

          <div
            *ngFor="let c of contracts"
            class="contract-card"
            [class.voided]="c.status === 'void'"
          >
            <div class="contract-head">
              <strong>{{ contractTypeLabel(c.contract_type) }}</strong>
              <span class="contract-badge" [class.void]="c.status === 'void'">
                {{ c.status === 'void' ? 'Anulado' : 'Firmado' }}
              </span>
            </div>
            <div class="contract-meta">
              <span>Folio: {{ c.folio || '—' }}</span>
              <span>Versión: {{ c.template_version || '—' }}</span>
              <span>Firmado: {{ c.signed_at ? (c.signed_at | date: 'dd MMM yyyy, HH:mm') : '—' }}</span>
              <span>Imagen: {{ imageAuthLabel(c) }}</span>
              <span *ngIf="c.checksum" class="hash">Hash: {{ c.checksum.slice(0, 16) }}…</span>
            </div>
            <div class="contract-actions">
              <button
                type="button"
                class="btn-secondary sm"
                [disabled]="!c.has_pdf || downloadingUuid === c.uuid"
                (click)="downloadContract(c)"
              >
                <span class="material-symbols-outlined">picture_as_pdf</span>
                {{ downloadingUuid === c.uuid ? 'Descargando…' : 'Descargar PDF' }}
              </button>
              <button
                type="button"
                class="btn-danger sm"
                *ngIf="c.status !== 'void'"
                (click)="voidContract(c)"
              >
                <span class="material-symbols-outlined">block</span>
                Anular
              </button>
            </div>
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

      /* ── Contratos ───────────────────────────────────────────── */
      .contracts-section {
        padding: 0 1.75rem 1.25rem;
        border-top: 1px solid #353534;
      }

      .contracts-section h3 {
        font: 700 0.78rem 'Space Grotesk', sans-serif;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #d1c5ac;
        margin: 1.1rem 0 0.75rem;
      }

      .contracts-empty {
        font: 400 0.85rem Inter, sans-serif;
        color: #b4afa6;
        padding: 0.5rem 0;
      }

      .legal-status {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 0.85rem;
      }

      .legal-chip {
        padding: 0.3rem 0.7rem;
        border-radius: 8px;
        background: #1a1a1a;
        border: 1px solid #353534;
        font: 400 0.78rem Inter, sans-serif;
        color: #b4afa6;
      }

      .legal-chip strong {
        color: #e5e2e1;
        font-weight: 600;
      }

      .contracts-empty.error {
        color: #f1a8a8;
      }

      .contract-card {
        background: #1a1a1a;
        border: 1px solid #353534;
        border-radius: 10px;
        padding: 0.85rem 1rem;
        margin-bottom: 0.65rem;
      }

      .contract-card.voided {
        opacity: 0.7;
      }

      .contract-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        margin-bottom: 0.45rem;
      }

      .contract-head strong {
        font: 600 0.9rem Inter, sans-serif;
        color: #e5e2e1;
      }

      .contract-badge {
        padding: 0.2rem 0.6rem;
        border-radius: 999px;
        font: 700 0.68rem Inter, sans-serif;
        background: rgba(245, 197, 24, 0.14);
        border: 1px solid rgba(245, 197, 24, 0.28);
        color: #ffe08b;
        white-space: nowrap;
      }

      .contract-badge.void {
        background: rgba(241, 168, 168, 0.12);
        border-color: rgba(241, 168, 168, 0.3);
        color: #f1a8a8;
      }

      .contract-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem 1rem;
        font: 400 0.78rem Inter, sans-serif;
        color: #b4afa6;
        margin-bottom: 0.7rem;
      }

      .contract-meta .hash {
        font-family: 'Space Grotesk', monospace;
      }

      .contract-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
      }

      .btn-secondary.sm,
      .btn-danger.sm {
        padding: 0.5rem 0.85rem;
        font-size: 0.82rem;
        border-radius: 8px;
      }

      .btn-secondary.sm .material-symbols-outlined,
      .btn-danger.sm .material-symbols-outlined {
        font-size: 1rem;
      }

      .btn-secondary.sm:disabled {
        opacity: 0.5;
        cursor: not-allowed;
      }

      .btn-danger {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        border: 1px solid rgba(241, 168, 168, 0.35);
        background: rgba(241, 168, 168, 0.08);
        color: #f1a8a8;
        cursor: pointer;
        transition: all 180ms ease;
      }

      .btn-danger:hover {
        background: rgba(241, 168, 168, 0.16);
        border-color: #f1a8a8;
      }
    `,
  ],
})
export class MemberDetailsModalComponent implements OnChanges {
  @Input() isOpen = false;
  @Input() member: UserSummary | null = null;
  @Output() onClose = new EventEmitter<void>();
  @Output() onEdit = new EventEmitter<UserSummary>();

  private api = inject(ApiService);

  contracts: MemberContractSummary[] = [];
  memberLegal: MemberLegalSummary | null = null;
  contractsLoading = false;
  contractsError: string | null = null;
  downloadingUuid: string | null = null;
  private loadedForUserId: number | null = null;

  ngOnChanges(changes: SimpleChanges): void {
    // Carga los contratos cuando se abre el modal con un miembro (una vez por id).
    if ((changes['isOpen'] || changes['member']) && this.isOpen && this.member) {
      if (this.loadedForUserId !== this.member.id) {
        this.loadContracts(this.member.id);
      }
    }
    if (changes['isOpen'] && !this.isOpen) {
      this.loadedForUserId = null;
      this.contracts = [];
      this.contractsError = null;
    }
  }

  private loadContracts(userId: number): void {
    this.contractsLoading = true;
    this.contractsError = null;
    this.loadedForUserId = userId;
    this.api.getUserContracts(userId).subscribe({
      next: (res) => {
        this.contracts = res.data ?? [];
        this.memberLegal = res.member ?? null;
        this.contractsLoading = false;
      },
      error: () => {
        this.contractsError = 'No se pudieron cargar los contratos.';
        this.contractsLoading = false;
      },
    });
  }

  biometricLabel(status?: string): string {
    const labels: { [key: string]: string } = {
      registered: 'Verificada',
      pending: 'Pendiente',
      skipped: 'Omitida',
      manual_required: 'Verificación presencial',
    };
    return labels[status || 'pending'] || 'Pendiente';
  }

  imageAuthLabel(c: MemberContractSummary): string {
    if (c.image_authorized === true) return 'Sí';
    if (c.image_authorized === false) return 'No';
    return '—';
  }

  contractTypeLabel(type: string): string {
    const labels: { [key: string]: string } = {
      workout_registration: 'Inscripción IRONBODY WORKOUT',
      basic_registration: 'Inscripción IRONBODY',
      minor_release: 'Liberación de responsabilidad (menor)',
    };
    return labels[type] || type;
  }

  downloadContract(c: MemberContractSummary): void {
    if (!c.has_pdf || this.downloadingUuid === c.uuid) return;
    this.downloadingUuid = c.uuid;
    this.api.downloadContract(c.uuid).subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `contrato_${c.contract_type}_${c.folio || c.uuid}.pdf`;
        a.click();
        URL.revokeObjectURL(url);
        this.downloadingUuid = null;
      },
      error: () => {
        this.downloadingUuid = null;
        this.contractsError = 'No se pudo descargar el PDF.';
      },
    });
  }

  voidContract(c: MemberContractSummary): void {
    const reason = window.prompt(
      'Motivo de anulación (mínimo 5 caracteres). El PDF firmado no se elimina, solo se marca como anulado:',
    );
    if (reason === null) return;
    if (reason.trim().length < 5) {
      this.contractsError = 'El motivo de anulación debe tener al menos 5 caracteres.';
      return;
    }
    this.api.voidContract(c.uuid, reason.trim()).subscribe({
      next: () => {
        if (this.member) this.loadContracts(this.member.id);
      },
      error: () => {
        this.contractsError = 'No se pudo anular el contrato.';
      },
    });
  }

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
