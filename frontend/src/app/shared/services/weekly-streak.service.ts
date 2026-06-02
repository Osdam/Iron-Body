import { Injectable, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

/** Beneficio/recompensa por racha (config desde CRM). */
export interface WeeklyStreakReward {
  id: number;
  config_id: number | null;
  required_days: number;
  title: string;
  description: string | null;
  image_url: string | null;
  badge_label: string | null;
  reward_type: string | null;
  is_active: boolean;
  sort_order: number;
  metadata: Record<string, any> | null;
}

/** Configuración del módulo "Esta semana". */
export interface WeeklyStreakConfig {
  id: number;
  title: string;
  subtitle: string | null;
  weekly_goal_days: number;
  hero_title: string | null;
  hero_description: string | null;
  hero_image_url: string | null;
  promo_image_url: string | null;
  cta_label: string | null;
  cta_route: string | null;
  is_active: boolean;
  sort_order: number;
  metadata: Record<string, any> | null;
  rewards: WeeklyStreakReward[];
}

interface ListResponse {
  ok: boolean;
  data: WeeklyStreakConfig[];
}
interface ConfigResponse {
  ok: boolean;
  data: WeeklyStreakConfig;
}
interface RewardResponse {
  ok: boolean;
  data: WeeklyStreakReward;
}
interface UploadResponse {
  ok: boolean;
  data: { path: string; url: string };
}

/**
 * Administración del módulo "Esta semana" desde el CRM. 100% conectado al
 * backend Laravel (admin). Sin datos simulados.
 */
@Injectable({ providedIn: 'root' })
export class WeeklyStreakService {
  private readonly http = inject(HttpClient);

  /** Misma base que el resto del CRM admin. */
  private readonly base = 'http://127.0.0.1:8080/api/admin/weekly-streak';

  private readonly _configs = signal<WeeklyStreakConfig[]>([]);
  public readonly configs = this._configs.asReadonly();

  private readonly _loading = signal<boolean>(false);
  public readonly loading = this._loading.asReadonly();

  private readonly _error = signal<string | null>(null);
  public readonly error = this._error.asReadonly();

  /** Carga la lista de configuraciones con sus beneficios. */
  refresh(): void {
    this._loading.set(true);
    this._error.set(null);
    this.http.get<ListResponse>(`${this.base}/configs`).subscribe({
      next: (res) => {
        this._configs.set(res?.data ?? []);
        this._loading.set(false);
      },
      error: () => {
        this._error.set('No pudimos cargar la configuración de "Esta semana".');
        this._loading.set(false);
      },
    });
  }

  createConfig(payload: Partial<WeeklyStreakConfig>): Observable<ConfigResponse> {
    return this.http.post<ConfigResponse>(`${this.base}/configs`, payload);
  }

  updateConfig(id: number, payload: Partial<WeeklyStreakConfig>): Observable<ConfigResponse> {
    return this.http.put<ConfigResponse>(`${this.base}/configs/${id}`, payload);
  }

  deleteConfig(id: number): Observable<{ ok: boolean }> {
    return this.http.delete<{ ok: boolean }>(`${this.base}/configs/${id}`);
  }

  createReward(payload: Partial<WeeklyStreakReward>): Observable<RewardResponse> {
    return this.http.post<RewardResponse>(`${this.base}/rewards`, payload);
  }

  updateReward(id: number, payload: Partial<WeeklyStreakReward>): Observable<RewardResponse> {
    return this.http.put<RewardResponse>(`${this.base}/rewards/${id}`, payload);
  }

  deleteReward(id: number): Observable<{ ok: boolean }> {
    return this.http.delete<{ ok: boolean }>(`${this.base}/rewards/${id}`);
  }

  /** Sube una imagen promocional y devuelve su URL pública. */
  uploadImage(file: File): Observable<UploadResponse> {
    const form = new FormData();
    form.append('file', file);
    return this.http.post<UploadResponse>(`${this.base}/upload`, form);
  }
}
