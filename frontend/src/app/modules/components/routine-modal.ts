import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, OnChanges, Output, inject } from '@angular/core';
import {
  FormArray,
  FormBuilder,
  FormControl,
  FormGroup,
  ReactiveFormsModule,
  Validators,
  AbstractControl,
  ValidationErrors,
} from '@angular/forms';

import type { Routine, RoutineExercise } from './routine-card';

export type RoutineModalMode = 'create' | 'edit' | 'detail' | 'assign';

interface LibraryExercise {
  name: string;
  muscleGroup: string;
  description: string;
}

const nonNegativeNumber = (control: AbstractControl): ValidationErrors | null => {
  const value = control.value;
  if (value === null || value === undefined || value === '') return null;
  const num = Number(value);
  if (Number.isNaN(num)) return { number: true };
  return num < 0 ? { nonNegative: true } : null;
};

@Component({
  selector: 'app-routine-modal',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  template: `
    <div *ngIf="isOpen" class="overlay" (click)="onOverlay($event)" role="dialog" aria-modal="true">
      <section class="drawer" [class.drawer-wide]="mode !== 'assign'">
        <header class="drawer-header">
          <div class="header-left">
            <div class="header-icon" aria-hidden="true">
              <span class="material-symbols-outlined">{{ headerIcon }}</span>
            </div>
            <div>
              <h2>{{ headerTitle }}</h2>
              <p>{{ headerSubtitle }}</p>
            </div>
          </div>

          <button type="button" class="close" (click)="close.emit()" aria-label="Cerrar">
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
          </button>
        </header>

        <div class="drawer-body">
          <div *ngIf="mode === 'assign'" class="assign-block">
            <label class="label">Miembro asignado</label>
            <select class="select" [formControl]="assignedMemberControl" [disabled]="isSaving">
              <option *ngFor="let m of members" [value]="m">{{ m }}</option>
            </select>
            <p class="hint">Selecciona el miembro o deja como Plantilla general.</p>
          </div>

          <form
            *ngIf="mode !== 'assign'"
            [formGroup]="routineForm"
            (ngSubmit)="submit()"
            class="form"
          >
            <div class="grid">
              <div class="field span-2">
                <label class="label">Nombre de la rutina</label>
                <input
                  class="input"
                  formControlName="name"
                  placeholder="Ej: Hipertrofia Tren Superior"
                  [disabled]="readonly || isSaving"
                />
                <p class="error" *ngIf="showError('name')">El nombre es obligatorio.</p>
              </div>

              <div class="field">
                <label class="label">Objetivo</label>
                <select
                  class="select"
                  formControlName="objective"
                  [disabled]="readonly || isSaving"
                >
                  <option value="">Selecciona</option>
                  <option *ngFor="let o of objectives" [value]="o">{{ o }}</option>
                </select>
                <p class="error" *ngIf="showError('objective')">El objetivo es obligatorio.</p>
              </div>

              <div class="field">
                <label class="label">Nivel</label>
                <select class="select" formControlName="level" [disabled]="readonly || isSaving">
                  <option value="">Selecciona</option>
                  <option *ngFor="let l of levels" [value]="l">{{ l }}</option>
                </select>
                <p class="error" *ngIf="showError('level')">El nivel es obligatorio.</p>
              </div>

              <div class="field">
                <label class="label">Duración estimada (min)</label>
                <input
                  class="input"
                  type="number"
                  formControlName="durationMinutes"
                  placeholder="Ej: 60"
                  [disabled]="readonly || isSaving"
                />
                <p class="error" *ngIf="showError('durationMinutes')">
                  Ingresa una duración mayor a 0.
                </p>
              </div>

              <div class="field">
                <label class="label">Días por semana</label>
                <input
                  class="input"
                  type="number"
                  formControlName="daysPerWeek"
                  placeholder="Ej: 4"
                  [disabled]="readonly || isSaving"
                />
                <p class="error" *ngIf="showError('daysPerWeek')">Ingresa un valor mayor a 0.</p>
              </div>

              <div class="field">
                <label class="label">Entrenador asignado</label>
                <select
                  class="select"
                  formControlName="trainerName"
                  [disabled]="readonly || isSaving"
                >
                  <option *ngFor="let t of trainers" [value]="t">{{ t }}</option>
                </select>
              </div>

              <div class="field">
                <label class="label">Miembro asignado</label>
                <select
                  class="select"
                  formControlName="assignedMemberName"
                  [disabled]="readonly || isSaving"
                >
                  <option *ngFor="let m of members" [value]="m">{{ m }}</option>
                </select>
              </div>

              <div class="field">
                <label class="label">Estado</label>
                <select class="select" formControlName="status" [disabled]="readonly || isSaving">
                  <option value="">Selecciona</option>
                  <option *ngFor="let s of statuses" [value]="s">{{ s }}</option>
                </select>
                <p class="error" *ngIf="showError('status')">El estado es obligatorio.</p>
              </div>

              <div class="field span-2">
                <label class="label">Descripción</label>
                <textarea
                  class="textarea"
                  formControlName="description"
                  placeholder="Describe el enfoque general de la rutina"
                  [disabled]="readonly || isSaving"
                ></textarea>
              </div>

              <div class="field span-2">
                <label class="label">Notas internas</label>
                <textarea
                  class="textarea"
                  formControlName="notes"
                  placeholder="Notas para el entrenador o recomendaciones especiales"
                  [disabled]="readonly || isSaving"
                ></textarea>
              </div>
            </div>

            <section class="builder">
              <header class="builder-header">
                <div>
                  <h3>Constructor de ejercicios</h3>
                  <p>Agrega ejercicios con series, reps, peso sugerido y descanso.</p>
                </div>

                <button
                  *ngIf="!readonly"
                  type="button"
                  class="btn-outline"
                  (click)="addExercise()"
                  [disabled]="isSaving"
                >
                  <span class="material-symbols-outlined" aria-hidden="true">add</span>
                  Agregar ejercicio
                </button>
              </header>

              <p class="error" *ngIf="exercisesInvalid">Debes agregar al menos 1 ejercicio.</p>

              <div class="exercise-grid" formArrayName="exercises">
                <article
                  class="exercise-card"
                  *ngFor="let exCtrl of exercises.controls; let i = index"
                >
                  <div class="exercise-top">
                    <div class="ex-index">Ejercicio {{ i + 1 }}</div>
                    <div class="ex-actions" *ngIf="!readonly">
                      <button
                        type="button"
                        class="tiny"
                        (click)="duplicateExercise(i)"
                        [disabled]="isSaving"
                        title="Duplicar"
                      >
                        <span class="material-symbols-outlined" aria-hidden="true"
                          >content_copy</span
                        >
                      </button>
                      <button
                        type="button"
                        class="tiny danger"
                        (click)="removeExercise(i)"
                        [disabled]="isSaving"
                        title="Eliminar"
                      >
                        <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                      </button>
                    </div>
                  </div>

                  <div class="exercise-fields" [formGroup]="getExerciseGroup(i)">
                    <div class="field span-2">
                      <label class="label">Nombre del ejercicio</label>
                      <input
                        class="input"
                        formControlName="name"
                        [disabled]="readonly || isSaving"
                        placeholder="Ej: Press banca"
                      />
                      <p class="error" *ngIf="showExerciseError(i, 'name')">
                        El nombre es obligatorio.
                      </p>
                    </div>

                    <div class="field">
                      <label class="label">Grupo muscular</label>
                      <select
                        class="select"
                        formControlName="muscleGroup"
                        [disabled]="readonly || isSaving"
                      >
                        <option value="">Selecciona</option>
                        <option *ngFor="let g of muscleGroups" [value]="g">{{ g }}</option>
                      </select>
                    </div>

                    <div class="field">
                      <label class="label">Series</label>
                      <input
                        class="input"
                        type="number"
                        formControlName="sets"
                        [disabled]="readonly || isSaving"
                      />
                      <p class="error" *ngIf="showExerciseError(i, 'sets')">Series mayor a 0.</p>
                    </div>

                    <div class="field">
                      <label class="label">Repeticiones</label>
                      <input
                        class="input"
                        type="number"
                        formControlName="reps"
                        [disabled]="readonly || isSaving"
                      />
                      <p class="error" *ngIf="showExerciseError(i, 'reps')">Reps mayor a 0.</p>
                    </div>

                    <div class="field">
                      <label class="label">Peso sugerido</label>
                      <input
                        class="input"
                        formControlName="suggestedWeight"
                        [disabled]="readonly || isSaving"
                        placeholder="Ej: 60 kg"
                      />
                    </div>

                    <div class="field">
                      <label class="label">Descanso (seg)</label>
                      <input
                        class="input"
                        type="number"
                        formControlName="restSeconds"
                        [disabled]="readonly || isSaving"
                      />
                      <p class="error" *ngIf="showExerciseError(i, 'restSeconds')">
                        Descanso no negativo.
                      </p>
                    </div>

                    <div class="field span-2">
                      <label class="label">Notas técnicas</label>
                      <input
                        class="input"
                        formControlName="notes"
                        [disabled]="readonly || isSaving"
                        placeholder="Notas de técnica o cues"
                      />
                    </div>
                  </div>
                </article>
              </div>
            </section>

            <section class="library">
              <header class="library-header">
                <div>
                  <h3>Biblioteca de ejercicios</h3>
                  <p>Ejercicios base para agregar rápidamente a la rutina.</p>
                </div>
              </header>

              <div class="library-grid">
                <article class="lib-card" *ngFor="let ex of library">
                  <div class="lib-top">
                    <div class="lib-name">{{ ex.name }}</div>
                    <span class="lib-group">{{ ex.muscleGroup }}</span>
                  </div>
                  <p class="lib-desc">{{ ex.description }}</p>
                  <button
                    *ngIf="!readonly"
                    type="button"
                    class="btn-add"
                    (click)="addFromLibrary(ex)"
                    [disabled]="isSaving"
                  >
                    <span class="material-symbols-outlined" aria-hidden="true">add</span>
                    Agregar a rutina
                  </button>
                </article>
              </div>
            </section>

            <footer class="drawer-footer">
              <div class="footer-left">
                <p class="error" *ngIf="formError">{{ formError }}</p>
              </div>

              <div class="footer-right">
                <button
                  type="button"
                  class="btn-secondary"
                  (click)="close.emit()"
                  [disabled]="isSaving"
                >
                  Cancelar
                </button>
                <button *ngIf="!readonly" type="submit" class="btn-primary" [disabled]="isSaving">
                  <span class="material-symbols-outlined" aria-hidden="true">fitness_center</span>
                  {{ isSaving ? savingLabel : submitLabel }}
                </button>
              </div>
            </footer>
          </form>
        </div>

        <footer *ngIf="mode === 'assign'" class="drawer-footer assign-footer">
          <div class="footer-right">
            <button
              type="button"
              class="btn-secondary"
              (click)="close.emit()"
              [disabled]="isSaving"
            >
              Cancelar
            </button>
            <button
              type="button"
              class="btn-primary"
              (click)="submitAssign()"
              [disabled]="isSaving"
            >
              <span class="material-symbols-outlined" aria-hidden="true">check</span>
              {{ isSaving ? 'Guardando...' : 'Guardar asignación' }}
            </button>
          </div>
        </footer>
      </section>
    </div>
  `,
  styles: [
    `
      .overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        justify-content: flex-end;
        z-index: 60;
      }

      .drawer {
        width: 560px;
        max-width: 100vw;
        height: 100vh;
        background: #ffffff;
        border-left: 1px solid rgba(255, 255, 255, 0.12);
        display: flex;
        flex-direction: column;
        box-shadow: -18px 0 50px rgba(0, 0, 0, 0.22);
      }

      .drawer-wide {
        width: 860px;
      }

      .drawer-header {
        padding: 1.25rem 1.25rem;
        border-bottom: 1px solid #f0f0f0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
      }

      .header-left {
        display: flex;
        align-items: center;
        gap: 0.9rem;
        min-width: 0;
      }

      .header-icon {
        width: 46px;
        height: 46px;
        border-radius: 14px;
        display: grid;
        place-items: center;
        border: 1px solid rgba(251, 191, 36, 0.45);
        background: rgba(251, 191, 36, 0.12);
      }

      .drawer-header h2 {
        margin: 0;
        font-weight: 900;
        letter-spacing: -0.01em;
        color: #0a0a0a;
      }

      .drawer-header p {
        margin: 0.25rem 0 0;
        color: #666;
      }

      .close {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        border: 1px solid #ededed;
        background: #ffffff;
        cursor: pointer;
        display: grid;
        place-items: center;
        transition:
          background 0.15s ease,
          border-color 0.15s ease,
          transform 0.15s ease;
      }

      .close:hover {
        background: #fafafa;
        border-color: #e5e5e5;
        transform: translateY(-1px);
      }

      .drawer-body {
        padding: 1.15rem 1.25rem;
        overflow: auto;
        flex: 1;
      }

      .form {
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
      }

      .grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.9rem;
      }

      .span-2 {
        grid-column: span 2;
      }

      .field {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
        min-width: 0;
      }

      .label {
        font-size: 0.72rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #666;
      }

      .input,
      .select,
      .textarea {
        border-radius: 12px;
        border: 1px solid #e5e5e5;
        background: #fbfbfb;
        padding: 0.75rem 0.9rem;
        font-weight: 750;
        color: #0a0a0a;
        outline: none;
        transition:
          border-color 0.15s ease,
          box-shadow 0.15s ease,
          background 0.15s ease;
      }

      .input:focus,
      .select:focus,
      .textarea:focus {
        border-color: #fbbf24;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.12);
      }

      .textarea {
        min-height: 92px;
        resize: vertical;
        font-weight: 650;
      }

      .error {
        margin: 0.25rem 0 0;
        color: #b91c1c;
        font-weight: 700;
        font-size: 0.9rem;
      }

      .hint {
        margin: 0.35rem 0 0;
        color: #666;
      }

      .builder {
        border: 1px solid #ededed;
        border-radius: 16px;
        padding: 1rem;
        background: #ffffff;
        box-shadow: 0 10px 22px rgba(0, 0, 0, 0.04);
      }

      .builder-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 0.75rem;
      }

      .builder-header h3 {
        margin: 0;
        font-weight: 900;
        letter-spacing: -0.01em;
        color: #0a0a0a;
      }

      .builder-header p {
        margin: 0.25rem 0 0;
        color: #666;
      }

      .btn-outline {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        height: 40px;
        padding: 0 0.9rem;
        border-radius: 12px;
        border: 1px solid #e5e5e5;
        background: #ffffff;
        font-weight: 800;
        cursor: pointer;
        transition:
          background 0.15s ease,
          border-color 0.15s ease;
        white-space: nowrap;
      }

      .btn-outline:hover {
        background: #fafafa;
        border-color: #d9d9d9;
      }

      .exercise-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.9rem;
      }

      .exercise-card {
        border: 1px solid #f0f0f0;
        background: #fbfbfb;
        border-radius: 16px;
        padding: 0.9rem;
      }

      .exercise-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 0.6rem;
      }

      .ex-index {
        font-size: 0.75rem;
        font-weight: 900;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.06em;
      }

      .ex-actions {
        display: flex;
        gap: 0.35rem;
      }

      .tiny {
        width: 36px;
        height: 36px;
        border-radius: 12px;
        border: 1px solid #ededed;
        background: #ffffff;
        cursor: pointer;
        display: grid;
        place-items: center;
        transition:
          background 0.15s ease,
          transform 0.15s ease;
      }

      .tiny:hover {
        background: #fafafa;
        transform: translateY(-1px);
      }

      .tiny.danger {
        border-color: rgba(239, 68, 68, 0.3);
        background: rgba(239, 68, 68, 0.06);
        color: #991b1b;
      }

      .tiny.danger:hover {
        background: rgba(239, 68, 68, 0.1);
      }

      .exercise-fields {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
      }

      .library {
        border: 1px solid #ededed;
        border-radius: 16px;
        padding: 1rem;
        background: #ffffff;
        box-shadow: 0 10px 22px rgba(0, 0, 0, 0.04);
      }

      .library-header h3 {
        margin: 0;
        font-weight: 900;
        letter-spacing: -0.01em;
        color: #0a0a0a;
      }

      .library-header p {
        margin: 0.25rem 0 0;
        color: #666;
      }

      .library-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.9rem;
        margin-top: 0.9rem;
      }

      .lib-card {
        border: 1px solid #f0f0f0;
        border-radius: 16px;
        background: #fbfbfb;
        padding: 0.9rem;
        display: flex;
        flex-direction: column;
        gap: 0.6rem;
      }

      .lib-top {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 0.6rem;
      }

      .lib-name {
        font-weight: 900;
        color: #0a0a0a;
        line-height: 1.2;
      }

      .lib-group {
        font-size: 0.75rem;
        font-weight: 900;
        padding: 0.22rem 0.5rem;
        border-radius: 999px;
        border: 1px solid #ededed;
        background: #ffffff;
        color: #444;
        white-space: nowrap;
      }

      .lib-desc {
        margin: 0;
        color: #666;
        line-height: 1.4;
        font-size: 0.92rem;
      }

      .btn-add {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
        height: 40px;
        border-radius: 12px;
        border: 1px solid rgba(251, 191, 36, 0.6);
        background: rgba(251, 191, 36, 0.14);
        color: #0a0a0a;
        font-weight: 900;
        cursor: pointer;
        transition: background 0.15s ease;
      }

      .btn-add:hover {
        background: rgba(251, 191, 36, 0.22);
      }

      .drawer-footer {
        position: sticky;
        bottom: 0;
        background: linear-gradient(to top, rgba(255, 255, 255, 1), rgba(255, 255, 255, 0.86));
        border-top: 1px solid #f0f0f0;
        padding: 1rem 0;
        margin-top: 1rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
      }

      .footer-right {
        display: flex;
        gap: 0.6rem;
      }

      .btn-secondary,
      .btn-primary {
        height: 44px;
        border-radius: 12px;
        padding: 0 1.05rem;
        font-weight: 900;
        cursor: pointer;
        border: 1px solid transparent;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        transition:
          background 0.15s ease,
          box-shadow 0.15s ease;
      }

      .btn-secondary {
        border-color: #e5e5e5;
        background: #ffffff;
        color: #0a0a0a;
      }

      .btn-secondary:hover {
        background: #fafafa;
      }

      .btn-primary {
        background: #fbbf24;
        color: #0a0a0a;
        box-shadow: 0 10px 22px rgba(251, 191, 36, 0.2);
      }

      .btn-primary:hover {
        background: #f9a825;
        box-shadow: 0 14px 28px rgba(251, 191, 36, 0.25);
      }

      .btn-primary:disabled,
      .btn-secondary:disabled,
      .btn-outline:disabled,
      .btn-add:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }

      .assign-block {
        border: 1px solid #ededed;
        border-radius: 16px;
        padding: 1rem;
        background: #ffffff;
        box-shadow: 0 10px 22px rgba(0, 0, 0, 0.04);
      }

      .assign-footer {
        padding: 1rem 1.25rem;
      }

      @media (max-width: 980px) {
        .drawer-wide {
          width: 100vw;
        }

        .exercise-grid {
          grid-template-columns: 1fr;
        }

        .library-grid {
          grid-template-columns: 1fr;
        }
      }

      @media (max-width: 640px) {
        .grid {
          grid-template-columns: 1fr;
        }

        .span-2 {
          grid-column: span 1;
        }
      }
    `,
  ],
})
export default class RoutineModalComponent implements OnChanges {
  private fb = inject(FormBuilder);

