<?php

namespace App\Services\Marketing\Attribution;

use App\Models\MarketingLead;
use App\Models\MarketingLeadAttribution;
use App\Models\MetaAdEntity;
use InvalidArgumentException;

/**
 * De dónde vino un lead, solo con evidencia (reglas D8 y D9).
 *
 *  · `paid`     el referral de Meta era un anuncio (`ad`) y trae su `ad_id`.
 *  · `organic`  el referral era una publicación (LeadAttributionService guarda
 *               `post` y `page` como `organic`).
 *  · `direct`   existe en el modelo, pero hoy nada lo escribe: que un mensaje
 *               no traiga referral NO prueba que la persona escribiera directo.
 *  · `unknown`  todo lo demás. Se pinta como «Sin atribuir».
 *
 * La plataforma sale del dominio de la URL del anuncio (fb.me o facebook.com →
 * Facebook; instagram.com → Instagram), nunca del texto del cliente.
 *
 * La campaña, el conjunto y el nombre del anuncio se resuelven por el `ad_id`
 * contra la dimensión que sincroniza la Marketing API (`meta_ad_entities`): la
 * relación anuncio → conjunto → campaña la da Meta. Solo cuenta la dimensión de
 * la cuenta publicitaria CONFIGURADA ({@see MetaAdEntity::scopeForConfiguredAccount()}):
 * un anuncio de otra cuenta no tiene gasto conocido en esta, así que no se
 * resuelve (`resolved = false`). Sin esa fila quedan en null.
 * NUNCA se deduce la campaña por coincidencia de fechas.
 *
 * Solo lee. Para no hacer una consulta por lead, quien clasifica muchos llama
 * antes a {@see preload()} con todos los `ad_id`; lo que no se precargó se
 * busca la primera vez y se recuerda.
 */
class LeadSourceClassifier
{
    public const ORIGIN_PAID = 'paid';

    public const ORIGIN_ORGANIC = 'organic';

    public const ORIGIN_DIRECT = 'direct';

    public const ORIGIN_UNKNOWN = 'unknown';

    public const ORIGINS = [self::ORIGIN_PAID, self::ORIGIN_ORGANIC, self::ORIGIN_DIRECT, self::ORIGIN_UNKNOWN];

    public const PLATFORM_FACEBOOK = 'facebook';

    public const PLATFORM_INSTAGRAM = 'instagram';

    public const KEY_UNATTRIBUTED = 'unattributed';

    /**
     * Las filas del bloque «Origen de leads», en el orden en que se pintan.
     * Salen siempre, aunque valgan cero.
     */
    public const KEYS = [
        'facebook_ads', 'instagram_ads', 'facebook_organic', 'instagram_organic',
        'whatsapp_direct', self::KEY_UNATTRIBUTED,
    ];

    /** Etiquetas legibles de cada clave, incluidas las que solo salen si tienen leads. */
    public const LABELS = [
        'facebook_ads' => 'Facebook Ads',
        'instagram_ads' => 'Instagram Ads',
        'facebook_organic' => 'Facebook orgánico',
        'instagram_organic' => 'Instagram orgánico',
        'whatsapp_direct' => 'WhatsApp directo',
        self::KEY_UNATTRIBUTED => 'Sin atribuir',
        'meta_ads' => 'Meta Ads (plataforma sin identificar)',
        'meta_organic' => 'Meta orgánico (plataforma sin identificar)',
    ];

    /** @var array<string, array{name: ?string, campaign_id: ?string, adset_id: ?string}|null> anuncios ya buscados; null = no está */
    private array $ads = [];

    /** @var array<string, ?string> nombres de campañas y conjuntos por «nivel|id» */
    private array $names = [];

