import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { LottieIconComponent } from '../../shared/components/lottie-icon/lottie-icon.component';

export interface KPIData {
  label: string;
  icon?: string;
  lottie?: string;
  value: string | number;
  suffix?: string;
  color?: 'success' | 'primary' | 'warning';
}

@Component({
  selector: 'app-plans-kpi',
  standalone: true,
  imports: [CommonModule, LottieIconComponent],
  template: `
    <article class="kpi-card" [class]="'color-' + (color || 'primary')">
      <div class="kpi-icon">
        <app-lottie-icon
          *ngIf="lottie; else materialIcon"
          [src]="lottie"
          [size]="36"
          [loop]="true"
        ></app-lottie-icon>
        <ng-template #materialIcon>
          <span class="material-symbols-outlined" aria-hidden="true">{{ icon }}</span>
        </ng-template>
      </div>
      <div class="kpi-content">
        <div class="kpi-label">{{ label }}</div>
        <div class="kpi-value">
          <strong>{{ formatValue(value) }}</strong>
          <span *ngIf="suffix">{{ suffix }}</span>
        </div>
      </div>
    </article>
  `,
  styles: [
    `
      .kpi-card {
        display: flex;
        align-items: flex-start;
        gap: 1.25rem;
        padding: 1.75rem;
        background: #fff;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
        transition: all 200ms cubic-bezier(0.4, 0, 0.2, 1);
      }

      .kpi-card:hover {
        border-color: #d0d0d0;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        transform: translateY(-2px);
      }

      .kpi-icon {
        display: grid;
        place-items: center;
        width: 56px;
        height: 56px;
        border-radius: 10px;
        background: #f5f5f5;
        color: #404040;
        flex-shrink: 0;
        overflow: hidden;
        transition: all 200ms ease;
      }

      .kpi-card.color-primary .kpi-icon {
        background: rgba(250, 204, 21, 0.1);
        color: #ca8a04;
      }

      .kpi-card.color-success .kpi-icon {
        background: #ecfdf5;
        color: #059669;
      }

      .kpi-card.color-warning .kpi-icon {
        background: #fef3c7;
        color: #d97706;
      }

      .kpi-card:hover .kpi-icon {
        transform: scale(1.05);
      }

      .kpi-content {
        flex: 1;
        min-width: 0;
      }

      .kpi-label {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: #666;
        font-weight: 600;
        margin-bottom: 0.5rem;
      }

      .kpi-value {
        display: flex;
        align-items: baseline;
        gap: 0.75rem;
      }

      .kpi-value strong {
        font-family: Inter, sans-serif;
        font-size: 2.2rem;
        line-height: 1;
        font-weight: 700;
        color: #0a0a0a;
        letter-spacing: -0.01em;
      }

      .kpi-value span {
        color: #666;
        font-weight: 500;
        font-size: 0.95rem;
      }

      @media (max-width: 1024px) {
        .kpi-card {
          padding: 1.5rem;
          gap: 1rem;
        }

        .kpi-icon {
          width: 48px;
          height: 48px;
        }

        .kpi-value strong {
          font-size: 1.8rem;
        }
      }

      @media (max-width: 600px) {
        .kpi-card {
          padding: 1.25rem;
          gap: 0.875rem;
        }

        .kpi-icon {
          width: 42px;
          height: 42px;
          font-size: 1.2rem;
        }

        .kpi-value strong {
          font-size: 1.5rem;
        }

        .kpi-label {
          font-size: 0.65rem;
        }
      }
    `,
  ],
})
export class PlansKPIComponent {
  @Input() label: string = '';
  @Input() icon: string = 'info';
  @Input() lottie: string = '';
  @Input() value: string | number = 0;
  @Input() suffix: string = '';
  @Input() color: 'success' | 'primary' | 'warning' = 'primary';

  formatValue(val: string | number): string {
    if (typeof val === 'number') {
      if (val >= 1000000) return (val / 1000000).toFixed(1) + 'M';
      if (val >= 1000) return (val / 1000).toFixed(1) + 'K';
      return val.toString();
    }
    return val;
  }
}
