import { Component, EventEmitter, Input, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { LottieIconComponent } from '../../shared/components/lottie-icon/lottie-icon.component';

export interface Trainer {
  id: string;
  fullName: string;
  document: string;
  phone: string;
  email: string;
  birthDate?: string;
  mainSpecialty: string;
  specialties: string[];
  experienceYears: number;
  contractType: string;
  status: string;
  rating: number;
  bio?: string;
  certifications?: string;
  avatarUrl?: string;
  bannerUrl?: string;
  availability: TrainerAvailability[];
  assignedClasses: number;
  assignedMembers: number;
  createdAt: string;
  updatedAt: string;
}

export interface TrainerAvailability {
  day: string;
  enabled: boolean;
  startTime: string;
  endTime: string;
}

@Component({
  selector: 'app-trainer-card',
  standalone: true,
  imports: [CommonModule, LottieIconComponent],
  template: `
    <article class="trainer-card">
      <!-- Banner -->
      <div class="card-banner" [style.background]="getBannerBackground()"></div>

      <!-- Bookmark Button -->
      <button
        type="button"
        class="card-bookmark"
        (click)="onBookmark()"
        title="Marcar como favorito"
        aria-label="Marcar entrenador como favorito"
      >
        <span class="material-symbols-outlined">bookmark</span>
      </button>

      <!-- Avatar (overlaps banner) -->
      <div class="card-avatar">
        <div class="avatar-circle">
          {{ getInitials(trainer.fullName) }}
        </div>
      </div>

      <!-- Content -->
      <div class="card-content">
        <!-- Name and Specialty -->
        <div class="card-header">
          <div>
            <h3 class="card-name">{{ trainer.fullName }}</h3>
            <p class="card-specialty">{{ trainer.mainSpecialty }}</p>
          </div>
          <div
            class="card-status"
            [ngClass]="'status-' + (trainer.status || 'Activo').toLowerCase()"
          >
            {{ formatStatus(trainer.status) }}
          </div>
        </div>

        <!-- Stats Row -->
        <div class="card-stats">
          <div class="stat-item">
            <span class="stat-icon material-symbols-outlined">star</span>
            <div class="stat-content">
              <div class="stat-value">{{ trainer.rating.toFixed(1) }}</div>
              <div class="stat-label">Evaluación</div>
            </div>
          </div>

          <div class="stat-divider"></div>

          <div class="stat-item">
            <span class="stat-icon material-symbols-outlined">work_history</span>
            <div class="stat-content">
              <div class="stat-value">{{ trainer.experienceYears }}</div>
              <div class="stat-label">Años exp</div>
            </div>
          </div>

          <div class="stat-divider"></div>

          <div class="stat-item">
            <span class="stat-icon material-symbols-outlined">school</span>
            <div class="stat-content">
              <div class="stat-value">{{ trainer.assignedClasses }}</div>
              <div class="stat-label">Clases</div>
            </div>
          </div>

          <div class="stat-divider"></div>

          <div class="stat-item">
            <span class="stat-icon material-symbols-outlined">group</span>
            <div class="stat-content">
              <div class="stat-value">{{ trainer.assignedMembers }}</div>
              <div class="stat-label">Miembros</div>
            </div>
          </div>
        </div>

        <!-- Specialties -->
        <div class="card-specialties">
          <div class="spec-label">Especialidades</div>
          <div class="spec-list">
            <span *ngFor="let spec of trainer.specialties" class="spec-badge">
              {{ spec }}
            </span>
          </div>
        </div>

        <!-- Contract Type and Availability -->
        <div class="card-meta">
          <div class="meta-item">
            <span class="meta-icon material-symbols-outlined">handshake</span>
            <span class="meta-text">{{ trainer.contractType }}</span>
          </div>
          <div class="meta-item">
            <span class="meta-icon material-symbols-outlined">schedule</span>
            <span class="meta-text">{{ getAvailabilityDays() }}</span>
          </div>
        </div>

        <!-- Contact Info -->
        <div class="card-contact">
          <a [href]="'tel:' + trainer.phone" class="contact-link" title="Llamar">
            <span class="material-symbols-outlined">call</span>
          </a>
          <a [href]="'mailto:' + trainer.email" class="contact-link" title="Enviar correo">
            <span class="material-symbols-outlined">mail</span>
          </a>
        </div>

        <!-- Action Button -->
        <button type="button" class="btn-primary" (click)="onViewProfile()">
          <span class="btn-lottie">
            <app-lottie-icon
              src="/assets/crm/entrenadores.json"
              [size]="22"
              [loop]="true"
            ></app-lottie-icon>
          </span>
          Ver perfil
        </button>
      </div>

      <!-- Actions Menu (bottom) -->
      <div class="card-actions">
        <button
          type="button"
          class="action-btn"
          (click)="onEdit()"
          title="Editar"
          aria-label="Editar entrenador"
        >
          <span class="material-symbols-outlined">edit</span>
        </button>
        <button
          type="button"
          class="action-btn"
          (click)="onToggleStatus()"
          title="Activar/Desactivar"
          aria-label="Cambiar estado"
        >
          <span class="material-symbols-outlined">{{
            trainer.status === 'Activo' ? 'check_circle' : 'cancel'
          }}</span>
        </button>
        <button
          type="button"
          class="action-btn danger"
          (click)="onDelete()"
          title="Eliminar"
          aria-label="Eliminar entrenador"
        >
          <span class="material-symbols-outlined">delete</span>
        </button>
      </div>
    </article>
  `,
  styles: [
    `
      .trainer-card {
        position: relative;
        border: 1px solid #f0f0f0;
        border-radius: 18px;
        background: #ffffff;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06);
        overflow: hidden;
        transition: all 0.2s ease;
        display: flex;
        flex-direction: column;
      }

      .trainer-card:hover {
        border-color: #e0e0e0;
        box-shadow: 0 14px 32px rgba(0, 0, 0, 0.1);
        transform: translateY(-4px);
      }

      .card-banner {
        width: 100%;
        height: 120px;
        background: linear-gradient(135deg, #fbbf24 0%, #ca8a04 100%);
      }

      .card-bookmark {
        position: absolute;
        top: 12px;
        right: 12px;
        width: 40px;
        height: 40px;
        border-radius: 12px;
        border: none;
        background: rgba(255, 255, 255, 0.9);
        color: #0a0a0a;
        cursor: pointer;
        display: grid;
        place-items: center;
        transition: all 0.2s ease;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      }

      .card-bookmark:hover {
        background: #ffffff;
        transform: scale(1.05);
      }

      .card-bookmark span {
        font-size: 1.2rem;
      }

      .card-avatar {
        position: absolute;
        left: 50%;
        top: 80px;
        transform: translateX(-50%) translateY(-50%);
      }

      .avatar-circle {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        border: 4px solid #ffffff;
        background: linear-gradient(135deg, #fbbf24 0%, #ca8a04 100%);
        display: grid;
        place-items: center;
        font-size: 1.4rem;
        font-weight: 900;
        color: #ffffff;
        box-shadow: 0 8px 20px rgba(251, 191, 36, 0.3);
      }

      .card-content {
        padding: 3.2rem 1.2rem 1.2rem;
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 0.9rem;
      }

      .card-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.8rem;
      }

      .card-name {
        margin: 0;
        font-size: 1.15rem;
        font-weight: 900;
        color: #0a0a0a;
        line-height: 1.2;
      }

      .card-specialty {
        margin: 0.2rem 0 0;
        font-size: 0.85rem;
        color: #666;
        line-height: 1.3;
      }

      .card-status {
        padding: 0.35rem 0.65rem;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        white-space: nowrap;
      }

      .card-status.status-activo {
        background: rgba(34, 197, 94, 0.12);
        color: #16a34a;
        border: 1px solid rgba(34, 197, 94, 0.3);
      }

      .card-status.status-inactivo {
        background: rgba(107, 114, 128, 0.12);
        color: #4b5563;
        border: 1px solid rgba(107, 114, 128, 0.3);
      }

      .card-status.status-pendiente {
        background: rgba(249, 115, 22, 0.12);
        color: #ea580c;
        border: 1px solid rgba(249, 115, 22, 0.3);
      }

      .card-stats {
        display: flex;
        align-items: center;
        justify-content: space-around;
        border: 1px solid #f0f0f0;
        border-radius: 14px;
        background: #fafafa;
        padding: 0.9rem;
        gap: 0.4rem;
      }

      .stat-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex: 1;
        text-align: center;
      }

      .stat-icon {
        font-size: 1rem;
        color: #fbbf24;
      }

      .stat-content {
        display: flex;
        flex-direction: column;
        gap: 0.1rem;
      }

      .stat-value {
        font-size: 0.95rem;
        font-weight: 900;
        color: #0a0a0a;
      }

      .stat-label {
        font-size: 0.65rem;
        color: #999;
        font-weight: 700;
      }

      .stat-divider {
        width: 1px;
        height: 28px;
        background: #e5e5e5;
      }

      .card-specialties {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
      }

      .spec-label {
        font-size: 0.7rem;
        font-weight: 900;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.03em;
      }

      .spec-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
      }

      .spec-badge {
        display: inline-block;
        padding: 0.35rem 0.6rem;
        border-radius: 999px;
        background: rgba(251, 191, 36, 0.12);
        color: #ca8a04;
        font-size: 0.7rem;
        font-weight: 800;
        border: 1px solid rgba(251, 191, 36, 0.3);
      }

      .card-meta {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
        font-size: 0.8rem;
      }

      .meta-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: #666;
      }

      .meta-icon {
        font-size: 0.95rem;
        color: #fbbf24;
      }

      .meta-text {
        line-height: 1.2;
      }

      .card-contact {
        display: flex;
        gap: 0.6rem;
        justify-content: center;
      }

      .contact-link {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        border: 1px solid #f0f0f0;
        background: #fafafa;
        display: grid;
        place-items: center;
        color: #0a0a0a;
        text-decoration: none;
        transition: all 0.2s ease;
      }

      .contact-link:hover {
        background: #fbbf24;
        border-color: #fbbf24;
        color: #0a0a0a;
        transform: translateY(-2px);
      }

      .contact-link span {
        font-size: 1rem;
      }

      .btn-primary {
        width: 100%;
        height: 44px;
        border-radius: 12px;
        border: none;
        background: #fbbf24;
        color: #0a0a0a;
        font-weight: 900;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        transition: all 0.2s ease;
      }

      .btn-primary:hover {
        background: #f9a825;
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(251, 191, 36, 0.3);
      }

      .btn-lottie {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        border-radius: 7px;
        background: rgba(0, 0, 0, 0.08);
        overflow: hidden;
        flex-shrink: 0;
      }

      .card-actions {
        display: flex;
        gap: 0.5rem;
        padding: 0.8rem 1.2rem;
        border-top: 1px solid #f0f0f0;
        background: #fafafa;
      }

      .action-btn {
        flex: 1;
        height: 36px;
        border-radius: 10px;
        border: 1px solid #e5e5e5;
        background: #ffffff;
        color: #0a0a0a;
        cursor: pointer;
        display: grid;
        place-items: center;
        transition: all 0.2s ease;
      }

      .action-btn:hover {
        background: #f9f9f9;
        border-color: #d0d0d0;
        transform: translateY(-1px);
      }

      .action-btn.danger {
        color: #991b1b;
      }

      .action-btn.danger:hover {
        border-color: rgba(239, 68, 68, 0.3);
        background: rgba(239, 68, 68, 0.06);
      }

      .action-btn span {
        font-size: 1rem;
      }
    `,
  ],
})
export default class TrainerCardComponent {
  @Input() trainer!: Trainer;
  @Output() view = new EventEmitter<Trainer>();
  @Output() edit = new EventEmitter<Trainer>();
  @Output() toggleStatus = new EventEmitter<Trainer>();
  @Output() delete = new EventEmitter<Trainer>();
  @Output() bookmark = new EventEmitter<Trainer>();

  onViewProfile(): void {
    this.view.emit(this.trainer);
  }

  onEdit(): void {
    this.edit.emit(this.trainer);
  }

  onToggleStatus(): void {
    this.toggleStatus.emit(this.trainer);
  }

  onDelete(): void {
    this.delete.emit(this.trainer);
  }

  onBookmark(): void {
    this.bookmark.emit(this.trainer);
  }

  getInitials(name: string): string {
    return (name || '')
      .split(' ')
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);
  }

  formatStatus(status: string): string {
    const s = (status || '').toLowerCase();
    if (s === 'activo') return 'Activo';
    if (s === 'inactivo') return 'Inactivo';
    return 'Pendiente';
  }

  getBannerBackground(): string {
    if (this.trainer?.bannerUrl) return `url(${this.trainer.bannerUrl})`;
    return 'linear-gradient(135deg, #fbbf24 0%, #ca8a04 100%)';
  }

  getAvailabilityDays(): string {
    const list = Array.isArray(this.trainer?.availability) ? this.trainer.availability : [];
    if (list.length === 0) return 'Sin configurar';

    const available = list.filter((a) => a && a.enabled && !!a.day);
    if (available.length === 0) return 'Sin disponibilidad';

    const days = available.map((a) => String(a.day).slice(0, 3)).join(', ');
    return days.length > 30 ? `${days.slice(0, 27)}...` : days;
  }
}
