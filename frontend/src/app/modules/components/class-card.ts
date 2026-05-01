import { CommonModule } from '@angular/common';
import { Component, Input, Output, EventEmitter } from '@angular/core';

export interface ClassCardData {
  id: number;
  name: string;
  type: string;
  trainerName?: string;
  dayOfWeek: string;
  startTime: string;
  endTime: string;
  durationMinutes: number;
  maxCapacity: number;
  enrolledCount: number;
  location: string;
  status: string;
  description?: string;
}

@Component({
  selector: 'app-class-card',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="class-card">
      <div class="card-header">
        <div class="header-top">
          <h3 class="class-name">{{ class.name }}</h3>
          <span class="type-badge">{{ class.type }}</span>
        </div>
        <p class="class-description">{{ class.description || 'Clase de gimnasio' }}</p>
      </div>

      <div class="card-details">
        <div class="detail-row">
          <div class="detail-item">
            <span class="detail-label">
              <span class="material-symbols-outlined">schedule</span>
              Día
            </span>
            <span class="detail-value">{{ class.dayOfWeek }}</span>
          </div>
          <div class="detail-item">
            <span class="detail-label">
              <span class="material-symbols-outlined">access_time</span>
              Horario
            </span>
            <span class="detail-value">{{ class.startTime }} - {{ class.endTime }}</span>
          </div>
        </div>

        <div class="detail-row">
          <div class="detail-item">
            <span class="detail-label">
              <span class="material-symbols-outlined">person</span>
              Entrenador
            </span>
            <span class="detail-value">{{ class.trainerName || 'Sin asignar' }}</span>
          </div>
          <div class="detail-item">
            <span class="detail-label">
              <span class="material-symbols-outlined">location_on</span>
              Ubicación
            </span>
            <span class="detail-value">{{ class.location }}</span>
          </div>
        </div>

        <div class="detail-row">
          <div class="detail-item">
            <span class="detail-label">
              <span class="material-symbols-outlined">timer</span>
              Duración
            </span>
            <span class="detail-value">{{ class.durationMinutes }} min</span>
          </div>
          <div class="detail-item">
            <span class="detail-label">
              <span class="material-symbols-outlined">event_seat</span>
              Cupos
            </span>
            <span class="detail-value">{{ class.enrolledCount }} / {{ class.maxCapacity }}</span>
          </div>
        </div>
      </div>

      <div class="capacity-bar">
        <div class="bar-background">
          <div
            class="bar-fill"
            [style.width.%]="(class.enrolledCount / class.maxCapacity) * 100"
          ></div>
        </div>
        <span class="capacity-text">{{ getCapacityLabel() }}</span>
      </div>

      <div class="card-footer">
        <span class="status-badge" [class]="'status-' + (class.status || 'active')">
          {{ getStatusLabel(class.status) }}
        </span>
        <div class="action-buttons">
          <button class="action-btn" title="Ver inscritos" (click)="onViewEnrollments.emit(class)">
            <span class="material-symbols-outlined">visibility</span>
          </button>
          <button class="action-btn" title="Editar" (click)="onEdit.emit(class)">
            <span class="material-symbols-outlined">edit</span>
          </button>
          <button class="action-btn" title="Duplicar" (click)="onDuplicate.emit(class)">
            <span class="material-symbols-outlined">content_copy</span>
          </button>
          <button class="action-btn" title="Cambiar estado" (click)="onToggleStatus.emit(class)">
            <span class="material-symbols-outlined">{{
              class.status === 'active' ? 'block' : 'check_circle'
            }}</span>
          </button>
          <button class="action-btn delete" title="Eliminar" (click)="onDelete.emit(class)">
            <span class="material-symbols-outlined">delete</span>
          </button>
        </div>
      </div>
    </div>
  `,
  styles: [
    `
      .class-card {
        display: flex;
        flex-direction: column;
        background: #fff;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
        overflow: hidden;
        transition: all 200ms ease;
        animation: slideIn 300ms ease;
      }

      @keyframes slideIn {
        from {
          opacity: 0;
          transform: translateY(10px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }

      .class-card:hover {
        border-color: #facc15;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
        transform: translateY(-4px);
      }

      .card-header {
        padding: 1.5rem;
        border-bottom: 1px solid #f0f0f0;
        background: linear-gradient(135deg, #fafafa 0%, #fff 100%);
      }

      .header-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 0.75rem;
      }

      .class-name {
        font-family: Inter, sans-serif;
        font-size: 1.25rem;
        font-weight: 700;
        color: #0a0a0a;
        margin: 0;
        letter-spacing: -0.01em;
      }

      .type-badge {
        display: inline-block;
        padding: 0.4rem 0.75rem;
        background: rgba(250, 204, 21, 0.15);
        color: #ca8a04;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: capitalize;
        white-space: nowrap;
      }

      .class-description {
        font-size: 0.9rem;
        color: #666;
        margin: 0;
        line-height: 1.5;
      }

      .card-details {
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
      }

      .detail-row {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.5rem;
      }

      .detail-item {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
      }

      .detail-label {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.8rem;
        font-weight: 600;
        color: #999;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-family: 'Space Grotesk', sans-serif;
      }

      .detail-label span {
        font-size: 1rem;
      }

      .detail-value {
        font-size: 0.95rem;
        color: #0a0a0a;
        font-weight: 500;
      }

      .capacity-bar {
        padding: 0 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
      }

      .bar-background {
        flex: 1;
        height: 6px;
        background: #f0f0f0;
        border-radius: 3px;
        overflow: hidden;
      }

      .bar-fill {
        height: 100%;
        background: linear-gradient(90deg, #facc15, #f0c00e);
        border-radius: 3px;
        transition: width 300ms ease;
      }

      .capacity-text {
        font-size: 0.8rem;
        color: #666;
        white-space: nowrap;
        min-width: 60px;
        text-align: right;
      }

      .card-footer {
        padding: 1.5rem;
        border-top: 1px solid #f0f0f0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        background: #fafafa;
      }

      .status-badge {
        display: inline-block;
        padding: 0.4rem 0.75rem;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: capitalize;
      }

      .status-active {
        background: #dcfce7;
        color: #166534;
      }

      .status-inactive {
        background: #fee2e2;
        color: #991b1b;
      }

      .status-finished {
        background: #e0e7ff;
        color: #3730a3;
      }

      .action-buttons {
        display: flex;
        gap: 0.5rem;
      }

      .action-btn {
        display: grid;
        place-items: center;
        width: 36px;
        height: 36px;
        border: 1px solid #e5e5e5;
        border-radius: 6px;
        background: #fff;
        color: #666;
        cursor: pointer;
        transition: all 200ms ease;
        font-size: 1rem;
      }

      .action-btn:hover {
        border-color: #facc15;
        background: rgba(250, 204, 21, 0.1);
        color: #ca8a04;
      }

      .action-btn.delete:hover {
        border-color: #dc2626;
        background: #fee2e2;
        color: #dc2626;
      }

      @media (max-width: 768px) {
        .class-card {
          border-radius: 10px;
        }

        .card-header,
        .card-details,
        .card-footer {
          padding: 1.25rem;
        }

        .class-name {
          font-size: 1.1rem;
        }

        .detail-row {
          grid-template-columns: 1fr;
          gap: 1rem;
        }

        .header-top {
          flex-direction: column;
          align-items: flex-start;
        }

        .action-buttons {
          flex-wrap: wrap;
        }
      }
    `,
  ],
})
export class ClassCardComponent {
  @Input() class!: ClassCardData;
  @Output() onEdit = new EventEmitter<ClassCardData>();
  @Output() onViewEnrollments = new EventEmitter<ClassCardData>();
  @Output() onDuplicate = new EventEmitter<ClassCardData>();
  @Output() onToggleStatus = new EventEmitter<ClassCardData>();
  @Output() onDelete = new EventEmitter<ClassCardData>();

  getCapacityLabel(): string {
    const percentage = (this.class.enrolledCount / this.class.maxCapacity) * 100;
    if (percentage === 0) return 'Vacío';
    if (percentage < 50) return 'Disponible';
    if (percentage < 100) return 'Casi lleno';
    return 'Lleno';
  }

  getStatusLabel(status: string): string {
    const labels: { [key: string]: string } = {
      active: 'Activa',
      inactive: 'Inactiva',
      finished: 'Finalizada',
    };
    return labels[status] || 'Desconocida';
  }
}
