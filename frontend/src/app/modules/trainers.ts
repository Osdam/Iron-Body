import { CommonModule } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { ApiService } from '../services/api.service';
import TrainersKpiComponent from './components/trainers-kpi';
import TrainersFiltersComponent, { TrainerFilters } from './components/trainers-filters';
import TrainerCardComponent, { Trainer, TrainerAvailability } from './components/trainer-card';
import TrainersTableComponent from './components/trainers-table';
import TrainerModalComponent, { TrainerModalMode } from './components/trainer-modal';
import { LottieIconComponent } from '../shared/components/lottie-icon/lottie-icon.component';
import { AuthService } from '../services/auth.service';
import { Permission } from '../models/permissions.enum';

@Component({
  selector: 'module-trainers',
  standalone: true,
  imports: [
    CommonModule,
    TrainersKpiComponent,
    TrainersFiltersComponent,
    TrainerCardComponent,
    TrainersTableComponent,
    TrainerModalComponent,
    LottieIconComponent,
  ],
  template: `
    <section class="trainers-page">
      <header class="header">
        <div class="header-left">
          <h1>Entrenadores</h1>
          <p>
            Gestiona perfiles, especialidades, disponibilidad y asignaciones del equipo técnico.
          </p>
        </div>

        <div class="header-right">
          <button type="button" class="btn-secondary" (click)="toggleView()">
            <span class="btn-lottie">
              <app-lottie-icon
                src="/assets/crm/vistatablavistacard.json"
                [size]="22"
                [loop]="true"
              ></app-lottie-icon>
            </span>
            {{ selectedView() === 'cards' ? 'Vista tabla' : 'Vista cards' }}
          </button>

          <button *ngIf="canCreateTrainers()" type="button" class="btn-primary" (click)="openCreateTrainerModal()">
            <span class="btn-lottie">
              <app-lottie-icon src="/assets/crm/mas.json" [size]="22" [loop]="true"></app-lottie-icon>
            </span>
            Nuevo entrenador
          </button>
        </div>
      </header>

      <div *ngIf="notice() as n" class="notice" [ngClass]="'notice-' + n.kind" role="status">
        <span class="material-symbols-outlined" aria-hidden="true">{{ noticeIcon(n.kind) }}</span>
        <p class="notice-message">{{ n.message }}</p>
        <button type="button" class="notice-close" (click)="dismissNotice()" aria-label="Cerrar">
          close
        </button>
      </div>

      <section class="kpis">
        <app-trainers-kpi
          title="Entrenadores activos"
          lottie="/assets/crm/entrenadores.json"
          color="success"
          [value]="kpis().active"
          subtitle="Estado Activo"
        ></app-trainers-kpi>
        <app-trainers-kpi
          title="Clases asignadas"
          lottie="/assets/crm/rutinasasignadas.json"
          color="info"
          [value]="kpis().classes"
          subtitle="Total del equipo"
        ></app-trainers-kpi>
        <app-trainers-kpi
          title="Miembros asignados"
          lottie="/assets/crm/miembros.json"
          color="primary"
          [value]="kpis().members"
          subtitle="Bajo supervisión"
        ></app-trainers-kpi>
        <app-trainers-kpi
          title="Disponibles hoy"
          lottie="/assets/crm/hoy.json"
          color="warning"
          [value]="kpis().available"
          subtitle="Listos para entrenar"
        ></app-trainers-kpi>
      </section>

      <app-trainers-filters
        [filters]="filters()"
        (filtersChange)="onFiltersChange($event)"
      ></app-trainers-filters>

      <ng-container *ngIf="trainers().length === 0; else content">
        <section class="empty">
          <div class="empty-icon" aria-hidden="true">
            <span class="material-symbols-outlined">fitness_center</span>
          </div>
          <h2>Todavía no hay entrenadores registrados</h2>
          <p>
            Registra tu primer entrenador para gestionar especialidades, disponibilidad, clases y
            miembros asignados.
          </p>
          <button *ngIf="canCreateTrainers()" type="button" class="btn-primary" (click)="openCreateTrainerModal()">
            <span class="material-symbols-outlined" aria-hidden="true">person_add</span>
            Registrar primer entrenador
          </button>
        </section>
      </ng-container>

      <ng-template #content>
        <ng-container *ngIf="selectedView() === 'cards'">
          <section class="cards" *ngIf="filteredTrainers().length; else noResults">
            <app-trainer-card
              *ngFor="let t of filteredTrainers(); trackBy: trackTrainer"
              [trainer]="t"
              (view)="viewTrainerProfile($event)"
              (edit)="editTrainer($event)"
              (toggleStatus)="toggleTrainerStatus($event)"
              (delete)="deleteTrainer($event)"
              (bookmark)="bookmarkTrainer($event)"
            ></app-trainer-card>
          </section>

          <ng-template #noResults>
            <div class="no-results">No hay entrenadores para mostrar con los filtros actuales.</div>
          </ng-template>
        </ng-container>

        <ng-container *ngIf="selectedView() === 'table'">
          <app-trainers-table
            [trainers]="filteredTrainers()"
            (view)="viewTrainerProfile($event)"
            (edit)="editTrainer($event)"
            (toggleStatus)="toggleTrainerStatus($event)"
            (delete)="deleteTrainer($event)"
          ></app-trainers-table>
        </ng-container>
      </ng-template>

      <app-trainer-modal
        [isOpen]="isTrainerModalOpen()"
        [mode]="modalMode()"
        [trainer]="selectedTrainer()"
        [isSaving]="isSavingTrainer()"
        (close)="closeTrainerModal()"
        (save)="submitTrainer($event)"
      ></app-trainer-modal>
    </section>
  `,
  styles: [
    `
      .trainers-page {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0;
        color: #0a0a0a;
      }

      .header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 2rem;
        margin-bottom: 1.9rem;
        flex-wrap: wrap;
        padding-bottom: 1.5rem;
        border-bottom: 2px solid #f0f0f0;
      }

      .header-left h1 {
        font-family: Inter, sans-serif;
        font-size: 2.5rem;
        font-weight: 800;
        margin: 0 0 0.5rem;
        letter-spacing: -0.02em;
        line-height: 1.1;
      }

      .header-left p {
        font-size: 1.05rem;
        line-height: 1.6;
        color: #666;
        margin: 0;
        max-width: 720px;
      }

      .header-right {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        align-items: end;
      }

      .btn-primary,
      .btn-secondary {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        padding: 0.78rem 1.2rem;
        border-radius: 12px;
        font-size: 0.95rem;
        font-weight: 850;
        cursor: pointer;
        transition: all 0.2s ease;
        border: 1px solid transparent;
      }

      .btn-primary {
        background: #fbbf24;
        color: #0a0a0a;
        box-shadow: 0 10px 22px rgba(251, 191, 36, 0.2);
      }

      .btn-primary:hover {
        background: #f9a825;
        box-shadow: 0 14px 28px rgba(251, 191, 36, 0.25);
        transform: translateY(-1px);
      }

      .btn-primary:focus {
        outline: none;
        box-shadow:
          0 0 0 3px rgba(251, 191, 36, 0.12),
          0 14px 28px rgba(251, 191, 36, 0.25);
      }

      .btn-secondary {
        background: #ffffff;
        color: #0a0a0a;
        border-color: #e5e5e5;
      }

      .btn-secondary:hover {
        background: #f9f9f9;
        border-color: #d0d0d0;
      }

      .btn-lottie {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        border-radius: 7px;
        background: rgba(0, 0, 0, 0.05);
        overflow: hidden;
        flex-shrink: 0;
      }

      .btn-primary .btn-lottie {
        background: rgba(0, 0, 0, 0.08);
      }

      .kpis {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
      }

      .cards {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1.1rem;
        margin-bottom: 2rem;
      }

      .empty {
        border: 1px solid #ededed;
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 14px 34px rgba(0, 0, 0, 0.05);
        padding: 2.2rem;
        text-align: center;
      }

      .empty-icon {
        width: 62px;
        height: 62px;
        border-radius: 18px;
        display: grid;
        place-items: center;
        margin: 0 auto 1rem;
        border: 1px solid rgba(251, 191, 36, 0.45);
        background: rgba(251, 191, 36, 0.12);
      }

      .empty-icon span {
        font-size: 1.8rem;
      }

      .empty h2 {
        margin: 0;
        font-size: 1.45rem;
        font-weight: 900;
        letter-spacing: -0.01em;
      }

      .empty p {
        margin: 0.6rem auto 1.35rem;
        color: #666;
        line-height: 1.6;
        max-width: 560px;
      }

      .no-results {
        padding: 1.2rem;
        border: 1px solid #ededed;
        border-radius: 14px;
        background: #ffffff;
        color: #666;
      }

      .notice {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 1rem 1.1rem;
        border-radius: 12px;
        border: 1px solid #e5e5e5;
        background: #ffffff;
        color: #0a0a0a;
        margin: 0 0 1.4rem;
      }

      .notice .material-symbols-outlined {
        font-size: 1.35rem;
      }

      .notice-message {
        margin: 0;
        flex: 1;
        font-weight: 700;
        color: #222;
      }

      .notice-close {
        border: none;
        background: transparent;
        cursor: pointer;
        color: #666;
        font-weight: 800;
        font-size: 0.9rem;
        padding: 0.25rem 0.35rem;
        border-radius: 8px;
        transition: background 0.15s ease;
      }

      .notice-close:hover {
        background: #f3f4f6;
      }

      .notice-success {
        border-color: #bbf7d0;
        background: #f0fdf4;
      }

      .notice-info {
        border-color: #e5e5e5;
        background: #fafafa;
      }

      .notice-error {
        border-color: #fecaca;
        background: #fef2f2;
      }

      .trainers-page {
        color: #e5e2e1;
      }

      .header {
        border-color: #353534;
      }

      .header-left h1,
      .empty h2,
      .notice-message {
        color: #e5e2e1;
      }

      .header-left p,
      .empty p,
      .no-results,
      .notice-close {
        color: #b4afa6;
      }

      .btn-secondary,
      .empty,
      .no-results,
      .notice {
        background: #1c1b1b;
        border-color: #353534;
        color: #e5e2e1;
      }

      .btn-secondary:hover,
      .notice-close:hover {
        background: #201f1f;
        border-color: #f5c518;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.13);
      }

      .btn-lottie {
        background: rgba(245, 197, 24, 0.12);
      }

      .notice-success {
        border-color: rgba(34, 197, 94, 0.34);
        background: rgba(34, 197, 94, 0.12);
      }

      .notice-info {
        border-color: rgba(245, 197, 24, 0.24);
        background: rgba(245, 197, 24, 0.1);
      }

      .notice-error {
        border-color: rgba(255, 180, 171, 0.32);
        background: rgba(255, 180, 171, 0.1);
      }

      @media (max-width: 1100px) {
        .cards {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }

      @media (max-width: 900px) {
        .kpis {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }

      @media (max-width: 640px) {
        .kpis {
          grid-template-columns: 1fr;
        }
        .cards {
          grid-template-columns: 1fr;
        }
        .header-left h1 {
          font-size: 2rem;
        }
      }
    `,
  ],
})
export default class TrainersModule implements OnInit {
  private api = inject(ApiService);
  private auth = inject(AuthService);

