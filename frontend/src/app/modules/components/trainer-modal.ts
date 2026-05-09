import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, OnChanges, Output, inject } from '@angular/core';
import {
  FormArray,
  FormBuilder,
  FormControl,
  FormGroup,
  ReactiveFormsModule,
  Validators,
  AbstractControl,
  ValidationErrors,
} from '@angular/forms';

import type { Trainer, TrainerAvailability } from './trainer-card';

export type TrainerModalMode = 'create' | 'edit' | 'detail';

const nonNegativeNumber = (control: AbstractControl): ValidationErrors | null => {
  const value = control.value;
  if (value === null || value === undefined || value === '') return null;
  const num = Number(value);
  if (Number.isNaN(num)) return { number: true };
  return num < 0 ? { nonNegative: true } : null;
};

const emailValidator = (control: AbstractControl): ValidationErrors | null => {
  const value = control.value;
  if (!value) return null;
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return emailRegex.test(value) ? null : { invalidEmail: true };
};

@Component({
  selector: 'app-trainer-modal',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  template: `
    <div *ngIf="isOpen" class="overlay" (click)="onOverlay($event)" role="dialog" aria-modal="true">
      <section class="drawer" [class.drawer-detail]="mode === 'detail'">
        <header class="drawer-header">
          <div class="header-left">
            <div class="header-icon" aria-hidden="true">
              <span class="material-symbols-outlined">{{ headerIcon }}</span>
            </div>
            <div>
              <h2>{{ headerTitle }}</h2>
              <p>{{ headerSubtitle }}</p>
            </div>
          </div>

          <button type="button" class="close" (click)="close.emit()" aria-label="Cerrar">
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
          </button>
        </header>

        <div class="drawer-body">
          <form [formGroup]="trainerForm" (ngSubmit)="submit()" class="form">
            <div class="form-section">
              <h3 class="section-title">Datos personales</h3>

              <div class="grid">
                <div class="field span-2">
                  <label class="label">Nombre completo</label>
                  <input
                    class="input"
                    formControlName="fullName"
                    placeholder="Ej: Carlos Ruiz"
                    [disabled]="readonly || isSaving"
                  />
                  <p class="error" *ngIf="showError('fullName')">El nombre es obligatorio.</p>
                </div>

                <div class="field">
                  <label class="label">Documento / ID</label>
                  <input
                    class="input"
                    formControlName="document"
                    placeholder="Ej: 1020304050"
                    [disabled]="readonly || isSaving"
                  />
                  <p class="error" *ngIf="showError('document')">El documento es obligatorio.</p>
                </div>

                <div class="field">
                  <label class="label">Teléfono</label>
                  <input
                    class="input"
                    formControlName="phone"
                    placeholder="Ej: 3001234567"
                    [disabled]="readonly || isSaving"
                  />
                  <p class="error" *ngIf="showError('phone')">El teléfono es obligatorio.</p>
                </div>

                <div class="field span-2">
                  <label class="label">Correo electrónico</label>
                  <input
                    class="input"
                    type="email"
                    formControlName="email"
                    placeholder="Ej: entrenador@correo.com"
                    [disabled]="readonly || isSaving"
                  />
                  <p class="error" *ngIf="showError('email')">Ingresa un correo válido.</p>
                </div>

                <div class="field">
                  <label class="label">Fecha de nacimiento</label>
                  <input
                    class="input"
                    type="date"
                    formControlName="birthDate"
                    [disabled]="readonly || isSaving"
                  />
                </div>
              </div>
            </div>

            <div class="form-section">
              <h3 class="section-title">Experiencia y especialidades</h3>

              <div class="grid">
                <div class="field span-2">
                  <label class="label">Especialidad principal</label>
                  <select
                    class="select"
                    formControlName="mainSpecialty"
                    [disabled]="readonly || isSaving"
                  >
                    <option value="">Selecciona</option>
                    <option *ngFor="let s of specialties" [value]="s">{{ s }}</option>
                  </select>
                  <p class="error" *ngIf="showError('mainSpecialty')">
                    La especialidad es obligatoria.
                  </p>
                </div>

                <div class="field">
                  <label class="label">Experiencia (años)</label>
                  <input
                    class="input"
                    type="number"
                    formControlName="experienceYears"
                    placeholder="Ej: 5"
                    [disabled]="readonly || isSaving"
                  />
                  <p class="error" *ngIf="showError('experienceYears')">Ingresa un valor válido.</p>
                </div>
              </div>

              <div class="field">
                <label class="label">Especialidades adicionales</label>
                <div class="checkbox-group">
                  <label *ngFor="let s of specialties" class="checkbox-label">
                    <input
                      type="checkbox"
                      [checked]="isSpecialtyChecked(s)"
                      (change)="toggleSpecialty(s, $event)"
                      [disabled]="readonly || isSaving"
                    />
                    <span>{{ s }}</span>
                  </label>
                </div>
              </div>
            </div>

            <div class="form-section">
              <h3 class="section-title">Contrato y estado</h3>

              <div class="grid">
                <div class="field">
                  <label class="label">Tipo de contrato</label>
                  <select
                    class="select"
                    formControlName="contractType"
                    [disabled]="readonly || isSaving"
                  >
                    <option value="">Selecciona</option>
                    <option value="Tiempo completo">Tiempo completo</option>
                    <option value="Medio tiempo">Medio tiempo</option>
                    <option value="Por horas">Por horas</option>
                    <option value="Independiente">Independiente</option>
                  </select>
                  <p class="error" *ngIf="showError('contractType')">
                    El tipo de contrato es obligatorio.
                  </p>
                </div>

                <div class="field">
                  <label class="label">Estado</label>
                  <select class="select" formControlName="status" [disabled]="readonly || isSaving">
                    <option value="">Selecciona</option>
                    <option value="Activo">Activo</option>
                    <option value="Inactivo">Inactivo</option>
                    <option value="Pendiente">Pendiente</option>
                  </select>
                  <p class="error" *ngIf="showError('status')">El estado es obligatorio.</p>
                </div>

                <div class="field span-2">
                  <label class="label">Evaluación / Rating (0-5)</label>
                  <input
                    class="input"
                    type="number"
                    formControlName="rating"
                    placeholder="Ej: 4.5"
                    min="0"
                    max="5"
                    step="0.1"
                    [disabled]="readonly || isSaving"
                  />
                </div>
              </div>
            </div>

            <div class="form-section">
              <h3 class="section-title">Información adicional</h3>

              <div class="field">
                <label class="label">Biografía</label>
                <textarea
                  class="textarea"
                  formControlName="bio"
                  placeholder="Describe brevemente la experiencia y enfoque del entrenador"
                  [disabled]="readonly || isSaving"
                  rows="3"
                ></textarea>
              </div>

              <div class="field">
                <label class="label">Certificaciones</label>
                <textarea
                  class="textarea"
                  formControlName="certifications"
                  placeholder="Ej: Entrenamiento funcional, nutrición deportiva, primeros auxilios"
                  [disabled]="readonly || isSaving"
                  rows="2"
                ></textarea>
              </div>
            </div>

            <div class="form-section">
              <h3 class="section-title">Disponibilidad semanal</h3>

              <div class="availability-grid">
                <div
                  *ngFor="let avail of availabilityArray.controls; let i = index"
                  class="availability-day"
                >
                  <label class="availability-label">
                    <input
                      type="checkbox"
                      [formControl]="getAvailabilityControl(i, 'enabled')"
                      [disabled]="readonly || isSaving"
                    />
                    <span class="day-name">{{ getDayName(i) }}</span>
                  </label>

                  <div class="time-inputs" *ngIf="getAvailabilityControl(i, 'enabled').value">
                    <input
                      type="time"
                      class="time-input"
                      [formControl]="getAvailabilityControl(i, 'startTime')"
                      [disabled]="readonly || isSaving"
                    />
                    <span class="time-sep">-</span>
                    <input
                      type="time"
                      class="time-input"
                      [formControl]="getAvailabilityControl(i, 'endTime')"
                      [disabled]="readonly || isSaving"
                    />
                  </div>
                </div>
              </div>
            </div>

            <div class="drawer-footer" *ngIf="!readonly">
              <button
                type="button"
                class="btn-secondary"
                (click)="close.emit()"
                [disabled]="isSaving"
              >
                Cancelar
              </button>
              <button
                type="submit"
                class="btn-primary"
                [disabled]="trainerForm.invalid || isSaving"
              >
                <span *ngIf="!isSaving">{{
                  mode === 'edit' ? 'Guardar cambios' : 'Registrar entrenador'
                }}</span>
                <span *ngIf="isSaving">{{
                  mode === 'edit' ? 'Guardando...' : 'Registrando...'
                }}</span>
              </button>
            </div>

            <div class="drawer-footer" *ngIf="readonly">
              <button type="button" class="btn-secondary" (click)="close.emit()">Cerrar</button>
            </div>
          </form>
        </div>
      </section>
    </div>
  `,
  styles: [
    `
      .overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        display: grid;
        place-items: center;
        z-index: 50;
        padding: 1rem;
        backdrop-filter: blur(2px);
      }

      .drawer {
        width: 100%;
        max-width: 700px;
        max-height: 90vh;
        border-radius: 20px;
        background: #ffffff;
        display: flex;
        flex-direction: column;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        overflow: hidden;
      }

      .drawer-detail {
        max-width: 600px;
      }

      .drawer-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.4rem;
        border-bottom: 1px solid #f0f0f0;
        background: #fafafa;
      }

      .header-left {
        display: flex;
        gap: 0.9rem;
        flex: 1;
      }

      .header-icon {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        border: 1px solid rgba(251, 191, 36, 0.3);
        background: rgba(251, 191, 36, 0.12);
        display: grid;
        place-items: center;
        color: #ca8a04;
        flex-shrink: 0;
      }

      .header-icon span {
        font-size: 1.4rem;
      }

      .drawer-header h2 {
        margin: 0;
        font-size: 1.4rem;
        font-weight: 900;
        color: #0a0a0a;
      }

      .drawer-header p {
        margin: 0.3rem 0 0;
        color: #666;
        font-size: 0.9rem;
      }

      .close {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        border: 1px solid #e5e5e5;
        background: #ffffff;
        cursor: pointer;
        display: grid;
        place-items: center;
        transition: all 0.15s ease;
        color: #0a0a0a;
        flex-shrink: 0;
      }

      .close:hover {
        background: #f9f9f9;
        border-color: #d0d0d0;
      }

      .close span {
        font-size: 1.2rem;
      }

      .drawer-body {
        flex: 1;
        overflow-y: auto;
        padding: 1.4rem;
      }

      .form {
        display: flex;
        flex-direction: column;
        gap: 1.6rem;
      }

      .form-section {
        display: flex;
        flex-direction: column;
        gap: 0.8rem;
      }

      .section-title {
        margin: 0;
        font-size: 0.95rem;
        font-weight: 900;
        color: #0a0a0a;
      }

      .grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.9rem;
      }

      .span-2 {
        grid-column: span 2;
      }

      .field {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
      }

      .label {
        font-size: 0.8rem;
        font-weight: 900;
        color: #0a0a0a;
      }

      .input,
      .select,
      .textarea {
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        padding: 0.75rem;
        font-size: 0.95rem;
        background: #ffffff;
        color: #0a0a0a;
        transition: border-color 0.15s ease;
        font-family: inherit;
      }

      .input:focus,
      .select:focus,
      .textarea:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.1);
      }

      .input:disabled,
      .select:disabled,
      .textarea:disabled {
        background: #f9f9f9;
        color: #999;
      }

      .input::placeholder {
        color: #ccc;
      }

      .textarea {
        resize: vertical;
        min-height: auto;
      }

      .error {
        margin: 0;
        font-size: 0.75rem;
        color: #dc2626;
        font-weight: 600;
      }

      .checkbox-group {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.6rem;
      }

      .checkbox-label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
        padding: 0.4rem;
        border-radius: 8px;
        transition: background 0.15s ease;
      }

      .checkbox-label:hover {
        background: #f9f9f9;
      }

      .checkbox-label input {
        cursor: pointer;
      }

      .checkbox-label span {
        font-size: 0.9rem;
        color: #0a0a0a;
      }

      .availability-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.9rem;
      }

      .availability-day {
        border: 1px solid #f0f0f0;
        border-radius: 12px;
        padding: 0.8rem;
        background: #fafafa;
      }

      .availability-label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
        margin-bottom: 0.5rem;
      }

      .availability-label input {
        cursor: pointer;
      }

      .day-name {
        font-size: 0.9rem;
        font-weight: 700;
        color: #0a0a0a;
      }

      .time-inputs {
        display: flex;
        align-items: center;
        gap: 0.5rem;
      }

      .time-input {
        flex: 1;
        border: 1px solid #e5e5e5;
        border-radius: 8px;
        padding: 0.5rem;
        font-size: 0.85rem;
        background: #ffffff;
        color: #0a0a0a;
      }

      .time-input:focus {
        outline: none;
        border-color: #fbbf24;
      }

      .time-sep {
        color: #ccc;
        font-weight: 600;
      }

      .drawer-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.8rem;
        padding: 1rem 1.4rem;
        border-top: 1px solid #f0f0f0;
        background: #fafafa;
      }

      .btn-secondary,
      .btn-primary {
        height: 44px;
        border-radius: 12px;
        padding: 0 1.2rem;
        font-weight: 900;
        cursor: pointer;
        border: 1px solid transparent;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        transition: all 0.15s ease;
      }

      .btn-secondary {
        border-color: #e5e5e5;
        background: #ffffff;
        color: #0a0a0a;
      }

      .btn-secondary:hover:not(:disabled) {
        background: #f9f9f9;
      }

      .btn-primary {
        background: #fbbf24;
        color: #0a0a0a;
        box-shadow: 0 8px 16px rgba(251, 191, 36, 0.2);
      }

      .btn-primary:hover:not(:disabled) {
        background: #f9a825;
        box-shadow: 0 12px 24px rgba(251, 191, 36, 0.25);
      }

      .btn-primary:disabled,
      .btn-secondary:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }

      @media (max-width: 640px) {
        .drawer {
          max-width: 100%;
          max-height: 100vh;
          border-radius: 16px 16px 0 0;
        }

        .grid {
          grid-template-columns: 1fr;
        }

        .span-2 {
          grid-column: span 1;
        }

        .checkbox-group {
          grid-template-columns: 1fr;
        }

        .availability-grid {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export default class TrainerModalComponent implements OnChanges {
  private fb = inject(FormBuilder);

  @Input() isOpen: boolean = false;
  @Input() mode: TrainerModalMode = 'create';
  @Input() trainer: Trainer | null = null;
  @Input() isSaving: boolean = false;

  @Output() close = new EventEmitter<void>();
  @Output() save = new EventEmitter<Partial<Trainer>>();

  specialties = [
    'Musculación',
    'Funcional',
    'Spinning',
    'Cross Training',
    'Yoga',
    'Pilates',
    'Boxeo',
    'Cardio',
    'Rehabilitación',
    'Entrenamiento personalizado',
  ];

  days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
  dayLabels = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

  trainerForm!: FormGroup;

  ngOnInit(): void {
    this.buildForm();
  }

  ngOnChanges(): void {
    if (this.isOpen && this.mode === 'edit' && this.trainer) {
      this.populateForm(this.trainer);
    } else if (this.isOpen && this.mode === 'create') {
      this.resetForm();
    } else if (this.isOpen && this.mode === 'detail' && this.trainer) {
      this.populateForm(this.trainer);
      this.trainerForm.disable();
    }
  }

  get readonly(): boolean {
    return this.mode === 'detail';
  }

  get headerTitle(): string {
    if (this.mode === 'detail') return 'Perfil del entrenador';
    return this.mode === 'edit' ? 'Editar entrenador' : 'Registrar nuevo entrenador';
  }

  get headerSubtitle(): string {
    if (this.mode === 'detail') return 'Información completa del perfil.';
    return 'Agrega datos personales, especialidades, disponibilidad y estado.';
  }

  get headerIcon(): string {
    if (this.mode === 'detail') return 'visibility';
    if (this.mode === 'edit') return 'edit';
    return 'person_add';
  }

  get availabilityArray(): FormArray {
    return this.trainerForm.get('availability') as FormArray;
  }

  onOverlay(event: MouseEvent): void {
    if (event.target === event.currentTarget) this.close.emit();
  }

  showError(field: string): boolean {
    const ctrl = this.trainerForm.get(field);
    return !!ctrl && ctrl.invalid && (ctrl.touched || ctrl.dirty);
  }

  isSpecialtyChecked(specialty: string): boolean {
    const specialties = this.trainerForm.get('specialties') as FormArray;
    return specialties.value.includes(specialty);
  }

  toggleSpecialty(specialty: string, event: any): void {
    const specialties = this.trainerForm.get('specialties') as FormArray;
    if (event.target.checked) {
      if (!specialties.value.includes(specialty)) {
        specialties.push(new FormControl(specialty));
      }
    } else {
      const index = specialties.value.indexOf(specialty);
      if (index >= 0) {
        specialties.removeAt(index);
      }
    }
  }

  getAvailabilityControl(index: number, field: string): FormControl {
    const group = this.availabilityArray.at(index) as FormGroup;
    return group.get(field) as FormControl;
  }

  getDayName(index: number): string {
    return this.dayLabels[index] || '';
  }

  submit(): void {
    if (this.trainerForm.invalid) return;

    const payload: Partial<Trainer> = {
      fullName: this.trainerForm.get('fullName')?.value,
      document: this.trainerForm.get('document')?.value,
      phone: this.trainerForm.get('phone')?.value,
      email: this.trainerForm.get('email')?.value,
      birthDate: this.trainerForm.get('birthDate')?.value,
      mainSpecialty: this.trainerForm.get('mainSpecialty')?.value,
      specialties: this.trainerForm.get('specialties')?.value || [],
      experienceYears: Number(this.trainerForm.get('experienceYears')?.value || 0),
      contractType: this.trainerForm.get('contractType')?.value,
      status: this.trainerForm.get('status')?.value,
      rating: Number(this.trainerForm.get('rating')?.value || 0),
      bio: this.trainerForm.get('bio')?.value,
      certifications: this.trainerForm.get('certifications')?.value,
      availability: this.trainerForm.get('availability')?.value || [],
    };

    this.save.emit(payload);
  }

  private buildForm(): void {
    const availability = this.days.map(
      (day, idx) =>
        new FormGroup({
          day: new FormControl(day),
          enabled: new FormControl(false),
          startTime: new FormControl('08:00'),
          endTime: new FormControl('18:00'),
        }),
    );

    this.trainerForm = this.fb.group({
      fullName: ['', Validators.required],
      document: ['', Validators.required],
      phone: ['', Validators.required],
      email: ['', [Validators.required, emailValidator]],
      birthDate: [''],
      mainSpecialty: ['', Validators.required],
      specialties: this.fb.array([]),
      experienceYears: [0, [Validators.required, nonNegativeNumber]],
      contractType: ['', Validators.required],
      status: ['', Validators.required],
      rating: [4.5, nonNegativeNumber],
      bio: [''],
      certifications: [''],
      availability: this.fb.array(availability),
    });
  }

  private resetForm(): void {
    this.trainerForm.reset({
      rating: 4.5,
      availability: this.days.map((day) => ({
        day,
        enabled: false,
        startTime: '08:00',
        endTime: '18:00',
      })),
    });
    this.trainerForm.markAsUntouched();
    this.trainerForm.markAsPristine();
  }

  private populateForm(trainer: Trainer): void {
    const specialties = this.fb.array((trainer.specialties || []).map((s) => new FormControl(s)));

    const availability = this.fb.array(
      this.days.map((day, idx) => {
        const avail = trainer.availability?.[idx] || {
          day,
          enabled: false,
          startTime: '08:00',
          endTime: '18:00',
        };
        return new FormGroup({
          day: new FormControl(day),
          enabled: new FormControl(avail.enabled),
          startTime: new FormControl(avail.startTime),
          endTime: new FormControl(avail.endTime),
        });
      }),
    );

    this.trainerForm.patchValue({
      fullName: trainer.fullName,
      document: trainer.document,
      phone: trainer.phone,
      email: trainer.email,
      birthDate: trainer.birthDate,
      mainSpecialty: trainer.mainSpecialty,
      experienceYears: trainer.experienceYears,
      contractType: trainer.contractType,
      status: trainer.status,
      rating: trainer.rating,
      bio: trainer.bio || '',
      certifications: trainer.certifications || '',
      specialties,
      availability,
    });
  }
}
