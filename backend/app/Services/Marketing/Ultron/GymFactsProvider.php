<?php

namespace App\Services\Marketing\Ultron;

use App\Models\ClassReservation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MyClass;
use App\Models\Plan;
use App\Models\Trainer;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Los hechos del gimnasio, desde la única fuente que los tiene: la base de
 * datos del CRM. El modelo nunca consulta tablas ni recuerda catálogos; recibe
 * esto ya sanitizado y, cuando una fuente no existe, recibe exactamente eso:
 * SOURCE_NOT_AVAILABLE. Inventar un horario es peor que no tenerlo.
 *
 * Reglas fijas:
 *  - Planes: sólo {@see Plan::scopeSellable()}. Sin precio: el precio lo pone
 *    Laravel al final, nunca el modelo.
 *  - Clases: las activas, sin duplicados de copia, con día y hora reales.
 *  - Entrenadores: cuántos hay y qué saben. Nunca nombre, documento, teléfono,
 *    correo ni fecha de nacimiento.
 *  - Horario de apertura: SOURCE_NOT_AVAILABLE mientras no exista en la base
 *    de conocimiento.
 *  - Conocimiento: activo Y vigente (valid_from / valid_until), la misma regla
 *    que knowledge_base; un horario caducado no es el horario.
 *
 * forPrompt() es lo que hoy viaja al prompt. El resto (comparePlans, planDetails,
 * classAvailability, businessInfo, search) es la API para las herramientas de
 * consulta y todavía no tiene llamadores en app/: andamiaje a propósito.
 */
final class GymFactsProvider
{
    public const SOURCE_NOT_AVAILABLE = 'SOURCE_NOT_AVAILABLE';

    /** Tope de clases que viajan al prompt: el contexto de cada turno no crece con el catálogo. */
    private const MAX_CLASSES = 40;

    private const ORDEN = ['lunes' => 1, 'martes' => 2, 'miércoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sábado' => 6, 'domingo' => 7];

    private const DIAS = ['monday' => 'lunes', 'tuesday' => 'martes', 'wednesday' => 'miércoles', 'thursday' => 'jueves', 'friday' => 'viernes', 'saturday' => 'sábado', 'sunday' => 'domingo'];

    /** @return array<string,mixed> */
    public function all(): array
    {
        return [
            'classes' => $this->classes(),
            'trainers' => $this->trainers(),
            'business_info' => $this->businessInfo(),
            'opening_hours' => $this->openingHours(),
        ];
    }

    /**
     * Lo que viaja al prompt. Sin business_info: eso ya va en knowledge_base y
     * duplicarlo solo gasta contexto. Sin cupos: las reservas de la app son
     * escasas frente a la asistencia real (4 en 30 días en septiembre de 2026),
     * así que afirmar «quedan cupos» sería inventar escasez o disponibilidad.
     *
     * @return array<string,mixed>
     */
    public function forPrompt(): array
    {
        return [
            'classes' => $this->classes(),
            'trainers' => $this->trainers(),
            'opening_hours' => $this->openingHours(),
            'opening_windows' => $this->openingWindows(),
            'class_availability' => self::SOURCE_NOT_AVAILABLE,
        ];
    }