  /**
   * Mapeo de status frontend ↔ backend.
   * Frontend usa "Activo"/"Inactivo" en español; backend usa "active"/"inactive".
   */
  private toBackendStatus(s?: string): string {
    const v = (s || '').toLowerCase();
    if (v.includes('inact')) return 'inactive';
    return 'active';
  }
  private toFrontendStatus(s?: string): string {
    const v = (s || '').toLowerCase();
    if (v === 'inactive' || v === 'inactivo') return 'Inactivo';
    return 'Activo';
  }
  private fromBackend(t: any): Trainer {
    const defaultDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    const rawAvail = Array.isArray(t?.availability) ? t.availability : [];
    const availability = defaultDays.map((day, idx) => {
      const slot = rawAvail[idx] || {};
      return {
        day: typeof slot.day === 'string' && slot.day ? slot.day : day,
        enabled: !!slot.enabled,
        startTime: slot.startTime || '08:00',
        endTime: slot.endTime || '18:00',
      };
    });
    return {
      ...t,
      id: String(t.id),
      status: this.toFrontendStatus(t.status),
      availability,
      specialties: Array.isArray(t?.specialties) ? t.specialties : [],
      assignedClasses: Number(t?.assignedClasses ?? 0),
      assignedMembers: Number(t?.assignedMembers ?? 0),
    } as Trainer;
  }

