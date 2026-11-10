<?php

namespace App\Services\Outreach\Core;

use InvalidArgumentException;

/**
 * La SELECCIÓN DE CANAL, determinista y explicable (contrato H9, D9-9.2;
 * fixture `channel-selection-cases.json`; T13, T19, T22). PURA.
 *
 * Recorre la lista ORDENADA de canales del paso y elige el PRIMERO que la
 * escalera ({@see PolicyLadder}, evaluada para cada canal) permite. Si ninguno
 * (DF-S2):
 *  - si alguno solo falla por TIEMPO (su veredicto es `defer`): `defer` al
 *    `retryAt` más temprano de esos (a igualdad, el orden);
 *  - si todos niegan: `deny`, con los motivos de cada canal.
 * Sin azar ni empates posibles.
 *
 * LA PREFERENCIA de la persona (N7, DF-S1): si algún canal de su medio está
 * permitido, los de ese medio pasan delante conservando su orden relativo
 * (`preference_applied`); si ninguno de su medio está permitido (o el paso no
 * tiene ninguno), se ignora (`instruction_ignored_by_policy`) y manda el orden
 * del paso. Nunca levanta una negativa.
 */
final class ChannelSelector
{
    public const SELECTED = 'selected';

    public const DEFER = 'defer';

    public const DENY = 'deny';

    /**
     * @param  list<string>  $channels  los canales del paso, en su orden (sin repetir)
     * @param  array<string, array{verdict: string, level: string|null, reasons: list<string>, retryAt: string|null}>  $perChannel  la decisión de la escalera de cada canal
     * @param  string|null  $preferredMedium  `whatsapp` o `voice`, o null
     * @return array{verdict: string, channel: string|null, retryAt: string|null, order: list<string>, annotations: list<string>, perChannel: list<array{channel: string, verdict: string, level: string|null, reasons: list<string>, retryAt: string|null}>}
     *
     * @throws InvalidArgumentException si los canales o las decisiones no tienen la forma
     */
    public static function choose(array $channels, array $perChannel, ?string $preferredMedium = null): array
    {
        // El tope de 3 por paso lo comprueba el contenido (StrategyContent); aquí basta que sean del conjunto y sin repetir.
        if (! array_is_list($channels) || $channels === [] || count(array_unique($channels)) !== count($channels)
            || array_diff($channels, array_keys(OutreachCatalog::STEP_CHANNELS)) !== []) {
            throw new InvalidArgumentException('Los canales son del conjunto cerrado y sin repetir.');
        }
        if ($preferredMedium !== null && ! in_array($preferredMedium, OutreachCatalog::MEDIUMS, true)) {
            throw new InvalidArgumentException('La preferencia es un medio del conjunto cerrado.');
        }
        $filas = [];
        foreach ($channels as $canal) {
            $d = $perChannel[$canal] ?? null;
            if (! is_array($d) || ! in_array($d['verdict'] ?? null, ['allow', 'defer', 'deny'], true) || ($d['verdict'] === 'defer' && ! RelativeTime::isInstant($d['retryAt'] ?? null))) {
                throw new InvalidArgumentException("Falta la decisión de la escalera del canal {$canal}.");
            }
            $filas[] = ['channel' => $canal, 'verdict' => $d['verdict'], 'level' => $d['level'] ?? null, 'reasons' => $d['reasons'] ?? [], 'retryAt' => $d['retryAt'] ?? null];
        }
        $permitido = static fn (string $c): bool => $perChannel[$c]['verdict'] === 'allow';

        $orden = $channels;
        $notas = [];
        if ($preferredMedium !== null) {
            $suyos = array_values(array_filter($channels, static fn (string $c): bool => OutreachCatalog::mediumOf($c) === $preferredMedium));
            if (array_filter($suyos, $permitido) !== []) {
                $orden = [...$suyos, ...array_values(array_diff($channels, $suyos))];
                $notas[] = 'preference_applied';
            } else {
                $notas[] = 'instruction_ignored_by_policy';
            }
        }

        foreach ($orden as $canal) {
            if ($permitido($canal)) {
                return ['verdict' => self::SELECTED, 'channel' => $canal, 'retryAt' => null, 'order' => $orden, 'annotations' => $notas, 'perChannel' => $filas];
            }
        }
        $antes = null;
        foreach ($orden as $canal) {
            $d = $perChannel[$canal];
            if ($d['verdict'] === 'defer' && ($antes === null || $d['retryAt'] < $antes)) {
                $antes = $d['retryAt'];
            }
        }

        return ['verdict' => $antes === null ? self::DENY : self::DEFER, 'channel' => null, 'retryAt' => $antes, 'order' => $orden, 'annotations' => $notas, 'perChannel' => $filas];
    }
}
