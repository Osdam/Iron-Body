<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\Admin;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Run\OutreachPublishedStrategies;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LO QUE APROBÓ META, registrado en el Studio (contrato H9, D9-6, D9-9.3, §3
 * protección 12: el cuarto cerrojo de un canal que gasta es la aprobación
 * EXTERNA; W9 gancho 4). Escribe `ultron_studio_template_meta_checks`
 * (migración `2026_11_13_200001`, SOLO INSERCIÓN: un cambio de estado en Meta
 * es una fila nueva y manda la última) y nada más; lo usa la orden
 * `ultron-studio:template-meta-check` (en seco por defecto).
 *
 * QUIÉN: la persona que lo comprobó en el administrador de WhatsApp de Meta,
 * por su cuenta del CRM: ACTIVA, con `ultron.approve` (la llave que aprueba
 * las plantillas del Studio, `AuthorizationMap`: `strategies/templates/{uuid}/approve`;
 * como toda llave del Studio, con `ultron.view`) y con su vínculo VERIFICADO a
 * una persona (D9-11.2: lo mismo que pide aprobar una plantilla). Su id y su
 * nombre quedan en la fila (la instantánea de F).
 *
 * QUÉ: los hechos de Meta TAL CUAL (nombre, idioma, categoría, estado, id) y,
 * del texto que aprobó Meta, SOLO su huella (sha256 de los bytes UTF-8, tras
 * quitar el BOM, pasar CRLF a LF y quitar UN salto final que deja el editor):
 * el texto nunca se guarda, se devuelve ni se registra (Ley 1581: tampoco
 * lleva datos de nadie, pero no tiene por qué salir de aquí).
 *
 * LA COMPARACIÓN ({@see compare()}) dice si el canal de WhatsApp aceptará la
 * plantilla con esta comprobación como la última: las mismas condiciones que
 * `OutreachPublishedStrategies::approvedWhatsappTemplate()` y `metaApproved()`
 * (las del canal). Tras registrar, el veredicto lo da el propio puente
 * ({@see channelAccepts()}); una prueba fija que los dos dicen lo mismo.
 *
 * IDEMPOTENTE por `client_request_id`: repetir con el mismo y los mismos
 * hechos devuelve la fila de antes sin escribir; con otros hechos, 409.
 */
final class StudioTemplateMetaChecks
{
    public const TABLE = 'ultron_studio_template_meta_checks';

    public const TEMPLATES = 'ultron_studio_message_templates';

    /** Los estados de una plantilla en Meta (el CHECK `ustmc_status_chk` de la migración). */
    public const STATUSES = ['APPROVED', 'REJECTED', 'PAUSED', 'DISABLED'];

    public const STATUS_APPROVED = 'APPROVED';

    /** El id que Meta da a la plantilla (`ustmc_shape_chk`): solo dígitos. */
    public const META_TEMPLATE_ID_PATTERN = '/^[0-9]{1,32}$/D';

    /** La llave de quien lo comprobó: la que aprueba las plantillas del Studio. */
    public const PERMISSION = StudioAccess::APPROVE;

    /** El texto de Meta: como el `bodyPreview` del Studio (≤ 1 024 caracteres). */
    public const BODY_MAX = StudioMessageTemplates::BODY_MAX;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /** Por qué el canal no la aceptaría, campo a campo (códigos, nunca el texto). */
    public const FIELD_REASONS = [
        'metaName' => 'meta_name_mismatch',
        'language' => 'language_mismatch',
        'category' => 'category_mismatch',
        'body' => 'body_mismatch',
        'status' => 'meta_status_not_approved',
    ];

    /** Las columnas que dicen lo mismo en una repetición idempotente. */
    private const FACT_COLUMNS = ['template_id', 'template_content_hash', 'meta_name', 'language', 'category', 'meta_body_hash', 'meta_template_id', 'status', 'recorded_by_admin_id'];

    public function __construct(private readonly OutreachPublishedStrategies $bridge = new OutreachPublishedStrategies) {}

