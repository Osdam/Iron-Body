import { Injectable } from '@angular/core';
import { Permission } from '../models/permissions.enum';

export type AccessSurface = 'crm' | 'mobile';

export interface AccessModule {
  id: string;
  name: string;
  icon: string;
  surfaces: AccessSurface[];
  permissions: Array<{
    key: Permission;
    label: string;
    description: string;
    surface: AccessSurface;
  }>;
}

export interface RoleProfile {
  id: string;
  name: string;
  description: string;
  surface: AccessSurface | 'both';
  locked?: boolean;
  permissions: Permission[];
}

export interface PlanAccessRule {
  id: string;
  name: string;
  description: string;
  enabledMobileModules: string[];
  limits: {
    classBookingsPerWeek: number;
    routineAssignments: number;
    trainerMessagesPerMonth: number;
  };
}

export interface AccessPolicy {
  roles: RoleProfile[];
  plans: PlanAccessRule[];
  updatedAt: string;
}

const ACCESS_POLICY_KEY = 'ironbody_access_policy';

@Injectable({ providedIn: 'root' })
export class AccessControlService {
  readonly modules: AccessModule[] = [
    {
      id: 'members',
      name: 'Miembros',
      icon: 'groups',
      surfaces: ['crm'],
      permissions: [
        this.permission(Permission.MEMBERS_VIEW, 'Ver', 'Consultar miembros', 'crm'),
        this.permission(Permission.MEMBERS_CREATE, 'Crear', 'Crear miembros', 'crm'),
        this.permission(Permission.MEMBERS_EDIT, 'Editar', 'Actualizar miembros', 'crm'),
        this.permission(Permission.MEMBERS_DELETE, 'Eliminar', 'Eliminar miembros', 'crm'),
      ],
    },
    {
      id: 'payments',
      name: 'Pagos',
      icon: 'payments',
      surfaces: ['crm'],
      permissions: [
        this.permission(Permission.PAYMENTS_VIEW, 'Ver', 'Consultar pagos', 'crm'),
        this.permission(Permission.PAYMENTS_CREATE, 'Crear', 'Registrar pagos', 'crm'),
        this.permission(Permission.PAYMENTS_CANCEL, 'Anular', 'Anular pagos', 'crm'),
        this.permission(Permission.PAYMENTS_EXPORT, 'Exportar', 'Exportar pagos', 'crm'),
      ],
    },
    {
      id: 'plans',
      name: 'Planes',
      icon: 'workspace_premium',
      surfaces: ['crm', 'mobile'],
      permissions: [
        this.permission(Permission.PLANS_VIEW, 'Ver', 'Ver planes en CRM y app', 'mobile'),
        this.permission(Permission.PLANS_CREATE, 'Crear', 'Crear planes', 'crm'),
        this.permission(Permission.PLANS_EDIT, 'Editar', 'Editar planes', 'crm'),
        this.permission(Permission.PLANS_DELETE, 'Eliminar', 'Eliminar planes', 'crm'),
      ],
    },
    {
      id: 'classes',
      name: 'Clases',
      icon: 'calendar_month',
      surfaces: ['crm', 'mobile'],
      permissions: [
        this.permission(Permission.CLASSES_VIEW, 'Ver', 'Ver calendario de clases', 'mobile'),
        this.permission(Permission.CLASSES_CREATE, 'Crear', 'Crear clases', 'crm'),
        this.permission(Permission.CLASSES_EDIT, 'Editar', 'Editar clases', 'crm'),
        this.permission(Permission.CLASSES_DELETE, 'Eliminar', 'Eliminar clases', 'crm'),
        this.permission(Permission.CLASSES_ENROLLMENTS, 'Inscripciones', 'Gestionar reservas', 'mobile'),
      ],
    },
    {
      id: 'routines',
      name: 'Rutinas',
      icon: 'fitness_center',
      surfaces: ['crm', 'mobile'],
      permissions: [
        this.permission(Permission.ROUTINES_VIEW, 'Ver', 'Ver rutinas', 'mobile'),
        this.permission(Permission.ROUTINES_CREATE, 'Crear', 'Crear rutinas', 'crm'),
        this.permission(Permission.ROUTINES_EDIT, 'Editar', 'Editar rutinas', 'crm'),
        this.permission(Permission.ROUTINES_DELETE, 'Eliminar', 'Eliminar rutinas', 'crm'),
        this.permission(Permission.ROUTINES_ASSIGN, 'Asignar', 'Asignar rutinas a miembros', 'mobile'),
      ],
    },
    {
      id: 'trainers',
      name: 'Entrenadores',
      icon: 'sports',
      surfaces: ['crm', 'mobile'],
      permissions: [
        this.permission(Permission.TRAINERS_VIEW, 'Ver', 'Ver entrenadores', 'mobile'),
        this.permission(Permission.TRAINERS_CREATE, 'Crear', 'Crear entrenadores', 'crm'),
        this.permission(Permission.TRAINERS_EDIT, 'Editar', 'Editar entrenadores', 'crm'),
        this.permission(Permission.TRAINERS_DELETE, 'Eliminar', 'Eliminar entrenadores', 'crm'),
      ],
    },
    {
      id: 'inventory',
      name: 'Inventario',
      icon: 'inventory_2',
      surfaces: ['crm'],
      permissions: [
        this.permission(Permission.INVENTORY_VIEW, 'Ver', 'Ver inventario', 'crm'),
        this.permission(Permission.INVENTORY_CREATE, 'Crear', 'Crear items de inventario', 'crm'),
        this.permission(Permission.INVENTORY_EDIT, 'Editar', 'Editar inventario', 'crm'),
        this.permission(Permission.INVENTORY_DELETE, 'Eliminar', 'Eliminar inventario', 'crm'),
      ],
    },
    {
      id: 'marketing',
      name: 'Mercadeo',
      icon: 'campaign',
      surfaces: ['crm'],
      permissions: [
        this.permission(Permission.MARKETING_VIEW, 'Ver', 'Ver campañas', 'crm'),
        this.permission(Permission.MARKETING_CREATE, 'Crear', 'Crear campañas', 'crm'),
        this.permission(Permission.MARKETING_EDIT, 'Editar', 'Editar campañas', 'crm'),
        this.permission(Permission.MARKETING_DELETE, 'Eliminar', 'Eliminar campañas', 'crm'),
        this.permission(Permission.MARKETING_SEND, 'Enviar', 'Enviar comunicaciones', 'crm'),
      ],
    },
    {
      id: 'reports',
      name: 'Reportes',
      icon: 'analytics',
      surfaces: ['crm'],
      permissions: [
        this.permission(Permission.REPORTS_VIEW, 'Ver', 'Ver reportes', 'crm'),
        this.permission(Permission.REPORTS_EXPORT, 'Exportar', 'Exportar reportes', 'crm'),
      ],
    },
    {
      id: 'settings',
      name: 'Configuración',
      icon: 'settings',
      surfaces: ['crm'],
      permissions: [
        this.permission(Permission.SETTINGS_VIEW, 'Ver', 'Abrir configuración', 'crm'),
        this.permission(Permission.SETTINGS_EDIT, 'Editar', 'Editar configuración general', 'crm'),
        this.permission(Permission.SETTINGS_ROLES, 'Usuarios', 'Administrar usuarios y roles', 'crm'),
        this.permission(Permission.SETTINGS_INTEGRATIONS, 'Integraciones', 'Administrar integraciones', 'crm'),
        this.permission(Permission.SETTINGS_SECURITY, 'Seguridad', 'Administrar seguridad', 'crm'),
        this.permission(Permission.SETTINGS_BACKUPS, 'Respaldos', 'Administrar respaldos', 'crm'),
      ],
    },
    {
      id: 'audit',
      name: 'Logs',
      icon: 'manage_search',
      surfaces: ['crm'],
      permissions: [
        this.permission(Permission.AUDIT_LOGS_VIEW, 'Ver logs', 'Ver auditoría completa del CRM', 'crm'),
      ],
    },
  ];

