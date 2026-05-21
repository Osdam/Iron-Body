import { Component, inject, Input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';
import { NotificationsService } from '../../services/notifications.service';

@Component({
  selector: 'app-notifications-popover',
  standalone: true,
  imports: [CommonModule],
  template: `
    <!-- Overlay -->
    <div
      *ngIf="isOpen"
      class="notifications-overlay"
      (click)="togglePopover()"
      aria-hidden="true"
    ></div>

    <!-- Popover Container -->
    <div *ngIf="isOpen" class="notifications-popover" role="dialog" aria-modal="true">
      <!-- Header -->
      <div class="notifications-header">
        <div class="notifications-header-top">
          <h2 class="notifications-title">Notificaciones</h2>
          <button
            class="notifications-close-btn"
            (click)="togglePopover()"
            aria-label="Cerrar notificaciones"
            type="button"
          >
            <span class="material-symbols-outlined">close</span>
          </button>
        </div>

        <!-- Filters -->
        <div class="notifications-filters">
          <button
            *ngFor="let filter of filters"
            [class.active]="notificationsService.selectedFilter() === filter.value"
            (click)="notificationsService.filterNotifications(filter.value)"
            class="filter-btn"
            type="button"
          >
            {{ filter.label }}
          </button>
        </div>
      </div>

      <!-- Mark All as Read Button -->
      <div *ngIf="notificationsService.unreadCount() > 0" class="notifications-action">
        <button (click)="notificationsService.markAllAsRead()" class="mark-all-btn" type="button">
          Marcar todas como leídas
        </button>
      </div>

      <!-- Notifications List -->
      <div
        *ngIf="notificationsService.getFilteredNotifications().length > 0; else emptyState"
        class="notifications-list"
      >
        <div
          *ngFor="let notification of notificationsService.getFilteredNotifications()"
          class="notification-item"
          [class.unread]="notification.unread"
          (click)="handleNotificationClick(notification)"
        >
          <div class="notification-icon" [class]="'priority-' + notification.priority">
            <span class="material-symbols-outlined">{{ getIconForType(notification.type) }}</span>
          </div>
          <div class="notification-content">
            <h3 class="notification-title">{{ notification.title }}</h3>
            <p class="notification-message">{{ notification.message }}</p>
            <span class="notification-timestamp">{{ notification.timestamp }}</span>
          </div>
          <div *ngIf="notification.unread" class="unread-indicator"></div>
        </div>
      </div>

      <!-- Empty State -->
      <ng-template #emptyState>
        <div class="empty-state">
          <span class="material-symbols-outlined">notifications_off</span>
          <p>No tienes notificaciones nuevas.</p>
        </div>
      </ng-template>
    </div>
  `,
  styleUrl: './notifications-popover.component.scss',
})
export class NotificationsPoperoverComponent {
  notificationsService = inject(NotificationsService);
  @Input() isOpen = false;
  @Output() close = new EventEmitter<void>();

  filters = [
    { label: 'Todas', value: 'todas' },
    { label: 'No leídas', value: 'noLeidas' },
    { label: 'Pagos', value: 'pagos' },
    { label: 'Membresías', value: 'membresias' },
    { label: 'Clases', value: 'clases' },
    { label: 'Sistema', value: 'sistema' },
  ];

  /**
   * Alternar popover
   */
  togglePopover(): void {
    this.close.emit();
  }

  /**
   * Manejar clic en notificación
   */
  handleNotificationClick(notification: any): void {
    this.notificationsService.markAsRead(notification.id);
    if (notification.route) {
      // TODO: Navegar a la ruta
      console.log('Navegar a:', notification.route);
    }
  }

  /**
   * Obtener icono según tipo
   */
  getIconForType(type: string): string {
    const icons: Record<string, string> = {
      Membresía: 'card_membership',
      Pago: 'payments',
      Clase: 'calendar_month',
      Sistema: 'dns',
      Rutina: 'fitness_center',
      Campaña: 'campaign',
    };
    return icons[type] || 'notifications';
  }
}
