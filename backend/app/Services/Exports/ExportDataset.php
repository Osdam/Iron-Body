<?php

namespace App\Services\Exports;

use Illuminate\Database\Eloquent\Builder;

/**
 * Un conjunto de datos que el módulo de exportación sabe sacar.
 *
 * Cada implementación declara TODO lo que el cliente puede pedir: qué columnas
 * existen, qué filtros acepta y con qué reglas. El controlador no confía en
 * nada que no esté aquí: una columna que el dataset no declare se rechaza, en
 * vez de convertirse en un `select` arbitrario sobre la tabla.
 */
abstract class ExportDataset
{
    /** Clave estable; forma parte de la URL (`/admin/exports/{key}`). */
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function description(): string;

    /** Icono de Material Symbols para la tarjeta del CRM. */
    abstract public function icon(): string;

    /** Permiso que exige descargarlo. Debe coincidir con AuthorizationMap. */
    abstract public function permission(): string;

    /** @return list<ExportColumn> */
    abstract public function columns(): array;

    /**
     * Filtros que el CRM pinta. Cada uno: clave, etiqueta, tipo y, si es una
     * lista, sus opciones.
     *
     * @return list<array{key: string, label: string, type: string, options?: list<array{value: string, label: string}>}>
     */
    abstract public function filters(): array;

    /** @return array<string, mixed> reglas de validación de los filtros */
    abstract public function filterRules(): array;

    /**
     * Consulta con los filtros aplicados y todo lo que las columnas necesitan
     * precargado. Debe ordenar por clave primaria: se recorre con lazyById.
     *
     * @param  array<string, mixed>  $filters
     */
    abstract public function query(array $filters): Builder;

    /** Nombre del fichero sin extensión, p. ej. «miembros». */
    abstract public function fileSlug(): string;

    /**
     * Columnas pedidas, en el orden del dataset y no en el del cliente: así dos
     * exportaciones con la misma selección producen siempre el mismo fichero.
     *
     * @param  list<string>  $keys
     * @return list<ExportColumn>
     */
    public function select(array $keys): array
    {
        $pedidas = array_flip($keys);

        return array_values(array_filter(
            $this->columns(),
            fn (ExportColumn $c) => isset($pedidas[$c->key]),
        ));
    }

    /** @return list<string> */
    public function columnKeys(): array
    {
        return array_map(fn (ExportColumn $c) => $c->key, $this->columns());
    }

    /** Lo que necesita el CRM para pintar el formulario. @return array<string, mixed> */
    public function describe(): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'description' => $this->description(),
            'icon' => $this->icon(),
            'columns' => array_map(fn (ExportColumn $c) => $c->describe(), $this->columns()),
            'filters' => $this->filters(),
        ];
    }
}
