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
    backgroundColor?: string | string[] | ((context: any) => string | CanvasGradient);
    hoverBackgroundColor?: string | string[] | ((context: any) => string | CanvasGradient);
    hoverBorderColor?: string | string[] | ((context: any) => string);
    borderRadius?: number;
    borderSkipped?: boolean;
    barPercentage?: number;
    categoryPercentage?: number;
    maxBarThickness?: number;
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
          <h3 class="chart-title">
            <span>{{ title }}</span>
            <span *ngIf="stats" class="chart-badge">
              <span class="material-symbols-outlined" aria-hidden="true">trending_up</span>
              {{ formatValue(stats.avg) }}
            </span>
          </h3>
          <p class="chart-subtitle">{{ subtitle }}</p>
        </div>
        <div class="chart-stats" *ngIf="stats">
          <div class="stat">
            <span class="stat-label">Máximo</span>
            <span class="stat-value">{{ formatValue(stats.max) }}</span>
          </div>
          <div class="stat">
            <span class="stat-label">Mínimo</span>
            <span class="stat-value">{{ formatValue(stats.min) }}</span>
          </div>
          <div class="stat">
            <span class="stat-label">Promedio</span>
            <span class="stat-value">{{ formatValue(stats.avg) }}</span>
          </div>
        </div>
      </div>

      <div class="chart-content">
        <div class="chart-container">
          <canvas
            baseChart
            [data]="styledChartData"
            [options]="chartOptions"
            [plugins]="chartPlugins"
            [type]="type"
          ></canvas>
        </div>
      </div>
    </div>
  `,
  styles: [
    `
      .chart-card {
        background: #ffffff;
        border: 1px solid #e5e5e5;
        border-radius: 8px;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 0;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        overflow: hidden;
      }

      .chart-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1.5rem;
        flex-wrap: wrap;
        padding: 1.5rem 1.5rem 0.75rem;
      }

      .chart-title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
        font-size: 1.5rem;
        font-weight: 600;
        color: #0a0a0a;
        line-height: 1;
        letter-spacing: 0;
        margin: 0 0 0.5rem;
      }

      .chart-subtitle {
        font-size: 0.875rem;
        color: #71717a;
        margin: 0;
      }

      .chart-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        border: 0;
        border-radius: 999px;
        background: rgba(34, 197, 94, 0.1);
        color: #22c55e;
        padding: 0.2rem 0.55rem;
        font-size: 0.75rem;
        font-weight: 700;
        line-height: 1;
      }

      .chart-badge .material-symbols-outlined {
        font-size: 1rem;
      }

      .chart-stats {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 0.5rem;
        flex-wrap: wrap;
      }

      .stat {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.4rem 0.55rem;
        background: #ffffff;
        border-radius: 999px;
        border: 1px solid #e5e5e5;
      }

      .stat-label {
        font-size: 0.72rem;
        color: #71717a;
        text-transform: none;
        font-weight: 650;
        letter-spacing: 0;
      }

      .stat-value {
        font-size: 0.78rem;
        font-weight: 700;
        color: #0a0a0a;
      }

      .chart-content {
        padding: 0 1.5rem 1.5rem;
      }

      .chart-container {
        position: relative;
        height: 350px;
        border-radius: 12px;
        overflow: hidden;
      }

      .chart-card {
        background: #1c1b1b !important;
        border-color: #353534;
        box-shadow: 0 18px 44px rgba(0, 0, 0, 0.20);
      }

      .chart-title,
      .stat-value {
        color: #e5e2e1;
      }

      .chart-subtitle,
      .stat-label {
        color: #b4afa6;
      }

      .stat {
        background: #151515;
        border-color: #353534;
      }

      .chart-badge {
        background: rgba(245, 197, 24, 0.13);
        color: #ffe08b;
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
  @Input() valueType: 'currency' | 'number' | 'percent' = 'currency';
  @Input() bgImage: string = '';

  private readonly barColor = '#fbbf24';
  private readonly mutedBarColor = 'rgba(251, 191, 36, 0.3)';

  get cardBackground(): string {
    if (!this.bgImage) return '';
    return `linear-gradient(rgba(28, 27, 27, 0.92), rgba(17, 17, 17, 0.90)), url('${this.bgImage}') center / cover no-repeat`;
  }

  get styledChartData(): ChartData {
    if (this.type !== 'bar') return this.chartData;

    return {
      ...this.chartData,
      datasets: this.chartData.datasets.map((dataset) => ({
        ...dataset,
        borderRadius: 12,
        borderSkipped: false,
        barPercentage: 0.55,
        categoryPercentage: 0.7,
        maxBarThickness: 54,
        borderWidth: 0,
        borderColor: 'transparent',
        hoverBorderColor: '#f59e0b',
        backgroundColor: (context: any) => {
          const activeIndex = context.chart.getActiveElements()?.[0]?.index;
          const isMuted = activeIndex !== undefined && activeIndex !== context.dataIndex;
          return this.barGradient(context, isMuted);
        },
        hoverBackgroundColor: (context: any) => this.barGradient(context, false),
      })),
    };
  }

  private barGradient(context: any, muted: boolean): string | CanvasGradient {
    const chart = context.chart;
    const { ctx, chartArea } = chart;

    if (!chartArea) return muted ? this.mutedBarColor : this.barColor;

    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
    if (muted) {
      gradient.addColorStop(0, 'rgba(251, 191, 36, 0.34)');
      gradient.addColorStop(1, 'rgba(245, 158, 11, 0.2)');
      return gradient;
    }

    gradient.addColorStop(0, '#fde68a');
    gradient.addColorStop(0.45, '#fbbf24');
    gradient.addColorStop(1, '#f59e0b');
    return gradient;
  }

  chartPlugins = [
    {
      id: 'dottedBarBackground',
      beforeDatasetsDraw: (chart: any) => {
        if (chart.config.type !== 'bar') return;
        const { ctx, chartArea } = chart;
        if (!chartArea) return;

        ctx.save();
        ctx.fillStyle = 'rgba(156, 163, 175, 0.18)';
        for (let x = chartArea.left; x < chartArea.right; x += 10) {
          for (let y = chartArea.top; y < chartArea.bottom; y += 10) {
            ctx.beginPath();
            ctx.arc(x + 2, y + 2, 1, 0, Math.PI * 2);
            ctx.fill();
          }
        }
        ctx.restore();
      },
    },
  ];

  chartOptions: ChartConfiguration['options'] = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: {
      mode: 'nearest',
      intersect: true,
    },
    plugins: {
      legend: {
        display: true,
        position: 'bottom',
        labels: {
          font: {
            size: 12,
            weight: 'bold' as any,
          },
          color: '#d1c5ac',
          padding: 15,
          usePointStyle: true,
        },
      },
      tooltip: {
        backgroundColor: '#151515',
        borderColor: 'rgba(245, 197, 24, 0.24)',
        borderWidth: 1,
        titleColor: '#e5e2e1',
        bodyColor: '#e5e2e1',
        padding: 10,
        titleFont: {
          size: 13,
          weight: 'bold' as any,
        },
        bodyFont: {
          size: 12,
        },
        cornerRadius: 10,
        displayColors: true,
        boxPadding: 4,
        callbacks: {
          label: (context: any) => {
            let label = context.dataset.label || '';
            if (label) label += ': ';
            const value = context.parsed?.y ?? context.parsed;
            if (value !== null && value !== undefined) {
              label += this.formatValue(value);
            }
            return label;
          },
        },
      },
    },
    scales: {
      x: {
        grid: {
          display: false,
        },
        border: {
          display: false,
        },
        ticks: {
          color: '#b4afa6',
          font: {
            size: 11,
          },
        },
      },
      y: {
        beginAtZero: true,
        grid: {
          color: 'rgba(78, 70, 51, 0.75)',
        },
        border: {
          display: false,
        },
        ticks: {
          color: '#b4afa6',
          font: {
            size: 11,
          },
          callback: (value: any) => this.formatValue(Number(value)),
        },
      },
    },
  };

  formatCurrency(value: number): string {
    if (value >= 1000000) return '$' + (value / 1000000).toFixed(1) + 'M';
    if (value >= 1000) return '$' + (value / 1000).toFixed(0) + 'K';
    return '$' + value.toLocaleString();
  }

  formatValue(value: number): string {
    if (this.valueType === 'currency') return this.formatCurrency(value);
    if (this.valueType === 'percent') return `${Number(value || 0).toFixed(0)}%`;
    return new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(value || 0);
  }
}
