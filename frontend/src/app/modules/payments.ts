import { CommonModule, DatePipe } from '@angular/common';
import { Component, OnInit, inject, signal, computed } from '@angular/core';
import {
  FormsModule,
  ReactiveFormsModule,
  FormBuilder,
  Validators,
} from '@angular/forms';
import { ApiService, PaymentSummary, PlanSummary, UserSummary } from '../services/api.service';
import { firstValueFrom } from 'rxjs';
import { LottieIconComponent } from '../shared/components/lottie-icon/lottie-icon.component';

@Component({
  selector: 'module-payments',
  standalone: true,
  imports: [CommonModule, DatePipe, FormsModule, ReactiveFormsModule, LottieIconComponent],
  template: `
    <section class="payments-page">
      <!-- Toast -->
      <div
        *ngIf="notification()"
        class="toast"
        [class.toast-success]="notification()?.type === 'success'"
        [class.toast-error]="notification()?.type === 'error'"
        role="alert"
      >
        <span class="material-symbols-outlined">
          {{ notification()?.type === 'success' ? 'check_circle' : 'error' }}
        </span>
        <span>{{ notification()?.message }}</span>
        <button class="toast-close" (click)="clearNotification()">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>

      <!-- Header -->
      <header class="payments-header">
        <div class="header-left">
          <h1>Pagos</h1>
          <p>Seguimiento de transacciones, referencias y estado de cobros de membresías.</p>
        </div>
        <button type="button" class="btn-primary" (click)="openModal()">
          <span class="btn-lottie">
            <app-lottie-icon src="/assets/crm/mas.json" [size]="22" [loop]="true"></app-lottie-icon>
          </span>
          Registrar pago
        </button>
      </header>

      <!-- KPI Cards -->
      <section class="kpis-grid" *ngIf="!loading() || payments().length > 0">
        <div class="kpi-card">
          <div class="kpi-icon kpi-icon-total">
            <app-lottie-icon src="/assets/crm/ingresos.json" [size]="32" [loop]="true"></app-lottie-icon>
          </div>
          <div class="kpi-content">
            <div class="kpi-value">{{ formatCurrencyShort(kpiTotalAmount()) }}</div>
            <div class="kpi-label">Total recaudado</div>
            <div class="kpi-sub">{{ payments().length }} pago(s)</div>
          </div>
        </div>
        <div class="kpi-card kpi-card-paid">
          <div class="kpi-icon kpi-icon-paid">
            <app-lottie-icon src="/assets/crm/checkgreen.json" [size]="32" [loop]="true"></app-lottie-icon>
          </div>
          <div class="kpi-content">
            <div class="kpi-value">{{ formatCurrencyShort(kpiPaidAmount()) }}</div>
            <div class="kpi-label">Pagos confirmados</div>
            <div class="kpi-sub">{{ kpiPaidCount() }} confirmado(s)</div>
          </div>
        </div>
        <div class="kpi-card kpi-card-pending">
          <div class="kpi-icon kpi-icon-pending">
            <app-lottie-icon src="/assets/crm/pagospendientes.json" [size]="32" [loop]="true"></app-lottie-icon>
          </div>
          <div class="kpi-content">
            <div class="kpi-value">{{ kpiPendingCount() }}</div>
            <div class="kpi-label">Pagos pendientes</div>
            <div class="kpi-sub">{{ formatCurrencyShort(kpiPendingAmount()) }} por cobrar</div>
          </div>
        </div>
        <div class="kpi-card kpi-card-failed">
          <div class="kpi-icon kpi-icon-failed">
            <app-lottie-icon src="/assets/crm/cancelar.json" [size]="32" [loop]="true"></app-lottie-icon>
          </div>
          <div class="kpi-content">
            <div class="kpi-value">{{ kpiFailedCount() }}</div>
            <div class="kpi-label">Fallidos / Cancelados</div>
            <div class="kpi-sub">requieren atención</div>
          </div>
        </div>
      </section>

      <!-- Estado de carga -->
      <div *ngIf="loading()" class="loading-state">
        <div class="spinner"></div>
        <p>Cargando pagos...</p>
      </div>

      <!-- Error -->
      <div *ngIf="error()" class="error-alert">
        <span class="material-symbols-outlined">error</span>
        <div>
          <strong>Error al cargar pagos</strong>
          <p>{{ error() }}</p>
        </div>
        <button class="btn-retry" (click)="loadPayments()">Reintentar</button>
      </div>

      <ng-container *ngIf="!loading() && !error()">
        <!-- Filtros -->
        <section class="filters-section">
          <div class="filter-group search-group">
            <span class="material-symbols-outlined filter-icon">search</span>
            <input
              type="text"
              class="search-input"
              placeholder="Buscar por cliente o referencia..."
              [ngModel]="searchQuery()"
              (ngModelChange)="onSearchChange($event)"
              aria-label="Buscar pagos"
            />
            <button
              *ngIf="searchQuery().length > 0"
              class="search-clear"
              (click)="clearSearch()"
              aria-label="Limpiar búsqueda"
            >
              <span class="material-symbols-outlined">close</span>
            </button>
          </div>
          <div class="filter-group">
            <select
              [ngModel]="filterStatus()"
              (ngModelChange)="onStatusChange($event)"
              class="filter-select"
              aria-label="Filtrar por estado"
            >
              <option value="">Todos los estados</option>
              <option value="paid">Pagados</option>
              <option value="pending">Pendientes</option>
              <option value="failed">Fallidos</option>
              <option value="cancelled">Cancelados</option>
              <option value="refunded">Reembolsados</option>
            </select>
          </div>
          <div class="filter-stats" *ngIf="payments().length > 0">
            <span>{{ filteredPayments().length }} resultado(s)</span>
          </div>
        </section>

        <!-- Estado vacío (sin pagos en el sistema) -->
        <div *ngIf="payments().length === 0" class="empty-state">
          <div class="empty-illustration">
            <app-lottie-icon
              src="/assets/crm/pagos.json"
              [size]="140"
              [loop]="true"
            ></app-lottie-icon>
          </div>
          <h2>Sin pagos registrados</h2>
          <p>
            Aún no hay transacciones en el sistema. Registra el primer pago para comenzar a
            llevar un control de los cobros del gimnasio.
          </p>
          <button class="btn-primary" (click)="openModal()">
            <span class="btn-lottie">
              <app-lottie-icon src="/assets/crm/mas.json" [size]="22" [loop]="true"></app-lottie-icon>
            </span>
            Registrar primer pago
          </button>
        </div>

        <!-- Estado vacío (sin resultados de búsqueda) -->
        <div
          *ngIf="payments().length > 0 && filteredPayments().length === 0"
          class="empty-state empty-state-filter"
        >
          <span class="material-symbols-outlined empty-icon">search_off</span>
          <h2>Sin resultados</h2>
          <p>No hay pagos que coincidan con los filtros aplicados.</p>
          <button class="btn-secondary-sm" (click)="clearFilters()">
            <span class="material-symbols-outlined">filter_list_off</span>
            Limpiar filtros
          </button>
        </div>

        <!-- Tabla de pagos -->
        <div *ngIf="filteredPayments().length > 0" class="table-container">
          <div class="table-scroll">
            <table class="payments-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Cliente</th>
                  <th>Plan</th>
                  <th>Monto</th>
                  <th>Método</th>
                  <th>Estado</th>
                  <th>Fecha</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                <tr *ngFor="let payment of paginatedPayments(); let i = index" class="payment-row">
                  <td class="id-cell">
                    <span class="payment-id">#{{ payment.id }}</span>
                  </td>
                  <td class="client-cell">
                    <div class="client-avatar">
                      {{ getInitials(payment.user?.name) }}
                    </div>
                    <div class="client-info">
                      <div class="client-name">{{ payment.user?.name || 'Usuario eliminado' }}</div>
                      <div class="client-ref" *ngIf="payment.reference">
                        Ref: {{ payment.reference }}
                      </div>
                      <div class="client-ref" *ngIf="!payment.reference">Sin referencia</div>
                    </div>
                  </td>
                  <td class="plan-cell">
                    <span class="plan-badge" *ngIf="payment.plan">{{ payment.plan.name }}</span>
                    <span class="no-plan" *ngIf="!payment.plan">Sin plan</span>
                  </td>
                  <td class="amount-cell">
                    <strong>{{ formatCurrency(payment.amount) }}</strong>
                  </td>
                  <td class="method-cell">
                    <span class="method-icon">{{ methodIcon(payment.method) }}</span>
                    {{ methodLabel(payment.method) }}
                  </td>
                  <td class="status-cell">
                    <span class="status-badge" [class]="'status-' + (payment.status || 'pending').toLowerCase()">
                      <i class="status-dot"></i>
                      {{ statusLabel(payment.status) }}
                    </span>
                  </td>
                  <td class="date-cell">
                    <div class="date-main">
                      {{ (payment.paid_at || payment.created_at) | date: 'dd MMM yyyy' }}
                    </div>
                    <div class="date-time">
                      {{ (payment.paid_at || payment.created_at) | date: 'HH:mm' }}
                    </div>
                  </td>
                  <td class="actions-cell">
                    <div class="action-btns">
                      <button
                        *ngIf="payment.status === 'pending'"
                        class="action-btn action-pay"
                        (click)="markAsPaid(payment)"
                        title="Marcar como pagado"
                      >
                        <span class="material-symbols-outlined">check_circle</span>
                      </button>
                      <button
                        class="action-btn action-view"
                        (click)="viewPayment(payment)"
                        title="Ver detalle"
                      >
                        <span class="material-symbols-outlined">visibility</span>
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Paginación -->
          <div class="pagination" *ngIf="totalPages() > 1">
            <button
              class="page-btn"
              (click)="changePage(currentPage() - 1)"
              [disabled]="currentPage() === 1"
              aria-label="Página anterior"
            >
              <span class="material-symbols-outlined">chevron_left</span>
            </button>
            <div class="page-info">
              Página {{ currentPage() }} de {{ totalPages() }}
            </div>
            <button
              class="page-btn"
              (click)="changePage(currentPage() + 1)"
              [disabled]="currentPage() >= totalPages()"
              aria-label="Página siguiente"
            >
              <span class="material-symbols-outlined">chevron_right</span>
            </button>
          </div>
        </div>
      </ng-container>
    </section>

    <!-- Detalle de pago (sidebar) -->
    <div *ngIf="selectedPayment()" class="detail-backdrop" (click)="closeDetail()" aria-hidden="true"></div>
    <aside *ngIf="selectedPayment()" class="detail-panel" role="complementary">
      <div class="detail-header">
        <h3>Detalle de pago</h3>
        <button class="btn-close" (click)="closeDetail()" aria-label="Cerrar">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
      <div class="detail-body">
        <div class="detail-amount">
          {{ formatCurrency(selectedPayment()!.amount) }}
        </div>
        <span class="status-badge detail-status" [class]="'status-' + selectedPayment()!.status">
          <i class="status-dot"></i>
          {{ statusLabel(selectedPayment()!.status) }}
        </span>
        <dl class="detail-list">
          <div class="detail-row">
            <dt><span class="material-symbols-outlined">person</span>Cliente</dt>
            <dd>{{ selectedPayment()!.user?.name || '—' }}</dd>
          </div>
          <div class="detail-row">
            <dt><span class="material-symbols-outlined">loyalty</span>Plan</dt>
            <dd>{{ selectedPayment()!.plan?.name || 'Sin plan' }}</dd>
          </div>
          <div class="detail-row">
            <dt><span class="material-symbols-outlined">credit_card</span>Método</dt>
            <dd>{{ methodLabel(selectedPayment()!.method) }}</dd>
          </div>
          <div class="detail-row">
            <dt><span class="material-symbols-outlined">tag</span>Referencia</dt>
            <dd>{{ selectedPayment()!.reference || '—' }}</dd>
          </div>
          <div class="detail-row">
            <dt><span class="material-symbols-outlined">calendar_today</span>Fecha</dt>
            <dd>{{ (selectedPayment()!.paid_at || selectedPayment()!.created_at) | date: 'dd MMM yyyy, HH:mm' }}</dd>
          </div>
        </dl>
        <div class="detail-actions" *ngIf="selectedPayment()!.status === 'pending'">
          <button class="btn-mark-paid" (click)="markAsPaid(selectedPayment()!)">
            <span class="material-symbols-outlined">check_circle</span>
            Marcar como pagado
          </button>
        </div>
      </div>
    </aside>

    <!-- Modal: Registrar pago -->
    <div *ngIf="isModalOpen()" class="modal-backdrop" (click)="closeModal()" aria-hidden="true"></div>
    <div *ngIf="isModalOpen()" class="modal-container" role="dialog" aria-modal="true">
      <div class="modal-card">
        <div class="modal-header">
          <div class="header-content">
            <div class="header-icon">
              <span class="material-symbols-outlined">payments</span>
            </div>
            <div>
              <h2 class="modal-title">Registrar pago</h2>
              <p class="modal-subtitle">Ingresa los datos de la transacción del miembro.</p>
            </div>
          </div>
          <button class="btn-close" (click)="closeModal()" aria-label="Cerrar">
            <span class="material-symbols-outlined">close</span>
          </button>
        </div>

        <div *ngIf="modalError()" class="modal-error">
          <span class="material-symbols-outlined">error</span>
          <div>
            <strong>Error al registrar</strong>
            <p>{{ modalError() }}</p>
          </div>
        </div>

        <div *ngIf="loadingModalData()" class="modal-loading">
          <div class="spinner-sm"></div>
          <span>Cargando miembros y planes...</span>
        </div>

        <form
          *ngIf="!loadingModalData()"
          [formGroup]="paymentForm"
          (ngSubmit)="onSubmitPayment()"
          class="modal-form"
        >
          <div class="form-grid">
            <!-- Miembro -->
            <div class="form-group full-width">
              <label for="pay-user" class="form-label">Miembro *</label>
              <select id="pay-user" formControlName="user_id" class="form-select">
                <option value="">Seleccionar miembro...</option>
                <option *ngFor="let u of users()" [value]="u.id">
                  {{ u.name }} {{ u.email ? '— ' + u.email : '' }}
                </option>
              </select>
              <span
                *ngIf="paymentForm.get('user_id')?.invalid && paymentForm.get('user_id')?.touched"
                class="error-text"
              >
                Selecciona un miembro
              </span>
              <small class="form-hint" *ngIf="users().length === 0">
                No hay miembros registrados aún.
              </small>
            </div>

            <!-- Plan -->
            <div class="form-group full-width">
              <label for="pay-plan" class="form-label">Plan (opcional)</label>
              <select id="pay-plan" formControlName="plan_id" class="form-select">
                <option value="">Sin plan asociado</option>
                <option *ngFor="let p of plans()" [value]="p.id">
                  {{ p.name }} — {{ formatCurrency(p.price) }}
                </option>
              </select>
            </div>

            <!-- Monto -->
            <div class="form-group">
              <label for="pay-amount" class="form-label">Monto en COP *</label>
              <div class="input-prefix">
                <span class="prefix">$</span>
                <input
                  id="pay-amount"
                  type="number"
                  formControlName="amount"
                  class="form-input"
                  placeholder="Ej: 80000"
                  min="1"
                />
              </div>
              <span
                *ngIf="paymentForm.get('amount')?.invalid && paymentForm.get('amount')?.touched"
                class="error-text"
              >
                El monto es obligatorio y debe ser mayor a 0
              </span>
            </div>

            <!-- Método -->
            <div class="form-group">
              <label for="pay-method" class="form-label">Método de pago *</label>
              <select id="pay-method" formControlName="method" class="form-select">
                <option value="">Seleccionar...</option>
                <option value="cash">Efectivo</option>
                <option value="transfer">Transferencia</option>
                <option value="card">Tarjeta</option>
                <option value="pse">PSE</option>
                <option value="nequi">Nequi / Daviplata</option>
                <option value="other">Otro</option>
              </select>
              <span
                *ngIf="paymentForm.get('method')?.invalid && paymentForm.get('method')?.touched"
                class="error-text"
              >
                Selecciona el método de pago
              </span>
            </div>

            <!-- Referencia -->
            <div class="form-group">
              <label for="pay-ref" class="form-label">Referencia (opcional)</label>
              <input
                id="pay-ref"
                type="text"
                formControlName="reference"
                class="form-input"
                placeholder="Ej: TXN-20260501"
              />
            </div>

            <!-- Estado -->
            <div class="form-group">
              <label for="pay-status" class="form-label">Estado *</label>
              <select id="pay-status" formControlName="status" class="form-select">
                <option value="paid">Pagado</option>
                <option value="pending">Pendiente</option>
              </select>
            </div>

            <!-- Fecha de pago -->
            <div class="form-group full-width" *ngIf="paymentForm.get('status')?.value === 'paid'">
              <label for="pay-date" class="form-label">Fecha de pago</label>
              <input
                id="pay-date"
                type="date"
                formControlName="paid_at"
                class="form-input"
              />
            </div>
          </div>

          <div class="modal-footer">
            <button
              type="button"
              class="btn-secondary"
              (click)="closeModal()"
              [disabled]="modalLoading()"
            >
              Cancelar
            </button>
            <button
              type="submit"
              class="btn-primary"
              [disabled]="!paymentForm.valid || modalLoading()"
            >
              <span *ngIf="!modalLoading()">
                <span class="material-symbols-outlined" style="font-size:1rem;vertical-align:middle">save</span>
                Guardar pago
              </span>
              <span *ngIf="modalLoading()">Guardando...</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  `,
  styles: [
    `
      .payments-page {
        max-width: 1280px;
        margin: 0 auto;
        padding: 1.25rem 1.25rem 2rem;
        color: #0a0a0a;
        background:
          linear-gradient(rgba(250, 250, 250, 0.78), rgba(250, 250, 250, 0.78)),
          url('/assets/crm/fondopago.png') center / cover no-repeat;
        border-radius: 16px;
      }

      /* Toast */
      .toast {
        position: fixed;
        bottom: 2rem;
        right: 2rem;
        z-index: 200;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 1rem 1.25rem;
        border-radius: 12px;
        font-size: 0.95rem;
        font-weight: 600;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        animation: slideInRight 300ms cubic-bezier(0.4, 0, 0.2, 1);
        max-width: 380px;
      }

      @keyframes slideInRight {
        from { opacity: 0; transform: translateX(50px); }
        to { opacity: 1; transform: translateX(0); }
      }

      .toast-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
      .toast-error { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; }

      .toast-close {
        display: grid; place-items: center; width: 28px; height: 28px;
        border: none; background: transparent; cursor: pointer;
        color: currentColor; opacity: 0.6; border-radius: 6px;
        transition: all 200ms ease; margin-left: auto; flex-shrink: 0;
      }
      .toast-close:hover { opacity: 1; background: rgba(0,0,0,0.05); }

      /* Header */
      .payments-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 2rem;
        margin-bottom: 2.5rem;
        flex-wrap: wrap;
        padding-bottom: 1.5rem;
        border-bottom: 2px solid #f0f0f0;
      }

      h1 {
        font-family: Inter, sans-serif;
        font-size: 2.25rem;
        font-weight: 700;
        margin: 0 0 0.5rem;
        letter-spacing: -0.02em;
        line-height: 1.1;
      }

      .header-left p {
        font-size: 1rem;
        color: #666;
        margin: 0;
        line-height: 1.6;
        max-width: 600px;
      }

      .btn-primary {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.875rem 1.75rem;
        border-radius: 10px;
        font-family: Inter, sans-serif;
        font-weight: 700;
        font-size: 0.95rem;
        cursor: pointer;
        border: none;
        background: #facc15;
        color: #000;
        box-shadow: 0 4px 12px rgba(250, 204, 21, 0.2);
        transition: all 200ms ease;
      }

      .btn-lottie {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        border-radius: 7px;
        background: transparent;
        overflow: hidden;
        flex-shrink: 0;
      }
      .btn-primary:hover:not(:disabled) {
        background: #f0c00e;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(250, 204, 21, 0.3);
      }
      .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }

      /* KPIs */
      .kpis-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.25rem;
        margin-bottom: 2.25rem;
      }

      .kpi-card {
        display: flex;
        align-items: center;
        gap: 1.25rem;
        background:
          linear-gradient(rgba(255, 255, 255, 0.90), rgba(255, 252, 230, 0.84)),
          url('/assets/crm/cardpago.png') center / cover no-repeat;
        border: 1px solid #e5e5e5;
        border-radius: 14px;
        padding: 1.5rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        transition: all 200ms ease;
      }

      .kpi-card:hover {
        box-shadow: 0 6px 20px rgba(0,0,0,0.08);
        transform: translateY(-2px);
      }

      .kpi-icon {
        display: grid;
        place-items: center;
        width: 52px;
        height: 52px;
        border-radius: 12px;
        font-size: 1.5rem;
        flex-shrink: 0;
        color: #fff;
        overflow: hidden;
      }

      .kpi-icon-total {
        background: rgba(0, 0, 0, 0.04);
        border: 1px solid rgba(0, 0, 0, 0.05);
      }
      .kpi-icon-paid {
        background: rgba(34, 197, 94, 0.06);
        border: 1px solid rgba(34, 197, 94, 0.10);
      }
      .kpi-icon-pending {
        background: rgba(255, 204, 0, 0.07);
        border: 1px solid rgba(255, 204, 0, 0.12);
      }
      .kpi-icon-failed {
        background: rgba(239, 68, 68, 0.06);
        border: 1px solid rgba(239, 68, 68, 0.10);
      }

      .kpi-value {
        font-family: Inter, sans-serif;
        font-size: 1.6rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        line-height: 1;
      }

      .kpi-label {
        font-size: 0.82rem;
        font-weight: 600;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        margin-top: 0.3rem;
      }

      .kpi-sub {
        font-size: 0.8rem;
        color: #999;
        margin-top: 0.2rem;
      }

      /* Loading */
      .loading-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 300px;
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

      @keyframes spin { to { transform: rotate(360deg); } }

      .error-alert {
        display: flex;
        gap: 1rem;
        padding: 1.5rem;
        background: #fee2e2;
        border: 1px solid #fecaca;
        border-radius: 10px;
        color: #991b1b;
        margin-bottom: 2rem;
        align-items: center;
      }

      .error-alert strong { display: block; margin-bottom: 0.25rem; }
      .error-alert p { margin: 0; font-size: 0.9rem; }

      .btn-retry {
        padding: 0.5rem 1rem;
        border-radius: 8px;
        border: 1px solid #fecaca;
        background: transparent;
        color: #991b1b;
        cursor: pointer;
        font-size: 0.85rem;
        font-weight: 600;
        transition: all 200ms ease;
        margin-left: auto;
      }
      .btn-retry:hover { background: #fef2f2; }

      /* Filters */
      .filters-section {
        display: flex;
        gap: 1rem;
        margin-bottom: 2rem;
        flex-wrap: wrap;
        align-items: center;
      }

      .filter-group { position: relative; flex: 1; min-width: 200px; }
      .search-group { flex: 2; min-width: 280px; }

      .filter-icon {
        position: absolute;
        left: 0.875rem;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
        pointer-events: none;
        font-size: 1.1rem;
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
        box-sizing: border-box;
      }

      .filter-select {
        padding-left: 1rem;
        cursor: pointer;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23999' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14L2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 0.75rem center;
        padding-right: 2.5rem;
      }

      .search-input::placeholder { color: #999; }

      .search-input:focus,
      .filter-select:focus {
        outline: none;
        border-color: #facc15;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.1);
      }

      .search-clear {
        position: absolute;
        right: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        display: grid;
        place-items: center;
        width: 28px;
        height: 28px;
        border: none;
        background: #f0f0f0;
        border-radius: 50%;
        cursor: pointer;
        color: #666;
        transition: all 200ms ease;
      }

      .search-clear:hover { background: #e0e0e0; color: #0a0a0a; }

      .filter-stats {
        font-size: 0.85rem;
        color: #999;
        white-space: nowrap;
        font-weight: 500;
      }

      /* Empty state */
      .empty-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 4rem 2rem;
        text-align: center;
        background: #fff;
        border: 1px solid #e5e5e5;
        border-radius: 14px;
        gap: 1rem;
      }

      .empty-illustration {
        margin-bottom: 0.5rem;
        display: grid;
        place-items: center;
        width: 160px;
        height: 160px;
        border-radius: 20px;
        background: rgba(250, 204, 21, 0.04);
        border: 1px solid rgba(0, 0, 0, 0.04);
        overflow: hidden;
      }

      .empty-state h2 {
        font-family: Inter, sans-serif;
        font-size: 1.4rem;
        font-weight: 700;
        color: #0a0a0a;
        margin: 0;
      }

      .empty-state p {
        color: #666;
        line-height: 1.6;
        margin: 0;
        max-width: 460px;
        font-size: 0.95rem;
      }

      .empty-state-filter { padding: 3rem 2rem; }

      .empty-icon {
        font-size: 3rem;
        color: #d0d0d0;
      }

      .btn-secondary-sm {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem 1.25rem;
        border-radius: 8px;
        font-family: Inter, sans-serif;
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        border: 1.5px solid #d0d0d0;
        background: #fff;
        color: #0a0a0a;
        transition: all 200ms ease;
      }
      .btn-secondary-sm:hover { border-color: #a0a0a0; background: #f9f9f9; }

      /* Table */
      .table-container {
        background:
          linear-gradient(rgba(255, 255, 255, 0.92), rgba(255, 252, 235, 0.86)),
          url('/assets/crm/cardpago.png') center / cover no-repeat;
        border: 1px solid #e5e5e5;
        border-radius: 14px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
        overflow: hidden;
      }

      .table-scroll { overflow-x: auto; }

      .payments-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 800px;
      }

      .payments-table thead {
        background: #f8f8f8;
        border-bottom: 1px solid #e8e8e8;
      }

      .payments-table th {
        padding: 1.1rem 1.25rem;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #888;
        text-align: left;
        white-space: nowrap;
      }

      .payments-table td {
        padding: 1.1rem 1.25rem;
        border-bottom: 1px solid #f5f5f5;
        vertical-align: middle;
      }

      .payment-row { transition: background 200ms ease; }
      .payment-row:last-child td { border-bottom: none; }
      .payment-row:hover { background: #fafafa; }

      .id-cell .payment-id {
        font-size: 0.8rem;
        font-weight: 700;
        color: #999;
        font-family: 'Courier New', monospace;
      }

      .client-cell {
        display: flex;
        align-items: center;
        gap: 0.875rem;
      }

      .client-avatar {
        display: grid;
        place-items: center;
        width: 38px;
        height: 38px;
        border-radius: 50%;
        background: #0a0a0a;
        color: #facc15;
        font-size: 0.8rem;
        font-weight: 800;
        flex-shrink: 0;
      }

      .client-name {
        font-weight: 600;
        font-size: 0.95rem;
        color: #0a0a0a;
      }

      .client-ref {
        font-size: 0.8rem;
        color: #999;
        margin-top: 0.15rem;
      }

      .plan-badge {
        display: inline-block;
        padding: 0.3rem 0.7rem;
        background: #f5f5f5;
        border-radius: 6px;
        font-size: 0.82rem;
        font-weight: 600;
        color: #444;
      }

      .no-plan { font-size: 0.85rem; color: #bbb; }

      .amount-cell strong {
        font-size: 1rem;
        font-weight: 800;
        letter-spacing: -0.01em;
      }

      .method-cell {
        font-size: 0.9rem;
        color: #555;
        display: flex;
        align-items: center;
        gap: 0.5rem;
      }

      .method-icon { font-size: 1rem; }

      .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.45rem 0.875rem;
        border-radius: 8px;
        font-size: 0.78rem;
        font-weight: 700;
        font-family: Inter, sans-serif;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        white-space: nowrap;
      }

      .status-dot {
        display: inline-block;
        width: 0.45rem;
        height: 0.45rem;
        border-radius: 50%;
        background: currentColor;
        flex-shrink: 0;
      }

      .status-paid { background: #ecfdf5; color: #047857; }
      .status-pending { background: #fef3c7; color: #92400e; }
      .status-failed { background: #fee2e2; color: #991b1b; }
      .status-cancelled { background: #f3f4f6; color: #374151; }
      .status-refunded { background: #e0e7ff; color: #3730a3; }

      .date-cell .date-main { font-size: 0.9rem; color: #444; font-weight: 500; }
      .date-cell .date-time { font-size: 0.78rem; color: #bbb; margin-top: 0.15rem; }

      .actions-cell { white-space: nowrap; }

      .action-btns { display: flex; gap: 0.4rem; }

      .action-btn {
        display: grid;
        place-items: center;
        width: 34px;
        height: 34px;
        border: 1px solid #e5e5e5;
        border-radius: 8px;
        background: #fff;
        cursor: pointer;
        color: #666;
        transition: all 200ms ease;
      }

      .action-btn .material-symbols-outlined { font-size: 1rem; }

      .action-btn:hover { background: #f5f5f5; border-color: #d0d0d0; color: #0a0a0a; }

      .action-pay:hover {
        background: #ecfdf5;
        border-color: #bbf7d0;
        color: #047857;
      }

      /* Pagination */
      .pagination {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 1rem;
        padding: 1.25rem;
        border-top: 1px solid #f0f0f0;
      }

      .page-btn {
        display: grid;
        place-items: center;
        width: 36px;
        height: 36px;
        border: 1px solid #e5e5e5;
        border-radius: 8px;
        background: #fff;
        cursor: pointer;
        color: #666;
        transition: all 200ms ease;
      }

      .page-btn:hover:not(:disabled) { background: #f5f5f5; border-color: #d0d0d0; color: #0a0a0a; }
      .page-btn:disabled { opacity: 0.4; cursor: not-allowed; }

      .page-info { font-size: 0.9rem; color: #666; font-weight: 600; }

      /* Detail Panel */
      .detail-backdrop {
        position: fixed; inset: 0;
        background: rgba(0,0,0,0.3);
        backdrop-filter: blur(2px);
        z-index: 40;
      }

      .detail-panel {
        position: fixed;
        top: 0;
        right: 0;
        bottom: 0;
        width: 100%;
        max-width: 380px;
        background: #fff;
        border-left: 1px solid #e5e5e5;
        box-shadow: -8px 0 32px rgba(0,0,0,0.12);
        z-index: 50;
        display: flex;
        flex-direction: column;
        animation: slideFromRight 300ms cubic-bezier(0.4, 0, 0.2, 1);
      }

      @keyframes slideFromRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
      }

      .detail-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1.5rem 1.75rem;
        border-bottom: 1px solid #f0f0f0;
      }

      .detail-header h3 {
        font-family: Inter, sans-serif;
        font-size: 1.1rem;
        font-weight: 700;
        margin: 0;
      }

      .detail-body {
        flex: 1;
        overflow-y: auto;
        padding: 1.75rem;
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
      }

      .detail-amount {
        font-family: Inter, sans-serif;
        font-size: 2.25rem;
        font-weight: 800;
        letter-spacing: -0.02em;
      }

      .detail-status { font-size: 0.85rem; }

      .detail-list {
        display: flex;
        flex-direction: column;
        gap: 0;
        margin: 0;
      }

      .detail-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        padding: 0.875rem 0;
        border-bottom: 1px solid #f5f5f5;
        gap: 1rem;
      }

      .detail-row dt {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.85rem;
        font-weight: 600;
        color: #888;
        white-space: nowrap;
      }

      .detail-row dt .material-symbols-outlined { font-size: 1rem; }

      .detail-row dd {
        font-size: 0.9rem;
        font-weight: 600;
        color: #0a0a0a;
        text-align: right;
        word-break: break-all;
      }

      .detail-actions { margin-top: auto; }

      .btn-mark-paid {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        width: 100%;
        padding: 0.875rem;
        border-radius: 10px;
        border: none;
        background: #facc15;
        color: #000;
        font-family: Inter, sans-serif;
        font-weight: 700;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 200ms ease;
      }

      .btn-mark-paid:hover { background: #f0c00e; }

      /* Modal */
      .modal-backdrop {
        position: fixed; inset: 0;
        background: rgba(0,0,0,0.5);
        backdrop-filter: blur(2px);
        z-index: 40;
        animation: fadeIn 200ms ease;
      }

      @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

      .modal-container {
        position: fixed; inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 50;
        padding: 1rem;
        animation: slideUp 300ms cubic-bezier(0.4, 0, 0.2, 1);
      }

      @keyframes slideUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
      }

      .modal-card {
        background: #fff;
        border: 1px solid #e5e5e5;
        border-radius: 14px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.15);
        width: 100%;
        max-width: 580px;
        max-height: 90vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
      }

      .modal-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1.5rem;
        padding: 1.75rem 2rem;
        border-bottom: 1px solid #f0f0f0;
      }

      .header-content { display: flex; gap: 1rem; flex: 1; }

      .header-icon {
        display: grid;
        place-items: center;
        width: 48px;
        height: 48px;
        border-radius: 10px;
        background: #facc15;
        color: #000;
        font-size: 1.5rem;
        flex-shrink: 0;
      }

      .modal-title {
        font-family: Inter, sans-serif;
        font-size: 1.4rem;
        font-weight: 700;
        color: #0a0a0a;
        margin: 0 0 0.35rem;
      }

      .modal-subtitle { font-size: 0.88rem; color: #666; margin: 0; }

      .btn-close {
        display: grid; place-items: center; width: 36px; height: 36px;
        border: none; border-radius: 8px; background: #f5f5f5; color: #666;
        cursor: pointer; transition: all 200ms ease; flex-shrink: 0;
      }
      .btn-close:hover { background: #e8e8e8; color: #0a0a0a; }

      .modal-error {
        display: flex; gap: 1rem; padding: 1.1rem 2rem;
        background: #fee2e2; border-bottom: 1px solid #fecaca;
        color: #991b1b; align-items: flex-start;
      }
      .modal-error .material-symbols-outlined { font-size: 1.3rem; flex-shrink: 0; }
      .modal-error strong { display: block; margin-bottom: 0.2rem; }
      .modal-error p { margin: 0; font-size: 0.88rem; }

      .modal-loading {
        display: flex; align-items: center; gap: 1rem;
        padding: 2rem; color: #666; justify-content: center;
      }

      .spinner-sm {
        width: 22px; height: 22px;
        border: 2px solid #e5e5e5;
        border-top: 2px solid #facc15;
        border-radius: 50%;
        animation: spin 1s linear infinite;
      }

      .modal-form { flex: 1; overflow-y: auto; padding: 1.75rem 2rem; }

      .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.25rem;
        margin-bottom: 1.5rem;
      }

      .form-group.full-width { grid-column: 1 / -1; }

      .form-label {
        display: block;
        font-size: 0.88rem;
        font-weight: 600;
        color: #0a0a0a;
        margin-bottom: 0.4rem;
        font-family: Inter, sans-serif;
      }

      .form-input,
      .form-select {
        width: 100%;
        padding: 0.8rem;
        border: 1px solid #e5e5e5;
        border-radius: 8px;
        font-family: Inter, sans-serif;
        font-size: 0.9rem;
        color: #0a0a0a;
        background: #fff;
        transition: all 200ms ease;
        box-sizing: border-box;
      }

      .form-select {
        cursor: pointer;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23999' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14L2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 0.75rem center;
        padding-right: 2.5rem;
      }

      .form-input::placeholder { color: #999; }

      .form-input:focus,
      .form-select:focus {
        outline: none;
        border-color: #facc15;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.1);
      }

      .input-prefix {
        position: relative;
        display: flex;
        align-items: center;
      }

      .prefix {
        position: absolute;
        left: 0.875rem;
        font-weight: 700;
        color: #444;
        pointer-events: none;
      }

      .input-prefix .form-input { padding-left: 1.75rem; }

      .error-text {
        display: block;
        margin-top: 0.35rem;
        font-size: 0.8rem;
        color: #dc2626;
        font-weight: 500;
      }

      .form-hint {
        display: block;
        margin-top: 0.35rem;
        font-size: 0.8rem;
        color: #999;
      }

      .modal-footer {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 1rem;
        padding: 1.25rem 2rem;
        border-top: 1px solid #f0f0f0;
        background: #f9f9f9;
      }

      .btn-secondary {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.875rem 1.5rem;
        border-radius: 8px;
        font-family: Inter, sans-serif;
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        border: 1.5px solid #d0d0d0;
        background: #fff;
        color: #0a0a0a;
        transition: all 200ms ease;
      }

      .btn-secondary:hover:not(:disabled) { border-color: #a0a0a0; background: #f5f5f5; }
      .btn-secondary:disabled { opacity: 0.6; cursor: not-allowed; }

      /* Responsive */
      @media (max-width: 1200px) {
        .kpis-grid { grid-template-columns: repeat(2, 1fr); }
      }

      @media (max-width: 900px) {
        .payments-header { flex-direction: column; align-items: flex-start; gap: 1.25rem; }
        .btn-primary { width: 100%; justify-content: center; }
        .filters-section { flex-direction: column; }
        .filter-group, .search-group { min-width: 100%; }
      }

      @media (max-width: 700px) {
        h1 { font-size: 1.75rem; }
        .kpis-grid { grid-template-columns: 1fr; }
        .detail-panel { max-width: 100%; }
        .form-grid { grid-template-columns: 1fr; }
        .modal-footer { flex-direction: column; }
        .btn-primary, .btn-secondary { width: 100%; justify-content: center; }
        .toast { right: 1rem; left: 1rem; bottom: 1rem; max-width: none; }
      }
    `,
  ],
})
export default class PaymentsModule implements OnInit {
  private api = inject(ApiService);
  private fb = inject(FormBuilder);

