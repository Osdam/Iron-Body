<?php

namespace App\Services\Valuations;

use App\Exceptions\ValuationException;
use App\Models\Member;
use App\Models\Trainer;
use App\Models\ValuationAppointment;
use App\Models\ValuationAppointmentEvent;
use App\Support\Access\TrainerMemberScope;
use App\Support\Caja\UntrustedText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Importación de citas desde un CSV (transición desde Google Calendar, que no
 * tiene integración). Contrato §0.14.
 *
 * Formato (UTF-8, con o sin BOM; separador `,` o `;`, detectado en la cabecera):
 *
 *   member_document,trainer_document,date,time,duration_minutes,type,notes
 *   1075123456,80012345,2026-10-24,09:00,60,initial,Primera valoración
 *
 *  · member_document  documento del socio (`members.document_number`; se ignoran
 *                     espacios, puntos y guiones).
 *  · trainer_document documento del entrenador (`trainers.document`; se comparan
 *                     solo los dígitos) o, si no lo tiene, su nombre completo
 *                     exacto (sin distinguir mayúsculas).
 *  · date             YYYY-MM-DD, día de Bogotá.
 *  · time             HH:MM, hora de pared de Bogotá (24 h).
 *  · duration_minutes 15..240, múltiplo de 5; vacío = la duración por defecto.
 *  · type             initial | follow_up | other (o inicial | seguimiento |
 *                     otra); vacío = initial.
 *  · notes            opcional, ≤ 1000, sin caracteres de control (salvo saltos
 *                     de línea). Administrativas, NO clínicas.
 *  Una fila que no es UTF-8 válido es «inválida» (no se busca nada con ella).
 *
 * Por defecto SIMULA: no escribe nada y dice qué pasaría, fila a fila. Con
 * `apply` escribe las filas válidas en UNA transacción (todas o ninguna), con
 * los entrenadores y socios implicados bloqueados en el mismo orden que crear
 * una cita (entrenadores y después socios, por id) y repitiendo las
 * comprobaciones dentro. Las citas importadas nacen `scheduled` con
 * `source=import` y su evento `imported`. Se comprueban las MISMAS reglas que al
 * agendar desde el CRM (franja de disponibilidad, bloqueo, clase, otra cita del
 * entrenador con su margen, otra cita del socio) además de los choques entre
 * filas del propio fichero: por eso la disponibilidad se configura ANTES de
 * importar (docs/agenda-valoraciones/TRANSICION_GOOGLE_CALENDAR.md).
 *
 * Duplicado: ya existe una cita del MISMO socio con el MISMO entrenador y la
 * MISMA hora de inicio, de cualquier origen (importada o del CRM) y en cualquier
 * estado, o una fila anterior igual en el fichero. No se escribe: reaplicar el
 * mismo fichero no crea nada.
 *
 * Pasado: una fila cuyo inicio ya pasó se rechaza como «en el pasado», salvo con
 * `includePast` (`--include-past`). Entonces se importa SIN ningún aviso.
 *
 * Avisos: ninguna confirmación al socio (ya se le dijo por Google). Una futura,
 * los recordatorios de siempre —largo, corto e internos— (los del socio salen
 * solo si el canal está activo). Ningún aviso inmediato a la bandeja del CRM:
 * una importación no es una novedad para nadie.
 */
final class ValuationImporter
{
    public const COLUMNS = ['member_document', 'trainer_document', 'date', 'time', 'duration_minutes', 'type', 'notes'];

    public const CATEGORIES = ['valid', 'member_not_found', 'trainer_not_found', 'trainer_ambiguous', 'in_past', 'conflict', 'duplicate', 'invalid'];

    private const TYPE_ALIASES = [
        'initial' => 'initial', 'inicial' => 'initial', '' => 'initial',
        'follow_up' => 'follow_up', 'seguimiento' => 'follow_up',
        'other' => 'other', 'otra' => 'other', 'otro' => 'other',
    ];

    private const MAX_ROWS = 5000;

    private const CONFLICT_REASONS = [
        'outside_availability' => 'fuera de la disponibilidad del entrenador',
        'blocked' => 'el entrenador tiene esa franja bloqueada',
        'class_conflict' => 'el entrenador tiene una clase a esa hora',
        'slot_taken' => 'el entrenador ya tiene otra cita a esa hora',
        'member_overlap' => 'el socio ya tiene otra cita a esa hora',
    ];

