import { Injectable, signal, computed } from '@angular/core';

export interface Notification {
  id: number;
  type: 'Membresía' | 'Pago' | 'Clase' | 'Sistema' | 'Rutina' | 'Campaña';
  title: string;
  message: string;
  timestamp: string;
  unread: boolean;
  route?: string;
  priority: 'baja' | 'media' | 'alta';
}

@Injectable({
  providedIn: 'root',
})
export class NotificationsService {
  private readonly _notifications = signal<Notification[]>([
    {
      id: 1,
      type: 'Membresía',
      title: 'Membresía próxima a vencer',
      message: 'La membresía de Juan Pérez vence en 3 días.',
      timestamp: 'Hace 10 minutos',
      unread: true,
      route: '/users',
      priority: 'media',
    },
    {
      id: 2,
      type: 'Pago',
      title: 'Pago pendiente',
      message: 'Hay 7 pagos pendientes por revisar.',
      timestamp: 'Hace 30 minutos',
      unread: true,
      route: '/payments',
      priority: 'alta',
    },
    {
      id: 3,
      type: 'Clase',
      title: 'Clase programada',
      message: 'Clase de GYM a las 18:00 hoy con 12 inscritos.',
      timestamp: 'Hace 1 hora',
      unread: true,
      route: '/classes',
      priority: 'media',
    },
    {
      id: 4,
      type: 'Sistema',
      title: 'Backend Laravel conectado',
      message: 'La conexión con el backend está activa.',
      timestamp: 'Hace 2 horas',
      unread: false,
      route: '/settings',
      priority: 'baja',
    },
    {
      id: 5,
      type: 'Campaña',
      title: 'Campaña enviada',
      message: 'Campaña de descuento de marzo enviada a 245 miembros.',
      timestamp: 'Ayer',
      unread: false,
      route: '/marketing',
      priority: 'baja',
    },
  ]);

  public readonly notifications = this._notifications.asReadonly();

  public readonly unreadCount = computed(
    () => this._notifications().filter((n) => n.unread).length,
  );

  public readonly filteredNotifications = signal<Notification[]>(this._notifications());

  private readonly _selectedFilter = signal<string>('todas');
  public readonly selectedFilter = this._selectedFilter.asReadonly();

  constructor() {
    this.setupFilterWatcher();
  }

  private setupFilterWatcher(): void {
    // Simular cambios en notificaciones cada cierto tiempo
    setInterval(() => {
      this.filterNotifications(this._selectedFilter());
    }, 1000);
  }

  /**
   * Marcar notificación como leída
   */
  markAsRead(id: number): void {
    this._notifications.update((notifs) =>
      notifs.map((n) => (n.id === id ? { ...n, unread: false } : n)),
    );
    this.filterNotifications(this._selectedFilter());
  }

  /**
   * Marcar todas como leídas
   */
  markAllAsRead(): void {
    this._notifications.update((notifs) => notifs.map((n) => ({ ...n, unread: false })));
    this.filterNotifications(this._selectedFilter());
  }

  /**
   * Filtrar notificaciones
   */
  filterNotifications(filter: string): void {
    this._selectedFilter.set(filter);
    const allNotifications = this._notifications();

    let filtered = allNotifications;

    switch (filter) {
      case 'noLeidas':
        filtered = allNotifications.filter((n) => n.unread);
        break;
      case 'pagos':
        filtered = allNotifications.filter((n) => n.type === 'Pago');
        break;
      case 'membresias':
        filtered = allNotifications.filter((n) => n.type === 'Membresía');
        break;
      case 'clases':
        filtered = allNotifications.filter((n) => n.type === 'Clase');
        break;
      case 'sistema':
        filtered = allNotifications.filter((n) => n.type === 'Sistema');
        break;
      default: // 'todas'
        filtered = allNotifications;
    }

    this.filteredNotifications.set(filtered);
  }

  /**
   * Obtener notificaciones filtradas
   */
  getFilteredNotifications(): Notification[] {
    return this.filteredNotifications();
  }

  /**
   * Eliminar notificación
   */
  deleteNotification(id: number): void {
    this._notifications.update((notifs) => notifs.filter((n) => n.id !== id));
    this.filterNotifications(this._selectedFilter());
  }
}
