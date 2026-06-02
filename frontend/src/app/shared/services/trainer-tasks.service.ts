import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

/** Tarea/alerta del entrenador humano (espejo de TrainerTask::toPublicArray). */
export interface TrainerTask {
  id: number;
  trainer_id: number;
  member_id: number;
  member_name: string | null;
  type: string;
  title: string;
  body: string;
  priority: 'low' | 'normal' | 'high' | string;
  status: 'pending' | 'seen' | 'done' | 'dismissed' | string;
  action_route: string | null;
  metadata: Record<string, any>;
  due_at: string | null;
  seen_at: string | null;
  completed_at: string | null;
  created_at: string | null;
}

export interface TrainerTasksPage {
  ok: boolean;
  data: TrainerTask[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

interface TaskResponse {
  ok: boolean;
  data: TrainerTask;
}
interface UnreadResponse {
  ok: boolean;
  pending: number;
}
interface TimelineResponse {
  ok: boolean;
  member_id: number;
  data: TrainerTask[];
}

export interface TrainerTasksQuery {
  status?: string;
  priority?: string;
  page?: number;
  perPage?: number;
}

/**
 * Tareas/alertas del entrenador humano (CRM admin). 100% conectado al backend
 * Laravel real; sin datos simulados. Solo consume datos públicos
 * (TrainerTask::toPublicArray) — nunca documentos, biometría, pagos ni tokens.
 */
@Injectable({ providedIn: 'root' })
export class TrainerTasksService {
  private readonly http = inject(HttpClient);

  /** API base del CRM admin (centralizado en environment). */
  private readonly base = environment.adminApiBaseUrl;

  /** Lista paginada de tareas de un entrenador (filtros opcionales). */
  listTasks(trainerId: number | string, query: TrainerTasksQuery = {}): Observable<TrainerTasksPage> {
    let params = new HttpParams();
    if (query.status) params = params.set('status', query.status);
    if (query.priority) params = params.set('priority', query.priority);
    if (query.page) params = params.set('page', String(query.page));
    if (query.perPage) params = params.set('per_page', String(query.perPage));
    return this.http.get<TrainerTasksPage>(`${this.base}/trainers/${trainerId}/tasks`, { params });
  }

  /** Conteo de tareas pendientes/vistas (para el badge). */
  unreadCount(trainerId: number | string): Observable<UnreadResponse> {
    return this.http.get<UnreadResponse>(`${this.base}/trainers/${trainerId}/tasks/unread-count`);
  }

  markSeen(taskId: number): Observable<TaskResponse> {
    return this.http.post<TaskResponse>(`${this.base}/trainer-tasks/${taskId}/seen`, {});
  }

  complete(taskId: number): Observable<TaskResponse> {
    return this.http.post<TaskResponse>(`${this.base}/trainer-tasks/${taskId}/complete`, {});
  }

  dismiss(taskId: number): Observable<TaskResponse> {
    return this.http.post<TaskResponse>(`${this.base}/trainer-tasks/${taskId}/dismiss`, {});
  }

  /** Historial coach del miembro (timeline). */
  memberTimeline(memberId: number): Observable<TimelineResponse> {
    return this.http.get<TimelineResponse>(`${this.base}/members/${memberId}/coach-timeline`);
  }
}
