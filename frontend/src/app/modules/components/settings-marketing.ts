import { Component, Input, Output, EventEmitter, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';

@Component({
  selector: 'app-settings-marketing',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  template: `
    <div class="settings-section">
      <div class="section-header">
        <div class="section-title">
          <span class="material-symbols-outlined">campaign</span>
          <div>
            <h2>Configuración de mercadeo</h2>
            <p>Campañas, cupones y comunicaciones</p>
          </div>
        </div>
      </div>

      <form [formGroup]="form" class="form-grid">
        <div class="form-group">
          <label for="defaultChannel">Canal predeterminado</label>
          <select id="defaultChannel" formControlName="defaultChannel">
            <option value="WhatsApp">WhatsApp</option>
            <option value="Correo electrónico">Correo electrónico</option>
            <option value="SMS">SMS</option>
            <option value="Notificación interna">Notificación interna</option>
            <option value="Redes sociales">Redes sociales</option>
          </select>
        </div>

        <div class="form-group">
          <label for="defaultSegment">Segmento predeterminado</label>
          <select id="defaultSegment" formControlName="defaultSegment">
            <option value="Todos los miembros">Todos los miembros</option>
            <option value="Miembros activos">Miembros activos</option>
            <option value="Miembros inactivos">Miembros inactivos</option>
            <option value="Membresías por vencer">Membresías por vencer</option>
          </select>
        </div>

        <div class="form-group">
          <label for="defaultCouponDays">Duración predeterminada de cupón (días)</label>
          <input type="number" id="defaultCouponDays" formControlName="defaultCouponDays" min="1" />
        </div>

        <div class="form-group">
          <label for="defaultCouponUsage">Límite predeterminado de usos de cupón</label>
          <input
            type="number"
            id="defaultCouponUsage"
            formControlName="defaultCouponUsage"
            min="1"
          />
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="automaticCampaignsEnabled" />
            <span>Permitir campañas automáticas</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="couponsEnabled" />
            <span>Permitir cupones</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="expirationCampaignEnabled" />
            <span>Notificar membresías por vencer</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="inactiveMembersCampaignEnabled" />
            <span>Notificar miembros inactivos</span>
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
export default class SettingsMarketingComponent implements OnInit {
  @Input() settings: any = {};
  @Output() settingsChange = new EventEmitter<any>();

  form: FormGroup;

  constructor(private fb: FormBuilder) {
    this.form = this.fb.group({
      defaultChannel: ['WhatsApp', Validators.required],
      defaultSegment: ['Todos los miembros', Validators.required],
      defaultCouponDays: [30, [Validators.required, Validators.min(1)]],
      defaultCouponUsage: [100, [Validators.required, Validators.min(1)]],
      automaticCampaignsEnabled: [true],
      couponsEnabled: [true],
      expirationCampaignEnabled: [true],
      inactiveMembersCampaignEnabled: [true],
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