  @Input() isOpen: boolean = false;
  @Input() mode: RoutineModalMode = 'create';
  @Input() routine: Routine | null = null;
  @Input() isSaving: boolean = false;

  @Input() trainers: string[] = [
    'Sin asignar',
    'Carlos Ruiz',
    'Laura Gómez',
    'Andrés Martínez',
    'Camila Torres',
  ];
  @Input() members: string[] = [
    'Plantilla general',
    'Alejandro Gómez',
    'Juan Pérez',
    'María Rodríguez',
    'Carlos Martínez',
  ];

  @Output() close = new EventEmitter<void>();
  @Output() save = new EventEmitter<Partial<Routine>>();
  @Output() assignSave = new EventEmitter<{ assignedMemberName: string }>();

  objectives = [
    'Hipertrofia',
    'Fuerza',
    'Pérdida de grasa',
    'Resistencia',
    'Funcional',
    'Rehabilitación',
    'Mantenimiento',
  ];
  levels = ['Principiante', 'Intermedio', 'Avanzado'];
  statuses = ['Activa', 'Inactiva', 'Borrador'];
  muscleGroups = [
    'Pecho',
    'Espalda',
    'Piernas',
    'Hombros',
    'Bíceps',
    'Tríceps',
    'Abdomen',
    'Glúteos',
    'Cardio',
    'Full body',
  ];

