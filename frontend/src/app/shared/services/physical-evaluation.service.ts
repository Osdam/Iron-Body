import { Injectable, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

export interface MemberOption {
  id: number;
  full_name: string;
  document: string | null;
}

export interface PhysicalEvaluation {
  id: number;
  member_id: number;
  trainer_id: number | null;
  weight_kg: number | null;
  height_cm: number | null;
  body_fat_pct: number | null;
  muscle_mass_pct: number | null;
  waist_cm: number | null;
  hip_cm: number | null;
  chest_cm: number | null;
  arm_cm: number | null;
  leg_cm: number | null;
  injuries: string | null;
  trainer_notes: string | null;
  bmi: number | null;
  bmi_label: string | null;
  created_at: string | null;
}

interface MembersResponse { ok: boolean; data: MemberOption[] }
interface ListResponse { ok: boolean; member: { id: number; full_name: string }; data: PhysicalEvaluation[] }
interface ItemResponse { ok: boolean; data: PhysicalEvaluation }

/**
 * Administración de evaluaciones físicas desde el CRM. 100% conectado al
 * backend Laravel (admin). Sin datos simulados.
 */
@Injectable({ providedIn: 'root' })
export class PhysicalEvaluationService {
  private readonly http = inject(HttpClient);
  private readonly base = 'http://127.0.0.1:8080/api/admin';

  private readonly _members = signal<MemberOption[]>([]);
  public readonly members = this._members.asReadonly();

  private readonly _evaluations = signal<PhysicalEvaluation[]>([]);
  public readonly evaluations = this._evaluations.asReadonly();

  private readonly _loading = signal<boolean>(false);
  public readonly loading = this._loading.asReadonly();

  private readonly _error = signal<string | null>(null);
  public readonly error = this._error.asReadonly();

  searchMembers(q: string): void {
    const url = `${this.base}/physical-evaluations/members${q ? `?q=${encodeURIComponent(q)}` : ''}`;
    this.http.get<MembersResponse>(url).subscribe({
      next: (res) => this._members.set(res?.data ?? []),
      error: () => this._members.set([]),
    });
  }

  loadEvaluations(memberId: number): void {
    this._loading.set(true);
    this._error.set(null);
    this.http.get<ListResponse>(`${this.base}/members/${memberId}/physical-evaluations`).subscribe({
      next: (res) => { this._evaluations.set(res?.data ?? []); this._loading.set(false); },
      error: () => { this._error.set('No pudimos cargar las evaluaciones.'); this._loading.set(false); },
    });
  }

  create(memberId: number, payload: Partial<PhysicalEvaluation>): Observable<ItemResponse> {
    return this.http.post<ItemResponse>(`${this.base}/members/${memberId}/physical-evaluations`, payload);
  }

  update(id: number, payload: Partial<PhysicalEvaluation>): Observable<ItemResponse> {
    return this.http.put<ItemResponse>(`${this.base}/physical-evaluations/${id}`, payload);
  }

  remove(id: number): Observable<{ ok: boolean }> {
    return this.http.delete<{ ok: boolean }>(`${this.base}/physical-evaluations/${id}`);
  }
}
