<?php

namespace App\Services\UltronStudio\Stream;

/**
 * Un TEMA del canal SSE único del Studio (contrato H9, «Paquete SSE9»).
 *
 * Un tema es una cola de solo lectura sobre una tabla (o una vista de ella)
 * recorrida por un CURSOR MONÓTONO y OPACO: el servidor lo guarda por
 * conexión, lo escribe en el `id` de cada evento (`<tema>:<cursor>`) y el
 * cliente lo devuelve al reconectar (`?after=` o `Last-Event-ID`).
 *
 * Reglas para quien implemente uno (los `outreach.*` los implementa B9-run):
 *
 *  · `cursor()` es la cabeza actual (lo último que existe). Una conexión que
 *    llega sin cursor para el tema arranca ahí: solo recibe lo nuevo.
 *  · `since($cursor, $limit)` devuelve, en orden de cursor creciente y como
 *    mucho `$limit`, los {@see StreamEvent} POSTERIORES al cursor (nunca el
 *    propio cursor: así reanudar no repite). Debe tolerar cualquier cursor
 *    sintácticamente válido ({@see StreamCursors::CURSOR}), también uno que
 *    no corresponda a ninguna fila.
 *  · El cursor es texto de `[A-Za-z0-9._-]{1,64}`: un id, un `seq` o una
 *    composición `<unix>-<id>`; sin `:` ni `,` (son los separadores del
 *    vector de cursores).
 *  · SOLO LECTURAS, por cursor, con `$limit` acotado y sobre un índice. Ni
 *    escrituras, ni red, ni bloqueos.
 *  · `permission()` es el permiso del GET de LISTA del módulo según
 *    AuthorizationMap (p. ej. `telephony.view` para `GET api/admin/voice/calls`):
 *    sin él, el tema se omite en silencio. Una prueba de guarda compara cada
 *    permiso declarado con lo que el mapa resuelve para esa ruta.
 *  · `data` nunca lleva teléfono, nombre, documento, cuerpo de mensaje,
 *    importe ni secreto: {@see StreamEvent} solo admite las claves de su
 *    lista blanca y valores escalares cortos.
 */
interface StreamTopic
{
    /** El nombre del tema, el del campo `event` del SSE: `ledger`, `voice.calls`, `outreach.runs`… */
    public function name(): string;

    /** El permiso del GET de lista del módulo (AuthorizationMap), que el tema exige además de `ultron.view`. */
    public function permission(): string;

    /**
     * La clave de AuthorizationMap del GET de lista cuyo permiso exige el tema
     * (`GET api/admin/voice/calls`); null solo en un tema pendiente. Una
     * prueba de guarda comprueba que `permission()` es lo que el mapa resuelve
     * para esa ruta, para que las dos no se separen.
     */
    public function listRoute(): ?string;

    /** La cabeza actual del tema (opaca, monótona). */
    public function cursor(): string;

    /**
     * Los eventos posteriores a `$cursor`, en orden creciente, como mucho `$limit`.
     *
     * @return list<StreamEvent>
     */
    public function since(string $cursor, int $limit): array;
}
