import { Component, Input, Output, EventEmitter, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';

@Component({
  selector: 'app-settings-classes',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  template: `
    <div class="settings-section">
      <div class="section-header">
        <div class="section-title">
          <span class="material-symbols-outlined">fitness_center</span>
          <div>
            <h2>Configuración de clases</h2>
            <p>Cupos, duración y política de inscripción</p>
          </div>
        </div>
      </div>

      <form [formGroup]="form" class="form-grid">
        <div class="form-group">
          <label for="defaultCapacity">Cupo máximo predeterminado</label>
          <input type="number" id="defaultCapacity" formControlName="defaultCapacity" min="1" />
        </div>

        <div class="form-group">
          <label for="defaultDuration">Duración predeterminada (minutos)</label>
          <input
            type="number"
            id="defaultDuration"
            formControlName="defaultDuration"
            min="15"
            step="15"
          />
        </div>

        <div class="form-group">
          <label for="cancellationMinHours">Tiempo mínimo antes para cancelar (horas)</label>
          <input
            type="number"
            id="cancellationMinHours"
            formControlName="cancellationMinHours"
            min="0"
          />
        </div>

        <div class="form-group">
          <label for="defaultView">Vista predeterminada</label>
          <select id="defaultView" formControlName="defaultView">
            <option value="monthly">Mensual</option>
            <option value="weekly">Semanal</option>
            <option value="daily">Diaria</option>
            <option value="list">Lista</option>
          </select>
        </div>

        <div class="form-group">
          <label for="defaultStatus">Estado predeterminado</label>
          <select id="defaultStatus" formControlName="defaultStatus">
            <option value="Activa">Activa</option>
            <option value="Inactiva">Inactiva</option>
            <option value="Finalizada">Finalizada</option>
          </select>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="onlineBookingEnabled" />
            <span>Permitir inscripción online</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="requireActivePlan" />
            <span>Requerir plan activo para inscribirse</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="recurringEnabled" />
            <span>Permitir clases recurrentes</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="notificationEnabled" />
            <span>Notificar clase próxima</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="showMonthly" />
            <span>Mostrar calendario mensual</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="showWeekly" />
            <span>Mostrar calendario semanal</span>
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
export default class SettingsClassesComponent implements OnInit {
  @Input() settings: any = {};
  @Output() settingsChange = new EventEmitter<any>();

  form: FormGroup;

  constructor(private fb: FormBuilder) {
    this.form = this.fb.group({
      defaultCapacity: [30, [Validators.required, Validators.min(1)]],
      defaultDuration: [60, [Validators.required, Validators.min(15)]],
      cancellationMinHours: [12, [Validators.required, Validators.min(0)]],
      onlineBookingEnabled: [true],
      requireActivePlan: [true],
      recurringEnabled: [true],
      notificationEnabled: [true],
      defaultView: ['weekly', Validators.required],
      defaultStatus: ['Activa', Validators.required],
      showMonthly: [true],
      showWeekly: [true],
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
