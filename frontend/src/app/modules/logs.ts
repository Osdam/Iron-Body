import { CommonModule } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AuditLogEntry, AuditLogService } from '../services/audit-log.service';

@Component({
  selector: 'module-logs',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <section class="logs-page">
      <header class="logs-header">
        <div>
          <span class="eyebrow">Auditoría</span>
          <h1>Logs del sistema</h1>
          <p>Control total de cambios: usuario, hora, cliente o entidad afectada y campos modificados.</p>
        </div>
        <button type="button" class="btn-primary" (click)="exportLogs()" [disabled]="!logs().length">
          <span class="material-symbols-outlined">download</span>
          Exportar JSON
        </button>
      </header>

      <div class="kpi-grid">
        <article>
          <span class="material-symbols-outlined">history</span>
          <strong>{{ logs().length }}</strong>
          <small>eventos registrados</small>
        </article>
        <article>
          <span class="material-symbols-outlined">edit_note</span>
          <strong>{{ updateCount() }}</strong>
          <small>cambios/ediciones</small>
        </article>
        <article>
          <span class="material-symbols-outlined">person_search</span>
          <strong>{{ actorCount() }}</strong>
          <small>usuarios auditados</small>
        </article>
      </div>

      <div class="filters">
        <label class="search-box">
          <span class="material-symbols-outlined">search</span>
          <input
            type="search"
            placeholder="Buscar usuario, cliente, plan, campo o resumen"
            [ngModel]="search()"
            (ngModelChange)="search.set($event)"
          />
        </label>

        <select [ngModel]="moduleFilter()" (ngModelChange)="moduleFilter.set($event)">
          <option value="all">Todos los módulos</option>
          <option *ngFor="let module of modules()" [value]="module">{{ module }}</option>
        </select>

        <select [ngModel]="actionFilter()" (ngModelChange)="actionFilter.set($event)">
          <option value="all">Todas las acciones</option>
          <option value="create">Creación</option>
          <option value="update">Edición</option>
          <option value="status">Estado</option>
          <option value="assign">Asignación</option>
          <option value="delete">Eliminación</option>
          <option value="settings">Configuración</option>
        </select>
      </div>

      <div class="logs-shell" *ngIf="filteredLogs().length; else emptyState">
        <article
          class="log-row"
          *ngFor="let log of filteredLogs(); trackBy: trackLog"
          [class.open]="selectedLogId() === log.id"
        >
          <button type="button" class="log-summary" (click)="toggleLog(log.id)">
            <span class="action-icon material-symbols-outlined">{{ actionIcon(log.action) }}</span>
            <div class="summary-main">
              <div>
                <strong>{{ log.summary }}</strong>
                <small>{{ log.module }} · {{ log.entity }} {{ log.entityId || '' }}</small>
              </div>
              <div class="log-meta">
                <span>{{ log.actorName }}</span>
                <time>{{ log.createdAt | date: 'dd MMM yyyy, HH:mm:ss' }}</time>
              </div>
            </div>
            <span class="material-symbols-outlined expand-icon">expand_more</span>
          </button>

          <div class="log-detail" *ngIf="selectedLogId() === log.id">
            <div class="detail-grid">
              <div>
                <span>Usuario</span>
                <strong>{{ log.actorName }}</strong>
                <small>{{ log.actorRole }}</small>
              </div>
              <div>
                <span>Cliente / entidad</span>
                <strong>{{ log.targetName || log.entity }}</strong>
                <small>ID: {{ log.entityId || 'N/A' }}</small>
              </div>
              <div>
                <span>Acción</span>
                <strong>{{ actionLabel(log.action) }}</strong>
                <small>{{ log.module }}</small>
              </div>
            </div>

            <div class="changes" *ngIf="log.changes.length; else noChanges">
              <div class="change-row" *ngFor="let change of log.changes">
                <strong>{{ change.field }}</strong>
                <span>{{ stringify(change.before) }}</span>
                <span class="material-symbols-outlined">arrow_forward</span>
                <span>{{ stringify(change.after) }}</span>
              </div>
            </div>

            <ng-template #noChanges>
              <div class="no-changes">El evento no trae comparación de campos, pero sí quedó registrada la acción.</div>
            </ng-template>
          </div>
        </article>
      </div>

      <ng-template #emptyState>
        <div class="empty-state">
          <span class="material-symbols-outlined">manage_search</span>
          <h2>No hay logs para mostrar</h2>
          <p>Cuando alguien cree, edite, elimine o cambie estados, los eventos aparecerán aquí.</p>
        </div>
      </ng-template>
    </section>
  `,
  styles: [
    `
      .logs-page {
        min-height: 100vh;
        padding: 1.5rem;
        background:
          linear-gradient(rgba(12, 12, 12, 0.9), rgba(12, 12, 12, 0.94)),
          url('/assets/crm/clases2.png') center / cover fixed no-repeat;
        color: #e5e2e1;
      }

      .logs-header {
        align-items: flex-start;
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1rem;
      }

      .eyebrow {
        color: #f5c518;
        font-size: 0.78rem;
        font-weight: 800;
        text-transform: uppercase;
      }

      h1,
      h2,
      p {
        margin: 0;
      }

      h1 {
        color: #f7f3eb;
        font-size: clamp(1.65rem, 3vw, 2.25rem);
      }

      p,
      small,
      time {
        color: #b8b3b1;
      }

      button,
      select,
      input {
        font: inherit;
      }

      .btn-primary {
        align-items: center;
        background: #f5c518;
        border: 0;
        border-radius: 0.6rem;
        color: #241a00;
        cursor: pointer;
        display: inline-flex;
        gap: 0.45rem;
        font-weight: 800;
        padding: 0.8rem 1rem;
      }

      .btn-primary:disabled {
        cursor: not-allowed;
        opacity: 0.55;
      }

      .kpi-grid,
      .filters,
      .logs-shell {
        display: grid;
        gap: 1rem;
      }

      .kpi-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        margin-bottom: 1rem;
      }

      .kpi-grid article,
      .filters,
      .log-row,
      .empty-state {
        background: rgba(28, 27, 27, 0.92);
        border: 1px solid #353534;
        border-radius: 0.75rem;
      }

      .kpi-grid article {
        align-items: center;
        display: flex;
        gap: 0.85rem;
        padding: 1rem;
      }

      .kpi-grid .material-symbols-outlined,
      .action-icon {
        color: #f5c518;
      }

      .kpi-grid strong {
        color: #f7f3eb;
        display: block;
        font-size: 1.35rem;
      }

      .filters {
        grid-template-columns: 1fr 220px 220px;
        margin-bottom: 1rem;
        padding: 1rem;
      }

      .search-box {
        align-items: center;
        display: flex;
        gap: 0.6rem;
      }

      input,
      select {
        background: #111;
        border: 1px solid #353534;
        border-radius: 0.55rem;
        color: #f7f3eb;
        min-height: 42px;
        padding: 0.7rem 0.8rem;
        width: 100%;
      }

      input:focus,
      select:focus {
        border-color: #f5c518;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.14);
        outline: none;
      }

      select option {
        background: #111;
        color: #f7f3eb;
      }

      .log-row {
        overflow: hidden;
      }

      .log-summary {
        align-items: center;
        background: transparent;
        border: 0;
        color: inherit;
        cursor: pointer;
        display: flex;
        gap: 0.85rem;
        padding: 1rem;
        text-align: left;
        width: 100%;
      }

      .summary-main {
        align-items: center;
        display: flex;
        gap: 1rem;
        justify-content: space-between;
        min-width: 0;
        width: 100%;
      }

      .summary-main strong {
        color: #f7f3eb;
      }

      .summary-main small {
        display: block;
        margin-top: 0.2rem;
      }

      .log-meta {
        display: grid;
        gap: 0.15rem;
        justify-items: end;
        min-width: 180px;
      }

      .log-meta span {
        color: #ffe08b;
        font-weight: 800;
      }

      .expand-icon {
        color: #f5c518;
        transition: transform 0.2s ease;
      }

      .log-row.open .expand-icon {
        transform: rotate(180deg);
      }

      .log-detail {
        border-top: 1px solid #353534;
        padding: 1rem;
      }

      .detail-grid {
        display: grid;
        gap: 0.8rem;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        margin-bottom: 1rem;
      }

      .detail-grid > div,
      .change-row,
      .no-changes {
        background: #111;
        border: 1px solid #2b2a29;
        border-radius: 0.6rem;
        padding: 0.85rem;
      }

      .detail-grid span {
        color: #b8b3b1;
        display: block;
        font-size: 0.75rem;
        text-transform: uppercase;
      }

      .detail-grid strong {
        color: #f7f3eb;
        display: block;
        margin-top: 0.25rem;
      }

      .changes {
        display: grid;
        gap: 0.55rem;
      }

      .change-row {
        align-items: center;
        display: grid;
        gap: 0.65rem;
        grid-template-columns: 160px 1fr auto 1fr;
      }

      .change-row strong {
        color: #f5c518;
      }

      .change-row span {
        color: #e5e2e1;
        min-width: 0;
        overflow-wrap: anywhere;
      }

      .no-changes {
        color: #b8b3b1;
      }

      .empty-state {
        display: grid;
        justify-items: center;
        padding: 3rem 1rem;
        text-align: center;
      }

      .empty-state .material-symbols-outlined {
        color: #f5c518;
        font-size: 2.6rem;
      }

      @media (max-width: 900px) {
        .logs-header,
        .summary-main {
          align-items: flex-start;
          flex-direction: column;
        }

        .kpi-grid,
        .filters,
        .detail-grid,
        .change-row {
          grid-template-columns: 1fr;
        }

        .log-meta {
          justify-items: start;
          min-width: 0;
        }
      }
    `,
  ],
})
export default class LogsModule {
  private readonly auditLog = inject(AuditLogService);

  readonly search = signal('');
  readonly moduleFilter = signal('all');
  readonly actionFilter = signal('all');
  readonly selectedLogId = signal('');
  readonly logs = this.auditLog.entries;

  readonly modules = computed(() =>
    Array.from(new Set(this.logs().map((log) => log.module))).sort((a, b) => a.localeCompare(b)),
  );

  readonly filteredLogs = computed(() => {
    const query = this.search().trim().toLowerCase();
    const module = this.moduleFilter();
    const action = this.actionFilter();

    return this.logs().filter((log) => {
      const matchesModule = module === 'all' || log.module === module;
      const matchesAction = action === 'all' || log.action === action;
      const haystack = [
        log.summary,
        log.module,
        log.entity,
        log.entityId,
        log.targetName,
        log.actorName,
        log.actorRole,
        ...log.changes.flatMap((change) => [change.field, change.before, change.after]),
      ]
        .join(' ')
        .toLowerCase();
      return matchesModule && matchesAction && (!query || haystack.includes(query));
    });
  });

  readonly updateCount = computed(
    () => this.logs().filter((log) => ['update', 'status', 'settings', 'assign'].includes(log.action)).length,
  );

  readonly actorCount = computed(() => new Set(this.logs().map((log) => log.actorName)).size);

  trackLog(_: number, log: AuditLogEntry): string {
    return log.id;
  }

  toggleLog(id: string): void {
    this.selectedLogId.update((current) => (current === id ? '' : id));
  }

  actionIcon(action: AuditLogEntry['action']): string {
    const icons: Record<AuditLogEntry['action'], string> = {
      create: 'add_circle',
      update: 'edit',
      delete: 'delete',
      status: 'published_with_changes',
      assign: 'assignment_ind',
      settings: 'settings',
    };
    return icons[action];
  }

  actionLabel(action: AuditLogEntry['action']): string {
    const labels: Record<AuditLogEntry['action'], string> = {
      create: 'Creación',
      update: 'Edición',
      delete: 'Eliminación',
      status: 'Cambio de estado',
      assign: 'Asignación',
      settings: 'Configuración',
    };
    return labels[action];
  }

  stringify(value: unknown): string {
    if (value === undefined) return 'Sin dato';
    if (value === null || value === '') return 'Vacío';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
  }

  exportLogs(): void {
    const blob = new Blob([this.auditLog.exportJson()], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `ironbody-logs-${new Date().toISOString().slice(0, 10)}.json`;
    link.click();
    URL.revokeObjectURL(url);
  }
}
