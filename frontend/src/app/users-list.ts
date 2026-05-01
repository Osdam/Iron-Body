import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ApiService } from './services/api.service';
import { CreateMemberModalComponent } from './modules/components/create-member-modal';
import { MembersEmptyComponent } from './modules/components/members-empty';

@Component({
  selector: 'users-list',
  standalone: true,
  imports: [CommonModule, FormsModule, CreateMemberModalComponent, MembersEmptyComponent],
  template: `
    <section class="users-page">
      <!-- Header premium -->
      <header class="users-header">
        <div class="header-content">
          <h1>Miembros</h1>
          <p>Administra y registra los miembros del gimnasio, sus membresías y datos personales.</p>
        </div>
        <button type="button" class="btn-primary" (click)="openCreateMember()">
          <span class="material-symbols-outlined" aria-hidden="true">person_add</span>
          Nuevo miembro
        </button>
      </header>

      <!-- Loading State -->
      <div *ngIf="loading()" class="loading-state">
        <div class="spinner"></div>
        <p>Cargando miembros...</p>
      </div>

      <!-- Error State -->
      <div *ngIf="error()" class="error-alert">
        <span class="material-symbols-outlined">error</span>
        <div>
          <strong>Error al cargar miembros</strong>
          <p>{{ error() }}</p>
        </div>
      </div>

      <!-- Main Content -->
      <ng-container *ngIf="!loading() && !error()">
        <!-- Empty State -->
        <ng-container *ngIf="members().length === 0">
          <app-members-empty (onCreate)="openCreateMember()"></app-members-empty>
        </ng-container>

        <!-- Members Table -->
        <section *ngIf="members().length > 0" class="table-section">
          <div class="filters-section">
            <div class="filter-group">
              <input
                type="text"
                class="search-input"
                placeholder="Buscar miembro..."
                [ngModel]="searchQuery()"
                (ngModelChange)="searchQuery.set($event)"
                aria-label="Buscar miembros"
              />
              <span class="material-symbols-outlined">search</span>
            </div>
            <div class="filter-group">
              <select
                [ngModel]="filterStatus()"
                (ngModelChange)="filterStatus.set($event)"
                class="filter-select"
                aria-label="Filtrar por estado"
              >
                <option value="">Todos los estados</option>
                <option value="active">Activos</option>
                <option value="inactive">Inactivos</option>
                <option value="pending">Pendientes</option>
                <option value="expired">Vencidos</option>
              </select>
            </div>
          </div>

          <div class="members-cards">
            <article
              *ngFor="let member of filteredMembers()"
              class="member-flight-card"
              [ngClass]="'member-' + (member.status || 'active')"
            >
              <div class="member-card-top">
                <span class="badge" [class]="'status-' + (member.status || 'active')">
                  {{ getStatusLabel(member.status) }}
                </span>
                <span class="member-document">{{ member.document || 'Sin documento' }}</span>
              </div>

              <div class="member-card-grid">
                <div class="member-profile">
                  <div class="avatar large">{{ getInitials(member.name) }}</div>
                  <div>
                    <strong>{{ member.name }}</strong>
                    <span>{{ member.email || 'Sin correo' }}</span>
                    <button type="button" class="link-btn" (click)="viewMember(member)">
                      Ver detalles
                    </button>
                  </div>
                </div>

                <div class="membership-timeline">
                  <div class="timeline-point">
                    <strong>{{ member.created_at | date: 'dd MMM' }}</strong>
                    <span>Registro</span>
                  </div>
                  <div class="timeline-line">
                    <span>{{ membershipDuration(member) }}</span>
                    <div class="line">
                      <i [class.visible]="member.status !== 'active'"></i>
                    </div>
                    <strong>{{ membershipStateText(member) }}</strong>
                  </div>
                  <div class="timeline-point">
                    <strong>{{ member.membershipEndDate ? (member.membershipEndDate | date: 'dd MMM') : '—' }}</strong>
                    <span>Vence</span>
                  </div>
                </div>

                <div class="member-side">
                  <div class="plan-price">
                    <strong>{{ member.plan || 'Sin plan' }}</strong>
                    <span>{{ member.phone || 'Sin teléfono' }}</span>
                  </div>
                  <p class="member-offer">{{ membershipHint(member) }}</p>
                  <div class="member-actions">
                    <button type="button" class="book-btn" (click)="editMember(member)">
                      Editar
                      <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                    </button>
                    <button
                      type="button"
                      class="round-btn"
                      title="Cambiar estado"
                      (click)="toggleStatus(member)"
                    >
                      <span class="material-symbols-outlined">{{
                        member.status === 'active' ? 'block' : 'check_circle'
                      }}</span>
                    </button>
                    <button
                      type="button"
                      class="round-btn danger"
                      title="Eliminar"
                      (click)="deleteMember(member)"
                    >
                      <span class="material-symbols-outlined">delete</span>
                    </button>
                  </div>
                </div>
              </div>
            </article>
          </div>
        </section>
      </ng-container>
    </section>

    <!-- Modal -->
    <app-create-member-modal
      [isOpen]="isCreateMemberOpen"
      (onClose)="onCreateMemberModalClose()"
      (onMemberCreated)="onMemberCreated($event)"
    ></app-create-member-modal>
  `,
  styles: [
    `
      .users-page {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0;
        color: #0a0a0a;
      }

      /* Header */
      .users-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 2rem;
        margin-bottom: 2.5rem;
        flex-wrap: wrap;
        padding-bottom: 1.75rem;
        border-bottom: 2px solid #f0f0f0;
      }

      .header-content h1 {
        font-family: Inter, sans-serif;
        font-size: 2.5rem;
        font-weight: 700;
        margin: 0 0 0.6rem;
        letter-spacing: -0.02em;
        line-height: 1.1;
      }

      .header-content p {
        font-size: 1.05rem;
        line-height: 1.6;
        color: #666;
        margin: 0;
        max-width: 600px;
      }

      .btn-primary {
        display: inline-flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.95rem 2rem;
        background: #facc15;
        color: #000;
        border: none;
        border-radius: 10px;
        font-family: Inter, sans-serif;
        font-weight: 600;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 200ms cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 4px 12px rgba(250, 204, 21, 0.2);
      }

      .btn-primary:hover {
        background: #f0c00e;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(250, 204, 21, 0.3);
      }

      .btn-primary:active {
        transform: translateY(0);
      }

      /* Loading State */
      .loading-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 400px;
        gap: 1.5rem;
        color: #666;
      }

      .spinner {
        width: 40px;
        height: 40px;
        border: 3px solid #f0f0f0;
        border-top: 3px solid #facc15;
        border-radius: 50%;
        animation: spin 1s linear infinite;
      }

      @keyframes spin {
        to {
          transform: rotate(360deg);
        }
      }

      /* Error Alert */
      .error-alert {
        display: flex;
        gap: 1rem;
        padding: 1.5rem;
        background: #fee2e2;
        border: 1px solid #fecaca;
        border-radius: 10px;
        color: #991b1b;
        margin-bottom: 2rem;
      }

      .error-alert span {
        flex-shrink: 0;
        font-size: 1.5rem;
      }

      .error-alert strong {
        display: block;
        margin-bottom: 0.25rem;
      }

      .error-alert p {
        margin: 0;
        font-size: 0.9rem;
      }

      /* Table Section */
      .table-section {
        animation: fadeIn 300ms ease;
      }

      @keyframes fadeIn {
        from {
          opacity: 0;
          transform: translateY(10px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }

      /* Filters */
      .filters-section {
        display: flex;
        gap: 1rem;
        margin-bottom: 2rem;
        flex-wrap: wrap;
      }

      .filter-group {
        position: relative;
        flex: 1;
        min-width: 200px;
      }

      .search-input,
      .filter-select {
        width: 100%;
        padding: 0.875rem 1rem 0.875rem 2.75rem;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        font-family: Inter, sans-serif;
        font-size: 0.95rem;
        color: #0a0a0a;
        background: #fff;
        transition: all 200ms ease;
      }

      .search-input::placeholder {
        color: #999;
      }

      .search-input:focus,
      .filter-select:focus {
        outline: none;
        border-color: #facc15;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.1);
      }

      .filter-group span {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
        pointer-events: none;
      }

      .members-cards {
        display: grid;
        gap: 1rem;
      }

      .member-flight-card {
        width: 100%;
        border: 1px solid #e5e5e5;
        border-radius: 14px;
        background: #ffffff;
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.05);
        padding: 1.2rem;
        transition:
          transform 0.18s ease,
          box-shadow 0.18s ease,
          border-color 0.18s ease;
      }

      .member-flight-card:hover {
        transform: translateY(-2px);
        border-color: #d0d0d0;
        box-shadow: 0 16px 34px rgba(0, 0, 0, 0.08);
      }

      .member-card-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
        flex-wrap: wrap;
      }

      .member-document {
        padding: 0.35rem 0.65rem;
        border-radius: 999px;
        background: #f5f5f5;
        color: #666;
        font-size: 0.78rem;
        font-weight: 800;
      }

      .member-card-grid {
        display: grid;
        grid-template-columns: minmax(220px, 1.1fr) minmax(260px, 1.5fr) minmax(210px, 0.9fr);
        gap: 1.4rem;
        align-items: center;
      }

      .member-profile {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        min-width: 0;
      }

      .avatar.large {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        font-size: 0.95rem;
        font-weight: 900;
      }

      .member-profile strong,
      .member-profile span {
        display: block;
        overflow-wrap: anywhere;
      }

      .member-profile strong {
        font-size: 1rem;
        font-weight: 900;
      }

      .member-profile span {
        color: #666;
        font-size: 0.86rem;
        margin-top: 0.2rem;
      }

      .link-btn {
        border: 0;
        background: transparent;
        color: #ca8a04;
        padding: 0;
        margin-top: 0.45rem;
        font-size: 0.82rem;
        font-weight: 850;
        cursor: pointer;
      }

      .link-btn:hover {
        text-decoration: underline;
      }

      .membership-timeline {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: 0.75rem;
        align-items: center;
      }

      .timeline-point {
        text-align: center;
        min-width: 58px;
      }

      .timeline-point strong {
        display: block;
        font-size: 1.05rem;
        font-weight: 950;
      }

      .timeline-point span {
        color: #999;
        font-size: 0.74rem;
        font-weight: 800;
      }

      .timeline-line {
        display: grid;
        gap: 0.3rem;
        text-align: center;
        min-width: 0;
      }

      .timeline-line span {
        color: #666;
        font-size: 0.78rem;
        font-weight: 800;
      }

      .timeline-line strong {
        color: #ca8a04;
        font-size: 0.78rem;
        font-weight: 900;
      }

      .line {
        position: relative;
        height: 1px;
        background: #e5e5e5;
      }

      .line::before,
      .line::after {
        content: '';
        position: absolute;
        top: 50%;
        width: 7px;
        height: 7px;
        border-radius: 999px;
        background: #fbbf24;
        transform: translateY(-50%);
      }

      .line::before {
        left: 0;
      }

      .line::after {
        right: 0;
      }

      .line i {
        position: absolute;
        top: 50%;
        left: 50%;
        width: 8px;
        height: 8px;
        border-radius: 999px;
        background: #0a0a0a;
        transform: translate(-50%, -50%);
        opacity: 0;
      }

      .line i.visible {
        opacity: 1;
      }

      .member-side {
        display: grid;
        gap: 0.55rem;
        justify-items: end;
        text-align: right;
        min-width: 0;
      }

      .plan-price strong {
        display: block;
        font-size: 1.45rem;
        line-height: 1.1;
        font-weight: 950;
        overflow-wrap: anywhere;
      }

      .plan-price span {
        display: block;
        color: #666;
        font-size: 0.86rem;
        margin-top: 0.25rem;
      }

      .member-offer {
        color: #16a34a;
        font-size: 0.82rem;
        font-weight: 750;
      }

      .member-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.4rem;
        flex-wrap: wrap;
      }

      .book-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        min-height: 38px;
        padding: 0.65rem 0.9rem;
        border: 0;
        border-radius: 10px;
        background: #fbbf24;
        color: #0a0a0a;
        font-weight: 900;
        cursor: pointer;
      }

      .book-btn:hover {
        background: #f9a825;
      }

      .book-btn .material-symbols-outlined {
        font-size: 1rem;
      }

      .round-btn {
        width: 38px;
        height: 38px;
        display: grid;
        place-items: center;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        background: #ffffff;
        color: #666;
        cursor: pointer;
      }

      .round-btn:hover {
        border-color: #fbbf24;
        color: #ca8a04;
      }

      .round-btn.danger:hover {
        border-color: #fecaca;
        background: #fee2e2;
        color: #991b1b;
      }

      /* Table Wrapper */
      .table-wrapper {
        overflow-x: auto;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        background: #fff;
      }

      .members-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.95rem;
      }

      .members-table thead {
        background: #f9f9f9;
        border-bottom: 1px solid #e5e5e5;
      }

      .members-table th {
        padding: 1rem;
        text-align: left;
        font-weight: 600;
        color: #666;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-family: 'Space Grotesk', sans-serif;
      }

      .members-table td {
        padding: 1.25rem 1rem;
        border-bottom: 1px solid #f0f0f0;
        vertical-align: middle;
      }

      .table-row:hover {
        background: #f9f9f9;
      }

      .col-name {
        width: 20%;
      }

      .col-document {
        width: 12%;
      }

      .col-phone {
        width: 12%;
      }

      .col-email {
        width: 18%;
      }

      .col-plan {
        width: 12%;
      }

      .col-status {
        width: 10%;
      }

      .col-expiry {
        width: 12%;
      }

      .col-actions {
        width: 14%;
      }

      /* Member Name */
      .member-name {
        display: flex;
        align-items: center;
        gap: 0.75rem;
      }

      .avatar {
        display: grid;
        place-items: center;
        width: 36px;
        height: 36px;
        border-radius: 8px;
        background: linear-gradient(135deg, #facc15, #f0c00e);
        color: #000;
        font-weight: 600;
        font-size: 0.8rem;
        flex-shrink: 0;
      }

      .document-code {
        background: #f5f5f5;
        padding: 0.35rem 0.75rem;
        border-radius: 6px;
        font-family: 'Monaco', 'Courier New', monospace;
        font-size: 0.85rem;
        color: #0a0a0a;
      }

      .email-text {
        color: #666;
      }

      .text-muted {
        color: #999;
      }

      /* Badges */
      .badge {
        display: inline-block;
        padding: 0.4rem 0.75rem;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: capitalize;
      }

      .badge-plan {
        background: #f0f0f0;
        color: #666;
      }

      .status-active {
        background: #dcfce7;
        color: #166534;
      }

      .status-inactive {
        background: #fee2e2;
        color: #991b1b;
      }

      .status-pending {
        background: #fef3c7;
        color: #a16207;
      }

      .status-expired {
        background: #e0e7ff;
        color: #3730a3;
      }

      .plan-monthly,
      .plan-quarterly,
      .plan-semi_annual,
      .plan-annual,
      .plan-vip {
        background: rgba(250, 204, 21, 0.1);
        color: #ca8a04;
      }

      /* Date Text */
      .date-text {
        color: #666;
      }

      /* Action Buttons */
      .action-buttons {
        display: flex;
        gap: 0.5rem;
        justify-content: flex-end;
      }

      .action-btn {
        display: grid;
        place-items: center;
        width: 32px;
        height: 32px;
        border: 1px solid #e5e5e5;
        border-radius: 6px;
        background: #fff;
        color: #666;
        cursor: pointer;
        transition: all 200ms ease;
      }

      .action-btn:hover {
        border-color: #facc15;
        background: rgba(250, 204, 21, 0.1);
        color: #ca8a04;
      }

      .action-btn.delete:hover {
        border-color: #dc2626;
        background: #fee2e2;
        color: #dc2626;
      }

      /* Responsive */
      @media (max-width: 1024px) {
        .users-header {
          flex-direction: column;
          align-items: flex-start;
          gap: 1.5rem;
        }

        .btn-primary {
          width: 100%;
          justify-content: center;
        }

        .col-email {
          width: 15%;
        }

        .col-expiry {
          width: 10%;
        }

        .col-actions {
          width: 12%;
        }

        .member-card-grid {
          grid-template-columns: 1fr;
          align-items: stretch;
        }

        .member-side {
          justify-items: start;
          text-align: left;
        }

        .member-actions {
          justify-content: flex-start;
        }
      }

      @media (max-width: 768px) {
        .header-content h1 {
          font-size: 1.75rem;
        }

        .header-content p {
          font-size: 0.95rem;
        }

        .members-table {
          font-size: 0.85rem;
        }

        .members-table th,
        .members-table td {
          padding: 0.75rem 0.5rem;
        }

        .col-phone,
        .col-document {
          display: none;
        }

        .member-name,
        .col-name {
          width: auto;
        }

        .filters-section {
          flex-direction: column;
          gap: 0.75rem;
        }

        .filter-group {
          min-width: 100%;
        }

        .membership-timeline {
          grid-template-columns: 1fr;
          gap: 0.55rem;
        }

        .timeline-point {
          display: flex;
          justify-content: space-between;
          align-items: center;
          min-width: 0;
          text-align: left;
        }

        .timeline-line {
          order: 3;
        }
      }

      @media (max-width: 480px) {
        .header-content h1 {
          font-size: 1.5rem;
        }

        .users-header {
          margin-bottom: 1.75rem;
          padding-bottom: 1.25rem;
        }

        .members-table {
          font-size: 0.8rem;
        }

        .members-table th,
        .members-table td {
          padding: 0.65rem 0.4rem;
        }

        .col-email,
        .col-plan {
          display: none;
        }

        .action-btn {
          width: 28px;
          height: 28px;
          font-size: 0.9rem;
        }

        .member-flight-card {
          padding: 1rem;
        }

        .member-profile {
          align-items: flex-start;
        }

        .book-btn,
        .round-btn {
          width: 100%;
        }

        .round-btn {
          height: 38px;
        }

        .member-actions {
          display: grid;
          grid-template-columns: 1fr;
          width: 100%;
        }
      }
    `,
  ],
})
export class UsersList implements OnInit {
  private api = inject(ApiService);

