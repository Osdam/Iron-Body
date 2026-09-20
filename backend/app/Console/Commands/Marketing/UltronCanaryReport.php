<?php

namespace App\Console\Commands\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\MobileAppLinks;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesAgentDecisionSchema;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * El acta del canario, turno a turno.
 *
 * No inventa una nota global. Reconstruye lo que de verdad pasó desde lo que el
 * sistema ya persiste —los mensajes y `marketing_ai_actions`— y separa dos cosas
 * que no se deben mezclar:
 *
 *  - Lo MECÁNICO, que se cuenta sin opinión: una oferta de traspaso que salió,
 *    un plan que no se vende, una cifra que no está en el catálogo, un enlace
 *    que no es de los oficiales, un dato personal en un mensaje de máquina.
 *    Esos números tienen que ser CERO, y si no lo son el canario falló.
 *  - Lo que exige CRITERIO: si alucinó, si perdió el hilo, si cansó repitiendo.
 *    Aquí no se pone un número inventado: se pone delante la evidencia que hay
 *    (el veredicto del Critic, la similitud con respuestas previas, cómo se
 *    resolvió el referente) y la nota la pone la persona que lo lea.
 *
 * Nunca imprime secretos. El teléfono y el documento se enmascaran salvo cuando
 * la comprobación consiste precisamente en buscarlos dentro de un saliente, y
 * entonces se dice que aparecieron, no cuáles son.
 */
class UltronCanaryReport extends Command
{
    protected $signature = 'ultron:canary-report {conversation : id de la conversación del canario} {--turns=40 : cuántos turnos como máximo} {--since= : cuenta solo desde esta fecha/hora (ISO), para no arrastrar el histórico} {--json : salida en JSON para archivar}';

    protected $description = 'Acta turno a turno del canario de ULTRON: qué entró, qué decidió, qué salió y qué no cuadra.';

