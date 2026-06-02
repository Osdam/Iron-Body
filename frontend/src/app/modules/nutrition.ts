import { CommonModule } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MemberOption } from '../shared/services/physical-evaluation.service';
import { NutritionAdminService, NutritionGoal } from '../shared/services/nutrition-admin.service';

/**
 * CRM — Nutrición por miembro: meta, día actual, historial semanal y
 * recomendaciones IA generadas. Permite ajustar la meta. 100% backend Laravel.
 */
@Component({
  selector: 'app-nutrition-admin-page',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <section class="nu-page">
      <header class="nu-head">
        <div>
          <h1>Nutrición</h1>
          <p class="nu-sub">Consulta y ajusta la nutrición de cada miembro.</p>
        </div>
      </header>

      <div class="nu-search">
        <span class="material-symbols-outlined">search</span>
        <input type="search" placeholder="Buscar miembro…" [(ngModel)]="query" (ngModelChange)="onSearch($event)" />
      </div>

      @if (members().length > 0 && !selected()) {
        <div class="nu-members">
          @for (m of members(); track m.id) {
            <button class="nu-member" (click)="select(m)" type="button">
              <span class="nu-avatar">{{ initials(m.full_name) }}</span>
              <span class="nu-member-info"><strong>{{ m.full_name }}</strong><small>{{ m.document || 'Sin documento' }}</small></span>
            </button>
          }
        </div>
      }

      @if (selected(); as member) {
        <div class="nu-selected-head">
          <span class="nu-avatar lg">{{ initials(member.full_name) }}</span>
          <div class="nu-member-info"><strong>{{ member.full_name }}</strong><small>{{ member.document || 'Sin documento' }}</small></div>
          <button class="nu-btn ghost" (click)="clear()" type="button"><span class="material-symbols-outlined">close</span> Cambiar</button>
        </div>

        @if (service.loading()) {
          <div class="nu-skeleton"></div><div class="nu-skeleton"></div>
        } @else if (service.error()) {
          <div class="nu-state"><span class="material-symbols-outlined">error</span><p>{{ service.error() }}</p></div>
        } @else if (service.data(); as data) {
          <!-- Meta editable -->
          <article class="nu-card">
            <h3>Meta nutricional</h3>
            <div class="nu-grid">
              <label class="nu-field"><span>Calorías</span><input [(ngModel)]="goal.daily_calories" type="number" /></label>
              <label class="nu-field"><span>Proteína (g)</span><input [(ngModel)]="goal.protein_g" type="number" /></label>
              <label class="nu-field"><span>Carbos (g)</span><input [(ngModel)]="goal.carbs_g" type="number" /></label>
              <label class="nu-field"><span>Grasas (g)</span><input [(ngModel)]="goal.fat_g" type="number" /></label>
            </div>
            <div class="nu-actions">
              <button class="nu-btn primary" (click)="saveGoals(member)" type="button" [disabled]="saving()">
                <span class="material-symbols-outlined">save</span> Guardar meta
              </button>
            </div>
          </article>

          <!-- Día actual -->
          <article class="nu-card">
            <h3>Hoy</h3>
            <div class="nu-today">
              <div class="nu-stat"><strong>{{ data.today.consumed.calories }}</strong><small>kcal consumidas</small></div>
              <div class="nu-stat"><strong>{{ data.today.remaining.calories }}</strong><small>kcal restantes</small></div>
              <div class="nu-stat"><strong>{{ data.streak.current }}</strong><small>días de racha</small></div>
            </div>
            <div class="nu-weekbars">
              @for (d of data.weekly_history; track d.date) {
                <div class="nu-bar-col">
                  <div class="nu-bar" [style.height.%]="barHeight(d.calories, data.goal.daily_calories)" [class.met]="d.goal_met" [class.today]="d.is_today"></div>
                  <span [class.today]="d.is_today">{{ d.label }}</span>
                </div>
              }
            </div>
          </article>

          <!-- Recomendaciones IA -->
          <article class="nu-card">
            <h3>Recomendaciones IRON IA ({{ service.recommendations().length }})</h3>
            @if (service.recommendations().length === 0) {
              <p class="nu-empty">Este miembro aún no tiene recomendaciones generadas.</p>
            } @else {
              @for (r of service.recommendations(); track r.id) {
                <div class="nu-rec">
                  <div class="nu-rec-head">
                    <span class="material-symbols-outlined">auto_awesome</span>
                    <strong>{{ r.recommendation?.title || 'Recomendación' }}</strong>
                    <small>{{ r.date }}</small>
                  </div>
                  @if (r.summary) { <p class="nu-rec-summary">{{ r.summary }}</p> }
                  @if (r.recommendation?.actions?.length) {
                    <ul class="nu-rec-actions">
                      @for (a of r.recommendation.actions; track a) { <li>{{ a }}</li> }
                    </ul>
                  }
                </div>
              }
            }
          </article>
        }
      }

      @if (toast()) { <div class="nu-toast">{{ toast() }}</div> }
    </section>
  `,
  styles: [
    `
      :host { display: block; --y: #facc15; --bg: #0f1115; --bg2: #15181f; --bd: rgba(255,255,255,0.08); --t1: #f4f5f7; --t2: #9aa0aa; --t3: #6b7280; }
      .nu-page { padding: 24px 28px; color: var(--t1); max-width: 1000px; margin: 0 auto; }
      .nu-head h1 { font-size: 24px; font-weight: 800; margin: 0; }
      .nu-sub { margin: 4px 0 20px; color: var(--t2); font-size: 13px; }
      .nu-btn { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; border-radius: 10px; padding: 9px 14px; cursor: pointer; border: 1px solid var(--bd); background: rgba(255,255,255,0.04); color: var(--t1); }
      .nu-btn .material-symbols-outlined { font-size: 18px; }
      .nu-btn.primary { background: var(--y); color: #1a1a1a; border-color: var(--y); }
      .nu-btn:disabled { opacity: .45; cursor: not-allowed; }
      .nu-search { display: flex; align-items: center; gap: 8px; background: var(--bg2); border: 1px solid var(--bd); border-radius: 12px; padding: 11px 14px; margin-bottom: 14px; }
      .nu-search input { flex: 1; background: transparent; border: none; outline: none; color: var(--t1); font-size: 14px; }
      .nu-search .material-symbols-outlined { color: var(--t3); }
      .nu-members { display: flex; flex-direction: column; gap: 8px; }
      .nu-member { display: flex; align-items: center; gap: 12px; background: var(--bg); border: 1px solid var(--bd); border-radius: 12px; padding: 12px 14px; cursor: pointer; text-align: left; }
      .nu-member:hover { border-color: rgba(250,204,21,.4); }
      .nu-avatar { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 50%; background: rgba(250,204,21,.16); color: var(--y); font-weight: 800; font-size: 14px; }
      .nu-avatar.lg { width: 48px; height: 48px; }
      .nu-member-info { display: flex; flex-direction: column; }
      .nu-member-info strong { font-size: 14px; }
      .nu-member-info small { color: var(--t3); font-size: 12px; }
      .nu-selected-head { display: flex; align-items: center; gap: 14px; background: var(--bg); border: 1px solid var(--bd); border-radius: 14px; padding: 14px 16px; margin-bottom: 18px; }
      .nu-selected-head .nu-member-info { flex: 1; }
      .nu-card { background: var(--bg); border: 1px solid var(--bd); border-radius: 14px; padding: 18px; margin-bottom: 16px; }
      .nu-card h3 { margin: 0 0 14px; font-size: 15px; }
      .nu-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
      .nu-field { display: flex; flex-direction: column; gap: 5px; }
      .nu-field > span { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--t3); font-weight: 700; }
      .nu-field input { background: var(--bg2); border: 1px solid var(--bd); border-radius: 10px; padding: 9px 11px; color: var(--t1); font-size: 13px; outline: none; }
      .nu-field input:focus { border-color: rgba(250,204,21,.5); }
      .nu-actions { display: flex; justify-content: flex-end; margin-top: 14px; }
      .nu-today { display: flex; gap: 24px; margin-bottom: 18px; }
      .nu-stat { display: flex; flex-direction: column; }
      .nu-stat strong { font-size: 22px; font-weight: 800; color: var(--y); }
      .nu-stat small { font-size: 11px; color: var(--t2); }
      .nu-weekbars { display: flex; gap: 10px; align-items: flex-end; height: 110px; }
      .nu-bar-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px; height: 100%; justify-content: flex-end; }
      .nu-bar { width: 100%; min-height: 4px; border-radius: 6px; background: rgba(255,255,255,0.10); transition: height .3s ease; }
      .nu-bar.met { background: var(--y); }
      .nu-bar.today:not(.met) { background: rgba(255,255,255,0.30); }
      .nu-bar-col span { font-size: 11px; color: var(--t3); }
      .nu-bar-col span.today { color: var(--t1); font-weight: 800; }
      .nu-empty { color: var(--t3); font-size: 13px; margin: 0; }
      .nu-rec { border-top: 1px solid var(--bd); padding: 12px 0; }
      .nu-rec:first-of-type { border-top: none; }
      .nu-rec-head { display: flex; align-items: center; gap: 8px; }
      .nu-rec-head .material-symbols-outlined { color: var(--y); font-size: 18px; }
      .nu-rec-head strong { font-size: 14px; flex: 1; }
      .nu-rec-head small { color: var(--t3); font-size: 11px; }
      .nu-rec-summary { margin: 6px 0; font-size: 13px; color: var(--t2); }
      .nu-rec-actions { margin: 6px 0 0; padding-left: 18px; color: var(--t2); font-size: 12.5px; }
      .nu-skeleton { height: 100px; margin: 8px 0; border-radius: 14px; background: linear-gradient(90deg, rgba(255,255,255,.04) 25%, rgba(255,255,255,.08) 37%, rgba(255,255,255,.04) 63%); background-size: 400% 100%; animation: nush 1.4s ease infinite; }
      @keyframes nush { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }
      .nu-state { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 50px; color: var(--t3); text-align: center; background: var(--bg); border: 1px solid var(--bd); border-radius: 14px; }
      .nu-state .material-symbols-outlined { font-size: 42px; color: var(--y); }
      .nu-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: var(--y); color: #1a1a1a; font-weight: 700; font-size: 13px; padding: 11px 20px; border-radius: 999px; box-shadow: 0 8px 28px rgba(0,0,0,.4); z-index: 50; }
      @media (max-width: 760px) { .nu-grid { grid-template-columns: 1fr 1fr; } }
    `,
  ],
})
export default class NutritionAdminPage {
  readonly service = inject(NutritionAdminService);

  query = '';
  readonly members = signal<MemberOption[]>([]);
  readonly selected = signal<MemberOption | null>(null);
  readonly saving = signal(false);
  readonly toast = signal<string | null>(null);

  goal: NutritionGoal = { daily_calories: 2200, protein_g: 150, carbs_g: 250, fat_g: 70, goal_type: null };
  private searchTimer: any = null;

  onSearch(q: string): void {
    if (this.searchTimer) clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => {
      this.service.searchMembers(q ?? '').subscribe({
        next: (res) => this.members.set(res?.data ?? []),
        error: () => this.members.set([]),
      });
    }, 300);
  }

  select(m: MemberOption): void {
    this.selected.set(m);
    this.service.load(m.id);
    // Sincroniza el formulario de meta cuando lleguen los datos.
    setTimeout(() => {
      const data = this.service.data();
      if (data) this.goal = { ...data.goal };
    }, 600);
  }

  clear(): void {
    this.selected.set(null);
    this.query = '';
  }

  saveGoals(member: MemberOption): void {
    this.saving.set(true);
    this.service.saveGoals(member.id, this.goal).subscribe({
      next: () => { this.saving.set(false); this.flash('Meta guardada'); this.service.load(member.id); },
      error: () => { this.saving.set(false); this.flash('No se pudo guardar'); },
    });
  }

  barHeight(cal: number, goal: number): number {
    if (goal <= 0) return 0;
    return Math.min(100, Math.round((cal / goal) * 100));
  }

  initials(name: string): string {
    return name.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase();
  }

  private flash(msg: string): void {
    this.toast.set(msg);
    setTimeout(() => this.toast.set(null), 2200);
  }
}
