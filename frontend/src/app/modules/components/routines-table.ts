import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, Output } from '@angular/core';
import type { Routine } from './routine-card';

@Component({
  selector: 'app-routines-table',
  standalone: true,
  imports: [CommonModule],
  template: `
    <section class="table-card">
      <header class="table-header">
        <div>
          <h3>Rutinas</h3>
          <p>Vista administrativa para gestionar rutinas y asignaciones.</p>
        </div>
      </header>

      <div class="table-wrap" *ngIf="routines.length; else empty">
        <table class="table">
          <thead>
            <tr>
              <th>Rutina</th>
              <th>Objetivo</th>
              <th>Nivel</th>
              <th>Duración</th>
              <th>Días/semana</th>
              <th>Entrenador</th>
              <th>Asignada a</th>
              <th>Estado</th>
              <th class="col-actions">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr *ngFor="let r of routines; trackBy: trackRoutine">
              <td class="col-title">
                <div class="name">{{ r.name }}</div>
                <div class="muted">{{ r.exercises.length || 0 }} ejercicios</div>
              </td>
              <td>
                <span class="pill">{{ r.objective }}</span>
              </td>
              <td>{{ r.level }}</td>
              <td>{{ r.durationMinutes }} min</td>
              <td>{{ r.daysPerWeek }}</td>
              <td>{{ r.trainerName || 'Sin asignar' }}</td>
              <td>{{ r.assignedMemberName || 'Plantilla general' }}</td>
              <td>
                <span class="status" [ngClass]="statusClass(r.status)">{{ r.status }}</span>
              </td>
              <td class="col-actions">
                <button type="button" class="action" (click)="view.emit(r)" title="Ver detalle">
                  <span class="material-symbols-outlined" aria-hidden="true">visibility</span>
                </button>
                <button type="button" class="action" (click)="edit.emit(r)" title="Editar">
                  <span class="material-symbols-outlined" aria-hidden="true">edit</span>
                </button>
                <button type="button" class="action" (click)="duplicate.emit(r)" title="Duplicar">
                  <span class="material-symbols-outlined" aria-hidden="true">content_copy</span>
                </button>
                <button
                  type="button"
                  class="action"
                  (click)="assign.emit(r)"
                  title="Asignar miembro"
                >
                  <span class="material-symbols-outlined" aria-hidden="true">person_add</span>
                </button>
                <button
                  type="button"
                  class="action"
                  (click)="toggleStatus.emit(r)"
                  title="Activar / Desactivar"
                >
                  <span class="material-symbols-outlined" aria-hidden="true"
                    >power_settings_new</span
                  >
                </button>
                <button
                  type="button"
                  class="action danger"
                  (click)="remove.emit(r)"
                  title="Eliminar"
                >
                  <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <ng-template #empty>
        <div class="table-empty">No hay rutinas para mostrar.</div>
      </ng-template>
    </section>
  `,
  styles: [
    `
      .table-card {
        border: 1px solid #ededed;
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 14px 34px rgba(0, 0, 0, 0.05);
        overflow: hidden;
      }

      .table-header {
        padding: 1.15rem 1.2rem;
        border-bottom: 1px solid #f0f0f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
      }

      .table-header h3 {
        margin: 0;
        font-weight: 900;
        letter-spacing: -0.01em;
        color: #0a0a0a;
      }

      .table-header p {
        margin: 0.25rem 0 0;
        color: #666;
      }

      .table-wrap {
        width: 100%;
        overflow: auto;
      }

      .table {
        width: 100%;
        border-collapse: collapse;
        min-width: 980px;
      }

      th,
      td {
        padding: 0.9rem 1.05rem;
        text-align: left;
        border-bottom: 1px solid #f3f4f6;
        vertical-align: middle;
      }

      th {
        font-size: 0.72rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #666;
        background: #fafafa;
        position: sticky;
        top: 0;
        z-index: 1;
      }

      tr:hover td {
        background: #fcfcfc;
      }

      .col-title .name {
        font-weight: 900;
        color: #0a0a0a;
      }

      .muted {
        font-size: 0.85rem;
        color: #666;
        margin-top: 0.2rem;
      }

      .pill {
        font-size: 0.78rem;
        font-weight: 900;
        padding: 0.25rem 0.6rem;
        border-radius: 999px;
        border: 1px solid rgba(251, 191, 36, 0.55);
        background: rgba(251, 191, 36, 0.14);
        color: #92400e;
        white-space: nowrap;
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
        white-space: nowrap;
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

      .col-actions {
        width: 1%;
        white-space: nowrap;
      }

      .action {
        border: 1px solid #ededed;
        background: #ffffff;
        color: #111;
        border-radius: 12px;
        width: 38px;
        height: 38px;
        display: inline-grid;
        place-items: center;
        cursor: pointer;
        margin-right: 0.25rem;
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

      .table-empty {
        padding: 1.2rem;
        color: #666;
      }

      .table-card {
        background:
          linear-gradient(rgba(28, 27, 27, 0.92), rgba(17, 17, 17, 0.9)),
          url('/assets/crm/clases2.png') center / cover no-repeat;
        border-color: #353534;
        color: #e5e2e1;
        box-shadow: 0 14px 34px rgba(0, 0, 0, 0.24);
      }

      .table-header {
        border-color: #353534;
      }

      .table-header h3,
      .col-title .name,
      td {
        color: #e5e2e1;
      }

      .table-header p,
      .muted,
      .table-empty {
        color: #b4afa6;
      }

      th {
        background: rgba(21, 21, 21, 0.92);
        color: #b4afa6;
      }

      th,
      td {
        border-color: #353534;
      }

      tr:hover td {
        background: rgba(245, 197, 24, 0.08);
      }

      .action {
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

      @media (max-width: 640px) {
        .table {
          min-width: 920px;
        }
      }
    `,
  ],
})
export default class RoutinesTableComponent {
  @Input() routines: Routine[] = [];

  @Output() view = new EventEmitter<Routine>();
  @Output() edit = new EventEmitter<Routine>();
  @Output() duplicate = new EventEmitter<Routine>();
  @Output() assign = new EventEmitter<Routine>();
  @Output() toggleStatus = new EventEmitter<Routine>();
  @Output() remove = new EventEmitter<Routine>();

  trackRoutine = (_: number, r: Routine) => r.id;

  statusClass(status: string | null | undefined): string {
    const s = String(status || '').toLowerCase();
    if (s.includes('inact')) return 'status-inactive';
    if (s.includes('act')) return 'status-active';
    return 'status-draft';
  }
}
