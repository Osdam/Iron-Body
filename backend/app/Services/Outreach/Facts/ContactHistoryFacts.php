<?php

namespace App\Services\Outreach\Facts;

use App\Models\MarketingMessage;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Telephony\VoiceFrequency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adaptador de SOLO LECTURA del HISTORIAL DE CONTACTOS de una persona
 * (contrato H9, D9-4 «Historial», D9-7 `ContactBudget`). Es lo que alimenta
 * `contact.count`, `contact.days_since_last`, `contact.last_outcome` y el
 * presupuesto omnicanal. Tablas: `marketing_messages` + `marketing_conversations`,
 * `voice_calls` + `voice_contacts`, y las del H9 `outreach_outcomes`,
 * `outreach_jobs`, `outreach_enrollments` y `ultron_studio_strategies`.
 *
 * CUENTA COMO CONTACTO (D9-7), y NADA MÁS:
 *  - los salientes PROACTIVOS de la IA: `sender_type = ai`, salientes, con
 *    `metadata.kind` distinto de `reply` (`ContactPolicy.php:104-116`). Una
 *    respuesta a lo que preguntó la persona no consume cuota;
 *  - las salientes de voz en `VoiceFrequency::COUNTED_STATUSES`, al número de
 *    alguno de los contactos de voz de la persona (por la huella, como cuenta
 *    la frecuencia del H8);
 *  - los contactos ASISTIDOS registrados: el resultado que registró una persona
 *    (`source = staff`) de un trabajo asistido en `live`. Un trabajo simulado
 *    nunca cuenta.
 * Los envíos automáticos del H9 (W9/V9) ya son un mensaje o una llamada: se
 * cuentan ahí, no dos veces.
 *
 * Cada contacto sale como `{at, medium, outcome, purpose, source, status,
 * strategy}`: instante UTC, `whatsapp`/`voice`, el resultado (D9-10) si lo hay,
 * la finalidad (la del H9, o `commercial` fuera de él), la estrategia del H9
 * (uuid) o null, la FUENTE con los nombres de `ContactBudget`
 * (`ai_proactive`, `voice_call`, `outreach`) y el estado de la llamada. Con
 * {@see CoreProjection} se convierte en los `contacts` de `Core\FactSnapshot`
 * y en el `history` de `ContactBudget::check()`.
 * Sin cuerpos, números ni remitentes. Del más reciente al más antiguo, en 730
 * días de Bogotá y como mucho `MAX_COUNT + 1`.
 *
 * Si las tablas del H9 todavía no existen (el código desplegado antes que las
 * migraciones de DB9), esa fuente se lee como vacía y {@see hasOutreachSource()}
 * lo dice; se detecta una vez por proceso, no por lote.
 */
final class ContactHistoryFacts
{
    public const TABLES = [
        'marketing_messages', 'marketing_conversations', 'voice_calls', 'voice_contacts',
        'outreach_outcomes', 'outreach_jobs', 'outreach_enrollments', 'ultron_studio_strategies',
    ];

    public const MAX_CONTACTS = OutreachCatalog::MAX_COUNT + 1;

    /**
     * Las fuentes, con los nombres de `ContactBudget::COUNTED_SOURCES` (DF-B1;
     * `ContactHistoryUnionTest` lo ata): el historial se le pasa tal cual.
     */
    public const SOURCE_MESSAGE = 'ai_proactive';

    public const SOURCE_VOICE = 'voice_call';

    public const SOURCE_ASSISTED = 'outreach';

    public const SOURCES = [self::SOURCE_MESSAGE, self::SOURCE_VOICE, self::SOURCE_ASSISTED];

    /**
     * La finalidad de un contacto de FUERA del H9 (Q-6 del informe): los
     * salientes proactivos de la IA y las salientes de voz del H8 son
     * comerciales (el consentimiento de voz es `commercial`); `Core\FactSnapshot`
     * y `ContactBudget` exigen una finalidad del conjunto cerrado.
     */
    public const LEGACY_PURPOSE = 'commercial';

    /** @var array<string, bool> por conexión: ¿existen las tablas del H9? (una vez por proceso, no por instancia) */
    private static array $outreachSource = [];

