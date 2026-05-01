import { CommonModule } from '@angular/common';
import { Component, OnInit, computed, signal } from '@angular/core';
import MarketingKpiComponent from './components/marketing-kpi';
import MarketingFiltersComponent, { MarketingFilters } from './components/marketing-filters';
import CampaignDistributionComponent, { CampaignSegment } from './components/campaign-distribution';
import MarketingChannelsComponent, { ChannelMetric } from './components/marketing-channels';
import CampaignCardComponent, { Campaign } from './components/campaign-card';
import MarketingModalCampaignComponent, {
  CampaignModalMode,
} from './components/marketing-modal-campaign';
import MarketingModalCouponComponent, {
  Coupon,
  CouponModalMode,
} from './components/marketing-modal-coupon';
import MarketingCommunicationComponent, {
  Communication,
} from './components/marketing-communication';

@Component({
  selector: 'module-marketing',
  standalone: true,
  imports: [
    CommonModule,
    MarketingKpiComponent,
    MarketingFiltersComponent,
    CampaignDistributionComponent,
    MarketingChannelsComponent,
    CampaignCardComponent,
    MarketingModalCampaignComponent,
    MarketingModalCouponComponent,
    MarketingCommunicationComponent,
  ],
  template: `
    <section class="marketing-page">
      <header class="header">
        <div class="header-left">
          <h1>Mercadeo</h1>
          <p>Gestiona campañas, cupones, segmentos y comunicaciones del gimnasio.</p>
        </div>

        <div class="header-right">
          <button type="button" class="btn-tertiary" (click)="openCommunicationModal()">
            <span class="material-symbols-outlined" aria-hidden="true">mail_outline</span>
            Enviar comunicación
          </button>

          <button type="button" class="btn-secondary" (click)="openCouponModal()">
            <span class="material-symbols-outlined" aria-hidden="true">discount</span>
            Crear cupón
          </button>

          <button type="button" class="btn-primary" (click)="openCreateCampaignModal()">
            <span class="material-symbols-outlined" aria-hidden="true">campaign</span>
            Crear campaña
          </button>
        </div>
      </header>

      <div *ngIf="notice() as n" class="notice" [ngClass]="'notice-' + n.kind" role="status">
        <span class="material-symbols-outlined" aria-hidden="true">{{ noticeIcon(n.kind) }}</span>
        <p class="notice-message">{{ n.message }}</p>
        <button type="button" class="notice-close" (click)="dismissNotice()" aria-label="Cerrar">
          close
        </button>
      </div>

      <section class="kpis">
        <app-marketing-kpi
          title="Campañas activas"
          icon="campaign"
          color="success"
          [value]="kpis().activeCampaigns"
          subtitle="Estado Activa"
        ></app-marketing-kpi>
        <app-marketing-kpi
          title="Cupones vigentes"
          icon="discount"
          color="info"
          [value]="kpis().validCoupons"
          subtitle="Activos y sin expirar"
        ></app-marketing-kpi>
        <app-marketing-kpi
          title="Comunicaciones enviadas"
          icon="mail"
          color="primary"
          [value]="kpis().communicationsSent"
          subtitle="Este mes"
        ></app-marketing-kpi>
        <app-marketing-kpi
          title="Conversión estimada"
          icon="trending_up"
          color="warning"
          [value]="kpis().estimatedConversion"
          subtitle="Sobre campañas totales"
        ></app-marketing-kpi>
      </section>

      <app-marketing-filters
        [filters]="filters()"
        (filtersChange)="onFiltersChange($event)"
      ></app-marketing-filters>

      <div class="campaigns-container">
        <app-campaign-distribution
          [segments]="campaignDistribution()"
          (actionClicked)="openCreateCampaignModal()"
          (createRenewalCampaign)="createRenewalCampaign()"
        ></app-campaign-distribution>

        <app-marketing-channels
          [channels]="channelMetrics()"
          (channelSelected)="onChannelSelected($event)"
        ></app-marketing-channels>
      </div>

      <ng-container *ngIf="filteredCampaigns().length === 0; else content">
        <section class="empty">
          <div class="empty-icon" aria-hidden="true">
            <span class="material-symbols-outlined">campaign</span>
          </div>
          <h2>Todavía no hay campañas creadas</h2>
          <p>
            Crea tu primera campaña para promocionar planes, recuperar miembros y comunicar
            novedades del gimnasio.
          </p>
          <button type="button" class="btn-primary" (click)="openCreateCampaignModal()">
            <span class="material-symbols-outlined" aria-hidden="true">add</span>
            Crear primera campaña
          </button>
        </section>
      </ng-container>

      <ng-template #content>
        <section class="campaigns-grid">
          <app-campaign-card
            *ngFor="let c of filteredCampaigns(); trackBy: trackCampaign"
            [campaign]="c"
            (view)="viewCampaign($event)"
            (edit)="editCampaign($event)"
            (duplicate)="duplicateCampaign($event)"
            (toggleStatus)="toggleCampaignStatus($event)"
            (finish)="finishCampaign($event)"
            (delete)="deleteCampaign($event)"
          ></app-campaign-card>
        </section>
      </ng-template>

      <app-marketing-modal-campaign
        [isOpen]="isCampaignModalOpen()"
        [mode]="campaignModalMode()"
        [campaign]="selectedCampaign()"
        [isSaving]="isSavingCampaign()"
        (close)="closeCampaignModal()"
        (save)="submitCampaign($event)"
      ></app-marketing-modal-campaign>

      <app-marketing-modal-coupon
        [isOpen]="isCouponModalOpen()"
        [mode]="couponModalMode()"
        [coupon]="selectedCoupon()"
        [isSaving]="isSavingCoupon()"
        (close)="closeCouponModal()"
        (save)="submitCoupon($event)"
      ></app-marketing-modal-coupon>

      <app-marketing-communication
        [isOpen]="isCommunicationOpen()"
        [isSending]="isSendingCommunication()"
        (close)="closeCommunicationModal()"
        (send)="sendCommunication($event)"
      ></app-marketing-communication>
    </section>
  `,
  styles: [
    `
      .marketing-page {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0;
        color: #0a0a0a;
      }

      .header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 2rem;
        margin-bottom: 1.9rem;
        flex-wrap: wrap;
        padding-bottom: 1.5rem;
        border-bottom: 2px solid #f0f0f0;
      }

      .header-left h1 {
        font-family: Inter, sans-serif;
        font-size: 2.5rem;
        font-weight: 800;
        margin: 0 0 0.5rem;
        letter-spacing: -0.02em;
        line-height: 1.1;
      }

      .header-left p {
        font-size: 1.05rem;
        line-height: 1.6;
        color: #666;
        margin: 0;
        max-width: 720px;
      }

      .header-right {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        align-items: end;
      }

      .btn-primary,
      .btn-secondary,
      .btn-tertiary {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        padding: 0.78rem 1.2rem;
        border-radius: 12px;
        font-size: 0.95rem;
        font-weight: 850;
        cursor: pointer;
        transition: all 0.2s ease;
        border: 1px solid transparent;
      }

      .btn-primary {
        background: #fbbf24;
        color: #0a0a0a;
        box-shadow: 0 10px 22px rgba(251, 191, 36, 0.2);
      }

      .btn-primary:hover {
        background: #f9a825;
        box-shadow: 0 14px 28px rgba(251, 191, 36, 0.25);
        transform: translateY(-1px);
      }

      .btn-primary:focus {
        outline: none;
        box-shadow:
          0 0 0 3px rgba(251, 191, 36, 0.12),
          0 14px 28px rgba(251, 191, 36, 0.25);
      }

      .btn-secondary {
        background: #ffffff;
        color: #0a0a0a;
        border-color: #e5e5e5;
      }

      .btn-secondary:hover {
        background: #f9f9f9;
        border-color: #d0d0d0;
      }

      .btn-tertiary {
        background: #ffffff;
        color: #0a0a0a;
        border-color: #e5e5e5;
      }

      .btn-tertiary:hover {
        background: #f9f9f9;
        border-color: #d0d0d0;
      }

      .kpis {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
      }

      .campaigns-container {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 1.2rem;
        margin-bottom: 1.8rem;
      }

      .campaigns-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1.1rem;
        margin-bottom: 2rem;
      }

      .empty {
        border: 1px solid #ededed;
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 14px 34px rgba(0, 0, 0, 0.05);
        padding: 2.2rem;
        text-align: center;
      }

      .empty-icon {
        width: 62px;
        height: 62px;
        border-radius: 18px;
        display: grid;
        place-items: center;
        margin: 0 auto 1rem;
        border: 1px solid rgba(251, 191, 36, 0.45);
        background: rgba(251, 191, 36, 0.12);
      }

      .empty-icon span {
        font-size: 1.8rem;
        color: #fbbf24;
      }

      .empty h2 {
        margin: 0;
        font-size: 1.45rem;
        font-weight: 900;
        letter-spacing: -0.01em;
      }

      .empty p {
        margin: 0.6rem auto 1.35rem;
        color: #666;
        line-height: 1.6;
        max-width: 560px;
      }

      .notice {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 1rem 1.1rem;
        border-radius: 12px;
        border: 1px solid #e5e5e5;
        background: #ffffff;
        color: #0a0a0a;
        margin: 0 0 1.4rem;
      }

      .notice .material-symbols-outlined {
        font-size: 1.35rem;
      }

      .notice-message {
        margin: 0;
        flex: 1;
        font-weight: 700;
        color: #222;
      }

      .notice-close {
        border: none;
        background: transparent;
        cursor: pointer;
        color: #666;
        font-weight: 800;
        font-size: 0.9rem;
        padding: 0.25rem 0.35rem;
        border-radius: 8px;
        transition: background 0.15s ease;
      }

      .notice-close:hover {
        background: #f3f4f6;
      }

      .notice-success {
        border-color: #bbf7d0;
        background: #f0fdf4;
      }

      .notice-info {
        border-color: #e5e5e5;
        background: #fafafa;
      }

      .notice-error {
        border-color: #fecaca;
        background: #fef2f2;
      }

      @media (max-width: 1100px) {
        .campaigns-container {
          grid-template-columns: 1fr;
        }

        .campaigns-grid {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }

      @media (max-width: 900px) {
        .kpis {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }

      @media (max-width: 640px) {
        .kpis {
          grid-template-columns: 1fr;
        }
        .campaigns-grid {
          grid-template-columns: 1fr;
        }
        .header-left h1 {
          font-size: 2rem;
        }
      }
    `,
  ],
})
export default class MarketingModule implements OnInit {
  campaigns = signal<Campaign[]>([]);
  coupons = signal<Coupon[]>([]);
  communications = signal<Communication[]>([]);

