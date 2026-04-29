import { Component } from '@angular/core';

@Component({
  selector: 'module-settings',
  standalone: true,
  template: `
  <div>
    <h1 class="text-2xl font-semibold mb-4">Configuración</h1>
    <p class="text-slate-300">Ajustes globales del sistema.</p>
  </div>
  `
})
export default class SettingsModule {}
