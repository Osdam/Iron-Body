import { Injectable, inject } from '@angular/core';
import {
  HttpRequest,
  HttpHandler,
  HttpEvent,
  HttpInterceptor,
  HttpErrorResponse,
} from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { AuthService } from './auth.service';

/**
 * AuthInterceptor agrega token Bearer a las requests
 * y maneja errores 401 (sesión expirada)
 * TODO: Configurado pero se registrará en app.config.ts
 */
@Injectable()
export class AuthInterceptor implements HttpInterceptor {
  private readonly authService = inject(AuthService);

  intercept(request: HttpRequest<unknown>, next: HttpHandler): Observable<HttpEvent<unknown>> {
    // Obtener token
    const token = this.authService.getToken();

    // Si hay token, agregar header Authorization
    if (token) {
      request = request.clone({
        setHeaders: {
          Authorization: `Bearer ${token}`,
        },
      });
    }

    return next.handle(request).pipe(
      catchError((error: HttpErrorResponse) => {
        // Si es error 401, sesión expirada
        if (error.status === 401) {
          this.authService.handleUnauthorized();
        }

        return throwError(() => error);
      }),
    );
  }
}