  library: LibraryExercise[] = [
    {
      name: 'Press banca',
      muscleGroup: 'Pecho',
      description: 'Empuje horizontal para fuerza e hipertrofia.',
    },
    {
      name: 'Sentadilla',
      muscleGroup: 'Piernas',
      description: 'Base de fuerza para tren inferior y estabilidad.',
    },
    {
      name: 'Peso muerto',
      muscleGroup: 'Full body',
      description: 'Cadena posterior, fuerza total y control técnico.',
    },
    {
      name: 'Remo con barra',
      muscleGroup: 'Espalda',
      description: 'Tirón horizontal para dorsales y espalda media.',
    },
    {
      name: 'Press militar',
      muscleGroup: 'Hombros',
      description: 'Empuje vertical para deltoides y core.',
    },
    {
      name: 'Curl bíceps',
      muscleGroup: 'Bíceps',
      description: 'Aislamiento para bíceps con control del recorrido.',
    },
    {
      name: 'Extensión tríceps',
      muscleGroup: 'Tríceps',
      description: 'Aislamiento para tríceps con foco en técnica.',
    },
    {
      name: 'Plancha',
      muscleGroup: 'Abdomen',
      description: 'Estabilidad del core y control postural.',
    },
    {
      name: 'Burpees',
      muscleGroup: 'Cardio',
      description: 'Condicionamiento general y resistencia.',
    },
    {
      name: 'Jumping Jacks',
      muscleGroup: 'Cardio',
      description: 'Activación cardiovascular y coordinación.',
    },
    {
      name: 'Bicicleta',
      muscleGroup: 'Cardio',
      description: 'Trabajo cardiovascular de bajo impacto.',
    },
    {
      name: 'Caminadora',
      muscleGroup: 'Cardio',
      description: 'Cardio continuo o intervalos según objetivo.',
    },
  ];