    /**
     * Precarga en lote la dimensión de estos anuncios y de sus padres.
     *
     * @param  iterable<?string>  $adIds
     */
    public function preload(iterable $adIds): void
    {
        $missing = [];
        foreach ($adIds as $id) {
            $id = trim((string) $id);
            if ($id !== '' && ! array_key_exists($id, $this->ads)) {
                $missing[$id] = true;
            }
        }

        if ($missing === []) {
            return;
        }

        foreach (array_chunk(array_keys($missing), 500) as $ids) {
            $found = MetaAdEntity::query()
                ->forConfiguredAccount()
                ->where('level', MetaAdEntity::LEVEL_AD)
                ->whereIn('entity_id', array_map('strval', $ids))
                ->get(['entity_id', 'name', 'campaign_id', 'adset_id'])
                ->keyBy('entity_id');

            foreach ($ids as $id) {
                $ad = $found->get((string) $id);
                $this->ads[(string) $id] = $ad === null ? null : [
                    'name' => $ad->name,
                    'campaign_id' => $ad->campaign_id,
                    'adset_id' => $ad->adset_id,
                ];
            }
        }

        $this->preloadNames();
    }

    /**
     * Clasifica el primer o el último contacto de un lead.
     *
     * `ad_id` va completo porque lo necesita quien agrega; quien responde al
     * front tiene que enmascararlo ({@see maskId()}).
     *
     * @return array{touch: string, channel: ?string, origin: string, platform: ?string, key: string,
     *     ad_id: ?string, campaign_id: ?string, campaign_name: ?string, adset_id: ?string,
     *     adset_name: ?string, ad_name: ?string, resolved: bool}
     */
    public function classify(?MarketingLeadAttribution $a, MarketingLead $lead, string $touch = 'first'): array
    {
        if (! in_array($touch, ['first', 'last'], true)) {
            throw new InvalidArgumentException("Contacto desconocido: {$touch} (first o last).");
        }

        [$type, $adId, $platform] = $a === null ? [null, null, null] : $this->touchOf($a, $touch);

        $type = strtolower(trim((string) $type));
        $adId = filled($adId) ? trim((string) $adId) : null;

        $origin = match (true) {
            $type === MarketingLeadAttribution::SOURCE_AD && $adId !== null => self::ORIGIN_PAID,
            in_array($type, [MarketingLeadAttribution::SOURCE_ORGANIC, 'post'], true) => self::ORIGIN_ORGANIC,
            $type === MarketingLeadAttribution::SOURCE_DIRECT => self::ORIGIN_DIRECT,
            default => self::ORIGIN_UNKNOWN,
        };

        // La plataforma solo tiene sentido si hubo un anuncio o una publicación.
        if (! in_array($origin, [self::ORIGIN_PAID, self::ORIGIN_ORGANIC], true)) {
            $platform = null;
        }

        $ad = $origin === self::ORIGIN_PAID ? $this->ad($adId) : null;

        return [
            'touch' => $touch,
            'channel' => $lead->channel,
            'origin' => $origin,
            'platform' => $platform,
            'key' => self::keyFor($origin, $platform, $lead->channel),
            'ad_id' => $origin === self::ORIGIN_PAID ? $adId : null,
            'campaign_id' => $ad['campaign_id'] ?? null,
            'campaign_name' => $ad !== null && $ad['campaign_id'] !== null
                ? ($this->names[MetaAdEntity::LEVEL_CAMPAIGN.'|'.$ad['campaign_id']] ?? null)
                : null,
            'adset_id' => $ad['adset_id'] ?? null,
            'adset_name' => $ad !== null && $ad['adset_id'] !== null
                ? ($this->names[MetaAdEntity::LEVEL_ADSET.'|'.$ad['adset_id']] ?? null)
                : null,
            'ad_name' => $ad['name'] ?? null,
            'resolved' => $ad !== null,
        ];
    }

    /**
     * Plataforma por el DOMINIO de la URL, no por un `contains` sobre la cadena:
     * «facebook.com.otro.sitio» no es Facebook.
     */
    public static function platformFromUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $host = parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(rtrim($host, '.'));

