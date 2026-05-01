import { Injectable } from '@angular/core';
import { Router } from '@angular/router';

export interface QuickAction {
  id: string;
  label: string;
  description: string;
  icon: string;
  route?: string;
  action?: string;
  requiredRole?: string[];
}

@Injectable({
  providedIn: 'root',
})
export class QuickActionsService {
  private readonly quickActions: QuickAction[] = [
    {
      id: 'home',
      label: 'Inicio',
      description: 'Panel principal',
      icon: 'dashboard',
      route: '/',
    },
    {
      id: 'members',
      label: 'Miembros',
      description: 'Gestionar miembros',
      icon: 'group',
      route: '/users',
    },
    {
      id: 'new-member',
      label: 'Nuevo miembro',
      description: 'Registrar miembro',
      icon: 'person_add',
      action: 'openCreateMemberModal',
    },
    {
      id: 'payments',
      label: 'Pagos',
      description: 'Gestionar pagos',
      icon: 'payments',
      route: '/payments',
    },
    {
      id: 'register-payment',
      label: 'Registrar pago',
      description: 'Nuevo pago',
      icon: 'add_circle',
      action: 'openCreatePaymentModal',
    },
    {
      id: 'plans',
      label: 'Planes',
      description: 'Planes y membresías',
      icon: 'card_membership',
      route: '/plans',
    },
    {
      id: 'new-plan',
      label: 'Crear plan',
      description: 'Nuevo plan',
      icon: 'note_add',
      action: 'openCreatePlanModal',
    },
    {
      id: 'classes',
      label: 'Clases',
      description: 'Gestionar clases',
      icon: 'calendar_month',
      route: '/classes',
    },
    {
      id: 'new-class',
      label: 'Crear clase',
      description: 'Nueva clase',
      icon: 'event',
      action: 'openCreateClassModal',
    },
    {
      id: 'routines',
      label: 'Rutinas',
      description: 'Rutinas de entrenamiento',
      icon: 'fitness_center',
      route: '/routines',
    },
    {
      id: 'new-routine',
      label: 'Crear rutina',
      description: 'Nueva rutina',
      icon: 'add_box',
      action: 'openCreateRoutineModal',
    },
    {
      id: 'trainers',
      label: 'Entrenadores',
      description: 'Gestionar entrenadores',
      icon: 'badge',
      route: '/trainers',
    },
    {
      id: 'marketing',
      label: 'Mercadeo',
      description: 'Campañas y marketing',
      icon: 'campaign',
      route: '/marketing',
    },
    {
      id: 'new-campaign',
      label: 'Crear campaña',
      description: 'Nueva campaña',
      icon: 'mail',
      action: 'openCreateCampaignModal',
    },
    {
      id: 'analytics',
      label: 'Analítica',
      description: 'Reportes y análisis',
      icon: 'monitoring',
      route: '/reports',
    },
    {
      id: 'settings',
      label: 'Configuración',
      description: 'Ajustes del sistema',
      icon: 'settings',
      route: '/settings',
    },
    {
      id: 'support',
      label: 'Soporte',
      description: 'Centro de soporte',
      icon: 'help',
      action: 'openSupportPanel',
    },
  ];

  constructor(private router: Router) {}

  /**
   * Obtener acciones rápidas
   */
  getQuickActions(): QuickAction[] {
    return this.quickActions;
  }

  /**
   * Obtener acción por ID
   */
  getActionById(id: string): QuickAction | undefined {
    return this.quickActions.find((action) => action.id === id);
  }

  /**
   * Obtener acciones por rol
   */
  getActionsByRole(role: string): QuickAction[] {
    return this.quickActions.filter(
      (action) => !action.requiredRole || action.requiredRole.includes(role),
    );
  }

  /**
   * Navegar desde acceso rápido
   */
  navigateToAction(action: QuickAction): void {
    if (action.route) {
      this.router.navigate([action.route]);
    }
  }
}