    public function __construct(
        private readonly ValuationSettings $settings,
        private readonly ValuationReminders $reminders,
        private readonly ValuationAgenda $agenda,
        private readonly ValuationAvailability $availability,
    ) {}

    /**
     * @return array{applied: bool, counts: array<string,int>, rows: list<array<string,mixed>>, created: int}
     *
     * @throws RuntimeException si el fichero no se puede leer o la cabecera no es la esperada
     */
    public function run(string $ruta, bool $aplicar, bool $includePast = false): array
    {
        $filas = $this->leer($ruta);

        if (! $aplicar) {
            $analisis = $this->analizar($filas, $includePast);

            return ['applied' => false, 'counts' => $this->contar($analisis), 'rows' => $analisis, 'created' => 0];
        }

        return DB::transaction(function () use ($filas, $includePast): array {
            $previo = $this->analizar($filas, $includePast);
            $entrenadores = array_values(array_unique(array_filter(array_column($previo, 'trainer_id'))));
            $socios = array_values(array_unique(array_filter(array_column($previo, 'member_id'))));

            return $this->agenda->withTrainerAndMemberLocks($entrenadores, $socios, function () use ($filas, $includePast): array {
                // Con todo bloqueado: lo que se escribe es lo que se comprueba ahora.
                $analisis = $this->analizar($filas, $includePast);
                $ahora = Carbon::now()->utc();
                $ajustes = $this->settings->current();
                $creadas = 0;

                foreach ($analisis as $i => $fila) {
                    if ($fila['category'] !== 'valid') {
                        continue;
                    }
                    $cita = ValuationAppointment::create([
                        'member_id' => $fila['member_id'],
                        'trainer_id' => $fila['trainer_id'],
                        'type' => $fila['type'],
                        'status' => ValuationAppointment::STATUS_SCHEDULED,
                        'starts_at' => $fila['starts_at']->copy()->utc(),
                        'ends_at' => $fila['ends_at']->copy()->utc(),
                        'duration_minutes' => $fila['duration_minutes'],
                        'notes' => $fila['notes'],
                        'source' => ValuationAppointment::SOURCE_IMPORT,
                        'version' => 1,
                        'created_by_admin_id' => null,
                        'created_by_name' => 'Importación CSV',
                    ]);
                    ValuationAppointmentEvent::create([
                        'appointment_id' => $cita->id,
                        'action' => ValuationAppointmentEvent::ACTION_IMPORTED,
                        'actor_admin_id' => null,
                        'actor_name' => 'Importación CSV',
                        'to_status' => $cita->status,
                        'to_starts_at' => $cita->starts_at,
                        'to_trainer_id' => $cita->trainer_id,
                        'metadata' => ['line' => $fila['line']],
                        'created_at' => $ahora,
                    ]);
                    // Sin confirmación (ya se le dijo por Google). Una pasada (solo con
                    // --include-past) no planifica nada: plan() lo descarta.
                    $this->reminders->plan($cita, $ajustes, null);
                    $analisis[$i]['uuid'] = $cita->uuid;
                    $creadas++;
                }

                Log::info('valuations.import_applied', ['created' => $creadas, 'rows' => count($analisis)]);

                return ['applied' => true, 'counts' => $this->contar($analisis), 'rows' => $analisis, 'created' => $creadas];
            });
        });
    }

