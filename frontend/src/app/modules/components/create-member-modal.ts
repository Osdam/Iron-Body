import { CommonModule } from '@angular/common';
import {
  Component,
  ElementRef,
  EventEmitter,
  HostListener,
  Input,
  OnInit,
  Output,
  Signal,
  signal,
} from '@angular/core';
import {
  FormBuilder,
  FormGroup,
  FormsModule,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import { ApiService, PlanSummary } from '../../services/api.service';
import { DateWheelPickerComponent } from '../../shared/components/date-wheel-picker/date-wheel-picker.component';

export interface MemberFormData {
  fullName: string;
  document: string;
  phone: string;
  email: string;
  birthDate?: string;
  gender: string;
  address?: string;
  plan: string;
  membershipStartDate?: string;
  membershipEndDate?: string;
  status: string;
  emergencyContact?: string;
  notes?: string;
  weight?: number;
  height?: number;
  fitnessGoal?: string;
  medicalConditions?: string;
  assignedTrainer?: string;
}

@Component({
  selector: 'app-create-member-modal',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule, DateWheelPickerComponent],
  template: `
    <div *ngIf="isOpen()" class="member-modal-backdrop" (click)="close()" aria-hidden="true"></div>

    <section *ngIf="isOpen()" class="member-modal-shell" aria-label="Crear nuevo miembro">
      <form [formGroup]="memberForm" (ngSubmit)="onSubmit()" class="signup-panel">
        <button
          type="button"
          class="close-button"
          (click)="close()"
          aria-label="Cerrar formulario"
          [disabled]="isSaving()"
        >
          <span class="material-symbols-outlined" aria-hidden="true">close</span>
        </button>

        <header class="signup-header">
          <div class="brand-mark" aria-label="Iron Body">
            <span class="material-symbols-outlined" aria-hidden="true">fitness_center</span>
          </div>
          <p class="eyebrow">Nuevo registro</p>
          <h2>Crear miembro Iron Body</h2>
          <p class="intro">Registra datos de contacto, membresía y perfil físico del cliente.</p>
        </header>

        <div class="quick-actions" aria-label="Opciones rápidas de registro">
          <button type="button" class="outline-action" (click)="applyMonthlyPlan()">
            <span class="material-symbols-outlined" aria-hidden="true">calendar_month</span>
            <span>Plan mensual</span>
          </button>
          <button type="button" class="outline-action" (click)="markAsPending()">
            <span class="material-symbols-outlined" aria-hidden="true">pending_actions</span>
            <span>Pendiente</span>
          </button>
        </div>

        <hr class="separator" />

        <div *ngIf="errorMessage()" class="feedback feedback-error">
          <span class="material-symbols-outlined" aria-hidden="true">error</span>
          <p>{{ errorMessage() }}</p>
        </div>

        <div *ngIf="successMessage()" class="feedback feedback-success">
          <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
          <p>{{ successMessage() }}</p>
        </div>

        <div class="form-stack">
          <section class="form-block">
            <div class="block-title">
              <span class="material-symbols-outlined" aria-hidden="true">badge</span>
              <h3>Datos personales</h3>
            </div>

            <div class="form-grid">
              <label class="field field-wide" for="fullName">
                <span>Nombre completo *</span>
                <input
                  id="fullName"
                  type="text"
                  formControlName="fullName"
                  placeholder="Ej: Alejandro Gomez"
                  autocomplete="name"
                />
                <small
                  *ngIf="
                    memberForm.get('fullName')?.hasError('required') &&
                    memberForm.get('fullName')?.touched
                  "
                >
                  El nombre es obligatorio
                </small>
              </label>

              <label class="field" for="document">
                <span>Documento *</span>
                <input id="document" type="text" formControlName="document" placeholder="1020304050" />
                <small
                  *ngIf="
                    memberForm.get('document')?.hasError('required') &&
                    memberForm.get('document')?.touched
                  "
                >
                  El documento es obligatorio
                </small>
              </label>

              <label class="field" for="phone">
                <span>Telefono *</span>
                <input id="phone" type="tel" formControlName="phone" placeholder="3001234567" />
                <small
                  *ngIf="
                    memberForm.get('phone')?.hasError('required') &&
                    memberForm.get('phone')?.touched
                  "
                >
                  El telefono es obligatorio
                </small>
              </label>

              <label class="field" for="email">
                <span>Correo</span>
                <input
                  id="email"
                  type="email"
                  formControlName="email"
                  placeholder="cliente@correo.com"
                  autocomplete="email"
                />
                <small
                  *ngIf="
                    memberForm.get('email')?.hasError('email') && memberForm.get('email')?.touched
                  "
                >
                  Ingresa un correo valido
                </small>
              </label>

              <label class="field" for="birthDate">
                <span>Fecha de nacimiento</span>
                <app-date-wheel-picker
                  formControlName="birthDate"
                  [maxYear]="currentYear"
                  size="sm"
                  ariaLabel="Fecha de nacimiento"
                ></app-date-wheel-picker>
              </label>

              <label class="field" for="gender">
                <span>Genero</span>
                <div class="pretty-select" [class.open]="openSelect() === 'gender'">
                  <button type="button" class="pretty-trigger" (click)="toggleSelect('gender')">
                    <span>{{ optionLabel('gender', memberForm.get('gender')?.value) }}</span>
                    <span class="select-chevron" aria-hidden="true"></span>
                  </button>
                  <div *ngIf="openSelect() === 'gender'" class="pretty-menu">
                    <button
                      type="button"
                      *ngFor="let option of genderOptions"
                      class="pretty-option"
                      [class.selected]="memberForm.get('gender')?.value === option.value"
                      (click)="chooseOption('gender', option.value)"
                      role="option"
                      [attr.aria-selected]="memberForm.get('gender')?.value === option.value"
                    >
                      <span class="option-main">
                        <span class="option-icon" aria-hidden="true">
                          <svg class="option-svg" viewBox="0 0 24 24">
                            <path [attr.d]="svgIcon(option.icon)"></path>
                          </svg>
                        </span>
                        <span class="option-copy">
                          <strong>{{ option.label }}</strong>
                          <small>{{ option.description }}</small>
                        </span>
                      </span>
                      <span class="option-check" aria-hidden="true"></span>
                    </button>
                  </div>
                </div>
              </label>

              <label class="field field-wide" for="address">
                <span>Direccion</span>
                <input id="address" type="text" formControlName="address" placeholder="Calle 10 # 20-30" />
              </label>
            </div>
          </section>

          <section class="form-block">
            <div class="block-title">
              <span class="material-symbols-outlined" aria-hidden="true">workspace_premium</span>
              <h3>Membresia</h3>
            </div>

            <div class="form-grid">
              <label class="field" for="plan">
                <span>Plan</span>
                <div class="pretty-select" [class.open]="openSelect() === 'plan'">
                  <button type="button" class="pretty-trigger" (click)="toggleSelect('plan')">
                    <span>{{ selectedPlanLabel() }}</span>
                    <span class="select-chevron" aria-hidden="true"></span>
                  </button>
                  <div *ngIf="openSelect() === 'plan'" class="pretty-menu plan-menu">
                    <button
                      type="button"
                      class="pretty-option"
                      [class.selected]="!memberForm.get('plan')?.value"
                      (click)="chooseOption('plan', '')"
                      role="option"
                      [attr.aria-selected]="!memberForm.get('plan')?.value"
                    >
                      <span class="option-main">
                        <span class="option-icon" aria-hidden="true">
                          <svg class="option-svg" viewBox="0 0 24 24">
                            <path [attr.d]="svgIcon('minus-circle')"></path>
                          </svg>
                        </span>
                        <span class="option-copy">
                          <strong>Sin plan</strong>
                          <small>Registrar sin membresía activa</small>
                        </span>
                      </span>
                      <span class="option-check" aria-hidden="true"></span>
                    </button>
                    <button
                      type="button"
                      *ngFor="let plan of activePlans()"
                      class="pretty-option"
                      [class.selected]="isSelectedPlan(plan.id)"
                      (click)="chooseOption('plan', plan.id)"
                      role="option"
                      [attr.aria-selected]="isSelectedPlan(plan.id)"
                    >
                      <span class="option-main">
                        <span class="option-icon" aria-hidden="true">
                          <svg class="option-svg" viewBox="0 0 24 24">
                            <path [attr.d]="svgIcon('badge')"></path>
                          </svg>
                        </span>
                        <span class="option-copy">
                          <strong>{{ plan.name }}</strong>
                          <small>{{ plan.duration_days }} días · vence automáticamente</small>
                        </span>
                      </span>
                      <span class="option-check" aria-hidden="true"></span>
                    </button>
                  </div>
                </div>
                <small *ngIf="plansError()">{{ plansError() }}</small>
              </label>

              <label class="field" for="status">
                <span>Estado *</span>
                <div class="pretty-select" [class.open]="openSelect() === 'status'">
                  <button type="button" class="pretty-trigger" (click)="toggleSelect('status')">
                    <span>{{ optionLabel('status', memberForm.get('status')?.value) }}</span>
                    <span class="select-chevron" aria-hidden="true"></span>
                  </button>
                  <div *ngIf="openSelect() === 'status'" class="pretty-menu">
                    <button
                      type="button"
                      *ngFor="let option of statusOptions"
                      class="pretty-option"
                      [class.selected]="memberForm.get('status')?.value === option.value"
                      (click)="chooseOption('status', option.value)"
                      role="option"
                      [attr.aria-selected]="memberForm.get('status')?.value === option.value"
                    >
                      <span class="option-main">
                        <span class="option-icon" aria-hidden="true">
                          <svg class="option-svg" viewBox="0 0 24 24">
                            <path [attr.d]="svgIcon(option.icon)"></path>
                          </svg>
                        </span>
                        <span class="option-copy">
                          <strong>{{ option.label }}</strong>
                          <small>{{ option.description }}</small>
                        </span>
                      </span>
                      <span class="option-check" aria-hidden="true"></span>
                    </button>
                  </div>
                </div>
                <small
                  *ngIf="
                    memberForm.get('status')?.hasError('required') &&
                    memberForm.get('status')?.touched
                  "
                >
                  El estado es obligatorio
                </small>
              </label>

              <label class="field" for="membershipStartDate">
                <span>Inicio</span>
                <app-date-wheel-picker
                  formControlName="membershipStartDate"
                  [minYear]="currentYear - 2"
                  [maxYear]="currentYear + 3"
                  size="sm"
                  ariaLabel="Fecha de inicio de membresia"
                  (dateChange)="recalculateMembershipEndDate()"
                ></app-date-wheel-picker>
              </label>

              <label class="field field-readonly" for="membershipEndDate">
                <span>Vencimiento</span>
                <div class="readonly-date">
                  <span class="material-symbols-outlined" aria-hidden="true">event_available</span>
                  <strong>{{ membershipEndLabel() }}</strong>
                </div>
                <small>{{ membershipDurationHint() }}</small>
              </label>
            </div>
          </section>

          <section class="form-block">
            <div class="block-title">
              <span class="material-symbols-outlined" aria-hidden="true">monitor_heart</span>
              <h3>Perfil fitness</h3>
            </div>

            <div class="form-grid">
              <label class="field" for="weight">
                <span>Peso (kg)</span>
                <input id="weight" type="number" formControlName="weight" min="0" step="0.1" placeholder="75" />
              </label>

              <label class="field" for="height">
                <span>Estatura (cm)</span>
                <input id="height" type="number" formControlName="height" min="0" step="0.1" placeholder="180" />
              </label>

              <label class="field" for="fitnessGoal">
                <span>Objetivo</span>
                <input
                  id="fitnessGoal"
                  type="text"
                  formControlName="fitnessGoal"
                  placeholder="Ganar masa muscular"
                />
              </label>

              <label class="field" for="emergencyContact">
                <span>Contacto emergencia</span>
                <input
                  id="emergencyContact"
                  type="text"
                  formControlName="emergencyContact"
                  placeholder="Nombre y telefono"
                />
              </label>

              <label class="field field-wide" for="medicalConditions">
                <span>Condiciones medicas</span>
                <textarea
                  id="medicalConditions"
                  formControlName="medicalConditions"
                  placeholder="Lesiones, alergias o restricciones"
                  rows="3"
                ></textarea>
              </label>

              <label class="field field-wide" for="notes">
                <span>Observaciones internas</span>
                <textarea
                  id="notes"
                  formControlName="notes"
                  placeholder="Notas para recepcion o entrenadores"
                  rows="3"
                ></textarea>
              </label>
            </div>
          </section>
        </div>

        <footer class="signup-footer">
          <p>
            ¿No esta listo?
            <button type="button" class="link-action" (click)="markAsPending()" [disabled]="isSaving()">
              Guardalo como pendiente
            </button>
          </p>
          <div class="footer-actions">
            <button type="button" class="ghost-button" (click)="close()" [disabled]="isSaving()">
              Cancelar
            </button>
            <button
              type="submit"
              class="continue-button"
              [disabled]="!memberForm.valid || isSaving()"
            >
              <span class="material-symbols-outlined" aria-hidden="true">
                {{ isSaving() ? 'progress_activity' : 'person_add' }}
              </span>
              {{ isSaving() ? 'Registrando...' : 'Continuar' }}
            </button>
          </div>
        </footer>
      </form>
    </section>
  `,
  styles: [
    `
      .member-modal-backdrop {
        position: fixed;
        inset: 0;
        z-index: 999;
        background: rgba(10, 10, 10, 0.55);
        backdrop-filter: blur(5px);
        animation: fadeIn 180ms ease;
      }

      .member-modal-shell {
        position: fixed;
        inset: 0;
        z-index: 1000;
        display: grid;
        place-items: center;
        padding: 1.25rem;
        overflow-y: auto;
      }

      .signup-panel {
        position: relative;
        width: min(100%, 860px);
        max-height: calc(100vh - 2.5rem);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        border: 1px solid #e4e4e7;
        border-radius: 10px;
        background: #ffffff;
        color: #18181b;
        box-shadow: 0 24px 70px rgba(0, 0, 0, 0.24);
        animation: slideUp 220ms cubic-bezier(0.4, 0, 0.2, 1);
      }

      .close-button {
        position: absolute;
        top: 0.75rem;
        right: 0.75rem;
        width: 2.25rem;
        height: 2.25rem;
        display: grid;
        place-items: center;
        border: 1px solid #e4e4e7;
        border-radius: 8px;
        background: #fafafa;
        color: #52525b;
        cursor: pointer;
        transition:
          background 160ms ease,
          color 160ms ease,
          border-color 160ms ease;
      }

      .close-button:hover:not(:disabled) {
        background: #f4f4f5;
        border-color: #d4d4d8;
        color: #18181b;
      }

      .signup-header {
        padding: 2rem 2rem 1rem;
      }

      .brand-mark {
        width: 2.75rem;
        height: 2.75rem;
        display: grid;
        place-items: center;
        border-radius: 8px;
        background: #18181b;
        color: #facc15;
      }

      .eyebrow {
        margin: 1.15rem 0 0.35rem;
        color: #a16207;
        font: 800 0.72rem Inter, sans-serif;
        letter-spacing: 0.08em;
        text-transform: uppercase;
      }

      .signup-header h2 {
        margin: 0;
        color: #18181b;
        font: 750 1.35rem/1.2 Inter, sans-serif;
        letter-spacing: 0;
      }

      .intro {
        margin: 0.45rem 0 0;
        color: #71717a;
        font: 400 0.92rem/1.5 Inter, sans-serif;
      }

      .quick-actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
        padding: 0 2rem;
      }

      .outline-action {
        min-height: 2.65rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        border: 1px solid #d4d4d8;
        border-radius: 8px;
        background: #ffffff;
        color: #18181b;
        font: 700 0.88rem Inter, sans-serif;
        cursor: pointer;
        transition:
          border-color 160ms ease,
          background 160ms ease,
          transform 160ms ease;
      }

      .outline-action .material-symbols-outlined {
        color: #ca8a04;
        font-size: 1.2rem;
      }

      .outline-action:hover {
        border-color: #facc15;
        background: #fffbeb;
        transform: translateY(-1px);
      }

      .separator {
        width: calc(100% - 4rem);
        margin: 1rem auto 0;
        border: 0;
        border-top: 1px dashed #d4d4d8;
      }

      .feedback {
        display: flex;
        align-items: flex-start;
        gap: 0.65rem;
        margin: 1rem 2rem 0;
        padding: 0.8rem 0.9rem;
        border-radius: 8px;
        font: 600 0.86rem/1.4 Inter, sans-serif;
      }

      .feedback p {
        margin: 0;
      }

      .feedback-error {
        border: 1px solid #fecaca;
        background: #fef2f2;
        color: #991b1b;
      }

      .feedback-success {
        border: 1px solid #bbf7d0;
        background: #f0fdf4;
        color: #166534;
      }

      .form-stack {
        flex: 1;
        display: grid;
        gap: 1rem;
        overflow-y: auto;
        padding: 1rem 2rem 1.35rem;
      }

      .form-block {
        display: grid;
        gap: 0.85rem;
      }

      .block-title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: #18181b;
      }

      .block-title .material-symbols-outlined {
        color: #ca8a04;
        font-size: 1.15rem;
      }

      .block-title h3 {
        margin: 0;
        font: 800 0.82rem Inter, sans-serif;
        letter-spacing: 0.06em;
        text-transform: uppercase;
      }

      .form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.85rem;
      }

      .field {
        display: grid;
        gap: 0.4rem;
        min-width: 0;
      }

      .field-wide {
        grid-column: 1 / -1;
      }

      .field span {
        color: #3f3f46;
        font: 700 0.82rem Inter, sans-serif;
      }

      .field input,
      .field select,
      .field textarea {
        width: 100%;
        min-height: 2.65rem;
        border: 1px solid #d4d4d8;
        border-radius: 8px;
        background: #ffffff;
        color: #18181b;
        font: 500 0.92rem Inter, sans-serif;
        padding: 0.72rem 0.78rem;
        transition:
          border-color 160ms ease,
          box-shadow 160ms ease;
      }

      .field select {
        cursor: pointer;
        appearance: none;
        padding-right: 2.4rem;
        background-image:
          linear-gradient(45deg, transparent 50%, #a16207 50%),
          linear-gradient(135deg, #a16207 50%, transparent 50%),
          linear-gradient(to right, #f4f4f5, #f4f4f5);
        background-position:
          calc(100% - 1rem) 50%,
          calc(100% - 0.72rem) 50%,
          calc(100% - 2.2rem) 50%;
        background-size:
          0.34rem 0.34rem,
          0.34rem 0.34rem,
          1px 1.45rem;
        background-repeat: no-repeat;
        color: #18181b;
        font-weight: 700;
      }

      .field select:hover {
        border-color: #c7c7cf;
        background-color: #fffdf4;
      }

      .field select option {
        background: #ffffff;
        color: #18181b;
        font-weight: 600;
      }

      .pretty-select {
        position: relative;
        width: 100%;
      }

      .pretty-trigger {
        width: 100%;
        min-height: 2.65rem;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 0.6rem;
        padding: 0.72rem 0.78rem;
        border: 1px solid #d4d4d8;
        border-radius: 8px;
        background: #ffffff;
        color: #18181b;
        font: 750 0.92rem Inter, sans-serif;
        text-align: left;
        cursor: pointer;
        transition:
          border-color 160ms ease,
          box-shadow 160ms ease,
          background 160ms ease;
      }

      .pretty-trigger > span:first-child {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .select-chevron {
        width: 0.55rem;
        height: 0.55rem;
        border-bottom: 2px solid #a16207;
        border-right: 2px solid #a16207;
        justify-self: end;
        transform: rotate(45deg) translateY(-1px);
        transition: transform 160ms ease;
      }

      .pretty-select.open .pretty-trigger,
      .pretty-trigger:hover {
        border-color: #eab308;
        background: #fffdf4;
        box-shadow: 0 0 0 3px rgba(234, 179, 8, 0.14);
      }

      .pretty-select.open .select-chevron {
        transform: rotate(225deg) translateY(-1px);
      }

      .pretty-menu {
        position: absolute;
        top: calc(100% + 0.35rem);
        left: 0;
        right: 0;
        z-index: 3200;
        display: grid;
        gap: 0.2rem;
        max-height: 260px;
        overflow-y: auto;
        padding: 0.45rem;
        border: 1px solid #e4e4e7;
        border-radius: 10px;
        background: #ffffff;
        box-shadow: 0 18px 42px rgba(0, 0, 0, 0.18);
        animation: selectIn 140ms ease;
      }

      @keyframes selectIn {
        from {
          opacity: 0;
          transform: translateY(-4px) scale(0.98);
        }
        to {
          opacity: 1;
          transform: translateY(0) scale(1);
        }
      }

      .pretty-option {
        min-height: 3.35rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.85rem;
        border: 0;
        border-radius: 8px;
        background: transparent;
        color: #3f3f46;
        text-align: left;
        padding: 0.62rem 0.7rem;
        cursor: pointer;
        transition:
          background 140ms ease,
          color 140ms ease,
          transform 140ms ease;
      }

      .pretty-option:hover {
        background: #fffbeb;
        color: #18181b;
        transform: translateY(-1px);
      }

      .pretty-option.selected {
        background: rgba(250, 204, 21, 0.18);
        color: #111827;
      }

      .option-main {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        min-width: 0;
      }

      .option-icon {
        width: 2rem;
        height: 2rem;
        display: grid;
        place-items: center;
        border-radius: 8px;
        background: #f4f4f5;
        color: #a16207;
        flex-shrink: 0;
      }

      .option-svg {
        width: 1.12rem;
        height: 1.12rem;
        display: block;
        fill: none;
        stroke: currentColor;
        stroke-width: 2;
        stroke-linecap: round;
        stroke-linejoin: round;
      }

      .pretty-option.selected .option-icon {
        background: #facc15;
        color: #111827;
      }

      .option-copy {
        display: grid;
        gap: 0.12rem;
        min-width: 0;
      }

      .option-copy strong {
        color: inherit;
        font: 850 0.9rem Inter, sans-serif;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .option-copy small {
        color: #71717a;
        font: 650 0.75rem Inter, sans-serif;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .pretty-option.selected .option-copy small {
        color: #854d0e;
      }

      .option-check {
        width: 1.15rem;
        height: 1.15rem;
        position: relative;
        display: block;
        border: 2px solid transparent;
        border-radius: 999px;
        flex-shrink: 0;
      }

      .pretty-option.selected .option-check {
        border-color: #ca8a04;
        background: #ca8a04;
      }

      .pretty-option.selected .option-check::after {
        content: '';
        position: absolute;
        left: 0.31rem;
        top: 0.16rem;
        width: 0.3rem;
        height: 0.58rem;
        border: solid #ffffff;
        border-width: 0 2px 2px 0;
        transform: rotate(45deg);
      }

      .field textarea {
        resize: vertical;
        min-height: 4.75rem;
      }

      .field input::placeholder,
      .field textarea::placeholder {
        color: #a1a1aa;
      }

      .field input:focus,
      .field select:focus,
      .field textarea:focus {
        outline: none;
        border-color: #eab308;
        box-shadow: 0 0 0 3px rgba(234, 179, 8, 0.16);
      }

      .field small {
        color: #dc2626;
        font: 700 0.76rem Inter, sans-serif;
      }

      .field-readonly small {
        color: #71717a;
      }

      .readonly-date {
        min-height: 2.65rem;
        display: flex;
        align-items: center;
        gap: 0.55rem;
        padding: 0.72rem 0.78rem;
        border: 1px solid #e4e4e7;
        border-radius: 8px;
        background: #fafafa;
        color: #18181b;
      }

      .readonly-date .material-symbols-outlined {
        color: #ca8a04;
        font-family: 'Material Symbols Outlined';
        font-weight: normal;
        font-style: normal;
        line-height: 1;
        letter-spacing: normal;
        text-transform: none;
        white-space: nowrap;
        direction: ltr;
        -webkit-font-feature-settings: 'liga';
        -webkit-font-smoothing: antialiased;
        font-size: 1.15rem;
      }

      .readonly-date strong {
        font: 850 0.92rem Inter, sans-serif;
        overflow-wrap: anywhere;
      }

      .signup-footer {
        display: grid;
        gap: 0.95rem;
        padding: 1rem;
        border-top: 1px solid #e4e4e7;
        background: #f4f4f5;
      }

      .signup-footer p {
        margin: 0;
        color: #52525b;
        text-align: center;
        font: 500 0.88rem Inter, sans-serif;
      }

      .link-action {
        border: 0;
        background: transparent;
        color: #a16207;
        font: 800 0.88rem Inter, sans-serif;
        padding: 0 0.2rem;
        cursor: pointer;
      }

      .link-action:hover:not(:disabled) {
        text-decoration: underline;
      }

      .footer-actions {
        display: grid;
        grid-template-columns: 0.75fr 1fr;
        gap: 0.75rem;
      }

      .ghost-button,
      .continue-button {
        min-height: 2.75rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        border-radius: 8px;
        font: 800 0.92rem Inter, sans-serif;
        cursor: pointer;
        transition:
          background 160ms ease,
          border-color 160ms ease,
          transform 160ms ease,
          opacity 160ms ease;
      }

      .ghost-button {
        border: 1px solid #d4d4d8;
        background: #ffffff;
        color: #18181b;
      }

      .continue-button {
        border: 1px solid #eab308;
        background: #facc15;
        color: #111827;
        box-shadow: 0 8px 18px rgba(234, 179, 8, 0.22);
      }

      .ghost-button:hover:not(:disabled),
      .continue-button:hover:not(:disabled) {
        transform: translateY(-1px);
      }

      .ghost-button:hover:not(:disabled) {
        border-color: #a1a1aa;
        background: #fafafa;
      }

      .continue-button:hover:not(:disabled) {
        background: #eab308;
      }

      button:disabled {
        opacity: 0.58;
        cursor: not-allowed;
      }

      @keyframes fadeIn {
        from {
          opacity: 0;
        }
        to {
          opacity: 1;
        }
      }

      @keyframes slideUp {
        from {
          opacity: 0;
          transform: translateY(18px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }

      @media (max-width: 640px) {
        .member-modal-shell {
          padding: 0.6rem;
        }

        .signup-panel {
          max-height: calc(100vh - 1.2rem);
          width: min(100%, 620px);
        }

        .signup-header {
          padding: 1.35rem 1.25rem 0.85rem;
        }

        .quick-actions,
        .form-stack {
          padding-left: 1.25rem;
          padding-right: 1.25rem;
        }

        .separator {
          width: calc(100% - 2.5rem);
        }

        .feedback {
          margin-left: 1.25rem;
          margin-right: 1.25rem;
        }

        .form-grid,
        .quick-actions,
        .footer-actions {
          grid-template-columns: 1fr;
        }

        .signup-footer {
          padding: 0.85rem;
        }
      }
    `,
  ],
})
export class CreateMemberModalComponent implements OnInit {
  @Input() isOpen!: Signal<boolean>;
  @Output() onClose = new EventEmitter<void>();
  @Output() onMemberCreated = new EventEmitter<any>();