    /**
     * Comparación de planes vendibles, sin precio: lo que los distingue.
     *
     * @return array<int, array<string,mixed>>
     */
    public function comparePlans(array $planIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $planIds)));
        if ($ids === []) {
            return [];
        }

        return Plan::query()->sellable()->whereIn('id', $ids)->orderBy('duration_days')->get()
            ->map(fn (Plan $p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'duration_days' => (int) $p->duration_days,
                'benefits' => array_values($p->benefitsArray()),
                'tier' => $p->tier,
                'access_classes' => (bool) $p->access_classes,
                'restrictions' => $p->restrictions,
            ])->all();
    }

    /** @return array<string,mixed> */
    public function planDetails(int $planId): ?array
    {
        $rows = $this->comparePlans([$planId]);

        return $rows === [] ? null : $rows[0];
    }

    /**
     * Clases activas, deduplicadas. Una fila «Copia de X» con el mismo horario
     * no es otra clase, es un residuo de la interfaz de administración.
     *
     * @return array<int, array<string,mixed>>
     */
    public function classes(): array
    {
        if (! Schema::hasTable('classes')) {
            return [];
        }
        try {
            $rows = MyClass::query()->whereIn('status', ['active', 'activa'])->orderBy('start_time')->get();
        } catch (Throwable $e) {
            $this->aviso('classes', $e);

            return [];
        }

        $out = [];
        $seen = [];
        foreach ($rows as $c) {
            $name = trim(preg_replace('/^(copia de\s+)+/iu', '', (string) $c->name) ?? (string) $c->name);
            $key = mb_strtolower($name).'|'.$c->day_of_week.'|'.$c->start_time;
            if ($name === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = array_filter([
                'id' => (int) $c->id,
                'name' => $name,
                'type' => $c->type,
                'day' => $this->dia($c->day_of_week),
                'start_time' => $c->start_time ? substr((string) $c->start_time, 0, 5) : null,
                'end_time' => $c->end_time ? substr((string) $c->end_time, 0, 5) : null,
                'description' => $c->description ? mb_substr(trim((string) $c->description), 0, 160) : null,
                'online_booking' => (bool) $c->allow_online_booking,
                'requires_active_plan' => (bool) $c->requires_active_plan,
            ], fn ($v) => $v !== null && $v !== '');
        }
        // Orden de calendario, no alfabético (domingo, jueves, lunes…).
        usort($out, fn ($a, $b) => [self::ORDEN[$a['day'] ?? ''] ?? 9, $a['start_time'] ?? ''] <=> [self::ORDEN[$b['day'] ?? ''] ?? 9, $b['start_time'] ?? '']);

        // El tope va DESPUÉS de deduplicar y ordenar: cortar antes se llevaría las
        // copias y las primeras horas de toda la semana, y el modelo «no vería» la tarde.
        return array_slice($out, 0, self::MAX_CLASSES);
    }

    /**
     * Cupos de una clase para una fecha: sólo si la fuente es fiable (capacidad
     * declarada y reservas por fecha). Si no, SOURCE_NOT_AVAILABLE.
     *
     * @return array{class_id:int, date:string, spots_left:int|string}
     */
    public function classAvailability(int $classId, string $date): array
    {
        try {
            $c = MyClass::query()->find($classId);
            if ($c === null || (int) $c->max_capacity <= 0 || ! Schema::hasTable('class_reservations')) {
                return ['class_id' => $classId, 'date' => $date, 'spots_left' => self::SOURCE_NOT_AVAILABLE];
            }
            $reserved = ClassReservation::query()->where('class_id', $classId)->whereDate('session_date', $date)->count();

            return ['class_id' => $classId, 'date' => $date, 'spots_left' => max(0, (int) $c->max_capacity - $reserved)];
        } catch (Throwable $e) {
            $this->aviso('class_reservations', $e);

            return ['class_id' => $classId, 'date' => $date, 'spots_left' => self::SOURCE_NOT_AVAILABLE];
        }
    }

    /**
     * Cuántos entrenadores hay y qué saben. Nada que identifique a ninguno.
     *
     * @return array{active_count:int, specialties:string[]}
     */
    public function trainers(): array
    {
        if (! Schema::hasTable('trainers')) {
            return ['active_count' => 0, 'specialties' => []];
        }
        try {
            $rows = Trainer::query()->where('status', 'active')->get(['main_specialty', 'specialties']);
        } catch (Throwable $e) {
            $this->aviso('trainers', $e);

            return ['active_count' => 0, 'specialties' => []];
        }
        $spec = [];
        foreach ($rows as $t) {
            if (trim((string) $t->main_specialty) !== '') {
                $spec[] = trim((string) $t->main_specialty);
            }
            foreach ((array) ($t->specialties ?? []) as $s) {
                if (is_string($s) && trim($s) !== '') {
                    $spec[] = trim($s);
                }
            }
        }

        return ['active_count' => $rows->count(), 'specialties' => array_values(array_unique($spec))];
    }

    /**
     * Identidad, sede, pagos y políticas: lo que la base de conocimiento tiene
     * activo, por categoría. Sin horario: ese va aparte y hoy no existe.
     *
     * @return array<string, string[]>
     */
    public function businessInfo(): array
    {
        $out = [];
        foreach ($this->activeItems() as $item) {
            if (! in_array($item->category, ['business_identity', 'location', 'payment_policy', 'membership_policy', 'invoice_policy', 'restrictions'], true)) {
                continue;
            }
            $linea = $this->comoDato($item->title, $item->content);
            if ($linea !== null) {
                $out[$item->category][] = $linea;
            }
        }

        return $out;
    }

    /**
     * El MISMO horario, legible por una máquina.
     *
     * El texto de arriba es el que se le dice a la persona y está bien así;
     * pero para decidir si «el sábado a las 6 de la tarde» cae dentro del
     * horario hace falta un dato, no una frase. Parsear el texto sería frágil
     * y además distinto en cada servidor: esta casa ya se rompió una vez por
     * una diferencia de PCRE entre local y producción.
     *
     * Así que la ventana vive en la `metadata` del MISMO ítem aprobado de la
     * base de conocimiento. Una sola fuente, editable por el negocio, sin
     * tabla nueva y sin riesgo de que la frase y el dato se separen.
     *
     * Formato: {"lunes": ["05:00","22:00"], ...}. Un día ausente significa
     * cerrado. Si no hay metadata se devuelve SOURCE_NOT_AVAILABLE, y quien
     * valide tiene que PREGUNTAR en vez de suponer: no saber el horario no
     * puede leerse como un sí.
     *
     * @return array<string,array{0:string,1:string}>|string
     */
    public function openingWindows(): array|string
    {
        foreach ($this->activeItems() as $item) {
            if ($item->category !== 'schedule') {
                continue;
            }
            $ventanas = data_get($item->metadata, 'windows');
            if (is_array($ventanas) && $ventanas !== []) {
                return $ventanas;
            }
        }

        return self::SOURCE_NOT_AVAILABLE;
    }

    /** Horario de apertura: sólo si la base de conocimiento lo declara. */
    public function openingHours(): array|string
    {
        $lines = [];
        foreach ($this->activeItems() as $item) {
            if ($item->category === 'schedule' && ($linea = $this->comoDato($item->title, $item->content)) !== null) {
                $lines[] = $linea;
            }
        }

        return $lines === [] ? self::SOURCE_NOT_AVAILABLE : $lines;
    }

    /**
     * Búsqueda por palabras en la base de conocimiento activa. Devuelve las
     * entradas, nunca una respuesta redactada: redactar es del Composer.
     *
     * @return array<int, array{category:string, title:string, content:string}>
     */
    public function search(string $query, int $limit = 3): array
    {
        $t = preg_replace('/[^a-z0-9\s]+/u', ' ', SalesAgentDecisionSchema::normalize($query)) ?? '';
        $stop = ['como', 'para', 'donde', 'cuando', 'cuanto', 'cual', 'cuales', 'tienen', 'tiene', 'esta', 'estan', 'sobre', 'quiero', 'saber', 'puedo', 'hacer'];
        $words = array_values(array_filter(preg_split('/\s+/u', trim($t)) ?: [], fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, $stop, true)));
        if ($words === []) {
            return [];
        }
        $scored = [];
        foreach ($this->activeItems() as $item) {
            $hay = SalesAgentDecisionSchema::normalize((string) $item->title.' '.(string) $item->content);
            $score = 0;
            foreach ($words as $w) {
                if (str_contains($hay, $w)) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scored[] = [$score, [
                    'category' => (string) $item->category,
                    'title' => MarketingKnowledgeBaseService::asData($item->title),
                    'content' => mb_substr(MarketingKnowledgeBaseService::asData($item->content), 0, 400),
                ]];
            }
        }
        usort($scored, fn ($a, $b) => $b[0] <=> $a[0]);

        return array_map(fn ($s) => $s[1], array_slice($scored, 0, $limit));
    }

    /**
     * Una línea de la base de conocimiento, con forma de DATO.
     *
     * Misma regla que `knowledge_base` ({@see MarketingKnowledgeBaseService::asData()}):
     * un ítem con saltos de línea podría escribir «\n\nSYSTEM:» y, allí donde
     * el contexto se interpola en la plantilla del prompt, fingir una sección
     * nuestra. Los hechos del gimnasio viajan al mismo prompt, así que se
     * aplanan igual. Devuelve null si no queda contenido.
     */
    private function comoDato(?string $titulo, ?string $contenido): ?string
    {
        $t = MarketingKnowledgeBaseService::asData($titulo);
        $c = MarketingKnowledgeBaseService::asData($contenido);
        if ($c === '') {
            return null;
        }

        return $t === '' ? $c : $t.': '.$c;
    }

    /**
     * Activos, vigentes Y aprobados: la misma regla que knowledge_base.
     *
     * Se consulta cada vez a propósito. Memorizarlo por instancia ahorraba una
     * consulta indexada sobre una tabla pequeña y, a cambio, dejaba que un
     * proceso de vida larga (un worker, o el mismo controlador reutilizado)
     * siguiera sirviendo el estado anterior: un horario recién APROBADO no
     * llegaba al prompt hasta reiniciar, y uno recién RECHAZADO seguía
     * llegando. En una frontera de confianza, que la revisión humana tarde en
     * hacer efecto es el fallo, no la consulta de más.
     */
    private function activeItems(): Collection
    {
        if (! Schema::hasTable('marketing_knowledge_items')) {
            return collect();
        }
        try {
            return MarketingKnowledgeItem::query()->activeNow()->orderBy('priority')->get();
        } catch (Throwable $e) {
            $this->aviso('knowledge', $e);

            return collect();
        }
    }

    /** Un hecho que se degrada a «no disponible» deja rastro: si no, nadie se entera. */
    private function aviso(string $fuente, Throwable $e): void
    {
        ChannelLog::warning('ultron.gym_facts.unavailable', ['source' => $fuente, 'exception' => class_basename($e)]);
    }

    /** Día en español y minúsculas venga como venga (monday, Lunes, miércoles, sabado). */
    private function dia(?string $dow): ?string
    {
        if ($dow === null || trim($dow) === '') {
            return null;
        }
        $k = mb_strtolower(trim($dow));
        $k = self::DIAS[$k] ?? $k;

        return strtr($k, ['miercoles' => 'miércoles', 'sabado' => 'sábado']);
    }
}
