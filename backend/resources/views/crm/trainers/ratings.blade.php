@extends('crm.layout')

@section('title', 'Calificaciones — ' . $trainer->full_name)

@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="{{ route('crm.trainers.index') }}" class="btn btn-outline-secondary btn-sm">← Volver</a>
    <h4 class="mb-0 fw-bold">Calificaciones de {{ $trainer->full_name }}</h4>
</div>

<div class="row g-3 mb-4">
    <div class="col-auto">
        <div class="card border-0 shadow-sm text-center px-4 py-3">
            <div class="fs-2 fw-bold text-success">
                {{ $reviews->count() > 0 ? number_format($reviews->avg('rating'), 1) : '—' }}
            </div>
            <div class="text-muted small">Promedio ★</div>
        </div>
    </div>
    <div class="col-auto">
        <div class="card border-0 shadow-sm text-center px-4 py-3">
            <div class="fs-2 fw-bold">{{ $reviews->count() }}</div>
            <div class="text-muted small">Calificaciones</div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Miembro</th>
                    <th>Documento</th>
                    <th class="text-center" style="width:160px">Calificación</th>
                    <th>Comentario</th>
                    <th style="width:160px">Fecha</th>
                </tr>
            </thead>
            <tbody>
                @forelse($reviews as $review)
                <tr>
                    <td>{{ $review->member?->full_name ?? 'N/A' }}</td>
                    <td class="text-muted small">{{ $review->member?->document_number ?? '-' }}</td>
                    <td class="text-center">
                        <span class="text-warning" style="letter-spacing:1px">
                            @for ($i = 1; $i <= 5; $i++)
                                {{ $i <= $review->rating ? '★' : '☆' }}
                            @endfor
                        </span>
                        <span class="text-muted small">({{ number_format($review->rating, 1) }})</span>
                    </td>
                    <td>{{ $review->comment ?: '—' }}</td>
                    <td class="text-muted small">
                        {{ $review->created_at->format('d/m/Y H:i') }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">
                        Este entrenador no tiene calificaciones aún.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
