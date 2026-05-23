@extends('crm.layout')
@section('title', 'Nueva rutina para ' . $member->full_name)

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
    <a href="{{ route('crm.member-routines.index', ['member_id' => $member->id]) }}"
       class="btn btn-outline-secondary btn-sm">← Volver</a>
    <h4 class="fw-bold mb-0">
        Nueva rutina para <span class="text-primary">{{ $member->full_name }}</span>
    </h4>
</div>

<div class="card shadow-sm">
    <div class="card-body p-4">
        <form method="POST" action="{{ route('crm.member-routines.store') }}">
            @csrf

            @include('crm.member-routines._form')

            <hr class="my-4">
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success">Guardar rutina</button>
                <a href="{{ route('crm.member-routines.index', ['member_id' => $member->id]) }}"
                   class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>
@endsection
