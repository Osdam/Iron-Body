import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, Output } from '@angular/core';

export interface CampaignSegment {
  name: string;
  count: number;
  color: string;
  percentage: number;
}

@Component({
  selector: 'app-campaign-distribution',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="distribution-card">
      <div class="distribution-header">
        <h3 class="distribution-title">Distribución de campañas</h3>
        <button type="button" class="distribution-action" (click)="onAction()">
          Crear campaña de renovación
        </button>
      </div>

      <div class="distribution-section">
        <div
          class="progress-bar"
          role="progressbar"
          [attr.aria-valuenow]="totalCampaigns"
          aria-valuemin="0"
          [attr.aria-valuemax]="maxCampaigns"
        >
          <div
            *ngFor="let seg of segments"
            class="progress-segment"
            [ngClass]="'segment-' + seg.name"
          >
            <div
              class="segment-fill"
              [style.width.%]="seg.percentage"
              [style.background]="seg.color"
            ></div>
          </div>
        </div>

        <div class="progress-stats">
          <div class="stats-items">
            <div *ngFor="let seg of segments" class="stat-item">
              <span class="stat-dot" [style.background]="seg.color"></span>
              <span class="stat-label">{{ seg.name }}</span>
              <span class="stat-value">{{ seg.count }}</span>
            </div>
          </div>
          <p class="progress-text">
            {{ totalCampaigns }} de {{ maxCampaigns }} campañas creadas este mes
          </p>
        </div>
      </div>

      <div class="alert" role="status">
        <span class="material-symbols-outlined alert-icon">lightbulb</span>
        <div class="alert-content">
          <p class="alert-message">
            Hay 14 membresías próximas a vencer. Crea una campaña de renovación para aumentar la
            retención.
          </p>
          <button type="button" class="alert-action" (click)="onCreateRenewalCampaign()">
            Crear campaña de renovación
          </button>
        </div>
      </div>
    </div>
  `,
  styles: [
    `
      .distribution-card {
        border: 1px solid #ededed;
        border-radius: 16px;
        background: #ffffff;
        padding: 1.6rem;
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.04);
      }

      .distribution-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.4rem;
        flex-wrap: wrap;
      }

      .distribution-title {
        font-size: 1.1rem;
        font-weight: 800;
        color: #0a0a0a;
        margin: 0;
      }

      .distribution-action {
        padding: 0.65rem 1.1rem;
        background: #ffffff;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 700;
        color: #0a0a0a;
        cursor: pointer;
        transition: all 0.15s ease;
      }

      .distribution-action:hover {
        background: #fafafa;
        border-color: #d0d0d0;
      }

      .distribution-section {
        margin-bottom: 1.4rem;
      }

      .progress-bar {
        position: relative;
        width: 100%;
        height: 12px;
        border-radius: 8px;
        background: #f3f3f3;
        overflow: hidden;
        margin-bottom: 1rem;
        display: flex;
      }

      .progress-segment {
        flex: 1;
        position: relative;
      }

      .segment-fill {
        height: 100%;
        transition: width 0.35s ease;
      }

      .progress-stats {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        flex-wrap: wrap;
      }

      .stats-items {
        display: flex;
        flex-wrap: wrap;
        gap: 1.2rem;
      }

      .stat-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.85rem;
      }

      .stat-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        flex-shrink: 0;
      }

      .stat-label {
        font-weight: 600;
        color: #0a0a0a;
      }

      .stat-value {
        font-weight: 700;
        color: #0a0a0a;
      }

      .progress-text {
        font-size: 0.85rem;
        color: #999;
        margin: 0;
        font-weight: 500;
      }

      .alert {
        display: flex;
        gap: 1rem;
        padding: 1.1rem;
        background: linear-gradient(
          135deg,
          rgba(251, 191, 36, 0.08) 0%,
          rgba(251, 191, 36, 0.03) 100%
        );
        border: 1px solid rgba(251, 191, 36, 0.3);
        border-radius: 12px;
      }

      .alert-icon {
        font-size: 1.4rem;
        color: #fbbf24;
        flex-shrink: 0;
        margin-top: 0.1rem;
      }

      .alert-content {
        flex: 1;
      }

      .alert-message {
        margin: 0 0 0.6rem;
        font-size: 0.9rem;
        font-weight: 600;
        color: #0a0a0a;
        line-height: 1.5;
      }

      .alert-action {
        padding: 0.5rem 1rem;
        background: #fbbf24;
        border: none;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 800;
        color: #0a0a0a;
        cursor: pointer;
        transition: all 0.15s ease;
      }

      .alert-action:hover {
        background: #f9a825;
      }
    `,
  ],
})
export default class CampaignDistributionComponent {
  @Input() segments: CampaignSegment[] = [
    { name: 'Activas', count: 3, color: '#10b981', percentage: 15 },
    { name: 'Programadas', count: 2, color: '#3b82f6', percentage: 10 },
    { name: 'Borrador', count: 8, color: '#fbbf24', percentage: 40 },
    { name: 'Finalizada', count: 7, color: '#d1d5db', percentage: 35 },
  ];

  @Output() actionClicked = new EventEmitter<string>();
  @Output() createRenewalCampaign = new EventEmitter<void>();

  get totalCampaigns(): number {
    return this.segments.reduce((sum, seg) => sum + seg.count, 0);
  }

  maxCampaigns = 20;

  onAction(): void {
    this.actionClicked.emit('create_campaign');
  }

  onCreateRenewalCampaign(): void {
    this.createRenewalCampaign.emit();
  }
}
