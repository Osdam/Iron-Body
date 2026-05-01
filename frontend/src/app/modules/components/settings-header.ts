import { Component, Input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-settings-header',
  standalone: true,
  imports: [CommonModule],
  template: `
    <header class="settings-header">
      <div class="header-left">
        <h1>Configuración</h1>
        <p>
          Administra los ajustes globales, seguridad, permisos, integraciones y operación del CRM.
        </p>
      </div>

      <div class="header-right">
        <div *ngIf="hasUnsavedChanges" class="unsaved-indicator">
          <span class="material-symbols-outlined">info</span>
          <span>Cambios sin guardar</span>
        </div>

        <button
          type="button"
          class="btn-secondary"
          (click)="onReset.emit()"
          [disabled]="isSaving || !hasUnsavedChanges"
          title="Volver a los valores anteriores"
        >
          <span class="material-symbols-outlined">restart_alt</span>
          Restablecer
        </button>

        <button
          type="button"
          class="btn-primary"
          (click)="onSave.emit()"
          [disabled]="isSaving || !hasUnsavedChanges"
          [class.loading]="isSaving"
        >
          <span *ngIf="!isSaving" class="material-symbols-outlined">check_circle</span>
          <span *ngIf="isSaving" class="material-symbols-outlined spinning">sync</span>
          {{ isSaving ? 'Guardando...' : 'Guardar cambios' }}
        </button>
      </div>
    </header>
  `,
  styles: [
    `
      .settings-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1.5rem;
        background: #ffffff;
        border-bottom: 1px solid #e5e7eb;
        margin-bottom: 2rem;
        gap: 2rem;
      }

      .header-left {
        flex: 1;
      }

      .header-left h1 {
        font-size: 1.875rem;
        font-weight: 600;
        color: #0a0a0a;
        margin: 0;
      }

      .header-left p {
        margin: 0.5rem 0 0 0;
        font-size: 0.875rem;
        color: #6b7280;
      }

      .header-right {
        display: flex;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
        justify-content: flex-end;
      }

      .unsaved-indicator {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background: #fef3c7;
        border: 1px solid #fcd34d;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        color: #92400e;
        white-space: nowrap;
      }

      .unsaved-indicator .material-symbols-outlined {
        font-size: 1.125rem;
      }

      button {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.625rem 1rem;
        border: none;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        white-space: nowrap;
      }

      button:disabled {
        opacity: 0.5;
        cursor: not-allowed;
      }

      button .material-symbols-outlined {
        font-size: 1.125rem;
      }

      button.spinning {
        animation: spin 1s linear infinite;
      }

      @keyframes spin {
        from {
          transform: rotate(0deg);
        }
        to {
          transform: rotate(360deg);
        }
      }

      @media (max-width: 768px) {
        .settings-header {
          flex-direction: column;
          align-items: flex-start;
          padding: 1rem;
          margin-bottom: 1.5rem;
        }

        .header-right {
          width: 100%;
          justify-content: flex-start;
        }
      }
    `,
  ],
})
export default class SettingsHeaderComponent {
  @Input() hasUnsavedChanges = false;
  @Input() isSaving = false;
  @Output() onSave = new EventEmitter<void>();
  @Output() onReset = new EventEmitter<void>();
}