  filters = signal<MarketingFilters>({
    searchTerm: '',
    status: 'all',
    type: 'all',
    channel: 'all',
    segment: 'all',
    dateRange: 'all',
  });

  notice = signal<{ kind: 'success' | 'info' | 'error'; message: string } | null>(null);

  isCampaignModalOpen = signal<boolean>(false);
  isSavingCampaign = signal<boolean>(false);
  campaignModalMode = signal<CampaignModalMode>('create');
  selectedCampaign = signal<Campaign | null>(null);

  isCouponModalOpen = signal<boolean>(false);
  isSavingCoupon = signal<boolean>(false);
  couponModalMode = signal<CouponModalMode>('create');
  selectedCoupon = signal<Coupon | null>(null);

  isCommunicationOpen = signal<boolean>(false);
  isSendingCommunication = signal<boolean>(false);

  filteredCampaigns = computed(() => this.filterCampaigns(this.campaigns(), this.filters()));
  kpis = computed(() => this.calculateMarketingKpis(this.campaigns(), this.coupons()));
  campaignDistribution = computed(() => this.buildCampaignDistribution(this.campaigns()));
  channelMetrics = computed(() => this.buildChannelMetrics(this.campaigns()));

  ngOnInit(): void {
    this.campaigns.set(this.buildMockCampaigns());
    this.coupons.set(this.buildMockCoupons());
  }

