<?php

namespace App\Services\Nutrition;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Proveedor OCR real basado en Tesseract instalado en el VPS (sin costos
 * mensuales). Ejecuta el binario de forma SEGURA con Symfony Process (sin
 * shell, argumentos como array → sin inyección de comandos), con timeout y
 * limpieza de temporales.
 *
 * Instalación en el servidor:
 *   sudo apt install -y tesseract-ocr tesseract-ocr-spa tesseract-ocr-eng
 */
class TesseractNutritionOcrProvider
{
    public function __construct(private NutritionOcrImagePreprocessor $preprocessor)
    {
    }

    /** ¿El binario de Tesseract existe y es ejecutable? */
    public function isAvailable(): bool
    {
        $bin = (string) config('nutrition.ocr.tesseract_bin');
        return $bin !== '' && is_file($bin) && is_executable($bin);
    }

    /**
     * Extrae texto de la imagen en disco. Devuelve null en error controlado
     * (binario ausente, timeout o fallo) — NUNCA finge texto.
     */
    public function extractText(string $imagePath): ?string
    {
        if (! $this->isAvailable()) {
            Log::warning('nutrition.ocr.tesseract.unavailable', [
                'bin' => config('nutrition.ocr.tesseract_bin'),
            ]);
            return null;
        }
        if (! is_file($imagePath)) {
            return null;
        }

        $bin = (string) config('nutrition.ocr.tesseract_bin');
        $lang = (string) config('nutrition.ocr.lang', 'spa+eng');
        $timeout = (int) config('nutrition.ocr.timeout_seconds', 20);

        // Preprocesa (opcional). Si falla, se usa la imagen original.
        $preprocessed = $this->preprocessor->process($imagePath, $timeout);
        $inputPath = $preprocessed ?? $imagePath;

        try {
            // tesseract <input> stdout -l <lang> --psm 6 (bloque uniforme de texto).
            $proc = new Process([
                $bin,
                $inputPath,
                'stdout',
                '-l', $lang,
                '--psm', '6',
            ]);
            $proc->setTimeout($timeout);
            $proc->run();

            if (! $proc->isSuccessful()) {
                Log::warning('nutrition.ocr.tesseract.failed', [
                    'err' => trim($proc->getErrorOutput()),
                ]);
                return null;
            }

            $text = trim($proc->getOutput());
            return $text === '' ? null : $text;
        } catch (\Throwable $e) {
            Log::warning('nutrition.ocr.tesseract.exception', ['msg' => $e->getMessage()]);
            return null;
        } finally {
            // Limpia el temporal de preprocesamiento (nunca el original del caller).
            if ($preprocessed !== null) {
                @unlink($preprocessed);
            }
        }
    }
}
