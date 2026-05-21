<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label fw-semibold">Nombre <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
               value="{{ old('name', $routine->name ?? '') }}" required>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label fw-semibold">Nivel</label>
        <select name="level" class="form-select">
            <option value="">— Seleccionar —</option>
            @foreach(['Principiante','Intermedio','Avanzado'] as $l)
                <option value="{{ $l }}" @selected(old('level', $routine->level ?? '') === $l)>{{ $l }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold">Objetivo</label>
        <input type="text" name="objective" class="form-control"
               value="{{ old('objective', $routine->objective ?? '') }}"
               placeholder="Ej: Pérdida de peso, Hipertrofia...">
    </div>
    <div class="col-md-3">
        <label class="form-label fw-semibold">Grupo muscular</label>
        <input type="text" name="muscle_group" class="form-control"
               value="{{ old('muscle_group', $routine->muscle_group ?? '') }}"
               placeholder="Ej: Pecho, Piernas...">
    </div>
    <div class="col-md-3">
        <label class="form-label fw-semibold">Duración estimada (min)</label>
        <input type="number" name="estimated_minutes" class="form-control" min="0" max="1440"
               value="{{ old('estimated_minutes', $routine->estimated_minutes ?? '') }}">
    </div>
    <div class="col-md-2">
        <label class="form-label fw-semibold">Días/semana</label>
        <input type="number" name="days_per_week" class="form-control" min="0" max="7"
               value="{{ old('days_per_week', $routine->days_per_week ?? '') }}">
    </div>
    <div class="col-md-5">
        <label class="form-label fw-semibold">Descripción</label>
        <textarea name="description" class="form-control" rows="3"
                  placeholder="Descripción general de la rutina...">{{ old('description', $routine->description ?? '') }}</textarea>
    </div>
    <div class="col-md-5">
        <label class="form-label fw-semibold">Notas internas</label>
        <textarea name="notes" class="form-control" rows="3"
                  placeholder="Notas para el entrenador...">{{ old('notes', $routine->notes ?? '') }}</textarea>
    </div>
</div>

{{-- Asignación a miembro --}}
<hr class="my-4">
<h6 class="fw-bold mb-3">Asignar a un miembro <span class="text-muted fw-normal small">(opcional)</span></h6>
<div class="row g-2 align-items-start mb-2">
    <div class="col-md-6">
        <label class="form-label fw-semibold">Documento o nombre del miembro</label>
        <input type="text" name="member_document" class="form-control @error('member_document') is-invalid @enderror"
               value="{{ old('member_document') }}"
               placeholder="Ej: 1234567890 o Juan Pérez">
        @error('member_document')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div class="form-text">Si dejas este campo vacío, la rutina quedará como plantilla global (sin asignar).</div>
    </div>
</div>

{{-- Asignaciones actuales (solo en edit) --}}
@isset($assignments)
    @if($assignments->count() > 0)
    <div class="mb-3">
        <p class="small fw-semibold text-muted mb-1">Actualmente asignada a:</p>
        <div class="d-flex flex-wrap gap-2">
            @foreach($assignments as $a)
                <span class="badge bg-success fs-6 fw-normal px-3 py-2">
                    {{ $a->member?->full_name ?? "Miembro #{$a->member_id}" }}
                </span>
            @endforeach
        </div>
    </div>
    @endif
@endisset

{{-- Selección de ejercicios --}}
<hr class="my-4">
<h6 class="fw-bold mb-3">Ejercicios de la rutina</h6>

<div class="table-responsive">
    <table class="table table-bordered align-middle" id="exerciseTable">
        <thead class="table-light">
            <tr>
                <th>Ejercicio</th>
                <th style="width:80px">Series</th>
                <th style="width:80px">Reps</th>
                <th style="width:110px">Peso</th>
                <th>Notas</th>
                <th style="width:50px"></th>
            </tr>
        </thead>
        <tbody id="exerciseRows">
            @isset($routineItems)
                @foreach($routineItems as $item)
                <tr class="exercise-row">
                    <td>
                        <select name="exercise_ids[]" class="form-select form-select-sm" required>
                            <option value="">— Seleccionar ejercicio —</option>
                            @foreach($exercises as $ex)
                                <option value="{{ $ex->id }}" @selected($item->exercise_id == $ex->id)>
                                    {{ $ex->name }}{{ ($ex->muscle_group || $ex->body_part) ? ' (' . ($ex->muscle_group ?? $ex->body_part) . ')' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </td>
                    <td><input type="number" name="sets_list[]" class="form-control form-control-sm" min="1" max="20" value="{{ $item->sets ?? 3 }}"></td>
                    <td><input type="number" name="reps_list[]" class="form-control form-control-sm" min="1" max="200" value="{{ $item->reps ?? 10 }}"></td>
                    <td><input type="text"   name="weight_list[]" class="form-control form-control-sm" value="{{ $item->weight ?? '' }}" placeholder="kg / libre"></td>
                    <td><input type="text"   name="ex_notes_list[]" class="form-control form-control-sm" value="{{ $item->notes ?? '' }}"></td>
                    <td><button type="button" class="btn btn-outline-danger btn-sm remove-row">✕</button></td>
                </tr>
                @endforeach
            @endisset
        </tbody>
    </table>
</div>
<button type="button" id="addExercise" class="btn btn-outline-secondary btn-sm mt-1">+ Agregar ejercicio</button>

{{-- Opciones de ejercicios para el template JS --}}
<script id="exerciseOptions" type="application/json">
@php
    $opts = $exercises->map(fn ($ex) => [
        'id'   => $ex->id,
        'name' => $ex->name . (($ex->muscle_group || $ex->body_part) ? ' (' . ($ex->muscle_group ?? $ex->body_part) . ')' : ''),
    ])->values()->toArray();
    echo json_encode($opts, JSON_UNESCAPED_UNICODE);
@endphp
</script>

<script>
(function () {
    const tbody   = document.getElementById('exerciseRows');
    const addBtn  = document.getElementById('addExercise');
    const options = JSON.parse(document.getElementById('exerciseOptions').textContent);

    function buildSelect() {
        const sel = document.createElement('select');
        sel.name  = 'exercise_ids[]';
        sel.className = 'form-select form-select-sm';
        sel.required  = true;
        const blank = new Option('— Seleccionar ejercicio —', '');
        sel.appendChild(blank);
        options.forEach(({ id, name }) => sel.appendChild(new Option(name, id)));
        return sel;
    }

    function makeRow() {
        const tr = document.createElement('tr');
        tr.className = 'exercise-row';
        tr.innerHTML = `
            <td></td>
            <td><input type="number" name="sets_list[]"     class="form-control form-control-sm" min="1" max="20"  value="3"></td>
            <td><input type="number" name="reps_list[]"     class="form-control form-control-sm" min="1" max="200" value="10"></td>
            <td><input type="text"   name="weight_list[]"   class="form-control form-control-sm" placeholder="kg / libre"></td>
            <td><input type="text"   name="ex_notes_list[]" class="form-control form-control-sm"></td>
            <td><button type="button" class="btn btn-outline-danger btn-sm remove-row">✕</button></td>
        `;
        tr.querySelector('td').appendChild(buildSelect());
        tr.querySelector('.remove-row').addEventListener('click', () => tr.remove());
        return tr;
    }

    addBtn.addEventListener('click', () => tbody.appendChild(makeRow()));

    tbody.querySelectorAll('.remove-row').forEach(btn =>
        btn.addEventListener('click', () => btn.closest('tr').remove())
    );
})();
</script>