  trainers = signal<Trainer[]>([]);

  selectedView = signal<'cards' | 'table'>('cards');

  filters = signal<TrainerFilters>({
    searchTerm: '',
    status: 'all',
    specialty: 'all',
    availability: 'all',
    contractType: 'all',
  });

  notice = signal<{ kind: 'success' | 'info' | 'error'; message: string } | null>(null);

  isTrainerModalOpen = signal<boolean>(false);
  isSavingTrainer = signal<boolean>(false);
  modalMode = signal<TrainerModalMode>('create');
  selectedTrainer = signal<Trainer | null>(null);

  filteredTrainers = computed(() => this.filterTrainers(this.trainers(), this.filters()));
  kpis = computed(() => this.calculateTrainerKpis(this.filteredTrainers()));

  ngOnInit(): void {
    this.loadTrainers();
  }

  async loadTrainers(): Promise<void> {
    try {
      const list = await firstValueFrom(this.api.getTrainers());
      this.trainers.set((list || []).map((t) => this.fromBackend(t)));
    } catch (e: any) {
      // Fallback al mock solo si el backend no responde
      this.trainers.set(this.buildMockTrainers());
      this.notice.set({
        kind: 'info',
        message: 'Backend no disponible; mostrando datos de ejemplo.',
      });
    }
  }

