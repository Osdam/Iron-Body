<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label fw-semibold">Nombre <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
               value="{{ old('name', $exercise->name ?? '') }}" required>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label fw-semibold">Dificultad</label>
        <select name="difficulty" class="form-select">
            <option value="">— Seleccionar —</option>
            @foreach(['Principiante','Intermedio','Avanzado'] as $d)
                <option value="{{ $d }}" @selected(old('difficulty', $exercise->difficulty ?? '') === $d)>{{ $d }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold">Grupo muscular</label>
        <input type="text" name="muscle_group" class="form-control"
               value="{{ old('muscle_group', $exercise->muscle_group ?? '') }}"
               placeholder="Ej: Pectorales, Bíceps...">
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold">Parte del cuerpo</label>
        <input type="text" name="body_part" class="form-control"
               value="{{ old('body_part', $exercise->body_part ?? '') }}"
               placeholder="Ej: upper arms, chest...">
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold">Equipo / Material</label>
        <input type="text" name="equipment" class="form-control"
               value="{{ old('equipment', $exercise->equipment ?? '') }}"
               placeholder="Ej: Mancuernas, Barra, Peso corporal...">
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold">URL GIF / Imagen</label>
        <input type="url" name="gif_url" class="form-control"
               value="{{ old('gif_url', $exercise->gif_url ?? '') }}"
               placeholder="https://...">
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold">URL Miniatura</label>
        <input type="url" name="thumbnail_url" class="form-control"
               value="{{ old('thumbnail_url', $exercise->thumbnail_url ?? '') }}"
               placeholder="https://...">
    </div>
    <div class="col-12">
        <label class="form-label fw-semibold">Descripción</label>
        <textarea name="description" class="form-control" rows="3"
                  placeholder="Descripción general del ejercicio...">{{ old('description', $exercise->description ?? '') }}</textarea>
    </div>
    <div class="col-md-4">
        <label class="form-label fw-semibold">Pasos (uno por línea)</label>
        <textarea name="steps" class="form-control" rows="5"
                  placeholder="Paso 1&#10;Paso 2&#10;Paso 3">{{ old('steps', isset($exercise) ? implode("\n", $exercise->steps ?? []) : '') }}</textarea>
    </div>
    <div class="col-md-4">
        <label class="form-label fw-semibold">Consejos (uno por línea)</label>
        <textarea name="tips" class="form-control" rows="5"
                  placeholder="Consejo 1&#10;Consejo 2">{{ old('tips', isset($exercise) ? implode("\n", $exercise->tips ?? []) : '') }}</textarea>
    </div>
    <div class="col-md-4">
        <label class="form-label fw-semibold">Músculos trabajados (uno por línea)</label>
        <textarea name="muscles_worked" class="form-control" rows="5"
                  placeholder="Pectoral mayor&#10;Tríceps">{{ old('muscles_worked', isset($exercise) ? implode("\n", $exercise->muscles_worked ?? []) : '') }}</textarea>
    </div>
</div>