    /**
     * @return array<string, list<array{at: string, medium: string, outcome: ?string, purpose: string, source: string, status: ?string, strategy: ?string}>|null> por clave pedida (null si no se resolvió)
     */
    public function contacts(FactPass $b): array
    {
        $desde = FactValues::sql(RelativeTime::windowStart($b->now, OutreachCatalog::MAX_WINDOW_DAYS, 'days'));
        $hasta = FactValues::sql($b->now);
        $porClave = [];
        foreach ($b->subjects as $clave => $s) {
            $porClave[$clave] = $s->resolved() ? [] : null;
        }

        // 1 · Salientes proactivos de la IA (WhatsApp).
        $leads = $b->leadIds();
        if ($leads !== []) {
            $filas = DB::table('marketing_messages as m')
                ->join('marketing_conversations as c', 'c.id', '=', 'm.conversation_id')
                ->whereIn('c.lead_id', $leads)
                ->where('m.direction', MarketingMessage::DIRECTION_OUTBOUND)
                ->where('m.sender_type', MarketingMessage::SENDER_AI)
                ->where('m.created_at', '>=', $desde)
                ->where('m.created_at', '<=', $hasta)
                ->orderByDesc('m.created_at')->orderByDesc('m.id')
                ->get(['m.id', 'm.created_at', 'm.metadata', 'c.lead_id']);
            foreach ($filas as $f) {
                if (self::kind($f->metadata) === 'reply') {
                    continue;
                }
                $cuando = FactValues::instant($f->created_at);
                if ($cuando === null) {
                    continue;
                }
                foreach ($b->keysForLead((int) $f->lead_id) as $clave) {
                    $porClave[$clave][] = self::contact($cuando, OutreachCatalog::MEDIUM_WHATSAPP, self::LEGACY_PURPOSE, null, null, self::SOURCE_MESSAGE, null, 'm'.$f->id);
                }
            }
        }

        // 2 · Salientes de voz al número de alguno de sus contactos (también retirados: el contacto ocurrió).
        $socios = $b->memberIds();
        if ($socios !== [] || $leads !== []) {
            $huellas = [];
            $q = DB::table('voice_contacts')->orderBy('id')->where(function ($w) use ($socios, $leads): void {
                if ($socios !== []) {
                    $w->orWhere(fn ($m) => $m->where('source_type', 'member')->whereIn('source_id', $socios));
                }
                if ($leads !== []) {
                    $w->orWhere(fn ($l) => $l->where('source_type', 'lead')->whereIn('source_id', $leads));
                }
            });
            foreach ($q->get(['source_type', 'source_id', 'phone_hash']) as $c) {
                $claves = $c->source_type === 'member' ? $b->keysForMember((int) $c->source_id) : $b->keysForLead((int) $c->source_id);
                foreach ($claves as $clave) {
                    $huellas[(string) $c->phone_hash][$clave] = true;
                }
            }
            if ($huellas !== []) {
                $lista = array_keys($huellas);
                sort($lista, SORT_STRING);
                $llamadas = DB::table('voice_calls')
                    ->where('direction', 'outbound')
                    ->whereIn('status', VoiceFrequency::COUNTED_STATUSES)
                    ->whereIn('remote_hash', $lista)
                    ->where('created_at', '>=', $desde)
                    ->where('created_at', '<=', $hasta)
                    ->orderByDesc('created_at')->orderByDesc('id')
                    ->get(['id', 'remote_hash', 'status', 'created_at']);
                foreach ($llamadas as $l) {
                    $cuando = FactValues::instant($l->created_at);
                    if ($cuando === null) {
                        continue;
                    }
                    foreach (array_keys($huellas[(string) $l->remote_hash] ?? []) as $clave) {
                        $porClave[$clave][] = self::contact($cuando, OutreachCatalog::MEDIUM_VOICE, self::LEGACY_PURPOSE, null, null, self::SOURCE_VOICE, (string) $l->status, 'v'.$l->id);
                    }
                }
            }
        }

        // 3 · Contactos asistidos registrados por una persona (H9, solo `live`).
        $canonicas = [];
        foreach ($b->subjects as $clave => $s) {
            if ($s->key !== null) {
                $canonicas[$s->key][] = $clave;
            }
        }
        if ($canonicas !== [] && $this->hasOutreachSource()) {
            $resultados = DB::table('outreach_outcomes as o')
                ->join('outreach_jobs as j', 'j.id', '=', 'o.job_id')
                ->join('outreach_enrollments as e', 'e.id', '=', 'o.enrollment_id')
                ->leftJoin('ultron_studio_strategies as st', 'st.id', '=', 'e.strategy_id')
                ->whereIn('j.subject_key', array_keys($canonicas))
                ->where('o.source', 'staff')
                ->where('j.channel', 'assisted')
                ->where('j.mode', 'live')
                ->where('o.occurred_at', '>=', $desde)
                ->where('o.occurred_at', '<=', $hasta)
                ->orderByDesc('o.occurred_at')->orderByDesc('o.id')
                ->get(['o.id', 'o.occurred_at', 'o.outcome', 'j.subject_key', 'j.medium', 'e.purpose', 'st.uuid as strategy_uuid']);
            foreach ($resultados as $r) {
                $cuando = FactValues::instant($r->occurred_at);
                $medio = in_array($r->medium, OutreachCatalog::MEDIUMS, true) ? (string) $r->medium : null;
                if ($cuando === null || $medio === null) {
                    continue;
                }
                $finalidad = in_array($r->purpose, OutreachCatalog::PURPOSES, true) ? (string) $r->purpose : null;
                if ($finalidad === null) {
                    continue; // una inscripción sin finalidad válida no es un contacto que se sepa contar
                }
                foreach ($canonicas[(string) $r->subject_key] ?? [] as $clave) {
                    $porClave[$clave][] = self::contact($cuando, $medio, $finalidad, $r->strategy_uuid !== null ? (string) $r->strategy_uuid : null, (string) $r->outcome, self::SOURCE_ASSISTED, null, 'o'.$r->id);
                }
            }
        }

        // Orden total (instante desc, después la fuente y su id) y tope.
        foreach ($porClave as $clave => $lista) {
            if ($lista === null) {
                continue;
            }
            usort($lista, static fn (array $a, array $b): int => [$b['at'], $b['_k']] <=> [$a['at'], $a['_k']]);
            $porClave[$clave] = array_map(static function (array $c): array {
                unset($c['_k']);

                return $c;
            }, array_slice($lista, 0, self::MAX_CONTACTS));
        }

        return $porClave;
    }

