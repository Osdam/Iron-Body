import { Component, Input, Output, EventEmitter, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';

export interface GymSettings {
  name: string;
  taxId: string;
  address: string;
  city: string;
  country: string;
  phone: string;
  email: string;
  website: string;
  openingTime: string;
  closingTime: string;
  operatingDays: string[];
  maxCapacity: number;
  description: string;
}

@Component({
  selector: 'app-settings-gym',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  template: `
    <div class="settings-section">
      <div class="section-header">
        <div class="section-title">
          <span class="material-symbols-outlined">business</span>
          <div>
            <h2>Configuración del gimnasio</h2>
            <p>Información general e institucional</p>
          </div>
        </div>
      </div>

      <form [formGroup]="form" class="form-grid">
        <div class="form-group">
          <label for="name">Nombre del gimnasio</label>
          <input type="text" id="name" formControlName="name" placeholder="Iron Body Gym" />
        </div>

        <div class="form-group">
          <label for="taxId">NIT / Identificación tributaria</label>
          <input type="text" id="taxId" formControlName="taxId" placeholder="900.123.456-7" />
        </div>

        <div class="form-group">
          <label for="address">Dirección</label>
          <input type="text" id="address" formControlName="address" placeholder="Cra 7 # 45-67" />
        </div>

        <div class="form-group">
          <label for="city">Ciudad</label>
          <input type="text" id="city" formControlName="city" placeholder="Bogotá" />
        </div>

        <div class="form-group">
          <label for="country">País</label>
          <input type="text" id="country" formControlName="country" placeholder="Colombia" />
        </div>

        <div class="form-group">
          <label for="phone">Teléfono</label>
          <input type="tel" id="phone" formControlName="phone" placeholder="+57 1 3456789" />
        </div>

        <div class="form-group">
          <label for="email">Correo electrónico</label>
          <input
            type="email"
            id="email"
            formControlName="email"
            placeholder="contacto@ironbody.com"
          />
        </div>

        <div class="form-group">
          <label for="website">Sitio web</label>
          <input
            type="url"
            id="website"
            formControlName="website"
            placeholder="https://ironbody.com"
          />
        </div>

        <div class="form-group">
          <label for="openingTime">Hora de apertura</label>
          <input type="time" id="openingTime" formControlName="openingTime" />
        </div>

        <div class="form-group">
          <label for="closingTime">Hora de cierre</label>
          <input type="time" id="closingTime" formControlName="closingTime" />
        </div>

        <div class="form-group">
          <label for="maxCapacity">Capacidad máxima</label>
          <input
            type="number"
            id="maxCapacity"
            formControlName="maxCapacity"
            placeholder="150"
            min="1"
          />
        </div>

        <div class="form-group full-width">
          <label>Días de operación</label>
          <div class="days-grid">
            <label class="day-checkbox" *ngFor="let day of days">
              <input
                type="checkbox"
                [checked]="operatingDays().includes(day)"
                (change)="toggleDay(day, $event)"
              />
              <span>{{ day }}</span>
            </label>
          </div>
        </div>

        <div class="form-group full-width">
          <label for="description">Descripción institucional</label>
          <textarea
            id="description"
            formControlName="description"
            placeholder="Breve descripción del gimnasio..."
            rows="4"
          ></textarea>
        </div>
      </form>
    </div>
  `,
  styles: [
    `
      .settings-section {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 0.75rem;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
      }

      .section-header {
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #f3f4f6;
      }

      .section-title {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
      }

      .section-title .material-symbols-outlined {
        font-size: 1.5rem;
        color: #fbbf24;
        margin-top: 0.25rem;
      }

      .section-title h2 {
        font-size: 1.125rem;
        font-weight: 600;
        color: #0a0a0a;
        margin: 0;
      }

      .section-title p {
        font-size: 0.875rem;
        color: #6b7280;
        margin: 0.25rem 0 0 0;
      }

      .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
      }

      .form-group {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
      }

      .form-group.full-width {
        grid-column: 1 / -1;
      }

      label {
        font-size: 0.875rem;
        font-weight: 500;
        color: #0a0a0a;
      }

      input,
      textarea,
      select {
        padding: 0.625rem 0.75rem;
        border: 1px solid #d1d5db;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-family: inherit;
        transition: all 0.2s;
      }

      input:focus,
      textarea:focus,
      select:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.1);
      }

      input::placeholder,
      textarea::placeholder {
        color: #9ca3af;
      }

      textarea {
        resize: vertical;
        font-family: inherit;
      }

      .days-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 1rem;
        margin-top: 0.5rem;
      }

      .day-checkbox {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
        user-select: none;
        padding: 0.5rem;
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
        transition: all 0.2s;
      }

      .day-checkbox:hover {
        background: #f9fafb;
      }

      .day-checkbox input[type='checkbox'] {
        cursor: pointer;
        width: auto !important;
      }

      .day-checkbox span {
        font-size: 0.875rem;
        color: #0a0a0a;
      }

      .settings-section {
        background:
          linear-gradient(rgba(28, 27, 27, 0.92), rgba(17, 17, 17, 0.9)),
          url('/assets/crm/clases2.png') center / cover no-repeat;
        border-color: #353534;
        color: #e5e2e1;
        box-shadow: 0 14px 34px rgba(0, 0, 0, 0.24);
      }

      .section-header {
        border-color: #353534;
      }

      .section-title h2 {
        color: #e5e2e1;
      }

      .section-title p {
        color: #b4afa6;
      }

      label {
        color: #cfcac2;
      }

      input,
      textarea,
      select {
        background: #151515;
        border-color: #353534;
        color: #e5e2e1;
        color-scheme: dark;
      }

      input:focus,
      textarea:focus,
      select:focus {
        background: #201f1f;
        border-color: #f5c518;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.13);
      }

      input::placeholder,
      textarea::placeholder {
        color: #77716a;
      }

      .day-checkbox {
        background: #1c1b1b;
        border-color: #353534;
      }

      .day-checkbox:hover {
        background: #201f1f;
        border-color: #f5c518;
      }

      .day-checkbox span {
        color: #e5e2e1;
      }

      @media (max-width: 768px) {
        .form-grid {
          grid-template-columns: 1fr;
        }

        .days-grid {
          grid-template-columns: repeat(2, 1fr);
        }
      }
    `,
  ],
})
export default class SettingsGymComponent implements OnInit {
  @Input() settings: Partial<GymSettings> = {};
  @Output() settingsChange = new EventEmitter<Partial<GymSettings>>();

  form: FormGroup;
  days = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

  constructor(private fb: FormBuilder) {
    this.form = this.fb.group({
      name: ['Iron Body Gym', [Validators.required, Validators.minLength(3)]],
      taxId: ['', Validators.required],
      address: ['', Validators.required],
      city: ['Bogotá', Validators.required],
      country: ['Colombia', Validators.required],
      phone: ['', Validators.required],
      email: ['', [Validators.required, Validators.email]],
      website: ['', Validators.required],
      openingTime: ['06:00', Validators.required],
      closingTime: ['22:00', Validators.required],
      maxCapacity: [150, [Validators.required, Validators.min(1)]],
      description: ['', Validators.maxLength(500)],
    });

    this.form.valueChanges.subscribe((value) => {
      this.settingsChange.emit(value);
    });
  }

  ngOnInit(): void {
    if (this.settings && Object.keys(this.settings).length > 0) {
      this.form.patchValue(this.settings, { emitEvent: false });
    }
  }

  operatingDays() {
    return (
      this.settings.operatingDays || ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado']
    );
  }

  toggleDay(day: string, event: Event): void {
    const checked = (event.target as HTMLInputElement).checked;
    const current = this.settings.operatingDays || [];
    let updated: string[];

    if (checked) {
      updated = [...current, day];
    } else {
      updated = current.filter((d) => d !== day);
    }

    this.settings = { ...this.settings, operatingDays: updated };
    this.settingsChange.emit(this.settings);
  }
}
