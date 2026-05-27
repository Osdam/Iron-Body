import { CommonModule } from '@angular/common';
import { Component, OnDestroy, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject } from 'rxjs';
import { debounceTime } from 'rxjs/operators';
import {
  AdminNotification,
  NotificationsMeta,
  NotificationsService,
} from '../shared/services/notifications.service';

interface CategoryChip {
  label: string;
  value: string;
}

/**
 * Página completa de notificaciones del CRM (/notifications).
 *
 * 100% conectada al backend Laravel (audience=admin). Sin datos simulados.
 * Búsqueda global, filtros por categoría/estado/fecha, paginación y acciones.
 * Mantiene su PROPIO estado (queryRaw) para no chocar con el polling del popover.
 */
@Component({
  selector: 'app-notifications-page',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <section class="nf-page">
      <!-- Header -->
      <header class="nf-head">
        <div>
          <h1>Notificaciones</h1>
          <p class="nf-sub">
            {{ meta().total }} en total ·
            <span class="nf-unread">{{ service.unreadCount() }} sin leer</span>
          </p>
        </div>
        <div class="nf-head-actions">
          <button class="nf-btn ghost" (click)="reload()" type="button">
            <span class="material-symbols-outlined">refresh</span> Refrescar
          </button>
          <button
            class="nf-btn primary"
            (click)="markAll()"
            type="button"
            [disabled]="service.unreadCount() === 0"
          >
            <span class="material-symbols-outlined">done_all</span> Marcar todas como leídas
          </button>
        </div>
      </header>

      <!-- Toolbar -->
      <div class="nf-toolbar">
        <label class="nf-search">
          <span class="material-symbols-outlined">search</span>
          <input
            type="search"
            [(ngModel)]="searchText"
            (ngModelChange)="onSearch($event)"
            placeholder="Buscar por nombre, documento, referencia, tipo o mensaje…"
          />
        </label>

        <div class="nf-filters">
          <select [(ngModel)]="status" (ngModelChange)="reload()" class="nf-select">
            <option value="">Todos los estados</option>
            <option value="unread">No leídas</option>
            <option value="read">Leídas</option>
          </select>
          <input type="date" [(ngModel)]="from" (ngModelChange)="reload()" class="nf-date" title="Desde" />
          <input type="date" [(ngModel)]="to" (ngModelChange)="reload()" class="nf-date" title="Hasta" />
        </div>
      </div>

      <!-- Category chips -->
      <div class="nf-chips">
        <button
          *ngFor="let c of categories"
          class="nf-chip"
          [class.active]="category === c.value"
          (click)="setCategory(c.value)"
          type="button"
        >
          {{ c.label }}
        </button>
      </div>

      <!-- Body -->
      <div class="nf-body">
        <ng-container *ngIf="loading()">
          <div class="nf-skeleton" *ngFor="let s of [1,2,3,4,5,6]"></div>
        </ng-container>

        <ng-container *ngIf="!loading() && error()">
          <div class="nf-state nf-error">
            <span class="material-symbols-outlined">wifi_off</span>
            <p>{{ error() }}</p>
            <button class="nf-btn primary" (click)="reload()" type="button">Reintentar</button>
          </div>
        </ng-container>

        <ng-container *ngIf="!loading() && !error() && items().length === 0">
          <div class="nf-state">
            <span class="material-symbols-outlined">notifications_off</span>
            <p>No hay notificaciones que coincidan con tu búsqueda.</p>
          </div>
        </ng-container>

        <table class="nf-table" *ngIf="!loading() && !error() && items().length > 0">
          <thead>
            <tr>
              <th></th>
              <th>Notificación</th>
              <th>Tipo</th>
              <th>Miembro / Documento</th>
              <th>Referencia</th>
              <th>Estado</th>
              <th>Fecha</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr
              *ngFor="let n of items()"
              class="nf-row"
              [class.unread]="n.status === 'unread'"
              (click)="open(n)"
            >
              <td>
                <span class="nf-ico" [attr.data-type]="n.type">
                  <span class="material-symbols-outlined">{{ iconFor(n.type) }}</span>
                </span>
              </td>
              <td class="nf-cell-main">
                <div class="nf-row-title">
                  {{ n.title }}
                  <span *ngIf="n.priority === 'high'" class="nf-prio">alta</span>
                </div>
                <div class="nf-row-msg">{{ n.message }}</div>
              </td>
              <td><span class="nf-type">{{ typeLabel(n.type) }}</span></td>
              <td>
                <div class="nf-member">{{ memberName(n) || '—' }}</div>
                <div class="nf-doc" *ngIf="n.document">{{ n.document }}</div>
              </td>
              <td class="nf-ref">{{ reference(n) || '—' }}</td>
              <td>
                <span class="nf-badge" [class.read]="n.status === 'read'">
                  {{ n.status === 'unread' ? 'No leída' : 'Leída' }}
                </span>
              </td>
              <td class="nf-date-cell">{{ n.time_ago }}</td>
              <td>
                <button
                  *ngIf="n.status === 'unread'"
                  class="nf-mark"
                  (click)="markOne($event, n)"
                  title="Marcar como leída"
                  type="button"
                >
                  <span class="material-symbols-outlined">check</span>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="nf-pager" *ngIf="meta().last_page > 1">
        <button class="nf-btn ghost" [disabled]="meta().current_page <= 1" (click)="goTo(meta().current_page - 1)" type="button">
          <span class="material-symbols-outlined">chevron_left</span>
        </button>
        <span class="nf-page-info">Página {{ meta().current_page }} de {{ meta().last_page }}</span>
        <button class="nf-btn ghost" [disabled]="meta().current_page >= meta().last_page" (click)="goTo(meta().current_page + 1)" type="button">
          <span class="material-symbols-outlined">chevron_right</span>
        </button>
      </div>
    </section>
  `,
  styles: [
    `
      :host { display: block; --y: #facc15; --bg: #0f1115; --bg2: #15181f; --bd: rgba(255,255,255,0.08); --t1: #f4f5f7; --t2: #9aa0aa; --t3: #6b7280; }
      .nf-page { padding: 24px 28px; color: var(--t1); max-width: 1280px; margin: 0 auto; }
      .nf-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
      .nf-head h1 { font-size: 24px; font-weight: 800; margin: 0; letter-spacing: -0.02em; }
      .nf-sub { margin: 4px 0 0; color: var(--t2); font-size: 13px; }
      .nf-unread { color: var(--y); font-weight: 700; }
      .nf-head-actions { display: flex; gap: 10px; }

      .nf-btn { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; border-radius: 10px; padding: 9px 14px; cursor: pointer; border: 1px solid var(--bd); transition: all .15s ease; }
      .nf-btn .material-symbols-outlined { font-size: 18px; }
      .nf-btn.ghost { background: rgba(255,255,255,0.04); color: var(--t1); }
      .nf-btn.ghost:hover { background: rgba(255,255,255,0.08); }
      .nf-btn.primary { background: var(--y); color: #1a1a1a; border-color: var(--y); }
      .nf-btn.primary:hover { filter: brightness(1.05); }
      .nf-btn:disabled { opacity: .45; cursor: not-allowed; }

      .nf-toolbar { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
      .nf-search { flex: 1; min-width: 260px; display: flex; align-items: center; gap: 8px; background: var(--bg2); border: 1px solid var(--bd); border-radius: 11px; padding: 10px 14px; }
      .nf-search:focus-within { border-color: rgba(250,204,21,.5); }
      .nf-search .material-symbols-outlined { color: var(--t3); font-size: 19px; }
      .nf-search input { flex: 1; background: transparent; border: none; outline: none; color: var(--t1); font-size: 13px; }
      .nf-search input::placeholder { color: var(--t3); }

      .nf-filters { display: flex; gap: 10px; }
      .nf-select, .nf-date { background: var(--bg2); border: 1px solid var(--bd); border-radius: 11px; padding: 10px 12px; color: var(--t1); font-size: 13px; outline: none; color-scheme: dark; }
      .nf-select:focus, .nf-date:focus { border-color: rgba(250,204,21,.5); }

      .nf-chips { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
      .nf-chip { font-size: 12px; font-weight: 600; color: var(--t2); background: rgba(255,255,255,0.04); border: 1px solid transparent; padding: 7px 14px; border-radius: 999px; cursor: pointer; transition: all .15s ease; }
      .nf-chip:hover { color: var(--t1); background: rgba(255,255,255,0.08); }
      .nf-chip.active { color: #1a1a1a; background: var(--y); border-color: var(--y); }

      .nf-body { background: var(--bg); border: 1px solid var(--bd); border-radius: 16px; overflow: hidden; min-height: 200px; }

      .nf-table { width: 100%; border-collapse: collapse; }
      .nf-table thead th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--t3); font-weight: 700; padding: 12px 14px; border-bottom: 1px solid var(--bd); background: var(--bg2); }
      .nf-row { cursor: pointer; transition: background .12s ease; }
      .nf-row td { padding: 13px 14px; border-bottom: 1px solid var(--bd); font-size: 13px; vertical-align: middle; }
      .nf-row:hover { background: rgba(255,255,255,0.04); }
      .nf-row.unread { background: rgba(250,204,21,.045); }
      .nf-row.unread .nf-row-title { font-weight: 700; }

      .nf-ico { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 10px; background: rgba(255,255,255,0.06); color: var(--t2); }
      .nf-ico .material-symbols-outlined { font-size: 19px; }
      .nf-ico[data-type='payment'] { background: rgba(34,197,94,.14); color: #4ade80; }
      .nf-ico[data-type='membership'] { background: rgba(250,204,21,.16); color: var(--y); }
      .nf-ico[data-type='class'] { background: rgba(14,165,233,.16); color: #38bdf8; }
      .nf-ico[data-type='routine'] { background: rgba(168,85,247,.16); color: #c084fc; }
      .nf-ico[data-type='iron_ai'] { background: rgba(244,114,182,.16); color: #f472b6; }
      .nf-ico[data-type='promotion'] { background: rgba(249,115,22,.16); color: #fb923c; }
      .nf-ico[data-type='system'] { background: rgba(148,163,184,.16); color: #cbd5e1; }

      .nf-cell-main { max-width: 380px; }
      .nf-row-title { display: flex; align-items: center; gap: 8px; color: var(--t1); }
      .nf-row-msg { color: var(--t2); font-size: 12px; margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 360px; }
      .nf-prio { font-size: 10px; font-weight: 700; text-transform: uppercase; color: #f87171; background: rgba(248,113,113,.14); padding: 1px 6px; border-radius: 5px; }
      .nf-type { font-size: 12px; color: var(--t2); }
      .nf-member { color: var(--t1); }
      .nf-doc { color: var(--t3); font-size: 11px; margin-top: 2px; }
      .nf-ref { color: var(--t2); font-family: ui-monospace, monospace; font-size: 12px; }
      .nf-badge { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 999px; background: rgba(250,204,21,.16); color: var(--y); }
      .nf-badge.read { background: rgba(148,163,184,.16); color: #94a3b8; }
      .nf-date-cell { color: var(--t3); white-space: nowrap; }
      .nf-mark { display: grid; place-items: center; width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--bd); background: transparent; color: var(--t2); cursor: pointer; }
      .nf-mark:hover { background: var(--y); color: #1a1a1a; border-color: var(--y); }

      .nf-state { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 64px 24px; color: var(--t3); text-align: center; }
      .nf-state .material-symbols-outlined { font-size: 44px; opacity: .7; }
      .nf-state p { margin: 0; font-size: 14px; }
      .nf-error .material-symbols-outlined { color: #f87171; }

      .nf-skeleton { height: 56px; margin: 8px 12px; border-radius: 12px; background: linear-gradient(90deg, rgba(255,255,255,.04) 25%, rgba(255,255,255,.08) 37%, rgba(255,255,255,.04) 63%); background-size: 400% 100%; animation: nfsh 1.4s ease infinite; }
      @keyframes nfsh { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }

      .nf-pager { display: flex; align-items: center; justify-content: center; gap: 14px; margin-top: 18px; }
      .nf-page-info { font-size: 13px; color: var(--t2); }

      @media (max-width: 900px) {
        .nf-table thead { display: none; }
        .nf-cell-main { max-width: none; }
      }
    `,
  ],
})
export default class NotificationsPage implements OnInit, OnDestroy {
  protected readonly service = inject(NotificationsService);
  private readonly router = inject(Router);

  /** Refresco automático de la página (silencioso, sin parpadeo). */
  private poll: any = null;
  private static readonly POLL_MS = 12000;

  protected readonly items = signal<AdminNotification[]>([]);
  protected readonly meta = signal<NotificationsMeta>({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
  protected readonly loading = signal(false);
  protected readonly error = signal<string | null>(null);

  protected searchText = '';
  protected status = '';
  protected from = '';
  protected to = '';
  protected category = 'all';
  private page = 1;
  private readonly search$ = new Subject<string>();

  protected readonly categories: CategoryChip[] = [
    { label: 'Todas', value: 'all' },
    { label: 'Pagos', value: 'payment' },
    { label: 'Membresías', value: 'membership' },
    { label: 'Clases', value: 'class' },
    { label: 'Rutinas', value: 'routine' },
    { label: 'Entrenador', value: 'trainer' },
    { label: 'Sistema', value: 'system' },
    { label: 'IRON IA', value: 'iron_ai' },
    { label: 'Promociones', value: 'promotion' },
  ];

  ngOnInit(): void {
    this.search$.pipe(debounceTime(350)).subscribe((term) => {
      this.searchText = term;
      this.page = 1;
      this.fetch();
    });
    this.fetch();
    // Refresco automático silencioso (no toca el skeleton ni el scroll).
    this.poll = setInterval(() => this.fetch(true), NotificationsPage.POLL_MS);
  }

  ngOnDestroy(): void {
    if (this.poll) {
      clearInterval(this.poll);
      this.poll = null;
    }
  }

  protected onSearch(term: string): void {
    this.search$.next(term);
  }

  protected setCategory(value: string): void {
    this.category = value;
    this.page = 1;
    this.fetch();
  }

  protected reload(): void {
    this.page = 1;
    this.fetch();
  }

  protected goTo(page: number): void {
    this.page = page;
    this.fetch();
  }

  protected markOne(event: Event, n: AdminNotification): void {
    event.stopPropagation();
    this.service.markReadRequest(n.uuid).subscribe(() => {
      this.items.update((list) => list.map((x) => (x.uuid === n.uuid ? { ...x, status: 'read' } : x)));
      this.service.refreshUnreadCount();
    });
  }

  protected markAll(): void {
    this.service.markAllRequest().subscribe(() => {
      this.items.update((list) => list.map((x) => ({ ...x, status: 'read' })));
      this.service.refreshUnreadCount();
    });
  }

  protected open(n: AdminNotification): void {
    if (n.status === 'unread') {
      this.service.markReadRequest(n.uuid).subscribe(() => {
        this.items.update((list) => list.map((x) => (x.uuid === n.uuid ? { ...x, status: 'read' } : x)));
        this.service.refreshUnreadCount();
      });
    }
    const route = this.routeFor(n);
    if (route) this.router.navigate([route]);
  }

  private fetch(silent = false): void {
    // El polling es silencioso: no muestra skeleton ni borra la lista si falla.
    if (!silent) {
      this.loading.set(true);
      this.error.set(null);
    }
    this.service
      .queryRaw({
        category: this.category,
        status: this.status,
        search: this.searchText,
        from: this.from,
        to: this.to,
        page: this.page,
        perPage: 20,
      })
      .subscribe({
        next: (res) => {
          this.loading.set(false);
          if (res?.ok) {
            this.items.set(res.data ?? []);
            this.meta.set(res.meta);
            this.service.refreshUnreadCount();
          }
        },
        error: () => {
          this.loading.set(false);
          if (!silent) this.error.set('No pudimos cargar las notificaciones.');
        },
      });
  }

  protected memberName(n: AdminNotification): string | null {
    return (n.metadata && (n.metadata['member_name'] as string)) || null;
  }

  protected reference(n: AdminNotification): string | null {
    return (n.metadata && (n.metadata['reference'] as string)) || null;
  }

  protected typeLabel(type: string): string {
    const map: Record<string, string> = {
      payment: 'Pago',
      membership: 'Membresía',
      class: 'Clase',
      routine: 'Rutina',
      system: 'Sistema',
      promotion: 'Promoción',
      iron_ai: 'IRON IA',
      trainer: 'Entrenador',
    };
    return map[type] || type;
  }

  protected iconFor(type: string): string {
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

  private routeFor(n: AdminNotification): string | null {
    switch (n.type) {
      case 'payment': return '/payments';
      case 'membership': return '/users';
      case 'class': return '/classes';
      case 'routine': return '/routines';
      case 'trainer': return '/trainers';
      case 'promotion': return '/marketing';
      default: return null;
    }
  }
}