  getPolicy(): AccessPolicy {
    const saved = this.readSavedPolicy();
    return saved ? this.mergeWithDefaults(saved) : this.defaultPolicy();
  }

  savePolicy(policy: AccessPolicy): AccessPolicy {
    const normalized = { ...policy, updatedAt: new Date().toISOString() };
    localStorage.setItem(ACCESS_POLICY_KEY, JSON.stringify(normalized));
    return normalized;
  }

  resetPolicy(): AccessPolicy {
    const policy = this.defaultPolicy();
    localStorage.setItem(ACCESS_POLICY_KEY, JSON.stringify(policy));
    return policy;
  }

  permissionsForRole(roleName: string): Permission[] {
    const normalized = this.normalize(roleName);
    const role = this.getPolicy().roles.find((item) => {
      return this.normalize(item.id) === normalized || this.normalize(item.name) === normalized;
    });

    return role?.permissions || [];
  }

  private defaultPolicy(): AccessPolicy {
    return {
      updatedAt: new Date().toISOString(),
      roles: [
        {
          id: 'super_admin',
          name: 'Super administrador',
          description: 'Control total del CRM y de la configuración.',
          surface: 'crm',
          locked: true,
          permissions: Object.values(Permission),
        },
        {
          id: 'administrador',
          name: 'Administrador',
          description: 'Opera el gimnasio sin tocar seguridad crítica.',
          surface: 'crm',
          permissions: Object.values(Permission).filter(
            (permission) =>
              ![
                Permission.SETTINGS_ROLES,
                Permission.SETTINGS_SECURITY,
                Permission.SETTINGS_BACKUPS,
                Permission.AUDIT_LOGS_VIEW,
              ].includes(permission),
          ),
        },
        {
          id: 'recepcion',
          name: 'Recepción',
          description: 'Alta de miembros, pagos, clases e inscripciones.',
          surface: 'crm',
          permissions: [
            Permission.MEMBERS_VIEW,
            Permission.MEMBERS_CREATE,
            Permission.MEMBERS_EDIT,
            Permission.PAYMENTS_VIEW,
            Permission.PAYMENTS_CREATE,
            Permission.PLANS_VIEW,
            Permission.CLASSES_VIEW,
            Permission.CLASSES_CREATE,
            Permission.CLASSES_EDIT,
            Permission.CLASSES_ENROLLMENTS,
            Permission.INVENTORY_VIEW,
          ],
        },
        {
          id: 'entrenador',
          name: 'Entrenador',
          description: 'Gestión de clases, rutinas y seguimiento de miembros asignados.',
          surface: 'both',
          permissions: [
            Permission.MEMBERS_VIEW,
            Permission.CLASSES_VIEW,
            Permission.CLASSES_CREATE,
            Permission.CLASSES_EDIT,
            Permission.CLASSES_ENROLLMENTS,
            Permission.ROUTINES_VIEW,
            Permission.ROUTINES_CREATE,
            Permission.ROUTINES_EDIT,
            Permission.ROUTINES_ASSIGN,
            Permission.TRAINERS_VIEW,
          ],
        },
        {
          id: 'miembro',
          name: 'Miembro app',
          description: 'Acceso móvil para reservas, rutinas y planes contratados.',
          surface: 'mobile',
          permissions: [
            Permission.PLANS_VIEW,
            Permission.CLASSES_VIEW,
            Permission.CLASSES_ENROLLMENTS,
            Permission.ROUTINES_VIEW,
            Permission.TRAINERS_VIEW,
          ],
        },
      ],
      plans: [
        {
          id: 'basic',
          name: 'Básico',
          description: 'Acceso móvil mínimo para consultar plan y clases.',
          enabledMobileModules: ['plans', 'classes'],
          limits: { classBookingsPerWeek: 2, routineAssignments: 1, trainerMessagesPerMonth: 0 },
        },
        {
          id: 'standard',
          name: 'Estándar',
          description: 'Clases y rutinas activas para miembros regulares.',
          enabledMobileModules: ['plans', 'classes', 'routines'],
          limits: { classBookingsPerWeek: 4, routineAssignments: 2, trainerMessagesPerMonth: 2 },
        },
        {
          id: 'premium',
          name: 'Premium',
          description: 'Mayor cupo semanal y contacto con entrenadores.',
          enabledMobileModules: ['plans', 'classes', 'routines', 'trainers'],
          limits: { classBookingsPerWeek: 7, routineAssignments: 4, trainerMessagesPerMonth: 8 },
        },
        {
          id: 'vip',
          name: 'VIP',
          description: 'Sin restricciones fuertes para experiencia completa.',
          enabledMobileModules: ['plans', 'classes', 'routines', 'trainers'],
          limits: { classBookingsPerWeek: 99, routineAssignments: 99, trainerMessagesPerMonth: 99 },
        },
      ],
    };
  }

