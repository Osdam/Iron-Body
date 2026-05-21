@extends('crm.layout')

@section('title', 'Catálogo de Ejercicios')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0 fw-bold">Catálogo de Ejercicios</h4>
    <a href="{{ route('crm.exercises.create') }}" class="btn btn-success btn-sm">+ Nuevo ejercicio</a>
</div>

<form class="row g-2 mb-3" method="GET">
    <div class="col-md-5">
        <input type="text" name="search" class="form-control form-control-sm"
               placeholder="Buscar por nombre o grupo muscular..."
               value="{{ request('search') }}">
    </div>
    <div class="col-md-3">
        <select name="difficulty" class="form-select form-select-sm">
            <option value="">Todos los niveles</option>
            @foreach(['Principiante','Intermedio','Avanzado'] as $d)
                <option value="{{ $d }}" @selected(request('difficulty') === $d)>{{ $d }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-primary btn-sm">Filtrar</button>
        <a href="{{ route('crm.exercises.index') }}" class="btn btn-outline-secondary btn-sm">Limpiar</a>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="width:60px"></th>
                    <th>Nombre</th>
                    <th>Grupo muscular</th>
                    <th>Dificultad</th>
                    <th>Equipo</th>
                    <th>Proveedor</th>
                    <th style="width:130px">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($exercises as $exercise)
                <tr>
                    <td>
                        @if($exercise->thumbnail_url || $exercise->gif_url)
                            <img src="{{ $exercise->thumbnail_url ?? $exercise->gif_url }}"
                                 alt="{{ $exercise->name }}"
                                 style="width:48px;height:48px;object-fit:cover;border-radius:4px">
                        @else
                            <div class="bg-secondary rounded d-flex align-items-center justify-content-center"
                                 style="width:48px;height:48px;color:#fff;font-size:1.2rem">💪</div>
                        @endif
                    </td>
                    <td class="fw-semibold">{{ $exercise->name }}</td>
                    <td>{{ $exercise->muscle_group ?? $exercise->body_part ?? '—' }}</td>
                    <td>
                        @if($exercise->difficulty)
                            <span class="badge bg-secondary">{{ $exercise->difficulty }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-muted small">{{ $exercise->equipment ?? '—' }}</td>
                    <td class="text-muted small">{{ $exercise->provider ?? '—' }}</td>
                    <td>
                        <a href="{{ route('crm.exercises.edit', $exercise) }}"
                           class="btn btn-outline-primary btn-sm">Editar</a>
                        <form method="POST" action="{{ route('crm.exercises.destroy', $exercise) }}"
                              class="d-inline"
                              onsubmit="return confirm('¿Eliminar este ejercicio?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-outline-danger btn-sm">Eliminar</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No hay ejercicios registrados.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">{{ $exercises->links() }}</div>
@endsection
