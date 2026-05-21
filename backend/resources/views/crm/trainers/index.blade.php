@extends('crm.layout')

@section('title', 'Entrenadores')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0 fw-bold">Entrenadores</h4>
    <a href="{{ route('crm.trainers.create') }}" class="btn btn-dark btn-sm">+ Nuevo entrenador</a>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nombre</th>
                    <th>Especialidad</th>
                    <th class="text-center">Años exp.</th>
                    <th class="text-center">Estudiantes</th>
                    <th class="text-center">Promedio ★</th>
                    <th class="text-center">N° Calif.</th>
                    <th class="text-center">Estado</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($trainers as $i => $trainer)
                <tr>
                    <td class="text-muted small">{{ $i + 1 }}</td>
                    <td class="fw-semibold">{{ $trainer->full_name }}</td>
                    <td>{{ $trainer->main_specialty ?? '—' }}</td>
                    <td class="text-center">{{ $trainer->experience_years }}</td>
                    <td class="text-center">{{ number_format($trainer->assigned_members ?? 0) }}</td>
                    <td class="text-center fw-bold {{ ($trainer->reviews_avg_rating ?? 0) >= 4 ? 'text-success' : '' }}">
                        {{ $trainer->reviews_avg_rating ? number_format($trainer->reviews_avg_rating, 1) : '—' }}
                    </td>
                    <td class="text-center">{{ $trainer->reviews_count ?? 0 }}</td>
                    <td class="text-center">
                        @if($trainer->isActive())
                            <span class="badge badge-active">Activo</span>
                        @else
                            <span class="badge badge-inactive">Inactivo</span>
                        @endif
                    </td>
                    <td class="text-center">
                        <a href="{{ route('crm.trainers.ratings', $trainer) }}"
                           class="btn btn-outline-secondary btn-sm" title="Ver calificaciones">★</a>
                        <a href="{{ route('crm.trainers.edit', $trainer) }}"
                           class="btn btn-outline-primary btn-sm" title="Editar">✎</a>
                        <form method="POST" action="{{ route('crm.trainers.destroy', $trainer) }}"
                              class="d-inline"
                              onsubmit="return confirm('¿Desactivar a {{ addslashes($trainer->full_name) }}?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Desactivar">✕</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">No hay entrenadores registrados.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
