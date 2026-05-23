import { CommonModule } from '@angular/common';
import {
  Component,
  Output,
  EventEmitter,
  Input,
  OnChanges,
  SimpleChanges,
  signal,
  Signal,
} from '@angular/core';
import {
  ReactiveFormsModule,
  FormsModule,
  FormBuilder,
  FormGroup,
  Validators,
} from '@angular/forms';
import { ApiService, PlanAiCapabilities, PlanFeatures, PlanSummary } from '../../services/api.service';
import { PlanCardData } from './plan-card';

@Component({
  selector: 'app-edit-plan-modal',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule],
  template: `
    <div *ngIf="isOpen()" class="modal-backdrop" (click)="close()" aria-hidden="true"></div>

    <div *ngIf="isOpen()" class="modal-container" role="dialog" aria-modal="true">
      <div class="modal-card">
        <div class="modal-header">
          <div class="header-content">
            <div class="header-icon">
              <span class="material-symbols-outlined" aria-hidden="true">edit</span>
            </div>
            <div class="header-text">
              <h2 class="modal-title">Editar plan</h2>
              <p class="modal-subtitle">Modifica la información del plan de membresía.</p>
            </div>
          </div>
          <button class="btn-close" (click)="close()" aria-label="Cerrar modal">
            <span class="material-symbols-outlined" aria-hidden="true">close</span>
          </button>
        </div>

        <div *ngIf="errorMessage()" class="error-message">
          <span class="material-symbols-outlined" aria-hidden="true">error</span>
          <div>
            <strong>Error al actualizar</strong>
            <p>{{ errorMessage() }}</p>
          </div>
        </div>

        <form [formGroup]="planForm" (ngSubmit)="onSubmit()" class="modal-form">
          <div class="form-grid">
            <div class="form-group full-width">
              <label for="edit-name" class="form-label">Nombre del plan *</label>
              <input
                id="edit-name"
                type="text"
                formControlName="name"
                class="form-input"
                placeholder="Ej: Plan Mensual, Plan VIP"
              />
              <span
                *ngIf="planForm.get('name')?.hasError('required') && planForm.get('name')?.touched"
                class="error-text"
              >
                El nombre es obligatorio
              </span>
            </div>

            <div class="form-group full-width">
              <label for="edit-benefits" class="form-label">Descripción / Beneficios *</label>
              <textarea
                id="edit-benefits"
                formControlName="benefits"
                class="form-input textarea"
                placeholder="Ej: Reserva de clases, acceso a rutinas asignadas..."
                rows="3"
              ></textarea>
              <span
                *ngIf="
                  planForm.get('benefits')?.hasError('required') &&
                  planForm.get('benefits')?.touched
                "
                class="error-text"
              >
                Los beneficios son obligatorios
              </span>
            </div>

            <div class="form-group">
              <label for="edit-price" class="form-label">Precio en COP *</label>
              <input
                id="edit-price"
                type="number"
                formControlName="price"
                class="form-input"
                placeholder="Ej: 80000"
                min="1"
              />
              <span
                *ngIf="planForm.get('price')?.hasError('required') && planForm.get('price')?.touched"
                class="error-text"
              >
                El precio es obligatorio
              </span>
              <span
                *ngIf="planForm.get('price')?.hasError('min') && planForm.get('price')?.touched"
                class="error-text"
              >
                El precio debe ser mayor a 0
              </span>
            </div>

            <div class="form-group">
              <label for="edit-duration" class="form-label">Duración en días *</label>
              <input
                id="edit-duration"
                type="number"
                formControlName="duration_days"
                class="form-input"
                placeholder="Ej: 30, 90, 180, 365"
                min="1"
              />
              <span
                *ngIf="
                  planForm.get('duration_days')?.hasError('required') &&
                  planForm.get('duration_days')?.touched
                "
                class="error-text"
              >
                La duración es obligatoria
              </span>
            </div>

            <div class="form-group">
              <label for="edit-status" class="form-label">Estado *</label>
              <select id="edit-status" formControlName="active" class="form-select">
                <option [ngValue]="true">Activo</option>
                <option [ngValue]="false">Inactivo</option>
              </select>
            </div>

            <div class="form-group">
              <label for="edit-access" class="form-label">Acceso a clases</label>
              <select id="edit-access" formControlName="access_classes" class="form-select">
                <option [ngValue]="true">Incluido</option>
                <option [ngValue]="false">No incluido</option>
              </select>
            </div>

            <div class="form-group">
              <label for="edit-reservations" class="form-label">Límite de reservas</label>
              <input
                id="edit-reservations"
                type="number"
                formControlName="reservations_limit"
                class="form-input"
                placeholder="Dejar vacío para ilimitado"
                min="0"
              />
            </div>
          </div>

          <!-- ── Módulos de la app ─────────────────────────────────────── -->
          <div class="features-section">
            <div class="features-header">
              <span class="material-symbols-outlined" aria-hidden="true">phone_android</span>
              <div>
                <h3 class="features-title">Módulos de la app</h3>
                <p class="features-subtitle">Activa o desactiva cada sección para los usuarios de este plan.</p>
              </div>
            </div>
            <div class="features-grid">
              <label class="feature-toggle" *ngFor="let feat of featureList">
                <div class="feature-info">
                  <span class="material-symbols-outlined feature-icon" aria-hidden="true">{{ feat.icon }}</span>
                  <span class="feature-label">{{ feat.label }}</span>
                </div>
                <div
                  class="toggle-track"
                  [class.on]="planForm.get(feat.key)?.value"
                  (click)="toggleFeature(feat.key)"
                  role="switch"
                  [attr.aria-checked]="planForm.get(feat.key)?.value"
                  tabindex="0"
                  (keydown.space)="toggleFeature(feat.key)"
                >
                  <div class="toggle-thumb"></div>
                </div>
              </label>
            </div>
          </div>

          <!-- ── Capacidades de IRON IA ────────────────────────────────── -->
          <div class="features-section ai-section">
            <div class="features-header">
              <span class="material-symbols-outlined" aria-hidden="true">psychology</span>
              <div>
                <h3 class="features-title">Capacidades de IRON IA</h3>
                <p class="features-subtitle">Controla qué funciones de IRON IA incluye este plan.</p>
              </div>
            </div>

            <div *ngIf="aiLoading()" class="ai-loading">Cargando capacidades…</div>

            <div class="ai-group" *ngFor="let group of aiToggleGroups">
              <div class="ai-group-title">{{ group.title }}</div>
              <p class="ai-group-help">{{ group.help }}</p>
              <div class="features-grid">
                <label class="feature-toggle" *ngFor="let t of group.toggles">
                  <div class="feature-info">
                    <span class="material-symbols-outlined feature-icon" aria-hidden="true">{{ t.icon }}</span>
                    <span class="feature-label">{{ t.label }}</span>
                  </div>
                  <div
                    class="toggle-track"
                    [class.on]="planForm.get('ai.' + t.key)?.value"
                    (click)="toggleFeature('ai.' + t.key)"
                    role="switch"
                    [attr.aria-checked]="planForm.get('ai.' + t.key)?.value"
                    tabindex="0"
                    (keydown.space)="toggleFeature('ai.' + t.key)"
                  >
                    <div class="toggle-thumb"></div>
                  </div>
                </label>
              </div>
            </div>

            <div class="ai-group" formGroupName="ai">
              <div class="ai-group-title">Límites</div>
              <p class="ai-group-help">Cuotas de uso. Deja vacío para ilimitado donde aplique.</p>
              <div class="ai-limits-grid">
                <div class="form-group" *ngFor="let lim of aiLimits">
                  <label class="form-label">{{ lim.label }}</label>
                  <input
                    type="number"
                    min="0"
                    class="form-input"
                    [formControlName]="lim.key"
                    [placeholder]="lim.placeholder"
                  />
                </div>
                <div class="form-group">
                  <label class="form-label">Nivel de contexto</label>
                  <select class="form-input" formControlName="ai_context_level">
                    <option value="basic">Básico</option>
                    <option value="personalized">Personalizado</option>
                    <option value="full">Completo</option>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn-secondary" (click)="close()" [disabled]="isSaving()">
              Cancelar
            </button>
            <button
              type="submit"
              class="btn-primary"
              [disabled]="!planForm.valid || isSaving()"
            >
              <span *ngIf="!isSaving()">
                <span class="material-symbols-outlined" style="font-size:1rem;vertical-align:middle">save</span>
                Guardar cambios
              </span>
              <span *ngIf="isSaving()">Guardando...</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  `,
  styles: [
    `
      .modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(2px);
        z-index: 40;
        animation: fadeIn 200ms ease;
      }

      @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
      }

      .modal-container {
        position: fixed;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 50;
        padding: 1rem;
        animation: slideUp 300ms cubic-bezier(0.4, 0, 0.2, 1);
      }

      @keyframes slideUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
      }

      .modal-card {
        background: #fff;
        border: 1px solid #e5e5e5;
        border-radius: 14px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        width: 100%;
        max-width: 580px;
        max-height: 90vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
      }

      .modal-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1.5rem;
        padding: 2rem;
        border-bottom: 1px solid #f0f0f0;
      }

      .header-content {
        display: flex;
        gap: 1rem;
        flex: 1;
      }

      .header-icon {
        display: grid;
        place-items: center;
        width: 48px;
        height: 48px;
        border-radius: 10px;
        background: #0a0a0a;
        color: #facc15;
        font-size: 1.5rem;
        flex-shrink: 0;
      }

      .header-text { flex: 1; }

      .modal-title {
        font-family: Inter, sans-serif;
        font-size: 1.4rem;
        font-weight: 700;
        color: #0a0a0a;
        margin: 0 0 0.4rem;
        letter-spacing: -0.01em;
      }

      .modal-subtitle {
        font-size: 0.9rem;
        color: #666;
        margin: 0;
        line-height: 1.5;
      }

      .btn-close {
        display: grid;
        place-items: center;
        width: 36px;
        height: 36px;
        border: none;
        border-radius: 8px;
        background: #f5f5f5;
        color: #666;
        cursor: pointer;
        transition: all 200ms ease;
        flex-shrink: 0;
      }

      .btn-close:hover {
        background: #e8e8e8;
        color: #0a0a0a;
      }

      .error-message {
        display: flex;
        gap: 1rem;
        padding: 1.25rem 2rem;
        background: #fee2e2;
        border: 1px solid #fecaca;
        margin: 1.5rem 2rem 0;
        border-radius: 10px;
        color: #991b1b;
      }

      .error-message span.material-symbols-outlined {
        font-size: 1.5rem;
        flex-shrink: 0;
      }

      .error-message strong {
        display: block;
        margin-bottom: 0.25rem;
      }

      .error-message p {
        margin: 0;
        font-size: 0.9rem;
      }

      .modal-form {
        flex: 1;
        overflow-y: auto;
        padding: 2rem;
      }

      .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.5rem;
        margin-bottom: 2rem;
      }

      .form-group.full-width {
        grid-column: 1 / -1;
      }

      .form-label {
        display: block;
        font-size: 0.9rem;
        font-weight: 600;
        color: #0a0a0a;
        margin-bottom: 0.5rem;
        font-family: Inter, sans-serif;
      }

      .form-input,
      .form-select {
        width: 100%;
        padding: 0.875rem;
        border: 1px solid #e5e5e5;
        border-radius: 8px;
        font-family: Inter, sans-serif;
        font-size: 0.95rem;
        color: #0a0a0a;
        background: #fff;
        transition: all 200ms ease;
        box-sizing: border-box;
      }

      .form-input::placeholder { color: #999; }

      .form-input:focus,
      .form-select:focus {
        outline: none;
        border-color: #facc15;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.1);
      }

      .form-input.textarea {
        resize: vertical;
        min-height: 80px;
      }

      .error-text {
        display: block;
        margin-top: 0.4rem;
        font-size: 0.8rem;
        color: #dc2626;
        font-weight: 500;
      }

      .modal-footer {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 1rem;
        padding: 1.5rem 2rem;
        border-top: 1px solid #f0f0f0;
        background: #f9f9f9;
      }

      .btn-primary,
      .btn-secondary {
        padding: 0.875rem 1.75rem;
        border-radius: 8px;
        font-family: Inter, sans-serif;
        font-weight: 600;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 200ms ease;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
      }

      .btn-primary {
        background: #facc15;
        color: #000;
        box-shadow: 0 2px 8px rgba(250, 204, 21, 0.2);
      }

      .btn-primary:hover:not(:disabled) {
        background: #f0c00e;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(250, 204, 21, 0.3);
      }

      .btn-primary:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }

      .btn-secondary {
        background: #fff;
        color: #0a0a0a;
        border: 1.5px solid #d0d0d0;
      }

      .btn-secondary:hover:not(:disabled) {
        border-color: #a0a0a0;
        background: #f5f5f5;
      }

      .btn-secondary:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }

      /* Dark CRM skin */
      .modal-backdrop {
        background: rgba(0, 0, 0, 0.72);
        backdrop-filter: blur(6px);
        z-index: 10000;
      }

      .modal-container {
        z-index: 10001;
      }

      .modal-card {
        background:
          radial-gradient(circle at 82% 0%, rgba(245, 197, 24, 0.1), transparent 34%),
          linear-gradient(145deg, #1c1b1b 0%, #111111 100%);
        border: 1px solid rgba(245, 197, 24, 0.16);
        box-shadow:
          inset 0 -18px 24px rgba(255, 255, 255, 0.04),
          0 28px 70px rgba(0, 0, 0, 0.58);
        color: #e5e2e1;
      }

      .modal-header {
        border-bottom-color: #353534;
        background: rgba(14, 14, 14, 0.52);
      }

      .header-icon {
        background: #f5c518;
        color: #241a00;
        box-shadow: 0 0 18px rgba(245, 197, 24, 0.18);
      }

      .modal-title,
      .form-label {
        color: #e5e2e1;
      }

      .modal-subtitle {
        color: #b4afa6;
      }

      .btn-close {
        background: #2a2a2a;
        border: 1px solid #353534;
        color: #d1c5ac;
      }

      .btn-close:hover {
        background: rgba(245, 197, 24, 0.12);
        border-color: rgba(245, 197, 24, 0.35);
        color: #ffe08b;
      }

      .modal-form {
        background: transparent;
      }

      .form-input,
      .form-select {
        background: #151515;
        border-color: #353534;
        color: #e5e2e1;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
      }

      .form-input::placeholder {
        color: #77716a;
      }

      .form-input:focus,
      .form-select:focus {
        border-color: #f5c518;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.14);
      }

      .form-select {
        color-scheme: dark;
        cursor: pointer;
      }

      .form-select option {
        background: #151515;
        color: #e5e2e1;
      }

      .modal-footer {
        border-top-color: #353534;
        background: #151515;
      }

      .btn-secondary {
        background: #201f1f;
        border-color: #353534;
        color: #d1c5ac;
      }

      .btn-secondary:hover:not(:disabled) {
        background: #2a2a2a;
        border-color: rgba(245, 197, 24, 0.35);
        color: #ffe08b;
      }

      .btn-primary {
        background: #f5c518;
        color: #241a00;
        box-shadow: 0 0 18px rgba(245, 197, 24, 0.16);
      }

      .btn-primary:hover:not(:disabled) {
        background: #ffd43b;
      }

      .error-message {
        background: rgba(147, 0, 10, 0.28);
        border-color: rgba(255, 180, 171, 0.3);
        color: #ffdad6;
      }

      .error-text {
        color: #ffb4ab;
      }

      /* ── Feature toggles ──────────────────────────────────────────── */
      .features-section {
        border: 1px solid #353534;
        border-radius: 10px;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
        background: rgba(255,255,255,0.02);
      }

      .features-header {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        margin-bottom: 1.25rem;
      }

      .features-header .material-symbols-outlined {
        font-size: 1.4rem;
        color: #f5c518;
        margin-top: 2px;
      }

      .features-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #e5e2e1;
        margin: 0 0 0.2rem;
        font-family: Inter, sans-serif;
      }

      .features-subtitle {
        font-size: 0.8rem;
        color: #8a847d;
        margin: 0;
      }

      .features-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
      }

      .feature-toggle {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.75rem 1rem;
        border: 1px solid #2a2a2a;
        border-radius: 8px;
        background: #151515;
        cursor: pointer;
        transition: border-color 200ms;
      }

      .feature-toggle:hover { border-color: rgba(245,197,24,0.3); }

      .feature-info {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        min-width: 0;
      }

      .feature-icon {
        font-size: 1.1rem;
        color: #77716a;
        flex-shrink: 0;
      }

      .feature-label {
        font-size: 0.85rem;
        font-weight: 600;
        color: #c8c0b4;
        white-space: nowrap;
        font-family: Inter, sans-serif;
      }

      .toggle-track {
        width: 40px;
        height: 22px;
        border-radius: 11px;
        background: #333;
        position: relative;
        cursor: pointer;
        transition: background 200ms;
        flex-shrink: 0;
        outline: none;
      }

      .toggle-track.on { background: #f5c518; }

      .toggle-track:focus-visible { box-shadow: 0 0 0 3px rgba(245,197,24,0.3); }

      .toggle-thumb {
        position: absolute;
        top: 3px;
        left: 3px;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: #fff;
        transition: transform 200ms;
        box-shadow: 0 1px 3px rgba(0,0,0,0.4);
      }

      .toggle-track.on .toggle-thumb { transform: translateX(18px); }

      /* ── Capacidades de IRON IA ───────────────────────────────────── */
      .ai-section { border-color: rgba(245,197,24,0.25); }
      .ai-loading {
        font-size: 0.85rem;
        color: rgba(255,255,255,0.55);
        padding: 0.25rem 0 0.75rem;
      }
      .ai-group { margin-top: 1.1rem; }
      .ai-group:first-of-type { margin-top: 0.5rem; }
      .ai-group-title {
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #f5c518;
      }
      .ai-group-help {
        margin: 0.15rem 0 0.7rem;
        font-size: 0.78rem;
        color: rgba(255,255,255,0.5);
      }
      .ai-limits-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.9rem 1rem;
      }
      .ai-limits-grid .form-group { margin: 0; }

      @media (max-width: 640px) {
        .ai-limits-grid { grid-template-columns: 1fr; }
        .modal-container { padding: 0.5rem; }
        .modal-card { border-radius: 10px; }
        .modal-header { padding: 1.5rem; }
        .modal-form { padding: 1.5rem; }
        .form-grid { grid-template-columns: 1fr; gap: 1.25rem; }
        .features-grid { grid-template-columns: 1fr; }
        .modal-footer {
          flex-direction: column;
          padding: 1.25rem 1.5rem;
          gap: 0.75rem;
        }
        .btn-primary,
        .btn-secondary { width: 100%; justify-content: center; }
      }
    `,
  ],
})
export class EditPlanModalComponent implements OnChanges {
  @Input() isOpen!: Signal<boolean>;
  @Input() plan: PlanCardData | null = null;
  @Output() onClose = new EventEmitter<void>();
  @Output() onPlanUpdated = new EventEmitter<PlanSummary>();

