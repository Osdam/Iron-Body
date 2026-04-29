import { Component, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { RouterModule } from '@angular/router';

type BackendHealthResponse = {
  message: string;
  status: string;
  timestamp: string;
};

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterModule],
  templateUrl: './app.html',
  styleUrls: ['./app.css']
})
export class App {
  private readonly http = inject(HttpClient);

  protected readonly backendStatus = signal('Conectando con Laravel...');
  protected readonly backendDetail = signal('');

  constructor() {
    this.http.get<BackendHealthResponse>('http://127.0.0.1:8000/api/health').subscribe({
      next: (response) => {
        this.backendStatus.set(response.message);
        this.backendDetail.set(`${response.status} · ${response.timestamp}`);
      },
      error: () => {
        this.backendStatus.set('No se pudo conectar con Laravel');
        this.backendDetail.set('Revisa que el backend esté ejecutándose en http://127.0.0.1:8000');
      }
    });
  }
}
