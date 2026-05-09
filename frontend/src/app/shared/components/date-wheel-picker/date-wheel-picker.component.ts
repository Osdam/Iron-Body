import { CommonModule } from '@angular/common';
import {
  Component,
  ElementRef,
  EventEmitter,
  forwardRef,
  HostListener,
  Input,
  OnChanges,
  Output,
  SimpleChanges,
  ViewChild,
} from '@angular/core';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';

type PickerSize = 'sm' | 'md' | 'lg';

interface CalendarDay {
  date: Date;
  day: number;
  isCurrentMonth: boolean;
  isToday: boolean;
  isSelected: boolean;
  isDisabled: boolean;
}

@Component({
  selector: 'app-date-wheel-picker',
  standalone: true,
  imports: [CommonModule],
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => DateWheelPickerComponent),
      multi: true,
    },
  ],
  template: `
    <div
      class="calendar-picker"
      [class.disabled]="isDisabled"
      [class.size-sm]="size === 'sm'"
      [class.size-lg]="size === 'lg'"
    >
      <button
        #trigger
        type="button"
        class="date-trigger"
        [disabled]="isDisabled"
        (click)="toggleOpen()"
        [attr.aria-expanded]="isOpen"
        [attr.aria-label]="ariaLabel"
      >
        <span class="material-symbols-outlined" aria-hidden="true">calendar_month</span>
        <span class="date-value">{{ formattedDate }}</span>
        <span class="material-symbols-outlined chevron" [class.open]="isOpen" aria-hidden="true">
          expand_more
        </span>
      </button>

      <div
        *ngIf="isOpen"
        class="calendar-popover"
        [ngStyle]="popoverStyle"
        role="dialog"
        [attr.aria-label]="ariaLabel"
      >
        <header class="calendar-header">
          <button type="button" class="nav-button" (click)="previousMonth()" aria-label="Mes anterior">
            <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
          </button>

          <div class="caption-controls">
            <select
              class="caption-select"
              [value]="visibleMonth"
              (change)="setVisibleMonth($any($event.target).value)"
              aria-label="Seleccionar mes"
            >
              <option *ngFor="let month of monthNames; let i = index" [value]="i">
                {{ month }}
              </option>
            </select>

            <select
              class="caption-select year"
              [value]="visibleYear"
              (change)="setVisibleYear($any($event.target).value)"
              aria-label="Seleccionar ano"
            >
              <option *ngFor="let year of years" [value]="year">{{ year }}</option>
            </select>
          </div>

          <button type="button" class="nav-button" (click)="nextMonth()" aria-label="Mes siguiente">
            <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
          </button>
        </header>

        <div class="weekdays" aria-hidden="true">
          <span *ngFor="let day of weekDays">{{ day }}</span>
        </div>

        <div class="calendar-grid">
          <button
            type="button"
            *ngFor="let item of calendarDays"
            class="calendar-day"
            [class.outside]="!item.isCurrentMonth"
            [class.today]="item.isToday"
            [class.selected]="item.isSelected"
            [disabled]="item.isDisabled"
            (click)="selectDate(item.date)"
            [attr.aria-label]="ariaLabelForDay(item)"
          >
            {{ item.day }}
          </button>
        </div>

        <footer class="calendar-footer">
          <button type="button" class="ghost-action" (click)="selectToday()">Hoy</button>
          <button type="button" class="primary-action" (click)="close()">Listo</button>
        </footer>
      </div>
    </div>
  `,
  styles: [
    `
      .calendar-picker {
        position: relative;
        width: 100%;
      }

      .date-trigger {
        width: 100%;
        min-height: 2.65rem;
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        align-items: center;
        gap: 0.55rem;
        padding: 0.68rem 0.78rem;
        border: 1px solid #d4d4d8;
        border-radius: 8px;
        background: #ffffff;
        color: #18181b;
        font: 700 0.9rem Inter, sans-serif;
        text-align: left;
        cursor: pointer;
        transition:
          border-color 160ms ease,
          box-shadow 160ms ease,
          background 160ms ease;
      }

      .date-trigger:hover:not(:disabled),
      .date-trigger:focus-visible {
        border-color: #eab308;
        box-shadow: 0 0 0 3px rgba(234, 179, 8, 0.14);
        outline: none;
      }

      .date-trigger .material-symbols-outlined {
        color: #ca8a04;
        font-size: 1.15rem;
      }

      .date-value {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .chevron {
        transition: transform 180ms ease;
      }

      .chevron.open {
        transform: rotate(180deg);
      }

      .calendar-popover {
        position: absolute;
        top: calc(100% + 0.5rem);
        left: 0;
        z-index: 3000;
        width: 318px;
        padding: 0.75rem;
        border: 1px solid #e4e4e7;
        border-radius: 12px;
        background: #ffffff;
        color: #18181b;
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.24);
        animation: popoverIn 160ms cubic-bezier(0.2, 0.8, 0.2, 1);
      }

      @keyframes popoverIn {
        from {
          opacity: 0;
          transform: translateY(-6px) scale(0.97);
        }
        to {
          opacity: 1;
          transform: translateY(0) scale(1);
        }
      }

      .calendar-header {
        display: grid;
        grid-template-columns: 2.25rem minmax(0, 1fr) 2.25rem;
        align-items: center;
        gap: 0.45rem;
        padding-bottom: 0.5rem;
      }

      .nav-button {
        width: 2.25rem;
        height: 2.25rem;
        display: grid;
        place-items: center;
        border: 0;
        border-radius: 9px;
        background: transparent;
        color: #71717a;
        cursor: pointer;
        transition:
          background 150ms ease,
          color 150ms ease;
      }

      .nav-button:hover {
        background: #f4f4f5;
        color: #18181b;
      }

      .caption-controls {
        display: grid;
        grid-template-columns: minmax(0, 1.2fr) minmax(82px, 0.8fr);
        gap: 0.4rem;
      }

      .caption-select {
        min-width: 0;
        height: 2.25rem;
        border: 1px solid #e4e4e7;
        border-radius: 9px;
        background: #fff;
        color: #18181b;
        font: 800 0.84rem Inter, sans-serif;
        text-transform: capitalize;
        padding: 0 0.55rem;
        outline: none;
      }

      .caption-select:focus {
        border-color: #eab308;
        box-shadow: 0 0 0 3px rgba(234, 179, 8, 0.14);
      }

      .weekdays,
      .calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 0.25rem;
      }

      .weekdays {
        padding: 0.15rem 0 0.35rem;
      }

      .weekdays span {
        display: grid;
        place-items: center;
        height: 2rem;
        color: #71717a;
        font: 800 0.72rem Inter, sans-serif;
        text-transform: uppercase;
      }

      .calendar-day {
        position: relative;
        width: 100%;
        aspect-ratio: 1;
        display: grid;
        place-items: center;
        border: 1px solid transparent;
        border-radius: 9px;
        background: transparent;
        color: #18181b;
        font: 750 0.88rem Inter, sans-serif;
        cursor: pointer;
        transition:
          background 150ms ease,
          color 150ms ease,
          border-color 150ms ease,
          transform 150ms ease;
      }

      .calendar-day:hover:not(:disabled) {
        background: #f4f4f5;
        transform: translateY(-1px);
      }

      .calendar-day.outside {
        color: #a1a1aa;
      }

      .calendar-day.today::after {
        content: '';
        position: absolute;
        bottom: 0.28rem;
        left: 50%;
        width: 4px;
        height: 4px;
        border-radius: 999px;
        background: #ca8a04;
        transform: translateX(-50%);
      }

      .calendar-day.selected {
        background: #facc15;
        color: #111827;
        border-color: #eab308;
        font-weight: 950;
      }

      .calendar-day.selected.today::after {
        background: #111827;
      }

      .calendar-day:disabled {
        opacity: 0.32;
        cursor: not-allowed;
      }

      .calendar-footer {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.55rem;
        padding-top: 0.7rem;
        margin-top: 0.65rem;
        border-top: 1px solid #f0f0f0;
      }

      .ghost-action,
      .primary-action {
        min-height: 2.35rem;
        border-radius: 8px;
        font: 850 0.86rem Inter, sans-serif;
        cursor: pointer;
        transition:
          background 150ms ease,
          border-color 150ms ease,
          transform 150ms ease;
      }

      .ghost-action {
        border: 1px solid #d4d4d8;
        background: #fff;
        color: #18181b;
      }

      .primary-action {
        border: 1px solid #eab308;
        background: #facc15;
        color: #111827;
      }

      .ghost-action:hover,
      .primary-action:hover {
        transform: translateY(-1px);
      }

      .disabled {
        opacity: 0.58;
        pointer-events: none;
      }

      .size-sm .date-trigger {
        min-height: 2.45rem;
        font-size: 0.84rem;
      }

      .size-lg .date-trigger {
        min-height: 2.95rem;
        font-size: 0.96rem;
      }

      @media (max-width: 480px) {
        .calendar-popover {
          width: calc(100vw - 1.5rem);
        }
      }
    `,
  ],
})
export class DateWheelPickerComponent implements ControlValueAccessor, OnChanges {
  @Input() minYear = 1920;
  @Input() maxYear = new Date().getFullYear() + 5;
  @Input() size: PickerSize = 'md';
  @Input() locale = 'es-CO';
  @Input() ariaLabel = 'Selector de fecha';
  @Input() disabled = false;
  @Output() dateChange = new EventEmitter<string>();
  @ViewChild('trigger') trigger?: ElementRef<HTMLButtonElement>;