  onFiltersChange(next: MarketingFilters): void {
    this.filters.set(next);
  }

  openCreateCampaignModal(): void {
    this.dismissNotice();
    this.campaignModalMode.set('create');
    this.selectedCampaign.set(null);
    this.isCampaignModalOpen.set(true);
  }

  closeCampaignModal(): void {
    if (this.isSavingCampaign()) return;
    this.isCampaignModalOpen.set(false);
    this.selectedCampaign.set(null);
  }

  viewCampaign(campaign: Campaign): void {
    this.dismissNotice();
    this.campaignModalMode.set('detail');
    this.selectedCampaign.set(campaign);
    this.isCampaignModalOpen.set(true);
  }

  editCampaign(campaign: Campaign): void {
    this.dismissNotice();
    this.campaignModalMode.set('edit');
    this.selectedCampaign.set(campaign);
    this.isCampaignModalOpen.set(true);
  }

  async submitCampaign(payload: Partial<Campaign>): Promise<void> {
    this.isSavingCampaign.set(true);
    this.notice.set(null);

    try {
      await new Promise((r) => setTimeout(r, 450));

      const mode = this.campaignModalMode();
      if (mode === 'edit') {
        const current = this.selectedCampaign();
        if (!current) throw new Error('Campaña no encontrada para edición.');

        const updated: Campaign = {
          ...current,
          ...payload,
          updatedAt: new Date().toISOString(),
        };

        this.campaigns.set(this.campaigns().map((c) => (c.id === current.id ? updated : c)));
        this.notice.set({ kind: 'success', message: 'Campaña actualizada correctamente.' });
        this.closeCampaignModal();
        return;
      }

      // Create
      const now = new Date().toISOString();
      const campaign: Campaign = {
        id: this.newId('campaign'),
        name: String(payload.name || '').trim(),
        type: String(payload.type || ''),
        objective: String(payload.objective || ''),
        segment: String(payload.segment || ''),
        channel: String(payload.channel || ''),
        startDate: String(payload.startDate || ''),
        endDate: String(payload.endDate || ''),
        status: String(payload.status || 'Borrador'),
        message: String(payload.message || ''),
        couponCode: payload.couponCode || '',
        estimatedReach: Number(payload.estimatedReach || 0),
        conversions: 0,
        budget: Number(payload.budget || 0),
        conversionGoal: Number(payload.conversionGoal || 0),
        createdAt: now,
        updatedAt: now,
      };

      this.campaigns.set([campaign, ...this.campaigns()]);
      this.notice.set({ kind: 'success', message: 'Campaña creada correctamente.' });
      this.closeCampaignModal();
    } catch (e: any) {
      this.notice.set({ kind: 'error', message: e?.message || 'No se pudo guardar la campaña.' });
    } finally {
      this.isSavingCampaign.set(false);
    }
  }

