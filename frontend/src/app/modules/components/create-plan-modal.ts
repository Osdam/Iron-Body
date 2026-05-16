import { CommonModule } from '@angular/common';
import {
  Component,
  ElementRef,
  Output,
  EventEmitter,
  HostListener,
  Input,
  OnInit,
  signal,
  Signal,
} from '@angular/core';
import {
  ReactiveFormsModule,
  FormsModule,
  FormBuilder,
  FormGroup,
  Validators,
} from '@angular/forms';
import { ApiService } from '../../services/api.service';

interface CreatePlanFormData {
  name: string;
  description: string;
  price: number;
  duration_days: number;
  billing_cycle: string;
  plan_type: string;
  status: string;
  benefits: string[];
}

type PlanSelectControl = 'billing_cycle' | 'plan_type' | 'status';

interface PlanSelectOption {
  value: string;
  label: string;
  description: string;
  icon: string;
}

@Component({
  selector: 'app-create-plan-modal',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule],
  template: `
    <!-- Modal Backdrop -->
    <div *ngIf="isOpen()" class="modal-backdrop" (click)="close()" aria-hidden="true"></div>

    <!-- Modal Card -->
    <div *ngIf="isOpen()" class="modal-container">
      <div class="modal-card">
        <!-- Header -->
        <div class="modal-header">
          <div class="header-content">
            <div class="header-icon">
              <span class="material-symbols-outlined" aria-hidden="true">loyalty</span>
            </div>
            <div class="header-text">
              <h2 class="modal-title">Crear nuevo plan</h2>
              <p class="modal-subtitle">
                Define precio, duración, beneficios y estado del plan de membresía.
              </p>
            </div>
          </div>
          <button class="btn-close" (click)="close()" aria-label="Cerrar modal">
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
          </button>
        </div>

        <!-- Error Message -->
        <div *ngIf="errorMessage()" class="error-message">
          <span class="material-symbols-outlined" aria-hidden="true">error</span>
          <div>
            <strong>Error al crear plan</strong>
            <p>{{ errorMessage() }}</p>
          </div>
        </div>

        <!-- Form Content -->
        <form [formGroup]="planForm" (ngSubmit)="onSubmit()" class="modal-form">
          <div class="form-grid">
            <!-- Nombre del Plan -->
            <div class="form-group full-width">
              <label for="name" class="form-label">Nombre del plan *</label>
              <input
                id="name"
                type="text"
                formControlName="name"
                class="form-input"
                placeholder="Ej: Plan Mensual, Plan VIP, Plan Anual"
                aria-required="true"
              />
              <span
                *ngIf="planForm.get('name')?.hasError('required') && planForm.get('name')?.touched"
                class="error-text"
              >
                El nombre es obligatorio
              </span>
            </div>

            <!-- Descripción -->
            <div class="form-group full-width">
              <label for="description" class="form-label">Descripción</label>
              <textarea
                id="description"
                formControlName="description"
                class="form-input textarea"
                placeholder="Describe brevemente qué incluye este plan"
                rows="3"
              ></textarea>
            </div>

            <!-- Precio -->
            <div class="form-group">
              <label for="price" class="form-label">Precio en COP *</label>
              <input
                id="price"
                type="number"
                formControlName="price"
                class="form-input"
                placeholder="Ej: 80000"
                min="1"
                aria-required="true"
              />
              <span
                *ngIf="
                  planForm.get('price')?.hasError('required') && planForm.get('price')?.touched
                "
                class="error-text"
              >
                El precio es obligatorio
              </span>
              <span
                *ngIf="planForm.get('price')?.hasError('min') && planForm.get('price')?.touched"
                class="error-text"
              >
                El precio debe ser mayor a 0
              </span>
            </div>

            <!-- Duración en días -->
            <div class="form-group">
              <label for="duration_days" class="form-label">Duración en días *</label>
              <input
                id="duration_days"
                type="number"
                formControlName="duration_days"
                class="form-input"
                placeholder="Ej: 30, 90, 180, 365"
                min="1"
                aria-required="true"
              />
              <span
                *ngIf="
                  planForm.get('duration_days')?.hasError('required') &&
                  planForm.get('duration_days')?.touched
                "
                class="error-text"
              >
                La duración es obligatoria
              </span>
              <span
                *ngIf="
                  planForm.get('duration_days')?.hasError('min') &&
                  planForm.get('duration_days')?.touched
                "
                class="error-text"
              >
                La duración debe ser mayor a 0
              </span>
            </div>

            <!-- Ciclo de cobro -->
            <div class="form-group">
              <label for="billing_cycle" class="form-label">Ciclo de cobro *</label>
              <div class="pretty-select" [class.open]="openSelect() === 'billing_cycle'">
                <button type="button" class="pretty-trigger" (click)="toggleSelect('billing_cycle')">
                  <span>{{ optionLabel('billing_cycle') }}</span>
                  <span class="select-chevron" aria-hidden="true"></span>
                </button>
                <div class="pretty-menu" *ngIf="openSelect() === 'billing_cycle'">
                  <button
                    type="button"
                    class="pretty-option"
                    *ngFor="let option of billingCycleOptions"
                    [class.selected]="planForm.get('billing_cycle')?.value === option.value"
                    (click)="chooseOption('billing_cycle', option.value)"
                  >
                    <span class="option-main">
                      <span class="option-icon material-symbols-outlined" aria-hidden="true">{{ option.icon }}</span>
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
                *ngIf="
                  planForm.get('billing_cycle')?.hasError('required') &&
                  planForm.get('billing_cycle')?.touched
                "
                class="error-text"
              >
                El ciclo de cobro es obligatorio
              </span>
            </div>

            <!-- Tipo de Plan -->
            <div class="form-group">
              <label for="plan_type" class="form-label">Tipo de plan *</label>
              <div class="pretty-select" [class.open]="openSelect() === 'plan_type'">
                <button type="button" class="pretty-trigger" (click)="toggleSelect('plan_type')">
                  <span>{{ optionLabel('plan_type') }}</span>
                  <span class="select-chevron" aria-hidden="true"></span>
                </button>
                <div class="pretty-menu" *ngIf="openSelect() === 'plan_type'">
                  <button
                    type="button"
                    class="pretty-option"
                    *ngFor="let option of planTypeOptions"
                    [class.selected]="planForm.get('plan_type')?.value === option.value"
                    (click)="chooseOption('plan_type', option.value)"
                  >
                    <span class="option-main">
                      <span class="option-icon material-symbols-outlined" aria-hidden="true">{{ option.icon }}</span>
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
                *ngIf="
                  planForm.get('plan_type')?.hasError('required') &&
                  planForm.get('plan_type')?.touched
                "
                class="error-text"
              >
                El tipo de plan es obligatorio
              </span>
            </div>

            <!-- Estado -->
            <div class="form-group">
              <label for="status" class="form-label">Estado *</label>
              <div class="pretty-select" [class.open]="openSelect() === 'status'">
                <button type="button" class="pretty-trigger" (click)="toggleSelect('status')">
                  <span>{{ optionLabel('status') }}</span>
                  <span class="select-chevron" aria-hidden="true"></span>
                </button>
                <div class="pretty-menu" *ngIf="openSelect() === 'status'">
                  <button
                    type="button"
                    class="pretty-option"
                    *ngFor="let option of statusOptions"
                    [class.selected]="planForm.get('status')?.value === option.value"
                    (click)="chooseOption('status', option.value)"
                  >
                    <span class="option-main">
                      <span class="option-icon material-symbols-outlined" aria-hidden="true">{{ option.icon }}</span>
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
                *ngIf="
                  planForm.get('status')?.hasError('required') && planForm.get('status')?.touched
                "
                class="error-text"
              >
                El estado es obligatorio
              </span>
            </div>
          </div>

          <!-- Beneficios Section -->
          <div class="beneficios-section">
            <div class="beneficios-header">
              <div>
                <h3 class="beneficios-title">Beneficios incluidos *</h3>
                <p class="beneficios-copy">Agrega al menos un beneficio visible para CRM y app móvil.</p>
              </div>
              <button
                type="button"
                class="btn-add-benefit"
                (click)="addBenefit()"
                aria-label="Agregar beneficio"
              >
                <span class="material-symbols-outlined" aria-hidden="true">add</span>
                Agregar beneficio
              </button>
            </div>

            <!-- Beneficios List -->
            <div class="beneficios-list">
              <div *ngFor="let benefit of benefitsList(); let i = index" class="benefit-item">
                <div class="benefit-content">
                  <span class="benefit-icon material-symbols-outlined" aria-hidden="true">
                    check_circle
                  </span>
                  <input
                    type="text"
                    [(ngModel)]="benefit.text"
                    [ngModelOptions]="{ standalone: true }"
                    class="benefit-input"
                    placeholder="Ej: Acceso a rutinas asignadas"
                    aria-label="Beneficio"
                    (ngModelChange)="benefitsError.set('')"
                  />
                </div>
                <button
                  type="button"
                  class="btn-remove-benefit"
                  (click)="removeBenefit(i)"
                  aria-label="Remover beneficio"
                >
                  <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                </button>
              </div>
            </div>
            <span *ngIf="benefitsError()" class="error-text benefits-error">
              {{ benefitsError() }}
            </span>

            <!-- Suggested Benefits -->
            <div class="suggested-benefits">
              <div class="suggested-title">Beneficios sugeridos:</div>
              <div class="suggested-grid">
                <button
                  *ngFor="let benefit of suggestedBenefits"
                  type="button"
                  class="suggested-item"
                  (click)="addSuggestedBenefit(benefit)"
                  [disabled]="isBenefitAdded(benefit)"
                >
                  {{ benefit }}
                </button>
              </div>
            </div>
          </div>

          <!-- Footer -->
          <div class="modal-footer">
            <button type="button" class="btn-secondary" (click)="close()" [disabled]="isSaving()">
              Cancelar
            </button>
            <button
              type="submit"
              class="btn-primary"
              [disabled]="!planForm.valid || !hasValidBenefits() || isSaving()"
              [class.loading]="isSaving()"
            >
              <span *ngIf="!isSaving()">Crear plan</span>
              <span *ngIf="isSaving()">Creando...</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  `,
  styles: [
    `
      .modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(10, 10, 10, 0.55);
        backdrop-filter: blur(5px);
        z-index: 999;
        animation: fadeIn 180ms ease;
      }

      @keyframes fadeIn {
        from {
          opacity: 0;
        }
        to {
          opacity: 1;
        }
      }

      .modal-container {
        position: fixed;
        inset: 0;
        display: grid;
        place-items: center;
        z-index: 1000;
        padding: 1.25rem;
        animation: slideUp 220ms cubic-bezier(0.4, 0, 0.2, 1);
        overflow-y: auto;
      }

      @keyframes slideUp {
        from {
          opacity: 0;
          transform: translateY(20px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }

      .modal-card {
        background: #fff;
        border: 1px solid #e4e4e7;
        border-radius: 10px;
        box-shadow: 0 24px 70px rgba(0, 0, 0, 0.24);
        width: 100%;
        max-width: 860px;
        max-height: calc(100vh - 2.5rem);
        display: flex;
        flex-direction: column;
        overflow: hidden;
      }

      .modal-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1.5rem;
        padding: 2rem 2rem 1rem;
        border-bottom: 0;
      }

      .header-content {
        display: flex;
        gap: 1rem;
        flex: 1;
      }

      .header-icon {
        display: grid;
        place-items: center;
        width: 44px;
        height: 44px;
        border-radius: 8px;
        background: #18181b;
        color: #facc15;
        font-size: 1.5rem;
        flex-shrink: 0;
      }

      .header-text {
        flex: 1;
      }

      .modal-title {
        font-family: Inter, sans-serif;
        font-size: 1.35rem;
        font-weight: 750;
        color: #18181b;
        margin: 0 0 0.35rem;
        letter-spacing: 0;
      }

      .modal-subtitle {
        font-size: 0.9rem;
        color: #71717a;
        margin: 0;
        line-height: 1.5;
      }

      .btn-close {
        display: grid;
        place-items: center;
        width: 36px;
        height: 36px;
        border: none;
        border-radius: 8px;
        border: 1px solid #e4e4e7;
        background: #fafafa;
        color: #52525b;
        cursor: pointer;
        transition: all 200ms ease;
        font-size: 1.2rem;
        padding: 0;
        flex-shrink: 0;
      }

      .btn-close:hover {
        background: #f4f4f5;
        border-color: #d4d4d8;
        color: #18181b;
      }

      .error-message {
        display: flex;
        gap: 1rem;
        padding: 1.25rem 2rem;
        background: #fee2e2;
        border: 1px solid #fecaca;
        margin: 1rem 2rem 0;
        border-radius: 8px;
        color: #991b1b;
      }

      .error-message span {
        font-size: 1.5rem;
        flex-shrink: 0;
        margin-top: -0.25rem;
      }

      .error-message strong {
        display: block;
        margin-bottom: 0.25rem;
      }

      .error-message p {
        margin: 0;
        font-size: 0.9rem;
      }

      .modal-form {
        flex: 1;
        overflow-y: auto;
        padding: 1rem 2rem 1.35rem;
      }

      .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.85rem;
        margin-bottom: 1rem;
      }

      .form-group.full-width {
        grid-column: 1 / -1;
      }

      .form-label {
        display: block;
        font-size: 0.82rem;
        font-weight: 700;
        color: #3f3f46;
        margin-bottom: 0.4rem;
        font-family: Inter, sans-serif;
      }

      .form-input,
      .form-select {
        width: 100%;
        min-height: 2.65rem;
        padding: 0.72rem 0.78rem;
        border: 1px solid #d4d4d8;
        border-radius: 8px;
        font-family: Inter, sans-serif;
        font-size: 0.92rem;
        color: #18181b;
        background: #fff;
        transition: all 200ms ease;
      }

      .form-input::placeholder {
        color: #999;
      }

      .form-input:focus,
      .form-select:focus {
        outline: none;
        border-color: #eab308;
        box-shadow: 0 0 0 3px rgba(234, 179, 8, 0.16);
      }

      .form-input.textarea {
        resize: vertical;
        min-height: 80px;
      }

      .error-text {
        display: block;
        margin-top: 0.4rem;
        font-size: 0.8rem;
        color: #dc2626;
        font-weight: 500;
      }

      .beneficios-section {
        grid-column: 1 / -1;
        margin-bottom: 1rem;
        padding: 1rem;
        background: #fafafa;
        border: 1px solid #e4e4e7;
        border-radius: 10px;
      }

      .beneficios-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
      }

      .beneficios-title {
        font-size: 0.82rem;
        font-weight: 800;
        color: #18181b;
        margin: 0;
        font-family: 'Space Grotesk', sans-serif;
        text-transform: uppercase;
        letter-spacing: 0.05em;
      }

      .beneficios-copy {
        color: #6b7280;
        font-size: 0.82rem;
        margin: 0.2rem 0 0;
      }

      .btn-add-benefit {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.6rem 1rem;
        border: 1px solid #facc15;
        border-radius: 8px;
        background: #ffffff;
        color: #ca8a04;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 200ms ease;
      }

      .btn-add-benefit:hover {
        background: rgba(250, 204, 21, 0.15);
        border-color: #ca8a04;
      }

      .beneficios-list {
        display: grid;
        gap: 0.75rem;
        margin-bottom: 1rem;
      }

      .benefit-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem;
        background: #fff;
        border: 1px solid #e4e4e7;
        border-radius: 8px;
      }

      .benefit-content {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex: 1;
      }

      .benefit-icon {
        color: #facc15;
        font-size: 1.1rem;
        flex-shrink: 0;
      }

      .benefit-input {
        flex: 1;
        border: none;
        background: transparent;
        font-size: 0.9rem;
        color: #0a0a0a;
        padding: 0;
      }

      .benefit-input::placeholder {
        color: #999;
      }

      .benefit-input:focus {
        outline: none;
      }

      .btn-remove-benefit {
        display: grid;
        place-items: center;
        width: 32px;
        height: 32px;
        border: none;
        border-radius: 6px;
        background: transparent;
        color: #dc2626;
        cursor: pointer;
        transition: all 200ms ease;
      }

      .btn-remove-benefit:hover {
        background: #fee2e2;
      }

      .suggested-benefits {
        margin-top: 1rem;
      }

      .benefits-error {
        display: block;
        margin-top: 0.75rem;
      }

      .suggested-title {
        font-size: 0.8rem;
        font-weight: 600;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 0.75rem;
      }

      .suggested-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        gap: 0.5rem;
      }

      .suggested-item {
        padding: 0.6rem 0.75rem;
        border: 1px solid #e5e5e5;
        border-radius: 6px;
        background: #fff;
        color: #666;
        font-size: 0.8rem;
        cursor: pointer;
        transition: all 200ms ease;
        text-align: left;
        font-weight: 500;
      }

      .suggested-item:hover:not(:disabled) {
        border-color: #facc15;
        background: rgba(250, 204, 21, 0.05);
        color: #ca8a04;
      }

      .suggested-item:disabled {
        opacity: 0.5;
        cursor: not-allowed;
      }

      .modal-footer {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 1rem;
        padding: 1rem;
        border-top: 1px solid #e4e4e7;
        background: #f4f4f5;
      }

      .btn-primary,
      .btn-secondary {
        min-height: 2.75rem;
        padding: 0.75rem 1.75rem;
        border-radius: 8px;
        font-family: Inter, sans-serif;
        font-weight: 800;
        font-size: 0.92rem;
        cursor: pointer;
        transition: all 200ms ease;
        border: none;
      }

      .btn-primary {
        background: #facc15;
        color: #000;
        box-shadow: 0 2px 8px rgba(250, 204, 21, 0.2);
      }

      .btn-primary:hover:not(:disabled) {
        background: #f0c00e;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(250, 204, 21, 0.3);
      }

      .btn-primary:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }

      .btn-secondary {
        background: #fff;
        color: #18181b;
        border: 1px solid #d4d4d8;
      }

      .btn-secondary:hover:not(:disabled) {
        border-color: #a0a0a0;
        background: #f5f5f5;
      }

      .btn-secondary:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }

      /* Dark CRM skin */
      .modal-backdrop {
        background: rgba(0, 0, 0, 0.72);
      }

      .modal-card {
        background: #1c1b1b;
        border-color: rgba(245, 197, 24, 0.12);
        color: #e5e2e1;
        box-shadow: 0 24px 70px rgba(0, 0, 0, 0.48);
      }

      .header-icon {
        background: #f5c518;
        color: #241a00;
        box-shadow: 0 0 18px rgba(245, 197, 24, 0.18);
      }

      .modal-title,
      .beneficios-title {
        color: #e5e2e1;
      }

      .modal-subtitle,
      .form-label,
      .beneficios-copy,
      .suggested-title {
        color: #b4afa6;
      }

      .btn-close,
      .btn-secondary,
      .btn-add-benefit,
      .suggested-item {
        background: #1a1a1a;
        border-color: #353534;
        color: #e5e2e1;
      }

      .btn-close:hover,
      .btn-secondary:hover:not(:disabled),
      .btn-add-benefit:hover,
      .suggested-item:hover:not(:disabled) {
        background: #2a2a2a;
        border-color: #f5c518;
        color: #ffe08b;
      }

      .error-message {
        background: rgba(255, 180, 171, 0.12);
        border-color: rgba(255, 180, 171, 0.28);
        color: #ffdad6;
      }

      .form-input,
      .form-select,
      .benefit-input {
        background: #1a1a1a;
        border-color: #353534;
        color: #e5e2e1;
      }

      .form-input::placeholder,
      .benefit-input::placeholder {
        color: #8f8a82;
      }

      .form-input:focus,
      .form-select:focus {
        border-color: #f5c518;
        background: #2a2a2a;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.16);
      }

      .select-shell {
        position: relative;
      }

      .select-shell .form-select {
        padding-left: 2.55rem;
        padding-right: 2.25rem;
        appearance: none;
        background-image:
          linear-gradient(45deg, transparent 50%, #ffe08b 50%),
          linear-gradient(135deg, #ffe08b 50%, transparent 50%);
        background-position:
          calc(100% - 1rem) 50%,
          calc(100% - 0.72rem) 50%;
        background-size:
          0.34rem 0.34rem,
          0.34rem 0.34rem;
        background-repeat: no-repeat;
      }

      .select-icon {
        position: absolute;
        left: 0.78rem;
        top: 50%;
        z-index: 1;
        color: #ffe08b;
        font-size: 1.1rem;
        pointer-events: none;
        transform: translateY(-50%);
      }

      .form-select option {
        background: #201f1f;
        color: #e5e2e1;
      }

      .pretty-select {
        position: relative;
        width: 100%;
      }

      .pretty-select.open {
        z-index: 50;
      }

      .pretty-trigger {
        width: 100%;
        min-height: 2.65rem;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 0.65rem;
        padding: 0.72rem 0.78rem;
        border: 1px solid #353534;
        border-radius: 8px;
        background: #1a1a1a;
        color: #e5e2e1;
        font: 800 0.92rem Inter, sans-serif;
        text-align: left;
        cursor: pointer;
        transition: all 160ms ease;
      }

      .pretty-trigger > span:first-child {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .pretty-trigger:hover,
      .pretty-select.open .pretty-trigger {
        border-color: #f5c518;
        background: #2a2a2a;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.14);
      }

      .select-chevron {
        width: 0.55rem;
        height: 0.55rem;
        border-bottom: 2px solid #ffe08b;
        border-right: 2px solid #ffe08b;
        transform: rotate(45deg) translateY(-1px);
        transition: transform 160ms ease;
      }

      .pretty-select.open .select-chevron {
        transform: rotate(225deg) translateY(-1px);
      }

      .pretty-menu {
        position: absolute;
        top: calc(100% + 0.35rem);
        left: 0;
        right: 0;
        z-index: 3200;
        display: grid;
        gap: 0.2rem;
        max-height: 260px;
        overflow-y: auto;
        padding: 0.45rem;
        border: 1px solid #4e4633;
        border-radius: 10px;
        background: #201f1f;
        box-shadow: 0 18px 42px rgba(0, 0, 0, 0.44);
      }

      .pretty-option {
        min-height: 3.35rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.85rem;
        border: 0;
        border-radius: 8px;
        background: transparent;
        color: #d1c5ac;
        text-align: left;
        padding: 0.62rem 0.7rem;
        cursor: pointer;
        transition: all 140ms ease;
      }

      .pretty-option:hover,
      .pretty-option.selected {
        background: rgba(245, 197, 24, 0.12);
        color: #ffe08b;
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
        background: #2a2a2a;
        color: #f5c518;
        flex-shrink: 0;
        font-size: 1.1rem;
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
        font: 850 0.9rem Inter, sans-serif;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .option-copy small {
        color: #b4afa6;
        font: 650 0.75rem Inter, sans-serif;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
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

      .error-text,
      .btn-remove-benefit {
        color: #ffb4ab;
      }

      .beneficios-section,
      .benefit-item {
        background: #201f1f;
        border-color: #353534;
      }

      .btn-remove-benefit:hover {
        background: rgba(255, 180, 171, 0.12);
      }

      .modal-footer {
        border-top-color: #353534;
        background: #201f1f;
      }

      .btn-primary {
        background: #f5c518;
        color: #241a00;
        box-shadow: 0 0 18px rgba(245, 197, 24, 0.18);
      }

      .btn-primary:hover:not(:disabled) {
        background: #ffd43b;
      }

      /* Responsive */
      @media (max-width: 640px) {
        .modal-container {
          padding: 0.5rem;
        }

        .modal-card {
          border-radius: 10px;
        }

        .modal-header {
          padding: 1.5rem;
          flex-direction: column;
          gap: 1rem;
        }

        .header-content {
          width: 100%;
        }

        .btn-close {
          align-self: flex-start;
        }

        .modal-form {
          padding: 1.5rem;
        }

        .form-grid {
          grid-template-columns: 1fr;
          gap: 1.25rem;
        }

        .suggested-grid {
          grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
        }

        .modal-footer {
          flex-direction: column;
          padding: 1.25rem 1.5rem;
          gap: 0.75rem;
        }

        .btn-primary,
        .btn-secondary {
          width: 100%;
        }
      }
    `,
  ],
})
export class CreatePlanModalComponent implements OnInit {
  @Input() isOpen!: Signal<boolean>;
  @Output() onClose = new EventEmitter<void>();
  @Output() onPlanCreated = new EventEmitter<any>();