    public function handle(): int
    {
        $conversation = MarketingConversation::with('lead')->find((int) $this->argument('conversation'));
        if ($conversation === null) {
            $this->error('Esa conversación no existe.');

            return self::FAILURE;
        }

        /*
         * La ventana. La conversación del canario existe desde antes —la 19
         * arrastra cuarenta turnos del asesor anterior—, así que sin esto el
         * acta cuenta como hallazgos del canario lo que dijo otro sistema en
         * junio. Los contadores tienen que hablar SOLO de lo que pasó a partir
         * de que el canario empezó.
         */
        $desde = null;
        if (($crudo = (string) $this->option('since')) !== '') {
            try {
                $desde = Carbon::parse($crudo);
            } catch (Throwable) {
                $this->error('No entiendo esa fecha: --since='.$crudo);

                return self::FAILURE;
            }
        }

        $mensajes = MarketingMessage::query()
            ->where('conversation_id', $conversation->id)
            ->when($desde, fn ($q) => $q->where('created_at', '>=', $desde))
            ->orderBy('id')
            ->get(['id', 'direction', 'sender_type', 'body', 'metadata', 'created_at']);

        $acciones = MarketingAiAction::query()
            ->where('conversation_id', $conversation->id)
            ->when($desde, fn ($q) => $q->where('created_at', '>=', $desde))
            ->orderBy('id')
            ->get(['id', 'action_type', 'status', 'source_type', 'source_event_id', 'metadata', 'created_at']);

        $turnos = $this->turnos($mensajes, $acciones, (int) $this->option('turns'));
        $vendibles = Plan::query()->sellable()->get(['id', 'name', 'price']);
        $hallazgos = $this->revisar($turnos, $vendibles, $conversation);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'conversation_id' => $conversation->id,
                'since' => $desde?->toIso8601String(),
                'turns' => $turnos,
                'findings' => $hallazgos,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $hallazgos['mecanicos_total'] === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->imprimir($conversation, $turnos, $hallazgos, $desde);

        return $hallazgos['mecanicos_total'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Un turno es un entrante de la persona y todo lo que vino detrás.
     *
     * @return list<array<string,mixed>>
     */
    private function turnos(Collection $mensajes, Collection $acciones, int $max): array
    {
        $entrantes = $mensajes->where('direction', MarketingMessage::DIRECTION_INBOUND)->values();
        $turnos = [];

        foreach ($entrantes as $i => $entrante) {
            $siguiente = $entrantes[$i + 1]->id ?? PHP_INT_MAX;

            $salientes = $mensajes
                ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)
                ->filter(fn (MarketingMessage $m) => $m->id > $entrante->id && $m->id < $siguiente)
                ->values();

            /*
             * El turno se ata por `source_event_id`, que es donde el commit
             * escribe el id del mensaje que lo disparó; `metadata.message_id`
             * es el respaldo para las filas del router de entrada. No existe
             * columna `message_id` en la tabla, y darla por buena dejaba todos
             * los turnos sin decisión, que es el fallo que cazó la prueba.
             */
            $accion = $acciones->first(fn (MarketingAiAction $a) => (int) $a->source_event_id === (int) $entrante->id
                || (int) data_get($a->metadata, 'message_id') === (int) $entrante->id);
            $meta = is_array($accion?->metadata) ? $accion->metadata : [];

            $primera = $salientes->first();
            $turnos[] = [
                'turno' => $i + 1,
                'entrante' => (string) $entrante->body,
                'en' => optional($entrante->created_at)->toIso8601String(),
                'accion' => $accion?->action_type,
                'estado' => $accion?->status,
                'outcome' => $meta['outcome'] ?? null,
                'blocked_reason' => $meta['blocked_reason'] ?? null,
                'intent' => $meta['intent'] ?? null,
                'strategy_goal' => $meta['strategy_goal'] ?? null,
                'next_best_action' => $meta['next_best_action'] ?? null,
                'referente' => $meta['reference_resolution'] ?? null,
                'temperatura' => $meta['lead_temperature'] ?? null,
                'ciclo_vida' => $meta['customer_lifecycle'] ?? null,
                'fase' => ($meta['previous_phase'] ?? '?').' → '.($meta['next_phase'] ?? '?'),
                'plan' => $meta['recommended_plan_id'] ?? null,
                'herramientas_pedidas' => $meta['tools_requested'] ?? [],
                'herramientas_ejecutadas' => $meta['tools_executed'] ?? [],
                'critic' => $meta['critic'] ?? null,
                'riesgos' => $meta['risk_flags'] ?? [],
                'novedad' => $meta['novelty_max_similarity'] ?? null,
                'revision_humana' => $meta['needs_staff_review'] ?? false,
                'salientes' => $salientes->map(fn (MarketingMessage $m) => [
                    'id' => (int) $m->id,
                    'tipo' => data_get($m->metadata, 'kind', 'text'),
                    'texto' => (string) $m->body,
                ])->all(),
                'latencia_s' => $primera !== null && $entrante->created_at !== null && $primera->created_at !== null
                    ? $entrante->created_at->diffInSeconds($primera->created_at)
                    : null,
            ];

            if (count($turnos) >= $max) {
                break;
            }
        }

        return $turnos;
    }

    /**
     * Lo que no cuadra, separado en lo que se cuenta y lo que se juzga.
     *
     * @param  list<array<string,mixed>>  $turnos
     * @return array<string,mixed>
     */
    private function revisar(array $turnos, Collection $vendibles, MarketingConversation $conversation): array
    {
        $guard = app(OutboundContentGuard::class);
        $noVendibles = Plan::query()->whereNotIn('id', $vendibles->pluck('id'))->pluck('name')->all();
        $precios = $vendibles->map(fn (Plan $p) => number_format((float) $p->price, 0, ',', '.'))->all();
        $enlacesOficiales = [MobileAppLinks::ANDROID, MobileAppLinks::IOS, MobileAppLinks::WEB];

        $telefono = preg_replace('/\D+/', '', (string) ($conversation->lead?->phone ?? ''));
        $mecanicos = [
            'traspasos_no_autorizados' => [],
            'planes_no_vendibles' => [],
            'precios_que_no_son_del_catalogo' => [],
            'enlaces_no_oficiales' => [],
            'datos_personales' => [],
        ];

        foreach ($turnos as $t) {
            foreach ($t['salientes'] as $s) {
                $texto = $s['texto'];
                $ref = 'turno '.$t['turno'].' · mensaje '.$s['id'];

                if ($guard->handoffOfferIn($texto) !== null) {
                    $mecanicos['traspasos_no_autorizados'][] = $ref;
                }

                /*
                 * Un plan que no se vende, NOMBRADO. Dos cautelas, y las dos
                 * las encontró el acta de la conversación 19 contándose a sí
                 * misma: si un plan interno se llama «Mensual» y el vendible
                 * «Plan Mensual», decir el bueno mencionaba al malo; y sin
                 * bordes, «mensual» dentro de «mensualidad» también contaba.
                 * Un contador que grita por una respuesta correcta aborta el
                 * canario por nada, que es la peor forma de fallar.
                 */
                $sinVendibles = $texto;
                foreach ($vendibles as $vendible) {
                    $sinVendibles = str_ireplace((string) $vendible->name, ' ', $sinVendibles);
                }
                foreach ($noVendibles as $nombre) {
                    if ($nombre === '') {
                        continue;
                    }
                    $patron = '/(?<![a-z0-9áéíóúüñ])'.preg_quote((string) $nombre, '/').'(?![a-z0-9áéíóúüñ])/iu';
                    if (preg_match($patron, $sinVendibles) === 1) {
                        $mecanicos['planes_no_vendibles'][] = $ref.' ('.$nombre.')';
                    }
                }

                /*
                 * Una cifra que el CRM no escribió. Los mensajes que compone
                 * Laravel (enlace de pago, inicio) llevan el precio del catálogo.
                 *
                 * Las URLs se quitan ANTES de mirar: el identificador de la app
                 * en la tienda de Apple es un número largo y se leía como un
                 * precio inventado, que es la clase de falso positivo que
                 * volvería inútil este contador.
                 */
                $sinEnlaces = preg_replace('~https?://\S+~u', ' ', $texto) ?? $texto;
                if (SalesAgentDecisionSchema::containsPrice($sinEnlaces)) {
                    $delCatalogo = false;
                    foreach ($precios as $p) {
                        if (str_contains($sinEnlaces, (string) $p)) {
                            $delCatalogo = true;
                        }
                    }
                    if (! $delCatalogo) {
                        $mecanicos['precios_que_no_son_del_catalogo'][] = $ref;
                    }
                }

                if (OutboundContentGuard::containsUrl($texto)) {
                    $conocido = str_contains($texto, 'checkout.wompi.co');
                    foreach ($enlacesOficiales as $enlace) {
                        if (str_contains($texto, (string) $enlace)) {
                            $conocido = true;
                        }
                    }
                    if (! $conocido) {
                        $mecanicos['enlaces_no_oficiales'][] = $ref;
                    }
                }

                // El teléfono de la persona no tiene por qué volver escrito en
                // un mensaje de máquina, y un correo o un documento tampoco.
                $sinPuntos = preg_replace('/\D+/', '', $texto) ?? '';
                if ($telefono !== '' && strlen($telefono) >= 7 && str_contains($sinPuntos, $telefono)) {
                    $mecanicos['datos_personales'][] = $ref.' (teléfono)';
                }
                if (preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $texto) === 1) {
                    $mecanicos['datos_personales'][] = $ref.' (correo)';
                }
            }
        }

        $total = array_sum(array_map('count', $mecanicos));

        return [
            'mecanicos' => $mecanicos,
            'mecanicos_total' => $total,
            'para_criterio_humano' => [
                'veredictos_del_critic' => array_values(array_filter(array_map(fn ($t) => $t['critic'] === null ? null : ['turno' => $t['turno'], 'critic' => $t['critic']], $turnos))),
                'similitud_maxima_con_respuestas_previas' => array_values(array_filter(array_map(fn ($t) => $t['novedad'] === null ? null : ['turno' => $t['turno'], 'similitud' => $t['novedad']], $turnos))),
                'referentes_resueltos' => array_values(array_map(fn ($t) => ['turno' => $t['turno'], 'referente' => $t['referente']], $turnos)),
                'turnos_con_revision_humana' => array_values(array_filter(array_map(fn ($t) => $t['revision_humana'] ? $t['turno'] : null, $turnos))),
                'turnos_bloqueados' => array_values(array_filter(array_map(fn ($t) => $t['blocked_reason'] === null ? null : ['turno' => $t['turno'], 'motivo' => $t['blocked_reason']], $turnos))),
            ],
        ];
    }

