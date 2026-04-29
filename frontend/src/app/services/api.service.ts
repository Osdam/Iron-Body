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

export interface DashboardStats {
  users: number;
  active_plans: number;
  payments: number;
  revenue: number;
}

@Injectable({ providedIn: 'root' })
export class ApiService {
  private http = inject(HttpClient);
  private base = 'http://127.0.0.1:8000/api';

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
}
