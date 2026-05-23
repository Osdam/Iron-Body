{{-- Hidden member_id --}}
<input type="hidden" name="member_id" value="{{ $member->id }}">

<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label fw-semibold">Nombre de la rutina <span class="text-danger">*</span></label>
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
    <div class="col-md-5">
        <label class="form-label fw-semibold">Grupo muscular</label>
        <input type="text" name="muscle_group" class="form-control"
               value="{{ old('muscle_group', $routine->muscle_group ?? '') }}"
               placeholder="Ej: Pecho, Piernas, Full body...">
    </div>
    <div class="col-md-3">
        <label class="form-label fw-semibold">Duración estimada (min)</label>
        <input type="number" name="estimated_minutes" class="form-control" min="0" max="1440"
               value="{{ old('estimated_minutes', $routine->estimated_minutes ?? '') }}">
    </div>
    <div class="col-12">
        <label class="form-label fw-semibold">Descripción</label>
        <textarea name="description" class="form-control" rows="2"
                  placeholder="Descripción visible para el cliente...">{{ old('description', $routine->description ?? '') }}</textarea>
    </div>
    <div class="col-12">
        <label class="form-label fw-semibold">Notas internas</label>
        <textarea name="notes" class="form-control" rows="2"
                  placeholder="Notas solo para el entrenador...">{{ old('notes', $routine->notes ?? '') }}</textarea>
    </div>
</div>

{{-- Exercise picker section --}}
<hr class="my-4">

