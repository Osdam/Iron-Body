<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioAuditEvent;
use Illuminate\Support\Facades\DB;

/**
 * CUATRO OJOS POR REGLA (B-2 de seguridad; opción A de
 * `plans/cierre/plan-quejas-consentimiento-cuatro-ojos.md` §3; Enmienda del
 * 22-11-2026 al contrato H5, A5.1 paso 4). Quien escribió las frases que una
 * versión copia de una regla S-NNN no aprueba esa versión, ni la publica, ni
 * la promueve, en WhatsApp ni en la voz, aunque la creara otra persona.
 * También el Super Admin: se comparan identidades, nunca permisos. Rechazar
 * NO pasa por aquí, porque va en la dirección segura (D15).
 *
 * QUÉ REGLAS CUENTAN: las que aportan frases a ESA versión, con el mismo
 * filtro que las compila ({@see StudioRulePhrases::byRule()}): S-NNN, no
 * archivada en el contenido, con `forbid text contains` y con su «si»
 * cumplido. Una S-NNN sin frases, o cuyo «si» no se cumple en la versión, no
 * convierte a nadie en autor.
 *
 * QUIÉN ES AUTOR: quien escribió CUALQUIER revisión ≤ la copiada. El dato sale
 * del LIBRO: `rule.created` es la revisión 1 y `rule.updated`, la de su
 * `diff.toRevision`. No sale de `created_by`/`updated_by` de la fila, que
 * solo dicen quién editó por última vez la revisión VIGENTE: si A escribe la
 * frase en la revisión 1 y B cambia la prioridad en la 2, el último editor es
 * B. Cada escritura de una regla y su línea del libro van en la misma
 * transacción ({@see StudioRules}), y el libro es de solo inserción.
 *
 * FALLA CERRADO (`unverified`): si a una revisión ≤ la copiada le falta su
 * línea del libro, o la línea no tiene actor, o la revisión copiada no es un
 * entero ≥ 1, nadie puede demostrar que no la escribió, así que se niega a
 * todos.
 *
 * UNA consulta por versión, y ninguna si la versión no lleva frases de
 * reglas: del código a la fila (`code` es UNIQUE) y de la fila al libro por
 * `usae_subject_idx`. El resultado solo depende del contenido, de quien mira
 * y de líneas del libro que ya no cambian (las de las revisiones ≤ la
 * copiada), así que la vista previa y la escritura dicen lo mismo.
 *
 * Lo que devuelve a quien mira son SUS revisiones de reglas que la versión ya
 * enseña a cualquiera con `ultron.view` (su `content.rules`), y que el libro
 * de las reglas ya le enseña. Nunca dice quién escribió las demás.
 */
final class StudioRulePhraseAuthors
{
    /** El `detail` del 403 `four_eyes_required` y de su requisito. */
    public const DETAIL = 'rule_phrase_author';

    /** Las líneas del libro que crean una revisión de una regla del Studio. */
    private const REVISION_EVENTS = ['rule.created', 'rule.updated'];

    /**
     * Las reglas de la versión cuya autoría impide a `$adminId` aprobarla,
     * publicarla o promoverla, por orden de código.
     *
     *  - `null`: la versión no lleva frases de ninguna regla (no hay consulta).
     *  - `[]`: las lleva, y `$adminId` no escribió ninguna revisión copiada.
     *  - Una lista: las reglas con `authored` (sus revisiones ≤ la copiada) o
     *    `unverified` (falla cerrado).
     *
     * @param  array<string,mixed>  $content  el `content` de una versión
     * @return list<array{code:string,revision:int|null,authored:list<int>,unverified:bool}>|null
     */
    public static function of(array $content, int $adminId): ?array
    {
        $copiadas = self::copiedRevisions($content);
        if ($copiadas === []) {
            return null;
        }

        $lineas = self::revisionLines(array_map('strval', array_keys($copiadas)));

        $out = [];
        foreach ($copiadas as $codigo => $copiada) {
            $codigo = (string) $codigo;
            $suyas = [];
            $conLinea = [];
            $sinActor = false;
            foreach ($lineas[$codigo] ?? [] as [$revision, $actor]) {
                if ($copiada === null || $revision > $copiada) {
                    continue;
                }
                $conLinea[$revision] = true;
                if ($actor === null) {
                    $sinActor = true;
                } elseif ($actor === $adminId) {
                    $suyas[$revision] = true;
                }
            }
            $suyas = array_map('intval', array_keys($suyas));
            sort($suyas);
            $sinVerificar = $copiada === null || $sinActor || count($conLinea) < $copiada;

            if ($suyas !== [] || $sinVerificar) {
                $out[] = ['code' => $codigo, 'revision' => $copiada, 'authored' => $suyas, 'unverified' => $sinVerificar];
            }
        }

        return $out;
    }

    /**
     * El requisito `rulePhraseAuthor` de la vista previa de publicar: solo
     * existe si la versión lleva frases de reglas.
     *
     * @param  list<array{code:string,revision:int|null,authored:list<int>,unverified:bool}>  $rules  lo que dio {@see of()}
     * @return array{ok:bool,message?:string,rules:list<array{code:string,revision:int|null,authored:list<int>,unverified:bool}>}
     */
    public static function requirement(array $rules, string $action): array
    {
        return $rules === []
            ? ['ok' => true, 'rules' => []]
            : ['ok' => false, 'message' => self::message($rules, $action), 'rules' => $rules];
    }

