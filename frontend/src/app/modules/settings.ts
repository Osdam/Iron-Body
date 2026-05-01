import { Component, OnInit, computed, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import SettingsHeaderComponent from './components/settings-header';
import SettingsGeneralComponent from './components/settings-general';
import SettingsGymComponent from './components/settings-gym';
import SettingsBrandingComponent from './components/settings-branding';
import SettingsRolesComponent from './components/settings-roles';
import SettingsPaymentsComponent from './components/settings-payments';
import SettingsMembershipsComponent from './components/settings-memberships';
import SettingsClassesComponent from './components/settings-classes';
import SettingsRoutinesComponent from './components/settings-routines';
import SettingsTrainersComponent from './components/settings-trainers';
import SettingsMarketingComponent from './components/settings-marketing';
import SettingsNotificationsComponent from './components/settings-notifications';
import SettingsSecurityComponent from './components/settings-security';
import SettingsIntegrationsComponent from './components/settings-integrations';
import SettingsSystemComponent from './components/settings-system';

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
    SettingsBrandingComponent,
    SettingsRolesComponent,
    SettingsPaymentsComponent,
    SettingsMembershipsComponent,
    SettingsClassesComponent,
    SettingsRoutinesComponent,
    SettingsTrainersComponent,
    SettingsMarketingComponent,
    SettingsNotificationsComponent,
    SettingsSecurityComponent,
    SettingsIntegrationsComponent,
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

          <!-- Branding -->
          <div *ngIf="selectedTab() === 'branding'" class="tab-pane">
            <app-settings-branding
              (settingsChange)="updateSettings('branding', $event)"
            ></app-settings-branding>
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

          <!-- Memberships -->
          <div *ngIf="selectedTab() === 'memberships'" class="tab-pane">
            <app-settings-memberships
              [settings]="currentSettings().memberships"
              (settingsChange)="updateSettings('memberships', $event)"
            ></app-settings-memberships>
          </div>

          <!-- Classes -->
          <div *ngIf="selectedTab() === 'classes'" class="tab-pane">
            <app-settings-classes
              [settings]="currentSettings().classes"
              (settingsChange)="updateSettings('classes', $event)"
            ></app-settings-classes>
          </div>

          <!-- Routines -->
          <div *ngIf="selectedTab() === 'routines'" class="tab-pane">
            <app-settings-routines
              [settings]="currentSettings().routines"
              (settingsChange)="updateSettings('routines', $event)"
            ></app-settings-routines>
          </div>

          <!-- Trainers -->
          <div *ngIf="selectedTab() === 'trainers'" class="tab-pane">
            <app-settings-trainers
              [settings]="currentSettings().trainers"
              (settingsChange)="updateSettings('trainers', $event)"
            ></app-settings-trainers>
          </div>

          <!-- Marketing -->
          <div *ngIf="selectedTab() === 'marketing'" class="tab-pane">
            <app-settings-marketing
              [settings]="currentSettings().marketing"
              (settingsChange)="updateSettings('marketing', $event)"
            ></app-settings-marketing>
          </div>

          <!-- Notifications -->
          <div *ngIf="selectedTab() === 'notifications'" class="tab-pane">
            <app-settings-notifications></app-settings-notifications>
          </div>

          <!-- Security -->
          <div *ngIf="selectedTab() === 'security'" class="tab-pane">
            <app-settings-security></app-settings-security>
          </div>

          <!-- Integrations -->
          <div *ngIf="selectedTab() === 'integrations'" class="tab-pane">
            <app-settings-integrations></app-settings-integrations>
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
          border-bottom: 1px solid #e5e7eb;
          background: #ffffff;
          padding: 0 1rem;
          margin: 0 -1rem;
        }

        .tab-button {
          border: none;
          border-bottom: 2px solid transparent;
          border-radius: 0;
          padding: 1rem 0.75rem;
          flex: 1;
        }

        .tab-button.active {
          background: transparent;
          border-bottom-color: #fbbf24;
        }

        .settings-content {
          padding: 0 1rem;
        }
      }
    `,
  ],
})
export default class SettingsModule implements OnInit {
  selectedTab = signal('general');
  isSaving = signal(false);
  currentSettings = signal<any>({});
  originalSettings = signal<any>({});

  tabs: SettingsTab[] = [
    { id: 'general', label: 'General', icon: 'settings' },
    { id: 'gym', label: 'Gimnasio', icon: 'business' },
    { id: 'branding', label: 'Identidad', icon: 'palette' },
    { id: 'roles', label: 'Usuarios', icon: 'security' },
    { id: 'payments', label: 'Pagos', icon: 'payments' },
    { id: 'memberships', label: 'Membresías', icon: 'card_membership' },
    { id: 'classes', label: 'Clases', icon: 'fitness_center' },
    { id: 'routines', label: 'Rutinas', icon: 'list_alt' },
    { id: 'trainers', label: 'Entrenadores', icon: 'person' },
    { id: 'marketing', label: 'Mercadeo', icon: 'campaign' },
    { id: 'notifications', label: 'Notificaciones', icon: 'notifications' },
    { id: 'security', label: 'Seguridad', icon: 'lock' },
    { id: 'integrations', label: 'Integraciones', icon: 'integration_instructions' },
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
    // TODO: Cargar desde API /api/settings si existe backend
    // Por ahora cargar desde localStorage
    const saved = localStorage.getItem('crmSettings');
    if (saved) {
      this.currentSettings.set(JSON.parse(saved));
      this.originalSettings.set(JSON.parse(saved));
    } else {
      const defaultSettings = this.getDefaultSettings();
      this.currentSettings.set(defaultSettings);
      this.originalSettings.set(defaultSettings);
    }
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
      branding: {
        logoUrl: '',
        sidebarLogoUrl: '',
        bannerUrl: '',
        primaryColor: '#fbbf24',
        secondaryColor: '#1f2937',
      },
      payments: {
        currency: 'COP',
        defaultMethod: 'Cash',
        graceDays: 3,
        reminderDays: 5,
        taxPercentage: 19,
        receiptPrefix: 'FACT',
        initialNumber: 1001,
        allowPartialPayments: true,
        allowCancellation: true,
        requireReason: true,
        enabledMethods: ['Efectivo', 'Transferencia', 'Tarjeta'],
      },
      memberships: {
        defaultDuration: 30,
        expirationReminder: 5,
        defaultStatus: 'Activa',
        maxFreezeDays: 30,
        autoRenewalEnabled: true,
        noExpirationAllowed: false,
        notificationsEnabled: true,
        discountsEnabled: true,
        freezeAllowed: true,
      },
      classes: {
        defaultCapacity: 30,
        defaultDuration: 60,
        cancellationMinHours: 12,
        onlineBookingEnabled: true,
        requireActivePlan: true,
        recurringEnabled: true,
        notificationEnabled: true,
        defaultView: 'weekly',
        defaultStatus: 'Activa',
        showMonthly: true,
        showWeekly: true,
      },
      routines: {
        defaultDuration: 60,
        defaultLevel: 'Intermedio',
        defaultObjective: 'Hipertrofia',
        weightUnit: 'kg',
        allowGeneralTemplates: true,
        allowMultipleAssignments: true,
        requireTrainer: false,
        exerciseLibraryEnabled: true,
        allowDuplicate: true,
      },
      trainers: {
        defaultSpecialty: 'Musculación',
        defaultContract: 'Tiempo completo',
        defaultStatus: 'Activo',
        weeklyAvailabilityEnabled: true,
        allowMemberAssignment: true,
        allowClassAssignment: true,
        ratingEnabled: true,
        certificationsRequired: false,
      },
      marketing: {
        defaultChannel: 'WhatsApp',
        defaultSegment: 'Todos los miembros',
        defaultCouponDays: 30,
        defaultCouponUsage: 100,
        automaticCampaignsEnabled: true,
        couponsEnabled: true,
        expirationCampaignEnabled: true,
        inactiveMembersCampaignEnabled: true,
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
      localStorage.setItem('crmSettings', JSON.stringify(this.currentSettings()));
      this.originalSettings.set(JSON.parse(JSON.stringify(this.currentSettings())));

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
