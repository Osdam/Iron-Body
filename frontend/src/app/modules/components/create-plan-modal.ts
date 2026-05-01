import { CommonModule } from '@angular/common';
import { Component, Output, EventEmitter, Input, OnInit, signal, Signal } from '@angular/core';
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
              <select formControlName="billing_cycle" class="form-select" aria-required="true">
                <option value="">Seleccionar...</option>
                <option value="monthly">Mensual</option>
                <option value="quarterly">Trimestral</option>
                <option value="semi_annual">Semestral</option>
                <option value="annual">Anual</option>
                <option value="custom">Personalizado</option>
              </select>
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
              <select formControlName="plan_type" class="form-select" aria-required="true">
                <option value="">Seleccionar...</option>
                <option value="general">General</option>
                <option value="vip">VIP</option>
                <option value="student">Estudiante</option>
                <option value="promo">Promocional</option>
                <option value="corporate">Corporativo</option>
              </select>
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
              <select formControlName="status" class="form-select" aria-required="true">
                <option value="">Seleccionar...</option>
                <option value="active">Activo</option>
                <option value="inactive">Inactivo</option>
              </select>
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
              <h3 class="beneficios-title">Beneficios incluidos (Opcional)</h3>
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
                    placeholder="Ej: Acceso libre al gimnasio"
                    aria-label="Beneficio"
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
              [disabled]="!planForm.valid || isSaving()"
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
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(2px);
        z-index: 40;
        animation: fadeIn 200ms ease;
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
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 50;
        padding: 1rem;
        animation: slideUp 300ms cubic-bezier(0.4, 0, 0.2, 1);
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
        border: 1px solid #e5e5e5;
        border-radius: 14px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        width: 100%;
        max-width: 600px;
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
        background: rgba(250, 204, 21, 0.1);
        color: #ca8a04;
        font-size: 1.5rem;
        flex-shrink: 0;
      }

      .header-text {
        flex: 1;
      }

      .modal-title {
        font-family: Inter, sans-serif;
        font-size: 1.5rem;
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
        font-size: 1.2rem;
        padding: 0;
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
      }

      .form-input::placeholder {
        color: #999;
      }

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

      .beneficios-section {
        grid-column: 1 / -1;
        margin-bottom: 2rem;
        padding: 1.5rem;
        background: #f9f9f9;
        border-radius: 10px;
      }

      .beneficios-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
      }

      .beneficios-title {
        font-size: 0.95rem;
        font-weight: 600;
        color: #0a0a0a;
        margin: 0;
        font-family: 'Space Grotesk', sans-serif;
        text-transform: uppercase;
        letter-spacing: 0.05em;
      }

      .btn-add-benefit {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.6rem 1rem;
        border: 1px solid #facc15;
        border-radius: 8px;
        background: rgba(250, 204, 21, 0.05);
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
        border: 1px solid #e5e5e5;
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
  benefitsList = signal<{ text: string }[]>([]);

  suggestedBenefits = [
    'Acceso libre al gimnasio',
    'Clases grupales incluidas',
    'Rutina personalizada',
    'Valoración física',
    'Acceso a lockers',
    'Entrenador asignado',
    'Seguimiento mensual',
  ];

  constructor(
    private fb: FormBuilder,
    private api: ApiService,
  ) {
    this.initializeForm();
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
  }

  addBenefit(): void {
    this.benefitsList.update((list) => [...list, { text: '' }]);
  }

  removeBenefit(index: number): void {
    this.benefitsList.update((list) => list.filter((_, i) => i !== index));
  }

  addSuggestedBenefit(benefit: string): void {
    if (!this.isBenefitAdded(benefit)) {
      this.benefitsList.update((list) => [...list, { text: benefit }]);
    }
  }

  isBenefitAdded(benefit: string): boolean {
    return this.benefitsList().some((b) => b.text === benefit);
  }

  onSubmit(): void {
    if (!this.planForm.valid) {
      Object.keys(this.planForm.controls).forEach((key) => {
        this.planForm.get(key)?.markAsTouched();
      });
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
      this.benefitsList.set([]);
      this.errorMessage.set('');
      this.onClose.emit();
    }
  }
}
