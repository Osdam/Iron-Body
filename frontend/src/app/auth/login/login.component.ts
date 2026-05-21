import { Component, inject, signal, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../services/auth.service';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, RouterLink],
  templateUrl: './login.component.html',
  styleUrls: ['./login.component.scss'],
})
export class LoginComponent implements OnInit {
  private readonly authService = inject(AuthService);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  loginForm!: FormGroup;
  protected readonly showPassword = signal(false);
  protected readonly isLoading = signal(false);
  protected readonly errorMessage = signal('');
  protected readonly successMessage = signal('');
  protected readonly sessionExpired = signal(false);

  ngOnInit(): void {
    this.initializeForm();
    // Verificar si vino desde sesión expirada
    this.router.routerState.root.queryParams.subscribe((params) => {
      if (params['sessionExpired']) {
        this.sessionExpired.set(true);
        setTimeout(() => this.sessionExpired.set(false), 5000);
      }
    });
  }

  protected initializeForm(): void {
    this.loginForm = this.fb.group({
      email: ['', [Validators.required, Validators.email]],
      password: ['', [Validators.required, Validators.minLength(6)]],
      rememberMe: [false],
    });
  }

  protected togglePasswordVisibility(): void {
    this.showPassword.set(!this.showPassword());
  }

  protected onSubmit(): void {
    if (this.loginForm.invalid) {
      return;
    }

    this.errorMessage.set('');
    this.successMessage.set('');
    this.isLoading.set(true);

    const credentials = {
      email: this.loginForm.get('email')?.value,
      password: this.loginForm.get('password')?.value,
      rememberMe: this.loginForm.get('rememberMe')?.value,
    };

    this.authService.login(credentials).subscribe({
      next: () => {
        this.successMessage.set('¡Sesión iniciada correctamente!');
        this.isLoading.set(false);
        // Redirigir después de 500ms
        setTimeout(() => {
          this.authService.redirectAfterLogin();
        }, 500);
      },
      error: (error) => {
        this.isLoading.set(false);
        this.errorMessage.set(
          error.message || 'Credenciales incorrectas. Verifica tus datos e intenta nuevamente.',
        );
      },
    });
  }

  protected getPasswordFieldType(): string {
    return this.showPassword() ? 'text' : 'password';
  }

  protected isFieldInvalid(fieldName: string): boolean {
    const field = this.loginForm.get(fieldName);
    return field ? field.invalid && (field.dirty || field.touched) : false;
  }

  protected getFieldError(fieldName: string): string {
    const field = this.loginForm.get(fieldName);
    if (!field || !field.errors) {
      return '';
    }

    if (field.errors['required']) {
      return fieldName === 'email' ? 'El correo es requerido' : 'La contraseña es requerida';
    }

    if (field.errors['email']) {
      return 'Correo inválido';
    }

    if (field.errors['minlength']) {
      return 'Mínimo 6 caracteres';
    }

    return '';
  }
}
