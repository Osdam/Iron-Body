<?php

namespace Tests\Feature\Outreach\Guards;

use App\Services\UltronStudio\Stream\StreamEvent;
use App\Services\UltronStudio\Stream\StreamTopics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * DATOS PERSONALES (contrato H9, §3 protección 10, D9-13, D9-15, T5;
 * «Paquete SSE9»; SEC9-a). Heurísticas por nombre, como las del H8: cazan los
 * errores corrientes, no los disimulados.
 *
 *  - Ninguna columna de las 15 tablas del H9 lleva el dato en claro de la
 *    persona contactada (teléfono, correo, documento, nombre de pila, fecha de
 *    nacimiento, cuerpo o texto de un mensaje, importe, dirección) ni el
 *    cuerpo crudo de una petición. La persona es `subject_key`/`subject_ref`
 *    y el teléfono `phone_ref`. Los nombres que sí hay son del PERSONAL
 *    (`*_by_name`, `actor_name`, `reviewer_name`) y el de la ESTRATEGIA
 *    (`ultron_studio_strategies.name`): el patrón del libro del H5.
 *  - Ningún teléfono escrito en el código del H9.
 *  - El canal SSE: la lista blanca del evento está anclada, y cada tema solo
 *    proyecta columnas de una lista cerrada (ids, uuids, estados, códigos,
 *    huellas e instantes).
 *  - La supresión 1581 (`ops/supresion-1581.sql`) hoy no conoce las tablas
 *    del H9 con `subject_key`: queda anotado como pendiente de OPS9 con la
 *    lista exacta, y la prueba cae cuando el script las incorpore para que se
 *    vacíe la lista.
 */
class OutreachPersonalDataGuardTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATIONS = [
        'database/migrations/2026_11_12_100001_create_ultron_studio_strategy_tables.php',
        'database/migrations/2026_11_12_100002_create_outreach_tables.php',
        'database/migrations/2026_11_12_100003_add_outreach_read_indexes.php',
    ];

    /** Nombres de columna que delatan el dato de la persona en claro (D9-13 «sin teléfonos, nombres, documentos ni textos»). */
    private const PERSONAL = '/(^|_)(phone|telefono|celular|movil|msisdn|e164|email|correo|document|documento|cedula|dni|nit|first_name|last_name|full_name|given_name|surname|apellido|birth|birthday|nacimiento|age|edad|body|cuerpo|text|texto|transcript|transcripcion|audio|recording|amount|importe|monto|debt|deuda|saldo|balance|address|direccion|city|ciudad)(_|$)/';

    /** El cuerpo crudo de una petición o respuesta (D8-17 del H8, igual aquí). */
    private const RAW = '/^(payload|raw|raw_body|request_body|response_body|request|response|body|cuerpo|carga)$/';

    /** Un seudónimo, una huella o una clave interna: `phone_ref`, `subject_ref`, `content_hash`, `subject_key` (D9-13). */
    private const SAFE_SUFFIX = '/(_ref|_refs|_hash|_key|_count|_len|_length)$/';

    /** Métodos de Blueprint que no declaran una columna. */
    private const NOT_A_COLUMN = ['comment', 'default', 'index', 'unique', 'primary', 'foreign', 'on', 'references', 'dropcolumn', 'dropindex', 'dropunique',
        'dropforeign', 'renamecolumn', 'raw', 'table', 'create', 'drop', 'dropifexists', 'hastable', 'hascolumn', 'spatialindex', 'fulltext', 'after', 'nullable',
        'useCurrent', 'constrained', 'cascadeOnDelete', 'restrictOnDelete', 'nullOnDelete', 'check', 'storedAs', 'virtualAs', 'charset', 'collation', 'from', 'startingValue'];

    /** Un teléfono colombiano (móvil o fijo) o un E.164 escrito en el código. */
    private const PHONE_LITERAL = '/\+[1-9][0-9]{7,14}(?![0-9])|(?<![0-9a-f])(57)?(3[0-9]{9}|60[0-9]{8})(?![0-9a-f])/';

    private const H9_CODE = ['app/Services/Outreach', 'app/Services/UltronStudio/Stream', 'app/Console/Commands/Outreach'];

    /** Las columnas que los temas del canal pueden leer de sus filas (`$fila->x`): identidad, estado, código, huella, instante. */
    private const TOPIC_ROW_FIELDS = [
        'id', 'uuid', 'kind', 'reason', 'open_reason', 'open_uuid', 'created_at', 'updated_at', 'occurred_at', 'last_seen_at', 'status', 'direction', 'call_uuid',
        'seq', 'action', 'scope', 'effective_hash', 'event', 'subject_type', 'subject_uuid', 'to_status', 'content_hash', 'verdict', 'end_reason', 'touch', 'occurrences',
    ];

    /** Lo que ningún fichero del canal nombra (ni como columna ni como clave). */
    private const TOPIC_FORBIDDEN = ["'phone", "'remote_", "'name'", "'email", "'document", "'body", "'transcript", "'note", "'script", "'evidence", "'facts", "'trace", "'amount", "'caller", "'to_number", "'from_number"];

    /**
     * Las tablas del H9 con `subject_key` que `ops/supresion-1581.sql` todavía
     * no suprime. OPS9 amplía el script (D9-15, L9-5): cuando lo haga, esta
     * lista se vacía (la prueba cae a propósito hasta entonces).
     */
    /** D9-15: OPS9-b incorporó las 4 tablas del H9 a supresion-1581.sql (L9-66, H-10 de OPS9-b): la lista queda vacía a propósito. */
    private const SUPPRESSION_PENDING_OPS9 = [];

    public function test_ninguna_columna_del_h9_lleva_el_dato_de_la_persona_en_claro(): void
    {
        $columnas = 0;
        foreach (self::MIGRATIONS as $relativa) {
            $this->assertFileExists(base_path($relativa));
            $tokens = $this->significantTokens(base_path($relativa));
            foreach ($tokens as $i => $t) {
                if (is_array($t) && $t[0] === T_VARIABLE && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_OBJECT_OPERATOR
                    && is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][0] === T_STRING && ($tokens[$i + 3] ?? null) === '('
                    && is_array($tokens[$i + 4] ?? null) && $tokens[$i + 4][0] === T_CONSTANT_ENCAPSED_STRING
                    && ! in_array(strtolower($tokens[$i + 2][1]), array_map('strtolower', self::NOT_A_COLUMN), true)) {
                    $columna = trim($tokens[$i + 4][1], "'\"");
                    if (preg_match('/^[a-z][a-z0-9_]*$/', $columna) !== 1) {
                        continue;
                    }
                    $columnas++;
                    $this->assertColumnIsNotPersonal($columna, "{$relativa} declara la columna «{$columna}»");
                }
            }
        }
        $this->assertGreaterThan(150, $columnas, 'el rastreo ve las columnas de DB9');

        // Y en el esquema real: ninguna tabla del H9 con una columna así (por si una migración futura las añade con otro helper).
        foreach ($this->h9Tables() as $tabla) {
            foreach (Schema::getColumnListing($tabla) as $columna) {
                $this->assertColumnIsNotPersonal($columna, "{$tabla}.{$columna}");
            }
        }
    }

    /** Un seudónimo o una huella (`phone_ref`, `subject_ref`, `content_hash`) vale; el dato en claro o el cuerpo crudo, no. */
    private function assertColumnIsNotPersonal(string $columna, string $donde): void
    {
        $this->assertDoesNotMatchRegularExpression(self::RAW, $columna, "{$donde}: el cuerpo crudo lleva el teléfono y el texto (D8-17).");
        if (preg_match(self::SAFE_SUFFIX, $columna) === 1) {
            return;
        }
        $this->assertDoesNotMatchRegularExpression(self::PERSONAL, $columna, "{$donde} (protección 10: la persona es subject_key/subject_ref y el teléfono phone_ref).");
    }

    public function test_sin_telefonos_escritos_en_el_codigo_del_h9(): void
    {
        $vistos = 0;
        foreach ($this->phpFiles(self::H9_CODE) as $relativa) {
            $vistos++;
            $this->assertDoesNotMatchRegularExpression(self::PHONE_LITERAL, $this->codeOnly(base_path($relativa)), "{$relativa} lleva un teléfono escrito.");
        }
        $this->assertGreaterThan(60, $vistos);
    }

    public function test_el_evento_sse_tiene_la_lista_blanca_anclada_y_cada_tema_solo_proyecta_columnas_de_la_lista(): void
    {
        $this->assertSame(['type', 'id', 'state', 'at', 'hash', 'ref'], StreamEvent::KEYS, 'La forma del evento es parte del contrato SSE9.');
        $this->assertSame(128, StreamEvent::MAX_VALUE_LENGTH);
        $this->assertSame(['ledger', 'voice.calls', 'voice.brake', 'voice.reconcile', 'voice.publications', 'voice.lab', 'monitor.incidents',
            'outreach.runs', 'outreach.jobs', 'outreach.outcomes', 'outreach.brakes'], StreamTopics::NAMES);

        $temas = 0;
        foreach ($this->phpFiles(['app/Services/UltronStudio/Stream']) as $relativa) {
            $codigo = $this->codeOnly(base_path($relativa));
            foreach (self::TOPIC_FORBIDDEN as $prohibido) {
                $this->assertStringNotContainsString($prohibido, $codigo, "{$relativa} nombra «{$prohibido}»: el canal no lleva datos personales.");
            }
            if (! str_ends_with($relativa, 'Topic.php')) {
                continue;
            }
            $temas++;
            preg_match_all('/\$(?:row|f|fila|b|e|c|r)->([a-z_]+)/', $codigo, $m);
            foreach (array_unique($m[1]) as $campo) {
                $this->assertContains($campo, self::TOPIC_ROW_FIELDS, "{$relativa} proyecta la columna «{$campo}», fuera de la lista cerrada del canal.");
            }
        }
        $this->assertGreaterThanOrEqual(8, $temas, 'el rastreo ve los temas');
    }

    public function test_la_supresion_1581_del_h9_queda_anotada_como_pendiente_de_ops9_con_la_lista_exacta(): void
    {
        $script = base_path('docs/ultron-studio/ops/supresion-1581.sql');
        $this->assertFileExists($script);
        preg_match_all('/\b(outreach_[a-z_]+|ultron_studio_[a-z_]+)\b/', (string) file_get_contents($script), $m);
        $conocidas = array_unique($m[1]);

        $conSujeto = [];
        foreach ($this->h9Tables() as $tabla) {
            if (Schema::hasColumn($tabla, 'subject_key') || Schema::hasColumn($tabla, 'phone_ref')) {
                $conSujeto[] = $tabla;
            }
        }
        sort($conSujeto);
        $this->assertNotSame([], $conSujeto, 'las tablas con persona existen');
        // Lo que lleva `subject_key` tiene la columna «una vez» de supresión (L9-5).
        foreach ($conSujeto as $tabla) {
            $this->assertTrue(Schema::hasColumn($tabla, 'redacted_at'), "{$tabla} sin redacted_at (L9-5)");
        }

        $faltan = array_values(array_diff($conSujeto, $conocidas));
        $this->assertSame(self::SUPPRESSION_PENDING_OPS9, $faltan, 'La supresión 1581 cambió de cobertura: si OPS9 ya incorporó una tabla, quítala de SUPPRESSION_PENDING_OPS9; si el H9 añadió otra con subject_key, OPS9 tiene que cubrirla.');
        if ($faltan !== []) {
            fwrite(STDERR, "\n[SEC9-a] supresion-1581.sql todavía no cubre: ".implode(', ', $faltan)." (pendiente de OPS9, D9-15)\n");
        }
    }

    // ────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function h9Tables(): array
    {
        $tablas = [];
        foreach (Schema::getTables() as $t) {
            $nombre = is_array($t) ? ($t['name'] ?? '') : (string) ($t->name ?? '');
            if (preg_match('/^(outreach_|ultron_studio_(strateg|message_templates|person_bindings))/', $nombre) === 1) {
                $tablas[] = $nombre;
            }
        }
        sort($tablas);
        $this->assertGreaterThanOrEqual(15, count($tablas), 'las 15 tablas del H9 están migradas');

        return $tablas;
    }

    /**
     * @param  list<string>  $raices
     * @return list<string>
     */
    private function phpFiles(array $raices): array
    {
        $base = rtrim(base_path(), '/').'/';
        $out = [];
        foreach ($raices as $raiz) {
            if (! is_dir($base.$raiz)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.$raiz, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $out[] = substr($f->getPathname(), strlen($base));
                }
            }
        }
        sort($out);

        return $out;
    }

    private function codeOnly(string $ruta): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($ruta)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    /** @return list<array{0:int,1:string,2:int}|string> */
    private function significantTokens(string $ruta): array
    {
        return array_values(array_filter(
            token_get_all((string) file_get_contents($ruta)),
            static fn ($t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
    }
}
