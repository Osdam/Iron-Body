<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExerciseProviderService;
use Illuminate\Http\Request;

/**
 * Endpoints internos de referencias visuales de ejercicios.
 *
 * Flutter consume SOLO estos endpoints; nunca toca el proveedor externo ni ve
 * credenciales. El proveedor (fitgif | workoutx | local) se elige por config;
 * la capa `ExerciseProviderService` ya entrega `gif_url`/`thumbnail_url`
 * finales (las de WorkoutX vía proxy del backend).
 *
 * Las listas responden siempre 200 con `{ data: ... }` (lista vacía como
 * fallback limpio) para no romper la pantalla Entrenar.
 */
class ExerciseController extends Controller
{
    public function __construct(private readonly ExerciseProviderService $exercises) {}

    // GET /api/exercises
    public function index(Request $request)
    {
        $limit  = (int) $request->input('limit', 30);
        $offset = (int) $request->input('offset', 0);

        return response()->json(['data' => $this->exercises->all($limit, $offset)]);
    }

    // GET /api/exercises/{id}
    public function show(string $id)
    {
        return response()->json(['data' => $this->exercises->find($id)]);
    }

    // GET /api/exercises/search?q=
    public function search(Request $request)
    {
        $q = (string) $request->input('q', '');

        return response()->json(['data' => $this->exercises->search($q)]);
    }

    // GET /api/exercises/by-muscle/{muscle}
    public function byMuscle(string $muscle)
    {
        return response()->json(['data' => $this->exercises->byMuscle($muscle)]);
    }

    // POST /api/exercises/sync  (puede tardar: throttle 3 req/min de FitGif)
    public function sync()
    {
        return response()->json($this->exercises->sync());
    }

    /**
     * GET /api/exercises/debug-fitgif?q=Press%20de%20Banca
     * Diagnóstico: candidatos probados, bodyPart, result_count por intento,
     * resultado elegido y has_gif_url. NO expone la API key.
     */
    public function debugFitgif(Request $request)
    {
        $q = (string) $request->input('q', '');
        if ($q === '') {
            return response()->json(['error' => 'q requerido'], 422);
        }
        return response()->json($this->exercises->fitgifDiagnose($q));
    }

    /**
     * GET /api/exercises/gif/{filename}
     * Proxy del GIF de WorkoutX: descarga con la key del servidor y lo reenvía.
     * La key jamás sale del backend. (FitGIF usa URLs públicas, sin proxy.)
     */
    public function gif(string $filename)
    {
        $resp = $this->exercises->workoutxGif($filename);
        if (! $resp) {
            abort(404);
        }

        return response($resp->body(), 200, [
            'Content-Type'  => $resp->header('Content-Type') ?: 'image/gif',
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }

    /**
     * GET /api/exercises/fitgif/gif/{id}
     * Sirve el GIF de FitGif YA descargado en disco durante el sync. CERO
     * llamadas a FitGif (respeta su límite 3/min); la key nunca sale del
     * backend y el cliente usa una URL estable y rápida.
     */
    public function fitgifGif(string $id)
    {
        $bytes = $this->exercises->fitgifGifContents($id);
        if ($bytes === null) {
            abort(404);
        }

        // El GIF cacheado es inmutable (mismo id ⇒ mismo binario): el cliente
        // y cualquier proxy pueden guardarlo 1 semana sin revalidar → carga
        // instantánea en aperturas posteriores del flip.
        return response($bytes, 200, [
            'Content-Type'   => 'image/gif',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control'  => 'public, max-age=604800, immutable',
            'ETag'           => '"' . md5($bytes) . '"',
        ]);
    }

    /**
     * GET /api/exercises/fitgif/video/{file}  (file = {slug}.mp4)
     * Sirve el MP4 optimizado (1.3x, 24fps, H.264) generado por ffmpeg en el
     * sync. CERO llamadas a FitGif. Soporta Range (seek/loop fluido). El GIF
     * sigue disponible como fallback en /fitgif/gif/{id}.
     */
    public function fitgifVideo(string $file)
    {
        $id  = preg_replace('/\.mp4$/i', '', $file);
        $abs = $this->exercises->fitgifVideoPath($id);
        if ($abs === null) {
            abort(404);
        }

        // response()->file() = BinaryFileResponse: Range + ETag + Last-Modified
        // automáticos (clave para que video_player haga loop suave).
        return response()->file($abs, [
            'Content-Type'  => 'video/mp4',
            'Cache-Control' => 'public, max-age=604800, immutable',
            'Accept-Ranges' => 'bytes',
        ]);
    }
}