  planForm!: FormGroup;
  isSaving = signal(false);
  errorMessage = signal('');
  benefitsError = signal('');
  benefitsList = signal<{ text: string }[]>([]);
  openSelect = signal<PlanSelectControl | null>(null);

  readonly billingCycleOptions: PlanSelectOption[] = [
    { value: '', label: 'Seleccionar...', description: 'Define el ciclo de cobro', icon: 'select_all' },
    { value: 'monthly', label: 'Mensual', description: 'Cobro cada mes', icon: 'calendar_month' },
    { value: 'quarterly', label: 'Trimestral', description: 'Cobro cada 90 días', icon: 'date_range' },
    { value: 'semi_annual', label: 'Semestral', description: 'Cobro cada 180 días', icon: 'event_repeat' },
    { value: 'annual', label: 'Anual', description: 'Cobro una vez al año', icon: 'event_available' },
    { value: 'custom', label: 'Personalizado', description: 'Ciclo definido manualmente', icon: 'tune' },
  ];

  readonly planTypeOptions: PlanSelectOption[] = [
    { value: '', label: 'Seleccionar...', description: 'Elige una categoría', icon: 'select_all' },
    { value: 'general', label: 'General', description: 'Membresía estándar', icon: 'fitness_center' },
    { value: 'vip', label: 'VIP', description: 'Acceso premium', icon: 'workspace_premium' },
    { value: 'student', label: 'Estudiante', description: 'Plan con descuento', icon: 'school' },
    { value: 'promo', label: 'Promocional', description: 'Campañas y ofertas', icon: 'local_offer' },
    { value: 'corporate', label: 'Corporativo', description: 'Empresas o grupos', icon: 'business_center' },
  ];

