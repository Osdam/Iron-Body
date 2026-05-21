import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, Output, OnInit } from '@angular/core';
import {
  FormBuilder,
  FormGroup,
  FormsModule,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import { DateWheelPickerComponent } from '../../shared/components/date-wheel-picker/date-wheel-picker.component';

export type CouponModalMode = 'create' | 'edit';

export interface Coupon {
  id: string;
  name: string;
  code: string;
  discountType: 'Porcentaje' | 'Valor fijo';
  discountValue: number;
  startDate: string;
  endDate: string;
  usageLimit: number;
  usedCount: number;
  status: 'Activo' | 'Inactivo' | 'Expirado';
  appliesTo: string;
  createdAt: string;
}

@Component({
  selector: 'app-marketing-modal-coupon',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule, DateWheelPickerComponent],
  template: `
    <div *ngIf="isOpen" class="modal-overlay" (click)="onClose()" role="dialog" aria-modal="true">
      <div class="modal-drawer" (click)="$event.stopPropagation()">
        <div class="modal-header">
          <div class="modal-title-section">
            <span class="material-symbols-outlined modal-icon">local_offer</span>
            <div>
              <h2 class="modal-title">{{ mode === 'create' ? 'Crear cupón' : 'Editar cupón' }}</h2>
              <p class="modal-subtitle">Define código, descuento, vigencia y aplicabilidad.</p>
            </div>
          </div>
          <button type="button" class="modal-close" (click)="onClose()" aria-label="Cerrar">
            <span class="material-symbols-outlined">close</span>
          </button>
        </div>

        <div class="modal-content">
          <form [formGroup]="couponForm">
            <fieldset class="form-group">
              <legend>Información básica</legend>

              <div class="form-field">
                <label for="name">Nombre del cupón</label>
                <input
                  type="text"
                  id="name"
                  formControlName="name"
                  placeholder="Ej: Renovación 10%"
                  class="form-input"
                />
              </div>

              <div class="form-field">
                <label for="code">Código</label>
                <input
                  type="text"
                  id="code"
                  formControlName="code"
                  placeholder="Ej: RENUEVA10"
                  class="form-input"
                />
              </div>
            </fieldset>

            <fieldset class="form-group">
              <legend>Descuento</legend>

              <div class="form-row">
                <div class="form-field">
                  <label for="discountType">Tipo de descuento</label>
                  <select id="discountType" formControlName="discountType" class="form-select">
                    <option value="Porcentaje">Porcentaje</option>
                    <option value="Valor fijo">Valor fijo</option>
                  </select>
                </div>

                <div class="form-field">
                  <label for="discountValue">Valor</label>
                  <input
                    type="number"
                    id="discountValue"
                    formControlName="discountValue"
                    placeholder="Ej: 10 o 20000"
                    class="form-input"
                    min="0"
                  />
                </div>
              </div>
            </fieldset>

            <fieldset class="form-group">
              <legend>Vigencia</legend>

              <div class="form-row">
                <div class="form-field">
                  <label for="startDate">Fecha de inicio</label>
                  <app-date-wheel-picker
                    formControlName="startDate"
                    [minYear]="currentYear - 1"
                    [maxYear]="currentYear + 3"
                    size="sm"
                    ariaLabel="Fecha de inicio del cupon"
                  ></app-date-wheel-picker>
                </div>

                <div class="form-field">
                  <label for="endDate">Fecha de fin</label>
                  <app-date-wheel-picker
                    formControlName="endDate"
                    [minYear]="currentYear - 1"
                    [maxYear]="currentYear + 3"
                    size="sm"
                    ariaLabel="Fecha de fin del cupon"
                  ></app-date-wheel-picker>
                </div>
              </div>

              <div class="form-field">
                <label for="usageLimit">Límite de usos</label>
                <input
                  type="number"
                  id="usageLimit"
                  formControlName="usageLimit"
                  placeholder="Ej: 100"
                  class="form-input"
                  min="1"
                />
              </div>
            </fieldset>

            <fieldset class="form-group">
              <legend>Aplicabilidad y estado</legend>

              <div class="form-field">
                <label for="appliesTo">Aplicable a</label>
                <select id="appliesTo" formControlName="appliesTo" class="form-select">
                  <option value="Todos los planes">Todos los planes</option>
                  <option value="Plan Mensual">Plan Mensual</option>
                  <option value="Plan Trimestral">Plan Trimestral</option>
                  <option value="Plan Semestral">Plan Semestral</option>
                  <option value="Plan Anual">Plan Anual</option>
                  <option value="Plan VIP">Plan VIP</option>
                </select>
              </div>

              <div class="form-field">
                <label for="status">Estado</label>
                <select id="status" formControlName="status" class="form-select">
                  <option value="Activo">Activo</option>
                  <option value="Inactivo">Inactivo</option>
                  <option value="Expirado">Expirado</option>
                </select>
              </div>
            </fieldset>
          </form>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn-secondary" (click)="onClose()" [disabled]="isSaving">
            Cancelar
          </button>
          <button
            type="button"
            class="btn-primary"
            (click)="onSubmit()"
            [disabled]="!couponForm.valid || isSaving"
          >
            <span *ngIf="!isSaving">{{ mode === 'create' ? 'Crear cupón' : 'Guardar cupón' }}</span>
            <span *ngIf="isSaving" class="loading">Guardando...</span>
          </button>
        </div>
      </div>
    </div>
  `,
  styles: [
    `
      .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.4);
        z-index: 50;
        display: flex;
        justify-content: flex-end;
      }

      .modal-drawer {
        width: 100%;
        max-width: 500px;
        height: 100vh;
        background: #ffffff;
        box-shadow: -4px 0 16px rgba(0, 0, 0, 0.12);
        display: flex;
        flex-direction: column;
        animation: slideIn 0.25s ease;
      }

      @keyframes slideIn {
        from {
          transform: translateX(100%);
          opacity: 0;
        }
        to {
          transform: translateX(0);
          opacity: 1;
        }
      }

      .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        padding: 1.6rem;
        border-bottom: 1px solid #ededed;
      }

      .modal-title-section {
        display: flex;
        gap: 1rem;
      }

      .modal-icon {
        font-size: 1.8rem;
        color: #fbbf24;
        flex-shrink: 0;
      }

      .modal-title {
        font-size: 1.25rem;
        font-weight: 900;
        color: #0a0a0a;
        margin: 0;
      }

      .modal-subtitle {
        font-size: 0.85rem;
        color: #666;
        margin: 0.4rem 0 0;
      }

      .modal-close {
        width: 36px;
        height: 36px;
        border: none;
        border-radius: 10px;
        background: #f3f3f3;
        color: #666;
        cursor: pointer;
        transition: all 0.15s ease;
        flex-shrink: 0;
      }

      .modal-close:hover {
        background: #e5e5e5;
        color: #0a0a0a;
      }

      .modal-content {
        flex: 1;
        overflow-y: auto;
        padding: 1.6rem;
      }

      .form-group {
        margin-bottom: 1.8rem;
        border: none;
        padding: 0;
      }

      .form-group legend {
        font-size: 0.8rem;
        font-weight: 800;
        color: #0a0a0a;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin: 0 0 0.9rem;
        padding: 0;
      }

      .form-field {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
        margin-bottom: 1rem;
      }

      .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-bottom: 1rem;
      }

      label {
        font-size: 0.85rem;
        font-weight: 700;
        color: #0a0a0a;
      }

      .form-input,
      .form-select {
        padding: 0.75rem;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        font-size: 0.9rem;
        color: #0a0a0a;
        font-weight: 500;
        transition: all 0.15s ease;
      }

      .form-input::placeholder {
        color: #bbb;
      }

      .form-input:focus,
      .form-select:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.1);
      }

      .modal-footer {
        display: flex;
        gap: 0.8rem;
        padding: 1.4rem 1.6rem;
        border-top: 1px solid #ededed;
        background: #fafafa;
      }

      .btn-secondary,
      .btn-primary {
        flex: 1;
        padding: 0.8rem;
        border-radius: 10px;
        font-size: 0.9rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.15s ease;
        border: none;
      }

      .btn-secondary {
        background: #ffffff;
        color: #0a0a0a;
        border: 1px solid #e5e5e5;
      }

      .btn-secondary:hover:not(:disabled) {
        background: #f9f9f9;
        border-color: #d0d0d0;
      }

      .btn-primary {
        background: #fbbf24;
        color: #0a0a0a;
        box-shadow: 0 6px 12px rgba(251, 191, 36, 0.15);
      }

      .btn-primary:hover:not(:disabled) {
        background: #f9a825;
        box-shadow: 0 8px 16px rgba(251, 191, 36, 0.2);
      }

      .btn-primary:disabled,
      .btn-secondary:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }

      .loading {
        animation: pulse 0.8s infinite;
      }

      .modal-overlay {
        background: rgba(0, 0, 0, 0.68);
      }

      .modal-drawer {
        background:
          linear-gradient(rgba(28, 27, 27, 0.95), rgba(17, 17, 17, 0.94)),
          url('/assets/crm/clases2.png') center / cover no-repeat;
        border-left: 1px solid #353534;
        color: #e5e2e1;
        box-shadow: -16px 0 48px rgba(0, 0, 0, 0.58);
      }

      .modal-header,
      .modal-footer {
        background:
          linear-gradient(135deg, rgba(245, 197, 24, 0.14), rgba(28, 27, 27, 0.94)),
          #1c1b1b;
        border-color: #353534;
      }

      .modal-title,
      .form-group legend,
      label {
        color: #e5e2e1;
      }

      .modal-subtitle {
        color: #b4afa6;
      }

      .modal-close,
      .btn-secondary {
        background: #1c1b1b;
        border: 1px solid #353534;
        color: #e5e2e1;
      }

      .modal-close:hover,
      .btn-secondary:hover:not(:disabled) {
        background: #201f1f;
        border-color: #f5c518;
        color: #ffe08b;
      }

      .form-input,
      .form-select {
        background: #151515;
        border-color: #353534;
        color: #e5e2e1;
        color-scheme: dark;
      }

      .form-select option {
        background: #151515;
        color: #e5e2e1;
      }

      .form-input::placeholder {
        color: #77716a;
      }

      .form-input:focus,
      .form-select:focus {
        background: #201f1f;
        border-color: #f5c518;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.13);
      }

      @keyframes pulse {
        0%,
        100% {
          opacity: 1;
        }
        50% {
          opacity: 0.6;
        }
      }

      @media (max-width: 640px) {
        .modal-drawer {
          max-width: 100%;
        }
      }
    `,
  ],
})
export default class MarketingModalCouponComponent implements OnInit {
  @Input() isOpen = false;
  @Input() mode: CouponModalMode = 'create';
  @Input() coupon: Coupon | null = null;
  @Input() isSaving = false;

