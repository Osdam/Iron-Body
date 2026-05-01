import { Component, Input, Output, EventEmitter, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';

@Component({
  selector: 'app-settings-payments',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  template: `
    <div class="settings-section">
      <div class="section-header">
        <div class="section-title">
          <span class="material-symbols-outlined">payments</span>
          <div>
            <h2>Configuración de pagos</h2>
            <p>Métodos, impuestos y políticas de pago</p>
          </div>
        </div>
      </div>

      <form [formGroup]="form" class="form-grid">
        <div class="form-group">
          <label for="currency">Moneda</label>
          <select id="currency" formControlName="currency">
            <option value="COP">COP - Peso colombiano</option>
            <option value="USD">USD - Dólar</option>
          </select>
        </div>

        <div class="form-group">
          <label for="defaultMethod">Método predeterminado</label>
          <select id="defaultMethod" formControlName="defaultMethod">
            <option value="Cash">Efectivo</option>
            <option value="Transfer">Transferencia</option>
            <option value="Card">Tarjeta</option>
          </select>
        </div>

        <div class="form-group">
          <label for="graceDays">Días de gracia</label>
          <input type="number" id="graceDays" formControlName="graceDays" min="0" />
        </div>

        <div class="form-group">
          <label for="reminderDays">Recordatorio antes del vencimiento (días)</label>
          <input type="number" id="reminderDays" formControlName="reminderDays" min="0" />
        </div>

        <div class="form-group">
          <label for="taxPercentage">Impuesto / IVA (%)</label>
          <input
            type="number"
            id="taxPercentage"
            formControlName="taxPercentage"
            min="0"
            max="100"
            step="0.01"
          />
        </div>

        <div class="form-group">
          <label for="receiptPrefix">Prefijo de recibo</label>
          <input
            type="text"
            id="receiptPrefix"
            formControlName="receiptPrefix"
            placeholder="FACT"
          />
        </div>

        <div class="form-group">
          <label for="initialNumber">Numeración inicial</label>
          <input type="number" id="initialNumber" formControlName="initialNumber" min="1" />
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="allowPartialPayments" />
            <span>Permitir pagos parciales</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="allowCancellation" />
            <span>Permitir anulación de pagos</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="requireReason" />
            <span>Requerir observación al anular pago</span>
          </label>
        </div>

        <div class="form-group full-width methods">
          <label>Métodos habilitados</label>
          <div class="methods-grid">
            <label class="method-checkbox" *ngFor="let method of methods">
              <input
                type="checkbox"
                [checked]="enabledMethods().includes(method)"
                (change)="toggleMethod(method, $event)"
              />
              <span>{{ method }}</span>
            </label>
          </div>
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
        display: flex;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
        user-select: none;
        margin: 0;
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

      .methods-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 1rem;
        margin-top: 0.75rem;
      }

      .method-checkbox {
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

      .method-checkbox:hover {
        background: #f9fafb;
      }

      .method-checkbox input[type='checkbox'] {
        width: auto !important;
        cursor: pointer;
      }

      .method-checkbox span {
        font-size: 0.875rem;
        color: #0a0a0a;
      }

      @media (max-width: 768px) {
        .form-grid {
          grid-template-columns: 1fr;
        }

        .methods-grid {
          grid-template-columns: repeat(2, 1fr);
        }
      }
    `,
  ],
})
export default class SettingsPaymentsComponent implements OnInit {
  @Input() settings: any = {};
  @Output() settingsChange = new EventEmitter<any>();

  form: FormGroup;
  methods = ['Efectivo', 'Transferencia', 'Tarjeta', 'Nequi', 'Daviplata', 'Otro'];

  constructor(private fb: FormBuilder) {
    this.form = this.fb.group({
      currency: ['COP', Validators.required],
      defaultMethod: ['Cash', Validators.required],
      graceDays: [3, [Validators.required, Validators.min(0)]],
      reminderDays: [5, [Validators.required, Validators.min(0)]],
      taxPercentage: [19, [Validators.required, Validators.min(0), Validators.max(100)]],
      receiptPrefix: ['FACT', Validators.required],
      initialNumber: [1001, [Validators.required, Validators.min(1)]],
      allowPartialPayments: [true],
      allowCancellation: [true],
      requireReason: [true],
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

  enabledMethods(): string[] {
    return this.settings.enabledMethods || ['Efectivo', 'Transferencia', 'Tarjeta'];
  }

  toggleMethod(method: string, event: Event): void {
    const checked = (event.target as HTMLInputElement).checked;
    const current = this.settings.enabledMethods || [];
    let updated: string[];

    if (checked) {
      updated = [...current, method];
    } else {
      updated = current.filter((m: string) => m !== method);
    }

    this.settings = { ...this.settings, enabledMethods: updated };
    this.settingsChange.emit(this.settings);
  }
}
