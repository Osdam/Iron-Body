<?php

namespace App\Support\Moderation;

/**
 * Catálogo CERRADO de motivos de reporte.
 *
 * El código (`nudity_or_sexual_content`, …) es la autoridad; la etiqueta es
 * solo presentación y puede cambiar sin migrar datos. El cliente jamás define
 * un motivo nuevo: cualquier valor fuera de este catálogo es 422.
 *
 * La severidad y la prioridad se DERIVAN del motivo en el backend — nunca se
 * aceptan del cliente. Así un atacante no puede inflar la prioridad de su
 * reporte para saturar la cola de moderación.
 */
final class ReportReason
{
    public const NUDITY = 'nudity_or_sexual_content';

    public const VIOLENCE = 'violence_or_graphic_content';

    public const HARASSMENT = 'harassment_or_bullying';

    public const HATE_SPEECH = 'hate_speech';

    public const SPAM = 'spam_or_scam';

    public const DANGEROUS = 'dangerous_activity';

    public const SELF_HARM = 'self_harm';

    public const CHILD_SAFETY = 'child_safety';

    public const IMPERSONATION = 'impersonation';

    public const PRIVACY = 'privacy_violation';

    public const IP = 'intellectual_property';

    public const ILLEGAL = 'illegal_content';

    public const OTHER = 'other';

    /** Severidades. Ordenadas de menor a mayor. */
    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    /**
     * Definición completa del catálogo.
     *
     * `priority` es 1..100 (más alto = se revisa antes). Los motivos que
     * implican riesgo para una persona (menores, autolesión) van arriba del
     * todo por política, no por volumen de reportes.
     *
     * @return array<string, array{label: string, description: string, severity: string, priority: int}>
     */
    public static function catalog(): array
    {
        return [
            self::CHILD_SAFETY => [
                'label' => 'Seguridad de menores',
                'description' => 'Contenido que pone en riesgo a un menor de edad.',
                'severity' => self::SEVERITY_CRITICAL,
                'priority' => 100,
            ],
            self::SELF_HARM => [
                'label' => 'Autolesión o suicidio',
                'description' => 'Contenido que muestra o promueve hacerse daño.',
                'severity' => self::SEVERITY_CRITICAL,
                'priority' => 98,
            ],
            self::ILLEGAL => [
                'label' => 'Contenido ilegal',
                'description' => 'Venta de sustancias, armas u otra actividad ilegal.',
                'severity' => self::SEVERITY_CRITICAL,
                'priority' => 95,
            ],
            self::NUDITY => [
                'label' => 'Desnudos o contenido sexual',
                'description' => 'Desnudos, contenido sexual explícito o sugerente.',
                'severity' => self::SEVERITY_HIGH,
                'priority' => 90,
            ],
            self::VIOLENCE => [
                'label' => 'Violencia o contenido gráfico',
                'description' => 'Agresiones, sangre o contenido perturbador.',
                'severity' => self::SEVERITY_HIGH,
                'priority' => 88,
            ],
            self::HATE_SPEECH => [
                'label' => 'Discurso de odio',
                'description' => 'Ataques por raza, género, religión, orientación o discapacidad.',
                'severity' => self::SEVERITY_HIGH,
                'priority' => 86,
            ],
            self::HARASSMENT => [
                'label' => 'Acoso o intimidación',
                'description' => 'Insultos, amenazas o burlas dirigidas a alguien.',
                'severity' => self::SEVERITY_HIGH,
                'priority' => 84,
            ],
            self::DANGEROUS => [
                'label' => 'Actividad peligrosa',
                'description' => 'Retos o prácticas que pueden causar lesiones graves.',
                'severity' => self::SEVERITY_MEDIUM,
                'priority' => 70,
            ],
            self::PRIVACY => [
                'label' => 'Violación de privacidad',
                'description' => 'Publica datos o imágenes de alguien sin permiso.',
                'severity' => self::SEVERITY_MEDIUM,
                'priority' => 68,
            ],
            self::IMPERSONATION => [
                'label' => 'Suplantación de identidad',
                'description' => 'Se hace pasar por otra persona o por Iron Body.',
                'severity' => self::SEVERITY_MEDIUM,
                'priority' => 66,
            ],
            self::IP => [
                'label' => 'Propiedad intelectual',
                'description' => 'Usa contenido de terceros sin autorización.',
                'severity' => self::SEVERITY_LOW,
                'priority' => 45,
            ],
            self::SPAM => [
                'label' => 'Spam o estafa',
                'description' => 'Publicidad no deseada, enlaces engañosos o fraude.',
                'severity' => self::SEVERITY_LOW,
                'priority' => 40,
            ],
            self::OTHER => [
                'label' => 'Otro motivo',
                'description' => 'No encaja en las categorías anteriores.',
                'severity' => self::SEVERITY_LOW,
                'priority' => 30,
            ],
        ];
    }

    /** @return list<string> Códigos válidos — usado por la regla `in:` del request. */
    public static function codes(): array
    {
        return array_keys(self::catalog());
    }

    public static function isValid(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::catalog());
    }

    public static function severityFor(string $code): string
    {
        return self::catalog()[$code]['severity'] ?? self::SEVERITY_MEDIUM;
    }

    public static function priorityFor(string $code): int
    {
        return self::catalog()[$code]['priority'] ?? 50;
    }

    public static function labelFor(string $code): string
    {
        return self::catalog()[$code]['label'] ?? 'Motivo no especificado';
    }

    /**
     * ¿Es un motivo que exige revisión humana inmediata y NUNCA puede
     * resolverse por una regla automática? (menores, autolesión, ilegal).
     */
    public static function requiresHumanReview(string $code): bool
    {
        return in_array($code, [self::CHILD_SAFETY, self::SELF_HARM, self::ILLEGAL], true);
    }

    /** Catálogo listo para servir a la app (sin datos internos). */
    public static function forClient(): array
    {
        $out = [];
        foreach (self::catalog() as $code => $meta) {
            $out[] = [
                'code' => $code,
                'label' => $meta['label'],
                'description' => $meta['description'],
            ];
        }

        return $out;
    }
}
