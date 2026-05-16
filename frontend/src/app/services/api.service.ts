import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { tap } from 'rxjs/operators';
import { AuditLogService } from './audit-log.service';

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface UserSummary {
  id: number;
  name: string;
  email: string;
  document?: string;
  phone?: string;
  status?: string;
  plan?: string | null;
  membershipStartDate?: string | null;
  membershipEndDate?: string | null;
  created_at: string;
}

export interface PlanSummary {
  id: number;
  name: string;
  price: number;
  duration_days: number;
  benefits?: string | null;
  active: boolean;
}

export interface PaymentSummary {
  id: number;
  amount: number;
  method?: string | null;
  reference?: string | null;
  status: string;
  paid_at?: string | null;
  created_at: string;
  user?: Pick<UserSummary, 'id' | 'name' | 'email'> | null;
  plan?: Pick<PlanSummary, 'id' | 'name'> | null;
}

export interface ClassSummary {
  id: number;
  name: string;
  type: string;
  trainer_id?: number | null;
  day_of_week: string;
  start_time: string;
  end_time: string;
  duration_minutes?: number;
  max_capacity: number;
  enrolled_count: number;
  location?: string | null;
  status: 'active' | 'inactive';
  description?: string | null;
  notes?: string | null;
  is_recurring?: boolean;
  allow_online_booking?: boolean;
  requires_active_plan?: boolean;
  created_at: string;
  trainer?: Pick<UserSummary, 'id' | 'name'> | null;
}

export interface DashboardStats {
  users: number;
  active_plans: number;
  payments: number;
  revenue: number;
  classes?: number;
}

@Injectable({ providedIn: 'root' })
export class ApiService {
  private http = inject(HttpClient);
  private audit = inject(AuditLogService);
  private base = 'http://127.0.0.1:8080/api';

  getDashboardStats(): Observable<DashboardStats> {
    return this.http.get<DashboardStats>(`${this.base}/dashboard`);
  }

  getUsers(page = 1): Observable<PaginatedResponse<UserSummary>> {
    return this.http.get<PaginatedResponse<UserSummary>>(`${this.base}/users?page=${page}`);
  }

  getUser(id: number): Observable<UserSummary> {
    return this.http.get<UserSummary>(`${this.base}/users/${id}`);
  }

  updateUser(
    id: number,
    data: Partial<{
      name: string;
      email: string;
      document: string;
      phone: string;
      status: string;
      plan: string | null;
      membershipStartDate: string | null;
      membershipEndDate: string | null;
    }>,
  ): Observable<UserSummary> {
    return this.http.patch<UserSummary>(`${this.base}/users/${id}`, data).pipe(
      tap((updated) =>
        this.audit.record({
          action: 'update',
          module: 'Miembros',
          entity: 'cliente',
          entityId: id,
          targetName: updated.name,
          after: data as Record<string, unknown>,
          metadata: { response: updated },
        }),
      ),
    );
  }

