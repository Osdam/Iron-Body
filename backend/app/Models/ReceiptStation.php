<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un equipo de caja con el servicio local de impresión instalado.
 *
 * `station_key` lo genera el servicio en su primer arranque y lo enseña en
 * /v1/status. NO es un secreto: es el nombre del equipo. Lo que autoriza un
 * trabajo es un ticket de un solo uso emitido para esta estación, y solo se
 * emiten para estaciones que registró un administrador con `cash.*.manage` y
 * que siguen activas.
 *
 * Dos grupos de columnas que no se mezclan:
 *  · lo que CONFIGURA el administrador (nombre, impresora, columnas, página de
 *    códigos, avance), que es lo que se le entrega al servicio al configurar;
 *  · lo que CONTÓ el servicio la última vez (`agent_*`, `last_*`), que solo se
 *    enseña y nunca decide nada.
 */
class ReceiptStation extends Model
{
    public const COLUMNS = [32, 42];

    public const CODEPAGES = ['cp850', 'cp437', 'ascii'];

    public const MAX_FEED_LINES = 8;

    protected $fillable = [
        'station_key', 'name', 'active', 'printer_name', 'columns', 'codepage',
        'feed_lines', 'agent_version', 'agent_protocol', 'last_printer_name',
        'last_seen_at', 'last_ip', 'created_by_admin_id', 'created_by_name',
    ];

    protected $casts = [
        'active' => 'boolean',
        'columns' => 'integer',
        'feed_lines' => 'integer',
        'agent_protocol' => 'integer',
        'last_seen_at' => 'datetime',
    ];

    protected $attributes = [
        'active' => true,
        'columns' => 32,
        'codepage' => 'cp850',
        'feed_lines' => 4,
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(ReceiptAgentTicket::class);
    }
}
