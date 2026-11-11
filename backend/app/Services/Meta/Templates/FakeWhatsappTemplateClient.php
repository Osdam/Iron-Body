<?php

namespace App\Services\Meta\Templates;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use InvalidArgumentException;
use LogicException;

/**
 * El DOBLE de {@see WhatsappTemplateClient}: sin red, determinista y con su
 * estado en la caché que se le dé (`array` en las pruebas, la de la app en el
 * banco). Rechaza las mismas entradas que el cliente de verdad
 * ({@see TemplateRequestGuard}) y devuelve las plantillas por la misma
 * normalización ({@see MetaTemplate::fromGraph()}).
 *
 * NO EXISTE FUERA DE LOCAL Y DE PRUEBAS. En producción escribiría aprobaciones
 * que Meta no dio, y quien las lee las daría por buenas. Por eso el
 * constructor y CADA método lanzan `LogicException` salvo con un entorno de
 * {@see ALLOWED_ENVIRONMENTS} (lista cerrada: un entorno mal escrito, como
 * `prod`, tampoco lo admite).
 *
 * ESCENARIOS, por el prefijo del nombre (`aprobar_bienvenida_v1`); sin prefijo
 * conocido, `aprobar`. «Mirar» es leer la plantilla pidiendo su `status`
 * (find, findByName o list): cada vez que se mira, avanza un paso. Crear
 * devuelve PENDING y la categoría pedida.
 *
 *  | prefijo          | al crear                         | 1.ª vez que se mira | 2.ª            | después      |
 *  |------------------|----------------------------------|---------------------|----------------|--------------|
 *  | `aprobar_`       | PENDING                          | APPROVED            | APPROVED       | APPROVED     |
 *  | `rechazar_`      | PENDING                          | REJECTED (INVALID_FORMAT)                           |
 *  | `pausar_`        | PENDING                          | APPROVED            | PAUSED         | APPROVED     |
 *  | `tardar_`        | PENDING                          | PENDING las N primeras ({@see DEFAULT_PENDING_PASSES}); luego APPROVED |
 *  | `editar_`        | PENDING                          | APPROVED            | APPROVED con el pie cambiado ({@see EDITED_FOOTER}) |
 *  | `recategorizar_` | PENDING                          | APPROVED            | APPROVED como MARKETING (previous_category = la pedida) |
 *  | `borrar_`        | PENDING                          | APPROVED            | ya no está (find: not_found; list: no sale) |
 *  | `caer_`          | `unknown` (transport), PERO creada: se encuentra al buscarla y sigue como `aprobar_` |
 *  | `perder_`        | `unknown` (transport) y NO creada: buscarla no la encuentra                    |
 *  | `fallar_`        | `failed` (validation, 100), no creada                                             |
 *
 * Como Meta: el mismo nombre en el mismo idioma da `duplicate_name`
 * (100/2388024); si la anterior se borró (`borrar_`), `validation`
 * (100/2388023). Un id que no tiene da `not_found` (100/33).
 * {@see seed()} pone plantillas «creadas fuera», fijas.
 */
final class FakeWhatsappTemplateClient implements WhatsappTemplateClient
{
    /** Los únicos entornos donde el doble se puede construir y usar. */
    public const ALLOWED_ENVIRONMENTS = ['local', 'testing'];

    public const SCENARIOS = ['aprobar', 'rechazar', 'pausar', 'tardar', 'editar', 'recategorizar', 'borrar', 'caer', 'perder', 'fallar'];

    public const DEFAULT_SCENARIO = 'aprobar';

    /** Veces que `tardar_` sigue en PENDING al mirarla. */
    public const DEFAULT_PENDING_PASSES = 3;

    public const DEFAULT_PERMISSIONS = ['whatsapp_business_management', 'whatsapp_business_messaging'];

    /** El pie que pone `editar_` (como si alguien lo hubiera cambiado en el administrador de WhatsApp). */
    public const EDITED_FOOTER = 'Editado en el administrador de WhatsApp';