  deleteUser(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/users/${id}`).pipe(
      tap(() =>
        this.audit.record({
          action: 'delete',
          module: 'Miembros',
          entity: 'cliente',
          entityId: id,
          summary: `eliminó cliente #${id}`,
        }),
      ),
    );
  }

  getPlans(page = 1): Observable<PaginatedResponse<PlanSummary>> {
    return this.http.get<PaginatedResponse<PlanSummary>>(`${this.base}/plans?page=${page}`);
  }

  getPayments(
    page = 1,
    filters?: { status?: string; search?: string; user_id?: number },
  ): Observable<PaginatedResponse<PaymentSummary>> {
    let url = `${this.base}/payments?page=${page}`;
    if (filters?.status) url += `&status=${filters.status}`;
    if (filters?.search) url += `&search=${encodeURIComponent(filters.search)}`;
    if (filters?.user_id) url += `&user_id=${filters.user_id}`;
    return this.http.get<PaginatedResponse<PaymentSummary>>(url);
  }

  createPayment(data: {
    user_id: number;
    plan_id?: number | null;
    amount: number;
    method?: string;
    reference?: string;
    status?: string;
    paid_at?: string;
  }): Observable<PaymentSummary> {
    return this.http.post<PaymentSummary>(`${this.base}/payments`, data).pipe(
      tap((payment) =>
        this.audit.record({
          action: 'create',
          module: 'Pagos',
          entity: 'pago',
          entityId: payment.id,
          targetName: payment.user?.name || `cliente #${data.user_id}`,
          after: data as Record<string, unknown>,
          metadata: { payment },
        }),
      ),
    );
  }

  updatePayment(
    id: number,
    data: { status?: string; paid_at?: string; method?: string; reference?: string; amount?: number },
  ): Observable<PaymentSummary> {
    return this.http.patch<PaymentSummary>(`${this.base}/payments/${id}`, data).pipe(
      tap((payment) =>
        this.audit.record({
          action: data.status ? 'status' : 'update',
          module: 'Pagos',
          entity: 'pago',
          entityId: id,
          targetName: payment.user?.name || payment.reference || `pago #${id}`,
          after: data as Record<string, unknown>,
          metadata: { payment },
        }),
      ),
    );
  }

  updatePlan(
    id: number,
    data: Partial<{
      name: string;
      price: number;
      duration_days: number;
      benefits: string;
      active: boolean;
      access_classes: boolean;
      reservations_limit: number;
    }>,
  ): Observable<PlanSummary> {
    return this.http.patch<PlanSummary>(`${this.base}/plans/${id}`, data).pipe(
      tap((plan) =>
        this.audit.record({
          action: 'update',
          module: 'Planes',
          entity: 'plan',
          entityId: id,
          targetName: plan.name,
          after: data as Record<string, unknown>,
          metadata: { plan },
        }),
      ),
    );
  }

  deletePlan(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/plans/${id}`).pipe(
      tap(() =>
        this.audit.record({
          action: 'delete',
          module: 'Planes',
          entity: 'plan',
          entityId: id,
          summary: `eliminó plan #${id}`,
        }),
      ),
    );
  }

  createPlan(data: {
    name: string;
    description?: string;
    price: number;
    duration_days: number;
    billing_cycle: string;
    plan_type: string;
    benefits?: string;
    active: boolean;
  }): Observable<PlanSummary> {
    return this.http.post<PlanSummary>(`${this.base}/plans`, data).pipe(
      tap((plan) =>
        this.audit.record({
          action: 'create',
          module: 'Planes',
          entity: 'plan',
          entityId: plan.id,
          targetName: plan.name,
          after: data as Record<string, unknown>,
          metadata: { plan },
        }),
      ),
    );
  }

  createMember(data: {
    fullName: string;
    document: string;
    phone: string;
    email?: string;
    birthDate?: string;
    gender?: string;
    address?: string;
    plan?: string;
    membershipStartDate?: string;
    membershipEndDate?: string;
    status: string;
    emergencyContact?: string;
    notes?: string;
    weight?: number;
    height?: number;
    fitnessGoal?: string;
    medicalConditions?: string;
    assignedTrainer?: string;
  }): Observable<UserSummary> {
    // TODO: Cambiar a endpoint POST real de Laravel
    // Actualmente usa mock. Descomenta cuando el backend esté listo:
    // return this.http.post<UserSummary>(`${this.base}/users`, data);
    return this.http.post<UserSummary>(`${this.base}/users`, data).pipe(
      tap((member) =>
        this.audit.record({
          action: 'create',
          module: 'Miembros',
          entity: 'cliente',
          entityId: member.id,
          targetName: member.name || data.fullName,
          after: data as Record<string, unknown>,
          metadata: { member },
        }),
      ),
    );
  }

  getClasses(
    page = 1,
    filters?: {
      status?: string;
      day_of_week?: string;
      trainer_id?: number;
      type?: string;
      search?: string;
    },
  ): Observable<PaginatedResponse<ClassSummary>> {
    let url = `${this.base}/classes?page=${page}`;

    if (filters?.status) url += `&status=${filters.status}`;
    if (filters?.day_of_week) url += `&day_of_week=${filters.day_of_week}`;
    if (filters?.trainer_id) url += `&trainer_id=${filters.trainer_id}`;
    if (filters?.type) url += `&type=${filters.type}`;
    if (filters?.search) url += `&search=${encodeURIComponent(filters.search)}`;

    return this.http.get<PaginatedResponse<ClassSummary>>(url);
  }

  getClass(id: number): Observable<ClassSummary> {
    return this.http.get<ClassSummary>(`${this.base}/classes/${id}`);
  }

  createClass(data: {
    name: string;
    type: string;
    day_of_week: string;
    start_time: string;
    end_time: string;
    duration_minutes?: number;
    max_capacity: number;
    location?: string;
    status: string;
    description?: string;
    notes?: string;
    is_recurring?: boolean;
    allow_online_booking?: boolean;
    requires_active_plan?: boolean;
    trainer_id?: number | null;
  }): Observable<ClassSummary> {
    return this.http.post<ClassSummary>(`${this.base}/classes`, data).pipe(
      tap((cls) =>
        this.audit.record({
          action: 'create',
          module: 'Clases',
          entity: 'clase',
          entityId: cls.id,
          targetName: cls.name,
          after: data as Record<string, unknown>,
          metadata: { class: cls },
        }),
      ),
    );
  }

  updateClass(
    id: number,
    data: Partial<{
      name: string;
      type: string;
      day_of_week: string;
      start_time: string;
      end_time: string;
      duration_minutes: number;
      max_capacity: number;
      enrolled_count: number;
      location: string;
      status: string;
      description: string;
      notes: string;
      is_recurring: boolean;
      allow_online_booking: boolean;
      requires_active_plan: boolean;
      trainer_id: number | null;
    }>,
  ): Observable<ClassSummary> {
    return this.http.patch<ClassSummary>(`${this.base}/classes/${id}`, data).pipe(
      tap((cls) =>
        this.audit.record({
          action: data.status ? 'status' : 'update',
          module: 'Clases',
          entity: 'clase',
          entityId: id,
          targetName: cls.name,
          after: data as Record<string, unknown>,
          metadata: { class: cls },
        }),
      ),
    );
  }

  deleteClass(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/classes/${id}`).pipe(
      tap(() =>
        this.audit.record({
          action: 'delete',
          module: 'Clases',
          entity: 'clase',
          entityId: id,
          summary: `eliminó clase #${id}`,
        }),
      ),
    );
  }

  // ─── Routines ─────────────────────────────────────
  getRoutines(filters?: {
    status?: string;
    level?: string;
    search?: string;
  }): Observable<any[]> {
    let url = `${this.base}/routines`;
    const params: string[] = [];
    if (filters?.status) params.push(`status=${encodeURIComponent(filters.status)}`);
    if (filters?.level) params.push(`level=${encodeURIComponent(filters.level)}`);
    if (filters?.search) params.push(`search=${encodeURIComponent(filters.search)}`);
    if (params.length) url += '?' + params.join('&');
    return this.http.get<any[]>(url);
  }

  createRoutine(data: any): Observable<any> {
    return this.http.post<any>(`${this.base}/routines`, data).pipe(
      tap((routine) =>
        this.audit.record({
          action: 'create',
          module: 'Rutinas',
          entity: 'rutina',
          entityId: routine?.id,
          targetName: routine?.name || data?.name,
          after: data,
          metadata: { routine },
        }),
      ),
    );
  }

  updateRoutine(id: string | number, data: any): Observable<any> {
    return this.http.patch<any>(`${this.base}/routines/${id}`, data).pipe(
      tap((routine) =>
        this.audit.record({
          action: data?.status ? 'status' : 'update',
          module: 'Rutinas',
          entity: 'rutina',
          entityId: id,
          targetName: routine?.name || data?.name,
          after: data,
          metadata: { routine },
        }),
      ),
    );
  }

  deleteRoutine(id: string | number): Observable<void> {
    return this.http.delete<void>(`${this.base}/routines/${id}`).pipe(
      tap(() =>
        this.audit.record({
          action: 'delete',
          module: 'Rutinas',
          entity: 'rutina',
          entityId: id,
          summary: `eliminó rutina #${id}`,
        }),
      ),
    );
  }

  assignRoutine(
    id: string | number,
    data: { assignedMemberName?: string | null; assignedMemberId?: number | null },
  ): Observable<any> {
    return this.http.patch<any>(`${this.base}/routines/${id}/assign`, data).pipe(
      tap((routine) =>
        this.audit.record({
          action: 'assign',
          module: 'Rutinas',
          entity: 'rutina',
          entityId: id,
          targetName: data.assignedMemberName || `cliente #${data.assignedMemberId}`,
          after: data,
          metadata: { routine },
        }),
      ),
    );
  }

  // ─── Trainers ─────────────────────────────────────
  getTrainers(filters?: { status?: string; search?: string }): Observable<any[]> {
    let url = `${this.base}/trainers`;
    const params: string[] = [];
    if (filters?.status) params.push(`status=${encodeURIComponent(filters.status)}`);
    if (filters?.search) params.push(`search=${encodeURIComponent(filters.search)}`);
    if (params.length) url += '?' + params.join('&');
    return this.http.get<any[]>(url);
  }

  createTrainer(data: any): Observable<any> {
    return this.http.post<any>(`${this.base}/trainers`, data).pipe(
      tap((trainer) =>
        this.audit.record({
          action: 'create',
          module: 'Entrenadores',
          entity: 'entrenador',
          entityId: trainer?.id,
          targetName: trainer?.name || data?.name,
          after: data,
          metadata: { trainer },
        }),
      ),
    );
  }

  updateTrainer(id: string | number, data: any): Observable<any> {
    return this.http.patch<any>(`${this.base}/trainers/${id}`, data).pipe(
      tap((trainer) =>
        this.audit.record({
          action: data?.status ? 'status' : 'update',
          module: 'Entrenadores',
          entity: 'entrenador',
          entityId: id,
          targetName: trainer?.name || data?.name,
          after: data,
          metadata: { trainer },
        }),
      ),
    );
  }

  deleteTrainer(id: string | number): Observable<void> {
    return this.http.delete<void>(`${this.base}/trainers/${id}`).pipe(
      tap(() =>
        this.audit.record({
          action: 'delete',
          module: 'Entrenadores',
          entity: 'entrenador',
          entityId: id,
          summary: `eliminó entrenador #${id}`,
        }),
      ),
    );
  }
}
