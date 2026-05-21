import { Component, Output, EventEmitter } from '@angular/core';

@Component({
  selector: 'app-plans-empty',
  standalone: true,
  template: `
    <section class="empty-state-container">
      <article class="empty-state-card">
        <div class="empty-icon">
          <span class="material-symbols-outlined" aria-hidden="true">loyalty</span>
        </div>

        <h2 class="empty-title">Todavía no hay planes creados</h2>

        <p class="empty-description">
          Crea tu primer plan para comenzar a administrar precios, duración, beneficios y miembros
          asociados a cada membresía.
        </p>

        <button class="create-btn" (click)="onCreate.emit()">
          <span class="material-symbols-outlined">add</span>
          Crear primer plan
        </button>

        <div class="empty-examples">
          <h3 class="examples-title">Ejemplos de planes:</h3>
          <div class="examples-grid">
            <div class="example-item">
              <span class="example-icon">📅</span>
              <span class="example-name">Mensual</span>
            </div>
            <div class="example-item">
              <span class="example-icon">📈</span>
              <span class="example-name">Trimestral</span>
            </div>
            <div class="example-item">
              <span class="example-icon">⭐</span>
              <span class="example-name">VIP</span>
            </div>
            <div class="example-item">
              <span class="example-icon">🎓</span>
              <span class="example-name">Estudiante</span>
            </div>
          </div>
        </div>
      </article>
    </section>
  `,
  styles: [
    `
      .empty-state-container {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 500px;
        padding: 2rem 1rem;
      }

      .empty-state-card {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        max-width: 450px;
        padding: 3rem 2.5rem;
        background: linear-gradient(135deg, #fafafa 0%, #ffffff 100%);
        border: 2px dashed #e5e5e5;
        border-radius: 16px;
        animation: slideUp 400ms cubic-bezier(0.4, 0, 0.2, 1);
      }

      @keyframes slideUp {
        from {
          opacity: 0;
          transform: translateY(20px);
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
        border-radius: 16px;
        background: linear-gradient(
          135deg,
          rgba(250, 204, 21, 0.15) 0%,
          rgba(250, 204, 21, 0.05) 100%
        );
        color: #ca8a04;
        font-size: 2.2rem;
        margin-bottom: 1.5rem;
      }

      .empty-title {
        font-family: Inter, sans-serif;
        font-size: 1.75rem;
        font-weight: 700;
        color: #0a0a0a;
        margin: 0 0 0.75rem;
        letter-spacing: -0.01em;
      }

      .empty-description {
        font-size: 0.95rem;
        line-height: 1.7;
        color: #666;
        margin: 0 0 2rem;
        max-width: 380px;
      }

      .create-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.6rem;
        padding: 0.875rem 2rem;
        border: none;
        border-radius: 10px;
        background: #facc15;
        color: #000;
        font-family: Inter, sans-serif;
        font-weight: 600;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 200ms cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 4px 12px rgba(250, 204, 21, 0.2);
        margin-bottom: 2rem;
      }

      .create-btn:hover {
        background: #f0c00e;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(250, 204, 21, 0.3);
      }

      .create-btn:active {
        transform: translateY(0);
      }

      .create-btn span {
        font-size: 1.1rem;
      }

      .empty-examples {
        width: 100%;
        padding-top: 1.5rem;
        border-top: 1px solid #e5e5e5;
      }

      .examples-title {
        font-size: 0.8rem;
        color: #999;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        font-weight: 600;
        margin: 0 0 1rem;
        font-family: 'Space Grotesk', sans-serif;
      }

      .examples-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0.75rem;
      }

      .example-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem;
        border-radius: 8px;
        background: #f5f5f5;
        transition: all 200ms ease;
      }

      .example-item:hover {
        background: #facc15;
        transform: translateY(-1px);
      }

      .example-icon {
        font-size: 1.5rem;
      }

      .example-name {
        font-size: 0.75rem;
        color: #404040;
        font-weight: 600;
        text-align: center;
      }

      @media (max-width: 600px) {
        .empty-state-container {
          min-height: 400px;
          padding: 1.5rem 1rem;
        }

        .empty-state-card {
          padding: 2rem 1.5rem;
        }

        .empty-icon {
          width: 64px;
          height: 64px;
          font-size: 1.8rem;
        }

        .empty-title {
          font-size: 1.4rem;
        }

        .empty-description {
          font-size: 0.9rem;
        }

        .examples-grid {
          grid-template-columns: repeat(2, 1fr);
          gap: 0.5rem;
        }

        .example-item {
          padding: 0.6rem;
        }
      }
    `,
  ],
})
export class PlansEmptyComponent {
  @Output() onCreate = new EventEmitter<void>();
}
