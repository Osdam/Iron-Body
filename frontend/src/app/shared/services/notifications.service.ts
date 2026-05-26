import { Injectable, signal, computed, inject, NgZone, OnDestroy } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Subject, of } from 'rxjs';
import { catchError, debounceTime, switchMap } from 'rxjs/operators';

/** Notificación tal como la entrega el backend (Notification::toPublicArray). */
export interface AdminNotification {
  uuid: string;
  type: 'payment' | 'membership' | 'class' | 'system' | 'trainer' | 'promotion' | 'iron_ai' | 'routine' | string;
  audience: string;
  title: string;
  message: string;
  status: 'unread' | 'read';
  priority: 'low' | 'medium' | 'high';
  document: string | null;
  member_id: number | null;
  action_type: string | null;
  action_url: string | null;
  action_payload: Record<string, any> | null;
  metadata: Record<string, any> | null;
  read_at: string | null;
  created_at: string | null;
  time_ago: string;
}

export interface NotificationsMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

interface AdminListResponse {
  ok: boolean;
  data: AdminNotification[];
  unread_count: number;
  meta: NotificationsMeta;
}

/** Tabs del CRM → categoría que entiende el backend. */
export type NotificationTab =
  | 'todas'
  | 'noLeidas'
  | 'pagos'
  | 'membresias'
  | 'clases'
  | 'rutinas'
  | 'entrenador'
  | 'promociones'
  | 'sistema'
  | 'iron_ai';

const TAB_TO_PARAM: Record<NotificationTab, { category: string; status?: string }> = {
  todas: { category: 'all' },
  noLeidas: { category: 'all', status: 'unread' },
  pagos: { category: 'payment' },
  membresias: { category: 'membership' },
  clases: { category: 'class' },
  rutinas: { category: 'routine' },
  entrenador: { category: 'trainer' },
  promociones: { category: 'promotion' },
  sistema: { category: 'system' },
  iron_ai: { category: 'iron_ai' },
};

/**
 * Notificaciones del CRM (audience=admin). 100% conectado al backend Laravel:
 * NO hay datos simulados. Mantiene el contador del topbar al día con polling
 * estable y expone helpers para el popover y la página completa.
 */
@Injectable({ providedIn: 'root' })
export class NotificationsService implements OnDestroy {
  private readonly http = inject(HttpClient);
  private readonly zone = inject(NgZone);

  /** Misma base que ApiService (backend local; el túnel ngrok apunta aquí). */
  private readonly base = 'http://127.0.0.1:8080/api/admin/notifications';

  // ── Estado reactivo ──
  private readonly _items = signal<AdminNotification[]>([]);
  public readonly items = this._items.asReadonly();

  private readonly _unreadCount = signal<number>(0);
  public readonly unreadCount = this._unreadCount.asReadonly();

