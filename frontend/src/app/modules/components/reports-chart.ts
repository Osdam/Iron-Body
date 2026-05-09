import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ChartConfiguration } from 'chart.js';
import { BaseChartDirective } from 'ng2-charts';

export interface ChartData {
  labels: string[];
  datasets: {
    label: string;
    data: number[];
    borderColor?: string;
    backgroundColor?: string | string[];
    fill?: boolean;
    tension?: number;
    borderWidth?: number;
  }[];
}

@Component({
  selector: 'app-reports-chart',
  standalone: true,
  imports: [CommonModule, BaseChartDirective],
  template: `
    <div class="chart-card" [style.background]="cardBackground">
      <div class="chart-header">
        <div>
          <h3 class="chart-title">{{ title }}</h3>
          <p class="chart-subtitle">{{ subtitle }}</p>
        </div>
        <div class="chart-stats" *ngIf="stats">
          <div class="stat">
            <span class="stat-label">Máximo</span>
            <span class="stat-value">{{ formatCurrency(stats.max) }}</span>
          </div>
          <div class="stat">
            <span class="stat-label">Mínimo</span>
            <span class="stat-value">{{ formatCurrency(stats.min) }}</span>
          </div>
          <div class="stat">
            <span class="stat-label">Promedio</span>
            <span class="stat-value">{{ formatCurrency(stats.avg) }}</span>
          </div>
        </div>
      </div>

      <div class="chart-container">
        <canvas baseChart [data]="chartData" [options]="chartOptions" [type]="type"></canvas>
      </div>
    </div>
  `,
  styles: [
    `
      .chart-card {
        background: #ffffff;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
      }

      .chart-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1.5rem;
        flex-wrap: wrap;
      }

      .chart-title {
        font-size: 1.125rem;
        font-weight: 600;
        color: #0a0a0a;
        margin: 0 0 0.25rem;
      }

      .chart-subtitle {
        font-size: 0.85rem;
        color: #999;
        margin: 0;
      }

      .chart-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 1rem;
      }

      .stat {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        padding: 0.75rem;
        background: #f9f9f9;
        border-radius: 8px;
        border: 1px solid #e5e5e5;
      }

      .stat-label {
        font-size: 0.75rem;
        color: #999;
        text-transform: uppercase;
        font-weight: 500;
        letter-spacing: 0.5px;
      }

      .stat-value {
        font-size: 1rem;
        font-weight: 700;
        color: #0a0a0a;
      }

      .chart-container {
        position: relative;
        height: 350px;
      }

      @media (max-width: 768px) {
        .chart-header {
          flex-direction: column;
        }

        .chart-stats {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export default class ReportsChartComponent {
  @Input() type: any = 'line';
  @Input() title: string = 'Gráfico';
  @Input() subtitle: string = '';
  @Input() chartData: ChartData = { labels: [], datasets: [] };
  @Input() stats?: { max: number; min: number; avg: number };
  @Input() bgImage: string = '';

  get cardBackground(): string {
    if (!this.bgImage) return '';
    return `linear-gradient(rgba(255, 255, 255, 0.93), rgba(255, 252, 235, 0.88)), url('${this.bgImage}') center / cover no-repeat`;
  }

  chartOptions: ChartConfiguration['options'] = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        display: true,
        position: 'bottom',
        labels: {
          font: {
            size: 12,
            weight: 'bold' as any,
          },
          color: '#0a0a0a',
          padding: 15,
          usePointStyle: true,
        },
      },
      tooltip: {
        backgroundColor: 'rgba(0, 0, 0, 0.8)',
        padding: 12,
        titleFont: {
          size: 13,
          weight: 'bold' as any,
        },
        bodyFont: {
          size: 12,
        },
        cornerRadius: 8,
        displayColors: true,
        callbacks: {
          label: (context: any) => {
            let label = context.dataset.label || '';
            if (label) label += ': ';
            if (context.parsed.y !== null) {
              label += this.formatCurrency(context.parsed.y);
            }
            return label;
          },
        },
      },
    },
    scales: {
      x: {
        grid: {
          color: 'rgba(0, 0, 0, 0.05)',
        },
        border: {
          display: false,
        },
        ticks: {
          color: '#999',
          font: {
            size: 11,
          },
        },
      },
      y: {
        beginAtZero: true,
        grid: {
          color: 'rgba(0, 0, 0, 0.05)',
        },
        border: {
          display: false,
        },
        ticks: {
          color: '#999',
          font: {
            size: 11,
          },
          callback: (value: any) => this.formatCurrency(value),
        },
      },
    },
  };

  formatCurrency(value: number): string {
    if (value >= 1000000) return '$' + (value / 1000000).toFixed(1) + 'M';
    if (value >= 1000) return '$' + (value / 1000).toFixed(0) + 'K';
    return '$' + value.toLocaleString();
  }
}