  // Estado
  payments = signal<PaymentSummary[]>([]);
  loading = signal(true);
  error = signal('');

  // KPIs
  kpiTotalAmount = computed(() =>
    this.payments().reduce((s, p) => s + (Number(p.amount) || 0), 0),
  );
  kpiPaidAmount = computed(() =>
    this.payments()
      .filter((p) => p.status === 'paid')
      .reduce((s, p) => s + (Number(p.amount) || 0), 0),
  );
  kpiPaidCount = computed(() => this.payments().filter((p) => p.status === 'paid').length);
  kpiPendingCount = computed(() => this.payments().filter((p) => p.status === 'pending').length);
  kpiPendingAmount = computed(() =>
    this.payments()
      .filter((p) => p.status === 'pending')
      .reduce((s, p) => s + (Number(p.amount) || 0), 0),
  );
  kpiFailedCount = computed(() =>
    this.payments().filter((p) => p.status === 'failed' || p.status === 'cancelled').length,
  );

  // Filtros
  searchQuery = signal('');
  filterStatus = signal('');

  filteredPayments = computed(() => {
    const search = this.searchQuery().toLowerCase();
    const status = this.filterStatus();
    return this.payments().filter((p) => {
      if (search) {
        const nameMatch = p.user?.name?.toLowerCase().includes(search);
        const refMatch = p.reference?.toLowerCase().includes(search);
        if (!nameMatch && !refMatch) return false;
      }
      if (status && p.status !== status) return false;
      return true;
    });
  });