  memberForm!: FormGroup;
  isSaving = signal(false);
  errorMessage = signal('');
  successMessage = signal('');
  activePlans = signal<PlanSummary[]>([]);
  plansError = signal('');
  openSelect = signal<'gender' | 'plan' | 'status' | null>(null);
  currentYear = new Date().getFullYear();

  genderOptions = [
    { value: '', label: 'Seleccionar...', icon: 'user', description: 'Sin dato definido' },
    { value: 'male', label: 'Masculino', icon: 'male', description: 'Perfil masculino' },
    { value: 'female', label: 'Femenino', icon: 'female', description: 'Perfil femenino' },
    { value: 'other', label: 'Otro', icon: 'users', description: 'Otra identidad' },
    {
      value: 'prefer_not',
      label: 'Prefiero no decir',
      icon: 'lock',
      description: 'Mantener privado',
    },
  ];

  statusOptions = [
    { value: '', label: 'Seleccionar...', icon: 'circle-help', description: 'Sin estado' },
    { value: 'active', label: 'Activo', icon: 'activity', description: 'Puede ingresar al gimnasio' },
    { value: 'inactive', label: 'Inactivo', icon: 'pause', description: 'Acceso pausado' },
    { value: 'pending', label: 'Pendiente', icon: 'clock', description: 'Falta validar registro' },
    { value: 'expired', label: 'Vencido', icon: 'alert', description: 'Membresía finalizada' },
  ];

