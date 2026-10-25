// @vitest-environment jsdom
import '@angular/compiler';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';

import { BACKEND_PUBLICATION } from './testing/backend-publication.testing';
import { StudioSession } from './studio-session';
import { StudioPublicationBarComponent } from './studio-publication-bar';
import { StudioPublicationDialogComponent } from './studio-publication-dialog';
import { StudioCanaryEvidenceComponent } from './studio-canary-evidence';
import { StudioPublicationHistoryComponent } from './studio-publication-history';
import { readCanaryEvidence, readPublicationEntry, readPublicationPreview, readPublicationState } from './studio-parse';
import { evidenceRows, labLatestText, requirementRows } from './studio-publication-text';
import { PUBLISH_ACK_MAX, STUDIO_REASON_MAX, STUDIO_REASON_MIN, VERSION_NOTE_MAX, isStudioErrorCode } from './studio-api.types';
import { environment } from '../../../environments/environment';
import { borradorDto, catalogoDto, estadoH5Dto, ok, paginador, reglaDto, versionDetalle, versionResumen } from './studio-api-fixtures.testing';

/**
 * PARIDAD con las formas que exporta el backend (`ultron-studio:export-fixtures`,
 * `backend-publication.testing.ts`, H5 §A7): los lectores estrictos del CRM las
 * leen y los componentes del paso 4 las pintan sin perder nada.
 */

const BASE = `${environment.adminApiBaseUrl}/ultron-studio`;
const P = BACKEND_PUBLICATION as unknown as {
  lifecycle: { status: string; label: string; reachable: boolean }[];
  labLatest: (string | null)[];
  evidenceGates: string[];
  errors: Record<string, number>;
  limits: Record<string, number | number[]>;
  examples: {
    engine: Record<string, unknown>;
    publishing: Record<string, { available: boolean; code: string | null; message: string }>;
    publication: Record<string, unknown>;
    entries: Record<string, unknown>;
    preview: unknown;
    previewBlocked: unknown;
    evidence: unknown;
    evidenceInsufficient: unknown;
  };
};