    /** @param  list<array<string,mixed>>  $turnos */
    private function imprimir(MarketingConversation $conversation, array $turnos, array $hallazgos, ?Carbon $desde = null): void
    {
        $this->info('ACTA DEL CANARIO — conversación '.$conversation->id.' · '.count($turnos).' turnos'
            .($desde !== null ? ' · desde '.$desde->toDateTimeString() : ' · TODO el histórico'));
        $this->newLine();

        foreach ($turnos as $t) {
            $this->line('── TURNO '.$t['turno'].' ── '.($t['en'] ?? '?').' ── latencia '.($t['latencia_s'] ?? '?').'s');
            $this->line('  entra    : '.$this->corto($t['entrante']));
            $this->line('  decide   : intent='.($t['intent'] ?? '-').' · objetivo='.($t['strategy_goal'] ?? '-').' · siguiente='.($t['next_best_action'] ?? '-'));
            $this->line('  contexto : referente='.($t['referente'] ?? '-').' · temp='.($t['temperatura'] ?? '-').' · ciclo='.($t['ciclo_vida'] ?? '-').' · fase='.$t['fase']);
            $this->line('  tools    : pedidas='.json_encode($t['herramientas_pedidas']).' ejecutadas='.json_encode($t['herramientas_ejecutadas']));
            $this->line('  critic   : '.($t['critic'] === null ? '-' : json_encode($t['critic'], JSON_UNESCAPED_UNICODE)));
            $this->line('  commit   : estado='.($t['estado'] ?? '-').' outcome='.($t['outcome'] ?? '-').' bloqueo='.($t['blocked_reason'] ?? '-').' riesgos='.json_encode($t['riesgos']));

            foreach ($t['salientes'] as $s) {
                $this->line('  sale     : ['.$s['tipo'].'] '.$this->corto($s['texto']));
            }
            if ($t['salientes'] === []) {
                $this->line('  sale     : (nada)');
            }
            $this->newLine();
        }

        $this->info('LO QUE SE CUENTA (tiene que ser CERO)');
        foreach ($hallazgos['mecanicos'] as $clave => $casos) {
            $n = count($casos);
            $this->line(sprintf('  %-34s %d%s', $clave, $n, $n > 0 ? '  → '.implode(', ', $casos) : ''));
        }
        $this->newLine();

        $this->info('LO QUE JUZGA UNA PERSONA (evidencia, no nota)');
        $h = $hallazgos['para_criterio_humano'];
        $this->line('  veredictos_del_critic            : '.count($h['veredictos_del_critic']).' turnos con veredicto');
        foreach ($h['veredictos_del_critic'] as $v) {
            $this->line('     turno '.$v['turno'].': '.json_encode($v['critic'], JSON_UNESCAPED_UNICODE));
        }
        $this->line('  referentes_resueltos             : '.implode(', ', array_map(fn ($r) => $r['turno'].'='.($r['referente'] ?? '-'), $h['referentes_resueltos'])));
        $this->line('  similitud_con_respuestas_previas : '.(count($h['similitud_maxima_con_respuestas_previas']) === 0 ? 'sin dato' : implode(', ', array_map(fn ($r) => $r['turno'].'='.$r['similitud'], $h['similitud_maxima_con_respuestas_previas']))));
        $this->line('  turnos_con_revision_humana       : '.($h['turnos_con_revision_humana'] === [] ? 'ninguno' : implode(', ', $h['turnos_con_revision_humana'])));
        $this->line('  turnos_bloqueados                : '.($h['turnos_bloqueados'] === [] ? 'ninguno' : implode(', ', array_map(fn ($r) => $r['turno'].'='.$r['motivo'], $h['turnos_bloqueados']))));
        $this->newLine();

        if ($hallazgos['mecanicos_total'] === 0) {
            $this->info('CANARIO SIN HALLAZGOS MECANICOS. Falta la lectura humana de los '.count($turnos).' turnos.');
        } else {
            $this->error('CANARIO NO PASA: '.$hallazgos['mecanicos_total'].' hallazgos mecanicos.');
        }
    }

    private function corto(string $texto): string
    {
        $limpio = trim(preg_replace('/\s+/', ' ', $texto) ?? '');

        return mb_strlen($limpio) > 160 ? mb_substr($limpio, 0, 157).'...' : $limpio;
    }
}