  private permission(
    key: Permission,
    label: string,
    description: string,
    surface: AccessSurface,
  ): AccessModule['permissions'][number] {
    return { key, label, description, surface };
  }

  private readSavedPolicy(): AccessPolicy | null {
    try {
      const raw = localStorage.getItem(ACCESS_POLICY_KEY);
      if (!raw) return null;
      const parsed = JSON.parse(raw) as AccessPolicy;
      if (!Array.isArray(parsed.roles) || !Array.isArray(parsed.plans)) return null;
      return parsed;
    } catch {
      return null;
    }
  }

  private mergeWithDefaults(saved: AccessPolicy): AccessPolicy {
    const defaults = this.defaultPolicy();
    const savedRoles = new Map(saved.roles.map((role) => [role.id, role]));
    const savedPlans = new Map(saved.plans.map((plan) => [plan.id, plan]));

    return {
      updatedAt: saved.updatedAt || defaults.updatedAt,
      roles: defaults.roles.map((defaultRole) => {
        const savedRole = savedRoles.get(defaultRole.id);
        if (!savedRole) return defaultRole;

        if (defaultRole.locked) {
          return {
            ...defaultRole,
            ...savedRole,
            locked: defaultRole.locked,
            permissions: Array.from(new Set([...defaultRole.permissions, ...savedRole.permissions])).filter(
              (permission) => Object.values(Permission).includes(permission),
            ),
          };
        }

        return {
          ...defaultRole,
          ...savedRole,
          permissions: savedRole.permissions.filter((permission) =>
            Object.values(Permission).includes(permission),
          ),
        };
      }),
      plans: defaults.plans.map((defaultPlan) => {
        const savedPlan = savedPlans.get(defaultPlan.id);
        if (!savedPlan) return defaultPlan;

        return {
          ...defaultPlan,
          ...savedPlan,
          limits: { ...defaultPlan.limits, ...savedPlan.limits },
        };
      }),
    };
  }

  private normalize(value: string): string {
    return value
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/\s+/g, '_');
  }
}