        if ($host === 'fb.me' || $host === 'facebook.com' || str_ends_with($host, '.facebook.com')) {
            return self::PLATFORM_FACEBOOK;
        }

        if ($host === 'instagram.com' || str_ends_with($host, '.instagram.com')) {
            return self::PLATFORM_INSTAGRAM;
        }

        return null;
    }

    /**
     * La fila de «Origen de leads» a la que va un contacto.
     *
     * Las seis claves del contrato cubren todo lo que hoy llega. Un anuncio o una
     * publicación sin plataforma reconocible van a `meta_ads`/`meta_organic`, y
     * un `direct` fuera de WhatsApp a `<canal>_direct`: antes que inventarles una
     * plataforma, una fila propia que solo aparece si tiene leads.
     */
    public static function keyFor(string $origin, ?string $platform, ?string $channel): string
    {
        return match ($origin) {
            self::ORIGIN_PAID => $platform !== null ? $platform.'_ads' : 'meta_ads',
            self::ORIGIN_ORGANIC => $platform !== null ? $platform.'_organic' : 'meta_organic',
            self::ORIGIN_DIRECT => ($channel !== null && $channel !== '' ? strtolower($channel) : 'whatsapp').'_direct',
            default => self::KEY_UNATTRIBUTED,
        };
    }

    public static function labelFor(string $key): string
    {
        if (isset(self::LABELS[$key])) {
            return self::LABELS[$key];
        }

        if (str_ends_with($key, '_direct')) {
            return ucfirst(substr($key, 0, -7)).' directo';
        }

        return $key;
    }

    /**
     * Un id de Meta enmascarado como en los mensajes de error de la
     * sincronización: «***1234». Sirve para cotejarlo a mano en el
     * Administrador de anuncios sin publicar el id entero.
     */
    public static function maskId(?string $id): ?string
    {
        $id = trim((string) $id);
        if ($id === '') {
            return null;
        }

        return '***'.substr($id, -4);
    }

    /**
     * Una serie de 8 o más dígitos, aunque lleve entre ellos separadores cortos
     * que no son letras: «3001234567», «+57 (316) 888-9900», «316/888/9900»,
     * «316_888_9900», «1202-3000-1234-567».
     */
    private const DIGIT_RUN = '/\+?\d(?:[^\p{L}\d]{0,3}\d){7,}/u';

    /**
     * Una fecha escrita con un solo separador («2026-10-01», «01/10/2026»)
     * también tiene 8 dígitos, pero no es un teléfono ni un id de Meta: se
     * respeta, porque los nombres de campaña la llevan a menudo.
     */
    private const DATE_SHAPE = '/^(?:\d{4}([-\/._])\d{2}\1\d{2}|\d{2}([-\/._])\d{2}\2\d{4})$/';

    private const EMAIL = '/[^\s@]+@[^\s@]+\.[^\s@]+/u';

    /**
     * Un texto libre que va al front, con las series de 8 o más dígitos
     * enmascaradas igual que {@see maskId()} («***9900»).
     *
     * Los nombres los escribe una persona en el Administrador de anuncios o en
     * su perfil de WhatsApp, y nada impide que lleven el id del anuncio o un
     * teléfono con cualquier formato («Video 120230001…», «(316) 888-9900»).
     * Las series cuentan aunque lleven separadores cortos que no son letras;
     * solo se respeta una fecha con forma de fecha. Lo demás queda intacto: no
     * se usa el saneador de errores de la sincronización porque tacharía
     * nombres legítimos largos con guiones bajos.
     */
    public static function maskDigits(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return preg_replace_callback(
            self::DIGIT_RUN,
            static fn (array $m): string => preg_match(self::DATE_SHAPE, $m[0]) === 1
                ? $m[0]
                : '***'.substr(preg_replace('/\D+/', '', $m[0]) ?? '', -4),
            $text,
        ) ?? $text;
    }

    /**
     * El nombre de una persona tal como va al front: sin teléfonos (ver
     * {@see maskDigits()}) y sin correos. Hay quien pone su número o su correo
     * como nombre de perfil de WhatsApp.
     */
    public static function maskPersonName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        return self::maskDigits(preg_replace(self::EMAIL, '***@***', $name) ?? $name);
    }

    // ── Interno ─────────────────────────────────────────────────────────────

    /**
     * El tipo, el anuncio y la plataforma de un contacto.
     *
     * Cómo guarda la fila LeadAttributionService: el primer contacto se congela
     * en `first_touch_*` y en `source_platform`; el último se actualiza en
     * `last_touch_*`, y `source_url` pasa a ser la del contacto más reciente que
     * trajo URL.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function touchOf(MarketingLeadAttribution $a, string $touch): array
    {
        $firstType = $a->first_touch_source_type ?? $a->source_type;
        $firstAd = $a->first_touch_ad_id
            ?? ($firstType === MarketingLeadAttribution::SOURCE_AD ? $a->ad_id : null);

        if ($touch === 'first' || ! $this->hadLaterTouch($a) || $a->last_touch_source_type === null) {
            return [$firstType, $firstAd, $this->firstTouchPlatform($a)];
        }

        return [$a->last_touch_source_type, $a->last_touch_ad_id, self::platformFromUrl($a->source_url)];
    }

    private function firstTouchPlatform(MarketingLeadAttribution $a): ?string
    {
        $stored = strtolower(trim((string) $a->source_platform));
        if (in_array($stored, [self::PLATFORM_FACEBOOK, self::PLATFORM_INSTAGRAM], true)) {
            return $stored;
        }

        // Sin plataforma guardada, la URL solo es la del primer contacto si no
        // hubo otro después: un contacto posterior la sobrescribe.
        return $this->hadLaterTouch($a) ? null : self::platformFromUrl($a->source_url);
    }

    /**
     * ¿Hubo un contacto con referral después del primero? Al crear la fila,
     * LeadAttributionService pone el último contacto igual al primero, y solo un
     * referral nuevo lo mueve.
     */
    private function hadLaterTouch(MarketingLeadAttribution $a): bool
    {
        if ($a->first_touch_at === null || $a->last_touch_at === null) {
            return $a->last_touch_source_type !== $a->first_touch_source_type
                || $a->last_touch_ad_id !== $a->first_touch_ad_id;
        }

        return ! $a->first_touch_at->equalTo($a->last_touch_at);
    }

    /** @return array{name: ?string, campaign_id: ?string, adset_id: ?string}|null */
    private function ad(?string $adId): ?array
    {
        if ($adId === null) {
            return null;
        }

        if (! array_key_exists($adId, $this->ads)) {
            $this->preload([$adId]);
        }

        return $this->ads[$adId] ?? null;
    }

    /** Nombres de las campañas y conjuntos de los anuncios ya cargados que aún falten. */
    private function preloadNames(): void
    {
        $wanted = [MetaAdEntity::LEVEL_CAMPAIGN => [], MetaAdEntity::LEVEL_ADSET => []];

        foreach ($this->ads as $ad) {
            if ($ad === null) {
                continue;
            }
            foreach ([MetaAdEntity::LEVEL_CAMPAIGN => $ad['campaign_id'], MetaAdEntity::LEVEL_ADSET => $ad['adset_id']] as $level => $id) {
                if ($id !== null && ! array_key_exists($level.'|'.$id, $this->names)) {
                    $wanted[$level][$id] = true;
                }
            }
        }

        foreach ($wanted as $level => $ids) {
            foreach (array_chunk(array_keys($ids), 500) as $chunk) {
                $chunk = array_map('strval', $chunk);
                $found = MetaAdEntity::query()
                    ->forConfiguredAccount()
                    ->where('level', $level)
                    ->whereIn('entity_id', $chunk)
                    ->pluck('name', 'entity_id');

                foreach ($chunk as $id) {
                    $this->names[$level.'|'.$id] = $found->get($id);
                }
            }
        }
    }
}
