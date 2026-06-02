import { CommonModule } from '@angular/common';
import {
  Component,
  EventEmitter,
  Input,
  OnChanges,
  Output,
  SimpleChanges,
  inject,
  signal,
} from '@angular/core';
import { firstValueFrom } from 'rxjs';
import {
  TrainerTask,
  TrainerTasksService,
} from '../../shared/services/trainer-tasks.service';

/**
 * Modal de TAREAS del entrenador humano (CRM admin). Lista las tareas reales
 * generadas por la automatización para un entrenador, con filtros por estado y
 * prioridad, acciones (visto/completar/descartar) y el timeline coach del
 * alumno. 100% conectado al backend; sin datos simulados.
 */
@Component({
  selector: 'app-trainer-tasks-modal',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="tt-overlay" *ngIf="open" (click)="onClose()">
      <div class="tt-modal" (click)="$event.stopPropagation()">
        <header class="tt-header">
          <div>
            <h2>Tareas del entrenador</h2>
            <p>{{ trainerName || 'Entrenador' }} · seguimiento de alumnos asignados</p>
          </div>
          <button type="button" class="tt-close" (click)="onClose()" aria-label="Cerrar">
            <span class="material-symbols-outlined">close</span>
          </button>
        </header>

        <div class="tt-filters">
          <label>
            Estado
            <select [value]="status()" (change)="onStatus($event)">
              <option value="">Todos</option>
              <option value="pending">Pendiente</option>
              <option value="seen">Vista</option>
              <option value="done">Completada</option>
              <option value="dismissed">Descartada</option>
            </select>
          </label>
          <label>
            Prioridad
            <select [value]="priority()" (change)="onPriority($event)">
              <option value="">Todas</option>
              <option value="high">Alta</option>
              <option value="normal">Normal</option>
              <option value="low">Baja</option>
            </select>
          </label>
          <button type="button" class="tt-refresh" (click)="reload()" [disabled]="loading()">
            <span class="material-symbols-outlined">refresh</span> Actualizar
          </button>
        </div>

        <div class="tt-body">
          <!-- Loading -->
          <div class="tt-state" *ngIf="loading()">
            <span class="material-symbols-outlined spin">progress_activity</span>
            <p>Cargando tareas…</p>
          </div>

          <!-- Error -->
          <div class="tt-state error" *ngIf="!loading() && error()">
            <span class="material-symbols-outlined">error</span>
            <p>{{ error() }}</p>
            <button type="button" class="btn-secondary" (click)="reload()">Reintentar</button>
          </div>

          <!-- Empty -->
          <div class="tt-state" *ngIf="!loading() && !error() && tasks().length === 0">
            <span class="material-symbols-outlined">task_alt</span>
            <p>Este entrenador no tiene tareas pendientes.</p>
          </div>

          <!-- List -->
          <ul class="tt-list" *ngIf="!loading() && !error() && tasks().length > 0">
            <li class="tt-item" *ngFor="let t of tasks(); trackBy: trackTask" [ngClass]="'pri-' + t.priority">
              <div class="tt-item-main">
                <div class="tt-item-top">
                  <span class="tt-priority" [ngClass]="'pri-' + t.priority">{{ priorityLabel(t.priority) }}</span>
                  <span class="tt-type">{{ t.type }}</span>
                  <span class="tt-status" [ngClass]="'st-' + t.status">{{ statusLabel(t.status) }}</span>
                  <span class="tt-date">{{ formatDate(t.created_at) }}</span>
                </div>
                <h3>{{ t.title }}</h3>
                <p class="tt-member" *ngIf="t.member_name">
                  <span class="material-symbols-outlined">person</span> {{ t.member_name }}
                </p>
                <p class="tt-body-text">{{ t.body }}</p>
                <p class="tt-route" *ngIf="t.action_route">
                  <span class="material-symbols-outlined">link</span> {{ t.action_route }}
                </p>
              </div>

              <div class="tt-actions">
                <button
                  type="button"
                  class="act seen"
                  *ngIf="t.status === 'pending'"
                  [disabled]="busyId() === t.id"
                  (click)="markSeen(t)"
                  title="Marcar visto"
                >
                  <span class="material-symbols-outlined">visibility</span> Visto
                </button>
                <button
                  type="button"
                  class="act done"
                  *ngIf="t.status !== 'done' && t.status !== 'dismissed'"
                  [disabled]="busyId() === t.id"
                  (click)="complete(t)"
                  title="Completar"
                >
                  <span class="material-symbols-outlined">check_circle</span> Completar
                </button>
                <button
                  type="button"
                  class="act dismiss"
                  *ngIf="t.status !== 'done' && t.status !== 'dismissed'"
                  [disabled]="busyId() === t.id"
                  (click)="dismiss(t)"
                  title="Descartar"
                >
                  <span class="material-symbols-outlined">cancel</span> Descartar
                </button>
                <button
                  type="button"
                  class="act timeline"
                  (click)="openTimeline(t)"
                  title="Ver timeline del alumno"
                >
                  <span class="material-symbols-outlined">timeline</span> Timeline
                </button>
              </div>
            </li>
          </ul>
        </div>

        <!-- Timeline del alumno (panel deslizable) -->
        <div class="tt-timeline" *ngIf="timelineOpen()">
          <header>
            <h3>Timeline del alumno{{ timelineMember() ? ' · ' + timelineMember() : '' }}</h3>
            <button type="button" class="tt-close" (click)="closeTimeline()" aria-label="Cerrar timeline">
              <span class="material-symbols-outlined">close</span>
            </button>
          </header>
          <div class="tt-state" *ngIf="timelineLoading()">
            <span class="material-symbols-outlined spin">progress_activity</span>
            <p>Cargando timeline…</p>
          </div>
          <div class="tt-state" *ngIf="!timelineLoading() && timeline().length === 0">
            <span class="material-symbols-outlined">history</span>
            <p>Sin actividad de seguimiento todavía.</p>
          </div>
          <ol class="tt-timeline-list" *ngIf="!timelineLoading() && timeline().length > 0">
            <li *ngFor="let e of timeline()">
              <span class="dot" [ngClass]="'pri-' + e.priority"></span>
              <div>
                <div class="tt-tl-top">
                  <strong>{{ e.title }}</strong>
                  <span class="tt-status" [ngClass]="'st-' + e.status">{{ statusLabel(e.status) }}</span>
                  <span class="tt-date">{{ formatDate(e.created_at) }}</span>
                </div>
                <p>{{ e.body }}</p>
              </div>
            </li>
          </ol>
        </div>
      </div>
    </div>
  `,
  styles: [
    `
      .tt-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        padding: 24px;
      }
      .tt-modal {
        background: #fff;
        border-radius: 18px;
        width: min(820px, 100%);
        max-height: 88vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.25);
      }
      .tt-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        padding: 20px 22px;
        border-bottom: 1px solid #eef2f7;
      }
      .tt-header h2 { margin: 0; font-size: 18px; font-weight: 700; color: #111827; }
      .tt-header p { margin: 4px 0 0; font-size: 13px; color: #6b7280; }
      .tt-close {
        border: none;
        background: #f3f4f6;
        border-radius: 10px;
        width: 36px;
        height: 36px;
        cursor: pointer;
        display: grid;
        place-items: center;
        color: #374151;
      }
      .tt-close:hover { background: #e5e7eb; }
      .tt-filters {
        display: flex;
        gap: 14px;
        align-items: flex-end;
        padding: 16px 22px;
        flex-wrap: wrap;
        border-bottom: 1px solid #f1f5f9;
      }
      .tt-filters label { display: flex; flex-direction: column; font-size: 12px; color: #6b7280; gap: 4px; }
      .tt-filters select {
        border: 1px solid #d1d5db;
        border-radius: 10px;
        padding: 8px 12px;
        font-size: 13px;
        color: #111827;
        min-width: 150px;
        background: #fff;
      }
      .tt-refresh {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid #d1d5db;
        background: #fff;
        border-radius: 10px;
        padding: 8px 14px;
        font-size: 13px;
        cursor: pointer;
        color: #374151;
      }
      .tt-refresh:disabled { opacity: 0.5; cursor: default; }
      .tt-body { overflow-y: auto; padding: 8px 22px 18px; }
      .tt-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 10px;
        padding: 48px 20px;
        color: #6b7280;
        text-align: center;
      }
      .tt-state .material-symbols-outlined { font-size: 40px; color: #9ca3af; }
      .tt-state.error .material-symbols-outlined { color: #ef4444; }
      .spin { animation: tt-spin 1s linear infinite; }
      @keyframes tt-spin { to { transform: rotate(360deg); } }
      .tt-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
      .tt-item {
        border: 1px solid #eef2f7;
        border-left: 4px solid #d1d5db;
        border-radius: 12px;
        padding: 14px 16px;
        display: flex;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
      }
      .tt-item.pri-high { border-left-color: #ef4444; }
      .tt-item.pri-normal { border-left-color: #f59e0b; }
      .tt-item.pri-low { border-left-color: #10b981; }
      .tt-item-main { flex: 1; min-width: 260px; }
      .tt-item-top { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 6px; }
      .tt-item h3 { margin: 2px 0 6px; font-size: 15px; color: #111827; font-weight: 700; }
      .tt-member { display: flex; align-items: center; gap: 4px; font-size: 12.5px; color: #374151; margin: 0 0 6px; }
      .tt-member .material-symbols-outlined, .tt-route .material-symbols-outlined { font-size: 16px; }
      .tt-body-text { margin: 0 0 6px; font-size: 13px; color: #4b5563; line-height: 1.5; }
      .tt-route { display: flex; align-items: center; gap: 4px; font-size: 11.5px; color: #6b7280; margin: 0; }
      .tt-priority, .tt-status, .tt-type, .tt-date { font-size: 11px; border-radius: 999px; padding: 2px 9px; font-weight: 600; }
      .tt-priority.pri-high { background: #fee2e2; color: #b91c1c; }
      .tt-priority.pri-normal { background: #fef3c7; color: #92400e; }
      .tt-priority.pri-low { background: #d1fae5; color: #065f46; }
      .tt-type { background: #eef2ff; color: #3730a3; }
      .tt-status { background: #f3f4f6; color: #374151; }
      .tt-status.st-done { background: #d1fae5; color: #065f46; }
      .tt-status.st-dismissed { background: #f3f4f6; color: #9ca3af; }
      .tt-status.st-seen { background: #dbeafe; color: #1e40af; }
      .tt-date { background: transparent; color: #9ca3af; font-weight: 500; }
      .tt-actions { display: flex; flex-direction: column; gap: 6px; align-items: stretch; min-width: 130px; }
      .act {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid #e5e7eb;
        background: #fff;
        border-radius: 9px;
        padding: 7px 10px;
        font-size: 12.5px;
        cursor: pointer;
        color: #374151;
        justify-content: center;
      }
      .act:hover { background: #f9fafb; }
      .act:disabled { opacity: 0.5; cursor: default; }
      .act .material-symbols-outlined { font-size: 17px; }
      .act.done { color: #065f46; border-color: #a7f3d0; }
      .act.dismiss { color: #b91c1c; border-color: #fecaca; }
      .act.seen { color: #1e40af; border-color: #bfdbfe; }
      .act.timeline { color: #4338ca; border-color: #c7d2fe; }
      .tt-timeline { border-top: 1px solid #eef2f7; padding: 16px 22px 20px; background: #f9fafb; max-height: 40vh; overflow-y: auto; }
      .tt-timeline header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
      .tt-timeline h3 { margin: 0; font-size: 15px; color: #111827; }
      .tt-timeline-list { list-style: none; margin: 0; padding: 0; }
      .tt-timeline-list li { display: flex; gap: 12px; padding: 8px 0; border-bottom: 1px dashed #e5e7eb; }
      .tt-timeline-list .dot { width: 10px; height: 10px; border-radius: 999px; margin-top: 6px; background: #d1d5db; flex: none; }
      .tt-timeline-list .dot.pri-high { background: #ef4444; }
      .tt-timeline-list .dot.pri-normal { background: #f59e0b; }
      .tt-timeline-list .dot.pri-low { background: #10b981; }
      .tt-tl-top { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
      .tt-tl-top strong { font-size: 13px; color: #111827; }
      .tt-timeline-list p { margin: 3px 0 0; font-size: 12.5px; color: #4b5563; }
      .btn-secondary {
        border: 1px solid #d1d5db; background: #fff; border-radius: 9px;
        padding: 7px 14px; font-size: 13px; cursor: pointer; color: #374151;
      }
    `,
  ],
})
export default class TrainerTasksModalComponent implements OnChanges {
  private readonly service = inject(TrainerTasksService);

  @Input() open = false;
  @Input() trainerId: number | string | null = null;
  @Input() trainerName: string | null = null;
  @Output() close = new EventEmitter<void>();
  /** Se emite cuando cambia el estado de una tarea (por si el padre refresca). */
  @Output() changed = new EventEmitter<void>();

  tasks = signal<TrainerTask[]>([]);
  loading = signal<boolean>(false);
  error = signal<string | null>(null);
  busyId = signal<number | null>(null);

  status = signal<string>('');
  priority = signal<string>('');

  timelineOpen = signal<boolean>(false);
  timelineLoading = signal<boolean>(false);
  timeline = signal<TrainerTask[]>([]);
  timelineMember = signal<string | null>(null);

  ngOnChanges(changes: SimpleChanges): void {
    // Recarga al abrir o cambiar de entrenador.
    if ((changes['open'] || changes['trainerId']) && this.open && this.trainerId) {
      this.closeTimeline();
      this.reload();
    }
  }

  onClose(): void {
    this.close.emit();
  }

  onStatus(ev: Event): void {
    this.status.set((ev.target as HTMLSelectElement).value);
    this.reload();
  }

  onPriority(ev: Event): void {
    this.priority.set((ev.target as HTMLSelectElement).value);
    this.reload();
  }

  reload(): void {
    const id = this.trainerId;
    if (!id) return;
    this.loading.set(true);
    this.error.set(null);
    this.service
      .listTasks(id, {
        status: this.status() || undefined,
        priority: this.priority() || undefined,
        perPage: 50,
      })
      .subscribe({
        next: (res) => {
          this.tasks.set(res?.data ?? []);
          this.loading.set(false);
        },
        error: () => {
          this.error.set('No pudimos cargar las tareas del entrenador.');
          this.loading.set(false);
        },
      });
  }

  async markSeen(t: TrainerTask): Promise<void> {
    await this.runAction(t, () => firstValueFrom(this.service.markSeen(t.id)));
  }
  async complete(t: TrainerTask): Promise<void> {
    await this.runAction(t, () => firstValueFrom(this.service.complete(t.id)));
  }
  async dismiss(t: TrainerTask): Promise<void> {
    await this.runAction(t, () => firstValueFrom(this.service.dismiss(t.id)));
  }

  private async runAction(t: TrainerTask, fn: () => Promise<{ data: TrainerTask }>): Promise<void> {
    this.busyId.set(t.id);
    try {
      const res = await fn();
      const updated = res?.data;
      if (updated) {
        // Actualiza la tarea en la lista (o la quita si ya no coincide con el filtro).
        const statusFilter = this.status();
        if (statusFilter && updated.status !== statusFilter) {
          this.tasks.set(this.tasks().filter((x) => x.id !== t.id));
        } else {
          this.tasks.set(this.tasks().map((x) => (x.id === t.id ? updated : x)));
        }
        this.changed.emit();
      }
    } catch {
      this.error.set('No pudimos actualizar la tarea. Intenta de nuevo.');
    } finally {
      this.busyId.set(null);
    }
  }

  openTimeline(t: TrainerTask): void {
    this.timelineOpen.set(true);
    this.timelineMember.set(t.member_name);
    this.timelineLoading.set(true);
    this.timeline.set([]);
    this.service.memberTimeline(t.member_id).subscribe({
      next: (res) => {
        this.timeline.set(res?.data ?? []);
        this.timelineLoading.set(false);
      },
      error: () => {
        this.timeline.set([]);
        this.timelineLoading.set(false);
      },
    });
  }

  closeTimeline(): void {
    this.timelineOpen.set(false);
    this.timeline.set([]);
    this.timelineMember.set(null);
  }

  trackTask(_: number, t: TrainerTask): number {
    return t.id;
  }

  priorityLabel(p: string): string {
    return p === 'high' ? 'Alta' : p === 'low' ? 'Baja' : 'Normal';
  }

  statusLabel(s: string): string {
    switch (s) {
      case 'pending': return 'Pendiente';
      case 'seen': return 'Vista';
      case 'done': return 'Completada';
      case 'dismissed': return 'Descartada';
      default: return s;
    }
  }

  formatDate(value: string | null): string {
    if (!value) return '';
    const d = new Date(value);
    if (isNaN(d.getTime())) return '';
    return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' });
  }
}
