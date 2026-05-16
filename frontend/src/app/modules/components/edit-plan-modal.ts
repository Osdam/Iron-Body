import { CommonModule } from '@angular/common';
import {
  Component,
  Output,
  EventEmitter,
  Input,
  OnChanges,
  SimpleChanges,
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
import { ApiService, PlanSummary } from '../../services/api.service';
import { PlanCardData } from './plan-card';

@Component({
  selector: 'app-edit-plan-modal',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule],
  template: `
    <div *ngIf="isOpen()" class="modal-backdrop" (click)="close()" aria-hidden="true"></div>

    <div *ngIf="isOpen()" class="modal-container" role="dialog" aria-modal="true">
      <div class="modal-card">
        <div class="modal-header">
          <div class="header-content">
            <div class="header-icon">
              <span class="material-symbols-outlined" aria-hidden="true">edit</span>
            </div>
            <div class="header-text">
              <h2 class="modal-title">Editar plan</h2>
              <p class="modal-subtitle">Modifica la información del plan de membresía.</p>
            </div>
          </div>
          <button class="btn-close" (click)="close()" aria-label="Cerrar modal">
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
          </button>
        </div>

        <div *ngIf="errorMessage()" class="error-message">
          <span class="material-symbols-outlined" aria-hidden="true">error</span>
          <div>
            <strong>Error al actualizar</strong>
            <p>{{ errorMessage() }}</p>
          </div>
        </div>

        <form [formGroup]="planForm" (ngSubmit)="onSubmit()" class="modal-form">
          <div class="form-grid">
            <div class="form-group full-width">
              <label for="edit-name" class="form-label">Nombre del plan *</label>
              <input
                id="edit-name"
                type="text"
                formControlName="name"
                class="form-input"
                placeholder="Ej: Plan Mensual, Plan VIP"
              />
              <span
                *ngIf="planForm.get('name')?.hasError('required') && planForm.get('name')?.touched"
                class="error-text"
              >
                El nombre es obligatorio
              </span>
            </div>

            <div class="form-group full-width">
              <label for="edit-benefits" class="form-label">Descripción / Beneficios *</label>
              <textarea
                id="edit-benefits"
                formControlName="benefits"
                class="form-input textarea"
                placeholder="Ej: Reserva de clases, acceso a rutinas asignadas..."
                rows="3"
              ></textarea>
              <span
                *ngIf="
                  planForm.get('benefits')?.hasError('required') &&
                  planForm.get('benefits')?.touched
                "
                class="error-text"
              >
                Los beneficios son obligatorios
              </span>
            </div>

            <div class="form-group">
              <label for="edit-price" class="form-label">Precio en COP *</label>
              <input
                id="edit-price"
                type="number"
                formControlName="price"
                class="form-input"
                placeholder="Ej: 80000"
                min="1"
              />
              <span
                *ngIf="planForm.get('price')?.hasError('required') && planForm.get('price')?.touched"
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

            <div class="form-group">
              <label for="edit-duration" class="form-label">Duración en días *</label>
              <input
                id="edit-duration"
                type="number"
                formControlName="duration_days"
                class="form-input"
                placeholder="Ej: 30, 90, 180, 365"
                min="1"
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
            </div>

            <div class="form-group">
              <label for="edit-status" class="form-label">Estado *</label>
              <select id="edit-status" formControlName="active" class="form-select">
                <option [ngValue]="true">Activo</option>
                <option [ngValue]="false">Inactivo</option>
              </select>
            </div>

            <div class="form-group">
              <label for="edit-access" class="form-label">Acceso a clases</label>
              <select id="edit-access" formControlName="access_classes" class="form-select">
                <option [ngValue]="true">Incluido</option>
                <option [ngValue]="false">No incluido</option>
              </select>
            </div>

            <div class="form-group">
              <label for="edit-reservations" class="form-label">Límite de reservas</label>
              <input
                id="edit-reservations"
                type="number"
                formControlName="reservations_limit"
                class="form-input"
                placeholder="Dejar vacío para ilimitado"
                min="0"
              />
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn-secondary" (click)="close()" [disabled]="isSaving()">
              Cancelar
            </button>
            <button
              type="submit"
              class="btn-primary"
              [disabled]="!planForm.valid || isSaving()"
            >
              <span *ngIf="!isSaving()">
                <span class="material-symbols-outlined" style="font-size:1rem;vertical-align:middle">save</span>
                Guardar cambios
              </span>
              <span *ngIf="isSaving()">Guardando...</span>
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
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(2px);
        z-index: 40;
        animation: fadeIn 200ms ease;
      }

      @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
      }

      .modal-container {
        position: fixed;
        inset: 0;
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
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
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
        padding: 2rem;
        border-bottom: 1px solid #f0f0f0;
      }

      .header-content {
        display: flex;
        gap: 1rem;
        flex: 1;
      }

      .header-icon {
        display: grid;
        place-items: center;
        width: 48px;
        height: 48px;
        border-radius: 10px;
        background: #0a0a0a;
        color: #facc15;
        font-size: 1.5rem;
        flex-shrink: 0;
      }

      .header-text { flex: 1; }

      .modal-title {
        font-family: Inter, sans-serif;
        font-size: 1.4rem;
        font-weight: 700;
        color: #0a0a0a;
        margin: 0 0 0.4rem;
        letter-spacing: -0.01em;
      }

      .modal-subtitle {
        font-size: 0.9rem;
        color: #666;
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
        background: #f5f5f5;
        color: #666;
        cursor: pointer;
        transition: all 200ms ease;
        flex-shrink: 0;
      }

      .btn-close:hover {
        background: #e8e8e8;
        color: #0a0a0a;
      }

      .error-message {
        display: flex;
        gap: 1rem;
        padding: 1.25rem 2rem;
        background: #fee2e2;
        border: 1px solid #fecaca;
        margin: 1.5rem 2rem 0;
        border-radius: 10px;
        color: #991b1b;
      }

      .error-message span.material-symbols-outlined {
        font-size: 1.5rem;
        flex-shrink: 0;
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
        padding: 2rem;
      }

      .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.5rem;
        margin-bottom: 2rem;
      }

      .form-group.full-width {
        grid-column: 1 / -1;
      }

      .form-label {
        display: block;
        font-size: 0.9rem;
        font-weight: 600;
        color: #0a0a0a;
        margin-bottom: 0.5rem;
        font-family: Inter, sans-serif;
      }

      .form-input,
      .form-select {
        width: 100%;
        padding: 0.875rem;
        border: 1px solid #e5e5e5;
        border-radius: 8px;
        font-family: Inter, sans-serif;
        font-size: 0.95rem;
        color: #0a0a0a;
        background: #fff;
        transition: all 200ms ease;
        box-sizing: border-box;
      }

      .form-input::placeholder { color: #999; }

      .form-input:focus,
      .form-select:focus {
        outline: none;
        border-color: #facc15;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.1);
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

      .modal-footer {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 1rem;
        padding: 1.5rem 2rem;
        border-top: 1px solid #f0f0f0;
        background: #f9f9f9;
      }

      .btn-primary,
      .btn-secondary {
        padding: 0.875rem 1.75rem;
        border-radius: 8px;
        font-family: Inter, sans-serif;
        font-weight: 600;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 200ms ease;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
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
        color: #0a0a0a;
        border: 1.5px solid #d0d0d0;
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
        backdrop-filter: blur(6px);
        z-index: 10000;
      }

      .modal-container {
        z-index: 10001;
      }

      .modal-card {
        background:
          radial-gradient(circle at 82% 0%, rgba(245, 197, 24, 0.1), transparent 34%),
          linear-gradient(145deg, #1c1b1b 0%, #111111 100%);
        border: 1px solid rgba(245, 197, 24, 0.16);
        box-shadow:
          inset 0 -18px 24px rgba(255, 255, 255, 0.04),
          0 28px 70px rgba(0, 0, 0, 0.58);
        color: #e5e2e1;
      }

      .modal-header {
        border-bottom-color: #353534;
        background: rgba(14, 14, 14, 0.52);
      }

      .header-icon {
        background: #f5c518;
        color: #241a00;
        box-shadow: 0 0 18px rgba(245, 197, 24, 0.18);
      }

      .modal-title,
      .form-label {
        color: #e5e2e1;
      }

      .modal-subtitle {
        color: #b4afa6;
      }

      .btn-close {
        background: #2a2a2a;
        border: 1px solid #353534;
        color: #d1c5ac;
      }

      .btn-close:hover {
        background: rgba(245, 197, 24, 0.12);
        border-color: rgba(245, 197, 24, 0.35);
        color: #ffe08b;
      }

      .modal-form {
        background: transparent;
      }

      .form-input,
      .form-select {
        background: #151515;
        border-color: #353534;
        color: #e5e2e1;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
      }

      .form-input::placeholder {
        color: #77716a;
      }

      .form-input:focus,
      .form-select:focus {
        border-color: #f5c518;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.14);
      }

      .form-select {
        color-scheme: dark;
        cursor: pointer;
      }

      .form-select option {
        background: #151515;
        color: #e5e2e1;
      }

      .modal-footer {
        border-top-color: #353534;
        background: #151515;
      }

      .btn-secondary {
        background: #201f1f;
        border-color: #353534;
        color: #d1c5ac;
      }

      .btn-secondary:hover:not(:disabled) {
        background: #2a2a2a;
        border-color: rgba(245, 197, 24, 0.35);
        color: #ffe08b;
      }

      .btn-primary {
        background: #f5c518;
        color: #241a00;
        box-shadow: 0 0 18px rgba(245, 197, 24, 0.16);
      }

      .btn-primary:hover:not(:disabled) {
        background: #ffd43b;
      }

      .error-message {
        background: rgba(147, 0, 10, 0.28);
        border-color: rgba(255, 180, 171, 0.3);
        color: #ffdad6;
      }

      .error-text {
        color: #ffb4ab;
      }

      @media (max-width: 640px) {
        .modal-container { padding: 0.5rem; }
        .modal-card { border-radius: 10px; }
        .modal-header { padding: 1.5rem; }
        .modal-form { padding: 1.5rem; }
        .form-grid { grid-template-columns: 1fr; gap: 1.25rem; }
        .modal-footer {
          flex-direction: column;
          padding: 1.25rem 1.5rem;
          gap: 0.75rem;
        }
        .btn-primary,
        .btn-secondary { width: 100%; justify-content: center; }
      }
    `,
  ],
})
export class EditPlanModalComponent implements OnChanges {
  @Input() isOpen!: Signal<boolean>;
  @Input() plan: PlanCardData | null = null;
  @Output() onClose = new EventEmitter<void>();
  @Output() onPlanUpdated = new EventEmitter<PlanSummary>();

