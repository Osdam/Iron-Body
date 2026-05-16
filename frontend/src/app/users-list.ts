import { Component, ElementRef, HostListener, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ApiService, UserSummary } from './services/api.service';
import { CreateMemberModalComponent } from './modules/components/create-member-modal';
import { MembersEmptyComponent } from './modules/components/members-empty';
import { EditMemberModalComponent } from './modules/components/edit-member-modal';
import { MemberDetailsModalComponent } from './modules/components/member-details-modal';
import { LottieIconComponent } from './shared/components/lottie-icon/lottie-icon.component';
import { AuthService } from './services/auth.service';
import { Permission } from './models/permissions.enum';

type UserFilterSelect = 'status';

interface UserFilterOption {
  value: string;
  label: string;
  description: string;
  icon: string;
}

@Component({
  selector: 'users-list',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    CreateMemberModalComponent,
    MembersEmptyComponent,
    EditMemberModalComponent,
    MemberDetailsModalComponent,
    LottieIconComponent,
  ],
  template: `
    <section class="users-page">
      <!-- Toast -->
      <div *ngIf="notification()" class="toast" [class]="'toast-' + notification()!.type">
        <span class="material-symbols-outlined">
          {{ notification()!.type === 'success' ? 'check_circle' : 'error' }}
        </span>
        <span>{{ notification()!.message }}</span>
      </div>

      <!-- Header premium -->
      <header class="users-header">
        <div class="header-content">
          <h1>Miembros</h1>
          <p>Administra y registra los miembros del gimnasio, sus membresías y datos personales.</p>
        </div>
        <div class="header-actions">
          <button type="button" class="btn-secondary" (click)="toggleView()">
            <span class="btn-lottie btn-lottie-light">
              <app-lottie-icon
                src="/assets/crm/vistatablavistacard.json"
                [size]="22"
                [loop]="true"
              ></app-lottie-icon>
            </span>
            {{ viewMode() === 'cards' ? 'Vista tabla' : 'Vista cards' }}
          </button>
          <button *ngIf="canCreateMembers()" type="button" class="btn-primary" (click)="openCreateMember()">
            <span class="btn-lottie">
              <app-lottie-icon src="/assets/crm/nuevomiembro.json" [size]="22" [loop]="true"></app-lottie-icon>
            </span>
            Nuevo miembro
          </button>
        </div>
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
          <app-members-empty *ngIf="canCreateMembers()" (onCreate)="openCreateMember()"></app-members-empty>
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
              <span class="search-icon material-symbols-outlined" aria-hidden="true">search</span>
            </div>
            <div class="filter-group">
              <div class="pretty-select" [class.open]="openSelect() === 'status'">
                <button type="button" class="pretty-trigger" (click)="toggleSelect('status')" aria-label="Filtrar por estado">
                  <span>{{ statusFilterLabel() }}</span>
                  <span class="select-chevron" aria-hidden="true"></span>
                </button>
                <div class="pretty-menu" *ngIf="openSelect() === 'status'">
                  <button
                    type="button"
                    class="pretty-option"
                    *ngFor="let option of statusFilterOptions"
                    [class.selected]="filterStatus() === option.value"
                    (click)="chooseStatusFilter(option.value)"
                  >
                    <span class="option-main">
                      <span class="option-icon material-symbols-outlined">{{ option.icon }}</span>
                      <span class="option-copy">
                        <strong>{{ option.label }}</strong>
                        <small>{{ option.description }}</small>
                      </span>
                    </span>
                    <span class="option-check" aria-hidden="true"></span>
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div class="members-cards" *ngIf="viewMode() === 'cards'">
            <article
              *ngFor="let member of filteredMembers(); trackBy: trackById"
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
                  <div class="member-profile-text">
                    <strong>{{ member.name }}</strong>
                    <span>{{ member.email || 'Sin correo' }}</span>
                    <button type="button" class="link-btn" (click)="viewMember(member)">
                      Ver detalles
                    </button>
                  </div>
                </div>

                <div class="membership-timeline">
                  <div class="timeline-point">
                    <strong>{{ membershipStartLabel(member) }}</strong>
                    <span>{{ member.membershipStartDate ? 'Inicio' : 'Registro' }}</span>
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
                    <button *ngIf="canEditMembers()" type="button" class="book-btn" (click)="editMember(member)">
                      Editar
                      <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                    </button>
                    <button
                      type="button"
                      class="round-btn"
                      *ngIf="canEditMembers()"
                      [title]="member.status === 'active' ? 'Desactivar miembro' : 'Activar miembro'"
                      [disabled]="busyMemberId() === member.id"
                      (click)="toggleStatus(member)"
                    >
                      <app-lottie-icon
                        [src]="member.status === 'active' ? '/assets/crm/cancelar.json' : '/assets/crm/activar.json'"
                        [size]="22"
                        [loop]="true"
                      ></app-lottie-icon>
                    </button>
                    <button
                      type="button"
                      class="round-btn danger"
                      *ngIf="canDeleteMembers()"
                      title="Eliminar miembro"
                      [disabled]="busyMemberId() === member.id"
                      (click)="requestDelete(member)"
                    >
                      <app-lottie-icon
                        src="/assets/crm/delete.json"
                        [size]="22"
                        [loop]="true"
                      ></app-lottie-icon>
                    </button>
                  </div>
                </div>
              </div>
            </article>
          </div>

          <!-- Vista tabla -->
          <div class="members-table-wrapper" *ngIf="viewMode() === 'table'">
            <table class="members-table">
              <thead>
                <tr>
                  <th>Miembro</th>
                  <th>Documento</th>
                  <th>Teléfono</th>
                  <th>Plan</th>
                  <th>Vence</th>
                  <th>Estado</th>
                  <th class="col-actions">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <tr *ngFor="let member of filteredMembers(); trackBy: trackById">
                  <td>
                    <div class="cell-member">
                      <div class="avatar small">{{ getInitials(member.name) }}</div>
                      <div>
                        <strong>{{ member.name }}</strong>
                        <span>{{ member.email || 'Sin correo' }}</span>
                      </div>
                    </div>
                  </td>
                  <td>{{ member.document || '—' }}</td>
                  <td>{{ member.phone || '—' }}</td>
                  <td>{{ member.plan || 'Sin plan' }}</td>
                  <td>
                    {{ member.membershipEndDate ? (member.membershipEndDate | date: 'dd MMM yyyy') : '—' }}
                  </td>
                  <td>
                    <span class="badge" [class]="'status-' + (member.status || 'active')">
                      {{ getStatusLabel(member.status) }}
                    </span>
                  </td>
                  <td class="col-actions">
                    <div class="row-actions">
                      <button
                        *ngIf="canEditMembers()"
                        type="button"
                        class="row-btn"
                        title="Ver detalles"
                        (click)="viewMember(member)"
                      >
                        <span class="material-symbols-outlined">visibility</span>
                      </button>
                      <button
                        *ngIf="canEditMembers()"
                        type="button"
                        class="row-btn"
                        title="Editar"
                        (click)="editMember(member)"
                      >
                        <span class="material-symbols-outlined">edit</span>
                      </button>
                      <button
                        *ngIf="canDeleteMembers()"
                        type="button"
                        class="row-btn"
                        [title]="member.status === 'active' ? 'Desactivar' : 'Activar'"
                        [disabled]="busyMemberId() === member.id"
                        (click)="toggleStatus(member)"
                      >
                        <app-lottie-icon
                          [src]="member.status === 'active' ? '/assets/crm/cancelar.json' : '/assets/crm/activar.json'"
                          [size]="20"
                          [loop]="true"
                        ></app-lottie-icon>
                      </button>
                      <button
                        type="button"
                        class="row-btn danger"
                        title="Eliminar"
                        [disabled]="busyMemberId() === member.id"
                        (click)="requestDelete(member)"
                      >
                        <app-lottie-icon
                          src="/assets/crm/delete.json"
                          [size]="20"
                          [loop]="true"
                        ></app-lottie-icon>
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

    <!-- Modal: crear -->
    <app-create-member-modal
      [isOpen]="isCreateMemberOpen"
      (onClose)="onCreateMemberModalClose()"
      (onMemberCreated)="onMemberCreated($event)"
    ></app-create-member-modal>

    <!-- Modal: detalles -->
    <app-member-details-modal
      [isOpen]="isDetailsOpen()"
      [member]="selectedMember()"
      (onClose)="closeDetails()"
      (onEdit)="editFromDetails($event)"
    ></app-member-details-modal>

    <!-- Modal: editar -->
    <app-edit-member-modal
      [isOpen]="isEditOpen()"
      [member]="memberToEdit()"
      (onClose)="closeEdit()"
      (onUpdated)="onMemberUpdated($event)"
    ></app-edit-member-modal>

    <!-- Confirm: eliminar -->
    <div *ngIf="memberToDelete()" class="confirm-backdrop" (click)="cancelDelete()" aria-hidden="true"></div>
    <div *ngIf="memberToDelete() as m" class="confirm-container">
      <div class="confirm-card">
        <div class="confirm-icon">
          <app-lottie-icon src="/assets/crm/delete.json" [size]="44" [loop]="true"></app-lottie-icon>
        </div>
        <h3>Eliminar miembro</h3>
        <p>
          ¿Seguro que deseas eliminar a <strong>{{ m.name }}</strong>? Esta acción no se puede deshacer.
        </p>
        <div class="confirm-actions">
          <button type="button" class="btn-secondary" (click)="cancelDelete()" [disabled]="deleting()">
            Cancelar
          </button>
          <button type="button" class="btn-danger" (click)="confirmDelete()" [disabled]="deleting()">
            <span *ngIf="!deleting()">Eliminar</span>
            <span *ngIf="deleting()">Eliminando…</span>
          </button>
        </div>
      </div>
    </div>
  `,
  styles: [
    `
      .users-page {
        max-width: 1400px;
        margin: 0 auto;
        padding: 1.25rem 1.25rem 2rem;
        color: #0a0a0a;
        background:
          linear-gradient(rgba(250, 250, 250, 0.74), rgba(250, 250, 250, 0.74)),
          url('/assets/crm/fondomiembro.png') center / cover no-repeat;
        border-radius: 16px;
        position: relative;
      }

      /* Toast */
      .toast {
        position: fixed;
        top: 1.25rem;
        right: 1.25rem;
        z-index: 1100;
        display: flex;
        align-items: center;
        gap: 0.65rem;
        padding: 0.85rem 1.2rem;
        border-radius: 12px;
        font: 600 0.9rem Inter, sans-serif;
        box-shadow: 0 10px 28px rgba(0, 0, 0, 0.18);
        animation: toastIn 220ms ease;
      }

      .toast-success {
        background: #16a34a;
        color: #fff;
      }

      .toast-error {
        background: #dc2626;
        color: #fff;
      }

      @keyframes toastIn {
        from {
          opacity: 0;
          transform: translateY(-10px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
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
        border-bottom: 2px solid rgba(0, 0, 0, 0.07);
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
        color: #555;
        margin: 0;
        max-width: 600px;
      }

      .btn-primary {
        display: inline-flex;
        align-items: center;
        gap: 0.6rem;
        padding: 0.85rem 1.6rem;
        background: #facc15;
        color: #000;
        border: none;
        border-radius: 10px;
        font-family: Inter, sans-serif;
        font-weight: 600;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 200ms cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 4px 12px rgba(250, 204, 21, 0.25);
      }

      .btn-primary:hover {
        background: #f0c00e;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(250, 204, 21, 0.35);
      }

      .btn-primary:active {
        transform: translateY(0);
      }

      .header-actions {
        display: flex;
        gap: 0.65rem;
        flex-wrap: wrap;
        align-items: center;
      }

      .btn-secondary {
        display: inline-flex;
        align-items: center;
        gap: 0.6rem;
        padding: 0.85rem 1.4rem;
        background: rgba(255, 255, 255, 0.95);
        color: #0a0a0a;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        font-family: Inter, sans-serif;
        font-weight: 600;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 200ms ease;
      }

      .btn-secondary:hover {
        background: #fff;
        border-color: #facc15;
        transform: translateY(-1px);
      }

      .btn-lottie-light {
        background: #f5c518 !important;
        box-shadow: 0 0 12px rgba(245, 197, 24, 0.16);
      }

      /* ── Vista tabla ── */
      .members-table-wrapper {
        background: rgba(255, 255, 255, 0.95);
        border: 1px solid #ededed;
        border-radius: 14px;
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.05);
        overflow-x: auto;
      }

      .members-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.92rem;
      }

      .members-table thead {
        background: #fafafa;
        border-bottom: 1px solid #ededed;
      }

      .members-table th {
        padding: 0.95rem 1rem;
        text-align: left;
        font-weight: 700;
        color: #555;
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
      }

      .members-table tbody tr {
        border-bottom: 1px solid #f3f3f3;
        transition: background 150ms ease;
      }

      .members-table tbody tr:hover {
        background: #fffbeb;
      }

      .members-table td {
        padding: 0.85rem 1rem;
        vertical-align: middle;
        color: #0a0a0a;
      }

      .cell-member {
        display: flex;
        align-items: center;
        gap: 0.65rem;
      }

      .cell-member strong {
        display: block;
        font-weight: 700;
      }

      .cell-member span {
        display: block;
        color: #666;
        font-size: 0.82rem;
        margin-top: 0.15rem;
      }

      .avatar.small {
        width: 36px;
        height: 36px;
        border-radius: 9px;
        display: grid;
        place-items: center;
        font-size: 0.78rem;
        font-weight: 800;
        background: linear-gradient(135deg, #facc15, #f0c00e);
        color: #000;
        flex-shrink: 0;
      }

      .col-actions {
        text-align: right;
      }

      .row-actions {
        display: inline-flex;
        gap: 0.35rem;
        justify-content: flex-end;
      }

      .row-btn {
        width: 34px;
        height: 34px;
        display: grid;
        place-items: center;
        border: 1px solid #e5e5e5;
        border-radius: 8px;
        background: #fff;
        color: #555;
        cursor: pointer;
        transition: all 150ms ease;
        overflow: hidden;
      }

      .row-btn:hover:not(:disabled) {
        border-color: #facc15;
        background: #fffbeb;
      }

      .row-btn.danger:hover:not(:disabled) {
        border-color: #fecaca;
        background: #fee2e2;
      }

      .row-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
      }

      .row-btn .material-symbols-outlined {
        font-size: 1.1rem;
      }

      @media (max-width: 720px) {
        .members-table th:nth-child(3),
        .members-table td:nth-child(3),
        .members-table th:nth-child(5),
        .members-table td:nth-child(5) {
          display: none;
        }
      }

      .btn-lottie {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 8px;
        background: rgba(245, 197, 24, 0.18);
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
        position: relative;
        z-index: 30;
        overflow: visible;
      }

      .filter-group {
        position: relative;
        flex: 1;
        min-width: 200px;
      }

      .search-input {
        width: 100%;
        padding: 0.875rem 1rem 0.875rem 2.75rem;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        font-family: Inter, sans-serif;
        font-size: 0.95rem;
        color: #0a0a0a;
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(2px);
        transition: all 200ms ease;
      }

      .search-input::placeholder {
        color: #999;
      }

      .search-input:focus {
        outline: none;
        border-color: #facc15;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.1);
      }

      .search-icon {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
        pointer-events: none;
      }

      .pretty-select {
        position: relative;
        width: 100%;
        min-width: 0;
      }

      .pretty-select.open {
        z-index: 80;
      }

      .pretty-trigger {
        width: 100%;
        min-height: 46px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        border: 1px solid #353534;
        border-radius: 10px;
        background: #1a1a1a;
        color: #e5e2e1;
        padding: 0 0.9rem;
        font-weight: 850;
        text-align: left;
        cursor: pointer;
        transition:
          border-color 0.15s ease,
          box-shadow 0.15s ease,
          background 0.15s ease;
      }

      .pretty-trigger > span:first-child {
        position: static;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        transform: none;
        color: inherit;
        pointer-events: auto;
      }

      .pretty-trigger:hover,
      .pretty-select.open .pretty-trigger {
        border-color: #f5c518;
        background: #2a2a2a;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.14);
      }

      .select-chevron {
        position: static;
        width: 0.52rem;
        height: 0.52rem;
        border-bottom: 2px solid #ffe08b;
        border-right: 2px solid #ffe08b;
        transform: rotate(45deg) translateY(-1px);
        transition: transform 160ms ease;
        flex-shrink: 0;
        pointer-events: auto;
      }

      .pretty-select.open .select-chevron {
        transform: rotate(225deg) translateY(-1px);
      }

      .pretty-menu {
        position: absolute;
        top: calc(100% + 0.35rem);
        left: 0;
        width: max(100%, 280px);
        min-width: 250px;
        z-index: 5000;
        display: grid;
        gap: 0.2rem;
        max-height: 280px;
        overflow-y: auto;
        padding: 0.45rem;
        border: 1px solid #4e4633;
        border-radius: 12px;
        background: #201f1f;
        box-shadow: 0 18px 42px rgba(0, 0, 0, 0.42);
        animation: selectIn 140ms ease;
      }

      @keyframes selectIn {
        from {
          opacity: 0;
          transform: translateY(-4px) scale(0.98);
        }
        to {
          opacity: 1;
          transform: translateY(0) scale(1);
        }
      }

      .pretty-option {
        min-height: 3.35rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.85rem;
        border: 0;
        border-radius: 9px;
        background: transparent;
        color: #d1c5ac;
        text-align: left;
        padding: 0.62rem 0.7rem;
        cursor: pointer;
        transition:
          background 140ms ease,
          color 140ms ease,
          transform 140ms ease;
      }

      .pretty-option:hover {
        background: rgba(245, 197, 24, 0.1);
        color: #ffe08b;
        transform: translateY(-1px);
      }

      .pretty-option.selected {
        background: rgba(245, 197, 24, 0.16);
        color: #ffe08b;
      }

      .option-main {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        min-width: 0;
      }

      .option-icon {
        position: static;
        width: 2rem;
        height: 2rem;
        display: grid;
        place-items: center;
        border-radius: 8px;
        background: #2a2a2a;
        color: #f5c518;
        flex-shrink: 0;
        font-size: 1.12rem;
        transform: none;
        pointer-events: auto;
      }

      .pretty-option.selected .option-icon {
        background: #f5c518;
        color: #241a00;
      }

      .option-copy {
        display: grid;
        gap: 0.12rem;
        min-width: 0;
      }

      .option-copy strong {
        color: inherit;
        font-weight: 900;
        font-size: 0.9rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .option-copy small {
        color: #b4afa6;
        font-weight: 650;
        font-size: 0.75rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .option-check {
        position: relative;
        width: 1.15rem;
        height: 1.15rem;
        display: block;
        border: 2px solid transparent;
        border-radius: 999px;
        flex-shrink: 0;
        transform: none;
        pointer-events: auto;
      }

      .pretty-option.selected .option-check {
        border-color: #f5c518;
        background: #f5c518;
      }

      .pretty-option.selected .option-check::after {
        content: '';
        position: absolute;
        left: 0.31rem;
        top: 0.16rem;
        width: 0.3rem;
        height: 0.58rem;
        border: solid #241a00;
        border-width: 0 2px 2px 0;
        transform: rotate(45deg);
      }

      .members-cards {
        display: grid;
        gap: 1rem;
      }

      .member-flight-card {
        width: 100%;
        border: 1px solid rgba(245, 197, 24, 0.12);
        border-radius: 14px;
        background:
          linear-gradient(rgba(28, 27, 27, 0.92), rgba(32, 31, 31, 0.84)),
          url('/assets/crm/cardmiembro.png') center / cover no-repeat;
        box-shadow: 0 18px 42px rgba(0, 0, 0, 0.34);
        padding: 1.2rem;
        transition:
          transform 0.18s ease,
          box-shadow 0.18s ease,
          border-color 0.18s ease;
      }

      .member-flight-card:hover {
        transform: translateY(-2px);
        border-color: #facc15;
        box-shadow: 0 16px 34px rgba(250, 204, 21, 0.14);
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
        background: rgba(245, 197, 24, 0.14);
        border: 1px solid rgba(245, 197, 24, 0.28);
        color: #ffe08b;
        font-size: 0.78rem;
        font-weight: 800;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
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

      .member-profile-text {
        min-width: 0;
      }

      .avatar.large {
        width: 48px;
        height: 48px;
        display: grid;
        place-items: center;
        border-radius: 12px;
        font-size: 0.95rem;
        font-weight: 900;
        line-height: 1;
        background: linear-gradient(135deg, #f5c518, #ffe08b);
        color: #241a00;
        box-shadow: 0 0 14px rgba(245, 197, 24, 0.18);
        flex-shrink: 0;
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
        color: #555;
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
        color: #888;
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
        color: #555;
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
        background: rgba(0, 0, 0, 0.12);
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
        color: #555;
        font-size: 0.86rem;
        margin-top: 0.25rem;
      }

      .member-offer {
        color: #16a34a;
        font-size: 0.82rem;
        font-weight: 750;
        margin: 0;
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
        transition: background 180ms ease;
      }

      .book-btn:hover {
        background: #f9a825;
      }

      .book-btn .material-symbols-outlined {
        font-size: 1rem;
      }

      .round-btn {
        width: 40px;
        height: 38px;
        display: grid;
        place-items: center;
        border: 1px solid rgba(229, 229, 229, 0.8);
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.95);
        color: #555;
        cursor: pointer;
        transition: all 180ms ease;
      }

      .round-btn:hover:not(:disabled) {
        border-color: #fbbf24;
        background: #fffbeb;
      }

      .round-btn.danger:hover:not(:disabled) {
        border-color: #fecaca;
        background: #fee2e2;
      }

      .round-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
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

      /* Confirm modal */
      .confirm-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.55);
        backdrop-filter: blur(4px);
        z-index: 999;
      }

      .confirm-container {
        position: fixed;
        inset: 0;
        z-index: 1000;
        display: grid;
        place-items: center;
        padding: 1.5rem;
      }

      .confirm-card {
        width: 100%;
        max-width: 420px;
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.25);
        padding: 1.75rem;
        text-align: center;
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

      .confirm-icon {
        width: 64px;
        height: 64px;
        margin: 0 auto 1rem;
        border-radius: 16px;
        background: #fee2e2;
        display: grid;
        place-items: center;
      }

      .confirm-card h3 {
        font: 700 1.2rem Inter, sans-serif;
        margin: 0 0 0.5rem;
        color: #0a0a0a;
      }

      .confirm-card p {
        font: 400 0.92rem Inter, sans-serif;
        color: #666;
        margin: 0 0 1.5rem;
        line-height: 1.5;
      }

      .confirm-actions {
        display: flex;
        gap: 0.65rem;
        justify-content: center;
      }

      .btn-secondary,
      .btn-danger {
        padding: 0.7rem 1.4rem;
        border-radius: 9px;
        font: 600 0.92rem Inter, sans-serif;
        cursor: pointer;
        border: none;
        transition: all 180ms ease;
      }

      .btn-secondary {
        background: #f5f5f5;
        color: #333;
      }

      .btn-secondary:hover:not(:disabled) {
        background: #e5e5e5;
      }

      .btn-danger {
        background: #dc2626;
        color: #fff;
        font-weight: 700;
      }

      .btn-danger:hover:not(:disabled) {
        background: #b91c1c;
      }

      .btn-danger:disabled,
      .btn-secondary:disabled {
        opacity: 0.6;
        cursor: not-allowed;
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
        .users-page {
          padding: 1rem 0.85rem 1.5rem;
        }

        .header-content h1 {
          font-size: 1.5rem;
        }

        .users-header {
          margin-bottom: 1.75rem;
          padding-bottom: 1.25rem;
        }

        .member-flight-card {
          padding: 1rem;
        }

        .member-profile {
          align-items: flex-start;
        }

        .book-btn {
          width: 100%;
          justify-content: center;
        }

        .round-btn {
          width: 100%;
          height: 40px;
        }

        .member-actions {
          display: grid;
          grid-template-columns: 1fr 1fr;
          gap: 0.5rem;
          width: 100%;
        }

        .book-btn {
          grid-column: 1 / -1;
        }
      }
    `,
  ],
})
export class UsersList implements OnInit {
  private api = inject(ApiService);
  private elementRef = inject(ElementRef<HTMLElement>);
  private auth = inject(AuthService);

