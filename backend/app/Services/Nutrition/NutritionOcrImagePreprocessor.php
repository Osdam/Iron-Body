<?php

namespace App\Services\Nutrition;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Preprocesamiento opcional de la imagen antes del OCR (mejora la lectura).
 *
 * Si ImageMagick (`convert`) está disponible: escala de grises, normaliza
 * contraste, redimensiona si es muy grande y corrige orientación EXIF. Si algo
 * falla, devuelve la ruta original sin crashear (degradación elegante).
 */
class NutritionOcrImagePreprocessor
{
    /**
     * Devuelve la ruta de una imagen procesada (nueva) o null si no se pudo /
     * no hay ImageMagick. El llamador debe borrar el archivo resultante.
     */
    public function process(string $sourcePath, int $timeoutSeconds = 20): ?string
    {
        $binary = $this->magickBinary();
        if ($binary === null || ! is_file($sourcePath)) {
            return null; // sin ImageMagick → se usa la imagen original
        }

        $outPath = sys_get_temp_dir() . '/ironbody_ocr_pre_' . bin2hex(random_bytes(8)) . '.png';

        // -auto-orient: EXIF · -colorspace Gray · -resize: limita lado mayor a
        // 2000px · -contrast-stretch/-normalize: realza el texto.
        $args = [
            $binary,
            $sourcePath,
            '-auto-orient',
            '-colorspace', 'Gray',
            '-resize', '2000x2000>',
            '-normalize',
            '-contrast-stretch', '2%x1%',
            $outPath,
        ];

        // `magick` moderno usa subcomando: `magick convert ...`.
        if (str_ends_with($binary, 'magick')) {
            array_splice($args, 1, 0, 'convert');
        }

        try {
            $proc = new Process($args);
            $proc->setTimeout($timeoutSeconds);
            $proc->run();
            if ($proc->isSuccessful() && is_file($outPath)) {
                return $outPath;
            }
            @unlink($outPath);
            Log::info('nutrition.ocr.preprocess.skip', ['err' => trim($proc->getErrorOutput())]);
        } catch (\Throwable $e) {
            @unlink($outPath);
            Log::info('nutrition.ocr.preprocess.error', ['msg' => $e->getMessage()]);
        }
        return null;
    }

    /** Localiza `magick` o `convert` (ImageMagick) en el PATH del sistema. */
    private function magickBinary(): ?string
    {
        $finder = new ExecutableFinder();
        return $finder->find('magick') ?? $finder->find('convert');
    }
}
