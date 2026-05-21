@extends('crm.layout')

@section('title', 'Rutinas Globales')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0 fw-bold">Rutinas Globales</h4>
    <a href="{{ route('crm.routines.create') }}" class="btn btn-success btn-sm">+ Nueva rutina</a>
</div>

<form class="row g-2 mb-3" method="GET">
    <div class="col-md-5">
        <input type="text" name="search" class="form-control form-control-sm"
               placeholder="Buscar por nombre u objetivo..."
               value="{{ request('search') }}">
    </div>
    <div class="col-md-3">
        <select name="level" class="form-select form-select-sm">
            <option value="">Todos los niveles</option>
            @foreach(['Principiante','Intermedio','Avanzado'] as $l)
                <option value="{{ $l }}" @selected(request('level') === $l)>{{ $l }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-primary btn-sm">Filtrar</button>
        <a href="{{ route('crm.routines.index') }}" class="btn btn-outline-secondary btn-sm">Limpiar</a>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Objetivo</th>
                    <th>Nivel</th>
                    <th>Grupo muscular</th>
                    <th class="text-center">Ejercicios</th>
                    <th class="text-center">Min.</th>
                    <th style="width:180px">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($routines as $routine)
                <tr>
                    <td class="fw-semibold">{{ $routine->name }}</td>
                    <td class="text-muted small">{{ $routine->objective ?? '—' }}</td>
                    <td>
                        @if($routine->level)
                            <span class="badge bg-secondary">{{ $routine->level }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-muted small">{{ $routine->muscle_group ?? '—' }}</td>
                    <td class="text-center">{{ $routine->routine_exercises_count }}</td>
                    <td class="text-center text-muted small">{{ $routine->estimated_minutes ?: '—' }}</td>
                    <td>
                        <a href="{{ route('crm.routines.edit', $routine) }}"
                           class="btn btn-outline-primary btn-sm">Editar</a>
                        <form method="POST" action="{{ route('crm.routines.destroy', $routine) }}"
                              class="d-inline"
                              onsubmit="return confirm('¿Eliminar esta rutina?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-outline-danger btn-sm">Eliminar</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No hay rutinas creadas aún.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">{{ $routines->links() }}</div>
@endsection
