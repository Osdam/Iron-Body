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

export interface MemberFormData {
  fullName: string;
  document: string;
  phone: string;
  email: string;
  birthDate?: string;
  gender: string;
  address?: string;
  plan: string;
  membershipStartDate?: string;
  membershipEndDate?: string;
  status: string;
  emergencyContact?: string;
  notes?: string;
  weight?: number;
  height?: number;
  fitnessGoal?: string;
  medicalConditions?: string;
  assignedTrainer?: string;
}

@Component({
  selector: 'app-create-member-modal',
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
              <span class="material-symbols-outlined" aria-hidden="true">person_add</span>
            </div>
            <div class="header-text">
              <h2 class="modal-title">Registrar nuevo miembro</h2>
              <p class="modal-subtitle">
                Agrega los datos personales, contacto y membresía del cliente.
              </p>
            </div>
          </div>
          <button
            class="btn-close"
            (click)="close()"
            aria-label="Cerrar modal"
            [disabled]="isSaving()"
          >
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
          </button>
        </div>

        <!-- Error Message -->
        <div *ngIf="errorMessage()" class="error-message">
          <span class="material-symbols-outlined" aria-hidden="true">error</span>
          <div>
            <strong>Error al registrar miembro</strong>
            <p>{{ errorMessage() }}</p>
          </div>
        </div>

        <!-- Success Message -->
        <div *ngIf="successMessage()" class="success-message">
          <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
          <div>
            <strong>Miembro registrado exitosamente</strong>
            <p>{{ successMessage() }}</p>
          </div>
        </div>

        <!-- Form Content -->
        <form [formGroup]="memberForm" (ngSubmit)="onSubmit()" class="modal-form">
          <!-- Section: Personal Information -->
          <div class="form-section">
            <h3 class="section-title">Información Personal</h3>

            <div class="form-grid">
              <!-- Nombre Completo -->
              <div class="form-group full-width">
                <label for="fullName" class="form-label">Nombre completo *</label>
                <input
                  id="fullName"
                  type="text"
                  formControlName="fullName"
                  class="form-input"
                  placeholder="Ej: Alejandro Gómez"
                  aria-required="true"
                />
                <span
                  *ngIf="
                    memberForm.get('fullName')?.hasError('required') &&
                    memberForm.get('fullName')?.touched
                  "
                  class="error-text"
                >
                  El nombre es obligatorio
                </span>
              </div>

              <!-- Documento -->
              <div class="form-group">
                <label for="document" class="form-label">Documento / Cédula *</label>
                <input
                  id="document"
                  type="text"
                  formControlName="document"
                  class="form-input"
                  placeholder="Ej: 1020304050"
                  aria-required="true"
                />
                <span
                  *ngIf="
                    memberForm.get('document')?.hasError('required') &&
                    memberForm.get('document')?.touched
                  "
                  class="error-text"
                >
                  El documento es obligatorio
                </span>
              </div>

              <!-- Teléfono -->
              <div class="form-group">
                <label for="phone" class="form-label">Teléfono *</label>
                <input
                  id="phone"
                  type="tel"
                  formControlName="phone"
                  class="form-input"
                  placeholder="Ej: 3001234567"
                  aria-required="true"
                />
                <span
                  *ngIf="
                    memberForm.get('phone')?.hasError('required') &&
                    memberForm.get('phone')?.touched
                  "
                  class="error-text"
                >
                  El teléfono es obligatorio
                </span>
              </div>

              <!-- Correo -->
              <div class="form-group">
                <label for="email" class="form-label">Correo electrónico</label>
                <input
                  id="email"
                  type="email"
                  formControlName="email"
                  class="form-input"
                  placeholder="Ej: cliente@correo.com"
                />
                <span
                  *ngIf="
                    memberForm.get('email')?.hasError('email') && memberForm.get('email')?.touched
                  "
                  class="error-text"
                >
                  Ingresa un correo válido
                </span>
              </div>

              <!-- Fecha de Nacimiento -->
              <div class="form-group">
                <label for="birthDate" class="form-label">Fecha de nacimiento</label>
                <input id="birthDate" type="date" formControlName="birthDate" class="form-input" />
              </div>

              <!-- Género -->
              <div class="form-group">
                <label for="gender" class="form-label">Género</label>
                <select formControlName="gender" class="form-select">
                  <option value="">Seleccionar...</option>
                  <option value="male">Masculino</option>
                  <option value="female">Femenino</option>
                  <option value="other">Otro</option>
                  <option value="prefer_not">Prefiero no decir</option>
                </select>
              </div>

              <!-- Dirección -->
              <div class="form-group full-width">
                <label for="address" class="form-label">Dirección</label>
                <input
                  id="address"
                  type="text"
                  formControlName="address"
                  class="form-input"
                  placeholder="Ej: Calle 10 # 20-30"
                />
              </div>
            </div>
          </div>

          <!-- Section: Membership Information -->
          <div class="form-section">
            <h3 class="section-title">Información de Membresía</h3>

            <div class="form-grid">
              <!-- Plan de Membresía -->
              <div class="form-group">
                <label for="plan" class="form-label">Plan de membresía</label>
                <select formControlName="plan" class="form-select">
                  <option value="">Sin plan</option>
                  <option value="monthly">Plan Mensual</option>
                  <option value="quarterly">Plan Trimestral</option>
                  <option value="semi_annual">Plan Semestral</option>
                  <option value="annual">Plan Anual</option>
                  <option value="vip">Plan VIP</option>
                </select>
              </div>

              <!-- Fecha de Inicio -->
              <div class="form-group">
                <label for="membershipStartDate" class="form-label">Fecha de inicio</label>
                <input
                  id="membershipStartDate"
                  type="date"
                  formControlName="membershipStartDate"
                  class="form-input"
                />
              </div>

              <!-- Fecha de Vencimiento -->
              <div class="form-group">
                <label for="membershipEndDate" class="form-label">Fecha de vencimiento</label>
                <input
                  id="membershipEndDate"
                  type="date"
                  formControlName="membershipEndDate"
                  class="form-input"
                />
              </div>

              <!-- Estado del Miembro -->
              <div class="form-group">
                <label for="status" class="form-label">Estado *</label>
                <select formControlName="status" class="form-select" aria-required="true">
                  <option value="">Seleccionar...</option>
                  <option value="active">Activo</option>
                  <option value="inactive">Inactivo</option>
                  <option value="pending">Pendiente</option>
                  <option value="expired">Vencido</option>
                </select>
                <span
                  *ngIf="
                    memberForm.get('status')?.hasError('required') &&
                    memberForm.get('status')?.touched
                  "
                  class="error-text"
                >
                  El estado es obligatorio
                </span>
              </div>
            </div>
          </div>

          <!-- Section: Additional Information -->
          <div class="form-section">
            <h3 class="section-title">Información Adicional</h3>

            <div class="form-grid">
              <!-- Contacto de Emergencia -->
              <div class="form-group full-width">
                <label for="emergencyContact" class="form-label">Contacto de emergencia</label>
                <input
                  id="emergencyContact"
                  type="text"
                  formControlName="emergencyContact"
                  class="form-input"
                  placeholder="Nombre y teléfono de contacto"
                />
              </div>

              <!-- Peso y Estatura -->
              <div class="form-group">
                <label for="weight" class="form-label">Peso (kg)</label>
                <input
                  id="weight"
                  type="number"
                  formControlName="weight"
                  class="form-input"
                  placeholder="Ej: 75"
                  min="0"
                  step="0.1"
                />
              </div>

              <div class="form-group">
                <label for="height" class="form-label">Estatura (cm)</label>
                <input
                  id="height"
                  type="number"
                  formControlName="height"
                  class="form-input"
                  placeholder="Ej: 180"
                  min="0"
                  step="0.1"
                />
              </div>

              <!-- Objetivo Fitness -->
              <div class="form-group">
                <label for="fitnessGoal" class="form-label">Objetivo fitness</label>
                <input
                  id="fitnessGoal"
                  type="text"
                  formControlName="fitnessGoal"
                  class="form-input"
                  placeholder="Ej: Ganar masa muscular"
                />
              </div>

              <!-- Condiciones Médicas -->
              <div class="form-group full-width">
                <label for="medicalConditions" class="form-label">Condiciones médicas</label>
                <textarea
                  id="medicalConditions"
                  formControlName="medicalConditions"
                  class="form-input textarea"
                  placeholder="Lesiones, alergias, restricciones..."
                  rows="3"
                ></textarea>
              </div>

              <!-- Observaciones -->
              <div class="form-group full-width">
                <label for="notes" class="form-label">Observaciones internas</label>
                <textarea
                  id="notes"
                  formControlName="notes"
                  class="form-input textarea"
                  placeholder="Notas internas del gimnasio"
                  rows="3"
                ></textarea>
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
              [disabled]="!memberForm.valid || isSaving()"
              [class.loading]="isSaving()"
            >
              <span *ngIf="!isSaving()">Registrar miembro</span>
              <span *ngIf="isSaving()">Registrando...</span>
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
        max-width: 700px;
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

      .btn-close:hover:not(:disabled) {
        background: #e8e8e8;
        color: #0a0a0a;
      }

      .btn-close:disabled {
        opacity: 0.5;
        cursor: not-allowed;
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

      .success-message {
        display: flex;
        gap: 1rem;
        padding: 1.25rem 2rem;
        background: #dcfce7;
        border: 1px solid #bbf7d0;
        margin: 1.5rem 2rem 0;
        border-radius: 10px;
        color: #166534;
      }

      .success-message span {
        font-size: 1.5rem;
        flex-shrink: 0;
        margin-top: -0.25rem;
        color: #22c55e;
      }

      .success-message strong {
        display: block;
        margin-bottom: 0.25rem;
      }

      .success-message p {
        margin: 0;
        font-size: 0.9rem;
      }

      .modal-form {
        flex: 1;
        overflow-y: auto;
        padding: 2rem;
      }

      .form-section {
        margin-bottom: 2.5rem;
      }

      .section-title {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 0.85rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: #0a0a0a;
        margin: 0 0 1.25rem;
        padding-bottom: 0.75rem;
        border-bottom: 2px solid #f0f0f0;
      }

      .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.5rem;
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
          max-height: 95vh;
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

        .form-section {
          margin-bottom: 1.75rem;
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
export class CreateMemberModalComponent implements OnInit {
  @Input() isOpen!: Signal<boolean>;
  @Output() onClose = new EventEmitter<void>();
  @Output() onMemberCreated = new EventEmitter<any>();

  memberForm!: FormGroup;
  isSaving = signal(false);
  errorMessage = signal('');
  successMessage = signal('');

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
    this.memberForm = this.fb.group({
      fullName: ['', [Validators.required]],
      document: ['', [Validators.required]],
      phone: ['', [Validators.required]],
      email: ['', [Validators.email]],
      birthDate: [''],
      gender: [''],
      address: [''],
      plan: [''],
      membershipStartDate: [''],
      membershipEndDate: [''],
      status: ['active', Validators.required],
      emergencyContact: [''],
      notes: [''],
      weight: [''],
      height: [''],
      fitnessGoal: [''],
      medicalConditions: [''],
      assignedTrainer: [''],
    });
  }

  onSubmit(): void {
    if (!this.memberForm.valid) {
      Object.keys(this.memberForm.controls).forEach((key) => {
        this.memberForm.get(key)?.markAsTouched();
      });
      return;
    }

    this.isSaving.set(true);
    this.errorMessage.set('');
    this.successMessage.set('');

    const formData = this.memberForm.value;

    this.api.createMember(formData).subscribe({
      next: (response) => {
        this.isSaving.set(false);
        this.successMessage.set(
          `${formData.fullName} ha sido registrado correctamente.`,
        );
        setTimeout(() => {
          this.onMemberCreated.emit(response);
          this.close();
        }, 900);
      },
      error: (error) => {
        this.isSaving.set(false);
        const message =
          error?.error?.message ||
          (error?.status === 422
            ? 'Datos inválidos. Revisa los campos del formulario.'
            : 'No se pudo registrar el miembro. Intenta de nuevo.');
        this.errorMessage.set(message);
      },
    });
  }

  close(): void {
    if (!this.isSaving()) {
      this.memberForm.reset({ status: 'active' });
      this.errorMessage.set('');
      this.successMessage.set('');
      this.onClose.emit();
    }
  }
}
