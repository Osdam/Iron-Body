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
      <div class="rotating-border" aria-hidden="true"></div>

      <div *ngIf="isFeatured()" class="popular-pill">Recomendado</div>

      <div class="card-shell">
        <div class="pricing-head">
          <div class="plan-identity">
            <div class="card-icon" [class.featured]="isFeatured()">
              <app-lottie-icon src="/assets/crm/gym.json" [size]="34" [loop]="true"></app-lottie-icon>
            </div>
            <div class="identity-copy">
              <h3 class="plan-name">{{ plan.name }}</h3>
              <p class="plan-description">
                {{ plan.description || plan.benefits || 'Membresía de acceso al gimnasio' }}
              </p>
            </div>
          </div>
          <span class="status-dot" [class.active]="plan.active" [class.inactive]="!plan.active"></span>
        </div>

        <div class="price-section">
          <div class="price-display">
            <span class="currency">$</span>
            <strong class="amount">{{ formatNumber(plan.price) }}</strong>
            <span class="period">/ {{ plan.billingCycle || 'mes' }}</span>
          </div>
          <p class="price-note">{{ getDurationLabel(plan.duration_days) }}</p>
        </div>

        <ul class="benefits-list">
          <li *ngFor="let benefit of getPlanBenefits()">
            <span class="benefit-check material-symbols-outlined">check</span>
            <span>{{ benefit }}</span>
          </li>
        </ul>

        <div class="plan-metrics">
          <div class="metric-item">
            <span class="material-symbols-outlined">group</span>
            <strong>{{ plan.estimatedMembers || 0 }}</strong>
            <small>Miembros</small>
          </div>
          <div class="metric-item">
            <span class="material-symbols-outlined">trending_up</span>
            <strong>{{ formatCurrency(plan.estimatedIncome || 0) }}</strong>
            <small>Ingreso mes</small>
          </div>
        </div>

        <button class="primary-cta" (click)="onEdit.emit(plan)" title="Editar plan">
          <span class="material-symbols-outlined">edit</span>
          <span>Editar</span>
        </button>

        <div class="card-actions">
          <button class="action-btn" (click)="onViewMembers.emit(plan)" title="Ver miembros">
            <span class="material-symbols-outlined">group</span>
            <span>Miembros</span>
          </button>
          <button class="action-btn" (click)="onDuplicate.emit(plan)" title="Duplicar plan">
            <span class="material-symbols-outlined">content_copy</span>
            <span>Duplicar</span>
          </button>
          <button class="action-btn" (click)="onToggleStatus.emit(plan)" title="Cambiar estado">
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
    </div>
  `,
  styles: [
    `
      :host {
        display: block;
        align-self: start;
      }

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

      /* Dark CRM skin */
      .plan-card {
        background:
          radial-gradient(circle at 88% 40%, rgba(24, 24, 27, 1) 0, transparent 42%),
          radial-gradient(circle at 0% 64%, rgba(117, 86, 12, 0.82) 0, transparent 36%),
          radial-gradient(circle at 41% 94%, rgba(245, 197, 24, 0.28) 0, transparent 40%),
          radial-gradient(circle at 100% 99%, rgba(94, 82, 0, 0.55) 0, transparent 42%),
          linear-gradient(145deg, rgba(15, 15, 15, 0.98), rgba(28, 27, 27, 0.92)),
          url('/assets/crm/cardspalnes.png') center / cover no-repeat;
        border-color: rgba(245, 197, 24, 0.14);
        box-shadow:
          inset 0 -16px 24px rgba(255, 255, 255, 0.08),
          0 18px 42px rgba(0, 0, 0, 0.34);
      }

      .plan-card::before {
        background:
          linear-gradient(90deg, rgba(245, 197, 24, 0.42), transparent 48%),
          linear-gradient(135deg, rgba(255, 255, 255, 0.03), transparent);
        height: 2px;
        bottom: auto;
        opacity: 1;
      }

      .plan-card:hover {
        border-color: #f5c518;
        box-shadow: 0 0 18px rgba(245, 197, 24, 0.14), 0 18px 42px rgba(0, 0, 0, 0.4);
      }

      .plan-card.featured {
        border-color: #f5c518;
        box-shadow:
          inset 0 -16px 24px rgba(255, 255, 255, 0.09),
          0 0 20px rgba(245, 197, 24, 0.16),
          0 18px 42px rgba(0, 0, 0, 0.38);
      }

      .plan-name,
      .currency,
      .amount,
      .detail-value {
        color: #e5e2e1;
      }

      .plan-description,
      .period,
      .benefits-title,
      .benefits-list li {
        color: #b4afa6;
      }

      .price-section,
      .card-actions {
        border-color: #353534;
      }

      .status-active,
      .feature-badge,
      .badge-featured,
      .badge-recommended,
      .badge-bestseller {
        background: rgba(245, 197, 24, 0.14);
        border: 1px solid rgba(245, 197, 24, 0.28);
        color: #ffe08b;
      }

      .status-inactive {
        background: rgba(180, 181, 181, 0.12);
        border: 1px solid rgba(180, 181, 181, 0.24);
        color: #c6c6c7;
      }

      .badge-new {
        background: rgba(158, 197, 255, 0.14);
        color: #d6e3ff;
      }

      .badge-premium {
        background: rgba(255, 224, 139, 0.14);
        color: #ffe08b;
      }

      .card-icon,
      .plan-details {
        background: rgba(42, 42, 42, 0.82);
        border: 1px solid #353534;
      }

      .card-icon.featured {
        background: rgba(245, 197, 24, 0.16);
        border-color: rgba(245, 197, 24, 0.28);
      }

      .detail-icon,
      .benefit-icon {
        color: #ffe08b;
      }

      .detail-label {
        color: #d1c5ac;
      }

      .benefits-list li:hover {
        color: #e5e2e1;
      }

      .action-btn {
        background: #1a1a1a;
        border-color: #353534;
        color: #d1c5ac;
      }

      .action-btn:hover {
        background: #2a2a2a;
        border-color: #f5c518;
        color: #ffe08b;
      }

      .action-btn.primary {
        background: #f5c518;
        border-color: #f5c518;
        color: #241a00;
      }

      .action-btn.primary:hover {
        background: #ffd43b;
        border-color: #ffd43b;
      }

      .action-btn.danger {
        color: #ffb4ab;
      }

      .action-btn.danger:hover {
        background: rgba(255, 180, 171, 0.1);
        border-color: rgba(255, 180, 171, 0.35);
        color: #ffdad6;
      }

      /* Pricing reference layout */
      .plan-card {
        isolation: isolate;
        min-height: 0;
        height: fit-content;
        align-self: start;
        margin-top: 0.7rem;
        padding: 0;
        border: 0;
        border-radius: 16px;
        background:
          radial-gradient(at 88% 40%, rgba(15, 15, 15, 1) 0, transparent 85%),
          radial-gradient(at 49% 30%, rgba(15, 15, 15, 1) 0, transparent 85%),
          radial-gradient(at 14% 26%, rgba(20, 19, 19, 1) 0, transparent 85%),
          radial-gradient(at 0% 64%, rgba(116, 91, 0, 0.92) 0, transparent 75%),
          radial-gradient(at 41% 94%, rgba(245, 197, 24, 0.45) 0, transparent 78%),
          radial-gradient(at 100% 99%, rgba(255, 224, 139, 0.26) 0, transparent 72%),
          #0f0f0f;
        box-shadow:
          inset 0 -16px 24px rgba(255, 255, 255, 0.14),
          0 18px 45px rgba(0, 0, 0, 0.42);
        overflow: visible;
      }

      .plan-card::before {
        display: none;
      }

      .plan-card:hover {
        transform: translateY(-4px);
        box-shadow:
          inset 0 -16px 24px rgba(255, 255, 255, 0.16),
          0 0 0 1px rgba(245, 197, 24, 0.16),
          0 24px 54px rgba(0, 0, 0, 0.48);
      }

      .plan-card.featured {
        border: 0;
        box-shadow:
          inset 0 -16px 24px rgba(255, 255, 255, 0.18),
          0 0 26px rgba(245, 197, 24, 0.18),
          0 24px 54px rgba(0, 0, 0, 0.48);
      }

      .rotating-border {
        position: absolute;
        inset: 0;
        z-index: 3;
        overflow: hidden;
        border-radius: 16px;
        pointer-events: none;
        padding: 1px;
        background: rgba(245, 197, 24, 0.1);
        -webkit-mask:
          linear-gradient(#000 0 0) content-box,
          linear-gradient(#000 0 0);
        -webkit-mask-composite: xor;
        mask-composite: exclude;
      }

      .rotating-border::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 200%;
        height: 10rem;
        transform: translate(-50%, -50%) rotate(0deg);
        transform-origin: left;
        background: linear-gradient(
          0deg,
          rgba(245, 197, 24, 0) 0%,
          rgba(245, 197, 24, 0.9) 42%,
          rgba(255, 229, 160, 0.92) 58%,
          rgba(245, 197, 24, 0) 100%
        );
        animation: pricingRotate 8s linear infinite;
      }

      @keyframes pricingRotate {
        to { transform: translate(-50%, -50%) rotate(360deg); }
      }

      .card-shell {
        position: relative;
        z-index: 1;
        display: flex;
        flex-direction: column;
        height: auto;
        box-sizing: border-box;
        padding: 1.45rem;
        border-radius: 16px;
        background:
          linear-gradient(180deg, rgba(19, 19, 19, 0.88), rgba(14, 14, 14, 0.94)),
          radial-gradient(circle at 80% 0%, rgba(245, 197, 24, 0.14), transparent 36%);
        border: 1px solid rgba(245, 197, 24, 0.12);
        box-shadow:
          inset 0 0 0 1px rgba(245, 197, 24, 0.08),
          inset 0 -16px 24px rgba(255, 255, 255, 0.08);
        overflow: hidden;
      }

      .popular-pill {
        position: absolute;
        top: 1px;
        left: 50%;
        z-index: 4;
        transform: translate(-50%, -62%);
        padding: 0.35rem 0.85rem;
        border-radius: 999px;
        background: #f5c518;
        color: #241a00;
        font-size: 0.68rem;
        font-weight: 800;
        line-height: 1;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        box-shadow: 0 8px 18px rgba(245, 197, 24, 0.2);
      }

      .pricing-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.55rem;
      }

      .plan-identity {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        min-width: 0;
      }

      .identity-copy {
        min-width: 0;
      }

      .card-icon {
        width: 42px;
        height: 42px;
        margin: 0;
        border-radius: 12px;
        background: linear-gradient(135deg, rgba(245, 197, 24, 0.22), rgba(255, 224, 139, 0.08));
        border: 1px solid rgba(255, 255, 255, 0.18);
      }

      .card-icon.featured {
        background: linear-gradient(135deg, rgba(245, 197, 24, 0.34), rgba(255, 224, 139, 0.12));
      }

      .plan-name {
        margin: 0;
        font-size: 1.2rem;
        font-weight: 600;
        color: #ffffff;
        letter-spacing: 0;
      }

      .plan-description {
        display: -webkit-box;
        min-height: 0;
        margin: 0.2rem 0 0;
        overflow: hidden;
        color: #8f8b85;
        font-size: 0.78rem;
        line-height: 1.35;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
      }

      .status-dot {
        width: 20px;
        height: 20px;
        border: 2px solid rgba(255, 255, 255, 0.28);
        border-radius: 999px;
        flex-shrink: 0;
      }

      .status-dot.active {
        border-color: rgba(245, 197, 24, 0.9);
        background: radial-gradient(circle, #f5c518 0 38%, transparent 42%);
        box-shadow: 0 0 14px rgba(245, 197, 24, 0.35);
      }

      .status-dot.inactive {
        border-color: rgba(198, 198, 199, 0.42);
      }

      .price-section {
        margin: 0 0 1.35rem;
        padding: 0;
        border: 0;
      }

      .price-display {
        gap: 0.35rem;
      }

      .currency {
        color: #ffffff;
        font-size: 1.25rem;
      }

      .amount {
        color: #ffffff;
        font-size: clamp(2.2rem, 5vw, 3rem);
        font-weight: 650;
        letter-spacing: 0;
      }

      .period {
        color: #b4afa6;
        font-size: 0.9rem;
      }

      .price-note {
        margin: 0.35rem 0 0;
        color: #77716a;
        font-size: 0.78rem;
      }

      .benefits-list {
        gap: 0.82rem;
        margin-bottom: 1.35rem;
      }

      .benefits-list li {
        align-items: flex-start;
        gap: 0.75rem;
        color: #d7d2cd;
        font-size: 0.88rem;
        line-height: 1.35;
      }

      .benefit-check {
        display: inline-grid;
        place-items: center;
        width: 1rem;
        height: 1rem;
        margin-top: 0.08rem;
        border-radius: 999px;
        background: #f5c518;
        color: #241a00;
        font-size: 0.72rem;
        font-weight: 800;
        flex-shrink: 0;
      }

      .plan-metrics {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
        margin-top: auto;
        margin-bottom: 1rem;
      }

      .metric-item {
        min-width: 0;
        padding: 0.78rem;
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.035);
      }

      .metric-item .material-symbols-outlined {
        display: block;
        margin-bottom: 0.4rem;
        color: #f5c518;
        font-size: 1.05rem;
      }

      .metric-item strong {
        display: block;
        color: #ffffff;
        font-size: 0.98rem;
        overflow-wrap: anywhere;
      }

      .metric-item small {
        display: block;
        margin-top: 0.12rem;
        color: #8f8b85;
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
      }

      .primary-cta {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        width: 100%;
        height: 3rem;
        border: 0;
        border-radius: 10px;
        background: #ffffff;
        color: #151515;
        font-family: Inter, sans-serif;
        font-size: 0.92rem;
        font-weight: 800;
        cursor: pointer;
        transition: background 180ms ease, transform 180ms ease;
      }

      .primary-cta:hover {
        background: #ffe08b;
        transform: translateY(-1px);
      }

      .primary-cta .material-symbols-outlined {
        font-size: 1.1rem;
      }

      .card-actions {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.45rem;
        margin-top: 0.7rem;
        padding-top: 0;
        border-top: 0;
      }

      .action-btn {
        min-height: 2.45rem;
        padding: 0.45rem 0.35rem;
        border-color: rgba(255, 255, 255, 0.08);
        background: rgba(255, 255, 255, 0.035);
        color: #b4afa6;
        font-size: 0.62rem;
      }

      .action-btn span:first-child {
        font-size: 1rem;
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

      @media (max-width: 1200px) {
        .plan-card { padding: 1px; }
        .card-shell { padding: 1.35rem; }
        .card-actions { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .action-btn {
          flex-direction: column;
          justify-content: center;
          padding: 0.45rem 0.35rem;
        }
        .action-btn span:last-child { display: inline; }
      }

      @media (max-width: 640px) {
        .card-actions { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .plan-metrics { grid-template-columns: 1fr; }
      }

      @media (max-width: 420px) {
        .pricing-head { align-items: flex-start; }
        .plan-identity { align-items: flex-start; }
        .card-actions { grid-template-columns: 1fr; }
        .action-btn {
          flex-direction: row;
          justify-content: flex-start;
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