  formError: string = '';

  assignedMemberControl = new FormControl('Plantilla general', { nonNullable: true });

  routineForm: FormGroup = this.fb.group({
    name: ['', [Validators.required]],
    objective: ['', [Validators.required]],
    level: ['', [Validators.required]],
    durationMinutes: [60, [Validators.required, Validators.min(1)]],
    daysPerWeek: [3, [Validators.required, Validators.min(1)]],
    trainerName: ['Sin asignar'],
    assignedMemberName: ['Plantilla general'],
    status: ['Activa', [Validators.required]],
    description: [''],
    notes: [''],
    exercises: this.fb.array([], [Validators.minLength(1)]),
  });

  ngOnChanges(): void {
    if (!this.isOpen) return;

    this.formError = '';

    if (this.mode === 'assign') {
      this.assignedMemberControl.setValue(this.routine?.assignedMemberName || 'Plantilla general');
      return;
    }

    const r = this.routine;
    const isCreate = this.mode === 'create';

    this.routineForm.reset({
      name: r?.name || '',
      objective: r?.objective || '',
      level: r?.level || '',
      durationMinutes: r?.durationMinutes || 60,
      daysPerWeek: r?.daysPerWeek || 3,
      trainerName: r?.trainerName || 'Sin asignar',
      assignedMemberName: r?.assignedMemberName || 'Plantilla general',
      status: r?.status || (isCreate ? 'Activa' : ''),
      description: r?.description || '',
      notes: r?.notes || '',
    });

    this.exercises.clear();
    const list = r?.exercises?.length ? r.exercises : [this.blankExercise(1)];
    list
      .slice()
      .sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
      .forEach((ex, idx) => this.exercises.push(this.exerciseGroup(ex, idx + 1)));

    if (this.readonly) {
      this.routineForm.disable({ emitEvent: false });
    } else {
      this.routineForm.enable({ emitEvent: false });
    }
  }

