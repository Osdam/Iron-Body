import { Routes } from '@angular/router';
import { AuthGuard } from './guards/auth.guard';
import { NoAuthGuard } from './guards/no-auth.guard';
import { Permission } from './models/permissions.enum';

export const routes: Routes = [
  // Public routes (no autenticación requerida)
  {
    path: 'login',
    canActivate: [NoAuthGuard],
    loadComponent: () => import('./auth/login/login.component').then((m) => m.LoginComponent),
  },
  {
    path: 'forgot-password',
    canActivate: [NoAuthGuard],
    loadComponent: () =>
      import('./auth/forgot-password/forgot-password.component').then(
        (m) => m.ForgotPasswordComponent,
      ),
  },

  // Protected routes (autenticación requerida)
  {
    path: '',
    canActivate: [AuthGuard],
    loadComponent: () => import('./home').then((m) => m.default),
  },
  {
    path: 'users',
    canActivate: [AuthGuard],
    data: { permissions: [Permission.MEMBERS_VIEW] },
    loadComponent: () => import('./users-list').then((m) => m.UsersList),
  },
  {
    path: 'plans',
    canActivate: [AuthGuard],
    data: { permissions: [Permission.PLANS_VIEW] },
    loadComponent: () => import('./modules/plans').then((m) => m.default),
  },
  {
    path: 'payments',
    canActivate: [AuthGuard],
    data: { permissions: [Permission.PAYMENTS_VIEW] },
    loadComponent: () => import('./modules/payments').then((m) => m.default),
  },
  {
    path: 'inventory',
    canActivate: [AuthGuard],
    data: { permissions: [Permission.INVENTORY_VIEW] },
    loadComponent: () => import('./modules/inventory').then((m) => m.default),
  },
  {
    path: 'routines',
    canActivate: [AuthGuard],
    data: { permissions: [Permission.ROUTINES_VIEW] },
    loadComponent: () => import('./modules/routines').then((m) => m.default),
  },
  {
    path: 'classes',
    canActivate: [AuthGuard],
    data: { permissions: [Permission.CLASSES_VIEW] },
    loadComponent: () => import('./modules/classes').then((m) => m.default),
  },
  {
    path: 'attendance',
    canActivate: [AuthGuard],
    data: { permissions: [Permission.CLASSES_VIEW] },
    loadComponent: () => import('./modules/attendance').then((m) => m.default),
  },
  {
    path: 'trainers',
    canActivate: [AuthGuard],
    data: { permissions: [Permission.TRAINERS_VIEW] },
    loadComponent: () => import('./modules/trainers').then((m) => m.default),
  },
  {
    path: 'marketing',
    canActivate: [AuthGuard],
    data: { permissions: [Permission.MARKETING_VIEW] },
    loadComponent: () => import('./modules/marketing').then((m) => m.default),
  },
  {
    path: 'reports',
    canActivate: [AuthGuard],
    data: { permissions: [Permission.REPORTS_VIEW] },
    loadComponent: () => import('./modules/reports').then((m) => m.default),
  },
  {
    path: 'logs',
    canActivate: [AuthGuard],
    data: { permissions: [Permission.AUDIT_LOGS_VIEW], superAdminOnly: true },
    loadComponent: () => import('./modules/logs').then((m) => m.default),
  },
  {
    path: 'settings',
    canActivate: [AuthGuard],
    data: { permissions: [Permission.SETTINGS_VIEW] },
    loadComponent: () => import('./modules/settings').then((m) => m.default),
  },
];