  readonly statusOptions: PlanSelectOption[] = [
    { value: '', label: 'Seleccionar...', description: 'Sin estado definido', icon: 'help' },
    { value: 'active', label: 'Activo', description: 'Disponible para venta', icon: 'check_circle' },
    { value: 'inactive', label: 'Inactivo', description: 'Oculto o pausado', icon: 'pause_circle' },
  ];

  readonly defaultBenefits = [
    'Acceso al gimnasio durante la vigencia del plan',
    'Reserva de clases grupales disponibles',
    'Acceso a rutinas asignadas en la app móvil',
  ];

  suggestedBenefits = [
    ...this.defaultBenefits,
    'Seguimiento de progreso mensual',
    'Valoración física inicial',
    'Soporte de entrenador en rutinas',
    'Acceso a historial de pagos y vencimiento',
  ];

  constructor(
    private fb: FormBuilder,
    private api: ApiService,
    private elementRef: ElementRef<HTMLElement>,
  ) {
    this.initializeForm();
  }

  @HostListener('document:click', ['$event'])
  closeSelectOnOutsideClick(event: MouseEvent): void {
    if (!this.openSelect()) return;
    if (!this.elementRef.nativeElement.contains(event.target as Node)) {
      this.openSelect.set(null);
    }
  }

  ngOnInit(): void {
    this.initializeForm();
  }

