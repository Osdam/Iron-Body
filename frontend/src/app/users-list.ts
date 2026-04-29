import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ApiService, UserSummary } from './services/api.service';

@Component({
  selector: 'users-list',
  standalone: true,
  imports: [CommonModule],
  template: `
  <section class="module-page">
    <div class="module-header">
      <div>
        <h1>Usuarios</h1>
        <p>Consulta rápida de los miembros registrados en la plataforma.</p>
      </div>
      <button class="btn btn-primary btn-sm">Nuevo usuario</button>
    </div>

    <div *ngIf="loading()" class="state-panel">Cargando usuarios...</div>
    <div *ngIf="error()" class="alert alert-danger">{{ error() }}</div>

    <div *ngIf="!loading() && !error() && users.length === 0" class="state-panel">
      No hay usuarios registrados todavía.
    </div>

    <div *ngIf="!loading() && !error() && users.length > 0" class="data-list">
      <article *ngFor="let u of users" class="data-row">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
          <div>
            <div class="fw-semibold text-white">{{ u.name }}</div>
            <div class="small text-slate-300">{{ u.email }}</div>
          </div>
          <div class="small text-slate-400">{{ u.created_at | date:'medium' }}</div>
        </div>
      </article>
    </div>
  </section>
  `
})
export class UsersList implements OnInit {
  private api = inject(ApiService);
  users: UserSummary[] = [];
  loading = signal(true);
  error = signal('');

  ngOnInit(): void {
    this.api.getUsers().subscribe({
      next: (res) => {
        this.users = res.data || [];
        this.loading.set(false);
      },
      error: () => {
        this.error.set('No se pudieron cargar los usuarios. Revisa la conexión con Laravel.');
        this.loading.set(false);
      }
    });
  }
}
