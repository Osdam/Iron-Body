<?php

namespace App\Services\Exports;

use Closure;

/**
 * Una columna exportable: su clave estable, cómo se llama en el fichero y de
 * dónde sale el valor.
 *
 * La clave es lo que viaja desde el CRM; la etiqueta es lo que ve quien abre el
 * Excel. Separarlas permite renombrar un encabezado sin romper las selecciones
 * que el cliente ya envía.
 */
final class ExportColumn
{
    public const TYPE_TEXT = 'text';

    public const TYPE_NUMBER = 'number';

    public const TYPE_DATE = 'date';

    /**
     * @param  Closure(mixed): (string|int|float|bool|null)  $value
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $group,
        public readonly Closure $value,
        public readonly string $type = self::TYPE_TEXT,
        // Marcada por defecto al abrir el módulo. Lo que casi todo el mundo
        // necesita; el resto se añade a mano.
        public readonly bool $default = true,
        // Dato personal (documento, teléfono, correo…). No se oculta ni se
        // bloquea —exportarlo es a veces justo lo que se quiere—, pero el CRM
        // lo señala para que se decida a sabiendas.
        public readonly bool $personal = false,
    ) {}

    /**
     * El valor de esta columna para una fila.
     *
     * La fila es `mixed` y no `Model` porque los informes exportan agregados
     * —un array por línea— y no filas de una tabla. El cierre de cada columna
     * declara qué espera recibir.
     */
    public function resolve(mixed $row): string|int|float|bool|null
    {
        return ($this->value)($row);
    }

    /** @return array{key: string, label: string, group: string, type: string, default: bool, personal: bool} */
    public function describe(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'group' => $this->group,
            'type' => $this->type,
            'default' => $this->default,
            'personal' => $this->personal,
        ];
    }
}
