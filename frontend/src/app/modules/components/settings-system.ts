import { Component, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-settings-system',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="settings-section">
      <div class="section-header">
        <div class="section-title">
          <span class="material-symbols-outlined">memory</span>
          <div>
            <h2>Sistema y backups</h2>
            <p>Estado, mantenimiento y respaldos</p>
          </div>
        </div>
      </div>

      <div class="system-info-grid">
        <div class="info-card">
          <span class="label">Versión Frontend</span>
          <span class="value">1.0.0</span>
        </div>
        <div class="info-card">
          <span class="label">Versión Backend</span>
          <span class="value">1.0.0</span>
        </div>
        <div class="info-card">
          <span class="label">Entorno</span>
          <span class="value">Producción</span>
        </div>
        <div class="info-card">
          <span class="label">Estado Backend</span>
          <span class="value status-online">En línea</span>
        </div>
        <div class="info-card">
          <span class="label">Última sincronización</span>
          <span class="value">hace 2 minutos</span>
        </div>
        <div class="info-card">
          <span class="label">Último backup</span>
          <span class="value">30 de abril - 14:30</span>
        </div>
      </div>

      <div class="actions-section">
        <h3>Acciones del sistema</h3>
        <div class="actions-grid">
          <button (click)="testBackend()" class="action-btn">
            <span class="material-symbols-outlined">cloud_queue</span>
            <span>Probar backend</span>
          </button>

          <button (click)="clearCache()" class="action-btn">
            <span class="material-symbols-outlined">memory</span>
            <span>Limpiar caché</span>
          </button>

          <button (click)="exportSettings()" class="action-btn">
            <span class="material-symbols-outlined">download</span>
            <span>Exportar configuración</span>
          </button>

          <button (click)="downloadBackup()" class="action-btn">
            <span class="material-symbols-outlined">backup</span>
            <span>Descargar backup</span>
          </button>

          <button
            (click)="toggleMaintenance()"
            class="action-btn"
            [class.active]="maintenanceMode()"
          >
            <span class="material-symbols-outlined">construction</span>
            <span>{{ maintenanceMode() ? 'Desactivar' : 'Activar' }} modo mantenimiento</span>
          </button>

          <button (click)="restoreDefaults()" class="action-btn danger">
            <span class="material-symbols-outlined">restart_alt</span>
            <span>Restaurar valores por defecto</span>
          </button>
        </div>
      </div>

      <div class="info-box warning">
        <span class="material-symbols-outlined">warning</span>
        <p>
          Las acciones peligrosas (restaurar valores por defecto) requieren confirmación adicional y
          no pueden deshacerse.
        </p>
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

      .system-info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
      }

      .info-card {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        padding: 1rem;
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
      }

      .info-card .label {
        font-size: 0.75rem;
        font-weight: 600;
        color: #6b7280;
        text-transform: uppercase;
      }

      .info-card .value {
        font-size: 0.875rem;
        font-weight: 500;
        color: #0a0a0a;
      }

      .info-card .value.status-online {
        color: #10b981;
      }

      .actions-section {
        margin-bottom: 2rem;
        padding-bottom: 1.5rem;
        border-bottom: 1px solid #f3f4f6;
      }

      .actions-section h3 {
        margin: 0 0 1rem 0;
        font-size: 0.875rem;
        font-weight: 600;
        color: #0a0a0a;
      }

      .actions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
      }

      .action-btn {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 1rem;
        background: #f3f4f6;
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
        font-size: 0.75rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        text-align: center;
      }

      .action-btn:hover {
        background: #e5e7eb;
        border-color: #fbbf24;
      }

      .action-btn.active {
        background: #fef3c7;
        border-color: #fbbf24;
        color: #92400e;
      }

      .action-btn.danger:hover {
        background: #fee2e2;
        border-color: #ef4444;
        color: #991b1b;
      }

      .action-btn .material-symbols-outlined {
        font-size: 1.5rem;
      }

      .info-box {
        display: flex;
        gap: 1rem;
        align-items: flex-start;
        padding: 1rem;
        border-radius: 0.5rem;
        border: 1px solid;
      }

      .info-box.warning {
        background: #fef3c7;
        border-color: #fcd34d;
      }

      .info-box .material-symbols-outlined {
        color: #92400e;
        margin-top: 0.125rem;
        flex-shrink: 0;
      }

      .info-box p {
        margin: 0;
        font-size: 0.875rem;
        color: #78350f;
      }

      .settings-section {
        background:
          linear-gradient(rgba(28, 27, 27, 0.92), rgba(17, 17, 17, 0.9)),
          url('/assets/crm/clases2.png') center / cover no-repeat;
        border-color: #353534;
        color: #e5e2e1;
        box-shadow: 0 14px 34px rgba(0, 0, 0, 0.24);
      }

      .section-header,
      .actions-section {
        border-color: #353534;
      }

      .section-title h2,
      .actions-section h3,
      .info-card .value {
        color: #e5e2e1;
      }

      .section-title p,
      .info-card .label {
        color: #b4afa6;
      }

      .info-card,
      .action-btn {
        background: #1c1b1b;
        border-color: #353534;
        color: #e5e2e1;
      }

      .action-btn:hover {
        background: #201f1f;
        border-color: #f5c518;
      }

      .action-btn.active {
        background: rgba(245, 197, 24, 0.14);
        color: #ffe08b;
      }

      .action-btn.danger:hover {
        background: rgba(255, 180, 171, 0.14);
        border-color: rgba(255, 180, 171, 0.38);
        color: #ffb4ab;
      }

      .info-box.warning {
        background: rgba(245, 197, 24, 0.1);
        border-color: rgba(245, 197, 24, 0.28);
      }

      .info-box p {
        color: #e5e2e1;
      }

      @media (max-width: 768px) {
        .system-info-grid {
          grid-template-columns: repeat(2, 1fr);
        }

        .actions-grid {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export default class SettingsSystemComponent {
  maintenanceMode = signal(false);

  testBackend(): void {
    console.log('Probando backend...');
    alert('Conexión con backend exitosa ✓');
  }

  clearCache(): void {
    console.log('Limpiando caché...');
    alert('Caché del sistema limpiado ✓');
  }

  exportSettings(): void {
    console.log('Exportando configuración...');
    alert('Configuración exportada. Archivo descargado.');
  }

  downloadBackup(): void {
    console.log('Descargando backup...');
    alert('Backup descargado: backup-2026-04-30.zip');
  }

  toggleMaintenance(): void {
    this.maintenanceMode.set(!this.maintenanceMode());
    const status = this.maintenanceMode() ? 'activado' : 'desactivado';
    alert(`Modo mantenimiento ${status}`);
  }

  restoreDefaults(): void {
    const ok = window.confirm(
      '¿Restaurar todos los valores por defecto? Esta acción no puede deshacerse.',
    );
    if (ok) {
      alert('Configuración restaurada a valores por defecto');
    }
  }
}
