import { Component, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

interface Integration {
  id: string;
  name: string;
  description: string;
  status: 'connected' | 'disconnected' | 'error';
  lastCheck?: string;
  fields?: any;
}

@Component({
  selector: 'app-settings-integrations',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="settings-section">
      <div class="section-header">
        <div class="section-title">
          <span class="material-symbols-outlined">integration_instructions</span>
          <div>
            <h2>Integraciones</h2>
            <p>Conecta APIs y servicios externos</p>
          </div>
        </div>
      </div>

      <div class="integrations-grid">
        <div
          *ngFor="let integration of integrations()"
          class="integration-card"
          [ngClass]="'status-' + integration.status"
        >
          <div class="card-header">
            <h3>{{ integration.name }}</h3>
            <span class="status-badge" [ngClass]="'status-' + integration.status">
              {{
                integration.status === 'connected'
                  ? 'Conectado'
                  : integration.status === 'error'
                    ? 'Error'
                    : 'No conectado'
              }}
            </span>
          </div>

          <p class="description">{{ integration.description }}</p>

          <div *ngIf="integration.lastCheck" class="last-check">
            Última verificación: {{ integration.lastCheck }}
          </div>

          <div class="card-actions">
            <button type="button" class="btn-secondary" (click)="testIntegration(integration.id)">
              <span class="material-symbols-outlined">science</span>
              Probar
            </button>
            <button
              type="button"
              class="btn-primary"
              (click)="configureIntegration(integration.id)"
            >
              <span class="material-symbols-outlined">settings</span>
              Configurar
            </button>
          </div>
        </div>
      </div>

      <div class="info-box">
        <span class="material-symbols-outlined">info</span>
        <p>
          Los campos sensibles (tokens, contraseñas) no se mostrarán por seguridad. Contacta al
          equipo de soporte para problemas de conexión.
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

      .integrations-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 1.5rem;
        margin-bottom: 1.5rem;
      }

      .integration-card {
        border: 1px solid #e5e7eb;
        border-radius: 0.75rem;
        padding: 1.25rem;
        background: #f9fafb;
        transition: all 0.2s;
      }

      .integration-card.status-connected {
        border-left: 4px solid #10b981;
      }

      .integration-card.status-disconnected {
        border-left: 4px solid #d1d5db;
      }

      .integration-card.status-error {
        border-left: 4px solid #ef4444;
      }

      .card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.75rem;
        gap: 1rem;
      }

      .card-header h3 {
        margin: 0;
        font-size: 0.875rem;
        font-weight: 600;
        color: #0a0a0a;
      }

      .status-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: 500;
        white-space: nowrap;
      }

      .status-badge.status-connected {
        background: #d1fae5;
        color: #065f46;
      }

      .status-badge.status-disconnected {
        background: #f3f4f6;
        color: #6b7280;
      }

      .status-badge.status-error {
        background: #fee2e2;
        color: #991b1b;
      }

      .description {
        font-size: 0.875rem;
        color: #6b7280;
        margin: 0 0 0.75rem 0;
        line-height: 1.4;
      }

      .last-check {
        font-size: 0.75rem;
        color: #9ca3af;
        margin-bottom: 1rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid #e5e7eb;
      }

      .card-actions {
        display: flex;
        gap: 0.75rem;
      }

      button {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex: 1;
        padding: 0.5rem 0.75rem;
        border: none;
        border-radius: 0.5rem;
        font-size: 0.75rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
      }

      button .material-symbols-outlined {
        font-size: 1rem;
      }

      .btn-secondary {
        background: #e5e7eb;
        color: #0a0a0a;
      }

      .btn-secondary:hover {
        background: #d1d5db;
      }

      .btn-primary {
        background: #fbbf24;
        color: #0a0a0a;
      }

      .btn-primary:hover {
        background: #f59e0b;
      }

      .info-box {
        display: flex;
        gap: 1rem;
        align-items: flex-start;
        padding: 1rem;
        background: #dbeafe;
        border: 1px solid #bfdbfe;
        border-radius: 0.5rem;
      }

      .info-box .material-symbols-outlined {
        color: #1e40af;
        margin-top: 0.125rem;
        flex-shrink: 0;
      }

      .info-box p {
        margin: 0;
        font-size: 0.875rem;
        color: #1e3a8a;
      }

      @media (max-width: 768px) {
        .integrations-grid {
          grid-template-columns: 1fr;
        }

        button {
          justify-content: center;
        }
      }
    `,
  ],
})
export default class SettingsIntegrationsComponent implements OnInit {
  integrations = signal<Integration[]>([]);

  ngOnInit(): void {
    this.integrations.set([
      {
        id: 'laravel-backend',
        name: 'Backend Laravel',
        description: 'API principal del CRM. Estado de conexión y sincronización.',
        status: 'connected',
        lastCheck: 'hace 2 minutos',
      },
      {
        id: 'whatsapp-api',
        name: 'WhatsApp Business API',
        description: 'Envío de mensajes y notificaciones por WhatsApp.',
        status: 'connected',
        lastCheck: 'hace 5 minutos',
      },
      {
        id: 'smtp',
        name: 'SMTP / Correo',
        description: 'Envío de correos electrónicos y notificaciones.',
        status: 'disconnected',
      },
      {
        id: 'payment-gateway',
        name: 'Pasarela de pagos',
        description: 'Procesamiento de pagos online.',
        status: 'error',
        lastCheck: 'hace 10 minutos',
      },
      {
        id: 'google-calendar',
        name: 'Google Calendar',
        description: 'Sincronización de clases con calendario.',
        status: 'disconnected',
      },
      {
        id: 'n8n',
        name: 'n8n Webhooks',
        description: 'Automatizaciones y flujos personalizados.',
        status: 'connected',
        lastCheck: 'hace 1 minuto',
      },
    ]);
  }

  testIntegration(integrationId: string): void {
    console.log(`Probando integración: ${integrationId}`);
    alert(`Conexión probada para: ${integrationId}`);
  }

  configureIntegration(integrationId: string): void {
    console.log(`Configurar integración: ${integrationId}`);
    alert(`Abriendo configuración para: ${integrationId}`);
  }
}
