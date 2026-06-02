import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

/** Métricas agregadas reales del módulo Mercadeo (espejo del overview backend). */
export interface MarketingOverview {
  spend_total: number;
  leads_total: number;
  conversations_total: number;
  converted_leads: number;
  revenue_total: number;
  roas: number | null;
  cac: number | null;
  conversion_rate: number | null;
  hot_leads: number;
  pending_followups: number;
  ai_actions_count: number;
  human_takeover_count: number;
}

export interface MarketingCampaign {
  id: number;
  meta_campaign_id: string | null;
  name: string;
  status: string | null;
  objective: string | null;
  spend: number;
  impressions: number;
  reach: number;
  clicks: number;
  ctr: number | null;
  cpc: number | null;
  cpm: number | null;
  leads: number;
  conversations: number;
  revenue_attributed: number;
  roas: number | null;
  date_range: { start: string | null; stop: string | null };
  created_at: string | null;
  updated_at: string | null;
}

export interface MarketingLead {
  id: number;
  channel: string;
  source: string | null;
  name: string | null;
  phone: string | null;
  instagram_username: string | null;
  status: string;
  temperature: string;
  objective: string | null;
  campaign_id: number | null;
  first_message_at: string | null;
  last_message_at: string | null;
  converted_at: string | null;
  created_at: string | null;
}

export interface MarketingPage<T> {
  ok: boolean;
  data: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

interface OverviewResponse {
  ok: boolean;
  data: MarketingOverview;
}

export interface MarketingListQuery {
  status?: string;
  temperature?: string;
  channel?: string;
  campaign_id?: number | string;
  from?: string;
  to?: string;
  page?: number;
  perPage?: number;
}

/**
 * Mercadeo digital (Meta) — datos REALES desde el backend admin. Sin mocks.
 * Si no hay registros sincronizados, las respuestas vienen vacías (empty state).
 */
@Injectable({ providedIn: 'root' })
export class MarketingService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.adminApiBaseUrl}/marketing`;

  overview(): Observable<OverviewResponse> {
    return this.http.get<OverviewResponse>(`${this.base}/overview`);
  }

  campaigns(q: MarketingListQuery = {}): Observable<MarketingPage<MarketingCampaign>> {
    return this.http.get<MarketingPage<MarketingCampaign>>(`${this.base}/campaigns`, { params: this.params(q) });
  }

  leads(q: MarketingListQuery = {}): Observable<MarketingPage<MarketingLead>> {
    return this.http.get<MarketingPage<MarketingLead>>(`${this.base}/leads`, { params: this.params(q) });
  }

  conversations(q: MarketingListQuery = {}): Observable<MarketingPage<any>> {
    return this.http.get<MarketingPage<any>>(`${this.base}/conversations`, { params: this.params(q) });
  }

  conversationMessages(id: number, q: MarketingListQuery = {}): Observable<MarketingPage<any>> {
    return this.http.get<MarketingPage<any>>(`${this.base}/conversations/${id}/messages`, { params: this.params(q) });
  }

  followups(q: MarketingListQuery = {}): Observable<MarketingPage<any>> {
    return this.http.get<MarketingPage<any>>(`${this.base}/followups`, { params: this.params(q) });
  }

  aiActions(q: MarketingListQuery = {}): Observable<MarketingPage<any>> {
    return this.http.get<MarketingPage<any>>(`${this.base}/ai-actions`, { params: this.params(q) });
  }

  attribution(q: MarketingListQuery = {}): Observable<MarketingPage<any>> {
    return this.http.get<MarketingPage<any>>(`${this.base}/attribution`, { params: this.params(q) });
  }

  private params(q: MarketingListQuery): HttpParams {
    let p = new HttpParams();
    for (const [k, v] of Object.entries(q)) {
      if (v === undefined || v === null || v === '') continue;
      const key = k === 'perPage' ? 'per_page' : k;
      p = p.set(key, String(v));
    }
    return p;
  }
}
