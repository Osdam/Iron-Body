<?php

namespace App\Services\Exercises;

use App\Models\Exercise;
use App\Models\ExerciseAlias;

/**
 * Resolución central de nombres/ids de ejercicios contra el catálogo real
 * (`exercises`, 206 ejercicios con `video_path`).
 *
 * Estrategia SEGURA (resolveSafe) — solo lo aplicable sin riesgo:
 *   1. exercise_id directo
 *   2. external_id
 *   3. alias verificado (exercise_aliases.is_verified)
 *   4. nombre exacto normalizado contra exercises.name (único)
 *   5. nombre exacto normalizado contra exercises.local_name (único)
 *
 * Estrategia de AUDITORÍA (audit) — añade sugerencias por tokens, pero NUNCA se
 * autoaplican: un humano las revisa y crea aliases verificados. Así "Press
 * francés" u otros candidatos peligrosos no se asignan solos.
 *
 * Se registra como singleton: carga el catálogo y los aliases UNA vez por
 * request (evita N+1 al serializar muchas rutinas).
 */
class ExerciseCatalogResolver
{
    /** @var array<int,Exercise>|null */
    private ?array $byId = null;
    /** @var array<string,Exercise> nombre normalizado único → ejercicio */
    private array $byName = [];
    /** @var array<string,Exercise> local_name normalizado único → ejercicio */
    private array $byLocalName = [];
    /** @var array<string,Exercise> external_id (lower) → ejercicio */
    private array $byExternalId = [];
    /** @var array<string,int> alias normalizado verificado → exercise_id */
    private array $aliases = [];
    /** @var list<array{exercise:Exercise,tokens:array<string,true>}> */
    private array $tokenIndex = [];

