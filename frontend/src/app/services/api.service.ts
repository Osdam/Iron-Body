import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map, tap } from 'rxjs/operators';
import { AuditLogService } from './audit-log.service';
import { environment } from '../../environments/environment';

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

export interface IncompleteMemberRegistration {
  id: number;
  member_id: number;
  member_uuid: string;
  name: string;
  email?: string | null;
  document?: string | null;
  phone?: string | null;
  status: string;
  registration_status: string;
  created_at: string;
  updated_at?: string;
}

export interface PlanFeatures {
  iron_ia: boolean;
  workouts: boolean;
  custom_routines: boolean;
  ranking: boolean;
  classes: boolean;
  progress: boolean;
  nutrition: boolean;
  [key: string]: boolean;
}

export interface PlanSummary {
  id: number;
  name: string;
  price: number;
  duration_days: number;
  benefits?: string | null;
  active: boolean;
  features?: PlanFeatures | null;
}

/** Capacidades detalladas de IRON IA por plan (membership_ai_capabilities). */
export interface PlanAiCapabilities {
  ai_enabled: boolean;
  ai_chat_enabled: boolean;
  ai_image_analysis_enabled: boolean;
  ai_voice_chat_enabled: boolean;
  ai_realtime_voice_enabled: boolean;
  ai_progress_analysis_enabled: boolean;
  ai_smart_recommendations_enabled: boolean;
  ai_weekly_summary_enabled: boolean;
  ai_proactive_notifications_enabled: boolean;
  ai_monthly_messages_limit: number | null;
  ai_daily_messages_limit: number | null;
  ai_monthly_image_limit: number;
  ai_monthly_audio_limit: number;
  ai_max_audio_seconds: number;
  ai_max_image_size_mb?: number;
  ai_context_level: 'basic' | 'personalized' | 'full';
}

export interface PlanAiCapabilitiesResponse {
  planId: string;
  planName: string;
  capabilities: PlanAiCapabilities;
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

export interface AttendanceSummary {
  id: number;
  user_id: number;
  member_id: number | null;
  member_name: string;
  plan?: string | null;
  action: 'entry' | 'exit';
  source: 'facial' | 'manual';
  confidence?: number | null;
  note?: string | null;
  captured_at: string;
  date: string;
  time: string;
}

export interface FaceReferencePayload {
  user_id: number;
  member_id: number;
  member_uuid: string;
  name: string;
  plan?: string | null;
  face_url: string;
  captured_at?: string | null;
}

export interface TurnstileSettings {
  id: number;
  name: string;
  enabled: boolean;
  mode: 'webhook' | 'zkteco' | 'serial';
  device_host: string | null;
  device_port: number | null;
  device_comm_key: string | null;
  serial_port: string | null;
  serial_baud: number | null;
  serial_command: string | null;
  webhook_url: string | null;
  http_method: 'GET' | 'POST' | 'PUT' | 'PATCH';
  auth_header: string | null;
  request_payload: string | null;
  open_duration_ms: number;
  fire_on_entry: boolean;
  fire_on_exit: boolean;
  sound_enabled: boolean;
  last_triggered_at: string | null;
  last_status: 'success' | 'error' | null;
  last_error: string | null;
  last_http_code: number | null;
}

export interface TurnstileResult {
  fired?: boolean;
  ok?: boolean;
  reason?: string;
  status?: number;
  body?: string;
  error?: string;
  host?: string;
  port?: number | string;
  duration_seconds?: number;
  session_id?: number;
  baud?: number;
  command?: string;
  output?: string;
}

export interface CreateAttendanceResponse {
  ok: boolean;
  deduplicated?: boolean;
  attendance: AttendanceSummary;
  turnstile?: TurnstileResult;
}

/** Contrato firmado de un miembro (consentimiento + firma electrónica). */
export interface MemberContractSummary {
  id: number;
  uuid: string;
  folio?: string | null;
  contract_type: string;
  status: 'draft' | 'pending_signature' | 'signed' | 'void';
  template_version?: string | null;
  signed_at?: string | null;
  checksum?: string | null;
  has_pdf: boolean;
  /** Autorización de uso de imagen (checkbox opcional). null si sin firmar. */
  image_authorized?: boolean | null;
  created_at?: string | null;
}

/** Resumen legal del miembro (estado biométrico / minoría de edad). */
export interface MemberLegalSummary {
  id: number;
  is_minor: boolean;
  biometric_status: 'pending' | 'registered' | 'skipped' | 'manual_required' | string;
}

@Injectable({ providedIn: 'root' })
export class ApiService {
  private http = inject(HttpClient);
  private audit = inject(AuditLogService);
  private base = environment.apiBaseUrl;
  private adminBase = environment.adminApiBaseUrl;

  getDashboardStats(): Observable<DashboardStats> {
    return this.http.get<DashboardStats>(`${this.base}/dashboard`);
  }