  planForm!: FormGroup;
  isSaving = signal(false);
  aiLoading = signal(false);
  errorMessage = signal('');

  readonly featureList = [
    { key: 'feat_iron_ia',          label: 'Iron IA',      icon: 'psychology' },
    { key: 'feat_workouts',         label: 'Entrenar',     icon: 'fitness_center' },
    { key: 'feat_custom_routines',  label: 'Mis rutinas',  icon: 'edit_note' },
    { key: 'feat_ranking',          label: 'Ranking',      icon: 'leaderboard' },
    { key: 'feat_classes',          label: 'Clases',       icon: 'calendar_month' },
    { key: 'feat_progress',         label: 'Progreso',     icon: 'trending_up' },
    { key: 'feat_nutrition',        label: 'Nutrición',    icon: 'restaurant' },
  ];

  // Capacidades de IRON IA agrupadas para el modal (toggles).
  readonly aiToggleGroups = [
    {
      title: 'Acceso IA',
      help: 'Disponibilidad general del asistente para este plan.',
      toggles: [
        { key: 'ai_enabled',      label: 'IRON IA activo', icon: 'smart_toy' },
        { key: 'ai_chat_enabled', label: 'Chat de texto',  icon: 'chat' },
      ],
    },
    {
      title: 'Multimodal',
      help: 'Voz, imagen y conversación en vivo.',
      toggles: [
        { key: 'ai_image_analysis_enabled', label: 'Análisis con imagen', icon: 'image' },
        { key: 'ai_voice_chat_enabled',     label: 'Chat por voz',        icon: 'mic' },
        { key: 'ai_realtime_voice_enabled', label: 'Conversación en vivo', icon: 'graphic_eq' },
      ],
    },
    {
      title: 'Automatización y seguimiento',
      help: 'Funciones inteligentes y proactivas.',
      toggles: [
        { key: 'ai_progress_analysis_enabled',       label: 'Análisis de progreso',        icon: 'insights' },
        { key: 'ai_smart_recommendations_enabled',   label: 'Recomendaciones inteligentes', icon: 'tips_and_updates' },
        { key: 'ai_weekly_summary_enabled',          label: 'Resumen semanal',             icon: 'calendar_view_week' },
        { key: 'ai_proactive_notifications_enabled', label: 'Notificaciones proactivas',   icon: 'notifications_active' },
      ],
    },
  ];

