<?php

namespace App\Services\Telephony;

use InvalidArgumentException;

/**
 * Lo que dice la cadena de voz de UNA parte (`canary` o `rest`), ya verificado
 * por {@see VoicePublishedConfig} (contrato H8, D8-8, Enmienda 3, E3-2).
 *
 *  - `ok`: una entrada vigente con su agente; lleva el SELLO completo de
 *    D8-8 (las columnas de `voice_calls` que se escriben al empezar).
 *  - `none`: la parte no tiene ninguna entrada.
 *  - `off`: la entrada vigente apaga la parte (voz apagada, D8-20 nivel 2).
 *  - `unreadable`: algo no verifica (`reason` dice qué, sin valores): un
 *    fallo DENIEGA (D8-8: `publication_unreadable`).
 *
 * Los DESTINOS DE TRANSFERENCIA aprobados (Enmienda 5, E5-1): los compilados
 * en el agente de la parte, solo su alias y la huella `voice-phone` de su
 * número, nunca el número. null = sin escribir o ilegibles (ninguno vale: la
 * guarda trata toda transferencia como no aprobada y `solicitar_humano` dice
 * que no hay nadie).
 */
final class VoicePublishedPart
{
    public const STATUS_OK = 'ok';

    public const STATUS_NONE = 'none';

    public const STATUS_OFF = 'off';

    public const STATUS_UNREADABLE = 'unreadable';

    public const STATUSES = [self::STATUS_OK, self::STATUS_NONE, self::STATUS_OFF, self::STATUS_UNREADABLE];

    /** D8-14: como mucho tres destinos por perfil (`usvp_transfer_chk`). */
    public const MAX_TRANSFER_DESTINATIONS = 3;

    /**
     * @param  list<array{alias: string, hash: string}>|null  $transferDestinations  solo con la parte vigente
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly string $part,
        public readonly string $status,
        public readonly ?int $seq = null,
        public readonly ?string $reason = null,
        public readonly ?string $studioVersionUuid = null,
        public readonly ?string $studioContentHash = null,
        public readonly ?string $voiceProfileUuid = null,
        public readonly ?string $voiceProfileHash = null,
        public readonly ?string $specHash = null,
        public readonly ?string $payloadHash = null,
        public readonly ?string $projectionHash = null,
        public readonly ?string $compilerVersion = null,
        public readonly ?string $providerAgentId = null,
        public readonly ?array $transferDestinations = null,
    ) {
        if (! in_array($part, VoicePublishedConfig::PARTS, true) || ! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Parte o estado de la cadena de voz desconocidos.');
        }
        $sello = [$studioVersionUuid, $studioContentHash, $voiceProfileUuid, $voiceProfileHash, $specHash, $payloadHash, $compilerVersion, $providerAgentId];
        if ($status === self::STATUS_OK && (in_array(null, $sello, true) || $seq === null)) {
            throw new InvalidArgumentException('Una parte vigente lleva su sello completo.');
        }
        if ($status !== self::STATUS_OK && array_filter($sello, static fn ($v) => $v !== null) !== []) {
            throw new InvalidArgumentException('Solo una parte vigente lleva sello.');
        }
        if ($transferDestinations !== null) {
            if ($status !== self::STATUS_OK || ! array_is_list($transferDestinations) || count($transferDestinations) > self::MAX_TRANSFER_DESTINATIONS) {
                throw new InvalidArgumentException('Los destinos de transferencia son de una parte vigente, como mucho tres.');
            }
            foreach ($transferDestinations as $d) {
                if (! is_array($d) || array_keys($d) !== ['alias', 'hash'] || ! is_string($d['alias']) || trim($d['alias']) === ''
                    || ! is_string($d['hash']) || preg_match('/^[0-9a-f]{64}$/D', $d['hash']) !== 1) {
                    throw new InvalidArgumentException('Un destino de transferencia es su alias y su huella, nada más.');
                }
            }
        }
    }

    /** @return list<string>|null las huellas `voice-phone` aprobadas (E5-1); null = no se sabe (ninguna vale) */
    public function transferDestinationHashes(): ?array
    {
        return $this->transferDestinations === null ? null : array_column($this->transferDestinations, 'hash');
    }

    /** @return list<string> los alias aprobados, en el orden del perfil (sin números) */
    public function transferAliases(): array
    {
        return $this->transferDestinations === null ? [] : array_column($this->transferDestinations, 'alias');
    }

    public function ok(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    /** El hecho de la publicación para las políticas: ok, none (sin entrada o apagada) o unreadable. */
    public function policyState(): string
    {
        return match ($this->status) {
            self::STATUS_OK => InboundPolicy::PUBLICATION_OK,
            self::STATUS_UNREADABLE => InboundPolicy::PUBLICATION_UNREADABLE,
            default => InboundPolicy::PUBLICATION_NONE,
        };
    }

    /**
     * Las columnas del sello de `voice_calls` (D8-8). `applied` con la parte
     * vigente; `off` sin publicación o con la parte apagada; `fallback` si la
     * cadena no se pudo leer.
     *
     * @return array<string, int|string|null>
     */
    public function stampColumns(): array
    {
        $estado = match ($this->status) {
            self::STATUS_OK => 'applied',
            self::STATUS_UNREADABLE => 'fallback',
            default => 'off',
        };

        return [
            'voice_publication_seq' => $this->ok() ? $this->seq : null,
            'part' => $this->ok() ? $this->part : null,
            'stamp_state' => $estado,
            'studio_version_uuid' => $this->studioVersionUuid,
            'studio_content_hash' => $this->studioContentHash,
            'voice_profile_hash' => $this->voiceProfileHash,
            'spec_hash' => $this->specHash,
            'payload_hash' => $this->payloadHash,
            'compiler_version' => $this->compilerVersion,
            'provider' => VoicePublishedConfig::PROVIDER,
            'provider_agent_id' => $this->providerAgentId,
        ];
    }

    /** La etiqueta de la variable `version_label` (sin datos personales; ≤ 40 caracteres). */
    public function versionLabel(): string
    {
        $parte = $this->part === VoicePublishedConfig::PART_CANARY ? 'canario' : 'resto';

        return $this->ok() ? "{$parte} · publicación {$this->seq}" : "{$parte} · sin publicación";
    }
}