    private const STOPWORDS = [
        'en', 'con', 'de', 'del', 'la', 'el', 'los', 'las',
        'y', 'a', 'para', 'por', 'al', 'un', 'una', 'o',
    ];

    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ];

    /** Fuerza recarga del catálogo/aliases (tras escribir aliases). */
    public function refresh(): void
    {
        $this->byId = null;
        $this->ensureLoaded();
    }

    private function ensureLoaded(): void
    {
        if ($this->byId !== null) {
            return;
        }

        $this->byId = [];
        $this->byName = [];
        $this->byLocalName = [];
        $this->byExternalId = [];
        $this->tokenIndex = [];
        $nameDup = [];
        $localDup = [];

        $cols = [
            'id', 'name', 'local_name', 'external_id', 'provider', 'equipment',
            'muscle_group', 'body_part', 'gif_url', 'thumbnail_url', 'video_path',
            'media_type', 'playback_speed',
        ];

        foreach (Exercise::query()->get($cols) as $ex) {
            $this->byId[(int) $ex->id] = $ex;

            if (is_string($ex->external_id) && trim($ex->external_id) !== '') {
                $this->byExternalId[mb_strtolower(trim($ex->external_id))] = $ex;
            }

            $n = $this->normalize($ex->name);
            if ($n !== '') {
                if (isset($this->byName[$n])) {
                    $nameDup[$n] = true;
                } else {
                    $this->byName[$n] = $ex;
                }
            }

            $ln = $this->normalize($ex->local_name);
            if ($ln !== '') {
                if (isset($this->byLocalName[$ln])) {
                    $localDup[$ln] = true;
                } else {
                    $this->byLocalName[$ln] = $ex;
                }
            }

            $this->tokenIndex[] = ['exercise' => $ex, 'tokens' => $this->tokenSet($ex->name)];
        }

        // Nombres no únicos → ambiguos: se descartan del match exacto.
        foreach (array_keys($nameDup) as $k) {
            unset($this->byName[$k]);
        }
        foreach (array_keys($localDup) as $k) {
            unset($this->byLocalName[$k]);
        }

        $this->aliases = [];
        foreach (ExerciseAlias::query()->where('is_verified', true)->get(['normalized_alias', 'exercise_id']) as $a) {
            if ($a->normalized_alias !== '') {
                $this->aliases[$a->normalized_alias] = (int) $a->exercise_id;
            }
        }
    }

    // ── Resolución segura (lo único que se autoaplica) ───────────────────────

    public function resolveSafe(?int $exerciseId, ?string $name, ?string $externalId = null): ?Exercise
    {
        $this->ensureLoaded();

        if ($exerciseId && isset($this->byId[$exerciseId])) {
            return $this->byId[$exerciseId];
        }
        if ($externalId !== null && trim($externalId) !== '') {
            $hit = $this->byExternalId[mb_strtolower(trim($externalId))] ?? null;
            if ($hit) {
                return $hit;
            }
        }

        $n = $this->normalize($name);
        if ($n === '') {
            return null;
        }
        if (isset($this->aliases[$n]) && isset($this->byId[$this->aliases[$n]])) {
            return $this->byId[$this->aliases[$n]];
        }
        if (isset($this->byName[$n])) {
            return $this->byName[$n];
        }
        if (isset($this->byLocalName[$n])) {
            return $this->byLocalName[$n];
        }

        return null;
    }

    /** Método por el que resolvió (para reportes). */
    public function methodFor(?int $exerciseId, ?string $name, Exercise $match): string
    {
        $this->ensureLoaded();
        if ($exerciseId && (int) $match->id === $exerciseId) {
            return 'exercise_id';
        }
        $n = $this->normalize($name);
        if ($n !== '' && isset($this->aliases[$n]) && $this->aliases[$n] === (int) $match->id) {
            return 'alias';
        }
        if ($n !== '' && isset($this->byName[$n]) && (int) $this->byName[$n]->id === (int) $match->id) {
            return 'exact_name';
        }
        if ($n !== '' && isset($this->byLocalName[$n]) && (int) $this->byLocalName[$n]->id === (int) $match->id) {
            return 'exact_local_name';
        }
        return 'external_id';
    }

    // ── Auditoría (sugerencias, NUNCA autoaplica tokens) ─────────────────────

    /**
     * @return array{status:string,exercise:?Exercise,method:?string,confidence:float,candidates:list<array{id:int,name:string,score:float}>,suggested:?Exercise}
     */
    public function audit(?int $exerciseId, string $name): array
    {
        $this->ensureLoaded();

        $safe = $this->resolveSafe($exerciseId, $name);
        if ($safe) {
            return [
                'status'     => 'matched',
                'exercise'   => $safe,
                'method'     => $this->methodFor($exerciseId, $name, $safe),
                'confidence' => 1.0,
                'candidates' => [],
                'suggested'  => null,
            ];
        }

        $cands = $this->tokenCandidates($name);
        if (empty($cands)) {
            return ['status' => 'no_candidate', 'exercise' => null, 'method' => null, 'confidence' => 0.0, 'candidates' => [], 'suggested' => null];
        }

        $top = $cands[0]['score'];
        $second = $cands[1]['score'] ?? 0.0;

        // Empate cercano entre los dos mejores → ambiguo.
        if ($second > 0 && ($top - $second) < 0.05) {
            return ['status' => 'ambiguous', 'exercise' => null, 'method' => 'token', 'confidence' => $top, 'candidates' => $cands, 'suggested' => null];
        }

        // Hay candidato razonable, pero por seguridad requiere revisión humana
        // (se convierte en alias verificado). Nunca se autoaplica.
        $suggested = ($top >= 0.5) ? $this->byId[$cands[0]['id']] ?? null : null;

        return ['status' => 'needs_review', 'exercise' => null, 'method' => 'token', 'confidence' => $top, 'candidates' => $cands, 'suggested' => $suggested];
    }

    /**
     * @return list<array{id:int,name:string,score:float}>
     */
    public function tokenCandidates(string $name): array
    {
        $this->ensureLoaded();
        $q = $this->tokenSet($name);
        if (empty($q)) {
            return [];
        }

        $scored = [];
        foreach ($this->tokenIndex as $row) {
            $t = $row['tokens'];
            if (empty($t)) {
                continue;
            }
            $inter = count(array_intersect_key($q, $t));
            if ($inter === 0) {
                continue;
            }
            $union = count($q + $t);
            $score = $union > 0 ? $inter / $union : 0.0;
            $scored[] = [
                'id'    => (int) $row['exercise']->id,
                'name'  => (string) $row['exercise']->name,
                'score' => round($score, 3),
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, 5);
    }

    // ── Media del catálogo lista para serializar/persistir ───────────────────

    /**
     * @return array{exercise_id:int,video_url:?string,gif_url:?string,thumbnail_url:?string,media_type:string,playback_speed:mixed,equipment:?string,muscle_group:?string}
     */
    public function mediaFor(Exercise $ex): array
    {
        $video = $this->publicMediaUrl($ex->video_path);

        return [
            'exercise_id'    => (int) $ex->id,
            'video_url'      => $video,
            'gif_url'        => $this->publicMediaUrl($ex->gif_url),
            'thumbnail_url'  => $this->publicMediaUrl($ex->thumbnail_url),
            'media_type'     => $video ? 'video' : ($ex->media_type ?? 'gif'),
            'playback_speed' => $ex->playback_speed,
            'equipment'      => $ex->equipment,
            'muscle_group'   => $ex->muscle_group ?? $ex->body_part,
        ];
    }

    // ── Normalización ────────────────────────────────────────────────────────

    public function normalize(?string $s): string
    {
        $s = trim((string) $s);
        if ($s === '') {
            return '';
        }
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, self::ACCENTS);
        $s = preg_replace('/[^a-z0-9 ]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return trim($s);
    }

    /** @return array<string,true> */
    private function tokenSet(?string $s): array
    {
        $n = $this->normalize($s);
        if ($n === '') {
            return [];
        }
        $out = [];
        foreach (explode(' ', $n) as $t) {
            if ($t === '' || in_array($t, self::STOPWORDS, true)) {
                continue;
            }
            $out[$t] = true;
        }

        return $out;
    }

    private function publicMediaUrl(?string $v): ?string
    {
        if (! is_string($v) || trim($v) === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $v)) {
            return $v;
        }
        $base = rtrim(config('app.public_url') ?: url('/'), '/');
        $rel = ltrim($v, '/');
        if (! str_starts_with($rel, 'storage/')) {
            $rel = 'storage/' . $rel;
        }

        return "{$base}/{$rel}";
    }
}