  // Paginación local
  currentPage = signal(1);
  private readonly perPage = 15;

  totalPages = computed(() =>
    Math.max(1, Math.ceil(this.filteredPayments().length / this.perPage)),
  );

  paginatedPayments = computed(() => {
    const page = this.currentPage();
    const start = (page - 1) * this.perPage;
    return this.filteredPayments().slice(start, start + this.perPage);
  });

  // Modal registro
  isModalOpen = signal(false);
  modalLoading = signal(false);
  modalError = signal('');
  loadingModalData = signal(false);
  users = signal<UserSummary[]>([]);
  plans = signal<PlanSummary[]>([]);

  paymentForm = this.fb.nonNullable.group({
    user_id: ['', Validators.required],
    plan_id: [''],
    amount: ['', [Validators.required, Validators.min(1)]],
    method: ['', Validators.required],
    reference: [''],
    status: ['paid', Validators.required],
    paid_at: [new Date().toISOString().split('T')[0]],
  });

  // Panel de detalle
  selectedPayment = signal<PaymentSummary | null>(null);

  // Notificación
  notification = signal<{ type: 'success' | 'error'; message: string } | null>(null);
  private notifTimer: any;

  ngOnInit(): void {
    this.loadPayments();
  }

  loadPayments(): void {
    this.loading.set(true);
    this.error.set('');
    this.api.getPayments(1).subscribe({
      next: (res) => {
        this.payments.set(res.data || []);
        this.loading.set(false);
      },
      error: () => {
        this.error.set('No se pudieron cargar los pagos. Verifica la conexión con el backend.');
        this.loading.set(false);
      },
    });
  }

