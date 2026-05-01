import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { RouterModule } from '@angular/router';
import { CreateMemberModalComponent } from './modules/components/create-member-modal';
import { SupportPanelComponent } from './shared/components/support-panel/support-panel.component';
import { NotificationsPoperoverComponent } from './shared/components/notifications-popover/notifications-popover.component';
import { MessagesPoperoverComponent } from './shared/components/messages-popover/messages-popover.component';
import { QuickAccessMenuComponent } from './shared/components/quick-access-menu/quick-access-menu.component';
import { UserMenuComponent } from './shared/components/user-menu/user-menu.component';
import { AuthService } from './services/auth.service';
import { SupportService } from './shared/services/support.service';
import { NotificationsService } from './shared/services/notifications.service';
import { MessagesService } from './shared/services/messages.service';
import { QuickActionsService } from './shared/services/quick-actions.service';

type BackendHealthResponse = {
  message: string;
  status: string;
  timestamp: string;
};

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [
    CommonModule,
    RouterModule,
    CreateMemberModalComponent,
    SupportPanelComponent,
    NotificationsPoperoverComponent,
    MessagesPoperoverComponent,
    QuickAccessMenuComponent,
    UserMenuComponent,
  ],
  templateUrl: './app.html',
  styleUrls: ['./app.css'],
})
export class App {
  private readonly http = inject(HttpClient);
  private readonly authService = inject(AuthService);
  protected readonly supportService = inject(SupportService);
  protected readonly notificationsService = inject(NotificationsService);
  protected readonly messagesService = inject(MessagesService);
  protected readonly quickActionsService = inject(QuickActionsService);

  protected readonly backendStatus = signal('Conectando con Laravel...');
  protected readonly backendDetail = signal('');
  protected readonly isCreateMemberOpen = signal(false);
  protected readonly isAuthenticated = signal(false);
  protected readonly isNotificationsOpen = signal(false);
  protected readonly isMessagesOpen = signal(false);
  protected readonly isQuickAccessOpen = signal(false);
  protected readonly isUserMenuOpen = signal(false);

  constructor() {
    this.checkAuthenticationStatus();
    this.http.get<BackendHealthResponse>('http://127.0.0.1:8080/api/health').subscribe({
      next: (response) => {
        this.backendStatus.set(this.backendMessage(response.message));
        this.backendDetail.set(`${response.status} · ${response.timestamp}`);
      },
      error: () => {
        this.backendStatus.set('No se pudo conectar con Laravel');
        this.backendDetail.set('Revisa que el backend esté ejecutándose en http://127.0.0.1:8080');
      },
    });
  }

  /**
   * Verificar estado de autenticación
   */
  private checkAuthenticationStatus(): void {
    this.authService.currentUser$.subscribe((user) => {
      this.isAuthenticated.set(!!user);
    });
  }

  protected openNewMemberModal(): void {
    this.isCreateMemberOpen.set(true);
  }

  protected closeNewMemberModal(): void {
    this.isCreateMemberOpen.set(false);
  }

  protected onMemberCreated(member: any): void {
    console.log('Nuevo miembro creado:', member);
    this.isCreateMemberOpen.set(false);
  }

  /**
   * Logout - cierra sesión y redirige a login
   */
  protected onLogout(): void {
    if (confirm('¿Deseas cerrar sesión?')) {
      this.authService.logout().subscribe();
    }
  }

  private backendMessage(message: string): string {
    const labels: Record<string, string> = {
      'Laravel API is healthy': 'Laravel está conectado',
      healthy: 'Servicio disponible',
      ok: 'Servicio disponible',
    };

    return labels[message] ?? message;
  }

  /**
   * Obtener nombre del usuario actual
   */
  protected getCurrentUserName(): string {
    return this.authService.getCurrentUser()?.name ?? 'Usuario';
  }

  /**
   * Obtener rol del usuario actual
   */
  protected getCurrentUserRole(): string {
    return this.authService.getCurrentUser()?.role ?? '';
  }

  /**
   * Abrir panel de soporte
   */
  protected openSupportPanel(): void {
    this.supportService.openSupport();
  }

  /**
   * Topbar methods - Notifications
   */
  protected toggleNotifications(): void {
    this.isNotificationsOpen.update((value) => !value);
  }

  /**
   * Topbar methods - Messages
   */
  protected toggleMessages(): void {
    this.isMessagesOpen.update((value) => !value);
  }

  /**
   * Topbar methods - Quick Access
   */
  protected toggleQuickAccess(): void {
    this.isQuickAccessOpen.update((value) => !value);
  }

  /**
   * Topbar methods - User Menu
   */
  protected toggleUserMenu(): void {
    this.isUserMenuOpen.update((value) => !value);
  }
}