  constructor(
    private fb: FormBuilder,
    private api: ApiService,
    private elementRef: ElementRef<HTMLElement>,
  ) {}

  @HostListener('document:click', ['$event'])
  closeSelectOnOutsideClick(event: MouseEvent): void {
    if (!this.openSelect()) return;
    if (!this.elementRef.nativeElement.contains(event.target as Node)) {
      this.openSelect.set(null);
    }
  }

  ngOnInit(): void {
    this.initializeForm();
    this.loadPlans();
  }

  applyMonthlyPlan(): void {
    const monthlyPlan =
      this.activePlans().find((plan) => plan.duration_days >= 28 && plan.duration_days <= 31) ||
      this.activePlans()[0];

    this.memberForm.patchValue({
      plan: monthlyPlan ? String(monthlyPlan.id) : '',
      status: 'active',
      membershipStartDate: this.toDateInputValue(new Date()),
    });
    this.recalculateMembershipEndDate();
  }

  markAsPending(): void {
    this.memberForm.patchValue({ status: 'pending' });
  }

  toggleSelect(select: 'gender' | 'plan' | 'status'): void {
    this.openSelect.update((current) => (current === select ? null : select));
  }

  chooseOption(control: 'gender' | 'plan' | 'status', value: string | number): void {
    this.memberForm.get(control)?.setValue(String(value));
    this.openSelect.set(null);
    if (control === 'plan') this.recalculateMembershipEndDate();
  }