    /**
     * Valida y resuelve lo pedido SIN ESCRIBIR NADA: la plantilla, la persona,
     * los hechos de Meta, la huella del texto y la comparación con el Studio.
     *
     * @param  array{template: mixed, metaName: mixed, language: mixed, category: mixed, status: mixed, metaTemplateId: mixed, body: mixed, by: mixed, checkedAt: mixed, clientRequestId: mixed}  $in
     * @return array{row: array<string, mixed>, template: array<string, mixed>, comparison: array<string, mixed>, latest: array<string, mixed>|null}
     *
     * @throws UltronStudioException 422 con los campos que no valen, 404 sin plantilla o sin cuenta, 403 sin la llave o sin persona
     */
    public function plan(array $in, DateTimeInterface $now): array
    {
        $ahora = RelativeTime::utc($now);
        $errores = [];
        $uuid = is_string($in['template']) ? strtolower(trim($in['template'])) : '';
        if (preg_match(self::UUID, $uuid) !== 1) {
            $errores['template'] = 'invalid_format';
        }
        $nombre = is_string($in['metaName']) ? trim($in['metaName']) : '';
        if (preg_match(StudioMessageTemplates::META_NAME_PATTERN, $nombre) !== 1) {
            $errores['metaName'] = 'invalid_format';
        }
        $idioma = is_string($in['language']) ? trim($in['language']) : '';
        if (! in_array($idioma, StudioMessageTemplates::LANGUAGES, true)) {
            $errores['language'] = 'value_not_in_domain';
        }
        $categoria = is_string($in['category']) ? trim($in['category']) : '';
        if (! in_array($categoria, StudioMessageTemplates::CATEGORIES, true)) {
            $errores['category'] = 'value_not_in_domain';
        }
        $estado = is_string($in['status']) ? strtoupper(trim($in['status'])) : '';
        if (! in_array($estado, self::STATUSES, true)) {
            $errores['status'] = 'value_not_in_domain';
        }
        $idMeta = is_string($in['metaTemplateId']) && trim($in['metaTemplateId']) !== '' ? trim($in['metaTemplateId']) : null;
        if ($idMeta !== null && preg_match(self::META_TEMPLATE_ID_PATTERN, $idMeta) !== 1) {
            $errores['metaTemplateId'] = 'invalid_format';
        }
        $huella = is_string($in['body']) ? self::bodyHash($in['body']) : null;
        if ($huella === null) {
            $errores['body'] = 'invalid_body';
        }
        $comprobado = $ahora;
        if (is_string($in['checkedAt']) && trim($in['checkedAt']) !== '') {
            if (! RelativeTime::isInstant(trim($in['checkedAt']))) {
                $errores['checkedAt'] = 'invalid_format';
            } else {
                $comprobado = RelativeTime::parseInstant(trim($in['checkedAt']));
                if ($comprobado > $ahora) {
                    $errores['checkedAt'] = 'in_the_future';
                }
            }
        }
        $peticion = is_string($in['clientRequestId']) && trim($in['clientRequestId']) !== '' ? strtolower(trim($in['clientRequestId'])) : null;
        if ($peticion !== null && preg_match(self::UUID, $peticion) !== 1) {
            $errores['clientRequestId'] = 'invalid_format';
        }
        if ($errores !== []) {
            throw new UltronStudioException('La comprobación de Meta no tiene la forma del contrato.', 'validation_failed', 422, ['errors' => $errores]);
        }

        $plantilla = DB::table(self::TEMPLATES)->where('uuid', $uuid)->first(['id', 'uuid', 'key', 'number', 'channel', 'purpose', 'status', 'content_json', 'content_hash']);
        if ($plantilla === null) {
            throw new UltronStudioException('No existe esa plantilla del Studio.', 'template_not_found', 404);
        }
        if ((string) $plantilla->channel !== StudioMessageTemplates::CHANNEL_WHATSAPP) {
            throw new UltronStudioException('Solo las plantillas de WhatsApp tienen correspondencia en Meta.', 'template_not_whatsapp', 422);
        }
        $persona = $this->checker($in['by']);

        $fila = [
            'client_request_id' => $peticion ?? strtolower((string) Str::uuid()),
            'template_id' => (int) $plantilla->id,
            'template_content_hash' => (string) $plantilla->content_hash,
            'meta_name' => $nombre,
            'language' => $idioma,
            'category' => $categoria,
            'meta_body_hash' => (string) $huella,
            'meta_template_id' => $idMeta,
            'status' => $estado,
            'checked_at' => $comprobado->format('Y-m-d H:i:s'),
            'recorded_by_admin_id' => (int) $persona->id,
            'recorded_by_name' => self::staffName($persona),
        ];
        $contenido = json_decode((string) $plantilla->content_json, true);

        return [
            'row' => $fila,
            'template' => [
                'id' => (int) $plantilla->id, 'uuid' => (string) $plantilla->uuid, 'key' => (string) $plantilla->key, 'number' => (int) $plantilla->number,
                'purpose' => (string) $plantilla->purpose, 'status' => (string) $plantilla->status, 'contentHash' => (string) $plantilla->content_hash,
            ],
            'comparison' => self::compare($fila, $plantilla, is_array($contenido) ? $contenido : []),
            'latest' => $this->latest((int) $plantilla->id),
        ];
    }

