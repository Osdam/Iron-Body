import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import SettingsHeaderComponent from './components/settings-header';
import SettingsGeneralComponent from './components/settings-general';
import SettingsGymComponent from './components/settings-gym';
import SettingsRolesComponent from './components/settings-roles';
import SettingsPaymentsComponent from './components/settings-payments';
import SettingsNotificationsComponent from './components/settings-notifications';
import SettingsSecurityComponent from './components/settings-security';
import SettingsSystemComponent from './components/settings-system';
import { AuditLogService } from '../services/audit-log.service';

interface SettingsTab {
  id: string;
  label: string;
  icon: string;
}

@Component({
  selector: 'module-settings',
  standalone: true,
  imports: [
    CommonModule,
    SettingsHeaderComponent,
    SettingsGeneralComponent,
    SettingsGymComponent,
    SettingsRolesComponent,
    SettingsPaymentsComponent,
    SettingsNotificationsComponent,
    SettingsSecurityComponent,
    SettingsSystemComponent,
  ],
  template: `
    <section class="settings-page">
      <app-settings-header
        [hasUnsavedChanges]="hasUnsavedChanges()"
        [isSaving]="isSaving()"
        (onSave)="saveSettings()"
        (onReset)="resetSettings()"
      ></app-settings-header>

      <div class="settings-container">
        <nav class="settings-tabs">
          <button
            *ngFor="let tab of tabs"
            type="button"
            class="tab-button"
            [class.active]="selectedTab() === tab.id"
            (click)="selectedTab.set(tab.id)"
            title="{{ tab.label }}"
          >
            <span class="material-symbols-outlined">{{ tab.icon }}</span>
            <span class="tab-label">{{ tab.label }}</span>
          </button>
        </nav>

        <div class="settings-content">
          <!-- General -->
          <div *ngIf="selectedTab() === 'general'" class="tab-pane">
            <app-settings-general
              [settings]="currentSettings().general"
              (settingsChange)="updateSettings('general', $event)"
            ></app-settings-general>
          </div>

          <!-- Gym -->
          <div *ngIf="selectedTab() === 'gym'" class="tab-pane">
            <app-settings-gym
              [settings]="currentSettings().gym"
              (settingsChange)="updateSettings('gym', $event)"
            ></app-settings-gym>
          </div>

          <!-- Roles -->
          <div *ngIf="selectedTab() === 'roles'" class="tab-pane">
            <app-settings-roles></app-settings-roles>
          </div>

          <!-- Payments -->
          <div *ngIf="selectedTab() === 'payments'" class="tab-pane">
            <app-settings-payments
              [settings]="currentSettings().payments"
              (settingsChange)="updateSettings('payments', $event)"
            ></app-settings-payments>
          </div>

          <!-- Notifications -->
          <div *ngIf="selectedTab() === 'notifications'" class="tab-pane">
            <app-settings-notifications></app-settings-notifications>
          </div>

          <!-- Security -->
          <div *ngIf="selectedTab() === 'security'" class="tab-pane">
            <app-settings-security></app-settings-security>
          </div>

          <!-- System -->
          <div *ngIf="selectedTab() === 'system'" class="tab-pane">
            <app-settings-system></app-settings-system>
          </div>
        </div>
      </div>
    </section>
  `,
  styles: [
    `
      .settings-page {
        display: flex;
        flex-direction: column;
        min-height: 100vh;
        background: #f5f5f5;
        gap: 1.5rem;
        padding-bottom: 2rem;
      }

      .settings-container {
        display: flex;
        gap: 2rem;
        max-width: 1400px;
        margin: 0 auto;
        width: 100%;
        padding: 0 1.5rem;
      }

      .settings-tabs {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        width: 200px;
        position: sticky;
        top: 1.5rem;
        height: fit-content;
      }

      .tab-button {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 1rem;
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        cursor: pointer;
        transition: all 0.2s;
        white-space: nowrap;
        text-align: left;
      }

      .tab-button:hover {
        background: #f9fafb;
        border-color: #fbbf24;
      }

      .tab-button.active {
        background: #fbbf24;
        border-color: #fbbf24;
        color: #0a0a0a;
        font-weight: 500;
      }

      .tab-button .material-symbols-outlined {
        font-size: 1.125rem;
        flex-shrink: 0;
      }

      .tab-label {
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .settings-content {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
      }

      .tab-pane {
        animation: fadeIn 0.2s ease-in-out;
      }

      @keyframes fadeIn {
        from {
          opacity: 0;
          transform: translateY(10px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }

      .settings-page {
        background:
          linear-gradient(rgba(12, 12, 12, 0.9), rgba(12, 12, 12, 0.92)),
          url('/assets/crm/clases2.png') center / cover fixed no-repeat;
        color: #e5e2e1;
      }

      .settings-tabs {
        padding: 0.75rem;
        border: 1px solid #353534;
        border-radius: 0.75rem;
        background: rgba(28, 27, 27, 0.88);
      }

      .tab-button {
        background: #1c1b1b;
        border-color: #353534;
        color: #e5e2e1;
      }

      .tab-button:hover {
        background: #201f1f;
        border-color: #f5c518;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.12);
      }

      .tab-button.active {
        background: #f5c518;
        border-color: #f5c518;
        color: #241a00;
      }

      @media (max-width: 1024px) {
        .settings-container {
          flex-direction: column;
          gap: 1rem;
        }

        .settings-tabs {
          position: relative;
          top: auto;
          width: 100%;
          flex-direction: row;
          overflow-x: auto;
          overflow-y: hidden;
          -webkit-overflow-scrolling: touch;
          scroll-snap-type: x mandatory;
        }

        .tab-button {
          scroll-snap-align: start;
          flex-shrink: 0;
        }
      }

      @media (max-width: 640px) {
        .settings-page {
          gap: 1rem;
        }

        .settings-container {
          gap: 0;
          padding: 0;
        }

        .settings-tabs {
          gap: 0;
          border-color: #353534;
          border-bottom: 1px solid #353534;
          background: rgba(28, 27, 27, 0.94);
          padding: 0 1rem;
          margin: 0 -1rem;
        }

        .tab-button {
          border: none;
          border-bottom: 2px solid transparent;
          border-radius: 0;
          background: transparent;
          color: #b8b3b1;
          padding: 1rem 0.75rem;
          flex: 1;
        }

        .tab-button.active {
          background: transparent;
          border-bottom-color: #f5c518;
          color: #f5c518;
          box-shadow: none;
        }

        .settings-content {
          padding: 0 1rem;
        }
      }
    `,
  ],
})
export default class SettingsModule implements OnInit {
  private readonly auditLog = inject(AuditLogService);