    /** @return list<array{line: int, values: array<string,string>}> */
    private function leer(string $ruta): array
    {
        if (! is_file($ruta) || ! is_readable($ruta)) {
            throw new RuntimeException("No se puede leer el fichero: {$ruta}");
        }
        $f = fopen($ruta, 'rb');
        if ($f === false) {
            throw new RuntimeException("No se puede abrir el fichero: {$ruta}");
        }

        try {
            $primera = fgets($f);
            if ($primera === false) {
                throw new RuntimeException('El fichero está vacío.');
            }
            $primera = preg_replace('/^\xEF\xBB\xBF/', '', $primera);
            $separador = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
            $cabecera = array_map(fn ($c) => strtolower(trim((string) $c)), str_getcsv(trim($primera), $separador, '"', ''));
            $faltan = array_diff(array_slice(self::COLUMNS, 0, 4), $cabecera);
            if ($faltan !== []) {
                throw new RuntimeException('Cabecera incorrecta. Faltan: '.implode(', ', $faltan).'. Esperada: '.implode(',', self::COLUMNS));
            }

            $filas = [];
            $linea = 1;
            while (($cols = fgetcsv($f, 0, $separador, '"', '')) !== false) {
                $linea++;
                if ($cols === [null] || (count($cols) === 1 && trim((string) $cols[0]) === '')) {
                    continue;
                }
                if (count($filas) >= self::MAX_ROWS) {
                    throw new RuntimeException('Demasiadas filas: como mucho '.self::MAX_ROWS.' por fichero.');
                }
                $valores = [];
                foreach (self::COLUMNS as $col) {
                    $pos = array_search($col, $cabecera, true);
                    $valores[$col] = $pos === false ? '' : trim((string) ($cols[$pos] ?? ''));
                }
                $filas[] = ['line' => $linea, 'values' => $valores];
            }

            return $filas;
        } finally {
            fclose($f);
        }
    }