    /** «Message template language is being deleted»: el nombre y el idioma se borraron hace poco. */
    public const SUBCODE_LANGUAGE_BEING_DELETED = 2388023;

    private const STATE_KEY = 'meta-templates-fake:v1:state';

    private const STATE_TTL_SECONDS = 604800;

    private const LOCK_SECONDS = 5;

    /** Las plantillas de {@see seed()}: no avanzan. */
    private const SEEDED = 'fija';

    /**
     * @param  list<string>  $grantedPermissions  lo que contesta {@see permissions()}, todo `granted`
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly int $pendingPasses = self::DEFAULT_PENDING_PASSES,
        private readonly array $grantedPermissions = self::DEFAULT_PERMISSIONS,
    ) {
        self::assertAllowedHere();

        if ($pendingPasses < 1 || $pendingPasses > 50) {
            throw new InvalidArgumentException('Las pasadas en PENDING del doble van de 1 a 50.');
        }
        foreach ($grantedPermissions as $permiso) {
            if (! is_string($permiso) || preg_match('/^[a-z0-9_]{1,64}$/D', $permiso) !== 1) {
                throw new InvalidArgumentException('Los permisos del doble son nombres en minúsculas.');
            }
        }
    }

    /** ¿Se puede usar el doble en este entorno? */
    public static function allowedHere(): bool
    {
        return ! app()->isProduction() && in_array(app()->environment(), self::ALLOWED_ENVIRONMENTS, true);
    }

    /** El escenario de un nombre: su prefijo si es uno de {@see SCENARIOS}; si no, {@see DEFAULT_SCENARIO}. */
    public static function scenarioOf(string $name): string
    {
        $prefijo = strstr($name, '_', true);

        return $prefijo !== false && in_array($prefijo, self::SCENARIOS, true) ? $prefijo : self::DEFAULT_SCENARIO;
    }

    public function configurationProblem(): ?string
    {
        self::assertAllowedHere();

        return null;
    }

    public function create(string $wabaId, array $payload): TemplateApiResult
    {
        self::assertAllowedHere();
        $waba = TemplateRequestGuard::wabaId($wabaId);
        TemplateRequestGuard::payload($payload);
        [$nombre, $idioma, $categoria] = [$payload['name'], $payload['language'], $payload['category']];
        $escenario = self::scenarioOf($nombre);

        if ($escenario === 'fallar') {
            return TemplateApiResult::failure(TemplateApiResult::OP_CREATE, TemplateApiResult::FAILED, TemplateApiErrors::VALIDATION, null, 400, 100, null,
                'Plantilla no válida (doble de Meta)', 'El doble de Meta rechaza a propósito las plantillas cuyo nombre empieza por fallar_.');
        }
        if ($escenario === 'perder') {
            return TemplateApiResult::failure(TemplateApiResult::OP_CREATE, TemplateApiResult::UNKNOWN, TemplateApiErrors::TRANSPORT, 'maybe_sent');
        }

        return $this->mutate(function (array $estado) use ($waba, $nombre, $idioma, $categoria, $payload, $escenario): array {
            foreach ($estado['templates'] as $t) {
                if ($t['waba'] !== $waba || $t['name'] !== $nombre || $t['language'] !== $idioma) {
                    continue;
                }

                return [$estado, $this->state($t)['gone']
                    ? TemplateApiResult::failure(TemplateApiResult::OP_CREATE, TemplateApiResult::FAILED, TemplateApiErrors::VALIDATION, null, 400, 100, self::SUBCODE_LANGUAGE_BEING_DELETED,
                        'No se puede crear todavía', 'Hace poco se borró una plantilla con este nombre en este idioma (doble de Meta).')
                    : TemplateApiResult::failure(TemplateApiResult::OP_CREATE, TemplateApiResult::FAILED, TemplateApiErrors::DUPLICATE_NAME, null, 400, 100, TemplateApiErrors::SUBCODE_DUPLICATE_NAME,
                        'El contenido en este idioma ya existe', 'Ya hay una plantilla con este nombre en este idioma (doble de Meta).')];
            }

            $id = self::idFor($waba, $nombre, $idioma);
            $estado['templates'][] = [
                'id' => $id, 'waba' => $waba, 'name' => $nombre, 'language' => $idioma, 'category' => $categoria,
                'parameter_format' => $payload['parameter_format'] ?? 'POSITIONAL', 'components' => $payload['components'],
                'scenario' => $escenario, 'observations' => 0, 'fixed' => [],
            ];

            return [$estado, $escenario === 'caer'
                ? TemplateApiResult::failure(TemplateApiResult::OP_CREATE, TemplateApiResult::UNKNOWN, TemplateApiErrors::TRANSPORT, 'maybe_sent')
                : TemplateApiResult::created($id, 'PENDING', $categoria, 200, 0)];
        });
    }

