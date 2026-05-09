import { Component, EventEmitter, Input, OnInit, Output } from '@angular/core';
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
            <h2>Pagos</h2>
            <p>Reglas reales para registrar cobros y generar referencias.</p>
          </div>
        </div>
        <span class="currency-pill">COP</span>
      </div>

      <form [formGroup]="form" class="form-grid">
        <div class="info-card full-width">
          <span class="material-symbols-outlined" aria-hidden="true">paid</span>
          <div>
            <strong>Moneda fija: peso colombiano</strong>
            <p>Todos los pagos se registran en COP. Esta opción no se cambia desde configuración.</p>
          </div>
        </div>

        <div class="form-group">
          <label for="defaultStatus">Estado inicial del pago</label>
          <select id="defaultStatus" formControlName="defaultStatus">
            <option value="paid">Pagado</option>
            <option value="pending">Pendiente</option>
          </select>
          <small>Define cómo se abre el modal de registrar pago.</small>
        </div>

        <div class="form-group">
          <label for="receiptPrefix">Prefijo de recibo</label>
          <input id="receiptPrefix" type="text" formControlName="receiptPrefix" placeholder="REC" />
          <small>Ejemplo: REC-1001</small>
        </div>

        <div class="form-group">
          <label for="nextReceiptNumber">Siguiente número</label>
          <input id="nextReceiptNumber" type="number" min="1" formControlName="nextReceiptNumber" />
          <small>Se incrementa automáticamente al guardar un pago con referencia generada.</small>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="autoGenerateReference" />
            <span>Generar referencia automáticamente al abrir el modal de pago</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="requireReference" />
            <span>Exigir referencia antes de guardar el pago</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="allowCancellation" />
            <span>Permitir anulación de pagos desde el historial</span>
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
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
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
        font-weight: 700;
        color: #0a0a0a;
        margin: 0;
      }

      .section-title p {
        font-size: 0.875rem;
        color: #6b7280;
        margin: 0.25rem 0 0;
      }

      .currency-pill {
        display: inline-flex;
        align-items: center;
        min-height: 32px;
        padding: 0 0.8rem;
        border-radius: 999px;
        background: #fef3c7;
        color: #92400e;
        font-size: 0.82rem;
        font-weight: 850;
      }

      .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.25rem;
      }

      .full-width {
        grid-column: 1 / -1;
      }

      .info-card {
        display: flex;
        gap: 0.9rem;
        align-items: flex-start;
        padding: 1rem;
        border: 1px solid #fde68a;
        border-radius: 0.75rem;
        background: #fffbeb;
      }

      .info-card .material-symbols-outlined {
        color: #ca8a04;
      }

      .info-card strong {
        display: block;
        color: #111827;
        font-weight: 850;
      }

      .info-card p {
        margin: 0.25rem 0 0;
        color: #57534e;
        font-size: 0.86rem;
      }

      .form-group {
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
      }

      label {
        font-size: 0.875rem;
        font-weight: 700;
        color: #0a0a0a;
      }

      input,
      select {
        padding: 0.7rem 0.8rem;
        border: 1px solid #d1d5db;
        border-radius: 0.5rem;
        font-size: 0.9rem;
        font-family: inherit;
        background: #ffffff;
      }

      input:focus,
      select:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.12);
      }

      small {
        color: #6b7280;
        font-size: 0.78rem;
      }

      .checkbox {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        padding: 0.85rem 1rem;
        border: 1px solid #e5e7eb;
        border-radius: 0.65rem;
        background: #fafafa;
        cursor: pointer;
      }

      .checkbox input {
        width: auto;
        cursor: pointer;
      }

      @media (max-width: 768px) {
        .form-grid {
          grid-template-columns: 1fr;
        }

        .section-header {
          flex-direction: column;
        }
      }
    `,
  ],
})
export default class SettingsPaymentsComponent implements OnInit {
  @Input() settings: any = {};
  @Output() settingsChange = new EventEmitter<any>();

  form: FormGroup;

  constructor(private fb: FormBuilder) {
    this.form = this.fb.group({
      currency: ['COP', Validators.required],
      defaultStatus: ['paid', Validators.required],
      receiptPrefix: ['REC', Validators.required],
      nextReceiptNumber: [1001, [Validators.required, Validators.min(1)]],
      autoGenerateReference: [true],
      requireReference: [false],
      allowCancellation: [true],
    });

    this.form.valueChanges.subscribe((value) => {
      this.settingsChange.emit({ ...value, currency: 'COP' });
    });
  }

  ngOnInit(): void {
    if (this.settings && Object.keys(this.settings).length > 0) {
      this.form.patchValue({ ...this.settings, currency: 'COP' }, { emitEvent: false });
    }
  }
}
