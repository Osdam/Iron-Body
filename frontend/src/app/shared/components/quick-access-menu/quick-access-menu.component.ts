import { CommonModule } from '@angular/common';
import { Component, signal, inject, Input, Output, EventEmitter } from '@angular/core';
import { RouterModule, Router } from '@angular/router';
import { QuickActionsService } from '../../services/quick-actions.service';
import { QuickAction } from '../../services/quick-actions.service';

@Component({
  selector: 'app-quick-access-menu',
  standalone: true,
  imports: [CommonModule, RouterModule],
  template: `
    <div *ngIf="isOpen" class="quick-overlay" (click)="togglePopover()"></div>

    <div *ngIf="isOpen" class="quick-menu-popover">
      <div class="quick-header">
        <h2 class="quick-title">Acceso rápido</h2>
        <button class="quick-close-btn" (click)="togglePopover()">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>

      <div class="quick-content">
        <div *ngIf="quickActions().length > 0; else noActions" class="quick-grid">
          <button
            *ngFor="let action of quickActions()"
            class="quick-action-item"
            (click)="handleActionClick(action)"
            [title]="action.description"
          >
            <div class="quick-icon">
              <span class="material-symbols-outlined">{{ action.icon }}</span>
            </div>
            <div class="quick-label">{{ action.label }}</div>
            <div class="quick-description">{{ action.description }}</div>
          </button>
        </div>

        <ng-template #noActions>
          <div class="empty-quick-state">
            <span class="material-symbols-outlined">block</span>
            <p>No hay accesos disponibles para tu rol.</p>
          </div>
        </ng-template>
      </div>
    </div>
  `,
  styleUrls: ['./quick-access-menu.component.scss'],
})
export class QuickAccessMenuComponent {
  private quickActionsService = inject(QuickActionsService);
  private router = inject(Router);

  @Input() isOpen = false;
  @Output() close = new EventEmitter<void>();
  quickActions = signal<QuickAction[]>([]);

  constructor() {
    this.loadQuickActions();
  }

  loadQuickActions(): void {
    // TODO: Get current user role from auth service
    const userRole = 'admin'; // Placeholder
    this.quickActions.set(this.quickActionsService.getActionsByRole(userRole));
  }

  togglePopover(): void {
    this.close.emit();
  }

  handleActionClick(action: QuickAction): void {
    if (action.route) {
      this.router.navigate([action.route]);
    } else if (action.action) {
      // Execute action method
      console.log('Creating new:', action.action);
      // TODO: Dispatch action or open modal
    }
    this.close.emit();
  }
}