  duplicateCampaign(campaign: Campaign): void {
    const now = new Date().toISOString();
    const duplicate: Campaign = {
      ...campaign,
      id: this.newId('campaign'),
      name: `${campaign.name} (copia)`,
      status: 'Borrador',
      conversions: 0,
      createdAt: now,
      updatedAt: now,
    };

    this.campaigns.set([duplicate, ...this.campaigns()]);
    this.notice.set({ kind: 'success', message: 'Campaña duplicada correctamente.' });
  }

  toggleCampaignStatus(campaign: Campaign): void {
    const current = (campaign.status || '').toLowerCase();
    const next = current.includes('activa') ? 'Pausada' : 'Activa';
    const updated: Campaign = { ...campaign, status: next, updatedAt: new Date().toISOString() };
    this.campaigns.set(this.campaigns().map((c) => (c.id === campaign.id ? updated : c)));
    this.notice.set({ kind: 'success', message: `Campaña ${next.toLowerCase()}.` });
  }

  finishCampaign(campaign: Campaign): void {
    const updated: Campaign = {
      ...campaign,
      status: 'Finalizada',
      updatedAt: new Date().toISOString(),
    };
    this.campaigns.set(this.campaigns().map((c) => (c.id === campaign.id ? updated : c)));
    this.notice.set({ kind: 'success', message: 'Campaña finalizada.' });
  }

  deleteCampaign(campaign: Campaign): void {
    const ok = window.confirm(
      `¿Eliminar la campaña "${campaign.name}"? Esta acción no se puede deshacer.`,
    );
    if (!ok) return;
    this.campaigns.set(this.campaigns().filter((c) => c.id !== campaign.id));
    this.notice.set({ kind: 'success', message: 'Campaña eliminada.' });
  }

