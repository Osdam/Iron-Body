import { Component, Input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';
import { PlanSummary } from '../../services/api.service';
import { LottieIconComponent } from '../../shared/components/lottie-icon/lottie-icon.component';

export type BadgeType =
  | 'active'
  | 'inactive'
  | 'featured'
  | 'recommended'
  | 'bestseller'
  | 'new'
  | 'premium';

export interface PlanCardData extends PlanSummary {
  badge?: BadgeType;
  estimatedMembers?: number;
  estimatedIncome?: number;
  description?: string;
  cardBenefits?: string[];
  billingCycle?: string;
}

@Component({
  selector: 'app-plan-card',
  standalone: true,
  imports: [CommonModule, LottieIconComponent],
  template: `
    <div class="plan-card" [class.featured]="isFeatured()">
      <!-- Badge de estado -->
      <div class="card-badges">
        <span class="status-badge" [class]="'status-' + (plan.active ? 'active' : 'inactive')">
          <i></i>
          {{ plan.active ? 'Activo' : 'Inactivo' }}
        </span>
        <span
          *ngIf="plan.badge && plan.badge !== 'active' && plan.badge !== 'inactive'"
          class="feature-badge"
          [class]="'badge-' + plan.badge"
        >
          {{ getBadgeLabel(plan.badge) }}
        </span>
      </div>

      <!-- Icono del plan -->
      <div class="card-icon" [class.featured]="isFeatured()">
        <app-lottie-icon src="/assets/crm/gym.json" [size]="44" [loop]="true"></app-lottie-icon>
      </div>

      <!-- Nombre del plan -->
      <h3 class="plan-name">{{ plan.name }}</h3>

      <!-- Descripción -->
      <p class="plan-description">
        {{ plan.description || plan.benefits || 'Plan de membresía para acceso al gimnasio' }}
      </p>

      <!-- Precio protagonista -->
      <div class="price-section">
        <div class="price-display">
          <span class="currency">$</span>
          <strong class="amount">{{ formatNumber(plan.price) }}</strong>
          <span class="period">{{ plan.billingCycle || 'mes' }}</span>
        </div>
      </div>

      <!-- Detalle de duración -->
      <div class="plan-details">
        <div class="detail-item">
          <span class="detail-icon material-symbols-outlined">schedule</span>
          <div>
            <span class="detail-label">Duración</span>
            <span class="detail-value">{{ getDurationLabel(plan.duration_days) }}</span>
          </div>
        </div>
        <div class="detail-item">
          <span class="detail-icon material-symbols-outlined">group</span>
          <div>
            <span class="detail-label">Miembros</span>
            <span class="detail-value">{{ plan.estimatedMembers || 0 }}</span>
          </div>
        </div>
        <div class="detail-item">
          <span class="detail-icon material-symbols-outlined">trending_up</span>
          <div>
            <span class="detail-label">Ingreso mes</span>
            <span class="detail-value">{{ formatCurrency(plan.estimatedIncome || 0) }}</span>
          </div>
        </div>
      </div>

      <!-- Beneficios -->
      <div class="benefits-section">
        <h4 class="benefits-title">Incluye:</h4>
        <ul class="benefits-list">
          <li *ngFor="let benefit of getPlanBenefits()">
            <span class="benefit-icon material-symbols-outlined">check_circle</span>
            <span>{{ benefit }}</span>
          </li>
        </ul>
      </div>

      <!-- Acciones administrativas -->
      <div class="card-actions">
        <button class="action-btn primary" (click)="onEdit.emit(plan)" title="Editar plan">
          <span class="material-symbols-outlined">edit</span>
          <span>Editar</span>
        </button>
        <button class="action-btn" (click)="onViewMembers.emit(plan)" title="Ver miembros">
          <span class="material-symbols-outlined">group</span>
          <span>Miembros</span>
        </button>
        <button class="action-btn" (click)="onDuplicate.emit(plan)" title="Duplicar plan">
          <span class="material-symbols-outlined">content_copy</span>
          <span>Duplicar</span>
        </button>
        <button
          class="action-btn danger"
          (click)="onToggleStatus.emit(plan)"
          title="Cambiar estado"
        >
          <span class="material-symbols-outlined">{{
            plan.active ? 'visibility_off' : 'visibility'
          }}</span>
          <span>{{ plan.active ? 'Desactivar' : 'Activar' }}</span>
        </button>
        <button class="action-btn danger" (click)="onDelete.emit(plan)" title="Eliminar plan">
          <span class="material-symbols-outlined">delete</span>
          <span>Eliminar</span>
        </button>
      </div>
    </div>
  `,
  styles: [
    `
      .plan-card {
        position: relative;
        display: flex;
        flex-direction: column;
        min-width: 0;
        background:
          linear-gradient(rgba(255, 255, 255, 0.88), rgba(255, 252, 230, 0.82)),
          url('/assets/crm/cardspalnes.png') center / cover no-repeat;
        border: 1px solid #e5e5e5;
        border-radius: 14px;
        padding: 2rem;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
        transition: all 300ms cubic-bezier(0.4, 0, 0.2, 1);
        overflow: hidden;
      }

      .plan-card::before {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(
          135deg,
          rgba(250, 204, 21, 0) 0%,
          rgba(250, 204, 21, 0.02) 100%
        );
        pointer-events: none;
        opacity: 0;
        transition: opacity 300ms ease;
      }

      .plan-card:hover {
        border-color: #d0d0d0;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
        transform: translateY(-4px);
      }

      .plan-card:hover::before {
        opacity: 1;
      }

      .plan-card.featured {
        border: 2px solid #facc15;
        box-shadow: 0 8px 32px rgba(250, 204, 21, 0.15);
      }

      .plan-card.featured:hover {
        box-shadow: 0 12px 48px rgba(250, 204, 21, 0.25);
      }

      .card-badges {
        position: relative;
        z-index: 1;
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        justify-content: flex-end;
        align-self: flex-end;
        max-width: 100%;
        margin-bottom: 1rem;
      }

      .status-badge,
      .feature-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.5rem 0.875rem;
        border-radius: 8px;
        font-size: 0.7rem;
        font-weight: 600;
        font-family: Inter, sans-serif;
        white-space: nowrap;
        text-transform: uppercase;
        letter-spacing: 0.05em;
      }

      .status-badge i {
        display: inline-block;
        width: 0.45rem;
        height: 0.45rem;
        border-radius: 50%;
        background: currentColor;
      }

      .status-active {
        background: #ecfdf5;
        color: #047857;
      }

      .status-inactive {
        background: #f5f5f5;
        color: #737373;
      }

      .feature-badge {
        background: rgba(250, 204, 21, 0.1);
        color: #ca8a04;
        gap: 0.25rem;
        padding: 0.4rem 0.75rem;
      }

      .badge-featured,
      .badge-recommended,
      .badge-bestseller {
        background: linear-gradient(
          135deg,
          rgba(250, 204, 21, 0.15) 0%,
          rgba(250, 204, 21, 0.05) 100%
        );
        color: #ca8a04;
      }

      .badge-new {
        background: #dbeafe;
        color: #0369a1;
      }

      .badge-premium {
        background: #fce7f3;
        color: #be185d;
      }

      .card-icon {
        position: relative;
        z-index: 1;
        display: grid;
        place-items: center;
        width: 64px;
        height: 64px;
        border-radius: 14px;
        background: rgba(255, 255, 255, 0.85);
        margin-bottom: 1.25rem;
        overflow: hidden;
        transition: all 300ms ease;
      }

      .card-icon.featured {
        background: rgba(250, 204, 21, 0.18);
      }

      .plan-card:hover .card-icon {
        transform: scale(1.06);
      }

      .plan-name {
        font-family: Inter, sans-serif;
        font-size: clamp(1.2rem, 2vw, 1.5rem);
        font-weight: 700;
        color: #0a0a0a;
        margin: 0 0 0.5rem;
        letter-spacing: -0.01em;
        line-height: 1.2;
        overflow-wrap: anywhere;
      }

      .plan-description {
        color: #666;
        font-size: 0.9rem;
        line-height: 1.6;
        margin: 0 0 1.5rem;
        min-height: 2.7em;
      }

      .price-section {
        margin-bottom: 1.75rem;
        padding-bottom: 1.75rem;
        border-bottom: 2px solid #f0f0f0;
      }

      .price-display {
        display: flex;
        align-items: baseline;
        gap: 0.5rem;
        flex-wrap: wrap;
        min-width: 0;
      }

      .currency {
        font-size: 1.2rem;
        color: #0a0a0a;
        font-weight: 600;
      }

      .amount {
        font-size: clamp(1.8rem, 5vw, 3rem);
        color: #0a0a0a;
        line-height: 1;
        letter-spacing: -0.02em;
        font-family: Inter, sans-serif;
        overflow-wrap: anywhere;
      }

      .period {
        color: #666;
        font-size: 0.95rem;
        font-weight: 500;
      }

      .plan-details {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 1rem;
        margin-bottom: 1.75rem;
        padding: 1.25rem;
        background: #f9f9f9;
        border-radius: 10px;
      }

      .detail-item {
        display: flex;
        gap: 0.75rem;
        align-items: flex-start;
        min-width: 0;
      }

      .detail-icon {
        font-size: 1.2rem;
        color: #666;
        flex-shrink: 0;
        margin-top: 0.15rem;
      }

      .detail-label {
        display: block;
        font-size: 0.75rem;
        color: #999;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 600;
        margin-bottom: 0.25rem;
      }

      .detail-value {
        display: block;
        font-size: 0.95rem;
        color: #0a0a0a;
        font-weight: 600;
        overflow-wrap: anywhere;
      }

      .benefits-section {
        margin-bottom: 1.75rem;
        flex: 1;
      }

      .benefits-title {
        font-size: 0.85rem;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 600;
        margin: 0 0 0.75rem;
        font-family: 'Space Grotesk', sans-serif;
      }

      .benefits-list {
        display: grid;
        gap: 0.6rem;
        list-style: none;
        margin: 0;
        padding: 0;
      }

      .benefits-list li {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        font-size: 0.9rem;
        color: #555;
        transition: color 200ms ease;
        min-width: 0;
        overflow-wrap: anywhere;
      }

      .benefits-list li:hover {
        color: #0a0a0a;
      }

      .benefit-icon {
        font-size: 1rem;
        color: #facc15;
        flex-shrink: 0;
      }

      .card-actions {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(86px, 1fr));
        gap: 0.5rem;
        padding-top: 1.5rem;
        border-top: 1px solid #f0f0f0;
      }

      .action-btn {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        padding: 0.75rem 0.5rem;
        border: 1px solid #e5e5e5;
        border-radius: 8px;
        background: #fff;
        color: #666;
        font-size: 0.7rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 200ms ease;
        font-family: Inter, sans-serif;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        min-width: 0;
      }

      .action-btn span:last-child {
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .action-btn span:first-child {
        font-size: 1rem;
      }

      .action-btn:hover {
        border-color: #d0d0d0;
        background: #f9f9f9;
        color: #0a0a0a;
      }

      .action-btn.primary {
        border-color: #facc15;
        background: #facc15;
        color: #000;
      }

      .action-btn.primary:hover {
        background: #f0c00e;
        border-color: #f0c00e;
        box-shadow: 0 2px 8px rgba(250, 204, 21, 0.3);
      }

      .action-btn.danger {
        color: #dc2626;
      }

      .action-btn.danger:hover {
        border-color: #fecaca;
        background: #fee2e2;
        color: #991b1b;
      }

      @media (max-width: 1200px) {
        .plan-details {
          grid-template-columns: 1fr;
          gap: 0.75rem;
        }

        .card-actions {
          grid-template-columns: repeat(3, 1fr);
        }

        .action-btn {
          flex-direction: row;
          padding: 0.65rem 0.75rem;
        }

        .action-btn span:first-child {
          font-size: 0.9rem;
        }

        .action-btn span:last-child {
          display: none;
        }
      }

      @media (max-width: 768px) {
        .plan-card {
          padding: 1.5rem 1.25rem;
        }

        .plan-name {
          font-size: 1.3rem;
        }

        .amount {
          font-size: 2.2rem;
        }

        .card-actions {
          grid-template-columns: repeat(2, 1fr);
        }

        .plan-details {
          gap: 0.5rem;
          padding: 1rem;
        }

        .detail-item {
          gap: 0.5rem;
        }
      }

      @media (max-width: 480px) {
        .plan-card {
          padding: 1.25rem 1rem;
        }

        .price-section {
          margin-bottom: 1.25rem;
          padding-bottom: 1.25rem;
        }

        .plan-details {
          margin-bottom: 1.25rem;
          padding: 0.875rem;
        }

        .amount {
          font-size: 1.8rem;
        }

        .card-actions {
          grid-template-columns: 1fr;
          gap: 0.4rem;
        }

        .action-btn {
          flex-direction: row;
          justify-content: flex-start;
          padding: 0.6rem;
        }

        .action-btn span:last-child {
          display: inline;
        }

        .action-btn span:first-child {
          font-size: 0.9rem;
        }
      }
    `,
  ],
})
export class PlanCardComponent {
  @Input() plan: PlanCardData = {} as PlanCardData;
  @Output() onEdit = new EventEmitter<PlanCardData>();
  @Output() onViewMembers = new EventEmitter<PlanCardData>();
  @Output() onDuplicate = new EventEmitter<PlanCardData>();
  @Output() onToggleStatus = new EventEmitter<PlanCardData>();
  @Output() onDelete = new EventEmitter<PlanCardData>();

  isFeatured(): boolean {
    return (
      this.plan.badge === 'featured' ||
      this.plan.badge === 'recommended' ||
      this.plan.badge === 'bestseller'
    );
  }

  getPlanIcon(): string {
    const icons: Record<string, string> = {
      vip: 'diamond',
      student: 'school',
      premium: 'verified',
      featured: 'diamond',
    };
    return icons[this.plan.badge || ''] || 'fitness_center';
  }

  getBadgeLabel(badge: BadgeType): string {
    const labels: Record<BadgeType, string> = {
      active: 'Activo',
      inactive: 'Inactivo',
      featured: 'Destacado',
      recommended: 'Recomendado',
      bestseller: 'Más vendido',
      new: 'Nuevo',
      premium: 'Premium',
    };
    return labels[badge] || '';
  }

  getDurationLabel(days: number): string {
    if (days >= 360) return 'Anual (365 días)';
    if (days >= 180) return 'Semestral (180 días)';
    if (days >= 90) return 'Trimestral (90 días)';
    if (days >= 28 && days <= 31) return 'Mensual (30 días)';
    return `${days} días`;
  }

  getPlanBenefits(): string[] {
    if (this.plan.cardBenefits && Array.isArray(this.plan.cardBenefits)) {
      return this.plan.cardBenefits;
    }
    if (typeof this.plan.benefits === 'string') {
      return this.plan.benefits
        .split(',')
        .map((b: string) => b.trim())
        .filter(Boolean);
    }
    // Beneficios por defecto según el plan
    const defaultBenefits: Record<string, string[]> = {
      VIP: [
        'Entrenador personalizado',
        'Rutina avanzada',
        'Seguimiento mensual',
        'Acceso a lockers',
      ],
      Anual: ['Acceso ilimitado', 'Clases grupales', 'Rutina personalizada', 'Valoración física'],
      Trimestral: ['Acceso libre', 'Clases grupales', 'Valoración física'],
      Mensual: ['Acceso libre', 'Clases grupales', 'Rutina básica'],
    };
    return defaultBenefits[this.plan.name] || ['Acceso al gimnasio', 'Clases grupales'];
  }

  formatNumber(num: number): string {
    if (num >= 1000000) return (num / 1000000).toFixed(1).replace('.0', '') + 'M';
    if (num >= 1000) return (num / 1000).toFixed(0) + 'K';
    return num.toLocaleString('es-CO');
  }

  formatCurrency(num: number): string {
    if (num >= 1000000) return '$' + (num / 1000000).toFixed(1).replace('.0', '') + 'M';
    if (num >= 1000) return '$' + (num / 1000).toFixed(0) + 'K';
    return '$' + num.toLocaleString('es-CO');
  }
}
