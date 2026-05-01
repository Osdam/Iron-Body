import { Component, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';

export interface QuickReport {
  id: string;
  title: string;
  description: string;
  icon: string;
  color: 'primary' | 'success' | 'warning' | 'danger' | 'info';
}

@Component({
  selector: 'app-reports-quick-actions',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="quick-actions-section">
      <h3 class="section-title">Reportes rápidos</h3>
      <div class="quick-actions-grid">
        <button
          *ngFor="let report of quickReports"
          (click)="onReportSelect(report)"
          [ngClass]="['quick-action-card', 'quick-action-' + report.color]"
        >
          <span class="material-symbols-outlined quick-icon">{{ report.icon }}</span>
          <h4 class="quick-title">{{ report.title }}</h4>
          <p class="quick-description">{{ report.description }}</p>
          <span class="quick-arrow material-symbols-outlined">arrow_forward</span>
        </button>
      </div>
    </div>
  `,
  styles: [
    `
      .quick-actions-section {
        margin-bottom: 2rem;
      }

      .section-title {
        font-size: 1rem;
        font-weight: 600;
        color: #0a0a0a;
        margin: 0 0 1rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }

      .quick-actions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 1rem;
      }

      .quick-action-card {
        background: #ffffff;
        border: 2px solid #e5e5e5;
        border-radius: 12px;
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        cursor: pointer;
        transition: all 0.3s ease;
        text-align: center;
        position: relative;
        overflow: hidden;
      }

      .quick-action-card:hover {
        border-color: #d0d0d0;
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        transform: translateY(-2px);
      }

      .quick-action-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        transition: all 0.3s ease;
      }

      .quick-action-primary::before {
        background: #fbbf24;
      }

      .quick-action-success::before {
        background: #10b981;
      }

      .quick-action-warning::before {
        background: #f97316;
      }

      .quick-action-danger::before {
        background: #ef4444;
      }

      .quick-action-info::before {
        background: #06b6d4;
      }

      .quick-icon {
        font-size: 2rem;
        color: #fbbf24;
      }

      .quick-action-success .quick-icon {
        color: #10b981;
      }

      .quick-action-warning .quick-icon {
        color: #f97316;
      }

      .quick-action-danger .quick-icon {
        color: #ef4444;
      }

      .quick-action-info .quick-icon {
        color: #06b6d4;
      }

      .quick-title {
        font-size: 1rem;
        font-weight: 600;
        color: #0a0a0a;
        margin: 0;
      }

      .quick-description {
        font-size: 0.8rem;
        color: #999;
        margin: 0;
        flex: 1;
      }

      .quick-arrow {
        font-size: 1.2rem;
        color: #fbbf24;
        transition: transform 0.2s ease;
      }

      .quick-action-card:hover .quick-arrow {
        transform: translateX(4px);
      }

      @media (max-width: 768px) {
        .quick-actions-grid {
          grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        }

        .quick-title {
          font-size: 0.9rem;
        }

        .quick-icon {
          font-size: 1.5rem;
        }
      }
    `,
  ],
})
export default class ReportsQuickActionsComponent {
  @Output() reportSelect = new EventEmitter<QuickReport>();

  quickReports: QuickReport[] = [
    {
      id: 'income',
      title: 'Ingresos',
      description: 'Resumen de ingresos totales',
      icon: 'trending_up',
      color: 'primary',
    },
    {
      id: 'pending-payments',
      title: 'Pagos Pendientes',
      description: 'Cobros pendientes de realizar',
      icon: 'pending_actions',
      color: 'warning',
    },
    {
      id: 'active-members',
      title: 'Miembros Activos',
      description: 'Miembros con membresía vigente',
      icon: 'group',
      color: 'success',
    },
    {
      id: 'expired-memberships',
      title: 'Membresías Vencidas',
      description: 'Membresías sin renovar',
      icon: 'event_busy',
      color: 'danger',
    },
    {
      id: 'best-plans',
      title: 'Planes Populares',
      description: 'Planes más vendidos',
      icon: 'star',
      color: 'primary',
    },
    {
      id: 'class-attendance',
      title: 'Asistencia a Clases',
      description: 'Promedio por clase',
      icon: 'people',
      color: 'info',
    },
  ];

  onReportSelect(report: QuickReport) {
    this.reportSelect.emit(report);
  }
}
