import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, Output, OnInit } from '@angular/core';
import {
  FormBuilder,
  FormGroup,
  FormsModule,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';

export interface Communication {
  id: string;
  segment: string;
  channel: string;
  message: string;
  recipientsCount: number;
  status: 'Borrador' | 'Enviada' | 'Fallida';
  sentAt?: string;
  createdAt: string;
}

@Component({
  selector: 'app-marketing-communication',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, FormsModule],
  template: `
    <div *ngIf="isOpen" class="modal-overlay" (click)="onClose()" role="dialog" aria-modal="true">
      <div class="modal-drawer" (click)="$event.stopPropagation()">
        <div class="modal-header">
          <div class="modal-title-section">
            <span class="material-symbols-outlined modal-icon">send</span>
            <div>
              <h2 class="modal-title">Enviar comunicación masiva</h2>
              <p class="modal-subtitle">Selecciona segmento, canal, redacta mensaje y envía.</p>
            </div>
          </div>
          <button type="button" class="modal-close" (click)="onClose()" aria-label="Cerrar">
            <span class="material-symbols-outlined">close</span>
          </button>
        </div>

        <div class="modal-content">
          <form [formGroup]="communicationForm" *ngIf="currentStep === 1">
            <fieldset class="form-group">
              <legend>Configuración</legend>

              <div class="form-row">
                <div class="form-field">
                  <label for="segment">Segmento de miembros</label>
                  <select id="segment" formControlName="segment" class="form-select">
                    <option *ngFor="let s of segments" [value]="s">{{ s }}</option>
                  </select>
                </div>

                <div class="form-field">
                  <label for="channel">Canal de envío</label>
                  <select id="channel" formControlName="channel" class="form-select">
                    <option *ngFor="let c of channels" [value]="c">{{ c }}</option>
                  </select>
                </div>
              </div>
            </fieldset>

            <fieldset class="form-group">
              <legend>Mensaje</legend>

              <div class="form-field">
                <label for="message">Contenido del mensaje</label>
                <textarea
                  id="message"
                  formControlName="message"
                  placeholder="Escribe tu mensaje aquí…"
                  class="form-textarea"
                  rows="6"
                ></textarea>
                <p class="char-count">{{ messageCharCount }}/320 caracteres</p>
              </div>
            </fieldset>

            <div class="preview-section">
              <h4 class="preview-title">Vista previa</h4>
              <div class="preview-box">
                <p class="preview-message">
                  {{ communicationForm.get('message')?.value || '(El mensaje aparecerá aquí)' }}
                </p>
              </div>
            </div>
          </form>

          <div *ngIf="currentStep === 2" class="confirmation">
            <div class="confirmation-icon">
              <span class="material-symbols-outlined">check_circle</span>
            </div>
            <h3 class="confirmation-title">Comunicación enviada</h3>
            <p class="confirmation-text">
              Se envió la comunicación correctamente a
              <strong>{{ estimatedRecipients }}</strong>
              miembros del segmento <strong>{{ communicationForm.get('segment')?.value }}</strong> a
              través de
              <strong>{{ communicationForm.get('channel')?.value }}</strong>
              .
            </p>

            <div class="send-details">
              <div class="detail-item">
                <span class="detail-label">Segmento:</span>
                <span class="detail-value">{{ communicationForm.get('segment')?.value }}</span>
              </div>
              <div class="detail-item">
                <span class="detail-label">Canal:</span>
                <span class="detail-value">{{ communicationForm.get('channel')?.value }}</span>
              </div>
              <div class="detail-item">
                <span class="detail-label">Destinatarios:</span>
                <span class="detail-value">{{ estimatedRecipients }}</span>
              </div>
              <div class="detail-item">
                <span class="detail-label">Enviada:</span>
                <span class="detail-value">{{ currentDate }}</span>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button
            type="button"
            class="btn-secondary"
            (click)="currentStep === 2 ? onClose() : onClose()"
          >
            {{ currentStep === 2 ? 'Cerrar' : 'Cancelar' }}
          </button>
          <button
            *ngIf="currentStep === 1"
            type="button"
            class="btn-primary"
            (click)="onSend()"
            [disabled]="!communicationForm.valid || isSending"
          >
            <span *ngIf="!isSending">Enviar comunicación</span>
            <span *ngIf="isSending" class="loading">Enviando...</span>
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
        max-width: 520px;
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
        min-height: 100px;
      }

      .form-textarea::placeholder {
        color: #bbb;
      }

      .form-select:focus,
      .form-textarea:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 2px rgba(251, 191, 36, 0.1);
      }

      .char-count {
        font-size: 0.75rem;
        color: #999;
        margin: 0.4rem 0 0;
      }

      .preview-section {
        margin-bottom: 1.2rem;
      }

      .preview-title {
        font-size: 0.8rem;
        font-weight: 700;
        color: #0a0a0a;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin: 0 0 0.8rem;
      }

      .preview-box {
        padding: 1rem;
        background: #f9f9f9;
        border: 1px solid #ededed;
        border-radius: 10px;
      }

      .preview-message {
        font-size: 0.9rem;
        color: #666;
        line-height: 1.6;
        margin: 0;
      }

      .confirmation {
        text-align: center;
        padding: 2rem 0;
      }

      .confirmation-icon {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        background: #d1fae5;
        display: grid;
        place-items: center;
        margin: 0 auto 1rem;
        font-size: 2rem;
        color: #10b981;
      }

      .confirmation-title {
        font-size: 1.1rem;
        font-weight: 800;
        color: #0a0a0a;
        margin: 0 0 0.6rem;
      }

      .confirmation-text {
        font-size: 0.9rem;
        color: #666;
        line-height: 1.6;
        margin: 0 0 1.4rem;
      }

      .send-details {
        display: flex;
        flex-direction: column;
        gap: 0.8rem;
        padding: 1rem;
        background: #f9f9f9;
        border-radius: 10px;
      }

      .detail-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
      }

      .detail-label {
        font-size: 0.85rem;
        font-weight: 600;
        color: #666;
      }

      .detail-value {
        font-size: 0.85rem;
        font-weight: 700;
        color: #0a0a0a;
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
export default class MarketingCommunicationComponent implements OnInit {
  @Input() isOpen = false;
  @Input() isSending = false;

  @Output() close = new EventEmitter<void>();
  @Output() send = new EventEmitter<Partial<Communication>>();

  communicationForm!: FormGroup;
  currentStep = 1;
  currentDate = '';

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

  segmentCounts: { [key: string]: number } = {
    'Todos los miembros': 450,
    'Miembros activos': 380,
    'Miembros inactivos': 70,
    'Membresías por vencer': 45,
    'Membresías vencidas': 25,
    'Nuevos miembros': 12,
    'Miembros VIP': 28,
    Leads: 35,
  };

  constructor(private fb: FormBuilder) {}

  ngOnInit(): void {
    this.buildForm();
  }

  buildForm(): void {
    this.communicationForm = this.fb.group({
      segment: ['Todos los miembros', Validators.required],
      channel: ['WhatsApp', Validators.required],
      message: ['', [Validators.required, Validators.maxLength(320)]],
    });
  }

  get messageCharCount(): number {
    return this.communicationForm.get('message')?.value?.length || 0;
  }

  get estimatedRecipients(): number {
    const segment = this.communicationForm.get('segment')?.value;
    return this.segmentCounts[segment] || 0;
  }

  onClose(): void {
    this.currentStep = 1;
    this.close.emit();
  }

  onSend(): void {
    if (!this.communicationForm.valid) return;
    this.send.emit(this.communicationForm.value);
    this.currentDate = new Date().toLocaleString('es-ES');
    this.currentStep = 2;
  }
}
