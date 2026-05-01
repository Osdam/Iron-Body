import { CommonModule } from '@angular/common';
import { Component, Input } from '@angular/core';

@Component({
  selector: 'app-marketing-kpi',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="kpi-card" [ngClass]="'kpi-' + color">
      <div class="kpi-header">
        <span class="material-symbols-outlined kpi-icon">{{ icon }}</span>
        <p class="kpi-title">{{ title }}</p>
      </div>
      <div class="kpi-value">{{ value }}</div>
      <p class="kpi-subtitle">{{ subtitle }}</p>
    </div>
  `,
  styles: [
    `
      .kpi-card {
        border: 1px solid #ededed;
        border-radius: 14px;
        background: #ffffff;
        padding: 1.4rem;
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.04);
        transition: all 0.22s ease;
      }

      .kpi-card:hover {
        border-color: #d0d0d0;
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.08);
        transform: translateY(-1px);
      }

      .kpi-header {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        margin-bottom: 0.8rem;
      }

      .kpi-icon {
        font-size: 1.4rem;
        font-weight: 800;
      }

      .kpi-title {
        font-size: 0.85rem;
        font-weight: 700;
        color: #666;
        margin: 0;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }

      .kpi-value {
        font-size: 2rem;
        font-weight: 900;
        color: #0a0a0a;
        margin: 0.4rem 0;
        line-height: 1.2;
      }

      .kpi-subtitle {
        font-size: 0.8rem;
        color: #999;
        margin: 0;
        font-weight: 500;
      }

      .kpi-success .kpi-icon {
        color: #10b981;
      }

      .kpi-info .kpi-icon {
        color: #3b82f6;
      }

      .kpi-primary .kpi-icon {
        color: #fbbf24;
      }

      .kpi-warning .kpi-icon {
        color: #f97316;
      }
    `,
  ],
})
export default class MarketingKpiComponent {
  @Input() title: string = '';
  @Input() value: number = 0;
  @Input() subtitle: string = '';
  @Input() icon: string = 'trending_up';
  @Input() color: 'success' | 'info' | 'primary' | 'warning' = 'primary';
}