  toggleView(): void {
    this.selectedView.set(this.selectedView() === 'cards' ? 'table' : 'cards');
  }

  onFiltersChange(next: TrainerFilters): void {
    this.filters.set(next);
  }

  openCreateTrainerModal(): void {
    if (!this.requirePermission(Permission.TRAINERS_CREATE, 'No tienes permiso para crear entrenadores.')) return;
    this.dismissNotice();
    this.modalMode.set('create');
    this.selectedTrainer.set(null);
    this.isTrainerModalOpen.set(true);
  }

  closeTrainerModal(): void {
    if (this.isSavingTrainer()) return;
    this.isTrainerModalOpen.set(false);
    this.selectedTrainer.set(null);
  }

  viewTrainerProfile(trainer: Trainer): void {
    this.dismissNotice();
    this.modalMode.set('detail');
    this.selectedTrainer.set(trainer);
    this.isTrainerModalOpen.set(true);
  }

  editTrainer(trainer: Trainer): void {
    if (!this.requirePermission(Permission.TRAINERS_EDIT, 'No tienes permiso para editar entrenadores.')) return;
    this.dismissNotice();
    this.modalMode.set('edit');
    this.selectedTrainer.set(trainer);
    this.isTrainerModalOpen.set(true);
  }

  async submitTrainer(payload: Partial<Trainer>): Promise<void> {
    const mode = this.modalMode();
    const permission = mode === 'edit' ? Permission.TRAINERS_EDIT : Permission.TRAINERS_CREATE;
    if (!this.requirePermission(permission, 'No tienes permiso para guardar entrenadores.')) return;
    this.isSavingTrainer.set(true);
    this.notice.set(null);

    try {
      const body: any = {
        fullName: String(payload.fullName || '').trim(),
        document: payload.document || null,
        phone: payload.phone || null,
        email: payload.email || null,
        birthDate: payload.birthDate || null,
        mainSpecialty: payload.mainSpecialty || null,
        specialties: payload.specialties || [],
        experienceYears: Number(payload.experienceYears || 0),
        contractType: payload.contractType || null,
        status: this.toBackendStatus(payload.status),
        rating: Number(payload.rating || 0),
        bio: payload.bio || null,
        certifications: payload.certifications || null,
        availability: this.normalizeAvailability(payload.availability || []),
      };

      if (mode === 'edit') {
        const current = this.selectedTrainer();
        if (!current) throw new Error('Entrenador no encontrado para edición.');

        const updated = (await firstValueFrom(
          this.api.updateTrainer(current.id, body),
        )) as any;
        const mapped = this.fromBackend(updated);
        this.trainers.set(this.trainers().map((t) => (t.id === current.id ? mapped : t)));
        this.notice.set({ kind: 'success', message: 'Entrenador actualizado correctamente.' });
        this.closeTrainerModal();
        return;
      }

      const created = (await firstValueFrom(this.api.createTrainer(body))) as any;
      const mapped = this.fromBackend(created);
      this.trainers.set([mapped, ...this.trainers()]);
      this.notice.set({ kind: 'success', message: 'Entrenador registrado correctamente.' });
      this.closeTrainerModal();
    } catch (e: any) {
      const msg =
        e?.error?.message ||
        (e?.status === 422 ? 'Datos inválidos. Revisa el formulario.' : null) ||
        e?.message ||
        'No se pudo guardar el entrenador.';
      this.notice.set({ kind: 'error', message: msg });
    } finally {
      this.isSavingTrainer.set(false);
    }
  }

