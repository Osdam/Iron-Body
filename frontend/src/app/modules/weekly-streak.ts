import { CommonModule } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  WeeklyStreakConfig,
  WeeklyStreakReward,
  WeeklyStreakService,
} from '../shared/services/weekly-streak.service';

/**
 * CRM — módulo "Esta semana".
 *
 * Permite configurar la card de Home y la experiencia premium: textos, meta
 * semanal, imágenes promocionales (upload real al backend), beneficios por
 * racha (CRUD) y estado activo/inactivo. 100% conectado al backend Laravel.
 */
@Component({
  selector: 'app-weekly-streak-page',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <section class="ws-page">
      <header class="ws-head">
        <div>
          <h1>Esta semana</h1>
          <p class="ws-sub">Configura la racha semanal, beneficios e imágenes promocionales.</p>
        </div>
        <div class="ws-head-actions">
          <button class="ws-btn ghost" (click)="reload()" type="button">
            <span class="material-symbols-outlined">refresh</span> Refrescar
          </button>
          <button class="ws-btn primary" (click)="newConfig()" type="button">
            <span class="material-symbols-outlined">add</span> Nueva configuración
          </button>
        </div>
      </header>

      @if (service.loading()) {
        <div class="ws-skeleton"></div>
        <div class="ws-skeleton"></div>
      } @else if (service.error()) {
        <div class="ws-state ws-error">
          <span class="material-symbols-outlined">error</span>
          <p>{{ service.error() }}</p>
          <button class="ws-btn ghost" (click)="reload()" type="button">Reintentar</button>
        </div>
      } @else if (service.configs().length === 0) {
        <div class="ws-state">
          <span class="material-symbols-outlined">local_fire_department</span>
          <p>Aún no hay configuración. Crea la primera para activar "Esta semana" en la app.</p>
          <button class="ws-btn primary" (click)="newConfig()" type="button">Crear configuración</button>
        </div>
      } @else {
        @for (cfg of service.configs(); track cfg.id) {
          <article class="ws-card">
            <div class="ws-card-head">
              <div class="ws-card-title">
                <span class="material-symbols-outlined ws-fire">local_fire_department</span>
                <div>
                  <h2>{{ cfg.title }}</h2>
                  <p>{{ cfg.subtitle || '—' }}</p>
                </div>
              </div>
              <div class="ws-card-meta">
                <span class="ws-pill" [class.on]="cfg.is_active">
                  {{ cfg.is_active ? 'Activo' : 'Inactivo' }}
                </span>
                <span class="ws-goal">Meta: {{ cfg.weekly_goal_days }} días</span>
              </div>
            </div>

            <!-- Editor de configuración -->
            <div class="ws-grid">
              <label class="ws-field">
                <span>Título</span>
                <input [(ngModel)]="cfg.title" type="text" />
              </label>
              <label class="ws-field">
                <span>Subtítulo</span>
                <input [(ngModel)]="cfg.subtitle" type="text" />
              </label>
              <label class="ws-field">
                <span>Meta semanal (días)</span>
                <input [(ngModel)]="cfg.weekly_goal_days" type="number" min="1" max="7" />
              </label>
              <label class="ws-field">
                <span>Texto CTA</span>
                <input [(ngModel)]="cfg.cta_label" type="text" placeholder="Entrenar hoy" />
              </label>
              <label class="ws-field ws-col2">
                <span>Título hero (experiencia premium)</span>
                <input [(ngModel)]="cfg.hero_title" type="text" />
              </label>
              <label class="ws-field ws-col2">
                <span>Descripción / motivación</span>
                <textarea [(ngModel)]="cfg.hero_description" rows="2"></textarea>
              </label>

              <!-- Imagen hero -->
              <div class="ws-field">
                <span>Imagen hero</span>
                <div class="ws-img">
                  @if (cfg.hero_image_url) {
                    <img [src]="cfg.hero_image_url" alt="hero" />
                  } @else {
                    <div class="ws-img-empty"><span class="material-symbols-outlined">image</span></div>
                  }
                  <input type="file" accept="image/*" (change)="onUpload($event, cfg, 'hero')" />
                </div>
              </div>
              <!-- Imagen promo -->
              <div class="ws-field">
                <span>Imagen promocional</span>
                <div class="ws-img">
                  @if (cfg.promo_image_url) {
                    <img [src]="cfg.promo_image_url" alt="promo" />
                  } @else {
                    <div class="ws-img-empty"><span class="material-symbols-outlined">image</span></div>
                  }
                  <input type="file" accept="image/*" (change)="onUpload($event, cfg, 'promo')" />
                </div>
              </div>

              <label class="ws-check">
                <input [(ngModel)]="cfg.is_active" type="checkbox" />
                <span>Activo (visible en la app)</span>
              </label>
            </div>

            <div class="ws-card-actions">
              <button class="ws-btn ghost danger" (click)="removeConfig(cfg)" type="button">
                <span class="material-symbols-outlined">delete</span> Eliminar
              </button>
              <button class="ws-btn primary" (click)="saveConfig(cfg)" type="button" [disabled]="saving()">
                <span class="material-symbols-outlined">save</span> Guardar configuración
              </button>
            </div>

            <!-- Beneficios -->
            <div class="ws-rewards">
              <div class="ws-rewards-head">
                <h3>Beneficios por racha</h3>
                <button class="ws-btn ghost" (click)="newReward(cfg)" type="button">
                  <span class="material-symbols-outlined">add</span> Añadir beneficio
                </button>
              </div>

              @if (cfg.rewards.length === 0) {
                <p class="ws-empty-rewards">Sin beneficios. Añade el primero (ej: 5 días → "Semana cumplida").</p>
              } @else {
                <div class="ws-reward-grid">
                  @for (rw of cfg.rewards; track rw.id) {
                    <div class="ws-reward">
                      <div class="ws-reward-img">
                        @if (rw.image_url) {
                          <img [src]="rw.image_url" alt="reward" />
                        } @else {
                          <div class="ws-img-empty"><span class="material-symbols-outlined">military_tech</span></div>
                        }
                        <input type="file" accept="image/*" (change)="onUploadReward($event, rw)" />
                      </div>
                      <label class="ws-field">
                        <span>Días requeridos</span>
                        <input [(ngModel)]="rw.required_days" type="number" min="1" max="7" />
                      </label>
                      <label class="ws-field">
                        <span>Título</span>
                        <input [(ngModel)]="rw.title" type="text" />
                      </label>
                      <label class="ws-field">
                        <span>Badge</span>
                        <input [(ngModel)]="rw.badge_label" type="text" placeholder="5 días" />
                      </label>
                      <label class="ws-field">
                        <span>Descripción</span>
                        <textarea [(ngModel)]="rw.description" rows="2"></textarea>
                      </label>
                      <label class="ws-check">
                        <input [(ngModel)]="rw.is_active" type="checkbox" />
                        <span>Activo</span>
                      </label>
                      <div class="ws-reward-actions">
                        <button class="ws-btn ghost danger sm" (click)="removeReward(cfg, rw)" type="button">
                          <span class="material-symbols-outlined">delete</span>
                        </button>
                        <button class="ws-btn primary sm" (click)="saveReward(rw)" type="button" [disabled]="saving()">
                          <span class="material-symbols-outlined">save</span> Guardar
                        </button>
                      </div>
                    </div>
                  }
                </div>
              }
            </div>
          </article>
        }
      }

      @if (toast()) {
        <div class="ws-toast">{{ toast() }}</div>
      }
    </section>
  `,
  styles: [
    `
      :host { display: block; --y: #facc15; --bg: #0f1115; --bg2: #15181f; --bd: rgba(255,255,255,0.08); --t1: #f4f5f7; --t2: #9aa0aa; --t3: #6b7280; }
      .ws-page { padding: 24px 28px; color: var(--t1); max-width: 1180px; margin: 0 auto; }
      .ws-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
      .ws-head h1 { font-size: 24px; font-weight: 800; margin: 0; letter-spacing: -0.02em; }
      .ws-sub { margin: 4px 0 0; color: var(--t2); font-size: 13px; }
      .ws-head-actions { display: flex; gap: 10px; }

      .ws-btn { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; border-radius: 10px; padding: 9px 14px; cursor: pointer; border: 1px solid var(--bd); transition: all .15s ease; background: rgba(255,255,255,0.04); color: var(--t1); }
      .ws-btn .material-symbols-outlined { font-size: 18px; }
      .ws-btn.ghost:hover { background: rgba(255,255,255,0.08); }
      .ws-btn.primary { background: var(--y); color: #1a1a1a; border-color: var(--y); }
      .ws-btn.primary:hover { filter: brightness(1.05); }
      .ws-btn.danger { color: #f87171; }
      .ws-btn.danger:hover { background: rgba(248,113,113,.12); }
      .ws-btn.sm { padding: 6px 10px; font-size: 12px; }
      .ws-btn:disabled { opacity: .45; cursor: not-allowed; }

      .ws-card { background: var(--bg); border: 1px solid var(--bd); border-radius: 16px; padding: 20px; margin-bottom: 20px; }
      .ws-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
      .ws-card-title { display: flex; gap: 12px; align-items: center; }
      .ws-card-title h2 { margin: 0; font-size: 18px; font-weight: 700; }
      .ws-card-title p { margin: 2px 0 0; color: var(--t2); font-size: 13px; }
      .ws-fire { color: var(--y); font-size: 30px; }
      .ws-card-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
      .ws-pill { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 999px; background: rgba(148,163,184,.16); color: #94a3b8; }
      .ws-pill.on { background: rgba(74,222,128,.16); color: #4ade80; }
      .ws-goal { font-size: 12px; color: var(--t2); }

      .ws-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
      .ws-field { display: flex; flex-direction: column; gap: 5px; }
      .ws-field.ws-col2 { grid-column: 1 / -1; }
      .ws-field > span { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--t3); font-weight: 700; }
      .ws-field input, .ws-field textarea { background: var(--bg2); border: 1px solid var(--bd); border-radius: 10px; padding: 10px 12px; color: var(--t1); font-size: 13px; outline: none; font-family: inherit; }
      .ws-field input:focus, .ws-field textarea:focus { border-color: rgba(250,204,21,.5); }

      .ws-img, .ws-reward-img { position: relative; border: 1px dashed var(--bd); border-radius: 12px; overflow: hidden; min-height: 90px; display: grid; place-items: center; background: var(--bg2); }
      .ws-img img, .ws-reward-img img { width: 100%; height: 110px; object-fit: cover; display: block; }
      .ws-img-empty { display: grid; place-items: center; height: 90px; color: var(--t3); }
      .ws-img-empty .material-symbols-outlined { font-size: 30px; }
      .ws-img input[type=file], .ws-reward-img input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }

      .ws-check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--t1); grid-column: 1 / -1; }
      .ws-check input { width: 16px; height: 16px; accent-color: var(--y); }

      .ws-card-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px; }

      .ws-rewards { margin-top: 22px; border-top: 1px solid var(--bd); padding-top: 18px; }
      .ws-rewards-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
      .ws-rewards-head h3 { margin: 0; font-size: 15px; font-weight: 700; }
      .ws-empty-rewards { color: var(--t3); font-size: 13px; margin: 0; }
      .ws-reward-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
      .ws-reward { background: var(--bg2); border: 1px solid var(--bd); border-radius: 14px; padding: 12px; display: flex; flex-direction: column; gap: 10px; }
      .ws-reward-actions { display: flex; justify-content: flex-end; gap: 8px; }

      .ws-skeleton { height: 120px; margin: 10px 0; border-radius: 16px; background: linear-gradient(90deg, rgba(255,255,255,.04) 25%, rgba(255,255,255,.08) 37%, rgba(255,255,255,.04) 63%); background-size: 400% 100%; animation: wssh 1.4s ease infinite; }
      @keyframes wssh { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }

      .ws-state { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 64px 24px; color: var(--t3); text-align: center; background: var(--bg); border: 1px solid var(--bd); border-radius: 16px; }
      .ws-state .material-symbols-outlined { font-size: 46px; opacity: .8; color: var(--y); }
      .ws-state p { margin: 0; font-size: 14px; max-width: 420px; }
      .ws-error .material-symbols-outlined { color: #f87171; }

      .ws-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: var(--y); color: #1a1a1a; font-weight: 700; font-size: 13px; padding: 11px 20px; border-radius: 999px; box-shadow: 0 8px 28px rgba(0,0,0,.4); z-index: 50; }

      @media (max-width: 760px) { .ws-grid { grid-template-columns: 1fr; } }
    `,
  ],
})
export default class WeeklyStreakPage implements OnInit {
  readonly service = inject(WeeklyStreakService);

  readonly saving = signal(false);
  readonly toast = signal<string | null>(null);

  ngOnInit(): void {
    this.service.refresh();
  }

  reload(): void {
    this.service.refresh();
  }

  private flash(msg: string): void {
    this.toast.set(msg);
    setTimeout(() => this.toast.set(null), 2200);
  }

  // ── Configuración ──
  newConfig(): void {
    this.service
      .createConfig({ title: 'Esta semana', weekly_goal_days: 5, is_active: true })
      .subscribe({
        next: () => { this.flash('Configuración creada'); this.service.refresh(); },
        error: () => this.flash('No se pudo crear'),
      });
  }

  saveConfig(cfg: WeeklyStreakConfig): void {
    this.saving.set(true);
    this.service
      .updateConfig(cfg.id, {
        title: cfg.title,
        subtitle: cfg.subtitle,
        weekly_goal_days: Number(cfg.weekly_goal_days) || 5,
        hero_title: cfg.hero_title,
        hero_description: cfg.hero_description,
        hero_image_url: cfg.hero_image_url,
        promo_image_url: cfg.promo_image_url,
        cta_label: cfg.cta_label,
        is_active: cfg.is_active,
      })
      .subscribe({
        next: () => { this.saving.set(false); this.flash('Configuración guardada'); },
        error: () => { this.saving.set(false); this.flash('No se pudo guardar'); },
      });
  }

  removeConfig(cfg: WeeklyStreakConfig): void {
    if (!confirm(`¿Eliminar la configuración "${cfg.title}"?`)) return;
    this.service.deleteConfig(cfg.id).subscribe({
      next: () => { this.flash('Configuración eliminada'); this.service.refresh(); },
      error: () => this.flash('No se pudo eliminar'),
    });
  }

  // ── Beneficios ──
  newReward(cfg: WeeklyStreakConfig): void {
    this.service
      .createReward({ config_id: cfg.id, required_days: 5, title: 'Nuevo beneficio', is_active: true })
      .subscribe({
        next: () => { this.flash('Beneficio creado'); this.service.refresh(); },
        error: () => this.flash('No se pudo crear el beneficio'),
      });
  }

  saveReward(rw: WeeklyStreakReward): void {
    this.saving.set(true);
    this.service
      .updateReward(rw.id, {
        required_days: Number(rw.required_days) || 1,
        title: rw.title,
        description: rw.description,
        badge_label: rw.badge_label,
        image_url: rw.image_url,
        is_active: rw.is_active,
      })
      .subscribe({
        next: () => { this.saving.set(false); this.flash('Beneficio guardado'); },
        error: () => { this.saving.set(false); this.flash('No se pudo guardar'); },
      });
  }

  removeReward(cfg: WeeklyStreakConfig, rw: WeeklyStreakReward): void {
    if (!confirm(`¿Eliminar el beneficio "${rw.title}"?`)) return;
    this.service.deleteReward(rw.id).subscribe({
      next: () => { this.flash('Beneficio eliminado'); this.service.refresh(); },
      error: () => this.flash('No se pudo eliminar'),
    });
  }

  // ── Uploads ──
  onUpload(event: Event, cfg: WeeklyStreakConfig, slot: 'hero' | 'promo'): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    this.service.uploadImage(file).subscribe({
      next: (res) => {
        if (slot === 'hero') cfg.hero_image_url = res.data.url;
        else cfg.promo_image_url = res.data.url;
        this.flash('Imagen subida — recuerda guardar');
      },
      error: () => this.flash('No se pudo subir la imagen'),
    });
  }

  onUploadReward(event: Event, rw: WeeklyStreakReward): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    this.service.uploadImage(file).subscribe({
      next: (res) => { rw.image_url = res.data.url; this.flash('Imagen subida — recuerda guardar'); },
      error: () => this.flash('No se pudo subir la imagen'),
    });
  }
}
