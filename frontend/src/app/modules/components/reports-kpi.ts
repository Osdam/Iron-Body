import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

type KPIColor = 'primary' | 'success' | 'warning' | 'danger' | 'info';

@Component({
  selector: 'app-reports-kpi',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div [ngClass]="['kpi-card', 'kpi-' + color]" [style.background]="cardBackground">
      <div class="kpi-header">
        <h3 class="kpi-label">{{ label }}</h3>
        <span class="material-symbols-outlined kpi-icon">{{ icon }}</span>
      </div>
      <div class="kpi-body">
        <p class="kpi-value">{{ formattedValue }}</p>
        <p class="kpi-suffix">{{ suffix }}</p>
      </div>
      <div *ngIf="trend" class="kpi-trend" [class]="'trend-' + trendDirection">
        <span class="material-symbols-outlined">{{ trendIcon }}</span>
        <span class="trend-text">{{ Math.abs(trend) }}%</span>
      </div>
    </div>
  `,
  styles: [
    `
      .kpi-card {
        background: #ffffff;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        gap: 1rem;
        transition: all 0.3s ease;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
      }

      .kpi-card:hover {
        border-color: #d0d0d0;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
      }

      .kpi-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
      }

      .kpi-label {
        font-size: 0.875rem;
        font-weight: 500;
        color: #666;
        margin: 0;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }

      .kpi-icon {
        font-size: 1.5rem;
        color: #999;
        font-variation-settings:
          'FILL' 0,
          'wght' 400,
          'GRAD' 0,
          'opsz' 24;
      }

      .kpi-body {
        flex: 1;
      }

      .kpi-value {
        font-size: 1.75rem;
        font-weight: 700;
        color: #0a0a0a;
        margin: 0 0 0.25rem;
        line-height: 1.2;
      }

      .kpi-suffix {
        font-size: 0.8rem;
        color: #999;
        margin: 0;
        font-weight: 500;
      }

      .kpi-trend {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.85rem;
        font-weight: 600;
      }

      .trend-up {
        color: #10b981;
      }

      .trend-down {
        color: #ef4444;
      }

      .kpi-primary {
        border-left: 4px solid #fbbf24;
      }

      .kpi-success {
        border-left: 4px solid #10b981;
      }

      .kpi-warning {
        border-left: 4px solid #f97316;
      }

      .kpi-danger {
        border-left: 4px solid #ef4444;
      }

      .kpi-info {
        border-left: 4px solid #06b6d4;
      }

      .kpi-primary .kpi-icon {
        color: #fbbf24;
      }

      .kpi-success .kpi-icon {
        color: #10b981;
      }

      .kpi-warning .kpi-icon {
        color: #f97316;
      }

      .kpi-danger .kpi-icon {
        color: #ef4444;
      }

      .kpi-info .kpi-icon {
        color: #06b6d4;
      }

      .kpi-card {
        background: #1c1b1b !important;
        border-color: #353534;
        box-shadow: 0 18px 44px rgba(0, 0, 0, 0.20);
      }

      .kpi-card:hover {
        border-color: #f5c518;
        box-shadow: 0 16px 38px rgba(245, 197, 24, 0.12);
      }

      .kpi-label,
      .kpi-suffix {
        color: #b4afa6;
      }

      .kpi-value {
        color: #e5e2e1;
      }
    `,
  ],
})
export default class ReportsKPIComponent {
  @Input() label: string = '';
  @Input() icon: string = 'info';
  @Input() value: number | string = 0;
  @Input() suffix: string = '';
  @Input() color: KPIColor = 'primary';
  @Input() trend?: number; // porcentaje, positivo o negativo
  @Input() bgImage: string = '';

  Math = Math;

  get cardBackground(): string {
    if (!this.bgImage) return '';
    return `linear-gradient(rgba(28, 27, 27, 0.92), rgba(17, 17, 17, 0.88)), url('${this.bgImage}') center / cover no-repeat`;
  }

  get formattedValue(): string {
    if (typeof this.value === 'string') return this.value;
    if (this.value >= 1000000) return (this.value / 1000000).toFixed(1) + 'M';
    if (this.value >= 1000) return (this.value / 1000).toFixed(1) + 'K';
    return this.value.toLocaleString();
  }

  get trendDirection(): 'up' | 'down' {
    return this.trend && this.trend >= 0 ? 'up' : 'down';
  }

  get trendIcon(): string {
    return this.trend && this.trend >= 0 ? 'trending_up' : 'trending_down';
  }
}
