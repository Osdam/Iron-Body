import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { LottieIconComponent } from '../../shared/components/lottie-icon/lottie-icon.component';

@Component({
  selector: 'app-trainers-kpi',
  standalone: true,
  imports: [CommonModule, LottieIconComponent],
  template: `
    <div class="kpi-card">
      <div class="kpi-icon" [ngClass]="'kpi-' + color">
        <app-lottie-icon
          *ngIf="lottie; else materialIcon"
          [src]="lottie"
          [size]="34"
          [loop]="true"
        ></app-lottie-icon>
        <ng-template #materialIcon>
          <span class="material-symbols-outlined" aria-hidden="true">{{ icon }}</span>
        </ng-template>
      </div>
      <div class="kpi-content">
        <p class="kpi-title">{{ title }}</p>
        <div class="kpi-value">{{ value }}</div>
        <p class="kpi-subtitle">{{ subtitle }}</p>
      </div>
    </div>
  `,
  styles: [
    `
      .kpi-card {
        display: flex;
        gap: 1rem;
        border: 1px solid #f0f0f0;
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 8px 18px rgba(0, 0, 0, 0.04);
        padding: 1.2rem;
        transition: all 0.2s ease;
      }

      .kpi-card:hover {
        border-color: #e0e0e0;
        box-shadow: 0 12px 26px rgba(0, 0, 0, 0.08);
        transform: translateY(-2px);
      }

      .kpi-icon {
        width: 56px;
        height: 56px;
        border-radius: 14px;
        display: grid;
        place-items: center;
        flex-shrink: 0;
        overflow: hidden;
      }

      .kpi-icon.kpi-success {
        border: 1px solid rgba(34, 197, 94, 0.3);
        background: rgba(34, 197, 94, 0.08);
        color: #16a34a;
      }

      .kpi-icon.kpi-info {
        border: 1px solid rgba(59, 130, 246, 0.3);
        background: rgba(59, 130, 246, 0.08);
        color: #2563eb;
      }

      .kpi-icon.kpi-primary {
        border: 1px solid rgba(251, 191, 36, 0.4);
        background: rgba(251, 191, 36, 0.14);
        color: #ca8a04;
      }

      .kpi-icon.kpi-warning {
        border: 1px solid rgba(249, 115, 22, 0.3);
        background: rgba(249, 115, 22, 0.08);
        color: #ea580c;
      }

      .kpi-icon span {
        font-size: 1.4rem;
      }

      .kpi-content {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 0.15rem;
      }

      .kpi-title {
        margin: 0;
        font-size: 0.8rem;
        font-weight: 700;
        color: #999;
        text-transform: uppercase;
        letter-spacing: 0.04em;
      }

      .kpi-value {
        font-size: 1.8rem;
        font-weight: 900;
        color: #0a0a0a;
        line-height: 1.2;
        letter-spacing: -0.01em;
      }

      .kpi-subtitle {
        margin: 0;
        font-size: 0.75rem;
        color: #999;
        line-height: 1.4;
      }

      .kpi-card {
        background:
          linear-gradient(135deg, rgba(28, 27, 27, 0.92), rgba(17, 17, 17, 0.88)),
          url('/assets/crm/clases2.png') center / cover no-repeat;
        border-color: #353534;
        color: #e5e2e1;
        box-shadow: 0 14px 32px rgba(0, 0, 0, 0.22);
      }

      .kpi-card:hover {
        border-color: rgba(245, 197, 24, 0.48);
        box-shadow:
          0 18px 36px rgba(0, 0, 0, 0.28),
          0 0 0 3px rgba(245, 197, 24, 0.08);
      }

      .kpi-icon,
      .kpi-icon.kpi-success,
      .kpi-icon.kpi-info,
      .kpi-icon.kpi-primary,
      .kpi-icon.kpi-warning {
        background: rgba(245, 197, 24, 0.12);
        border: 1px solid rgba(245, 197, 24, 0.24);
        color: #ffe08b;
      }

      .kpi-title,
      .kpi-subtitle {
        color: #b4afa6;
      }

      .kpi-value {
        color: #e5e2e1;
      }
    `,
  ],
})
export default class TrainersKpiComponent {
  @Input() title: string = '';
  @Input() value: number = 0;
  @Input() subtitle: string = '';
  @Input() icon: string = 'person';
  @Input() lottie: string = '';
  @Input() color: 'success' | 'info' | 'primary' | 'warning' = 'info';
}
