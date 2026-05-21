@if($errors->any())
    <div class="alert alert-danger py-2 small">
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label fw-semibold">Nombre <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
               value="{{ old('name', $trainer?->full_name) }}" required maxlength="120">
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-8">
        <label class="form-label fw-semibold">Especialidad <span class="text-danger">*</span></label>
        <input type="text" name="specialty" class="form-control @error('specialty') is-invalid @enderror"
               value="{{ old('specialty', $trainer?->main_specialty) }}" required maxlength="120"
               placeholder="Ej: Spinning & Cardio">
        @error('specialty')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold">Años de experiencia</label>
        <input type="number" name="experience_years" class="form-control @error('experience_years') is-invalid @enderror"
               value="{{ old('experience_years', $trainer?->experience_years ?? 0) }}" min="0" max="80">
        @error('experience_years')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold">N° Estudiantes</label>
        <input type="number" name="student_count" class="form-control @error('student_count') is-invalid @enderror"
               value="{{ old('student_count', $trainer?->assigned_members ?? 0) }}" min="0">
        @error('student_count')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <label class="form-label fw-semibold">Biografía</label>
        <textarea name="bio" class="form-control @error('bio') is-invalid @enderror"
                  rows="3">{{ old('bio', $trainer?->bio) }}</textarea>
        @error('bio')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-8">
        <label class="form-label fw-semibold">URL de foto</label>
        <input type="text" name="photo_url" class="form-control @error('photo_url') is-invalid @enderror"
               value="{{ old('photo_url', $trainer?->avatar_url) }}" maxlength="500"
               placeholder="https://...">
        @error('photo_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                   id="isActive" {{ old('is_active', $trainer ? $trainer->isActive() : true) ? 'checked' : '' }}>
            <label class="form-check-label fw-semibold" for="isActive">Entrenador activo</label>
        </div>
    </div>
</div>
