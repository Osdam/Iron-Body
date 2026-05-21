import { Component, Input, Output, EventEmitter, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';

@Component({
  selector: 'app-settings-memberships',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  template: `
    <div class="settings-section">
      <div class="section-header">
        <div class="section-title">
          <span class="material-symbols-outlined">card_membership</span>
          <div>
            <h2>Planes y membresías</h2>
            <p>Configuración de renovación y vigencia</p>
          </div>
        </div>
      </div>

      <form [formGroup]="form" class="form-grid">
        <div class="form-group">
          <label for="defaultDuration">Duración predeterminada (días)</label>
          <input type="number" id="defaultDuration" formControlName="defaultDuration" min="1" />
        </div>

        <div class="form-group">
          <label for="expirationReminder">Días antes para alerta de vencimiento</label>
          <input
            type="number"
            id="expirationReminder"
            formControlName="expirationReminder"
            min="1"
          />
        </div>

        <div class="form-group">
          <label for="defaultStatus">Estado predeterminado</label>
          <select id="defaultStatus" formControlName="defaultStatus">
            <option value="Activa">Activa</option>
            <option value="Inactiva">Inactiva</option>
            <option value="Vencida">Vencida</option>
            <option value="Pendiente">Pendiente</option>
          </select>
        </div>

        <div class="form-group">
          <label for="maxFreezeDays">Días máximos de congelación</label>
          <input type="number" id="maxFreezeDays" formControlName="maxFreezeDays" min="0" />
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="autoRenewalEnabled" />
            <span>Permitir renovación automática</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="noExpirationAllowed" />
            <span>Permitir plan sin fecha de vencimiento</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="notificationsEnabled" />
            <span>Notificar membresías por vencer</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="discountsEnabled" />
            <span>Aplicar descuentos a planes</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="freezeAllowed" />
            <span>Permitir membresías congeladas</span>
          </label>
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

      .form-group.checkbox label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
        user-select: none;
        font-weight: normal;
        margin: 0;
      }

      .form-group.checkbox input {
        width: auto !important;
        cursor: pointer;
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
      }

      input:focus,
      select:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.1);
      }

      @media (max-width: 768px) {
        .form-grid {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export default class SettingsMembershipsComponent implements OnInit {
  @Input() settings: any = {};
  @Output() settingsChange = new EventEmitter<any>();

  form: FormGroup;

  constructor(private fb: FormBuilder) {
    this.form = this.fb.group({
      defaultDuration: [30, [Validators.required, Validators.min(1)]],
      expirationReminder: [5, [Validators.required, Validators.min(1)]],
      defaultStatus: ['Activa', Validators.required],
      maxFreezeDays: [30, [Validators.required, Validators.min(0)]],
      autoRenewalEnabled: [true],
      noExpirationAllowed: [false],
      notificationsEnabled: [true],
      discountsEnabled: [true],
      freezeAllowed: [true],
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
