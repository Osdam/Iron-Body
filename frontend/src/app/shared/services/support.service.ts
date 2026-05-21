import { Injectable, signal, computed, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { AuthService } from '../../services/auth.service';

export interface SupportTicket {
  type: 'technical' | 'error' | 'improvement' | 'usage' | 'admin' | 'integration';
  priority: 'low' | 'medium' | 'high' | 'critical';
  module: string;
  subject: string;
  message: string;
  email: string;
  timestamp: string;
  route: string;
  systemInfo: string;
}

export interface SystemStatus {
  backendConnected: boolean;
  lastCheck: string;
  apiUrl: string;
}

@Injectable({
  providedIn: 'root',
})
export class SupportService {
  private readonly http = inject(HttpClient);
  private readonly authService = inject(AuthService);
  private readonly router = inject(Router);

  // Signals
  private readonly _isSupportOpen = signal(false);
  public readonly isSupportOpen = this._isSupportOpen.asReadonly();

  private readonly _isFormOpen = signal(false);
  public readonly isFormOpen = this._isFormOpen.asReadonly();

  private readonly _isSending = signal(false);
  public readonly isSending = this._isSending.asReadonly();

  private readonly _sendSuccess = signal(false);
  public readonly sendSuccess = this._sendSuccess.asReadonly();

  private readonly _sendError = signal<string | null>(null);
  public readonly sendError = this._sendError.asReadonly();

  private readonly _backendStatus = signal<SystemStatus>({
    backendConnected: false,
    lastCheck: '',
    apiUrl: 'http://127.0.0.1:8080',
  });
  public readonly backendStatus = this._backendStatus.asReadonly();

  private readonly _testingConnection = signal(false);
  public readonly testingConnection = this._testingConnection.asReadonly();

  // Tickets almacenados localmente
  private readonly _supportTickets = signal<SupportTicket[]>([]);
  public readonly supportTickets = this._supportTickets.asReadonly();

  // Información del sistema
  public readonly systemInfo = computed(() => this.getSystemInfo());

  constructor() {
    this.checkBackendStatus();
  }

  /**
   * Abrir panel de soporte
   */
  public openSupport(): void {
    this._isSupportOpen.set(true);
    this._isFormOpen.set(false);
    this._sendSuccess.set(false);
    this._sendError.set(null);
  }

  /**
   * Cerrar panel de soporte
   */
  public closeSupport(): void {
    this._isSupportOpen.set(false);
    this._isFormOpen.set(false);
  }

  /**
   * Abrir formulario de tickets
   */
  public openSupportForm(): void {
    this._isFormOpen.set(true);
  }

  /**
   * Cerrar formulario de tickets
   */
  public closeSupportForm(): void {
    this._isFormOpen.set(false);
  }

  /**
   * Enviar ticket de soporte
   */
  public submitSupportTicket(ticket: SupportTicket): void {
    if (!this.validateTicket(ticket)) {
      this._sendError.set('Por favor completa todos los campos requeridos.');
      return;
    }

    this._isSending.set(true);
    this._sendError.set(null);
    this._sendSuccess.set(false);

    // Agregar información del ticket
    const completeTicket: SupportTicket = {
      ...ticket,
      timestamp: new Date().toISOString(),
      route: this.router.url,
      systemInfo: this.getSystemInfoString(),
    };

    // Guardar localmente
    const tickets = this._supportTickets();
    this._supportTickets.set([...tickets, completeTicket]);

    // Simular envío
    setTimeout(() => {
      this._isSending.set(false);
      this._sendSuccess.set(true);
      this._sendError.set(null);

      // Intentar enviar por email si existe backend
      this.sendEmailNotification(completeTicket);

      // Limpiar estado después de 3 segundos
      setTimeout(() => {
        this._sendSuccess.set(false);
        this._isFormOpen.set(false);
      }, 3000);
    }, 1500);
  }

  /**
   * Validar ticket
   */
  private validateTicket(ticket: SupportTicket): boolean {
    return (
      !!ticket.type &&
      !!ticket.priority &&
      !!ticket.module &&
      !!ticket.subject?.trim() &&
      !!ticket.message?.trim() &&
      this.isValidEmail(ticket.email)
    );
  }

  /**
   * Validar email
   */
  private isValidEmail(email: string): boolean {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
  }

  /**
   * Enviar notificación por email (mock)
   */
  private sendEmailNotification(ticket: SupportTicket): void {
    // TODO: Conectar con endpoint real del backend
    // this.http.post('/api/support/tickets', ticket).subscribe(...)
    console.log('Ticket para enviar:', ticket);
  }

  /**
   * Probar conexión con backend
   */
  public testBackendConnection(): void {
    this._testingConnection.set(true);
    this.http.get(`${this._backendStatus().apiUrl}/api/health`).subscribe({
      next: () => {
        this._backendStatus.set({
          backendConnected: true,
          lastCheck: new Date().toLocaleString('es-ES'),
          apiUrl: this._backendStatus().apiUrl,
        });
        this._testingConnection.set(false);
      },
      error: () => {
        this._backendStatus.set({
          backendConnected: false,
          lastCheck: new Date().toLocaleString('es-ES'),
          apiUrl: this._backendStatus().apiUrl,
        });
        this._testingConnection.set(false);
      },
    });
  }

  /**
   * Verificar conexión backend al iniciar
   */
  private checkBackendStatus(): void {
    this.http.get(`${this._backendStatus().apiUrl}/api/health`).subscribe({
      next: () => {
        this._backendStatus.set({
          backendConnected: true,
          lastCheck: new Date().toLocaleString('es-ES'),
          apiUrl: this._backendStatus().apiUrl,
        });
      },
      error: () => {
        this._backendStatus.set({
          backendConnected: false,
          lastCheck: new Date().toLocaleString('es-ES'),
          apiUrl: this._backendStatus().apiUrl,
        });
      },
    });
  }

  /**
   * Obtener información del sistema
   */
  private getSystemInfo() {
    const user = this.authService.getCurrentUser();
    return {
      system: 'Iron Body Admin',
      environment: 'Desarrollo',
      route: this.router.url,
      timestamp: new Date().toLocaleString('es-ES'),
      user: user?.name || 'Desconocido',
      role: user?.role || 'Sin rol',
      backend: this._backendStatus().backendConnected ? 'Conectado' : 'No conectado',
      apiUrl: this._backendStatus().apiUrl,
    };
  }

  /**
   * Obtener información del sistema como string
   */
  private getSystemInfoString(): string {
    const info = this.getSystemInfo();
    return `
Sistema: ${info.system}
Entorno: ${info.environment}
Ruta: ${info.route}
Hora: ${info.timestamp}
Usuario: ${info.user}
Rol: ${info.role}
Backend: ${info.backend}
URL API: ${info.apiUrl}
    `.trim();
  }

  /**
   * Copiar correo de soporte
   */
  public copySupportEmail(): void {
    this.copyToClipboard('developersoftware106@gmail.com');
  }

  /**
   * Copiar WhatsApp
   */
  public copySupportWhatsApp(): void {
    this.copyToClipboard('1350536026');
  }

  /**
   * Copiar información del sistema
   */
  public copySystemInfo(): void {
    this.copyToClipboard(this.getSystemInfoString());
  }

  /**
   * Copiar texto al portapapeles
   */
  private copyToClipboard(text: string): void {
    navigator.clipboard.writeText(text).then(() => {
      console.log('Copiado:', text);
    });
  }

  /**
   * Abrir correo de soporte
   */
  public openSupportEmail(subject: string = 'Soporte Iron Body Admin'): void {
    const mailtoLink = `mailto:developersoftware106@gmail.com?subject=${encodeURIComponent(subject)}`;
    window.open(mailtoLink);
  }

  /**
   * Abrir WhatsApp
   */
  public openSupportWhatsApp(
    message: string = 'Hola, necesito soporte con Iron Body Admin.',
  ): void {
    const phone = '1350536026';
    const whatsappLink = `https://wa.me/${phone}?text=${encodeURIComponent(message)}`;
    window.open(whatsappLink, '_blank');
  }
}
