<?php

namespace App\Console\Commands\UltronStudio;

use App\Services\UltronStudio\StudioCatalog;
use Illuminate\Console\Command;
use JsonException;

/**
 * Vuelca el catálogo de ULTRON STUDIO y los casos de paridad a tres ficheros
 * que las pruebas del CRM puedan importar.
 *
 * Existe porque el CRM valida el borrador en vivo mientras se edita y el
 * servidor repite la validación y manda él. Si los dos lados no pintan y no
 * validan con el mismo catálogo, o si su validación diverge, alguien ve un
 * borrador «publicable» que el servidor rechaza al guardar, y nada lo avisa
 * leyendo un solo repositorio.
 *
 *  - `backend-studio-catalog.testing.ts`: StudioCatalog::toArray(), la forma
 *    exacta de `GET catalog` (con `bindings`, `readings` y `legacyConditions`
 *    desde el Hito G).
 *  - `backend-validation-cases.testing.ts`: los casos de
 *    `tests/fixtures/ultron-studio/validation-cases.json` tal cual, con la
 *    salida esperada de la referencia. El backend los cumple
 *    (StudioValidationParityTest) y el CRM tiene que cumplirlos también.
 *  - `backend-coherence-cases.testing.ts` (Hito G): los casos de
 *    `tests/fixtures/ultron-studio/coherence-cases.json` tal cual. Los escribe
 *    el tester desde el contrato; el backend los cumple
 *    (StudioCoherenceParityTest) y el CRM también.
 *
 * No autoriza nada ni se usa en tiempo de ejecución: es un contrato
 * comprobable entre dos repositorios.
 */
class ExportStudioFixturesCommand extends Command
{
    protected $signature = 'ultron-studio:export-fixtures {--path= : directorio donde escribir los tres .ts}';

    protected $description = 'Exporta el catálogo de ULTRON STUDIO y los casos de paridad para las pruebas del CRM';

    public const CATALOG_FILE = 'backend-studio-catalog.testing.ts';

    public const CASES_FILE = 'backend-validation-cases.testing.ts';

    public const COHERENCE_FILE = 'backend-coherence-cases.testing.ts';

    /** Las listas que tiene que traer el fixture de coherencia (versión 1). */
    public const COHERENCE_SECTIONS = ['canonicalize', 'analyze', 'conflicts', 'evaluate'];

