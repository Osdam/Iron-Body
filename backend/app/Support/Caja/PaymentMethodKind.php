<?php

namespace App\Support\Caja;

/**
 * Medio de pago CANÓNICO para el arqueo de caja.
 *
 * Existe porque las dos fuentes de dinero hablan idiomas distintos y ninguna
 * tiene enum ni constraint:
 *
 *   payments.method            → efectivo, transferencia, datafono, manual,
 *                                cash, transfer, card, wompi, nequi, other
 *   product_sales.payment_method → cash, card, online, nequi, transfer
 *
 * Solo CASH entra en `expected_cash`: una transferencia o un datáfono no ponen
 * billetes en el cajón, y contarlos haría que la caja "faltara" siempre.
 *
 * Sobre `manual`: NO es un medio de pago. Lo produce únicamente
 * ImportLegacyCrmCommand::metodoPago() cuando el texto legado traía cero o más
 * de un medio, y son los 1.484 registros `MIGR-*`. Se mapea a OTHER —nunca a
 * CASH— para que, si alguno llegara a caer dentro de una ventana de turno, no
 * inflara el efectivo esperado. Ningún flujo vivo lo crea; ver
 * {@see PaymentOrigin}.
 */
enum PaymentMethodKind: string
{
    case CASH = 'cash';
    case TRANSFER = 'transfer';
    case CARD = 'card';
    case WOMPI = 'wompi';
    case OTHER = 'other';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Efectivo',
            self::TRANSFER => 'Transferencia',
            self::CARD => 'Tarjeta',
            self::WOMPI => 'Wompi',
            self::OTHER => 'Otros',
        };
    }

    /** ¿Este medio deja dinero físico en el cajón? Solo el efectivo. */
    public function isCash(): bool
    {
        return $this === self::CASH;
    }

    /**
     * Normaliza cualquier valor histórico o actual al medio canónico.
     *
     * Desconocido → OTHER, nunca CASH. Un medio que no sabemos leer no puede
     * aumentar el efectivo que alguien tendrá que cuadrar a mano.
     */
    public static function normalize(?string $raw): self
    {
        $key = strtolower(trim((string) $raw));

        return match ($key) {
            'cash', 'efectivo' => self::CASH,
            'transfer', 'transferencia', 'nequi', 'daviplata', 'pse' => self::TRANSFER,
            'card', 'datafono', 'datáfono', 'tarjeta' => self::CARD,
            'wompi', 'online', 'epayco' => self::WOMPI,
            // '', 'manual', 'other' y cualquier valor no reconocido.
            default => self::OTHER,
        };
    }

    /**
     * Medios que un empleado puede seleccionar al cobrar en mostrador.
     *
     * `manual` queda deliberadamente fuera: un cobro operativo nuevo no puede
     * nacer sin saber cómo se pagó. WOMPI tampoco: no se cobra a mano por
     * pasarela, lo registra la propia pasarela.
     *
     * @return string[]
     */
    public static function selectableAtCounter(): array
    {
        return [self::CASH->value, self::TRANSFER->value, self::CARD->value, self::OTHER->value];
    }
}
