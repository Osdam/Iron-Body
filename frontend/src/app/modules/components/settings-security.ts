import { Component, Input, Output, EventEmitter, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';

@Component({
  selector: 'app-settings-security',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  template: `
    <div class="settings-section">
      <div class="section-header">
        <div class="section-title">
          <span class="material-symbols-outlined">lock</span>
          <div>
            <h2>Seguridad</h2>
            <p>Contraseña, sesión y logs</p>
          </div>
        </div>
      </div>

      <form [formGroup]="form" class="form-grid">
        <div class="form-group">
          <label for="minPasswordLength">Longitud mínima de contraseña</label>
          <input
            type="number"
            id="minPasswordLength"
            formControlName="minPasswordLength"
            min="6"
            max="20"
          />
        </div>

        <div class="form-group">
          <label for="sessionTimeout">Tiempo de sesión (minutos)</label>
          <input type="number" id="sessionTimeout" formControlName="sessionTimeout" min="5" />
        </div>

        <div class="form-group">
          <label for="maxFailedAttempts">Máximo de intentos fallidos</label>
          <input
            type="number"
            id="maxFailedAttempts"
            formControlName="maxFailedAttempts"
            min="3"
            max="10"
          />
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="strongPasswordRequired" />
            <span>Requerir contraseña segura (mayúsculas, números, símbolos)</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="autoLogoutEnabled" />
            <span>Cierre automático por inactividad</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="auditLogsEnabled" />
            <span>Activar logs de auditoría</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="activeUsersOnly" />
            <span>Solo permitir usuarios activos</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="lockAfterFailedAttempts" />
            <span>Bloquear usuario tras intentos fallidos</span>
          </label>
        </div>

        <div class="form-group full-width">
          <label class="checkbox">
            <input type="checkbox" formControlName="automaticBackupEnabled" />
            <span>Activar backups automáticos</span>
          </label>
        </div>
      </form>

      <div class="separator"></div>

      <div class="password-section">
        <h3>Cambiar contraseña del administrador</h3>
        <div class="password-form">
          <div class="form-group">
            <label for="currentPassword">Contraseña actual</label>
            <input
              type="password"
              id="currentPassword"
              placeholder="Ingresa tu contraseña actual"
            />
          </div>
          <div class="form-group">
            <label for="newPassword">Nueva contraseña</label>
            <input type="password" id="newPassword" placeholder="Ingresa nueva contraseña" />
          </div>
          <div class="form-group">
            <label for="confirmPassword">Confirmar contraseña</label>
            <input
              type="password"
              id="confirmPassword"
              placeholder="Confirma tu nueva contraseña"
            />
          </div>
          <button type="button" class="btn-save">Cambiar contraseña</button>
        </div>
      </div>
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

      input {
        padding: 0.625rem 0.75rem;
        border: 1px solid #d1d5db;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-family: inherit;
      }

      input:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.1);
      }

      .separator {
        height: 1px;
        background: #f3f4f6;
        margin: 2rem 0;
      }

      .password-section {
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid #f3f4f6;
      }

      .password-section h3 {
        margin: 0 0 1rem 0;
        font-size: 0.875rem;
        font-weight: 600;
        color: #0a0a0a;
      }

      .password-form {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1rem;
        max-width: 600px;
      }

      .btn-save {
        grid-column: 1 / -1;
        padding: 0.625rem 1rem;
        background: #fbbf24;
        color: #0a0a0a;
        border: none;
        border-radius: 0.5rem;
        font-weight: 500;
        font-size: 0.875rem;
        cursor: pointer;
        transition: all 0.2s;
      }

      .btn-save:hover {
        background: #f59e0b;
      }

      @media (max-width: 768px) {
        .form-grid {
          grid-template-columns: 1fr;
        }

        .password-form {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export default class SettingsSecurityComponent implements OnInit {
  form: FormGroup;

  constructor(private fb: FormBuilder) {
    this.form = this.fb.group({
      minPasswordLength: [8, [Validators.required, Validators.min(6), Validators.max(20)]],
      sessionTimeout: [30, [Validators.required, Validators.min(5)]],
      maxFailedAttempts: [5, [Validators.required, Validators.min(3), Validators.max(10)]],
      strongPasswordRequired: [true],
      autoLogoutEnabled: [true],
      auditLogsEnabled: [true],
      activeUsersOnly: [true],
      lockAfterFailedAttempts: [true],
      automaticBackupEnabled: [true],
    });
  }

  ngOnInit(): void {
    // Load settings if needed
  }
}
