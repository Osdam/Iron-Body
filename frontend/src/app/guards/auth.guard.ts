import { Injectable, inject } from '@angular/core';
import { CanActivate, ActivatedRouteSnapshot, RouterStateSnapshot, Router } from '@angular/router';
import { AuthService } from '../services/auth.service';
import { Permission } from '../models/permissions.enum';
import { UserRole } from '../models/user-role.enum';

/**
 * AuthGuard protege rutas internas
 * Solo permite acceso si el usuario está autenticado
 * Si no, redirige a /login
 */
@Injectable({
  providedIn: 'root',
})
export class AuthGuard implements CanActivate {
  private readonly authService = inject(AuthService);
  private readonly router = inject(Router);

  canActivate(route: ActivatedRouteSnapshot, state: RouterStateSnapshot): boolean {
    if (this.authService.isAuthenticated() && this.hasRouteAccess(route)) {
      return true;
    }

    if (this.authService.isAuthenticated()) {
      this.router.navigate(['/'], {
        queryParams: { denied: state.url },
      });
      return false;
    }

    this.router.navigate(['/login'], {
      queryParams: { returnUrl: state.url },
    });
    return false;
  }

  private hasRouteAccess(route: ActivatedRouteSnapshot): boolean {
    if (route.data?.['superAdminOnly'] && !this.authService.hasRole(UserRole.SUPER_ADMIN)) {
      return false;
    }

    const permissions = route.data?.['permissions'] as Permission[] | undefined;
    if (!permissions?.length) return true;
    return this.authService.hasAnyPermission(permissions);
  }
}
