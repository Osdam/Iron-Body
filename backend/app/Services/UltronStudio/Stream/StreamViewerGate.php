<?php

namespace App\Services\UltronStudio\Stream;

use App\Services\UltronStudio\StudioAccess;

/**
 * Una puerta ADICIONAL de un tema, cuando el GET de lista de su módulo exige
 * algo más que el permiso del mapa (IRON GUARD solo deja pasar a ciertos
 * roles, además de `security.view`). Opcional: solo la implementa el tema que
 * la necesita; el controlador la comprueba después del permiso y, si no pasa,
 * omite el tema igual que si faltara el permiso.
 */
interface StreamViewerGate
{
    public function readableBy(StudioAccess $viewer): bool;
}
