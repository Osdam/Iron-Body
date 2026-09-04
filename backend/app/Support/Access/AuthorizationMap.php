<?php

namespace App\Support\Access;

use Illuminate\Routing\Route;

/**
 * Mapa CENTRAL de autorización: qué permiso exige cada ruta administrativa.
 *
 * Por qué existe. La cobertura anterior se anotaba ruta por ruta con
 * `->middleware('admin.can:…')`, y eso produce lo que produjo: 43 rutas
 * protegidas de 341. Se anota donde uno se acuerda y se olvida en la
 * siguiente, y una ruta nueva nace desprotegida sin que nada avise.
 *
 * Aquí la relación se declara UNA vez, por CONTROLADOR —que es la unidad
 * semántica real— con excepciones explícitas por ruta cuando un controlador
 * mezcla cosas distintas. Cabe en una pantalla, se revisa de un vistazo, y
 * `AuthorizationCoverageTest` falla si aparece una ruta que este mapa no sabe
 * resolver. La cobertura deja de depender de la memoria de nadie.
 *
 * FALLA CERRADO. Lo que no está mapeado se deniega. Esa es la diferencia entre
 * un sistema de permisos y una colección de recordatorios.
 */
final class AuthorizationMap
{
    /**
     * Ruta accesible para cualquiera, sin sesión. Solo la puerta de entrada.
     */
    public const PUBLIC = '@public';

    /**
     * Ruta que cualquier administrador AUTENTICADO puede usar sobre sí mismo o
     * sobre el funcionamiento básico del panel: quién soy, cerrar sesión, mis
     * notificaciones, el vale para abrir un stream.
     *
     * No es un agujero: exigen sesión válida y no exponen datos de negocio de
     * otros. Pedirles un permiso obligaría a concedérselo a todo el mundo, que
     * es la forma de vaciar de sentido un catálogo.
     */
    public const SELF = '@self';

    /**
     * Ruta cuyo permiso DEPENDE DE LOS DATOS, no de la URL: lo resuelve el
     * controlador en cuanto sabe sobre qué está operando.
     *
     * No es una excepción a la autorización: es autorización que este mapa no
     * puede expresar. El caso concreto son los turnos de caja. `cash.products`
     * y `cash.gym` son permisos SEPARADOS, y la ruta `shifts/{shift}` no sabe
     * de qué caja es el turno hasta cargarlo. Poner aquí un permiso fijo
     * obligaba a elegir uno de los dos, y eso es justo lo que estaba mal:
     * exigir `cash.products.view` para todo el módulo bloqueaba a quien solo
     * tuviera `cash.gym.view` antes de que nadie mirase el tipo del turno.
     *
     * Condiciones para usarlo, y son las tres a la vez:
     *
     *  1. El middleware exige sesión de administrador igual que en cualquier
     *     otra ruta: sin ella no se pasa. Esto NO abre nada.
     *  2. El controlador DEBE denegar explícitamente, y por defecto: un camino
     *     que no compruebe nada es un agujero, no una ruta permisiva.
     *  3. La ruta va en la lista blanca de {@see AuthorizationMap::CONTROLLER_RESOLVED},
     *     que una prueba compara con lo que el mapa resuelve de verdad. Añadir
     *     una cuarta ruta a este centinela sin declararla rompe CI.
     *
     * Los permisos que se comprueban así se declaran en CONTROLLER_ENFORCED
     * para que el catálogo pueda ofrecerlos.
     */
    public const CONTROLLER = '@controller';

    /**
     * Las ÚNICAS rutas que pueden resolver a {@see AuthorizationMap::CONTROLLER}.
     *
     * Es una lista blanca, no documentación: `AuthorizationCoverageTest` la
     * compara con lo que el mapa resuelve realmente, así que el centinela no
     * puede extenderse a una ruta nueva sin que alguien lo escriba aquí y
     * justifique por qué su permiso depende de los datos.
     *
     * @var array<string>
     */
    public const CONTROLLER_RESOLVED = [
        'GET api/admin/caja/shift',
        'POST api/admin/caja/shift/open',
        'POST api/admin/caja/shift/close',
        'GET api/admin/caja/shifts',
        'GET api/admin/caja/shifts/{shift}',
        'GET api/admin/caja/shifts/{shift}/pdf',
        'POST api/admin/caja/shifts/{shift}/difference',
    ];

