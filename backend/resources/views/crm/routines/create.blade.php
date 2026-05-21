@extends('crm.layout')

@section('title', 'Nueva Rutina')

@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="{{ route('crm.routines.index') }}" class="btn btn-outline-secondary btn-sm">← Volver</a>
    <h4 class="mb-0 fw-bold">Nueva Rutina</h4>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <form method="POST" action="{{ route('crm.routines.store') }}">
            @csrf
            @include('crm.routines._form')
            <div class="mt-4">
                <button type="submit" class="btn btn-success">Guardar rutina</button>
                <a href="{{ route('crm.routines.index') }}" class="btn btn-outline-secondary ms-2">Cancelar</a>
            </div>
        </form>
    </div>
</div>
@endsection
