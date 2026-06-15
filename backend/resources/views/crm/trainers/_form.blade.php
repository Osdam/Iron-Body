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

{{-- ── Portal profesional (aditivo) ──────────────────────────────────────────
     Datos que habilitan el acceso del entrenador al portal: documento (identidad),
     teléfono (OTP), sede y rol. Si se dejan vacíos, el entrenador sigue existiendo
     en el CRM como hoy, pero sin acceso al portal. --}}
@php
    $trainerRoles = isset($trainer) && $trainer ? $trainer->roleNames() : [];
    $selectedRoles = old('roles', $trainerRoles);
@endphp
<hr class="my-4">
<h6 class="fw-bold text-uppercase text-muted small mb-3">Portal profesional</h6>

<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label fw-semibold">Documento</label>
        <input type="text" name="document" class="form-control @error('document') is-invalid @enderror"
               value="{{ old('document', $trainer?->document) }}" maxlength="50"
               placeholder="Identifica a la persona (vincula su identidad)">
        @error('document')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-6">
        <label class="form-label fw-semibold">Teléfono (OTP)</label>
        <input type="text" name="phone" class="form-control @error('phone') is-invalid @enderror"
               value="{{ old('phone', $trainer?->phone) }}" maxlength="30"
               placeholder="+57 300 000 0000">
        <div class="form-text">El código de acceso al portal se envía a este número.</div>
        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-6">
        <label class="form-label fw-semibold">Sede</label>
        <input type="text" name="location" class="form-control @error('location') is-invalid @enderror"
               value="{{ old('location', $trainer?->location) }}" maxlength="120"
               placeholder="Ej: Sede Norte">
        @error('location')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-6">
        <label class="form-label fw-semibold d-block">Rol profesional</label>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="roles[]" value="trainer_floor"
                   id="roleFloor" {{ in_array('trainer_floor', $selectedRoles) ? 'checked' : '' }}>
            <label class="form-check-label" for="roleFloor">Planta</label>
        </div>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="roles[]" value="trainer_functional"
                   id="roleFunctional" {{ in_array('trainer_functional', $selectedRoles) ? 'checked' : '' }}>
            <label class="form-check-label" for="roleFunctional">Funcional</label>
        </div>
        <div class="form-text">Habilita el acceso al portal. Sin rol, no puede ingresar.</div>
    </div>

    @if(isset($trainer) && $trainer)
        <div class="col-12">
            <div class="d-flex flex-wrap gap-2 small">
                <span class="badge {{ $trainer->identity_id ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">
                    Identidad: {{ $trainer->identity_id ? 'vinculada' : 'pendiente' }}
                </span>
                <span class="badge bg-light text-dark border">
                    Sesiones activas: {{ $trainer->active_sessions_count ?? 0 }}
                </span>
            </div>
        </div>
    @endif
</div>
