import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, Output, OnInit } from '@angular/core';
import {
  FormBuilder,
  FormGroup,
  FormsModule,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import { Campaign } from './campaign-card';
import { DateWheelPickerComponent } from '../../shared/components/date-wheel-picker/date-wheel-picker.component';

export type CampaignModalMode = 'create' | 'edit' | 'detail';

@Component({
  selector: 'app-marketing-modal-campaign',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule, DateWheelPickerComponent],
  template: `
    <div *ngIf="isOpen" class="modal-overlay" (click)="onClose()" role="dialog" aria-modal="true">
      <div class="modal-drawer" (click)="$event.stopPropagation()">
        <div class="modal-header">
          <div class="modal-title-section">
            <span class="material-symbols-outlined modal-icon">campaign</span>
            <div>
              <h2 class="modal-title">
                {{
                  mode === 'create'
                    ? 'Crear nueva campaña'
                    : mode === 'edit'
                      ? 'Editar campaña'
                      : 'Detalle de campaña'
                }}
              </h2>
              <p class="modal-subtitle">
                Define objetivo, segmento, canal, mensaje y fechas de la campaña.
              </p>
            </div>
          </div>
          <button type="button" class="modal-close" (click)="onClose()" aria-label="Cerrar">
            <span class="material-symbols-outlined">close</span>
          </button>
        </div>

        <div class="modal-content">
          <form [formGroup]="campaignForm" [class.readonly]="mode === 'detail'">
            <fieldset class="form-group">
              <legend>Información básica</legend>

              <div class="form-field">
                <label for="name">Nombre de la campaña</label>
                <input
                  type="text"
                  id="name"
                  formControlName="name"
                  placeholder="Ej: Renovación mensual abril"
                  class="form-input"
                  [readonly]="mode === 'detail'"
                />
              </div>

              <div class="form-row">
                <div class="form-field">
                  <label for="type">Tipo de campaña</label>
                  <select
                    id="type"
                    formControlName="type"
                    class="form-select"
                    [disabled]="mode === 'detail'"
                  >
                    <option *ngFor="let t of campaignTypes" [value]="t">{{ t }}</option>
                  </select>
                </div>

                <div class="form-field">
                  <label for="objective">Objetivo</label>
                  <select
                    id="objective"
                    formControlName="objective"
                    class="form-select"
                    [disabled]="mode === 'detail'"
                  >
                    <option *ngFor="let o of objectives" [value]="o">{{ o }}</option>
                  </select>
                </div>
              </div>
            </fieldset>

            <fieldset class="form-group">
              <legend>Segmentación y canales</legend>

              <div class="form-row">
                <div class="form-field">
                  <label for="segment">Segmento</label>
                  <select
                    id="segment"
                    formControlName="segment"
                    class="form-select"
                    [disabled]="mode === 'detail'"
                  >
                    <option *ngFor="let s of segments" [value]="s">{{ s }}</option>
                  </select>
                </div>

                <div class="form-field">
                  <label for="channel">Canal</label>
                  <select
                    id="channel"
                    formControlName="channel"
                    class="form-select"
                    [disabled]="mode === 'detail'"
                  >
                    <option *ngFor="let c of channels" [value]="c">{{ c }}</option>
                  </select>
                </div>
              </div>
            </fieldset>

            <fieldset class="form-group">
              <legend>Fechas</legend>

              <div class="form-row">
                <div class="form-field">
                  <label for="startDate">Fecha de inicio</label>
                  <app-date-wheel-picker
                    formControlName="startDate"
                    [minYear]="currentYear - 1"
                    [maxYear]="currentYear + 3"
                    size="sm"
                    ariaLabel="Fecha de inicio de campana"
                    [disabled]="mode === 'detail'"
                  ></app-date-wheel-picker>
                </div>

                <div class="form-field">
                  <label for="endDate">Fecha de finalización</label>
                  <app-date-wheel-picker
                    formControlName="endDate"
                    [minYear]="currentYear - 1"
                    [maxYear]="currentYear + 3"
                    size="sm"
                    ariaLabel="Fecha de finalizacion de campana"
                    [disabled]="mode === 'detail'"
                  ></app-date-wheel-picker>
                </div>
              </div>
            </fieldset>

            <fieldset class="form-group">
              <legend>Contenido</legend>

              <div class="form-field">
                <label for="message">Mensaje de campaña</label>
                <textarea
                  id="message"
                  formControlName="message"
                  placeholder="Escribe el mensaje que se enviará a los miembros…"
                  class="form-textarea"
                  rows="4"
                  [readonly]="mode === 'detail'"
                ></textarea>
              </div>

              <div class="form-row">
                <div class="form-field">
                  <label for="couponCode">Cupón asociado</label>
                  <input
                    type="text"
                    id="couponCode"
                    formControlName="couponCode"
                    placeholder="Ej: RENUEVA10 (opcional)"
                    class="form-input"
                    [readonly]="mode === 'detail'"
                  />
                </div>

                <div class="form-field">
                  <label for="status">Estado</label>
                  <select
                    id="status"
                    formControlName="status"
                    class="form-select"
                    [disabled]="mode === 'detail'"
                  >
                    <option value="Borrador">Borrador</option>
                    <option value="Programada">Programada</option>
                    <option value="Activa">Activa</option>
                    <option value="Pausada">Pausada</option>
                    <option value="Finalizada">Finalizada</option>
                  </select>
                </div>
              </div>
            </fieldset>

            <fieldset class="form-group">
              <legend>Métricas</legend>

              <div class="form-row">
                <div class="form-field">
                  <label for="budget">Presupuesto estimado</label>
                  <input
                    type="number"
                    id="budget"
                    formControlName="budget"
                    placeholder="Ej: 50000"
                    class="form-input"
                    min="0"
                    [readonly]="mode === 'detail'"
                  />
                </div>

                <div class="form-field">
                  <label for="conversionGoal">Meta de conversión</label>
                  <input
                    type="number"
                    id="conversionGoal"
                    formControlName="conversionGoal"
                    placeholder="Ej: 20 renovaciones"
                    class="form-input"
                    min="0"
                    [readonly]="mode === 'detail'"
                  />
                </div>
              </div>
            </fieldset>
          </form>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn-secondary" (click)="onClose()" [disabled]="isSaving">
            {{ mode === 'detail' ? 'Cerrar' : 'Cancelar' }}
          </button>
          <button
            *ngIf="mode !== 'detail'"
            type="button"
            class="btn-primary"
            (click)="onSubmit()"
            [disabled]="!campaignForm.valid || isSaving"
          >
            <span *ngIf="!isSaving">{{
              mode === 'create' ? 'Crear campaña' : 'Guardar campaña'
            }}</span>
            <span *ngIf="isSaving" class="loading">{{
              mode === 'create' ? 'Creando...' : 'Guardando...'
            }}</span>
          </button>
        </div>
      </div>
    </div>
  `,
  styles: [
    `
      .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.4);
        z-index: 50;
        display: flex;
        justify-content: flex-end;
      }

      .modal-drawer {
        width: 100%;
        max-width: 550px;
        height: 100vh;
        background: #ffffff;
        box-shadow: -4px 0 16px rgba(0, 0, 0, 0.12);
        display: flex;
        flex-direction: column;
        animation: slideIn 0.25s ease;
      }

      @keyframes slideIn {
        from {
          transform: translateX(100%);
          opacity: 0;
        }
        to {
          transform: translateX(0);
          opacity: 1;
        }
      }

      .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        padding: 1.6rem;
        border-bottom: 1px solid #ededed;
      }

      .modal-title-section {
        display: flex;
        gap: 1rem;
      }

      .modal-icon {
        font-size: 1.8rem;
        color: #fbbf24;
        flex-shrink: 0;
      }

      .modal-title {
        font-size: 1.25rem;
        font-weight: 900;
        color: #0a0a0a;
        margin: 0;
      }

      .modal-subtitle {
        font-size: 0.85rem;
        color: #666;
        margin: 0.4rem 0 0;
      }

      .modal-close {
        width: 36px;
        height: 36px;
        border: none;
        border-radius: 10px;
        background: #f3f3f3;
        color: #666;
        cursor: pointer;
        transition: all 0.15s ease;
        flex-shrink: 0;
      }

      .modal-close:hover {
        background: #e5e5e5;
        color: #0a0a0a;
      }

      .modal-content {
        flex: 1;
        overflow-y: auto;
        padding: 1.6rem;
      }

      .form-group {
        margin-bottom: 1.8rem;
        border: none;
        padding: 0;
      }

      .form-group legend {
        font-size: 0.8rem;
        font-weight: 800;
        color: #0a0a0a;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin: 0 0 0.9rem;
        padding: 0;
      }

      .form-field {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
        margin-bottom: 1rem;
      }

      .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-bottom: 1rem;
      }

      label {
        font-size: 0.85rem;
        font-weight: 700;
        color: #0a0a0a;
      }

      .form-input,
      .form-select,
      .form-textarea {
        padding: 0.75rem;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        font-size: 0.9rem;
        color: #0a0a0a;
        font-weight: 500;
        font-family: inherit;
        transition: all 0.15s ease;
      }

      .form-textarea {
        resize: vertical;
        min-height: 80px;
      }

      .form-input::placeholder,
      .form-textarea::placeholder {
        color: #bbb;
      }

      .form-input:focus,
      .form-select:focus,
      .form-textarea:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.1);
      }

      .form-input[readonly],
      .form-textarea[readonly] {
        background: #f9f9f9;
        cursor: default;
      }

      .form-select:disabled {
        background: #f9f9f9;
        cursor: default;
      }

      .modal-footer {
        display: flex;
        gap: 0.8rem;
        padding: 1.4rem 1.6rem;
        border-top: 1px solid #ededed;
        background: #fafafa;
      }

      .btn-secondary,
      .btn-primary {
        flex: 1;
        padding: 0.8rem;
        border-radius: 10px;
        font-size: 0.9rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.15s ease;
        border: none;
      }

      .btn-secondary {
        background: #ffffff;
        color: #0a0a0a;
        border: 1px solid #e5e5e5;
      }

      .btn-secondary:hover:not(:disabled) {
        background: #f9f9f9;
        border-color: #d0d0d0;
      }

      .btn-primary {
        background: #fbbf24;
        color: #0a0a0a;
        box-shadow: 0 6px 12px rgba(251, 191, 36, 0.15);
      }

      .btn-primary:hover:not(:disabled) {
        background: #f9a825;
        box-shadow: 0 8px 16px rgba(251, 191, 36, 0.2);
      }

      .btn-primary:disabled,
      .btn-secondary:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }

      .loading {
        animation: pulse 0.8s infinite;
      }

      .modal-overlay {
        background: rgba(0, 0, 0, 0.68);
      }

      .modal-drawer {
        background:
          linear-gradient(rgba(28, 27, 27, 0.95), rgba(17, 17, 17, 0.94)),
          url('/assets/crm/clases2.png') center / cover no-repeat;
        border-left: 1px solid #353534;
        color: #e5e2e1;
        box-shadow: -16px 0 48px rgba(0, 0, 0, 0.58);
      }

      .modal-header,
      .modal-footer {
        background:
          linear-gradient(135deg, rgba(245, 197, 24, 0.14), rgba(28, 27, 27, 0.94)),
          #1c1b1b;
        border-color: #353534;
      }

      .modal-title,
      .form-group legend,
      label {
        color: #e5e2e1;
      }

      .modal-subtitle {
        color: #b4afa6;
      }

      .modal-close,
      .btn-secondary {
        background: #1c1b1b;
        border: 1px solid #353534;
        color: #e5e2e1;
      }

      .modal-close:hover,
      .btn-secondary:hover:not(:disabled) {
        background: #201f1f;
        border-color: #f5c518;
        color: #ffe08b;
      }

      .form-input,
      .form-select,
      .form-textarea {
        background: #151515;
        border-color: #353534;
        color: #e5e2e1;
        color-scheme: dark;
      }

      .form-select option {
        background: #151515;
        color: #e5e2e1;
      }

      .form-input::placeholder,
      .form-textarea::placeholder {
        color: #77716a;
      }

      .form-input[readonly],
      .form-textarea[readonly],
      .form-select:disabled {
        background: #181716;
        color: #cfcac2;
        opacity: 1;
      }

      .form-input:focus,
      .form-select:focus,
      .form-textarea:focus {
        background: #201f1f;
        border-color: #f5c518;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.13);
      }

      @keyframes pulse {
        0%,
        100% {
          opacity: 1;
        }
        50% {
          opacity: 0.6;
        }
      }

      @media (max-width: 640px) {
        .modal-drawer {
          max-width: 100%;
        }
      }
    `,
  ],
})
export default class MarketingModalCampaignComponent implements OnInit {
  @Input() isOpen = false;
  @Input() mode: CampaignModalMode = 'create';
  @Input() campaign: Campaign | null = null;
  @Input() isSaving = false;

