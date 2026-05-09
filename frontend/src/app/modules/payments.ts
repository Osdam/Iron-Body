import { CommonModule, DatePipe } from '@angular/common';
import { Component, ElementRef, HostListener, OnInit, inject, signal, computed } from '@angular/core';
import {
  FormsModule,
  ReactiveFormsModule,
  FormBuilder,
  Validators,
} from '@angular/forms';
import { ApiService, PaymentSummary, PlanSummary, UserSummary } from '../services/api.service';
import { firstValueFrom } from 'rxjs';
import { LottieIconComponent } from '../shared/components/lottie-icon/lottie-icon.component';
import { DateWheelPickerComponent } from '../shared/components/date-wheel-picker/date-wheel-picker.component';

@Component({
  selector: 'module-payments',
  standalone: true,
  imports: [
    CommonModule,
    DatePipe,
    FormsModule,
    ReactiveFormsModule,
    LottieIconComponent,
    DateWheelPickerComponent,
  ],
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
                        *ngIf="canCancelPayments() && payment.status !== 'cancelled'"
                        class="action-btn action-cancel"
                        (click)="cancelPayment(payment)"
                        title="Anular pago"
                      >
                        <span class="material-symbols-outlined">block</span>
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
              <label class="form-label">Cliente *</label>
              <div class="pretty-select" [class.open]="openSelect() === 'user'">
                <button type="button" class="pretty-trigger" (click)="toggleSelect('user')">
                  <span>{{ selectedUserLabel() }}</span>
                  <span class="select-chevron" aria-hidden="true"></span>
                </button>
                <div *ngIf="openSelect() === 'user'" class="pretty-menu user-menu">
                  <div class="user-search-box">
                    <span class="material-symbols-outlined" aria-hidden="true">search</span>
                    <input
                      type="text"
                      class="user-search-input"
                      placeholder="Buscar por nombre, cédula o correo..."
                      [value]="userSearchQuery()"
                      (input)="onUserSearch($event)"
                      (click)="$event.stopPropagation()"
                    />
                  </div>
                  <div class="user-result-count">
                    {{ filteredModalUsers().length }} cliente(s)
                  </div>
                  <button
                    type="button"
                    *ngFor="let u of filteredModalUsers()"
                    class="pretty-option"
                    [class.selected]="isSelectedControl('user_id', u.id)"
                    (click)="choosePaymentOption('user', u.id)"
                  >
                    <span class="option-main">
                      <span class="option-icon avatar-icon" aria-hidden="true">{{ getInitials(u.name) }}</span>
                      <span class="option-copy">
                        <strong>{{ u.name }}</strong>
                        <small>{{ u.email || u.document || 'Cliente registrado' }}</small>
                      </span>
                    </span>
                    <span class="option-check" aria-hidden="true"></span>
                  </button>
                  <div *ngIf="filteredModalUsers().length === 0" class="user-empty">
                    No hay clientes que coincidan con la búsqueda.
                  </div>
                </div>
              </div>
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
              <label class="form-label">Plan (opcional)</label>
              <div class="pretty-select" [class.open]="openSelect() === 'plan'">
                <button type="button" class="pretty-trigger" (click)="toggleSelect('plan')">
                  <span>{{ selectedPlanLabel() }}</span>
                  <span class="select-chevron" aria-hidden="true"></span>
                </button>
                <div *ngIf="openSelect() === 'plan'" class="pretty-menu plan-menu">
                  <button
                    type="button"
                    class="pretty-option"
                    [class.selected]="!paymentForm.get('plan_id')?.value"
                    (click)="choosePaymentOption('plan', '')"
                  >
                    <span class="option-main">
                      <span class="option-icon" aria-hidden="true">
                        <svg class="option-svg" viewBox="0 0 24 24">
                          <path [attr.d]="svgIcon('minus-circle')"></path>
                        </svg>
                      </span>
                      <span class="option-copy">
                        <strong>Sin plan asociado</strong>
                        <small>Registrar pago libre</small>
                      </span>
                    </span>
                    <span class="option-check" aria-hidden="true"></span>
                  </button>
                  <button
                    type="button"
                    *ngFor="let p of plans()"
                    class="pretty-option"
                    [class.selected]="isSelectedControl('plan_id', p.id)"
                    (click)="choosePaymentOption('plan', p.id)"
                  >
                    <span class="option-main">
                      <span class="option-icon" aria-hidden="true">
                        <svg class="option-svg" viewBox="0 0 24 24">
                          <path [attr.d]="svgIcon('badge')"></path>
                        </svg>
                      </span>
                      <span class="option-copy">
                        <strong>{{ p.name }}</strong>
                        <small>{{ formatCurrency(p.price) }} · {{ p.duration_days }} días</small>
                      </span>
                    </span>
                    <span class="option-check" aria-hidden="true"></span>
                  </button>
                </div>
              </div>
            </div>

            <!-- Monto -->
            <div class="form-group">
              <label for="pay-amount" class="form-label">Monto *</label>
              <div class="input-prefix">
                <span class="prefix">$</span>
                <input
                  id="pay-amount"
                  type="number"
                  formControlName="amount"
                  class="form-input readonly-input"
                  placeholder="Selecciona un plan"
                  min="1"
                  readonly
                  tabindex="-1"
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
              <label class="form-label">Método de pago *</label>
              <div class="pretty-select" [class.open]="openSelect() === 'method'">
                <button type="button" class="pretty-trigger" (click)="toggleSelect('method')">
                  <span>{{ optionLabel(methodOptions, paymentForm.get('method')?.value) }}</span>
                  <span class="select-chevron" aria-hidden="true"></span>
                </button>
                <div *ngIf="openSelect() === 'method'" class="pretty-menu">
                  <button
                    type="button"
                    *ngFor="let option of methodOptions"
                    class="pretty-option"
                    [class.selected]="paymentForm.get('method')?.value === option.value"
                    (click)="choosePaymentOption('method', option.value)"
                  >
                    <span class="option-main">
                      <span class="option-icon" aria-hidden="true">
                        <svg class="option-svg" viewBox="0 0 24 24">
                          <path [attr.d]="svgIcon(option.icon)"></path>
                        </svg>
                      </span>
                      <span class="option-copy">
                        <strong>{{ option.label }}</strong>
                        <small>{{ option.description }}</small>
                      </span>
                    </span>
                    <span class="option-check" aria-hidden="true"></span>
                  </button>
                </div>
              </div>
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
              <label class="form-label">Estado *</label>
              <div class="pretty-select" [class.open]="openSelect() === 'status'">
                <button type="button" class="pretty-trigger" (click)="toggleSelect('status')">
                  <span>{{ optionLabel(statusOptions, paymentForm.get('status')?.value) }}</span>
                  <span class="select-chevron" aria-hidden="true"></span>
                </button>
                <div *ngIf="openSelect() === 'status'" class="pretty-menu">
                  <button
                    type="button"
                    *ngFor="let option of statusOptions"
                    class="pretty-option"
                    [class.selected]="paymentForm.get('status')?.value === option.value"
                    (click)="choosePaymentOption('status', option.value)"
                  >
                    <span class="option-main">
                      <span class="option-icon" aria-hidden="true">
                        <svg class="option-svg" viewBox="0 0 24 24">
                          <path [attr.d]="svgIcon(option.icon)"></path>
                        </svg>
                      </span>
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

            <!-- Fecha de pago -->
            <div class="form-group full-width" *ngIf="paymentForm.get('status')?.value === 'paid'">
              <label for="pay-date" class="form-label">Fecha de pago</label>
              <app-date-wheel-picker
                formControlName="paid_at"
                [minYear]="currentYear - 2"
                [maxYear]="currentYear + 1"
                size="sm"
                ariaLabel="Fecha de pago"
              ></app-date-wheel-picker>
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

      .action-cancel:hover {
        background: #fef2f2;
        border-color: #fecaca;
        color: #b91c1c;
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
        border-radius: 16px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.15);
        width: 100%;
        max-width: 760px;
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

      .readonly-input {
        background: #f8fafc;
        color: #52525b;
        cursor: not-allowed;
      }

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

      .pretty-select {
        position: relative;
      }

      .pretty-trigger {
        width: 100%;
        min-height: 46px;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 0.75rem;
        padding: 0.78rem 0.85rem;
        border: 1px solid #e5e5e5;
        border-radius: 8px;
        background: #fff;
        color: #0a0a0a;
        font: 700 0.9rem Inter, sans-serif;
        text-align: left;
        cursor: pointer;
        transition:
          border-color 160ms ease,
          box-shadow 160ms ease,
          background 160ms ease;
      }

      .pretty-trigger > span:first-child {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .select-chevron {
        width: 0.55rem;
        height: 0.55rem;
        border-bottom: 2px solid #a16207;
        border-right: 2px solid #a16207;
        transform: rotate(45deg) translateY(-1px);
        transition: transform 160ms ease;
      }

      .pretty-select.open .pretty-trigger,
      .pretty-trigger:hover {
        border-color: #facc15;
        background: #fffdf4;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.12);
      }

      .pretty-select.open .select-chevron {
        transform: rotate(225deg) translateY(-1px);
      }

      .pretty-menu {
        position: absolute;
        top: calc(100% + 0.35rem);
        left: 0;
        right: 0;
        z-index: 80;
        display: grid;
        gap: 0.2rem;
        max-height: 260px;
        overflow-y: auto;
        padding: 0.35rem;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.98);
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.16);
      }

      .user-menu,
      .plan-menu {
        max-height: 310px;
      }

      .user-menu {
        padding-top: 0.45rem;
      }

      .user-search-box {
        position: sticky;
        top: 0;
        z-index: 2;
        display: grid;
        grid-template-columns: auto 1fr;
        align-items: center;
        gap: 0.55rem;
        margin: 0 0 0.35rem;
        padding: 0.58rem 0.7rem;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #ffffff;
        box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
      }

      .user-search-box .material-symbols-outlined {
        color: #a16207;
        font-size: 1.1rem;
      }

      .user-search-input {
        width: 100%;
        min-width: 0;
        border: 0;
        outline: 0;
        background: transparent;
        color: #18181b;
        font: 700 0.86rem Inter, sans-serif;
      }

      .user-search-input::placeholder {
        color: #a1a1aa;
      }

      .user-result-count {
        padding: 0.15rem 0.3rem 0.35rem;
        color: #71717a;
        font: 750 0.74rem Inter, sans-serif;
      }

      .user-empty {
        padding: 0.9rem 0.7rem;
        color: #71717a;
        font: 700 0.84rem Inter, sans-serif;
        text-align: center;
      }

      .pretty-option {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.85rem;
        min-height: 54px;
        padding: 0.6rem 0.7rem;
        border: 0;
        border-radius: 9px;
        background: transparent;
        color: #27272a;
        text-align: left;
        cursor: pointer;
        transition:
          background 160ms ease,
          color 160ms ease,
          transform 160ms ease;
      }

      .pretty-option:hover {
        background: #fafafa;
      }

      .pretty-option.selected {
        background: #fef3c7;
        color: #111827;
      }

      .option-main {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        min-width: 0;
      }

      .option-icon {
        width: 2rem;
        height: 2rem;
        display: grid;
        place-items: center;
        border-radius: 8px;
        background: #f4f4f5;
        color: #a16207;
        flex-shrink: 0;
      }

      .avatar-icon {
        background: #111827;
        color: #facc15;
        font: 900 0.72rem Inter, sans-serif;
        letter-spacing: 0.02em;
      }

      .pretty-option.selected .option-icon {
        background: #facc15;
        color: #111827;
      }

      .option-svg {
        width: 1.12rem;
        height: 1.12rem;
        display: block;
        fill: none;
        stroke: currentColor;
        stroke-width: 2;
        stroke-linecap: round;
        stroke-linejoin: round;
      }

      .option-copy {
        display: grid;
        gap: 0.12rem;
        min-width: 0;
      }

      .option-copy strong,
      .option-copy small {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .option-copy strong {
        color: inherit;
        font: 850 0.9rem Inter, sans-serif;
      }

      .option-copy small {
        color: #71717a;
        font: 650 0.75rem Inter, sans-serif;
      }

      .pretty-option.selected .option-copy small {
        color: #854d0e;
      }

      .option-check {
        width: 1.15rem;
        height: 1.15rem;
        position: relative;
        display: block;
        border: 2px solid transparent;
        border-radius: 999px;
        flex-shrink: 0;
      }

      .pretty-option.selected .option-check {
        border-color: #ca8a04;
        background: #ca8a04;
      }

      .pretty-option.selected .option-check::after {
        content: '';
        position: absolute;
        left: 0.31rem;
        top: 0.16rem;
        width: 0.3rem;
        height: 0.58rem;
        border: solid #fff;
        border-width: 0 2px 2px 0;
        transform: rotate(45deg);
      }

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
  private elementRef = inject(ElementRef<HTMLElement>);

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
  userSearchQuery = signal('');
  openSelect = signal<'user' | 'plan' | 'method' | 'status' | null>(null);
  currentYear = new Date().getFullYear();

  filteredModalUsers = computed(() => {
    const query = this.normalizeSearch(this.userSearchQuery());
    if (!query) return this.users();

    return this.users().filter((user) => {
      const searchable = this.normalizeSearch(
        `${user.name || ''} ${user.document || ''} ${user.email || ''} ${user.phone || ''}`,
      );
      return searchable.includes(query);
    });
  });

  methodOptions = [
    { value: '', label: 'Seleccionar...', icon: 'circle-help', description: 'Método sin definir' },
    { value: 'cash', label: 'Efectivo', icon: 'banknote', description: 'Pago en caja' },
    { value: 'transfer', label: 'Transferencia', icon: 'building', description: 'Cuenta bancaria' },
    { value: 'card', label: 'Tarjeta', icon: 'credit-card', description: 'Débito o crédito' },
    { value: 'pse', label: 'PSE', icon: 'link', description: 'Pago electrónico' },
    { value: 'nequi', label: 'Nequi / Daviplata', icon: 'phone', description: 'Billetera digital' },
    { value: 'other', label: 'Otro', icon: 'receipt', description: 'Método alternativo' },
  ];

  paymentRules(): {
    defaultStatus: string;
    autoGenerateReference: boolean;
    requireReference: boolean;
    receiptPrefix: string;
    nextReceiptNumber: number;
    allowCancellation: boolean;
  } {
    const defaults = {
      defaultStatus: 'paid',
      autoGenerateReference: true,
      requireReference: false,
      receiptPrefix: 'REC',
      nextReceiptNumber: 1001,
      allowCancellation: true,
    };

    try {
      const saved = localStorage.getItem('crmSettings');
      if (!saved) return defaults;
      const parsed = JSON.parse(saved);
      return {
        defaultStatus: parsed?.payments?.defaultStatus || defaults.defaultStatus,
        autoGenerateReference:
          parsed?.payments?.autoGenerateReference ?? defaults.autoGenerateReference,
        requireReference: parsed?.payments?.requireReference ?? defaults.requireReference,
        receiptPrefix: parsed?.payments?.receiptPrefix || defaults.receiptPrefix,
        nextReceiptNumber: Number(
          parsed?.payments?.nextReceiptNumber || defaults.nextReceiptNumber,
        ),
        allowCancellation: parsed?.payments?.allowCancellation ?? defaults.allowCancellation,
      };
    } catch {
      return defaults;
    }
  }

  statusOptions = [
    { value: 'paid', label: 'Pagado', icon: 'check-circle', description: 'Pago confirmado' },
    { value: 'pending', label: 'Pendiente', icon: 'clock', description: 'Por confirmar' },
  ];

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

  @HostListener('document:click', ['$event'])
  closeSelectOnOutsideClick(event: MouseEvent): void {
    if (!this.openSelect()) return;
    if (!this.elementRef.nativeElement.contains(event.target as Node)) {
      this.openSelect.set(null);
    }
  }

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
    this.openSelect.set(null);
    this.userSearchQuery.set('');
    const rules = this.paymentRules();
    this.paymentForm.reset({
      user_id: '',
      plan_id: '',
      amount: '',
      method: '',
      reference: rules.autoGenerateReference ? this.buildPaymentReference(rules) : '',
      status: rules.defaultStatus,
      paid_at: new Date().toISOString().split('T')[0],
    });

    try {
      const [usersRes, plansRes] = await Promise.all([
        this.loadAllUsers(),
        this.loadAllPlans(),
      ]);
      this.users.set(usersRes);
      this.plans.set(plansRes.filter((plan) => plan.active));
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
      this.openSelect.set(null);
      this.userSearchQuery.set('');
    }
  }

  private async loadAllUsers(): Promise<UserSummary[]> {
    const first = await firstValueFrom(this.api.getUsers(1));
    const users = [...(first.data || [])];

    for (let page = 2; page <= first.last_page; page++) {
      const next = await firstValueFrom(this.api.getUsers(page));
      users.push(...(next.data || []));
    }

    return users;
  }

  private async loadAllPlans(): Promise<PlanSummary[]> {
    const first = await firstValueFrom(this.api.getPlans(1));
    const plans = [...(first.data || [])];

    for (let page = 2; page <= first.last_page; page++) {
      const next = await firstValueFrom(this.api.getPlans(page));
      plans.push(...(next.data || []));
    }

    return plans;
  }

  toggleSelect(select: 'user' | 'plan' | 'method' | 'status'): void {
    this.openSelect.update((current) => (current === select ? null : select));
  }

  onUserSearch(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.userSearchQuery.set(input.value || '');
  }

  choosePaymentOption(
    control: 'user' | 'plan' | 'method' | 'status',
    value: string | number,
  ): void {
    const normalized = String(value);

    if (control === 'user') {
      this.paymentForm.patchValue({ user_id: normalized });
      this.userSearchQuery.set('');
      this.applyUserPlan(Number(value));
    } else if (control === 'plan') {
      this.paymentForm.patchValue({ plan_id: normalized });
      this.applyPlanAmount(Number(value));
    } else {
      this.paymentForm.get(control)?.setValue(normalized);
      if (control === 'status' && normalized === 'paid' && !this.paymentForm.get('paid_at')?.value) {
        this.paymentForm.patchValue({ paid_at: new Date().toISOString().split('T')[0] });
      }
    }

    this.paymentForm.get(control === 'user' ? 'user_id' : control === 'plan' ? 'plan_id' : control)?.markAsTouched();
    this.openSelect.set(null);
  }

  private applyUserPlan(userId: number): void {
    const user = this.users().find((item) => item.id === userId);
    const userPlan = (user?.plan || '').toLowerCase().trim();
    if (!userPlan) return;

    const matchingPlan = this.plans().find((plan) => plan.name.toLowerCase().trim() === userPlan);
    if (!matchingPlan) return;

    this.paymentForm.patchValue({ plan_id: String(matchingPlan.id) });
    this.applyPlanAmount(matchingPlan.id);
  }

  private applyPlanAmount(planId: number): void {
    const plan = this.plans().find((item) => item.id === planId);
    if (!plan) return;
    this.paymentForm.patchValue({ amount: String(plan.price || '') });
  }

  isSelectedControl(control: 'user_id' | 'plan_id', id: number): boolean {
    return Number(this.paymentForm.get(control)?.value) === id;
  }

  selectedUserLabel(): string {
    const userId = Number(this.paymentForm.get('user_id')?.value);
    return this.users().find((user) => user.id === userId)?.name || 'Seleccionar cliente...';
  }

  selectedPlanLabel(): string {
    const planId = Number(this.paymentForm.get('plan_id')?.value);
    const plan = this.plans().find((item) => item.id === planId);
    return plan ? `${plan.name} · ${this.formatCurrency(plan.price)}` : 'Sin plan asociado';
  }

  optionLabel(options: { value: string; label: string }[], value?: string | null): string {
    return options.find((option) => option.value === (value || ''))?.label || 'Seleccionar...';
  }

  private buildPaymentReference(rules = this.paymentRules()): string {
    const prefix = String(rules.receiptPrefix || 'REC').trim().toUpperCase();
    return `${prefix}-${rules.nextReceiptNumber}`;
  }

  private incrementReceiptNumber(): void {
    const saved = localStorage.getItem('crmSettings');
    if (!saved) return;

    const parsed = JSON.parse(saved);
    parsed.payments = {
      ...(parsed.payments || {}),
      nextReceiptNumber: Number(parsed?.payments?.nextReceiptNumber || 1001) + 1,
      currency: 'COP',
    };
    localStorage.setItem('crmSettings', JSON.stringify(parsed));
  }

  private normalizeSearch(value: string): string {
    return value
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .trim();
  }

  svgIcon(icon: string): string {
    const icons: Record<string, string> = {
      'circle-help': 'M9.09 9a3 3 0 1 1 5.82 1c0 2-3 2-3 4 M12 17h.01 M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0',
      banknote: 'M3 6h18v12H3z M7 12h.01 M17 12h.01 M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6',
      building: 'M3 21h18 M6 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16 M9 8h.01 M15 8h.01 M9 12h.01 M15 12h.01 M9 16h.01 M15 16h.01',
      'credit-card': 'M3 6h18v12H3z M3 10h18 M7 15h3',
      link: 'M10 13a5 5 0 0 0 7.07 0l2-2a5 5 0 0 0-7.07-7.07l-1.15 1.15 M14 11a5 5 0 0 0-7.07 0l-2 2a5 5 0 0 0 7.07 7.07l1.15-1.15',
      phone: 'M22 16.92v3a2 2 0 0 1-2.18 2A19.8 19.8 0 0 1 3 5.18 2 2 0 0 1 5 3h3a2 2 0 0 1 2 1.72c.12.9.32 1.77.57 2.61a2 2 0 0 1-.45 2.11L9 10.56a16 16 0 0 0 4.44 4.44l1.12-1.12a2 2 0 0 1 2.11-.45c.84.25 1.71.45 2.61.57A2 2 0 0 1 22 16.92',
      receipt: 'M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1z M8 7h8 M8 12h8 M8 17h5',
      'check-circle': 'M9 12l2 2 4-4 M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0',
      clock: 'M12 6v6l4 2 M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0',
      badge: 'M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0 M8.2 11.5 7 21l5-3 5 3-1.2-9.5 M6 11h12',
      'minus-circle': 'M8 12h8 M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0',
    };

    return icons[icon] || icons['circle-help'];
  }

  onSubmitPayment(): void {
    if (!this.paymentForm.valid) {
      this.paymentForm.markAllAsTouched();
      return;
    }

    const val = this.paymentForm.getRawValue();
    const rules = this.paymentRules();

    if (rules.requireReference && !String(val.reference || '').trim()) {
      this.modalError.set('La referencia es obligatoria según la configuración de pagos.');
      this.paymentForm.get('reference')?.markAsTouched();
      return;
    }

    this.modalLoading.set(true);
    this.modalError.set('');

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
        if (rules.autoGenerateReference && val.reference === this.buildPaymentReference(rules)) {
          this.incrementReceiptNumber();
        }
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

  canCancelPayments(): boolean {
    return this.paymentRules().allowCancellation;
  }

  cancelPayment(payment: PaymentSummary): void {
    const ok = window.confirm(`¿Anular el pago #${payment.id}?`);
    if (!ok) return;

    this.api.updatePayment(payment.id, { status: 'cancelled' }).subscribe({
      next: (updated) => {
        this.payments.update((list) => list.map((p) => (p.id === updated.id ? updated : p)));
        if (this.selectedPayment()?.id === payment.id) this.selectedPayment.set(updated);
        this.showNotification('success', `Pago #${payment.id} anulado.`);
      },
      error: () => this.showNotification('error', 'No se pudo anular el pago.'),
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