    /**
     * Clasifica cada fila. Las válidas anteriores del mismo fichero cuentan como
     * ocupadas para las siguientes.
     *
     * @param  list<array{line: int, values: array<string,string>}>  $filas
     * @return list<array<string,mixed>>
     */
    private function analizar(array $filas, bool $includePast): array
    {
        $ahora = Carbon::now()->utc();
        $ajustes = $this->settings->current();
        $porDefecto = (int) $ajustes->default_duration_minutes;
        $margen = max(0, (int) $ajustes->buffer_minutes);
        $todos = Trainer::query()->get(['id', 'document', 'full_name']);
        $porDocumento = $todos->filter(fn (Trainer $t) => preg_replace('/\D+/', '', (string) $t->document) !== '')
            ->groupBy(fn (Trainer $t) => preg_replace('/\D+/', '', (string) $t->document));
        $porNombre = $todos->groupBy(fn (Trainer $t) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $t->full_name))));

        $aceptadas = [];
        $out = [];
        foreach ($filas as $fila) {
            $v = $fila['values'];
            $r = [
                'line' => $fila['line'],
                'member_document_tail' => ValuationPresenter::documentTail($v['member_document']),
                'trainer_document_tail' => ValuationPresenter::documentTail($v['trainer_document']),
                'date' => $v['date'],
                'time' => $v['time'],
                'category' => 'valid',
                'reason' => null,
                'member_id' => null,
                'trainer_id' => null,
            ];

            $fecha = ValuationTime::plainDate($v['date']);
            $hora = preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $v['time'], $m) ? sprintf('%02d:%s', (int) $m[1], $m[2]) : null;
            $duracion = $v['duration_minutes'] === '' ? $porDefecto : (ctype_digit($v['duration_minutes']) ? (int) $v['duration_minutes'] : -1);
            $tipo = self::TYPE_ALIASES[mb_strtolower($v['type'])] ?? null;

            $invalido = match (true) {
                // Antes que nada: con bytes inválidos, PostgreSQL rechaza la
                // consulta del socio (22021) y abortaría la importación entera.
                ! UntrustedText::allUtf8($v) => 'texto que no es UTF-8 válido',
                ! UntrustedText::isPlain($v['notes'], true) => 'notas con caracteres no permitidos',
                $v['member_document'] === '' => 'falta el documento del socio',
                $v['trainer_document'] === '' => 'falta el documento del entrenador',
                $fecha === null => 'fecha no válida (YYYY-MM-DD)',
                $hora === null => 'hora no válida (HH:MM)',
                $duracion < 15 || $duracion > 240 || $duracion % 5 !== 0 => 'duración no válida (15..240, múltiplo de 5)',
                $tipo === null => 'tipo no válido (initial, follow_up, other)',
                mb_strlen($v['notes']) > 1000 => 'notas de más de 1000 caracteres',
                default => null,
            };
            if ($invalido !== null) {
                $out[] = array_merge($r, ['category' => 'invalid', 'reason' => $invalido]);

                continue;
            }

            $socios = Member::query()
                ->withoutGlobalScope(TrainerMemberScope::NAME)
                ->where('document_number', Member::normalizeDocumentNumber($v['member_document']))
                ->whereNull('anonymized_at')
                ->pluck('id');
            if ($socios->count() !== 1) {
                $out[] = array_merge($r, ['category' => 'member_not_found', 'reason' => $socios->isEmpty() ? 'socio no encontrado' : 'documento de socio ambiguo']);

                continue;
            }

            $digitos = (string) preg_replace('/\D+/', '', $v['trainer_document']);
            $candidatos = $digitos !== '' ? $porDocumento->get($digitos, collect()) : collect();
            if ($candidatos->isEmpty()) {
                // Sin documento en la ficha del entrenador: su nombre completo exacto.
                $candidatos = $porNombre->get(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $v['trainer_document']))), collect());
            }
            if ($candidatos->count() === 0) {
                $out[] = array_merge($r, ['category' => 'trainer_not_found', 'reason' => 'entrenador no encontrado']);

                continue;
            }
            if ($candidatos->count() > 1) {
                $out[] = array_merge($r, ['category' => 'trainer_ambiguous', 'reason' => 'documento de entrenador ambiguo']);

                continue;
            }

            $memberId = (int) $socios->first();
            $trainerId = (int) $candidatos->first()->id;
            $inicio = ValuationTime::wallToUtc($fecha, $hora);
            $fin = $inicio->copy()->addMinutes($duracion);
            $r = array_merge($r, [
                'member_id' => $memberId, 'trainer_id' => $trainerId, 'type' => $tipo,
                'starts_at' => $inicio, 'ends_at' => $fin, 'duration_minutes' => $duracion,
                'notes' => $v['notes'] === '' ? null : $v['notes'],
            ]);

            // Duplicado ANTES que «en el pasado»: reaplicar un fichero ya importado
            // (también con --include-past) tiene que decir «duplicado».
            $existente = ValuationAppointment::query()
                ->where('member_id', $memberId)->where('trainer_id', $trainerId)
                ->where('starts_at', ValuationAvailability::db($inicio))
                ->orderBy('id')
                ->first(['id', 'source', 'status']);
            if ($existente !== null) {
                $out[] = array_merge($r, ['category' => 'duplicate', 'reason' => 'ya existe esa cita ('
                    .($existente->source === ValuationAppointment::SOURCE_IMPORT ? 'importada' : 'agendada en el CRM')
                    .', '.(ValuationAppointment::STATUS_LABELS[$existente->status] ?? $existente->status).')']);

                continue;
            }
            $repetida = collect($aceptadas)->first(fn ($a) => $a['member_id'] === $memberId && $a['trainer_id'] === $trainerId && $a['starts_at']->equalTo($inicio));
            if ($repetida !== null) {
                $out[] = array_merge($r, ['category' => 'duplicate', 'reason' => 'repite la fila '.$repetida['line'].' del fichero']);

                continue;
            }

            if (! $inicio->gt($ahora) && ! $includePast) {
                $out[] = array_merge($r, ['category' => 'in_past', 'reason' => 'en el pasado (usa --include-past para importarla sin avisos)']);

                continue;
            }

            // Las reglas 6–10 de agendar, contra lo que ya hay en la base…
            try {
                $this->availability->assertBookable($trainerId, $memberId, $inicio, $fin, $ajustes);
            } catch (ValuationException $e) {
                $out[] = array_merge($r, ['category' => 'conflict', 'reason' => self::CONFLICT_REASONS[$e->errorCode] ?? $e->errorCode]);

                continue;
            }
            // …y contra las filas válidas anteriores del mismo fichero.
            $enFichero = collect($aceptadas)->first(fn ($a) => ($a['trainer_id'] === $trainerId
                    && $a['starts_at']->copy()->subMinutes($margen)->lt($fin) && $a['ends_at']->copy()->addMinutes($margen)->gt($inicio))
                || ($a['member_id'] === $memberId && $a['starts_at']->lt($fin) && $a['ends_at']->gt($inicio)));
            if ($enFichero !== null) {
                $out[] = array_merge($r, ['category' => 'conflict', 'reason' => 'choca con la fila '.$enFichero['line'].' del fichero']);

                continue;
            }

            $aceptadas[] = $r;
            $out[] = $r;
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $analisis
     * @return array<string,int>
     */
    private function contar(array $analisis): array
    {
        $cuenta = array_fill_keys(self::CATEGORIES, 0);
        foreach ($analisis as $fila) {
            $cuenta[$fila['category']]++;
        }

        return $cuenta;
    }
}