  selectedTab = signal('general');
  isSaving = signal(false);
  currentSettings = signal<any>({});
  originalSettings = signal<any>({});

  tabs: SettingsTab[] = [
    { id: 'general', label: 'General', icon: 'settings' },
    { id: 'gym', label: 'Gimnasio', icon: 'business' },
    { id: 'payments', label: 'Pagos', icon: 'payments' },
    { id: 'roles', label: 'Usuarios', icon: 'security' },
    { id: 'notifications', label: 'Notificaciones', icon: 'notifications' },
    { id: 'security', label: 'Seguridad', icon: 'lock' },
    { id: 'system', label: 'Sistema', icon: 'memory' },
  ];

  hasUnsavedChanges = computed(() => {
    const current = JSON.stringify(this.currentSettings());
    const original = JSON.stringify(this.originalSettings());
    return current !== original;
  });

  ngOnInit(): void {
    this.loadSettings();
  }

  private loadSettings(): void {
    const defaults = this.getDefaultSettings();
    const saved = localStorage.getItem('crmSettings');
    if (saved) {
      const settings = this.mergeSettings(defaults, JSON.parse(saved));
      this.currentSettings.set(settings);
      this.originalSettings.set(JSON.parse(JSON.stringify(settings)));
    } else {
      this.currentSettings.set(defaults);
      this.originalSettings.set(defaults);
    }
  }

  private mergeSettings(defaults: any, saved: any): any {
    const settings = {
      ...defaults,
      ...saved,
      general: { ...defaults.general, ...(saved?.general || {}) },
      gym: { ...defaults.gym, ...(saved?.gym || {}) },
      payments: { ...defaults.payments, ...(saved?.payments || {}) },
    };

    settings.payments.defaultStatus = settings.payments.defaultStatus || 'paid';
    settings.payments.autoGenerateReference =
      settings.payments.autoGenerateReference ?? settings.payments.receiptPrefix !== undefined;
    settings.payments.requireReference = settings.payments.requireReference ?? false;
    settings.payments.receiptPrefix = settings.payments.receiptPrefix || 'REC';
    settings.payments.nextReceiptNumber =
      Number(settings.payments.nextReceiptNumber || settings.payments.initialNumber || 1001);

    return settings;
  }

  private getDefaultSettings(): any {
    return {
      general: {
        systemName: 'Iron Body Admin',
        businessName: 'Iron Body Gym',
        currency: 'COP',
        timezone: 'America/Bogota',
        language: 'es',
        dateFormat: 'DD/MM/YYYY',
        timeFormat: '24',
        compactMode: false,
        defaultPage: 'dashboard',
      },
      gym: {
        name: 'Iron Body Gym',
        taxId: '900.123.456-7',
        address: 'Cra 7 # 45-67',
        city: 'Bogotá',
        country: 'Colombia',
        phone: '+57 1 3456789',
        email: 'contacto@ironbody.com',
        website: 'https://ironbody.com',
        openingTime: '06:00',
        closingTime: '22:00',
        operatingDays: ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'],
        maxCapacity: 150,
        description: 'Centro de entrenamiento premium',
      },
      payments: {
        currency: 'COP',
        defaultStatus: 'paid',
        autoGenerateReference: true,
        requireReference: false,
        receiptPrefix: 'REC',
        nextReceiptNumber: 1001,
        allowPartialPayments: false,
        allowCancellation: true,
        requireReason: true,
      },
    };
  }

  updateSettings(section: string, value: any): void {
    const current = { ...this.currentSettings() };
    current[section] = { ...current[section], ...value };
    this.currentSettings.set(current);
  }

  async saveSettings(): Promise<void> {
    this.isSaving.set(true);

    try {
      await new Promise((r) => setTimeout(r, 600));

      // TODO: Guardar en API /api/settings si existe backend
      // Por ahora guardar en localStorage
      const before = JSON.parse(JSON.stringify(this.originalSettings()));
      const after = JSON.parse(JSON.stringify(this.currentSettings()));
      localStorage.setItem('crmSettings', JSON.stringify(this.currentSettings()));
      this.originalSettings.set(JSON.parse(JSON.stringify(this.currentSettings())));
      this.auditLog.record({
        action: 'settings',
        module: 'Configuración',
        entity: 'ajustes generales',
        targetName: this.selectedTab(),
        before,
        after,
      });

      alert('Configuración guardada exitosamente ✓');
    } catch (error) {
      console.error('Error al guardar:', error);
      alert('Error al guardar la configuración');
    } finally {
      this.isSaving.set(false);
    }
  }

  resetSettings(): void {
    const ok = window.confirm('¿Descartar cambios? Volverás a la configuración anterior.');
    if (ok) {
      this.currentSettings.set(JSON.parse(JSON.stringify(this.originalSettings())));
    }
  }
}
