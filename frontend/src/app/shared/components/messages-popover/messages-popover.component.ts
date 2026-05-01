import { Component, inject, signal, Input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { MessagesService } from '../../services/messages.service';
import { SupportService } from '../../services/support.service';

@Component({
  selector: 'app-messages-popover',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  template: `
    <!-- Overlay -->
    <div *ngIf="isOpen" class="messages-overlay" (click)="togglePopover()" aria-hidden="true"></div>

    <!-- Popover Container -->
    <div *ngIf="isOpen" class="messages-popover" role="dialog" aria-modal="true">
      <!-- Header -->
      <div class="messages-header">
        <h2 class="messages-title">Mensajes y soporte</h2>
        <button
          class="messages-close-btn"
          (click)="togglePopover()"
          aria-label="Cerrar mensajes"
          type="button"
        >
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>

      <!-- Tabs -->
      <div class="messages-tabs">
        <button
          *ngFor="let tab of tabs"
          [class.active]="selectedTab() === tab.id"
          (click)="selectedTab.set(tab.id)"
          class="tab-btn"
          type="button"
        >
          {{ tab.label }}
        </button>
      </div>

      <!-- Tab Content -->
      <div class="messages-content">
        <!-- Messages Tab -->
        <div *ngIf="selectedTab() === 'mensajes'" class="tab-content">
          <div
            *ngIf="messagesService.getLatestMessages().length > 0; else noMessages"
            class="messages-list"
          >
            <div *ngFor="let msg of messagesService.getLatestMessages()" class="message-item">
              <div class="message-icon">
                <span class="material-symbols-outlined">{{ getMessageIcon(msg.status) }}</span>
              </div>
              <div class="message-content">
                <h3 class="message-title">{{ msg.title }}</h3>
                <p class="message-text">{{ msg.message }}</p>
                <div class="message-footer">
                  <span class="message-status">{{ msg.status }}</span>
                  <span class="message-time">{{ msg.timestamp }}</span>
                </div>
              </div>
            </div>
          </div>
          <ng-template #noMessages>
            <div class="empty-state">
              <span class="material-symbols-outlined">mail_outline</span>
              <p>No hay mensajes recientes.</p>
            </div>
          </ng-template>
        </div>

        <!-- Feedback Tab -->
        <div *ngIf="selectedTab() === 'feedback'" class="tab-content">
          <div *ngIf="messagesService.feedbackSuccess()" class="feedback-alert success">
            <span class="material-symbols-outlined">check_circle</span>
            <div>
              <strong>Feedback enviado</strong>
              <p>Gracias por tu comentario.</p>
            </div>
          </div>

          <div *ngIf="messagesService.feedbackError()" class="feedback-alert error">
            <span class="material-symbols-outlined">error</span>
            <div>
              <strong>Error</strong>
              <p>{{ messagesService.feedbackError() }}</p>
            </div>
          </div>

          <form [formGroup]="feedbackForm" (ngSubmit)="onSubmitFeedback()" class="feedback-form">
            <div class="form-group">
              <label for="type" class="form-label">Tipo</label>
              <select id="type" formControlName="type" class="form-control">
                <option value="">Selecciona un tipo</option>
                <option value="Comentario">Comentario</option>
                <option value="Error">Error</option>
                <option value="Mejora">Mejora</option>
                <option value="Duda">Duda</option>
              </select>
            </div>

            <div class="form-group">
              <label for="message" class="form-label">Mensaje</label>
              <textarea
                id="message"
                formControlName="message"
                class="form-control"
                placeholder="Describe brevemente tu comentario o problema…"
                rows="4"
              ></textarea>
            </div>

            <button
              type="submit"
              class="submit-btn"
              [disabled]="!feedbackForm.valid || messagesService.isSendingFeedback()"
            >
              {{ messagesService.isSendingFeedback() ? 'Enviando...' : 'Enviar feedback' }}
            </button>
          </form>
        </div>

        <!-- Tickets Tab -->
        <div *ngIf="selectedTab() === 'tickets'" class="tab-content">
          <div class="tickets-info">
            <p>Manage your support tickets from here or open the full support panel.</p>
          </div>
          <button (click)="openSupportPanel()" class="full-support-btn" type="button">
            Abrir centro de soporte
          </button>
        </div>
      </div>
    </div>
  `,
  styleUrl: './messages-popover.component.scss',
})
export class MessagesPoperoverComponent {
  messagesService = inject(MessagesService);
  supportService = inject(SupportService);
  private readonly fb = inject(FormBuilder);

  @Input() isOpen = false;
  @Output() close = new EventEmitter<void>();
  selectedTab = signal<string>('mensajes');
  feedbackForm: FormGroup;

  tabs = [
    { id: 'mensajes', label: 'Mensajes' },
    { id: 'feedback', label: 'Feedback' },
    { id: 'tickets', label: 'Tickets' },
  ];

  constructor() {
    this.feedbackForm = this.fb.group({
      type: ['', Validators.required],
      message: ['', [Validators.required, Validators.minLength(5)]],
    });
  }

  /**
   * Alternar popover
   */
  togglePopover(): void {
    this.close.emit();
  }

  /**
   * Enviar feedback
   */
  onSubmitFeedback(): void {
    if (!this.feedbackForm.valid) {
      return;
    }

    this.messagesService.submitFeedback({
      type: this.feedbackForm.get('type')?.value,
      message: this.feedbackForm.get('message')?.value,
    });

    // Limpiar formulario
    setTimeout(() => {
      this.feedbackForm.reset();
    }, 1500);
  }

  /**
   * Obtener icono según estado
   */
  getMessageIcon(status: string): string {
    const icons: Record<string, string> = {
      Abierto: 'mail_outline',
      Recibido: 'mail',
      Resuelto: 'done_all',
    };
    return icons[status] || 'mail';
  }

  /**
   * Abrir panel de soporte
   */
  openSupportPanel(): void {
    this.supportService.openSupport();
    this.togglePopover();
  }
}
