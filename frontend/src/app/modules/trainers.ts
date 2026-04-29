import { Component } from '@angular/core';

@Component({
  selector: 'module-trainers',
  standalone: true,
  template: `
  <div>
    <h1 class="text-2xl font-semibold mb-4">Entrenadores</h1>
    <p class="text-slate-300">Perfiles de entrenadores, disponibilidad y asignaciones.</p>
  </div>
  `
})
export default class TrainersModule {}
