<?php

namespace App\Support\Notifications;

/**
 * Categorías de notificación. Es la ÚNICA clasificación del sistema: de ella
 * dependen tanto el canal de Android como lo que el socio puede desactivar.
 *
 * Antes cada notificación traía un `type` suelto ('payment', 'story', 'system'…)
 * que no servía para decidir nada: ni prioridad, ni si el usuario la quería. Aquí
 * ese `type` se traduce a una categoría estable, y todo lo demás cuelga de ella.
 */
final class NotificationCategory
{
    /** Obligatorias: seguridad de la cuenta y decisiones que le afectan. */
    public const ACCOUNT_SECURITY = 'account_security';

    public const PAYMENTS = 'payments';

    public const MEMBERSHIP = 'membership';

    public const CLASSES = 'classes';

    public const WORKOUTS = 'workouts';

    public const NUTRITION = 'nutrition';

    public const SOCIAL = 'social';

    public const MOTIVATION = 'motivation';

    public const HYDRATION = 'hydration';

    public const RECOVERY = 'recovery';

    public const SUPPLEMENTS = 'supplements';

    public const PROMOTIONS = 'promotions';

    /** Subtipos de `supplements`: se pueden apagar por separado. */
    public const SUPPLEMENT_CREATINE = 'creatine';

    public const SUPPLEMENT_PROTEIN = 'protein';

    public const SUPPLEMENT_PRE_WORKOUT = 'pre_workout';

    public const SUPPLEMENT_MULTIVITAMINS = 'multivitamins';

    public const SUPPLEMENT_BCAA = 'bcaa';

    /**
     * Categorías que NO se pueden desactivar.
     *
     * Se limita a lo que el socio no debería poder perderse sin enterarse: que
     * alguien entró en su cuenta, o que se ha tomado una decisión sobre ella.
     * Todo lo demás —incluidos pagos y membresía— es opcional: molestar a quien
     * pidió silencio no es una obligación legal, es una molestia.
     */
    public const MANDATORY = [
        self::ACCOUNT_SECURITY,
    ];

    /** Categorías que además pueden saltarse las horas de silencio. */
    public const BYPASSES_QUIET_HOURS = [
        self::ACCOUNT_SECURITY,
    ];

    public const ALL = [
        self::ACCOUNT_SECURITY,
        self::PAYMENTS,
        self::MEMBERSHIP,
        self::CLASSES,
        self::WORKOUTS,
        self::NUTRITION,
        self::SOCIAL,
        self::MOTIVATION,
        self::HYDRATION,
        self::RECOVERY,
        self::SUPPLEMENTS,
        self::PROMOTIONS,
    ];

    public const SUPPLEMENT_KINDS = [
        self::SUPPLEMENT_CREATINE,
        self::SUPPLEMENT_PROTEIN,
        self::SUPPLEMENT_PRE_WORKOUT,
        self::SUPPLEMENT_MULTIVITAMINS,
        self::SUPPLEMENT_BCAA,
    ];

    /** Traduce el `type` histórico de `notifications`/`app_notifications`. */
    private const LEGACY_TYPE_MAP = [
        'security' => self::ACCOUNT_SECURITY,
        'moderation' => self::ACCOUNT_SECURITY,
        'payment' => self::PAYMENTS,
        'billing' => self::PAYMENTS,
        'order' => self::PAYMENTS,
        'store' => self::PAYMENTS,
        'membership' => self::MEMBERSHIP,
        'class' => self::CLASSES,
        'routine' => self::WORKOUTS,
        'trainer' => self::WORKOUTS,
        'nutrition' => self::NUTRITION,
        'story' => self::SOCIAL,
        'social' => self::SOCIAL,
        'promotion' => self::PROMOTIONS,
        'motivation' => self::MOTIVATION,
        'hydration' => self::HYDRATION,
        'recovery' => self::RECOVERY,
        'supplements' => self::SUPPLEMENTS,
        'iron_ai' => self::WORKOUTS,
        'system' => self::MEMBERSHIP,
    ];

    public static function isValid(?string $category): bool
    {
        return $category !== null && in_array($category, self::ALL, true);
    }

    public static function isMandatory(string $category): bool
    {
        return in_array($category, self::MANDATORY, true);
    }

    public static function bypassesQuietHours(string $category): bool
    {
        return in_array($category, self::BYPASSES_QUIET_HOURS, true);
    }

    public static function isSupplementKind(?string $kind): bool
    {
        return $kind !== null && in_array($kind, self::SUPPLEMENT_KINDS, true);
    }

    /**
     * Categoría a la que pertenece un `type` heredado. Lo desconocido cae en
     * MEMBERSHIP (avisos generales del gimnasio) y NO en una categoría
     * obligatoria: un tipo nuevo no debería colarse saltándose las preferencias
     * solo por no estar en la tabla.
     */
    public static function fromLegacyType(?string $type): string
    {
        if ($type === null || $type === '') {
            return self::MEMBERSHIP;
        }

        return self::LEGACY_TYPE_MAP[strtolower($type)] ?? self::MEMBERSHIP;
    }

    /**
     * Tipo que entiende el óvalo in-app de Flutter
     * (`ironNotificationTypeFromString`), para que elija icono y color.
     *
     * Sin esto, todo lo que sale por el motor nuevo cae en el caso por defecto
     * («info») y una alerta de seguridad se vería con el estilo más genérico de
     * todos, que es justo al revés de lo que conviene.
     */
    public static function appType(string $category): string
    {
        return match ($category) {
            self::ACCOUNT_SECURITY => 'security',
            self::PAYMENTS, self::MEMBERSHIP => 'membership',
            self::CLASSES, self::WORKOUTS => 'training',
            self::NUTRITION, self::SUPPLEMENTS => 'nutrition',
            self::MOTIVATION, self::RECOVERY, self::HYDRATION => 'progress',
            default => 'info',
        };
    }

    /** Etiquetas para el CRM y la pantalla de ajustes de la app. */
    public static function label(string $category): string
    {
        return match ($category) {
            self::ACCOUNT_SECURITY => 'Seguridad de la cuenta',
            self::PAYMENTS => 'Pagos y facturación',
            self::MEMBERSHIP => 'Membresía',
            self::CLASSES => 'Clases',
            self::WORKOUTS => 'Entrenamientos y rutinas',
            self::NUTRITION => 'Nutrición',
            self::SOCIAL => 'Comunidad',
            self::MOTIVATION => 'Motivación y constancia',
            self::HYDRATION => 'Hidratación y hábitos',
            self::RECOVERY => 'Descanso y recuperación',
            self::SUPPLEMENTS => 'Suplementos',
            self::PROMOTIONS => 'Promociones',
            default => $category,
        };
    }

    public static function supplementLabel(string $kind): string
    {
        return match ($kind) {
            self::SUPPLEMENT_CREATINE => 'Creatina',
            self::SUPPLEMENT_PROTEIN => 'Proteína',
            self::SUPPLEMENT_PRE_WORKOUT => 'Preentreno',
            self::SUPPLEMENT_MULTIVITAMINS => 'Multivitamínicos',
            self::SUPPLEMENT_BCAA => 'BCAA',
            default => $kind,
        };
    }
}
