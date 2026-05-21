export enum Permission {
  // Miembros
  MEMBERS_VIEW = 'members.view',
  MEMBERS_CREATE = 'members.create',
  MEMBERS_EDIT = 'members.edit',
  MEMBERS_DELETE = 'members.delete',

  // Pagos
  PAYMENTS_VIEW = 'payments.view',
  PAYMENTS_CREATE = 'payments.create',
  PAYMENTS_CANCEL = 'payments.cancel',
  PAYMENTS_EXPORT = 'payments.export',

  // Planes
  PLANS_VIEW = 'plans.view',
  PLANS_CREATE = 'plans.create',
  PLANS_EDIT = 'plans.edit',
  PLANS_DELETE = 'plans.delete',

  // Clases
  CLASSES_VIEW = 'classes.view',
  CLASSES_CREATE = 'classes.create',
  CLASSES_EDIT = 'classes.edit',
  CLASSES_DELETE = 'classes.delete',
  CLASSES_ENROLLMENTS = 'classes.enrollments',

  // Rutinas
  ROUTINES_VIEW = 'routines.view',
  ROUTINES_CREATE = 'routines.create',
  ROUTINES_EDIT = 'routines.edit',
  ROUTINES_DELETE = 'routines.delete',
  ROUTINES_ASSIGN = 'routines.assign',

  // Entrenadores
  TRAINERS_VIEW = 'trainers.view',
  TRAINERS_CREATE = 'trainers.create',
  TRAINERS_EDIT = 'trainers.edit',
  TRAINERS_DELETE = 'trainers.delete',

  // Inventario
  INVENTORY_VIEW = 'inventory.view',
  INVENTORY_CREATE = 'inventory.create',
  INVENTORY_EDIT = 'inventory.edit',
  INVENTORY_DELETE = 'inventory.delete',

  // Mercadeo
  MARKETING_VIEW = 'marketing.view',
  MARKETING_CREATE = 'marketing.create',
  MARKETING_EDIT = 'marketing.edit',
  MARKETING_DELETE = 'marketing.delete',
  MARKETING_SEND = 'marketing.send',

  // Analítica
  REPORTS_VIEW = 'reports.view',
  REPORTS_EXPORT = 'reports.export',

  // Configuración
  SETTINGS_VIEW = 'settings.view',
  SETTINGS_EDIT = 'settings.edit',
  SETTINGS_ROLES = 'settings.roles',
  SETTINGS_INTEGRATIONS = 'settings.integrations',
  SETTINGS_SECURITY = 'settings.security',
  SETTINGS_BACKUPS = 'settings.backups',

  // Auditoría
  AUDIT_LOGS_VIEW = 'audit.logs.view',
}