  get readonly(): boolean {
    return this.mode === 'detail';
  }

  get headerTitle(): string {
    if (this.mode === 'edit') return 'Editar rutina';
    if (this.mode === 'detail') return 'Detalle de rutina';
    if (this.mode === 'assign') return 'Asignar rutina';
    return 'Crear nueva rutina';
  }

  get headerSubtitle(): string {
    if (this.mode === 'assign') return 'Selecciona el miembro al que se asignará la rutina.';
    if (this.mode === 'detail')
      return 'Consulta objetivo, nivel, ejercicios y asignación de entrenamiento.';
    return 'Define objetivo, nivel, ejercicios y asignación de entrenamiento.';
  }

  get headerIcon(): string {
    if (this.mode === 'assign') return 'group_add';
    if (this.mode === 'detail') return 'visibility';
    return 'fitness_center';
  }

  get submitLabel(): string {
    if (this.mode === 'edit') return 'Guardar cambios';
    return 'Crear rutina';
  }

  get savingLabel(): string {
    if (this.mode === 'edit') return 'Guardando...';
    return 'Creando...';
  }

  get exercises(): FormArray {
    return this.routineForm.get('exercises') as FormArray;
  }

  get exercisesInvalid(): boolean {
    return this.exercises.invalid && (this.exercises.touched || this.exercises.dirty);
  }

