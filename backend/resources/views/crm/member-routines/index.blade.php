@extends('crm.layout')
@section('title', 'Rutinas por cliente')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0">Rutinas por cliente</h4>
</div>

<div class="row g-4">
    {{-- ── Columna izquierda: buscador y lista de miembros ── --}}
    <div class="col-md-4 col-lg-3">
        <div class="card shadow-sm">
            <div class="card-header bg-dark text-white py-2">
                <span class="fw-semibold small">Clientes</span>
            </div>
            <div class="card-body p-2">
                <form method="GET" action="{{ route('crm.member-routines.index') }}">
                    <div class="input-group input-group-sm mb-2">
                        <input type="text" name="search" class="form-control"
                               placeholder="Buscar por nombre o documento..."
                               value="{{ request('search') }}">
                        <button class="btn btn-outline-secondary" type="submit">🔍</button>
                    </div>
                    @if(request('member_id'))
                        <input type="hidden" name="member_id" value="{{ request('member_id') }}">
                    @endif
                </form>

                <div class="list-group list-group-flush" style="max-height: 70vh; overflow-y: auto;">
                    @forelse($members as $m)
                        <a href="{{ route('crm.member-routines.index', ['member_id' => $m->id, 'search' => request('search')]) }}"
                           class="list-group-item list-group-item-action py-2 px-2 small
                                  {{ $selectedMember?->id == $m->id ? 'active' : '' }}">
                            <div class="fw-semibold">{{ $m->full_name }}</div>
                            <div class="text-muted" style="font-size:.78rem">{{ $m->document_number }}</div>
                        </a>
                    @empty
                        <p class="text-muted small p-2 mb-0">No se encontraron clientes.</p>
                    @endforelse
                </div>

                {{-- Paginación compacta --}}
                @if($members->hasPages())
                <div class="mt-2 d-flex justify-content-between align-items-center px-1">
                    @if($members->onFirstPage())
                        <span class="btn btn-sm btn-outline-secondary disabled">‹</span>
                    @else
                        <a class="btn btn-sm btn-outline-secondary"
                           href="{{ $members->previousPageUrl() }}&member_id={{ request('member_id') }}&search={{ request('search') }}">‹</a>
                    @endif
                    <span class="small text-muted">{{ $members->currentPage() }}/{{ $members->lastPage() }}</span>
                    @if($members->hasMorePages())
                        <a class="btn btn-sm btn-outline-secondary"
                           href="{{ $members->nextPageUrl() }}&member_id={{ request('member_id') }}&search={{ request('search') }}">›</a>
                    @else
                        <span class="btn btn-sm btn-outline-secondary disabled">›</span>
                    @endif
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Columna derecha: rutinas del miembro seleccionado ── --}}
    <div class="col-md-8 col-lg-9">
        @if($selectedMember)
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold mb-0">{{ $selectedMember->full_name }}</h5>
                    <span class="text-muted small">{{ $selectedMember->document_number }}</span>
                </div>
                <a href="{{ route('crm.member-routines.create', ['member_id' => $selectedMember->id]) }}"
                   class="btn btn-success btn-sm">
                    + Nueva rutina
                </a>
            </div>

            @if($routines->isEmpty())
                <div class="card shadow-sm">
                    <div class="card-body text-center text-muted py-5">
                        <p class="mb-2">Este cliente no tiene rutinas asignadas todavía.</p>
                        <a href="{{ route('crm.member-routines.create', ['member_id' => $selectedMember->id]) }}"
                           class="btn btn-outline-success btn-sm">Crear primera rutina</a>
                    </div>
                </div>
            @else
                <div class="row g-3">
                    @foreach($routines as $routine)
                    <div class="col-sm-6 col-xl-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <h6 class="fw-bold mb-0">{{ $routine->name }}</h6>
                                    <span class="badge bg-secondary ms-2 flex-shrink-0">{{ $routine->level ?? '—' }}</span>
                                </div>
                                @if($routine->muscle_group)
                                    <div class="small text-muted mb-1">{{ $routine->muscle_group }}</div>
                                @endif
                                <div class="small text-muted mb-2">
                                    {{ $routine->routineExercises->count() }} ejercicio(s)
                                    @if($routine->estimated_minutes)
                                        · {{ $routine->estimated_minutes }} min
                                    @endif
                                </div>
                                @if($routine->description)
                                    <p class="small text-muted mb-2" style="overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">
                                        {{ $routine->description }}
                                    </p>
                                @endif

                                {{-- Exercise chips --}}
                                @if($routine->routineExercises->count() > 0)
                                <div class="d-flex flex-wrap gap-1 mb-3">
                                    @foreach($routine->routineExercises->take(4) as $re)
                                        <span class="badge bg-light text-dark border" style="font-size:.72rem">
                                            {{ $re->exercise?->name ?? "Ej.#{$re->exercise_id}" }}
                                        </span>
                                    @endforeach
                                    @if($routine->routineExercises->count() > 4)
                                        <span class="badge bg-light text-muted border" style="font-size:.72rem">
                                            +{{ $routine->routineExercises->count() - 4 }} más
                                        </span>
                                    @endif
                                </div>
                                @endif
                            </div>
                            <div class="card-footer bg-transparent pt-0 pb-2 d-flex gap-2">
                                <a href="{{ route('crm.member-routines.edit', [$routine, 'member_id' => $selectedMember->id]) }}"
                                   class="btn btn-outline-primary btn-sm flex-grow-1">Editar</a>
                                <form method="POST"
                                      action="{{ route('crm.member-routines.destroy', [$routine, 'member_id' => $selectedMember->id]) }}"
                                      onsubmit="return confirm('¿Eliminar esta rutina?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm">✕</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            @endif

        @else
            <div class="card shadow-sm">
                <div class="card-body text-center text-muted py-5">
                    <p class="mb-0">Selecciona un cliente de la lista para ver sus rutinas.</p>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
