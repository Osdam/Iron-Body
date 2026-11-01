<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioPublication;
use App\Models\UltronStudio\StudioVersion;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Hito 7, B7-c: `verified` por entrada del historial (B-2 de `security` en el
 * Hito 5, diferido por la Enmienda 2 §E2.5). `GET publications` y las
 * `publications` del detalle de la versión enseñaban cada entrada sin
 * verificarla: solo se verificaba la cabeza. Ahora cada fila lleva `verified`,
 * con las mismas cuatro comprobaciones que la cabeza (`head.signatureOk`).
 *
 * Una fila tocada por DML (saltándose la guarda de solo inserción, como podría
 * hacerlo el rol dueño) sale `false`, y SOLO ella. La cabeza, el motor y
 * `publication-check` no cambian: miran la cabeza.
 */
class UltronStudioPublicationVerifiedTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const TABLA = 'ultron_studio_publications';

    /** Las claves de una entrada del historial: `PublicationEntryDto` y, al final, `verified`. */
    private const CLAVES = ['uuid', 'seq', 'action', 'version', 'effectiveHash', 'shortHash', 'labGate', 'actor', 'reason', 'at', 'verified'];

    private Admin $ana;

    private Admin $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
    }

    /** Una cadena sembrada: publicada en canario (1), promovida (2) y vuelta a Legacy (3, la cabeza). */
    private function cadena(): StudioVersion
    {
        $v = $this->seededVersion($this->ana, $this->root, $this->baseline(), 'PUBLISHED');
        $this->seedEntry(D::ACTION_PUBLISH, $v, $this->partOf($v), null);
        $this->seedEntry(D::ACTION_PROMOTE, $v, null, $this->partOf($v));
        $this->seedEntry(D::ACTION_LEGACY, null, null, null);

        return $v;
    }

    /**
     * Cambia columnas de una fila por DML, con los disparadores de la tabla
     * apagados mientras tanto (en PG, `DISABLE TRIGGER USER`, que exige ser el
     * dueño; en SQLite, se quitan y se vuelven a crear). La transacción de la
     * prueba lo deshace todo al final.
     *
     * @param  array<string, string>  $columnas
     */
    private function tocar(int $seq, array $columnas): void
    {
        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE '.self::TABLA.' DISABLE TRIGGER USER');
            $filas = DB::table(self::TABLA)->where('seq', $seq)->update($columnas);
            DB::statement('ALTER TABLE '.self::TABLA.' ENABLE TRIGGER USER');
        } else {
            $disparadores = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?", [self::TABLA]);
            foreach ($disparadores as $d) {
                DB::statement("DROP TRIGGER {$d->name}");
            }
            $filas = DB::table(self::TABLA)->where('seq', $seq)->update($columnas);
            foreach ($disparadores as $d) {
                DB::statement($d->sql);
            }
        }
        $this->assertSame(1, $filas, 'la fila no se tocó');
    }

    private function fila(int $seq): StudioPublication
    {
        return StudioPublication::query()->where('seq', $seq)->sole();
    }

    /** @return array<int, bool> seq => verified, del historial */
    private function historial(): array
    {
        return array_column($this->studio('GET', 'publications', $this->root)->assertOk()->json('data.data'), 'verified', 'seq');
    }

    /** @return array<int, bool> seq => verified, de las `publications` del detalle de la versión */
    private function detalle(StudioVersion $v): array
    {
        return array_column($this->studio('GET', "versions/{$v->uuid}", $this->root)->assertOk()->json('data.publications'), 'verified', 'seq');
    }

    /** @return array{0:int,1:string} el código de salida y el JSON de `publication-check` */
    private function comprobacion(): array
    {
        $codigo = Artisan::call('ultron-studio:publication-check', ['--json' => true]);

        return [$codigo, Artisan::output()];
    }

    /** @return array<string, array{Closure(StudioPublication, StudioPublication): array<string, string>}> */
    public static function manipulaciones(): array
    {
        return [
            'la firma, rehecha con otra clave' => [static fn (StudioPublication $f): array => [
                'signature' => hash_hmac('sha256', D::CONTRACT."\n".$f->effective_json, random_bytes(32)),
            ]],
            'la huella' => [static fn (): array => ['effective_hash' => str_repeat('0', 64)]],
            'el texto, con su huella rehecha y la firma vieja' => [static function (StudioPublication $f): array {
                $texto = str_replace('"seq":2', '"seq":2 ', (string) $f->effective_json);

                return ['effective_json' => $texto, 'effective_hash' => D::effectiveHash($texto)];
            }],
            // Texto, huella y firma buenos, pero de OTRA fila: solo lo caza la
            // decodificación atada al uuid y al seq de la fila.
            'texto, huella y firma de la entrada 1' => [static fn (StudioPublication $f, StudioPublication $primera): array => [
                'effective_json' => (string) $primera->effective_json,
                'effective_hash' => (string) $primera->effective_hash,
                'signature' => (string) $primera->signature,
            ]],
        ];
    }

    /** @param  Closure(StudioPublication, StudioPublication): array<string, string>  $manipulacion */
    #[DataProvider('manipulaciones')]
    public function test_una_entrada_tocada_por_dml_sale_verified_false_y_solo_ella(Closure $manipulacion): void
    {
        $v = $this->cadena();
        $this->assertSame([3 => true, 2 => true, 1 => true], $this->historial());
        $this->assertSame([2 => true, 1 => true], $this->detalle($v));
        $antes = $this->comprobacion();
        $this->assertSame(0, $antes[0], $antes[1]);

        $this->tocar(2, $manipulacion($this->fila(2), $this->fila(1)));

        $this->assertSame([3 => true, 2 => false, 1 => true], $this->historial());
        $this->assertSame([2 => false, 1 => true], $this->detalle($v));

        // La cabeza no cambia de semántica: verifica, y `publication-check` dice
        // exactamente lo mismo que antes (solo mira la cabeza).
        $estado = $this->studio('GET', 'status', $this->root)->assertOk()->json('data.engine.publication');
        $this->assertSame([3, true], [$estado['head']['seq'], $estado['head']['signatureOk']]);
        $this->assertSame($antes, $this->comprobacion());
    }

    /** `verified` de la cabeza es su `head.signatureOk`: una cabeza tocada sale false en los dos sitios, y `publication-check` falla, como antes. */
    public function test_la_cabeza_tocada_sale_false_en_el_historial_y_en_signature_ok(): void
    {
        $this->cadena();
        $this->tocar(3, ['signature' => str_repeat('0', 64)]);

        $this->assertSame([3 => false, 2 => true, 1 => true], $this->historial());
        $this->assertFalse($this->studio('GET', 'status', $this->root)->assertOk()->json('data.engine.publication.head.signatureOk'));
        $this->assertSame(1, $this->comprobacion()[0]);
    }

    /**
     * Lo que firma el Studio de verdad (publicar y volver a Legacy por HTTP)
     * verifica. La forma solo suma `verified` al final, y el historial no
     * enseña ni el texto firmado ni la firma. Las respuestas de las escrituras
     * no cambian (sin `verified`).
     */
    public function test_lo_que_escribe_el_studio_verifica_y_la_respuesta_solo_suma_verified(): void
    {
        $this->importBaseline();
        $this->openKeys();
        $v = $this->approvedVersion($this->ana, $this->root);
        $publicada = $this->publishAs($this->root, $v->uuid)->assertCreated()->json('data');
        $this->legacyAs($this->root)->assertCreated();
        $this->assertArrayNotHasKey('verified', $publicada);

        $respuesta = $this->studio('GET', 'publications', $this->root)->assertOk();
        $entradas = $respuesta->json('data.data');
        $this->assertSame([2 => true, 1 => true], array_column($entradas, 'verified', 'seq'));
        $this->assertSame([2 => self::CLAVES, 1 => self::CLAVES], [2 => array_keys($entradas[0]), 1 => array_keys($entradas[1])]);
        $this->assertSame(array_diff_key($entradas[1], ['verified' => true]), $publicada, 'la entrada del historial es la de la escritura más verified');
        $this->assertSame([1 => true], $this->detalle($v));

        foreach (StudioPublication::query()->get() as $f) {
            $this->assertStringNotContainsString((string) $f->signature, $respuesta->getContent());
            $this->assertStringNotContainsString((string) $f->effective_json, $respuesta->getContent());
        }
    }
}