    public function find(string $templateId, array $fields): TemplateApiResult
    {
        self::assertAllowedHere();
        $id = TemplateRequestGuard::templateId($templateId);
        $campos = explode(',', TemplateRequestGuard::fields($fields, ['id']));

        return $this->mutate(function (array $estado) use ($id, $campos): array {
            foreach ($estado['templates'] as $i => $t) {
                if ($t['id'] !== $id || $this->state($t)['gone']) {
                    continue;
                }
                $estado['templates'][$i] = $t = self::observed($t, $campos);
                if ($this->state($t)['gone']) {
                    break;
                }

                return [$estado, TemplateApiResult::templates(TemplateApiResult::OP_FIND, [$this->snapshot($t, $campos)], null, 0, 200, 0)];
            }

            return [$estado, TemplateApiResult::failure(TemplateApiResult::OP_FIND, TemplateApiResult::FAILED, TemplateApiErrors::NOT_FOUND, null, 400, 100, TemplateApiErrors::SUBCODE_OBJECT_NOT_FOUND)];
        });
    }

    public function findByName(string $wabaId, string $name, ?string $language, array $fields): TemplateApiResult
    {
        self::assertAllowedHere();
        $waba = TemplateRequestGuard::wabaId($wabaId);
        $nombre = TemplateRequestGuard::name($name);
        $idioma = TemplateRequestGuard::language($language);
        $campos = explode(',', TemplateRequestGuard::fields($fields, ['id', 'name', 'language']));

        return $this->mutate(function (array $estado) use ($waba, $nombre, $idioma, $campos): array {
            $encontradas = [];
            foreach ($estado['templates'] as $i => $t) {
                if ($t['waba'] !== $waba || $t['name'] !== $nombre || ($idioma !== null && $t['language'] !== $idioma) || $this->state($t)['gone']) {
                    continue;
                }
                $estado['templates'][$i] = $t = self::observed($t, $campos);
                if (! $this->state($t)['gone']) {
                    $encontradas[] = $this->snapshot($t, $campos);
                }
            }

            return [$estado, TemplateApiResult::templates(TemplateApiResult::OP_FIND_BY_NAME, $encontradas, null, 0, 200, 0)];
        });
    }