  async openModal(): Promise<void> {
    this.isModalOpen.set(true);
    this.loadingModalData.set(true);
    this.modalError.set('');
    this.paymentForm.reset({
      status: 'paid',
      paid_at: new Date().toISOString().split('T')[0],
    });

    try {
      const [usersRes, plansRes] = await Promise.all([
        firstValueFrom(this.api.getUsers(1)),
        firstValueFrom(this.api.getPlans(1)),
      ]);
      this.users.set(usersRes.data || []);
      this.plans.set(plansRes.data || []);
    } catch {
      this.modalError.set('No se pudieron cargar los miembros y planes.');
    } finally {
      this.loadingModalData.set(false);
    }
  }

  closeModal(): void {
    if (!this.modalLoading()) {
      this.isModalOpen.set(false);
      this.modalError.set('');
    }
  }

  onSubmitPayment(): void {
    if (!this.paymentForm.valid) {
      this.paymentForm.markAllAsTouched();
      return;
    }

    this.modalLoading.set(true);
    this.modalError.set('');

    const val = this.paymentForm.getRawValue();
    const data = {
      user_id: Number(val.user_id),
      plan_id: val.plan_id ? Number(val.plan_id) : undefined,
      amount: Number(val.amount),
      method: val.method || undefined,
      reference: val.reference || undefined,
      status: val.status,
      paid_at: val.status === 'paid' ? val.paid_at : undefined,
    };

    this.api.createPayment(data).subscribe({
      next: (payment) => {
        this.payments.update((list) => [payment, ...list]);
        this.currentPage.set(1);
        this.closeModal();
        this.showNotification('success', 'Pago registrado correctamente.');
        this.modalLoading.set(false);
      },
      error: (err) => {
        const msg = err?.error?.message || 'No se pudo registrar el pago. Intenta de nuevo.';
        this.modalError.set(msg);
        this.modalLoading.set(false);
      },
    });
  }