  // Límites numéricos de IRON IA.
  readonly aiLimits = [
    { key: 'ai_monthly_messages_limit', label: 'Consultas IA / mes', placeholder: 'Vacío = ilimitado' },
    { key: 'ai_daily_messages_limit',   label: 'Consultas IA / día', placeholder: 'Vacío = ilimitado' },
    { key: 'ai_monthly_image_limit',    label: 'Imágenes / mes',     placeholder: '0' },
    { key: 'ai_monthly_audio_limit',    label: 'Audios / mes',       placeholder: '0' },
    { key: 'ai_max_audio_seconds',      label: 'Duración máx. audio (seg)', placeholder: '60' },
  ];

  private readonly defaultBenefits = [
    'Acceso al gimnasio durante la vigencia del plan',
    'Reserva de clases grupales disponibles',
    'Acceso a rutinas asignadas en la app móvil',
  ].join(', ');

  constructor(
    private fb: FormBuilder,
    private api: ApiService,
  ) {
    this.planForm = this.fb.group({
      name: ['', Validators.required],
      benefits: [this.defaultBenefits, Validators.required],
      price: ['', [Validators.required, Validators.min(1)]],
      duration_days: ['', [Validators.required, Validators.min(1)]],
      active: [true, Validators.required],
      access_classes: [true],
      reservations_limit: [null],
      feat_iron_ia:         [false],
      feat_workouts:        [true],
      feat_custom_routines: [false],
      feat_ranking:         [false],
      feat_classes:         [false],
      feat_progress:        [true],
      feat_nutrition:       [false],
      // Capacidades detalladas de IRON IA (membership_ai_capabilities).
      ai: this.fb.group({
        ai_enabled:                         [true],
        ai_chat_enabled:                    [true],
        ai_image_analysis_enabled:          [false],
        ai_voice_chat_enabled:              [false],
        ai_realtime_voice_enabled:          [false],
        ai_progress_analysis_enabled:       [false],
        ai_smart_recommendations_enabled:   [false],
        ai_weekly_summary_enabled:          [false],
        ai_proactive_notifications_enabled: [false],
        ai_monthly_messages_limit:          [null],
        ai_daily_messages_limit:            [null],
        ai_monthly_image_limit:             [0],
        ai_monthly_audio_limit:             [0],
        ai_max_audio_seconds:               [60],
        ai_context_level:                   ['basic'],
      }),
    });
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['plan'] && this.plan) {
      this.errorMessage.set('');
      const f = (this.plan as any).features as PlanFeatures | null | undefined;
      this.planForm.patchValue({
        name: this.plan.name || '',
        benefits: this.plan.benefits || this.defaultBenefits,
        price: this.plan.price || '',
        duration_days: this.plan.duration_days || '',
        active: this.plan.active ?? true,
        access_classes: true,
        reservations_limit: null,
        feat_iron_ia:         f?.iron_ia         ?? false,
        feat_workouts:        f?.workouts        ?? true,
        feat_custom_routines: f?.custom_routines ?? false,
        feat_ranking:         f?.ranking         ?? false,
        feat_classes:         f?.classes         ?? false,
        feat_progress:        f?.progress        ?? true,
        feat_nutrition:       f?.nutrition       ?? false,
      });
      this.loadAiCapabilities(this.plan.id);
    }
  }

  /** Carga las capacidades de IRON IA del plan desde el backend. */
  private loadAiCapabilities(planId: number | undefined): void {
    if (!planId) return;
    this.aiLoading.set(true);
    this.api.getPlanAiCapabilities(planId).subscribe({
      next: (res) => {
        this.planForm.get('ai')?.patchValue(res.capabilities);
        this.aiLoading.set(false);
      },
      error: () => this.aiLoading.set(false),
    });
  }

  toggleFeature(key: string): void {
    const ctrl = this.planForm.get(key);
    if (ctrl) ctrl.setValue(!ctrl.value);
  }

  onSubmit(): void {
    const benefitsControl = this.planForm.get('benefits');
    if (!String(benefitsControl?.value || '').trim()) {
      benefitsControl?.setErrors({ required: true });
    }

    if (!this.planForm.valid) {
      Object.keys(this.planForm.controls).forEach((key) =>
        this.planForm.get(key)?.markAsTouched(),
      );
      return;
    }

    if (!this.plan?.id) return;

    this.isSaving.set(true);
    this.errorMessage.set('');

    const val = this.planForm.value;
    const planData = {
      name: val.name,
      benefits: String(val.benefits || '').trim(),
      price: Number(val.price),
      duration_days: Number(val.duration_days),
      active: val.active,
      access_classes: val.access_classes,
      reservations_limit: val.reservations_limit ? Number(val.reservations_limit) : null,
    };
    const features: PlanFeatures = {
      iron_ia:         val.feat_iron_ia,
      workouts:        val.feat_workouts,
      custom_routines: val.feat_custom_routines,
      ranking:         val.feat_ranking,
      classes:         val.feat_classes,
      progress:        val.feat_progress,
      nutrition:       val.feat_nutrition,
    };

    const planId = this.plan.id;
    this.api.updatePlan(planId, { ...planData, features } as any).subscribe({
      next: (updated) => {
        // Tras guardar el plan, persiste las capacidades de IRON IA.
        this.api.updatePlanAiCapabilities(planId, this.buildAiPayload()).subscribe({
          next: () => {
            this.isSaving.set(false);
            this.onPlanUpdated.emit(updated);
            this.close();
          },
          error: (err) => {
            this.isSaving.set(false);
            // El plan ya se guardó; informamos el fallo solo de las capacidades.
            this.onPlanUpdated.emit(updated);
            const message = err?.error?.message
              || 'El plan se guardó, pero no se pudieron guardar las capacidades de IRON IA.';
            this.errorMessage.set(message);
          },
        });
      },
      error: (err) => {
        this.isSaving.set(false);
        const message = err?.error?.message || 'No se pudo actualizar el plan. Intenta de nuevo.';
        this.errorMessage.set(message);
      },
    });
  }

  /** Construye el payload de capacidades IA (coerciona límites numéricos). */
  private buildAiPayload(): Partial<PlanAiCapabilities> {
    const a = this.planForm.get('ai')?.value ?? {};
    const num = (v: unknown, def: number | null): number | null =>
      v === '' || v === null || v === undefined ? def : Number(v);

    return {
      ai_enabled: !!a.ai_enabled,
      ai_chat_enabled: !!a.ai_chat_enabled,
      ai_image_analysis_enabled: !!a.ai_image_analysis_enabled,
      ai_voice_chat_enabled: !!a.ai_voice_chat_enabled,
      ai_realtime_voice_enabled: !!a.ai_realtime_voice_enabled,
      ai_progress_analysis_enabled: !!a.ai_progress_analysis_enabled,
      ai_smart_recommendations_enabled: !!a.ai_smart_recommendations_enabled,
      ai_weekly_summary_enabled: !!a.ai_weekly_summary_enabled,
      ai_proactive_notifications_enabled: !!a.ai_proactive_notifications_enabled,
      ai_monthly_messages_limit: num(a.ai_monthly_messages_limit, null),
      ai_daily_messages_limit: num(a.ai_daily_messages_limit, null),
      ai_monthly_image_limit: num(a.ai_monthly_image_limit, 0) ?? 0,
      ai_monthly_audio_limit: num(a.ai_monthly_audio_limit, 0) ?? 0,
      ai_max_audio_seconds: num(a.ai_max_audio_seconds, 60) ?? 60,
      ai_context_level: a.ai_context_level ?? 'basic',
    };
  }

  close(): void {
    if (!this.isSaving()) {
      this.errorMessage.set('');
      this.onClose.emit();
    }
  }
}