  openCouponModal(): void {
    this.dismissNotice();
    this.couponModalMode.set('create');
    this.selectedCoupon.set(null);
    this.isCouponModalOpen.set(true);
  }

  closeCouponModal(): void {
    if (this.isSavingCoupon()) return;
    this.isCouponModalOpen.set(false);
    this.selectedCoupon.set(null);
  }

  async submitCoupon(payload: Partial<Coupon>): Promise<void> {
    this.isSavingCoupon.set(true);
    this.notice.set(null);

    try {
      await new Promise((r) => setTimeout(r, 450));

      const mode = this.couponModalMode();
      if (mode === 'edit') {
        const current = this.selectedCoupon();
        if (!current) throw new Error('Cupón no encontrado para edición.');

        const updated: Coupon = {
          ...current,
          ...payload,
        };

        this.coupons.set(this.coupons().map((cp) => (cp.id === current.id ? updated : cp)));
        this.notice.set({ kind: 'success', message: 'Cupón actualizado correctamente.' });
        this.closeCouponModal();
        return;
      }

      // Create
      const now = new Date().toISOString();
      const coupon: Coupon = {
        id: this.newId('coupon'),
        name: String(payload.name || '').trim(),
        code: String(payload.code || '').trim(),
        discountType: (payload.discountType as any) || 'Porcentaje',
        discountValue: Number(payload.discountValue || 0),
        startDate: String(payload.startDate || ''),
        endDate: String(payload.endDate || ''),
        usageLimit: Number(payload.usageLimit || 100),
        usedCount: 0,
        status: (payload.status as any) || 'Activo',
        appliesTo: String(payload.appliesTo || 'Todos los planes'),
        createdAt: now,
      };

      this.coupons.set([coupon, ...this.coupons()]);
      this.notice.set({ kind: 'success', message: 'Cupón creado correctamente.' });
      this.closeCouponModal();
    } catch (e: any) {
      this.notice.set({ kind: 'error', message: e?.message || 'No se pudo guardar el cupón.' });
    } finally {
      this.isSavingCoupon.set(false);
    }
  }

  openCommunicationModal(): void {
    this.dismissNotice();
    this.isCommunicationOpen.set(true);
  }

  closeCommunicationModal(): void {
    if (this.isSendingCommunication()) return;
    this.isCommunicationOpen.set(false);
  }

  async sendCommunication(payload: Partial<Communication>): Promise<void> {
    this.isSendingCommunication.set(true);
    this.notice.set(null);

    try {
      await new Promise((r) => setTimeout(r, 600));

      // TODO: Conectar con endpoint /api/communications para envío real
      // Por ahora simula envío local
      const segmentCounts: { [key: string]: number } = {
        'Todos los miembros': 450,
        'Miembros activos': 380,
        'Miembros inactivos': 70,
        'Membresías por vencer': 45,
        'Membresías vencidas': 25,
        'Nuevos miembros': 12,
        'Miembros VIP': 28,
        Leads: 35,
      };

      const now = new Date().toISOString();
      const recipients = segmentCounts[(payload.segment as string) || 'Todos los miembros'] || 0;
      const communication: Communication = {
        id: this.newId('communication'),
        segment: String(payload.segment || ''),
        channel: String(payload.channel || ''),
        message: String(payload.message || ''),
        recipientsCount: recipients,
        status: 'Enviada',
        sentAt: now,
        createdAt: now,
      };

      this.communications.set([communication, ...this.communications()]);
      this.notice.set({
        kind: 'success',
        message: `Comunicación enviada a ${recipients} miembros.`,
      });
      this.closeCommunicationModal();
    } catch (e: any) {
      this.notice.set({
        kind: 'error',
        message: e?.message || 'No se pudo enviar la comunicación.',
      });
    } finally {
      this.isSendingCommunication.set(false);
    }
  }

  onChannelSelected(channel: ChannelMetric): void {
    this.filters.set({ ...this.filters(), channel: channel.name });
  }

  createRenewalCampaign(): void {
    this.openCreateCampaignModal();
  }

