<?php

namespace App\Services\Meta\Templates;

/**
 * Cliente NEUTRO de las plantillas de mensaje de WhatsApp en Meta (WhatsApp
 * Business Management API): crear una plantilla, leerla por id o por nombre,
 * listar las de una cuenta (WABA) página a página y ver los permisos del token.
 * Nada más: ni enviar mensajes, ni editar, ni borrar, ni reanudar plantillas.
 *
 * Dos implementaciones:
 *  - {@see GraphWhatsappTemplateClient}: la de verdad, contra Graph.
 *  - {@see FakeWhatsappTemplateClient}: el doble sin red, solo en local y en
 *    pruebas (en producción no se puede construir).
 * Quien no quiera decidir entre ellas usa {@see WhatsappTemplateClientFactory}.
 *
 * EL CONTRATO, igual para las dos:
 *
 *  1. Nunca lanzan por Meta ni por la red: todo lo que pase fuera de este
 *     proceso vuelve como {@see TemplateApiResult}, con un código de una lista
 *     cerrada ({@see TemplateApiErrors}). Lo que sí lanza
 *     (`InvalidArgumentException`, sin red) son los errores de quien llama: un
 *     id que no son dígitos, un campo que no está en {@see FIELDS}, un tamaño
 *     de página fuera de 1..{@see MAX_PAGE_SIZE}, un cursor con otra forma o
 *     una plantilla que no se puede mandar ({@see TemplateRequestGuard}).
 *  2. `create()` hace UNA petición y nunca la repite. Si no se sabe si Meta la
 *     recibió (tiempo agotado, conexión cortada después de salir, un 5xx, una
 *     respuesta 2xx ilegible), el resultado es `unknown`: hay que buscarla por
 *     nombre e idioma antes de volver a enviarla, nunca reenviarla a ciegas.
 *     Las lecturas tampoco se repiten solas: un fallo es `failed` y quien llama
 *     decide cuándo vuelve a mirar ({@see TemplateApiResult::retryable()}).
 *  3. Ni el token ni el cuerpo de una respuesta de error salen de aquí: de un
 *     error de Meta solo quedan el código, el subcódigo, el título y el mensaje
 *     para el usuario (saneados y acotados) y el `fbtrace_id`. Las plantillas
 *     leídas vuelven normalizadas ({@see MetaTemplate}), no como el JSON de Meta.
 */
interface WhatsappTemplateClient
{
    /**
     * Los campos de una plantilla que se pueden pedir: una lista cerrada, la
     * que {@see MetaTemplate} sabe leer. Todos existen en el nodo
     * WhatsAppMessageTemplate de la referencia de Graph.
     */
    public const FIELDS = [
        'id', 'name', 'language', 'status', 'category', 'previous_category', 'correct_category',
        'components', 'quality_score', 'rejected_reason', 'parameter_format',
    ];

    /** Lo que necesita una sincronización completa (todo lo que el canal compara, más la calidad y el motivo). */
    public const SYNC_FIELDS = [
        'id', 'name', 'language', 'status', 'category', 'correct_category', 'components', 'quality_score', 'rejected_reason',
    ];

    /** Plantillas por página como mucho (`limit`). */
    public const MAX_PAGE_SIZE = 100;

    /**
     * Lo que impediría llamar AHORA, sin red: `missing_token`,
     * `malformed_token` o `no_simulator` (fuera de producción, sin base del
     * simulador); null si se puede llamar. Es el mismo motivo que tendría el
     * `not_configured` de una llamada ({@see TemplateApiResult::$detail}). No
     * dice nada de los permisos del token: eso lo dice {@see WhatsappTemplateProbe}.
     */
    public function configurationProblem(): ?string;

    /**
     * Crea una plantilla: `POST /{wabaId}/message_templates` con `$payload`
     * como cuerpo JSON, EXACTAMENTE como llega (mismo orden de claves, UTF-8
     * sin escapar). Una sola petición, sin reintentos.
     *
     * Bien: `ok`, con {@see TemplateApiResult::$createdId} (dígitos) y, si Meta
     * los da, `createdStatus` y `createdCategory`.
     * Meta la rechazó o la petición no llegó a salir: `failed`.
     * No se sabe si Meta la creó: `unknown` (ver la regla 2 de la interfaz).
     *
     * @param  array<string,mixed>  $payload  name, language, category, components y, opcionales,
     *                                        parameter_format, allow_category_change, message_send_ttl_seconds y sub_category
     */
    public function create(string $wabaId, array $payload): TemplateApiResult;

    /**
     * Una plantilla por su id: `GET /{templateId}?fields=…` (`id` va siempre).
     * Bien: `ok` con una plantilla en {@see TemplateApiResult::template()}. Si
     * Meta no la tiene (o el token no la ve): `failed` con `not_found`.
     *
     * @param  list<string>  $fields  de {@see FIELDS}
     */
    public function find(string $templateId, array $fields): TemplateApiResult;

    /**
     * Las plantillas de la cuenta con ese nombre EXACTO (en todos los idiomas,
     * o solo en `$language`): `GET /{wabaId}/message_templates?name=…`. Meta no
     * documenta si su filtro es exacto o «contiene», así que la coincidencia se
     * vuelve a comprobar aquí y se recorren las páginas que haga falta (como
     * mucho {@see GraphWhatsappTemplateClient::FIND_BY_NAME_MAX_PAGES}).
     * Bien: `ok` con 0..n plantillas (0 = Meta no la tiene). `id`, `name` y
     * `language` van siempre en los campos.
     *
     * @param  list<string>  $fields  de {@see FIELDS}
     */
    public function findByName(string $wabaId, string $name, ?string $language, array $fields): TemplateApiResult;

    /**
     * UNA página de las plantillas de la cuenta: `GET
     * /{wabaId}/message_templates?fields=…&limit=…[&after=…]`. Para la página
     * siguiente se vuelve a llamar con {@see TemplateApiResult::$nextCursor}
     * (null = no hay más). Nunca se sigue la URL `paging.next` que trae la
     * respuesta: la escribe un tercero.
     *
     * @param  list<string>  $fields  de {@see FIELDS}
     */
    public function list(string $wabaId, array $fields, ?string $after = null, int $limit = self::MAX_PAGE_SIZE): TemplateApiResult;

    /**
     * Los permisos del token: `GET /me/permissions`, con el token en la
     * cabecera (nunca `debug_token`, que lo lleva en la URL). Bien: `ok` con
     * {@see TemplateApiResult::$permissions} (`permiso => granted|declined|expired`).
     * La sonda completa, con su veredicto, es {@see WhatsappTemplateProbe}.
     */
    public function permissions(): TemplateApiResult;
}