  private readonly _meta = signal<NotificationsMeta>({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
  public readonly meta = this._meta.asReadonly();

  private readonly _loading = signal<boolean>(false);
  public readonly loading = this._loading.asReadonly();

  private readonly _error = signal<string | null>(null);
  public readonly error = this._error.asReadonly();

  private readonly _tab = signal<NotificationTab>('todas');
  public readonly selectedTab = this._tab.asReadonly();
  /** Compat con el popover anterior. */
  public readonly selectedFilter = this._tab.asReadonly();

  private readonly _search = signal<string>('');
  public readonly searchTerm = this._search.asReadonly();

  private page = 1;
  private perPage = 8;
  private pollHandle: any = null;
  private readonly search$ = new Subject<string>();

  /** Tiempo real (SSE): empuja notificaciones nuevas sin esperar al polling. */
  private readonly streamUrl = `${this.base}/stream`;
  private eventSource: EventSource | null = null;
  private realtimeDebounce: any = null;

  public readonly hasItems = computed(() => this._items().length > 0);

  constructor() {
    // Búsqueda con debounce: no martillea el backend mientras se escribe.
    this.search$
      .pipe(
        debounceTime(350),
        switchMap((term) => {
          this._search.set(term);
          this.page = 1;
          return this.fetch$();
        }),
        catchError(() => of(null)),
      )
      .subscribe();
  }

  ngOnDestroy(): void {
    this.stopPolling();
  }

  // ── API pública para componentes ──

  /** Cambiar de tab (categoría/estado) y recargar desde el backend. */
  setTab(tab: NotificationTab): void {
    if (this._tab() === tab) return;
    this._tab.set(tab);
    this.page = 1;
    this.refresh();
  }

  /** Compat: el popover anterior llamaba filterNotifications(value). */
  filterNotifications(tab: string): void {
    this.setTab((tab as NotificationTab) ?? 'todas');
  }

  /** Buscar (debounced). */
  search(term: string): void {
    this.search$.next(term ?? '');
  }

  /** Tamaño de página (8 en popover, 20 en página completa). */
  setPerPage(n: number): void {
    this.perPage = n;
  }

  goToPage(page: number): void {
    this.page = Math.max(1, page);
    this.refresh();
  }

  /** Recarga lista + contador desde el backend. */
  refresh(): void {
    this.fetch$().subscribe();
  }

  /** Solo el contador (polling ligero). */
  refreshUnreadCount(): void {
    this.http
      .get<{ ok: boolean; unread_count: number }>(`${this.base}/unread-count`)
      .pipe(catchError(() => of(null)))
      .subscribe((res) => {
        if (res) this._unreadCount.set(res.unread_count ?? 0);
      });
  }

  markAsRead(uuid: string): void {
    // Optimista: actualiza UI y resincroniza con el backend.
    this._items.update((list) =>
      list.map((n) => (n.uuid === uuid ? { ...n, status: 'read' as const } : n)),
    );
    this._unreadCount.update((c) => Math.max(0, c - 1));
    this.http
      .post(`${this.base}/${uuid}/read`, {})
      .pipe(catchError(() => of(null)))
      .subscribe(() => this.refreshUnreadCount());
  }

  markAllAsRead(): void {
    this._items.update((list) => list.map((n) => ({ ...n, status: 'read' as const })));
    this._unreadCount.set(0);
    this.http
      .post(`${this.base}/read-all`, {})
      .pipe(catchError(() => of(null)))
      .subscribe(() => this.refresh());
  }

  /** Notificación manual desde el CRM. */
  createManual(payload: {
    title: string;
    message: string;
    audience?: 'member' | 'admin';
    type?: string;
    priority?: 'low' | 'medium' | 'high';
    document?: string;
    member_id?: number;
  }) {
    return this.http.post<{ ok: boolean; data: AdminNotification }>(this.base, payload);
  }

  // ── Polling estable (cada 25s mientras el panel está montado) ──

  startPolling(everyMs = 25000): void {
    // Tiempo real primero; el polling queda como fallback (red intermitente).
    this.startRealtime();
    if (this.pollHandle) return;
    this.refresh();
    // Fuera de Angular para no disparar change-detection en cada tick del timer.
    this.zone.runOutsideAngular(() => {
      this.pollHandle = setInterval(() => {
        this.zone.run(() => this.refreshUnreadCount());
      }, everyMs);
    });
  }

  stopPolling(): void {
    if (this.pollHandle) {
      clearInterval(this.pollHandle);
      this.pollHandle = null;
    }
    this.stopRealtime();
  }

  // ── Tiempo real (SSE) ──

  /** Abre el stream SSE; cada notificación nueva refresca lista + contador. */
  private startRealtime(): void {
    if (this.eventSource || typeof EventSource === 'undefined') return;
    this.zone.runOutsideAngular(() => {
      try {
        const es = new EventSource(this.streamUrl);
        es.addEventListener('notification', () => this.onRealtimeEvent());
        // El backend cierra cada ~20s; EventSource reconecta solo.
        es.onerror = () => {/* reconexión nativa */};
        this.eventSource = es;
      } catch {
        this.eventSource = null;
      }
    });
  }

  private stopRealtime(): void {
    if (this.realtimeDebounce) {
      clearTimeout(this.realtimeDebounce);
      this.realtimeDebounce = null;
    }
    if (this.eventSource) {
      this.eventSource.close();
      this.eventSource = null;
    }
  }

  /** Coalesce ráfagas de eventos en un solo refresco suave (200 ms). */
  private onRealtimeEvent(): void {
    if (this.realtimeDebounce) clearTimeout(this.realtimeDebounce);
    this.realtimeDebounce = setTimeout(() => {
      this.zone.run(() => {
        this.refresh();
        this.refreshUnreadCount();
      });
    }, 200);
  }

  // ── Compat con el popover anterior ──
  getFilteredNotifications(): AdminNotification[] {
    return this._items();
  }

  // ── Helpers STATELESS para la página completa /notifications ──
  // No tocan el estado compartido del popover: la página mantiene el suyo.

  queryRaw(opts: {
    category?: string;
    status?: string;
    search?: string;
    from?: string;
    to?: string;
    page?: number;
    perPage?: number;
  }) {
    const params = new URLSearchParams();
    params.set('category', opts.category || 'all');
    if (opts.status) params.set('status', opts.status);
    if (opts.search) params.set('search', opts.search);
    if (opts.from) params.set('from', opts.from);
    if (opts.to) params.set('to', opts.to);
    params.set('per_page', String(opts.perPage ?? 20));
    params.set('page', String(opts.page ?? 1));
    return this.http.get<AdminListResponse>(`${this.base}?${params.toString()}`);
  }

  markReadRequest(uuid: string) {
    return this.http.post<{ ok: boolean; data: AdminNotification }>(`${this.base}/${uuid}/read`, {});
  }

  markAllRequest() {
    return this.http.post<{ ok: boolean; updated: number }>(`${this.base}/read-all`, {});
  }

  // ── Interno ──
  private fetch$() {
    this._loading.set(true);
    this._error.set(null);

    const { category, status } = TAB_TO_PARAM[this._tab()];
    const params = new URLSearchParams();
    params.set('category', category);
    if (status) params.set('status', status);
    if (this._search()) params.set('search', this._search());
    params.set('per_page', String(this.perPage));
    params.set('page', String(this.page));

    return this.http.get<AdminListResponse>(`${this.base}?${params.toString()}`).pipe(
      catchError(() => {
        this._loading.set(false);
        this._error.set('No pudimos cargar las notificaciones.');
        return of(null);
      }),
      switchMap((res) => {
        this._loading.set(false);
        if (res && res.ok) {
          this._items.set(res.data ?? []);
          this._unreadCount.set(res.unread_count ?? 0);
          this._meta.set(res.meta);
        }
        return of(res);
      }),
    );
  }
}
