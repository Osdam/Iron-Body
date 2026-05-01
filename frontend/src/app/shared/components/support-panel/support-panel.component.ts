import { Component, inject, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import {
  ReactiveFormsModule,
  FormBuilder,
  FormGroup,
  Validators,
  FormsModule,
} from '@angular/forms';
import { SupportService, SupportTicket } from '../../services/support.service';

@Component({
  selector: 'app-support-panel',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule],
  template: `
    <!-- Support Drawer Overlay -->
    <div
      *ngIf="supportService.isSupportOpen()"
      class="support-overlay"
      (click)="supportService.closeSupport()"
      aria-hidden="true"
    ></div>

    <!-- Support Drawer -->
    <div
      *ngIf="supportService.isSupportOpen()"
      class="support-drawer"
      role="dialog"
      aria-modal="true"
    >
      <!-- Close Button -->
      <button
        class="support-close-btn"
        (click)="supportService.closeSupport()"
        aria-label="Cerrar soporte"
        type="button"
      >
        <span class="material-symbols-outlined">close</span>
      </button>

      <!-- Main View or Form View -->
      <div *ngIf="!supportService.isFormOpen()">
        <!-- Header -->
        <div class="support-header">
          <div class="support-header-icon">
            <span class="material-symbols-outlined">headset_mic</span>
          </div>
          <div class="support-header-text">
            <h2 class="support-title">Centro de soporte</h2>
            <p class="support-subtitle">
              Contacta al equipo técnico, reporta errores o consulta el estado del sistema.
            </p>
          </div>
        </div>

        <!-- Quick Contact Cards -->
        <div class="support-cards">
          <!-- Email Card -->
          <div class="support-card">
            <div class="support-card-icon">
              <span class="material-symbols-outlined">mail</span>
            </div>
            <div class="support-card-content">
              <h3 class="support-card-title">Correo de soporte</h3>
              <p class="support-card-description">
                Escríbenos para reportes técnicos, solicitudes o seguimiento.
              </p>
              <p class="support-card-value">developersoftware106@gmail.com</p>
            </div>
            <div class="support-card-actions">
              <button
                class="support-btn support-btn-secondary"
                (click)="supportService.copySupportEmail()"
                type="button"
                title="Copiar correo"
              >
                Copiar
              </button>
              <button
                class="support-btn support-btn-primary"
                (click)="supportService.openSupportEmail()"
                type="button"
              >
                Abrir correo
              </button>
            </div>
          </div>

          <!-- WhatsApp Card -->
          <div class="support-card">
            <div class="support-card-icon">
              <span class="material-symbols-outlined">message</span>
            </div>
            <div class="support-card-content">
              <h3 class="support-card-title">WhatsApp</h3>
              <p class="support-card-description">Comunícate con soporte para asistencia rápida.</p>
              <p class="support-card-value">+1 350 536 026</p>
            </div>
            <div class="support-card-actions">
              <button
                class="support-btn support-btn-secondary"
                (click)="supportService.copySupportWhatsApp()"
                type="button"
                title="Copiar número"
              >
                Copiar
              </button>
              <button
                class="support-btn support-btn-primary"
                (click)="supportService.openSupportWhatsApp()"
                type="button"
              >
                Abrir WhatsApp
              </button>
            </div>
          </div>

          <!-- Report Problem Card -->
          <div class="support-card">
            <div class="support-card-icon">
              <span class="material-symbols-outlined">bug_report</span>
            </div>
            <div class="support-card-content">
              <h3 class="support-card-title">Reportar problema</h3>
              <p class="support-card-description">
                Envía un ticket con el detalle del error o comportamiento inesperado.
              </p>
            </div>
            <div class="support-card-actions">
              <button
                class="support-btn support-btn-primary"
                (click)="supportService.openSupportForm()"
                type="button"
              >
                Crear ticket
              </button>
            </div>
          </div>

          <!-- System Status Card -->
          <div class="support-card">
            <div class="support-card-icon">
              <span class="material-symbols-outlined">{{
                supportService.backendStatus().backendConnected ? 'check_circle' : 'error'
              }}</span>
            </div>
            <div class="support-card-content">
              <h3 class="support-card-title">Estado del sistema</h3>
              <p class="support-card-description">
                Verifica conexión con backend Laravel y servicios principales.
              </p>
              <div class="system-status">
                <p>
                  <strong>Backend:</strong>
                  <span
                    [class]="
                      'status-' +
                      (supportService.backendStatus().backendConnected
                        ? 'connected'
                        : 'disconnected')
                    "
                  >
                    {{
                      supportService.backendStatus().backendConnected ? 'Conectado' : 'No conectado'
                    }}
                  </span>
                </p>
                <p *ngIf="supportService.backendStatus().lastCheck">
                  <strong>Última verificación:</strong>
                  {{ supportService.backendStatus().lastCheck }}
                </p>
              </div>
            </div>
            <div class="support-card-actions">
              <button
                class="support-btn support-btn-primary"
                (click)="supportService.testBackendConnection()"
                [disabled]="supportService.testingConnection()"
                type="button"
              >
                {{ supportService.testingConnection() ? 'Probando...' : 'Probar conexión' }}
              </button>
            </div>
          </div>
        </div>

        <!-- System Information -->
        <div class="support-system-info">
          <h3 class="support-section-title">Información del sistema</h3>
          <div class="system-info-grid">
            <div class="info-item">
              <span class="info-label">Sistema:</span>
              <span class="info-value">{{ supportService.systemInfo().system }}</span>
            </div>
            <div class="info-item">
              <span class="info-label">Entorno:</span>
              <span class="info-value">{{ supportService.systemInfo().environment }}</span>
            </div>
            <div class="info-item">
              <span class="info-label">Usuario:</span>
              <span class="info-value">{{ supportService.systemInfo().user }}</span>
            </div>
            <div class="info-item">
              <span class="info-label">Rol:</span>
              <span class="info-value">{{ supportService.systemInfo().role }}</span>
            </div>
            <div class="info-item">
              <span class="info-label">Ruta actual:</span>
              <span class="info-value">{{ supportService.systemInfo().route }}</span>
            </div>
            <div class="info-item">
              <span class="info-label">Hora:</span>
              <span class="info-value">{{ supportService.systemInfo().timestamp }}</span>
            </div>
          </div>
          <button
            class="support-btn support-btn-secondary"
            (click)="supportService.copySystemInfo()"
            type="button"
            style="width: 100%; margin-top: 12px;"
          >
            Copiar información del sistema
          </button>
        </div>
      </div>

      <!-- Support Form View -->
      <div *ngIf="supportService.isFormOpen()">
        <!-- Back Button -->
        <button
          class="support-back-btn"
          (click)="supportService.closeSupportForm()"
          type="button"
          aria-label="Volver"
        >
          <span class="material-symbols-outlined">arrow_back</span>
          Volver
        </button>

        <!-- Form Header -->
        <div class="support-form-header">
          <div class="support-form-header-icon">
            <span class="material-symbols-outlined">description</span>
          </div>
          <div class="support-form-header-text">
            <h2 class="support-form-title">Crear ticket</h2>
            <p class="support-form-subtitle">Cuéntanos qué necesitas</p>
          </div>
        </div>

        <!-- Success Message -->
        <div *ngIf="supportService.sendSuccess()" class="support-alert support-alert-success">
          <span class="material-symbols-outlined">check_circle</span>
          <div>
            <strong>Solicitud enviada correctamente</strong>
            <p>El equipo de soporte revisará tu caso.</p>
          </div>
        </div>

        <!-- Error Message -->
        <div *ngIf="supportService.sendError()" class="support-alert support-alert-error">
          <span class="material-symbols-outlined">error</span>
          <div>
            <strong>Error al enviar</strong>
            <p>{{ supportService.sendError() }}</p>
          </div>
        </div>

        <!-- Ticket Form -->
        <form [formGroup]="ticketForm" (ngSubmit)="onSubmitTicket()" class="support-form">
          <!-- Type -->
          <div class="form-group">
            <label for="type" class="form-label">Tipo de solicitud *</label>
            <select id="type" formControlName="type" class="form-control" required>
              <option value="">Selecciona una opción</option>
              <option value="technical">Problema técnico</option>
              <option value="error">Error en el sistema</option>
              <option value="improvement">Solicitud de mejora</option>
              <option value="usage">Duda de uso</option>
              <option value="admin">Soporte administrativo</option>
              <option value="integration">Integración / automatización</option>
            </select>
          </div>

          <!-- Priority -->
          <div class="form-group">
            <label for="priority" class="form-label">Prioridad *</label>
            <select id="priority" formControlName="priority" class="form-control" required>
              <option value="">Selecciona una opción</option>
              <option value="low">Baja</option>
              <option value="medium">Media</option>
              <option value="high">Alta</option>
              <option value="critical">Crítica</option>
            </select>
          </div>

          <!-- Module -->
          <div class="form-group">
            <label for="module" class="form-label">Módulo afectado *</label>
            <select id="module" formControlName="module" class="form-control" required>
              <option value="">Selecciona un módulo</option>
              <option value="home">Inicio</option>
              <option value="members">Miembros</option>
              <option value="payments">Pagos</option>
              <option value="plans">Planes / Membresías</option>
              <option value="classes">Clases</option>
              <option value="analytics">Analítica</option>
              <option value="routines">Rutinas</option>
              <option value="trainers">Entrenadores</option>
              <option value="marketing">Mercadeo</option>
              <option value="settings">Configuración</option>
              <option value="auth">Login / autenticación</option>
              <option value="backend">Backend Laravel</option>
              <option value="other">Otro</option>
            </select>
          </div>

          <!-- Subject -->
          <div class="form-group">
            <label for="subject" class="form-label">Asunto *</label>
            <input
              id="subject"
              type="text"
              formControlName="subject"
              class="form-control"
              placeholder="Ej: No puedo registrar un nuevo miembro"
              required
            />
          </div>

          <!-- Message -->
          <div class="form-group">
            <label for="message" class="form-label">Mensaje *</label>
            <textarea
              id="message"
              formControlName="message"
              class="form-control form-textarea"
              placeholder="Describe qué ocurrió, qué estabas intentando hacer y si apareció algún error."
              rows="5"
              required
            ></textarea>
          </div>

          <!-- Email -->
          <div class="form-group">
            <label for="email" class="form-label">Correo de contacto *</label>
            <input
              id="email"
              type="email"
              formControlName="email"
              class="form-control"
              placeholder="tu@email.com"
              required
            />
          </div>

          <!-- Form Actions -->
          <div class="support-form-actions">
            <button
              type="button"
              class="support-btn support-btn-secondary"
              (click)="supportService.closeSupportForm()"
              [disabled]="supportService.isSending()"
            >
              Cancelar
            </button>
            <button
              type="submit"
              class="support-btn support-btn-primary"
              [disabled]="!ticketForm.valid || supportService.isSending()"
            >
              {{ supportService.isSending() ? 'Enviando...' : 'Enviar solicitud' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  `,
  styleUrl: './support-panel.component.scss',
})
export class SupportPanelComponent implements OnInit {
  supportService: SupportService;
  private readonly fb = inject(FormBuilder);

  protected ticketForm: FormGroup;

  constructor() {
    this.supportService = inject(SupportService);
    this.ticketForm = this.fb.group({
      type: ['', Validators.required],
      priority: ['', Validators.required],
      module: ['', Validators.required],
      subject: ['', [Validators.required, Validators.minLength(5)]],
      message: ['', [Validators.required, Validators.minLength(10)]],
      email: ['developersoftware106@gmail.com', [Validators.required, Validators.email]],
    });
  }

  ngOnInit(): void {
    // Form initialization if needed
  }

  /**
   * Enviar ticket
   */
  onSubmitTicket(): void {
    if (!this.ticketForm.valid) {
      return;
    }

    const ticket: SupportTicket = {
      type: this.ticketForm.get('type')?.value,
      priority: this.ticketForm.get('priority')?.value,
      module: this.ticketForm.get('module')?.value,
      subject: this.ticketForm.get('subject')?.value,
      message: this.ticketForm.get('message')?.value,
      email: this.ticketForm.get('email')?.value,
      timestamp: '',
      route: '',
      systemInfo: '',
    };

    this.supportService.submitSupportTicket(ticket);
  }
}
