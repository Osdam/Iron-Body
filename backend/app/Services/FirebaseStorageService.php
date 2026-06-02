<?php

namespace App\Services;

use Kreait\Firebase\Contract\Storage as FirebaseStorageContract;
use Kreait\Firebase\Factory;
use RuntimeException;
use Throwable;

/**
 * Borrado físico de objetos en Firebase Storage usando el service account.
 *
 * **Por qué en backend (y no en la app):**
 * Garantiza que el recurso se libera aunque la app se cierre justo después
 * de pedir el delete. El backend ya re-valida el owner del story (defense in
 * depth) antes de llamar aquí, así que nunca borramos un objeto ajeno.
 *
 * **Seguridad:**
 * - El service account JSON nunca sale del backend; solo se usa para
 *   autenticar la llamada a la API de Storage de Google.
 * - No logueamos credenciales — solo metadata (path del objeto, código).
 *
 * **Tolerancia a fallos:**
 * - Si el objeto ya no existe (object-not-found), se considera éxito: el
 *   objetivo (que no quede archivo) ya se cumplió.
 * - Errores de red/permisos se loguean y devuelven `false` sin lanzar, para
 *   que el borrado del row de la DB no quede bloqueado por un fallo remoto.
 */
class FirebaseStorageService
{
    private ?FirebaseStorageContract $storage = null;

    /**
     * Borra un objeto del bucket. Acepta tanto una ruta de objeto
     * (`stories/uid/x.jpg`) como una URL `gs://bucket/stories/uid/x.jpg`.
     *
     * @return bool true si el objeto fue borrado o ya no existía.
     */
    public function deleteObject(string $pathOrGsUrl): bool
    {
        $objectPath = $this->normalizeObjectPath($pathOrGsUrl);
        if ($objectPath === '') {
            return false;
        }

        try {
            $bucket = $this->storage()->getBucket($this->bucketName());
            $object = $bucket->object($objectPath);

            // Si no existe, el resultado deseado (no hay archivo) ya se cumple.
            if (!$object->exists()) {
                return true;
            }

            $object->delete();
            return true;
        } catch (Throwable $e) {
            // No bloqueamos el borrado del row por un fallo remoto. El comando
            // stories:purge volverá a intentar limpiar huérfanos más tarde.
            logger()->warning('FirebaseStorageService: no se pudo borrar el objeto', [
                'object' => $objectPath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Convierte `gs://bucket/ruta/archivo.ext` (o una ruta ya relativa) en la
     * ruta de objeto que espera la API (`ruta/archivo.ext`).
     */
    private function normalizeObjectPath(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, 'gs://')) {
            // gs://bucket/objeto → quita el esquema y el nombre del bucket.
            $withoutScheme = substr($value, strlen('gs://'));
            $slash = strpos($withoutScheme, '/');
            return $slash === false ? '' : ltrim(substr($withoutScheme, $slash + 1), '/');
        }

        return ltrim($value, '/');
    }

    private function bucketName(): string
    {
        $bucket = (string) config('services.firebase.storage_bucket');
        if ($bucket === '') {
            throw new RuntimeException('FIREBASE_STORAGE_BUCKET no configurado.');
        }
        return $bucket;
    }

    /**
     * Cliente lazy de Storage. Cacheado por request (singleton Laravel) para
     * no releer el service account JSON en cada borrado.
     */
    private function storage(): FirebaseStorageContract
    {
        if ($this->storage !== null) {
            return $this->storage;
        }

        $relative = (string) config('services.firebase.credentials');
        // Acepta ruta absoluta o relativa a base_path (como en FCM).
        $path = str_starts_with($relative, '/') ? $relative : base_path($relative);

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(
                'Firebase service account no encontrado/legible para Storage: ' . $path
            );
        }

        $this->storage = (new Factory())
            ->withServiceAccount($path)
            ->createStorage();

        return $this->storage;
    }
}
