@extends('crm.layout')

@section('title', 'Nuevo Ejercicio')

@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="{{ route('crm.exercises.index') }}" class="btn btn-outline-secondary btn-sm">← Volver</a>
    <h4 class="mb-0 fw-bold">Nuevo Ejercicio</h4>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <form method="POST" action="{{ route('crm.exercises.store') }}">
            @csrf
            @include('crm.exercises._form')
            <div class="mt-4">
                <button type="submit" class="btn btn-success">Guardar ejercicio</button>
                <a href="{{ route('crm.exercises.index') }}" class="btn btn-outline-secondary ms-2">Cancelar</a>
            </div>
        </form>
    </div>
</div>
@endsection
