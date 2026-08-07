<?php

namespace App\Services\Marketing\Attribution;

/**
 * ¿Sigue existiendo lo que decía el anuncio?
 *
 * Un anuncio vive en Meta y el catálogo vive en el CRM, y se desincronizan
 * solos: la pauta sigue publicada semanas después de que el plan subiera de
 * precio o desapareciera. Alguien llega diciendo «vi el mensual a 80.000» y
 * hoy cuesta otra cosa.
 *
 * La respuesta correcta no es discutir ni improvisar un descuento: es saber que
 * hay una diferencia, no prometer lo que ya no existe, mirar el catálogo y
 * ofrecer lo real. Este objeto es lo que le permite al agente saberlo antes de
 * abrir la boca.
 */
final class OfferConsistency
{
    /** El anuncio no prometía un producto concreto: no hay nada que contrastar. */
    public const NOT_ADVERTISED = 'not_advertised';

    /** Lo anunciado existe y está vigente. */
    public const MATCHES = 'matches';

    /** El plan anunciado ya no está activo o no existe. */
    public const PLAN_UNAVAILABLE = 'plan_unavailable';

    /** Existe, pero el precio del anuncio no es el de hoy. */
    public const PRICE_CHANGED = 'price_changed';

    private function __construct(
        public readonly string $status,
        public readonly ?string $advertisedProduct,
        public readonly ?float $advertisedPrice,
        public readonly ?float $currentPrice,
        public readonly ?int $currentPlanId,
    ) {}

    public static function notAdvertised(): self
    {
        return new self(self::NOT_ADVERTISED, null, null, null, null);
    }

    public static function matches(?string $product, ?float $currentPrice, ?int $planId): self
    {
        return new self(self::MATCHES, $product, null, $currentPrice, $planId);
    }

    public static function planUnavailable(?string $product): self
    {
        return new self(self::PLAN_UNAVAILABLE, $product, null, null, null);
    }

    public static function priceChanged(?string $product, float $advertised, float $current, ?int $planId): self
    {
        return new self(self::PRICE_CHANGED, $product, $advertised, $current, $planId);
    }

    /** ¿Se puede usar lo anunciado como punto de partida de una recomendación? */
    public function isUsable(): bool
    {
        return $this->status === self::MATCHES || $this->status === self::NOT_ADVERTISED;
    }

    public function needsAttention(): bool
    {
        return $this->status === self::PLAN_UNAVAILABLE || $this->status === self::PRICE_CHANGED;
    }

    /**
     * La instrucción para el agente, en una línea.
     *
     * Se le dice qué NO hacer y qué hacer en su lugar, sin darle el precio del
     * anuncio: si lo tuviera a mano acabaría repitiéndolo. Tampoco se le pide
     * que le explique al cliente que hubo un desajuste de pauta —eso es un
     * problema nuestro, no suyo— sino que mire las opciones actuales.
     */
    public function agentNote(): ?string
    {
        return match ($this->status) {
            self::PLAN_UNAVAILABLE => 'Lo que anunciaba la pauta ya no está disponible. NO lo prometas. '
                .'Consulta active_plans y ofrece con naturalidad la alternativa vigente más parecida.',
            self::PRICE_CHANGED => 'El precio que aparecía en la pauta ya no es el vigente. Usa SIEMPRE el de '
                .'active_plans y no repitas el del anuncio, aunque la persona lo mencione.',
            default => null,
        };
    }

    /** @return array<string,mixed> Lo que se registra como alerta comercial. */
    public function toEvidence(): array
    {
        return array_filter([
            'status' => $this->status,
            'advertised_product' => $this->advertisedProduct,
            'advertised_price' => $this->advertisedPrice,
            'current_price' => $this->currentPrice,
            'current_plan_id' => $this->currentPlanId,
        ], fn ($v) => $v !== null);
    }
}
