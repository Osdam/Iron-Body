import { Component } from '@angular/core';

@Component({
  selector: 'module-classes',
  standalone: true,
  template: `
  <div>
    <h1 class="text-2xl font-semibold mb-4">Clases</h1>
    <p class="text-slate-300">Gestión de horarios, inscripciones y cupos de clases.</p>
  </div>
  `
})
export default class ClassesModule {}