    public function list(string $wabaId, array $fields, ?string $after = null, int $limit = self::MAX_PAGE_SIZE): TemplateApiResult
    {
        self::assertAllowedHere();
        $waba = TemplateRequestGuard::wabaId($wabaId);
        $campos = explode(',', TemplateRequestGuard::fields($fields, ['id']));
        $tamano = TemplateRequestGuard::limit($limit);
        $cursor = TemplateRequestGuard::cursor($after);

        return $this->mutate(function (array $estado) use ($waba, $campos, $tamano, $cursor): array {
            // Cursor por clave (el id de la última de la página anterior), no por posición:
            // una plantilla que desaparece entre dos páginas no hace saltar a otra.
            $desde = 0;
            if ($cursor !== null) {
                $ultimo = self::cursorId($cursor);
                $posicion = $ultimo === null ? false : array_search($ultimo, array_column($estado['templates'], 'id'), true);
                if ($posicion === false) {
                    return [$estado, TemplateApiResult::failure(TemplateApiResult::OP_LIST, TemplateApiResult::FAILED, TemplateApiErrors::VALIDATION, null, 400, 100, null,
                        'Cursor no válido', 'El doble de Meta no reconoce ese cursor.')];
                }
                $desde = $posicion + 1;
            }

            // Se toman hasta `$tamano` plantillas visibles; una que desaparece al
            // mirarla cuenta como tomada pero no sale (como en Meta, ya no está).
            $pagina = [];
            $tomadas = 0;
            $ultimaId = null;
            $hayMas = false;
            $total = count($estado['templates']);
            for ($i = $desde; $i < $total; $i++) {
                $t = $estado['templates'][$i];
                if ($t['waba'] !== $waba || $this->state($t)['gone']) {
                    continue;
                }
                if ($tomadas === $tamano) {
                    $hayMas = true;
                    break;
                }
                $estado['templates'][$i] = $t = self::observed($t, $campos);
                $tomadas++;
                $ultimaId = $t['id'];
                if (! $this->state($t)['gone']) {
                    $pagina[] = $this->snapshot($t, $campos);
                }
            }

            $siguiente = $hayMas && $ultimaId !== null ? base64_encode('fake:'.$ultimaId) : null;

            return [$estado, TemplateApiResult::templates(TemplateApiResult::OP_LIST, $pagina, $siguiente, 0, 200, 0)];
        });
    }

    public function permissions(): TemplateApiResult
    {
        self::assertAllowedHere();

        $permisos = array_fill_keys($this->grantedPermissions, 'granted');
        ksort($permisos, SORT_STRING);

        return TemplateApiResult::permissionsRead($permisos, 0, 200, 0);
    }