  async toggleTrainerStatus(trainer: Trainer): Promise<void> {
    if (!this.requirePermission(Permission.TRAINERS_EDIT, 'No tienes permiso para cambiar estado de entrenadores.')) return;
    const current = (trainer.status || '').toLowerCase();
    const nextLabel = current.includes('inactivo') ? 'Activo' : 'Inactivo';
    const nextBackend = this.toBackendStatus(nextLabel);
    try {
      const updated = (await firstValueFrom(
        this.api.updateTrainer(trainer.id, { status: nextBackend }),
      )) as any;
      const mapped = this.fromBackend(updated);
      this.trainers.set(this.trainers().map((t) => (t.id === trainer.id ? mapped : t)));
      this.notice.set({ kind: 'success', message: `Estado actualizado a ${nextLabel}.` });
    } catch (e: any) {
      this.notice.set({
        kind: 'error',
        message: e?.error?.message || e?.message || 'No se pudo cambiar el estado.',
      });
    }
  }

  async deleteTrainer(trainer: Trainer): Promise<void> {
    if (!this.requirePermission(Permission.TRAINERS_DELETE, 'No tienes permiso para eliminar entrenadores.')) return;
    const ok = window.confirm(
      `¿Eliminar el entrenador "${trainer.fullName}"? Esta acción no se puede deshacer.`,
    );
    if (!ok) return;
    try {
      await firstValueFrom(this.api.deleteTrainer(trainer.id));
      this.trainers.set(this.trainers().filter((t) => t.id !== trainer.id));
      this.notice.set({ kind: 'success', message: 'Entrenador eliminado.' });
    } catch (e: any) {
      this.notice.set({
        kind: 'error',
        message: e?.error?.message || e?.message || 'No se pudo eliminar el entrenador.',
      });
    }
  }

  bookmarkTrainer(trainer: Trainer): void {
    this.notice.set({ kind: 'info', message: `${trainer.fullName} marcado como favorito.` });
  }

  trackTrainer = (_: number, t: Trainer) => t.id;

