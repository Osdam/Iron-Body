import { CommonModule } from '@angular/common';
import { Component, Input, Output, EventEmitter, signal, Signal, computed } from '@angular/core';

interface CalendarClass {
  id: number;
  name: string;
  type: string;
  startTime: string;
  endTime: string;
  trainerName: string;
  maxCapacity: number;
  enrolledCount: number;
  location: string;
  status: 'active' | 'inactive';
  date?: string;
}

interface CalendarDay {
  date: Date;
  day: number;
  month: number;
  year: number;
  isCurrentMonth: boolean;
  isToday: boolean;
  hasClasses: boolean;
  classes: CalendarClass[];
}

@Component({
  selector: 'app-class-calendar',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="calendar-container">
      <!-- Header with navigation -->
      <div class="calendar-header">
        <button class="nav-btn" (click)="previousMonth()" aria-label="Mes anterior">
          <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
        </button>

        <div class="month-year">
          <h3>{{ monthName() }} {{ currentYear() }}</h3>
        </div>

        <button class="nav-btn" (click)="nextMonth()" aria-label="Próximo mes">
          <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
        </button>

        <button class="today-btn" (click)="goToToday()">Hoy</button>
      </div>

      <!-- Day names -->
      <div class="calendar-weekdays">
        <div class="weekday" *ngFor="let day of weekDays">{{ day }}</div>
      </div>

      <!-- Calendar grid -->
      <div class="calendar-grid">
        <button
          *ngFor="let day of calendarDays()"
          (click)="selectDay(day)"
          [class.other-month]="!day.isCurrentMonth"
          [class.today]="day.isToday"
          [class.selected]="isSelectedDay(day)"
          [class.has-classes]="day.hasClasses"
          class="calendar-day"
          [attr.aria-label]="formatAriaLabel(day)"
        >
          <div class="day-number">{{ day.day }}</div>
          <div class="day-indicator" *ngIf="day.hasClasses">
            <div
              class="dot-indicator"
              *ngFor="
                let _ of [1, 2, 3] | slice: 0 : (day.classes.length > 2 ? 2 : day.classes.length)
              "
            ></div>
            <span class="class-count" *ngIf="day.classes.length > 2"
              >+{{ day.classes.length - 2 }}</span
            >
          </div>
        </button>
      </div>

      <!-- Selected day details -->
      <div *ngIf="selectedDay()" class="day-details">
        <div class="selected-day-header">
          <h4 class="selected-day-title">
            {{ selectedDay()!.isToday ? 'Hoy' : getFormattedDate(selectedDay()!.date) }}
          </h4>
          <span *ngIf="selectedDay()!.hasClasses" class="class-count-badge">
            {{ selectedDay()!.classes.length }}
            {{ selectedDay()!.classes.length === 1 ? 'clase' : 'clases' }}
          </span>
        </div>

        <!-- Classes list for selected day -->
        <div *ngIf="selectedDay()!.classes.length > 0" class="classes-list">
          <div
            *ngFor="let cls of selectedDay()!.classes"
            class="class-item"
            [class.inactive]="cls.status === 'inactive'"
          >
            <div class="class-time">
              <span class="time">{{ cls.startTime }}</span>
              <span class="separator">-</span>
              <span class="time">{{ cls.endTime }}</span>
            </div>
            <div class="class-info">
              <h5 class="class-name">{{ cls.name }}</h5>
              <div class="class-meta">
                <span class="trainer">
                  <span class="material-symbols-outlined" aria-hidden="true">person</span>
                  {{ cls.trainerName }}
                </span>
                <span class="location">
                  <span class="material-symbols-outlined" aria-hidden="true">location_on</span>
                  {{ cls.location }}
                </span>
              </div>
              <div class="class-capacity">
                <div class="capacity-bar">
                  <div
                    class="capacity-fill"
                    [style.width.%]="(cls.enrolledCount / cls.maxCapacity) * 100"
                  ></div>
                </div>
                <span class="capacity-text"
                  >{{ cls.enrolledCount }}/{{ cls.maxCapacity }} inscritos</span
                >
              </div>
            </div>
            <div class="class-type-badge" [class]="'type-' + cls.type.toLowerCase()">
              {{ cls.type }}
            </div>
          </div>
        </div>

        <!-- Empty state -->
        <div *ngIf="!selectedDay()!.hasClasses" class="empty-day">
          <span class="material-symbols-outlined" aria-hidden="true">event_repeat</span>
          <p>No hay clases programadas para este día</p>
        </div>
      </div>
    </div>
  `,
  styles: [
    `
      .calendar-container {
        display: grid;
        grid-template-columns: 1fr 300px;
        gap: 2rem;
        padding: 1.5rem;
        background: #fff;
        border-radius: 12px;
        border: 1px solid #e5e5e5;
      }

      @media (max-width: 1024px) {
        .calendar-container {
          grid-template-columns: 1fr;
        }
      }

      .calendar-header {
        grid-column: 1 / -1;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding-bottom: 1.5rem;
        border-bottom: 1px solid #f0f0f0;
      }

      .nav-btn,
      .today-btn {
        padding: 0.5rem;
        border: none;
        background: #f5f5f5;
        border-radius: 8px;
        cursor: pointer;
        transition: all 200ms ease;
        display: grid;
        place-items: center;
        width: 36px;
        height: 36px;
        color: #666;
      }

      .nav-btn:hover {
        background: #e8e8e8;
        color: #0a0a0a;
      }

      .today-btn {
        background: rgba(250, 204, 21, 0.1);
        color: #ca8a04;
        font-weight: 600;
        width: auto;
        padding: 0.5rem 1rem;
        margin-left: auto;
        font-size: 0.85rem;
      }

      .today-btn:hover {
        background: rgba(250, 204, 21, 0.2);
      }

      .month-year {
        flex: 1;
        text-align: center;
      }

      .month-year h3 {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 1rem;
        font-weight: 700;
        color: #0a0a0a;
        margin: 0;
        text-transform: capitalize;
      }

      .calendar-weekdays {
        grid-column: 1;
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 0.5rem;
        margin-bottom: 0.5rem;
      }

      .weekday {
        text-align: center;
        font-size: 0.8rem;
        font-weight: 600;
        color: #999;
        text-transform: uppercase;
        padding: 0.75rem 0;
        letter-spacing: 0.05em;
      }

      .calendar-grid {
        grid-column: 1;
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 0.5rem;
      }

      .calendar-day {
        aspect-ratio: 1;
        border: 1px solid #e5e5e5;
        border-radius: 8px;
        background: #fff;
        cursor: pointer;
        padding: 0.5rem;
        transition: all 200ms ease;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        position: relative;
        font-family: Inter, sans-serif;
      }

      .calendar-day:hover {
        border-color: #d0d0d0;
        background: #f9f9f9;
      }

      .calendar-day.other-month {
        background: #fafafa;
        color: #ccc;
      }

      .calendar-day.today {
        border: 2px solid #facc15;
        background: rgba(250, 204, 21, 0.05);
      }

      .calendar-day.selected {
        background: #facc15;
        border-color: #facc15;
        color: #000;
      }

      .calendar-day.has-classes {
        border: 2px solid #4f46e5;
      }

      .calendar-day.selected.has-classes {
        border-color: #facc15;
      }

      .day-number {
        font-weight: 600;
        font-size: 0.9rem;
      }

      .day-indicator {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.25rem;
        margin-top: 0.25rem;
      }

      .dot-indicator {
        width: 4px;
        height: 4px;
        border-radius: 50%;
        background: #4f46e5;
      }

      .calendar-day.selected .dot-indicator {
        background: #fff;
      }

      .class-count {
        font-size: 0.65rem;
        color: #4f46e5;
        font-weight: 600;
        margin-left: 0.25rem;
      }

      .calendar-day.selected .class-count {
        color: #fff;
      }

      .day-details {
        grid-column: 2;
        grid-row: 2 / 4;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
        background: #f9f9f9;
      }

      @media (max-width: 1024px) {
        .day-details {
          grid-column: 1;
          margin-top: 1rem;
        }
      }

      .selected-day-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #e5e5e5;
      }

      .selected-day-title {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 0.95rem;
        font-weight: 700;
        color: #0a0a0a;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin: 0;
      }

      .class-count-badge {
        display: inline-block;
        background: #4f46e5;
        color: #fff;
        font-size: 0.75rem;
        font-weight: 700;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
      }

      .classes-list {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        max-height: 400px;
        overflow-y: auto;
      }

      .class-item {
        background: #fff;
        border: 1px solid #e5e5e5;
        border-radius: 8px;
        padding: 1rem;
        transition: all 200ms ease;
      }

      .class-item:hover {
        border-color: #facc15;
        box-shadow: 0 4px 12px rgba(250, 204, 21, 0.1);
      }

      .class-item.inactive {
        opacity: 0.6;
      }

      .class-time {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.75rem;
        font-weight: 600;
        font-size: 0.9rem;
        color: #0a0a0a;
      }

      .separator {
        color: #ccc;
      }

      .class-name {
        font-weight: 700;
        color: #0a0a0a;
        margin: 0 0 0.5rem;
        font-size: 0.95rem;
      }

      .class-meta {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
        margin-bottom: 0.75rem;
        font-size: 0.8rem;
        color: #666;
      }

      .trainer,
      .location {
        display: flex;
        align-items: center;
        gap: 0.4rem;
      }

      .class-meta span.material-symbols-outlined {
        font-size: 1rem;
      }

      .class-capacity {
        margin-top: 0.75rem;
      }

      .capacity-bar {
        width: 100%;
        height: 4px;
        background: #e5e5e5;
        border-radius: 2px;
        overflow: hidden;
        margin-bottom: 0.4rem;
      }

      .capacity-fill {
        height: 100%;
        background: linear-gradient(90deg, #4f46e5 0%, #6366f1 100%);
        transition: width 300ms ease;
      }

      .capacity-text {
        font-size: 0.75rem;
        color: #666;
        display: block;
      }

      .class-type-badge {
        display: inline-block;
        font-size: 0.65rem;
        font-weight: 700;
        padding: 0.35rem 0.7rem;
        border-radius: 4px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-top: 0.75rem;
      }

      .type-spinning {
        background: rgba(239, 68, 68, 0.1);
        color: #dc2626;
      }

      .type-funcional,
      .type-cross-training {
        background: rgba(79, 70, 229, 0.1);
        color: #4f46e5;
      }

      .type-yoga,
      .type-pilates {
        background: rgba(34, 197, 94, 0.1);
        color: #22c55e;
      }

      .type-boxeo {
        background: rgba(245, 158, 11, 0.1);
        color: #d97706;
      }

      .type-cardio {
        background: rgba(168, 85, 247, 0.1);
        color: #a855f7;
      }

      .empty-day {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        padding: 2rem 1rem;
        color: #999;
      }

      .empty-day span {
        font-size: 2.5rem;
        opacity: 0.3;
      }

      .empty-day p {
        margin: 0;
        font-size: 0.9rem;
        text-align: center;
      }

      @media (max-width: 640px) {
        .calendar-container {
          padding: 1rem;
        }

        .calendar-day {
          font-size: 0.85rem;
        }

        .day-number {
          font-size: 0.8rem;
        }

        .classes-list {
          max-height: 300px;
        }

        .calendar-header {
          gap: 0.5rem;
        }

        .today-btn {
          padding: 0.4rem 0.8rem;
          font-size: 0.75rem;
        }
      }
    `,
  ],
})
export class ClassCalendarComponent {
  @Input() classes: CalendarClass[] = [];
  @Output() daySelected = new EventEmitter<Date>();
  @Output() classSelected = new EventEmitter<CalendarClass>();

  weekDays = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
  monthNames = [
    'Enero',
    'Febrero',
    'Marzo',
    'Abril',
    'Mayo',
    'Junio',
    'Julio',
    'Agosto',
    'Septiembre',
    'Octubre',
    'Noviembre',
    'Diciembre',
  ];

  currentDate = signal(new Date());
  selectedDate = signal<Date | null>(null);

  currentYear = computed(() => this.currentDate().getFullYear());
  currentMonth = computed(() => this.currentDate().getMonth());
  monthName = computed(() => this.monthNames[this.currentMonth()]);

  calendarDays = computed(() => {
    const date = new Date(this.currentYear(), this.currentMonth(), 1);
    const startDate = new Date(date);
    startDate.setDate(startDate.getDate() - date.getDay());

    const days: CalendarDay[] = [];
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    for (let i = 0; i < 42; i++) {
      const currentDate = new Date(startDate);
      currentDate.setDate(startDate.getDate() + i);

      const dayDate = new Date(currentDate);
      dayDate.setHours(0, 0, 0, 0);

      const dayDateString = dayDate.toISOString().split('T')[0];

      const isCurrentMonth = currentDate.getMonth() === this.currentMonth();
      const isToday = dayDate.getTime() === today.getTime();

      const dayClasses = this.classes.filter((cls) => {
        if (!cls.date) return false;
        return cls.date === dayDateString;
      });

      days.push({
        date: currentDate,
        day: currentDate.getDate(),
        month: currentDate.getMonth(),
        year: currentDate.getFullYear(),
        isCurrentMonth,
        isToday,
        hasClasses: dayClasses.length > 0,
        classes: dayClasses,
      });
    }

    return days;
  });

  selectedDay = computed(() => {
    if (!this.selectedDate()) return null;

    const selected = this.selectedDate()!;
    selected.setHours(0, 0, 0, 0);

    return (
      this.calendarDays().find((day) => {
        const dayDate = new Date(day.date);
        dayDate.setHours(0, 0, 0, 0);
        return dayDate.getTime() === selected.getTime();
      }) || null
    );
  });

  previousMonth(): void {
    const date = new Date(this.currentDate());
    date.setMonth(date.getMonth() - 1);
    this.currentDate.set(date);
  }

  nextMonth(): void {
    const date = new Date(this.currentDate());
    date.setMonth(date.getMonth() + 1);
    this.currentDate.set(date);
  }

  goToToday(): void {
    this.currentDate.set(new Date());
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    this.selectedDate.set(today);
    this.daySelected.emit(today);
  }

  selectDay(day: CalendarDay): void {
    const selectedDate = new Date(day.date);
    selectedDate.setHours(0, 0, 0, 0);
    this.selectedDate.set(selectedDate);
    this.daySelected.emit(selectedDate);
  }

  isSelectedDay(day: CalendarDay): boolean {
    if (!this.selectedDate()) return false;

    const selected = new Date(this.selectedDate()!);
    selected.setHours(0, 0, 0, 0);

    const dayDate = new Date(day.date);
    dayDate.setHours(0, 0, 0, 0);

    return selected.getTime() === dayDate.getTime();
  }

  getFormattedDate(date: Date): string {
    const options: Intl.DateTimeFormatOptions = {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
    };
    return new Intl.DateTimeFormat('es-ES', options).format(date);
  }

  formatAriaLabel(day: CalendarDay): string {
    if (day.hasClasses) {
      return `${day.day} de ${this.monthNames[day.month]} - ${day.classes.length} clase${day.classes.length > 1 ? 's' : ''}`;
    }
    return `${day.day} de ${this.monthNames[day.month]}`;
  }
}