  trackCampaign = (_: number, c: Campaign) => c.id;

  dismissNotice(): void {
    this.notice.set(null);
  }

  noticeIcon(kind: 'success' | 'info' | 'error'): string {
    if (kind === 'success') return 'check_circle';
    if (kind === 'error') return 'error';
    return 'info';
  }

  private filterCampaigns(campaigns: Campaign[], filters: MarketingFilters): Campaign[] {
    const term = (filters.searchTerm || '').trim().toLowerCase();
    const status = String(filters.status || 'all');
    const type = String(filters.type || 'all');
    const channel = String(filters.channel || 'all');
    const segment = String(filters.segment || 'all');

    return (campaigns || []).filter((c) => {
      const name = (c.name || '').toLowerCase();
      const msg = (c.message || '').toLowerCase();
      const coupon = (c.couponCode || '').toLowerCase();

      const matchesTerm =
        !term || name.includes(term) || msg.includes(term) || coupon.includes(term);
      const matchesStatus = status === 'all' || (c.status || '') === status;
      const matchesType = type === 'all' || (c.type || '') === type;
      const matchesChannel = channel === 'all' || (c.channel || '') === channel;
      const matchesSegment = segment === 'all' || (c.segment || '') === segment;

      return matchesTerm && matchesStatus && matchesType && matchesChannel && matchesSegment;
    });
  }

  private calculateMarketingKpis(
    campaigns: Campaign[],
    coupons: Coupon[],
  ): {
    activeCampaigns: number;
    validCoupons: number;
    communicationsSent: number;
    estimatedConversion: number;
  } {
    const list = campaigns || [];
    const cpList = coupons || [];
    const commList = this.communications();

    const activeCampaigns = list.filter(
      (c) => String(c.status || '').toLowerCase() === 'activa',
    ).length;

    const today = new Date().toISOString().split('T')[0];
    const validCoupons = cpList.filter(
      (cp) => cp.status === 'Activo' && cp.endDate >= today,
    ).length;

    const communicationsSent = commList.filter((cm) => cm.status === 'Enviada').length;

    const totalReach = list.reduce((sum, c) => sum + c.estimatedReach, 0);
    const totalConversions = list.reduce((sum, c) => sum + c.conversions, 0);
    const estimatedConversion =
      totalReach > 0 ? Math.round((totalConversions / totalReach) * 100) : 0;

    return { activeCampaigns, validCoupons, communicationsSent, estimatedConversion };
  }

  private buildCampaignDistribution(campaigns: Campaign[]): CampaignSegment[] {
    const list = campaigns || [];
    const total = list.length || 1;

    const status = {
      Activas: list.filter((c) => c.status === 'Activa').length,
      Programadas: list.filter((c) => c.status === 'Programada').length,
      Borrador: list.filter((c) => c.status === 'Borrador').length,
      Finalizada: list.filter((c) => c.status === 'Finalizada').length,
    };

    return [
      {
        name: 'Activas',
        count: status['Activas'],
        color: '#10b981',
        percentage: (status['Activas'] / total) * 100,
      },
      {
        name: 'Programadas',
        count: status['Programadas'],
        color: '#3b82f6',
        percentage: (status['Programadas'] / total) * 100,
      },
      {
        name: 'Borrador',
        count: status['Borrador'],
        color: '#fbbf24',
        percentage: (status['Borrador'] / total) * 100,
      },
      {
        name: 'Finalizada',
        count: status['Finalizada'],
        color: '#d1d5db',
        percentage: (status['Finalizada'] / total) * 100,
      },
    ];
  }

