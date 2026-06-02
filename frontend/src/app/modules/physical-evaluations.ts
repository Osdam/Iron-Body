import { CommonModule } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  MemberOption,
  PhysicalEvaluation,
  PhysicalEvaluationService,
} from '../shared/services/physical-evaluation.service';

/**
 * CRM — Evaluaciones físicas.
 *
 * Permite a entrenadores/admins buscar un miembro, ver su historial de
 * evaluaciones, crear nuevas (datos corporales) y editar las observaciones del
 * entrenador. 100% conectado al backend Laravel. La app del miembro ve estos
 * datos en Progreso/Evaluación.
 */
@Component({
  selector: 'app-physical-evaluations-page',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <section class="pe-page">
      <header class="pe-head">
        <div>
          <h1>Evaluaciones físicas</h1>
          <p class="pe-sub">Registra y consulta la evolución corporal de cada miembro.</p>
        </div>
      </header>

      <!-- Selector de miembro -->
      <div class="pe-search">
        <span class="material-symbols-outlined">search</span>
        <input
          type="search"
          placeholder="Buscar miembro por nombre o documento…"
          [(ngModel)]="query"
          (ngModelChange)="onSearch($event)"
        />
      </div>

      @if (service.members().length > 0 && !selected()) {
        <div class="pe-member-list">
          @for (m of service.members(); track m.id) {
            <button class="pe-member" (click)="selectMember(m)" type="button">
              <span class="pe-avatar">{{ initials(m.full_name) }}</span>
              <span class="pe-member-info">
                <strong>{{ m.full_name }}</strong>
                <small>{{ m.document || 'Sin documento' }}</small>
              </span>
            </button>
          }
        </div>
      }

      @if (selected(); as member) {
        <div class="pe-selected">
          <div class="pe-selected-head">
            <div>
              <span class="pe-avatar lg">{{ initials(member.full_name) }}</span>
            </div>
            <div class="pe-selected-info">
              <strong>{{ member.full_name }}</strong>
              <small>{{ member.document || 'Sin documento' }}</small>
            </div>
            <button class="pe-btn ghost" (click)="clearMember()" type="button">
              <span class="material-symbols-outlined">close</span> Cambiar
            </button>
            <button class="pe-btn primary" (click)="startNew()" type="button">
              <span class="material-symbols-outlined">add</span> Nueva evaluación
            </button>
          </div>

          <!-- Formulario nueva evaluación -->
          @if (creating()) {
            <div class="pe-form">
              <h3>Nueva evaluación</h3>
              <div class="pe-grid">
                <label class="pe-field"><span>Peso (kg)</span><input [(ngModel)]="form.weight_kg" type="number" /></label>
                <label class="pe-field"><span>Estatura (cm)</span><input [(ngModel)]="form.height_cm" type="number" /></label>
                <label class="pe-field"><span>% Grasa</span><input [(ngModel)]="form.body_fat_pct" type="number" /></label>
                <label class="pe-field"><span>% Masa muscular</span><input [(ngModel)]="form.muscle_mass_pct" type="number" /></label>
                <label class="pe-field"><span>Cintura (cm)</span><input [(ngModel)]="form.waist_cm" type="number" /></label>
                <label class="pe-field"><span>Cadera (cm)</span><input [(ngModel)]="form.hip_cm" type="number" /></label>
                <label class="pe-field"><span>Pecho (cm)</span><input [(ngModel)]="form.chest_cm" type="number" /></label>
                <label class="pe-field"><span>Brazo (cm)</span><input [(ngModel)]="form.arm_cm" type="number" /></label>
                <label class="pe-field"><span>Pierna (cm)</span><input [(ngModel)]="form.leg_cm" type="number" /></label>
                <label class="pe-field pe-col2"><span>Lesiones / restricciones</span><textarea [(ngModel)]="form.injuries" rows="2"></textarea></label>
                <label class="pe-field pe-col2"><span>Observaciones del entrenador</span><textarea [(ngModel)]="form.trainer_notes" rows="2"></textarea></label>
              </div>
              <div class="pe-form-actions">
                <button class="pe-btn ghost" (click)="cancelNew()" type="button">Cancelar</button>
                <button class="pe-btn primary" (click)="saveNew(member)" type="button" [disabled]="saving()">
                  <span class="material-symbols-outlined">save</span> Guardar evaluación
                </button>
              </div>
            </div>
          }

          <!-- Historial -->
          @if (service.loading()) {
            <div class="pe-skeleton"></div>
            <div class="pe-skeleton"></div>
          } @else if (service.error()) {
            <div class="pe-state"><span class="material-symbols-outlined">error</span><p>{{ service.error() }}</p></div>
          } @else if (service.evaluations().length === 0) {
            <div class="pe-state">
              <span class="material-symbols-outlined">monitor_weight</span>
              <p>Este miembro aún no tiene evaluaciones. Crea la primera.</p>
            </div>
          } @else {
            <h3 class="pe-hist-title">Historial ({{ service.evaluations().length }})</h3>
            @for (ev of service.evaluations(); track ev.id) {
              <article class="pe-eval">
                <div class="pe-eval-head">
                  <div class="pe-eval-date">
                    <span class="material-symbols-outlined">event</span>
                    {{ formatDate(ev.created_at) }}
                  </div>
                  <div class="pe-eval-metrics">
                    @if (ev.weight_kg != null) { <span class="pe-chip">{{ ev.weight_kg }} kg</span> }
                    @if (ev.bmi != null) { <span class="pe-chip">IMC {{ ev.bmi }}</span> }
                    @if (ev.bmi_label) { <span class="pe-chip accent">{{ ev.bmi_label }}</span> }
                  </div>
                  <button class="pe-btn ghost danger sm" (click)="remove(ev, member)" type="button">
                    <span class="material-symbols-outlined">delete</span>
                  </button>
                </div>
                <div class="pe-eval-body">
                  <div class="pe-measures">
                    <span>Grasa: {{ ev.body_fat_pct ?? '—' }}%</span>
                    <span>Músculo: {{ ev.muscle_mass_pct ?? '—' }}%</span>
                    <span>Cintura: {{ ev.waist_cm ?? '—' }}</span>
                    <span>Cadera: {{ ev.hip_cm ?? '—' }}</span>
                    <span>Pecho: {{ ev.chest_cm ?? '—' }}</span>
                    <span>Brazo: {{ ev.arm_cm ?? '—' }}</span>
                    <span>Pierna: {{ ev.leg_cm ?? '—' }}</span>
                  </div>
                  <label class="pe-field">
                    <span>Observaciones del entrenador</span>
                    <textarea [(ngModel)]="ev.trainer_notes" rows="2"></textarea>
                  </label>
                  <div class="pe-eval-actions">
                    <button class="pe-btn primary sm" (click)="saveNotes(ev)" type="button" [disabled]="saving()">
                      <span class="material-symbols-outlined">save</span> Guardar notas
                    </button>
                  </div>
                </div>
              </article>
            }
          }
        </div>
      }

      @if (toast()) { <div class="pe-toast">{{ toast() }}</div> }
    </section>
  `,
  styles: [
    `
      :host { display: block; --y: #facc15; --bg: #0f1115; --bg2: #15181f; --bd: rgba(255,255,255,0.08); --t1: #f4f5f7; --t2: #9aa0aa; --t3: #6b7280; }
      .pe-page { padding: 24px 28px; color: var(--t1); max-width: 1100px; margin: 0 auto; }
      .pe-head h1 { font-size: 24px; font-weight: 800; margin: 0; letter-spacing: -0.02em; }
      .pe-sub { margin: 4px 0 20px; color: var(--t2); font-size: 13px; }

      .pe-btn { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; border-radius: 10px; padding: 9px 14px; cursor: pointer; border: 1px solid var(--bd); background: rgba(255,255,255,0.04); color: var(--t1); transition: all .15s ease; }
      .pe-btn .material-symbols-outlined { font-size: 18px; }
      .pe-btn.primary { background: var(--y); color: #1a1a1a; border-color: var(--y); }
      .pe-btn.ghost:hover { background: rgba(255,255,255,0.08); }
      .pe-btn.danger { color: #f87171; }
      .pe-btn.sm { padding: 6px 10px; font-size: 12px; }
      .pe-btn:disabled { opacity: .45; cursor: not-allowed; }

      .pe-search { display: flex; align-items: center; gap: 8px; background: var(--bg2); border: 1px solid var(--bd); border-radius: 12px; padding: 11px 14px; margin-bottom: 14px; }
      .pe-search:focus-within { border-color: rgba(250,204,21,.5); }
      .pe-search .material-symbols-outlined { color: var(--t3); }
      .pe-search input { flex: 1; background: transparent; border: none; outline: none; color: var(--t1); font-size: 14px; }

      .pe-member-list { display: flex; flex-direction: column; gap: 8px; }
      .pe-member { display: flex; align-items: center; gap: 12px; background: var(--bg); border: 1px solid var(--bd); border-radius: 12px; padding: 12px 14px; cursor: pointer; text-align: left; transition: all .15s ease; }
      .pe-member:hover { border-color: rgba(250,204,21,.4); background: var(--bg2); }
      .pe-avatar { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 50%; background: rgba(250,204,21,.16); color: var(--y); font-weight: 800; font-size: 14px; }
      .pe-avatar.lg { width: 48px; height: 48px; font-size: 16px; }
      .pe-member-info { display: flex; flex-direction: column; }
      .pe-member-info strong { font-size: 14px; }
      .pe-member-info small { color: var(--t3); font-size: 12px; }

      .pe-selected { margin-top: 8px; }
      .pe-selected-head { display: flex; align-items: center; gap: 14px; background: var(--bg); border: 1px solid var(--bd); border-radius: 14px; padding: 14px 16px; margin-bottom: 18px; }
      .pe-selected-info { flex: 1; display: flex; flex-direction: column; }
      .pe-selected-info strong { font-size: 16px; }
      .pe-selected-info small { color: var(--t3); font-size: 12px; }

      .pe-form { background: var(--bg); border: 1px solid var(--bd); border-radius: 14px; padding: 18px; margin-bottom: 20px; }
      .pe-form h3 { margin: 0 0 14px; font-size: 15px; }
      .pe-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
      .pe-field { display: flex; flex-direction: column; gap: 5px; }
      .pe-field.pe-col2 { grid-column: span 3; }
      .pe-field > span { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--t3); font-weight: 700; }
      .pe-field input, .pe-field textarea { background: var(--bg2); border: 1px solid var(--bd); border-radius: 10px; padding: 9px 11px; color: var(--t1); font-size: 13px; outline: none; font-family: inherit; }
      .pe-field input:focus, .pe-field textarea:focus { border-color: rgba(250,204,21,.5); }
      .pe-form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px; }

      .pe-hist-title { font-size: 15px; margin: 4px 0 14px; }
      .pe-eval { background: var(--bg); border: 1px solid var(--bd); border-radius: 14px; padding: 14px 16px; margin-bottom: 12px; }
      .pe-eval-head { display: flex; align-items: center; gap: 12px; }
      .pe-eval-date { display: flex; align-items: center; gap: 6px; font-weight: 700; font-size: 13px; }
      .pe-eval-date .material-symbols-outlined { font-size: 18px; color: var(--y); }
      .pe-eval-metrics { flex: 1; display: flex; gap: 6px; flex-wrap: wrap; }
      .pe-chip { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 999px; background: rgba(255,255,255,0.06); color: var(--t2); }
      .pe-chip.accent { background: rgba(250,204,21,.16); color: var(--y); }
      .pe-eval-body { margin-top: 12px; display: flex; flex-direction: column; gap: 10px; }
      .pe-measures { display: flex; flex-wrap: wrap; gap: 10px 18px; font-size: 12px; color: var(--t2); }
      .pe-eval-actions { display: flex; justify-content: flex-end; }

      .pe-skeleton { height: 90px; margin: 8px 0; border-radius: 14px; background: linear-gradient(90deg, rgba(255,255,255,.04) 25%, rgba(255,255,255,.08) 37%, rgba(255,255,255,.04) 63%); background-size: 400% 100%; animation: pesh 1.4s ease infinite; }
      @keyframes pesh { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }
      .pe-state { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 50px 24px; color: var(--t3); text-align: center; background: var(--bg); border: 1px solid var(--bd); border-radius: 14px; }
      .pe-state .material-symbols-outlined { font-size: 42px; opacity: .8; color: var(--y); }
      .pe-state p { margin: 0; font-size: 14px; }

      .pe-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: var(--y); color: #1a1a1a; font-weight: 700; font-size: 13px; padding: 11px 20px; border-radius: 999px; box-shadow: 0 8px 28px rgba(0,0,0,.4); z-index: 50; }

      @media (max-width: 760px) { .pe-grid { grid-template-columns: 1fr 1fr; } .pe-field.pe-col2 { grid-column: span 2; } }
    `,
  ],
})
export default class PhysicalEvaluationsPage {
  readonly service = inject(PhysicalEvaluationService);

  query = '';
  readonly selected = signal<MemberOption | null>(null);
  readonly creating = signal(false);
  readonly saving = signal(false);
  readonly toast = signal<string | null>(null);

  form: Partial<PhysicalEvaluation> = {};
  private searchTimer: any = null;

  onSearch(q: string): void {
    if (this.searchTimer) clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.service.searchMembers(q ?? ''), 300);
  }

  selectMember(m: MemberOption): void {
    this.selected.set(m);
    this.creating.set(false);
    this.service.loadEvaluations(m.id);
  }

  clearMember(): void {
    this.selected.set(null);
    this.creating.set(false);
    this.query = '';
  }

  startNew(): void {
    this.form = {};
    this.creating.set(true);
  }

  cancelNew(): void {
    this.creating.set(false);
  }

  saveNew(member: MemberOption): void {
    this.saving.set(true);
    this.service.create(member.id, this.normalize(this.form)).subscribe({
      next: () => {
        this.saving.set(false);
        this.creating.set(false);
        this.flash('Evaluación creada');
        this.service.loadEvaluations(member.id);
      },
      error: () => { this.saving.set(false); this.flash('No se pudo guardar'); },
    });
  }

  saveNotes(ev: PhysicalEvaluation): void {
    this.saving.set(true);
    this.service.update(ev.id, { trainer_notes: ev.trainer_notes }).subscribe({
      next: () => { this.saving.set(false); this.flash('Notas guardadas'); },
      error: () => { this.saving.set(false); this.flash('No se pudo guardar'); },
    });
  }

  remove(ev: PhysicalEvaluation, member: MemberOption): void {
    if (!confirm('¿Eliminar esta evaluación?')) return;
    this.service.remove(ev.id).subscribe({
      next: () => { this.flash('Evaluación eliminada'); this.service.loadEvaluations(member.id); },
      error: () => this.flash('No se pudo eliminar'),
    });
  }

  initials(name: string): string {
    return name.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase();
  }

  formatDate(iso: string | null): string {
    if (!iso) return 'Evaluación';
    const d = new Date(iso);
    return d.toLocaleDateString('es', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  private normalize(form: Partial<PhysicalEvaluation>): Partial<PhysicalEvaluation> {
    const out: Partial<PhysicalEvaluation> = {};
    const numericKeys: (keyof PhysicalEvaluation)[] = [
      'weight_kg', 'height_cm', 'body_fat_pct', 'muscle_mass_pct',
      'waist_cm', 'hip_cm', 'chest_cm', 'arm_cm', 'leg_cm',
    ];
    for (const k of numericKeys) {
      const v = form[k];
      if (v !== null && v !== undefined && v !== ('' as any)) (out as any)[k] = Number(v);
    }
    if (form.injuries) out.injuries = form.injuries;
    if (form.trainer_notes) out.trainer_notes = form.trainer_notes;
    return out;
  }

  private flash(msg: string): void {
    this.toast.set(msg);
    setTimeout(() => this.toast.set(null), 2200);
  }
}
