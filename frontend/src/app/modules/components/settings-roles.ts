import { Component, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';

export interface Role {
  id: string;
  name: string;
  permissions: { [key: string]: boolean };
}

@Component({
  selector: 'app-settings-roles',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="settings-section">
      <div class="section-header">
        <div class="section-title">
          <span class="material-symbols-outlined">security</span>
          <div>
            <h2>Usuarios y roles</h2>
            <p>Gestiona roles y permisos del sistema</p>
          </div>
        </div>
      </div>

      <div class="roles-grid">
        <div *ngFor="let role of roles()" class="role-card">
          <div class="role-header">
            <h3>{{ role.name }}</h3>
            <span class="role-id">{{ role.id }}</span>
          </div>

          <div class="permissions-list">
            <div class="permission-group" *ngFor="let module of getModules()">
              <label class="permission-checkbox">
                <input
                  type="checkbox"
                  [checked]="role.permissions[module + '_view']"
                  (change)="togglePermission(role.id, module + '_view', $event)"
                />
                <span class="permission-label">Ver {{ module }}</span>
              </label>
              <label class="permission-checkbox">
                <input
                  type="checkbox"
                  [checked]="role.permissions[module + '_create']"
                  (change)="togglePermission(role.id, module + '_create', $event)"
                />
                <span class="permission-label">Crear {{ module }}</span>
              </label>
              <label class="permission-checkbox">
                <input
                  type="checkbox"
                  [checked]="role.permissions[module + '_edit']"
                  (change)="togglePermission(role.id, module + '_edit', $event)"
                />
                <span class="permission-label">Editar {{ module }}</span>
              </label>
              <label class="permission-checkbox">
                <input
                  type="checkbox"
                  [checked]="role.permissions[module + '_delete']"
                  (change)="togglePermission(role.id, module + '_delete', $event)"
                />
                <span class="permission-label">Eliminar {{ module }}</span>
              </label>
            </div>
          </div>

          <button class="btn-save" (click)="saveRole(role.id)">Guardar permiso</button>
        </div>
      </div>

      <div class="info-box">
        <span class="material-symbols-outlined">info</span>
        <p>Selecciona los permisos para cada rol. Los cambios se guardan automáticamente.</p>
      </div>
    </div>
  `,
  styles: [
    `
      .settings-section {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 0.75rem;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
      }

      .section-header {
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #f3f4f6;
      }

      .section-title {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
      }

      .section-title .material-symbols-outlined {
        font-size: 1.5rem;
        color: #fbbf24;
        margin-top: 0.25rem;
      }

      .section-title h2 {
        font-size: 1.125rem;
        font-weight: 600;
        color: #0a0a0a;
        margin: 0;
      }

      .section-title p {
        font-size: 0.875rem;
        color: #6b7280;
        margin: 0.25rem 0 0 0;
      }

      .roles-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
        gap: 1.5rem;
        margin-bottom: 1.5rem;
      }

      .role-card {
        border: 1px solid #e5e7eb;
        border-radius: 0.75rem;
        padding: 1.25rem;
        background: #f9fafb;
      }

      .role-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid #e5e7eb;
      }

      .role-header h3 {
        margin: 0;
        font-size: 1rem;
        font-weight: 600;
        color: #0a0a0a;
      }

      .role-id {
        font-size: 0.75rem;
        background: #e5e7eb;
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        color: #6b7280;
      }

      .permissions-list {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
        margin-bottom: 1rem;
      }

      .permission-group {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
      }

      .permission-checkbox {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
        user-select: none;
        font-size: 0.875rem;
      }

      .permission-checkbox input[type='checkbox'] {
        cursor: pointer;
        width: auto !important;
        accent-color: #fbbf24;
      }

      .permission-label {
        color: #374151;
      }

      .btn-save {
        width: 100%;
        padding: 0.625rem 1rem;
        background: #fbbf24;
        color: #0a0a0a;
        border: none;
        border-radius: 0.5rem;
        font-weight: 500;
        font-size: 0.875rem;
        cursor: pointer;
        transition: all 0.2s;
      }

      .btn-save:hover {
        background: #f59e0b;
      }

      .info-box {
        display: flex;
        gap: 1rem;
        align-items: flex-start;
        padding: 1rem;
        background: #fef3c7;
        border: 1px solid #fcd34d;
        border-radius: 0.5rem;
      }

      .info-box .material-symbols-outlined {
        color: #92400e;
        margin-top: 0.125rem;
        flex-shrink: 0;
      }

      .info-box p {
        margin: 0;
        font-size: 0.875rem;
        color: #78350f;
      }

      @media (max-width: 768px) {
        .roles-grid {
          grid-template-columns: 1fr;
        }

        .permissions-list {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export default class SettingsRolesComponent implements OnInit {
  roles = signal<Role[]>([]);

  ngOnInit(): void {
    this.roles.set([
      {
        id: 'super_admin',
        name: 'Super administrador',
        permissions: this.getAllPermissions(true),
      },
      {
        id: 'admin',
        name: 'Administrador',
        permissions: this.getAdminPermissions(),
      },
      {
        id: 'reception',
        name: 'Recepción',
        permissions: this.getReceptionPermissions(),
      },
      {
        id: 'trainer',
        name: 'Entrenador',
        permissions: this.getTrainerPermissions(),
      },
    ]);
  }

  getModules(): string[] {
    return [
      'Miembros',
      'Pagos',
      'Planes',
      'Clases',
      'Rutinas',
      'Entrenadores',
      'Mercadeo',
      'Reportes',
    ];
  }

  togglePermission(roleId: string, permission: string, event: Event): void {
    const checked = (event.target as HTMLInputElement).checked;
    const roles = this.roles();
    const role = roles.find((r) => r.id === roleId);
    if (role) {
      role.permissions[permission] = checked;
      this.roles.set([...roles]);
    }
  }

  saveRole(roleId: string): void {
    console.log(`Permisos guardados para rol: ${roleId}`);
    const role = this.roles().find((r) => r.id === roleId);
    if (role) {
      console.log('Configuración de permisos:', role.permissions);
    }
  }

  private getAllPermissions(enabled: boolean): { [key: string]: boolean } {
    const perms: { [key: string]: boolean } = {};
    this.getModules().forEach((module) => {
      perms[`${module.toLowerCase()}_view`] = enabled;
      perms[`${module.toLowerCase()}_create`] = enabled;
      perms[`${module.toLowerCase()}_edit`] = enabled;
      perms[`${module.toLowerCase()}_delete`] = enabled;
    });
    return perms;
  }

  private getAdminPermissions(): { [key: string]: boolean } {
    const perms = this.getAllPermissions(true);
    return perms;
  }

  private getReceptionPermissions(): { [key: string]: boolean } {
    const perms: { [key: string]: boolean } = {};
    const allowedModules = ['miembros', 'pagos', 'clases'];
    this.getModules().forEach((module) => {
      const lower = module.toLowerCase();
      perms[`${lower}_view`] = allowedModules.includes(lower);
      perms[`${lower}_create`] = allowedModules.includes(lower);
      perms[`${lower}_edit`] = allowedModules.includes(lower);
      perms[`${lower}_delete`] = false;
    });
    return perms;
  }

  private getTrainerPermissions(): { [key: string]: boolean } {
    const perms: { [key: string]: boolean } = {};
    this.getModules().forEach((module) => {
      const lower = module.toLowerCase();
      perms[`${lower}_view`] = ['rutinas', 'clases', 'miembros'].includes(lower);
      perms[`${lower}_create`] = ['rutinas', 'clases'].includes(lower);
      perms[`${lower}_edit`] = ['rutinas', 'clases'].includes(lower);
      perms[`${lower}_delete`] = false;
    });
    return perms;
  }
}