describe('paridad con las formas de la publicación del backend', () => {
  let http: HttpTestingController;
  let s: StudioSession;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), StudioSession] });
    http = TestBed.inject(HttpTestingController);
    s = TestBed.inject(StudioSession);
  });
  afterEach(() => {
    try {
      http.verify();
    } finally {
      TestBed.resetTestingModule();
    }
  });

  async function asentar(f: ComponentFixture<unknown>): Promise<void> {
    for (let i = 0; i < 3; i++) {
      await new Promise((r) => setTimeout(r, 0));
      f.detectChanges();
    }
  }
  async function iniciar(publication?: unknown): Promise<void> {
    const p = s.init('u1');
    http.expectOne(`${BASE}/status`).flush(ok(estadoH5Dto({}, publication === undefined ? {} : { publication })));
    http.expectOne(`${BASE}/catalog`).flush(ok(catalogoDto()));
    http.expectOne(`${BASE}/draft`).flush(ok(borradorDto()));
    http.expectOne(`${BASE}/rules`).flush(ok([reglaDto()]));
    http.expectOne((r) => r.url === `${BASE}/versions`).flush(ok(paginador([versionResumen()])));
    await p;
  }

  it('los lectores estrictos leen todos los ejemplos', () => {
    for (const [n, v] of Object.entries(P.examples.publication)) expect(() => readPublicationState(v), n).not.toThrow();
    for (const [n, v] of Object.entries(P.examples.entries)) expect(() => readPublicationEntry(v), n).not.toThrow();
    expect(() => readPublicationPreview(P.examples.preview)).not.toThrow();
    expect(() => readPublicationPreview(P.examples.previewBlocked)).not.toThrow();
    expect(() => readCanaryEvidence(P.examples.evidence)).not.toThrow();
    expect(() => readCanaryEvidence(P.examples.evidenceInsufficient)).not.toThrow();
    expect(() => readPublicationState(P.examples.engine['publication'])).not.toThrow();
  });

  it('tubería, enumeraciones, límites y códigos de error coinciden con los del CRM (salvo D-1)', () => {
    expect(P.lifecycle.find((p) => p.status === 'RETIRED')!.label).toBe('Sustituida');
    expect(P.lifecycle.filter((p) => !p.reachable).map((p) => p.status)).toEqual(['VALIDATING']);
    for (const l of P.labLatest) expect(labLatestText(l as never)).not.toBe('');
    const filas = evidenceRows(readCanaryEvidence(P.examples.evidence)).map((r) => r.id);
    for (const g of P.evidenceGates) expect(filas, g).toContain(g);
    expect(P.limits['acknowledgedNoEffectMax']).toBe(PUBLISH_ACK_MAX);
    expect(P.limits['noteMax']).toBe(VERSION_NOTE_MAX);
    expect([P.limits['reasonMin'], P.limits['reasonMax']]).toEqual([STUDIO_REASON_MIN, STUDIO_REASON_MAX]);
    const desconocidos = Object.keys(P.errors).filter((c) => !isStudioErrorCode(c));
    expect(desconocidos).toEqual(['publication_head_invalid']);
  });

  it('la barra pinta cada estado de la publicación del servidor', async () => {
    for (const [n, v] of Object.entries(P.examples.publication)) {
      TestBed.resetTestingModule();
      TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), StudioSession] });
      http = TestBed.inject(HttpTestingController);
      s = TestBed.inject(StudioSession);
      await iniciar(v);
      const f = TestBed.createComponent(StudioPublicationBarComponent);
      f.detectChanges();
      const el = f.nativeElement as HTMLElement;
      const estado = readPublicationState(v);
      expect(el.querySelector('[data-estado-publicacion]')!.getAttribute('data-estado-publicacion-sello'), n).toBe(estado.state);
      expect(el.querySelectorAll('[data-deriva-param]').length, n).toBe(estado.drift.length);
      http.verify();
    }
  });

  it('el diálogo de publicar pinta la vista previa publicable y la bloqueada del servidor', async () => {
    await iniciar();
    for (const [n, cuerpo] of [
      ['publicable', P.examples.preview],
      ['bloqueada', P.examples.previewBlocked],
    ] as const) {
      const vp = readPublicationPreview(cuerpo);
      const version = versionDetalle({ uuid: vp.versionUuid, status: 'APPROVED', statusLabel: 'Aprobada' });
      const f = TestBed.createComponent(StudioPublicationDialogComponent);
      f.componentRef.setInput('version', version);
      f.detectChanges();
      http.expectOne(`${BASE}/versions/${vp.versionUuid}/publication-preview`).flush(ok(cuerpo));
      await asentar(f);
      const el = f.nativeElement as HTMLElement;
      expect(el.querySelector('[data-publicacion-dialogo]')!.getAttribute('data-publicacion-estado'), n).toBe(vp.publishable ? 'publicable' : 'bloqueada');
      expect(el.querySelectorAll('[data-publicacion-requisito]').length, n).toBe(10);
      expect(el.querySelectorAll('[data-publicacion-clase="must_match"]').length, n).toBe(4);
      expect(el.querySelectorAll('[data-publicacion-sin-efecto]').length, n).toBe(vp.noEffect.length);
      const filas = requirementRows(vp.requirements);
      expect([...el.querySelectorAll('[data-publicacion-requisito]')].map((x) => x.getAttribute('data-ok')), n).toEqual(filas.map((r) => String(r.ok)));
      f.destroy();
    }
  });

  it('la evidencia pinta la suficiente y la insuficiente del servidor', async () => {
    await iniciar();
    for (const cuerpo of [P.examples.evidence, P.examples.evidenceInsufficient]) {
      const ev = readCanaryEvidence(cuerpo);
      const uuid = ev.versionUuid ?? versionDetalle().uuid;
      const f = TestBed.createComponent(StudioCanaryEvidenceComponent);
      f.componentRef.setInput('version', versionDetalle({ uuid, status: 'CANARY', statusLabel: 'Canario' }));
      f.detectChanges();
      http.expectOne(`${BASE}/versions/${uuid}/canary-evidence`).flush(ok(cuerpo));
      await asentar(f);
      const el = f.nativeElement as HTMLElement;
      expect(el.querySelector('[data-evidencia]')!.getAttribute('data-evidencia-pasa')).toBe(String(ev.passes));
      expect(el.querySelectorAll('[data-evidencia-medida]').length).toBeGreaterThanOrEqual(9);
      for (const c of ev.failing) expect(el.querySelector(`[data-evidencia-medida="${c}"]`)!.getAttribute('data-ok'), c).toBe('false');
      f.destroy();
    }
  });

  it('la cadena pinta las cuatro entradas del servidor', async () => {
    await iniciar();
    const f = TestBed.createComponent(StudioPublicationHistoryComponent);
    f.componentRef.setInput('entries', Object.values(P.examples.entries).map((e) => readPublicationEntry(e)));
    f.detectChanges();
    await asentar(f);
    const el = f.nativeElement as HTMLElement;
    expect([...el.querySelectorAll('[data-publicacion-entrada]')].map((x) => x.getAttribute('data-accion')).sort()).toEqual(['legacy', 'promote', 'publish', 'rollback']);
  });
});