    /**
     * Permiso por CONTROLADOR. Es el caso normal: un controlador administra un
     * dominio y todas sus acciones exigen lo mismo, con la distinción de
     * lectura/escritura resuelta en {@see resolve()} según el verbo HTTP.
     *
     * Formato: 'ControllerShortName' => 'dominio' (se le añade .view o el
     * permiso de escritura declarado en WRITE_PERMISSION).
     *
     * @var array<string, string>
     */
    private const CONTROLLERS = [
        // ── Miembros y su expediente ────────────────────────────────────────
        'UserController' => 'members',
        'MemberStaffController' => 'members',
        'MemberRegistrationController' => 'members',
        'MemberIdentityReviewController' => 'members',
        'MembershipController' => 'members',
        'ContractAdminController' => 'members',
        'MemberTrainerController' => 'members',
        'PhysicalEvaluationAdminController' => 'members',
        'NutritionAdminController' => 'members',
        'FiscalProfileController' => 'members',
        'MemberRiskController' => 'members',

        // ── Planes ──────────────────────────────────────────────────────────
        'PlanController' => 'plans',

        // ── Pagos y suscripciones ───────────────────────────────────────────
        'PaymentController' => 'payments',
        'AdminSubscriptionController' => 'payments',

        // ── Caja ────────────────────────────────────────────────────────────
        // El punto de venta de productos. Los turnos de AMBAS cajas viven en
        // CashShiftController y se resuelven por ruta, porque el tipo va en el
        // cuerpo o en la query: ver OVERRIDES.
        'CajaController' => 'cash.products',

        // ── Inventario ──────────────────────────────────────────────────────
        'ProductController' => 'inventory',
        'InventoryController' => 'inventory',

        // ── Facturación electrónica ─────────────────────────────────────────
        'ElectronicInvoiceController' => 'billing',
        'BillingTaxController' => 'billing',
        'BillingQuoteController' => 'billing',

        // ── Clases, asistencia y torniquete ─────────────────────────────────
        'ClassController' => 'classes',
        'ClassSupervisionController' => 'classes',
        'AttendanceController' => 'classes',
        'TurnstileController' => 'classes',

        // ── Rutinas y ejercicios ────────────────────────────────────────────
        'RoutineController' => 'routines',
        'MemberRoutineController' => 'routines',
        'ExerciseController' => 'routines',

        // ── Entrenadores ────────────────────────────────────────────────────
        'TrainerController' => 'trainers',
        'TrainerAdminController' => 'trainers',
        'TrainerTaskController' => 'trainers',

        // ── Mercadeo ────────────────────────────────────────────────────────
        'MarketingController' => 'marketing',
        'MarketingInboxController' => 'marketing',
        'MarketingAgentActionController' => 'marketing',
        'MarketingAppointmentController' => 'marketing',
        'MarketingAnalyticsController' => 'marketing',
        'MarketingAttachmentController' => 'marketing',
        'SupervisionController' => 'marketing',

        // ── Soporte ─────────────────────────────────────────────────────────
        'SupportController' => 'support',
        'SecuritySupportController' => 'support',

        // ── Moderación de comunidad ─────────────────────────────────────────
        // Conserva además su propia comprobación interna (ModerationPermission),
        // que es defensa en profundidad y NO contradice a esta: aquella es más
        // fina, esta es la puerta.
        'ModerationController' => 'moderation',
        'ModerationRealtimeController' => 'moderation',
        'StoriesController' => 'moderation',
        'LiveController' => 'moderation',

        // ── Nutrición (catálogo de alimentos) ───────────────────────────────
        'NutritionFoodAdminController' => 'nutrition',

        // ── Contenido de la app: eventos, anuncios, rachas, push ────────────
        'EventController' => 'content',
        'AdController' => 'content',
        'WeeklyStreakAdminController' => 'content',
        'NotificationAdminController' => 'content',

        // ── Equipos del gimnasio ────────────────────────────────────────────
        'GymEquipmentController' => 'equipment',

        // ── Informes y dinero ───────────────────────────────────────────────
        'ReportsOverviewController' => 'reports',
        'EarningsController' => 'earnings',

        // ── Auditoría ───────────────────────────────────────────────────────
        'AuditLogController' => 'audit',

        // ── Integraciones ───────────────────────────────────────────────────
        'WhatsappIntegrationController' => 'integrations',

        // ── Seguridad de la plataforma ──────────────────────────────────────
        'IronGuardController' => 'security',

        // ── Identidad: usuarios administrativos y roles ─────────────────────
        'AdminUserController' => 'users',
        'AdminRoleController' => 'roles',
        'RolePermissionController' => 'roles',

        // ── Asistente IA del CRM ────────────────────────────────────────────
        'IronCrmAiController' => 'ai',
    ];