  optionLabel(type: 'gender' | 'status', value: string): string {
    const options = type === 'gender' ? this.genderOptions : this.statusOptions;
    return options.find((option) => option.value === value)?.label || 'Seleccionar...';
  }

  svgIcon(icon: string): string {
    const icons: Record<string, string> = {
      user: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2 M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
      male: 'M16 3h5v5 M21 3l-7.5 7.5 M14 14a5 5 0 1 1-3.5-3.5',
      female: 'M12 14a5 5 0 1 0 0-10 5 5 0 0 0 0 10 M12 14v7 M9 18h6',
      users:
        'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M16 3.13a4 4 0 0 1 0 7.75',
      lock: 'M7 11V7a5 5 0 0 1 10 0v4 M5 11h14v10H5z M12 15v2',
      'circle-help': 'M9.09 9a3 3 0 1 1 5.82 1c0 2-3 2-3 4 M12 17h.01 M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0',
      activity: 'M22 12h-4l-3 8-6-16-3 8H2',
      pause: 'M10 15V9 M14 15V9 M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0',
      clock: 'M12 6v6l4 2 M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0',
      alert: 'M12 8v4 M12 16h.01 M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0',
      badge:
        'M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0 M8.2 11.5 7 21l5-3 5 3-1.2-9.5 M6 11h12',
      'minus-circle': 'M8 12h8 M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0',
    };

    return icons[icon] || icons['circle-help'];
  }

