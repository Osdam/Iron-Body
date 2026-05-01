import { Injectable, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { Observable, BehaviorSubject, of, throwError } from 'rxjs';
import { tap, catchError } from 'rxjs/operators';
import { User, LoginCredentials, AuthResponse } from '../models/user.model';
import { UserRole } from '../models/user-role.enum';
import { Permission } from '../models/permissions.enum';

/**
 * AuthService maneja autenticación v, gestión de sesión y permisos
 * Mock data: superadmin@ironbody.com / admin123 | admin@ironbody.com / admin123
 * TODO: Conectar con endpoints reales de Laravel cuando backend esté listo
 */
@Injectable({
  providedIn: 'root',
})
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);

  // Signals
  private currentUserSubject = new BehaviorSubject<User | null>(null);
  public currentUser$ = this.currentUserSubject.asObservable();
  public isAuthenticated$ = this.currentUser$.pipe();

  // Storage keys
  private readonly TOKEN_KEY = 'auth_token';
  private readonly USER_KEY = 'auth_user';
  private readonly SESSION_TYPE_KEY = 'auth_session_type'; // 'local' o 'session'

  // Mock users con permisos predefinidos
  private readonly MOCK_USERS: Record<string, { password: string; user: User }> = {
    'superadmin@ironbody.com': {
      password: 'admin123',
      user: {
        id: '1',
        email: 'superadmin@ironbody.com',
        name: 'Super Admin',
        role: UserRole.SUPER_ADMIN,
        permissions: Object.values(Permission), // Todos los permisos
      },
    },
    'admin@ironbody.com': {
      password: 'admin123',
      user: {
        id: '2',
        email: 'admin@ironbody.com',
        name: 'Administrador',
        role: UserRole.ADMINISTRADOR,
        permissions: [
          // Operativos
          Permission.MEMBERS_VIEW,
          Permission.MEMBERS_CREATE,
          Permission.MEMBERS_EDIT,
          Permission.MEMBERS_DELETE,
          Permission.PAYMENTS_VIEW,
          Permission.PAYMENTS_CREATE,
          Permission.PAYMENTS_CANCEL,
          Permission.PAYMENTS_EXPORT,
          Permission.PLANS_VIEW,
          Permission.PLANS_CREATE,
          Permission.PLANS_EDIT,
          Permission.PLANS_DELETE,
          Permission.CLASSES_VIEW,
          Permission.CLASSES_CREATE,
          Permission.CLASSES_EDIT,
          Permission.CLASSES_DELETE,
          Permission.CLASSES_ENROLLMENTS,
          Permission.ROUTINES_VIEW,
          Permission.ROUTINES_CREATE,
          Permission.ROUTINES_EDIT,
          Permission.ROUTINES_DELETE,
          Permission.ROUTINES_ASSIGN,
          Permission.TRAINERS_VIEW,
          Permission.TRAINERS_CREATE,
          Permission.TRAINERS_EDIT,
          Permission.TRAINERS_DELETE,
          Permission.MARKETING_VIEW,
          Permission.MARKETING_CREATE,
          Permission.MARKETING_EDIT,
          Permission.MARKETING_DELETE,
          Permission.MARKETING_SEND,
          Permission.REPORTS_VIEW,
          Permission.REPORTS_EXPORT,
          Permission.SETTINGS_VIEW,
          // NO tiene acceso a configuración crítica
        ],
      },
    },
  };

  constructor() {
    // Recuperar sesión al inicializar si existe
    this.restoreSession();
  }

  /**
   * Login con credenciales
   * Mock: usa objetos predefinidos
   * TODO: POST /api/login con credenciales reales al backend
   */
  login(credentials: LoginCredentials): Observable<AuthResponse> {
    // Validar credenciales en mock
    const mockUserData = this.MOCK_USERS[credentials.email];

    if (!mockUserData || mockUserData.password !== credentials.password) {
      return throwError(
        () => new Error('Credenciales incorrectas. Verifica tus datos e intenta nuevamente.'),
      );
    }

    // Simular delay de API (300-500ms)
    const delay = Math.random() * 200 + 300;
    return of({
      user: mockUserData.user,
      token: `mock_token_${Date.now()}_${Math.random()}`,
      expiresAt: Date.now() + 24 * 60 * 60 * 1000, // 24 horas
    }).pipe(
      tap(async (response) => {
        // Esperar el delay simulado
        await new Promise((resolve) => setTimeout(resolve, delay));
        this.setSession(response, credentials.rememberMe || false);
      }),
    );

    // TODO: Descomentar cuando backend esté listo
    // return this.http.post<AuthResponse>('http://127.0.0.1:8080/api/login', credentials).pipe(
    //   tap((response) => this.setSession(response, credentials.rememberMe || false)),
    //   catchError((error) => {
    //     return throwError(
    //       () =>
    //         new Error(
    //           error.error?.message ||
    //           'Credenciales incorrectas. Verifica tus datos.',
    //         ),
    //     );
    //   }),
    // );
  }

  /**
   * Logout - limpia sesión y redirige a login
   * TODO: POST /api/logout para notificar al backend
   */
  logout(): Observable<void> {
    // TODO: Llamar endpoint real del backend
    // return this.http.post<void>('http://127.0.0.1:8080/api/logout', {}).pipe(
    //   tap(() => this.clearSession()),
    // );

    // Por ahora, solo limpiar en frontend
    this.clearSession();
    this.router.navigate(['/login']);
    return of(void 0);
  }

  /**
   * Obtener usuario actual
   */
  getCurrentUser(): User | null {
    return this.currentUserSubject.value;
  }

  /**
   * Verificar si está autenticado
   */
  isAuthenticated(): boolean {
    return !!this.currentUserSubject.value && !!this.getToken();
  }

  /**
   * Obtener token actual
   */
  getToken(): string | null {
    const sessionType = this.getStorage().getItem(this.SESSION_TYPE_KEY);
    const storage = sessionType === 'local' ? localStorage : sessionStorage;
    return storage.getItem(this.TOKEN_KEY);
  }

  /**
   * Verificar si tiene un rol específico
   */
  hasRole(role: UserRole): boolean {
    const user = this.getCurrentUser();
    return user?.role === role;
  }

  /**
   * Verificar si tiene un permiso específico
   */
  hasPermission(permission: Permission): boolean {
    const user = this.getCurrentUser();
    return user?.permissions?.includes(permission) ?? false;
  }

  /**
   * Verificar si tiene todos los permisos (AND)
   */
  hasAllPermissions(permissions: Permission[]): boolean {
    return permissions.every((perm) => this.hasPermission(perm));
  }

  /**
   * Verificar si tiene alguno de los permisos (OR)
   */
  hasAnyPermission(permissions: Permission[]): boolean {
    return permissions.some((perm) => this.hasPermission(perm));
  }

  /**
   * Guardar sesión en localStorage o sessionStorage
   */
  private setSession(response: AuthResponse, rememberMe: boolean): void {
    const sessionType = rememberMe ? 'local' : 'session';
    const storage = rememberMe ? localStorage : sessionStorage;

    // Guardar token y usuario
    storage.setItem(this.TOKEN_KEY, response.token);
    storage.setItem(this.USER_KEY, JSON.stringify(response.user));
    storage.setItem(this.SESSION_TYPE_KEY, sessionType);

    // Actualizar BehaviorSubject
    this.currentUserSubject.next(response.user);
  }

  /**
   * Limpiar sesión
   */
  private clearSession(): void {
    const storage = this.getStorage();
    storage.removeItem(this.TOKEN_KEY);
    storage.removeItem(this.USER_KEY);
    storage.removeItem(this.SESSION_TYPE_KEY);
    this.currentUserSubject.next(null);
  }

  /**
   * Restaurar sesión al inicializar app
   */
  private restoreSession(): void {
    const storage = this.getStorage();
    const userJson = storage.getItem(this.USER_KEY);
    const token = storage.getItem(this.TOKEN_KEY);

    if (userJson && token) {
      try {
        const user = JSON.parse(userJson) as User;
        this.currentUserSubject.next(user);
      } catch {
        // JSON inválido, limpiar sesión
        this.clearSession();
      }
    }
  }

  /**
   * Obtener el storage correcto (localStorage o sessionStorage)
   */
  private getStorage(): Storage {
    const sessionType = localStorage.getItem(this.SESSION_TYPE_KEY);
    return sessionType === 'local' ? localStorage : sessionStorage;
  }

  /**
   * Redirigir según rol después de login
   */
  redirectAfterLogin(): void {
    const user = this.getCurrentUser();
    // Por ahora redirigir a inicio (/)
    // TODO: Implementar redirección según rol si es necesario
    if (user) {
      this.router.navigate(['/']);
    }
  }

  /**
   * Manejar error 401 (sesión expirada)
   */
  handleUnauthorized(): void {
    this.clearSession();
    this.router.navigate(['/login'], {
      queryParams: { sessionExpired: true },
    });
  }

  /**
   * Obtener permisos del usuario actual
   */
  getCurrentUserPermissions(): Permission[] {
    return this.getCurrentUser()?.permissions ?? [];
  }
}