    /**
     * Excepciones y casos por RUTA, cuando el controlador no basta.
     *
     * Se consulta ANTES que el mapa de controladores. La clave es
     * "MÉTODO uri" tal y como los expone el router.
     *
     * @var array<string, string>
     */
    private const OVERRIDES = [
        // Puerta de entrada del CRM: sin ella nadie podría autenticarse nunca.
        'POST api/admin/auth/login' => self::PUBLIC,

        // Sobre uno mismo. Exigen sesión, no exponen negocio ajeno.
        'GET api/admin/auth/me' => self::SELF,
        'POST api/admin/auth/logout' => self::SELF,
        'GET api/admin/notifications' => self::SELF,
        'GET api/admin/notifications/stream' => self::SELF,
        'GET api/admin/notifications/unread-count' => self::SELF,
        'POST api/admin/notifications/read-all' => self::SELF,
        'POST api/admin/notifications/{uuid}/read' => self::SELF,
        // Vale de corta vida para abrir un SSE. Quien lo canjea vuelve a pasar
        // por el permiso del stream que abra.
        'GET api/admin/stream-ticket' => self::SELF,

        // Crear una notificación interna del panel no es contenido de la app:
        // lo usa el propio CRM para avisar a su gente.
        'POST api/admin/notifications' => self::SELF,

        // Turnos de caja: el tipo (products|gym) llega en el cuerpo o la query,
        // así que el permiso fino lo decide el controlador caja por caja. Aquí
        // se exige poder ver ALGUNA caja; el orquestador hace el resto.
        // Estado del turno y apertura/cierre. El tipo llega en la query o en el
        // cuerpo, así que tampoco aquí puede la ruta saber de qué caja se
        // habla. `current()` exige el `view` de ESE tipo; abrir y cerrar exigen
        // el `operate` de CADA caja tocada, y eso lo aplica el orquestador caja
        // por caja: marcar `also` no concede nada.
        'GET api/admin/caja/shift' => self::CONTROLLER,
        'POST api/admin/caja/shift/open' => self::CONTROLLER,
        'POST api/admin/caja/shift/close' => self::CONTROLLER,

        // Consulta e informe de turnos: el permiso depende del TIPO de la caja,
        // y el tipo está en el dato, no en la URL. `cash.products` y `cash.gym`
        // son permisos separados; fijar uno aquí bloquearía a quien tenga el
        // otro antes de que nadie mire de qué turno se trata. Lo resuelve
        // CashShiftController —`denegarSiNoPuedeVer()` para leer, el permiso de
        // supervisión de esa caja para arquear, y el filtrado por cajas
        // visibles en el historial—, siempre denegando por defecto.
        'GET api/admin/caja/shifts' => self::CONTROLLER,
        'GET api/admin/caja/shifts/{shift}' => self::CONTROLLER,
        'GET api/admin/caja/shifts/{shift}/pdf' => self::CONTROLLER,
        'POST api/admin/caja/shifts/{shift}/difference' => self::CONTROLLER,

        // Cobro presencial de membresía: es la caja del GIMNASIO, aunque el
        // controlador sea el de pagos.
        'POST api/payments' => 'cash.gym.operate',
        'PUT api/payments/{payment}' => 'payments.cancel',
        'PATCH api/payments/{payment}' => 'payments.cancel',

        // Liberar el vínculo de un equipo es soporte de acceso, no "auth".
        'POST api/admin/devices/{deviceId}/release' => 'support.manage',

        // Paneles de inicio: agregados de negocio, no un dominio propio.
        // Devuelve solo los contadores que el actor ya puede ver por su cuenta,
        // y `revenue` únicamente con `reports.view`. Exigir ese permiso en la
        // puerta dejaba a recepción sin pantalla de inicio. Ver routes/api.php.
        'GET api/dashboard' => self::SELF,
        'GET api/reports/stats' => 'reports.view',
        'GET api/admin/reports/overview' => 'reports.view',
        // Cotizar es un cálculo fiscal previo a cobrar: pertenece a facturación.
        'POST api/admin/billing/quote' => 'billing.view',

        // Anular un contrato es una acción sobre el expediente del socio.
        'POST api/admin/contracts/{contract}/void' => 'members.edit',

        // Webhook del torniquete: lo llama el hardware, no una persona. Su
        // autenticación es la del propio dispositivo.
        'POST api/turnstile/webhook/fire' => self::PUBLIC,

        // Movimientos de existencias y foto de producto: EDITAN un producto que
        // ya existe. Crear es solo `POST api/admin/products`. La regla genérica
        // POST→create era demasiado gruesa aquí, y la anotación anterior —que
        // exigía inventory.edit— tenía razón.
        'POST api/admin/products/{product}/entry' => 'inventory.edit',
        'POST api/admin/products/{product}/exit' => 'inventory.edit',
        'POST api/admin/products/{product}/stock' => 'inventory.edit',
        'POST api/admin/products/{product}/image' => 'inventory.edit',
        'DELETE api/admin/products/{product}/image' => 'inventory.edit',

        // Anular una venta ya cobrada es supervisión, no operación de mostrador.
        'POST api/admin/caja/sales/{sale}/cancel' => 'cash.products.manage',

        // Ver el catálogo de roles ya revela el organigrama y qué puede cada
        // uno. Se exige el mismo permiso que para tocarlo: un dominio con una
        // sola llave se entiende mejor que uno con dos que casi coinciden.
        'GET api/admin/roles' => 'roles.manage',
        'GET api/admin/roles/assignable' => 'roles.manage',
        'GET api/admin/roles/permissions' => 'roles.manage',
    ];