  selectedPlanLabel(): string {
    return this.selectedPlan()?.name || 'Sin plan';
  }

  isSelectedPlan(planId: number): boolean {
    return Number(this.memberForm.get('plan')?.value) === planId;
  }

  onSubmit(): void {
    if (!this.memberForm.valid) {
      Object.keys(this.memberForm.controls).forEach((key) => {
        this.memberForm.get(key)?.markAsTouched();
      });
      return;
    }

    this.isSaving.set(true);
    this.errorMessage.set('');
    this.successMessage.set('');

    const formData = this.memberForm.value;
    this.recalculateMembershipEndDate();
    const selectedPlan = this.selectedPlan();
    const payload = {
      ...formData,
      plan: selectedPlan?.name || '',
      membershipEndDate: this.memberForm.get('membershipEndDate')?.value || '',
    };

    this.api.createMember(payload).subscribe({
      next: (response) => {
        this.isSaving.set(false);
        this.successMessage.set(`${payload.fullName} ha sido registrado correctamente.`);
        setTimeout(() => {
          this.onMemberCreated.emit(response);
          this.close();
        }, 900);
      },
      error: (error) => {
        this.isSaving.set(false);
        const message =
          error?.error?.message ||
          (error?.status === 422
            ? 'Datos invalidos. Revisa los campos del formulario.'
            : 'No se pudo registrar el miembro. Intenta de nuevo.');
        this.errorMessage.set(message);
      },
    });
  }

