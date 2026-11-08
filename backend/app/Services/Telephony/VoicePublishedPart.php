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
 */
final class VoicePublishedPart
{
    public const STATUS_OK = 'ok';

    public const STATUS_NONE = 'none';

    public const STATUS_OFF = 'off';

    public const STATUS_UNREADABLE = 'unreadable';

    public const STATUSES = [self::STATUS_OK, self::STATUS_NONE, self::STATUS_OFF, self::STATUS_UNREADABLE];

    /** @throws InvalidArgumentException */
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
