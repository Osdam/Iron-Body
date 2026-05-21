import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

@Component({
  selector: 'app-forgot-password',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, RouterLink],
  templateUrl: './forgot-password.component.html',
  styleUrls: ['./forgot-password.component.scss'],
})
export class ForgotPasswordComponent {
  private readonly fb = inject(FormBuilder);
  private readonly router = inject(Router);

  forgotForm!: FormGroup;
  protected readonly isLoading = signal(false);
  protected readonly errorMessage = signal('');
  protected readonly successMessage = signal('');

  constructor() {
    this.initializeForm();
  }

  protected initializeForm(): void {
    this.forgotForm = this.fb.group({
      email: ['', [Validators.required, Validators.email]],
    });
  }

  protected onSubmit(): void {
    if (this.forgotForm.invalid) {
      return;
    }

    this.errorMessage.set('');
    this.successMessage.set('');
    this.isLoading.set(true);

    const email = this.forgotForm.get('email')?.value;

    // Simular envío de email (500-1000ms)
    setTimeout(() => {
      this.isLoading.set(false);
      this.successMessage.set(
        'Si el correo existe, enviaremos instrucciones para restablecer la contraseña.',
      );
      this.forgotForm.reset();

      // Redirigir a login después de 3 segundos
      setTimeout(() => {
        this.router.navigate(['/login']);
      }, 3000);
    }, 600);

    // TODO: POST /api/forgot-password con el email al backend
    // this.http.post('http://127.0.0.1:8080/api/forgot-password', { email })
    //   .pipe(
    //     timeout(5000),
    //     catchError(error => {
    //       this.isLoading.set(false);
    //       this.errorMessage.set(
    //         'Error al enviar instrucciones. Intenta nuevamente más tarde.'
    //       );
    //       return throwError(() => error);
    //     })
    //   )
    //   .subscribe(() => {
    //     this.isLoading.set(false);
    //     this.successMessage.set(
    //       'Si el correo existe, enviaremos instrucciones para restablecer la contraseña.'
    //     );
    //     this.forgotForm.reset();
    //     setTimeout(() => this.router.navigate(['/login']), 3000);
    //   });
  }

  protected isFieldInvalid(fieldName: string): boolean {
    const field = this.forgotForm.get(fieldName);
    return field ? field.invalid && (field.dirty || field.touched) : false;
  }

  protected getFieldError(fieldName: string): string {
    const field = this.forgotForm.get(fieldName);
    if (!field || !field.errors) {
      return '';
    }

    if (field.errors['required']) {
      return 'El correo es requerido';
    }

    if (field.errors['email']) {
      return 'Correo inválido';
    }

    return '';
  }
}
