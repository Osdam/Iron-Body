import { Injectable, inject, signal } from '@angular/core';
import { AuthService } from './auth.service';

export type AuditAction = 'create' | 'update' | 'delete' | 'status' | 'assign' | 'settings';

export interface AuditChange {
  field: string;
  before?: unknown;
  after?: unknown;
}

export interface AuditLogEntry {
  id: string;
  action: AuditAction;
  module: string;
  entity: string;
  entityId?: string | number | null;
  targetName?: string;
  actorId?: string;
  actorName: string;
  actorRole: string;
  createdAt: string;
  summary: string;
  changes: AuditChange[];
  metadata?: Record<string, unknown>;
}

export interface AuditLogInput {
  action: AuditAction;
  module: string;
  entity: string;
  entityId?: string | number | null;
  targetName?: string;
  summary?: string;
  before?: Record<string, unknown> | null;
  after?: Record<string, unknown> | null;
  changes?: AuditChange[];
  metadata?: Record<string, unknown>;
}

const AUDIT_LOG_KEY = 'ironbody_audit_logs';
const MAX_LOGS = 1200;

@Injectable({ providedIn: 'root' })
export class AuditLogService {
  private readonly authService = inject(AuthService);
  private readonly entriesSignal = signal<AuditLogEntry[]>(this.readEntries());

  readonly entries = this.entriesSignal.asReadonly();

  record(input: AuditLogInput): void {
    const user = this.authService.getCurrentUser();
    const changes = input.changes || this.diffObjects(input.before, input.after);
    const entry: AuditLogEntry = {
      id: this.createId(),
      action: input.action,
      module: input.module,
      entity: input.entity,
      entityId: input.entityId ?? null,
      targetName: input.targetName || this.resolveTargetName(input.after, input.before),
      actorId: user?.id,
      actorName: user?.name || 'Sistema',
      actorRole: user?.role || 'Sistema',
      createdAt: new Date().toISOString(),
      summary: input.summary || this.buildSummary(input, changes),
      changes,
      metadata: input.metadata,
    };

    const next = [entry, ...this.entriesSignal()].slice(0, MAX_LOGS);
    this.entriesSignal.set(next);
    localStorage.setItem(AUDIT_LOG_KEY, JSON.stringify(next));
  }

  clear(): void {
    this.entriesSignal.set([]);
    localStorage.removeItem(AUDIT_LOG_KEY);
  }

  exportJson(): string {
    return JSON.stringify(this.entriesSignal(), null, 2);
  }

  private diffObjects(
    before?: Record<string, unknown> | null,
    after?: Record<string, unknown> | null,
  ): AuditChange[] {
    if (!before && !after) return [];
    const fields = new Set([...Object.keys(before || {}), ...Object.keys(after || {})]);

    return Array.from(fields)
      .filter((field) => JSON.stringify(before?.[field]) !== JSON.stringify(after?.[field]))
      .map((field) => ({
        field,
        before: before?.[field],
        after: after?.[field],
      }));
  }

  private buildSummary(input: AuditLogInput, changes: AuditChange[]): string {
    const actionLabel: Record<AuditAction, string> = {
      create: 'creó',
      update: 'actualizó',
      delete: 'eliminó',
      status: 'cambió estado de',
      assign: 'asignó',
      settings: 'modificó configuración de',
    };
    const target = input.targetName || input.entityId || input.entity;
    const fields = changes.length ? ` (${changes.map((item) => item.field).join(', ')})` : '';
    return `${actionLabel[input.action]} ${input.entity} ${target}${fields}`;
  }

  private resolveTargetName(
    after?: Record<string, unknown> | null,
    before?: Record<string, unknown> | null,
  ): string | undefined {
    const source = after || before;
    const value = source?.['name'] || source?.['fullName'] || source?.['email'] || source?.['reference'];
    return typeof value === 'string' ? value : undefined;
  }

  private readEntries(): AuditLogEntry[] {
    try {
      const raw = localStorage.getItem(AUDIT_LOG_KEY);
      if (!raw) return [];
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch {
      return [];
    }
  }

  private createId(): string {
    return `log_${Date.now()}_${Math.random().toString(36).slice(2, 9)}`;
  }
}
