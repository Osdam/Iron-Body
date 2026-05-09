import { CommonModule } from '@angular/common';
import { Component, signal, inject, Input, Output, EventEmitter } from '@angular/core';
import { Router } from '@angular/router';
import { ReactiveFormsModule, FormBuilder, Validators } from '@angular/forms';
import { AuthService } from '../../../services/auth.service';

interface User {
  id: string;
  name: string;
  email: string;
  role: string;
  avatar?: string;
}

interface MenuItem {
  label: string;
  icon: string;
  action: () => void;
  isDangerous?: boolean;
}

@Component({
  selector: 'app-user-menu',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  template: `
    <div *ngIf="isOpen" class="user-overlay" (click)="toggleMenu()"></div>

    <div *ngIf="isOpen" class="user-menu-popover">
      <div class="user-profile">
        <div class="user-avatar">
          {{ getInitials(currentUser().name) }}
        </div>
        <div class="user-info">
          <div class="user-name">{{ currentUser().name }}</div>
          <div class="user-email">{{ currentUser().email }}</div>
          <div class="user-role">{{ currentUser().role }}</div>
        </div>
      </div>

      <div class="user-menu-divider"></div>

      <div class="user-menu-items">
        <button
          *ngFor="let item of menuItems"
          class="user-menu-item"
          [class.dangerous]="item.isDangerous"
          (click)="item.action()"
        >
          <span class="material-symbols-outlined">{{ item.icon }}</span>
          <span>{{ item.label }}</span>
        </button>
      </div>
    </div>

    <!-- Change Password Modal -->
    <div
      *ngIf="showPasswordModal()"
      class="password-modal-overlay"
      (click)="closePasswordModal()"
    ></div>
    <div *ngIf="showPasswordModal()" class="password-modal">
      <div class="modal-header">
        <h3>Cambiar contraseña</h3>
        <button class="modal-close" (click)="closePasswordModal()">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>

      <form [formGroup]="passwordForm" class="modal-form">
        <div class="form-group">
          <label class="form-label">Contraseña actual</label>
          <input
            type="password"
            class="form-control"
            formControlName="currentPassword"
            placeholder="Ingresa tu contraseña actual"
          />
          <span *ngIf="getError('currentPassword')" class="form-error">
            {{ getError('currentPassword') }}
          </span>
        </div>

        <div class="form-group">
          <label class="form-label">Nueva contraseña</label>
          <input
            type="password"
            class="form-control"
            formControlName="newPassword"
            placeholder="Ingresa tu nueva contraseña"
          />
          <span *ngIf="getError('newPassword')" class="form-error">
            {{ getError('newPassword') }}
          </span>
        </div>

        <div class="form-group">
          <label class="form-label">Confirmar contraseña</label>
          <input
            type="password"
            class="form-control"
            formControlName="confirmPassword"
            placeholder="Confirma tu nueva contraseña"
          />
          <span *ngIf="getError('confirmPassword')" class="form-error">
            {{ getError('confirmPassword') }}
          </span>
        </div>

        <div *ngIf="passwordForm.hasError('passwordMismatch')" class="form-alert error-alert">
          <span class="material-symbols-outlined">error</span>
          <span>Las contraseñas no coinciden.</span>
        </div>

        <div *ngIf="passwordChangeSuccess()" class="form-alert success-alert">
          <span class="material-symbols-outlined">check_circle</span>
          <span>Contraseña cambiada exitosamente.</span>
        </div>

        <div *ngIf="passwordChangeError()" class="form-alert error-alert">
          <span class="material-symbols-outlined">error</span>
          <span>{{ passwordChangeError() }}</span>
        </div>
      </form>

      <div class="modal-actions">
        <button class="btn-cancel" (click)="closePasswordModal()">Cancelar</button>
        <button
          class="btn-confirm"
          [disabled]="!passwordForm.valid || isChangingPassword()"
          (click)="submitPasswordChange()"
        >
          {{ isChangingPassword() ? 'Actualizando...' : 'Cambiar contraseña' }}
        </button>
      </div>
    </div>
  `,
  styleUrls: ['./user-menu.component.scss'],
})
export class UserMenuComponent {
  private router = inject(Router);
  private fb = inject(FormBuilder);
  private auth = inject(AuthService);