  getExerciseGroup(index: number): FormGroup {
    return this.exercises.at(index) as FormGroup;
  }

  onOverlay(event: MouseEvent): void {
    if (event.target === event.currentTarget) this.close.emit();
  }

  showError(field: string): boolean {
    const ctrl = this.routineForm.get(field);
    return !!ctrl && ctrl.invalid && (ctrl.touched || ctrl.dirty);
  }

  showExerciseError(index: number, field: string): boolean {
    const group = this.exercises.at(index) as FormGroup;
    const ctrl = group.get(field);
    return !!ctrl && ctrl.invalid && (ctrl.touched || ctrl.dirty);
  }

  addExercise(): void {
    const next = this.exercises.length + 1;
    this.exercises.push(this.exerciseGroup(this.blankExercise(next), next));
  }

  removeExercise(index: number): void {
    if (this.exercises.length <= 1) {
      this.exercises.markAsTouched();
      return;
    }
    this.exercises.removeAt(index);
    this.reindex();
  }

  duplicateExercise(index: number): void {
    const group = this.exercises.at(index) as FormGroup;
    const raw = group.getRawValue();
    const next = this.exercises.length + 1;
    this.exercises.insert(index + 1, this.exerciseGroup({ ...raw, id: '', order: next }, next));
    this.reindex();
  }

