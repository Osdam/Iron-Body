@extends('crm.layout')
@section('title', 'Editar rutina — ' . $routine->name)

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
    <a href="{{ route('crm.member-routines.index', ['member_id' => $member->id]) }}"
       class="btn btn-outline-secondary btn-sm">← Volver</a>
    <div>
        <h4 class="fw-bold mb-0">Editar: {{ $routine->name }}</h4>
        <span class="text-muted small">Cliente: {{ $member->full_name }}</span>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-4">
        <form method="POST"
              action="{{ route('crm.member-routines.update', [$routine, 'member_id' => $member->id]) }}">
            @csrf @method('PUT')

            @include('crm.member-routines._form')

            <hr class="my-4">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Guardar cambios</button>
                    <a href="{{ route('crm.member-routines.index', ['member_id' => $member->id]) }}"
                       class="btn btn-outline-secondary">Cancelar</a>
                </div>
                <form method="POST"
                      action="{{ route('crm.member-routines.destroy', [$routine, 'member_id' => $member->id]) }}"
                      onsubmit="return confirm('¿Eliminar esta rutina? Esta acción no se puede deshacer.')">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger btn-sm">Eliminar rutina</button>
                </form>
            </div>
        </form>
    </div>
</div>
@endsection