  private initializeForm(): void {
    this.planForm = this.fb.group({
      name: ['', [Validators.required]],
      description: [''],
      price: ['', [Validators.required, Validators.min(1)]],
      duration_days: ['', [Validators.required, Validators.min(1)]],
      billing_cycle: ['', Validators.required],
      plan_type: ['', Validators.required],
      status: ['active', Validators.required],
    });
    this.benefitsList.set(this.defaultBenefits.map((text) => ({ text })));
    this.benefitsError.set('');
  }

  addBenefit(): void {
    this.benefitsList.update((list) => [...list, { text: '' }]);
  }

  toggleSelect(select: PlanSelectControl): void {
    this.openSelect.update((current) => (current === select ? null : select));
  }

  chooseOption(control: PlanSelectControl, value: string): void {
    this.planForm.get(control)?.setValue(value);
    this.openSelect.set(null);
  }

  optionLabel(control: PlanSelectControl): string {
    const value = this.planForm?.get(control)?.value || '';
    const options =
      control === 'billing_cycle'
        ? this.billingCycleOptions
        : control === 'plan_type'
          ? this.planTypeOptions
          : this.statusOptions;
    return options.find((option) => option.value === value)?.label || 'Seleccionar...';
  }

  removeBenefit(index: number): void {
    this.benefitsList.update((list) => list.filter((_, i) => i !== index));
    this.benefitsError.set(
      this.hasValidBenefits() ? '' : 'Debes incluir al menos un beneficio real para el plan.',
    );
  }

