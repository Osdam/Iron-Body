import { Component, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

@Component({
  selector: 'app-settings-notifications',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="settings-section">
      <div class="section-header">
        <div class="section-title">
          <span class="material-symbols-outlined">notifications</span>
          <div>
            <h2>Notificaciones</h2>
            <p>Canales y plantillas de notificación</p>
          </div>
        </div>
      </div>

      <div class="notifications-grid">
        <div *ngFor="let event of notificationEvents()" class="notification-card">
          <div class="card-header">
            <h3>{{ event.name }}</h3>
            <div class="channels">
              <label
                class="channel-checkbox"
                *ngFor="let channel of ['Sistema', 'WhatsApp', 'Correo', 'SMS']"
              >
                <input
                  type="checkbox"
                  [checked]="event.channels.includes(channel)"
                  (change)="toggleChannel(event.id, channel, $event)"
                />
                <span>{{ channel }}</span>
              </label>
            </div>
          </div>
        </div>
      </div>

      <div class="templates-section">
        <h3 class="section-subtitle">Plantillas editables</h3>
        <div *ngFor="let template of templates()" class="template-card">
          <label>{{ template.name }}</label>
          <textarea
            [(ngModel)]="template.content"
            (ngModelChange)="onTemplateChange(template.id)"
            [attr.maxlength]="300"
            rows="3"
          ></textarea>
          <div class="character-count">{{ template.content.length }} / 300 caracteres</div>
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

      .notifications-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
      }

      .notification-card {
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
        padding: 1rem;
        background: #f9fafb;
      }

      .card-header h3 {
        margin: 0 0 0.75rem 0;
        font-size: 0.875rem;
        font-weight: 600;
        color: #0a0a0a;
      }

      .channels {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.5rem;
      }

      .channel-checkbox {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
        user-select: none;
        font-size: 0.75rem;
      }

      .channel-checkbox input {
        width: auto !important;
        cursor: pointer;
      }

      .section-subtitle {
        font-size: 0.875rem;
        font-weight: 600;
        color: #0a0a0a;
        margin: 0 0 1rem 0;
      }

      .templates-section {
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid #f3f4f6;
      }

      .template-card {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
      }

      .template-card label {
        font-size: 0.875rem;
        font-weight: 500;
        color: #0a0a0a;
      }

      textarea {
        padding: 0.625rem 0.75rem;
        border: 1px solid #d1d5db;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-family: inherit;
        resize: vertical;
      }

      textarea:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.1);
      }

      .character-count {
        font-size: 0.75rem;
        color: #9ca3af;
        text-align: right;
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
      .templates-section {
        border-color: #353534;
      }

      .section-title h2,
      .card-header h3,
      .section-subtitle {
        color: #e5e2e1;
      }

      .section-title p,
      .character-count {
        color: #b4afa6;
      }

      .notification-card {
        background: #1c1b1b;
        border-color: #353534;
      }

      .channel-checkbox,
      .template-card label {
        color: #cfcac2;
      }

      textarea {
        background: #151515;
        border-color: #353534;
        color: #e5e2e1;
      }

      textarea:focus {
        background: #201f1f;
        border-color: #f5c518;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.13);
      }

      @media (max-width: 768px) {
        .notifications-grid {
          grid-template-columns: 1fr;
        }

        .channels {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export default class SettingsNotificationsComponent implements OnInit {
  notificationEvents = signal<any[]>([]);
  templates = signal<any[]>([]);

  ngOnInit(): void {
    this.notificationEvents.set([
      { id: 'new-member', name: 'Nueva inscripción', channels: ['Sistema', 'Correo'] },
      { id: 'payment-received', name: 'Pago recibido', channels: ['Sistema', 'WhatsApp'] },
      { id: 'payment-pending', name: 'Pago pendiente', channels: ['WhatsApp', 'SMS'] },
      { id: 'membership-expiring', name: 'Membresía por vencer', channels: ['WhatsApp', 'Correo'] },
      { id: 'membership-expired', name: 'Membresía vencida', channels: ['Sistema', 'Correo'] },
      { id: 'upcoming-class', name: 'Clase próxima', channels: ['Sistema', 'WhatsApp'] },
      { id: 'routine-assigned', name: 'Nueva rutina asignada', channels: ['Sistema', 'Correo'] },
      { id: 'campaign-sent', name: 'Campaña enviada', channels: ['Sistema'] },
    ]);

    this.templates.set([
      {
        id: 'welcome',
        name: 'Bienvenida nuevo miembro',
        content:
          'Bienvenido a Iron Body. Te invitamos a aprovechar todas nuestras instalaciones y servicios.',
      },
      {
        id: 'payment-reminder',
        name: 'Recordatorio de pago',
        content:
          'Tu pago está pendiente. Por favor regulariza tu membresía para continuar accediendo.',
      },
      {
        id: 'expiration-reminder',
        name: 'Vencimiento de membresía',
        content: 'Tu membresía vence próximamente. Renueva ahora y sigue disfrutando de Iron Body.',
      },
    ]);
  }

  toggleChannel(eventId: string, channel: string, event: Event): void {
    const checked = (event.target as HTMLInputElement).checked;
    const events = this.notificationEvents();
    const evt = events.find((e) => e.id === eventId);
    if (evt) {
      if (checked && !evt.channels.includes(channel)) {
        evt.channels.push(channel);
      } else if (!checked) {
        evt.channels = evt.channels.filter((c: string) => c !== channel);
      }
      this.notificationEvents.set([...events]);
    }
  }

  onTemplateChange(templateId: string): void {
    console.log(`Plantilla actualizada: ${templateId}`);
  }
}
