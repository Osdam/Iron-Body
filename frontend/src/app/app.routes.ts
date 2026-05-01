import { Routes } from '@angular/router';
import { AuthGuard } from './guards/auth.guard';
import { NoAuthGuard } from './guards/no-auth.guard';

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
    loadComponent: () => import('./users-list').then((m) => m.UsersList),
  },
  {
    path: 'plans',
    canActivate: [AuthGuard],
    loadComponent: () => import('./modules/plans').then((m) => m.default),
  },
  {
    path: 'payments',
    canActivate: [AuthGuard],
    loadComponent: () => import('./modules/payments').then((m) => m.default),
  },
  {
    path: 'routines',
    canActivate: [AuthGuard],
    loadComponent: () => import('./modules/routines').then((m) => m.default),
  },
  {
    path: 'classes',
    canActivate: [AuthGuard],
    loadComponent: () => import('./modules/classes').then((m) => m.default),
  },
  {
    path: 'trainers',
    canActivate: [AuthGuard],
    loadComponent: () => import('./modules/trainers').then((m) => m.default),
  },
  {
    path: 'marketing',
    canActivate: [AuthGuard],
    loadComponent: () => import('./modules/marketing').then((m) => m.default),
  },
  {
    path: 'reports',
    canActivate: [AuthGuard],
    loadComponent: () => import('./modules/reports').then((m) => m.default),
  },
  {
    path: 'settings',
    canActivate: [AuthGuard],
    loadComponent: () => import('./modules/settings').then((m) => m.default),
  },
];