  getUsers(page = 1): Observable<PaginatedResponse<UserSummary>> {
    return this.http.get<PaginatedResponse<UserSummary>>(`${this.base}/users?page=${page}`);
  }

  getIncompleteMemberRegistrations(page = 1): Observable<PaginatedResponse<IncompleteMemberRegistration>> {
    return this.http.get<PaginatedResponse<IncompleteMemberRegistration>>(`${this.base}/members/incomplete?page=${page}`);
  }

  deleteIncompleteMemberRegistration(memberId: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/members/${memberId}`);
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

  updatePlanFeatures(id: number, features: Partial<PlanFeatures>): Observable<{ planId: string; planName: string; features: PlanFeatures }> {
    return this.http.put<{ planId: string; planName: string; features: PlanFeatures }>(
      `${this.base}/plans/${id}/features`,
      { features },
    ).pipe(
      tap(() =>
        this.audit.record({
          action: 'update',
          module: 'Planes',
          entity: 'plan',
          entityId: id,
          summary: `actualizó módulos de la app para plan #${id}`,
          after: { features } as Record<string, unknown>,
        }),
      ),
    );
  }

  /** GET capacidades de IRON IA del plan (fila o defaults del tier). */
  getPlanAiCapabilities(id: number): Observable<PlanAiCapabilitiesResponse> {
    return this.http.get<PlanAiCapabilitiesResponse>(`${this.base}/plans/${id}/ai-capabilities`);
  }

  /** PUT capacidades de IRON IA del plan → membership_ai_capabilities. */
  updatePlanAiCapabilities(
    id: number,
    capabilities: Partial<PlanAiCapabilities>,
  ): Observable<PlanAiCapabilitiesResponse> {
    return this.http
      .put<PlanAiCapabilitiesResponse>(`${this.base}/plans/${id}/ai-capabilities`, capabilities)
      .pipe(
        tap(() =>
          this.audit.record({
            action: 'update',
            module: 'Planes',
            entity: 'plan',
            entityId: id,
            summary: `actualizó capacidades de IRON IA para plan #${id}`,
            after: { capabilities } as Record<string, unknown>,
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
    let url = `${this.base}/trainers?admin=1`;
    const params: string[] = [];
    if (filters?.status) params.push(`status=${encodeURIComponent(filters.status)}`);
    if (filters?.search) params.push(`search=${encodeURIComponent(filters.search)}`);
    if (params.length) url += '&' + params.join('&');
    return this.http.get<any[] | { ok: boolean; data: any[] }>(url).pipe(
      map((response) => Array.isArray(response) ? response : response.data || []),
    );
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

  // ─── Attendances ─────────────────────────────────
  getAttendances(
    page = 1,
    filters?: { user_id?: number; from?: string; to?: string; per_page?: number },
  ): Observable<PaginatedResponse<AttendanceSummary>> {
    let url = `${this.base}/attendances?page=${page}`;
    if (filters?.user_id) url += `&user_id=${filters.user_id}`;
    if (filters?.from) url += `&from=${encodeURIComponent(filters.from)}`;
    if (filters?.to) url += `&to=${encodeURIComponent(filters.to)}`;
    if (filters?.per_page) url += `&per_page=${filters.per_page}`;
    return this.http.get<PaginatedResponse<AttendanceSummary>>(url);
  }

  createAttendance(data: {
    user_id: number;
    action?: 'entry' | 'exit';
    source?: 'facial' | 'manual';
    confidence?: number;
    note?: string;
  }): Observable<CreateAttendanceResponse> {
    return this.http
      .post<CreateAttendanceResponse>(`${this.base}/attendances`, data)
      .pipe(
        tap((response) => {
          if (response.deduplicated) return;
          this.audit.record({
            action: 'create',
            module: 'Asistencias',
            entity: 'asistencia',
            entityId: response.attendance.id,
            targetName: response.attendance.member_name,
            summary: `${response.attendance.action === 'entry' ? 'Entrada' : 'Salida'} ${response.attendance.source === 'facial' ? 'facial' : 'manual'}`,
            after: data as Record<string, unknown>,
          });
        }),
      );
  }

  getFaceReferences(): Observable<{ ok: boolean; count: number; data: FaceReferencePayload[] }> {
    return this.http.get<{ ok: boolean; count: number; data: FaceReferencePayload[] }>(
      `${this.base}/attendances/face-references`,
    );
  }

  // ─── Turnstile (torniquete) ──────────────────────
  getTurnstile(): Observable<{ ok: boolean; data: TurnstileSettings }> {
    return this.http.get<{ ok: boolean; data: TurnstileSettings }>(`${this.base}/turnstile`);
  }

  updateTurnstile(
    data: Partial<Omit<TurnstileSettings, 'id' | 'last_triggered_at' | 'last_status' | 'last_error' | 'last_http_code'>>,
  ): Observable<{ ok: boolean; data: TurnstileSettings }> {
    return this.http
      .put<{ ok: boolean; data: TurnstileSettings }>(`${this.base}/turnstile`, data)
      .pipe(
        tap(() =>
          this.audit.record({
            action: 'update',
            module: 'Asistencias',
            entity: 'torniquete',
            summary: 'actualizó configuración del torniquete',
            after: data as Record<string, unknown>,
          }),
        ),
      );
  }

  triggerTurnstile(
    data: { action?: 'entry' | 'exit'; reason?: string } = {},
  ): Observable<{ ok: boolean; result: TurnstileResult; data: TurnstileSettings }> {
    return this.http
      .post<{ ok: boolean; result: TurnstileResult; data: TurnstileSettings }>(
        `${this.base}/turnstile/trigger`,
        data,
      )
      .pipe(
        tap((response) =>
          this.audit.record({
            action: 'status',
            module: 'Asistencias',
            entity: 'torniquete',
            summary: response.ok ? 'abrió torniquete manualmente' : 'intento fallido de apertura',
            after: data as Record<string, unknown>,
            metadata: { result: response.result },
          }),
        ),
      );
  }

  // Disparo ad-hoc de un webhook (Sonoff / ESP32 / Shelly). El backend hace
  // el HTTP por nosotros para evitar CORS desde el navegador.
  triggerTurnstileWebhook(
    data: { url: string; method?: 'GET' | 'POST' | 'PUT' | 'PATCH'; payload?: string | null },
  ): Observable<{ ok: boolean; result: TurnstileResult; data: TurnstileSettings }> {
    return this.http
      .post<{ ok: boolean; result: TurnstileResult; data: TurnstileSettings }>(
        `${this.base}/turnstile/webhook/fire`,
        data,
      )
      .pipe(
        tap((response) =>
          this.audit.record({
            action: 'status',
            module: 'Asistencias',
            entity: 'torniquete',
            summary: response.ok ? 'abrió torniquete (relé HTTP)' : 'falló apertura del relé',
            after: data as Record<string, unknown>,
            metadata: { result: response.result },
          }),
        ),
      );
  }

  // Apertura por COM (replica NetGymValidator: PULSE 3000\r\n al puerto serie).
  openSerialTurnstile(
    data: { port?: string; baud?: number; command?: string } = {},
  ): Observable<{ ok: boolean; result: TurnstileResult; data: TurnstileSettings }> {
    return this.http
      .post<{ ok: boolean; result: TurnstileResult; data: TurnstileSettings }>(
        `${this.base}/turnstile/serial/open`,
        data,
      )
      .pipe(
        tap((response) =>
          this.audit.record({
            action: 'status',
            module: 'Asistencias',
            entity: 'torniquete',
            summary: response.ok ? 'abrió torniquete por COM' : 'falló apertura por COM',
            after: data as Record<string, unknown>,
            metadata: { result: response.result },
          }),
        ),
      );
  }

  // Apertura directa ZKTeco — no requiere enabled=true ni configurar webhook.
  openZktecoTurnstile(
    data: { host?: string; port?: number; comm_key?: string | null; duration_seconds?: number } = {},
  ): Observable<{ ok: boolean; result: TurnstileResult; data: TurnstileSettings }> {
    return this.http
      .post<{ ok: boolean; result: TurnstileResult; data: TurnstileSettings }>(
        `${this.base}/turnstile/zkteco/open`,
        data,
      )
      .pipe(
        tap((response) =>
          this.audit.record({
            action: 'status',
            module: 'Asistencias',
            entity: 'torniquete',
            summary: response.ok ? 'abrió torniquete ZKTeco' : 'intento fallido apertura ZKTeco',
            after: data as Record<string, unknown>,
            metadata: { result: response.result },
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

  // ─── Contratos firmados (consentimiento + firma electrónica) ──────────────
  /** Contratos del miembro vinculado a un usuario del CRM (por user_id). */
  getUserContracts(
    userId: number,
  ): Observable<{ data: MemberContractSummary[]; member: MemberLegalSummary | null }> {
    return this.http.get<{ data: MemberContractSummary[]; member: MemberLegalSummary | null }>(
      `${this.adminBase}/users/${userId}/contracts`,
    );
  }

  /** Descarga el PDF firmado (blob privado). El backend audita cada descarga. */
  downloadContract(uuid: string): Observable<Blob> {
    return this.http.get(`${this.adminBase}/contracts/${uuid}/download`, {
      responseType: 'blob',
    });
  }

  /** Anula un contrato firmado (solo admin, con motivo). El PDF no se borra. */
  voidContract(uuid: string, reason: string): Observable<{ data: MemberContractSummary }> {
    return this.http
      .post<{ data: MemberContractSummary }>(`${this.adminBase}/contracts/${uuid}/void`, { reason })
      .pipe(
        tap(() =>
          this.audit.record({
            action: 'delete',
            module: 'Miembros',
            entity: 'contrato',
            summary: `anuló el contrato ${uuid}`,
            after: { reason } as Record<string, unknown>,
          }),
        ),
      );
  }
}
