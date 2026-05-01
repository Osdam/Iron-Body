import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';

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
  plan?: string;
  membershipEndDate?: string;
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
  private base = 'http://127.0.0.1:8080/api';

  getDashboardStats(): Observable<DashboardStats> {
    return this.http.get<DashboardStats>(`${this.base}/dashboard`);
  }

  getUsers(page = 1): Observable<PaginatedResponse<UserSummary>> {
    return this.http.get<PaginatedResponse<UserSummary>>(`${this.base}/users?page=${page}`);
  }

  getPlans(page = 1): Observable<PaginatedResponse<PlanSummary>> {
    return this.http.get<PaginatedResponse<PlanSummary>>(`${this.base}/plans?page=${page}`);
  }

  getPayments(page = 1): Observable<PaginatedResponse<PaymentSummary>> {
    return this.http.get<PaginatedResponse<PaymentSummary>>(`${this.base}/payments?page=${page}`);
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
    return this.http.post<PlanSummary>(`${this.base}/plans`, data);
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
    return this.http.post<UserSummary>(`${this.base}/users`, data);
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
    return this.http.post<ClassSummary>(`${this.base}/classes`, data);
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
    return this.http.patch<ClassSummary>(`${this.base}/classes/${id}`, data);
  }

  deleteClass(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/classes/${id}`);
  }
}