  close(): void {
    if (!this.isSaving()) {
      this.memberForm.reset({
        status: 'active',
        membershipStartDate: this.toDateInputValue(new Date()),
        membershipEndDate: '',
      });
      this.errorMessage.set('');
      this.successMessage.set('');
      this.onClose.emit();
    }
  }

  private initializeForm(): void {
    this.memberForm = this.fb.group({
      fullName: ['', [Validators.required]],
      document: ['', [Validators.required]],
      phone: ['', [Validators.required]],
      email: ['', [Validators.email]],
      birthDate: [''],
      gender: [''],
      address: [''],
      plan: [''],
      membershipStartDate: [this.toDateInputValue(new Date())],
      membershipEndDate: [''],
      status: ['active', Validators.required],
      emergencyContact: [''],
      notes: [''],
      weight: [''],
      height: [''],
      fitnessGoal: [''],
      medicalConditions: [''],
      assignedTrainer: [''],
    });

    this.memberForm.get('plan')?.valueChanges.subscribe(() => this.recalculateMembershipEndDate());
    this.memberForm
      .get('membershipStartDate')
      ?.valueChanges.subscribe(() => this.recalculateMembershipEndDate());
  }

  private toDateInputValue(date: Date): string {
    return date.toISOString().slice(0, 10);
  }