    /**
     * Registra lo resuelto por {@see plan()}: UNA fila, en una transacción.
     * Con un `client_request_id` ya usado, la misma fila si los hechos son los
     * mismos (`replayed`) y 409 `client_request_reused` si no.
     *
     * @param  array{row: array<string, mixed>}  $plan
     * @return array{uuid: string, replayed: bool, channelAccepts: bool}
     *
     * @throws UltronStudioException 409 `client_request_reused`
     */
    public function record(array $plan, DateTimeInterface $now): array
    {
        $fila = $plan['row'];
        $previa = $this->byRequest((string) $fila['client_request_id']);
        if ($previa !== null) {
            return $this->replay($previa, $fila);
        }
        $uuid = strtolower((string) Str::uuid());
        try {
            DB::transaction(static function () use ($fila, $uuid, $now): void {
                DB::table(self::TABLE)->insert($fila + ['uuid' => $uuid, 'created_at' => RelativeTime::utc($now)->format('Y-m-d H:i:s')]);
            });
        } catch (UniqueConstraintViolationException) {
            // La carrera: otra ejecución con el mismo `client_request_id` entró antes.
            $previa = $this->byRequest((string) $fila['client_request_id']);
            if ($previa === null) {
                throw new UltronStudioException('La comprobación chocó con otra y no se pudo releer.', 'client_request_conflict', 409);
            }

            return $this->replay($previa, $fila);
        }

        return ['uuid' => $uuid, 'replayed' => false, 'channelAccepts' => $this->channelAccepts((int) $fila['template_id'])];
    }

    /**
     * ¿Aceptará el canal de WhatsApp esta plantilla AHORA? Lo dice el puente
     * de la ejecución, con la última comprobación registrada (lo mismo que el
     * canal mira antes de cada envío). Nunca lanza.
     */
    public function channelAccepts(int $templateId): bool
    {
        $t = DB::table(self::TEMPLATES)->where('id', $templateId)->first(['content_hash', 'purpose']);
        if ($t === null) {
            return false;
        }
        $aprobada = $this->bridge->approvedWhatsappTemplate($templateId, (string) $t->content_hash, (string) $t->purpose);

        return $aprobada !== null && $this->bridge->metaApproved($aprobada);
    }

    /**
     * La comparación con la plantilla ACTUAL del Studio (PURA): campo a campo
     * y el veredicto del canal si esta comprobación fuera la última. Las
     * mismas condiciones que el puente: la plantilla APROBADA en el Studio,
     * de bytes que casan con su huella y con la categoría de su finalidad; la
     * comprobación `APPROVED`, de esa huella y con el mismo nombre, idioma,
     * categoría y texto (`bodyPreview`).
     *
     * @param  array<string, mixed>  $row  la fila de {@see plan()}
     * @param  array<string, mixed>  $content
     * @return array{fields: array<string, bool>, studioApproved: bool, channelAccepts: bool, reasons: list<string>}
     */
    public static function compare(array $row, object $template, array $content): array
    {
        $campos = [
            'metaName' => ($content['metaName'] ?? null) === $row['meta_name'],
            'language' => ($content['language'] ?? null) === $row['language'],
            'category' => ($content['category'] ?? null) === $row['category'],
            'body' => is_string($content['bodyPreview'] ?? null) && hash_equals(hash('sha256', $content['bodyPreview']), (string) $row['meta_body_hash']),
            'status' => $row['status'] === self::STATUS_APPROVED,
        ];
        $motivos = [];
        if ((string) $template->status !== 'APPROVED') {
            $motivos[] = 'studio_template_not_approved';
        }
        if (! hash_equals((string) $template->content_hash, hash('sha256', (string) $template->content_json))) {
            $motivos[] = 'studio_template_hash_mismatch';
        }
        if (($content['category'] ?? null) !== (StudioMessageTemplates::CATEGORY_BY_PURPOSE[(string) $template->purpose] ?? null)) {
            $motivos[] = 'studio_category_purpose_mismatch';
        }
        foreach ($campos as $campo => $casa) {
            if (! $casa) {
                $motivos[] = self::FIELD_REASONS[$campo];
            }
        }

        return ['fields' => $campos, 'studioApproved' => (string) $template->status === 'APPROVED', 'channelAccepts' => $motivos === [], 'reasons' => $motivos];
    }