<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="fw-bold mb-0">Ejercicios de la rutina</h6>
    <div class="d-flex gap-2">
        {{-- Filter controls for the modal picker --}}
        <select id="filterMuscle" class="form-select form-select-sm" style="width:auto">
            <option value="">Todos los músculos</option>
            @foreach($exercises->pluck('muscle_group')->filter()->unique()->sort()->values() as $mg)
                <option value="{{ $mg }}">{{ $mg }}</option>
            @endforeach
        </select>
        <input type="text" id="filterSearch" class="form-control form-control-sm" placeholder="Buscar ejercicio..." style="width:180px">
        <button type="button" id="addExercise" class="btn btn-outline-primary btn-sm">+ Agregar ejercicio</button>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-bordered align-middle" id="exerciseTable">
        <thead class="table-dark">
            <tr>
                <th>Ejercicio</th>
                <th style="width:80px">Series</th>
                <th style="width:90px">Reps</th>
                <th style="width:100px">Peso (kg)</th>
                <th>Notas</th>
                <th style="width:50px"></th>
            </tr>
        </thead>
        <tbody id="exerciseRows">
            @isset($routineItems)
                @foreach($routineItems as $item)
                <tr class="exercise-row">
                    <td>
                        <select name="exercise_ids[]" class="form-select form-select-sm exercise-select" required>
                            <option value="">— Seleccionar ejercicio —</option>
                            @foreach($exercises as $ex)
                                <option value="{{ $ex->id }}" @selected($item->exercise_id == $ex->id)>
                                    {{ $ex->name }}{{ $ex->muscle_group ? ' ('.$ex->muscle_group.')' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </td>
                    <td><input type="number" name="sets_list[]" class="form-control form-control-sm" min="1" max="20" value="{{ $item->sets ?? 3 }}"></td>
                    <td><input type="text"   name="reps_list[]" class="form-control form-control-sm" value="{{ $item->reps ?? '10' }}" placeholder="8-12"></td>
                    <td><input type="number" name="weight_list[]" class="form-control form-control-sm" step="0.5" min="0" value="{{ $item->weight ?? '' }}" placeholder="libre"></td>
                    <td><input type="text"   name="ex_notes_list[]" class="form-control form-control-sm" value="{{ $item->notes ?? '' }}"></td>
                    <td><button type="button" class="btn btn-outline-danger btn-sm remove-row">✕</button></td>
                </tr>
                @endforeach
            @endisset
        </tbody>
    </table>
</div>

<p class="text-muted small mt-1 mb-0">
    Las series y reps se pre-rellenan con los valores sugeridos del ejercicio seleccionado.
</p>

{{-- Exercise data for JS --}}
<script id="exerciseData" type="application/json">
@php
    $exData = $exercises->map(fn($ex) => [
        'id'             => $ex->id,
        'name'           => $ex->name,
        'muscle_group'   => $ex->muscle_group ?? $ex->body_part ?? '',
        'suggested_sets' => $ex->suggested_sets ?? 3,
        'suggested_reps' => $ex->suggested_reps ?? '8-12',
    ])->values()->toArray();
    echo json_encode($exData, JSON_UNESCAPED_UNICODE);
@endphp
</script>

<script>
(function () {
    const tbody      = document.getElementById('exerciseRows');
    const addBtn     = document.getElementById('addExercise');
    const filterMuscle = document.getElementById('filterMuscle');
    const filterSearch = document.getElementById('filterSearch');
    const allExercises = JSON.parse(document.getElementById('exerciseData').textContent);

    function filteredExercises() {
        const muscle = filterMuscle.value.toLowerCase();
        const search = filterSearch.value.toLowerCase();
        return allExercises.filter(ex => {
            const matchMuscle = !muscle || (ex.muscle_group || '').toLowerCase() === muscle;
            const matchSearch = !search || ex.name.toLowerCase().includes(search);
            return matchMuscle && matchSearch;
        });
    }

    function buildSelect(preselect) {
        const sel = document.createElement('select');
        sel.name      = 'exercise_ids[]';
        sel.className = 'form-select form-select-sm exercise-select';
        sel.required  = true;
        sel.appendChild(new Option('— Seleccionar ejercicio —', ''));
        filteredExercises().forEach(ex => {
            const label = ex.muscle_group ? `${ex.name} (${ex.muscle_group})` : ex.name;
            const opt   = new Option(label, ex.id);
            if (preselect && ex.id == preselect) opt.selected = true;
            sel.appendChild(opt);
        });
        sel.addEventListener('change', function () {
            const ex  = allExercises.find(e => e.id == this.value);
            if (!ex) return;
            const row = this.closest('tr');
            row.querySelector('[name="sets_list[]"]').value = ex.suggested_sets;
            row.querySelector('[name="reps_list[]"]').value = ex.suggested_reps;
        });
        return sel;
    }

    function makeRow() {
        const tr = document.createElement('tr');
        tr.className = 'exercise-row';
        tr.innerHTML = `
            <td></td>
            <td><input type="number" name="sets_list[]"   class="form-control form-control-sm" min="1" max="20" value="3"></td>
            <td><input type="text"   name="reps_list[]"   class="form-control form-control-sm" value="8-12" placeholder="8-12"></td>
            <td><input type="number" name="weight_list[]" class="form-control form-control-sm" step="0.5" min="0" placeholder="libre"></td>
            <td><input type="text"   name="ex_notes_list[]" class="form-control form-control-sm"></td>
            <td><button type="button" class="btn btn-outline-danger btn-sm remove-row">✕</button></td>
        `;
        tr.querySelector('td').appendChild(buildSelect(null));
        tr.querySelector('.remove-row').addEventListener('click', () => tr.remove());
        return tr;
    }

    addBtn.addEventListener('click', () => tbody.appendChild(makeRow()));

    // Wire existing remove buttons
    tbody.querySelectorAll('.remove-row').forEach(btn =>
        btn.addEventListener('click', () => btn.closest('tr').remove())
    );

    // Wire existing selects for auto-fill
    tbody.querySelectorAll('.exercise-select').forEach(sel => {
        sel.addEventListener('change', function () {
            const ex  = allExercises.find(e => e.id == this.value);
            if (!ex) return;
            const row = this.closest('tr');
            row.querySelector('[name="sets_list[]"]').value = ex.suggested_sets;
            row.querySelector('[name="reps_list[]"]').value = ex.suggested_reps;
        });
    });
})();
</script>