  members = signal<UserSummary[]>([]);
  loading = signal(true);
  error = signal('');
  isCreateMemberOpen = signal(false);
  searchQuery = signal('');
  filterStatus = signal('');
  openSelect = signal<UserFilterSelect | null>(null);
  viewMode = signal<'cards' | 'table'>('cards');
  readonly statusFilterOptions: UserFilterOption[] = [
    { value: '', label: 'Todos los estados', description: 'Mostrar todos los miembros', icon: 'select_all' },
    { value: 'active', label: 'Activos', description: 'Miembros con acceso vigente', icon: 'check_circle' },
    { value: 'inactive', label: 'Inactivos', description: 'Miembros desactivados', icon: 'pause_circle' },
    { value: 'pending', label: 'Pendientes', description: 'Pagos o activación pendientes', icon: 'schedule' },
    { value: 'expired', label: 'Vencidos', description: 'Membresía fuera de vigencia', icon: 'event_busy' },
  ];

  toggleView(): void {
    this.viewMode.set(this.viewMode() === 'cards' ? 'table' : 'cards');
  }

  // Detail / edit modal state
  selectedMember = signal<UserSummary | null>(null);
  isDetailsOpen = signal(false);
  memberToEdit = signal<UserSummary | null>(null);
  isEditOpen = signal(false);

