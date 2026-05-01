import { Component, Input, Output, EventEmitter, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';

@Component({
  selector: 'app-settings-routines',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  template: `
    <div class="settings-section">
      <div class="section-header">
        <div class="section-title">
          <span class="material-symbols-outlined">list_alt</span>
          <div>
            <h2>Configuración de rutinas</h2>
            <p>Duración, nivel y objetivos predeterminados</p>
          </div>
        </div>
      </div>

      <form [formGroup]="form" class="form-grid">
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
          <label for="defaultLevel">Nivel predeterminado</label>
          <select id="defaultLevel" formControlName="defaultLevel">
            <option value="Principiante">Principiante</option>
            <option value="Intermedio">Intermedio</option>
            <option value="Avanzado">Avanzado</option>
          </select>
        </div>

        <div class="form-group">
          <label for="defaultObjective">Objetivo predeterminado</label>
          <select id="defaultObjective" formControlName="defaultObjective">
            <option value="">Seleccionar...</option>
            <option value="Hipertrofia">Hipertrofia</option>
            <option value="Fuerza">Fuerza</option>
            <option value="Pérdida de grasa">Pérdida de grasa</option>
            <option value="Resistencia">Resistencia</option>
            <option value="Funcional">Funcional</option>
            <option value="Rehabilitación">Rehabilitación</option>
          </select>
        </div>

        <div class="form-group">
          <label for="weightUnit">Unidades de peso</label>
          <select id="weightUnit" formControlName="weightUnit">
            <option value="kg">Kilogramos (kg)</option>
            <option value="lb">Libras (lb)</option>
          </select>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="allowGeneralTemplates" />
            <span>Permitir rutinas como plantilla general</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="allowMultipleAssignments" />
            <span>Permitir asignar rutina a varios miembros</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="requireTrainer" />
            <span>Requerir entrenador asignado</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="exerciseLibraryEnabled" />
            <span>Mostrar biblioteca de ejercicios</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="allowDuplicate" />
            <span>Permitir duplicar rutinas</span>
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
export default class SettingsRoutinesComponent implements OnInit {
  @Input() settings: any = {};
  @Output() settingsChange = new EventEmitter<any>();

  form: FormGroup;

  constructor(private fb: FormBuilder) {
    this.form = this.fb.group({
      defaultDuration: [60, [Validators.required, Validators.min(15)]],
      defaultLevel: ['Intermedio', Validators.required],
      defaultObjective: ['Hipertrofia', Validators.required],
      weightUnit: ['kg', Validators.required],
      allowGeneralTemplates: [true],
      allowMultipleAssignments: [true],
      requireTrainer: [false],
      exerciseLibraryEnabled: [true],
      allowDuplicate: [true],
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
