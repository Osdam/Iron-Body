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
        padding: 1.25rem;
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.04);
        transition: all 0.22s ease;
        min-width: 0;
        overflow: hidden;
      }

      .kpi-card:hover {
        border-color: #d0d0d0;
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.08);
        transform: translateY(-1px);
      }

      .kpi-header {
        display: grid;
        grid-template-columns: 38px minmax(0, 1fr);
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 0.9rem;
        min-width: 0;
      }

      .kpi-icon {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        display: grid;
        place-items: center;
        background: #f7f7f7;
        font-size: 1.25rem;
        font-weight: 800;
        flex: 0 0 auto;
      }

      .kpi-title {
        font-size: 0.85rem;
        font-weight: 700;
        color: #666;
        margin: 0;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        line-height: 1.25;
        min-width: 0;
        overflow-wrap: break-word;
      }

      .kpi-value {
        font-size: 2rem;
        font-weight: 900;
        color: #0a0a0a;
        margin: 0.4rem 0;
        line-height: 1.2;
        overflow-wrap: break-word;
      }

      .kpi-subtitle {
        font-size: 0.8rem;
        color: #999;
        margin: 0;
        font-weight: 500;
        line-height: 1.35;
        overflow-wrap: break-word;
      }

      @media (max-width: 520px) {
        .kpi-card {
          padding: 1.1rem;
        }

        .kpi-title {
          font-size: 0.78rem;
        }

        .kpi-value {
          font-size: 1.75rem;
        }
      }

      .kpi-success .kpi-icon {
        color: #10b981;
        background: rgba(16, 185, 129, 0.1);
      }

      .kpi-info .kpi-icon {
        color: #3b82f6;
        background: rgba(59, 130, 246, 0.1);
      }

      .kpi-primary .kpi-icon {
        color: #fbbf24;
        background: rgba(251, 191, 36, 0.12);
      }

      .kpi-warning .kpi-icon {
        color: #f97316;
        background: rgba(249, 115, 22, 0.1);
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
