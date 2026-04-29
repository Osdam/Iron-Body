import { Component } from '@angular/core';

@Component({
  selector: 'module-marketing',
  standalone: true,
  template: `
  <div>
    <h1 class="text-2xl font-semibold mb-4">Mercadeo</h1>
    <p class="text-slate-300">Campañas, cupones y comunicaciones.</p>
  </div>
  `
})
export default class MarketingModule {}
