import { CommonModule } from '@angular/common';
import { Component, Output, EventEmitter } from '@angular/core';
import { LottieIconComponent } from '../../shared/components/lottie-icon/lottie-icon.component';

@Component({
  selector: 'app-members-empty',
  standalone: true,
  imports: [CommonModule, LottieIconComponent],
  template: `
    <div class="empty-state">
      <div class="empty-icon">
        <app-lottie-icon src="/assets/crm/miembros.json" [size]="64" [loop]="true"></app-lottie-icon>
      </div>
      <h3 class="empty-title">Todavía no hay miembros registrados</h3>
      <p class="empty-description">
        Registra tu primer miembro para comenzar a gestionar datos personales, contacto, membresía y
        estado dentro del gimnasio.
      </p>
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
        width: 96px;
        height: 96px;
        background: rgba(250, 204, 21, 0.1);
        border-radius: 20px;
        margin-bottom: 1.5rem;
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
        margin: 0;
        max-width: 400px;
        line-height: 1.6;
      }

      @media (max-width: 640px) {
        .empty-state {
          min-height: 400px;
          padding: 2rem 1.5rem;
        }

        .empty-icon {
          width: 76px;
          height: 76px;
          margin-bottom: 1rem;
        }

        .empty-title {
          font-size: 1.25rem;
        }

        .empty-description {
          font-size: 0.9rem;
        }
      }
    `,
  ],
})
export class MembersEmptyComponent {
  @Output() onCreate = new EventEmitter<void>();
}
