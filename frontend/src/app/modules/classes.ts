import { CommonModule } from '@angular/common';
import { Component, OnInit, signal, Signal, computed, inject } from '@angular/core';
import { ReactiveFormsModule, FormBuilder, FormGroup } from '@angular/forms';
import { ApiService, ClassSummary } from '../services/api.service';
import { ClassesKPIComponent } from './components/classes-kpi';
import { ClassCardComponent, ClassCardData } from './components/class-card';
import { ClassesEmptyComponent } from './components/classes-empty';
import { CreateClassModalComponent } from './components/create-class-modal';
import { ClassCalendarComponent } from './components/class-calendar';

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
          icon="school"
          [value]="activeClassesCount()"
          suffix="clases"
          color="primary"
        ></app-classes-kpi>

        <app-classes-kpi
          label="Hoy"
          icon="today"
          [value]="todayClassesCount()"
          suffix="clases"
          color="success"
        ></app-classes-kpi>

        <app-classes-kpi
          label="Slots Disponibles"
          icon="event_seat"
          [value]="availableSlotsCount()"
          suffix="cupos"
          color="warning"
        ></app-classes-kpi>

        <app-classes-kpi
          label="Inscritos Totales"
          icon="group"
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
          <span class="material-symbols-outlined" aria-hidden="true">calendar_month</span>
          Calendario
        </button>
        <button
          (click)="setView('cards')"
          [class.active]="currentView() === 'cards'"
          class="toggle-btn"
          aria-label="Vista tarjetas"
        >
          <span class="material-symbols-outlined" aria-hidden="true">view_agenda</span>
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
      (onClose)="closeModal()"
      (onClassCreated)="handleClassCreated($event)"
    ></app-create-class-modal>
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
        background: #f5f5f5;
        min-height: 100vh;
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

    // TODO: Cambiar por API real cuando el backend esté disponible
    // this.api.getClasses().subscribe({...})

    // MOCK: Datos de ejemplo
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
    this.isModalOpen.set(true);
  }

  closeModal(): void {
    this.isModalOpen.set(false);
  }

  handleClassCreated(newClass: any): void {
    try {
      // Validar y normalizar el objeto de la clase
      if (!newClass || !newClass.id || !newClass.name || !newClass.type) {
        console.error('Objeto de clase incompleto:', newClass);
        return;
      }

      // Asegurar que todos los campos definidos tengan valores válidos
      const normalizedClass: ClassExtended = {
        id: newClass.id,
        name: newClass.name || '',
        type: newClass.type || '',
        trainer_id: newClass.trainer_id || null,
        day_of_week: newClass.day_of_week || '',
        date: newClass.date || this.getNextDateForDayOfWeek(newClass.day_of_week || 'Lunes'),
        start_time: newClass.start_time || '',
        end_time: newClass.end_time || '',
        duration_minutes: newClass.duration_minutes || 60,
        max_capacity: newClass.max_capacity || 20,
        enrolled_count: newClass.enrolled_count || 0,
        location: newClass.location || '',
        status: (newClass.status || 'active') as 'active' | 'inactive',
        description: newClass.description || '',
        notes: newClass.notes || '',
        is_recurring: newClass.is_recurring === true,
        allow_online_booking: newClass.allow_online_booking === true,
        requires_active_plan: newClass.requires_active_plan === true,
        created_at: newClass.created_at || new Date().toISOString(),
        trainerName: newClass.trainerName || 'Sin asignar',
      };

      // Agregar a la lista
      this.allClasses.update((classes) => [normalizedClass, ...classes]);
      this.closeModal();
    } catch (error) {
      console.error('Error al procesar clase creada:', error);
    }
  }

  editClass(cls: ClassExtended): void {
    console.log('Editar clase:', cls);
    // TODO: Implementar edición de clase
  }

  viewEnrollments(cls: ClassExtended): void {
    console.log('Ver inscritos:', cls);
    // TODO: Implementar vista de inscritos
  }

  duplicateClass(cls: ClassExtended): void {
    console.log('Duplicar clase:', cls);
    // TODO: Implementar duplicación de clase
  }

  toggleClassStatus(cls: ClassExtended): void {
    console.log('Cambiar estado:', cls);
    // TODO: Implementar cambio de estado
  }

  deleteClass(cls: ClassExtended): void {
    if (confirm(`¿Deseas eliminar la clase "${cls.name}"?`)) {
      this.allClasses.update((classes) => classes.filter((c) => c.id !== cls.id));
    }
  }
}
