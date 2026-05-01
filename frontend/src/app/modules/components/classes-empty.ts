import { CommonModule } from '@angular/common';
import { Component, Output, EventEmitter } from '@angular/core';

@Component({
  selector: 'app-classes-empty',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="empty-state">
      <div class="empty-icon">
        <span class="material-symbols-outlined" aria-hidden="true">school</span>
      </div>
      <h3 class="empty-title">Todavía no hay clases creadas</h3>
      <p class="empty-description">
        Crea tu primera clase para comenzar a gestionar horarios, entrenadores, cupos e
        inscripciones.
      </p>
      <button type="button" class="btn-create-first" (click)="onCreate.emit()">
        <span class="material-symbols-outlined" aria-hidden="true">add_circle</span>
        Crear primera clase
      </button>
    </div>
  `,
  styles: [
    `
      .empty-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 500px;
        padding: 3rem 2rem;
        text-align: center;
        background: linear-gradient(135deg, #f9f9f9 0%, #ffffff 100%);
        border: 1px solid #e5e5e5;
        border-radius: 14px;
        animation: fadeIn 300ms ease;
      }

      @keyframes fadeIn {
        from {
          opacity: 0;
          transform: translateY(10px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }

      .empty-icon {
        display: grid;
        place-items: center;
        width: 80px;
        height: 80px;
        background: rgba(250, 204, 21, 0.1);
        border-radius: 16px;
        margin-bottom: 1.5rem;
        font-size: 2.5rem;
        color: #ca8a04;
      }

      .empty-title {
        font-family: Inter, sans-serif;
        font-size: 1.5rem;
        font-weight: 700;
        color: #0a0a0a;
        margin: 0 0 0.75rem;
        letter-spacing: -0.01em;
      }

      .empty-description {
        font-size: 0.95rem;
        color: #666;
        margin: 0 0 2rem;
        max-width: 400px;
        line-height: 1.6;
      }

      .btn-create-first {
        display: inline-flex;
        align-items: center;
        gap: 0.75rem;
        padding: 1rem 2rem;
        background: #facc15;
        color: #000;
        border: none;
        border-radius: 10px;
        font-family: Inter, sans-serif;
        font-weight: 600;
        font-size: 1rem;
        cursor: pointer;
        transition: all 200ms cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 4px 12px rgba(250, 204, 21, 0.2);
      }

      .btn-create-first:hover {
        background: #f0c00e;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(250, 204, 21, 0.3);
      }

      .btn-create-first:active {
        transform: translateY(0);
      }

      .btn-create-first span {
        font-size: 1.25rem;
      }

      @media (max-width: 640px) {
        .empty-state {
          min-height: 400px;
          padding: 2rem 1.5rem;
        }

        .empty-icon {
          width: 60px;
          height: 60px;
          font-size: 2rem;
          margin-bottom: 1rem;
        }

        .empty-title {
          font-size: 1.25rem;
        }

        .empty-description {
          font-size: 0.9rem;
        }

        .btn-create-first {
          padding: 0.875rem 1.5rem;
          font-size: 0.9rem;
        }
      }
    `,
  ],
})
export class ClassesEmptyComponent {
  @Output() onCreate = new EventEmitter<void>();
}