    /**
     * Pone en el doble una plantilla que «ya estaba en Meta» (creada fuera de
     * este cliente), fija: no avanza al mirarla. Devuelve su id.
     *
     * @param  array{name: string, language: string, category: string, status?: string, components?: list<array<string,mixed>>,
     *               rejected_reason?: string, quality?: string, correct_category?: string, id?: string}  $template
     */
    public function seed(string $wabaId, array $template): string
    {
        self::assertAllowedHere();
        $waba = TemplateRequestGuard::wabaId($wabaId);
        $nombre = TemplateRequestGuard::name((string) ($template['name'] ?? ''));
        $idioma = (string) TemplateRequestGuard::language((string) ($template['language'] ?? ''));
        $fijos = [];
        foreach (['category' => 16, 'status' => 24, 'rejected_reason' => 32, 'quality' => 8, 'correct_category' => 16] as $clave => $tope) {
            if (! array_key_exists($clave, $template)) {
                continue;
            }
            $valor = MetaTemplate::enum($template[$clave], $tope);
            if ($valor === null) {
                throw new InvalidArgumentException("«{$clave}» de la plantilla sembrada va en mayúsculas.");
            }
            $fijos[$clave] = $valor;
        }
        if (! isset($fijos['category'])) {
            throw new InvalidArgumentException('La plantilla sembrada necesita su categoría.');
        }
        $componentes = $template['components'] ?? [['type' => 'BODY', 'text' => 'Plantilla sembrada en el doble de Meta.']];
        if (! is_array($componentes) || MetaTemplate::fromGraph(['id' => '1', 'components' => $componentes])?->components === null) {
            throw new InvalidArgumentException('Los componentes de la plantilla sembrada no tienen la forma de Meta.');
        }
        $id = $template['id'] ?? self::idFor($waba, $nombre, $idioma);
        if (! is_string($id) || preg_match(TemplateRequestGuard::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException('El id de la plantilla sembrada son dígitos.');
        }

        $this->mutate(function (array $estado) use ($id, $waba, $nombre, $idioma, $fijos, $componentes): array {
            foreach ($estado['templates'] as $t) {
                if ($t['id'] === $id || ($t['waba'] === $waba && $t['name'] === $nombre && $t['language'] === $idioma)) {
                    throw new InvalidArgumentException('Esa plantilla (id, o nombre e idioma) ya está en el doble.');
                }
            }
            $estado['templates'][] = [
                'id' => $id, 'waba' => $waba, 'name' => $nombre, 'language' => $idioma, 'category' => $fijos['category'],
                'parameter_format' => 'POSITIONAL', 'components' => $componentes,
                'scenario' => self::SEEDED, 'observations' => 0, 'fixed' => $fijos,
            ];

            return [$estado, TemplateApiResult::created($id, $fijos['status'] ?? 'APPROVED', $fijos['category'], 200, 0)];
        });

        return $id;
    }

    /**
     * Lo que Meta enseñaría ahora de una plantilla del doble, según su
     * escenario y las veces que se ha mirado.
     *
     * @param  array<string,mixed>  $t
     * @return array{status: string, category: string, previous_category: ?string, rejected_reason: string, quality: string,
     *               correct_category: ?string, components: list<array<string,mixed>>, gone: bool}
     */
    private function state(array $t): array
    {
        $n = (int) $t['observations'];
        $base = [
            'status' => 'PENDING', 'category' => $t['category'], 'previous_category' => null, 'rejected_reason' => 'NONE',
            'quality' => 'UNKNOWN', 'correct_category' => null, 'components' => $t['components'], 'gone' => false,
        ];
        $aprobada = static fn (array $mas = []): array => array_merge($base, ['status' => 'APPROVED', 'quality' => 'GREEN'], $mas);

        return match ($t['scenario']) {
            self::SEEDED => array_merge($base, ['status' => 'APPROVED'], $t['fixed']),
            'rechazar' => $n >= 1 ? array_merge($base, ['status' => 'REJECTED', 'rejected_reason' => 'INVALID_FORMAT']) : $base,
            'pausar' => match (true) {
                $n === 0 => $base,
                $n === 1 => $aprobada(),
                $n === 2 => array_merge($base, ['status' => 'PAUSED', 'quality' => 'RED']),
                default => $aprobada(['quality' => 'YELLOW']),
            },
            'tardar' => $n > $this->pendingPasses ? $aprobada() : $base,
            'editar' => match (true) {
                $n === 0 => $base,
                $n === 1 => $aprobada(),
                default => $aprobada(['components' => self::edited($t['components'])]),
            },
            'recategorizar' => match (true) {
                $n === 0 => $base,
                $n === 1 || $t['category'] === 'MARKETING' => $aprobada(),
                default => $aprobada(['category' => 'MARKETING', 'previous_category' => $t['category']]),
            },
            'borrar' => match (true) {
                $n === 0 => $base,
                $n === 1 => $aprobada(),
                default => array_merge($base, ['gone' => true]),
            },
            default => $n >= 1 ? $aprobada() : $base,
        };
    }

    /**
     * La plantilla como la daría Graph con esos campos, y por la MISMA
     * normalización que el cliente de verdad.
     *
     * @param  array<string,mixed>  $t
     * @param  list<string>  $campos
     */
    private function snapshot(array $t, array $campos): MetaTemplate
    {
        $ahora = $this->state($t);
        $valores = [
            'id' => $t['id'], 'name' => $t['name'], 'language' => $t['language'], 'status' => $ahora['status'],
            'category' => $ahora['category'], 'previous_category' => $ahora['previous_category'], 'correct_category' => $ahora['correct_category'],
            'components' => $ahora['components'], 'quality_score' => ['score' => $ahora['quality']], 'rejected_reason' => $ahora['rejected_reason'],
            'parameter_format' => $t['parameter_format'],
        ];

        $nodo = [];
        foreach ($campos as $campo) {
            if (($valores[$campo] ?? null) !== null) {
                $nodo[$campo] = $valores[$campo];
            }
        }

        $plantilla = MetaTemplate::fromGraph($nodo);
        if ($plantilla === null) {
            throw new LogicException('El doble de Meta compuso una plantilla que no se puede leer.');
        }

        return $plantilla;
    }

    /**
     * Mirar es pedir el estado: solo entonces avanza el escenario.
     *
     * @param  array<string,mixed>  $t
     * @param  list<string>  $campos
     * @return array<string,mixed>
     */
    private static function observed(array $t, array $campos): array
    {
        if (in_array('status', $campos, true)) {
            $t['observations'] = (int) $t['observations'] + 1;
        }

        return $t;
    }

    /**
     * El pie cambiado, como si lo hubieran editado en el administrador de
     * WhatsApp: si hay pie, otro texto; si no, uno nuevo antes de los botones.
     *
     * @param  list<array<string,mixed>>  $componentes
     * @return list<array<string,mixed>>
     */
    private static function edited(array $componentes): array
    {
        foreach ($componentes as $i => $c) {
            if (($c['type'] ?? null) === 'FOOTER') {
                $componentes[$i]['text'] = self::EDITED_FOOTER;

                return $componentes;
            }
        }

        $pie = ['type' => 'FOOTER', 'text' => self::EDITED_FOOTER];
        foreach ($componentes as $i => $c) {
            if (($c['type'] ?? null) === 'BUTTONS') {
                array_splice($componentes, $i, 0, [$pie]);

                return $componentes;
            }
        }
        $componentes[] = $pie;

        return $componentes;
    }

    /** Un id de 16 dígitos, siempre el mismo para la misma cuenta, nombre e idioma. */
    private static function idFor(string $waba, string $nombre, string $idioma): string
    {
        $numero = hexdec(substr(hash('sha256', "{$waba}|{$nombre}|{$idioma}"), 0, 15)) % 1_000_000_000_000_000;

        return '1'.str_pad((string) $numero, 15, '0', STR_PAD_LEFT);
    }

    private static function cursorId(string $cursor): ?string
    {
        $claro = base64_decode($cursor, true);

        return is_string($claro) && preg_match('/^fake:([0-9]{1,32})$/D', $claro, $m) === 1 ? $m[1] : null;
    }

    /**
     * Lee el estado, aplica el cambio y lo guarda, con el cerrojo de la caché
     * si la caché lo tiene (dos lecturas a la vez no se pisan las pasadas).
     *
     * @param  callable(array{templates: list<array<string,mixed>>}): array{0: array{templates: list<array<string,mixed>>}, 1: TemplateApiResult}  $cambio
     */
    private function mutate(callable $cambio): TemplateApiResult
    {
        $hacer = function () use ($cambio): TemplateApiResult {
            $estado = $this->cache->get(self::STATE_KEY);
            if (! is_array($estado) || ! is_array($estado['templates'] ?? null)) {
                $estado = ['templates' => []];
            }
            [$nuevo, $resultado] = $cambio($estado);
            if ($nuevo !== $estado) {
                $this->cache->put(self::STATE_KEY, $nuevo, self::STATE_TTL_SECONDS);
            }

            return $resultado;
        };

        $almacen = $this->cache->getStore();
        if ($almacen instanceof LockProvider) {
            return $almacen->lock(self::STATE_KEY.':lock', self::LOCK_SECONDS)->block(self::LOCK_SECONDS, $hacer);
        }

        return $hacer();
    }

    private static function assertAllowedHere(): void
    {
        if (! self::allowedHere()) {
            throw new LogicException('El doble de las plantillas de Meta solo existe en local y en pruebas: en cualquier otro entorno escribiría aprobaciones que Meta no dio.');
        }
    }
}