    /**
     * ¿Existen ya las tablas del H9 que cuentan los contactos asistidos? Se
     * mira UNA VEZ POR PROCESO Y CONEXIÓN, no por instancia: cada foto crea su
     * adaptador, y detectarlo por instancia costaba cuatro consultas de
     * catálogo por lote (la foto completa pasó de 39 a 43 al llegar DB9). El
     * esquema no cambia en caliente; una prueba que lo cambie llama a
     * {@see forgetOutreachSource()}.
     */
    public function hasOutreachSource(): bool
    {
        $conexion = DB::connection()->getName();

        return self::$outreachSource[$conexion] ??= Schema::hasTable('outreach_outcomes') && Schema::hasTable('outreach_jobs')
            && Schema::hasTable('outreach_enrollments') && Schema::hasTable('ultron_studio_strategies');
    }

    /** Olvida la detección (pruebas que crean o quitan las tablas del H9 dentro del mismo proceso). */
    public static function forgetOutreachSource(): void
    {
        self::$outreachSource = [];
    }

    /** @return array{at: string, medium: string, outcome: ?string, purpose: ?string, source: string, status: ?string, strategy: ?string, _k: string} */
    private static function contact(\DateTimeImmutable $at, string $medium, string $purpose, ?string $strategy, ?string $outcome, string $source, ?string $status, string $orden): array
    {
        return [
            'at' => RelativeTime::format($at), 'medium' => $medium, 'outcome' => $outcome, 'purpose' => $purpose,
            'source' => $source, 'status' => $status, 'strategy' => $strategy, '_k' => str_pad($orden, 24, '0', STR_PAD_LEFT),
        ];
    }

    /** Solo `metadata.kind` (el resto puede llevar el número del destinatario: se descarta aquí mismo). */
    private static function kind(mixed $metadata): ?string
    {
        if (is_string($metadata) && $metadata !== '') {
            $metadata = json_decode($metadata, true);
        }

        return is_array($metadata) && is_string($metadata['kind'] ?? null) ? $metadata['kind'] : null;
    }
}