    /**
     * El 403 `four_eyes_required` de siempre, con el motivo (`detail`) y las
     * reglas.
     *
     * @param  list<array{code:string,revision:int|null,authored:list<int>,unverified:bool}>  $rules
     */
    public static function refusal(array $rules, string $action): UltronStudioException
    {
        return new UltronStudioException(self::message($rules, $action), 'four_eyes_required', 403, [
            'detail' => self::DETAIL,
            'rulePhraseAuthor' => $rules,
        ]);
    }

    /**
     * El `diff` del evento `*.four_eyes_denied`: los códigos, y aparte los que
     * se niegan por falta de rastro en el libro.
     *
     * @param  list<array{code:string,revision:int|null,authored:list<int>,unverified:bool}>  $rules
     * @return array{rulePhraseAuthor:list<string>,rulePhraseUnverified?:list<string>}
     */
    public static function auditDiff(array $rules): array
    {
        $sinVerificar = array_values(array_map(
            static fn (array $r): string => $r['code'],
            array_filter($rules, static fn (array $r): bool => $r['unverified']),
        ));

        return ['rulePhraseAuthor' => array_column($rules, 'code')]
            + ($sinVerificar === [] ? [] : ['rulePhraseUnverified' => $sinVerificar]);
    }

    /**
     * «Escribiste S-003, revisión 2: tiene que aprobarla otra persona.»
     *
     * @param  list<array{code:string,revision:int|null,authored:list<int>,unverified:bool}>  $rules
     * @param  string  $action  `approve`, `publish` o `promote`
     */
    public static function message(array $rules, string $action): string
    {
        [$verbo, $infinitivo] = match ($action) {
            'publish' => ['publicarla', 'publica'],
            'promote' => ['promoverla', 'promueve'],
            default => ['aprobarla', 'aprueba'],
        };

        $escritas = [];
        $sinRastro = [];
        foreach ($rules as $r) {
            if ($r['authored'] !== []) {
                $n = $r['authored'];
                $ultima = array_pop($n);
                $escritas[] = $r['code'].', '.($n === [] ? "revisión {$ultima}" : 'revisiones '.implode(', ', $n)." y {$ultima}");
            }
            if ($r['unverified']) {
                $sinRastro[] = $r['code'];
            }
        }

        $partes = [];
        if ($escritas !== []) {
            $partes[] = 'Escribiste '.implode('; ', $escritas).": tiene que {$verbo} otra persona.";
        }
        if ($sinRastro !== []) {
            $partes[] = 'No consta en el libro quién escribió todas las revisiones de '.implode(', ', $sinRastro)
                .": no se {$infinitivo} una versión que la copie.";
        }

        return implode(' ', $partes);
    }

    /**
     * Las reglas que aportan frases, con la revisión copiada (null si no es un
     * entero ≥ 1). Con dos copias del mismo código (un contenido que la API no
     * produce), la mayor: cuenta más autores, nunca menos.
     *
     * @param  array<string,mixed>  $content
     * @return array<string,int|null>
     */
    private static function copiedRevisions(array $content): array
    {
        $codigos = StudioRulePhrases::codes(StudioRulePhrases::byRule($content));
        if ($codigos === []) {
            return [];
        }

        $copiadas = [];
        foreach ($codigos as $codigo) {
            $copiadas[$codigo] = 0;
        }
        foreach (is_array($content['rules'] ?? null) ? $content['rules'] : [] as $regla) {
            if (! is_array($regla) || ! is_string($regla['code'] ?? null) || ! array_key_exists($regla['code'], $copiadas)
                || ($regla['status'] ?? null) === StudioRuleCoherence::STATUS_ARCHIVED) {
                continue;
            }
            $revision = $regla['revision'] ?? null;
            $previa = $copiadas[$regla['code']];
            $copiadas[$regla['code']] = ! is_int($revision) || $revision < 1 || $previa === null ? null : max($previa, $revision);
        }

        // Una regla que `byRule` dio y no tiene revisión (no debería pasar): falla cerrado.
        return array_map(static fn (?int $r): ?int => $r === 0 ? null : $r, $copiadas);
    }

    /**
     * Las líneas del libro que crean revisiones de esas reglas, en UNA
     * consulta: por código, `[revisión, actor]` (actor null si la línea no
     * tiene persona). Una línea sin revisión legible no cuenta, así que esa
     * revisión queda sin rastro.
     *
     * @param  list<string>  $codes
     * @return array<string,list<array{0:int,1:int|null}>>
     */
    private static function revisionLines(array $codes): array
    {
        $filas = DB::table('ultron_studio_audit_events as e')
            ->join('ultron_studio_rules as r', 'r.uuid', '=', 'e.subject_uuid')
            ->where('e.subject_type', StudioAuditEvent::SUBJECT_RULE)
            ->whereIn('e.event', self::REVISION_EVENTS)
            ->whereIn('r.code', $codes)
            ->orderBy('e.id')
            ->get(['r.code', 'e.event', 'e.actor_admin_id', 'e.diff']);

        $out = [];
        foreach ($filas as $f) {
            if ($f->event === 'rule.created') {
                $revision = 1;
            } else {
                $diff = is_string($f->diff) ? json_decode($f->diff, true) : null;
                $revision = is_array($diff) && is_int($diff['toRevision'] ?? null) ? $diff['toRevision'] : null;
            }
            if ($revision === null || $revision < 1) {
                continue;
            }
            $out[(string) $f->code][] = [$revision, $f->actor_admin_id === null ? null : (int) $f->actor_admin_id];
        }

        return $out;
    }
}
