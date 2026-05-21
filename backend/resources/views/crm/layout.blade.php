<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'CRM') — Iron Body</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        body { background: #f4f6fb; }
        .navbar-brand { font-weight: 700; letter-spacing: .5px; }
        .table th { background: #212529; color: #fff; font-size: .85rem; }
        .badge-active   { background: #198754; }
        .badge-inactive { background: #6c757d; }
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark px-4 py-2 mb-4">
    <a class="navbar-brand" href="{{ route('crm.trainers.index') }}">Iron Body CRM</a>
    <div class="d-flex gap-3">
        <a href="{{ route('crm.trainers.index') }}" class="text-white text-decoration-none small">Entrenadores</a>
        <a href="{{ route('crm.exercises.index') }}" class="text-white text-decoration-none small">Ejercicios</a>
        <a href="{{ route('crm.routines.index') }}" class="text-white text-decoration-none small">Rutinas</a>
        <a href="{{ route('crm.routines.custom') }}" class="text-secondary text-decoration-none small">Rutinas App</a>
    </div>
</nav>

<div class="container-lg">
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @yield('content')
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
