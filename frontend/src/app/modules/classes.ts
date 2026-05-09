import { CommonModule } from '@angular/common';
import { Component, OnInit, signal, Signal, computed, inject } from '@angular/core';
import { ReactiveFormsModule, FormBuilder, FormGroup } from '@angular/forms';
import { ApiService, ClassSummary } from '../services/api.service';
import { ClassesKPIComponent } from './components/classes-kpi';
import { ClassCardComponent, ClassCardData } from './components/class-card';
import { ClassesEmptyComponent } from './components/classes-empty';
import { CreateClassModalComponent } from './components/create-class-modal';
import { ClassCalendarComponent } from './components/class-calendar';
import { LottieIconComponent } from '../shared/components/lottie-icon/lottie-icon.component';

interface ClassExtended extends ClassSummary {
  trainerName?: string;
  date?: string;
}

type ViewType = 'calendar' | 'cards';

@Component({
  selector: 'module-classes',
  standalone: true,
  imports: [
    CommonModule,
    ReactiveFormsModule,
    ClassesKPIComponent,
    ClassCardComponent,
    ClassesEmptyComponent,
    CreateClassModalComponent,
    ClassCalendarComponent,
    LottieIconComponent,
  ],
  template: `
    <div class="classes-container">
      <!-- Header -->
      <div class="module-header">
        <div class="header-content">
          <div class="header-title">
            <h1>Gestión de Clases</h1>
            <p>Programa, organiza e inscribe miembros en las clases de tu gimnasio</p>
          </div>
        </div>
        <button class="btn-create" (click)="openCreateModal()">
          <span class="material-symbols-outlined" aria-hidden="true">add</span>
          Crear clase
        </button>
      </div>

      <!-- KPI Cards -->
      <div class="kpi-section">
        <app-classes-kpi
          label="Clases Activas"
          lottie="/assets/crm/clasesactivas.json"
          [value]="activeClassesCount()"
          suffix="clases"
          color="primary"
        ></app-classes-kpi>

        <app-classes-kpi
          label="Hoy"
          lottie="/assets/crm/hoy.json"
          [value]="todayClassesCount()"
          suffix="clases"
          color="success"
        ></app-classes-kpi>

        <app-classes-kpi
          label="Slots Disponibles"
          lottie="/assets/crm/curps.json"
          [value]="availableSlotsCount()"
          suffix="cupos"
          color="warning"
        ></app-classes-kpi>

        <app-classes-kpi
          label="Inscritos Totales"
          lottie="/assets/crm/insctiros.json"
          [value]="totalEnrolledCount()"
          suffix="personas"
          color="primary"
        ></app-classes-kpi>
      </div>

      <!-- Filters -->
      <div class="filters-section">
        <div class="search-box">
          <span class="material-symbols-outlined" aria-hidden="true">search</span>
          <input
            type="text"
            placeholder="Buscar por nombre, tipo o entrenador..."
            (input)="onSearchChange($event)"
            class="search-input"
            aria-label="Buscar clases"
          />
        </div>

        <div class="filter-group">
          <select
            (change)="onStatusFilterChange($event)"
            class="filter-select"
            aria-label="Filtrar por estado"
          >
            <option value="">Todos los estados</option>
            <option value="active">Activas</option>
            <option value="inactive">Inactivas</option>
          </select>

          <select
            (change)="onDayFilterChange($event)"
            class="filter-select"
            aria-label="Filtrar por día"
          >
            <option value="">Todos los días</option>
            <option value="Lunes">Lunes</option>
            <option value="Martes">Martes</option>
            <option value="Miércoles">Miércoles</option>
            <option value="Jueves">Jueves</option>
            <option value="Viernes">Viernes</option>
            <option value="Sábado">Sábado</option>
            <option value="Domingo">Domingo</option>
          </select>

          <select
            (change)="onTypeFilterChange($event)"
            class="filter-select"
            aria-label="Filtrar por tipo"
          >
            <option value="">Todos los tipos</option>
            <option value="Spinning">Spinning</option>
            <option value="Funcional">Funcional</option>
            <option value="Cross Training">Cross Training</option>
            <option value="Yoga">Yoga</option>
            <option value="Pilates">Pilates</option>
            <option value="Boxeo">Boxeo</option>
            <option value="Cardio">Cardio</option>
          </select>

          <button
            class="btn-reset-filters"
            (click)="resetFilters()"
            [disabled]="!hasActiveFilters()"
            aria-label="Limpiar filtros"
          >
            <span class="material-symbols-outlined" aria-hidden="true">clear</span>
          </button>
        </div>
      </div>

      <!-- View Toggle -->
      <div class="view-toggle">
        <button
          (click)="setView('calendar')"
          [class.active]="currentView() === 'calendar'"
          class="toggle-btn"
          aria-label="Vista calendario"
        >
          <span class="toggle-lottie">
            <app-lottie-icon
              src="/assets/crm/calendario.json"
              [size]="22"
              [loop]="true"
            ></app-lottie-icon>
          </span>
          Calendario
        </button>
        <button
          (click)="setView('cards')"
          [class.active]="currentView() === 'cards'"
          class="toggle-btn"
          aria-label="Vista tarjetas"
        >
          <span class="toggle-lottie">
            <app-lottie-icon
              src="/assets/crm/vistatablavistacard.json"
              [size]="22"
              [loop]="true"
            ></app-lottie-icon>
          </span>
          Tarjetas
        </button>
      </div>

      <!-- Content Area -->
      <div class="content-area">
        <!-- Empty State -->
        <app-classes-empty
          *ngIf="!isLoading() && filteredClasses().length === 0"
          (onCreate)="openCreateModal()"
        ></app-classes-empty>

        <!-- Calendar View -->
        <app-class-calendar
          *ngIf="!isLoading() && filteredClasses().length > 0 && currentView() === 'calendar'"
          [classes]="calendarClasses()"
        ></app-class-calendar>

        <!-- Cards View -->
        <div
          *ngIf="!isLoading() && filteredClasses().length > 0 && currentView() === 'cards'"
          class="cards-grid"
        >
          <app-class-card
            *ngFor="let cls of filteredClasses()"
            [class]="mapToCardData(cls)"
            (onEdit)="editClass(cls)"
            (onViewEnrollments)="viewEnrollments(cls)"
            (onDuplicate)="duplicateClass(cls)"
            (onToggleStatus)="toggleClassStatus(cls)"
            (onDelete)="deleteClass(cls)"
          ></app-class-card>
        </div>

        <!-- Loading State -->
        <div *ngIf="isLoading()" class="loading-state">
          <div class="spinner"></div>
          <p>Cargando clases...</p>
        </div>
      </div>
    </div>

    <!-- Create Class Modal -->
    <app-create-class-modal
      [isOpen]="isModalOpen"
      [classToEdit]="classBeingEdited()"
      (onClose)="closeModal()"
      (onClassCreated)="handleClassCreated($event)"
    ></app-create-class-modal>

    <div
      *ngIf="enrollmentClass()"
      class="enrollment-backdrop"
      (click)="closeEnrollments()"
      aria-hidden="true"
    ></div>
    <section *ngIf="enrollmentClass() as cls" class="enrollment-modal" role="dialog" aria-modal="true">
      <header class="enrollment-header">
        <div>
          <h2>Inscritos</h2>
          <p>{{ cls.name }} · {{ cls.day_of_week }} {{ cls.start_time }} - {{ cls.end_time }}</p>
        </div>
        <button type="button" class="enrollment-close" (click)="closeEnrollments()" aria-label="Cerrar">
          <span class="material-symbols-outlined" aria-hidden="true">close</span>
        </button>
      </header>

      <div class="enrollment-body">
        <div class="enrollment-stat">
          <strong>{{ cls.enrolled_count || 0 }}</strong>
          <span>Inscritos</span>
        </div>
        <div class="enrollment-stat">
          <strong>{{ cls.max_capacity || 0 }}</strong>
          <span>Cupos totales</span>
        </div>
        <div class="enrollment-stat">
          <strong>{{ remainingSlots(cls) }}</strong>
          <span>Disponibles</span>
        </div>
      </div>

      <div class="enrollment-actions">
        <button type="button" class="btn-secondary" (click)="adjustEnrollment(cls, -1)" [disabled]="(cls.enrolled_count || 0) <= 0">
          <span class="material-symbols-outlined" aria-hidden="true">remove</span>
          Quitar inscrito
        </button>
        <button type="button" class="btn-primary" (click)="adjustEnrollment(cls, 1)" [disabled]="remainingSlots(cls) <= 0">
          <span class="material-symbols-outlined" aria-hidden="true">add</span>
          Agregar inscrito
        </button>
      </div>
    </section>
  `,
  styles: [
    `
      .classes-container {
        width: 100%;
        min-width: 0;
        max-width: 1400px;
        margin: 0 auto;
        box-sizing: border-box;
        padding: 2rem;
        background:
          linear-gradient(rgba(248, 248, 248, 0.80), rgba(248, 248, 248, 0.80)),
          url('/assets/crm/fondoclases.png') center / cover no-repeat;
        border-radius: 16px;
        min-height: 100vh;
      }

      .toggle-lottie {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        border-radius: 7px;
        background: rgba(0, 0, 0, 0.05);
        overflow: hidden;
      }

      .toggle-btn.active .toggle-lottie {
        background: rgba(0, 0, 0, 0.08);
      }

      .module-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 2rem;
        margin-bottom: 2.5rem;
      }

      .header-content {
        flex: 1;
      }

      .header-title h1 {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 2rem;
        font-weight: 700;
        color: #0a0a0a;
        margin: 0 0 0.5rem;
        letter-spacing: -0.02em;
      }

      .header-title p {
        font-size: 0.95rem;
        color: #666;
        margin: 0;
        line-height: 1.5;
      }

      .btn-create {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.875rem 1.75rem;
        background: #facc15;
        color: #000;
        border: none;
        border-radius: 10px;
        font-weight: 700;
        font-family: Inter, sans-serif;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 200ms ease;
        box-shadow: 0 2px 8px rgba(250, 204, 21, 0.2);
        white-space: nowrap;
      }

      .btn-create:hover {
        background: #f0c00e;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(250, 204, 21, 0.3);
      }

      .btn-create span {
        font-size: 1.2rem;
      }

      .kpi-section {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.5rem;
        margin-bottom: 2rem;
      }

      @media (max-width: 1200px) {
        .kpi-section {
          grid-template-columns: repeat(2, 1fr);
        }
      }

      @media (max-width: 640px) {
        .kpi-section {
          grid-template-columns: 1fr;
        }

        .module-header {
          flex-direction: column;
        }

        .btn-create {
          width: 100%;
          justify-content: center;
        }
      }

      .filters-section {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        margin-bottom: 2rem;
        background: #fff;
        padding: 1.5rem;
        border-radius: 12px;
        border: 1px solid #e5e5e5;
      }

      .search-box {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0 1rem;
        border: 1px solid #e5e5e5;
        border-radius: 8px;
        background: #f9f9f9;
        transition: all 200ms ease;
      }

      .search-box:focus-within {
        border-color: #facc15;
        background: #fff;
      }

      .search-box span {
        color: #999;
        font-size: 1.2rem;
      }

      .search-input {
        flex: 1;
        border: none;
        background: transparent;
        padding: 0.875rem 0;
        font-family: Inter, sans-serif;
        font-size: 0.95rem;
        color: #0a0a0a;
        outline: none;
      }

      .search-input::placeholder {
        color: #999;
      }

      .filter-group {
        display: flex;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
      }

      .filter-select {
        padding: 0.75rem 1rem;
        border: 1px solid #e5e5e5;
        border-radius: 8px;
        background: #fff;
        font-family: Inter, sans-serif;
        font-size: 0.9rem;
        color: #0a0a0a;
        cursor: pointer;
        transition: all 200ms ease;
      }

      .filter-select:hover,
      .filter-select:focus {
        border-color: #facc15;
      }

      .btn-reset-filters {
        padding: 0.75rem 1rem;
        border: 1.5px solid #e5e5e5;
        border-radius: 8px;
        background: #fff;
        color: #999;
        cursor: pointer;
        transition: all 200ms ease;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 600;
        font-size: 0.85rem;
      }

      .btn-reset-filters:hover:not(:disabled) {
        border-color: #facc15;
        color: #ca8a04;
      }

      .btn-reset-filters:disabled {
        opacity: 0.5;
        cursor: not-allowed;
      }

      .enrollment-backdrop {
        position: fixed;
        inset: 0;
        z-index: 70;
        background: rgba(15, 23, 42, 0.52);
        backdrop-filter: blur(3px);
      }

      .enrollment-modal {
        position: fixed;
        left: 50%;
        top: 50%;
        z-index: 80;
        width: min(92vw, 520px);
        transform: translate(-50%, -50%);
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 24px 70px rgba(15, 23, 42, 0.25);
        overflow: hidden;
      }

      .enrollment-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.35rem 1.5rem;
        border-bottom: 1px solid #f0f0f0;
        background: linear-gradient(135deg, rgba(250, 204, 21, 0.2), #ffffff);
      }

      .enrollment-header h2 {
        margin: 0;
        color: #111827;
        font: 900 1.25rem Inter, sans-serif;
      }

      .enrollment-header p {
        margin: 0.25rem 0 0;
        color: #52525b;
        font: 650 0.9rem Inter, sans-serif;
      }

      .enrollment-close {
        width: 38px;
        height: 38px;
        display: grid;
        place-items: center;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #fff;
        cursor: pointer;
      }

      .enrollment-body {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.85rem;
        padding: 1.25rem 1.5rem;
      }

      .enrollment-stat {
        display: grid;
        gap: 0.25rem;
        padding: 1rem;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #fafafa;
        text-align: center;
      }

      .enrollment-stat strong {
        font: 900 1.55rem Inter, sans-serif;
        color: #111827;
      }

      .enrollment-stat span {
        color: #71717a;
        font: 750 0.78rem Inter, sans-serif;
      }

      .enrollment-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        padding: 1rem 1.5rem 1.35rem;
      }

      .enrollment-actions .btn-primary,
      .enrollment-actions .btn-secondary {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        min-height: 40px;
        padding: 0 1rem;
        border-radius: 10px;
        font-weight: 850;
        cursor: pointer;
        border: 1px solid transparent;
      }

      .enrollment-actions .btn-primary {
        background: #facc15;
        color: #111827;
      }

      .enrollment-actions .btn-secondary {
        background: #ffffff;
        color: #111827;
        border-color: #d4d4d8;
      }

      .enrollment-actions button:disabled {
        opacity: 0.55;
        cursor: not-allowed;
      }

      .view-toggle {
        display: flex;
        gap: 1rem;
        margin-bottom: 2rem;
      }

      .toggle-btn {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem 1.5rem;
        border: 1.5px solid #e5e5e5;
        border-radius: 8px;
        background: #fff;
        color: #666;
        cursor: pointer;
        font-weight: 600;
        font-family: Inter, sans-serif;
        font-size: 0.9rem;
        transition: all 200ms ease;
      }

      .toggle-btn:hover {
        border-color: #d0d0d0;
      }

      .toggle-btn.active {
        border-color: #facc15;
        background: rgba(250, 204, 21, 0.1);
        color: #ca8a04;
      }

      .content-area {
        min-height: 400px;
        min-width: 0;
      }

      .cards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: 1.5rem;
      }

      .loading-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 1.5rem;
        padding: 4rem 2rem;
        background: #fff;
        border-radius: 12px;
        border: 1px solid #e5e5e5;
      }

      .spinner {
        width: 48px;
        height: 48px;
        border: 3px solid #e5e5e5;
        border-top-color: #facc15;
        border-radius: 50%;
        animation: spin 1s linear infinite;
      }

      @keyframes spin {
        to {
          transform: rotate(360deg);
        }
      }

      .loading-state p {
        color: #666;
        margin: 0;
        font-size: 0.95rem;
      }

      @media (max-width: 640px) {
        .classes-container {
          padding: 1rem;
        }

        .module-header {
          gap: 1rem;
        }

        .header-title h1 {
          font-size: 1.5rem;
        }

        .filter-group {
          width: 100%;
          gap: 0.75rem;
        }

        .filter-select,
        .btn-reset-filters {
          flex: 1;
          min-width: 100px;
        }

        .cards-grid {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export default class ClassesModule implements OnInit {
  private api = inject(ApiService);
  private fb = inject(FormBuilder);

  // Signals
  isLoading = signal(false);
  isModalOpen = signal(false);
  allClasses = signal<ClassExtended[]>([]);
  classBeingEdited = signal<ClassExtended | null>(null);
  enrollmentClass = signal<ClassExtended | null>(null);

  // Filters
  searchTerm = signal('');
  statusFilter = signal('');
  dayFilter = signal('');
  typeFilter = signal('');
  currentView = signal<ViewType>('calendar');

  // Computed
  filteredClasses = computed(() => {
    let classes = this.allClasses();

    if (this.searchTerm()) {
      const term = (this.searchTerm() || '').toLowerCase().trim();
      classes = classes.filter(
        (c) =>
          (c.name || '').toLowerCase().includes(term) ||
          (c.type || '').toLowerCase().includes(term) ||
          (c.trainerName || '').toLowerCase().includes(term),
      );
    }

    if (this.statusFilter()) {
      classes = classes.filter((c) => c.status === this.statusFilter());
    }

    if (this.dayFilter()) {
      classes = classes.filter((c) => c.day_of_week === this.dayFilter());
    }

    if (this.typeFilter()) {
      classes = classes.filter((c) => c.type === this.typeFilter());
    }

    return classes;
  });

  mapToCardData(cls: ClassExtended): ClassCardData {
    return {
      id: cls.id || 0,
      name: cls.name || '',
      type: cls.type || '',
      trainerName: cls.trainerName || 'Sin asignar',
      dayOfWeek: cls.day_of_week || '',
      startTime: cls.start_time || '',
      endTime: cls.end_time || '',
      durationMinutes: cls.duration_minutes || 60,
      maxCapacity: cls.max_capacity || 20,
      enrolledCount: cls.enrolled_count || 0,
      location: cls.location || 'Sin ubicación',
      status: cls.status || 'active',
      description: cls.description || undefined,
    };
  }

  calendarClasses = computed(() => {
    return this.filteredClasses().map((cls) => ({
      id: cls.id || 0,
      name: cls.name || '',
      type: cls.type || '',
      startTime: cls.start_time || '',
      endTime: cls.end_time || '',
      trainerName: cls.trainerName || 'Sin asignar',
      maxCapacity: cls.max_capacity || 20,
      enrolledCount: cls.enrolled_count || 0,
      location: cls.location || 'Sin ubicación',
      status: (cls.status || 'active') as 'active' | 'inactive',
      date: cls.date || '',
    }));
  });

  activeClassesCount = computed(() => {
    return this.allClasses().filter((c) => (c.status || '').toString() === 'active').length;
  });

  todayClassesCount = computed(() => {
    const today =
      new Intl.DateTimeFormat('es-ES', { weekday: 'long' })
        .format(new Date())
        .charAt(0)
        .toUpperCase() +
      new Intl.DateTimeFormat('es-ES', { weekday: 'long' }).format(new Date()).slice(1);

    return this.allClasses().filter(
      (c) =>
        (c.status || '').toString() === 'active' &&
        (c.day_of_week || '').toLowerCase() === today.toLowerCase(),
    ).length;
  });

  availableSlotsCount = computed(() => {
    return this.allClasses().reduce(
      (total, cls) => total + Math.max(0, (cls.max_capacity || 0) - (cls.enrolled_count || 0)),
      0,
    );
  });

  totalEnrolledCount = computed(() => {
    return this.allClasses().reduce((total, cls) => total + (cls.enrolled_count || 0), 0);
  });

  hasActiveFilters = computed(() => {
    return !!(this.searchTerm() || this.statusFilter() || this.dayFilter() || this.typeFilter());
  });

  ngOnInit(): void {
    this.loadClasses();
  }

  private getNextDateForDayOfWeek(dayOfWeek: string): string {
    const days: { [key: string]: number } = {
      Lunes: 1,
      Martes: 2,
      Miércoles: 3,
      Jueves: 4,
      Viernes: 5,
      Sábado: 6,
      Domingo: 0,
    };

    const targetDay = days[dayOfWeek] ?? 1;
    const today = new Date();
    const dayOfWeekNum = today.getDay();
    let daysToAdd = (targetDay - dayOfWeekNum + 7) % 7;

    // Si es hoy, mostrar hoy; si no, mostrar el próximo ocurrencia
    if (daysToAdd === 0) {
      daysToAdd = 0; // Hoy
    }

    const date = new Date(today);
    date.setDate(date.getDate() + daysToAdd);
    return date.toISOString().split('T')[0];
  }

  private loadClasses(): void {
    this.isLoading.set(true);

    this.api.getClasses(1).subscribe({
      next: (res) => {
        const list = (res?.data || []).map((c: any) => ({
          ...c,
          trainerName: c.trainer?.full_name || c.trainer?.name || c.trainerName || '',
          date: c.date || this.getNextDateForDayOfWeek(c.day_of_week),
          enrolled_count: c.enrolled_count ?? 0,
        }));
        this.allClasses.set(list as ClassExtended[]);
        this.isLoading.set(false);
      },
      error: (err) => {
        console.error('No se pudo cargar la lista de clases desde el backend:', err);
        this.allClasses.set([]);
        this.isLoading.set(false);
      },
    });
  }

  private loadMockClasses(): void {
    setTimeout(() => {
      this.allClasses.set([
        {
          id: 1,
          name: 'Spinning Matutino',
          type: 'Spinning',
          trainer_id: 1,
          trainerName: 'Carlos Ruiz',
          date: this.getNextDateForDayOfWeek('Lunes'),
          day_of_week: 'Lunes',
          start_time: '06:00',
          end_time: '07:00',
          duration_minutes: 60,
          max_capacity: 20,
          enrolled_count: 18,
          location: 'Salón Principal',
          status: 'active',
          description: 'Clase de spinning de alta intensidad',
          is_recurring: true,
          allow_online_booking: true,
          created_at: new Date().toISOString(),
        },
        {
          id: 2,
          name: 'Yoga Relajante',
          type: 'Yoga',
          trainer_id: 2,
          trainerName: 'Laura Gómez',
          date: this.getNextDateForDayOfWeek('Lunes'),
          day_of_week: 'Lunes',
          start_time: '07:30',
          end_time: '08:45',
          duration_minutes: 75,
          max_capacity: 15,
          enrolled_count: 12,
          location: 'Salón Zen',
          status: 'active',
          description: 'Yoga restaurativo para relajación',
          is_recurring: true,
          allow_online_booking: true,
          created_at: new Date().toISOString(),
        },
        {
          id: 3,
          name: 'Cross Training',
          type: 'Cross Training',
          trainer_id: 3,
          trainerName: 'Andrés Martínez',
          date: this.getNextDateForDayOfWeek('Martes'),
          day_of_week: 'Martes',
          start_time: '18:00',
          end_time: '19:00',
          duration_minutes: 60,
          max_capacity: 25,
          enrolled_count: 20,
          location: 'Zona Funcional',
          status: 'active',
          description: 'Entrenamiento funcional de cuerpo completo',
          is_recurring: true,
          allow_online_booking: true,
          created_at: new Date().toISOString(),
        },
        {
          id: 4,
          name: 'Pilates Matutino',
          type: 'Pilates',
          trainer_id: 2,
          trainerName: 'Laura Gómez',
          date: this.getNextDateForDayOfWeek('Miércoles'),
          day_of_week: 'Miércoles',
          start_time: '09:00',
          end_time: '10:00',
          duration_minutes: 60,
          max_capacity: 12,
          enrolled_count: 10,
          location: 'Salón Zen',
          status: 'active',
          description: 'Pilates para fortalecer el core',
          is_recurring: true,
          allow_online_booking: true,
          created_at: new Date().toISOString(),
        },
        {
          id: 5,
          name: 'Boxeo Intenso',
          type: 'Boxeo',
          trainer_id: 3,
          trainerName: 'Andrés Martínez',
          date: this.getNextDateForDayOfWeek('Jueves'),
          day_of_week: 'Jueves',
          start_time: '19:00',
          end_time: '20:00',
          duration_minutes: 60,
          max_capacity: 20,
          enrolled_count: 15,
          location: 'Ring',
          status: 'active',
          description: 'Clase de boxeo para cardio y tonificación',
          is_recurring: true,
          allow_online_booking: true,
          created_at: new Date().toISOString(),
        },
      ]);
      this.isLoading.set(false);
    }, 1200);
  }

  onSearchChange(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.searchTerm.set(value);
  }

  onStatusFilterChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    this.statusFilter.set(value);
  }

  onDayFilterChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    this.dayFilter.set(value);
  }

  onTypeFilterChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    this.typeFilter.set(value);
  }

  resetFilters(): void {
    this.searchTerm.set('');
    this.statusFilter.set('');
    this.dayFilter.set('');
    this.typeFilter.set('');
  }

  setView(view: ViewType): void {
    this.currentView.set(view);
  }

  openCreateModal(): void {
    this.classBeingEdited.set(null);
    this.isModalOpen.set(true);
  }

  closeModal(): void {
    this.isModalOpen.set(false);
    this.classBeingEdited.set(null);
  }

  handleClassCreated(_newClass: any): void {
    // Cerrar modal y recargar desde backend para mantener una sola fuente de verdad.
    // Esto refresca la lista, el calendario y las métricas (computed signals) automáticamente.
    this.closeModal();
    this.loadClasses();
  }

  editClass(cls: ClassExtended): void {
    this.classBeingEdited.set(cls);
    this.isModalOpen.set(true);
  }

  viewEnrollments(cls: ClassExtended): void {
    this.enrollmentClass.set(cls);
  }

  closeEnrollments(): void {
    this.enrollmentClass.set(null);
  }

  remainingSlots(cls: ClassExtended): number {
    return Math.max(0, (cls.max_capacity || 0) - (cls.enrolled_count || 0));
  }

  adjustEnrollment(cls: ClassExtended, delta: number): void {
    const nextCount = Math.max(0, Math.min(cls.max_capacity || 0, (cls.enrolled_count || 0) + delta));
    this.api.updateClass(cls.id, { enrolled_count: nextCount }).subscribe({
      next: (updated: any) => {
        const normalized = {
          ...cls,
          ...updated,
          trainerName: updated.trainer?.full_name || updated.trainer?.name || cls.trainerName,
          date: cls.date,
        };
        this.allClasses.update((list) => list.map((c) => (c.id === cls.id ? normalized : c)));
        this.enrollmentClass.set(normalized);
      },
      error: () => alert('No se pudo actualizar el número de inscritos.'),
    });
  }

  duplicateClass(cls: ClassExtended): void {
    const payload: any = {
      name: `Copia de ${cls.name}`,
      type: cls.type,
      day_of_week: cls.day_of_week,
      start_time: cls.start_time,
      end_time: cls.end_time,
      duration_minutes: cls.duration_minutes,
      max_capacity: cls.max_capacity,
      location: cls.location,
      status: 'inactive',
      description: cls.description,
      is_recurring: cls.is_recurring,
      allow_online_booking: cls.allow_online_booking,
      requires_active_plan: cls.requires_active_plan,
      trainer_id: cls.trainer_id,
    };
    this.api.createClass(payload).subscribe({
      next: (created: any) => {
        this.allClasses.update((list) => [
          { ...created, trainerName: cls.trainerName, date: cls.date },
          ...list,
        ]);
      },
      error: () => alert('No se pudo duplicar la clase. Intenta de nuevo.'),
    });
  }

  toggleClassStatus(cls: ClassExtended): void {
    const next = cls.status === 'active' ? 'inactive' : 'active';
    this.api.updateClass(cls.id, { status: next }).subscribe({
      next: (updated: any) => {
        this.allClasses.update((list) =>
          list.map((c) => (c.id === cls.id ? { ...c, ...updated } : c)),
        );
      },
      error: () => alert('No se pudo cambiar el estado de la clase.'),
    });
  }

  deleteClass(cls: ClassExtended): void {
    if (!confirm(`¿Deseas eliminar la clase "${cls.name}"?`)) return;
    this.api.deleteClass(cls.id).subscribe({
      next: () => {
        this.allClasses.update((classes) => classes.filter((c) => c.id !== cls.id));
      },
      error: () => alert('No se pudo eliminar la clase.'),
    });
  }
}
