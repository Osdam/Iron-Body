import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterModule } from '@angular/router';
import { CommonModule } from '@angular/common';
import { ApiService, DashboardStats } from './services/api.service';

@Component({
  selector: 'app-home',
  standalone: true,
  imports: [RouterModule, CommonModule],
  template: `
    <div class="p-4">
      <div class="mb-5">
        <h1 class="display-5 fw-bold mb-2">Panel de Control</h1>
        <p class="text-muted">Bienvenido al panel administrativo de Iron Body. Gestiona toda tu plataforma desde aquí.</p>
      </div>

      <!-- KPIs Grid -->
      <div class="row g-3 mb-5">
        <div class="col-md-3 col-sm-6">
          <div class="card text-center border-0" style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(29, 78, 216, 0.1));">
            <div class="card-body py-4">
              <div style="font-size: 2rem;">👥</div>
              <h6 class="card-title text-muted mt-2 mb-1">Usuarios Activos</h6>
              <h2 class="fw-bold" style="color: #60a5fa;">{{ stats().users }}</h2>
              <small class="text-success">Registrados en el sistema</small>
            </div>
          </div>
        </div>

        <div class="col-md-3 col-sm-6">
          <div class="card text-center border-0" style="background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(22, 163, 74, 0.1));">
            <div class="card-body py-4">
              <div style="font-size: 2rem;">💰</div>
              <h6 class="card-title text-muted mt-2 mb-1">Ingresos Confirmados</h6>
              <h2 class="fw-bold" style="color: #22c55e;">{{ stats().revenue | currency:'USD':'symbol':'1.0-0' }}</h2>
              <small class="text-success">Pagos completados</small>
            </div>
          </div>
        </div>

        <div class="col-md-3 col-sm-6">
          <div class="card text-center border-0" style="background: linear-gradient(135deg, rgba(249, 115, 22, 0.1), rgba(234, 88, 12, 0.1));">
            <div class="card-body py-4">
              <div style="font-size: 2rem;">📅</div>
              <h6 class="card-title text-muted mt-2 mb-1">Planes Activos</h6>
              <h2 class="fw-bold" style="color: #f97316;">{{ stats().active_plans }}</h2>
              <small class="text-warning">Disponibles para venta</small>
            </div>
          </div>
        </div>

        <div class="col-md-3 col-sm-6">
          <div class="card text-center border-0" style="background: linear-gradient(135deg, rgba(236, 72, 153, 0.1), rgba(219, 39, 119, 0.1));">
            <div class="card-body py-4">
              <div style="font-size: 2rem;">🎯</div>
              <h6 class="card-title text-muted mt-2 mb-1">Pagos Registrados</h6>
              <h2 class="fw-bold" style="color: #ec4899;">{{ stats().payments }}</h2>
              <small class="text-danger">Historial total</small>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="row g-4">
        <div class="col-lg-6">
          <div class="card border-0" style="background: rgba(51, 65, 85, 0.3);">
            <div class="card-header border-0 py-3">
              <h5 class="card-title fw-bold mb-0">Accesos Rápidos</h5>
            </div>
            <div class="card-body">
              <div class="row g-2">
                <div class="col-6">
                  <a routerLink="/users" class="btn btn-outline-primary btn-sm w-100 py-2">
                    👥 Gestionar Usuarios
                  </a>
                </div>
                <div class="col-6">
                  <a routerLink="/plans" class="btn btn-outline-success btn-sm w-100 py-2">
                    🎯 Planes y Membresías
                  </a>
                </div>
                <div class="col-6">
                  <a routerLink="/payments" class="btn btn-outline-info btn-sm w-100 py-2">
                    💰 Registrar Pago
                  </a>
                </div>
                <div class="col-6">
                  <a routerLink="/classes" class="btn btn-outline-warning btn-sm w-100 py-2">
                    📅 Gestionar Clases
                  </a>
                </div>
                <div class="col-6">
                  <a routerLink="/routines" class="btn btn-outline-danger btn-sm w-100 py-2">
                    🏋️ Rutinas
                  </a>
                </div>
                <div class="col-6">
                  <a routerLink="/trainers" class="btn btn-outline-secondary btn-sm w-100 py-2">
                    👨‍🏫 Entrenadores
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="card border-0" style="background: rgba(51, 65, 85, 0.3);">
            <div class="card-header border-0 py-3">
              <h5 class="card-title fw-bold mb-0">Tareas Pendientes</h5>
            </div>
            <div class="card-body">
              <div class="list-group list-group-flush">
                <a href="#" class="list-group-item list-group-item-action border-0 px-0 py-3">
                  <div class="d-flex justify-content-between align-items-start">
                    <div>
                      <h6 class="mb-1">47 membresías por vencer</h6>
                      <small class="text-muted">Enviar recordatorios de renovación</small>
                    </div>
                    <span class="badge bg-danger">Hoy</span>
                  </div>
                </a>
                <a href="#" class="list-group-item list-group-item-action border-0 px-0 py-3">
                  <div class="d-flex justify-content-between align-items-start">
                    <div>
                      <h6 class="mb-1">12 pagos pendientes</h6>
                      <small class="text-muted">Realizar seguimiento de cobranza</small>
                    </div>
                    <span class="badge bg-warning text-dark">Urgente</span>
                  </div>
                </a>
                <a href="#" class="list-group-item list-group-item-action border-0 px-0 py-3">
                  <div class="d-flex justify-content-between align-items-start">
                    <div>
                      <h6 class="mb-1">3 clases sin entrenador</h6>
                      <small class="text-muted">Asignar personal de entrenamiento</small>
                    </div>
                    <span class="badge bg-info">Normal</span>
                  </div>
                </a>
                <a href="#" class="list-group-item list-group-item-action border-0 px-0 py-3">
                  <div class="d-flex justify-content-between align-items-start">
                    <div>
                      <h6 class="mb-1">Generar reportes mensuales</h6>
                      <small class="text-muted">Análisis de ingresos y usuarios</small>
                    </div>
                    <span class="badge bg-success">Planificado</span>
                  </div>
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Modules Overview -->
      <div class="mt-5">
        <h5 class="fw-bold mb-4">Módulos del Sistema</h5>
        <div class="row g-3">
          <div class="col-md-4 col-lg-3">
            <div class="card h-100 border-0 shadow-sm" style="background: rgba(51, 65, 85, 0.3);">
              <div class="card-body text-center">
                <div style="font-size: 2.5rem; margin-bottom: 1rem;">👥</div>
                <h6 class="card-title">Usuarios</h6>
                <p class="card-text small text-muted">Gestión completa de perfiles, membresías y accesos.</p>
                <a routerLink="/users" class="btn btn-sm btn-primary">Acceder</a>
              </div>
            </div>
          </div>

          <div class="col-md-4 col-lg-3">
            <div class="card h-100 border-0 shadow-sm" style="background: rgba(51, 65, 85, 0.3);">
              <div class="card-body text-center">
                <div style="font-size: 2.5rem; margin-bottom: 1rem;">💰</div>
                <h6 class="card-title">Pagos</h6>
                <p class="card-text small text-muted">Registro y seguimiento de transacciones.</p>
                <a routerLink="/payments" class="btn btn-sm btn-primary">Acceder</a>
              </div>
            </div>
          </div>

          <div class="col-md-4 col-lg-3">
            <div class="card h-100 border-0 shadow-sm" style="background: rgba(51, 65, 85, 0.3);">
              <div class="card-body text-center">
                <div style="font-size: 2.5rem; margin-bottom: 1rem;">🎯</div>
                <h6 class="card-title">Planes</h6>
                <p class="card-text small text-muted">Configuración de membresías y beneficios.</p>
                <a routerLink="/plans" class="btn btn-sm btn-primary">Acceder</a>
              </div>
            </div>
          </div>

          <div class="col-md-4 col-lg-3">
            <div class="card h-100 border-0 shadow-sm" style="background: rgba(51, 65, 85, 0.3);">
              <div class="card-body text-center">
                <div style="font-size: 2.5rem; margin-bottom: 1rem;">🏋️</div>
                <h6 class="card-title">Rutinas</h6>
                <p class="card-text small text-muted">Ejercicios, rutinas y progreso físico.</p>
                <a routerLink="/routines" class="btn btn-sm btn-primary">Acceder</a>
              </div>
            </div>
          </div>

          <div class="col-md-4 col-lg-3">
            <div class="card h-100 border-0 shadow-sm" style="background: rgba(51, 65, 85, 0.3);">
              <div class="card-body text-center">
                <div style="font-size: 2.5rem; margin-bottom: 1rem;">📅</div>
                <h6 class="card-title">Clases</h6>
                <p class="card-text small text-muted">Horarios, reservas y asistencia.</p>
                <a routerLink="/classes" class="btn btn-sm btn-primary">Acceder</a>
              </div>
            </div>
          </div>

          <div class="col-md-4 col-lg-3">
            <div class="card h-100 border-0 shadow-sm" style="background: rgba(51, 65, 85, 0.3);">
              <div class="card-body text-center">
                <div style="font-size: 2.5rem; margin-bottom: 1rem;">👨‍🏫</div>
                <h6 class="card-title">Entrenadores</h6>
                <p class="card-text small text-muted">Perfiles y asignaciones de staff.</p>
                <a routerLink="/trainers" class="btn btn-sm btn-primary">Acceder</a>
              </div>
            </div>
          </div>

          <div class="col-md-4 col-lg-3">
            <div class="card h-100 border-0 shadow-sm" style="background: rgba(51, 65, 85, 0.3);">
              <div class="card-body text-center">
                <div style="font-size: 2.5rem; margin-bottom: 1rem;">📢</div>
                <h6 class="card-title">Marketing</h6>
                <p class="card-text small text-muted">Campañas y comunicación con usuarios.</p>
                <a routerLink="/marketing" class="btn btn-sm btn-primary">Acceder</a>
              </div>
            </div>
          </div>

          <div class="col-md-4 col-lg-3">
            <div class="card h-100 border-0 shadow-sm" style="background: rgba(51, 65, 85, 0.3);">
              <div class="card-body text-center">
                <div style="font-size: 2.5rem; margin-bottom: 1rem;">📈</div>
                <h6 class="card-title">Reportes</h6>
                <p class="card-text small text-muted">Analítica y métricas del negocio.</p>
                <a routerLink="/reports" class="btn btn-sm btn-primary">Acceder</a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    :host ::ng-deep .btn-outline-primary {
      color: #60a5fa;
      border-color: #60a5fa;
    }
    :host ::ng-deep .btn-outline-primary:hover {
      background-color: #60a5fa;
      border-color: #60a5fa;
    }
  `]
})
export default class HomeComponent implements OnInit {
  private api = inject(ApiService);
  protected stats = signal<DashboardStats>({
    users: 0,
    active_plans: 0,
    payments: 0,
    revenue: 0
  });

  ngOnInit(): void {
    this.api.getDashboardStats().subscribe({
      next: (stats) => this.stats.set(stats),
      error: () => {
        this.stats.set({
          users: 0,
          active_plans: 0,
          payments: 0,
          revenue: 0
        });
      }
    });
  }
}
