import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, Output } from '@angular/core';

export interface Campaign {
  id: string;
  name: string;
  type: string;
  objective: string;
  segment: string;
  channel: string;
  startDate: string;
  endDate: string;
  status: string;
  message: string;
  couponCode?: string;
  estimatedReach: number;
  conversions: number;
  budget: number;
  conversionGoal: number;
  createdAt: string;
  updatedAt: string;
}

@Component({
  selector: 'app-campaign-card',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="campaign-card">
      <div class="campaign-header">
        <div class="campaign-title-section">
          <h3 class="campaign-title">{{ campaign.name }}</h3>
          <span class="campaign-type" [ngClass]="'type-' + campaign.type">{{ campaign.type }}</span>
        </div>
        <div class="campaign-actions">
          <button type="button" class="action-btn" title="Más opciones" (click)="toggleMenu()">
            <span class="material-symbols-outlined">more_vert</span>
          </button>
          <div *ngIf="menuOpen" class="action-menu">
            <button type="button" class="menu-item" (click)="onEdit()">
              <span class="material-symbols-outlined">edit</span>
              Editar
            </button>
            <button type="button" class="menu-item" (click)="onDuplicate()">
              <span class="material-symbols-outlined">content_copy</span>
              Duplicar
            </button>
            <button type="button" class="menu-item" (click)="onFinish()">
              <span class="material-symbols-outlined">done_all</span>
              Finalizar
            </button>
            <button type="button" class="menu-item danger" (click)="onDelete()">
              <span class="material-symbols-outlined">delete</span>
              Eliminar
            </button>
          </div>
        </div>
      </div>

      <div class="campaign-meta">
        <div class="meta-item">
          <span class="meta-label">Segmento</span>
          <span class="meta-value">{{ campaign.segment }}</span>
        </div>
        <div class="meta-item">
          <span class="meta-label">Canal</span>
          <span class="meta-value">{{ campaign.channel }}</span>
        </div>
        <div class="meta-item">
          <span class="meta-label">Estado</span>
          <span class="meta-status" [ngClass]="'status-' + campaign.status">{{
            campaign.status
          }}</span>
        </div>
      </div>

      <div class="campaign-dates">
        <div class="date-item">
          <span class="material-symbols-outlined">calendar_today</span>
          {{ formatDate(campaign.startDate) }} - {{ formatDate(campaign.endDate) }}
        </div>
      </div>

      <p class="campaign-message">{{ campaign.message }}</p>

      <div class="campaign-stats">
        <div class="stat">
          <span class="stat-value">{{ campaign.estimatedReach }}</span>
          <span class="stat-label">Alcance</span>
        </div>
        <div class="stat">
          <span class="stat-value">{{ campaign.conversions }}/{{ campaign.conversionGoal }}</span>
          <span class="stat-label">Conversiones</span>
        </div>
        <div class="stat" *ngIf="campaign.couponCode">
          <span class="stat-value">{{ campaign.couponCode }}</span>
          <span class="stat-label">Cupón</span>
        </div>
      </div>

      <div class="campaign-footer">
        <button type="button" class="btn-secondary" (click)="onToggleStatus()">
          <span class="material-symbols-outlined">{{
            campaign.status === 'Activa' ? 'pause' : 'play_arrow'
          }}</span>
          {{ campaign.status === 'Activa' ? 'Pausar' : 'Activar' }}
        </button>
        <button type="button" class="btn-primary" (click)="onView()">
          <span class="material-symbols-outlined">visibility</span>
          Ver detalle
        </button>
      </div>
    </div>
  `,
  styles: [
    `
      .campaign-card {
        border: 1px solid #ededed;
        border-radius: 14px;
        background: #ffffff;
        padding: 1.3rem;
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.04);
        transition: all 0.2s ease;
      }

      .campaign-card:hover {
        border-color: #d0d0d0;
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.08);
      }

      .campaign-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        margin-bottom: 1rem;
      }

      .campaign-title-section {
        flex: 1;
      }

      .campaign-title {
        font-size: 1rem;
        font-weight: 800;
        color: #0a0a0a;
        margin: 0 0 0.4rem;
      }

      .campaign-type {
        display: inline-block;
        padding: 0.35rem 0.7rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.3px;
      }

      .type-Promoción {
        background: #dbeafe;
        color: #0c4a6e;
      }

      .type-Descuento {
        background: #fef3c7;
        color: #b45309;
      }

      .type-Renovación {
        background: #d1fae5;
        color: #065f46;
      }

      .type-Reactivación {
        background: #fce7f3;
        color: #831843;
      }

      .type-Cumpleaños {
        background: #ede9fe;
        color: #5b21b6;
      }

      .type-Referidos {
        background: #fee2e2;
        color: #7f1d1d;
      }

      .type-Evento {
        background: #f3e8ff;
        color: #4c1d95;
      }

      .campaign-actions {
        position: relative;
      }

      .action-btn {
        width: 32px;
        height: 32px;
        border: none;
        border-radius: 8px;
        background: #fafafa;
        color: #666;
        cursor: pointer;
        transition: all 0.15s ease;
      }

      .action-btn:hover {
        background: #f3f3f3;
        color: #0a0a0a;
      }

      .action-menu {
        position: absolute;
        top: 100%;
        right: 0;
        background: #ffffff;
        border: 1px solid #ededed;
        border-radius: 10px;
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.12);
        min-width: 150px;
        z-index: 10;
        margin-top: 0.4rem;
      }

      .menu-item {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        width: 100%;
        padding: 0.7rem 1rem;
        border: none;
        background: transparent;
        color: #0a0a0a;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s ease;
        text-align: left;
      }

      .menu-item:first-child {
        border-radius: 10px 10px 0 0;
      }

      .menu-item:last-child {
        border-radius: 0 0 10px 10px;
      }

      .menu-item:hover {
        background: #f9f9f9;
      }

      .menu-item.danger {
        color: #dc2626;
      }

      .menu-item.danger:hover {
        background: #fee2e2;
      }

      .campaign-meta {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.8rem;
        margin-bottom: 0.9rem;
        padding-bottom: 0.9rem;
        border-bottom: 1px solid #f3f3f3;
      }

      .meta-item {
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
      }

      .meta-label {
        font-size: 0.75rem;
        font-weight: 700;
        color: #999;
        text-transform: uppercase;
        letter-spacing: 0.3px;
      }

      .meta-value {
        font-size: 0.85rem;
        font-weight: 600;
        color: #0a0a0a;
      }

      .meta-status {
        display: inline-block;
        padding: 0.3rem 0.6rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        width: fit-content;
      }

      .status-Activa {
        background: #d1fae5;
        color: #065f46;
      }

      .status-Programada {
        background: #dbeafe;
        color: #0c4a6e;
      }

      .status-Borrador {
        background: #f3f4f6;
        color: #374151;
      }

      .status-Pausada {
        background: #fef3c7;
        color: #b45309;
      }

      .status-Finalizada {
        background: #e5e7eb;
        color: #6b7280;
      }

      .campaign-dates {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.8rem;
        color: #666;
        margin-bottom: 0.8rem;
      }

      .date-item {
        display: flex;
        align-items: center;
        gap: 0.4rem;
      }

      .campaign-message {
        font-size: 0.85rem;
        line-height: 1.5;
        color: #666;
        margin: 0 0 1rem;
        padding: 0.8rem;
        background: #fafafa;
        border-radius: 8px;
        border-left: 3px solid #fbbf24;
      }

      .campaign-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.8rem;
        margin-bottom: 1rem;
        padding: 0.8rem;
        background: #f9f9f9;
        border-radius: 10px;
      }

      .stat {
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
      }

      .stat-value {
        font-size: 0.95rem;
        font-weight: 800;
        color: #0a0a0a;
      }

      .stat-label {
        font-size: 0.75rem;
        color: #999;
        font-weight: 600;
      }

      .campaign-footer {
        display: flex;
        gap: 0.6rem;
      }

      .btn-secondary,
      .btn-primary {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        padding: 0.6rem;
        border-radius: 10px;
        font-size: 0.8rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.15s ease;
        border: 1px solid transparent;
      }

      .btn-secondary {
        background: #f3f3f3;
        color: #0a0a0a;
        border-color: #e5e5e5;
      }

      .btn-secondary:hover {
        background: #e5e5e5;
      }

      .btn-primary {
        background: #fbbf24;
        color: #0a0a0a;
        box-shadow: 0 6px 12px rgba(251, 191, 36, 0.15);
      }

      .btn-primary:hover {
        background: #f9a825;
        box-shadow: 0 8px 16px rgba(251, 191, 36, 0.2);
      }

      @media (max-width: 768px) {
        .campaign-meta {
          grid-template-columns: 1fr;
        }

        .campaign-stats {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export default class CampaignCardComponent {
  @Input() campaign!: Campaign;

  @Output() view = new EventEmitter<Campaign>();
  @Output() edit = new EventEmitter<Campaign>();
  @Output() duplicate = new EventEmitter<Campaign>();
  @Output() toggleStatus = new EventEmitter<Campaign>();
  @Output() finish = new EventEmitter<Campaign>();
  @Output() delete = new EventEmitter<Campaign>();

  menuOpen = false;

  toggleMenu(): void {
    this.menuOpen = !this.menuOpen;
  }

  onView(): void {
    this.view.emit(this.campaign);
  }

  onEdit(): void {
    this.menuOpen = false;
    this.edit.emit(this.campaign);
  }

  onDuplicate(): void {
    this.menuOpen = false;
    this.duplicate.emit(this.campaign);
  }

  onToggleStatus(): void {
    this.toggleStatus.emit(this.campaign);
  }

  onFinish(): void {
    this.menuOpen = false;
    this.finish.emit(this.campaign);
  }

  onDelete(): void {
    this.menuOpen = false;
    this.delete.emit(this.campaign);
  }

  formatDate(date: string): string {
    if (!date) return '';
    const d = new Date(date);
    return `${d.getDate()}/${d.getMonth() + 1}/${d.getFullYear()}`;
  }
}
