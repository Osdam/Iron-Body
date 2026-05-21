import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, Output } from '@angular/core';

export interface ChannelMetric {
  id: string;
  name: string;
  icon: string;
  count: number;
  color: string;
}

@Component({
  selector: 'app-marketing-channels',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div class="channels-card">
      <div class="channels-header">
        <h3 class="channels-title">Canales de comunicación</h3>
        <a href="#" class="channels-see-all">Ver todos</a>
      </div>

      <div class="channels-list">
        <button
          *ngFor="let channel of channels"
          type="button"
          class="channel-item"
          (click)="selectChannel(channel)"
        >
          <div class="channel-icon" [ngClass]="'icon-' + channel.id">
            <span class="material-symbols-outlined">{{ channel.icon }}</span>
          </div>
          <div class="channel-info">
            <p class="channel-name">{{ channel.name }}</p>
            <p class="channel-count">{{ channel.count }} mensajes</p>
          </div>
          <span class="material-symbols-outlined channel-chevron">chevron_right</span>
        </button>
      </div>
    </div>
  `,
  styles: [
    `
      .channels-card {
        border: 1px solid #ededed;
        border-radius: 16px;
        background: #ffffff;
        padding: 1.4rem;
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.04);
      }

      .channels-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.2rem;
      }

      .channels-title {
        font-size: 1.1rem;
        font-weight: 800;
        color: #0a0a0a;
        margin: 0;
      }

      .channels-see-all {
        font-size: 0.85rem;
        font-weight: 700;
        color: #fbbf24;
        text-decoration: none;
        transition: color 0.15s ease;
      }

      .channels-see-all:hover {
        color: #f9a825;
      }

      .channels-list {
        display: flex;
        flex-direction: column;
        gap: 0;
      }

      .channel-item {
        display: flex;
        align-items: center;
        gap: 0.9rem;
        padding: 1rem;
        border: none;
        border-bottom: 1px solid #f3f3f3;
        background: transparent;
        cursor: pointer;
        transition: all 0.15s ease;
      }

      .channel-item:last-child {
        border-bottom: none;
      }

      .channel-item:hover {
        background: #fafafa;
      }

      .channel-icon {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        display: grid;
        place-items: center;
        font-weight: 700;
        color: #ffffff;
      }

      .icon-whatsapp {
        background: #25d366;
      }

      .icon-email {
        background: #ea4335;
      }

      .icon-sms {
        background: #6c63ff;
      }

      .icon-push {
        background: #ff6b6b;
      }

      .icon-social {
        background: #1f2937;
      }

      .channel-info {
        flex: 1;
        text-align: left;
      }

      .channel-name {
        font-size: 0.92rem;
        font-weight: 700;
        color: #0a0a0a;
        margin: 0;
      }

      .channel-count {
        font-size: 0.8rem;
        color: #999;
        margin: 0.2rem 0 0;
      }

      .channel-chevron {
        font-size: 1.2rem;
        color: #ccc;
        transition: transform 0.15s ease;
      }

      .channel-item:hover .channel-chevron {
        transform: translateX(2px);
        color: #fbbf24;
      }

      .channels-card {
        background:
          linear-gradient(rgba(28, 27, 27, 0.92), rgba(17, 17, 17, 0.9)),
          url('/assets/crm/clases2.png') center / cover no-repeat;
        border-color: #353534;
        color: #e5e2e1;
        box-shadow: 0 14px 34px rgba(0, 0, 0, 0.24);
      }

      .channels-title,
      .channel-name {
        color: #e5e2e1;
      }

      .channel-count,
      .channel-chevron {
        color: #b4afa6;
      }

      .channel-item {
        border-color: #353534;
        color: #e5e2e1;
      }

      .channel-item:hover {
        background: rgba(245, 197, 24, 0.08);
      }
    `,
  ],
})
export default class MarketingChannelsComponent {
  @Input() channels: ChannelMetric[] = [
    { id: 'whatsapp', name: 'WhatsApp', icon: 'message', count: 420, color: '#25d366' },
    { id: 'email', name: 'Correo electrónico', icon: 'mail', count: 180, color: '#ea4335' },
    { id: 'sms', name: 'SMS', icon: 'sms', count: 75, color: '#6c63ff' },
    {
      id: 'push',
      name: 'Notificación interna',
      icon: 'notifications_active',
      count: 95,
      color: '#ff6b6b',
    },
    { id: 'social', name: 'Redes sociales', icon: 'public', count: 8, color: '#1f2937' },
  ];

  @Output() channelSelected = new EventEmitter<ChannelMetric>();

  selectChannel(channel: ChannelMetric): void {
    this.channelSelected.emit(channel);
  }
}
