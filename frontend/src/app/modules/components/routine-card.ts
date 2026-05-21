import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, Output } from '@angular/core';

export interface RoutineExercise {
  id: string;
  name: string;
  muscleGroup: string;
  sets: number;
  reps: number;
  suggestedWeight: string;
  restSeconds: number;
  notes: string;
  order: number;
}

export interface Routine {
  id: string;
  name: string;
  objective: string;
  level: string;
  durationMinutes: number;
  daysPerWeek: number;
  trainerId?: string | null;
  trainerName?: string | null;
  assignedMemberId?: string | null;
  assignedMemberName?: string | null;
  status: string;
  description?: string;
  notes?: string;
  exercises: RoutineExercise[];
  createdAt: string;
  updatedAt: string;
}

@Component({
  selector: 'app-routine-card',
  standalone: true,
  imports: [CommonModule],
  template: `
    <article class="card" [class.card-draft]="isDraft" [class.card-inactive]="isInactive">
      <header class="card-header">
        <div class="meta">
          <div class="meta-top">
            <span class="date">{{ routine.updatedAt | date: 'mediumDate' }}</span>
            <span class="status" [ngClass]="statusClass">{{ routine.status }}</span>
          </div>
          <h3 class="title">{{ routine.name }}</h3>
          <p class="sub">
            <span class="pill" [ngClass]="objectiveClass">{{ routine.objective }}</span>
            <span class="dot" aria-hidden="true"></span>
            <span class="muted">Nivel:</span> <strong>{{ routine.level }}</strong>
            <span class="dot" aria-hidden="true"></span>
            <span class="muted">Duración:</span> <strong>{{ routine.durationMinutes }} min</strong>
            <span class="dot" aria-hidden="true"></span>
            <span class="muted">Días/semana:</span> <strong>{{ routine.daysPerWeek }}</strong>
          </p>
        </div>

        <div class="actions">
          <button type="button" class="action" (click)="view.emit(routine)" title="Ver detalle">
            <span class="material-symbols-outlined" aria-hidden="true">visibility</span>
          </button>
          <button type="button" class="action" (click)="edit.emit(routine)" title="Editar">
            <span class="material-symbols-outlined" aria-hidden="true">edit</span>
          </button>
          <button type="button" class="action" (click)="duplicate.emit(routine)" title="Duplicar">
            <span class="material-symbols-outlined" aria-hidden="true">content_copy</span>
          </button>
          <button
            type="button"
            class="action"
            (click)="assign.emit(routine)"
            title="Asignar miembro"
          >
            <span class="material-symbols-outlined" aria-hidden="true">person_add</span>
          </button>
          <button
            type="button"
            class="action"
            (click)="toggleStatus.emit(routine)"
            title="Activar / Desactivar"
          >
            <span class="material-symbols-outlined" aria-hidden="true">power_settings_new</span>
          </button>
          <button
            type="button"
            class="action danger"
            (click)="remove.emit(routine)"
            title="Eliminar"
          >
            <span class="material-symbols-outlined" aria-hidden="true">delete</span>
          </button>
        </div>
      </header>

      <section class="assignments">
        <div class="assignment">
          <span class="material-symbols-outlined" aria-hidden="true">fitness_center</span>
          <div>
            <div class="label">Entrenador</div>
            <div class="value">{{ routine.trainerName || 'Sin asignar' }}</div>
          </div>
        </div>
        <div class="assignment">
          <span class="material-symbols-outlined" aria-hidden="true">group</span>
          <div>
            <div class="label">Asignada a</div>
            <div class="value">{{ routine.assignedMemberName || 'Plantilla general' }}</div>
          </div>
        </div>
      </section>

      <section class="exercises">
        <div class="exercises-header">
          <h4>Ejercicios</h4>
          <span class="count">{{ routine.exercises.length }} items</span>
        </div>

        <div class="exercise" *ngFor="let ex of orderedExercises; trackBy: trackExercise">
          <div class="ex-left">
            <div class="ex-name">{{ ex.name }}</div>
            <div class="ex-meta">
              <span class="chip">{{ ex.muscleGroup }}</span>
              <span class="sep" aria-hidden="true"></span>
              <span
                ><strong>{{ ex.sets }}</strong> series x <strong>{{ ex.reps }}</strong> reps</span
              >
              <span class="sep" aria-hidden="true"></span>
              <span class="muted">Peso:</span> <span>{{ ex.suggestedWeight || '—' }}</span>
              <span class="sep" aria-hidden="true"></span>
              <span class="muted">Descanso:</span> <span>{{ ex.restSeconds }}s</span>
            </div>
            <p class="ex-notes" *ngIf="ex.notes">{{ ex.notes }}</p>
          </div>
        </div>
      </section>
    </article>
  `,
  styles: [
    `
      .card {
        border: 1px solid #ededed;
        border-radius: 18px;
        background:
          linear-gradient(rgba(255, 255, 255, 0.80), rgba(255, 252, 226, 0.74)),
          url('/assets/crm/clases2.png') center / cover no-repeat;
        box-shadow: 0 14px 34px rgba(0, 0, 0, 0.05);
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        gap: 1.1rem;
        transition:
          transform 0.15s ease,
          box-shadow 0.15s ease,
          border-color 0.15s ease;
      }

      .card:hover {
        transform: translateY(-2px);
        box-shadow: 0 18px 42px rgba(0, 0, 0, 0.07);
        border-color: #e5e5e5;
      }

      .card-draft {
        border-color: rgba(251, 191, 36, 0.45);
      }

      .card-inactive {
        opacity: 0.88;
      }

      .card-header {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: flex-start;
      }

      .meta {
        min-width: 0;
      }

      .meta-top {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        margin-bottom: 0.35rem;
      }

      .date {
        font-size: 0.85rem;
        color: #666;
        font-weight: 650;
      }

      .status {
        font-size: 0.75rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        padding: 0.35rem 0.6rem;
        border-radius: 999px;
        border: 1px solid #ededed;
        background: #fafafa;
      }

      .status-active {
        border-color: rgba(16, 185, 129, 0.35);
        background: rgba(16, 185, 129, 0.1);
        color: #065f46;
      }

      .status-inactive {
        border-color: rgba(156, 163, 175, 0.45);
        background: rgba(156, 163, 175, 0.14);
        color: #374151;
      }

      .status-draft {
        border-color: rgba(251, 191, 36, 0.55);
        background: rgba(251, 191, 36, 0.14);
        color: #92400e;
      }

      .title {
        font-size: 1.3rem;
        font-weight: 850;
        margin: 0;
        letter-spacing: -0.01em;
        color: #0a0a0a;
        line-height: 1.2;
      }

      .sub {
        margin: 0.55rem 0 0;
        color: #444;
        font-size: 0.92rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem 0.55rem;
        align-items: center;
      }

      .pill {
        font-size: 0.78rem;
        font-weight: 900;
        padding: 0.25rem 0.55rem;
        border-radius: 999px;
        border: 1px solid rgba(251, 191, 36, 0.55);
        background: rgba(251, 191, 36, 0.14);
        color: #92400e;
      }

      .dot {
        width: 4px;
        height: 4px;
        border-radius: 999px;
        background: #d1d5db;
      }

      .muted {
        color: #666;
        font-weight: 650;
      }

      .actions {
        display: flex;
        gap: 0.35rem;
        flex-wrap: wrap;
        justify-content: flex-end;
      }

      .action {
        border: 1px solid #ededed;
        background: #ffffff;
        color: #111;
        border-radius: 12px;
        width: 40px;
        height: 40px;
        display: grid;
        place-items: center;
        cursor: pointer;
        transition:
          background 0.15s ease,
          border-color 0.15s ease,
          transform 0.15s ease;
      }

      .action:hover {
        background: #fafafa;
        border-color: #e5e5e5;
        transform: translateY(-1px);
      }

      .action.danger {
        border-color: rgba(239, 68, 68, 0.3);
        background: rgba(239, 68, 68, 0.06);
        color: #991b1b;
      }

      .action.danger:hover {
        background: rgba(239, 68, 68, 0.1);
      }

      .assignments {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.8rem;
      }

      .assignment {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.85rem 0.95rem;
        border: 1px solid #f0f0f0;
        border-radius: 14px;
        background: #fafafa;
      }

      .assignment span {
        color: #0a0a0a;
      }

      .label {
        font-size: 0.72rem;
        font-weight: 850;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.06em;
      }

      .value {
        font-weight: 800;
        color: #0a0a0a;
        margin-top: 0.15rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 220px;
      }

      .exercises {
        border-top: 1px solid #f0f0f0;
        padding-top: 1.05rem;
      }

      .exercises-header {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        margin-bottom: 0.7rem;
      }

      .exercises-header h4 {
        margin: 0;
        font-size: 0.95rem;
        letter-spacing: -0.01em;
        font-weight: 900;
        color: #0a0a0a;
      }

      .count {
        font-size: 0.85rem;
        color: #666;
        font-weight: 700;
      }

      .exercise {
        border: 1px solid #f0f0f0;
        border-radius: 14px;
        background: #fbfbfb;
        padding: 0.85rem 0.9rem;
        margin-bottom: 0.6rem;
      }

      .ex-name {
        font-weight: 900;
        color: #0a0a0a;
        margin-bottom: 0.2rem;
      }

      .ex-meta {
        font-size: 0.9rem;
        color: #333;
        display: flex;
        flex-wrap: wrap;
        gap: 0.25rem 0.55rem;
        align-items: center;
      }

      .chip {
        font-size: 0.78rem;
        font-weight: 900;
        padding: 0.22rem 0.55rem;
        border-radius: 999px;
        border: 1px solid #ededed;
        background: #ffffff;
        color: #111;
      }

      .sep {
        width: 4px;
        height: 4px;
        border-radius: 999px;
        background: #d1d5db;
      }

      .ex-notes {
        margin: 0.35rem 0 0;
        color: #666;
        font-size: 0.88rem;
        line-height: 1.45;
      }

      .card {
        position: relative;
        overflow: hidden;
        background:
          linear-gradient(rgba(28, 27, 27, 0.9), rgba(17, 17, 17, 0.88)),
          url('/assets/crm/clases2.png') center / cover no-repeat;
        border-color: #353534;
        color: #e5e2e1;
        box-shadow: 0 14px 34px rgba(0, 0, 0, 0.24);
      }

      .card::before {
        content: '';
        position: absolute;
        inset: 0;
        pointer-events: none;
        border-radius: inherit;
        border: 1px solid rgba(245, 197, 24, 0);
        opacity: 0;
        transition:
          opacity 0.18s ease,
          border-color 0.18s ease;
      }

      .card:hover {
        border-color: rgba(245, 197, 24, 0.42);
        box-shadow:
          0 18px 42px rgba(0, 0, 0, 0.3),
          0 0 0 3px rgba(245, 197, 24, 0.08);
      }

      .card:hover::before {
        opacity: 1;
        border-color: rgba(245, 197, 24, 0.5);
      }

      .date,
      .sub,
      .muted,
      .label,
      .count,
      .ex-notes {
        color: #b4afa6;
      }

      .title,
      .sub strong,
      .value,
      .exercises-header h4,
      .ex-name,
      .ex-meta,
      .assignment span {
        color: #e5e2e1;
      }

      .assignment,
      .exercise {
        background: rgba(20, 20, 20, 0.72);
        border-color: #353534;
      }

      .exercises {
        border-color: #353534;
      }

      .action,
      .chip {
        background: #1c1b1b;
        border-color: #353534;
        color: #e5e2e1;
      }

      .action:hover {
        background: #201f1f;
        border-color: #f5c518;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.12);
      }

      .pill,
      .status-draft {
        background: rgba(245, 197, 24, 0.14);
        color: #ffe08b;
        border-color: rgba(245, 197, 24, 0.28);
      }

      .status-active {
        background: rgba(34, 197, 94, 0.14);
        color: #86efac;
        border-color: rgba(34, 197, 94, 0.28);
      }

      .status-inactive {
        background: rgba(156, 163, 175, 0.15);
        color: #d4d4d8;
        border-color: rgba(156, 163, 175, 0.25);
      }

      .action.danger {
        background: rgba(255, 180, 171, 0.1);
        color: #ffb4ab;
        border-color: rgba(255, 180, 171, 0.24);
      }

      .action.danger:hover {
        background: rgba(255, 180, 171, 0.16);
        border-color: rgba(255, 180, 171, 0.38);
      }

      @media (max-width: 900px) {
        .card-header {
          flex-direction: column;
        }
        .actions {
          justify-content: flex-start;
        }
        .assignments {
          grid-template-columns: 1fr;
        }
        .value {
          max-width: 100%;
        }
      }
    `,
  ],
})
export default class RoutineCardComponent {
  @Input({ required: true }) routine!: Routine;

  @Output() view = new EventEmitter<Routine>();
  @Output() edit = new EventEmitter<Routine>();
  @Output() duplicate = new EventEmitter<Routine>();
  @Output() assign = new EventEmitter<Routine>();
  @Output() toggleStatus = new EventEmitter<Routine>();
  @Output() remove = new EventEmitter<Routine>();

  trackExercise = (_: number, ex: RoutineExercise) => ex.id;

  get isInactive(): boolean {
    return (this.routine?.status || '').toLowerCase() === 'inactiva';
  }

  get isDraft(): boolean {
    return (this.routine?.status || '').toLowerCase() === 'borrador';
  }

  get orderedExercises(): RoutineExercise[] {
    const list = this.routine?.exercises || [];
    return list.slice().sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
  }

  get statusClass(): string {
    const s = (this.routine?.status || '').toLowerCase();
    if (s.includes('inact')) return 'status-inactive';
    if (s.includes('act')) return 'status-active';
    return 'status-draft';
  }

  get objectiveClass(): string {
    return 'objective';
  }
}