    /**
     * La huella del texto que aprobó Meta: UTF-8 válido, sin el BOM, con los
     * saltos en LF y sin UN salto final (el que añade el editor al fichero);
     * entre 1 y {@see BODY_MAX} caracteres. null si no vale.
     */
    public static function bodyHash(string $raw): ?string
    {
        if (! mb_check_encoding($raw, 'UTF-8')) {
            return null;
        }
        $texto = str_starts_with($raw, "\u{FEFF}") ? substr($raw, 3) : $raw;
        $texto = str_replace("\r\n", "\n", $texto);
        if (str_ends_with($texto, "\n")) {
            $texto = substr($texto, 0, -1);
        }
        $largo = mb_strlen($texto, 'UTF-8');
        if ($largo < 1 || $largo > self::BODY_MAX || trim($texto) === '') {
            return null;
        }

        return hash('sha256', $texto);
    }

    /** La última comprobación registrada de una plantilla (la que manda), sin el texto (no lo hay). @return array<string, mixed>|null */
    public function latest(int $templateId): ?array
    {
        $f = DB::table(self::TABLE)->where('template_id', $templateId)->orderByDesc('id')
            ->first(['uuid', 'status', 'meta_name', 'language', 'category', 'meta_template_id', 'template_content_hash', 'checked_at', 'recorded_by_name']);

        return $f === null ? null : [
            'uuid' => (string) $f->uuid, 'status' => (string) $f->status, 'metaName' => (string) $f->meta_name, 'language' => (string) $f->language,
            'category' => (string) $f->category, 'metaTemplateId' => $f->meta_template_id, 'templateContentHash' => (string) $f->template_content_hash,
            'checkedAt' => RelativeTime::format(new DateTimeImmutable((string) $f->checked_at, new \DateTimeZone('UTC'))), 'recordedBy' => (string) $f->recorded_by_name,
        ];
    }

    /**
     * La persona que lo comprobó: la cuenta (id o correo) ACTIVA, con la llave
     * de aprobar del Studio y su vínculo verificado a una persona.
     *
     * @throws UltronStudioException
     */
    private function checker(mixed $by): Admin
    {
        $ref = is_string($by) ? trim($by) : (is_int($by) ? (string) $by : '');
        if ($ref === '') {
            throw new UltronStudioException('Falta la persona que lo comprobó en Meta (su cuenta del CRM: id o correo).', 'validation_failed', 422, ['errors' => ['by' => 'required']]);
        }
        $cuenta = ctype_digit($ref) ? Admin::query()->find((int) $ref) : Admin::query()->where('email', $ref)->first();
        if (! $cuenta instanceof Admin || ! $cuenta->isActive()) {
            throw new UltronStudioException('La cuenta no existe o no está activa.', 'admin_not_found', 404);
        }
        // Como toda llave del Studio, presupone `ultron.view` (StudioAccess::require).
        (new StudioAccess($cuenta))->require(self::PERMISSION);
        if (StudioPersonBindings::personOf((int) $cuenta->id) === null) {
            throw new UltronStudioException('Esa cuenta no está vinculada y verificada con una persona: no puede dar fe de lo que aprobó Meta.', 'person_binding_required', 403);
        }

        return $cuenta;
    }

    private static function staffName(Admin $cuenta): string
    {
        $nombre = trim((string) $cuenta->name);

        return mb_substr($nombre !== '' ? $nombre : 'Cuenta '.$cuenta->id, 0, 120);
    }

    private function byRequest(string $clientRequestId): ?object
    {
        return DB::table(self::TABLE)->where('client_request_id', $clientRequestId)->first(['uuid', ...self::FACT_COLUMNS]);
    }

    /**
     * @param  array<string, mixed>  $fila
     * @return array{uuid: string, replayed: bool, channelAccepts: bool}
     *
     * @throws UltronStudioException 409 `client_request_reused`
     */
    private function replay(object $previa, array $fila): array
    {
        foreach (self::FACT_COLUMNS as $c) {
            $antes = $previa->{$c};
            if ((is_int($fila[$c]) ? (int) $antes : $antes) !== $fila[$c]) {
                throw new UltronStudioException('Ese client_request_id ya registró OTRA comprobación.', 'client_request_reused', 409);
            }
        }

        return ['uuid' => (string) $previa->uuid, 'replayed' => true, 'channelAccepts' => $this->channelAccepts((int) $fila['template_id'])];
    }
}