  selectedDay = new Date().getDate();
  selectedMonth = new Date().getMonth();
  selectedYear = new Date().getFullYear();
  visibleMonth = new Date().getMonth();
  visibleYear = new Date().getFullYear();
  isDisabled = false;
  isOpen = false;
  popoverStyle: Record<string, string> = {};

  readonly weekDays = ['Do', 'Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sa'];

  private onChange: (value: string) => void = () => {};
  private onTouched: () => void = () => {};

  constructor(private readonly elementRef: ElementRef<HTMLElement>) {}

  @HostListener('document:click', ['$event'])
  onDocumentClick(event: MouseEvent): void {
    if (!this.isOpen) return;
    if (!this.elementRef.nativeElement.contains(event.target as Node)) {
      this.close();
    }
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (this.isOpen) this.close();
  }

  @HostListener('window:resize')
  @HostListener('window:scroll')
  reposition(): void {
    if (this.isOpen) this.positionPopover();
  }

  get monthNames(): string[] {
    const formatter = new Intl.DateTimeFormat(this.locale, { month: 'long' });
    return Array.from({ length: 12 }, (_, index) => formatter.format(new Date(2000, index, 1)));
  }

  get years(): number[] {
    const years: number[] = [];
    for (let year = this.minYear; year <= this.maxYear; year++) {
      years.push(year);
    }
    return years;
  }

