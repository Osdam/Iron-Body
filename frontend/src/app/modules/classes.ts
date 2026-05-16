import { CommonModule } from '@angular/common';
import { Component, ElementRef, HostListener, OnInit, signal, Signal, computed, inject } from '@angular/core';
import { ReactiveFormsModule, FormBuilder, FormGroup } from '@angular/forms';
import { ApiService, ClassSummary } from '../services/api.service';
import { ClassesKPIComponent } from './components/classes-kpi';
import { ClassCardComponent, ClassCardData } from './components/class-card';
import { ClassesEmptyComponent } from './components/classes-empty';
import { CreateClassModalComponent } from './components/create-class-modal';
import { ClassCalendarComponent } from './components/class-calendar';
import { LottieIconComponent } from '../shared/components/lottie-icon/lottie-icon.component';
import { AuthService } from '../services/auth.service';
import { Permission } from '../models/permissions.enum';

interface ClassExtended extends ClassSummary {
  trainerName?: string;
  date?: string;
}

type ViewType = 'calendar' | 'cards';
type ClassFilterSelect = 'status' | 'day' | 'type';

interface ClassFilterOption {
  value: string;
  label: string;
  description: string;
  icon: string;
}

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
        <button *ngIf="canCreateClasses()" class="btn-create" (click)="openCreateModal()">
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
            [value]="searchTerm()"
            (input)="onSearchChange($event)"
            class="search-input"
            aria-label="Buscar clases"
          />
        </div>

        <div class="filter-group">
          <div class="pretty-select" [class.open]="openSelect() === 'status'">
            <button type="button" class="pretty-trigger" (click)="toggleSelect('status')" aria-label="Filtrar por estado">
              <span>{{ filterLabel('status') }}</span>
              <span class="select-chevron" aria-hidden="true"></span>
            </button>
            <div *ngIf="openSelect() === 'status'" class="pretty-menu">
              <button
                type="button"
                class="pretty-option"
                *ngFor="let option of statusFilterOptions"
                [class.selected]="statusFilter() === option.value"
                (click)="chooseFilter('status', option.value)"
              >
                <span class="option-main">
                  <span class="option-icon material-symbols-outlined" aria-hidden="true">{{ option.icon }}</span>
                  <span class="option-copy">
                    <strong>{{ option.label }}</strong>
                    <small>{{ option.description }}</small>
                  </span>
                </span>
                <span class="option-check" aria-hidden="true"></span>
              </button>
            </div>
          </div>

          <div class="pretty-select" [class.open]="openSelect() === 'day'">
            <button type="button" class="pretty-trigger" (click)="toggleSelect('day')" aria-label="Filtrar por día">
              <span>{{ filterLabel('day') }}</span>
              <span class="select-chevron" aria-hidden="true"></span>
            </button>
            <div *ngIf="openSelect() === 'day'" class="pretty-menu">
              <button
                type="button"
                class="pretty-option"
                *ngFor="let option of dayFilterOptions"
                [class.selected]="dayFilter() === option.value"
                (click)="chooseFilter('day', option.value)"
              >
                <span class="option-main">
                  <span class="option-icon material-symbols-outlined" aria-hidden="true">{{ option.icon }}</span>
                  <span class="option-copy">
                    <strong>{{ option.label }}</strong>
                    <small>{{ option.description }}</small>
                  </span>
                </span>
                <span class="option-check" aria-hidden="true"></span>
              </button>
            </div>
          </div>

          <div class="pretty-select" [class.open]="openSelect() === 'type'">
            <button type="button" class="pretty-trigger" (click)="toggleSelect('type')" aria-label="Filtrar por tipo">
              <span>{{ filterLabel('type') }}</span>
              <span class="select-chevron" aria-hidden="true"></span>
            </button>
            <div *ngIf="openSelect() === 'type'" class="pretty-menu">
              <button
                type="button"
                class="pretty-option"
                *ngFor="let option of typeFilterOptions"
                [class.selected]="typeFilter() === option.value"
                (click)="chooseFilter('type', option.value)"
              >
                <span class="option-main">
                  <span class="option-icon material-symbols-outlined" aria-hidden="true">{{ option.icon }}</span>
                  <span class="option-copy">
                    <strong>{{ option.label }}</strong>
                    <small>{{ option.description }}</small>
                  </span>
                </span>
                <span class="option-check" aria-hidden="true"></span>
              </button>
            </div>
          </div>

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
          *ngIf="!isLoading() && filteredClasses().length === 0 && canCreateClasses()"
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
        position: relative;
        z-index: 30;
        overflow: visible;
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

      .pretty-select {
        position: relative;
        flex: 1 1 200px;
        min-width: 200px;
        z-index: 1;
      }

      .pretty-select.open {
        z-index: 80;
      }

      .pretty-trigger {
        width: 100%;
        min-height: 46px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        border: 1px solid #e5e5e5;
        border-radius: 8px;
        background: #fff;
        color: #0a0a0a;
        padding: 0 0.9rem;
        font-family: Inter, sans-serif;
        font-size: 0.95rem;
        font-weight: 850;
        text-align: left;
        cursor: pointer;
        transition:
          border-color 0.15s ease,
          box-shadow 0.15s ease,
          background 0.15s ease;
      }

      .pretty-trigger > span:first-child {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .pretty-trigger:hover,
      .pretty-select.open .pretty-trigger {
        border-color: #facc15;
        background: #fffdf4;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.12);
      }

      .select-chevron {
        width: 0.52rem;
        height: 0.52rem;
        border-bottom: 2px solid #a16207;
        border-right: 2px solid #a16207;
        transform: rotate(45deg) translateY(-1px);
        transition: transform 160ms ease;
        flex-shrink: 0;
      }

      .pretty-select.open .select-chevron {
        transform: rotate(225deg) translateY(-1px);
      }

      .pretty-menu {
        position: absolute;
        top: calc(100% + 0.35rem);
        left: 0;
        width: max(100%, 280px);
        min-width: 250px;
        z-index: 5000;
        display: grid;
        gap: 0.2rem;
        max-height: 280px;
        overflow-y: auto;
        padding: 0.45rem;
        border: 1px solid #e4e4e7;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: 0 18px 42px rgba(0, 0, 0, 0.18);
        animation: selectIn 140ms ease;
      }

      .pretty-select:nth-last-of-type(-n + 1) .pretty-menu {
        left: auto;
        right: 0;
      }

      @keyframes selectIn {
        from { opacity: 0; transform: translateY(-4px) scale(0.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
      }

      .pretty-option {
        min-height: 3.35rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.85rem;
        border: 0;
        border-radius: 9px;
        background: transparent;
        color: #3f3f46;
        text-align: left;
        padding: 0.62rem 0.7rem;
        cursor: pointer;
        transition:
          background 140ms ease,
          color 140ms ease,
          transform 140ms ease;
      }

      .pretty-option:hover {
        background: #fffbeb;
        color: #18181b;
        transform: translateY(-1px);
      }

      .pretty-option.selected {
        background: rgba(250, 204, 21, 0.18);
        color: #111827;
      }

      .option-main {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        min-width: 0;
      }

      .option-icon {
        width: 2rem;
        height: 2rem;
        display: grid;
        place-items: center;
        border-radius: 8px;
        background: #f4f4f5;
        color: #a16207;
        flex-shrink: 0;
        font-size: 1.12rem;
      }

      .pretty-option.selected .option-icon {
        background: #facc15;
        color: #111827;
      }

      .option-copy {
        display: grid;
        gap: 0.12rem;
        min-width: 0;
      }

      .option-copy strong {
        color: inherit;
        font-weight: 900;
        font-size: 0.9rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .option-copy small {
        color: #71717a;
        font-weight: 650;
        font-size: 0.75rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .option-check {
        width: 1.15rem;
        height: 1.15rem;
        position: relative;
        display: block;
        border: 2px solid transparent;
        border-radius: 999px;
        flex-shrink: 0;
      }

      .pretty-option.selected .option-check {
        border-color: #ca8a04;
        background: #ca8a04;
      }

      .pretty-option.selected .option-check::after {
        content: '';
        position: absolute;
        left: 0.31rem;
        top: 0.16rem;
        width: 0.3rem;
        height: 0.58rem;
        border: solid #ffffff;
        border-width: 0 2px 2px 0;
        transform: rotate(45deg);
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

      .classes-container {
        background:
          linear-gradient(rgba(12, 12, 12, 0.90), rgba(12, 12, 12, 0.92)),
          url('/assets/crm/fondoclases.png') center / cover no-repeat;
        color: #e5e2e1;
        border: 1px solid rgba(245, 197, 24, 0.08);
      }

      .header-title h1,
      .loading-state p {
        color: #e5e2e1;
      }

      .header-title p {
        color: #b4afa6;
      }

      .filters-section,
      .loading-state {
        background: rgba(28, 27, 27, 0.94);
        border-color: #353534;
        box-shadow: 0 18px 44px rgba(0, 0, 0, 0.22);
      }

      .search-box,
      .pretty-trigger,
      .btn-reset-filters,
      .toggle-btn {
        background: #151515;
        border-color: #353534;
        color: #e5e2e1;
      }

      .search-box:focus-within,
      .pretty-trigger:hover,
      .pretty-select.open .pretty-trigger {
        background: #1f1f1f;
        border-color: #f5c518;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.13);
      }

      .search-box span,
      .search-input::placeholder {
        color: #8e8675;
      }

      .search-input {
        color: #e5e2e1;
      }

      .select-chevron {
        border-color: #f5c518;
      }

      .pretty-menu {
        background: #151515;
        border-color: #353534;
        box-shadow: 0 22px 54px rgba(0, 0, 0, 0.44);
      }

      .pretty-option {
        color: #e5e2e1;
      }

      .pretty-option:hover,
      .pretty-option.selected {
        background: rgba(245, 197, 24, 0.13);
        color: #ffe08b;
      }

      .option-icon {
        background: #252423;
        color: #ffe08b;
      }

      .option-copy small {
        color: #a9a197;
      }

      .btn-reset-filters:hover:not(:disabled),
      .toggle-btn:hover {
        border-color: #f5c518;
        color: #ffe08b;
        background: #201f1f;
      }

      .toggle-lottie {
        background: rgba(245, 197, 24, 0.12);
      }

      .toggle-btn.active {
        border-color: #f5c518;
        background: rgba(245, 197, 24, 0.15);
        color: #ffe08b;
      }

      .enrollment-backdrop {
        background: rgba(0, 0, 0, 0.62);
        backdrop-filter: none;
      }

      .enrollment-modal {
        background: #1c1b1b;
        border-color: #353534;
        color: #e5e2e1;
        box-shadow: 0 26px 80px rgba(0, 0, 0, 0.58);
      }

      .enrollment-header {
        background: linear-gradient(135deg, rgba(245, 197, 24, 0.14), #151515);
        border-color: #353534;
      }

      .enrollment-header h2,
      .enrollment-stat strong {
        color: #e5e2e1;
      }

      .enrollment-header p,
      .enrollment-stat span {
        color: #b4afa6;
      }

      .enrollment-close,
      .enrollment-stat,
      .enrollment-actions .btn-secondary {
        background: #151515;
        border-color: #353534;
        color: #d1c5ac;
      }

      .enrollment-actions .btn-primary {
        background: #f5c518;
        color: #241a00;
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
  private elementRef = inject(ElementRef<HTMLElement>);
  private auth = inject(AuthService);

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
  openSelect = signal<ClassFilterSelect | null>(null);
  currentView = signal<ViewType>('calendar');
  readonly statusFilterOptions: ClassFilterOption[] = [
    { value: '', label: 'Todos los estados', description: 'Mostrar todas las clases', icon: 'select_all' },
    { value: 'active', label: 'Activas', description: 'Disponibles en agenda', icon: 'check_circle' },
    { value: 'inactive', label: 'Inactivas', description: 'Pausadas u ocultas', icon: 'pause_circle' },
  ];
  readonly dayFilterOptions: ClassFilterOption[] = [
    { value: '', label: 'Todos los días', description: 'Cualquier día de la semana', icon: 'calendar_month' },
    { value: 'Lunes', label: 'Lunes', description: 'Clases del lunes', icon: 'event' },
    { value: 'Martes', label: 'Martes', description: 'Clases del martes', icon: 'event' },
    { value: 'Miércoles', label: 'Miércoles', description: 'Clases del miércoles', icon: 'event' },
    { value: 'Jueves', label: 'Jueves', description: 'Clases del jueves', icon: 'event' },
    { value: 'Viernes', label: 'Viernes', description: 'Clases del viernes', icon: 'event' },
    { value: 'Sábado', label: 'Sábado', description: 'Clases del sábado', icon: 'event_available' },
    { value: 'Domingo', label: 'Domingo', description: 'Clases del domingo', icon: 'event_available' },
  ];
  readonly typeFilterOptions: ClassFilterOption[] = [
    { value: '', label: 'Todos los tipos', description: 'Cualquier modalidad', icon: 'apps' },
    { value: 'Spinning', label: 'Spinning', description: 'Cardio en bicicleta', icon: 'directions_bike' },
    { value: 'Funcional', label: 'Funcional', description: 'Trabajo físico integral', icon: 'fitness_center' },
    { value: 'Cross Training', label: 'Cross Training', description: 'Entrenamiento de alta intensidad', icon: 'bolt' },
    { value: 'Yoga', label: 'Yoga', description: 'Movilidad y respiración', icon: 'self_improvement' },
    { value: 'Pilates', label: 'Pilates', description: 'Control y estabilidad', icon: 'accessibility_new' },
    { value: 'Boxeo', label: 'Boxeo', description: 'Técnica y acondicionamiento', icon: 'sports_mma' },
    { value: 'Cardio', label: 'Cardio', description: 'Resistencia cardiovascular', icon: 'monitor_heart' },
  ];

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

  @HostListener('document:click', ['$event'])
  closeSelectOnOutsideClick(event: MouseEvent): void {
    if (!this.openSelect()) return;
    if (!this.elementRef.nativeElement.contains(event.target as Node)) {
      this.openSelect.set(null);
    }
  }

  toggleSelect(select: ClassFilterSelect): void {
    this.openSelect.update((current) => (current === select ? null : select));
  }

  chooseFilter(select: ClassFilterSelect, value: string): void {
    if (select === 'status') this.statusFilter.set(value);
    if (select === 'day') this.dayFilter.set(value);
    if (select === 'type') this.typeFilter.set(value);
    this.openSelect.set(null);
  }

  filterLabel(select: ClassFilterSelect): string {
    const options =
      select === 'status'
        ? this.statusFilterOptions
        : select === 'day'
          ? this.dayFilterOptions
          : this.typeFilterOptions;
    const value =
      select === 'status' ? this.statusFilter() : select === 'day' ? this.dayFilter() : this.typeFilter();
    return options.find((option) => option.value === value)?.label || options[0].label;
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
    if (!this.requirePermission(Permission.CLASSES_CREATE, 'No tienes permiso para crear clases.')) return;
    this.classBeingEdited.set(null);
    this.isModalOpen.set(true);
  }

  closeModal(): void {
    this.isModalOpen.set(false);
    this.classBeingEdited.set(null);
  }

  handleClassCreated(_newClass: any): void {
    const permission = this.classBeingEdited() ? Permission.CLASSES_EDIT : Permission.CLASSES_CREATE;
    if (!this.requirePermission(permission, 'No tienes permiso para guardar clases.')) return;
    // Cerrar modal y recargar desde backend para mantener una sola fuente de verdad.
    // Esto refresca la lista, el calendario y las métricas (computed signals) automáticamente.
    this.closeModal();
    this.loadClasses();
  }

  editClass(cls: ClassExtended): void {
    if (!this.requirePermission(Permission.CLASSES_EDIT, 'No tienes permiso para editar clases.')) return;
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
    if (!this.requirePermission(Permission.CLASSES_ENROLLMENTS, 'No tienes permiso para modificar inscripciones.')) return;
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
    if (!this.requirePermission(Permission.CLASSES_CREATE, 'No tienes permiso para duplicar clases.')) return;
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
    if (!this.requirePermission(Permission.CLASSES_EDIT, 'No tienes permiso para cambiar el estado de clases.')) return;
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
    if (!this.requirePermission(Permission.CLASSES_DELETE, 'No tienes permiso para eliminar clases.')) return;
    if (!confirm(`¿Deseas eliminar la clase "${cls.name}"?`)) return;
    this.api.deleteClass(cls.id).subscribe({
      next: () => {
        this.allClasses.update((classes) => classes.filter((c) => c.id !== cls.id));
      },
      error: () => alert('No se pudo eliminar la clase.'),
    });
  }

  canCreateClasses(): boolean {
    return this.auth.hasPermission(Permission.CLASSES_CREATE);
  }

  private requirePermission(permission: Permission, message: string): boolean {
    if (this.auth.hasPermission(permission)) return true;
    alert(message);
    return false;
  }
}