    /**
     * Qué permiso exige ESCRIBIR en cada dominio. Sin entrada aquí, escribir
     * exige `<dominio>.manage`.
     *
     * Existe porque los dominios no tienen todos la misma granularidad: en
     * inventario se distingue crear de editar y de archivar, mientras que en
     * planes basta con poder gestionarlos.
     *
     * @var array<string, string>
     */
    private const WRITE_PERMISSION = [
        'cash.products' => 'cash.products.operate',
        'cash.gym' => 'cash.gym.operate',
        'ai' => 'ai.use',
    ];

    /**
     * Dominios donde el verbo HTTP distingue la acción, porque existen
     * endpoints separados para cada una.
     *
     * Se declara solo donde la distinción es real: en planes o mercadeo no hay
     * un «archivar» distinto de un «editar», y fabricar la llave produciría un
     * permiso que no protege nada.
     *
     * @var array<string, array<string, string>>
     */
    private const GRANULAR_WRITE = [
        'members' => [
            'POST' => 'members.create',
            'PUT' => 'members.edit',
            'PATCH' => 'members.edit',
            'DELETE' => 'members.archive',
        ],
        'inventory' => [
            'POST' => 'inventory.create',
            'DELETE' => 'inventory.delete',
            'PUT' => 'inventory.edit',
            'PATCH' => 'inventory.edit',
        ],
        'payments' => ['POST' => 'payments.create', 'DELETE' => 'payments.cancel'],
    ];

    /**
     * Dominios donde escribir NO está permitido a nadie por esta vía.
     *
     * `audit` es el caso: el libro de auditoría se escribe desde dentro del
     * sistema, no desde el panel. Borrar o inventar entradas destruiría
     * justamente lo que hace útil el registro.
     *
     * @var array<string>
     */
    private const READ_ONLY_DOMAINS = ['audit', 'reports', 'earnings'];

    /**
     * Permisos REALES que no exige ninguna ruta, porque se comprueban DENTRO
     * del controlador con una granularidad que la ruta no puede expresar.
     *
     * El caso son los turnos de caja: la ruta es la misma para productos y para
     * gimnasio —el tipo va en el cuerpo— y cerrar el turno de otra persona
     * depende de quién lo abrió, no de la URL. CashShiftController y su
     * orquestador lo resuelven caja por caja.
     *
     * Se declaran aquí para que el catálogo los ofrezca: un permiso que se
     * comprueba pero que nadie puede conceder es peor que no tenerlo.
     *
     * @var array<string>
     */
    private const CONTROLLER_ENFORCED = [
        'cash.products.view',
        'cash.products.operate',
        'cash.products.manage',
        'cash.gym.view',
        'cash.gym.operate',
        'cash.gym.manage',
    ];

