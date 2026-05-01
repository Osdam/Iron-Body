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

          <div class="table-wrapper">
            <table class="members-table">
              <thead>
                <tr>
                  <th class="col-name">Nombre</th>
                  <th class="col-document">Documento</th>
                  <th class="col-phone">Teléfono</th>
                  <th class="col-email">Correo</th>
                  <th class="col-plan">Plan</th>
                  <th class="col-status">Estado</th>
                  <th class="col-expiry">Vencimiento</th>
                  <th class="col-actions">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <tr *ngFor="let member of filteredMembers()" class="table-row">
                  <td class="col-name">
                    <div class="member-name">
                      <div class="avatar">{{ getInitials(member.name) }}</div>
                      <span>{{ member.name }}</span>
                    </div>
                  </td>
                  <td class="col-document">
                    <code class="document-code">{{ member.document || '—' }}</code>
                  </td>
                  <td class="col-phone">{{ member.phone || '—' }}</td>
                  <td class="col-email">
                    <span class="email-text"
                      >{{ member.email | slice: 0 : 25
                      }}{{ member.email.length > 25 ? '...' : '' }}</span
                    >
                  </td>
                  <td class="col-plan">
                    <span class="badge badge-plan" [class]="'plan-' + (member.plan || 'none')">
                      {{ member.plan || 'Sin plan' }}
                    </span>
                  </td>
                  <td class="col-status">
                    <span class="badge" [class]="'status-' + (member.status || 'active')">
                      {{ getStatusLabel(member.status) }}
                    </span>
                  </td>
                  <td class="col-expiry">
                    <span *ngIf="member.membershipEndDate; else noDate" class="date-text">
                      {{ member.membershipEndDate | date: 'short' }}
                    </span>
                    <ng-template #noDate>
                      <span class="text-muted">—</span>
                    </ng-template>
                  </td>
                  <td class="col-actions">
                    <div class="action-buttons">
                      <button class="action-btn" title="Ver perfil" (click)="viewMember(member)">
                        <span class="material-symbols-outlined">visibility</span>
                      </button>
                      <button class="action-btn" title="Editar" (click)="editMember(member)">
                        <span class="material-symbols-outlined">edit</span>
                      </button>
                      <button
                        class="action-btn"
                        title="Cambiar estado"
                        (click)="toggleStatus(member)"
                      >
                        <span class="material-symbols-outlined">{{
                          member.status === 'active' ? 'block' : 'check_circle'
                        }}</span>
                      </button>
                      <button
                        class="action-btn delete"
                        title="Eliminar"
                        (click)="deleteMember(member)"
                      >
                        <span class="material-symbols-outlined">delete</span>
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
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
