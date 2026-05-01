import { Injectable, inject } from '@angular/core';
import { CanActivate, Router } from '@angular/router';
import { AuthService } from '../services/auth.service';

/**
 * NoAuthGuard protege rutas de autenticación (/login, /forgot-password)
 * Si el usuario YA está autenticado, redirige a dashboard
 * Si no está autenticado, permite acceso
 */
@Injectable({
  providedIn: 'root',
})
export class NoAuthGuard implements CanActivate {
  private readonly authService = inject(AuthService);
  private readonly router = inject(Router);

  canActivate(): boolean {
    if (this.authService.isAuthenticated()) {
      // Ya está autenticado, redirigir a dashboard
      this.router.navigate(['/']);
      return false;
    }

    // No está autenticado, permitir acceso a /login
    return true;
  }
}
