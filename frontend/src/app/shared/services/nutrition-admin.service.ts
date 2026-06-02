import { Injectable, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { MemberOption } from './physical-evaluation.service';

export interface NutritionGoal {
  daily_calories: number;
  protein_g: number;
  carbs_g: number;
  fat_g: number;
  goal_type: string | null;
}

export interface NutritionAdminData {
  ok: boolean;
  member: { id: number; full_name: string };
  goal: NutritionGoal;
  today: {
    consumed: { calories: number; protein_g: number; carbs_g: number; fat_g: number };
    remaining: { calories: number; protein_g: number; carbs_g: number; fat_g: number };
    meals: Array<{ meal_type: string; items: any[]; totals: any }>;
    has_data: boolean;
  };
  streak: { current: number; has_logged_today: boolean };
  weekly_history: Array<{ label: string; date: string; calories: number; goal_met: boolean; is_today: boolean }>;
}

export interface AiRecommendation {
  id: number;
  date: string | null;
  summary: string | null;
  recommendation: any;
  created_at: string | null;
}

/**
 * Administración de Nutrición por miembro desde el CRM. 100% backend Laravel.
 */
@Injectable({ providedIn: 'root' })
export class NutritionAdminService {
  private readonly http = inject(HttpClient);
  private readonly base = environment.adminApiBaseUrl;

  private readonly _data = signal<NutritionAdminData | null>(null);
  public readonly data = this._data.asReadonly();

  private readonly _recommendations = signal<AiRecommendation[]>([]);
  public readonly recommendations = this._recommendations.asReadonly();

  private readonly _loading = signal<boolean>(false);
  public readonly loading = this._loading.asReadonly();

  private readonly _error = signal<string | null>(null);
  public readonly error = this._error.asReadonly();

  /** Reutiliza el buscador de miembros del módulo de evaluaciones. */
  searchMembers(q: string): Observable<{ ok: boolean; data: MemberOption[] }> {
    const url = `${this.base}/physical-evaluations/members${q ? `?q=${encodeURIComponent(q)}` : ''}`;
    return this.http.get<{ ok: boolean; data: MemberOption[] }>(url);
  }

  load(memberId: number): void {
    this._loading.set(true);
    this._error.set(null);
    this.http.get<NutritionAdminData>(`${this.base}/members/${memberId}/nutrition`).subscribe({
      next: (res) => { this._data.set(res); this._loading.set(false); },
      error: () => { this._error.set('No pudimos cargar la nutrición.'); this._loading.set(false); },
    });
    this.http.get<{ ok: boolean; data: AiRecommendation[] }>(`${this.base}/members/${memberId}/nutrition/recommendations`).subscribe({
      next: (res) => this._recommendations.set(res?.data ?? []),
      error: () => this._recommendations.set([]),
    });
  }

  saveGoals(memberId: number, goal: NutritionGoal): Observable<{ ok: boolean; data: NutritionGoal }> {
    return this.http.post<{ ok: boolean; data: NutritionGoal }>(`${this.base}/members/${memberId}/nutrition/goals`, goal);
  }
}
