import { Component, Input, Output, EventEmitter, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';

export interface GeneralSettings {
  systemName: string;
  businessName: string;
  currency: string;
  timezone: string;
  language: string;
  dateFormat: string;
  timeFormat: string;
  compactMode: boolean;
  defaultPage: string;
}

@Component({
  selector: 'app-settings-general',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  template: `
    <div class="settings-section">
      <div class="section-header">
        <div class="section-title">
          <span class="material-symbols-outlined">settings</span>
          <div>
            <h2>Configuración general</h2>
            <p>Ajustes básicos del sistema</p>
          </div>
        </div>
      </div>

      <form [formGroup]="form" class="form-grid">
        <div class="form-group">
          <label for="systemName">Nombre del sistema</label>
          <input
            type="text"
            id="systemName"
            formControlName="systemName"
            placeholder="Iron Body Admin"
          />
        </div>

        <div class="form-group">
          <label for="businessName">Nombre comercial</label>
          <input
            type="text"
            id="businessName"
            formControlName="businessName"
            placeholder="Iron Body Gym"
          />
        </div>

        <div class="form-group">
          <label for="currency">Moneda predeterminada</label>
          <select id="currency" formControlName="currency">
            <option value="">Seleccionar...</option>
            <option value="COP">COP - Peso colombiano</option>
            <option value="USD">USD - Dólar estadounidense</option>
            <option value="EUR">EUR - Euro</option>
            <option value="MXN">MXN - Peso mexicano</option>
          </select>
        </div>

        <div class="form-group">
          <label for="timezone">Zona horaria</label>
          <select id="timezone" formControlName="timezone">
            <option value="">Seleccionar...</option>
            <option value="America/Bogota">America/Bogota (UTC-5)</option>
            <option value="America/Mexico_City">America/Mexico_City (UTC-6)</option>
            <option value="America/New_York">America/New_York (UTC-5)</option>
            <option value="America/Los_Angeles">America/Los_Angeles (UTC-8)</option>
            <option value="Europe/Madrid">Europe/Madrid (UTC+1)</option>
          </select>
        </div>

        <div class="form-group">
          <label for="language">Idioma</label>
          <select id="language" formControlName="language">
            <option value="">Seleccionar...</option>
            <option value="es">Español</option>
            <option value="en">English</option>
            <option value="pt">Português</option>
          </select>
        </div>

        <div class="form-group">
          <label for="dateFormat">Formato de fecha</label>
          <select id="dateFormat" formControlName="dateFormat">
            <option value="">Seleccionar...</option>
            <option value="DD/MM/YYYY">DD/MM/YYYY (30/04/2026)</option>
            <option value="MM/DD/YYYY">MM/DD/YYYY (04/30/2026)</option>
            <option value="YYYY-MM-DD">YYYY-MM-DD (2026-04-30)</option>
          </select>
        </div>

        <div class="form-group">
          <label for="timeFormat">Formato de hora</label>
          <select id="timeFormat" formControlName="timeFormat">
            <option value="">Seleccionar...</option>
            <option value="12">12 horas (2:30 PM)</option>
            <option value="24">24 horas (14:30)</option>
          </select>
        </div>

        <div class="form-group">
          <label for="defaultPage">Página inicial</label>
          <select id="defaultPage" formControlName="defaultPage">
            <option value="">Seleccionar...</option>
            <option value="dashboard">Dashboard</option>
            <option value="members">Miembros</option>
            <option value="payments">Pagos</option>
            <option value="classes">Clases</option>
            <option value="trainers">Entrenadores</option>
          </select>
        </div>

        <div class="form-group checkbox">
          <input type="checkbox" id="compactMode" formControlName="compactMode" />
          <label for="compactMode">Activar modo compacto del sistema</label>
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

      .form-group.checkbox {
        flex-direction: row;
        align-items: center;
        margin-top: 0.5rem;
      }

      .form-group.checkbox input {
        width: auto !important;
        cursor: pointer;
      }

      .form-group.checkbox label {
        margin: 0;
        cursor: pointer;
        user-select: none;
      }

      label {
        font-size: 0.875rem;
        font-weight: 500;
        color: #0a0a0a;
      }

      input,
      select {
        padding: 0.625rem 0.75rem;
        border: 1px solid #d1d5db;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-family: inherit;
        transition: all 0.2s;
      }

      input:focus,
      select:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.1);
      }

      input::placeholder {
        color: #9ca3af;
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
      select {
        background: #151515;
        border-color: #353534;
        color: #e5e2e1;
        color-scheme: dark;
      }

      select option {
        background: #151515;
        color: #e5e2e1;
      }

      input:focus,
      select:focus {
        background: #201f1f;
        border-color: #f5c518;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.13);
      }

      input::placeholder {
        color: #77716a;
      }

      @media (max-width: 768px) {
        .form-grid {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export default class SettingsGeneralComponent implements OnInit {
  @Input() settings: Partial<GeneralSettings> = {};
  @Output() settingsChange = new EventEmitter<Partial<GeneralSettings>>();

  form: FormGroup;

  constructor(private fb: FormBuilder) {
    this.form = this.fb.group({
      systemName: ['Iron Body Admin', Validators.required],
      businessName: ['Iron Body Gym', Validators.required],
      currency: ['COP', Validators.required],
      timezone: ['America/Bogota', Validators.required],
      language: ['es', Validators.required],
      dateFormat: ['DD/MM/YYYY', Validators.required],
      timeFormat: ['24', Validators.required],
      compactMode: [false],
      defaultPage: ['dashboard', Validators.required],
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
}