  get formattedDate(): string {
    const date = new Date(this.selectedYear, this.selectedMonth, this.selectedDay);
    return new Intl.DateTimeFormat(this.locale, {
      day: '2-digit',
      month: 'long',
      year: 'numeric',
    }).format(date);
  }

  get calendarDays(): CalendarDay[] {
    const firstOfMonth = new Date(this.visibleYear, this.visibleMonth, 1);
    const start = new Date(firstOfMonth);
    start.setDate(start.getDate() - start.getDay());

    const today = this.startOfDay(new Date());
    const selected = this.startOfDay(new Date(this.selectedYear, this.selectedMonth, this.selectedDay));
    const days: CalendarDay[] = [];

    for (let index = 0; index < 42; index++) {
      const date = new Date(start);
      date.setDate(start.getDate() + index);

      const normalized = this.startOfDay(date);
      days.push({
        date,
        day: date.getDate(),
        isCurrentMonth: date.getMonth() === this.visibleMonth,
        isToday: normalized.getTime() === today.getTime(),
        isSelected: normalized.getTime() === selected.getTime(),
        isDisabled: date.getFullYear() < this.minYear || date.getFullYear() > this.maxYear,
      });
    }

    return days;
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['disabled']) {
      this.isDisabled = this.disabled;
    }

