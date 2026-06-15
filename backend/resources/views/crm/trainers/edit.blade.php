@extends('crm.layout')

@section('title', 'Editar entrenador')

@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="{{ route('crm.trainers.index') }}" class="btn btn-outline-secondary btn-sm">← Volver</a>
    <h4 class="mb-0 fw-bold">Editar: {{ $trainer->full_name }}</h4>
</div>

@if(session('success'))
    <div class="alert alert-success py-2 small">{{ session('success') }}</div>
@endif

<div class="card shadow-sm border-0 mb-4" style="max-width:680px">
    <div class="card-body">
        <form method="POST" action="{{ route('crm.trainers.update', $trainer) }}">
            @csrf
            @method('PUT')
            @include('crm.trainers._form', ['trainer' => $trainer])
            <button type="submit" class="btn btn-dark mt-2">Guardar cambios</button>
        </form>
    </div>
</div>

{{-- ── Miembros asignados (mismo módulo, sin pantalla aparte) ───────────────── --}}
<div class="card shadow-sm border-0 mb-4" id="miembros" style="max-width:680px">
    <div class="card-body">
        <h6 class="fw-bold text-uppercase text-muted small mb-3">Miembros asignados</h6>

        @if($assignedMembers->isEmpty())
            <p class="text-muted small mb-0">Este entrenador no tiene miembros asignados.</p>
        @else
            <ul class="list-group list-group-flush">
                @foreach($assignedMembers as $member)
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <div>
                            <span class="fw-semibold">{{ $member->full_name }}</span>
                            <span class="text-muted small d-block">
                                Doc: {{ $member->document_number ?? '—' }} · Tel: {{ $member->phone ?? '—' }}
                            </span>
                        </div>
                        <form method="POST"
                              action="{{ route('crm.trainers.members.unassign', ['trainer' => $trainer, 'member' => $member]) }}"
                              onsubmit="return confirm('¿Quitar a {{ addslashes($member->full_name) }} de este entrenador?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-sm">Quitar</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>

{{-- Buscar y asignar miembros activos --}}
<div class="card shadow-sm border-0" style="max-width:680px">
    <div class="card-body">
        <h6 class="fw-bold text-uppercase text-muted small mb-3">Asignar miembros</h6>

        <form method="GET" action="{{ route('crm.trainers.edit', $trainer) }}" class="mb-3">
            <div class="input-group">
                <input type="text" name="member_q" class="form-control"
                       value="{{ $memberQuery }}"
                       placeholder="Buscar por nombre, documento o teléfono…">
                <button type="submit" class="btn btn-outline-primary">Buscar</button>
            </div>
        </form>

        @if($memberQuery !== '')
            @if($memberResults->isEmpty())
                <p class="text-muted small mb-0">Sin miembros activos que coincidan con “{{ $memberQuery }}”.</p>
            @else
                <form method="POST" action="{{ route('crm.trainers.members.assign', $trainer) }}">
                    @csrf
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-2">
                            <thead>
                                <tr>
                                    <th style="width:36px"></th>
                                    <th>Nombre</th>
                                    <th>Documento</th>
                                    <th>Teléfono</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($memberResults as $member)
                                    <tr>
                                        <td>
                                            <input class="form-check-input" type="checkbox"
                                                   name="member_ids[]" value="{{ $member->id }}"
                                                   id="m{{ $member->id }}">
                                        </td>
                                        <td><label class="mb-0" for="m{{ $member->id }}">{{ $member->full_name }}</label></td>
                                        <td class="small text-muted">{{ $member->document_number ?? '—' }}</td>
                                        <td class="small text-muted">{{ $member->phone ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" class="btn btn-dark btn-sm">Asignar seleccionados</button>
                </form>
            @endif
        @endif
    </div>
</div>
@endsection