  dismissNotice(): void {
    this.notice.set(null);
  }

  canCreateTrainers(): boolean {
    return this.auth.hasPermission(Permission.TRAINERS_CREATE);
  }

  private requirePermission(permission: Permission, message: string): boolean {
    if (this.auth.hasPermission(permission)) return true;
    this.notice.set({ kind: 'error', message });
    return false;
  }

  noticeIcon(kind: 'success' | 'info' | 'error'): string {
    if (kind === 'success') return 'check_circle';
    if (kind === 'error') return 'error';
    return 'info';
  }

  private filterTrainers(trainers: Trainer[], filters: TrainerFilters): Trainer[] {
    const term = (filters.searchTerm || '').trim().toLowerCase();
    const status = String(filters.status || 'all');
    const specialty = String(filters.specialty || 'all');
    const availability = String(filters.availability || 'all');
    const contractType = String(filters.contractType || 'all');

    return (trainers || []).filter((t) => {
      const name = (t.fullName || '').toLowerCase();
      const doc = (t.document || '').toLowerCase();
      const email = (t.email || '').toLowerCase();
      const phone = (t.phone || '').toLowerCase();
      const mainSpec = (t.mainSpecialty || '').toLowerCase();
      const specs = ((t.specialties || []) as string[])
        .map((s) => (s || '').toLowerCase())
        .join(' ');

      const matchesTerm =
        !term ||
        name.includes(term) ||
        doc.includes(term) ||
        email.includes(term) ||
        phone.includes(term) ||
        mainSpec.includes(term) ||
        specs.includes(term);

      const matchesStatus = status === 'all' || (t.status || '') === status;
      const matchesSpecialty = specialty === 'all' || (t.mainSpecialty || '') === specialty;

      let matchesAvailability = true;
      if (availability !== 'all') {
        const hasAvail = (t.availability || []).filter((a) => a.enabled).length > 0;
        if (availability === 'Disponible') matchesAvailability = hasAvail;
        if (availability === 'Sin horario') matchesAvailability = !hasAvail;
      }

      const matchesContractType = contractType === 'all' || (t.contractType || '') === contractType;

      return (
        matchesTerm &&
        matchesStatus &&
        matchesSpecialty &&
        matchesAvailability &&
        matchesContractType
      );
    });
  }

  private calculateTrainerKpis(trainers: Trainer[]): {
    active: number;
    classes: number;
    members: number;
    available: number;
  } {
    const list = trainers || [];
    const active = list.filter((t) => String(t.status || '').toLowerCase() === 'activo').length;
    const classes = list.reduce((sum, t) => sum + (t.assignedClasses || 0), 0);
    const members = list.reduce((sum, t) => sum + (t.assignedMembers || 0), 0);
    const available = list.filter(
      (t) => (t.availability || []).filter((a) => a.enabled).length > 0,
    ).length;

    return { active, classes, members, available };
  }

  private normalizeAvailability(availability: any[]): TrainerAvailability[] {
    const list: any[] = Array.isArray(availability) ? availability : [];
    return list.map((a) => ({
      day: String(a.day || ''),
      enabled: Boolean(a.enabled),
      startTime: String(a.startTime || '08:00'),
      endTime: String(a.endTime || '18:00'),
    }));
  }

  private newId(prefix: string): string {
    const rand = Math.random().toString(16).slice(2, 10);
    return `${prefix}_${Date.now()}_${rand}`;
  }

