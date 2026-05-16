import { CommonModule } from '@angular/common';
import { Component, Input } from '@angular/core';
import { LottieIconComponent } from '../../shared/components/lottie-icon/lottie-icon.component';

@Component({
  selector: 'app-routines-kpi',
  standalone: true,
  imports: [CommonModule, LottieIconComponent],
  template: `
    <article class="kpi-card" [ngClass]="'kpi-' + color">
      <div class="kpi-icon" aria-hidden="true">
        <app-lottie-icon
          *ngIf="lottie; else materialIcon"
          [src]="lottie"
          [size]="32"
          [loop]="true"
        ></app-lottie-icon>
        <ng-template #materialIcon>
          <span class="material-symbols-outlined">{{ icon }}</span>
        </ng-template>
      </div>
      <div class="kpi-body">
        <div class="kpi-title">{{ title }}</div>
        <div class="kpi-value">{{ value }}</div>
        <div class="kpi-sub">{{ subtitle }}</div>
      </div>
    </article>
  `,
  styles: [
    `
      .kpi-card {
        display: flex;
        gap: 0.9rem;
        align-items: center;
        padding: 1.1rem 1.2rem;
        border-radius: 14px;
        border: 1px solid #ededed;
        background: #ffffff;
        box-shadow: 0 8px 22px rgba(0, 0, 0, 0.04);
        transition:
          transform 0.15s ease,
          box-shadow 0.15s ease,
          border-color 0.15s ease;
        min-height: 92px;
      }

      .kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 14px 30px rgba(0, 0, 0, 0.06);
        border-color: #e5e5e5;
      }

      .kpi-icon {
        width: 46px;
        height: 46px;
        border-radius: 14px;
        display: grid;
        place-items: center;
        border: 1px solid #f0f0f0;
        background: #fafafa;
        overflow: hidden;
      }

      .kpi-icon span {
        font-size: 1.4rem;
      }

      .kpi-body {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        min-width: 0;
      }

      .kpi-title {
        font-size: 0.72rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #666;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .kpi-value {
        font-size: 1.65rem;
        font-weight: 800;
        line-height: 1.1;
        color: #0a0a0a;
      }

      .kpi-sub {
        font-size: 0.9rem;
        color: #666;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .kpi-primary .kpi-icon {
        border-color: rgba(251, 191, 36, 0.4);
        background: rgba(251, 191, 36, 0.12);
      }

      .kpi-success .kpi-icon {
        border-color: rgba(16, 185, 129, 0.35);
        background: rgba(16, 185, 129, 0.08);
      }

      .kpi-info .kpi-icon {
        border-color: rgba(59, 130, 246, 0.25);
        background: rgba(59, 130, 246, 0.08);
      }

      .kpi-warning .kpi-icon {
        border-color: rgba(245, 158, 11, 0.25);
        background: rgba(245, 158, 11, 0.08);
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

      .kpi-icon {
        background: rgba(245, 197, 24, 0.12);
        border-color: rgba(245, 197, 24, 0.24);
        color: #ffe08b;
      }

      .kpi-title,
      .kpi-sub {
        color: #b4afa6;
      }

      .kpi-value {
        color: #e5e2e1;
      }
    `,
  ],
})
export default class RoutinesKpiComponent {
  @Input({ required: true }) title!: string;
  @Input({ required: true }) value!: string | number;
  @Input({ required: true }) subtitle!: string;
  @Input() icon: string = 'analytics';
  @Input() lottie: string = '';
  @Input() color: 'primary' | 'success' | 'info' | 'warning' = 'primary';
}
