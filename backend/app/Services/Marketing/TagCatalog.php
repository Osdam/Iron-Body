<?php

namespace App\Services\Marketing;

use App\Models\MarketingTag;

/**
 * El catálogo de etiquetas que el gimnasio usa de verdad.
 *
 * Vive en código y no solo en la base porque son vocabulario compartido: el
 * motor comercial escribe «alta intención», el inbox la pinta y la analítica la
 * agrupa. Si cada uno pudiera inventarse la suya, tres partes del sistema
 * hablarían de lo mismo con tres nombres distintos.
 *
 * Que esté aquí no impide crear etiquetas propias desde el inbox: las que
 * alguien escriba se dan de alta como manuales. Lo que garantiza el catálogo es
 * que las que el sistema pone tengan siempre el mismo nombre y el mismo
 * significado.
 */
class TagCatalog
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public static function definitions(): array
    {
        return [
            // ── Comerciales: en qué punto está la relación ────────────────
            ...self::commercial(),
            // ── Operativas: qué necesita el equipo hacer ──────────────────
            ...self::operational(),
            // ── Atribución: de dónde vino. Bloqueadas. ────────────────────
            ...self::attribution(),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function commercial(): array
    {
        $c = MarketingTag::CATEGORY_COMMERCIAL;
        $auto = MarketingTag::KIND_AUTOMATIC;

        return [
            ['slug' => 'interesado', 'name' => 'Interesado', 'category' => $c, 'kind' => $auto,
                'color' => 'blue', 'sort_order' => 10,
                'description' => 'Preguntó por planes o precios.'],
            ['slug' => 'alta-intencion', 'name' => 'Alta intención', 'category' => $c, 'kind' => $auto,
                'color' => 'amber', 'sort_order' => 11,
                'description' => 'Señales claras de querer inscribirse pronto.'],
            ['slug' => 'sensible-al-precio', 'name' => 'Sensible al precio', 'category' => $c, 'kind' => $auto,
                'color' => 'violet', 'sort_order' => 12,
                'description' => 'Puso objeciones de precio más de una vez.'],
            ['slug' => 'pago-pendiente', 'name' => 'Pago pendiente', 'category' => $c, 'kind' => $auto,
                'color' => 'orange', 'sort_order' => 13,
                'description' => 'Tiene un enlace de pago generado y sin usar.'],
            ['slug' => 'renovacion', 'name' => 'Renovación', 'category' => $c, 'kind' => $auto,
                'color' => 'green', 'sort_order' => 14,
                'description' => 'Su membresía está por vencer.'],
            ['slug' => 'upgrade', 'name' => 'Upgrade', 'category' => $c, 'kind' => $auto,
                'color' => 'teal', 'sort_order' => 15,
                'description' => 'Usa el gimnasio lo bastante para un plan más largo.'],
            ['slug' => 'reactivacion', 'name' => 'Reactivación', 'category' => $c, 'kind' => $auto,
                'color' => 'rose', 'sort_order' => 16,
                'description' => 'Fue socio y su membresía venció.'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function operational(): array
    {
        $c = MarketingTag::CATEGORY_OPERATIONAL;
        $sys = MarketingTag::KIND_SYSTEM;

        return [
            ['slug' => 'requiere-revision', 'name' => 'Requiere revisión', 'category' => $c, 'kind' => $sys,
                'color' => 'red', 'sort_order' => 1,
                'description' => 'Algo necesita que lo mire una persona.'],
            // La pauta sigue publicada en Meta y el catalogo ya cambio. Quien
            // atiende tiene que verlo ANTES de contestar, y quien lleva la
            // pauta tiene que poder listar cuantas conversaciones llegaron con
            // una oferta que ya no existe.
            ['slug' => 'pauta-desactualizada', 'name' => 'Pauta desactualizada', 'category' => $c, 'kind' => $sys,
                'color' => 'amber', 'sort_order' => 2,
                'description' => 'Llego por un anuncio que promete algo que ya no esta vigente.'],
            ['slug' => 'humano', 'name' => 'Humano', 'category' => $c, 'kind' => $sys,
                'color' => 'amber', 'sort_order' => 2,
                'description' => 'La conversación la lleva una persona; la IA está en pausa.'],
            ['slug' => 'pendiente', 'name' => 'Pendiente', 'category' => $c, 'kind' => MarketingTag::KIND_MANUAL,
                'color' => 'neutral', 'sort_order' => 3,
                'description' => 'Queda algo por hacer en este caso.'],
            ['slug' => 'facturacion', 'name' => 'Facturación', 'category' => $c, 'kind' => MarketingTag::KIND_MANUAL,
                'color' => 'blue', 'sort_order' => 4,
                'description' => 'Asunto de factura electrónica.'],
            ['slug' => 'soporte', 'name' => 'Soporte', 'category' => $c, 'kind' => MarketingTag::KIND_MANUAL,
                'color' => 'violet', 'sort_order' => 5,
                'description' => 'Problema técnico o de acceso, no comercial.'],
        ];
    }

    /**
     * Etiquetas de origen. Todas BLOQUEADAS.
     *
     * No son opiniones del equipo: son la lectura de lo que el canal informó.
     * Poder editarlas a mano convertiría la analítica de pauta en algo que
     * nadie podría defender.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function attribution(): array
    {
        $c = MarketingTag::CATEGORY_ATTRIBUTION;
        $src = MarketingTag::KIND_SOURCE;

        return [
            ['slug' => 'meta-ads', 'name' => 'Meta Ads', 'category' => $c, 'kind' => $src,
                'color' => 'sky', 'locked' => true, 'sort_order' => 20,
                'description' => 'Llegó tocando un anuncio pagado.'],
            ['slug' => 'instagram', 'name' => 'Instagram', 'category' => $c, 'kind' => $src,
                'color' => 'pink', 'locked' => true, 'sort_order' => 21,
                'description' => 'El anuncio o publicación estaba en Instagram.'],
            ['slug' => 'facebook', 'name' => 'Facebook', 'category' => $c, 'kind' => $src,
                'color' => 'sky', 'locked' => true, 'sort_order' => 22,
                'description' => 'El anuncio o publicación estaba en Facebook.'],
            ['slug' => 'organico', 'name' => 'Orgánico', 'category' => $c, 'kind' => $src,
                'color' => 'green', 'locked' => true, 'sort_order' => 23,
                'description' => 'Vino de una publicación sin pauta.'],
            ['slug' => 'referido', 'name' => 'Referido', 'category' => $c, 'kind' => $src,
                'color' => 'teal', 'locked' => true, 'sort_order' => 24,
                'description' => 'Llegó recomendado por alguien.'],
            ['slug' => 'origen-desconocido', 'name' => 'Origen desconocido', 'category' => $c, 'kind' => $src,
                'color' => 'neutral', 'locked' => true, 'sort_order' => 25,
                'description' => 'El canal no informó de dónde vino. No se ha inventado nada.'],
        ];
    }

    /**
     * Da de alta lo que falte, sin pisar lo que alguien haya personalizado.
     *
     * Se actualiza únicamente lo estructural —categoría, tipo y bloqueo—, que
     * es lo que el sistema necesita que sea correcto. El nombre y el color se
     * dejan como estén: si alguien renombró una etiqueta en el CRM, sabrá por
     * qué lo hizo mejor que este archivo.
     */
    public static function sync(): int
    {
        $touched = 0;

        foreach (self::definitions() as $definition) {
            $tag = MarketingTag::query()->firstOrNew(['slug' => $definition['slug']]);

            if (! $tag->exists) {
                $tag->fill($definition + ['locked' => false, 'active' => true]);
                $tag->save();
                $touched++;

                continue;
            }

            $tag->forceFill([
                'category' => $definition['category'],
                'kind' => $definition['kind'],
                'locked' => (bool) ($definition['locked'] ?? false),
            ])->save();
            $touched++;
        }

        return $touched;
    }
}