  // Signals
  members = signal<any[]>([]);
  loading = signal(true);
  error = signal('');
  isCreateMemberOpen = signal(false);
  searchQuery = signal('');
  filterStatus = signal('');
  filteredMembers = computed(() => {
    const search = this.searchQuery().toLowerCase();
    const status = this.filterStatus();

    return this.members().filter((member) => {
      if (search) {
        const searchFields = [member.name, member.email, member.document, member.phone]
          .join(' ')
          .toLowerCase();
        if (!searchFields.includes(search)) return false;
      }

      if (status && member.status !== status) return false;

      return true;
    });
  });

  ngOnInit(): void {
    this.api.getUsers().subscribe({
      next: (res) => {
        this.members.set(res.data || []);
        this.loading.set(false);
      },
      error: () => {
        this.error.set('No se pudieron cargar los miembros. Revisa la conexión con Laravel.');
        this.loading.set(false);
      },
    });

  }

  openCreateMember(): void {
    this.isCreateMemberOpen.set(true);
  }

  onCreateMemberModalClose(): void {
    this.isCreateMemberOpen.set(false);
  }

  onMemberCreated(newMember: any): void {
    // Agregar miembro a la lista
    this.members.update((members) => [newMember, ...members]);
    this.isCreateMemberOpen.set(false);
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

  getStatusLabel(status: string): string {
    const labels: { [key: string]: string } = {
      active: 'Activo',
      inactive: 'Inactivo',
      pending: 'Pendiente',
      expired: 'Vencido',
    };
    return labels[status] || 'Desconocido';
  }

  membershipDuration(member: any): string {
    if (!member.membershipEndDate) return 'Sin vigencia';

    const start = member.created_at ? new Date(member.created_at) : new Date();
    const end = new Date(member.membershipEndDate);
    if (Number.isNaN(end.getTime())) return 'Sin vigencia';

    const days = Math.max(0, Math.ceil((end.getTime() - start.getTime()) / 86400000));
    if (days >= 365) return 'Plan anual';
    if (days >= 180) return 'Plan semestral';
    if (days >= 90) return 'Plan trimestral';
    if (days >= 28) return 'Plan mensual';
    return `${days} días`;
  }

  membershipStateText(member: any): string {
    const status = String(member.status || 'active');
    if (status === 'expired') return 'Membresía vencida';
    if (status === 'pending') return 'Pendiente de activar';
    if (status === 'inactive') return 'Miembro inactivo';
    if (!member.membershipEndDate) return 'Sin fecha final';

    const end = new Date(member.membershipEndDate);
    const today = new Date();
    const daysLeft = Math.ceil((end.getTime() - today.getTime()) / 86400000);
    if (daysLeft < 0) return 'Membresía vencida';
    if (daysLeft <= 7) return 'Por vencer';
    return 'Membresía activa';
  }

  membershipHint(member: any): string {
    const state = this.membershipStateText(member);
    if (state === 'Membresía activa') return 'Acceso habilitado';
    if (state === 'Por vencer') return 'Recomendar renovación';
    if (state === 'Pendiente de activar') return 'Validar pago o registro';
    if (state === 'Miembro inactivo') return 'Campaña de reactivación';
    return 'Revisar membresía';
  }

  viewMember(member: any): void {
    console.log('Ver perfil:', member);
    alert(`Ver perfil de ${member.name}`);
  }

  editMember(member: any): void {
    console.log('Editar:', member);
    alert(`Editar ${member.name}`);
  }

  toggleStatus(member: any): void {
    console.log('Cambiar estado:', member);
    const newStatus = member.status === 'active' ? 'inactive' : 'active';
    alert(`Cambiar estado de ${member.name} a ${newStatus}`);
  }

  deleteMember(member: any): void {
    if (confirm(`¿Eliminar a ${member.name}? Esta acción no se puede deshacer.`)) {
      this.members.update((members) => members.filter((m) => m.id !== member.id));
    }
  }
}