  planForm!: FormGroup;
  isSaving = signal(false);
  errorMessage = signal('');
  private readonly defaultBenefits = [
    'Acceso al gimnasio durante la vigencia del plan',
    'Reserva de clases grupales disponibles',
    'Acceso a rutinas asignadas en la app móvil',
  ].join(', ');

  constructor(
    private fb: FormBuilder,
    private api: ApiService,
  ) {
    this.planForm = this.fb.group({
      name: ['', Validators.required],
      benefits: [this.defaultBenefits, Validators.required],
      price: ['', [Validators.required, Validators.min(1)]],
      duration_days: ['', [Validators.required, Validators.min(1)]],
      active: [true, Validators.required],
      access_classes: [true],
      reservations_limit: [null],
    });
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['plan'] && this.plan) {
      this.errorMessage.set('');
      this.planForm.patchValue({
        name: this.plan.name || '',
        benefits: this.plan.benefits || this.defaultBenefits,
        price: this.plan.price || '',
        duration_days: this.plan.duration_days || '',
        active: this.plan.active ?? true,
        access_classes: true,
        reservations_limit: null,
      });
    }
  }

  onSubmit(): void {
    const benefitsControl = this.planForm.get('benefits');
    if (!String(benefitsControl?.value || '').trim()) {
      benefitsControl?.setErrors({ required: true });
    }

    if (!this.planForm.valid) {
      Object.keys(this.planForm.controls).forEach((key) =>
        this.planForm.get(key)?.markAsTouched(),
      );
      return;
    }

    if (!this.plan?.id) return;

    this.isSaving.set(true);
    this.errorMessage.set('');

    const val = this.planForm.value;
    const data = {
      name: val.name,
      benefits: String(val.benefits || '').trim(),
      price: Number(val.price),
      duration_days: Number(val.duration_days),
      active: val.active,
      access_classes: val.access_classes,
      reservations_limit: val.reservations_limit ? Number(val.reservations_limit) : null,
    };

    this.api.updatePlan(this.plan.id, data as any).subscribe({
      next: (updated) => {
        this.isSaving.set(false);
        this.onPlanUpdated.emit(updated);
        this.close();
      },
      error: (err) => {
        this.isSaving.set(false);
        const message = err?.error?.message || 'No se pudo actualizar el plan. Intenta de nuevo.';
        this.errorMessage.set(message);
      },
    });
  }

  close(): void {
    if (!this.isSaving()) {
      this.errorMessage.set('');
      this.onClose.emit();
    }
  }
}