  private loadPlans(): void {
    this.api.getPlans().subscribe({
      next: (response) => {
        this.activePlans.set((response.data || []).filter((plan) => plan.active));
        this.plansError.set('');
        this.recalculateMembershipEndDate();
      },
      error: () => {
        this.activePlans.set([]);
        this.plansError.set('No se pudieron cargar los planes.');
      },
    });
  }

  recalculateMembershipEndDate(): void {
    const plan = this.selectedPlan();
    const startValue = this.memberForm?.get('membershipStartDate')?.value;

    if (!plan || !startValue) {
      this.memberForm?.get('membershipEndDate')?.setValue('', { emitEvent: false });
      return;
    }

    const start = this.parseDateInput(startValue);
    if (!start) return;

    const end = new Date(start);
    end.setDate(end.getDate() + Number(plan.duration_days || 0));
    this.memberForm.get('membershipEndDate')?.setValue(this.toDateInputValue(end), { emitEvent: false });
  }

  membershipEndLabel(): string {
    const value = this.memberForm?.get('membershipEndDate')?.value;
    const date = this.parseDateInput(value);
    if (!date) return 'Selecciona un plan';

    return new Intl.DateTimeFormat('es-CO', {
      day: '2-digit',
      month: 'long',
      year: 'numeric',
    }).format(date);
  }

  membershipDurationHint(): string {
    const plan = this.selectedPlan();
    if (!plan) return 'El vencimiento se calculará automáticamente.';
    return `Calculado con ${plan.duration_days} días de duración.`;
  }

  private selectedPlan(): PlanSummary | undefined {
    const planId = Number(this.memberForm?.get('plan')?.value);
    if (!planId) return undefined;
    return this.activePlans().find((plan) => plan.id === planId);
  }

  private parseDateInput(value: string): Date | null {
    if (!value) return null;
    const [year, month, day] = value.split('-').map(Number);
    if (!year || !month || !day) return null;
    const date = new Date(year, month - 1, day);
    return Number.isNaN(date.getTime()) ? null : date;
  }
}