    /**
     * Permiso exigido por una ruta, o uno de los centinelas PUBLIC/SELF.
     *
     * Devuelve null si la ruta NO se sabe clasificar: el middleware lo trata
     * como denegación y el test de cobertura lo convierte en un fallo de CI.
     */
    public static function resolve(Route $route): ?string
    {
        $clave = self::routeKey($route);
        if (isset(self::OVERRIDES[$clave])) {
            return self::OVERRIDES[$clave];
        }

        $dominio = self::CONTROLLERS[self::controllerName($route)] ?? null;
        if ($dominio === null) {
            return null;
        }

        if (self::isRead($route)) {
            return "{$dominio}.view";
        }

        if (in_array($dominio, self::READ_ONLY_DOMAINS, true)) {
            // Escribir en un dominio de solo lectura exige el permiso más alto
            // del CRM; en la práctica, nadie lo hace desde el panel.
            return 'roles.manage';
        }

        $verbo = self::writeVerb($route);
        if (isset(self::GRANULAR_WRITE[$dominio][$verbo])) {
            return self::GRANULAR_WRITE[$dominio][$verbo];
        }

        return self::WRITE_PERMISSION[$dominio] ?? "{$dominio}.manage";
    }

    /**
     * ¿Esta ruta pertenece al universo que hay que autorizar?
     *
     * Es EXACTAMENTE el mismo universo que exige credencial administrativa en
     * {@see \App\Http\Middleware\ProtectAdminPaths}, más las rutas del CRM
     * que se blindan por alias. Definirlo aparte habría dejado que las dos
     * definiciones se separaran con el tiempo, y el hueco entre ambas es
     * precisamente donde vive una ruta sin proteger.
     */
    public static function isAdministrative(Route $route): bool
    {
        $uri = $route->uri();

        if (str_starts_with($uri, 'api/admin')) {
            return true;
        }

        // Pagos legacy del CRM. Los pagos in-app del socio (wompi) NO: llevan
        // su propia autenticación de miembro y no son administrativos.
        if (str_starts_with($uri, 'api/payments')) {
            return ! str_starts_with($uri, 'api/payments/wompi');
        }

        // Resto del CRM fuera del prefijo /admin: comparte espacio con rutas de
        // la app móvil, así que el discriminante es su middleware.
        return in_array('auth.admin', $route->gatherMiddleware(), true);
    }

    public static function routeKey(Route $route): string
    {
        $metodo = collect($route->methods())
            ->first(fn (string $m) => ! in_array($m, ['HEAD', 'OPTIONS'], true)) ?? 'GET';

        return $metodo.' '.$route->uri();
    }

    /**
     * Permisos que este mapa exige DE VERDAD.
     *
     * Se calcula recorriendo las rutas reales, no enumerando combinaciones
     * posibles: un permiso que ninguna ruta pide es un permiso que no protege
     * nada, y no debe aparecer en la matriz que se le enseña a nadie.
     *
     * @return list<string>
     */
    public static function referencedPermissions(): array
    {
        $out = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (! self::isAdministrative($route)) {
                continue;
            }
            $p = self::resolve($route);
            if ($p !== null && ! in_array($p, [self::PUBLIC, self::SELF, self::CONTROLLER], true)) {
                $out[$p] = true;
            }
        }
        foreach (self::CONTROLLER_ENFORCED as $p) {
            $out[$p] = true;
        }

        $claves = array_keys($out);
        sort($claves);

        return $claves;
    }

    /** Verbo de escritura de la ruta, normalizado. */
    private static function writeVerb(Route $route): string
    {
        foreach (['POST', 'DELETE', 'PUT', 'PATCH'] as $v) {
            if (in_array($v, $route->methods(), true)) {
                return $v;
            }
        }

        return 'POST';
    }

    private static function isRead(Route $route): bool
    {
        return in_array('GET', $route->methods(), true);
    }

    private static function controllerName(Route $route): string
    {
        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            return 'Closure';
        }

        $clase = explode('@', $action)[0];
        $partes = explode('\\', $clase);

        return end($partes);
    }
}