  addFromLibrary(ex: LibraryExercise): void {
    const next = this.exercises.length + 1;
    this.exercises.push(
      this.exerciseGroup(
        {
          ...this.blankExercise(next),
          name: ex.name,
          muscleGroup: ex.muscleGroup,
          notes: ex.description,
        },
        next,
      ),
    );
  }

  submit(): void {
    this.formError = '';

    if (this.readonly) return;

    if (this.exercises.length < 1) {
      this.exercises.markAsTouched();
      this.formError = 'Debes agregar al menos 1 ejercicio.';
      return;
    }

    if (this.routineForm.invalid) {
      this.routineForm.markAllAsTouched();
      this.formError = 'Revisa los campos marcados antes de continuar.';
      return;
    }

    const value = this.routineForm.getRawValue();

    const exercises: RoutineExercise[] = (value.exercises || []).map((e: any, idx: number) => ({
      id: e.id || '',
      name: String(e.name || ''),
      muscleGroup: String(e.muscleGroup || ''),
      sets: Number(e.sets || 0),
      reps: Number(e.reps || 0),
      suggestedWeight: String(e.suggestedWeight || ''),
      restSeconds: Number(e.restSeconds || 0),
      notes: String(e.notes || ''),
      order: idx + 1,
    }));

    // Validación extra (por si hay coerciones raras)
    const hasBad = exercises.some(
      (e) => !e.name || e.sets <= 0 || e.reps <= 0 || e.restSeconds < 0,
    );
    if (hasBad) {
      this.routineForm.markAllAsTouched();
      this.formError =
        'Corrige ejercicios: nombre/series/reps obligatorios y descanso no negativo.';
      return;
    }

    this.save.emit({
      name: String(value.name || '').trim(),
      objective: value.objective,
      level: value.level,
      durationMinutes: Number(value.durationMinutes),
      daysPerWeek: Number(value.daysPerWeek),
      trainerName: value.trainerName,
      assignedMemberName: value.assignedMemberName,
      status: value.status,
      description: String(value.description || ''),
      notes: String(value.notes || ''),
      exercises,
    });
  }

