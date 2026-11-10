<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Outreach\Core\CanonicalJson;
use App\Services\UltronStudio\StudioContentHasher;
use LogicException;

/**
 * Lectura de los fixtures del H9 (`tests/fixtures/outreach/*.json`, de
 * TEST9-a, escritos desde el contrato y NO generados por el núcleo). Si un
 * caso discrepa se corrige el PHP; el fixture solo cambia con una enmienda.
 */
trait LoadsOutreachFixtures
{
    /**
     * L9-29 (la clave de ciclo de las clases es `cls:{class_id}:{session_date}`:
     * la fila de la sesión solo nace al iniciar la clase) llevó el catálogo a
     * `outreach-catalog/2026-11-10.2`, con `keyVersion` 2 en
     * `class.upcoming_with_capacity`, DESPUÉS de que TEST9-a generara sus
     * fixtures con la `.1`. Mientras no los regenere, la paridad compara con el
     * fixture más EXACTAMENTE este cambio ratificado; con los fixtures ya
     * regenerados, no hace nada. Cualquier otra diferencia sigue fallando.
     */
    protected const L9_29 = [
        'fromVersion' => 'outreach-catalog/2026-11-10.1',
        'fromHash' => '4580c4477ddc86ef14f0c5c7ce494d767caff102e5377d7f10a58e406295fe47',
        'toVersion' => 'outreach-catalog/2026-11-10.2',
        'event' => 'class.upcoming_with_capacity',
        'fromCycleKey' => 'cls:{class_session_id}',
        'toCycleKey' => 'cls:{class_id}:{session_date}',
        'fromKeyVersion' => 1,
        'toKeyVersion' => 2,
    ];

    /** @var array<string, array<string,mixed>> */
    private static array $fixtures = [];

    /** @return array<string,mixed> */
    protected static function fixture(string $name): array
    {
        return self::$fixtures[$name] ??= json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/fixtures/outreach/'.$name.'.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Los casos de unas secciones, por nombre (para un DataProvider).
     *
     * @param  list<string>  $sections
     * @return iterable<string, array{array<string,mixed>}>
     */
    protected static function cases(string $name, array $sections): iterable
    {
        foreach ($sections as $seccion) {
            foreach (self::fixture($name)[$seccion] as $caso) {
                yield $caso['name'] => [$caso + ['_section' => $seccion]];
            }
        }
    }

    /** El árbol de entrada de un caso: `input` o, si fija números que JSON no conserva, `inputText` decodificado. */
    protected static function input(array $caso, string $key = 'input', string $textKey = 'inputText'): mixed
    {
        return array_key_exists($key, $caso) ? $caso[$key] : json_decode($caso[$textKey], true, 512, JSON_THROW_ON_ERROR);
    }

    /** ¿Sigue `catalog-snapshot.json` en el catálogo de antes de L9-29? */
    protected static function fixturesBeforeL929(): bool
    {
        return self::fixture('catalog-snapshot')['catalogVersion'] === self::L9_29['fromVersion'];
    }

    /**
     * El `catalog` de un fixture con L9-29 aplicado si aún es el de antes,
     * exigiendo que el evento de clases tenga exactamente la forma antigua.
     *
     * @param  array<string,mixed>  $catalog
     * @return array<string,mixed>
     */
    protected static function ratifiedCatalog(array $catalog): array
    {
        if (($catalog['version'] ?? null) !== self::L9_29['fromVersion']) {
            return $catalog;
        }
        $catalog['version'] = self::L9_29['toVersion'];
        $cambiados = 0;
        foreach ($catalog['events'] as $i => $e) {
            if ($e['key'] !== self::L9_29['event']) {
                continue;
            }
            if ($e['cycleKey'] !== self::L9_29['fromCycleKey'] || $e['keyVersion'] !== self::L9_29['fromKeyVersion']) {
                throw new LogicException('El evento de clases del fixture no tiene la forma de antes de L9-29.');
            }
            $catalog['events'][$i]['cycleKey'] = self::L9_29['toCycleKey'];
            $catalog['events'][$i]['keyVersion'] = self::L9_29['toKeyVersion'];
            $cambiados++;
        }
        if ($cambiados !== 1) {
            throw new LogicException('El fixture no trae el evento de clases exactamente una vez.');
        }

        return $catalog;
    }

    /**
     * La versión y la huella que debe declarar un fixture: las suyas o, si aún
     * son las de antes de L9-29, las del catálogo del fixture con L9-29 (la
     * huella la calcula `StudioContentHasher`, no el núcleo).
     *
     * @return array{0: string, 1: string}
     */
    protected static function ratifiedPin(string $version, string $hash): array
    {
        if ($version !== self::L9_29['fromVersion'] || $hash !== self::L9_29['fromHash']) {
            return [$version, $hash];
        }

        return [self::L9_29['toVersion'], StudioContentHasher::hash(self::ratifiedCatalog(self::fixture('catalog-snapshot')['catalog']))];
    }

    /** Igualdad por JSON canónico (el orden de las claves no cuenta; el de las listas sí). */
    protected function assertCanonicalSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertSame(CanonicalJson::encode($expected), CanonicalJson::encode($actual), $message);
    }
}
