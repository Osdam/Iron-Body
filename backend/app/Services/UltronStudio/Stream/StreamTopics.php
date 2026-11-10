<?php

namespace App\Services\UltronStudio\Stream;

use App\Services\Outreach\Run\Stream\OutreachBrakesTopic;
use App\Services\Outreach\Run\Stream\OutreachJobsTopic;
use App\Services\Outreach\Run\Stream\OutreachOutcomesTopic;
use App\Services\Outreach\Run\Stream\OutreachRunsTopic;
use App\Services\UltronStudio\StudioAccess;
use InvalidArgumentException;

/**
 * El REGISTRO de temas del canal (contrato H9, «Paquete SSE9»): los once
 * nombres del contrato, cada uno con su adaptador, construidos PARA QUIEN
 * MIRA ({@see for()}): algún tema filtra filas por la identidad de la persona
 * (el libro oculta los borradores ajenos sin `audit.view`, como su GET).
 *
 * Una prueba lo sustituye dejando una instancia en el contenedor
 * (`app()->instance(StreamTopics::class, …)`); el controlador la prefiere.
 *
 * B9-run cambia en {@see defaults()} el {@see PendingTopic} de cada
 * `outreach.*` por su adaptador real (misma interfaz, mismo nombre) y nada
 * más del canal cambia.
 */
final class StreamTopics
{
    /** Los nombres del contrato, en su orden. */
    public const NAMES = [
        'ledger',
        'voice.calls', 'voice.brake', 'voice.reconcile', 'voice.publications', 'voice.lab',
        'monitor.incidents',
        'outreach.runs', 'outreach.jobs', 'outreach.outcomes', 'outreach.brakes',
    ];

    /** @var array<string, StreamTopic> */
    private array $topics = [];

    /** @param  iterable<StreamTopic>  $topics */
    public function __construct(iterable $topics)
    {
        foreach ($topics as $topic) {
            $this->register($topic);
        }
    }

    /** El catálogo del contrato para la persona que mira. */
    public static function for(StudioAccess $viewer): self
    {
        return new self(self::defaults($viewer));
    }

    /**
     * El catálogo del contrato. Los `outreach.*` están PENDIENTES de B9-run:
     * registrados y vacíos; su permiso provisional es la base del Studio
     * (`ultron.view`), a fijar por B9-run con el permiso del GET de lista de
     * sus rutas cuando existan en AuthorizationMap.
     *
     * @return list<StreamTopic>
     */
    public static function defaults(StudioAccess $viewer): array
    {
        return [
            new LedgerTopic($viewer),
            new VoiceCallsTopic,
            new VoiceBrakeTopic,
            new VoiceReconcileTopic,
            new VoicePublicationsTopic,
            new VoiceLabTopic,
            new IncidentsTopic,
            new OutreachRunsTopic,
            new OutreachJobsTopic,
            new OutreachOutcomesTopic,
            new OutreachBrakesTopic,
        ];
    }

    public function register(StreamTopic $topic): void
    {
        $name = $topic->name();
        if (preg_match(StreamCursors::TOPIC, $name) !== 1) {
            throw new InvalidArgumentException("ultron_studio stream: nombre de tema inválido «{$name}».");
        }
        if (isset($this->topics[$name])) {
            throw new InvalidArgumentException("ultron_studio stream: el tema «{$name}» ya está registrado.");
        }
        $this->topics[$name] = $topic;
    }

    public function has(string $name): bool
    {
        return isset($this->topics[$name]);
    }

    public function get(string $name): StreamTopic
    {
        return $this->topics[$name] ?? throw new InvalidArgumentException("ultron_studio stream: tema desconocido «{$name}».");
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->topics);
    }

    /** @return array<string, StreamTopic> */
    public function all(): array
    {
        return $this->topics;
    }
}