  private buildMockTrainers(): Trainer[] {
    const now = new Date().toISOString();
    return [
      {
        id: this.newId('trainer'),
        fullName: 'Carlos Ruiz',
        document: '1020304050',
        phone: '3001234567',
        email: 'carlos.ruiz@ironbody.com',
        birthDate: '1989-03-15',
        mainSpecialty: 'Musculación',
        specialties: ['Musculación', 'Fuerza', 'Entrenamiento personalizado'],
        experienceYears: 6,
        contractType: 'Tiempo completo',
        status: 'Activo',
        rating: 4.8,
        bio: 'Especialista en hipertrofia y desarrollo de fuerza. Experiencia de 6 años con atletas de competencia.',
        certifications: 'Entrenamiento funcional, nutrición deportiva, primeros auxilios',
        availability: [
          { day: 'Monday', enabled: true, startTime: '06:00', endTime: '14:00' },
          { day: 'Tuesday', enabled: true, startTime: '06:00', endTime: '14:00' },
          { day: 'Wednesday', enabled: true, startTime: '06:00', endTime: '14:00' },
          { day: 'Thursday', enabled: true, startTime: '06:00', endTime: '14:00' },
          { day: 'Friday', enabled: true, startTime: '06:00', endTime: '14:00' },
          { day: 'Saturday', enabled: false, startTime: '08:00', endTime: '18:00' },
          { day: 'Sunday', enabled: false, startTime: '08:00', endTime: '18:00' },
        ],
        assignedClasses: 5,
        assignedMembers: 24,
        createdAt: now,
        updatedAt: now,
      },
      {
        id: this.newId('trainer'),
        fullName: 'Laura Gómez',
        document: '1010203040',
        phone: '3009876543',
        email: 'laura.gomez@ironbody.com',
        birthDate: '1991-07-22',
        mainSpecialty: 'Funcional',
        specialties: ['Funcional', 'Cardio', 'Cross Training'],
        experienceYears: 4,
        contractType: 'Medio tiempo',
        status: 'Activo',
        rating: 4.7,
        bio: 'Entrenadora funcional con enfoque en entrenamiento en circuito. Certificada en CrossFit.',
        certifications: 'CrossFit Level 2, nutrición deportiva',
        availability: [
          { day: 'Monday', enabled: true, startTime: '14:00', endTime: '22:00' },
          { day: 'Tuesday', enabled: false, startTime: '08:00', endTime: '18:00' },
          { day: 'Wednesday', enabled: true, startTime: '14:00', endTime: '22:00' },
          { day: 'Thursday', enabled: false, startTime: '08:00', endTime: '18:00' },
          { day: 'Friday', enabled: true, startTime: '14:00', endTime: '22:00' },
          { day: 'Saturday', enabled: true, startTime: '08:00', endTime: '14:00' },
          { day: 'Sunday', enabled: false, startTime: '08:00', endTime: '18:00' },
        ],
        assignedClasses: 4,
        assignedMembers: 18,
        createdAt: now,
        updatedAt: now,
      },
      {
        id: this.newId('trainer'),
        fullName: 'Andrés Martínez',
        document: '1087654321',
        phone: '3005551234',
        email: 'andres.martinez@ironbody.com',
        birthDate: '1985-11-08',
        mainSpecialty: 'Cross Training',
        specialties: ['Cross Training', 'Boxeo', 'Fuerza'],
        experienceYears: 8,
        contractType: 'Por horas',
        status: 'Pendiente',
        rating: 4.5,
        bio: 'Entrenador de Cross Training y boxeo con experiencia en competiciones internacionales.',
        certifications: 'Boxeo profesional, CrossFit, entrenamiento táctico',
        availability: [
          { day: 'Monday', enabled: false, startTime: '08:00', endTime: '18:00' },
          { day: 'Tuesday', enabled: true, startTime: '18:00', endTime: '22:00' },
          { day: 'Wednesday', enabled: false, startTime: '08:00', endTime: '18:00' },
          { day: 'Thursday', enabled: true, startTime: '18:00', endTime: '22:00' },
          { day: 'Friday', enabled: false, startTime: '08:00', endTime: '18:00' },
          { day: 'Saturday', enabled: true, startTime: '10:00', endTime: '14:00' },
          { day: 'Sunday', enabled: false, startTime: '08:00', endTime: '18:00' },
        ],
        assignedClasses: 2,
        assignedMembers: 10,
        createdAt: now,
        updatedAt: now,
      },
    ];
  }
}
