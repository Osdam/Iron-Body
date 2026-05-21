@extends('crm.layout')

@section('title', 'Editar entrenador')

@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="{{ route('crm.trainers.index') }}" class="btn btn-outline-secondary btn-sm">← Volver</a>
    <h4 class="mb-0 fw-bold">Editar: {{ $trainer->full_name }}</h4>
</div>

<div class="card shadow-sm border-0" style="max-width:680px">
    <div class="card-body">
        <form method="POST" action="{{ route('crm.trainers.update', $trainer) }}">
            @csrf
            @method('PUT')
            @include('crm.trainers._form', ['trainer' => $trainer])
            <button type="submit" class="btn btn-dark mt-2">Guardar cambios</button>
        </form>
    </div>
</div>
@endsection