  markAsPaid(payment: PaymentSummary): void {
    const now = new Date().toISOString();
    this.api.updatePayment(payment.id, { status: 'paid', paid_at: now }).subscribe({
      next: (updated) => {
        this.payments.update((list) => list.map((p) => (p.id === updated.id ? updated : p)));
        if (this.selectedPayment()?.id === payment.id) this.selectedPayment.set(updated);
        this.showNotification('success', `Pago de ${payment.user?.name || '#' + payment.id} marcado como pagado.`);
      },
      error: () => this.showNotification('error', 'No se pudo actualizar el estado del pago.'),
    });
  }

  viewPayment(payment: PaymentSummary): void {
    this.selectedPayment.set(payment);
  }

  closeDetail(): void {
    this.selectedPayment.set(null);
  }

  onSearchChange(val: string): void {
    this.searchQuery.set(val);
    this.currentPage.set(1);
  }

  clearSearch(): void {
    this.searchQuery.set('');
    this.currentPage.set(1);
  }

  onStatusChange(val: string): void {
    this.filterStatus.set(val);
    this.currentPage.set(1);
  }

  clearFilters(): void {
    this.searchQuery.set('');
    this.filterStatus.set('');
    this.currentPage.set(1);
  }

  changePage(page: number): void {
    if (page >= 1 && page <= this.totalPages()) this.currentPage.set(page);
  }