    if (changes['minYear'] || changes['maxYear']) {
      this.selectedYear = Math.min(this.maxYear, Math.max(this.minYear, this.selectedYear));
      this.visibleYear = Math.min(this.maxYear, Math.max(this.minYear, this.visibleYear));
      this.clampDay();
    }
  }

  writeValue(value: string | Date | null | undefined): void {
    const parsed = this.parseDate(value);
    if (!parsed) return;

    this.selectedDay = parsed.getDate();
    this.selectedMonth = parsed.getMonth();
    this.selectedYear = parsed.getFullYear();
    this.visibleMonth = this.selectedMonth;
    this.visibleYear = this.selectedYear;
    this.clampDay();
  }

  registerOnChange(fn: (value: string) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

  setDisabledState(isDisabled: boolean): void {
    this.isDisabled = isDisabled;
  }

  toggleOpen(): void {
    if (this.isDisabled) return;
    this.isOpen = !this.isOpen;
    this.onTouched();

    if (this.isOpen) {
      this.visibleMonth = this.selectedMonth;
      this.visibleYear = this.selectedYear;
      requestAnimationFrame(() => this.positionPopover());
    }
  }

  close(): void {
    this.isOpen = false;
    this.onTouched();
  }

  previousMonth(): void {
    const date = new Date(this.visibleYear, this.visibleMonth - 1, 1);
    this.visibleMonth = date.getMonth();
    this.visibleYear = Math.max(this.minYear, date.getFullYear());
  }

  nextMonth(): void {
    const date = new Date(this.visibleYear, this.visibleMonth + 1, 1);
    this.visibleMonth = date.getMonth();
    this.visibleYear = Math.min(this.maxYear, date.getFullYear());
  }

  setVisibleMonth(value: string | number): void {
    this.visibleMonth = Number(value);
  }

  setVisibleYear(value: string | number): void {
    this.visibleYear = Number(value);
  }

  selectToday(): void {
    const today = new Date();
    this.selectDate(today, false);
  }

  selectDate(date: Date, shouldClose = true): void {
    if (date.getFullYear() < this.minYear || date.getFullYear() > this.maxYear) return;

    this.selectedDay = date.getDate();
    this.selectedMonth = date.getMonth();
    this.selectedYear = date.getFullYear();
    this.visibleMonth = this.selectedMonth;
    this.visibleYear = this.selectedYear;
    this.emitValue();

    if (shouldClose) this.close();
  }

  ariaLabelForDay(day: CalendarDay): string {
    const date = new Intl.DateTimeFormat(this.locale, {
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    }).format(day.date);
    return day.isSelected ? `${date}, seleccionado` : date;
  }

  private positionPopover(): void {
    const trigger = this.trigger?.nativeElement;
    if (!trigger) return;

    const rect = trigger.getBoundingClientRect();
    const width = Math.min(318, window.innerWidth - 24);
    const estimatedHeight = 390;
    const hasRoomBelow = rect.bottom + estimatedHeight < window.innerHeight - 12;

    this.popoverStyle = {
      top: hasRoomBelow ? 'calc(100% + 0.5rem)' : 'auto',
      bottom: hasRoomBelow ? 'auto' : 'calc(100% + 0.5rem)',
      left: '0',
      width: `${width}px`,
    };
  }

  private emitValue(): void {
    const value = this.toDateInputValue();
    this.onChange(value);
    this.dateChange.emit(value);
  }

  private clampDay(): void {
    const days = new Date(this.selectedYear, this.selectedMonth + 1, 0).getDate();
    this.selectedDay = Math.min(this.selectedDay, days);
  }

  private toDateInputValue(): string {
    const month = String(this.selectedMonth + 1).padStart(2, '0');
    const day = String(this.selectedDay).padStart(2, '0');
    return `${this.selectedYear}-${month}-${day}`;
  }

  private parseDate(value: string | Date | null | undefined): Date | null {
    if (!value) return null;
    if (value instanceof Date && !Number.isNaN(value.getTime())) return value;
    if (typeof value !== 'string') return null;

    const [year, month, day] = value.split('-').map(Number);
    if (!year || !month || !day) return null;

    const parsed = new Date(year, month - 1, day);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
  }

  private startOfDay(date: Date): Date {
    const normalized = new Date(date);
    normalized.setHours(0, 0, 0, 0);
    return normalized;
  }
}
