<?php

namespace App\Services\Admin\IronCrm;

use App\Models\Admin;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Auditoría básica del copiloto IRON del CRM. Registra QUÉ admin consultó,
 * cuándo y con qué características la consulta — NUNCA el contenido sensible del
 * mensaje, ni tokens, ni la respuesta del modelo, ni las claves de OpenAI.
 *
 * Deja traza en dos lugares (defensivo):
 *   1. Log estructurado (`Log::info('iron-crm:chat', …)`) — siempre.
 *   2. Tabla `audit_logs` (append-only) — best effort; si falla, no rompe el
 *      chat (el copiloto debe seguir respondiendo aunque la auditoría caiga).
 */
class IronCrmAuditService
{
    /**
     * Registra un evento de chat del copiloto. `$meta` admite: message_length,
     * had_image (bool), tools_used (array<string>), error (bool), model.
     *
     * @param  array<string, mixed>  $meta
     */
    public function logChat(Request $request, ?Admin $admin, array $meta = []): void
    {
        $toolsUsed = array_values(array_unique(array_map('strval', (array) ($meta['tools_used'] ?? []))));

        $payload = [
            'actor_id' => $admin?->id,
            'actor_name' => $admin?->name ?? 'Sistema',
            'actor_role' => $admin?->role ?? 'system',
            'message_length' => (int) ($meta['message_length'] ?? 0),
            'had_image' => (bool) ($meta['had_image'] ?? false),
            'tools_used' => $toolsUsed,
            'error' => (bool) ($meta['error'] ?? false),
            'model' => $meta['model'] ?? null,
            'ip' => $request->ip(),
        ];

        Log::info('iron-crm:chat', $payload);

        try {
            AuditLog::create([
                'action' => 'settings',
                'module' => 'iron_ai',
                'entity' => 'iron_ai_chat',
                'entity_id' => null,
                'target_name' => 'IRON copiloto CRM',
                'actor_id' => $admin?->id !== null ? (string) $admin->id : null,
                'actor_name' => $admin?->name ?? 'Sistema',
                'actor_role' => $admin?->role ?? 'system',
                'summary' => $this->summary($payload),
                'changes' => [],
                'metadata' => [
                    'message_length' => $payload['message_length'],
                    'had_image' => $payload['had_image'],
                    'tools_used' => $toolsUsed,
                    'error' => $payload['error'],
                    'model' => $payload['model'],
                ],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // La auditoría no debe tumbar el chat: solo se registra el fallo.
            Log::warning('iron-crm:audit-failed', ['error_class' => get_class($e)]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function summary(array $payload): string
    {
        $parts = ['Consulta a IRON (CRM)'];
        if (! empty($payload['tools_used'])) {
            $parts[] = 'herramientas: '.implode(', ', $payload['tools_used']);
        }
        if (! empty($payload['had_image'])) {
            $parts[] = 'con imagen';
        }
        if (! empty($payload['error'])) {
            $parts[] = 'con error';
        }

        return implode(' · ', $parts);
    }
}
