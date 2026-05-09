import { CommonModule } from '@angular/common';
import { Component, Input } from '@angular/core';
import { LottieIconComponent } from '../../shared/components/lottie-icon/lottie-icon.component';

@Component({
  selector: 'app-classes-kpi',
  standalone: true,
  imports: [CommonModule, LottieIconComponent],
  template: `
    <div class="kpi-card">
      <div class="kpi-header">
        <div
          class="kpi-icon"
          [style.backgroundColor]="'var(--color-' + color + ')'"
          [style.color]="getIconColor()"
        >
          <app-lottie-icon
            *ngIf="lottie; else materialIcon"
            [src]="lottie"
            [size]="32"
            [loop]="true"
          ></app-lottie-icon>
          <ng-template #materialIcon>
            <span class="material-symbols-outlined" aria-hidden="true">{{ icon }}</span>
          </ng-template>
        </div>
        <span class="kpi-label">{{ label }}</span>
      </div>
      <div class="kpi-value">{{ value }}</div>
      <div class="kpi-suffix">{{ suffix }}</div>
    </div>
  `,
  styles: [
    `
      :host {
        --color-primary: rgba(250, 204, 21, 0.1);
        --color-success: rgba(34, 197, 94, 0.1);
        --color-warning: rgba(249, 115, 22, 0.1);
      }

      .kpi-card {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        padding: 1.5rem;
        background: #fff;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        transition: all 200ms ease;
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

      .kpi-card:hover {
        border-color: #facc15;
        box-shadow: 0 4px 12px rgba(250, 204, 21, 0.15);
        transform: translateY(-2px);
      }

      .kpi-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
      }

      .kpi-icon {
        display: grid;
        place-items: center;
        width: 48px;
        height: 48px;
        border-radius: 10px;
        font-size: 1.5rem;
        flex-shrink: 0;
        overflow: hidden;
      }

      .kpi-label {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #999;
      }

      .kpi-value {
        font-family: Inter, sans-serif;
        font-size: 2.5rem;
        font-weight: 700;
        color: #0a0a0a;
        letter-spacing: -0.02em;
        line-height: 1;
      }

      .kpi-suffix {
        font-size: 0.9rem;
        color: #999;
      }

      @media (max-width: 768px) {
        .kpi-card {
          padding: 1.25rem;
        }

        .kpi-value {
          font-size: 2rem;
        }

        .kpi-label {
          font-size: 0.8rem;
        }
      }
    `,
  ],
})
export class ClassesKPIComponent {
  @Input() label: string = '';
  @Input() icon: string = 'school';
  @Input() lottie: string = '';
  @Input() value: string | number = '0';
  @Input() suffix: string = '';
  @Input() color: 'primary' | 'success' | 'warning' = 'primary';

  getIconColor(): string {
    const colors: { [key: string]: string } = {
      primary: '#ca8a04',
      success: '#16a34a',
      warning: '#f97316',
    };
    return colors[this.color] || '#ca8a04';
  }
}