  private buildChannelMetrics(campaigns: Campaign[]): ChannelMetric[] {
    const list = campaigns || [];
    const channels: { [key: string]: number } = {
      whatsapp: 0,
      email: 0,
      sms: 0,
      push: 0,
      social: 0,
    };

    list.forEach((c) => {
      const ch = (c.channel || '').toLowerCase();
      if (ch.includes('whatsapp')) channels['whatsapp'] += c.estimatedReach;
      if (ch.includes('correo') || ch.includes('email')) channels['email'] += c.estimatedReach;
      if (ch.includes('sms')) channels['sms'] += c.estimatedReach;
      if (ch.includes('notificación') || ch.includes('push')) channels['push'] += c.estimatedReach;
      if (ch.includes('redes') || ch.includes('social')) channels['social'] += c.estimatedReach;
    });

    return [
      {
        id: 'whatsapp',
        name: 'WhatsApp',
        icon: 'message',
        count: channels['whatsapp'],
        color: '#25d366',
      },
      {
        id: 'email',
        name: 'Correo electrónico',
        icon: 'mail',
        count: channels['email'],
        color: '#ea4335',
      },
      { id: 'sms', name: 'SMS', icon: 'sms', count: channels['sms'], color: '#6c63ff' },
      {
        id: 'push',
        name: 'Notificación interna',
        icon: 'notifications_active',
        count: channels['push'],
        color: '#ff6b6b',
      },
      {
        id: 'social',
        name: 'Redes sociales',
        icon: 'public',
        count: channels['social'],
        color: '#1f2937',
      },
    ];
  }

  private newId(prefix: string): string {
    const rand = Math.random().toString(16).slice(2, 10);
    return `${prefix}_${Date.now()}_${rand}`;
  }

  private buildMockCampaigns(): Campaign[] {
    const now = new Date().toISOString();
    return [
      {
        id: this.newId('campaign'),
        name: 'Renovación mensual abril',
        type: 'Renovación',
        objective: 'Aumentar renovaciones',
        segment: 'Membresías por vencer',
        channel: 'WhatsApp',
        startDate: '2026-04-01',
        endDate: '2026-04-30',
        status: 'Activa',
        message:
          'Renueva tu membresía este mes y recibe un beneficio especial. Mantén tu acceso a todas nuestras clases y equipos.',
        couponCode: 'RENUEVA10',
        estimatedReach: 48,
        conversions: 12,
        budget: 50000,
        conversionGoal: 20,
        createdAt: now,
        updatedAt: now,
      },
      {
        id: this.newId('campaign'),
        name: 'Reactivación miembros inactivos',
        type: 'Reactivación',
        objective: 'Recuperar miembros inactivos',
        segment: 'Miembros inactivos',
        channel: 'Correo electrónico',
        startDate: '2026-04-15',
        endDate: '2026-04-25',
        status: 'Programada',
        message:
          'Te extrañamos en Iron Body. Vuelve esta semana con un descuento especial y recupera tu forma.',
        couponCode: 'VUELVE15',
        estimatedReach: 32,
        conversions: 5,
        budget: 30000,
        conversionGoal: 10,
        createdAt: now,
        updatedAt: now,
      },
      {
        id: this.newId('campaign'),
        name: 'Referidos VIP',
        type: 'Referidos',
        objective: 'Generar referidos',
        segment: 'Miembros VIP',
        channel: 'WhatsApp',
        startDate: '2026-04-20',
        endDate: '2026-05-05',
        status: 'Borrador',
        message:
          'Invita a un amigo a Iron Body y ambos reciben beneficios exclusivos. Sin límite de referidos.',
        couponCode: 'REFERIDO20',
        estimatedReach: 18,
        conversions: 0,
        budget: 20000,
        conversionGoal: 8,
        createdAt: now,
        updatedAt: now,
      },
    ];
  }

  private buildMockCoupons(): Coupon[] {
    const now = new Date().toISOString();
    return [
      {
        id: this.newId('coupon'),
        name: 'Renovación 10%',
        code: 'RENUEVA10',
        discountType: 'Porcentaje',
        discountValue: 10,
        startDate: '2026-04-01',
        endDate: '2026-04-30',
        usageLimit: 100,
        usedCount: 12,
        status: 'Activo',
        appliesTo: 'Todos los planes',
        createdAt: now,
      },
      {
        id: this.newId('coupon'),
        name: 'Regreso 15%',
        code: 'VUELVE15',
        discountType: 'Porcentaje',
        discountValue: 15,
        startDate: '2026-04-15',
        endDate: '2026-04-25',
        usageLimit: 50,
        usedCount: 5,
        status: 'Activo',
        appliesTo: 'Plan Mensual',
        createdAt: now,
      },
    ];
  }
}
