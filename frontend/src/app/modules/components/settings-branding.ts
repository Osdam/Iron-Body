import { Component, Input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

interface BrandingSettings {
  logoUrl: string;
  sidebarLogoUrl: string;
  bannerUrl: string;
  primaryColor: string;
  secondaryColor: string;
}

@Component({
  selector: 'app-settings-branding',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="settings-section">
      <div class="section-header">
        <div class="section-title">
          <span class="material-symbols-outlined">palette</span>
          <div>
            <h2>Identidad visual</h2>
            <p>Logo, colores y marca del CRM</p>
          </div>
        </div>
      </div>

      <div class="branding-grid">
        <div class="upload-area">
          <label>Logo principal</label>
          <div class="upload-box" (click)="triggerUpload('primary')">
            <span class="material-symbols-outlined">image_search</span>
            <p>Click para cargar logo principal</p>
            <span class="hint">PNG, JPG | Max 2MB</span>
          </div>
          <input
            type="file"
            hidden
            #primaryUpload
            accept="image/*"
            (change)="onLogoUpload($event, 'primary')"
          />
          <div *ngIf="logoUrl" class="logo-preview">
            <img [src]="logoUrl" alt="Logo preview" />
            <button type="button" (click)="removeLogo('primary')">Eliminar</button>
          </div>
        </div>

        <div class="upload-area">
          <label>Logo para sidebar</label>
          <div class="upload-box" (click)="triggerUpload('sidebar')">
            <span class="material-symbols-outlined">image_search</span>
            <p>Click para cargar logo sidebar</p>
            <span class="hint">PNG, JPG | Max 2MB</span>
          </div>
          <input
            type="file"
            hidden
            #sidebarUpload
            accept="image/*"
            (change)="onLogoUpload($event, 'sidebar')"
          />
          <div *ngIf="sidebarLogoUrl" class="logo-preview">
            <img [src]="sidebarLogoUrl" alt="Sidebar logo preview" />
            <button type="button" (click)="removeLogo('sidebar')">Eliminar</button>
          </div>
        </div>
      </div>

      <div class="colors-grid">
        <div class="color-picker-group">
          <label for="primaryColor">Color principal</label>
          <div class="color-input-wrapper">
            <input
              type="color"
              id="primaryColor"
              [(ngModel)]="primaryColor"
              (ngModelChange)="onColorChange('primary', $event)"
            />
            <input
              type="text"
              [(ngModel)]="primaryColor"
              (ngModelChange)="onColorChange('primary', $event)"
              placeholder="#fbbf24"
            />
          </div>
        </div>

        <div class="color-picker-group">
          <label for="secondaryColor">Color secundario</label>
          <div class="color-input-wrapper">
            <input
              type="color"
              id="secondaryColor"
              [(ngModel)]="secondaryColor"
              (ngModelChange)="onColorChange('secondary', $event)"
            />
            <input
              type="text"
              [(ngModel)]="secondaryColor"
              (ngModelChange)="onColorChange('secondary', $event)"
              placeholder="#1f2937"
            />
          </div>
        </div>
      </div>

      <div class="preview-section">
        <h3>Vista previa</h3>
        <div class="preview-grid">
          <div class="preview-box">
            <span class="preview-label">Botón principal</span>
            <button [style.background-color]="primaryColor" class="preview-btn">
              Guardar cambios
            </button>
          </div>
          <div class="preview-box">
            <span class="preview-label">KPI Card</span>
            <div class="preview-kpi" [style.border-left-color]="primaryColor">
              <div class="preview-kpi-content">
                <span class="preview-kpi-value">1,234</span>
                <span class="preview-kpi-label">Miembros activos</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="info-box">
        <span class="material-symbols-outlined">info</span>
        <p>
          Los colores por defecto quedaron intactos. Si deseas personalizarlos, podrás hacerlo aquí.
        </p>
      </div>
    </div>
  `,
  styles: [
    `
      .settings-section {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 0.75rem;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
      }

      .section-header {
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #f3f4f6;
      }

      .section-title {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
      }

      .section-title .material-symbols-outlined {
        font-size: 1.5rem;
        color: #fbbf24;
        margin-top: 0.25rem;
      }

      .section-title h2 {
        font-size: 1.125rem;
        font-weight: 600;
        color: #0a0a0a;
        margin: 0;
      }

      .section-title p {
        font-size: 0.875rem;
        color: #6b7280;
        margin: 0.25rem 0 0 0;
      }

      .branding-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
      }

      .upload-area {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
      }

      .upload-area > label {
        font-size: 0.875rem;
        font-weight: 500;
        color: #0a0a0a;
      }

      .upload-box {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 2rem;
        border: 2px dashed #d1d5db;
        border-radius: 0.75rem;
        cursor: pointer;
        transition: all 0.2s;
        background: #f9fafb;
      }

      .upload-box:hover {
        background: #f3f4f6;
        border-color: #fbbf24;
      }

      .upload-box .material-symbols-outlined {
        font-size: 2.5rem;
        color: #9ca3af;
        margin-bottom: 0.5rem;
      }

      .upload-box p {
        margin: 0.5rem 0 0 0;
        font-size: 0.875rem;
        font-weight: 500;
        color: #0a0a0a;
      }

      .upload-box .hint {
        font-size: 0.75rem;
        color: #9ca3af;
        margin-top: 0.25rem;
      }

      .logo-preview {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.75rem;
        padding: 1rem;
        background: #f3f4f6;
        border-radius: 0.5rem;
        margin-top: 0.75rem;
      }

      .logo-preview img {
        max-width: 100%;
        max-height: 120px;
        border-radius: 0.5rem;
      }

      .logo-preview button {
        padding: 0.5rem 1rem;
        background: #ef4444;
        color: #ffffff;
        border: none;
        border-radius: 0.5rem;
        font-size: 0.75rem;
        cursor: pointer;
        transition: all 0.2s;
      }

      .logo-preview button:hover {
        background: #dc2626;
      }

      .colors-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
      }

      .color-picker-group {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
      }

      .color-picker-group label {
        font-size: 0.875rem;
        font-weight: 500;
        color: #0a0a0a;
      }

      .color-input-wrapper {
        display: flex;
        gap: 0.75rem;
        align-items: center;
      }

      input[type='color'] {
        width: 60px;
        height: 40px;
        border: 1px solid #d1d5db;
        border-radius: 0.5rem;
        cursor: pointer;
      }

      input[type='text'] {
        flex: 1;
        padding: 0.625rem 0.75rem;
        border: 1px solid #d1d5db;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-family: monospace;
      }

      input[type='text']:focus {
        outline: none;
        border-color: #fbbf24;
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.1);
      }

      .preview-section {
        margin-bottom: 1.5rem;
        padding: 1rem;
        background: #f9fafb;
        border-radius: 0.75rem;
      }

      .preview-section h3 {
        margin: 0 0 1rem 0;
        font-size: 0.875rem;
        font-weight: 600;
        color: #0a0a0a;
      }

      .preview-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
      }

      .preview-box {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        padding: 1rem;
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
      }

      .preview-label {
        font-size: 0.75rem;
        font-weight: 600;
        color: #6b7280;
        text-transform: uppercase;
      }

      .preview-btn {
        padding: 0.625rem 1rem;
        color: #ffffff;
        border: none;
        border-radius: 0.5rem;
        font-weight: 500;
        font-size: 0.875rem;
        cursor: pointer;
      }

      .preview-kpi {
        border-left: 4px solid;
        padding: 0.75rem;
        background: #f9fafb;
        border-radius: 0.375rem;
      }

      .preview-kpi-content {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
      }

      .preview-kpi-value {
        font-weight: 600;
        font-size: 0.875rem;
        color: #0a0a0a;
      }

      .preview-kpi-label {
        font-size: 0.75rem;
        color: #6b7280;
      }

      .info-box {
        display: flex;
        gap: 1rem;
        align-items: flex-start;
        padding: 1rem;
        background: #fef3c7;
        border: 1px solid #fcd34d;
        border-radius: 0.5rem;
      }

      .info-box .material-symbols-outlined {
        color: #92400e;
        margin-top: 0.125rem;
        flex-shrink: 0;
      }

      .info-box p {
        margin: 0;
        font-size: 0.875rem;
        color: #78350f;
      }

      @media (max-width: 768px) {
        .branding-grid {
          grid-template-columns: 1fr;
        }

        .colors-grid {
          grid-template-columns: 1fr;
        }
      }
    `,
  ],
})
export default class SettingsBrandingComponent {
  @Output() settingsChange = new EventEmitter<Partial<BrandingSettings>>();

  logoUrl = '';
  sidebarLogoUrl = '';
  primaryColor = '#fbbf24';
  secondaryColor = '#1f2937';

  triggerUpload(type: 'primary' | 'sidebar'): void {
    const input =
      type === 'primary'
        ? document.querySelector('input[#primaryUpload]')
        : document.querySelector('input[#sidebarUpload]');
    (input as HTMLInputElement)?.click();
  }

  onLogoUpload(event: Event, type: 'primary' | 'sidebar'): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (file) {
      const reader = new FileReader();
      reader.onload = (e) => {
        const image = e.target?.result as string;
        if (type === 'primary') {
          this.logoUrl = image;
        } else {
          this.sidebarLogoUrl = image;
        }
        this.settingsChange.emit({
          logoUrl: this.logoUrl,
          sidebarLogoUrl: this.sidebarLogoUrl,
        });
      };
      reader.readAsDataURL(file);
    }
  }

  removeLogo(type: 'primary' | 'sidebar'): void {
    if (type === 'primary') {
      this.logoUrl = '';
    } else {
      this.sidebarLogoUrl = '';
    }
    this.settingsChange.emit({
      logoUrl: this.logoUrl,
      sidebarLogoUrl: this.sidebarLogoUrl,
    });
  }

  onColorChange(type: 'primary' | 'secondary', value: string): void {
    this.settingsChange.emit({
      primaryColor: type === 'primary' ? value : this.primaryColor,
      secondaryColor: type === 'secondary' ? value : this.secondaryColor,
    });
  }
}
