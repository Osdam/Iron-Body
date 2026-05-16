import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { firstValueFrom } from 'rxjs';
import { Permission } from '../../models/permissions.enum';
import { ApiService, PaginatedResponse, PlanSummary } from '../../services/api.service';
import {
  AccessControlService,
  AccessModule,
  AccessPolicy,
  PlanAccessRule,
  RoleProfile,
} from '../../services/access-control.service';
import { AuditLogService } from '../../services/audit-log.service';

@Component({
  selector: 'app-settings-roles',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="settings-section">
      <div class="section-header">
        <div class="section-title">
          <span class="material-symbols-outlined">admin_panel_settings</span>
          <div>
            <h2>Usuarios y accesos</h2>
            <p>Roles reales para administrativos, entrenadores y miembros de la app móvil</p>
          </div>
        </div>

        <div class="header-actions">
          <span class="save-state" *ngIf="savedMessage()">{{ savedMessage() }}</span>
          <button type="button" class="btn-secondary" (click)="resetDefaults()">
            <span class="material-symbols-outlined">restart_alt</span>
            Restaurar
          </button>
          <button type="button" class="btn-primary" (click)="savePolicy()">
            <span class="material-symbols-outlined">save</span>
            Guardar accesos
          </button>
        </div>
      </div>

      <div class="access-summary">
        <div class="summary-card">
          <span class="material-symbols-outlined">badge</span>
          <strong>{{ policy().roles.length }}</strong>
          <small>perfiles activos</small>
        </div>
        <div class="summary-card">
          <span class="material-symbols-outlined">apps</span>
          <strong>{{ crmModules().length }}</strong>
          <small>módulos CRM</small>
        </div>
        <div class="summary-card">
          <span class="material-symbols-outlined">phone_iphone</span>
          <strong>{{ mobileModules().length }}</strong>
          <small>módulos app</small>
        </div>
      </div>

      <div class="accordion-list">
        <article
          class="role-card"
          *ngFor="let role of policy().roles; trackBy: trackRole"
          [class.open]="openRoleId() === role.id"
        >
          <button type="button" class="accordion-trigger" (click)="toggleRole(role.id)">
            <div>
              <span class="surface-pill" [class.mobile]="role.surface === 'mobile'" [class.both]="role.surface === 'both'">
                {{ surfaceLabel(role.surface) }}
              </span>
              <h3>{{ role.name }}</h3>
              <p>{{ role.description }}</p>
            </div>
            <div class="trigger-meta">
              <span class="role-id">{{ enabledRolePermissions(role) }} permisos</span>
              <span class="material-symbols-outlined expand-icon">expand_more</span>
            </div>
          </button>

          <div class="accordion-body" *ngIf="openRoleId() === role.id">
            <div class="role-note" *ngIf="role.locked">
              <span class="material-symbols-outlined">lock</span>
              Perfil protegido con acceso total.
            </div>

            <div class="module-list">
            <section class="module-permissions" *ngFor="let module of modulesForRole(role)">
              <div class="module-title">
                <span class="material-symbols-outlined">{{ module.icon }}</span>
                <div>
                  <strong>{{ module.name }}</strong>
                  <small>{{ moduleSurfaceLabel(module) }}</small>
                </div>
              </div>

              <div class="permission-grid">
                <label
                  class="permission-toggle"
                  *ngFor="let permission of module.permissions"
                  [class.mobile]="permission.surface === 'mobile'"
                  [title]="permission.description"
                >
                  <input
                    type="checkbox"
                    [checked]="hasPermission(role, permission.key)"
                    [disabled]="role.locked"
                    (change)="togglePermission(role.id, permission.key, $event)"
                  />
                  <span>
                    <b>{{ permission.label }}</b>
                    <small>{{ permission.surface === 'mobile' ? 'App móvil' : 'CRM' }}</small>
                  </span>
                </label>
              </div>
            </section>
            </div>
          </div>
        </article>
      </div>

      <section class="plan-section">
        <div class="section-title compact">
          <span class="material-symbols-outlined">workspace_premium</span>
          <div>
            <h2>Límites por plan en app móvil</h2>
            <p>Planes sincronizados desde el módulo de Planes</p>
          </div>
        </div>

        <div class="sync-state" *ngIf="plansLoading()">
          <span class="material-symbols-outlined">sync</span>
          Cargando planes reales...
        </div>

        <div class="sync-state error" *ngIf="plansError()">
          <span class="material-symbols-outlined">warning</span>
          {{ plansError() }}
        </div>

        <div class="accordion-list">
          <article
            class="plan-card"
            *ngFor="let plan of policy().plans; trackBy: trackPlan"
            [class.open]="openPlanId() === plan.id"
          >
            <button type="button" class="accordion-trigger" (click)="togglePlan(plan.id)">
              <div>
                <h3>{{ plan.name }}</h3>
                <p>{{ plan.description }}</p>
              </div>
              <div class="trigger-meta">
                <span>{{ plan.enabledMobileModules.length }} módulos</span>
                <span class="material-symbols-outlined expand-icon">expand_more</span>
              </div>
            </button>

            <div class="accordion-body" *ngIf="openPlanId() === plan.id">
              <div class="mobile-modules">
                <label *ngFor="let module of mobileModules()" class="module-chip">
                  <input
                    type="checkbox"
                    [checked]="plan.enabledMobileModules.includes(module.id)"
                    (change)="togglePlanModule(plan.id, module.id, $event)"
                  />
                  <span class="material-symbols-outlined">{{ module.icon }}</span>
                  {{ module.name }}
                </label>
              </div>

              <div class="limit-grid">
                <label>
                  <span>Reservas/semana</span>
                  <input
                    type="number"
                    min="0"
                    [ngModel]="plan.limits.classBookingsPerWeek"
                    (ngModelChange)="updatePlanLimit(plan.id, 'classBookingsPerWeek', $event)"
                  />
                </label>
                <label>
                  <span>Rutinas activas</span>
                  <input
                    type="number"
                    min="0"
                    [ngModel]="plan.limits.routineAssignments"
                    (ngModelChange)="updatePlanLimit(plan.id, 'routineAssignments', $event)"
                  />
                </label>
                <label>
                  <span>Mensajes/mes</span>
                  <input
                    type="number"
                    min="0"
                    [ngModel]="plan.limits.trainerMessagesPerMonth"
                    (ngModelChange)="updatePlanLimit(plan.id, 'trainerMessagesPerMonth', $event)"
                  />
                </label>
              </div>
            </div>
          </article>
        </div>
      </section>

      <div class="info-box">
        <span class="material-symbols-outlined">sync</span>
        <p>
          Esta configuración queda guardada como política compartida. El CRM la usa para permisos
          administrativos y la app móvil puede consumir los mismos módulos y límites por plan.
        </p>
      </div>
    </div>
  `,
  styles: [
    `
      .settings-section {
        background:
          linear-gradient(rgba(28, 27, 27, 0.92), rgba(17, 17, 17, 0.9)),
          url('/assets/crm/clases2.png') center / cover no-repeat;
        border: 1px solid #353534;
        border-radius: 0.75rem;
        color: #e5e2e1;
        padding: 1.5rem;
        box-shadow: 0 14px 34px rgba(0, 0, 0, 0.24);
      }

      .section-header,
      .accordion-trigger {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
      }

      .section-header {
        align-items: flex-start;
        margin-bottom: 1.25rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #353534;
      }

      .section-title {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
      }

      .section-title.compact {
        margin-bottom: 1rem;
      }

      .section-title .material-symbols-outlined {
        color: #f5c518;
        font-size: 1.6rem;
        margin-top: 0.2rem;
      }

      h2,
      h3,
      p {
        margin: 0;
      }

      h2 {
        color: #f7f3eb;
        font-size: 1.15rem;
      }

      h3 {
        color: #f7f3eb;
        font-size: 1rem;
      }

      p,
      small {
        color: #b8b3b1;
      }

      .header-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        flex-wrap: wrap;
        gap: 0.75rem;
      }

      button {
        border: 0;
        border-radius: 0.55rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        font-weight: 700;
        padding: 0.7rem 0.95rem;
      }

      button .material-symbols-outlined {
        font-size: 1.05rem;
      }

      .btn-primary {
        background: #f5c518;
        color: #241a00;
      }

      .btn-secondary {
        background: #1c1b1b;
        border: 1px solid #353534;
        color: #e5e2e1;
      }

      .save-state {
        color: #f5c518;
        font-size: 0.85rem;
        font-weight: 700;
      }

      .access-summary,
      .accordion-list {
        display: grid;
        gap: 1rem;
      }

      .access-summary {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        margin-bottom: 1rem;
      }

      .summary-card,
      .role-card,
      .plan-card,
      .info-box {
        background: rgba(28, 27, 27, 0.92);
        border: 1px solid #353534;
        border-radius: 0.75rem;
      }

      .summary-card {
        align-items: center;
        display: flex;
        gap: 0.85rem;
        padding: 1rem;
      }

      .summary-card .material-symbols-outlined {
        color: #f5c518;
      }

      .summary-card strong {
        color: #f7f3eb;
        display: block;
        font-size: 1.25rem;
      }

      .summary-card small {
        display: block;
      }

      .role-card,
      .plan-card {
        overflow: hidden;
      }

      .accordion-trigger {
        align-items: center;
        background: transparent;
        border-bottom: 1px solid #353534;
        border-radius: 0;
        color: inherit;
        padding: 1rem;
        text-align: left;
        width: 100%;
      }

      .role-id,
      .trigger-meta > span:first-child {
        background: rgba(245, 197, 24, 0.14);
        border: 1px solid rgba(245, 197, 24, 0.26);
        border-radius: 999px;
        color: #ffe08b;
        font-size: 0.72rem;
        font-weight: 800;
        padding: 0.28rem 0.55rem;
        text-transform: uppercase;
      }

      .trigger-meta {
        align-items: center;
        display: flex;
        gap: 0.65rem;
        flex-shrink: 0;
      }

      .expand-icon {
        color: #f5c518;
        transition: transform 0.2s ease;
      }

      .role-card.open .expand-icon,
      .plan-card.open .expand-icon {
        transform: rotate(180deg);
      }

      .accordion-body {
        animation: dropdownIn 0.18s ease;
        padding: 0 1rem 1rem;
      }

      .role-note {
        align-items: center;
        background: rgba(245, 197, 24, 0.1);
        border: 1px solid rgba(245, 197, 24, 0.24);
        border-radius: 0.55rem;
        color: #ffe08b;
        display: flex;
        gap: 0.55rem;
        font-size: 0.85rem;
        font-weight: 700;
        margin-top: 0.85rem;
        padding: 0.75rem;
      }

      .sync-state {
        align-items: center;
        background: rgba(245, 197, 24, 0.1);
        border: 1px solid rgba(245, 197, 24, 0.24);
        border-radius: 0.6rem;
        color: #ffe08b;
        display: flex;
        gap: 0.55rem;
        font-size: 0.85rem;
        font-weight: 800;
        margin-bottom: 0.85rem;
        padding: 0.75rem;
      }

      .sync-state.error {
        background: rgba(255, 180, 171, 0.12);
        border-color: rgba(255, 180, 171, 0.28);
        color: #ffb4ab;
      }

      .surface-pill {
        color: #241a00;
        background: #f5c518;
        border-radius: 999px;
        display: inline-flex;
        font-size: 0.7rem;
        font-weight: 800;
        margin-bottom: 0.45rem;
        padding: 0.22rem 0.5rem;
        text-transform: uppercase;
      }

      .surface-pill.mobile {
        background: #65e4a3;
      }

      .surface-pill.both {
        background: #8bd3ff;
      }

      .module-list {
        display: grid;
        gap: 0.85rem;
        margin-top: 0.9rem;
      }

      .module-permissions {
        background: #111;
        border: 1px solid #2b2a29;
        border-radius: 0.65rem;
        padding: 0.85rem;
      }

      .module-title {
        align-items: center;
        display: flex;
        gap: 0.65rem;
        margin-bottom: 0.75rem;
      }

      .module-title .material-symbols-outlined {
        color: #f5c518;
      }

      .module-title strong,
      .permission-toggle b,
      .limit-grid span {
        color: #f7f3eb;
      }

      .module-title small,
      .permission-toggle small {
        display: block;
      }

      .permission-grid,
      .mobile-modules {
        display: grid;
        gap: 0.55rem;
      }

      .permission-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .permission-toggle,
      .module-chip {
        align-items: center;
        background: #1c1b1b;
        border: 1px solid #353534;
        border-radius: 0.55rem;
        cursor: pointer;
        display: flex;
        gap: 0.6rem;
        padding: 0.65rem;
      }

      .permission-toggle.mobile,
      .module-chip:has(input:checked) {
        border-color: rgba(101, 228, 163, 0.35);
      }

      input[type='checkbox'] {
        accent-color: #f5c518;
        cursor: pointer;
        flex: 0 0 auto;
      }

      input:disabled {
        cursor: not-allowed;
        opacity: 0.6;
      }

      .plan-section {
        border-top: 1px solid #353534;
        margin-top: 1.25rem;
        padding-top: 1.25rem;
      }

      .mobile-modules {
        margin: 0.9rem 0;
      }

      .module-chip {
        color: #e5e2e1;
      }

      .module-chip .material-symbols-outlined {
        color: #65e4a3;
        font-size: 1.05rem;
      }

      .limit-grid {
        display: grid;
        gap: 0.65rem;
      }

      .limit-grid label {
        display: grid;
        gap: 0.35rem;
      }

      .limit-grid input {
        background: #111;
        border: 1px solid #353534;
        border-radius: 0.5rem;
        color: #f7f3eb;
        padding: 0.65rem;
        width: 100%;
      }

      .limit-grid input:focus {
        border-color: #f5c518;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.14);
        outline: none;
      }

      .info-box {
        align-items: flex-start;
        display: flex;
        gap: 0.85rem;
        margin-top: 1rem;
        padding: 1rem;
      }

      .info-box .material-symbols-outlined {
        color: #f5c518;
      }

      @keyframes dropdownIn {
        from {
          opacity: 0;
          transform: translateY(-6px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }

      @media (max-width: 760px) {
        .section-header,
        .accordion-trigger {
          flex-direction: column;
          align-items: flex-start;
        }

        .header-actions {
          justify-content: flex-start;
          width: 100%;
        }

        .access-summary,
        .permission-grid {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export default class SettingsRolesComponent implements OnInit {
  private readonly accessControl = inject(AccessControlService);
  private readonly auditLog = inject(AuditLogService);
  private readonly api = inject(ApiService);

  readonly modules = this.accessControl.modules;
  readonly policy = signal<AccessPolicy>(this.accessControl.getPolicy());
  readonly savedMessage = signal('');
  readonly openRoleId = signal('administrador');
  readonly openPlanId = signal('');
  readonly plansLoading = signal(false);
  readonly plansError = signal('');

  readonly mobileModules = computed(() =>
    this.modules.filter((module) => module.surfaces.includes('mobile')),
  );

  readonly crmModules = computed(() => this.modules.filter((module) => module.surfaces.includes('crm')));

  ngOnInit(): void {
    this.policy.set(this.accessControl.getPolicy());
    void this.loadPlansForMobileLimits();
  }

  trackRole(_: number, role: RoleProfile): string {
    return role.id;
  }

  trackPlan(_: number, plan: PlanAccessRule): string {
    return plan.id;
  }

  toggleRole(roleId: string): void {
    this.openRoleId.update((current) => (current === roleId ? '' : roleId));
  }

  togglePlan(planId: string): void {
    this.openPlanId.update((current) => (current === planId ? '' : planId));
  }

  modulesForRole(role: RoleProfile): AccessModule[] {
    if (role.surface === 'crm') return this.crmModules();
    if (role.surface === 'mobile') return this.mobileModules();
    return this.modules.filter((module) => module.surfaces.some((surface) => surface === 'crm' || surface === 'mobile'));
  }

  enabledRolePermissions(role: RoleProfile): number {
    return role.permissions.length;
  }

  hasPermission(role: RoleProfile, permission: Permission): boolean {
    return role.permissions.includes(permission);
  }

  togglePermission(roleId: string, permission: Permission, event: Event): void {
    const checked = (event.target as HTMLInputElement).checked;
    this.policy.update((policy) => ({
      ...policy,
      roles: policy.roles.map((role) => {
        if (role.id !== roleId || role.locked) return role;
        const permissions = checked
          ? Array.from(new Set([...role.permissions, permission]))
          : role.permissions.filter((item) => item !== permission);
        return { ...role, permissions };
      }),
    }));
    this.savedMessage.set('Cambios sin guardar');
  }

  togglePlanModule(planId: string, moduleId: string, event: Event): void {
    const checked = (event.target as HTMLInputElement).checked;
    this.policy.update((policy) => ({
      ...policy,
      plans: policy.plans.map((plan) => {
        if (plan.id !== planId) return plan;
        const enabledMobileModules = checked
          ? Array.from(new Set([...plan.enabledMobileModules, moduleId]))
          : plan.enabledMobileModules.filter((item) => item !== moduleId);
        return { ...plan, enabledMobileModules };
      }),
    }));
    this.savedMessage.set('Cambios sin guardar');
  }

  updatePlanLimit(planId: string, key: keyof PlanAccessRule['limits'], value: number | string): void {
    const normalized = Math.max(0, Number(value || 0));
    this.policy.update((policy) => ({
      ...policy,
      plans: policy.plans.map((plan) =>
        plan.id === planId ? { ...plan, limits: { ...plan.limits, [key]: normalized } } : plan,
      ),
    }));
    this.savedMessage.set('Cambios sin guardar');
  }

  savePolicy(): void {
    const before = this.accessControl.getPolicy() as unknown as Record<string, unknown>;
    const after = this.policy() as unknown as Record<string, unknown>;
    this.policy.set(this.accessControl.savePolicy(this.policy()));
    this.auditLog.record({
      action: 'settings',
      module: 'Configuración',
      entity: 'usuarios y accesos',
      targetName: 'Roles y límites por plan',
      before,
      after,
    });
    this.flashMessage('Accesos guardados');
  }

  resetDefaults(): void {
    const before = this.policy() as unknown as Record<string, unknown>;
    this.policy.set(this.accessControl.resetPolicy());
    void this.loadPlansForMobileLimits();
    this.auditLog.record({
      action: 'settings',
      module: 'Configuración',
      entity: 'usuarios y accesos',
      targetName: 'Restaurar accesos por defecto',
      before,
      after: this.policy() as unknown as Record<string, unknown>,
    });
    this.flashMessage('Accesos restaurados');
  }

  surfaceLabel(surface: RoleProfile['surface']): string {
    if (surface === 'both') return 'CRM + App';
    return surface === 'mobile' ? 'App móvil' : 'CRM';
  }

  moduleSurfaceLabel(module: AccessModule): string {
    return module.surfaces.includes('crm') && module.surfaces.includes('mobile')
      ? 'CRM y app móvil'
      : module.surfaces.includes('mobile')
        ? 'App móvil'
        : 'CRM';
  }

  private flashMessage(message: string): void {
    this.savedMessage.set(message);
    window.setTimeout(() => {
      if (this.savedMessage() === message) this.savedMessage.set('');
    }, 2200);
  }

  private async loadPlansForMobileLimits(): Promise<void> {
    this.plansLoading.set(true);
    this.plansError.set('');

    try {
      const plans = await this.fetchAllPages<PlanSummary>((page) => this.api.getPlans(page));
      const savedPlans = new Map(this.policy().plans.map((plan) => [String(plan.id), plan]));
      const dynamicRules = plans.map((plan) => {
        const id = String(plan.id);
        const saved = savedPlans.get(id);
        return {
          id,
          name: plan.name,
          description: this.planDescription(plan),
          enabledMobileModules: saved?.enabledMobileModules || this.defaultMobileModules(plan),
          limits: saved?.limits || this.defaultPlanLimits(plan),
        };
      });

      this.policy.update((policy) => ({ ...policy, plans: dynamicRules }));
      if (!this.openPlanId() && dynamicRules[0]) this.openPlanId.set(dynamicRules[0].id);
    } catch {
      this.plansError.set('No se pudieron cargar los planes reales. Revisa la conexión con la API.');
    } finally {
      this.plansLoading.set(false);
    }
  }

  private async fetchAllPages<T>(
    loader: (page: number) => ReturnType<ApiService['getPlans']>,
  ): Promise<T[]> {
    const first = (await firstValueFrom(loader(1))) as PaginatedResponse<T>;
    const rows = [...(first.data || [])];

    for (let page = 2; page <= (first.last_page || 1); page++) {
      const next = (await firstValueFrom(loader(page))) as PaginatedResponse<T>;
      rows.push(...(next.data || []));
    }

    return rows;
  }

  private planDescription(plan: PlanSummary): string {
    const state = plan.active ? 'Activo' : 'Inactivo';
    return `${state} · ${plan.duration_days} días · $${Number(plan.price || 0).toLocaleString('es-CO')}`;
  }

  private defaultMobileModules(plan: PlanSummary): string[] {
    const benefits = String(plan.benefits || '').toLowerCase();
    const modules = ['plans'];
    if (benefits.includes('clase') || plan.active) modules.push('classes');
    if (benefits.includes('rutina') || benefits.includes('entren')) modules.push('routines');
    if (benefits.includes('entrenador')) modules.push('trainers');
    return Array.from(new Set(modules));
  }

  private defaultPlanLimits(plan: PlanSummary): PlanAccessRule['limits'] {
    const duration = Number(plan.duration_days || 30);
    const isLongPlan = duration >= 90;
    const isVip = String(plan.name || '').toLowerCase().includes('vip');

    return {
      classBookingsPerWeek: isVip ? 99 : isLongPlan ? 6 : 3,
      routineAssignments: isVip ? 99 : isLongPlan ? 4 : 2,
      trainerMessagesPerMonth: isVip ? 99 : isLongPlan ? 6 : 2,
    };
  }
}
