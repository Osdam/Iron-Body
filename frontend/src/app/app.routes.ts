import { Routes } from '@angular/router';

export const routes: Routes = [
	{ path: '', loadComponent: () => import('./home').then(m => m.default) },
	{ path: 'users', loadComponent: () => import('./users-list').then(m => m.UsersList) },
	{ path: 'plans', loadComponent: () => import('./modules/plans').then(m => m.default) },
	{ path: 'payments', loadComponent: () => import('./modules/payments').then(m => m.default) },
	{ path: 'routines', loadComponent: () => import('./modules/routines').then(m => m.default) },
	{ path: 'classes', loadComponent: () => import('./modules/classes').then(m => m.default) },
	{ path: 'trainers', loadComponent: () => import('./modules/trainers').then(m => m.default) },
	{ path: 'marketing', loadComponent: () => import('./modules/marketing').then(m => m.default) },
	{ path: 'reports', loadComponent: () => import('./modules/reports').then(m => m.default) },
	{ path: 'settings', loadComponent: () => import('./modules/settings').then(m => m.default) },
];
