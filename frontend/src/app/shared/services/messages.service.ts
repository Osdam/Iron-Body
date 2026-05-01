import { Injectable, signal } from '@angular/core';

export interface Message {
  id: number;
  title: string;
  message: string;
  status: 'Abierto' | 'Recibido' | 'Resuelto';
  timestamp: string;
}

export interface Feedback {
  type: 'Comentario' | 'Error' | 'Mejora' | 'Duda';
  message: string;
}

@Injectable({
  providedIn: 'root',
})
export class MessagesService {
  private readonly _messages = signal<Message[]>([
    {
      id: 1,
      title: 'Ticket de soporte creado',
      message: 'Tu solicitud fue registrada correctamente.',
      status: 'Abierto',
      timestamp: 'Hace 15 minutos',
    },
    {
      id: 2,
      title: 'Comentario enviado',
      message: 'Gracias por enviar tu sugerencia.',
      status: 'Recibido',
      timestamp: 'Ayer',
    },
  ]);

  public readonly messages = this._messages.asReadonly();

  private readonly _isSendingFeedback = signal(false);
  public readonly isSendingFeedback = this._isSendingFeedback.asReadonly();

  private readonly _feedbackSuccess = signal(false);
  public readonly feedbackSuccess = this._feedbackSuccess.asReadonly();

  private readonly _feedbackError = signal<string | null>(null);
  public readonly feedbackError = this._feedbackError.asReadonly();

  constructor() {}

  /**
   * Enviar feedback
   */
  submitFeedback(feedback: Feedback): void {
    if (!feedback.type || !feedback.message?.trim()) {
      this._feedbackError.set('Por favor completa todos los campos.');
      return;
    }

    this._isSendingFeedback.set(true);
    this._feedbackError.set(null);
    this._feedbackSuccess.set(false);

    // Simular envío
    setTimeout(() => {
      // Agregar nuevo mensaje a la lista
      const newMessage: Message = {
        id: Math.max(...this._messages().map((m) => m.id), 0) + 1,
        title: `${feedback.type} enviado`,
        message: feedback.message,
        status: 'Recibido',
        timestamp: 'Hace 1 minuto',
      };

      this._messages.update((msgs) => [newMessage, ...msgs]);
      this._isSendingFeedback.set(false);
      this._feedbackSuccess.set(true);

      // Limpiar alertas después de 3 segundos
      setTimeout(() => {
        this._feedbackSuccess.set(false);
      }, 3000);
    }, 1500);
  }

  /**
   * Agregar mensaje
   */
  addMessage(message: Message): void {
    this._messages.update((msgs) => [message, ...msgs]);
  }

  /**
   * Obtener últimos mensajes
   */
  getLatestMessages(limit: number = 3): Message[] {
    return this._messages().slice(0, limit);
  }

  /**
   * Limpiar error
   */
  clearError(): void {
    this._feedbackError.set(null);
  }
}
