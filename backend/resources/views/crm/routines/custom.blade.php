@extends('crm.layout')

@section('title', 'Rutinas de la App')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0 fw-bold">Rutinas personalizadas (creadas en la app)</h4>
    <a href="{{ route('crm.routines.index') }}" class="btn btn-outline-secondary btn-sm">← Rutinas globales</a>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Miembro ID</th>
                    <th>Nivel</th>
                    <th class="text-center">Ejercicios</th>
                    <th>Creada</th>
                </tr>
            </thead>
            <tbody>
                @forelse($routines as $routine)
                <tr>
                    <td class="fw-semibold">{{ $routine->name }}</td>
                    <td class="text-muted small">{{ $routine->member_id ?? '—' }}</td>
                    <td>
                        @if($routine->level)
                            <span class="badge bg-secondary">{{ $routine->level }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-center">{{ $routine->routineExercises->count() }}</td>
                    <td class="text-muted small">{{ $routine->created_at->format('d/m/Y') }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        Ningún miembro ha creado rutinas personalizadas aún.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">{{ $routines->links() }}</div>
@endsection