  // Delete confirm state
  memberToDelete = signal<UserSummary | null>(null);
  deleting = signal(false);

  // Per-row busy id (for toggleStatus / delete in-flight)
  busyMemberId = signal<number | null>(null);

  // Toast
  notification = signal<{ type: 'success' | 'error'; message: string } | null>(null);
  private toastTimer?: ReturnType<typeof setTimeout>;

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
    this.loadMembers();
  }

  @HostListener('document:click', ['$event'])
  closeSelectOnOutsideClick(event: MouseEvent): void {
    if (!this.openSelect()) return;
    if (!this.elementRef.nativeElement.contains(event.target as Node)) {
      this.openSelect.set(null);
    }
  }

  loadMembers(): void {
    this.loading.set(true);
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

  trackById(_index: number, member: UserSummary): number {
    return member.id;
  }

  toggleSelect(select: UserFilterSelect): void {
    this.openSelect.update((current) => (current === select ? null : select));
  }

  chooseStatusFilter(value: string): void {
    this.filterStatus.set(value);
    this.openSelect.set(null);
  }

  statusFilterLabel(): string {
    return (
      this.statusFilterOptions.find((option) => option.value === this.filterStatus())?.label ||
      'Todos los estados'
    );
  }

  openCreateMember(): void {
    if (!this.requirePermission(Permission.MEMBERS_CREATE, 'No tienes permiso para crear miembros.')) return;
    this.isCreateMemberOpen.set(true);
  }

  onCreateMemberModalClose(): void {
    this.isCreateMemberOpen.set(false);
  }

  onMemberCreated(newMember: UserSummary): void {
    this.members.update((members) => [newMember, ...members]);
    this.isCreateMemberOpen.set(false);
    this.showToast('success', 'Miembro registrado correctamente.');
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

  membershipStartLabel(member: UserSummary): string {
    const dateString = member.membershipStartDate || member.created_at;
    if (!dateString) return '—';
    const d = new Date(dateString);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short' });
  }

  membershipDuration(member: UserSummary): string {
    if (!member.membershipEndDate) return 'Sin vigencia';

    const startSrc = member.membershipStartDate || member.created_at;
    const start = startSrc ? new Date(startSrc) : new Date();
    const end = new Date(member.membershipEndDate);
    if (Number.isNaN(end.getTime()) || Number.isNaN(start.getTime())) return 'Sin vigencia';

    const days = Math.max(0, Math.ceil((end.getTime() - start.getTime()) / 86400000));
    if (days >= 365) return 'Plan anual';
    if (days >= 180) return 'Plan semestral';
    if (days >= 90) return 'Plan trimestral';
    if (days >= 28) return 'Plan mensual';
    return `${days} días`;
  }

  membershipStateText(member: UserSummary): string {
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

  membershipHint(member: UserSummary): string {
    const state = this.membershipStateText(member);
    if (state === 'Membresía activa') return 'Acceso habilitado';
    if (state === 'Por vencer') return 'Recomendar renovación';
    if (state === 'Pendiente de activar') return 'Validar pago o registro';
    if (state === 'Miembro inactivo') return 'Campaña de reactivación';
    return 'Revisar membresía';
  }

  // ─── Acciones ──────────────────────────────────────────────
  viewMember(member: UserSummary): void {
    this.selectedMember.set(member);
    this.isDetailsOpen.set(true);
  }

  closeDetails(): void {
    this.isDetailsOpen.set(false);
    this.selectedMember.set(null);
  }

  editMember(member: UserSummary): void {
    if (!this.requirePermission(Permission.MEMBERS_EDIT, 'No tienes permiso para editar miembros.')) return;
    this.memberToEdit.set(member);
    this.isEditOpen.set(true);
  }

  editFromDetails(member: UserSummary): void {
    this.closeDetails();
    this.editMember(member);
  }

  closeEdit(): void {
    this.isEditOpen.set(false);
    this.memberToEdit.set(null);
  }

  onMemberUpdated(updated: UserSummary): void {
    if (!this.requirePermission(Permission.MEMBERS_EDIT, 'No tienes permiso para guardar cambios de miembros.')) return;
    this.members.update((list) =>
      list.map((m) => (m.id === updated.id ? { ...m, ...updated } : m)),
    );
    this.closeEdit();
    this.showToast('success', 'Miembro actualizado correctamente.');
  }

  toggleStatus(member: UserSummary): void {
    if (!this.requirePermission(Permission.MEMBERS_EDIT, 'No tienes permiso para cambiar el estado de miembros.')) return;
    const newStatus = member.status === 'active' ? 'inactive' : 'active';
    this.busyMemberId.set(member.id);

    this.api.updateUser(member.id, { status: newStatus }).subscribe({
      next: (updated) => {
        this.members.update((list) =>
          list.map((m) => (m.id === member.id ? { ...m, ...updated } : m)),
        );
        this.busyMemberId.set(null);
        this.showToast(
          'success',
          newStatus === 'active' ? 'Miembro activado.' : 'Miembro desactivado.',
        );
      },
      error: () => {
        this.busyMemberId.set(null);
        this.showToast('error', 'No se pudo cambiar el estado. Intenta de nuevo.');
      },
    });
  }

  requestDelete(member: UserSummary): void {
    if (!this.requirePermission(Permission.MEMBERS_DELETE, 'No tienes permiso para eliminar miembros.')) return;
    this.memberToDelete.set(member);
  }

  cancelDelete(): void {
    if (this.deleting()) return;
    this.memberToDelete.set(null);
  }

  confirmDelete(): void {
    if (!this.requirePermission(Permission.MEMBERS_DELETE, 'No tienes permiso para eliminar miembros.')) return;
    const member = this.memberToDelete();
    if (!member) return;

    this.deleting.set(true);
    this.api.deleteUser(member.id).subscribe({
      next: () => {
        this.members.update((list) => list.filter((m) => m.id !== member.id));
        this.deleting.set(false);
        this.memberToDelete.set(null);
        this.showToast('success', `${member.name} fue eliminado.`);
      },
      error: () => {
        this.deleting.set(false);
        this.showToast('error', 'No se pudo eliminar el miembro. Intenta de nuevo.');
      },
    });
  }

  private showToast(type: 'success' | 'error', message: string): void {
    this.notification.set({ type, message });
    if (this.toastTimer) clearTimeout(this.toastTimer);
    this.toastTimer = setTimeout(() => this.notification.set(null), 4500);
  }

  canCreateMembers(): boolean {
    return this.auth.hasPermission(Permission.MEMBERS_CREATE);
  }

  canEditMembers(): boolean {
    return this.auth.hasPermission(Permission.MEMBERS_EDIT);
  }

  canDeleteMembers(): boolean {
    return this.auth.hasPermission(Permission.MEMBERS_DELETE);
  }

  private requirePermission(permission: Permission, message: string): boolean {
    if (this.auth.hasPermission(permission)) return true;
    this.showToast('error', message);
    return false;
  }
}