    public function handle(): int
    {
        $destino = rtrim((string) ($this->option('path')
            ?: base_path('../../Iron-Body_Front/src/app/modules/ultron-studio/testing')), '/');

        // El directorio `testing/` se crea si hace falta, pero solo dentro de
        // uno que exista: una ruta mal escrita no siembra carpetas por ahí.
        if (! is_dir($destino)) {
            if (! is_dir(dirname($destino))) {
                $this->error('No existe el directorio: '.dirname($destino));

                return self::FAILURE;
            }
            if (! mkdir($destino) && ! is_dir($destino)) {
                $this->error('No se pudo crear el directorio: '.$destino);

                return self::FAILURE;
            }
        }

        $origen = base_path('tests/fixtures/ultron-studio/validation-cases.json');
        $casos = is_file($origen) ? file_get_contents($origen) : false;
        if ($casos === false) {
            $this->error('No se encuentran los casos de paridad: '.$origen);

            return self::FAILURE;
        }

        // Se copian TAL CUAL (no se decodifica y vuelve a codificar): así un
        // `{}` sigue siendo un objeto vacío y no una lista, y nada cambia de orden.
        try {
            $fixture = json_decode($casos, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('Los casos de paridad no son JSON válido: '.$origen);

            return self::FAILURE;
        }
        if (! is_array($fixture) || ($fixture['version'] ?? null) !== 1 || ! is_array($fixture['cases'] ?? null)) {
            $this->error('Los casos de paridad no tienen la forma esperada (version 1 con cases).');

            return self::FAILURE;
        }

        // Los de coherencia también se copian tal cual. Sin ellos no se exporta
        // nada: el CRM se quedaría con un juego de casos a medias.
        $origenCoherencia = base_path('tests/fixtures/ultron-studio/coherence-cases.json');
        $coherencia = is_file($origenCoherencia) ? file_get_contents($origenCoherencia) : false;
        if ($coherencia === false) {
            $this->error('No se encuentran los casos de coherencia: '.$origenCoherencia);

            return self::FAILURE;
        }
        try {
            $fixtureCoherencia = json_decode($coherencia, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('Los casos de coherencia no son JSON válido: '.$origenCoherencia);

            return self::FAILURE;
        }
        $secciones = is_array($fixtureCoherencia) ? array_filter(self::COHERENCE_SECTIONS, static fn (string $s): bool => is_array($fixtureCoherencia[$s] ?? null)) : [];
        if (! is_array($fixtureCoherencia) || ($fixtureCoherencia['version'] ?? null) !== 1 || count($secciones) !== count(self::COHERENCE_SECTIONS)) {
            $this->error('Los casos de coherencia no tienen la forma esperada (version 1 con '.implode(', ', self::COHERENCE_SECTIONS).').');

            return self::FAILURE;
        }

        $catalogo = StudioCatalog::toArray();
        $version = StudioCatalog::VERSION;
        $huella = StudioCatalog::hash();
        $json = self::twoSpaces(json_encode($catalogo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $contenidoCatalogo = <<<TS
        // Catálogo de ULTRON STUDIO tal y como lo sirve el backend (`GET catalog`).
        //
        // GENERADO — no editar a mano. Lo produce el comando de artisan
        // `ultron-studio:export-fixtures` desde StudioCatalog::toArray(), que es
        // el catálogo con el que el servidor valida de verdad.
        //
        // Solo lo importan las pruebas del CRM: comprueban que el cliente pinta y
        // valida con el mismo catálogo que el servidor.
        // Versión {$version} · huella {$huella}.
        export const BACKEND_STUDIO_CATALOG = {$json} as const;

        TS;

        $total = count($fixture['cases']);
        $cuerpo = rtrim($casos);

        $contenidoCasos = <<<TS
        // Casos de paridad de la validación de ULTRON STUDIO.
        //
        // GENERADO — no editar a mano. Lo produce el comando de artisan
        // `ultron-studio:export-fixtures` copiando tal cual
        // tests/fixtures/ultron-studio/validation-cases.json del backend.
        //
        // Cada caso lleva la salida esperada de la validación de referencia
        // (validateDraft de studio-graph.ts en el commit 4f7d774), sin escribir a
        // mano. El backend la reproduce exactamente; el CRM tiene que hacer lo
        // mismo. Si alguno discrepa, se corrige el lado que se aparta, nunca
        // `expected`. Los casos con catalog "real" usan BACKEND_STUDIO_CATALOG;
        // los demás, el catálogo de `catalogs` con ese nombre.
        export const BACKEND_VALIDATION_CASES = {$cuerpo} as const;

        TS;

        $totalCoherencia = array_sum(array_map(static fn (string $s): int => count($fixtureCoherencia[$s]), self::COHERENCE_SECTIONS));
        $cuerpoCoherencia = rtrim($coherencia);

        $contenidoCoherencia = <<<TS
        // Casos de paridad de la coherencia de las reglas de ULTRON STUDIO (Hito G).
        //
        // GENERADO — no editar a mano. Lo produce el comando de artisan
        // `ultron-studio:export-fixtures` copiando tal cual
        // tests/fixtures/ultron-studio/coherence-cases.json del backend.
        //
        // Los casos los escribió el tester desde el contrato (§A3 y §A9), no los
        // generó ninguna de las dos implementaciones. El backend los cumple
        // exactamente (StudioCoherenceParityTest) y el CRM tiene que hacer lo
        // mismo (studio-rules-coherence.parity.spec.ts). Si alguno discrepa, se
        // corrige la implementación; el fixture solo cambia con una enmienda del
        // contrato. Catálogo: siempre BACKEND_STUDIO_CATALOG.
        export const BACKEND_COHERENCE_CASES = {$cuerpoCoherencia} as const;

        TS;

        file_put_contents($destino.'/'.self::CATALOG_FILE, $contenidoCatalogo);
        file_put_contents($destino.'/'.self::CASES_FILE, $contenidoCasos);
        file_put_contents($destino.'/'.self::COHERENCE_FILE, $contenidoCoherencia);

        $this->info("Catálogo {$version}, {$total} casos de paridad y {$totalCoherencia} de coherencia exportados a {$destino}");

        return self::SUCCESS;
    }

    /** JSON_PRETTY_PRINT sangra con cuatro espacios; el CRM usa dos. */
    private static function twoSpaces(string $json): string
    {
        return (string) preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json);
    }
}