  submitAssign(): void {
    this.formError = '';
    const member = this.assignedMemberControl.value || 'Plantilla general';
    this.assignSave.emit({ assignedMemberName: member });
  }

  private reindex(): void {
    this.exercises.controls.forEach((ctrl, idx) => {
      const g = ctrl as FormGroup;
      g.patchValue({ order: idx + 1 }, { emitEvent: false });
    });
  }

  private exerciseGroup(ex: Partial<RoutineExercise>, order: number): FormGroup {
    return this.fb.group({
      id: [ex.id || ''],
      name: [ex.name || '', [Validators.required]],
      muscleGroup: [ex.muscleGroup || ''],
      sets: [ex.sets ?? 3, [Validators.required, Validators.min(1)]],
      reps: [ex.reps ?? 10, [Validators.required, Validators.min(1)]],
      suggestedWeight: [ex.suggestedWeight || ''],
      restSeconds: [ex.restSeconds ?? 60, [Validators.required, nonNegativeNumber]],
      notes: [ex.notes || ''],
      order: [order],
    });
  }

  private blankExercise(order: number): RoutineExercise {
    return {
      id: '',
      name: '',
      muscleGroup: 'Full body',
      sets: 3,
      reps: 10,
      suggestedWeight: '',
      restSeconds: 60,
      notes: '',
      order,
    };
  }
}
