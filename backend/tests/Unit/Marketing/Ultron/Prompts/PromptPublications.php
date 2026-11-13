<?php

namespace Tests\Unit\Marketing\Ultron\Prompts;

use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\UltronPublishedConfig as Lector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Andamio de las pruebas de prompts: entradas REALES de la cadena de
 * publicaciones (`/1` y `/2`), firmadas con la única firma del motor e
 * insertadas en la tabla de la suite dentro de la transacción de cada prueba
 * (la cadena es de solo inserción). Mismo esquema que el andamio de
 * `UltronCommitStudioStampTest` (encadenado del Hito 7 incluido).
 *
 * En PostgreSQL, `/2` lo admite la migración `2026_11_16_100001` (paquete del
 * especialista de datos); un contrato desconocido (`/3`) exige quitar el
 * CHECK dentro de la transacción de la prueba.
 */
trait PromptPublications
{
    private int $seqDeLaCadena = 0;

    /** @return array<string,mixed> una parte `/1` */
    protected static function parteV1(string $version): array
    {
        return [
            'approvedBy' => 12,
            'catalogVersion' => '2026-10-19.1',
            'contentHash' => hash('sha256', $version),
            'declared' => [
                'governance.canaryOnly' => true,
                'governance.enabled' => true,
                'intent.driver' => 'entorno',
                'rules.paymentLinksEnabled' => false,
            ],
            'publishedBy' => 7,
            'version' => $version,
        ];
    }

    /**
     * Una parte `/2`: la de `/1` más los gobernados y la huella del paquete,
     * que se calcula con el compilador salvo que la prueba la fije.
     *
     * @param  array<string,mixed>  $governed  cambios sobre la línea base
     * @param  array<string,mixed>  $prompts  cambios sobre {base, bundleHash, compiler}
     * @return array<string,mixed>
     */
    protected static function parteV2(string $version, array $governed = [], array $prompts = []): array
    {
        $valores = array_replace(UltronPromptCompiler::BASELINE, $governed);
        $huella = array_key_exists('bundleHash', $prompts) ? $prompts['bundleHash'] : UltronPromptCompiler::compile($valores)['bundleHash'];

        return self::parteV1($version) + [
            'governed' => $valores,
            'prompts' => array_replace(['base' => UltronPromptCompiler::BASE, 'bundleHash' => $huella, 'compiler' => UltronPromptCompiler::VERSION], $prompts),
        ];
    }

    /**
     * Una parte `/2` firmada con el compilador 1 (antes de las reglas S-NNN):
     * `governed` con las cuatro claves de entonces y la huella de esos cuatro
     * valores con el compilador de hoy (con `maxChars` ≠ 320, el 1 daba otra:
     * da igual, `decide` compara el compilador antes que la huella). Es lo que
     * queda en la cadena al subir el compilador: cae a `compiler_changed`.
     *
     * @param  array<string,mixed>  $governed  cambios sobre la línea base
     * @return array<string,mixed>
     */
    protected static function parteV2Compilador1(string $version, array $governed = []): array
    {
        $parte = self::parteV2($version, $governed, ['compiler' => 1]);
        unset($parte['governed'][UltronPromptCompiler::PHRASES]);

        return $parte;
    }

    /**
     * Inserta una entrada y devuelve la fila.
     *
     * @param  array<string,mixed>|null  $canary
     * @param  array<string,mixed>|null  $rest
     * @param  array<string,mixed>  $sobre  columnas que la prueba quiere distintas (`signature` puede ser un cierre sobre el JSON)
     * @return array<string,mixed>
     */
    protected function publicaEntrada(string $contrato, ?array $canary, ?array $rest, array $sobre = []): array
    {
        $uuid = (string) Str::uuid();
        $seq = ++$this->seqDeLaCadena;
        $json = self::canonico(['canary' => $canary, 'contract' => $contrato, 'entry' => $uuid, 'issuedAt' => '2026-11-11T15:04:05Z', 'rest' => $rest, 'seq' => $seq]);
        $legacy = ! str_contains($json, '"version"');

        $fila = array_merge([
            'uuid' => $uuid,
            'seq' => $seq,
            'client_request_id' => (string) Str::uuid(),
            'action' => $legacy ? 'legacy' : 'publish',
            'version_id' => null,
            'restores_publication_id' => null,
            'previous_publication_id' => DB::table('ultron_studio_publications')->orderByDesc('seq')->value('id'),
            'engine_contract' => $contrato,
            'effective_json' => $json,
            'effective_hash' => hash('sha256', $json),
            'signature' => Lector::signatureFor($json),
            'catalog_version' => '2026-10-19.1',
            'lab_gate' => json_encode(['mode' => 'waived']),
            'acknowledged' => null,
            'evidence' => null,
            'reason' => $legacy ? 'Prueba de los prompts del motor' : null,
            'actor_admin_id' => 7,
            'actor_name' => 'Persona de pruebas',
            'actor_role' => 'Super Admin',
            'created_at' => now(),
        ], $sobre);
        // Una firma que la prueba calcula sobre los bytes de la entrada (p. ej. con otro prefijo).
        if ($fila['signature'] instanceof \Closure) {
            $fila['signature'] = ($fila['signature'])($json);
        }

        if (! in_array($fila['engine_contract'], Lector::CONTRACTS, true) && DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ultron_studio_publications DROP CONSTRAINT IF EXISTS uspub_engine_contract_chk');
        }
        DB::table('ultron_studio_publications')->insert($fila);

        return $fila;
    }

    /** El JSON como lo emite el Studio: claves ordenadas, sin escapar barras ni unicode. */
    protected static function canonico(array $valor): string
    {
        $ordena = function (mixed $v) use (&$ordena): mixed {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v, SORT_STRING);
            }

            return array_map($ordena, $v);
        };

        return json_encode($ordena($valor), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** La carga firmada de un `decide_token` (sin verificar la firma: eso es de UltronDecideToken). */
    protected static function cargaDelToken(string $token): array
    {
        [$cuerpo] = explode('.', $token);

        return json_decode((string) base64_decode(strtr($cuerpo, '-_', '+/')), true, 32, JSON_THROW_ON_ERROR);
    }
}