  showNotification(type: 'success' | 'error', message: string): void {
    clearTimeout(this.notifTimer);
    this.notification.set({ type, message });
    this.notifTimer = setTimeout(() => this.notification.set(null), 4500);
  }

  clearNotification(): void {
    clearTimeout(this.notifTimer);
    this.notification.set(null);
  }

  getInitials(name?: string | null): string {
    if (!name) return '?';
    const parts = name.trim().split(' ');
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return name.slice(0, 2).toUpperCase();
  }

  statusLabel(status: string): string {
    const labels: Record<string, string> = {
      paid: 'Pagado',
      pending: 'Pendiente',
      failed: 'Fallido',
      cancelled: 'Cancelado',
      refunded: 'Reembolsado',
    };
    return labels[status?.toLowerCase()] ?? status;
  }

  methodLabel(method?: string | null): string {
    const labels: Record<string, string> = {
      cash: 'Efectivo',
      transfer: 'Transferencia',
      card: 'Tarjeta',
      pse: 'PSE',
      nequi: 'Nequi / Daviplata',
      other: 'Otro',
    };
    return labels[method?.toLowerCase() ?? ''] ?? method ?? '—';
  }

  methodIcon(method?: string | null): string {
    const icons: Record<string, string> = {
      cash: '💵',
      transfer: '🏦',
      card: '💳',
      pse: '🔗',
      nequi: '📱',
      other: '📄',
    };
    return icons[method?.toLowerCase() ?? ''] ?? '💰';
  }

  formatCurrency(amount: number): string {
    return new Intl.NumberFormat('es-CO', {
      style: 'currency',
      currency: 'COP',
      maximumFractionDigits: 0,
    }).format(amount || 0);
  }

  formatCurrencyShort(amount: number): string {
    if (amount >= 1000000) return '$' + (amount / 1000000).toFixed(1).replace('.0', '') + 'M';
    if (amount >= 1000) return '$' + (amount / 1000).toFixed(0) + 'K';
    return '$' + (amount || 0).toLocaleString('es-CO');
  }
}