  @Input() isOpen = false;
  @Output() close = new EventEmitter<void>();
  showPasswordModal = signal(false);
  isChangingPassword = signal(false);
  passwordChangeSuccess = signal(false);
  passwordChangeError = signal<string | null>(null);

  currentUser = signal<User>({
    id: '1',
    name: 'Alejandro García',
    email: 'alejandro@ironbody.com',
    role: 'Administrador',
    avatar: 'AG',
  });

  private passwordMatchValidator = (group: any) => {
    const newPassword = group.get('newPassword')?.value;
    const confirmPassword = group.get('confirmPassword')?.value;

    if (newPassword && confirmPassword && newPassword !== confirmPassword) {
      return { passwordMismatch: true };
    }
    return null;
  };

  passwordForm = this.fb.group(
    {
      currentPassword: ['', [Validators.required]],
      newPassword: ['', [Validators.required, Validators.minLength(8)]],
      confirmPassword: ['', [Validators.required]],
    },
    { validators: this.passwordMatchValidator },
  );

  menuItems: MenuItem[] = [
    {
      label: 'Perfil',
      icon: 'person',
      action: () => {
        this.router.navigate(['/profile']);
        this.toggleMenu();
      },
    },
    {
      label: 'Configuración',
      icon: 'settings',
      action: () => {
        this.router.navigate(['/settings']);
        this.toggleMenu();
      },
    },
    {
      label: 'Cambiar contraseña',
      icon: 'lock',
      action: () => {
        this.openPasswordModal();
        this.toggleMenu();
      },
    },
    {
      label: 'Cerrar sesión',
      icon: 'logout',
      action: () => this.handleLogout(),
      isDangerous: true,
    },
  ];

  toggleMenu(): void {
    this.close.emit();
  }

  getInitials(name: string): string {
    return name
      .split(' ')
      .map((word) => word.charAt(0).toUpperCase())
      .join('')
      .slice(0, 2);
  }

  openPasswordModal(): void {
    this.showPasswordModal.set(true);
    this.passwordChangeSuccess.set(false);
    this.passwordChangeError.set(null);
  }

  closePasswordModal(): void {
    this.showPasswordModal.set(false);
    this.passwordForm.reset();
  }

  submitPasswordChange(): void {
    if (!this.passwordForm.valid) return;

    this.isChangingPassword.set(true);
    this.passwordChangeError.set(null);

    // TODO: Call backend API to change password
    // For now, simulate the request
    setTimeout(() => {
      this.isChangingPassword.set(false);
      this.passwordChangeSuccess.set(true);
      setTimeout(() => {
        this.closePasswordModal();
      }, 1500);
    }, 1500);
  }

  handleLogout(): void {
    if (!confirm('¿Estás seguro de que deseas cerrar sesión?')) return;

    // Cierra el menú desplegable y delega en AuthService.logout(),
    // que limpia token + usuario + storage y redirige a /login.
    this.close.emit();
    this.auth.logout().subscribe({
      next: () => {
        // Garantiza redirección incluso si el observable termina sin tap.
        this.router.navigate(['/login']);
      },
      error: () => {
        // Si el backend falla, limpiamos igual y redirigimos para no atrapar al usuario.
        this.router.navigate(['/login']);
      },
    });
  }

  getError(fieldName: string): string | null {
    const control = this.passwordForm.get(fieldName);
    if (!control || !control.errors || !control.touched) {
      return null;
    }

    if (control.errors['required']) return 'Este campo es requerido.';
    if (control.errors['minlength']) {
      return `Mínimo ${control.errors['minlength'].requiredLength} caracteres.`;
    }
    return null;
  }
}
