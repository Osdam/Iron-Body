import { CommonModule } from '@angular/common';
import {
  Component,
  ElementRef,
  HostListener,
  OnDestroy,
  OnInit,
  ViewChild,
  computed,
  inject,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { firstValueFrom, Observable } from 'rxjs';
import { ApiService, PaginatedResponse, PaymentSummary, UserSummary } from '../services/api.service';

interface AttendanceRecord {
  id: string;
  userId: number;
  memberName: string;
  plan?: string | null;
  action: 'entry' | 'exit';
  source: 'facial' | 'manual';
  date: string;
  time: string;
  note?: string;
}

interface AttendanceVisit {
  id: string;
  userId: number;
  memberName: string;
  date: string;
  entryTime: string | null;
  exitTime: string | null;
  entrySource?: AttendanceRecord['source'];
  exitSource?: AttendanceRecord['source'];
  sortTime: number;
}

interface MemberAttendanceView extends UserSummary {
  lastVisitDate: string | null;
  daysSinceLastVisit: number | null;
  visitsThisMonth: number;
  planDaysLeft: number | null;
  currentlyInside: boolean;
  paymentState: 'paid' | 'pending' | 'expired' | 'none';
  attendanceState: 'today' | 'recent' | 'warning' | 'critical' | 'none';
  planState: 'active' | 'soon' | 'expired' | 'unknown';
}

type CameraTerminal = 'entry' | 'exit';

@Component({
  selector: 'module-attendance',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <section class="attendance-page">
      <header class="attendance-header">
        <div>
          <h1>Control de asistencia</h1>
          <p>
            Registra visitas, detecta miembros ausentes y revisa cuántos días faltan para vencer cada plan.
          </p>
        </div>
        <div class="header-actions">
          <button type="button" class="btn-secondary" (click)="simulateFacialAccess()">
            <span class="material-symbols-outlined" aria-hidden="true">face</span>
            Lectura facial
          </button>
          <button type="button" class="btn-primary" (click)="markSelectedAttendance()">
            <span class="material-symbols-outlined" aria-hidden="true">how_to_reg</span>
            Registro manual
          </button>
        </div>
      </header>

      <section class="kpi-grid">
        <article class="kpi-card">
          <span class="material-symbols-outlined">today</span>
          <div>
            <strong>{{ todayVisits() }}</strong>
            <small>Visitas hoy</small>
          </div>
        </article>
        <article class="kpi-card">
          <span class="material-symbols-outlined">person_alert</span>
          <div>
            <strong>{{ absentMembers().length }}</strong>
            <small>Sin venir hace {{ absenceThreshold() }}+ días</small>
          </div>
        </article>
        <article class="kpi-card">
          <span class="material-symbols-outlined">event_busy</span>
          <div>
            <strong>{{ expiringSoonMembers().length }}</strong>
            <small>Planes por vencer</small>
          </div>
        </article>
        <article class="kpi-card">
          <span class="material-symbols-outlined">sensor_occupied</span>
          <div>
            <strong>{{ membersInside() }}</strong>
            <small>Dentro del gimnasio</small>
          </div>
        </article>
      </section>

      <section class="access-terminal">
        <div class="terminal-visual">
          <span class="material-symbols-outlined" aria-hidden="true">face</span>
          <div class="scan-ring"></div>
        </div>
        <div>
          <h2>Terminal de acceso facial</h2>
          <p>
            El lector identifica al miembro y el sistema registra entrada o salida automáticamente según si ya está dentro.
          </p>
        </div>
        <div class="terminal-state" *ngIf="selectedMemberView() as member">
          <strong>{{ member.name }}</strong>
          <span [class.inside]="member.currentlyInside">
            {{ member.currentlyInside ? 'Próxima lectura: salida' : 'Próxima lectura: entrada' }}
          </span>
        </div>
      </section>

      <section class="camera-control">
        <div class="camera-header">
          <div>
            <h2>Terminales faciales</h2>
            <p>Conecta una cámara para entrada y otra para salida. Las predeterminadas se guardan en este navegador.</p>
          </div>
          <button type="button" class="btn-secondary" (click)="refreshCameraDevices()">
            <span class="material-symbols-outlined" aria-hidden="true">sync</span>
            Buscar cámaras
          </button>
        </div>

        <div *ngIf="cameraError()" class="camera-alert">
          <span class="material-symbols-outlined" aria-hidden="true">warning</span>
          {{ cameraError() }}
        </div>

        <div class="camera-grid">
          <article class="camera-card" [class.connected]="entryCameraStatus() === 'Conectada'">
            <div class="camera-card-head">
              <div>
                <span class="camera-type">Entrada</span>
                <h3>Cámara de ingreso</h3>
                <p>{{ entryCameraStatus() }}</p>
              </div>
              <span class="material-symbols-outlined">login</span>
            </div>

            <video #entryVideo muted playsinline></video>

            <label>
              <span>Dispositivo</span>
              <select [ngModel]="entryCameraId()" (ngModelChange)="selectCamera('entry', $event)">
                <option value="">Seleccionar cámara</option>
                <option *ngFor="let camera of cameraDevices()" [value]="camera.deviceId">
                  {{ camera.label || 'Cámara disponible' }}
                </option>
              </select>
            </label>

            <div class="camera-actions">
              <button type="button" class="btn-secondary" (click)="startCamera('entry')">
                <span class="material-symbols-outlined">videocam</span>
                Conectar
              </button>
              <button type="button" class="btn-secondary" (click)="stopCamera('entry')">
                <span class="material-symbols-outlined">videocam_off</span>
                Detener
              </button>
              <button type="button" class="btn-primary" (click)="recognizeFromCamera('entry')">
                <span class="material-symbols-outlined">face</span>
                Registrar entrada
              </button>
            </div>

            <button type="button" class="default-link" (click)="setDefaultCamera('entry')">
              Dejar como predeterminada de entrada
            </button>
          </article>

          <article class="camera-card" [class.connected]="exitCameraStatus() === 'Conectada'">
            <div class="camera-card-head">
              <div>
                <span class="camera-type exit">Salida</span>
                <h3>Cámara de salida</h3>
                <p>{{ exitCameraStatus() }}</p>
              </div>
              <span class="material-symbols-outlined">logout</span>
            </div>

            <video #exitVideo muted playsinline></video>

            <label>
              <span>Dispositivo</span>
              <select [ngModel]="exitCameraId()" (ngModelChange)="selectCamera('exit', $event)">
                <option value="">Seleccionar cámara</option>
                <option *ngFor="let camera of cameraDevices()" [value]="camera.deviceId">
                  {{ camera.label || 'Cámara disponible' }}
                </option>
              </select>
            </label>

            <div class="camera-actions">
              <button type="button" class="btn-secondary" (click)="startCamera('exit')">
                <span class="material-symbols-outlined">videocam</span>
                Conectar
              </button>
              <button type="button" class="btn-secondary" (click)="stopCamera('exit')">
                <span class="material-symbols-outlined">videocam_off</span>
                Detener
              </button>
              <button type="button" class="btn-primary" (click)="recognizeFromCamera('exit')">
                <span class="material-symbols-outlined">face</span>
                Registrar salida
              </button>
            </div>

            <button type="button" class="default-link" (click)="setDefaultCamera('exit')">
              Dejar como predeterminada de salida
            </button>
          </article>
        </div>
      </section>

      <section class="checkin-panel">
        <div class="checkin-form">
          <label>
            <span>Miembro</span>
            <div class="pretty-select member-select" [class.open]="openSelect() === 'member'">
              <button type="button" class="pretty-trigger" (click)="toggleSelect('member')">
                <span>{{ selectedMemberLabel() }}</span>
                <span class="select-chevron" aria-hidden="true"></span>
              </button>
              <div class="pretty-menu" *ngIf="openSelect() === 'member'">
                <div class="member-search-box" (click)="$event.stopPropagation()">
                  <span class="material-symbols-outlined" aria-hidden="true">search</span>
                  <input
                    type="search"
                    [ngModel]="memberSelectSearch()"
                    (ngModelChange)="memberSelectSearch.set($event)"
                    placeholder="Buscar por nombre o cédula..."
                  />
                </div>
                <button
                  type="button"
                  class="pretty-option"
                  [class.selected]="selectedUserId() === 0"
                  (click)="chooseMember(0)"
                >
                  <span class="option-main">
                    <span class="option-icon material-symbols-outlined">person_search</span>
                    <span class="option-copy">
                      <strong>Seleccionar miembro</strong>
                      <small>Busca por nombre en la tabla si necesitas filtrar</small>
                    </span>
                  </span>
                  <span class="option-check" aria-hidden="true"></span>
                </button>
                <button
                  type="button"
                  class="pretty-option"
                  *ngFor="let member of filteredSelectMembers(); trackBy: trackByMember"
                  [class.selected]="selectedUserId() === member.id"
                  [class.denied-option]="!canEnter(member) && !member.currentlyInside"
                  (click)="chooseMember(member.id)"
                >
                  <span class="option-main">
                    <span class="option-icon material-symbols-outlined">{{ canEnter(member) || member.currentlyInside ? 'verified_user' : 'block' }}</span>
                    <span class="option-copy">
                      <strong>{{ member.name }}</strong>
                      <small>{{ member.plan || 'Sin plan' }} · {{ member.currentlyInside ? 'Dentro' : accessStateLabel(member) }}</small>
                    </span>
                  </span>
                  <span class="option-check" aria-hidden="true"></span>
                </button>
                <div *ngIf="filteredSelectMembers().length === 0" class="select-empty">
                  No hay miembros con ese nombre o cédula.
                </div>
              </div>
            </div>
          </label>
          <label>
            <span>Nota manual</span>
            <input
              type="text"
              [ngModel]="attendanceNote()"
              (ngModelChange)="attendanceNote.set($event)"
              placeholder="Opcional: entrenamiento, observación..."
            />
          </label>
          <label>
            <span>Alerta de ausencia</span>
            <div class="pretty-select" [class.open]="openSelect() === 'absence'">
              <button type="button" class="pretty-trigger" (click)="toggleSelect('absence')">
                <span>{{ absenceThreshold() }} días</span>
                <span class="select-chevron" aria-hidden="true"></span>
              </button>
              <div class="pretty-menu compact-menu" *ngIf="openSelect() === 'absence'">
                <button
                  type="button"
                  class="pretty-option"
                  *ngFor="let day of absenceOptions"
                  [class.selected]="absenceThreshold() === day"
                  (click)="chooseAbsence(day)"
                >
                  <span class="option-main">
                    <span class="option-icon material-symbols-outlined">event_busy</span>
                    <span class="option-copy">
                      <strong>{{ day }} días</strong>
                      <small>Marcar alerta desde este tiempo</small>
                    </span>
                  </span>
                  <span class="option-check" aria-hidden="true"></span>
                </button>
              </div>
            </div>
          </label>
        </div>
      </section>

      <div *ngIf="notice()" class="notice">
        <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
        {{ notice() }}
      </div>

      <section class="content-grid">
        <article class="panel">
          <div class="panel-header">
            <div>
              <h2>Miembros y asistencia</h2>
              <p>Última visita, faltas y vigencia del plan</p>
            </div>
            <input
              type="search"
              [ngModel]="searchTerm()"
              (ngModelChange)="searchTerm.set($event)"
              placeholder="Buscar miembro..."
            />
          </div>

          <div class="members-cards">
            <article
              class="member-card"
              *ngFor="let member of filteredMembers(); trackBy: trackByMember"
              [class.card-denied]="!member.currentlyInside && !canEnter(member)"
              [class.card-inside]="member.currentlyInside"
            >
              <div class="member-card-top">
                <div class="member-cell">
                  <div class="avatar">{{ initials(member.name) }}</div>
                  <div>
                    <strong>{{ member.name }}</strong>
                    <small>{{ member.email || member.phone || 'Sin contacto' }}</small>
                  </div>
                </div>
                <b class="access-pill" [class.inside]="member.currentlyInside" [class.denied]="!member.currentlyInside && !canEnter(member)">
                  {{ member.currentlyInside ? 'Dentro' : accessStateLabel(member) }}
                </b>
              </div>

              <div class="member-card-plan">
                <span class="material-symbols-outlined" aria-hidden="true">workspace_premium</span>
                <div>
                  <small>Plan actual</small>
                  <strong>{{ member.plan || 'Sin plan asignado' }}</strong>
                </div>
              </div>

              <div class="member-card-metrics">
                <div class="metric-box">
                  <span class="material-symbols-outlined" aria-hidden="true">event_available</span>
                  <small>Última visita</small>
                  <strong>{{ member.lastVisitDate ? (member.lastVisitDate | date: 'dd MMM yyyy') : 'Sin visitas' }}</strong>
                </div>
                <div class="metric-box">
                  <span class="material-symbols-outlined" aria-hidden="true">timer_off</span>
                  <small>Faltas</small>
                  <strong [class]="member.attendanceState">
                    {{ member.daysSinceLastVisit === null ? 'Nunca' : member.daysSinceLastVisit + ' días' }}
                  </strong>
                </div>
                <div class="metric-box">
                  <span class="material-symbols-outlined" aria-hidden="true">hourglass_bottom</span>
                  <small>Vigencia</small>
                  <strong [class]="member.planState">{{ planDaysLabel(member) }}</strong>
                </div>
              </div>

              <div class="member-card-actions">
                <button
                  type="button"
                  class="action-btn enter"
                  [class.blocked]="!canEnter(member)"
                  (click)="registerAccess(member, 'manual', 'entry')"
                >
                  <span class="material-symbols-outlined" aria-hidden="true">login</span>
                  Entrada
                </button>
                <button type="button" class="action-btn exit" (click)="registerAccess(member, 'manual', 'exit')">
                  <span class="material-symbols-outlined" aria-hidden="true">logout</span>
                  Salida
                </button>
              </div>
            </article>
            <div *ngIf="filteredMembers().length === 0" class="empty-mini">No hay miembros con ese filtro.</div>
          </div>
        </article>

        <aside class="side-panels">
          <article class="panel compact warning-panel">
            <h2>Para llamar</h2>
            <p>Miembros que llevan muchos días sin venir</p>
            <div class="call-list">
              <div *ngFor="let member of absentMembers().slice(0, 6)" class="call-item">
                <strong>{{ member.name }}</strong>
                <span>{{ member.daysSinceLastVisit === null ? 'Nunca ha asistido' : member.daysSinceLastVisit + ' días sin venir' }}</span>
                <small>{{ member.phone || member.email || 'Sin contacto' }}</small>
              </div>
              <div *ngIf="absentMembers().length === 0" class="empty-mini">No hay alertas de ausencia.</div>
            </div>
          </article>

          <article class="panel compact">
            <h2>Últimas visitas</h2>
            <p>Entrada y salida agrupadas por cliente</p>
            <div class="timeline">
              <div *ngFor="let visit of recentVisits()" class="timeline-item visit-session">
                <span class="dot" [class.exit-dot]="visit.exitTime"></span>
                <div>
                  <strong>{{ visit.memberName }}</strong>
                  <small>{{ visit.date | date: 'dd MMM yyyy' }}</small>
                  <div class="visit-times">
                    <span>
                      <b>Entrada</b>
                      {{ visit.entryTime || 'Sin registro' }}
                      <em *ngIf="visit.entrySource">· {{ visit.entrySource === 'facial' ? 'Facial' : 'Manual' }}</em>
                    </span>
                    <span [class.pending-exit]="!visit.exitTime">
                      <b>Salida</b>
                      {{ visit.exitTime || 'Pendiente' }}
                      <em *ngIf="visit.exitSource">· {{ visit.exitSource === 'facial' ? 'Facial' : 'Manual' }}</em>
                    </span>
                  </div>
                </div>
              </div>
              <div *ngIf="recentVisits().length === 0" class="empty-mini">Aún no hay visitas registradas.</div>
            </div>
          </article>
        </aside>
      </section>
    </section>
  `,
  styles: [
    `
      .attendance-page {
        color: #0a0a0a;
        display: grid;
        gap: 1.25rem;
      }

      .attendance-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 1rem;
        padding: 1.5rem;
        border-radius: 16px;
        background:
          linear-gradient(rgba(255, 255, 255, 0.88), rgba(255, 251, 235, 0.9)),
          url('/assets/crm/fondo7.png') center / cover no-repeat;
        border: 1px solid #e5e5e5;
      }

      .header-actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
      }

      h1,
      h2,
      p {
        margin: 0;
      }

      .attendance-header h1 {
        font-size: 2rem;
        line-height: 1.1;
        font-weight: 800;
      }

      .attendance-header p,
      .panel p {
        color: #666;
        margin-top: 0.35rem;
      }

      .btn-primary,
      .btn-secondary,
      .icon-btn {
        border: none;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
      }

      .btn-primary,
      .btn-secondary {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        font-weight: 800;
        border-radius: 10px;
        padding: 0.8rem 1rem;
      }

      .btn-primary {
        background: #fbbf24;
        color: #0a0a0a;
      }

      .btn-secondary {
        background: #ffffff;
        color: #0a0a0a;
        border: 1px solid #e5e5e5;
      }

      .btn-primary:hover:not(:disabled),
      .btn-secondary:hover:not(:disabled) {
        transform: translateY(-1px);
        box-shadow: 0 10px 20px rgba(251, 191, 36, 0.22);
      }

      .btn-primary:disabled,
      .btn-secondary:disabled {
        opacity: 0.55;
        cursor: not-allowed;
      }

      .access-terminal {
        display: grid;
        grid-template-columns: auto 1fr auto;
        align-items: center;
        gap: 1rem;
        padding: 1.2rem;
        border: 1px solid #fde68a;
        border-radius: 16px;
        background:
          radial-gradient(circle at 16px 16px, rgba(251, 191, 36, 0.16) 1.5px, transparent 1.5px) 0 0 / 12px 12px,
          linear-gradient(135deg, #fffbeb, #ffffff);
      }

      .terminal-visual {
        position: relative;
        width: 72px;
        height: 72px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 18px;
        background: #0a0a0a;
        color: #fbbf24;
        overflow: hidden;
      }

      .terminal-visual > span {
        font-size: 2rem;
        z-index: 1;
      }

      .scan-ring {
        position: absolute;
        inset: 10px;
        border: 2px solid rgba(251, 191, 36, 0.8);
        border-radius: 999px;
        animation: pulseScan 1.8s ease-in-out infinite;
      }

      @keyframes pulseScan {
        0% {
          transform: scale(0.7);
          opacity: 0.45;
        }
        70% {
          transform: scale(1.25);
          opacity: 0;
        }
        100% {
          opacity: 0;
        }
      }

      .terminal-state {
        display: grid;
        gap: 0.2rem;
        padding: 0.75rem 0.9rem;
        border-radius: 12px;
        background: #ffffff;
        border: 1px solid #e5e5e5;
      }

      .terminal-state span,
      .inside {
        color: #16a34a;
      }

      .camera-control {
        display: grid;
        gap: 1rem;
        padding: 1.2rem;
        border-radius: 16px;
        background: #ffffff;
        border: 1px solid #e5e5e5;
      }

      .camera-header,
      .camera-card-head,
      .camera-actions {
        display: flex;
        gap: 1rem;
      }

      .camera-header {
        align-items: flex-start;
        justify-content: space-between;
      }

      .camera-header p,
      .camera-card p {
        color: #666;
        margin-top: 0.3rem;
      }

      .camera-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
      }

      .camera-card {
        display: grid;
        gap: 0.85rem;
        padding: 1rem;
        border: 1px solid #e5e5e5;
        border-radius: 14px;
        background: #fafafa;
      }

      .camera-card.connected {
        border-color: rgba(34, 197, 94, 0.45);
        box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.1);
      }

      .camera-card-head {
        align-items: flex-start;
        justify-content: space-between;
      }

      .camera-card-head > .material-symbols-outlined {
        color: #fbbf24;
        font-size: 2rem;
      }

      .camera-type {
        display: inline-flex;
        margin-bottom: 0.35rem;
        padding: 0.25rem 0.55rem;
        border-radius: 999px;
        background: #dcfce7;
        color: #166534;
        font-size: 0.72rem;
        font-weight: 900;
        text-transform: uppercase;
      }

      .camera-type.exit {
        background: #fee2e2;
        color: #991b1b;
      }

      .camera-card video {
        width: 100%;
        aspect-ratio: 16 / 9;
        object-fit: cover;
        border-radius: 12px;
        background: #111;
        border: 1px solid #2b2a29;
      }

      .camera-card label {
        display: grid;
        gap: 0.4rem;
        font-weight: 800;
      }

      .camera-card select {
        min-height: 42px;
        padding: 0.65rem 0.8rem;
        border-radius: 10px;
        border: 1px solid #ddd;
        background: #fff;
        color: #0a0a0a;
      }

      .camera-actions {
        flex-wrap: wrap;
      }

      .default-link {
        justify-self: start;
        border: 0;
        background: transparent;
        color: #a16207;
        cursor: pointer;
        font-weight: 800;
        padding: 0;
      }

      .camera-alert {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        padding: 0.85rem 1rem;
        border-radius: 12px;
        background: #fef3c7;
        color: #78350f;
        font-weight: 700;
      }

      .kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1rem;
      }

      .kpi-card {
        display: flex;
        align-items: center;
        gap: 0.9rem;
        padding: 1rem;
        border-radius: 12px;
        background: #ffffff;
        border: 1px solid #e5e5e5;
      }

      .kpi-card > span {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #fffbeb;
        color: #f59e0b;
      }

      .kpi-card strong {
        display: block;
        font-size: 1.35rem;
        font-weight: 850;
      }

      .kpi-card small,
      .member-cell small,
      .timeline small,
      .call-item small {
        color: #71717a;
      }

      .checkin-panel,
      .panel,
      .notice {
        background: #ffffff;
        border: 1px solid #e5e5e5;
        border-radius: 12px;
      }

      .checkin-panel {
        position: relative;
        z-index: 30;
        padding: 1rem;
        overflow: visible;
      }

      .checkin-form {
        display: grid;
        grid-template-columns: 1.2fr 1.2fr 0.6fr;
        gap: 0.75rem;
        overflow: visible;
      }

      label {
        display: grid;
        gap: 0.35rem;
        font-size: 0.78rem;
        font-weight: 800;
        color: #666;
        text-transform: uppercase;
      }

      input {
        height: 42px;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        padding: 0 0.85rem;
        color: #0a0a0a;
        background: #ffffff;
        font-weight: 650;
      }

      .pretty-select {
        position: relative;
        width: 100%;
        min-width: 0;
      }

      .pretty-select.open {
        z-index: 80;
      }

      .pretty-trigger {
        width: 100%;
        height: 42px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        background: #fbfbfb;
        color: #0a0a0a;
        padding: 0 0.85rem;
        font-weight: 800;
        text-align: left;
        cursor: pointer;
        transition:
          border-color 0.15s ease,
          box-shadow 0.15s ease,
          background 0.15s ease;
      }

      .pretty-trigger > span:first-child {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .pretty-trigger:hover,
      .pretty-select.open .pretty-trigger {
        border-color: #fbbf24;
        background: #fffdf4;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.12);
      }

      .select-chevron {
        width: 0.52rem;
        height: 0.52rem;
        border-bottom: 2px solid #a16207;
        border-right: 2px solid #a16207;
        transform: rotate(45deg) translateY(-1px);
        transition: transform 160ms ease;
        flex-shrink: 0;
      }

      .pretty-select.open .select-chevron {
        transform: rotate(225deg) translateY(-1px);
      }

      .pretty-menu {
        position: absolute;
        top: calc(100% + 0.35rem);
        left: 0;
        width: max(100%, 330px);
        min-width: 260px;
        z-index: 5000;
        display: grid;
        gap: 0.2rem;
        max-height: 310px;
        overflow-y: auto;
        padding: 0.45rem;
        border: 1px solid #e4e4e7;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: 0 18px 42px rgba(0, 0, 0, 0.18);
        animation: selectIn 140ms ease;
      }

      .compact-menu {
        right: 0;
        left: auto;
        width: max(100%, 240px);
      }

      .member-select .pretty-menu {
        width: max(100%, 420px);
      }

      .member-search-box {
        position: sticky;
        top: 0;
        z-index: 2;
        display: flex;
        align-items: center;
        gap: 0.45rem;
        margin-bottom: 0.35rem;
        padding: 0.35rem 0.55rem;
        border: 1px solid #e5e5e5;
        border-radius: 10px;
        background: #ffffff;
        box-shadow: 0 8px 18px rgba(255, 255, 255, 0.86);
      }

      .member-search-box .material-symbols-outlined {
        color: #a16207;
        font-size: 1.15rem;
      }

      .member-search-box input {
        width: 100%;
        height: 34px;
        border: 0;
        padding: 0;
        outline: none;
        background: transparent;
        font-weight: 800;
      }

      .select-empty {
        padding: 0.85rem;
        color: #71717a;
        font-size: 0.86rem;
        font-weight: 750;
        text-transform: none;
      }

      @keyframes selectIn {
        from {
          opacity: 0;
          transform: translateY(-4px) scale(0.98);
        }
        to {
          opacity: 1;
          transform: translateY(0) scale(1);
        }
      }

      .pretty-option {
        min-height: 3.35rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.85rem;
        border: 0;
        border-radius: 9px;
        background: transparent;
        color: #3f3f46;
        text-align: left;
        padding: 0.62rem 0.7rem;
        cursor: pointer;
        transition:
          background 140ms ease,
          color 140ms ease,
          transform 140ms ease;
      }

      .pretty-option:hover {
        background: #fffbeb;
        color: #18181b;
        transform: translateY(-1px);
      }

      .pretty-option.selected {
        background: rgba(250, 204, 21, 0.18);
        color: #111827;
      }

      .pretty-option.denied-option {
        background: #fff1f2;
      }

      .option-main {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        min-width: 0;
      }

      .option-icon {
        width: 2rem;
        height: 2rem;
        display: grid;
        place-items: center;
        border-radius: 8px;
        background: #f4f4f5;
        color: #a16207;
        flex-shrink: 0;
        font-size: 1.12rem;
      }

      .pretty-option.denied-option .option-icon {
        background: #fee2e2;
        color: #b91c1c;
      }

      .pretty-option.selected .option-icon {
        background: #facc15;
        color: #111827;
      }

      .option-copy {
        display: grid;
        gap: 0.12rem;
        min-width: 0;
      }

      .option-copy strong {
        color: inherit;
        font-weight: 900;
        font-size: 0.9rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .option-copy small {
        color: #71717a;
        font-weight: 650;
        font-size: 0.75rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .pretty-option.selected .option-copy small {
        color: #854d0e;
      }

      .option-check {
        width: 1.15rem;
        height: 1.15rem;
        position: relative;
        display: block;
        border: 2px solid transparent;
        border-radius: 999px;
        flex-shrink: 0;
      }

      .pretty-option.selected .option-check {
        border-color: #ca8a04;
        background: #ca8a04;
      }

      .pretty-option.selected .option-check::after {
        content: '';
        position: absolute;
        left: 0.31rem;
        top: 0.16rem;
        width: 0.3rem;
        height: 0.58rem;
        border: solid #ffffff;
        border-width: 0 2px 2px 0;
        transform: rotate(45deg);
      }

      .notice {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.85rem 1rem;
        background: #f0fdf4;
        border-color: #bbf7d0;
        color: #166534;
        font-weight: 750;
      }

      .content-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 360px;
        gap: 1rem;
        align-items: start;
      }

      .panel {
        padding: 1rem;
      }

      .panel-header {
        display: flex;
        justify-content: space-between;
        align-items: start;
        gap: 1rem;
        margin-bottom: 1rem;
      }

      .panel-header input {
        max-width: 260px;
      }

      .members-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 0.85rem;
      }

      .member-card {
        position: relative;
        display: grid;
        gap: 0.9rem;
        min-width: 0;
        padding: 1rem;
        border: 1px solid #ececec;
        border-radius: 14px;
        background:
          linear-gradient(135deg, rgba(255, 255, 255, 0.96), rgba(255, 251, 235, 0.86)),
          radial-gradient(circle at top right, rgba(250, 204, 21, 0.18), transparent 34%);
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
        overflow: hidden;
        transition:
          transform 180ms ease,
          box-shadow 180ms ease,
          border-color 180ms ease;
      }

      .member-card::before {
        content: '';
        position: absolute;
        inset: 0 auto 0 0;
        width: 4px;
        background: #facc15;
      }

      .member-card:hover {
        transform: translateY(-2px);
        border-color: #facc15;
        box-shadow: 0 18px 34px rgba(15, 23, 42, 0.1);
      }

      .member-card.card-denied::before {
        background: #ef4444;
      }

      .member-card.card-inside::before {
        background: #22c55e;
      }

      .member-card-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
        min-width: 0;
      }

      .member-cell {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        min-width: 0;
      }

      .member-cell strong,
      .member-cell small {
        display: block;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .avatar {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #0a0a0a;
        color: #fbbf24;
        font-weight: 850;
        flex-shrink: 0;
      }

      .access-pill {
        flex-shrink: 0;
        padding: 0.35rem 0.55rem;
        border-radius: 999px;
        background: #f4f4f5;
        color: #3f3f46;
        font-size: 0.75rem;
        font-weight: 900;
      }

      .access-pill.inside {
        background: #dcfce7;
        color: #15803d;
      }

      .access-pill.denied {
        background: #fee2e2;
        color: #b91c1c;
      }

      .member-card-plan {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        padding: 0.75rem;
        border: 1px solid rgba(250, 204, 21, 0.32);
        border-radius: 12px;
        background: rgba(255, 251, 235, 0.72);
      }

      .member-card-plan .material-symbols-outlined {
        color: #a16207;
      }

      .member-card-plan small,
      .metric-box small {
        display: block;
        color: #71717a;
        font-size: 0.72rem;
        font-weight: 850;
        text-transform: uppercase;
      }

      .member-card-plan strong {
        display: block;
        color: #18181b;
        font-size: 0.9rem;
      }

      .member-card-metrics {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.55rem;
      }

      .metric-box {
        min-width: 0;
        display: grid;
        gap: 0.25rem;
        padding: 0.7rem;
        border: 1px solid #eeeeee;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.78);
      }

      .metric-box .material-symbols-outlined {
        color: #a16207;
        font-size: 1.2rem;
      }

      .metric-box strong {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 0.82rem;
      }

      .today,
      .active {
        color: #16a34a;
      }

      .recent {
        color: #2563eb;
      }

      .warning,
      .soon {
        color: #d97706;
      }

      .critical,
      .expired {
        color: #dc2626;
      }

      b.denied {
        color: #dc2626;
      }

      .none,
      .unknown {
        color: #71717a;
      }

      .member-card-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.55rem;
      }

      .action-btn {
        min-width: 0;
        min-height: 42px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        border: 0;
        border-radius: 11px;
        font-weight: 900;
        cursor: pointer;
        transition:
          transform 140ms ease,
          box-shadow 140ms ease,
          background 140ms ease;
      }

      .action-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 10px 18px rgba(15, 23, 42, 0.1);
      }

      .action-btn.enter {
        background: #facc15;
        color: #111827;
      }

      .action-btn.enter.blocked {
        background: #fee2e2;
        color: #991b1b;
      }

      .action-btn.exit {
        background: #fef2f2;
        color: #dc2626;
      }

      .side-panels {
        display: grid;
        gap: 1rem;
      }

      .compact h2 {
        font-size: 1.05rem;
      }

      .warning-panel {
        background: #fffbeb;
        border-color: #fde68a;
      }

      .call-list,
      .timeline {
        display: grid;
        gap: 0.65rem;
        margin-top: 1rem;
      }

      .call-item,
      .timeline-item {
        display: grid;
        gap: 0.2rem;
        padding: 0.7rem;
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.72);
        border: 1px solid rgba(229, 229, 229, 0.7);
      }

      .timeline-item {
        grid-template-columns: 10px 1fr;
        align-items: start;
      }

      .visit-session strong,
      .visit-session small {
        display: block;
      }

      .visit-times {
        display: grid;
        gap: 0.35rem;
        margin-top: 0.55rem;
      }

      .visit-times span {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.28rem;
        padding: 0.42rem 0.5rem;
        border-radius: 9px;
        background: #f8fafc;
        color: #3f3f46;
        font-size: 0.78rem;
        font-weight: 750;
      }

      .visit-times b {
        color: #111827;
        font-size: 0.72rem;
        text-transform: uppercase;
      }

      .visit-times em {
        color: #71717a;
        font-style: normal;
      }

      .visit-times .pending-exit {
        background: #fffbeb;
        color: #a16207;
      }

      .dot {
        width: 8px;
        height: 8px;
        margin-top: 0.35rem;
        border-radius: 999px;
        background: #fbbf24;
      }

      .exit-dot {
        background: #dc2626;
      }

      .empty-mini {
        color: #71717a;
        font-weight: 650;
        padding: 0.7rem;
      }

      .attendance-page {
        color: #e5e2e1;
      }

      .attendance-header {
        background:
          linear-gradient(rgba(12, 12, 12, 0.91), rgba(12, 12, 12, 0.94)),
          url('/assets/crm/fondo7.png') center / cover no-repeat;
        border-color: rgba(245, 197, 24, 0.10);
        box-shadow: 0 18px 44px rgba(0, 0, 0, 0.24);
      }

      .attendance-header h1,
      .panel h2,
      .access-terminal h2,
      .camera-header h2,
      .camera-card h3,
      .terminal-state strong,
      .kpi-card strong,
      .member-cell strong,
      .member-card-plan strong,
      .call-item strong,
      .timeline-item strong {
        color: #e5e2e1;
      }

      .attendance-header p,
      .panel p,
      .camera-header p,
      .camera-card p,
      label,
      .kpi-card small,
      .member-cell small,
      .timeline small,
      .call-item small,
      .member-card-plan small,
      .metric-box small,
      .option-copy small,
      .select-empty,
      .empty-mini {
        color: #b4afa6;
      }

      .btn-secondary,
      .terminal-state,
      .camera-control,
      .camera-card,
      .kpi-card,
      .checkin-panel,
      .panel,
      .notice,
      input,
      .camera-card select,
      .pretty-trigger,
      .member-search-box,
      .pretty-menu,
      .member-card,
      .metric-box,
      .call-item,
      .timeline-item {
        background: #1c1b1b;
        border-color: #353534;
        color: #e5e2e1;
      }

      .btn-primary,
      .action-btn.enter {
        background: #f5c518;
        color: #241a00;
      }

      .btn-secondary:hover:not(:disabled),
      input:focus,
      .pretty-trigger:hover,
      .pretty-select.open .pretty-trigger {
        background: #201f1f;
        border-color: #f5c518;
        box-shadow: 0 0 0 3px rgba(245, 197, 24, 0.13);
        outline: none;
      }

      input::placeholder {
        color: #77716a;
      }

      .camera-card video {
        background: #090909;
        border-color: #353534;
      }

      .camera-card.connected {
        border-color: rgba(34, 197, 94, 0.38);
        box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.08);
      }

      .default-link {
        color: #ffe08b;
      }

      .access-terminal {
        background:
          radial-gradient(circle at 16px 16px, rgba(245, 197, 24, 0.12) 1.5px, transparent 1.5px) 0 0 / 12px 12px,
          linear-gradient(135deg, #1c1b1b, #111);
        border-color: rgba(245, 197, 24, 0.22);
      }

      .terminal-visual,
      .avatar {
        background: #0e0e0e;
        border: 1px solid rgba(245, 197, 24, 0.18);
        color: #ffe08b;
      }

      .kpi-card > span,
      .option-icon,
      .member-card-plan .material-symbols-outlined,
      .metric-box .material-symbols-outlined,
      .member-search-box .material-symbols-outlined {
        background: rgba(245, 197, 24, 0.12);
        color: #ffe08b;
      }

      .pretty-menu {
        box-shadow: 0 22px 54px rgba(0, 0, 0, 0.44);
      }

      .member-search-box {
        box-shadow: 0 8px 18px rgba(0, 0, 0, 0.32);
      }

      .pretty-option {
        color: #e5e2e1;
      }

      .pretty-option:hover,
      .pretty-option.selected {
        background: rgba(245, 197, 24, 0.13);
        color: #ffe08b;
      }

      .pretty-option.denied-option {
        background: rgba(255, 180, 171, 0.10);
      }

      .pretty-option.denied-option .option-icon {
        background: rgba(255, 180, 171, 0.14);
        color: #ffb4ab;
      }

      .pretty-option.selected .option-copy small {
        color: #d1c5ac;
      }

      .select-chevron {
        border-color: #f5c518;
      }

      .notice {
        background: rgba(34, 197, 94, 0.12);
        border-color: rgba(34, 197, 94, 0.30);
        color: #86efac;
      }

      .member-card {
        background:
          linear-gradient(135deg, rgba(28, 27, 27, 0.96), rgba(17, 17, 17, 0.92)),
          radial-gradient(circle at top right, rgba(245, 197, 24, 0.16), transparent 34%);
        box-shadow: 0 18px 44px rgba(0, 0, 0, 0.22);
      }

      .member-card:hover {
        border-color: #f5c518;
        box-shadow: 0 18px 44px rgba(245, 197, 24, 0.12);
      }

      .access-pill {
        background: #2a2a2a;
        color: #d1c5ac;
      }

      .access-pill.inside {
        background: rgba(34, 197, 94, 0.14);
        color: #86efac;
      }

      .access-pill.denied,
      .action-btn.enter.blocked,
      .action-btn.exit {
        background: rgba(255, 180, 171, 0.14);
        color: #ffb4ab;
      }

      .member-card-plan,
      .metric-box {
        background: rgba(21, 21, 21, 0.78);
        border-color: #353534;
      }

      .visit-times span {
        background: #151515;
        color: #d1c5ac;
      }

      .visit-times b {
        color: #e5e2e1;
      }

      .visit-times em {
        color: #b4afa6;
      }

      .visit-times .pending-exit,
      .warning-panel {
        background: rgba(245, 197, 24, 0.10);
        border-color: rgba(245, 197, 24, 0.22);
        color: #ffe08b;
      }

      @media (max-width: 1180px) {
        .kpi-grid,
        .camera-grid,
        .checkin-form,
        .content-grid {
          grid-template-columns: 1fr;
        }

        .members-cards {
          grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        }
      }

      @media (max-width: 720px) {
        .attendance-header,
        .camera-header,
        .panel-header,
        .access-terminal {
          flex-direction: column;
        }

        .access-terminal {
          grid-template-columns: 1fr;
        }

        .panel-header input {
          max-width: none;
          width: 100%;
        }

        .members-cards,
        .member-card-metrics,
        .member-card-actions {
          grid-template-columns: 1fr;
        }

        .member-card-top {
          flex-direction: column;
        }
      }
    `,
  ],
})
export default class AttendanceModule implements OnInit, OnDestroy {
  private readonly api = inject(ApiService);
  private readonly elementRef = inject(ElementRef<HTMLElement>);
  private readonly storageKey = 'iron-body-attendance-records';
  private readonly entryCameraKey = 'iron-body-attendance-entry-camera';
  private readonly exitCameraKey = 'iron-body-attendance-exit-camera';
  private entryStream: MediaStream | null = null;
  private exitStream: MediaStream | null = null;

  @ViewChild('entryVideo') entryVideo?: ElementRef<HTMLVideoElement>;
  @ViewChild('exitVideo') exitVideo?: ElementRef<HTMLVideoElement>;

  members = signal<UserSummary[]>([]);
  payments = signal<PaymentSummary[]>([]);
  records = signal<AttendanceRecord[]>([]);
  cameraDevices = signal<MediaDeviceInfo[]>([]);
  entryCameraId = signal('');
  exitCameraId = signal('');
  entryCameraStatus = signal('Sin conectar');
  exitCameraStatus = signal('Sin conectar');
  cameraError = signal('');
  selectedUserId = signal<number>(0);
  attendanceNote = signal('');
  absenceThreshold = signal(7);
  openSelect = signal<'member' | 'absence' | null>(null);
  memberSelectSearch = signal('');
  searchTerm = signal('');
  notice = signal('');
  readonly absenceOptions = [3, 5, 7, 15];

  activeMembers = computed(() =>
    this.members().filter((member) => String(member.status || 'active') !== 'inactive'),
  );

  activeMemberViews = computed(() =>
    this.memberViews().filter((member) => String(member.status || 'active') !== 'inactive'),
  );

  filteredSelectMembers = computed(() => {
    const term = this.normalizeSearch(this.memberSelectSearch());
    const members = this.activeMemberViews();
    if (!term) return members;

    return members.filter((member) =>
      this.normalizeSearch(
        `${member.name || ''} ${member.document || ''} ${member.email || ''} ${member.phone || ''} ${member.plan || ''}`,
      ).includes(term),
    );
  });

  activeMembersCount = computed(
    () => this.members().filter((member) => String(member.status || 'active') === 'active').length,
  );

  membersInside = computed(() => this.memberViews().filter((member) => member.currentlyInside).length);

  memberViews = computed<MemberAttendanceView[]>(() =>
    this.members().map((member) => this.buildMemberView(member)),
  );

  filteredMembers = computed(() => {
    const term = this.searchTerm().trim().toLowerCase();
    if (!term) return this.memberViews();
    return this.memberViews().filter((member) =>
      [member.name, member.email, member.phone, member.document, member.plan]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(term)),
    );
  });

  todayVisits = computed(() => {
    const today = this.todayKey();
    return this.records().filter((record) => record.date === today && this.recordAction(record) === 'entry').length;
  });

  absentMembers = computed(() =>
    this.memberViews()
      .filter(
        (member) =>
          member.attendanceState === 'critical' ||
          member.daysSinceLastVisit === null ||
          (member.daysSinceLastVisit ?? 0) >= this.absenceThreshold(),
      )
      .sort((a, b) => (b.daysSinceLastVisit ?? 9999) - (a.daysSinceLastVisit ?? 9999)),
  );

  expiringSoonMembers = computed(() =>
    this.memberViews().filter((member) => member.planState === 'soon' || member.planState === 'expired'),
  );

  recentVisits = computed(() => this.buildVisitSessions().slice(0, 12));

  selectedMemberView = computed(() =>
    this.memberViews().find((member) => member.id === this.selectedUserId()) || null,
  );

  selectedMemberLabel = computed(() => {
    const member = this.selectedMemberView();
    return member ? `${member.name} - ${member.plan || 'Sin plan'}` : 'Seleccionar miembro';
  });

  @HostListener('document:click', ['$event'])
  closeSelectOnOutsideClick(event: MouseEvent): void {
    if (!this.openSelect()) return;
    if (!this.elementRef.nativeElement.contains(event.target as Node)) {
      this.openSelect.set(null);
    }
  }

  ngOnInit(): void {
    this.loadRecords();
    this.loadCameraDefaults();
    void this.refreshCameraDevices(true);
    this.loadAccessData();
  }

  ngOnDestroy(): void {
    this.stopCamera('entry');
    this.stopCamera('exit');
  }

  markSelectedAttendance(): void {
    const member = this.members().find((item) => item.id === this.selectedUserId());
    if (!member) {
      this.showNotice('Selecciona un miembro antes de registrar el acceso manual.');
      return;
    }

    this.registerAccess(member, 'manual');
  }

  simulateFacialAccess(): void {
    const member = this.members().find((item) => item.id === this.selectedUserId());
    if (!member) {
      this.showNotice('Selecciona un miembro para simular la lectura facial.');
      return;
    }

    this.registerAccess(member, 'facial');
  }

  async refreshCameraDevices(autoStart = false): Promise<void> {
    if (!navigator.mediaDevices?.enumerateDevices) {
      this.cameraError.set('Este navegador no permite listar cámaras. Usa Chrome/Edge en localhost o HTTPS.');
      return;
    }

    try {
      this.cameraError.set('');
      await this.ensureCameraPermission();
      const devices = await navigator.mediaDevices.enumerateDevices();
      const cameras = devices.filter((device) => device.kind === 'videoinput');
      this.cameraDevices.set(cameras);

      if (!this.entryCameraId() && cameras[0]) this.entryCameraId.set(cameras[0].deviceId);
      if (!this.exitCameraId() && cameras[1]) this.exitCameraId.set(cameras[1].deviceId);
      if (!this.exitCameraId() && cameras[0]) this.exitCameraId.set(cameras[0].deviceId);

      if (autoStart) {
        if (this.entryCameraId()) await this.startCamera('entry');
        if (this.exitCameraId()) await this.startCamera('exit');
      }
    } catch (error) {
      this.cameraError.set(this.cameraErrorMessage(error));
    }
  }

  selectCamera(terminal: CameraTerminal, deviceId: string): void {
    if (terminal === 'entry') {
      this.entryCameraId.set(deviceId);
      if (this.entryStream) void this.startCamera('entry');
      return;
    }

    this.exitCameraId.set(deviceId);
    if (this.exitStream) void this.startCamera('exit');
  }

  async startCamera(terminal: CameraTerminal): Promise<void> {
    const deviceId = terminal === 'entry' ? this.entryCameraId() : this.exitCameraId();
    if (!deviceId) {
      this.showNotice('Selecciona una cámara antes de conectar.');
      return;
    }

    this.setCameraStatus(terminal, 'Conectando...');
    this.stopCamera(terminal);

    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { deviceId: { exact: deviceId } },
        audio: false,
      });
      const video = terminal === 'entry' ? this.entryVideo?.nativeElement : this.exitVideo?.nativeElement;
      if (video) {
        video.srcObject = stream;
        await video.play();
      }

      if (terminal === 'entry') this.entryStream = stream;
      else this.exitStream = stream;

      this.setCameraStatus(terminal, 'Conectada');
      this.cameraError.set('');
    } catch (error) {
      this.setCameraStatus(terminal, 'Error de conexión');
      this.cameraError.set(this.cameraErrorMessage(error));
    }
  }

  stopCamera(terminal: CameraTerminal): void {
    const stream = terminal === 'entry' ? this.entryStream : this.exitStream;
    stream?.getTracks().forEach((track) => track.stop());

    const video = terminal === 'entry' ? this.entryVideo?.nativeElement : this.exitVideo?.nativeElement;
    if (video) video.srcObject = null;

    if (terminal === 'entry') {
      this.entryStream = null;
      this.entryCameraStatus.set('Sin conectar');
      return;
    }

    this.exitStream = null;
    this.exitCameraStatus.set('Sin conectar');
  }

  setDefaultCamera(terminal: CameraTerminal): void {
    const deviceId = terminal === 'entry' ? this.entryCameraId() : this.exitCameraId();
    if (!deviceId) {
      this.showNotice('Selecciona una cámara antes de guardarla como predeterminada.');
      return;
    }

    localStorage.setItem(terminal === 'entry' ? this.entryCameraKey : this.exitCameraKey, deviceId);
    this.showNotice(
      `Cámara predeterminada de ${terminal === 'entry' ? 'entrada' : 'salida'} guardada.`,
    );
  }

  recognizeFromCamera(terminal: CameraTerminal): void {
    const isConnected = terminal === 'entry' ? !!this.entryStream : !!this.exitStream;
    if (!isConnected) {
      this.showNotice(`Conecta primero la cámara de ${terminal === 'entry' ? 'entrada' : 'salida'}.`);
      return;
    }

    const member = this.members().find((item) => item.id === this.selectedUserId());
    if (!member) {
      this.showNotice('Selecciona el miembro identificado para completar la lectura facial.');
      return;
    }

    this.registerAccess(member, 'facial', terminal === 'entry' ? 'entry' : 'exit');
  }

  toggleSelect(select: 'member' | 'absence'): void {
    this.openSelect.update((current) => (current === select ? null : select));
    if (select === 'member') this.memberSelectSearch.set('');
  }

  chooseMember(userId: number): void {
    this.selectedUserId.set(userId);
    this.memberSelectSearch.set('');
    this.openSelect.set(null);
  }

  chooseAbsence(days: number): void {
    this.absenceThreshold.set(days);
    this.openSelect.set(null);
  }

  registerAccess(
    member: UserSummary,
    source: AttendanceRecord['source'],
    action?: AttendanceRecord['action'],
  ): void {
    const now = new Date();
    const nextAction = action || (this.isMemberInside(member.id) ? 'exit' : 'entry');
    if (nextAction === 'entry' && !this.canEnter(member)) {
      this.showNotice(this.accessDeniedMessage(member));
      return;
    }

    const record: AttendanceRecord = {
      id: `${member.id}-${now.getTime()}`,
      userId: member.id,
      memberName: member.name,
      plan: member.plan,
      action: nextAction,
      source,
      date: this.toDateKey(now),
      time: now.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' }),
      note: this.attendanceNote().trim() || undefined,
    };

    this.records.update((records) => [record, ...records]);
    this.saveRecords();
    this.attendanceNote.set('');
    this.selectedUserId.set(0);
    this.showNotice(
      `${nextAction === 'entry' ? 'Entrada' : 'Salida'} registrada para ${member.name} (${source === 'facial' ? 'facial' : 'manual'}).`,
    );
  }

  trackByMember(_index: number, member: MemberAttendanceView): number {
    return member.id;
  }

  initials(name: string): string {
    return String(name || 'U')
      .split(' ')
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase())
      .join('');
  }

  toNumber(value: string | number): number {
    return Number(value) || 0;
  }

  planDaysLabel(member: MemberAttendanceView): string {
    if (member.planDaysLeft === null) return 'Sin fecha';
    if (member.planDaysLeft < 0) return `Vencido hace ${Math.abs(member.planDaysLeft)} días`;
    if (member.planDaysLeft === 0) return 'Vence hoy';
    return `${member.planDaysLeft} días`;
  }

  canEnter(member: Pick<UserSummary, 'id' | 'status' | 'membershipEndDate'>): boolean {
    return this.resolvePaymentState(member) === 'paid';
  }

  accessStateLabel(member: MemberAttendanceView): string {
    if (this.canEnter(member)) return 'Fuera';
    if (member.paymentState === 'pending') return 'Pago pendiente';
    if (member.paymentState === 'expired') return 'Plan vencido';
    return 'Sin pago';
  }

  private async loadAccessData(): Promise<void> {
    try {
      const [members, payments] = await Promise.all([
        this.fetchAllPages<UserSummary>((page) => this.api.getUsers(page)),
        this.fetchAllPages<PaymentSummary>((page) => this.api.getPayments(page)),
      ]);
      this.members.set(members);
      this.payments.set(payments);
    } catch {
      this.members.set([]);
      this.payments.set([]);
      this.showNotice('No se pudieron cargar miembros o pagos para validar el acceso.');
    }
  }

  private async fetchAllPages<T>(
    loader: (page: number) => Observable<PaginatedResponse<T>>,
  ): Promise<T[]> {
    const first = await firstValueFrom(loader(1));
    const rows = [...(first.data || [])];

    for (let page = 2; page <= (first.last_page || 1); page++) {
      const next = await firstValueFrom(loader(page));
      rows.push(...(next.data || []));
    }

    return rows;
  }

  private loadRecords(): void {
    try {
      const raw = localStorage.getItem(this.storageKey);
      const parsed = raw ? JSON.parse(raw) : [];
      this.records.set(
        Array.isArray(parsed)
          ? parsed.map((record) => ({
              ...record,
              action: record.action || 'entry',
              source: record.source || 'manual',
            }))
          : [],
      );
    } catch {
      this.records.set([]);
    }
  }

  private loadCameraDefaults(): void {
    this.entryCameraId.set(localStorage.getItem(this.entryCameraKey) || '');
    this.exitCameraId.set(localStorage.getItem(this.exitCameraKey) || '');
  }

  private async ensureCameraPermission(): Promise<void> {
    const hasKnownLabels = (await navigator.mediaDevices.enumerateDevices()).some(
      (device) => device.kind === 'videoinput' && device.label,
    );
    if (hasKnownLabels) return;

    const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
    stream.getTracks().forEach((track) => track.stop());
  }

  private setCameraStatus(terminal: CameraTerminal, status: string): void {
    if (terminal === 'entry') this.entryCameraStatus.set(status);
    else this.exitCameraStatus.set(status);
  }

  private cameraErrorMessage(error: unknown): string {
    const name = error instanceof DOMException ? error.name : '';
    if (name === 'NotAllowedError') return 'Permiso de cámara denegado. Autoriza el acceso en el navegador.';
    if (name === 'NotFoundError') return 'No se encontró una cámara conectada.';
    if (name === 'NotReadableError') return 'La cámara está ocupada por otra aplicación.';
    if (name === 'OverconstrainedError') return 'La cámara seleccionada no está disponible.';
    return 'No se pudo conectar la cámara. Revisa permisos, conexión o navegador.';
  }

  private saveRecords(): void {
    localStorage.setItem(this.storageKey, JSON.stringify(this.records()));
  }

  private showNotice(message: string): void {
    this.notice.set(message);
    window.setTimeout(() => this.notice.set(''), 2800);
  }

  private buildVisitSessions(): AttendanceVisit[] {
    const openByUser = new Map<number, AttendanceVisit>();
    const visits: AttendanceVisit[] = [];
    const orderedRecords = [...this.records()].sort(
      (a, b) => this.recordTimestamp(a) - this.recordTimestamp(b),
    );

    orderedRecords.forEach((record) => {
      const action = this.recordAction(record);
      const timestamp = this.recordTimestamp(record);

      if (action === 'entry') {
        const openVisit = openByUser.get(record.userId);
        if (openVisit) visits.push(openVisit);

        openByUser.set(record.userId, {
          id: record.id,
          userId: record.userId,
          memberName: record.memberName,
          date: record.date,
          entryTime: record.time,
          exitTime: null,
          entrySource: record.source,
          sortTime: timestamp,
        });
        return;
      }

      const openVisit = openByUser.get(record.userId);
      if (openVisit) {
        openVisit.exitTime = record.time;
        openVisit.exitSource = record.source;
        openVisit.sortTime = timestamp;
        visits.push(openVisit);
        openByUser.delete(record.userId);
        return;
      }

      visits.push({
        id: record.id,
        userId: record.userId,
        memberName: record.memberName,
        date: record.date,
        entryTime: null,
        exitTime: record.time,
        exitSource: record.source,
        sortTime: timestamp,
      });
    });

    openByUser.forEach((visit) => visits.push(visit));
    return visits.sort((a, b) => b.sortTime - a.sortTime);
  }

  private recordTimestamp(record: AttendanceRecord): number {
    const idTime = Number(String(record.id || '').split('-').pop());
    if (Number.isFinite(idTime) && idTime > 0) return idTime;

    const fallback = new Date(`${record.date} ${record.time || '00:00'}`).getTime();
    return Number.isNaN(fallback) ? 0 : fallback;
  }

  private buildMemberView(member: UserSummary): MemberAttendanceView {
    const memberRecords = this.records().filter((record) => record.userId === member.id);
    const entryRecords = memberRecords.filter((record) => this.recordAction(record) === 'entry');
    const lastVisitDate = entryRecords[0]?.date || null;
    const daysSinceLastVisit = lastVisitDate ? this.daysBetween(lastVisitDate, this.todayKey()) : null;
    const visitsThisMonth = entryRecords.filter((record) => record.date.startsWith(this.currentMonthKey())).length;
    const planDaysLeft = member.membershipEndDate
      ? this.daysBetween(this.todayKey(), member.membershipEndDate)
      : null;

    return {
      ...member,
      lastVisitDate,
      daysSinceLastVisit,
      visitsThisMonth,
      planDaysLeft,
      currentlyInside: this.isMemberInside(member.id),
      paymentState: this.resolvePaymentState(member),
      attendanceState: this.resolveAttendanceState(daysSinceLastVisit),
      planState: this.resolvePlanState(planDaysLeft),
    };
  }

  private resolvePaymentState(
    member: Pick<UserSummary, 'id' | 'status' | 'membershipEndDate'>,
  ): MemberAttendanceView['paymentState'] {
    const status = String(member.status || '').toLowerCase().trim();
    const daysLeft = member.membershipEndDate ? this.daysBetween(this.todayKey(), member.membershipEndDate) : null;

    if (daysLeft !== null && daysLeft < 0) return 'expired';
    if (status === 'pending' || status === 'pendiente') return 'pending';

    const latestPayment = this.latestPaymentForMember(member.id);
    if (!latestPayment) return 'none';

    const paymentStatus = this.paymentStatusKey(latestPayment.status);
    if (paymentStatus === 'paid') return 'paid';
    if (paymentStatus === 'pending') return 'pending';
    return 'none';
  }

  private latestPaymentForMember(userId: number): PaymentSummary | undefined {
    return this.payments()
      .filter((payment) => Number(payment.user?.id) === Number(userId))
      .sort((a, b) => this.paymentTime(b) - this.paymentTime(a))[0];
  }

  private paymentTime(payment: PaymentSummary): number {
    const date = new Date(payment.paid_at || payment.created_at || '');
    return Number.isNaN(date.getTime()) ? 0 : date.getTime();
  }

  private paymentStatusKey(status: string | null | undefined): string {
    const key = String(status || '').toLowerCase().trim();
    if (key === 'pagado' || key === 'aprobado' || key === 'approved') return 'paid';
    if (key === 'pendiente') return 'pending';
    return key;
  }

  private accessDeniedMessage(member: Pick<UserSummary, 'id' | 'name' | 'status' | 'membershipEndDate'>): string {
    const state = this.resolvePaymentState(member);
    if (state === 'pending') return `Acceso bloqueado para ${member.name}: tiene pago pendiente.`;
    if (state === 'expired') return `Acceso bloqueado para ${member.name}: el plan está vencido.`;
    return `Acceso bloqueado para ${member.name}: no tiene un pago confirmado.`;
  }

  private normalizeSearch(value: string | number | null | undefined): string {
    return String(value || '')
      .trim()
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '');
  }

  private isMemberInside(userId: number): boolean {
    const lastRecord = this.records().find((record) => record.userId === userId);
    return this.recordAction(lastRecord) === 'entry';
  }

  private recordAction(record?: Partial<AttendanceRecord>): AttendanceRecord['action'] {
    return record?.action || 'entry';
  }

  private resolveAttendanceState(days: number | null): MemberAttendanceView['attendanceState'] {
    if (days === null) return 'none';
    if (days === 0) return 'today';
    if (days <= 2) return 'recent';
    if (days < this.absenceThreshold()) return 'warning';
    return 'critical';
  }

  private resolvePlanState(days: number | null): MemberAttendanceView['planState'] {
    if (days === null) return 'unknown';
    if (days < 0) return 'expired';
    if (days <= 7) return 'soon';
    return 'active';
  }

  private todayKey(): string {
    return this.toDateKey(new Date());
  }

  private currentMonthKey(): string {
    return this.todayKey().slice(0, 7);
  }

  private toDateKey(date: Date): string {
    return date.toISOString().split('T')[0];
  }

  private daysBetween(startDate: string, endDate: string): number {
    const start = new Date(startDate);
    const end = new Date(endDate);
    start.setHours(0, 0, 0, 0);
    end.setHours(0, 0, 0, 0);
    return Math.ceil((end.getTime() - start.getTime()) / 86400000);
  }
}