  @Output() close = new EventEmitter<void>();
  @Output() save = new EventEmitter<Partial<Coupon>>();

  couponForm!: FormGroup;
  currentYear = new Date().getFullYear();

  constructor(private fb: FormBuilder) {}

  ngOnInit(): void {
    this.buildForm();
  }

  ngOnChanges(): void {
    this.buildForm();
  }

  buildForm(): void {
    const now = new Date().toISOString().split('T')[0];
    this.couponForm = this.fb.group({
      name: [this.coupon?.name || '', Validators.required],
      code: [this.coupon?.code || '', Validators.required],
      discountType: [this.coupon?.discountType || 'Porcentaje', Validators.required],
      discountValue: [this.coupon?.discountValue || '', [Validators.required, Validators.min(1)]],
      startDate: [this.coupon?.startDate || now, Validators.required],
      endDate: [this.coupon?.endDate || now, Validators.required],
      usageLimit: [this.coupon?.usageLimit || 100, [Validators.required, Validators.min(1)]],
      status: [this.coupon?.status || 'Activo', Validators.required],
      appliesTo: [this.coupon?.appliesTo || 'Todos los planes', Validators.required],
    });
  }

  onClose(): void {
    this.close.emit();
  }

  onSubmit(): void {
    if (!this.couponForm.valid) return;
    this.save.emit(this.couponForm.value);
  }
}