  @Output() close = new EventEmitter<void>();
  @Output() save = new EventEmitter<Partial<Campaign>>();

  campaignForm!: FormGroup;
  currentYear = new Date().getFullYear();

  campaignTypes = [
    'Promoción',
    'Descuento',
    'Renovación',
    'Reactivación',
    'Cumpleaños',
    'Referidos',
    'Clase especial',
    'Evento',
    'Comunicación general',
  ];

  objectives = [
    'Aumentar renovaciones',
    'Recuperar miembros inactivos',
    'Vender planes',
    'Promocionar clase',
    'Comunicar novedad',
    'Generar referidos',
  ];

  segments = [
    'Todos los miembros',
    'Miembros activos',
    'Miembros inactivos',
    'Membresías por vencer',
    'Membresías vencidas',
    'Nuevos miembros',
    'Miembros VIP',
    'Leads',
  ];

  channels = ['WhatsApp', 'Correo electrónico', 'SMS', 'Notificación interna', 'Redes sociales'];

  constructor(private fb: FormBuilder) {}

  ngOnInit(): void {
    this.buildForm();
  }

  ngOnChanges(): void {
    this.buildForm();
  }

  buildForm(): void {
    const now = new Date().toISOString().split('T')[0];
    this.campaignForm = this.fb.group({
      name: [this.campaign?.name || '', this.mode === 'detail' ? [] : Validators.required],
      type: [this.campaign?.type || '', this.mode === 'detail' ? [] : Validators.required],
      objective: [
        this.campaign?.objective || '',
        this.mode === 'detail' ? [] : Validators.required,
      ],
      segment: [this.campaign?.segment || '', this.mode === 'detail' ? [] : Validators.required],
      channel: [this.campaign?.channel || '', this.mode === 'detail' ? [] : Validators.required],
      startDate: [
        this.campaign?.startDate || now,
        this.mode === 'detail' ? [] : Validators.required,
      ],
      endDate: [this.campaign?.endDate || now, this.mode === 'detail' ? [] : Validators.required],
      message: [this.campaign?.message || '', this.mode === 'detail' ? [] : Validators.required],
      couponCode: [this.campaign?.couponCode || ''],
      status: [
        this.campaign?.status || 'Borrador',
        this.mode === 'detail' ? [] : Validators.required,
      ],
      budget: [this.campaign?.budget || 0, Validators.min(0)],
      conversionGoal: [this.campaign?.conversionGoal || 0, Validators.min(0)],
    });
  }

  onClose(): void {
    this.close.emit();
  }

  onSubmit(): void {
    if (!this.campaignForm.valid || this.mode === 'detail') return;
    this.save.emit(this.campaignForm.value);
  }
}
