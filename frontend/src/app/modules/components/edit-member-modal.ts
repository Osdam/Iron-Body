import { CommonModule } from '@angular/common';
import {
  Component,
  EventEmitter,
  Input,
  OnChanges,
  Output,
  SimpleChanges,
  inject,
  signal,
} from '@angular/core';
import {
  FormBuilder,
  FormGroup,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import { ApiService, UserSummary } from '../../services/api.service';
import { LottieIconComponent } from '../../shared/components/lottie-icon/lottie-icon.component';
import { DateWheelPickerComponent } from '../../shared/components/date-wheel-picker/date-wheel-picker.component';

@Component({
  selector: 'app-edit-member-modal',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, LottieIconComponent, DateWheelPickerComponent],
  template: `
    <div *ngIf="isOpen" class="modal-backdrop" (click)="close()" aria-hidden="true"></div>

    <div *ngIf="isOpen" class="modal-container">
      <div class="modal-card">
        <header class="modal-header">
          <div class="header-title">
            <div class="header-icon">
              <app-lottie-icon src="/assets/crm/edit.json" [size]="30" [loop]="true"></app-lottie-icon>
            </div>
            <div>
              <h2>Editar miembro</h2>
              <p>Actualiza los datos personales y el estado de la membresía.</p>
            </div>
          </div>
          <button type="button" class="btn-close" (click)="close()" [disabled]="saving()" aria-label="Cerrar">
            <span class="material-symbols-outlined">close</span>
          </button>
        </header>

        <div *ngIf="errorMessage()" class="message error">
          <span class="material-symbols-outlined">error</span>
          <p>{{ errorMessage() }}</p>
        </div>

        <form [formGroup]="form" (ngSubmit)="onSubmit()" class="modal-form">
          <div class="form-grid">
            <div class="form-group full">
              <label>Nombre completo</label>
              <input type="text" formControlName="name" class="form-input" />
            </div>

            <div class="form-group">
              <label>Documento</label>
              <input type="text" formControlName="document" class="form-input" />
            </div>

            <div class="form-group">
              <label>Teléfono</label>
              <input type="text" formControlName="phone" class="form-input" />
            </div>

            <div class="form-group full">
              <label>Correo</label>
              <input type="email" formControlName="email" class="form-input" />
            </div>

            <div class="form-group">
              <label>Estado</label>
              <select formControlName="status" class="form-input">
                <option value="active">Activo</option>
                <option value="inactive">Inactivo</option>
                <option value="pending">Pendiente</option>
                <option value="expired">Vencido</option>
              </select>
            </div>

            <div class="form-group">
              <label>Plan / Membresía</label>
              <input
                type="text"
                formControlName="plan"
                class="form-input"
                placeholder="Ej: Plan Mensual VIP"
              />
            </div>

            <div class="form-group">
              <label>Fecha de inicio</label>
              <app-date-wheel-picker
                formControlName="membershipStartDate"
                [minYear]="currentYear - 2"
                [maxYear]="currentYear + 3"
                size="sm"
                ariaLabel="Fecha de inicio de membresia"
              ></app-date-wheel-picker>
            </div>

            <div class="form-group">
              <label>Fecha de vencimiento</label>
              <app-date-wheel-picker
                formControlName="membershipEndDate"
                [minYear]="currentYear - 2"
                [maxYear]="currentYear + 5"
                size="sm"
                ariaLabel="Fecha de vencimiento de membresia"
              ></app-date-wheel-picker>
            </div>
          </div>

          <footer class="modal-footer">
            <button type="button" class="btn-secondary" (click)="close()" [disabled]="saving()">
              Cancelar
            </button>
            <button type="submit" class="btn-primary" [disabled]="saving() || form.invalid">
              <span *ngIf="!saving()">Guardar cambios</span>
              <span *ngIf="saving()">Guardando…</span>
            </button>
          </footer>
        </form>
      </div>
    </div>
  `,
  styles: [
    `
      .modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.55);
        backdrop-filter: blur(4px);
        z-index: 999;
      }

      .modal-container {
        position: fixed;
        inset: 0;
        z-index: 1000;
        display: grid;
        place-items: center;
        padding: 1.5rem;
      }

      .modal-card {
        width: 100%;
        max-width: 560px;
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.25);
        overflow: hidden;
        animation: slideUp 220ms cubic-bezier(0.4, 0, 0.2, 1);
      }

      @keyframes slideUp {
        from {
          opacity: 0;
          transform: translateY(14px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }

      .modal-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.5rem 1.75rem;
        border-bottom: 1px solid #f0f0f0;
      }

      .header-title {
        display: flex;
        align-items: center;
        gap: 0.85rem;
      }

      .header-icon {
        width: 44px;
        height: 44px;
        display: grid;
        place-items: center;
        border-radius: 11px;
        background: rgba(250, 204, 21, 0.14);
        overflow: hidden;
      }

      .header-title h2 {
        font: 700 1.15rem Inter, sans-serif;
        margin: 0 0 0.2rem;
        color: #0a0a0a;
      }

      .header-title p {
        font: 400 0.85rem Inter, sans-serif;
        color: #666;
        margin: 0;
      }

      .btn-close {
        background: #f5f5f5;
        border: none;
        border-radius: 8px;
        width: 36px;
        height: 36px;
        cursor: pointer;
        display: grid;
        place-items: center;
        color: #555;
        transition: all 150ms ease;
      }

      .btn-close:hover {
        background: #e5e5e5;
        color: #0a0a0a;
      }

      .message {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        padding: 0.85rem 1.25rem;
        margin: 1rem 1.5rem 0;
        border-radius: 10px;
        font-size: 0.88rem;
      }

      .message.error {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fecaca;
      }

      .message p {
        margin: 0;
      }

      .modal-form {
        padding: 1.25rem 1.75rem 1.75rem;
      }

      .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
      }

      .form-group.full {
        grid-column: 1 / -1;
      }

      .form-group label {
        display: block;
        font: 600 0.78rem 'Space Grotesk', sans-serif;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #555;
        margin-bottom: 0.4rem;
      }

      .form-input {
        width: 100%;
        padding: 0.75rem 0.95rem;
        border: 1px solid #e5e5e5;
        border-radius: 9px;
        font: 400 0.92rem Inter, sans-serif;
        color: #0a0a0a;
        background: #fff;
        transition: all 180ms ease;
      }

      .form-input:focus {
        outline: none;
        border-color: #facc15;
        box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.18);
      }

      .modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 0.65rem;
        margin-top: 1.5rem;
      }

      .btn-secondary,
      .btn-primary {
        padding: 0.75rem 1.4rem;
        border-radius: 9px;
        font: 600 0.92rem Inter, sans-serif;
        cursor: pointer;
        border: none;
        transition: all 180ms ease;
      }

      .btn-secondary {
        background: #f5f5f5;
        color: #333;
      }

      .btn-secondary:hover:not(:disabled) {
        background: #e5e5e5;
      }

      .btn-primary {
        background: #facc15;
        color: #0a0a0a;
        font-weight: 700;
      }

      .btn-primary:hover:not(:disabled) {
        background: #f0c00e;
      }

      .btn-primary:disabled,
      .btn-secondary:disabled,
      .btn-close:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }

      @media (max-width: 560px) {
        .form-grid {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export class EditMemberModalComponent implements OnChanges {
  @Input() isOpen = false;
  @Input() member: UserSummary | null = null;
  @Output() onClose = new EventEmitter<void>();
  @Output() onUpdated = new EventEmitter<UserSummary>();

  private fb = inject(FormBuilder);
  private api = inject(ApiService);

  saving = signal(false);
  errorMessage = signal('');
  currentYear = new Date().getFullYear();

  form: FormGroup = this.fb.group({
    name: ['', [Validators.required, Validators.maxLength(255)]],
    document: [''],
    phone: [''],
    email: ['', [Validators.email]],
    status: ['active'],
    plan: [''],
    membershipStartDate: [''],
    membershipEndDate: [''],
  });

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['member'] && this.member) {
      this.form.reset({
        name: this.member.name || '',
        document: this.member.document || '',
        phone: this.member.phone || '',
        email: this.member.email || '',
        status: this.member.status || 'active',
        plan: this.member.plan || '',
        membershipStartDate: this.member.membershipStartDate || '',
        membershipEndDate: this.member.membershipEndDate || '',
      });
      this.errorMessage.set('');
    }
  }

  close(): void {
    if (this.saving()) return;
    this.onClose.emit();
  }

  onSubmit(): void {
    if (!this.member || this.form.invalid) return;

    this.saving.set(true);
    this.errorMessage.set('');

    const raw = this.form.getRawValue();
    const payload = {
      name: raw.name,
      document: raw.document || null,
      phone: raw.phone || null,
      email: raw.email || null,
      status: raw.status,
      plan: raw.plan || null,
      membershipStartDate: raw.membershipStartDate || null,
      membershipEndDate: raw.membershipEndDate || null,
    };

    this.api.updateUser(this.member.id, payload).subscribe({
      next: (updated) => {
        this.saving.set(false);
        this.onUpdated.emit(updated);
      },
      error: (err) => {
        this.saving.set(false);
        const msg =
          err?.error?.message ||
          (err?.status === 422
            ? 'Datos inválidos. Revisa los campos del formulario.'
            : 'No se pudieron guardar los cambios. Intenta de nuevo.');
        this.errorMessage.set(msg);
      },
    });
  }
}