  addSuggestedBenefit(benefit: string): void {
    if (!this.isBenefitAdded(benefit)) {
      this.benefitsList.update((list) => [...list, { text: benefit }]);
      this.benefitsError.set('');
    }
  }

  isBenefitAdded(benefit: string): boolean {
    return this.benefitsList().some((b) => b.text === benefit);
  }

  hasValidBenefits(): boolean {
    return this.benefitsList().some((benefit) => benefit.text.trim().length > 0);
  }

  onSubmit(): void {
    if (!this.planForm.valid || !this.hasValidBenefits()) {
      Object.keys(this.planForm.controls).forEach((key) => {
        this.planForm.get(key)?.markAsTouched();
      });
      if (!this.hasValidBenefits()) {
        this.benefitsError.set('Debes incluir al menos un beneficio real para el plan.');
      }
      return;
    }

    this.isSaving.set(true);
    this.errorMessage.set('');

    const formData = this.planForm.value;
    const benefits = this.benefitsList()
      .map((b) => b.text)
      .filter((text) => text.trim());

    const planData = {
      ...formData,
      benefits: benefits.join(', '),
      active: formData.status === 'active',
    };

    this.api.createPlan(planData).subscribe({
      next: (response) => {
        this.isSaving.set(false);
        this.onPlanCreated.emit(response);
        this.close();
      },
      error: (error) => {
        this.isSaving.set(false);
        const message = error?.error?.message || 'No se pudo crear el plan. Intenta de nuevo.';
        this.errorMessage.set(message);
      },
    });
  }

  close(): void {
    if (!this.isSaving()) {
      this.planForm.reset({ status: 'active' });
      this.benefitsList.set(this.defaultBenefits.map((text) => ({ text })));
      this.benefitsError.set('');
      this.errorMessage.set('');
      this.onClose.emit();
    }
  }
}
