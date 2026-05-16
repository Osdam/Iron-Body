import { CommonModule } from '@angular/common';
import { Component, Input, Output, EventEmitter, OnChanges, OnInit, signal, Signal } from '@angular/core';
import {
  ReactiveFormsModule,
  FormsModule,
  FormBuilder,
  FormGroup,
  Validators,
} from '@angular/forms';
import { ApiService, ClassSummary } from '../../services/api.service';
import { DateWheelPickerComponent } from '../../shared/components/date-wheel-picker/date-wheel-picker.component';

@Component({
  selector: 'app-create-class-modal',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule, DateWheelPickerComponent],
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
              <span class="material-symbols-outlined" aria-hidden="true">school</span>
            </div>
            <div class="header-text">
              <h2 class="modal-title">{{ classToEdit ? 'Editar clase' : 'Crear nueva clase' }}</h2>
              <p class="modal-subtitle">
                Define horario, entrenador, cupos y configuración de la clase.
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
            <strong>{{ classToEdit ? 'Error al editar clase' : 'Error al crear clase' }}</strong>
            <p>{{ errorMessage() }}</p>
          </div>
        </div>

        <!-- Form Content -->
        <form [formGroup]="classForm" (ngSubmit)="onSubmit()" class="modal-form">
          <!-- Section: Basic Information -->
          <div class="form-section">
            <h3 class="section-title">Información Básica</h3>

            <div class="form-grid">
              <!-- Nombre -->
              <div class="form-group full-width">
                <label for="name" class="form-label">Nombre de la clase *</label>
                <input
                  id="name"
                  type="text"
                  formControlName="name"
                  class="form-input"
                  placeholder="Ej: Spinning, Cross Training, Yoga"
                  aria-required="true"
                />
                <span
                  *ngIf="
                    classForm.get('name')?.hasError('required') && classForm.get('name')?.touched
                  "
                  class="error-text"
                >
                  El nombre es obligatorio
                </span>
              </div>

              <!-- Tipo -->
              <div class="form-group">
                <label for="type" class="form-label">Tipo de clase *</label>
                <select formControlName="type" class="form-select" aria-required="true">
                  <option value="">Seleccionar...</option>
                  <option value="Spinning">Spinning</option>
                  <option value="Funcional">Funcional</option>
                  <option value="Cross Training">Cross Training</option>
                  <option value="Yoga">Yoga</option>
                  <option value="Pilates">Pilates</option>
                  <option value="Boxeo">Boxeo</option>
                  <option value="Cardio">Cardio</option>
                  <option value="Personalizada">Personalizada</option>
                </select>
                <span
                  *ngIf="
                    classForm.get('type')?.hasError('required') && classForm.get('type')?.touched
                  "
                  class="error-text"
                >
                  El tipo es obligatorio
                </span>
              </div>

              <!-- Entrenador -->
              <div class="form-group">
                <label for="trainer_id" class="form-label">Entrenador asignado</label>
                <select formControlName="trainer_id" class="form-select">
                  <option value="">Sin asignar</option>
                  <option *ngFor="let t of trainers()" [value]="t.id">{{ t.name }}</option>
                  <option *ngIf="trainers().length === 0" value="" disabled>
                    (No hay entrenadores registrados aún)
                  </option>
                </select>
              </div>

              <!-- Descripción -->
              <div class="form-group full-width">
                <label for="description" class="form-label">Descripción</label>
                <textarea
                  id="description"
                  formControlName="description"
                  class="form-input textarea"
                  placeholder="Describe brevemente el enfoque de la clase"
                  rows="3"
                ></textarea>
              </div>
            </div>
          </div>

          <!-- Section: Schedule -->
          <div class="form-section">
            <h3 class="section-title">Programación</h3>

            <div class="form-grid">
              <!-- Fecha de la clase -->
              <div class="form-group">
                <label for="date" class="form-label">Fecha de la clase *</label>
                <app-date-wheel-picker
                  formControlName="date"
                  [minYear]="currentYear - 1"
                  [maxYear]="currentYear + 3"
                  size="sm"
                  ariaLabel="Fecha de la clase"
                  (dateChange)="syncDayOfWeek($event)"
                ></app-date-wheel-picker>
                <span
                  *ngIf="
                    classForm.get('date')?.hasError('required') && classForm.get('date')?.touched
                  "
                  class="error-text"
                >
                  La fecha es obligatoria
                </span>
              </div>

              <!-- Día de la semana -->
              <div class="form-group">
                <label for="day_of_week" class="form-label">Día de la semana *</label>
                <select formControlName="day_of_week" class="form-select" aria-required="true">
                  <option value="">Seleccionar...</option>
                  <option value="Lunes">Lunes</option>
                  <option value="Martes">Martes</option>
                  <option value="Miércoles">Miércoles</option>
                  <option value="Jueves">Jueves</option>
                  <option value="Viernes">Viernes</option>
                  <option value="Sábado">Sábado</option>
                  <option value="Domingo">Domingo</option>
                </select>
                <span
                  *ngIf="
                    classForm.get('day_of_week')?.hasError('required') &&
                    classForm.get('day_of_week')?.touched
                  "
                  class="error-text"
                >
                  El día es obligatorio
                </span>
              </div>

              <!-- Hora de inicio -->
              <div class="form-group">
                <label for="start_time" class="form-label">Hora de inicio *</label>
                <input
                  id="start_time"
                  type="time"
                  formControlName="start_time"
                  class="form-input"
                  aria-required="true"
                />
                <span
                  *ngIf="
                    classForm.get('start_time')?.hasError('required') &&
                    classForm.get('start_time')?.touched
                  "
                  class="error-text"
                >
                  La hora de inicio es obligatoria
                </span>
              </div>

              <!-- Hora de fin -->
              <div class="form-group">
                <label for="end_time" class="form-label">Hora de fin *</label>
                <input
                  id="end_time"
                  type="time"
                  formControlName="end_time"
                  class="form-input"
                  aria-required="true"
                />
                <span
                  *ngIf="
                    classForm.get('end_time')?.hasError('required') &&
                    classForm.get('end_time')?.touched
                  "
                  class="error-text"
                >
                  La hora de fin es obligatoria
                </span>
              </div>

              <!-- Duración -->
              <div class="form-group">
                <label for="duration_minutes" class="form-label">Duración automática</label>
                <input
                  id="duration_minutes"
                  type="number"
                  formControlName="duration_minutes"
                  class="form-input readonly-input"
                  placeholder="Calculada por horario"
                  min="15"
                  readonly
                  tabindex="-1"
                />
                <small class="form-hint">Se calcula con la hora de inicio y fin.</small>
              </div>
            </div>
          </div>

          <!-- Section: Capacity -->
          <div class="form-section">
            <h3 class="section-title">Capacidad y Ubicación</h3>

            <div class="form-grid">
              <!-- Cupos máximos -->
              <div class="form-group">
                <label for="max_capacity" class="form-label">Cupos máximos *</label>
                <input
                  id="max_capacity"
                  type="number"
                  formControlName="max_capacity"
                  class="form-input"
                  placeholder="Ej: 20"
                  min="1"
                  aria-required="true"
                />
                <span
                  *ngIf="
                    classForm.get('max_capacity')?.hasError('required') &&
                    classForm.get('max_capacity')?.touched
                  "
                  class="error-text"
                >
                  Los cupos máximos son obligatorios
                </span>
                <span
                  *ngIf="
                    classForm.get('max_capacity')?.hasError('min') &&
                    classForm.get('max_capacity')?.touched
                  "
                  class="error-text"
                >
                  Los cupos deben ser mayor a 0
                </span>
              </div>

              <!-- Ubicación -->
              <div class="form-group">
                <label for="location" class="form-label">Sala o ubicación</label>
                <input
                  id="location"
                  type="text"
                  formControlName="location"
                  class="form-input"
                  placeholder="Ej: Salón principal, Zona funcional"
                />
              </div>
            </div>
          </div>

          <!-- Section: Additional -->
          <div class="form-section">
            <h3 class="section-title">Configuración</h3>

            <div class="form-grid">
              <!-- Estado -->
              <div class="form-group">
                <label for="status" class="form-label">Estado *</label>
                <select formControlName="status" class="form-select" aria-required="true">
                  <option value="">Seleccionar...</option>
                  <option value="active">Activa</option>
                  <option value="inactive">Inactiva</option>
                </select>
                <span
                  *ngIf="
                    classForm.get('status')?.hasError('required') &&
                    classForm.get('status')?.touched
                  "
                  class="error-text"
                >
                  El estado es obligatorio
                </span>
              </div>

              <!-- Notas internas -->
              <div class="form-group full-width">
                <label for="notes" class="form-label">Notas internas</label>
                <textarea
                  id="notes"
                  formControlName="notes"
                  class="form-input textarea"
                  placeholder="Ej: traer toalla, hidratación, ropa cómoda"
                  rows="3"
                ></textarea>
              </div>

              <!-- Recurrente -->
              <div class="form-group-checkbox">
                <input
                  id="is_recurring"
                  type="checkbox"
                  formControlName="is_recurring"
                  class="form-checkbox"
                />
                <label for="is_recurring" class="checkbox-label">Clase recurrente semanal</label>
              </div>

              <!-- Permitir inscripción online -->
              <div class="form-group-checkbox">
                <input
                  id="allow_online_booking"
                  type="checkbox"
                  formControlName="allow_online_booking"
                  class="form-checkbox"
                />
                <label for="allow_online_booking" class="checkbox-label"
                  >Permitir inscripción online</label
                >
              </div>

              <!-- Requiere plan activo -->
              <div class="form-group-checkbox">
                <input
                  id="requires_active_plan"
                  type="checkbox"
                  formControlName="requires_active_plan"
                  class="form-checkbox"
                />
                <label for="requires_active_plan" class="checkbox-label"
                  >Requiere plan activo</label
                >
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
              [disabled]="!classForm.valid || isSaving()"
              [class.loading]="isSaving()"
            >
              <span *ngIf="!isSaving()">{{ classToEdit ? 'Guardar cambios' : 'Crear clase' }}</span>
              <span *ngIf="isSaving()">{{ classToEdit ? 'Guardando...' : 'Creando...' }}</span>
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

      .readonly-input {
        background: #f8fafc;
        color: #52525b;
        cursor: not-allowed;
      }

      .form-hint {
        display: block;
        margin-top: 0.35rem;
        color: #71717a;
        font-size: 0.78rem;
        font-weight: 600;
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

      .form-group-checkbox {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 0;
      }

      .form-checkbox {
        width: 18px;
        height: 18px;
        border: 1.5px solid #e5e5e5;
        border-radius: 4px;
        cursor: pointer;
        accent-color: #facc15;
        transition: all 200ms ease;
      }

      .form-checkbox:checked {
        background: #facc15;
        border-color: #facc15;
      }

      .checkbox-label {
        font-size: 0.95rem;
        color: #0a0a0a;
        cursor: pointer;
        user-select: none;
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

      .modal-backdrop {
        background: rgba(0, 0, 0, 0.62);
        backdrop-filter: none;
        z-index: 10000;
      }

      .modal-container {
        z-index: 10001;
        overflow-y: auto;
      }

      .modal-card {
        background: linear-gradient(145deg, #1c1b1b 0%, #111111 100%);
        border-color: rgba(245, 197, 24, 0.16);
        color: #e5e2e1;
        box-shadow: 0 28px 80px rgba(0, 0, 0, 0.58);
        overflow: visible;
      }

      .modal-header,
      .modal-footer {
        background: #151515;
        border-color: #353534;
      }

      .header-icon,
      .btn-primary {
        background: #f5c518;
        color: #241a00;
      }

      .modal-title,
      .section-title,
      .form-label,
      .checkbox-label {
        color: #e5e2e1;
      }

      .modal-subtitle,
      .form-hint {
        color: #b4afa6;
      }

      .section-title {
        border-color: #353534;
      }

      .btn-close,
      .btn-secondary,
      .form-input,
      .form-select,
      .readonly-input {
        background: #151515;
        border-color: #353534;
        color: #e5e2e1;
      }

      .form-input,
      .form-select {
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
        border-color: #f5c518;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.14);
      }

      .btn-close:hover:not(:disabled),
      .btn-secondary:hover:not(:disabled) {
        background: #201f1f;
        border-color: #f5c518;
        color: #ffe08b;
      }

      .error-message {
        background: rgba(147, 0, 10, 0.20);
        border-color: rgba(255, 180, 171, 0.32);
        color: #ffb4ab;
      }

      @media (max-width: 640px) {
        .modal-container {
          padding: 0.5rem;
        }

        .modal-card {
          max-height: 95vh;
        }

        .modal-header {
          padding: 1.5rem;
          flex-direction: column;
        }

        .header-content {
          width: 100%;
        }

        .modal-form {
          padding: 1.5rem;
        }

        .form-grid {
          grid-template-columns: 1fr;
        }

        .modal-footer {
          flex-direction: column;
          padding: 1.25rem 1.5rem;
        }

        .btn-primary,
        .btn-secondary {
          width: 100%;
        }
      }
    `,
  ],
})
export class CreateClassModalComponent implements OnChanges, OnInit {
  @Input() isOpen!: Signal<boolean>;
  @Input() classToEdit: ClassSummary | null = null;
  @Output() onClose = new EventEmitter<void>();
  @Output() onClassCreated = new EventEmitter<any>();

  classForm!: FormGroup;
  isSaving = signal(false);
  errorMessage = signal('');
  trainers = signal<{ id: number; name: string }[]>([]);
  currentYear = new Date().getFullYear();

  constructor(
    private fb: FormBuilder,
    private api: ApiService,
  ) {
    this.initializeForm();
  }

  ngOnInit(): void {
    this.loadTrainers();
  }

  ngOnChanges(): void {
    if (!this.classForm) return;
    if (this.isOpen?.()) {
      this.fillForm();
    }
  }

  private loadTrainers(): void {
    // Sin filtro de status: el formulario de clases debe poder asignar cualquier
    // entrenador registrado, independiente del label del status (Activo/active/etc).
    this.api.getTrainers().subscribe({
      next: (list) => {
        const trainers = (list || []).map((t: any) => ({
          id: typeof t.id === 'string' ? parseInt(t.id, 10) : t.id,
          name: t.fullName || t.name || 'Sin nombre',
        }));
        this.trainers.set(trainers);
      },
      error: () => {
        this.trainers.set([]);
      },
    });
  }

  private initializeForm(): void {
    const today = new Date().toISOString().split('T')[0];
    this.classForm = this.fb.group({
      name: ['', [Validators.required]],
      type: ['', [Validators.required]],
      date: [today, [Validators.required]],
      day_of_week: ['', [Validators.required]],
      start_time: ['', [Validators.required]],
      end_time: ['', [Validators.required]],
      duration_minutes: ['60'],
      max_capacity: ['20', [Validators.required, Validators.min(1)]],
      location: [''],
      status: ['active', [Validators.required]],
      trainer_id: [''],
      description: [''],
      notes: [''],
      is_recurring: [true],
      allow_online_booking: [true],
      requires_active_plan: [false],
    });
    this.syncDayOfWeek(today);
    this.classForm.get('start_time')?.valueChanges.subscribe(() => this.updateDuration());
    this.classForm.get('end_time')?.valueChanges.subscribe(() => this.updateDuration());
  }

  private fillForm(): void {
    const cls = this.classToEdit;
    const today = new Date().toISOString().split('T')[0];

    this.classForm.reset({
      name: cls?.name || '',
      type: cls?.type || '',
      date: (cls as any)?.date || today,
      day_of_week: cls?.day_of_week || '',
      start_time: this.normalizeTime(cls?.start_time || ''),
      end_time: this.normalizeTime(cls?.end_time || ''),
      duration_minutes: cls?.duration_minutes || '',
      max_capacity: cls?.max_capacity || '20',
      location: cls?.location || '',
      status: cls?.status || 'active',
      trainer_id: cls?.trainer_id || '',
      description: cls?.description || '',
      notes: (cls as any)?.notes || '',
      is_recurring: (cls as any)?.is_recurring ?? true,
      allow_online_booking: (cls as any)?.allow_online_booking ?? true,
      requires_active_plan: (cls as any)?.requires_active_plan ?? false,
    });

    if (!cls) this.syncDayOfWeek(today);
    this.updateDuration();
  }

  syncDayOfWeek(dateValue: string): void {
    if (!dateValue || !this.classForm) return;

    const [year, month, day] = dateValue.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    if (Number.isNaN(date.getTime())) return;

    const dayLabels = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    this.classForm.get('day_of_week')?.setValue(dayLabels[date.getDay()]);
  }

  private updateDuration(): void {
    const start = this.classForm.get('start_time')?.value;
    const end = this.classForm.get('end_time')?.value;
    const minutes = this.calculateDuration(start, end);
    this.classForm.get('duration_minutes')?.setValue(minutes ? String(minutes) : '', {
      emitEvent: false,
    });
  }

  private calculateDuration(start?: string, end?: string): number | null {
    if (!start || !end) return null;
    const [startHour, startMinute] = start.split(':').map(Number);
    const [endHour, endMinute] = end.split(':').map(Number);
    if ([startHour, startMinute, endHour, endMinute].some((n) => Number.isNaN(n))) return null;
    const startTotal = startHour * 60 + startMinute;
    const endTotal = endHour * 60 + endMinute;
    const diff = endTotal - startTotal;
    return diff > 0 ? diff : null;
  }

  private normalizeTime(value: string): string {
    return value ? value.slice(0, 5) : '';
  }

  onSubmit(): void {
    if (!this.classForm.valid) {
      Object.keys(this.classForm.controls).forEach((key) => {
        this.classForm.get(key)?.markAsTouched();
      });
      return;
    }

    this.isSaving.set(true);
    this.errorMessage.set('');

    const formData = this.classForm.value;
    const duration = this.calculateDuration(formData.start_time, formData.end_time);
    if (!duration || duration < 15) {
      this.isSaving.set(false);
      this.errorMessage.set('La hora de fin debe ser posterior a la hora de inicio y durar mínimo 15 minutos.');
      return;
    }

    const payload: any = {
      name: formData.name || '',
      type: formData.type || '',
      day_of_week: formData.day_of_week || '',
      start_time: formData.start_time || '',
      end_time: formData.end_time || '',
      max_capacity: formData.max_capacity ? parseInt(formData.max_capacity, 10) : 20,
      status: formData.status || 'active',
    };

    payload.duration_minutes = duration;
    if (formData.trainer_id) payload.trainer_id = parseInt(formData.trainer_id, 10);
    if (formData.location) payload.location = formData.location;
    if (formData.description) payload.description = formData.description;
    if (formData.notes) payload.notes = formData.notes;
    if (formData.is_recurring !== undefined) payload.is_recurring = formData.is_recurring === true;
    if (formData.allow_online_booking !== undefined)
      payload.allow_online_booking = formData.allow_online_booking === true;
    if (formData.requires_active_plan !== undefined)
      payload.requires_active_plan = formData.requires_active_plan === true;

    const request$ = this.classToEdit
      ? this.api.updateClass(this.classToEdit.id, payload)
      : this.api.createClass(payload);

    request$.subscribe({
      next: (created: any) => {
        // El backend no almacena `date` (solo day_of_week); el padre lo calcula al normalizar.
        const enriched = {
          ...created,
          date: formData.date || '',
          trainerName: created.trainer?.name || this.getTrainerName(formData.trainer_id || ''),
          enrolled_count: created.enrolled_count ?? 0,
        };
        this.isSaving.set(false);
        this.onClassCreated.emit(enriched);
        this.close();
      },
      error: (err) => {
        this.isSaving.set(false);
        const msg =
          err?.error?.message ||
          (err?.status === 422
            ? 'Datos inválidos. Revisa los campos del formulario.'
            : this.classToEdit
              ? 'No se pudo editar la clase. Intenta de nuevo.'
              : 'No se pudo crear la clase. Intenta de nuevo.');
        this.errorMessage.set(msg);
      },
    });
  }

  private getTrainerName(trainerId: string | number): string {
    if (!trainerId) return 'Sin asignar';
    const id = typeof trainerId === 'string' ? parseInt(trainerId, 10) : trainerId;
    const found = this.trainers().find((t) => t.id === id);
    return found ? found.name : 'Sin asignar';
  }

  close(): void {
    if (!this.isSaving()) {
      const today = new Date().toISOString().split('T')[0];
      this.classForm.reset({
        date: today,
        status: 'active',
        duration_minutes: '',
        max_capacity: '20',
        is_recurring: true,
        allow_online_booking: true,
      });
      this.errorMessage.set('');
      this.onClose.emit();
    }
  }
}
