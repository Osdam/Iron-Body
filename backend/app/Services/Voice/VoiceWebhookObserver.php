<?php

namespace App\Services\Voice;

use App\Services\Voice\Dto\VoiceSecretList;
use App\Services\Voice\Dto\VoiceWebhookList;

/**
 * Leer cómo están registrados en el proveedor el webhook post-llamada y los
 * secretos que cita el agente (auditoría B-T5; contrato H8, D8-16 y D8-21,
 * decisión (f): la SALUD DEL WEBHOOK). Una interfaz SEGREGADA de solo lectura,
 * como {@see VoiceNumberObserver} (E5-2): nadie que despliegue agentes tiene
 * que aprender a leer webhooks. La implementa
 * {@see ElevenLabs\ElevenLabsAgentDeployer}, que ya habla con el proveedor.
 *
 * Solo GET, uno por método. Ninguna implementación lanza por la red: los
 * fallos son resultados. Ninguna sigue redirecciones ni reintenta. Nunca
 * devuelve el valor de un secreto: la lista del proveedor no lo da, y de los
 * secretos solo salen sus ids.
 */
interface VoiceWebhookObserver
{
    /** Solo GET: los webhooks del workspace (id, URL, si está desactivado y su autenticación); sin red, «desconocido». */
    public function describeWebhooks(): VoiceWebhookList;

    /** Solo GET: los ids de los secretos guardados en el workspace (una página); sin red, «desconocido». */
    public function describeSecrets(): VoiceSecretList;
}
