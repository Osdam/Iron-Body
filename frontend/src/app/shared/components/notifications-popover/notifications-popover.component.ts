import { Component, inject, Input, Output, EventEmitter, OnInit, OnDestroy, OnChanges, SimpleChanges } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { AdminNotification, NotificationsService, NotificationTab } from '../../services/notifications.service';

/**
 * Centro de notificaciones del CRM — panel flotante oscuro premium.
 *
 * 100% conectado al backend (NotificationsService). Sin datos simulados.
 * Tabs, búsqueda, marcar como leídas, acciones por tipo y acceso a la
 * página completa /notifications.
 */
@Component({
  selector: 'app-notifications-popover',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div *ngIf="isOpen" class="notifications-overlay" (click)="togglePopover()" aria-hidden="true"></div>

    <div *ngIf="isOpen" class="notifications-popover" role="dialog" aria-modal="true">
      <!-- Header -->
      <div class="np-header">
        <div class="np-header-top">
          <div class="np-title-wrap">
            <h2 class="np-title">Notificaciones</h2>
            <span *ngIf="notificationsService.unreadCount() > 0" class="np-count-pill">
              {{ notificationsService.unreadCount() }} sin leer
            </span>
          </div>
          <div class="np-header-actions">
            <button class="np-icon-btn" (click)="notificationsService.refresh()" title="Refrescar" type="button">
              <span class="material-symbols-outlined">refresh</span>
            </button>
            <button class="np-icon-btn" (click)="togglePopover()" aria-label="Cerrar" type="button">
              <span class="material-symbols-outlined">close</span>
            </button>
          </div>
        </div>

        <!-- Search -->
        <label class="np-search">
          <span class="material-symbols-outlined">search</span>
          <input
            type="search"
            placeholder="Buscar por nombre, documento, referencia o mensaje…"
            (input)="onSearch($event)"
          />
        </label>

        <!-- Tabs -->
        <div class="np-tabs">
          <button
            *ngFor="let tab of tabs"
            [class.active]="notificationsService.selectedTab() === tab.value"
            (click)="notificationsService.setTab(tab.value)"
            class="np-tab"
            type="button"
          >
            {{ tab.label }}
          </button>
        </div>
      </div>

      <!-- Mark all -->
      <div *ngIf="notificationsService.unreadCount() > 0" class="np-actionbar">
        <button (click)="notificationsService.markAllAsRead()" class="np-markall" type="button">
          <span class="material-symbols-outlined">done_all</span> Marcar todas como leídas
        </button>
      </div>

      <!-- List -->
      <div class="np-list">
        <ng-container *ngIf="!notificationsService.loading() && notificationsService.error()">
          <div class="np-state np-error">
            <span class="material-symbols-outlined">wifi_off</span>
            <p>{{ notificationsService.error() }}</p>
            <button class="np-retry" (click)="notificationsService.refresh()" type="button">Reintentar</button>
          </div>
        </ng-container>

        <ng-container *ngIf="notificationsService.loading() && !notificationsService.hasItems()">
          <div class="np-skeleton" *ngFor="let s of [1,2,3,4]"></div>
        </ng-container>

        <ng-container *ngIf="!notificationsService.error() && notificationsService.hasItems()">
          <div
            *ngFor="let n of notificationsService.items()"
            class="np-item"
            [class.unread]="n.status === 'unread'"
            (click)="onClick(n)"
          >
            <div class="np-item-icon" [attr.data-type]="n.type">
              <span class="material-symbols-outlined">{{ iconFor(n.type) }}</span>
            </div>
            <div class="np-item-body">
              <div class="np-item-top">
                <h3 class="np-item-title">{{ n.title }}</h3>
                <span class="np-item-time">{{ n.time_ago }}</span>
              </div>
              <p class="np-item-msg">{{ n.message }}</p>
              <span *ngIf="n.priority === 'high'" class="np-priority">Prioridad alta</span>
            </div>
            <span *ngIf="n.status === 'unread'" class="np-dot"></span>
          </div>
        </ng-container>

        <ng-container *ngIf="!notificationsService.loading() && !notificationsService.error() && !notificationsService.hasItems()">
          <div class="np-state">
            <span class="material-symbols-outlined">notifications_off</span>
            <p>No hay notificaciones por ahora.</p>
          </div>
        </ng-container>
      </div>

      <!-- Footer -->
      <div class="np-footer">
        <button class="np-viewall" (click)="goToFullPage()" type="button">
          Ver todas las notificaciones
          <span class="material-symbols-outlined">arrow_forward</span>
        </button>
      </div>
    </div>
  `,
  styleUrl: './notifications-popover.component.scss',
})
export class NotificationsPoperoverComponent implements OnInit, OnDestroy, OnChanges {
  notificationsService = inject(NotificationsService);
  private router = inject(Router);

  @Input() isOpen = false;
  @Output() close = new EventEmitter<void>();

  /** Refresco automático de la LISTA mientras el panel está abierto. */
  private listPoll: any = null;
  private static readonly LIST_POLL_MS = 10000;

  tabs: { label: string; value: NotificationTab }[] = [
    { label: 'Todas', value: 'todas' },
    { label: 'No leídas', value: 'noLeidas' },
    { label: 'Pagos', value: 'pagos' },
    { label: 'Membresías', value: 'membresias' },
    { label: 'Clases', value: 'clases' },
    { label: 'Rutinas', value: 'rutinas' },
    { label: 'Entrenador', value: 'entrenador' },
    { label: 'Promociones', value: 'promociones' },
    { label: 'Sistema', value: 'sistema' },
    { label: 'IRON IA', value: 'iron_ai' },
  ];

  ngOnInit(): void {
    // El popover vive en el shell: arrancamos polling para mantener el badge
    // del topbar al día aunque el panel esté cerrado.
    this.notificationsService.setPerPage(8);
    this.notificationsService.startPolling(10000);
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['isOpen']) {
      if (this.isOpen) {
        this.notificationsService.refresh();
        this.startListPoll();
      } else {
        this.stopListPoll();
      }
    }
  }

  ngOnDestroy(): void {
    this.stopListPoll();
    this.notificationsService.stopPolling();
  }

  /** Mientras el panel está abierto, refresca la lista periódicamente. */
  private startListPoll(): void {
    if (this.listPoll) return;
    this.listPoll = setInterval(
      () => this.notificationsService.refresh(),
      NotificationsPoperoverComponent.LIST_POLL_MS,
    );
  }

  private stopListPoll(): void {
    if (this.listPoll) {
      clearInterval(this.listPoll);
      this.listPoll = null;
    }
  }

  togglePopover(): void {
    this.close.emit();
  }

  onSearch(event: Event): void {
    this.notificationsService.search((event.target as HTMLInputElement).value);
  }

  onClick(n: AdminNotification): void {
    if (n.status === 'unread') {
      this.notificationsService.markAsRead(n.uuid);
    }
    const route = this.routeFor(n);
    if (route) {
      this.close.emit();
      this.router.navigate([route]);
    }
  }

  goToFullPage(): void {
    this.close.emit();
    this.router.navigate(['/notifications']);
  }

  iconFor(type: string): string {
    const map: Record<string, string> = {
      payment: 'payments',
      membership: 'card_membership',
      class: 'calendar_month',
      routine: 'fitness_center',
      system: 'dns',
      promotion: 'campaign',
      iron_ai: 'smart_toy',
      trainer: 'sports',
    };
    return map[type] || 'notifications';
  }

  /** Solo devuelve ruta si la acción está realmente conectada al CRM. */
  private routeFor(n: AdminNotification): string | null {
    switch (n.type) {
      case 'payment':
        return '/payments';
      case 'membership':
        return '/users';
      case 'class':
        return '/classes';
      case 'routine':
        return '/routines';
      case 'trainer':
        return '/trainers';
      case 'promotion':
        return '/marketing';
      default:
        return null;
    }
  }
}
